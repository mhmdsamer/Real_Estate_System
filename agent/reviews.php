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

// Handle new response submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'respond') {
    $review_id = $_POST['review_id'];
    $response = $_POST['response'];
    
    // Update the database with the response
    // Note: We would need to add a 'response' column to the reviews table
    $response_query = $conn->prepare("UPDATE reviews SET agent_response = ?, response_date = NOW() WHERE review_id = ? AND agent_id = ?");
    $response_query->bind_param("sii", $response, $review_id, $agent_id);
    $response_query->execute();
    
    // Redirect to prevent form resubmission
    header("Location: reviews.php?responded=true");
    exit();
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

// Calculate overall rating statistics
$ratings_query = $conn->prepare("
    SELECT 
        AVG(rating) as average_rating, 
        COUNT(*) as total_reviews,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
        SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
        SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
        SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
        SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
    FROM reviews
    WHERE agent_id = ? AND is_approved = 1
");
$ratings_query->bind_param("i", $agent_id);
$ratings_query->execute();
$ratings_result = $ratings_query->get_result();
$ratings = $ratings_result->fetch_assoc();

$average_rating = number_format($ratings['average_rating'] ?? 0, 1);
$total_reviews = $ratings['total_reviews'] ?? 0;

// Calculate percentages for the rating bars
$rating_percentages = [];
if ($total_reviews > 0) {
    $rating_percentages = [
        5 => ($ratings['five_star'] / $total_reviews) * 100,
        4 => ($ratings['four_star'] / $total_reviews) * 100,
        3 => ($ratings['three_star'] / $total_reviews) * 100,
        2 => ($ratings['two_star'] / $total_reviews) * 100,
        1 => ($ratings['one_star'] / $total_reviews) * 100
    ];
}

// Get all reviews with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // Reviews per page
$offset = ($page - 1) * $limit;

// Filter options
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// Build the WHERE clause based on filter
$where_clause = "WHERE r.agent_id = ?";
$params = [$agent_id];
$types = "i";

if ($filter === 'unanswered') {
    $where_clause .= " AND (r.agent_response IS NULL OR r.agent_response = '')";
} elseif ($filter === 'low_rating') {
    $where_clause .= " AND r.rating <= 3";
} elseif ($filter === 'high_rating') {
    $where_clause .= " AND r.rating >= 4";
} elseif ($filter === 'pending') {
    $where_clause .= " AND r.is_approved = 0";
} elseif ($filter === 'approved') {
    $where_clause .= " AND r.is_approved = 1";
}

// Build the ORDER BY clause based on sort
$order_clause = "ORDER BY ";
if ($sort === 'oldest') {
    $order_clause .= "r.created_at ASC";
} elseif ($sort === 'highest') {
    $order_clause .= "r.rating DESC, r.created_at DESC";
} elseif ($sort === 'lowest') {
    $order_clause .= "r.rating ASC, r.created_at DESC";
} else { // newest (default)
    $order_clause .= "r.created_at DESC";
}

// Count total reviews for pagination
$count_query = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM reviews r 
    $where_clause
");
$count_query->bind_param($types, ...$params);
$count_query->execute();
$count_result = $count_query->get_result();
$total_filtered_reviews = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_filtered_reviews / $limit);

// Get reviews with user information and property details if available
$reviews_query = $conn->prepare("
    SELECT r.*, 
           u.first_name, u.last_name, u.profile_image,
           p.title as property_title, p.address as property_address,
           p.property_id
    FROM reviews r
    JOIN users u ON r.reviewer_id = u.user_id
    LEFT JOIN properties p ON r.property_id = p.property_id
    $where_clause
    $order_clause
    LIMIT ? OFFSET ?
");

// Add limit and offset to params
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$reviews_query->bind_param($types, ...$params);
$reviews_query->execute();
$reviews_result = $reviews_query->get_result();

// Format name for display
$agent_name = $user['first_name'] . ' ' . $user['last_name'];
$profile_image = $user['profile_image'] ?? 'https://via.placeholder.com/150';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews - Agent Dashboard - PrimeEstate</title>
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
        
        /* Rating stars */
        .star-rating {
            color: #e5e7eb; /* Gray for empty stars */
        }
        
        .star-rating .filled {
            color: #f59e0b; /* Amber for filled stars */
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

        /* Rating bars */
        .rating-bar {
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .rating-bar-fill {
            height: 100%;
            background-color: #f59e0b;
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
                            <a href="clients.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-users w-5 h-5 mr-3 text-gray-500"></i>
                                Clients
                            </a>
                            <a href="reviews.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Reviews & Ratings</h1>
                
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
                            <a href="clients.php" class="sidebar-link flex items-center px-4 py-3 text-sm font-medium text-gray-600 rounded-md">
                                <i class="fas fa-users w-5 h-5 mr-3 text-gray-500"></i>
                                Clients
                            </a>
                            <a href="reviews.php" class="sidebar-link active flex items-center px-4 py-3 text-sm font-medium rounded-md">
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
                <!-- Success message after responding to a review -->
                <?php if (isset($_GET['responded']) && $_GET['responded'] === 'true'): ?>
                <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 rounded" id="success-alert">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-500"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-700">Your response has been submitted successfully.</p>
                        </div>
                        <div class="ml-auto pl-3">
                            <div class="-mx-1.5 -my-1.5">
                                <button type="button" class="text-green-500 hover:text-green-600 rounded-md focus:outline-none" onclick="document.getElementById('success-alert').remove()">
                                    <span class="sr-only">Dismiss</span>
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Rating Overview and Statistics Card -->
                <div class="card p-6 mb-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">My Rating Overview</h2>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- Overall Rating -->
                        <div class="flex flex-col items-center">
                            <div class="flex items-center space-x-2 mb-2">
                                <span class="text-4xl font-bold text-gray-900"><?php echo $average_rating; ?></span>
                                <div class="flex">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= round($average_rating)): ?>
                                            <i class="fas fa-star text-yellow-400"></i>
                                        <?php else: ?>
                                            <i class="far fa-star text-yellow-400"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <p class="text-gray-500 mb-4">Based on <?php echo $total_reviews; ?> reviews</p>
                            
                            <!-- Rating distribution -->
                            <div class="w-full space-y-2">
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                <div class="flex items-center">
                                    <div class="w-16 flex items-center">
                                        <span class="text-sm mr-2"><?php echo $i; ?></span>
                                        <i class="fas fa-star text-yellow-400 text-sm"></i>
                                    </div>
                                    <div class="flex-1 mx-2">
                                        <div class="rating-bar">
                                            <div class="rating-bar-fill" style="width: <?php echo $rating_percentages[$i] ?? 0; ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="w-16 text-right">
                                        <span class="text-sm text-gray-500"><?php echo $ratings[$i . '_star'] ?? 0; ?></span>
                                    </div>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                        
                        <!-- Rating stats -->
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <h3 class="text-gray-500 text-sm font-medium mb-2">Approved Reviews</h3>
                                <p class="text-3xl font-bold text-gray-800"><?php echo $total_reviews; ?></p>
                            </div>
                            
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <h3 class="text-gray-500 text-sm font-medium mb-2">Pending Reviews</h3>
                                <p class="text-3xl font-bold text-gray-800"><?php echo $total_filtered_reviews - $total_reviews; ?></p>
                            </div>
                            
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <h3 class="text-gray-500 text-sm font-medium mb-2">High Ratings (4-5)</h3>
                                <p class="text-3xl font-bold text-gray-800"><?php echo ($ratings['five_star'] ?? 0) + ($ratings['four_star'] ?? 0); ?></p>
                            </div>
                            
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <h3 class="text-gray-500 text-sm font-medium mb-2">Low Ratings (1-3)</h3>
                                <p class="text-3xl font-bold text-gray-800"><?php echo ($ratings['three_star'] ?? 0) + ($ratings['two_star'] ?? 0) + ($ratings['one_star'] ?? 0); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters and Reviews List -->
                <div class="card p-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4 md:mb-0">All Reviews</h2>
                        
                        <div class="flex flex-col md:flex-row space-y-2 md:space-y-0 md:space-x-2">
                            <!-- Filter dropdown -->
                            <div class="relative">
                                <select id="review-filter" onchange="window.location = this.value;" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                <option value="?filter=all<?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] : ''; ?>" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Reviews</option>
                                    <option value="?filter=unanswered<?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] : ''; ?>" <?php echo $filter === 'unanswered' ? 'selected' : ''; ?>>Unanswered</option>
                                    <option value="?filter=low_rating<?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] : ''; ?>" <?php echo $filter === 'low_rating' ? 'selected' : ''; ?>>Low Rating (1-3)</option>
                                    <option value="?filter=high_rating<?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] : ''; ?>" <?php echo $filter === 'high_rating' ? 'selected' : ''; ?>>High Rating (4-5)</option>
                                    <option value="?filter=pending<?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] : ''; ?>" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                                    <option value="?filter=approved<?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] : ''; ?>" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                </select>
                            </div>
                            
                            <!-- Sort dropdown -->
                            <div class="relative">
                                <select id="review-sort" onchange="window.location = this.value;" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                    <option value="?sort=newest<?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?>" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                                    <option value="?sort=oldest<?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?>" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                                    <option value="?sort=highest<?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?>" <?php echo $sort === 'highest' ? 'selected' : ''; ?>>Highest Rated</option>
                                    <option value="?sort=lowest<?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?>" <?php echo $sort === 'lowest' ? 'selected' : ''; ?>>Lowest Rated</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Reviews list -->
                    <div class="space-y-6">
                        <?php if ($reviews_result->num_rows > 0): ?>
                            <?php while($review = $reviews_result->fetch_assoc()): ?>
                                <div class="card p-5 border border-gray-100">
                                    <div class="flex flex-col md:flex-row md:items-start md:justify-between">
                                        <div class="flex items-start mb-4 md:mb-0">
                                            <div class="flex-shrink-0 mr-4">
                                                <img class="h-12 w-12 rounded-full object-cover" src="<?php echo htmlspecialchars($review['profile_image'] ?? 'https://via.placeholder.com/150'); ?>" alt="Reviewer">
                                            </div>
                                            <div>
                                                <h3 class="text-lg font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?>
                                                </h3>
                                                <div class="flex items-center mt-1">
                                                    <div class="flex items-center">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <?php if ($i <= $review['rating']): ?>
                                                                <i class="fas fa-star text-yellow-400"></i>
                                                            <?php else: ?>
                                                                <i class="far fa-star text-yellow-400"></i>
                                                            <?php endif; ?>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <span class="ml-2 text-sm text-gray-500">
                                                        <?php echo date('M j, Y', strtotime($review['created_at'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="flex flex-wrap gap-2">
                                            <?php if (!$review['is_approved']): ?>
                                                <span class="badge badge-warning">Pending</span>
                                            <?php endif; ?>
                                            
                                            <?php if (empty($review['agent_response'])): ?>
                                                <span class="badge badge-danger">Needs Response</span>
                                            <?php else: ?>
                                                <span class="badge badge-success">Responded</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($review['property_id']): ?>
                                                <a href="../property.php?id=<?php echo $review['property_id']; ?>" class="badge badge-primary">
                                                    <i class="fas fa-home mr-1"></i> Property
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($review['property_id']): ?>
                                        <div class="mt-3 text-sm text-gray-500">
                                            <i class="fas fa-home mr-1"></i>
                                            <a href="../property.php?id=<?php echo $review['property_id']; ?>" class="hover:underline">
                                                <?php echo htmlspecialchars($review['property_title'] . ' - ' . $review['property_address']); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-4">
                                        <h4 class="font-medium text-gray-900 mb-1"><?php echo htmlspecialchars($review['title'] ?? 'Review'); ?></h4>
                                        <p class="text-gray-600"><?php echo nl2br(htmlspecialchars($review['content'])); ?></p>
                                    </div>
                                    
                                    <?php if (!empty($review['agent_response'])): ?>
                                        <div class="mt-4 pt-4 border-t border-gray-100">
                                            <h5 class="text-sm font-medium text-gray-900 mb-1">Your Response</h5>
                                            <p class="text-sm text-gray-600"><?php echo nl2br(htmlspecialchars($review['agent_response'])); ?></p>
                                            <div class="mt-2 text-xs text-gray-500">
                                                <?php echo date('M j, Y', strtotime($review['response_date'])); ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-4 pt-4 border-t border-gray-100">
                                            <button type="button" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 focus:outline-none" onclick="toggleResponseForm(<?php echo $review['review_id']; ?>)">
                                                <i class="fas fa-reply mr-1"></i> Add Response
                                            </button>
                                            <div id="response-form-<?php echo $review['review_id']; ?>" class="hidden mt-3">
                                                <form action="reviews.php" method="POST">
                                                    <input type="hidden" name="action" value="respond">
                                                    <input type="hidden" name="review_id" value="<?php echo $review['review_id']; ?>">
                                                    
                                                    <div class="mt-2">
                                                        <textarea name="response" rows="3" class="block w-full shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm border-gray-300 rounded-md" placeholder="Write your response..." required></textarea>
                                                    </div>
                                                    <div class="mt-3 flex items-center justify-end">
                                                        <button type="button" class="mr-3 bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" onclick="toggleResponseForm(<?php echo $review['review_id']; ?>)">
                                                            Cancel
                                                        </button>
                                                        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                                            Submit Response
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <div class="text-gray-400 mb-2">
                                    <i class="fas fa-comment-slash text-4xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900">No reviews found</h3>
                                <p class="text-gray-500 mt-1">There are no reviews matching your current filter settings.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="mt-6">
                            <nav class="flex items-center justify-between">
                                <div class="flex-1 flex justify-between">
                                    <?php if ($page > 1): ?>
                                        <a href="?page=<?php echo $page - 1; ?><?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?><?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] : ''; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                            Previous
                                        </a>
                                    <?php else: ?>
                                        <button class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-white cursor-not-allowed" disabled>
                                            Previous
                                        </button>
                                    <?php endif; ?>
                                    
                                    <div class="hidden md:flex">
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <?php if ($i == $page): ?>
                                                <button class="relative inline-flex items-center px-4 py-2 border border-indigo-500 text-sm font-medium rounded-md text-indigo-600 bg-indigo-50" disabled>
                                                    <?php echo $i; ?>
                                                </button>
                                            <?php else: ?>
                                                <a href="?page=<?php echo $i; ?><?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?><?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] : ''; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 mx-1">
                                                    <?php echo $i; ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?page=<?php echo $page + 1; ?><?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?><?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] : ''; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                            Next
                                        </a>
                                    <?php else: ?>
                                        <button class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-white cursor-not-allowed" disabled>
                                            Next
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Toggle user dropdown menu
        document.getElementById('user-menu-button').addEventListener('click', function() {
            document.getElementById('user-menu-dropdown').classList.toggle('hidden');
        });
        
        // Close dropdown when clicking outside
        window.addEventListener('click', function(e) {
            if (!document.getElementById('user-menu-button').contains(e.target)) {
                document.getElementById('user-menu-dropdown').classList.add('hidden');
            }
        });
        
        // Mobile menu functionality
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.remove('hidden');
        });
        
        document.getElementById('close-mobile-menu').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.add('hidden');
        });
        
        document.getElementById('mobile-menu-overlay').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.add('hidden');
        });
        
        // Response form toggle
        function toggleResponseForm(reviewId) {
            const form = document.getElementById('response-form-' + reviewId);
            form.classList.toggle('hidden');
        }
        
        // Auto-hide success message after 5 seconds
        const successAlert = document.getElementById('success-alert');
        if (successAlert) {
            setTimeout(function() {
                successAlert.style.transition = 'opacity 1s ease';
                successAlert.style.opacity = '0';
                setTimeout(function() {
                    successAlert.remove();
                }, 1000);
            }, 5000);
        }
    </script>
</body>
</html>