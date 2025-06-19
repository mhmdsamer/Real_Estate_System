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

// Handle inquiry status update
if (isset($_POST['update_status'])) {
    $inquiry_id = $_POST['inquiry_id'];
    $new_status = $_POST['new_status'];
    
    $update_query = $conn->prepare("UPDATE inquiries SET status = ? WHERE inquiry_id = ?");
    $update_query->bind_param("si", $new_status, $inquiry_id);
    
    if ($update_query->execute()) {
        $status_updated = true;
    } else {
        $status_update_error = true;
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$property_filter = isset($_GET['property_id']) ? $_GET['property_id'] : '';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';

// Build SQL query with filters
$sql = "
    SELECT i.*, p.title as property_title, p.address, p.city, p.state
    FROM inquiries i
    JOIN properties p ON i.property_id = p.property_id
    JOIN property_listings pl ON p.property_id = pl.property_id
    WHERE pl.agent_id = ?
";

// Add status filter
if ($status_filter != 'all') {
    $sql .= " AND i.status = ?";
}

// Add property filter
if (!empty($property_filter)) {
    $sql .= " AND i.property_id = ?";
}

// Add search filter
if (!empty($search_query)) {
    $sql .= " AND (i.name LIKE ? OR i.email LIKE ? OR i.message LIKE ? OR p.title LIKE ?)";
}

// Add sorting
$sql .= " ORDER BY 
    CASE 
        WHEN i.status = 'new' THEN 1
        WHEN i.status = 'in_progress' THEN 2
        WHEN i.status = 'responded' THEN 3
        WHEN i.status = 'closed' THEN 4
    END,
    i.created_at DESC
";

// Prepare the statement
$stmt = $conn->prepare($sql);

// Bind parameters based on filters
if ($status_filter != 'all' && !empty($property_filter) && !empty($search_query)) {
    $search_param = "%$search_query%";
    $stmt->bind_param("issssss", $agent_id, $status_filter, $property_filter, $search_param, $search_param, $search_param, $search_param);
} elseif ($status_filter != 'all' && !empty($property_filter)) {
    $stmt->bind_param("iss", $agent_id, $status_filter, $property_filter);
} elseif ($status_filter != 'all' && !empty($search_query)) {
    $search_param = "%$search_query%";
    $stmt->bind_param("issss", $agent_id, $status_filter, $search_param, $search_param, $search_param, $search_param);
} elseif (!empty($property_filter) && !empty($search_query)) {
    $search_param = "%$search_query%";
    $stmt->bind_param("issss", $agent_id, $property_filter, $search_param, $search_param, $search_param, $search_param);
} elseif ($status_filter != 'all') {
    $stmt->bind_param("is", $agent_id, $status_filter);
} elseif (!empty($property_filter)) {
    $stmt->bind_param("is", $agent_id, $property_filter);
} elseif (!empty($search_query)) {
    $search_param = "%$search_query%";
    $stmt->bind_param("issss", $agent_id, $search_param, $search_param, $search_param, $search_param);
} else {
    $stmt->bind_param("i", $agent_id);
}

$stmt->execute();
$result = $stmt->get_result();

// Get property list for filter dropdown
$properties_query = $conn->prepare("
    SELECT p.property_id, p.title, p.address, p.city, p.state
    FROM properties p
    JOIN property_listings pl ON p.property_id = pl.property_id
    WHERE pl.agent_id = ?
    ORDER BY p.title ASC
");
$properties_query->bind_param("i", $agent_id);
$properties_query->execute();
$properties_result = $properties_query->get_result();

// Count total inquiries by status
$count_query = $conn->prepare("
    SELECT i.status, COUNT(*) as count
    FROM inquiries i
    JOIN properties p ON i.property_id = p.property_id
    JOIN property_listings pl ON p.property_id = pl.property_id
    WHERE pl.agent_id = ?
    GROUP BY i.status
");
$count_query->bind_param("i", $agent_id);
$count_query->execute();
$count_result = $count_query->get_result();

$status_counts = [
    'new' => 0,
    'in_progress' => 0,
    'responded' => 0,
    'closed' => 0
];

while ($count = $count_result->fetch_assoc()) {
    $status_counts[$count['status']] = $count['count'];
}

$total_inquiries = array_sum($status_counts);

// Format name for display
$agent_name = $user['first_name'] . ' ' . $user['last_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Inquiries - PrimeEstate</title>
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
        
        /* Mobile menu animation */
        .mobile-menu {
            transition: transform 0.3s ease;
        }
        
        .mobile-menu.hidden {
            transform: translateX(-100%);
        }
        
        /* Status pill styles */
        .status-pill {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-new {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .status-in_progress {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-responded {
            background-color: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }
        
        .status-closed {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        /* Tab styles */
        .tab {
            cursor: pointer;
            padding: 0.75rem 1rem;
            border-bottom: 2px solid transparent;
            font-weight: 500;
        }
        
        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        /* Dropdown menu */
        .dropdown-menu {
            position: absolute;
            right: 0;
            margin-top: 0.5rem;
            min-width: 10rem;
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            z-index: 50;
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
                            <a href="inquiries.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
                                <i class="fas fa-question-circle w-5 h-5 mr-3 text-gray-500"></i>
                                Inquiries
                                <?php if ($status_counts['new'] > 0): ?>
                                <span class="ml-auto px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800"><?php echo $status_counts['new']; ?></span>
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Manage Inquiries</h1>
                
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
                            <a href="inquiries.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
                                <i class="fas fa-question-circle w-5 h-5 mr-3 text-gray-500"></i>
                                Inquiries
                                <?php if ($status_counts['new'] > 0): ?>
                                <span class="ml-auto px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800"><?php echo $status_counts['new']; ?></span>
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
                <!-- Status notification -->
                <?php if (isset($status_updated) && $status_updated): ?>
                <div id="status-alert" class="mb-4 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 flex justify-between items-center">
                    <div>
                        <i class="fas fa-check-circle mr-2"></i>
                        Inquiry status has been updated successfully.
                    </div>
                    <button type="button" onclick="document.getElementById('status-alert').remove()" class="text-green-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($status_update_error) && $status_update_error): ?>
                <div id="error-alert" class="mb-4 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 flex justify-between items-center">
                    <div>
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        Error updating inquiry status. Please try again.
                    </div>
                    <button type="button" onclick="document.getElementById('error-alert').remove()" class="text-red-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
                
                <!-- Inquiries Overview Stats -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="card p-4 flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-500">Total Inquiries</p>
                            <p class="text-2xl font-semibold"><?php echo $total_inquiries; ?></p>
                        </div>
                        <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                            <i class="fas fa-envelope text-indigo-600"></i>
                        </div>
                    </div>
                    
                    <div class="card p-4 flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-500">New</p>
                            <p class="text-2xl font-semibold"><?php echo $status_counts['new']; ?></p>
                        </div>
                        <div class="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center">
                            <i class="fas fa-inbox text-red-600"></i>
                        </div>
                    </div>
                    
                    <div class="card p-4 flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-500">In Progress</p>
                            <p class="text-2xl font-semibold"><?php echo $status_counts['in_progress']; ?></p>
                        </div>
                        <div class="h-10 w-10 rounded-full bg-yellow-100 flex items-center justify-center">
                            <i class="fas fa-spinner text-yellow-600"></i>
                        </div>
                    </div>
                    
                    <div class="card p-4 flex justify-between items-center">
                        <div>
                            <p class="text-sm text-gray-500">Responded</p>
                            <p class="text-2xl font-semibold"><?php echo $status_counts['responded'] + $status_counts['closed']; ?></p>
                        </div>
                        <div class="h-10 w-10 rounded-full bg-green-100 flex items-center justify-center">
                            <i class="fas fa-check text-green-600"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Filter and Search Section -->
                <div class="card p-4 mb-6">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="status" name="status" class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="new" <?php echo $status_filter == 'new' ? 'selected' : ''; ?>>New</option>
                                <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="responded" <?php echo $status_filter == 'responded' ? 'selected' : ''; ?>>Responded</option>
                                <option value="closed" <?php echo $status_filter == 'closed' ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="property_id" class="block text-sm font-medium text-gray-700 mb-1">Property</label>
                            <select id="property_id" name="property_id" class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                <option value="">All Properties</option>
                                <?php while ($property = $properties_result->fetch_assoc()): ?>
                                <option value="<?php echo $property['property_id']; ?>" <?php echo $property_filter == $property['property_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($property['title'] . ' - ' . $property['address']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label><div class="flex">
                                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search by name, email, or message..." class="block w-full px-3 py-2 border border-gray-300 rounded-l-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-l-0 border-gray-300 text-sm font-medium rounded-r-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Inquiries Listing -->
                <div class="card overflow-hidden">
                    <div class="overflow-x-auto">
                        <?php if ($result->num_rows > 0): ?>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Property</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Message</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php while ($inquiry = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($inquiry['property_title']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($inquiry['address'] . ', ' . $inquiry['city'] . ', ' . $inquiry['state']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($inquiry['name']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($inquiry['email']); ?>
                                        </div>
                                        <?php if (!empty($inquiry['phone'])): ?>
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($inquiry['phone']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900 max-w-xs overflow-hidden text-ellipsis" style="max-height: 3rem;">
                                            <?php echo htmlspecialchars(substr($inquiry['message'], 0, 100) . (strlen($inquiry['message']) > 100 ? '...' : '')); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-500">
                                            <?php echo date('M d, Y g:i A', strtotime($inquiry['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="status-pill status-<?php echo $inquiry['status']; ?>">
                                            <?php 
                                            switch($inquiry['status']) {
                                                case 'new':
                                                    echo 'New';
                                                    break;
                                                case 'in_progress':
                                                    echo 'In Progress';
                                                    break;
                                                case 'responded':
                                                    echo 'Responded';
                                                    break;
                                                case 'closed':
                                                    echo 'Closed';
                                                    break;
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="inquiry-detail.php?id=<?php echo $inquiry['inquiry_id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <div class="relative" x-data="{ open: false }">
                                                <button @click="open = !open" class="text-gray-500 hover:text-gray-700">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                
                                                <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                                                    <?php if ($inquiry['status'] !== 'in_progress'): ?>
                                                    <form method="POST" class="w-full">
                                                        <input type="hidden" name="inquiry_id" value="<?php echo $inquiry['inquiry_id']; ?>">
                                                        <input type="hidden" name="new_status" value="in_progress">
                                                        <button type="submit" name="update_status" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                            Mark as In Progress
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($inquiry['status'] !== 'responded'): ?>
                                                    <form method="POST" class="w-full">
                                                        <input type="hidden" name="inquiry_id" value="<?php echo $inquiry['inquiry_id']; ?>">
                                                        <input type="hidden" name="new_status" value="responded">
                                                        <button type="submit" name="update_status" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                            Mark as Responded
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($inquiry['status'] !== 'closed'): ?>
                                                    <form method="POST" class="w-full">
                                                        <input type="hidden" name="inquiry_id" value="<?php echo $inquiry['inquiry_id']; ?>">
                                                        <input type="hidden" name="new_status" value="closed">
                                                        <button type="submit" name="update_status" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                            Mark as Closed
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                    
                                                    <a href="mailto:<?php echo htmlspecialchars($inquiry['email']); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                        Email Client
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="p-6 text-center">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 mb-4">
                                <i class="fas fa-inbox text-gray-500 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-medium text-gray-900 mb-1">No inquiries found</h3>
                            <p class="text-gray-500">
                                <?php if (!empty($search_query) || $status_filter != 'all' || !empty($property_filter)): ?>
                                Try adjusting your filters to see more results.
                                <?php else: ?>
                                When clients inquire about your properties, they'll appear here.
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($search_query) || $status_filter != 'all' || !empty($property_filter)): ?>
                            <a href="inquiries.php" class="mt-4 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Clear All Filters
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.8.2/dist/alpine.min.js" defer></script>
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
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const mobileMenu = document.getElementById('mobile-menu');
        
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.remove('hidden');
        });
        
        function closeMobileMenu() {
            mobileMenu.classList.add('hidden');
        }
        
        closeMobileMenuButton.addEventListener('click', closeMobileMenu);
        mobileMenuOverlay.addEventListener('click', closeMobileMenu);
        
        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            const statusAlert = document.getElementById('status-alert');
            if (statusAlert) {
                statusAlert.remove();
            }
            
            const errorAlert = document.getElementById('error-alert');
            if (errorAlert) {
                errorAlert.remove();
            }
        }, 5000);
    </script>
</body>
</html>