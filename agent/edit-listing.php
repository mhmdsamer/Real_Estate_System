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

// Check if listing ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: listings.php");
    exit();
}

$listing_id = $_GET['id'];

// Verify ownership of the listing
$check_query = $conn->prepare("
    SELECT pl.*, p.* FROM property_listings pl
    JOIN properties p ON pl.property_id = p.property_id
    WHERE pl.listing_id = ? AND pl.agent_id = ?
");
$check_query->bind_param("ii", $listing_id, $agent_id);
$check_query->execute();
$check_result = $check_query->get_result();

if ($check_result->num_rows === 0) {
    // Listing not found or doesn't belong to this agent
    header("Location: listings.php");
    exit();
}

$listing = $check_result->fetch_assoc();
$property_id = $listing['property_id'];

// Get property features
$features_query = $conn->prepare("
    SELECT pf.feature_id, pf.name
    FROM property_features pf
    JOIN property_has_features phf ON pf.feature_id = phf.feature_id
    WHERE phf.property_id = ?
");
$features_query->bind_param("i", $property_id);
$features_query->execute();
$features_result = $features_query->get_result();
$selected_features = [];
while ($feature = $features_result->fetch_assoc()) {
    $selected_features[] = $feature['feature_id'];
}

// Get all available features
$all_features_query = $conn->query("SELECT * FROM property_features ORDER BY name");
$all_features = [];
while ($feature = $all_features_query->fetch_assoc()) {
    $all_features[] = $feature;
}

// Get property images
$images_query = $conn->prepare("
    SELECT * FROM property_images 
    WHERE property_id = ?
    ORDER BY is_primary DESC, display_order ASC
");
$images_query->bind_param("i", $property_id);
$images_query->execute();
$images_result = $images_query->get_result();
$property_images = [];
while ($image = $images_result->fetch_assoc()) {
    $property_images[] = $image;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Update property data
        $update_property = $conn->prepare("
            UPDATE properties SET
                title = ?,
                description = ?,
                property_type = ?,
                status = ?,
                price = ?,
                bedrooms = ?,
                bathrooms = ?,
                area_sqft = ?,
                year_built = ?,
                lot_size_sqft = ?,
                address = ?,
                city = ?,
                state = ?,
                postal_code = ?,
                country = ?,
                latitude = ?,
                longitude = ?,
                featured = ?
            WHERE property_id = ?
        ");
        
        $title = $_POST['title'];
        $description = $_POST['description'];
        $property_type = $_POST['property_type'];
        $status = $_POST['status'];
        $price = $_POST['price'];
        $bedrooms = empty($_POST['bedrooms']) ? NULL : $_POST['bedrooms'];
        $bathrooms = empty($_POST['bathrooms']) ? NULL : $_POST['bathrooms'];
        $area_sqft = empty($_POST['area_sqft']) ? NULL : $_POST['area_sqft'];
        $year_built = empty($_POST['year_built']) ? NULL : $_POST['year_built'];
        $lot_size_sqft = empty($_POST['lot_size_sqft']) ? NULL : $_POST['lot_size_sqft'];
        $address = $_POST['address'];
        $city = $_POST['city'];
        $state = $_POST['state'];
        $postal_code = $_POST['postal_code'];
        $country = $_POST['country'];
        $latitude = empty($_POST['latitude']) ? NULL : $_POST['latitude'];
        $longitude = empty($_POST['longitude']) ? NULL : $_POST['longitude'];
        $featured = isset($_POST['featured']) ? 1 : 0;
        
        $update_property->bind_param(
            "ssssddisisssssssddi",
            $title, $description, $property_type, $status, $price, 
            $bedrooms, $bathrooms, $area_sqft, $year_built, $lot_size_sqft,
            $address, $city, $state, $postal_code, $country,
            $latitude, $longitude, $featured, $property_id
        );
        $update_property->execute();
        
        // Update listing data
        $update_listing = $conn->prepare("
            UPDATE property_listings SET
                list_date = ?,
                expiration_date = ?,
                commission_percentage = ?,
                exclusive = ?
            WHERE listing_id = ?
        ");
        
        $list_date = $_POST['list_date'];
        $expiration_date = empty($_POST['expiration_date']) ? NULL : $_POST['expiration_date'];
        $commission_percentage = empty($_POST['commission_percentage']) ? NULL : $_POST['commission_percentage'];
        $exclusive = isset($_POST['exclusive']) ? 1 : 0;
        
        $update_listing->bind_param(
            "ssdii",
            $list_date, $expiration_date, $commission_percentage, $exclusive, $listing_id
        );
        $update_listing->execute();
        
        // Handle property features
        // First, remove all existing feature associations
        $delete_features = $conn->prepare("DELETE FROM property_has_features WHERE property_id = ?");
        $delete_features->bind_param("i", $property_id);
        $delete_features->execute();
        
        // Then add selected features
        if (isset($_POST['features']) && is_array($_POST['features'])) {
            $insert_feature = $conn->prepare("INSERT INTO property_has_features (property_id, feature_id) VALUES (?, ?)");
            foreach ($_POST['features'] as $feature_id) {
                $insert_feature->bind_param("ii", $property_id, $feature_id);
                $insert_feature->execute();
            }
        }
        
        // Handle image uploads
        if (isset($_FILES['images']) && $_FILES['images']['error'][0] !== UPLOAD_ERR_NO_FILE) {
            $upload_dir = '../uploads/properties/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Process each uploaded image
            $images_count = count($_FILES['images']['name']);
            for ($i = 0; $i < $images_count; $i++) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['images']['tmp_name'][$i];
                    $name = basename($_FILES['images']['name'][$i]);
                    $extension = pathinfo($name, PATHINFO_EXTENSION);
                    $new_filename = uniqid('property_') . '.' . $extension;
                    $target_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($tmp_name, $target_path)) {
                        $relative_path = 'uploads/properties/' . $new_filename;
                        $caption = $_POST['image_captions'][$i] ?? null;
                        $is_primary = isset($_POST['primary_image']) && $_POST['primary_image'] == $i ? 1 : 0;
                        $display_order = $i;
                        
                        // If this is set as primary, update all other images to not be primary
                        if ($is_primary) {
                            $update_primary = $conn->prepare("UPDATE property_images SET is_primary = 0 WHERE property_id = ?");
                            $update_primary->bind_param("i", $property_id);
                            $update_primary->execute();
                        }
                        
                        // Insert image record
                        $insert_image = $conn->prepare("
                            INSERT INTO property_images (property_id, image_url, caption, is_primary, display_order)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $insert_image->bind_param("issii", $property_id, $relative_path, $caption, $is_primary, $display_order);
                        $insert_image->execute();
                    }
                }
            }
        }
        
        // Handle image deletion
        if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
            foreach ($_POST['delete_images'] as $image_id) {
                // Get image path before deleting
                $image_query = $conn->prepare("SELECT image_url FROM property_images WHERE image_id = ? AND property_id = ?");
                $image_query->bind_param("ii", $image_id, $property_id);
                $image_query->execute();
                $image_result = $image_query->get_result();
                
                if ($image_result->num_rows > 0) {
                    $image_data = $image_result->fetch_assoc();
                    $image_path = '../' . $image_data['image_url'];
                    
                    // Delete the image file if it exists
                    if (file_exists($image_path)) {
                        unlink($image_path);
                    }
                    
                    // Delete the image record
                    $delete_image = $conn->prepare("DELETE FROM property_images WHERE image_id = ?");
                    $delete_image->bind_param("i", $image_id);
                    $delete_image->execute();
                }
            }
        }
        
        // Handle primary image update
        if (isset($_POST['existing_primary_image']) && is_numeric($_POST['existing_primary_image'])) {
            $primary_image_id = $_POST['existing_primary_image'];
            
            // Reset all images to non-primary
            $reset_primary = $conn->prepare("UPDATE property_images SET is_primary = 0 WHERE property_id = ?");
            $reset_primary->bind_param("i", $property_id);
            $reset_primary->execute();
            
            // Set the selected image as primary
            $set_primary = $conn->prepare("UPDATE property_images SET is_primary = 1 WHERE image_id = ? AND property_id = ?");
            $set_primary->bind_param("ii", $primary_image_id, $property_id);
            $set_primary->execute();
        }
        
        // Commit transaction
        $conn->commit();
        
        // Success message
        $success_message = "Listing updated successfully!";
        
        // Refresh property data
        $check_query->execute();
        $check_result = $check_query->get_result();
        $listing = $check_result->fetch_assoc();
        
        // Refresh property images
        $images_query->execute();
        $images_result = $images_query->get_result();
        $property_images = [];
        while ($image = $images_result->fetch_assoc()) {
            $property_images[] = $image;
        }
        
    } catch (Exception $e) {
        // Roll back transaction and set error message
        $conn->rollback();
        $error_message = "Error updating listing: " . $e->getMessage();
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

// Format agent name
$agent_name = $user['first_name'] . ' ' . $user['last_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Listing - PrimeEstate</title>
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
            transition: all 0.3s ease;
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
        
        /* Form styling */
        .form-label {
            @apply block text-sm font-medium text-gray-700 mb-1;
        }
        
        .form-input {
            @apply w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500;
        }
        
        .form-select {
            @apply w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500;
        }
        
        .form-checkbox {
            @apply h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded;
        }
        
        .form-textarea {
            @apply w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500;
        }
        
        .btn-primary {
            @apply px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500;
        }
        
        .btn-secondary {
            @apply px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500;
        }
        
        .btn-danger {
            @apply px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500;
        }
        
        .image-preview-container {
            @apply border border-gray-300 rounded-md p-2 mb-2 relative;
        }
        
        .image-preview-container img {
            @apply w-full h-32 object-cover rounded-md;
        }
        
        .image-preview-container .delete-btn {
            @apply absolute top-1 right-1 bg-red-600 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-700 focus:outline-none;
        }

        /* Mobile menu animation */
        .mobile-menu {
            transition: transform 0.3s ease;
        }
        
        .mobile-menu.hidden {
            transform: translateX(-100%);
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Edit Listing</h1>
                
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
            <div class="flex-1 overflow-auto px-4 py-6 md:px-8">
                <?php if (isset($success_message)): ?>
                <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-sm">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium"><?php echo $success_message; ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-sm">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium"><?php echo $error_message; ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800">Edit Listing Details</h2>
                        <a href="listings.php" class="text-indigo-600 hover:text-indigo-800 flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i> Back to Listings
                        </a>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="space-y-6">
                        <!-- Tabs Navigation -->
                        <div class="border-b border-gray-200">
                            <nav class="-mb-px flex space-x-8" id="tab-navigation">
                                <button type="button" class="tab-button active py-4 px-1 border-b-2 font-medium text-sm" data-tab="property-details">
                                    Property Details
                                </button>
                                <button type="button" class="tab-button py-4 px-1 border-b-2 font-medium text-sm" data-tab="listing-details">
                                    Listing Details
                                </button>
                                <button type="button" class="tab-button py-4 px-1 border-b-2 font-medium text-sm" data-tab="features">
                                    Features
                                </button>
                                <button type="button" class="tab-button py-4 px-1 border-b-2 font-medium text-sm" data-tab="images">
                                    Images
                                </button>
                            </nav>
                        </div>
                        
                        <!-- Property Details Tab -->
                        <div class="tab-content" id="property-details-tab">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="title" class="form-label">Title <span class="text-red-500">*</span></label>
                                    <input type="text" id="title" name="title" class="form-input" value="<?php echo htmlspecialchars($listing['title']); ?>" required>
                                </div>
                                
                                <div>
                                    <label for="property_type" class="form-label">Property Type <span class="text-red-500">*</span></label>
                                    <select id="property_type" name="property_type" class="form-select" required>
                                        <option value="apartment" <?php echo $listing['property_type'] == 'apartment' ? 'selected' : ''; ?>>Apartment</option>
                                        <option value="house" <?php echo $listing['property_type'] == 'house' ? 'selected' : ''; ?>>House</option>
                                        <option value="condo" <?php echo $listing['property_type'] == 'condo' ? 'selected' : ''; ?>>Condo</option>
                                        <option value="townhouse" <?php echo $listing['property_type'] == 'townhouse' ? 'selected' : ''; ?>>Townhouse</option>
                                        <option value="land" <?php echo $listing['property_type'] == 'land' ? 'selected' : ''; ?>>Land</option>
                                        <option value="commercial" <?php echo $listing['property_type'] == 'commercial' ? 'selected' : ''; ?>>Commercial</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="status" class="form-label">Status <span class="text-red-500">*</span></label>
                                    <select id="status" name="status" class="form-select" required>
                                        <option value="for_sale" <?php echo $listing['status'] == 'for_sale' ? 'selected' : ''; ?>>For Sale</option>
                                        <option value="for_rent" <?php echo $listing['status'] == 'for_rent' ? 'selected' : ''; ?>>For Rent</option>
                                        <option value="pending" <?php echo $listing['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="sold" <?php echo $listing['status'] == 'sold' ? 'selected' : ''; ?>>Sold</option>
                                        <option value="rented" <?php echo $listing['status'] == 'rented' ? 'selected' : ''; ?>>Rented</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="price" class="form-label">Price ($) <span class="text-red-500">*</span></label>
                                    <input type="number" id="price" name="price" class="form-input" step="0.01" min="0" value="<?php echo htmlspecialchars($listing['price']); ?>" required>
                                </div>
                                
                                <div>
                                    <label for="bedrooms" class="form-label">Bedrooms</label>
                                    <input type="number" id="bedrooms" name="bedrooms" class="form-input" min="0" value="<?php echo htmlspecialchars($listing['bedrooms'] ?? ''); ?>">
                                </div>
                                
                                <div>
                                    <label for="bathrooms" class="form-label">Bathrooms</label>
                                    <input type="number" id="bathrooms" name="bathrooms" class="form-input" min="0" step="0.5" value="<?php echo htmlspecialchars($listing['bathrooms'] ?? ''); ?>">
                                </div>
                                
                                <div>
                                    <label for="area_sqft" class="form-label">Area (sqft)</label>
                                    <input type="number" id="area_sqft" name="area_sqft" class="form-input" min="0" step="0.01" value="<?php echo htmlspecialchars($listing['area_sqft'] ?? ''); ?>">
                                </div>
                                
                                <div>
                                    <label for="lot_size_sqft" class="form-label">Lot Size (sqft)</label>
                                    <input type="number" id="lot_size_sqft" name="lot_size_sqft" class="form-input" min="0" step="0.01" value="<?php echo htmlspecialchars($listing['lot_size_sqft'] ?? ''); ?>">
                                </div>
                                
                                <div>
                                    <label for="year_built" class="form-label">Year Built</label>
                                    <input type="number" id="year_built" name="year_built" class="form-input" min="1800" max="<?php echo date('Y'); ?>" value="<?php echo htmlspecialchars($listing['year_built'] ?? ''); ?>">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea id="description" name="description" class="form-textarea" rows="5"><?php echo htmlspecialchars($listing['description'] ?? ''); ?></textarea>
                                </div>
                                
                                <div>
                                    <label for="address" class="form-label">Address <span class="text-red-500">*</span></label>
                                    <input type="text" id="address" name="address" class="form-input" value="<?php echo htmlspecialchars($listing['address']); ?>" required>
                                </div>
                                
                                <div>
                                    <label for="city" class="form-label">City <span class="text-red-500">*</span></label>
                                    <input type="text" id="city" name="city" class="form-input" value="<?php echo htmlspecialchars($listing['city']); ?>" required>
                                </div>
                                
                                <div>
                                    <label for="state" class="form-label">State <span class="text-red-500">*</span></label>
                                    <input type="text" id="state" name="state" class="form-input" value="<?php echo htmlspecialchars($listing['state']); ?>" required>
                                </div>
                                
                                <div>
                                    <label for="postal_code" class="form-label">Postal Code <span class="text-red-500">*</span></label>
                                    <input type="text" id="postal_code" name="postal_code" class="form-input" value="<?php echo htmlspecialchars($listing['postal_code']); ?>" required>
                                </div>
                                
                                <div>
                                    <label for="country" class="form-label">Country <span class="text-red-500">*</span></label>
                                    <input type="text" id="country" name="country" class="form-input" value="<?php echo htmlspecialchars($listing['country']); ?>" required>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="featured" name="featured" class="form-checkbox" <?php echo $listing['featured'] ? 'checked' : ''; ?>>
                                    <label for="featured" class="ml-2 text-sm text-gray-700">Feature this property on homepage</label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Listing Details Tab -->
                        <div class="tab-content hidden" id="listing-details-tab">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="list_date" class="form-label">Listing Date <span class="text-red-500">*</span></label>
                                    <input type="date" id="list_date" name="list_date" class="form-input" value="<?php echo htmlspecialchars($listing['list_date']); ?>" required>
                                </div>
                                
                                <div>
                                    <label for="expiration_date" class="form-label">Expiration Date</label>
                                    <input type="date" id="expiration_date" name="expiration_date" class="form-input" value="<?php echo htmlspecialchars($listing['expiration_date'] ?? ''); ?>">
                                </div>
                                
                                <div>
                                    <label for="commission_percentage" class="form-label">Commission Percentage (%)</label>
                                    <input type="number" id="commission_percentage" name="commission_percentage" class="form-input" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars($listing['commission_percentage'] ?? ''); ?>">
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" id="exclusive" name="exclusive" class="form-checkbox" <?php echo $listing['exclusive'] ? 'checked' : ''; ?>>
                                    <label for="exclusive" class="ml-2 text-sm text-gray-700">Exclusive Listing</label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Features Tab -->
                        <div class="tab-content hidden" id="features-tab">
                            <div class="mb-4">
                                <h3 class="text-lg font-medium text-gray-900 mb-2">Property Features</h3>
                                <p class="text-sm text-gray-600 mb-4">Select all features that apply to this property.</p>
                                
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                    <?php foreach ($all_features as $feature): ?>
                                    <div class="flex items-start">
                                        <div class="flex items-center h-5">
                                            <input type="checkbox" 
                                                   id="feature-<?php echo $feature['feature_id']; ?>" 
                                                   name="features[]" 
                                                   value="<?php echo $feature['feature_id']; ?>" 
                                                   class="form-checkbox"
                                                   <?php echo in_array($feature['feature_id'], $selected_features) ? 'checked' : ''; ?>>
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="feature-<?php echo $feature['feature_id']; ?>" class="text-gray-700"><?php echo htmlspecialchars($feature['name']); ?></label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Images Tab -->
                        <div class="tab-content hidden" id="images-tab">
                            <div class="mb-6">
                                <h3 class="text-lg font-medium text-gray-900 mb-2">Current Images</h3>
                                
                                <?php if (empty($property_images)): ?>
                                <p class="text-gray-600 italic">No images have been uploaded yet.</p>
                                <?php else: ?>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                                    <?php foreach ($property_images as $image): ?>
                                    <div class="image-preview-container">
                                        <img src="../<?php echo htmlspecialchars($image['image_url']); ?>" alt="<?php echo htmlspecialchars($image['caption'] ?? 'Property image'); ?>">
                                        <div class="mt-2 flex justify-between items-center">
                                            <div class="flex items-center">
                                                <input type="radio" 
                                                       id="primary-<?php echo $image['image_id']; ?>" 
                                                       name="existing_primary_image" 
                                                       value="<?php echo $image['image_id']; ?>" 
                                                       <?php echo $image['is_primary'] ? 'checked' : ''; ?>>
                                                <label for="primary-<?php echo $image['image_id']; ?>" class="ml-2 text-sm text-gray-700">Primary</label>
                                            </div>
                                            <div>
                                                <label class="flex items-center">
                                                    <input type="checkbox" name="delete_images[]" value="<?php echo $image['image_id']; ?>" class="form-checkbox text-red-600">
                                                    <span class="ml-2 text-sm text-red-600">Delete</span>
                                                </label>
                                            </div>
                                        </div>
                                        <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($image['caption'] ?? ''); ?></p>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="border-t border-gray-200 pt-6">
                                <h3 class="text-lg font-medium text-gray-900 mb-2">Upload New Images</h3>
                                <p class="text-sm text-gray-600 mb-4">Upload new images for this property. You can select multiple files.</p>
                                
                                <div id="image-upload-container">
                                    <div class="image-upload-row mb-4">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div class="col-span-2">
                                                <input type="file" name="images[]" accept="image/*" class="form-input py-1">
                                            </div>
                                            <div>
                                                <input type="text" name="image_captions[]" placeholder="Caption (optional)" class="form-input">
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="primary_image" value="0" class="form-radio text-indigo-600">
                                                <span class="ml-2 text-sm text-gray-700">Set as primary image</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="button" id="add-more-images" class="btn-secondary mt-2">
                                    <i class="fas fa-plus mr-2"></i> Add More Images
                                </button>
                            </div>
                        </div>
                        
                        <div class="border-t border-gray-200 pt-6">
                            <div class="flex justify-end space-x-3">
                                <a href="listings.php" class="btn-secondary">Cancel</a>
                                <button type="submit" class="btn-primary">Update Listing</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Tab Navigation
        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabId = this.dataset.tab;
                    
                    // Remove active class from all buttons and hide all content
                    tabButtons.forEach(btn => {
                        btn.classList.remove('active', 'border-indigo-500', 'text-indigo-600');
                        btn.classList.add('border-transparent', 'text-gray-500', 'hover:text-gray-700', 'hover:border-gray-300');
                    });
                    
                    tabContents.forEach(content => {
                        content.classList.add('hidden');
                    });
                    
                    // Add active class to current button and show content
                    this.classList.add('active', 'border-indigo-500', 'text-indigo-600');
                    this.classList.remove('border-transparent', 'text-gray-500', 'hover:text-gray-700', 'hover:border-gray-300');
                    
                    document.getElementById(`${tabId}-tab`).classList.remove('hidden');
                });
            });
            
            // Set active tab on page load
            document.querySelector('.tab-button.active').click();
            
            // User Menu Toggle
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenuDropdown = document.getElementById('user-menu-dropdown');
            
            if (userMenuButton && userMenuDropdown) {
                userMenuButton.addEventListener('click', function() {
                    userMenuDropdown.classList.toggle('hidden');
                });
                
                // Close the dropdown when clicking outside
                document.addEventListener('click', function(event) {
                    if (!userMenuButton.contains(event.target) && !userMenuDropdown.contains(event.target)) {
                        userMenuDropdown.classList.add('hidden');
                    }
                });
            }
            
            // Mobile Menu Toggle
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');
            const closeMobileMenuButton = document.getElementById('close-mobile-menu');
            const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
            
            if (mobileMenuButton && mobileMenu && closeMobileMenuButton) {
                mobileMenuButton.addEventListener('click', function() {
                    mobileMenu.classList.toggle('hidden');
                });
                
                closeMobileMenuButton.addEventListener('click', function() {
                    mobileMenu.classList.add('hidden');
                });
                
                mobileMenuOverlay.addEventListener('click', function() {
                    mobileMenu.classList.add('hidden');
                });
            }
            
            // Add More Images Functionality
            const addMoreImagesButton = document.getElementById('add-more-images');
            const imageUploadContainer = document.getElementById('image-upload-container');
            
            if (addMoreImagesButton && imageUploadContainer) {
                let imageCounter = 1;
                
                addMoreImagesButton.addEventListener('click', function() {
                    const newRow = document.createElement('div');
                    newRow.className = 'image-upload-row mb-4';
                    newRow.innerHTML = `
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="col-span-2">
                                <input type="file" name="images[]" accept="image/*" class="form-input py-1">
                            </div>
                            <div>
                                <input type="text" name="image_captions[]" placeholder="Caption (optional)" class="form-input">
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="inline-flex items-center">
                                <input type="radio" name="primary_image" value="${imageCounter}" class="form-radio text-indigo-600">
                                <span class="ml-2 text-sm text-gray-700">Set as primary image</span>
                            </label>
                        </div>
                    `;
                    
                    imageUploadContainer.appendChild(newRow);
                    imageCounter++;
                });
            }
        });
    </script>
</body>
</html>