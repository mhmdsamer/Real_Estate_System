<?php
require_once '../connection.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Initialize variables
$error_message = "";
$success_message = "";
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user = [];

// Get user data
if ($user_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header('Location: users.php');
        exit();
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // If this is an agent, get agent details
    if ($user['user_type'] === 'agent') {
        $agentStmt = $conn->prepare("SELECT * FROM agents WHERE user_id = ?");
        $agentStmt->bind_param('i', $user_id);
        $agentStmt->execute();
        $agentResult = $agentStmt->get_result();
        
        if ($agentResult->num_rows > 0) {
            $agent_data = $agentResult->fetch_assoc();
            $user = array_merge($user, $agent_data);
        }
        $agentStmt->close();
    }
} else {
    header('Location: users.php');
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic user info
    $email = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $user_type = trim($_POST['user_type']);
    $bio = trim($_POST['bio'] ?? '');
    
    // Agent specific fields
    $license_number = isset($_POST['license_number']) ? trim($_POST['license_number']) : '';
    $brokerage = isset($_POST['brokerage']) ? trim($_POST['brokerage']) : '';
    $experience_years = isset($_POST['experience_years']) ? (int)$_POST['experience_years'] : null;
    $specialties = isset($_POST['specialties']) ? trim($_POST['specialties']) : '';
    
    // Password (only update if provided)
    $new_password = trim($_POST['password'] ?? '');
    
    // Validation
    if (empty($email) || empty($first_name) || empty($last_name)) {
        $error_message = "Email, first name, and last name are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        // Check if email already exists for another user
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt->bind_param('si', $email, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error_message = "Email address is already in use by another user.";
        } else {
            // Start transaction
            $conn->begin_transaction();
            try {
                // Update basic user info
                $updateUserQuery = "UPDATE users SET 
                                    email = ?, 
                                    first_name = ?, 
                                    last_name = ?, 
                                    phone = ?, 
                                    user_type = ?,
                                    bio = ?";

                $params = [$email, $first_name, $last_name, $phone, $user_type, $bio];
                $paramTypes = 'ssssss';

                // Handle password update if provided
                if (!empty($new_password)) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $updateUserQuery .= ", password = ?";
                    $params[] = $hashed_password;
                    $paramTypes .= 's';
                }

                $updateUserQuery .= " WHERE user_id = ?";
                $params[] = $user_id;
                $paramTypes .= 'i';

                $stmt = $conn->prepare($updateUserQuery);
                $stmt->bind_param($paramTypes, ...$params);
                $stmt->execute();
                
                // Handle agent data
                if ($user_type === 'agent') {
                    // Check if agent record exists
                    $checkAgentStmt = $conn->prepare("SELECT agent_id FROM agents WHERE user_id = ?");
                    $checkAgentStmt->bind_param('i', $user_id);
                    $checkAgentStmt->execute();
                    $agentResult = $checkAgentStmt->get_result();
                    
                    if ($agentResult->num_rows > 0) {
                        // Update existing agent record
                        $agentStmt = $conn->prepare("UPDATE agents SET 
                                                license_number = ?, 
                                                brokerage = ?, 
                                                experience_years = ?, 
                                                specialties = ? 
                                                WHERE user_id = ?");
                        $agentStmt->bind_param('ssisi', $license_number, $brokerage, $experience_years, $specialties, $user_id);
                        $agentStmt->execute();
                    } else {
                        // Create new agent record
                        $agentStmt = $conn->prepare("INSERT INTO agents (user_id, license_number, brokerage, experience_years, specialties) 
                                                   VALUES (?, ?, ?, ?, ?)");
                        $agentStmt->bind_param('issis', $user_id, $license_number, $brokerage, $experience_years, $specialties);
                        $agentStmt->execute();
                    }
                } else if ($user['user_type'] === 'agent' && $user_type !== 'agent') {
                    // If user was an agent but is no longer, delete agent record
                    $deleteAgentStmt = $conn->prepare("DELETE FROM agents WHERE user_id = ?");
                    $deleteAgentStmt->bind_param('i', $user_id);
                    $deleteAgentStmt->execute();
                }
                
                // Commit transaction
                $conn->commit();
                $success_message = "User updated successfully.";
                
                // Refresh user data
                $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                
                // If user is agent, get agent data
                if ($user['user_type'] === 'agent') {
                    $agentStmt = $conn->prepare("SELECT * FROM agents WHERE user_id = ?");
                    $agentStmt->bind_param('i', $user_id);
                    $agentStmt->execute();
                    $agentResult = $agentStmt->get_result();
                    
                    if ($agentResult->num_rows > 0) {
                        $agent_data = $agentResult->fetch_assoc();
                        $user = array_merge($user, $agent_data);
                    }
                }
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $error_message = "Error updating user: " . $e->getMessage();
            }
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - PrimeEstate</title>
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
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
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
                
                <a href="properties.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-building text-indigo-600 w-5"></i>
                    <span class="ml-3">Properties</span>
                </a>
                
                <a href="users.php" class="sidebar-link active flex items-center px-4 py-3 my-1">
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Edit User</h1>
                
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
            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                    <p><?php echo $success_message; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Breadcrumbs -->
            <nav class="text-sm mb-6" aria-label="Breadcrumb">
                <ol class="list-none p-0 inline-flex">
                    <li class="flex items-center">
                        <a href="dashboard.php" class="text-gray-500 hover:text-indigo-600">Dashboard</a>
                        <svg class="fill-current w-3 h-3 mx-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512">
                            <path d="M285.476 272.971L91.132 467.314c-9.373 9.373-24.569 9.373-33.941 0l-22.667-22.667c-9.357-9.357-9.375-24.522-.04-33.901L188.505 256 34.484 101.255c-9.335-9.379-9.317-24.544.04-33.901l22.667-22.667c9.373-9.373 24.569-9.373 33.941 0L285.475 239.03c9.373 9.372 9.373 24.568.001 33.941z"/>
                        </svg>
                    </li>
                    <li class="flex items-center">
                        <a href="users.php" class="text-gray-500 hover:text-indigo-600">Users</a>
                        <svg class="fill-current w-3 h-3 mx-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512">
                            <path d="M285.476 272.971L91.132 467.314c-9.373 9.373-24.569 9.373-33.941 0l-22.667-22.667c-9.357-9.357-9.375-24.522-.04-33.901L188.505 256 34.484 101.255c-9.335-9.379-9.317-24.544.04-33.901l22.667-22.667c9.373-9.373 24.569-9.373 33.941 0L285.475 239.03c9.373 9.372 9.373 24.568.001 33.941z"/>
                        </svg>
                    </li>
                    <li>
                        <span class="text-indigo-600"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    </li>
                </ol>
            </nav>
            
            <!-- User Edit Form -->
            <div class="dashboard-card p-6 mb-6 fade-in-up">
                <form action="" method="POST">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Basic Information Section -->
                        <div class="col-span-2">
                            <h2 class="text-xl font-semibold text-gray-800 mb-4">Basic Information</h2>
                        </div>
                        
                        <div>
                            <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                            <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required
                                class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                        </div>
                        
                        <div>
                            <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                            <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required
                                class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                        </div>
                        
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                            <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" required
                                class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                        </div>
                        
                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                            <input type="text" name="phone" id="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                        </div>
                        
                        <div>
                            <label for="user_type" class="block text-sm font-medium text-gray-700 mb-1">User Type</label>
                            <select name="user_type" id="user_type" 
                                   class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                <option value="client" <?php echo $user['user_type'] === 'client' ? 'selected' : ''; ?>>Client</option>
                                <option value="agent" <?php echo $user['user_type'] === 'agent' ? 'selected' : ''; ?>>Agent</option>
                                <option value="admin" <?php echo $user['user_type'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password (leave blank to keep current)</label>
                            <input type="password" name="password" id="password"
                                class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                        </div>
                        
                        <div class="col-span-2">
                            <label for="bio" class="block text-sm font-medium text-gray-700 mb-1">Bio</label>
                            <textarea name="bio" id="bio" rows="4" 
                                    class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                        </div>
                        
                        <!-- Agent Information Section -->
                        <div class="col-span-2 mt-4" id="agent_section" style="<?php echo $user['user_type'] !== 'agent' ? 'display: none;' : ''; ?>">
                            <h2 class="text-xl font-semibold text-gray-800 mb-4">Agent Information</h2>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="license_number" class="block text-sm font-medium text-gray-700 mb-1">License Number</label>
                                    <input type="text" name="license_number" id="license_number" value="<?php echo htmlspecialchars($user['license_number'] ?? ''); ?>"
                                        class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                </div>
                                
                                <div>
                                    <label for="brokerage" class="block text-sm font-medium text-gray-700 mb-1">Brokerage</label>
                                    <input type="text" name="brokerage" id="brokerage" value="<?php echo htmlspecialchars($user['brokerage'] ?? ''); ?>"
                                        class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                </div>
                                
                                <div>
                                    <label for="experience_years" class="block text-sm font-medium text-gray-700 mb-1">Years of Experience</label>
                                    <input type="number" name="experience_years" id="experience_years" value="<?php echo isset($user['experience_years']) ? (int)$user['experience_years'] : ''; ?>"
                                        class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                </div>
                                
                                <div class="col-span-2">
                                    <label for="specialties" class="block text-sm font-medium text-gray-700 mb-1">Specialties</label>
                                    <textarea name="specialties" id="specialties" rows="3" 
                                            class="focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"><?php echo htmlspecialchars($user['specialties'] ?? ''); ?></textarea>
                                    <p class="mt-1 text-sm text-gray-500">Separate specialties with commas (e.g. Residential, Commercial, Luxury Homes)</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="col-span-2 flex justify-between mt-6">
                            <a href="users.php" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <i class="fas fa-arrow-left mr-2"></i> Back to Users
                            </a>
                            
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <i class="fas fa-save mr-2"></i> Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </main>
        
        <!-- Footer -->
        <footer class="bg-white p-4 mt-auto border-t">
            <div class="max-w-7xl mx-auto text-center text-sm text-gray-500">
                <p>&copy; <?php echo date('Y'); ?> PrimeEstate. All rights reserved.</p>
            </div>
        </footer>
    </div>
    
    <!-- JavaScript for mobile menu toggle and user type description -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const sidebar = document.getElementById('sidebar');
            
            mobileMenuButton.addEventListener('click', function() {
                if (sidebar.classList.contains('-translate-x-full')) {
                    // Show sidebar
                    sidebar.classList.remove('-translate-x-full');
                    sidebar.classList.add('translate-x-0');
                } else {
                    // Hide sidebar
                    sidebar.classList.remove('translate-x-0');
                    sidebar.classList.add('-translate-x-full');
                }
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth < 768) {
                    const isClickInsideSidebar = sidebar.contains(event.target);
                    const isClickOnMenuButton = mobileMenuButton.contains(event.target);
                    
                    if (!isClickInsideSidebar && !isClickOnMenuButton && !sidebar.classList.contains('-translate-x-full')) {
                        sidebar.classList.remove('translate-x-0');
                        sidebar.classList.add('-translate-x-full');
                    }
                }
            });
            
            // Hide mobile menu on window resize if transitioning to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    sidebar.classList.remove('-translate-x-full');
                    sidebar.classList.add('translate-x-0');
                } else {
                    sidebar.classList.remove('translate-x-0');
                    sidebar.classList.add('-translate-x-full');
                }
            });
            
            // Update user type description
            const userTypeSelect = document.getElementById('user_type');
            const userTypeDescription = document.getElementById('user-type-description');
            
            const descriptions = {
                'client': 'Regular user account for property browsing and saving favorites.',
                'agent': 'Real estate agent account with ability to list and manage properties.',
                'admin': 'Full administrative access to manage all aspects of the system.'
            };
            
            function updateDescription() {
                userTypeDescription.textContent = descriptions[userTypeSelect.value];
            }
            
            updateDescription(); // Set initial description
            
            userTypeSelect.addEventListener('change', updateDescription);
        });
    </script>
</body>
</html>