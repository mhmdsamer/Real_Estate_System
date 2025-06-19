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

// Process form submission
$errors = [];
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate form inputs
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    // Validation
    if (empty($first_name)) {
        $errors[] = "First name is required";
    }
    
    if (empty($last_name)) {
        $errors[] = "Last name is required";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Email format is invalid";
    } else {
        // Check if email already exists
        $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $check_email->bind_param("s", $email);
        $check_email->execute();
        $check_email_result = $check_email->get_result();
        if ($check_email_result->num_rows > 0) {
            $errors[] = "Email address is already in use";
        }
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    // If no errors, create the new client
    if (empty($errors)) {
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Insert into users table
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $insert_user = $conn->prepare("INSERT INTO users (email, password, first_name, last_name, phone, user_type, created_at) VALUES (?, ?, ?, ?, ?, 'client', NOW())");
            $insert_user->bind_param("sssss", $email, $hashed_password, $first_name, $last_name, $phone);
            $insert_user->execute();
            
            $new_user_id = $conn->insert_id;
            
            // If there are notes, create a client_notes table and add a note
            // Note: This assumes you have a client_notes table. If not, you can create one.
            if (!empty($notes)) {
                // Check if client_notes table exists
                $table_check = $conn->query("SHOW TABLES LIKE 'client_notes'");
                
                // If table doesn't exist, create it
                if ($table_check->num_rows == 0) {
                    $create_notes_table = "CREATE TABLE `client_notes` (
                        `note_id` int(11) NOT NULL AUTO_INCREMENT,
                        `user_id` int(11) NOT NULL,
                        `agent_id` int(11) NOT NULL,
                        `note_content` text NOT NULL,
                        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                        PRIMARY KEY (`note_id`),
                        KEY `user_id` (`user_id`),
                        KEY `agent_id` (`agent_id`),
                        CONSTRAINT `client_notes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
                        CONSTRAINT `client_notes_ibfk_2` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`agent_id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
                    
                    $conn->query($create_notes_table);
                }
                
                // Insert note
                $insert_note = $conn->prepare("INSERT INTO client_notes (user_id, agent_id, note_content) VALUES (?, ?, ?)");
                $insert_note->bind_param("iis", $new_user_id, $agent_id, $notes);
                $insert_note->execute();
            }
            
            // Commit transaction
            $conn->commit();
            
            $success_message = "Client added successfully!";
            
            // Optionally redirect to the client detail page
            // header("Location: client-detail.php?id=" . $new_user_id);
            // exit();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $errors[] = "An error occurred: " . $e->getMessage();
        }
    }
}

// Get pending inquiries count for notification badge (copied from clients.php)
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Client - PrimeEstate</title>
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
        
        /* Form controls */
        .form-input {
            @apply block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm;
        }
        
        .form-label {
            @apply block text-sm font-medium text-gray-700 mb-1;
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Add New Client</h1>
                
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
                <!-- Header Section -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Add New Client</h2>
                        <p class="text-gray-600 mt-1">Create a new client account and start managing their property interests</p>
                    </div>
                    <div>
                        <a href="clients.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            Back to Clients
                        </a>
                    </div>
                </div>
                
                <!-- Form Card -->
                <div class="bg-white rounded-lg shadow p-6">
                    <!-- Error Messages -->
                    <?php if (!empty($errors)): ?>
                        <div class="mb-4 p-4 bg-red-50 border-l-4 border-red-500 text-red-700">
                            <p class="font-medium">Please correct the following errors:</p>
                            <ul class="mt-2 ml-4 list-disc">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Success Message -->
                    <?php if (!empty($success_message)): ?>
                        <div class="mb-4 p-4 bg-green-50 border-l-4 border-green-500 text-green-700">
                            <p><?php echo htmlspecialchars($success_message); ?></p>
                            <p class="mt-2">
                                <a href="clients.php" class="text-green-600 underline">Return to client list</a> or continue adding another client.
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <form action="add-client.php" method="POST" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Personal Information -->
                            <div class="space-y-4">
                                <h3 class="text-lg font-medium text-gray-900">Personal Information</h3>
                                
                                <div>
                                    <label for="first_name" class="form-label">First Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="first_name" id="first_name" class="form-input" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required>
                                </div>
                                
                                <div>
                                    <label for="last_name" class="form-label">Last Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="last_name" id="last_name" class="form-input" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required>
                                </div>
                                
                                <div>
                                    <label for="email" class="form-label">Email Address <span class="text-red-500">*</span></label>
                                    <input type="email" name="email" id="email" class="form-input" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                                </div>
                                
                                <div>
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" name="phone" id="phone" class="form-input" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                                </div>
                            </div>
                            
                            <!-- Account Information -->
                            <div class="space-y-4">
                                <h3 class="text-lg font-medium text-gray-900">Account Information</h3>
                                
                                <div>
                                    <label for="password" class="form-label">Password <span class="text-red-500">*</span></label>
                                    <input type="password" name="password" id="password" class="form-input" minlength="8" required>
                                    <p class="mt-1 text-sm text-gray-500">Password must be at least 8 characters long</p>
                                </div>
                                
                                <div>
                                    <label for="confirm_password" class="form-label">Confirm Password <span class="text-red-500">*</span></label>
                                    <input type="password" name="confirm_password" id="confirm_password" class="form-input" required>
                                </div>
                                
                                <div>
                                    <label for="notes" class="form-label">Initial Notes</label>
                                    <textarea name="notes" id="notes" rows="4" class="form-input"><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                                    <p class="mt-1 text-sm text-gray-500">Add any initial notes about this client's interests or requirements</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="pt-4 border-t border-gray-200 flex justify-end space-x-3">
                            <a href="clients.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Cancel
                            </a>
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Add Client
                            </button>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        // Mobile menu toggle
const mobileMenuButton = document.getElementById('mobile-menu-button');
const closeMobileMenuButton = document.getElementById('close-mobile-menu');
const mobileMenu = document.getElementById('mobile-menu');
const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');

mobileMenuButton.addEventListener('click', () => {
    mobileMenu.classList.remove('hidden');
});

closeMobileMenuButton.addEventListener('click', () => {
    mobileMenu.classList.add('hidden');
});

mobileMenuOverlay.addEventListener('click', () => {
    mobileMenu.classList.add('hidden');
});

// User menu dropdown toggle
const userMenuButton = document.getElementById('user-menu-button');
const userMenuDropdown = document.getElementById('user-menu-dropdown');

userMenuButton.addEventListener('click', () => {
    userMenuDropdown.classList.toggle('hidden');
});

// Close user menu when clicking outside
document.addEventListener('click', (event) => {
    if (!userMenuButton.contains(event.target) && !userMenuDropdown.contains(event.target)) {
        userMenuDropdown.classList.add('hidden');
    }
});

// Password confirmation validation
const password = document.getElementById('password');
const confirmPassword = document.getElementById('confirm_password');

function validatePassword() {
    if (password.value !== confirmPassword.value) {
        confirmPassword.setCustomValidity("Passwords don't match");
    } else {
        confirmPassword.setCustomValidity('');
    }
}

password.addEventListener('change', validatePassword);
confirmPassword.addEventListener('keyup', validatePassword);

// Form validation highlighting
const form = document.querySelector('form');
const inputs = form.querySelectorAll('input[required]');

inputs.forEach(input => {
    input.addEventListener('blur', () => {
        if (input.value.trim() === '') {
            input.classList.add('border-red-500');
            const errorMessage = document.createElement('p');
            errorMessage.classList.add('text-red-500', 'text-xs', 'mt-1', 'error-message');
            errorMessage.innerText = 'This field is required';
            
            // Remove any existing error messages
            const existingError = input.parentNode.querySelector('.error-message');
            if (existingError) {
                existingError.remove();
            }
            
            if (!input.validity.valid) {
                input.parentNode.appendChild(errorMessage);
            }
        } else {
            input.classList.remove('border-red-500');
            const existingError = input.parentNode.querySelector('.error-message');
            if (existingError) {
                existingError.remove();
            }
        }
    });
});

// Email validation
const emailInput = document.getElementById('email');
emailInput.addEventListener('blur', () => {
    if (emailInput.value && !emailInput.validity.valid) {
        emailInput.classList.add('border-red-500');
        const errorMessage = document.createElement('p');
        errorMessage.classList.add('text-red-500', 'text-xs', 'mt-1', 'error-message');
        errorMessage.innerText = 'Please enter a valid email address';
        
        // Remove any existing error messages
        const existingError = emailInput.parentNode.querySelector('.error-message');
        if (existingError) {
            existingError.remove();
        }
        
        emailInput.parentNode.appendChild(errorMessage);
    }
});

// Form submission
form.addEventListener('submit', (event) => {
    // Validate password match one more time
    if (password.value !== confirmPassword.value) {
        event.preventDefault();
        confirmPassword.classList.add('border-red-500');
        const errorMessage = document.createElement('p');
        errorMessage.classList.add('text-red-500', 'text-xs', 'mt-1', 'error-message');
        errorMessage.innerText = 'Passwords do not match';
        
        // Remove any existing error messages
        const existingError = confirmPassword.parentNode.querySelector('.error-message');
        if (existingError) {
            existingError.remove();
        }
        
        confirmPassword.parentNode.appendChild(errorMessage);
        confirmPassword.focus();
    }
    
    // Check if all required fields are filled
    let hasErrors = false;
    inputs.forEach(input => {
        if (input.value.trim() === '') {
            input.classList.add('border-red-500');
            const errorMessage = document.createElement('p');
            errorMessage.classList.add('text-red-500', 'text-xs', 'mt-1', 'error-message');
            errorMessage.innerText = 'This field is required';
            
            // Remove any existing error messages
            const existingError = input.parentNode.querySelector('.error-message');
            if (existingError) {
                existingError.remove();
            }
            
            input.parentNode.appendChild(errorMessage);
            if (!hasErrors) {
                input.focus();
                hasErrors = true;
            }
        }
    });
    
    if (hasErrors) {
        event.preventDefault();
    }
});

// Phone number formatting
const phoneInput = document.getElementById('phone');
phoneInput.addEventListener('input', (e) => {
    // Remove all non-digit characters
    let phone = e.target.value.replace(/\D/g, '');
    
    // Format the phone number as (XXX) XXX-XXXX
    if (phone.length >= 10) {
        phone = `(${phone.substring(0, 3)}) ${phone.substring(3, 6)}-${phone.substring(6, 10)}`;
    } else if (phone.length >= 6) {
        phone = `(${phone.substring(0, 3)}) ${phone.substring(3, 6)}-${phone.substring(6)}`;
    } else if (phone.length >= 3) {
        phone = `(${phone.substring(0, 3)}) ${phone.substring(3)}`;
    }
    
    e.target.value = phone;
});

// Success message auto-dismissal
const successMessage = document.querySelector('.bg-green-50');
if (successMessage) {
    setTimeout(() => {
        successMessage.style.transition = 'opacity 1s ease-out';
        successMessage.style.opacity = '0';
        setTimeout(() => {
            successMessage.remove();
        }, 1000);
    }, 5000);
}
</script>
</body>
</html>