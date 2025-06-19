<?php
require_once '../connection.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Initialize variables
$agent_id = null;
$agent_data = null;
$error_message = null;
$success_message = null;

// Check if agent ID is provided
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $agent_id = (int)$_GET['id'];
    
    // Fetch agent data
    $stmt = $conn->prepare("
        SELECT a.agent_id, a.license_number, a.brokerage, a.experience_years, a.specialties,
               u.user_id, u.email, u.first_name, u.last_name, u.phone, u.bio
        FROM agents a
        JOIN users u ON a.user_id = u.user_id
        WHERE a.agent_id = ?
    ");
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $error_message = "Agent not found.";
    } else {
        $agent_data = $result->fetch_assoc();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_agent'])) {
    $agent_id = (int)$_POST['agent_id'];
    $user_id = (int)$_POST['user_id'];
    
    // Validate and sanitize input
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $phone = trim($_POST['phone']);
    $license_number = trim($_POST['license_number']);
    $brokerage = trim($_POST['brokerage']);
    $experience_years = (int)$_POST['experience_years'];
    $specialties = trim($_POST['specialties']);
    $bio = trim($_POST['bio']);
    
    // Basic validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($license_number)) {
        $error_message = "First name, last name, email, and license number are required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } elseif ($experience_years < 0 || $experience_years > 100) {
        $error_message = "Experience years must be between 0 and 100.";
    } else {
        // Check if email already exists for another user
        $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $check_email->bind_param('si', $email, $user_id);
        $check_email->execute();
        $email_result = $check_email->get_result();
        
        if ($email_result->num_rows > 0) {
            $error_message = "Email address is already in use by another account.";
        } else {
            // Check if license number already exists for another agent
            $check_license = $conn->prepare("SELECT agent_id FROM agents WHERE license_number = ? AND agent_id != ?");
            $check_license->bind_param('si', $license_number, $agent_id);
            $check_license->execute();
            $license_result = $check_license->get_result();
            
            if ($license_result->num_rows > 0) {
                $error_message = "License number is already in use by another agent.";
            } else {
                // Begin transaction
                $conn->begin_transaction();
                
                try {
                    // Update user information
                    $updateUser = $conn->prepare("
                        UPDATE users 
                        SET first_name = ?, last_name = ?, email = ?, phone = ?, bio = ?
                        WHERE user_id = ?
                    ");
                    $updateUser->bind_param('sssssi', $first_name, $last_name, $email, $phone, $bio, $user_id);
                    $updateUser->execute();
                    
                    // Update agent information
                    $updateAgent = $conn->prepare("
                        UPDATE agents 
                        SET license_number = ?, brokerage = ?, experience_years = ?, specialties = ?
                        WHERE agent_id = ?
                    ");
                    $updateAgent->bind_param('ssisi', $license_number, $brokerage, $experience_years, $specialties, $agent_id);
                    $updateAgent->execute();
                    
                    // Commit transaction
                    $conn->commit();
                    $success_message = "Agent information updated successfully!";
                    
                    // Refresh agent data after update
                    $stmt = $conn->prepare("
                        SELECT a.agent_id, a.license_number, a.brokerage, a.experience_years, a.specialties,
                               u.user_id, u.email, u.first_name, u.last_name, u.phone, u.bio
                        FROM agents a
                        JOIN users u ON a.user_id = u.user_id
                        WHERE a.agent_id = ?
                    ");
                    $stmt->bind_param('i', $agent_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $agent_data = $result->fetch_assoc();
                    
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $conn->rollback();
                    $error_message = "Error updating agent information: " . $e->getMessage();
                }
            }
        }
    }
}

// Get listing count for this agent
$listing_count = 0;
if ($agent_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM property_listings WHERE agent_id = ?");
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $listing_count = $row['count'];
}

// Get broker list for dropdown
$brokerageQuery = "SELECT DISTINCT brokerage FROM agents WHERE brokerage IS NOT NULL AND brokerage != '' ORDER BY brokerage";
$brokerageResult = $conn->query($brokerageQuery);
$brokerages = [];
while ($row = $brokerageResult->fetch_assoc()) {
    $brokerages[] = $row['brokerage'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Agent - PrimeEstate</title>
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
                
                <a href="users.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-users text-indigo-600 w-5"></i>
                    <span class="ml-3">Users</span>
                </a>
                
                <a href="agents.php" class="sidebar-link active flex items-center px-4 py-3 my-1">
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">
                    <?php echo isset($agent_data) ? "Edit Agent: " . htmlspecialchars($agent_data['first_name'] . ' ' . $agent_data['last_name']) : "Agent Not Found"; ?>
                </h1>
                
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
            <!-- Breadcrumbs -->
            <div class="text-sm breadcrumbs mb-6">
                <ul class="flex items-center space-x-2 text-gray-500">
                    <li><a href="dashboard.php" class="hover:text-indigo-600"><i class="fas fa-home"></i></a></li>
                    <li class="flex items-center">
                        <i class="fas fa-chevron-right text-xs mx-2"></i>
                        <a href="agents.php" class="hover:text-indigo-600">Agents</a>
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-chevron-right text-xs mx-2"></i>
                        <span class="text-gray-700">Edit Agent</span>
                    </li>
                </ul>
            </div>
            
            <?php if(isset($error_message)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if(isset($success_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                    <p><?php echo $success_message; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if(isset($agent_data)): ?>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Agent Form -->
                    <div class="lg:col-span-2">
                        <div class="dashboard-card p-6">
                            <h2 class="text-xl font-semibold mb-6">Agent Information</h2>
                            
                            <form action="" method="POST">
                                <input type="hidden" name="agent_id" value="<?php echo $agent_data['agent_id']; ?>">
                                <input type="hidden" name="user_id" value="<?php echo $agent_data['user_id']; ?>">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                    <!-- First Name -->
                                    <div>
                                        <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($agent_data['first_name']); ?>"
                                               class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                               required>
                                    </div>
                                    
                                    <!-- Last Name -->
                                    <div>
                                        <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($agent_data['last_name']); ?>"
                                               class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                               required>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                    <!-- Email -->
                                    <div>
                                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address <span class="text-red-500">*</span></label>
                                        <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($agent_data['email']); ?>"
                                               class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                               required>
                                    </div>
                                    
                                    <!-- Phone -->
                                    <div>
                                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                                        <input type="tel" name="phone" id="phone" value="<?php echo htmlspecialchars($agent_data['phone']); ?>"
                                               class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                    <!-- License Number -->
                                    <div>
                                        <label for="license_number" class="block text-sm font-medium text-gray-700 mb-1">License Number <span class="text-red-500">*</span></label>
                                        <input type="text" name="license_number" id="license_number" value="<?php echo htmlspecialchars($agent_data['license_number']); ?>"
                                               class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                                               required>
                                    </div>
                                    
                                    <!-- Experience Years -->
                                    <div>
                                        <label for="experience_years" class="block text-sm font-medium text-gray-700 mb-1">Years of Experience</label>
                                        <input type="number" name="experience_years" id="experience_years" min="0" max="100" value="<?php echo $agent_data['experience_years']; ?>"
                                               class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                    </div>
                                </div>
                                
                                <div class="mb-6">
                                    <!-- Brokerage -->
                                    <label for="brokerage" class="block text-sm font-medium text-gray-700 mb-1">Brokerage</label>
                                    <div class="relative">
                                        <input type="text" name="brokerage" id="brokerage" value="<?php echo htmlspecialchars($agent_data['brokerage']); ?>"
                                               list="brokerage_list" 
                                               class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                        <datalist id="brokerage_list">
                                            <?php foreach($brokerages as $brokerage): ?>
                                                <option value="<?php echo htmlspecialchars($brokerage); ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                </div>
                                
                                <div class="mb-6">
                                    <!-- Specialties -->
                                    <label for="specialties" class="block text-sm font-medium text-gray-700 mb-1">Specialties</label>
                                    <input type="text" name="specialties" id="specialties" value="<?php echo htmlspecialchars($agent_data['specialties']); ?>"
                                           placeholder="Residential, Commercial, Investment Properties, etc."
                                           class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md">
                                    <p class="mt-1 text-xs text-gray-500">Separate specialties with commas</p>
                                </div>
                                
                                <div class="mb-6">
                                    <!-- Bio -->
                                    <label for="bio" class="block text-sm font-medium text-gray-700 mb-1">Bio</label>
                                    <textarea name="bio" id="bio" rows="5"
                                              class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"><?php echo htmlspecialchars($agent_data['bio']); ?></textarea>
                                </div>
                                
                                <div class="flex justify-between mt-8">
                                    <a href="agents.php" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                        <i class="fas fa-arrow-left mr-2"></i> Back to Agents
                                    </a>
                                    <button type="submit" name="update_agent" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                        <i class="fas fa-save mr-2"></i> Update Agent
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Agent Information Card -->
                    <div class="lg:col-span-1">
                        <div class="dashboard-card p-6 mb-6">
                            <div class="flex items-center justify-center mb-6">
                                <div class="w-24 h-24 rounded-full bg-indigo-100 flex items-center justify-center">
                                    <i class="fas fa-user-tie text-indigo-600 text-4xl"></i>
                                </div>
                            </div>
                            
                            <div class="text-center mb-6">
                                <h3 class="text-lg font-semibold"><?php echo htmlspecialchars($agent_data['first_name'] . ' ' . $agent_data['last_name']); ?></h3>
                                <p class="text-gray-500"><?php echo htmlspecialchars($agent_data['brokerage']); ?></p>
                            </div>
                            
                            <div class="border-t pt-4">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="text-sm text-gray-500">User ID:</span>
                                    <span class="text-sm font-medium"><?php echo $agent_data['user_id']; ?></span>
                                </div>
                                <div class="flex items-center justify-between mb-3">
                                    <span class="text-sm text-gray-500">Agent ID:</span>
                                    <span class="text-sm font-medium"><?php echo $agent_data['agent_id']; ?></span>
                                </div>
                                <div class="flex items-center justify-between mb-3">
                                    <span class="text-sm text-gray-500">Active Listings:</span>
                                    <span class="text-sm font-medium"><?php echo $listing_count; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="dashboard-card p-6">
                            <h3 class="text-lg font-semibold mb-4">Quick Actions</h3>
                            
                            <div class="space-y-3">
                                <a href="agent-view.php?id=<?php echo $agent_data['agent_id']; ?>" class="flex items-center p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                                    <div class="rounded-full bg-blue-100 p-2 mr-3">
                                        <i class="fas fa-eye text-blue-600"></i>
                                    </div>
                                    <span>View Profile</span>
                                </a>
                                
                                
                                
                                <?php if($listing_count == 0): ?>
                                    <button type="button" onclick="confirmDelete(<?php echo $agent_data['agent_id']; ?>)" class="w-full flex items-center p-3 rounded-lg bg-gray-50 hover:bg-red-50 transition-colors">
                                        <div class="rounded-full bg-red-100 p-2 mr-3">
                                            <i class="fas fa-trash text-red-600"></i>
                                        </div>
                                        <span>Remove Agent</span>
                                    </button>
                                    
                                    <form id="delete-form" action="agents.php" method="POST" class="hidden">
                                        <input type="hidden" name="agent_id" id="delete-agent-id">
                                        <input type="hidden" name="delete_agent" value="1">
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-white shadow rounded-lg p-8 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-red-100 rounded-full mb-4">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900 mb-2">Agent Not Found</h2>
                    <p class="text-gray-600 mb-6">The agent you are looking for does not exist or has been removed.</p>
                    <a href="agents.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Agents List
                    </a>
                </div>
            <?php endif; ?>
        </main>
        
        <!-- Footer -->
        <footer class="bg-white p-4 mt-auto border-t">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <p class="text-center text-sm text-gray-500">
                    &copy; <?php echo date('Y'); ?> PrimeEstate. All rights reserved.
                </p>
            </div>
        </footer>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.remove('-translate-x-full');
            } else {
                sidebar.classList.add('-translate-x-full');
            }
        });
        
        // Delete confirmation
        function confirmDelete(agentId) {
            if (confirm('Are you sure you want to delete this agent? This action cannot be undone.')) {
                document.getElementById('delete-agent-id').value = agentId;
                document.getElementById('delete-form').submit();
            }
        }
    </script>
</body>
</html>