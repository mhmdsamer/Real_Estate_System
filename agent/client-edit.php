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

// Get agent profile information
$user_query = $conn->prepare("SELECT first_name, last_name, email, phone, profile_image FROM users WHERE user_id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user = $user_result->fetch_assoc();

// Check if client ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: clients.php");
    exit();
}

$client_id = $_GET['id'];

// Get client information
$client_query = $conn->prepare("
    SELECT u.*, 
           CASE 
               WHEN EXISTS (SELECT 1 FROM transactions t WHERE (t.buyer_id = u.user_id OR t.seller_id = u.user_id) 
                          AND (t.listing_agent_id = ? OR t.buyer_agent_id = ?) AND t.status = 'completed') THEN 'active'
               WHEN EXISTS (SELECT 1 FROM property_viewings pv WHERE pv.user_id = u.user_id AND pv.agent_id = ? AND pv.viewing_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) THEN 'prospect'
               WHEN EXISTS (SELECT 1 FROM inquiries i JOIN property_listings pl ON i.property_id = pl.property_id 
                          WHERE i.user_id = u.user_id AND pl.agent_id = ? AND i.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) THEN 'lead'
               ELSE 'inactive'
           END as client_status
    FROM users u
    WHERE u.user_id = ? AND u.user_type = 'client'
");
$client_query->bind_param("iiiii", $agent_id, $agent_id, $agent_id, $agent_id, $client_id);
$client_query->execute();
$client_result = $client_query->get_result();

// Check if client exists
if ($client_result->num_rows === 0) {
    header("Location: clients.php");
    exit();
}

$client = $client_result->fetch_assoc();

// Process form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize inputs
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $phone = trim($_POST['phone']);
    $bio = trim($_POST['bio']);
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error_message = "First name, last name, and email are required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        // Check if email exists for another user
        $email_check = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $email_check->bind_param("si", $email, $client_id);
        $email_check->execute();
        $email_result = $email_check->get_result();
        
        if ($email_result->num_rows > 0) {
            $error_message = "Email address is already in use by another user.";
        } else {
            // Process profile image upload if provided
            $profile_image = $client['profile_image']; // Keep existing image by default
            
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['profile_image']['type'];
                
                if (in_array($file_type, $allowed_types)) {
                    $upload_dir = '../uploads/profiles/';
                    
                    // Create directory if it doesn't exist
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_name = time() . '_' . $_FILES['profile_image']['name'];
                    $destination = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                        $profile_image = $destination;
                    } else {
                        $error_message = "Failed to upload profile image. Please try again.";
                    }
                } else {
                    $error_message = "Invalid file type. Only JPEG, PNG, and GIF files are allowed.";
                }
            }
            
            // If no errors, update user information
            if (empty($error_message)) {
                $update_query = $conn->prepare("
                    UPDATE users 
                    SET first_name = ?, last_name = ?, email = ?, phone = ?, bio = ?, profile_image = ?, updated_at = NOW()
                    WHERE user_id = ?
                ");
                $update_query->bind_param("ssssssi", $first_name, $last_name, $email, $phone, $bio, $profile_image, $client_id);
                
                if ($update_query->execute()) {
                    $success_message = "Client information updated successfully.";
                    
                    // Refresh client data
                    $client_query->execute();
                    $client_result = $client_query->get_result();
                    $client = $client_result->fetch_assoc();
                } else {
                    $error_message = "Failed to update client information. Please try again.";
                }
            }
        }
    }
}

// Get pending inquiries count for notification badge
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

// Format name for display
$agent_name = $user['first_name'] . ' ' . $user['last_name'];
$profile_image = $user['profile_image'] ?? 'https://via.placeholder.com/150';
$client_name = $client['first_name'] . ' ' . $client['last_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Client - PrimeEstate</title>
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
        
        .card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
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
        
        /* Badge styles */
        .badge {
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-active {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-prospect {
            background-color: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }
        
        .status-lead {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-inactive {
            background-color: rgba(156, 163, 175, 0.1);
            color: #6b7280;
        }
        
        /* Mobile menu animation */
        .mobile-menu {
            transition: transform 0.3s ease;
        }
        
        .mobile-menu.hidden {
            transform: translateX(-100%);
        }
        
        .form-input:focus, .form-textarea:focus, .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
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
                            <a href="listings.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
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
                            <a href="clients.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Edit Client</h1>
                
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
                            <a href="listings.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
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
                            <a href="clients.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <!-- Breadcrumb -->
                <nav class="flex mb-5" aria-label="Breadcrumb">
                    <ol class="inline-flex items-center space-x-1 md:space-x-3">
                        <li class="inline-flex items-center">
                            <a href="dashboard.php" class="text-gray-600 hover:text-gray-900 inline-flex items-center">
                                <i class="fas fa-home text-gray-400 mr-2"></i>
                                Dashboard
                            </a>
                        </li>
                        <li>
                            <div class="flex items-center">
                                <i class="fas fa-chevron-right text-gray-400 text-sm mx-2"></i>
                                <a href="clients.php" class="text-gray-600 hover:text-gray-900">
                                    Clients
                                </a>
                            </div>
                        </li>
                        <li>
                            <div class="flex items-center">
                                <i class="fas fa-chevron-right text-gray-400 text-sm mx-2"></i>
                                <span class="text-gray-500">Edit Client</span>
                            </div>
                        </li>
                    </ol>
                </nav>
                
                <!-- Header Section -->
                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Edit Client: <?php echo htmlspecialchars($client_name); ?></h2>
                        <p class="text-gray-600 mt-1">Update client information and preferences</p>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <a href="clients.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            Back to Client Details
                        </a>
                    </div>
                </div>
                
                <!-- Alert Messages -->
                <?php if (!empty($success_message)): ?>
                <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6 rounded-md">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-700">
                                <?php echo $success_message; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded-md">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-700">
                                <?php echo $error_message; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Client Edit Form -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6">
                        <form action="client-edit.php?id=<?php echo $client_id; ?>" method="POST" enctype="multipart/form-data">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Client Status -->
                                <div class="md:col-span-2 mb-4">
                                    <div class="flex items-center">
                                        <div class="mr-3">
                                            <span class="text-sm font-medium text-gray-700">Client Status:</span>
                                        </div>
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full status-<?php echo $client['client_status']; ?>">
                                            <?php 
                                            switch($client['client_status']) {
                                                case 'active': 
                                                    echo 'Active Client'; 
                                                    break;
                                                case 'prospect': 
                                                    echo 'Prospect'; 
                                                    break;
                                                case 'lead': 
                                                    echo 'Lead'; 
                                                    break;
                                                default: 
                                                    echo 'Inactive'; 
                                                    break;
                                            }
                                            ?>
                                        </span>
                                        <span class="ml-2 text-xs text-gray-500">(Status is determined automatically based on client activity)</span>
                                    </div>
                                </div>
                                
                                <!-- Left Column -->
                                <div>
                                    <div class="mb-6">
                                        <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name <span class="text-red-600">*</span></label>
                                        <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($client['first_name']); ?>" required class="form-input block w-full sm:text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                                    </div>
                                    
                                    <div class="mb-6">
                                        <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name <span class="text-red-600">*</span></label>
                                        <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($client['last_name']); ?>" required class="form-input block w-full sm:text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                                    </div>
                                    
                                    <div class="mb-6">
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address <span class="text-red-600">*</span></label>
                                        <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($client['email']); ?>" required class="form-input block w-full sm:text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                                    </div>
                                </div>
                                
                                <!-- Right Column -->
                                <div>
                                    <div class="mb-6">
                                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                                        <input type="tel" name="phone" id="phone" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>" class="form-input block w-full sm:text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                                    </div>
                                    
                                    <div class="mb-6">
                                        <label for="profile_image" class="block text-sm font-medium text-gray-700 mb-1">Profile Photo</label>
                                        <div class="flex items-center space-x-4">
                                            <div class="w-16 h-16 rounded-full overflow-hidden bg-gray-100 flex items-center justify-center">
                                                <?php if (!empty($client['profile_image'])): ?>
                                                    <img src="<?php echo htmlspecialchars($client['profile_image']); ?>" alt="Client profile" class="w-full h-full object-cover">
                                                <?php else: ?>
                                                    <i class="fas fa-user text-gray-400 text-2xl"></i>
                                                <?php endif; ?>
                                            </div>
                                            <input type="file" name="profile_image" id="profile_image" accept="image/jpeg,image/png,image/gif" class="form-input block w-full sm:text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1">Upload a new photo (JPEG, PNG, or GIF)</p>
                                    </div>
                                </div>
                                
                                <!-- Bio - Full Width -->
                                <div class="md:col-span-2">
                                    <div class="mb-6">
                                        <label for="bio" class="block text-sm font-medium text-gray-700 mb-1">Bio / Notes</label>
                                        <textarea name="bio" id="bio" rows="4" class="form-textarea block w-full sm:text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"><?php echo htmlspecialchars($client['bio'] ?? ''); ?></textarea>
                                        <p class="text-xs text-gray-500 mt-1">Add any additional notes about this client, their preferences, or important information.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="pt-4 border-t border-gray-200 mt-6 flex justify-end space-x-3">
                                <a href="client-detail.php?id=<?php echo $client_id; ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    Cancel
                                </a>
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    <i class="fas fa-save mr-2"></i>
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script>
        // Toggle user dropdown menu
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenuDropdown = document.getElementById('user-menu-dropdown');
        
        userMenuButton.addEventListener('click', function() {
            userMenuDropdown.classList.toggle('hidden');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!userMenuButton.contains(event.target) && !userMenuDropdown.contains(event.target)) {
                userMenuDropdown.classList.add('hidden');
            }
        });
        
        // Mobile menu functionality
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const closeMobileMenuButton = document.getElementById('close-mobile-menu');
        const mobileMenu = document.getElementById('mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        
        mobileMenuButton.addEventListener('click', function() {
            mobileMenu.classList.remove('hidden');
        });
        
        function closeMobileMenu() {
            mobileMenu.classList.add('hidden');
        }
        
        closeMobileMenuButton.addEventListener('click', closeMobileMenu);
        mobileMenuOverlay.addEventListener('click', closeMobileMenu);
        
        // Preview selected image before upload
        const profileImageInput = document.getElementById('profile_image');
        
        if (profileImageInput) {
            profileImageInput.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    const imagePreview = this.parentElement.querySelector('img') || document.createElement('img');
                    
                    if (!this.parentElement.querySelector('img')) {
                        const iconElement = this.parentElement.querySelector('i');
                        if (iconElement) {
                            iconElement.remove();
                        }
                        imagePreview.classList.add('w-full', 'h-full', 'object-cover');
                        this.parentElement.querySelector('.w-16').appendChild(imagePreview);
                    }
                    
                    reader.onload = function(e) {
                        imagePreview.src = e.target.result;
                    }
                    
                    reader.readAsDataURL(file);
                }
            });
        }
    </script>
</body>
</html>