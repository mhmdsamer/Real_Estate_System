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
$user_query = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user = $user_result->fetch_assoc();

// Format name for display
$agent_name = $user['first_name'] . ' ' . $user['last_name'];

// Pagination settings
$results_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $results_per_page;

// Filter settings
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : '';

// Base query
$query = "
    FROM transactions t
    JOIN properties p ON t.property_id = p.property_id
    LEFT JOIN users buyer ON t.buyer_id = buyer.user_id
    LEFT JOIN users seller ON t.seller_id = seller.user_id
    WHERE (t.listing_agent_id = ? OR t.buyer_agent_id = ?)
";

// Add filters to the query
$params = array($agent_id, $agent_id);
$types = "ii";

if ($status_filter) {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($type_filter) {
    $query .= " AND t.transaction_type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

if ($search) {
    $search_param = "%$search%";
    $query .= " AND (p.title LIKE ? OR p.address LIKE ? OR buyer.first_name LIKE ? OR buyer.last_name LIKE ? OR seller.first_name LIKE ? OR seller.last_name LIKE ?)";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= "ssssss";
}

if ($date_filter) {
    if ($date_filter == 'last_30_days') {
        $query .= " AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } elseif ($date_filter == 'last_6_months') {
        $query .= " AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
    } elseif ($date_filter == 'current_year') {
        $query .= " AND YEAR(t.updated_at) = YEAR(CURDATE())";
    }
}

// Count total results for pagination
$count_query = "SELECT COUNT(*) as total " . $query;
$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_results = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_results / $results_per_page);

// Get transactions with pagination
$transactions_query = "
    SELECT 
        t.*, 
        p.title, 
        p.address, 
        p.city,
        p.state,
        CONCAT(buyer.first_name, ' ', buyer.last_name) as buyer_name,
        CONCAT(seller.first_name, ' ', seller.last_name) as seller_name,
        listing_agent.license_number as listing_agent_license,
        buyer_agent.license_number as buyer_agent_license,
        CASE 
            WHEN t.listing_agent_id = ? THEN 'Listing Agent'
            WHEN t.buyer_agent_id = ? THEN 'Buyer\'s Agent'
            ELSE 'Unknown'
        END as agent_role
    " . $query . "
    ORDER BY t.updated_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $agent_id;
$params[] = $agent_id;
$params[] = $results_per_page;
$params[] = $offset;
$types .= "iiii";

$transactions_stmt = $conn->prepare($transactions_query);
$transactions_stmt->bind_param($types, ...$params);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_transactions,
        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_transactions,
        SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_transactions,
        SUM(CASE WHEN t.transaction_type = 'sale' THEN 1 ELSE 0 END) as sales,
        SUM(CASE WHEN t.transaction_type = 'rental' THEN 1 ELSE 0 END) as rentals,
        AVG(t.sale_price) as avg_price
    FROM transactions t
    WHERE (t.listing_agent_id = ? OR t.buyer_agent_id = ?)
";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("ii", $agent_id, $agent_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

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

// Helper function to format price
function formatPrice($price) {
    return number_format($price, 0);
}

// Helper function to get status badge classes
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'completed':
            return 'badge-success';
        case 'pending':
            return 'badge-warning';
        case 'in_progress':
            return 'badge-primary';
        case 'canceled':
            return 'badge-danger';
        default:
            return 'badge-secondary';
    }
}

// Helper function to get transaction type badge classes
function getTypeBadgeClass($type) {
    switch ($type) {
        case 'sale':
            return 'badge-success';
        case 'rental':
            return 'badge-primary';
        default:
            return 'badge-secondary';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - PrimeEstate</title>
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
        
        /* Custom table styles */
        .table-container {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .data-table th {
            background-color: #f8fafc;
            font-weight: 600;
            text-align: left;
            padding: 12px;
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .data-table tr {
            transition: all 0.2s ease;
        }
        
        .data-table tbody tr:hover {
            background-color: #f1f5f9;
        }
        
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
            font-size: 0.875rem;
        }
        
        /* Pagination styles */
        .pagination {
            display: flex;
            justify-content: center;
        }
        
        .pagination a {
            padding: 8px 16px;
            margin: 0 3px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .pagination a:hover {
            background-color: #f1f5f9;
        }
        
        .pagination .active {
            background-color: #4338ca;
            color: white;
        }
        
        .pagination .disabled {
            color: #cbd5e1;
            pointer-events: none;
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
                            <a href="transactions.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Transactions</h1>
                
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
                            <a href="transactions.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <!-- Transaction Summary Section -->
                <div class="mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Total Transactions -->
                        <div class="stat-card p-5">
                            <div class="flex justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Total Transactions</p>
                                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats['total_transactions']; ?></p>
                                </div>
                                <div class="h-12 w-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-exchange-alt text-indigo-600 text-xl"></i>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Sales vs Rentals -->
                        <div class="stat-card p-5" style="border-left-color: var(--success);">
                            <div class="flex justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Sales / Rentals</p>
                                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats['sales']; ?> / <?php echo $stats['rentals']; ?></p>
                                </div>
                                <div class="h-12 w-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-chart-pie text-green-600 text-xl"></i>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Completed Transactions -->
                        <div class="stat-card p-5" style="border-left-color: var(--secondary);">
                            <div class="flex justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Completed</p>
                                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats['completed_transactions']; ?></p>
                                </div>
                                <div class="h-12 w-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check-circle text-blue-600 text-xl"></i>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Average Sale Price -->
                        <div class="stat-card p-5" style="border-left-color: var(--accent);">
                            <div class="flex justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-500">Average Price</p>
                                    <p class="text-2xl font-bold text-gray-900">$<?php echo formatPrice($stats['avg_price'] ?? 0); ?></p>
                                </div>
                                <div class="h-12 w-12 bg-amber-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-dollar-sign text-amber-600 text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filter and Search Section -->
                <div class="card mb-6 p-5">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                        <!-- Search Input -->
                        <div class="col-span-1 md:col-span-2 lg:col-span-2">
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                            <div class="relative rounded-md shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" class="block w-full pl-10 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="Search property, client