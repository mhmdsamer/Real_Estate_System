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

// Get agent's active listings count
$listings_query = $conn->prepare("
    SELECT COUNT(*) as active_listings 
    FROM property_listings pl
    JOIN properties p ON pl.property_id = p.property_id
    WHERE pl.agent_id = ? AND p.status IN ('for_sale', 'for_rent')
");
$listings_query->bind_param("i", $agent_id);
$listings_query->execute();
$listings_result = $listings_query->get_result();
$listings_count = $listings_result->fetch_assoc()['active_listings'];

// Get pending inquiries count
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

// Get upcoming viewings
$viewings_query = $conn->prepare("
    SELECT pv.*, p.title, p.address, u.first_name, u.last_name, u.email, u.phone
    FROM property_viewings pv
    JOIN properties p ON pv.property_id = p.property_id
    LEFT JOIN users u ON pv.user_id = u.user_id
    WHERE pv.agent_id = ? AND pv.status = 'confirmed' AND pv.viewing_date >= NOW()
    ORDER BY pv.viewing_date ASC
    LIMIT 5
");
$viewings_query->bind_param("i", $agent_id);
$viewings_query->execute();
$viewings_result = $viewings_query->get_result();

// Get recent transactions
$transactions_query = $conn->prepare("
    SELECT t.*, p.title, p.address, 
           CONCAT(buyer.first_name, ' ', buyer.last_name) as buyer_name,
           CONCAT(seller.first_name, ' ', seller.last_name) as seller_name
    FROM transactions t
    JOIN properties p ON t.property_id = p.property_id
    LEFT JOIN users buyer ON t.buyer_id = buyer.user_id
    LEFT JOIN users seller ON t.seller_id = seller.user_id
    WHERE t.listing_agent_id = ? OR t.buyer_agent_id = ?
    ORDER BY t.updated_at DESC
    LIMIT 5
");
$transactions_query->bind_param("ii", $agent_id, $agent_id);
$transactions_query->execute();
$transactions_result = $transactions_query->get_result();

// Get ratings average
$ratings_query = $conn->prepare("
    SELECT AVG(rating) as average_rating, COUNT(*) as total_reviews
    FROM reviews
    WHERE agent_id = ? AND is_approved = 1
");
$ratings_query->bind_param("i", $agent_id);
$ratings_query->execute();
$ratings_result = $ratings_query->get_result();
$ratings = $ratings_result->fetch_assoc();
$average_rating = number_format($ratings['average_rating'] ?? 0, 1);
$total_reviews = $ratings['total_reviews'] ?? 0;

// Format name for display
$agent_name = $user['first_name'] . ' ' . $user['last_name'];
$profile_image = $user['profile_image'] ?? 'https://via.placeholder.com/150';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Dashboard - PrimeEstate</title>
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
                            <a href="dashboard.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Agent Dashboard</h1>
                
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
                            <a href="dashboard.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <!-- Welcome Section -->
                <div class="mb-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!</h2>
                            <p class="text-gray-600 mt-1">Here's what's happening with your properties today.</p>
                        </div>
                        <div class="mt-4 md:mt-0">
                            <a href="add-listing.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <i class="fas fa-plus mr-2"></i>
                                New Listing
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <!-- Active Listings -->
                    <div class="stat-card p-5">
                        <div class="flex justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Active Listings</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $listings_count; ?></p>
                            </div>
                            <div class="h-12 w-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-home text-indigo-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pending Inquiries -->
                    <div class="stat-card p-5" style="border-left-color: var(--warning);">
                        <div class="flex justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Pending Inquiries</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $pending_inquiries; ?></p>
                            </div>
                            <div class="h-12 w-12 bg-amber-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-question-circle text-amber-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Average Rating -->
                    <div class="stat-card p-5" style="border-left-color: var(--success);">
                        <div class="flex justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Average Rating</p>
                                <div class="flex items-center">
                                    <p class="text-2xl font-bold text-gray-900"><?php echo $average_rating; ?></p>
                                    <div class="ml-2 flex text-yellow-400">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <?php if ($i <= round($average_rating)): ?>
                                                <i class="fas fa-star text-xs"></i>
                                            <?php else: ?>
                                                <i class="far fa-star text-xs"></i>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1"><?php echo $total_reviews; ?> reviews</p>
                            </div>
                            <div class="h-12 w-12 bg-green-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-star text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <!-- License Number -->
                    <div class="stat-card p-5" style="border-left-color: var(--secondary);">
                        <div class="flex justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">License Number</p>
                                <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($agent['license_number']); ?></p>
                                <p class="text-xs text-gray-500 mt-1"><?php echo $agent['experience_years']; ?> years experience</p>
                            </div>
                            <div class="h-12 w-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-id-card text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Content Sections -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Upcoming Viewings Section -->
                    <div class="card p-5">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Upcoming Viewings</h3>
                            <a href="viewings.php" class="text-sm text-indigo-600 hover:text-indigo-500">View all</a>
                        </div>
                        
                        <?php if ($viewings_result->num_rows > 0): ?>
                            <div class="space-y-4">
                                <?php while ($viewing = $viewings_result->fetch_assoc()): ?>
                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div class="mb-2 sm:mb-0">
                                            <h4 class="font-medium text-gray-800 text-sm"><?php echo htmlspecialchars($viewing['title']); ?></h4>
                                            <p class="text-gray-500 text-xs"><?php echo htmlspecialchars($viewing['address']); ?></p>
                                            <div class="flex items-center mt-1">
                                                <i class="far fa-clock text-gray-400 mr-1"></i>
                                                <span class="text-xs"><?php echo date('M j, Y - g:i A', strtotime($viewing['viewing_date'])); ?></span>
                                            </div>
                                        </div>
                                        <div class="mt-2 sm:mt-0">
                                            <div class="flex flex-col">
                                                <span class="text-sm font-medium"><?php echo htmlspecialchars($viewing['first_name'] . ' ' . $viewing['last_name']); ?></span>
                                                <a href="mailto:<?php echo htmlspecialchars($viewing['email']); ?>" class="text-xs text-indigo-600 hover:underline"><?php echo htmlspecialchars($viewing['email']); ?></a>
                                                <?php if ($viewing['phone']): ?>
                                                    <a href="tel:<?php echo htmlspecialchars($viewing['phone']); ?>" class="text-xs text-gray-500"><?php echo htmlspecialchars($viewing['phone']); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-calendar-times text-gray-300 text-3xl mb-2"></i>
                                <p>No upcoming viewings scheduled</p>
                                <a href="viewings.php" class="inline-block mt-2 text-sm text-indigo-600 hover:text-indigo-500">Manage viewings</a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Recent Transactions Section -->
                    <div class="card p-5">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Recent Transactions</h3>
                            <a href="transactions.php" class="text-sm text-indigo-600 hover:text-indigo-500">View all</a>
                        </div>
                        
                        <?php if ($transactions_result->num_rows > 0): ?>
                            <div class="space-y-4">
                                <?php while ($transaction = $transactions_result->fetch_assoc()): ?>
                                    <div class="flex flex-col p-4 bg-gray-50 rounded-lg">
                                        <div class="flex justify-between">
                                            <div>
                                                <h4 class="font-medium text-gray-800 text-sm"><?php echo htmlspecialchars($transaction['title']); ?></h4>
                                                <p class="text-gray-500 text-xs"><?php echo htmlspecialchars($transaction['address']); ?></p>
                                                <div class="flex items-center mt-1">
                                                    <?php if ($transaction['transaction_type'] == 'sale'): ?>
                                                        <span class="badge badge-success mr-2">Sale</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-primary mr-2">Rental</span>
                                                    <?php endif; ?>
                                                    <span class="text-xs"><?php echo date('M j, Y', strtotime($transaction['closing_date'] ?? $transaction['updated_at'])); ?></span>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <span class="font-bold text-gray-900">$<?php echo number_format($transaction['sale_price'], 0); ?></span>
                                                <div class="mt-1">
                                                    <span class="badge <?php echo ($transaction['status'] == 'completed') ? 'badge-success' : 'badge-warning'; ?>">
                                                        <?php echo ucfirst($transaction['status']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-3 flex justify-between text-xs text-gray-500">
                                            <div>
                                                <div><span class="font-medium">Buyer:</span> <?php echo htmlspecialchars($transaction['buyer_name'] ?? 'N/A'); ?></div>
                                                <div><span class="font-medium">Seller:</span> <?php echo htmlspecialchars($transaction['seller_name'] ?? 'N/A'); ?></div>
                                            </div>
                                            <a href="transaction-details.php?id=<?php echo $transaction['transaction_id']; ?>" class="text-indigo-600 hover:text-indigo-500 font-medium">View Details</a>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-exchange-alt text-gray-300 text-3xl mb-2"></i>
                                <p>No recent transactions</p>
                                <a href="transactions.php" class="inline-block mt-2 text-sm text-indigo-600 hover:text-indigo-500">View all transactions</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Latest Activity Section -->
                <div class="mt-6">
                    <div class="card p-5">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h3>
                        
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                            <a href="add-listing.php" class="flex flex-col items-center justify-center p-4 bg-gray-50 rounded-lg hover:bg-indigo-50 transition">
                                <div class="h-10 w-10 bg-indigo-100 rounded-full flex items-center justify-center mb-2">
                                    <i class="fas fa-plus text-indigo-600"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-800">Add Listing</span>
                            </a>
                            
                            <a href="inquiries.php" class="flex flex-col items-center justify-center p-4 bg-gray-50 rounded-lg hover:bg-amber-50 transition">
                                <div class="h-10 w-10 bg-amber-100 rounded-full flex items-center justify-center mb-2">
                                    <i class="fas fa-question text-amber-600"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-800">View Inquiries</span>
                            </a>
                            
                            <a href="schedule-viewing.php" class="flex flex-col items-center justify-center p-4 bg-gray-50 rounded-lg hover:bg-blue-50 transition">
                                <div class="h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center mb-2">
                                    <i class="fas fa-calendar-plus text-blue-600"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-800">Schedule Viewing</span>
                            </a>
                            
                            <a href="add-client.php" class="flex flex-col items-center justify-center p-4 bg-gray-50 rounded-lg hover:bg-green-50 transition">
                                <div class="h-10 w-10 bg-green-100 rounded-full flex items-center justify-center mb-2">
                                    <i class="fas fa-user-plus text-green-600"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-800">Add Client</span>
                            </a>
                            
                            <a href="add-transaction.php" class="flex flex-col items-center justify-center p-4 bg-gray-50 rounded-lg hover:bg-purple-50 transition">
                                <div class="h-10 w-10 bg-purple-100 rounded-full flex items-center justify-center mb-2">
                                    <i class="fas fa-file-contract text-purple-600"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-800">New Transaction</span>
                            </a>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Handle dropdown menu toggle
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
        
        // Mobile menu handlers
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const closeMobileMenu = document.getElementById('close-mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.remove('hidden');
        });
        
        const closeMenu = () => {
            mobileMenu.classList.add('hidden');
        };
        
        closeMobileMenu.addEventListener('click', closeMenu);
        mobileMenuOverlay.addEventListener('click', closeMenu);
    </script>
</body>
</html>