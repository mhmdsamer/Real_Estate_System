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

// Handle listing actions
if (isset($_GET['action']) && isset($_GET['listing_id'])) {
    $listing_id = $_GET['listing_id'];
    $action = $_GET['action'];
    
    // Verify ownership of the listing
    $check_query = $conn->prepare("
        SELECT pl.* FROM property_listings pl
        WHERE pl.listing_id = ? AND pl.agent_id = ?
    ");
    $check_query->bind_param("ii", $listing_id, $agent_id);
    $check_query->execute();
    $check_result = $check_query->get_result();
    
    if ($check_result->num_rows > 0) {
        if ($action === 'delete') {
            // Instead of actually deleting, you might want to mark as inactive
            $delete_query = $conn->prepare("DELETE FROM property_listings WHERE listing_id = ?");
            $delete_query->bind_param("i", $listing_id);
            if ($delete_query->execute()) {
                $success_message = "Listing removed successfully!";
            } else {
                $error_message = "Error removing listing. Please try again.";
            }
        } elseif ($action === 'mark_sold') {
            // Mark property as sold
            $sold_query = $conn->prepare("
                UPDATE properties p
                JOIN property_listings pl ON p.property_id = pl.property_id
                SET p.status = 'sold'
                WHERE pl.listing_id = ?
            ");
            $sold_query->bind_param("i", $listing_id);
            if ($sold_query->execute()) {
                $success_message = "Property marked as sold!";
            } else {
                $error_message = "Error updating property status. Please try again.";
            }
        } elseif ($action === 'mark_rented') {
            // Mark property as rented
            $rented_query = $conn->prepare("
                UPDATE properties p
                JOIN property_listings pl ON p.property_id = pl.property_id
                SET p.status = 'rented'
                WHERE pl.listing_id = ?
            ");
            $rented_query->bind_param("i", $listing_id);
            if ($rented_query->execute()) {
                $success_message = "Property marked as rented!";
            } else {
                $error_message = "Error updating property status. Please try again.";
            }
        }
    } else {
        $error_message = "You don't have permission to perform this action.";
    }
}

// Get pending inquiries count (for notification badge)
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

// Pagination setup
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filter setup
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$property_type_filter = isset($_GET['property_type']) ? $_GET['property_type'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Building the WHERE clause for filters
$where_clauses = ["pl.agent_id = ?"];
$params = [$agent_id];
$types = "i";

if ($status_filter !== 'all') {
    $where_clauses[] = "p.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($property_type_filter !== 'all') {
    $where_clauses[] = "p.property_type = ?";
    $params[] = $property_type_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $search_term = "%$search_term%";
    $where_clauses[] = "(p.title LIKE ? OR p.address LIKE ? OR p.city LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

$where_clause = implode(" AND ", $where_clauses);

// Get total listings count for pagination
$count_query = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM property_listings pl
    JOIN properties p ON pl.property_id = p.property_id
    WHERE $where_clause
");
$count_query->bind_param($types, ...$params);
$count_query->execute();
$count_result = $count_query->get_result();
$total_listings = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_listings / $limit);

// Get listings
$listings_query = $conn->prepare("
    SELECT p.*, pl.*, 
           (SELECT COUNT(*) FROM inquiries i WHERE i.property_id = p.property_id AND i.status = 'new') as new_inquiries,
           (SELECT COUNT(*) FROM property_viewings pv WHERE pv.property_id = p.property_id AND pv.status IN ('requested', 'confirmed')) as pending_viewings,
           (SELECT image_url FROM property_images pi WHERE pi.property_id = p.property_id AND pi.is_primary = 1 LIMIT 1) as primary_image
    FROM property_listings pl
    JOIN properties p ON pl.property_id = p.property_id
    WHERE $where_clause
    ORDER BY pl.list_date DESC
    LIMIT ?, ?
");
$listings_query->bind_param($types . "ii", ...[...$params, $offset, $limit]);
$listings_query->execute();
$listings_result = $listings_query->get_result();

// Format agent name
$agent_name = $user['first_name'] . ' ' . $user['last_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Listings - PrimeEstate</title>
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
        
        /* Property status colors */
        .status-for_sale {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-for_rent {
            background-color: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }
        
        .status-sold {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .status-rented {
            background-color: rgba(14, 165, 233, 0.1);
            color: var(--secondary);
        }
        
        .status-pending {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--warning);
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
                            <a href="listings.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">My Listings</h1>
                
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
                            <a href="listings.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <?php if (isset($success_message)): ?>
                <div class="mb-4 p-4 bg-green-100 border border-green-200 text-green-700 rounded-md flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo $success_message; ?>
                </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                <div class="mb-4 p-4 bg-red-100 border border-red-200 text-red-700 rounded-md flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo $error_message; ?>
                </div>
                <?php endif; ?>
                
                <!-- Page Header -->
                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Property Listings</h2>
                        <p class="text-gray-600 mt-1">Manage all your property listings</p>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <a href="add-listing.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-plus mr-2"></i>
                            Add New Listing
                        </a>
                    </div>
                </div>
                
                <!-- Filter & Search Section -->
                <div class="card p-4 md:p-6 mb-6">
                    <form action="listings.php" method="GET" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select id="status" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="for_sale" <?php echo $status_filter === 'for_sale' ? 'selected' : ''; ?>>For Sale</option>
                                    <option value="for_rent" <?php echo $status_filter === 'for_rent' ? 'selected' : ''; ?>>For Rent</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="sold" <?php echo $status_filter === 'sold' ? 'selected' : ''; ?>>Sold</option>
                                    <option value="rented" <?php echo $status_filter === 'rented' ? 'selected' : ''; ?>>Rented</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="property_type" class="block text-sm font-medium text-gray-700 mb-1">Property Type</label>
                                <select id="property_type" name="property_type" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="all" <?php echo $property_type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="apartment" <?php echo $property_type_filter === 'apartment' ? 'selected' : ''; ?>>Apartment</option>
                                    <option value="house" <?php echo $property_type_filter === 'house' ? 'selected' : ''; ?>>House</option>
                                    <option value="condo" <?php echo $property_type_filter === 'condo' ? 'selected' : ''; ?>>Condo</option>
                                    <option value="townhouse" <?php echo $property_type_filter === 'townhouse' ? 'selected' : ''; ?>>Townhouse</option>
                                    <option value="land" <?php echo $property_type_filter === 'land' ? 'selected' : ''; ?>>Land</option>
                                    <option value="commercial" <?php echo $property_type_filter === 'commercial' ? 'selected' : ''; ?>>Commercial</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-search text-gray-400"></i>
                                    </div>
                                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Search by title, address, city..." class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end">
                            <a href="listings.php" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 mr-2">
                                Clear Filters
                            </a>
                            <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Apply Filters
                            </button></div>
                    </form>
                </div>
                
                <!-- Listings Grid -->
                <?php if ($listings_result->num_rows > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php while ($listing = $listings_result->fetch_assoc()): ?>
                    <div class="card overflow-hidden">
                        <!-- Property Image -->
                        <div class="relative h-48 bg-gray-200">
                            <?php if (!empty($listing['primary_image'])): ?>
                            <img src="<?php echo htmlspecialchars('../' . $listing['primary_image']); ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center bg-gray-200 text-gray-400">
                                <i class="fas fa-home text-4xl"></i>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Property Status Badge -->
                            <div class="absolute top-2 left-2">
                                <span class="badge status-<?php echo $listing['status']; ?> px-2 py-1">
                                    <?php 
                                    switch($listing['status']) {
                                        case 'for_sale':
                                            echo 'For Sale';
                                            break;
                                        case 'for_rent':
                                            echo 'For Rent';
                                            break;
                                        case 'pending':
                                            echo 'Pending';
                                            break;
                                        case 'sold':
                                            echo 'Sold';
                                            break;
                                        case 'rented':
                                            echo 'Rented';
                                            break;
                                        default:
                                            echo ucfirst($listing['status']);
                                    }
                                    ?>
                                </span>
                            </div>
                            
                            <!-- Price Badge -->
                            <div class="absolute bottom-2 left-2 bg-white/90 backdrop-blur-sm px-2 py-1 rounded-md shadow-sm">
                                <span class="font-semibold">
                                    $<?php echo number_format($listing['price']); ?>
                                    <?php if ($listing['status'] === 'for_rent'): ?>
                                    <span class="text-xs font-normal text-gray-600">/month</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            
                            <!-- Feature badges -->
                            <div class="absolute bottom-2 right-2 flex space-x-1">
                                <?php if ($listing['bedrooms']): ?>
                                <div class="bg-white/90 backdrop-blur-sm px-2 py-1 rounded-md shadow-sm flex items-center space-x-1 text-xs">
                                    <i class="fas fa-bed text-gray-500"></i>
                                    <span><?php echo $listing['bedrooms']; ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($listing['bathrooms']): ?>
                                <div class="bg-white/90 backdrop-blur-sm px-2 py-1 rounded-md shadow-sm flex items-center space-x-1 text-xs">
                                    <i class="fas fa-bath text-gray-500"></i>
                                    <span><?php echo $listing['bathrooms']; ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($listing['area_sqft']): ?>
                                <div class="bg-white/90 backdrop-blur-sm px-2 py-1 rounded-md shadow-sm flex items-center space-x-1 text-xs">
                                    <i class="fas fa-ruler-combined text-gray-500"></i>
                                    <span><?php echo number_format($listing['area_sqft']); ?> sqft</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Property Details -->
                        <div class="p-4">
                            <h3 class="text-lg font-semibold text-gray-800 mb-1 truncate"><?php echo htmlspecialchars($listing['title']); ?></h3>
                            <p class="text-gray-600 text-sm mb-3 truncate"><?php echo htmlspecialchars($listing['address']); ?>, <?php echo htmlspecialchars($listing['city']); ?>, <?php echo htmlspecialchars($listing['state']); ?></p>
                            
                            <!-- Property Type & Listing Date -->
                            <div class="flex justify-between items-center mb-3">
                                <span class="text-xs font-medium badge badge-primary">
                                    <?php echo ucfirst(str_replace('_', ' ', $listing['property_type'])); ?>
                                </span>
                                <span class="text-xs text-gray-500">
                                    Listed: <?php echo date('M j, Y', strtotime($listing['list_date'])); ?>
                                </span>
                            </div>
                            
                            <!-- Notification Badges -->
                            <?php if ($listing['new_inquiries'] > 0 || $listing['pending_viewings'] > 0): ?>
                            <div class="flex gap-2 mt-2 mb-3">
                                <?php if ($listing['new_inquiries'] > 0): ?>
                                <div class="flex items-center text-xs badge badge-danger">
                                    <i class="fas fa-envelope mr-1"></i>
                                    <?php echo $listing['new_inquiries']; ?> new inquiries
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($listing['pending_viewings'] > 0): ?>
                                <div class="flex items-center text-xs badge badge-warning">
                                    <i class="fas fa-calendar mr-1"></i>
                                    <?php echo $listing['pending_viewings']; ?> viewings
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Action Buttons -->
                            <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-100">
                                <div class="flex gap-2">
                                    <a href="../property.php?id=<?php echo $listing['property_id']; ?>" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium" target="_blank">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit-listing.php?id=<?php echo $listing['listing_id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="#" onclick="return confirm('Are you sure you want to delete this listing?') ? window.location.href='listings.php?action=delete&listing_id=<?php echo $listing['listing_id']; ?>' : false" class="text-red-600 hover:text-red-800 text-sm font-medium">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </div>
                                
                                <!-- Status Change Dropdown -->
                                <?php if ($listing['status'] === 'for_sale' || $listing['status'] === 'for_rent' || $listing['status'] === 'pending'): ?>
                                <div class="relative" x-data="{ open: false }">
                                    <button @click="open = !open" type="button" class="text-sm text-gray-500 hover:text-gray-700 focus:outline-none">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div x-show="open" @click.away="open = false" class="absolute right-0 bottom-8 w-48 bg-white rounded-md shadow-lg py-1 z-10">
                                        <?php if ($listing['status'] === 'for_sale' || $listing['status'] === 'pending'): ?>
                                        <a href="listings.php?action=mark_sold&listing_id=<?php echo $listing['listing_id']; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                            Mark as Sold
                                        </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($listing['status'] === 'for_rent'): ?>
                                        <a href="listings.php?action=mark_rented&listing_id=<?php echo $listing['listing_id']; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                            Mark as Rented
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="flex justify-center mt-8">
                    <nav class="inline-flex rounded-md shadow">
                        <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&property_type=<?php echo $property_type_filter; ?>&search=<?php echo urlencode($search_term); ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <?php else: ?>
                        <span class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $start_page + 4);
                        if ($end_page - $start_page < 4 && $total_pages > 4) {
                            $start_page = max(1, $end_page - 4);
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                        <a href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&property_type=<?php echo $property_type_filter; ?>&search=<?php echo urlencode($search_term); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 <?php echo $i === $page ? 'bg-indigo-50 text-indigo-600 z-10' : 'bg-white text-gray-500 hover:bg-gray-50'; ?> text-sm font-medium">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&property_type=<?php echo $property_type_filter; ?>&search=<?php echo urlencode($search_term); ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <?php else: ?>
                        <span class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                            <i class="fas fa-chevron-right"></i>
                        </span>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <!-- No Listings Found -->
                <div class="bg-white rounded-lg shadow-sm p-6 text-center">
                    <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center text-gray-400 mb-4">
                        <i class="fas fa-home text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No listings found</h3>
                    <p class="text-gray-500 mb-4">
                        <?php if (!empty($search_term) || $status_filter !== 'all' || $property_type_filter !== 'all'): ?>
                            No properties match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            You haven't added any property listings yet.
                        <?php endif; ?>
                    </p>
                    <a href="add-listing.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-plus mr-2"></i>
                        Add New Listing
                    </a>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Alpine.js for dropdowns -->
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.10.2/dist/cdn.min.js" defer></script>
    
    <script>
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
        
        // Mobile menu toggle
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const closeMobileMenuButton = document.getElementById('close-mobile-menu');
        
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });
        
        closeMobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.add('hidden');
        });
        
        mobileMenuOverlay.addEventListener('click', () => {
            mobileMenu.classList.add('hidden');
        });
    </script>
</body>
</html>