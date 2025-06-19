<?php
require_once '../connection.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Initialize variables for filtering and pagination
$search = isset($_GET['search']) ? $_GET['search'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($current_page - 1) * $per_page;

// Handle approve/reject/delete actions
if (isset($_POST['action']) && isset($_POST['review_id'])) {
    $review_id = (int)$_POST['review_id'];
    $action = $_POST['action'];
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE reviews SET is_approved = 1 WHERE review_id = ?");
        $stmt->bind_param('i', $review_id);
        if ($stmt->execute()) {
            $success_message = "Review approved successfully.";
        } else {
            $error_message = "Error approving review: " . $conn->error;
        }
        $stmt->close();
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE reviews SET is_approved = 0 WHERE review_id = ?");
        $stmt->bind_param('i', $review_id);
        if ($stmt->execute()) {
            $success_message = "Review rejected successfully.";
        } else {
            $error_message = "Error rejecting review: " . $conn->error;
        }
        $stmt->close();
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM reviews WHERE review_id = ?");
        $stmt->bind_param('i', $review_id);
        if ($stmt->execute()) {
            $success_message = "Review deleted successfully.";
        } else {
            $error_message = "Error deleting review: " . $conn->error;
        }
        $stmt->close();
    }
}

// Build the base query
$query = "SELECT r.review_id, r.title, r.content, r.rating, r.is_approved, r.created_at,
                 u.user_id as reviewer_id, CONCAT(u.first_name, ' ', u.last_name) as reviewer_name,
                 p.property_id, p.title as property_title,
                 a.agent_id, CONCAT(au.first_name, ' ', au.last_name) as agent_name,
                 CASE WHEN p.property_id IS NOT NULL THEN 'property' ELSE 'agent' END as review_type
          FROM reviews r
          JOIN users u ON r.reviewer_id = u.user_id
          LEFT JOIN properties p ON r.property_id = p.property_id
          LEFT JOIN agents a ON r.agent_id = a.agent_id
          LEFT JOIN users au ON a.user_id = au.user_id";

$countQuery = "SELECT COUNT(*) as total FROM reviews r
               JOIN users u ON r.reviewer_id = u.user_id
               LEFT JOIN properties p ON r.property_id = p.property_id
               LEFT JOIN agents a ON r.agent_id = a.agent_id
               LEFT JOIN users au ON a.user_id = au.user_id";

$whereConditions = [];
$params = [];
$paramTypes = '';

// Add search condition if search is provided
if (!empty($search)) {
    $searchTerm = "%$search%";
    $whereConditions[] = "(r.title LIKE ? OR r.content LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? 
                          OR p.title LIKE ? OR au.first_name LIKE ? OR au.last_name LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $paramTypes .= 'sssssss';
}

// Add review type filter if provided
if (!empty($type_filter)) {
    if ($type_filter === 'property') {
        $whereConditions[] = "r.property_id IS NOT NULL";
    } elseif ($type_filter === 'agent') {
        $whereConditions[] = "r.agent_id IS NOT NULL";
    }
}

// Add status filter if provided
if (!empty($status_filter)) {
    if ($status_filter === 'approved') {
        $whereConditions[] = "r.is_approved = 1";
    } elseif ($status_filter === 'pending') {
        $whereConditions[] = "r.is_approved = 0";
    }
}

// Combine where conditions if any exist
if (!empty($whereConditions)) {
    $query .= " WHERE " . implode(' AND ', $whereConditions);
    $countQuery .= " WHERE " . implode(' AND ', $whereConditions);
}

// Add order by and limit
$query .= " ORDER BY r.created_at DESC LIMIT ?, ?";
$params[] = $offset;
$params[] = $per_page;
$paramTypes .= 'ii';

// Get total reviews count for pagination
$countStmt = $conn->prepare($countQuery);
if (!empty($params) && count($params) > 2) {
    // Remove the last two parameters (offset and limit) for the count query
    $countParams = array_slice($params, 0, -2);
    $countParamTypes = substr($paramTypes, 0, -2);
    
    if (!empty($countParams)) {
        $countStmt->bind_param($countParamTypes, ...$countParams);
    }
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();
$total_reviews = $countRow['total'];
$total_pages = ceil($total_reviews / $per_page);

// Get reviews for current page
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$reviews = [];
while ($row = $result->fetch_assoc()) {
    $reviews[] = $row;
}

// Get review statistics
$stats = [];

// Total reviews
$statsStmt = $conn->prepare("SELECT COUNT(*) as total FROM reviews");
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats['total'] = $statsResult->fetch_assoc()['total'];

// Approved reviews
$statsStmt = $conn->prepare("SELECT COUNT(*) as approved FROM reviews WHERE is_approved = 1");
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats['approved'] = $statsResult->fetch_assoc()['approved'];

// Pending reviews
$statsStmt = $conn->prepare("SELECT COUNT(*) as pending FROM reviews WHERE is_approved = 0");
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats['pending'] = $statsResult->fetch_assoc()['pending'];

// Property reviews
$statsStmt = $conn->prepare("SELECT COUNT(*) as property_reviews FROM reviews WHERE property_id IS NOT NULL");
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats['property'] = $statsResult->fetch_assoc()['property_reviews'];

// Agent reviews
$statsStmt = $conn->prepare("SELECT COUNT(*) as agent_reviews FROM reviews WHERE agent_id IS NOT NULL");
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats['agent'] = $statsResult->fetch_assoc()['agent_reviews'];

// Average rating
$statsStmt = $conn->prepare("SELECT AVG(rating) as avg_rating FROM reviews");
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats['avg_rating'] = round($statsResult->fetch_assoc()['avg_rating'], 1);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reviews - PrimeEstate</title>
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

        /* Stars styling */
        .stars {
            color: #f59e0b; /* amber color for stars */
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
                
                <a href="reviews.php" class="sidebar-link active flex items-center px-4 py-3 my-1">
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Manage Reviews</h1>
                
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
            <?php if(isset($success_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                    <p><?php echo $success_message; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if(isset($error_message)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Review Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Total Reviews Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-indigo-500 to-indigo-600 text-white fade-in-up">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Total Reviews</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $stats['total']; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-star text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Reviews by Status Cards -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-green-500 to-green-600 text-white fade-in-up delay-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Approved Reviews</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $stats['approved']; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-card p-6 bg-gradient-to-br from-amber-500 to-amber-600 text-white fade-in-up delay-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Pending Reviews</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $stats['pending']; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Average Rating Card -->
                <div class="dashboard-card p-6 fade-in-up delay-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Average Rating</p>
                            <div class="flex items-center mt-1">
                                <p class="text-3xl font-bold text-gray-800"><?php echo $stats['avg_rating']; ?></p>
                                <div class="ml-2 stars">
                                    <?php 
                                        $full_stars = floor($stats['avg_rating']);
                                        $half_star = $stats['avg_rating'] - $full_stars >= 0.5;
                                        $empty_stars = 5 - $full_stars - ($half_star ? 1 : 0);
                                        
                                        for ($i = 0; $i < $full_stars; $i++) {
                                            echo '<i class="fas fa-star"></i>';
                                        }
                                        
                                        if ($half_star) {
                                            echo '<i class="fas fa-star-half-alt"></i>';
                                        }
                                        
                                        for ($i = 0; $i < $empty_stars; $i++) {
                                            echo '<i class="far fa-star"></i>';
                                        }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Property Reviews Card -->
                <div class="dashboard-card p-6 fade-in-up delay-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Property Reviews</p>
                            <p class="text-3xl font-bold text-gray-800 mt-1"><?php echo $stats['property']; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-blue-100">
                            <i class="fas fa-building text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Agent Reviews Card -->
                <div class="dashboard-card p-6 fade-in-up delay-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Agent Reviews</p>
                            <p class="text-3xl font-bold text-gray-800 mt-1"><?php echo $stats['agent']; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-purple-100">
                            <i class="fas fa-user-tie text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Search and Filter Bar -->
            <div class="dashboard-card p-6 mb-6 fade-in-up delay-300">
                <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search Reviews</label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-10 pr-12 sm:text-sm border-gray-300 rounded-md"
                                   placeholder="Name, title, or content">
                        </div>
                    </div>
                    
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Review Type</label>
                        <select name="type" id="type" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="">All Types</option>
                            <option value="property" <?php echo $type_filter === 'property' ? 'selected' : ''; ?>>Property</option>
                            <option value="agent" <?php echo $type_filter === 'agent' ? 'selected' : ''; ?>>Agent</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" id="status" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="">All Status</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-indigo-600 border border-transparent rounded-md shadow-sm py-2 px-4 flex items-center justify-center text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-filter mr-2"></i> Filter Results
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Showing results info -->
            <div class="flex flex-col md:flex-row justify-between mb-6">
                <div class="text-sm text-gray-600">
                    Showing <?php echo count($reviews); ?> of <?php echo $total_reviews; ?> reviews
                </div>
            </div>
            
            <!-- Reviews List -->
            <div class="mb-6 space-y-4">
                <?php if (empty($reviews)): ?>
                    <div class="dashboard-card p-6 text-center text-gray-500">
                        No reviews found matching your criteria
                    </div>
                <?php else: ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="dashboard-card p-6 fade-in-up">
                            <div class="flex flex-col md:flex-row md:items-start justify-between mb-4">
                                <div>
                                    <div class="flex items-center">
                                        <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($review['title']); ?></h3>
                                        <div class="ml-3 stars">
                                            <?php 
                                                for ($i = 0; $i < $review['rating']; $i++) {
                                                    echo '<i class="fas fa-star"></i>';
                                                }
                                                for ($i = $review['rating']; $i < 5; $i++) {
                                                    echo '<i class="far fa-star"></i>';
                                                }
                                            ?>
                                        </div>
                                    </div>
                                    <p class="text-sm text-gray-500 mt-1">
                                        By <span class="font-medium"><?php echo htmlspecialchars($review['reviewer_name']); ?></span> 
                                        on <?php echo date('M j, Y', strtotime($review['created_at'])); ?>
                                    </p>
                                </div>
                                
                                <div class="mt-2 md:mt-0 flex items-center space-x-2">
                                    <?php if ($review['is_approved']): ?>
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            Approved
                                        </span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                            Pending
                                        </span>
                                    <?php endif; ?>
                                    
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        <?php echo ucfirst($review['review_type']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <p class="text-gray-700 mb-4">
                                <?php echo htmlspecialchars($review['content']); ?>
                            </p>
                            
                            <div class="border-t pt-4">
                                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                                    <div class="mb-3 md:mb-0">
                                        <?php if ($review['review_type'] === 'property'): ?>
                                            <p class="text-sm text-gray-600">
                                                <i class="fas fa-building text-indigo-500 mr-1"></i> 
                                                Review for property: 
                                                <a href="../property.php?id=<?php echo $review['property_id']; ?>" class="text-indigo-600 hover:text-indigo-800 font-medium">
                                                    <?php echo htmlspecialchars($review['property_title']); ?>
                                                </a>
                                            </p>
                                        <?php else: ?>
                                            <p class="text-sm text-gray-600">
                                                <i class="fas fa-user-tie text-indigo-500 mr-1"></i> 
                                                Review for agent: 
                                                <a href="../agent.php?id=<?php echo $review['agent_id']; ?>" class="text-indigo-600 hover:text-indigo-800 font-medium">
                                                    <?php echo htmlspecialchars($review['agent_name']); ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="flex space-x-2">
                                        <?php if (!$review['is_approved']): ?>
                                            <form action="" method="POST" class="inline">
                                                <input type="hidden" name="review_id" value="<?php echo $review['review_id']; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="bg-green-500 hover:bg-green-600 text-white text-xs font-medium py-2 px-4 rounded transition duration-200">
                                                    <i class="fas fa-check mr-1"></i> Approve
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form action="" method="POST" class="inline">
                                                <input type="hidden" name="review_id" value="<?php echo $review['review_id']; ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white text-xs font-medium py-2 px-4 rounded transition duration-200">
                                                    <i class="fas fa-times mr-1"></i> Reject
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this review? This action cannot be undone.');">
                                            <input type="hidden" name="review_id" value="<?php echo $review['review_id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="bg-red-500 hover:bg-red-600 text-white text-xs font-medium py-2 px-4 rounded transition duration-200">
                                                <i class="fas fa-trash mr-1"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="flex justify-center my-8">
                    <nav class="inline-flex rounded-md shadow">
                        <?php if ($current_page > 1): ?>
                            <a href="?page=<?php echo $current_page - 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>&status=<?php echo urlencode($status_filter); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <i class="fas fa-chevron-left mr-1"></i> Previous
                            </a>
                        <?php else: ?>
                            <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-gray-100 text-sm font-medium text-gray-500 cursor-not-allowed">
                                <i class="fas fa-chevron-left mr-1"></i> Previous
                            </span>
                        <?php endif; ?>
                        
                        <div class="flex">
                            <?php 
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<a href="?page=1&search=' . urlencode($search) . '&type=' . urlencode($type_filter) . '&status=' . urlencode($status_filter) . '" 
                                             class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                            1
                                          </a>';
                                    if ($start_page > 2) {
                                        echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">
                                                ...
                                              </span>';
                                    }
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    if ($i == $current_page) {
                                        echo '<span class="relative inline-flex items-center px-4 py-2 border border-indigo-500 bg-indigo-600 text-sm font-medium text-white">
                                                ' . $i . '
                                              </span>';
                                    } else {
                                        echo '<a href="?page=' . $i . '&search=' . urlencode($search) . '&type=' . urlencode($type_filter) . '&status=' . urlencode($status_filter) . '" 
                                                 class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                                ' . $i . '
                                              </a>';
                                    }
                                }
                                
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">
                                                ...
                                              </span>';
                                    }
                                    echo '<a href="?page=' . $total_pages . '&search=' . urlencode($search) . '&type=' . urlencode($type_filter) . '&status=' . urlencode($status_filter) . '" 
                                             class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                            ' . $total_pages . '
                                          </a>';
                                }
                            ?>
                        </div>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?page=<?php echo $current_page + 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>&status=<?php echo urlencode($status_filter); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Next <i class="fas fa-chevron-right ml-1"></i>
                            </a>
                        <?php else: ?>
                            <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-gray-100 text-sm font-medium text-gray-500 cursor-not-allowed">
                                Next <i class="fas fa-chevron-right ml-1"></i>
                            </span>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
        </main>
        
        <!-- Footer -->
        <footer class="bg-white border-t p-4 mt-auto">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <p class="text-sm text-gray-600">
                    &copy; 2025 PrimeEstate. All rights reserved.
                </p>
                <p class="text-sm text-gray-500 mt-2 md:mt-0">
                    Admin Dashboard v1.2.0
                </p>
            </div>
        </footer>
    </div>
    
    <!-- JavaScript for Mobile Menu Toggle -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarEl = document.getElementById('sidebar');
            const menuButton = document.getElementById('mobile-menu-button');
            
            menuButton.addEventListener('click', function() {
                sidebarEl.classList.toggle('-translate-x-full');
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (!sidebarEl.contains(event.target) && !menuButton.contains(event.target) && window.innerWidth < 768) {
                    sidebarEl.classList.add('-translate-x-full');
                }
            });
        });
    </script>
</body>
</html>