<?php
require_once '../connection.php';
session_start();

// Check if user is logged in and is an agent
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'agent') {
    header("Location: ../login.php");
    exit();
}

// Get agent information
$user_id = $_SESSION['user_id'];
$agent_query = $conn->prepare("SELECT a.* FROM agents a WHERE a.user_id = ?");
$agent_query->bind_param("i", $user_id);
$agent_query->execute();
$agent_result = $agent_query->get_result();
$agent = $agent_result->fetch_assoc();
$agent_id = $agent['agent_id'];

// Get user profile information
$user_query = $conn->prepare("SELECT first_name, last_name, email, phone, profile_image FROM users WHERE user_id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user = $user_result->fetch_assoc();

// Format agent name
$agent_name = $user['first_name'] . ' ' . $user['last_name'];

// Get available property features
$features_query = $conn->query("SELECT * FROM property_features ORDER BY name");
$features = $features_query->fetch_all(MYSQLI_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Property data
        $title = $_POST['title'];
        $description = $_POST['description'];
        $property_type = $_POST['property_type'];
        $status = $_POST['status'];
        $price = $_POST['price'];
        $bedrooms = !empty($_POST['bedrooms']) ? $_POST['bedrooms'] : null;
        $bathrooms = !empty($_POST['bathrooms']) ? $_POST['bathrooms'] : null;
        $area_sqft = !empty($_POST['area_sqft']) ? $_POST['area_sqft'] : null;
        $year_built = !empty($_POST['year_built']) ? $_POST['year_built'] : null;
        $lot_size = !empty($_POST['lot_size']) ? $_POST['lot_size'] : null;
        $address = $_POST['address'];
        $city = $_POST['city'];
        $state = $_POST['state'];
        $postal_code = $_POST['postal_code'];
        $country = $_POST['country'];
        $featured = isset($_POST['featured']) ? 1 : 0;
        
        // Optional coordinates
        $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
        $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
        
        // Insert property
        $property_query = $conn->prepare("
            INSERT INTO properties (
                title, description, property_type, status, price, 
                bedrooms, bathrooms, area_sqft, year_built, lot_size_sqft,
                address, city, state, postal_code, country, 
                latitude, longitude, featured
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?
            )
        ");
        
        $property_query->bind_param(
            "sssssiidissssssddi",
            $title, $description, $property_type, $status, $price,
            $bedrooms, $bathrooms, $area_sqft, $year_built, $lot_size,
            $address, $city, $state, $postal_code, $country,
            $latitude, $longitude, $featured
        );
        
        $property_query->execute();
        $property_id = $conn->insert_id;
        
        // Insert listing
        $list_date = date('Y-m-d');
        $expiration_date = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;
        $commission = !empty($_POST['commission']) ? $_POST['commission'] : null;
        $exclusive = isset($_POST['exclusive']) ? 1 : 0;
        
        $listing_query = $conn->prepare("
            INSERT INTO property_listings (
                property_id, agent_id, list_date, expiration_date, 
                commission_percentage, exclusive
            ) VALUES (
                ?, ?, ?, ?, ?, ?
            )
        ");
        
        $listing_query->bind_param(
            "iissdi",
            $property_id, $agent_id, $list_date, $expiration_date,
            $commission, $exclusive
        );
        
        $listing_query->execute();
        
        // Handle property features
        if (isset($_POST['features']) && is_array($_POST['features'])) {
            $feature_query = $conn->prepare("
                INSERT INTO property_has_features (property_id, feature_id)
                VALUES (?, ?)
            ");
            
            foreach ($_POST['features'] as $feature_id) {
                $feature_query->bind_param("ii", $property_id, $feature_id);
                $feature_query->execute();
            }
        }
        
        // Handle image uploads
        $upload_dir = '../uploads/properties/' . $property_id . '/';
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Process image uploads
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            $image_query = $conn->prepare("
                INSERT INTO property_images (
                    property_id, image_url, caption, is_primary, display_order
                ) VALUES (
                    ?, ?, ?, ?, ?
                )
            ");
            
            $total_images = count($_FILES['images']['name']);
            
            for ($i = 0; $i < $total_images; $i++) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['images']['tmp_name'][$i];
                    $name = basename($_FILES['images']['name'][$i]);
                    $type = $_FILES['images']['type'][$i];
                    $size = $_FILES['images']['size'][$i];
                    
                    // Validate file type and size
                    if (!in_array($type, $allowed_types)) {
                        throw new Exception("Invalid file type for image #{$i}. Only JPG and PNG are allowed.");
                    }
                    
                    if ($size > $max_size) {
                        throw new Exception("Image #{$i} exceeds the maximum file size of 5MB.");
                    }
                    
                    // Generate unique filename
                    $extension = pathinfo($name, PATHINFO_EXTENSION);
                    $new_filename = uniqid() . '.' . $extension;
                    $destination = $upload_dir . $new_filename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($tmp_name, $destination)) {
                        $image_url = 'uploads/properties/' . $property_id . '/' . $new_filename;
                        $caption = isset($_POST['captions'][$i]) ? $_POST['captions'][$i] : null;
                        $is_primary = ($i === 0) ? 1 : 0; // First image is primary by default
                        
                        $image_query->bind_param(
                            "issii",
                            $property_id, $image_url, $caption, $is_primary, $i
                        );
                        
                        $image_query->execute();
                    } else {
                        throw new Exception("Failed to upload image #{$i}.");
                    }
                } elseif ($_FILES['images']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    throw new Exception("Error uploading image #{$i}. Error code: " . $_FILES['images']['error'][$i]);
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Success message and redirect
        $_SESSION['success_message'] = "Property listing has been added successfully!";
        header("Location: listings.php");
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error_message = $e->getMessage();
    }
}

// Get pending inquiries count (for notification badge)
$inquiries_query = $conn->prepare("
    SELECT COUNT(*) as pending_inquiries 
    FROM inquiries i 
    JOIN properties p ON i.property_id = p.property_id
    JOIN property_listings pl ON p.property_id = pl.property_id
    WHERE pl.agent_id = ? AND i.status = 'new'
");
$inquiries_query->bind_param("i", $agent_id);
$inquiries_query->execute();
$inquiries_result = $inquiries_query->get_result();
$pending_inquiries = $inquiries_result->fetch_assoc()['pending_inquiries'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Listing - PrimeEstate</title>
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
        
        .card {
            background-color: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .sidebar-link {
            transition: all 0.2s ease;
        }
        
        .sidebar-link:hover, .sidebar-link.active {
            background-color: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }
        
        .sidebar-link.active {
            border-left: 4px solid var(--primary);
        }
        
        /* Custom scrollbar */
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
        
        /* Mobile menu animation */
        .mobile-menu {
            transition: transform 0.3s ease;
        }
        
        .mobile-menu.hidden {
            transform: translateX(-100%);
        }
        
        /* Preview image thumbnail */
        .image-preview {
            width: 150px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
        }
        
        /* Toggle switch */
        .toggle-checkbox:checked {
            right: 0;
            border-color: #4338ca;
        }
        
        .toggle-checkbox:checked + .toggle-label {
            background-color: #4338ca;
        }
    </style>
</head>
<body>
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar - Desktop -->
        <div class="hidden md:flex md:flex-shrink-0">
            <div class="flex flex-col w-64 border-r border-gray-200 bg-white">
                <div class="flex items-center justify-center h-16 px-4 border-b border-gray-200">
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-gradient-to-br from-indigo-500 to-blue-600 rounded-lg flex items-center justify-center shadow-lg">
                            <i class="fas fa-home text-white text-sm"></i>
                        </div>
                        <h1 class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-blue-600">PrimeEstate</h1>
                    </div>
                </div>
                
                <div class="flex flex-col flex-grow pt-5 pb-4 overflow-y-auto">
                    <div class="flex-grow flex flex-col">
                        <nav class="flex-1 px-2 space-y-1">
                            <a href="dashboard.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-tachometer-alt w-5 h-5 mr-3 text-gray-500"></i>
                                Dashboard
                            </a>
                            <a href="listings.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
                                <i class="fas fa-list w-5 h-5 mr-3 text-gray-500"></i>
                                My Listings
                            </a>
                            <a href="inquiries.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-question-circle w-5 h-5 mr-3 text-gray-500"></i>
                                Inquiries
                                <?php if ($pending_inquiries > 0): ?>
                                <span class="ml-auto px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800"><?php echo $pending_inquiries; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="viewings.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-calendar w-5 h-5 mr-3 text-gray-500"></i>
                                Viewings
                            </a>
                            <a href="transactions.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-exchange-alt w-5 h-5 mr-3 text-gray-500"></i>
                                Transactions
                            </a>
                            <a href="clients.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-users w-5 h-5 mr-3 text-gray-500"></i>
                                Clients
                            </a>
                            <a href="reviews.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-star w-5 h-5 mr-3 text-gray-500"></i>
                                Reviews
                            </a>
                            <a href="profile.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-user-circle w-5 h-5 mr-3 text-gray-500"></i>
                                My Profile
                            </a>
                        </nav>
                    </div>
                    <div class="px-3 py-3">
                        <a href="../logout.php" class="flex items-center px-4 py-2 text-sm font-medium text-red-600 rounded-md hover:bg-red-50 transition">
                            <i class="fas fa-sign-out-alt w-5 h-5 mr-3"></i>
                            Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex flex-col flex-1 overflow-hidden">
            <!-- Top Navigation Bar -->
            <div class="bg-white border-b border-gray-200 flex items-center justify-between p-4 md:px-8">
                <!-- Mobile menu button -->
                <button type="button" class="md:hidden text-gray-500 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500" id="mobile-menu-button">
                    <i class="fas fa-bars"></i>
                </button>
                
                <!-- Page Title -->
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Add New Listing</h1>
                
                <!-- User Menu -->
                <div class="relative">
                    <button type="button" class="flex items-center space-x-2 focus:outline-none" id="user-menu-button">
                    <div class="flex items-center">
                            <span class="mr-2"><?php echo htmlspecialchars($agent_name); ?></span>
                            <i class="fas fa-chevron-down text-gray-500"></i>
                        </div>
                    </button>
                    
                    <!-- Dropdown Menu (Hidden by default) -->
                    <div class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50" id="user-menu-dropdown">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Your Profile</a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                        <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">Sign out</a>
                    </div>
                </div>
            </div>
            
            <!-- Mobile Sidebar (Hidden by default) -->
            <div class="md:hidden fixed inset-0 z-40 hidden mobile-menu" id="mobile-menu">
                <div class="fixed inset-0 bg-gray-600 bg-opacity-75" id="mobile-menu-overlay"></div>
                <div class="relative flex-1 flex flex-col max-w-xs w-full bg-white">
                    <div class="absolute top-0 right-0 -mr-12 pt-2">
                        <button type="button" class="ml-1 flex items-center justify-center h-10 w-10 rounded-full focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white" id="close-mobile-menu">
                            <span class="sr-only">Close sidebar</span>
                            <i class="fas fa-times text-white"></i>
                        </button>
                    </div>
                    <div class="flex-1 h-0 pt-5 pb-4 overflow-y-auto">
                        <div class="flex-shrink-0 flex items-center px-4">
                            <div class="flex items-center space-x-2">
                                <div class="w-8 h-8 bg-gradient-to-br from-indigo-500 to-blue-600 rounded-lg flex items-center justify-center shadow-lg">
                                    <i class="fas fa-home text-white text-sm"></i>
                                </div>
                                <h1 class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-blue-600">PrimeEstate</h1>
                            </div>
                        </div>
                        <nav class="mt-5 px-2 space-y-1">
                            <a href="dashboard.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-tachometer-alt w-5 h-5 mr-3 text-gray-500"></i>
                                Dashboard
                            </a>
                            <a href="listings.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
                                <i class="fas fa-list w-5 h-5 mr-3 text-gray-500"></i>
                                My Listings
                            </a>
                            <a href="inquiries.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-question-circle w-5 h-5 mr-3 text-gray-500"></i>
                                Inquiries
                                <?php if ($pending_inquiries > 0): ?>
                                <span class="ml-auto px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800"><?php echo $pending_inquiries; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="viewings.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-calendar w-5 h-5 mr-3 text-gray-500"></i>
                                Viewings
                            </a>
                            <a href="transactions.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-exchange-alt w-5 h-5 mr-3 text-gray-500"></i>
                                Transactions
                            </a>
                            <a href="clients.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-users w-5 h-5 mr-3 text-gray-500"></i>
                                Clients
                            </a>
                            <a href="reviews.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-star w-5 h-5 mr-3 text-gray-500"></i>
                                Reviews
                            </a>
                            <a href="profile.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-user-circle w-5 h-5 mr-3 text-gray-500"></i>
                                My Profile
                            </a>
                            <a href="../logout.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-red-600 rounded-md">
                                <i class="fas fa-sign-out-alt w-5 h-5 mr-3"></i>
                                Sign Out
                            </a>
                        </nav>
                    </div>
                </div>
            </div>
            
            <!-- Main Content Area -->
            <main class="flex-1 overflow-y-auto p-4 md:p-6 bg-gray-50">
                <!-- Error Message -->
                <?php if (isset($error_message)): ?>
                <div class="mb-4 p-4 bg-red-100 border border-red-200 text-red-700 rounded-md flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo $error_message; ?>
                </div>
                <?php endif; ?>
                
                <!-- Page Header -->
                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Add New Property Listing</h2>
                        <p class="text-gray-600 mt-1">Fill in the details to create a new property listing</p>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <a href="listings.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            Back to Listings
                        </a>
                    </div>
                </div>
                
                <!-- Listing Form -->
                <form method="POST" action="add-listing.php" enctype="multipart/form-data" class="space-y-6">
                    <!-- Basic Information -->
                    <div class="card p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Property Title *</label>
                                <input type="text" id="title" name="title" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="e.g. Luxurious Waterfront Condo">
                            </div>
                            
                            <div>
                                <label for="property_type" class="block text-sm font-medium text-gray-700 mb-1">Property Type *</label>
                                <select id="property_type" name="property_type" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="">Select Property Type</option>
                                    <option value="apartment">Apartment</option>
                                    <option value="house">House</option>
                                    <option value="condo">Condo</option>
                                    <option value="townhouse">Townhouse</option>
                                    <option value="land">Land</option>
                                    <option value="commercial">Commercial</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Listing Status *</label>
                                <select id="status" name="status" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="">Select Status</option>
                                    <option value="for_sale">For Sale</option>
                                    <option value="for_rent">For Rent</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="price" class="block text-sm font-medium text-gray-700 mb-1">Price ($) *</label>
                                <input type="number" id="price" name="price" required min="0" step="0.01"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Enter property price">
                                <p class="mt-1 text-xs text-gray-500">For rentals, enter the monthly rent</p>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description *</label>
                                <textarea id="description" name="description" rows="5" required
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                          placeholder="Detailed description of the property..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Property Details -->
                    <div class="card p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Property Details</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="bedrooms" class="block text-sm font-medium text-gray-700 mb-1">Bedrooms</label>
                                <input type="number" id="bedrooms" name="bedrooms" min="0"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" placeholder="Number of bedrooms">
                            </div>
                            
                            <div>
                                <label for="bathrooms" class="block text-sm font-medium text-gray-700 mb-1">Bathrooms</label>
                                <input type="number" id="bathrooms" name="bathrooms" min="0" step="0.5"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Number of bathrooms">
                            </div>
                            
                            <div>
                                <label for="area_sqft" class="block text-sm font-medium text-gray-700 mb-1">Area (sq ft)</label>
                                <input type="number" id="area_sqft" name="area_sqft" min="0" step="0.01"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Total living area">
                            </div>
                            
                            <div>
                                <label for="year_built" class="block text-sm font-medium text-gray-700 mb-1">Year Built</label>
                                <input type="number" id="year_built" name="year_built" min="1800" max="<?php echo date('Y'); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Year of construction">
                            </div>
                            
                            <div>
                                <label for="lot_size" class="block text-sm font-medium text-gray-700 mb-1">Lot Size (sq ft)</label>
                                <input type="number" id="lot_size" name="lot_size" min="0" step="0.01"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Size of the lot">
                            </div>
                            
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label for="featured" class="block text-sm font-medium text-gray-700">Featured Property</label>
                                    <div class="relative inline-block w-10 mr-2 align-middle select-none">
                                        <input type="checkbox" name="featured" id="featured" class="toggle-checkbox absolute block w-6 h-6 rounded-full bg-white border-4 appearance-none cursor-pointer"/>
                                        <label for="featured" class="toggle-label block overflow-hidden h-6 rounded-full bg-gray-300 cursor-pointer"></label>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500">Feature this property at the top of listings</p>
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <h4 class="text-md font-medium text-gray-700 mb-2">Property Features</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <?php foreach ($features as $feature): ?>
                                <div class="flex items-center">
                                    <input id="feature-<?php echo $feature['feature_id']; ?>" name="features[]" value="<?php echo $feature['feature_id']; ?>" type="checkbox" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                    <label for="feature-<?php echo $feature['feature_id']; ?>" class="ml-2 block text-sm text-gray-700">
                                        <?php echo htmlspecialchars($feature['name']); ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Location Information -->
                    <div class="card p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Location Information</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Street Address *</label>
                                <input type="text" id="address" name="address" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Enter street address">
                            </div>
                            
                            <div>
                                <label for="city" class="block text-sm font-medium text-gray-700 mb-1">City *</label>
                                <input type="text" id="city" name="city" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Enter city">
                            </div>
                            
                            <div>
                                <label for="state" class="block text-sm font-medium text-gray-700 mb-1">State/Province *</label>
                                <input type="text" id="state" name="state" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Enter state or province">
                            </div>
                            
                            <div>
                                <label for="postal_code" class="block text-sm font-medium text-gray-700 mb-1">Postal/ZIP Code *</label>
                                <input type="text" id="postal_code" name="postal_code" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Enter postal code">
                            </div>
                            
                            <div>
                                <label for="country" class="block text-sm font-medium text-gray-700 mb-1">Country *</label>
                                <input type="text" id="country" name="country" required value="USA"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Enter country">
                            </div>
                            
                            <div>
                                <label for="latitude" class="block text-sm font-medium text-gray-700 mb-1">Latitude</label>
                                <input type="text" id="latitude" name="latitude"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Optional - for map display">
                            </div>
                            
                            <div>
                                <label for="longitude" class="block text-sm font-medium text-gray-700 mb-1">Longitude</label>
                                <input type="text" id="longitude" name="longitude"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Optional - for map display">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Listing Details -->
                    <div class="card p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Listing Details</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="expiration_date" class="block text-sm font-medium text-gray-700 mb-1">Listing Expiration Date</label>
                                <input type="date" id="expiration_date" name="expiration_date"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                                <p class="mt-1 text-xs text-gray-500">Leave blank if no expiration date</p>
                            </div>
                            
                            <div>
                                <label for="commission" class="block text-sm font-medium text-gray-700 mb-1">Commission (%)</label>
                                <input type="number" id="commission" name="commission" min="0" max="100" step="0.01"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="Your commission percentage">
                            </div>
                            
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label for="exclusive" class="block text-sm font-medium text-gray-700">Exclusive Listing</label>
                                    <div class="relative inline-block w-10 mr-2 align-middle select-none">
                                        <input type="checkbox" name="exclusive" id="exclusive" class="toggle-checkbox absolute block w-6 h-6 rounded-full bg-white border-4 appearance-none cursor-pointer"/>
                                        <label for="exclusive" class="toggle-label block overflow-hidden h-6 rounded-full bg-gray-300 cursor-pointer"></label>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500">You are the only agent representing this property</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Property Images -->
                    <div class="card p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Property Images</h3>
                        
                        <div id="image-upload-container">
                            <div class="image-upload-group mb-4">
                                <div class="mb-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Image 1 (Primary)</label>
                                    <input type="file" name="images[]" class="image-upload w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" accept="image/jpeg,image/png">
                                </div>
                                
                                <div class="flex items-center">
                                    <div class="flex-1">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Caption (Optional)</label>
                                        <input type="text" name="captions[]" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" placeholder="Enter image caption">
                                    </div>
                                    <div class="w-24 flex items-center justify-center ml-4">
                                        <div class="image-preview-container hidden">
                                            <img src="#" alt="Preview" class="image-preview">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-center mt-4">
                            <button type="button" id="add-more-images" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <i class="fas fa-plus mr-2"></i>
                                Add More Images
                            </button>
                        </div>
                        
                        <p class="mt-2 text-xs text-gray-500 text-center">Upload up to 10 images. Maximum size: 5MB per image. Accepted formats: JPG, PNG.</p>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="flex justify-end space-x-3">
                        <a href="listings.php" class="px-6 py-3 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Cancel
                        </a>
                        <button type="submit" class="px-6 py-3 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Create Listing
                        </button>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <script>
        // Toggle user menu dropdown
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenuDropdown = document.getElementById('user-menu-dropdown');
        
        userMenuButton.addEventListener('click', () => {
            userMenuDropdown.classList.toggle('hidden');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', (event) => {
            if (!userMenuButton.contains(event.target) && !userMenuDropdown.contains(event.target)) {
                userMenuDropdown.classList.add('hidden');
            }
        });
        
        // Mobile menu functionality
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const closeMobileMenu = document.getElementById('close-mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const mobileMenu = document.getElementById('mobile-menu');
        
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });
        
        closeMobileMenu.addEventListener('click', () => {
            mobileMenu.classList.add('hidden');
        });
        
        mobileMenuOverlay.addEventListener('click', () => {
            mobileMenu.classList.add('hidden');
        });
        
        // Image preview functionality
        document.addEventListener('DOMContentLoaded', function() {
            const imageUploadContainer = document.getElementById('image-upload-container');
            const addMoreImagesBtn = document.getElementById('add-more-images');
            let imageCount = 1;
            
            // Function to handle file input change
            function handleFileInputChange(input) {
                const previewContainer = input.closest('.image-upload-group').querySelector('.image-preview-container');
                const preview = previewContainer.querySelector('.image-preview');
                
                if (input.files && input.files[0]) {
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        preview.src = e.target.result;
                        previewContainer.classList.remove('hidden');
                    }
                    
                    reader.readAsDataURL(input.files[0]);
                } else {
                    preview.src = '#';
                    previewContainer.classList.add('hidden');
                }
            }
            
            // Add event listener to existing file input
            document.querySelector('.image-upload').addEventListener('change', function() {
                handleFileInputChange(this);
            });
            
            // Add more images button functionality
            addMoreImagesBtn.addEventListener('click', function() {
                if (imageCount >= 10) {
                    alert('You can upload maximum 10 images.');
                    return;
                }
                
                imageCount++;
                
                const newImageGroup = document.createElement('div');
                newImageGroup.className = 'image-upload-group mb-4';
                newImageGroup.innerHTML = `
                    <div class="mb-2 flex justify-between items-center">
                        <label class="block text-sm font-medium text-gray-700">Image ${imageCount}</label>
                        <button type="button" class="remove-image text-red-500 hover:text-red-700">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </div>
                    <div class="mb-2">
                        <input type="file" name="images[]" class="image-upload w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" accept="image/jpeg,image/png">
                    </div>
                    <div class="flex items-center">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Caption (Optional)</label>
                            <input type="text" name="captions[]" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" placeholder="Enter image caption">
                        </div>
                        <div class="w-24 flex items-center justify-center ml-4">
                            <div class="image-preview-container hidden">
                                <img src="#" alt="Preview" class="image-preview">
                            </div>
                        </div>
                    </div>
                `;
                
                imageUploadContainer.appendChild(newImageGroup);
                
                // Add event listener to the new file input
                const newFileInput = newImageGroup.querySelector('.image-upload');
                newFileInput.addEventListener('change', function() {
                    handleFileInputChange(this);
                });
                
                // Add event listener to remove button
                const removeButton = newImageGroup.querySelector('.remove-image');
                removeButton.addEventListener('click', function() {
                    imageUploadContainer.removeChild(newImageGroup);
                    imageCount--;
                });
            });
        });
    </script>
</body>
</html>