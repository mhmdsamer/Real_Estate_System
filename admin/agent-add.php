<?php
require_once '../connection.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$error_message = '';
$success_message = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $license_number = trim($_POST['license_number']);
    $brokerage = trim($_POST['brokerage']);
    $experience_years = (int)$_POST['experience_years'];
    $specialties = trim($_POST['specialties']);
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email) || empty($license_number)) {
        $error_message = "First name, last name, email, and license number are required fields.";
    } else {
        // Check if email already exists
        $checkEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $checkEmail->bind_param('s', $email);
        $checkEmail->execute();
        $emailResult = $checkEmail->get_result();
        
        if ($emailResult->num_rows > 0) {
            $error_message = "Email address already exists. Please use a different email.";
        } else {
            // Begin transaction
            $conn->begin_transaction();
            
            try {
                // Generate secure password if not provided
                if (empty($password)) {
                    $password = bin2hex(random_bytes(8)); // Generate 16 character random password
                }
                
                // Hash the password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert new user
                $userStmt = $conn->prepare("INSERT INTO users (email, password, first_name, last_name, phone, user_type) VALUES (?, ?, ?, ?, ?, 'agent')");
                $userStmt->bind_param('sssss', $email, $hashed_password, $first_name, $last_name, $phone);
                $userStmt->execute();
                
                // Get the inserted user ID
                $user_id = $conn->insert_id;
                
                // Insert agent details
                $agentStmt = $conn->prepare("INSERT INTO agents (user_id, license_number, brokerage, experience_years, specialties) VALUES (?, ?, ?, ?, ?)");
                $agentStmt->bind_param('issis', $user_id, $license_number, $brokerage, $experience_years, $specialties);
                $agentStmt->execute();
                
                // If everything is successful, commit the transaction
                $conn->commit();
                
                // Show the success message with the generated password if applicable
                if (isset($_POST['password']) && !empty($_POST['password'])) {
                    $success_message = "Agent has been added successfully.";
                } else {
                    $success_message = "Agent has been added successfully. Temporary password: " . $password;
                }
                
                // Clear form data after successful submission
                $first_name = $last_name = $email = $phone = $license_number = $brokerage = $specialties = '';
                $experience_years = 0;
                
            } catch (Exception $e) {
                // If an error occurs, roll back the transaction
                $conn->rollback();
                $error_message = "Error adding agent: " . $e->getMessage() . " - " . $conn->error;
            }
        }
    }
}

// Get list of brokerages for autocomplete suggestions
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
    <title>Add New Agent - PrimeEstate</title>
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Add New Agent</h1>
                
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
        
        <main class="p-4 md:p-6">
            <!-- Success and Error Messages -->
            <?php if(!empty($success_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 fade-in-up" role="alert">
                    <p><?php echo $success_message; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if(!empty($error_message)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 fade-in-up" role="alert">
                    <p><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Breadcrumb -->
            <nav class="text-sm mb-6" aria-label="Breadcrumb">
                <ol class="list-none p-0 inline-flex">
                    <li class="flex items-center">
                        <a href="dashboard.php" class="text-gray-500 hover:text-indigo-600">Dashboard</a>
                        <i class="fas fa-chevron-right text-gray-400 mx-2 text-xs"></i>
                    </li>
                    <li class="flex items-center">
                        <a href="agents.php" class="text-gray-500 hover:text-indigo-600">Agents</a>
                        <i class="fas fa-chevron-right text-gray-400 mx-2 text-xs"></i>
                    </li>
                    <li class="flex items-center">
                        <span class="text-indigo-600">Add New Agent</span>
                    </li>
                </ol>
            </nav>
            
            <!-- Agent Form Card -->
            <div class="dashboard-card p-6 mb-6 fade-in-up">
                <form action="" method="POST" class="space-y-6">
                    <h2 class="text-xl font-semibold mb-4 pb-2 border-b">Agent Information</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Personal Information -->
                        <div class="space-y-4">
                            <h3 class="text-lg font-medium text-gray-700">Personal Details</h3>
                            
                            <div>
                                <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                                <input type="text" id="first_name" name="first_name" required 
                                       value="<?php echo isset($first_name) ? htmlspecialchars($first_name) : ''; ?>" 
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            
                            <div>
                                <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                                <input type="text" id="last_name" name="last_name" required 
                                       value="<?php echo isset($last_name) ? htmlspecialchars($last_name) : ''; ?>" 
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address *</label>
                                <input type="email" id="email" name="email" required 
                                       value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" 
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                                <input type="tel" id="phone" name="phone" 
                                       value="<?php echo isset($phone) ? htmlspecialchars($phone) : ''; ?>" 
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            
                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                                    Password 
                                    <span class="text-xs text-gray-500">(Leave blank to auto-generate)</span>
                                </label>
                                <div class="relative">
                                    <input type="password" id="password" name="password" 
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <button type="button" id="toggle-password" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Professional Information -->
                        <div class="space-y-4">
                            <h3 class="text-lg font-medium text-gray-700">Professional Details</h3>
                            
                            <div>
                                <label for="license_number" class="block text-sm font-medium text-gray-700 mb-1">License Number *</label>
                                <input type="text" id="license_number" name="license_number" required 
                                       value="<?php echo isset($license_number) ? htmlspecialchars($license_number) : ''; ?>" 
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            
                            <div>
                                <label for="brokerage" class="block text-sm font-medium text-gray-700 mb-1">Brokerage</label>
                                <input type="text" id="brokerage" name="brokerage" list="brokerage-list" 
                                       value="<?php echo isset($brokerage) ? htmlspecialchars($brokerage) : ''; ?>" 
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <datalist id="brokerage-list">
                                    <?php foreach($brokerages as $broker): ?>
                                        <option value="<?php echo htmlspecialchars($broker); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            
                            <div>
                                <label for="experience_years" class="block text-sm font-medium text-gray-700 mb-1">Years of Experience</label>
                                <input type="number" id="experience_years" name="experience_years" min="0" max="60" 
                                       value="<?php echo isset($experience_years) ? htmlspecialchars($experience_years) : '0'; ?>" 
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            
                            <div>
                                <label for="specialties" class="block text-sm font-medium text-gray-700 mb-1">Specialties</label>
                                <textarea id="specialties" name="specialties" rows="4" 
                                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                          placeholder="e.g. Luxury Properties, Commercial, Residential, etc."><?php echo isset($specialties) ? htmlspecialchars($specialties) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="pt-5 border-t flex justify-between items-center">
                        <div class="text-xs text-gray-500">* Required fields</div>
                        <div class="flex space-x-3">
                            <a href="agents.php" class="inline-flex justify-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Cancel
                            </a>
                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Add Agent
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </main>
        
        <!-- Footer -->
        <footer class="bg-white p-4 border-t mt-auto">
            <div class="text-center text-sm text-gray-500">
                &copy; <?php echo date('Y'); ?> PrimeEstate. All rights reserved.
            </div>
        </footer>
    </div>
    
    <script>
        // Mobile sidebar toggle
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('-translate-x-full');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const button = document.getElementById('mobile-menu-button');
            
            if (window.innerWidth < 768 && !sidebar.contains(event.target) && !button.contains(event.target)) {
                sidebar.classList.add('-translate-x-full');
            }
        });
        
        // Toggle password visibility
        document.getElementById('toggle-password').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const passwordIcon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordIcon.classList.remove('fa-eye');
                passwordIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordIcon.classList.remove('fa-eye-slash');
                passwordIcon.classList.add('fa-eye');
            }
        });
    </script>
</body>
</html>