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
$user_query = $conn->prepare("SELECT first_name, last_name, email, phone FROM users WHERE user_id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user = $user_result->fetch_assoc();

// Format name for display
$agent_name = $user['first_name'] . ' ' . $user['last_name'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    $property_id = filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT);
    $client_id = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
    $viewing_date = $_POST['viewing_date'] ?? '';
    $viewing_time = $_POST['viewing_time'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    $errors = [];
    
    if (!$property_id) {
        $errors[] = "Please select a property.";
    }
    
    if (empty($viewing_date)) {
        $errors[] = "Please select a date.";
    }
    
    if (empty($viewing_time)) {
        $errors[] = "Please select a time.";
    }
    
    // If no errors, proceed with scheduling
    if (empty($errors)) {
        // Combine date and time
        $viewing_datetime = date('Y-m-d H:i:s', strtotime("$viewing_date $viewing_time"));
        
        // Insert into property_viewings table
        $insert_query = $conn->prepare("
            INSERT INTO property_viewings 
            (property_id, user_id, agent_id, viewing_date, status, notes, created_at) 
            VALUES (?, ?, ?, ?, 'confirmed', ?, NOW())
        ");
        
        // If client_id is empty, set it to NULL for the database
        if (empty($client_id)) {
            $insert_query->bind_param("iisss", $property_id, $null_value, $agent_id, $viewing_datetime, $notes);
        } else {
            $insert_query->bind_param("iisss", $property_id, $client_id, $agent_id, $viewing_datetime, $notes);
        }
        
        if ($insert_query->execute()) {
            // Success! Set flash message and redirect
            $_SESSION['flash_message'] = "Viewing scheduled successfully!";
            $_SESSION['flash_type'] = "success";
            header("Location: viewings.php");
            exit();
        } else {
            $errors[] = "Failed to schedule viewing. Please try again. Error: " . $conn->error;
        }
    }
}

// Get properties listed by this agent
$properties_query = $conn->prepare("
    SELECT p.property_id, p.title, p.address, p.city, p.state, p.status 
    FROM properties p 
    JOIN property_listings pl ON p.property_id = pl.property_id 
    WHERE pl.agent_id = ? 
    ORDER BY p.title ASC
");
$properties_query->bind_param("i", $agent_id);
$properties_query->execute();
$properties_result = $properties_query->get_result();

// Get all clients (users with type 'client')
$clients_query = $conn->prepare("
    SELECT user_id, first_name, last_name, email, phone 
    FROM users 
    WHERE user_type = 'client' 
    ORDER BY last_name, first_name
");
$clients_query->execute();
$clients_result = $clients_query->get_result();

// Function to format property status badge
function getPropertyStatusBadge($status) {
    switch ($status) {
        case 'for_sale':
            return '<span class="badge badge-success">For Sale</span>';
        case 'for_rent':
            return '<span class="badge badge-primary">For Rent</span>';
        case 'sold':
            return '<span class="badge badge-secondary">Sold</span>';
        case 'rented':
            return '<span class="badge badge-secondary">Rented</span>';
        case 'pending':
            return '<span class="badge badge-warning">Pending</span>';
        default:
            return '<span class="badge badge-secondary">Unknown</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Viewing - PrimeEstate</title>
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
        
        /* Badge styles */
        .badge {
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-primary {
            background-color: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }
        
        .badge-success {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .badge-warning {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .badge-danger {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .badge-secondary {
            background-color: rgba(107, 114, 128, 0.1);
            color: #6b7280;
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
                            </a>
                            <a href="viewings.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Schedule Property Viewing</h1>
                
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
                            <a href="viewings.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <!-- Flash Message Display -->
                <?php if (isset($_SESSION['flash_message'])): ?>
                    <div class="mb-4 p-4 rounded-lg <?php echo ($_SESSION['flash_type'] == 'success') ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>" id="flash-message">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center">
                                <i class="fas <?php echo ($_SESSION['flash_type'] == 'success') ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500'; ?> mr-2"></i>
                                <span><?php echo $_SESSION['flash_message']; ?></span>
                            </div>
                            <button type="button" class="text-gray-500 hover:text-gray-700" onclick="document.getElementById('flash-message').remove()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
                <?php endif; ?>
                
                <!-- Error Message Display -->
                <?php if (!empty($errors)): ?>
                    <div class="mb-4 p-4 rounded-lg bg-red-100 text-red-800">
                        <div class="flex items-center mb-2">
                            <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
                            <span class="font-medium">Please correct the following errors:</span>
                        </div>
                        <ul class="list-disc pl-5">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <!-- Back to Viewings Link -->
                <div class="mb-6">
                    <a href="viewings.php" class="inline-flex items-center text-sm text-indigo-600 hover:text-indigo-900">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Viewings
                    </a>
                </div>
                
                <!-- Page Header -->
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">Schedule a Property Viewing</h2>
                    <p class="text-gray-600 mt-1">Fill out the form below to schedule a property viewing with a client.</p>
                </div>
                
                <!-- Scheduling Form -->
                <div class="card p-6">
                    <form method="POST" action="schedule-viewing.php" class="space-y-6">
                        <!-- Select Property -->
                        <div>
                            <label for="property_id" class="block text-sm font-medium text-gray-700 mb-1">Select Property <span class="text-red-500">*</span></label>
                            <select id="property_id" name="property_id" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                                <option value="">-- Select a Property --</option>
                                <?php while ($property = $properties_result->fetch_assoc()): ?>
                                    <option value="<?php echo $property['property_id']; ?>" <?php echo (isset($_POST['property_id']) && $_POST['property_id'] == $property['property_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($property['title']); ?> - 
                                        <?php echo htmlspecialchars($property['address']); ?>, 
                                        <?php echo htmlspecialchars($property['city']); ?> 
                                        <?php echo getPropertyStatusBadge($property['status']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <p class="mt-2 text-xs text-gray-500">
                                Only properties that you are the listing agent for will appear here.
                            </p>
                        </div>
                        
                        <!-- Select Client -->
                        <div>
                            <label for="client_id" class="block text-sm font-medium text-gray-700 mb-1">Select Client</label>
                            <select id="client_id" name="client_id" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                <option value="">-- No Registered Client (Walk-in) --</option>
                                <?php while ($client = $clients_result->fetch_assoc()): ?>
                                    <option value="<?php echo $client['user_id']; ?>" <?php echo (isset($_POST['client_id']) && $_POST['client_id'] == $client['user_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?> 
                                        (<?php echo htmlspecialchars($client['email']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <p class="mt-2 text-xs text-gray-500">
                                If this is a walk-in client or someone not yet registered in the system, leave this empty.
                            </p>
                        </div>
                        
                        <!-- Date and Time Selection -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Viewing Date -->
                            <div>
                                <label for="viewing_date" class="block text-sm font-medium text-gray-700 mb-1">Viewing Date <span class="text-red-500">*</span></label>
                                <input type="date" id="viewing_date" name="viewing_date" 
                                    value="<?php echo isset($_POST['viewing_date']) ? $_POST['viewing_date'] : date('Y-m-d'); ?>" 
                                    min="<?php echo date('Y-m-d'); ?>"
                                    class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" 
                                    required>
                            </div>
                            
                            <!-- Viewing Time -->
                            <div>
                                <label for="viewing_time" class="block text-sm font-medium text-gray-700 mb-1">Viewing Time <span class="text-red-500">*</span></label>
                                <input type="time" id="viewing_time" name="viewing_time" 
                                    value="<?php echo isset($_POST['viewing_time']) ? $_POST['viewing_time'] : '10:00'; ?>" 
                                    class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" 
                                    required>
                            </div>
                        </div>
                        
                        <!-- Notes -->
                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea id="notes" name="notes" rows="3" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Add any special instructions or notes about this viewing"><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="flex justify-end">
                            <a href="viewings.php" class="mr-4 px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Cancel
                            </a>
                            <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Schedule Viewing
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Tips Card -->
                <div class="mt-6 card p-6 bg-blue-50 border border-blue-100">
                    <h3 class="text-lg font-medium text-blue-800 mb-2">
                        <i class="fas fa-lightbulb text-blue-500 mr-2"></i> Tips for Successful Property Viewings
                    </h3>
                    <ul class="list-disc pl-5 text-blue-700 space-y-2">
                        <li>Schedule viewings during daylight hours to showcase natural lighting.</li>
                        <li>Allow at least 30-45 minutes per viewing for a thorough tour.</li>
                        <li>Consider scheduling back-to-back viewings with a 15-minute buffer in between.</li>
                        <li>Send a confirmation email or text to clients a day before the scheduled viewing.</li>
                        <li>Prepare property information sheets to hand out during the viewing.</li>
                    </ul>
                </div>
            </main>
        </div>
    </div>
    
    <script>
        // Toggle user dropdown menu
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
        
        // Flash message auto-dismissal
        const flashMessage = document.getElementById('flash-message');
        if (flashMessage) {
            setTimeout(() => {
                flashMessage.remove();
            }, 5000); // Dismiss after 5 seconds
        }
        
        // Set minimum time to current time if date is today
        const viewingDate = document.getElementById('viewing_date');
        const viewingTime = document.getElementById('viewing_time');
        
        viewingDate.addEventListener('change', function() {
            // If selected date is today, ensure time is not in the past
            if (this.value === new Date().toISOString().split('T')[0]) {
                const now = new Date();
                const currentHour = now.getHours().toString().padStart(2, '0');
                const currentMinute = now.getMinutes().toString().padStart(2, '0');
                const currentTime = `${currentHour}:${currentMinute}`;
                
                if (viewingTime.value < currentTime) {
                    viewingTime.value = currentTime;
                }
            }
        });
    </script>
</body>
</html>