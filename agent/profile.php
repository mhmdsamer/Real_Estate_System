<?php
require_once '../connection.php';
session_start();

// Check if user is logged in and is an agent
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'agent') {
    header("Location: ../login.php");
    exit();
}

// Get user ID from session
$user_id = $_SESSION['user_id'];

// Initialize variables for success/error messages
$success_message = "";
$error_message = "";

// If form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate and sanitize inputs
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $license_number = trim($_POST['license_number']);
    $brokerage = trim($_POST['brokerage']);
    $experience_years = intval($_POST['experience_years']);
    $bio = trim($_POST['bio']);
    $specialties = trim($_POST['specialties']);
    
    // Check if email was changed, and if so, check if it's already in use
    $email_check_query = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
    $email_check_query->bind_param("si", $email, $user_id);
    $email_check_query->execute();
    $email_result = $email_check_query->get_result();
    
    if ($email_result->num_rows > 0) {
        $error_message = "This email is already in use by another account.";
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update user details
            $user_update = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, bio = ? WHERE user_id = ?");
            $user_update->bind_param("sssssi", $first_name, $last_name, $email, $phone, $bio, $user_id);
            $user_update->execute();
            
            // Update agent details
            $agent_update = $conn->prepare("UPDATE agents SET license_number = ?, brokerage = ?, experience_years = ?, specialties = ? WHERE user_id = ?");
            $agent_update->bind_param("ssisi", $license_number, $brokerage, $experience_years, $specialties, $user_id);
            $agent_update->execute();
            
            // Handle profile image upload if a new file was selected
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['size'] > 0) {
                $file_name = $_FILES['profile_image']['name'];
                $file_size = $_FILES['profile_image']['size'];
                $file_tmp = $_FILES['profile_image']['tmp_name'];
                $file_type = $_FILES['profile_image']['type'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                $extensions = array("jpeg", "jpg", "png");
                
                if (in_array($file_ext, $extensions)) {
                    if ($file_size < 5000000) { // 5MB max
                        $new_file_name = uniqid('profile_') . '.' . $file_ext;
                        $upload_path = '../uploads/profiles/' . $new_file_name;
                        
                        // Create directory if it doesn't exist
                        if (!file_exists('../uploads/profiles/')) {
                            mkdir('../uploads/profiles/', 0777, true);
                        }
                        
                        if (move_uploaded_file($file_tmp, $upload_path)) {
                            // Get the old profile image to delete it later
                            $old_image_query = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
                            $old_image_query->bind_param("i", $user_id);
                            $old_image_query->execute();
                            $old_image_result = $old_image_query->get_result();
                            $old_image = $old_image_result->fetch_assoc()['profile_image'];
                            
                            // Update database with new image path
                            $image_update = $conn->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?");
                            $db_image_path = 'uploads/profiles/' . $new_file_name;
                            $image_update->bind_param("si", $db_image_path, $user_id);
                            $image_update->execute();
                            
                            // Delete old profile image if it exists and is not a default image
                            if ($old_image && !strpos($old_image, 'placeholder') && file_exists('../' . $old_image)) {
                                unlink('../' . $old_image);
                            }
                        } else {
                            throw new Exception("Failed to upload the image.");
                        }
                    } else {
                        throw new Exception("File size must be less than 5MB.");
                    }
                } else {
                    throw new Exception("Only JPEG, JPG and PNG files are allowed.");
                }
            }
            
            // Password change if provided
            if (!empty($_POST['new_password']) && !empty($_POST['current_password'])) {
                // Verify current password
                $password_check = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
                $password_check->bind_param("i", $user_id);
                $password_check->execute();
                $current_hash = $password_check->get_result()->fetch_assoc()['password'];
                
                if (password_verify($_POST['current_password'], $current_hash)) {
                    if ($_POST['new_password'] === $_POST['confirm_password']) {
                        $new_hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                        $password_update = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                        $password_update->bind_param("si", $new_hash, $user_id);
                        $password_update->execute();
                    } else {
                        throw new Exception("New password and confirmation do not match.");
                    }
                } else {
                    throw new Exception("Current password is incorrect.");
                }
            }
            
            // Commit transaction
            $conn->commit();
            $success_message = "Profile updated successfully!";
            
            // Update session data
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['email'] = $email;
            
        } catch (Exception $e) {
            // Roll back transaction on error
            $conn->rollback();
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Get user and agent data
$user_query = $conn->prepare("
    SELECT u.*, a.* 
    FROM users u 
    LEFT JOIN agents a ON u.user_id = a.user_id 
    WHERE u.user_id = ?
");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

// Format the profile image path
$profile_image = $user_data['profile_image'] ?? 'https://via.placeholder.com/150';
if (!empty($profile_image) && !filter_var($profile_image, FILTER_VALIDATE_URL)) {
    $profile_image = '../' . $profile_image;
}

// Get agent's listings count
$listings_query = $conn->prepare("
    SELECT COUNT(*) as listings_count 
    FROM property_listings 
    WHERE agent_id = ?
");
$listings_query->bind_param("i", $user_data['agent_id']);
$listings_query->execute();
$listings_result = $listings_query->get_result();
$listings_count = $listings_result->fetch_assoc()['listings_count'];

// Get agent's completed transactions count
$transactions_query = $conn->prepare("
    SELECT COUNT(*) as transactions_count 
    FROM transactions 
    WHERE (listing_agent_id = ? OR buyer_agent_id = ?) AND status = 'completed'
");
$transactions_query->bind_param("ii", $user_data['agent_id'], $user_data['agent_id']);
$transactions_query->execute();
$transactions_result = $transactions_query->get_result();
$transactions_count = $transactions_result->fetch_assoc()['transactions_count'];

// Get agent's ratings
$ratings_query = $conn->prepare("
    SELECT AVG(rating) as average_rating, COUNT(*) as total_reviews
    FROM reviews
    WHERE agent_id = ? AND is_approved = 1
");
$ratings_query->bind_param("i", $user_data['agent_id']);
$ratings_query->execute();
$ratings_result = $ratings_query->get_result();
$ratings = $ratings_result->fetch_assoc();
$average_rating = number_format($ratings['average_rating'] ?? 0, 1);
$total_reviews = $ratings['total_reviews'] ?? 0;

// Format name for display
$agent_name = $user_data['first_name'] . ' ' . $user_data['last_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Profile - PrimeEstate</title>
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
        
        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f9fafb 100%);
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border-left: 4px solid var(--primary);
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
        .form-input {
            border-radius: 0.375rem;
            border: 1px solid #e2e8f0;
            width: 100%;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #4b5563;
            margin-bottom: 0.5rem;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            transition: background-color 0.15s ease-in-out;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            background-color: #f3f4f6;
            color: #374151;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            transition: background-color 0.15s ease-in-out;
        }
        
        .btn-secondary:hover {
            background-color: #e5e7eb;
        }
        
        .file-upload {
            position: relative;
            overflow: hidden;
            margin: 10px 0;
            cursor: pointer;
        }
        
        .file-upload input[type=file] {
            position: absolute;
            top: 0;
            right: 0;
            min-width: 100%;
            min-height: 100%;
            font-size: 100px;
            text-align: right;
            filter: alpha(opacity=0);
            opacity: 0;
            outline: none;
            cursor: inherit;
            display: block;
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
                            <a href="profile.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">My Profile</h1>
                
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
                            <a href="profile.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <!-- Success/Error Messages -->
                <?php if (!empty($success_message)): ?>
                    <div class="mb-6 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded" role="alert">
                        <p class="font-medium">Success!</p>
                        <p><?php echo $success_message; ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="mb-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded" role="alert">
                        <p class="font-medium">Error!</p>
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php endif; ?>
                
                <!-- Profile Overview -->
                <div class="mb-6">
                    <div class="card p-6">
                        <div class="flex flex-col md:flex-row md:items-center">
                            <div class="md:mr-8 mb-4 md:mb-0 flex flex-col items-center">
                                <div class="relative">
                                    <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Profile Picture" class="h-32 w-32 rounded-full object-cover border-4 border-white shadow-lg">
                                    <div class="absolute bottom-0 right-0 bg-white rounded-full p-1 shadow-md">
                                        <div class="text-xs flex items-center justify-center h-6 w-6 rounded-full bg-indigo-100 text-indigo-800">
                                            <i class="fas fa-star"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4 flex flex-col items-center">
                                    <h2 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($agent_name); ?></h2>
                                    <p class="text-gray-500 text-sm">Real Estate Agent</p>
                                    <div class="flex items-center mt-1">
                                        <div class="flex text-yellow-400">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <?php if ($i <= round($average_rating)): ?>
                                                    <i class="fas fa-star text-xs"></i>
                                                <?php else: ?>
                                                    <i class="far fa-star text-xs"></i>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                        </div>
                                        <span class="ml-1 text-sm text-gray-600">(<?php echo $total_reviews; ?>)</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex-grow">
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div class="stat-card p-4">
                                        <p class="text-sm font-medium text-gray-500">License Number</p>
                                        <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($user_data['license_number']); ?></p>
                                    </div>
                                    
                                    <div class="stat-card p-4" style="border-left-color: var(--success);">
                                        <p class="text-sm font-medium text-gray-500">Experience</p>
                                        <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($user_data['experience_years']); ?> years</p>
                                    </div>
                                    
                                    <div class="stat-card p-4" style="border-left-color: var(--secondary);">
                                        <p class="text-sm font-medium text-gray-500">Brokerage</p>
                                        <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($user_data['brokerage']); ?></p>
                                    </div>
                                    
                                    <div class="stat-card p-4" style="border-left-color: var(--warning);">
                                        <p class="text-sm font-medium text-gray-500">Active Listings</p>
                                        <p class="text-lg font-bold text-gray-900"><?php echo $listings_count; ?></p>
                                    </div>
                                    
                                    <div class="stat-card p-4" style="border-left-color: var(--accent);">
                                        <p class="text-sm font-medium text-gray-500">Completed Deals</p>
                                        <p class="text-lg font-bold text-gray-900"><?php echo $transactions_count; ?></p>
                                    </div>
                                    
                                    <div class="stat-card p-4" style="border-left-color: var(--primary);">
                                        <p class="text-sm font-medium text-gray-500">Specialties</p>
                                        <p class="text-lg font-bold text-gray-900"><?php echo !empty($user_data['specialties']) ? htmlspecialchars($user_data['specialties']) : 'Not specified'; ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">About Me</h3>
                            <p class="text-gray-600"><?php echo !empty($user_data['bio']) ? nl2br(htmlspecialchars($user_data['bio'])) : 'No bio available.'; ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Edit Form -->
                <div class="card p-6 mb-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">Edit Profile Information</h2>
                    
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Personal Information -->
                            <div>
                                <h3 class="text-lg font-semibold text-gray-700 mb-4">Personal Information</h3>
                                
                                <div class="mb-4">
                                    <label for="first_name" class="form-label">First Name</label>
                                    <input type="text" id="first_name" name="first_name" class="form-input" value="<?php echo htmlspecialchars($user_data['first_name']); ?>" required>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="last_name" class="form-label">Last Name</label>
                                    <input type="text" id="last_name" name="last_name" class="form-input" value="<?php echo htmlspecialchars($user_data['last_name']); ?>" required>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" id="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" id="phone" name="phone" class="form-input" value="<?php echo htmlspecialchars($user_data['phone']); ?>" required>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="bio" class="form-label">Bio</label>
                                    <textarea id="bio" name="bio" rows="5" class="form-input"><?php echo htmlspecialchars($user_data['bio'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label">Profile Picture</label>
                                    <div class="flex items-center">
                                        <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Current Profile" class="w-16 h-16 rounded-full object-cover mr-4">
                                        <div class="file-upload btn-secondary">
                                            <i class="fas fa-upload mr-2"></i> Upload New Image
                                            <input type="file" name="profile_image" accept="image/jpeg, image/png">
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">Supported formats: JPG, PNG. Max size: 5MB</p>
                                </div>
                            </div>
                            
                            <!-- Professional Information -->
                            <div>
                                <h3 class="text-lg font-semibold text-gray-700 mb-4">Professional Information</h3>
                                
                                <div class="mb-4">
                                    <label for="license_number" class="form-label">License Number</label>
                                    <input type="text" id="license_number" name="license_number" class="form-input" value="<?php echo htmlspecialchars($user_data['license_number'] ?? ''); ?>" required>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="brokerage" class="form-label">Brokerage</label>
                                    <input type="text" id="brokerage" name="brokerage" class="form-input" value="<?php echo htmlspecialchars($user_data['brokerage'] ?? ''); ?>" required>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="experience_years" class="form-label">Years of Experience</label>
                                    <input type="number" id="experience_years" name="experience_years" class="form-input" value="<?php echo htmlspecialchars($user_data['experience_years'] ?? 0); ?>" min="0" required>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="specialties" class="form-label">Specialties</label>
                                    <input type="text" id="specialties" name="specialties" class="form-input" value="<?php echo htmlspecialchars($user_data['specialties'] ?? ''); ?>" placeholder="e.g. Residential, Commercial, Luxury">
                                </div>
                                
                                <hr class="my-6 border-gray-200">
                                
                                <h3 class="text-lg font-semibold text-gray-700 mb-4">Change Password</h3>
                                <p class="text-sm text-gray-500 mb-4">Leave blank if you don't want to change your password</p>
                                
                                <div class="mb-4">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <input type="password" id="current_password" name="current_password" class="form-input">
                                </div>
                                
                                <div class="mb-4">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" id="new_password" name="new_password" class="form-input">
                                </div>
                                
                                <div class="mb-4">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-input">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex justify-end">
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-save mr-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>
    
    <script>
        // Toggle mobile menu
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.toggle('hidden');
        });
        
        document.getElementById('close-mobile-menu').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.add('hidden');
        });
        
        document.getElementById('mobile-menu-overlay').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.add('hidden');
        });
        
        // Toggle user dropdown
        document.getElementById('user-menu-button').addEventListener('click', function() {
            document.getElementById('user-menu-dropdown').classList.toggle('hidden');
        });
        
        // Close the dropdown when clicking outside
        window.addEventListener('click', function(event) {
            if (!event.target.closest('#user-menu-button')) {
                const dropdown = document.getElementById('user-menu-dropdown');
                if (!dropdown.classList.contains('hidden')) {
                    dropdown.classList.add('hidden');
                }
            }
        });
        
        // Display file name when selected
        const fileInput = document.querySelector('input[type="file"]');
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                this.parentElement.querySelector('i').className = 'fas fa-check-circle mr-2 text-green-500';
                this.parentElement.innerHTML = this.parentElement.innerHTML.replace('Upload New Image', fileName);
            }
        });
    </script>
</body>
</html>