<?php
require_once '../connection.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Get available agents for assignment
$agentQuery = "SELECT a.agent_id, CONCAT(u.first_name, ' ', u.last_name) AS agent_name 
               FROM agents a 
               JOIN users u ON a.user_id = u.user_id 
               ORDER BY u.first_name, u.last_name";
$agentResult = $conn->query($agentQuery);
$agents = [];
while ($row = $agentResult->fetch_assoc()) {
    $agents[] = $row;
}

// Get property features for checkboxes
$featureQuery = "SELECT * FROM property_features ORDER BY name";
$featureResult = $conn->query($featureQuery);
$features = [];
while ($row = $featureResult->fetch_assoc()) {
    $features[] = $row;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    $requiredFields = ['title', 'property_type', 'status', 'price', 'address', 'city', 'state', 'postal_code'];
    $errors = [];
    
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }
    
    // Continue if no validation errors
    if (empty($errors)) {
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Insert into properties table
            $stmt = $conn->prepare("INSERT INTO properties (
                title, description, property_type, status, price, bedrooms, bathrooms, 
                area_sqft, year_built, lot_size_sqft, address, city, state, postal_code, 
                country, latitude, longitude, featured
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $title = $_POST['title'];
            $description = $_POST['description'];
            $type = $_POST['property_type'];
            $status = $_POST['status'];
            $price = $_POST['price'];
            $bedrooms = !empty($_POST['bedrooms']) ? $_POST['bedrooms'] : null;
            $bathrooms = !empty($_POST['bathrooms']) ? $_POST['bathrooms'] : null;
            $area = !empty($_POST['area_sqft']) ? $_POST['area_sqft'] : null;
            $year_built = !empty($_POST['year_built']) ? $_POST['year_built'] : null;
            $lot_size = !empty($_POST['lot_size_sqft']) ? $_POST['lot_size_sqft'] : null;
            $address = $_POST['address'];
            $city = $_POST['city'];
            $state = $_POST['state'];
            $postal_code = $_POST['postal_code'];
            $country = !empty($_POST['country']) ? $_POST['country'] : 'USA';
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            $featured = isset($_POST['featured']) ? 1 : 0;
            
            $stmt->bind_param(
                'ssssdsdddssssssddi',
                $title, $description, $type, $status, $price, $bedrooms, $bathrooms,
                $area, $year_built, $lot_size, $address, $city, $state, $postal_code,
                $country, $latitude, $longitude, $featured
            );
            
            $stmt->execute();
            $property_id = $conn->insert_id;
            
            // Assign agent if selected
            if (!empty($_POST['agent_id'])) {
                $listingStmt = $conn->prepare("INSERT INTO property_listings (
                    property_id, agent_id, list_date, expiration_date, commission_percentage, exclusive
                ) VALUES (?, ?, CURDATE(), ?, ?, ?)");
                
                $agent_id = $_POST['agent_id'];
                $expiration_date = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;
                $commission = !empty($_POST['commission_percentage']) ? $_POST['commission_percentage'] : null;
                $exclusive = isset($_POST['exclusive']) ? 1 : 0;
                
                $listingStmt->bind_param(
                    'iisdi',
                    $property_id, $agent_id, $expiration_date, $commission, $exclusive
                );
                
                $listingStmt->execute();
            }
            
            // Add property features if any selected
            if (!empty($_POST['features']) && is_array($_POST['features'])) {
                $featureStmt = $conn->prepare("INSERT INTO property_has_features (property_id, feature_id) VALUES (?, ?)");
                
                foreach ($_POST['features'] as $feature_id) {
                    $featureStmt->bind_param('ii', $property_id, $feature_id);
                    $featureStmt->execute();
                }
            }
            
            // Handle image uploads
            if (!empty($_FILES['property_images']['name'][0])) {
                $upload_dir = '../uploads/properties/';
                
                // Create directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $imageStmt = $conn->prepare("INSERT INTO property_images (
                    property_id, image_url, caption, is_primary, display_order
                ) VALUES (?, ?, ?, ?, ?)");
                
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $max_size = 5 * 1024 * 1024; // 5MB
                
                for ($i = 0; $i < count($_FILES['property_images']['name']); $i++) {
                    if ($_FILES['property_images']['error'][$i] === UPLOAD_ERR_OK) {
                        $tmp_name = $_FILES['property_images']['tmp_name'][$i];
                        $name = $_FILES['property_images']['name'][$i];
                        $type = $_FILES['property_images']['type'][$i];
                        $size = $_FILES['property_images']['size'][$i];
                        
                        // Validate file
                        if (!in_array($type, $allowed_types)) {
                            $errors[] = "File type not allowed: " . $name;
                            continue;
                        }
                        
                        if ($size > $max_size) {
                            $errors[] = "File too large: " . $name;
                            continue;
                        }
                        
                        // Generate unique filename
                        $ext = pathinfo($name, PATHINFO_EXTENSION);
                        $unique_name = uniqid('property_') . '.' . $ext;
                        $destination = $upload_dir . $unique_name;
                        
                        if (move_uploaded_file($tmp_name, $destination)) {
                            $image_url = 'uploads/properties/' . $unique_name;
                            $caption = !empty($_POST['image_captions'][$i]) ? $_POST['image_captions'][$i] : null;
                            $is_primary = ($i === 0) ? 1 : 0; // First image is primary
                            $display_order = $i;
                            
                            $imageStmt->bind_param(
                                'issii',
                                $property_id, $image_url, $caption, $is_primary, $display_order
                            );
                            
                            $imageStmt->execute();
                        } else {
                            $errors[] = "Failed to upload: " . $name;
                        }
                    }
                }
            }
            
            // Commit transaction if no errors
            if (empty($errors)) {
                $conn->commit();
                $success_message = "Property added successfully!";
                
                // Redirect to property list after short delay
                header("Refresh: 2; URL=properties.php");
            } else {
                // Rollback if there were errors during file uploads
                $conn->rollback();
            }
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Property - PrimeEstate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Nunito:wght@300;400;500;600;700&display=swap');
        
        :root {
            --primary: #4338ca;
            --primary-light: #6366f1;
            --primary-dark: #3730a3;
            --secondary: #0ea5e9;
            --accent: #f59e0b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --dark: #1e293b;
        }
        
        body {
            font-family: 'Nunito', sans-serif;
            background-color: #f1f5f9;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Montserrat', sans-serif;
        }
        
        .dashboard-card {
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            background-color: white;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .sidebar-link {
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        
        .sidebar-link:hover {
            background-color: rgba(99, 102, 241, 0.1);
        }
        
        .sidebar-link.active {
            background-color: var(--primary);
            color: white;
        }
        
        .sidebar-link.active i {
            color: white;
        }
        
        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #c7d2fe;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }
        
        /* Animation for the cards */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translate3d(0, 20px, 0);
            }
            to {
                opacity: 1;
                transform: translate3d(0, 0, 0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
    </style>
</head>
<body class="min-h-screen flex flex-col md:flex-row">
    <!-- Sidebar - Hidden on mobile by default -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 transform -translate-x-full md:relative md:translate-x-0 bg-white shadow-lg transition-transform duration-300 ease-in-out">
        <div class="flex flex-col h-full">
            <!-- Logo and brand -->
            <div class="flex items-center justify-center p-4 border-b">
                <a href="../index.php" class="flex items-center space-x-2">
                    <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-blue-600 rounded-lg flex items-center justify-center shadow-lg">
                        <i class="fas fa-home text-white text-lg"></i>
                    </div>
                    <span class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-blue-600">PrimeEstate</span>
                </a>
            </div>
            
            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto py-4 px-3">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider ml-4 mt-2 mb-2">Main</p>
                
                <a href="dashboard.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-tachometer-alt text-indigo-600 w-5"></i>
                    <span class="ml-3">Dashboard</span>
                </a>
                
                <a href="properties.php" class="sidebar-link active flex items-center px-4 py-3 my-1">
                    <i class="fas fa-building text-indigo-600 w-5"></i>
                    <span class="ml-3">Properties</span>
                </a>
                
                <a href="users.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-users text-indigo-600 w-5"></i>
                    <span class="ml-3">Users</span>
                </a>
                
                <a href="agents.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-user-tie text-indigo-600 w-5"></i>
                    <span class="ml-3">Agents</span>
                </a>
                
                <a href="inquiries.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-envelope text-indigo-600 w-5"></i>
                    <span class="ml-3">Inquiries</span>
                </a>
                
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider ml-4 mt-6 mb-2">Content</p>
                
                <a href="blog.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-blog text-indigo-600 w-5"></i>
                    <span class="ml-3">Blog Posts</span>
                </a>
                
                <a href="reviews.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-star text-indigo-600 w-5"></i>
                    <span class="ml-3">Reviews</span>
                </a>
                
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider ml-4 mt-6 mb-2">System</p>
                
                <a href="settings.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-cog text-indigo-600 w-5"></i>
                    <span class="ml-3">Settings</span>
                </a>
            </nav>
            
            <!-- User profile -->
            <div class="border-t p-4">
                <div class="flex items-center">
                    <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
                        <i class="fas fa-user text-indigo-600"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium text-sm"><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></p>
                        <p class="text-xs text-gray-500">Administrator</p>
                    </div>
                    <a href="../logout.php" class="ml-auto text-gray-400 hover:text-gray-600" title="Sign Out">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </aside>
    
    <!-- Main Content -->
    <div class="flex-1 md:ml-0">
        <!-- Top navbar -->
        <header class="bg-white shadow-sm sticky top-0 z-40">
            <div class="flex items-center justify-between p-4">
                <!-- Mobile menu button -->
                <button id="mobile-menu-button" type="button" class="md:hidden text-gray-600 focus:outline-none">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Add New Property</h1>
                
                <!-- User dropdown and notifications for mobile - shown on medium screens and up -->
                <div class="hidden md:flex items-center space-x-4">
                    <div class="relative">
                        <button class="text-gray-600 hover:text-gray-800 focus:outline-none">
                            <i class="fas fa-bell text-xl"></i>
                            <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"></span>
                        </button>
                    </div>
                    
                    <div class="relative">
                        <button class="text-gray-600 hover:text-gray-800 focus:outline-none">
                            <i class="fas fa-envelope text-xl"></i>
                            <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"></span>
                        </button>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Page content -->
        <main class="p-4 md:p-6">
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p class="font-bold">Please correct the following errors:</p>
                    <ul class="list-disc ml-5">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                    <p><?php echo $success_message; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Back Button -->
            <div class="mb-6">
                <a href="properties.php" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-medium rounded-md transition-colors duration-300">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Properties
                </a>
            </div>
            
            <!-- Property Form -->
            <form action="" method="POST" enctype="multipart/form-data" class="space-y-6">
                <!-- Basic Information -->
                <div class="dashboard-card p-6 fade-in-up">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-info-circle text-indigo-600 mr-2"></i>Basic Information
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-1">
                                Property Title <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="title" id="title" required 
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" 
                                   placeholder="Enter property title" value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                        </div>
                        
                        <div>
                            <label for="property_type" class="block text-sm font-medium text-gray-700 mb-1">
                                Property Type <span class="text-red-500">*</span>
                            </label>
                            <select name="property_type" id="property_type" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Select property type</option>
                                <option value="apartment" <?php echo (isset($_POST['property_type']) && $_POST['property_type'] === 'apartment') ? 'selected' : ''; ?>>Apartment</option>
                                <option value="house" <?php echo (isset($_POST['property_type']) && $_POST['property_type'] === 'house') ? 'selected' : ''; ?>>House</option>
                                <option value="condo" <?php echo (isset($_POST['property_type']) && $_POST['property_type'] === 'condo') ? 'selected' : ''; ?>>Condo</option>
                                <option value="townhouse" <?php echo (isset($_POST['property_type']) && $_POST['property_type'] === 'townhouse') ? 'selected' : ''; ?>>Townhouse</option>
                                <option value="land" <?php echo (isset($_POST['property_type']) && $_POST['property_type'] === 'land') ? 'selected' : ''; ?>>Land</option>
                                <option value="commercial" <?php echo (isset($_POST['property_type']) && $_POST['property_type'] === 'commercial') ? 'selected' : ''; ?>>Commercial</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">
                                Listing Status <span class="text-red-500">*</span>
                            </label>
                            <select name="status" id="status" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Select status</option>
                                <option value="for_sale" <?php echo (isset($_POST['status']) && $_POST['status'] === 'for_sale') ? 'selected' : ''; ?>>For Sale</option>
                                <option value="for_rent" <?php echo (isset($_POST['status']) && $_POST['status'] === 'for_rent') ? 'selected' : ''; ?>>For Rent</option>
                                <option value="pending" <?php echo (isset($_POST['status']) && $_POST['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="sold" <?php echo (isset($_POST['status']) && $_POST['status'] === 'sold') ? 'selected' : ''; ?>>Sold</option>
                                <option value="rented" <?php echo (isset($_POST['status']) && $_POST['status'] === 'rented') ? 'selected' : ''; ?>>Rented</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="price" class="block text-sm font-medium text-gray-700 mb-1">
                                Price <span class="text-red-500">*</span>
                            </label>
                            <div class="mt-1 relative rounded-md shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500 sm:text-sm">$</span>
                                </div>
                                <input type="number" name="price" id="price" min="0" step="0.01" required
                                       class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-7 pr-12 sm:text-sm border-gray-300 rounded-md"
                                       placeholder="0.00" value="<?php echo isset($_POST['price']) ? htmlspecialchars($_POST['price']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
                                Description
                            </label>
                            <textarea name="description" id="description" rows="4"
                                     class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                     placeholder="Describe the property..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        </div>
                        
                        <div>
                            <div class="flex items-center">
                                <input id="featured" name="featured" type="checkbox" value="1" <?php echo (isset($_POST['featured']) && $_POST['featured'] === '1') ? 'checked' : ''; ?>
                                       class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                <label for="featured" class="ml-2 block text-sm text-gray-900">
                                    Feature this property (will be highlighted on the homepage)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Property Details -->
                <div class="dashboard-card p-6 fade-in-up delay-100">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-home text-indigo-600 mr-2"></i>Property Details
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="bedrooms" class="block text-sm font-medium text-gray-700 mb-1">
                                Bedrooms
                            </label>
                            <input type="number" name="bedrooms" id="bedrooms" min="0" step="1"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="Number of bedrooms" value="<?php echo isset($_POST['bedrooms']) ? htmlspecialchars($_POST['bedrooms']) : ''; ?>">
                        </div>
                        
                        <div>
                            <label for="bathrooms" class="block text-sm font-medium text-gray-700 mb-1">
                                Bathrooms
                            </label>
                            <input type="number" name="bathrooms" id="bathrooms" min="0" step="0.5"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="Number of bathrooms" value="<?php echo isset($_POST['bathrooms']) ? htmlspecialchars($_POST['bathrooms']) : ''; ?>">
                        </div>
                        
                        <div>
                            <label for="area_sqft" class="block text-sm font-medium text-gray-700 mb-1">
                                Living Area (sq ft)
                            </label>
                            <input type="number" name="area_sqft" id="area_sqft" min="0" step="1"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="Total living area" value="<?php echo isset($_POST['area_sqft']) ? htmlspecialchars($_POST['area_sqft']) : ''; ?>">
                        </div>
                        
                        <div>
                            <label for="lot_size_sqft" class="block text-sm font-medium text-gray-700 mb-1">
                                Lot Size (sq ft)
                            </label>
                            <input type="number" name="lot_size_sqft" id="lot_size_sqft" min="0" step="1"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="Total lot size" value="<?php echo isset($_POST['lot_size_sqft']) ? htmlspecialchars($_POST['lot_size_sqft']) : ''; ?>">
                        </div>
                        
                        <div>
                            <label for="year_built" class="block text-sm font-medium text-gray-700 mb-1">
                                Year Built
                            </label>
                            <input type="number" name="year_built" id="year_built" min="1800" max="<?php echo date('Y'); ?>" step="1"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="Year property was built" value="<?php echo isset($_POST['year_built']) ? htmlspecialchars($_POST['year_built']) : ''; ?>">
                        </div>
                    </div>
                    
                    <?php if (!empty($features)): ?>
                        <div class="mt-6">
    <label class="block text-sm font-medium text-gray-700 mb-2">
        Property Features
    </label>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
        <?php foreach ($features as $feature): ?>
            <div class="flex items-center">
                <input id="feature_<?php echo $feature['feature_id']; ?>" name="features[]" type="checkbox" value="<?php echo $feature['feature_id']; ?>"
                       <?php if (isset($_POST['features']) && in_array($feature['feature_id'], $_POST['features'])) echo 'checked'; ?>
                       class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                <label for="feature_<?php echo $feature['feature_id']; ?>" class="ml-2 block text-sm text-gray-900">
                    <?php echo htmlspecialchars($feature['name']); ?>
                </label>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
</div>

<!-- Location Information -->
<div class="dashboard-card p-6 fade-in-up delay-200">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">
        <i class="fas fa-map-marker-alt text-indigo-600 mr-2"></i>Location Information
    </h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label for="address" class="block text-sm font-medium text-gray-700 mb-1">
                Address <span class="text-red-500">*</span>
            </label>
            <input type="text" name="address" id="address" required
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                   placeholder="Street address" value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>">
        </div>
        
        <div>
            <label for="city" class="block text-sm font-medium text-gray-700 mb-1">
                City <span class="text-red-500">*</span>
            </label>
            <input type="text" name="city" id="city" required
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                   placeholder="City" value="<?php echo isset($_POST['city']) ? htmlspecialchars($_POST['city']) : ''; ?>">
        </div>
        
        <div>
            <label for="state" class="block text-sm font-medium text-gray-700 mb-1">
                State <span class="text-red-500">*</span>
            </label>
            <input type="text" name="state" id="state" required
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                   placeholder="State" value="<?php echo isset($_POST['state']) ? htmlspecialchars($_POST['state']) : ''; ?>">
        </div>
        
        <div>
            <label for="postal_code" class="block text-sm font-medium text-gray-700 mb-1">
                Postal Code <span class="text-red-500">*</span>
            </label>
            <input type="text" name="postal_code" id="postal_code" required
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                   placeholder="Postal code" value="<?php echo isset($_POST['postal_code']) ? htmlspecialchars($_POST['postal_code']) : ''; ?>">
        </div>
        
        <div>
            <label for="country" class="block text-sm font-medium text-gray-700 mb-1">
                Country
            </label>
            <input type="text" name="country" id="country"
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                   placeholder="Country" value="<?php echo isset($_POST['country']) ? htmlspecialchars($_POST['country']) : 'USA'; ?>">
        </div>
        
        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="latitude" class="block text-sm font-medium text-gray-700 mb-1">
                    Latitude
                </label>
                <input type="text" name="latitude" id="latitude"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                       placeholder="Optional geographic coordinate" value="<?php echo isset($_POST['latitude']) ? htmlspecialchars($_POST['latitude']) : ''; ?>">
            </div>
            
            <div>
                <label for="longitude" class="block text-sm font-medium text-gray-700 mb-1">
                    Longitude
                </label>
                <input type="text" name="longitude" id="longitude"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                       placeholder="Optional geographic coordinate" value="<?php echo isset($_POST['longitude']) ? htmlspecialchars($_POST['longitude']) : ''; ?>">
            </div>
        </div>
    </div>
</div>

<!-- Listing Agent Information -->
<div class="dashboard-card p-6 fade-in-up delay-200">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">
        <i class="fas fa-user-tie text-indigo-600 mr-2"></i>Listing Agent Information
    </h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label for="agent_id" class="block text-sm font-medium text-gray-700 mb-1">
                Assign Agent
            </label>
            <select name="agent_id" id="agent_id"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Select an agent (optional)</option>
                <?php foreach ($agents as $agent): ?>
                    <option value="<?php echo $agent['agent_id']; ?>" <?php echo (isset($_POST['agent_id']) && $_POST['agent_id'] == $agent['agent_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($agent['agent_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label for="commission_percentage" class="block text-sm font-medium text-gray-700 mb-1">
                Commission Percentage
            </label>
            <div class="mt-1 relative rounded-md shadow-sm">
                <input type="number" name="commission_percentage" id="commission_percentage" min="0" max="100" step="0.01"
                       class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pr-12 sm:text-sm border-gray-300 rounded-md"
                       placeholder="e.g., 3.5" value="<?php echo isset($_POST['commission_percentage']) ? htmlspecialchars($_POST['commission_percentage']) : ''; ?>">
                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                    <span class="text-gray-500 sm:text-sm">%</span>
                </div>
            </div>
        </div>
        
        <div>
            <label for="expiration_date" class="block text-sm font-medium text-gray-700 mb-1">
                Listing Expiration Date
            </label>
            <input type="date" name="expiration_date" id="expiration_date"
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                   value="<?php echo isset($_POST['expiration_date']) ? htmlspecialchars($_POST['expiration_date']) : ''; ?>">
        </div>
        
        <div class="flex items-center">
            <input id="exclusive" name="exclusive" type="checkbox" value="1" <?php echo (isset($_POST['exclusive']) && $_POST['exclusive'] === '1') ? 'checked' : ''; ?>
                   class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
            <label for="exclusive" class="ml-2 block text-sm text-gray-900">
                Exclusive Listing
            </label>
        </div>
    </div>
</div>

<!-- Property Images -->
<div class="dashboard-card p-6 fade-in-up delay-300">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">
        <i class="fas fa-images text-indigo-600 mr-2"></i>Property Images
    </h2>
    
    <div class="space-y-4">
        <div class="flex items-center justify-center w-full">
            <label for="property_images" class="w-full flex flex-col items-center px-4 py-6 bg-white rounded-lg border-2 border-dashed border-gray-300 cursor-pointer hover:bg-gray-50">
                <div class="flex flex-col items-center justify-center pt-5 pb-6">
                    <i class="fas fa-cloud-upload-alt text-3xl text-indigo-500 mb-2"></i>
                    <p class="mb-2 text-sm text-gray-500">
                        <span class="font-semibold">Upload property images</span>
                    </p>
                    <p class="text-xs text-gray-500">
                        PNG, JPG, GIF, WEBP up to 5MB (multiple files allowed)
                    </p>
                </div>
                <input id="property_images" name="property_images[]" type="file" multiple accept="image/*" class="hidden">
            </label>
        </div>
        
        <div id="image_preview" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <!-- Image previews will be displayed here via JavaScript -->
        </div>
        
        <p class="text-xs text-gray-500 mt-2">
            <i class="fas fa-info-circle mr-1"></i>
            The first uploaded image will be set as the featured image.
        </p>
    </div>
</div>

<!-- Submit Button -->
<div class="flex justify-end pt-6">
    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-300">
        <i class="fas fa-save mr-2"></i>Add Property
    </button>
</div>
</form>
</main>

<footer class="mt-auto py-4 px-6 bg-white border-t">
    <div class="text-center text-gray-500 text-sm">
        &copy; <?php echo date('Y'); ?> PrimeEstate. All rights reserved.
    </div>
</footer>
</div>

<script>
    // Mobile menu toggle
    const mobileMenuButton = document.getElementById('mobile-menu-button');
    const sidebar = document.getElementById('sidebar');
    
    mobileMenuButton.addEventListener('click', () => {
        sidebar.classList.toggle('-translate-x-full');
    });
    
    // Image preview functionality
    const imageInput = document.getElementById('property_images');
    const imagePreview = document.getElementById('image_preview');
    
    imageInput.addEventListener('change', function() {
        imagePreview.innerHTML = '';
        
        if (this.files) {
            for (let i = 0; i < this.files.length; i++) {
                const file = this.files[i];
                
                if (!file.type.match('image.*')) {
                    continue;
                }
                
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const previewCard = document.createElement('div');
                    previewCard.className = 'relative bg-white rounded-lg shadow overflow-hidden';
                    
                    const image = document.createElement('img');
                    image.src = e.target.result;
                    image.className = 'w-full h-40 object-cover';
                    image.alt = 'Property image preview';
                    
                    const captionContainer = document.createElement('div');
                    captionContainer.className = 'p-2';
                    
                    const caption = document.createElement('input');
                    caption.type = 'text';
                    caption.name = 'image_captions[]';
                    caption.placeholder = 'Image caption';
                    caption.className = 'w-full text-sm p-1 border border-gray-300 rounded';
                    
                    captionContainer.appendChild(caption);
                    previewCard.appendChild(image);
                    previewCard.appendChild(captionContainer);
                    
                    imagePreview.appendChild(previewCard);
                };
                
                reader.readAsDataURL(file);
            }
        }
    });
</script>
</body>
</html>