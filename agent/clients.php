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

// Process search filters if any
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build client query with filters
$client_query_sql = "
    SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email, u.phone, u.created_at,
           (SELECT COUNT(*) FROM property_viewings pv WHERE pv.user_id = u.user_id AND pv.agent_id = ?) as viewings_count,
           (SELECT COUNT(*) FROM transactions t WHERE (t.buyer_id = u.user_id OR t.seller_id = u.user_id) 
            AND (t.listing_agent_id = ? OR t.buyer_agent_id = ?)) as transactions_count,
           CASE 
               WHEN EXISTS (SELECT 1 FROM transactions t WHERE (t.buyer_id = u.user_id OR t.seller_id = u.user_id) 
                          AND (t.listing_agent_id = ? OR t.buyer_agent_id = ?) AND t.status = 'completed') THEN 'active'
               WHEN EXISTS (SELECT 1 FROM property_viewings pv WHERE pv.user_id = u.user_id AND pv.agent_id = ? AND pv.viewing_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) THEN 'prospect'
               WHEN EXISTS (SELECT 1 FROM inquiries i JOIN property_listings pl ON i.property_id = pl.property_id 
                          WHERE i.user_id = u.user_id AND pl.agent_id = ? AND i.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) THEN 'lead'
               ELSE 'inactive'
           END as client_status
    FROM users u
    WHERE u.user_type = 'client' AND (
        u.user_id IN (SELECT pv.user_id FROM property_viewings pv WHERE pv.agent_id = ? AND pv.user_id IS NOT NULL) OR
        u.user_id IN (SELECT i.user_id FROM inquiries i JOIN property_listings pl ON i.property_id = pl.property_id WHERE pl.agent_id = ? AND i.user_id IS NOT NULL) OR
        u.user_id IN (SELECT t.buyer_id FROM transactions t WHERE (t.listing_agent_id = ? OR t.buyer_agent_id = ?) AND t.buyer_id IS NOT NULL) OR
        u.user_id IN (SELECT t.seller_id FROM transactions t WHERE (t.listing_agent_id = ? OR t.buyer_agent_id = ?) AND t.seller_id IS NOT NULL)
    )
";

// Add search filter if provided
if (!empty($search)) {
    $search_param = "%$search%";
    $client_query_sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
}

// Add status filter if provided
if (!empty($status_filter)) {
    $client_query_sql .= " HAVING client_status = ?";
}

$client_query_sql .= " ORDER BY u.last_name, u.first_name";

// Prepare and execute the query
$client_query = $conn->prepare($client_query_sql);

// Bind parameters based on filters
if (!empty($search) && !empty($status_filter)) {
    $client_query->bind_param("iiiiiiiiiiiiissss", 
        $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id,
        $search_param, $search_param, $search_param, $search_param, $status_filter);
} elseif (!empty($search)) {
    $client_query->bind_param("iiiiiiiiiiiiissss", 
        $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id,
        $search_param, $search_param, $search_param, $search_param);
} elseif (!empty($status_filter)) {
    $client_query->bind_param("iiiiiiiiiiiiis", 
        $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id,
        $status_filter);
} else {
    $client_query->bind_param("iiiiiiiiiiiii", 
        $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id, $agent_id);
}

$client_query->execute();
$clients_result = $client_query->get_result();

// Get recent client activity
$activity_query = $conn->prepare("
    (SELECT 
        u.user_id, CONCAT(u.first_name, ' ', u.last_name) as client_name, 
        p.title as property_title, p.property_id,
        'viewing' as activity_type, 
        pv.viewing_date as activity_date,
        pv.status as activity_status
    FROM property_viewings pv
    JOIN users u ON pv.user_id = u.user_id
    JOIN properties p ON pv.property_id = p.property_id
    WHERE pv.agent_id = ? AND pv.user_id IS NOT NULL)
    
    UNION
    
    (SELECT 
        u.user_id, CONCAT(u.first_name, ' ', u.last_name) as client_name,
        p.title as property_title, p.property_id,
        'inquiry' as activity_type,
        i.created_at as activity_date,
        i.status as activity_status
    FROM inquiries i
    JOIN users u ON i.user_id = u.user_id
    JOIN properties p ON i.property_id = p.property_id
    JOIN property_listings pl ON p.property_id = pl.property_id
    WHERE pl.agent_id = ? AND i.user_id IS NOT NULL)
    
    UNION
    
    (SELECT 
        CASE WHEN t.buyer_id IS NOT NULL THEN t.buyer_id ELSE t.seller_id END as user_id,
        CASE 
            WHEN t.buyer_id IS NOT NULL THEN (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE user_id = t.buyer_id)
            ELSE (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE user_id = t.seller_id)
        END as client_name,
        p.title as property_title, p.property_id,
        'transaction' as activity_type,
        t.updated_at as activity_date,
        t.status as activity_status
    FROM transactions t
    JOIN properties p ON t.property_id = p.property_id
    WHERE (t.listing_agent_id = ? OR t.buyer_agent_id = ?) 
    AND (t.buyer_id IS NOT NULL OR t.seller_id IS NOT NULL))
    
    ORDER BY activity_date DESC
    LIMIT 10
");

$activity_query->bind_param("iiii", $agent_id, $agent_id, $agent_id, $agent_id);
$activity_query->execute();
$activity_result = $activity_query->get_result();

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Management - PrimeEstate</title>
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Client Management</h1>
                
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
                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Your Clients</h2>
                        <p class="text-gray-600 mt-1">Manage your client relationships and track their activities</p>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <a href="add-client.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-user-plus mr-2"></i>
                            Add New Client
                        </a>
                    </div>
                </div>
                
                <!-- Filter and Search Section -->
                <div class="bg-white rounded-lg shadow mb-6 p-4">
                    <form action="clients.php" method="GET" class="flex flex-col md:flex-row gap-4">
                        <div class="flex-1">
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search Clients</label>
                            <div class="relative rounded-md shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-md" placeholder="Search by name, email or phone">
                            </div>
                        </div>
                        
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Filter by Status</label>
                            <select name="status" id="status" class="focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                <option value="">All Statuses</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active Clients</option>
                                <option value="prospect" <?php echo $status_filter === 'prospect' ? 'selected' : ''; ?>>Prospects</option>
                                <option value="lead" <?php echo $status_filter === 'lead' ? 'selected' : ''; ?>>Leads</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="flex items-end">
                            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Filter Results
                            </button>
                            <?php if (!empty($search) || !empty($status_filter)): ?>
                                <a href="clients.php" class="ml-2 text-indigo-600 hover:text-indigo-900 text-sm font-medium flex items-center">
                                    <i class="fas fa-times mr-1"></i> Clear
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Clients List -->
                <div class="bg-white rounded-lg shadow">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Client
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Contact Info
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Activity
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($clients_result->num_rows > 0): ?>
                                    <?php while ($client = $clients_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10">
                                                        <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-500">
                                                            <i class="fas fa-user"></i>
                                                        </div>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?>
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            Client since <?php echo date('M Y', strtotime($client['created_at'])); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    <i class="fas fa-envelope text-gray-400 mr-1"></i> <?php echo htmlspecialchars($client['email']); ?>
                                                </div>
                                                <?php if (!empty($client['phone'])): ?>
                                                <div class="text-sm text-gray-500">
                                                    <i class="fas fa-phone text-gray-400 mr-1"></i> <?php echo htmlspecialchars($client['phone']); ?>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full status-<?php echo $client['client_status']; ?>">
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
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <div class="flex items-center space-x-2">
                                                    <div class="flex items-center">
                                                        <i class="fas fa-eye text-indigo-400 mr-1"></i>
                                                        <span><?php echo $client['viewings_count']; ?> viewings</span>
                                                    </div>
                                                    <span class="text-gray-300">|</span>
                                                    <div class="flex items-center">
                                                        <i class="fas fa-exchange-alt text-indigo-400 mr-1"></i>
                                                        <span><?php echo $client['transactions_count']; ?> transactions</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex items-center space-x-2">
                                                    <a href="client-detail.php?id=<?php echo $client['user_id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="client-edit.php?id=<?php echo $client['user_id']; ?>" class="text-gray-600 hover:text-gray-900">
                                                        <i class="fas fa-pencil-alt"></i>
                                                    </a>
                                                    <a href="client-notes.php?id=<?php echo $client['user_id']; ?>" class="text-yellow-600 hover:text-yellow-900">
                                                        <i class="fas fa-sticky-note"></i>
                                                    </a>
                                                    <button type="button" class="text-gray-600 hover:text-gray-900 email-client" data-email="<?php echo htmlspecialchars($client['email']); ?>">
                                                        <i class="fas fa-envelope"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No clients found matching your criteria.
                                            <?php if (!empty($search) || !empty($status_filter)): ?>
                                                <a href="clients.php" class="text-indigo-600 hover:text-indigo-900">Clear filters</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Recent Activity Section -->
                <div class="mt-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Recent Client Activity</h3>
                    <div class="bg-white shadow rounded-lg overflow-hidden">
                        <ul class="divide-y divide-gray-200">
                            <?php if ($activity_result->num_rows > 0): ?>
                                <?php while ($activity = $activity_result->fetch_assoc()): ?>
                                    <li class="p-4 hover:bg-gray-50">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-3">
                                                <div class="flex-shrink-0">
                                                    <?php if ($activity['activity_type'] === 'viewing'): ?>
                                                        <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                                            <i class="fas fa-eye"></i>
                                                        </div>
                                                    <?php elseif ($activity['activity_type'] === 'inquiry'): ?>
                                                        <div class="h-10 w-10 rounded-full bg-purple-100 flex items-center justify-center text-purple-600">
                                                            <i class="fas fa-question"></i>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="h-10 w-10 rounded-full bg-green-100 flex items-center justify-center text-green-600">
                                                            <i class="fas fa-file-contract"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($activity['client_name']); ?>
                                                        
                                                        <?php if ($activity['activity_type'] === 'viewing'): ?>
                                                            scheduled a viewing for 
                                                        <?php elseif ($activity['activity_type'] === 'inquiry'): ?>
                                                            made an inquiry about 
                                                        <?php else: ?>
                                                            has a transaction for 
                                                        <?php endif; ?>
                                                        
                                                        <a href="../property.php?id=<?php echo $activity['property_id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                                            <?php echo htmlspecialchars($activity['property_title']); ?>
                                                        </a>
                                                    </div>
                                                    <div class="text-sm text-gray-500 flex items-center space-x-2">
                                                        <span><?php echo date('M j, Y \a\t g:i a', strtotime($activity['activity_date'])); ?></span>
                                                        <span class="text-gray-300">|</span>
                                                        <span class="capitalize">Status: <?php echo str_replace('_', ' ', $activity['activity_status']); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div>
                                                <?php if ($activity['activity_type'] === 'viewing'): ?>
                                                    <a href="viewing-detail.php?id=<?php echo $activity['property_id']; ?>&date=<?php echo urlencode($activity['activity_date']); ?>&client=<?php echo $activity['user_id']; ?>" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                                                        View Details
                                                    </a>
                                                <?php elseif ($activity['activity_type'] === 'inquiry'): ?>
                                                    <a href="inquiry-detail.php?property=<?php echo $activity['property_id']; ?>&client=<?php echo $activity['user_id']; ?>" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                                                        View Inquiry
                                                    </a>
                                                <?php else: ?>
                                                    <a href="transaction-detail.php?property=<?php echo $activity['property_id']; ?>&client=<?php echo $activity['user_id']; ?>" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                                                        View Transaction
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <li class="p-4 text-center text-sm text-gray-500">
                                    No recent client activity found.
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Email Client Modal (Hidden by default) -->
    <div class="fixed z-10 inset-0 overflow-y-auto hidden" id="email-modal">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-100 sm:mx-0 sm:h-10 sm:w-10">
                            <i class="fas fa-envelope text-indigo-600"></i>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                Send Email to Client
                            </h3>
                            <div class="mt-2">
                                <form id="email-form">
                                    <div class="mb-4">
                                        <label for="email-to" class="block text-sm font-medium text-gray-700">To</label>
                                        <input type="email" name="email-to" id="email-to" class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" readonly>
                                    </div>
                                    <div class="mb-4">
                                        <label for="email-subject" class="block text-sm font-medium text-gray-700">Subject</label>
                                        <input type="text" name="email-subject" id="email-subject" class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label for="email-message" class="block text-sm font-medium text-gray-700">Message</label>
                                        <textarea name="email-message" id="email-message" rows="4" class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" required></textarea>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm" id="send-email-btn">
                        Send Email
                    </button>
                    <button type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm close-modal">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Mobile menu functionality
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const closeMobileMenu = document.getElementById('close-mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.remove('hidden');
        });
        
        closeMobileMenu.addEventListener('click', () => {
            mobileMenu.classList.add('hidden');
        });
        
        mobileMenuOverlay.addEventListener('click', () => {
            mobileMenu.classList.add('hidden');
        });
        
        // User dropdown menu functionality
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
        
        // Email client modal functionality
        const emailClientButtons = document.querySelectorAll('.email-client');
        const emailModal = document.getElementById('email-modal');
        const closeModalButtons = document.querySelectorAll('.close-modal');
        const emailToInput = document.getElementById('email-to');
        const sendEmailBtn = document.getElementById('send-email-btn');
        const emailForm = document.getElementById('email-form');
        
        emailClientButtons.forEach(button => {
            button.addEventListener('click', () => {
                const clientEmail = button.getAttribute('data-email');
                emailToInput.value = clientEmail;
                emailModal.classList.remove('hidden');
            });
        });
        
        closeModalButtons.forEach(button => {
            button.addEventListener('click', () => {
                emailModal.classList.add('hidden');
                emailForm.reset();
            });
        });
        
        // Close modal when clicking outside
        emailModal.addEventListener('click', (event) => {
            if (event.target === emailModal) {
                emailModal.classList.add('hidden');
                emailForm.reset();
            }
        });
        
        // Handle email send (simulate sending)
        sendEmailBtn.addEventListener('click', () => {
            const subject = document.getElementById('email-subject').value;
            const message = document.getElementById('email-message').value;
            
            if (!subject || !message) {
                alert('Please fill in all fields.');
                return;
            }
            
            // In a real application, you would send this to the server
            // Here we're just simulating a successful send
            sendEmailBtn.disabled = true;
            sendEmailBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Sending...';
            
            setTimeout(() => {
                alert('Email sent successfully!');
                emailModal.classList.add('hidden');
                emailForm.reset();
                sendEmailBtn.disabled = false;
                sendEmailBtn.innerHTML = 'Send Email';
            }, 1500);
        });
    </script>
</body>
</html>