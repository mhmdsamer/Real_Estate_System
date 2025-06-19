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

// Handle status updates
if (isset($_POST['update_status']) && isset($_POST['viewing_id']) && isset($_POST['new_status'])) {
    $viewing_id = $_POST['viewing_id'];
    $new_status = $_POST['new_status'];
    
    $update_query = $conn->prepare("UPDATE property_viewings SET status = ? WHERE viewing_id = ? AND agent_id = ?");
    $update_query->bind_param("sii", $new_status, $viewing_id, $agent_id);
    $update_query->execute();
    
    // Add flash message for success
    $_SESSION['flash_message'] = "Viewing status updated successfully!";
    $_SESSION['flash_type'] = "success";
    
    // Redirect to refresh page and avoid form resubmission
    header("Location: viewings.php");
    exit();
}

// Add note to a viewing
if (isset($_POST['add_note']) && isset($_POST['viewing_id']) && isset($_POST['notes'])) {
    $viewing_id = $_POST['viewing_id'];
    $notes = $_POST['notes'];
    
    $note_query = $conn->prepare("UPDATE property_viewings SET notes = ? WHERE viewing_id = ? AND agent_id = ?");
    $note_query->bind_param("sii", $notes, $viewing_id, $agent_id);
    $note_query->execute();
    
    // Add flash message for success
    $_SESSION['flash_message'] = "Note added successfully!";
    $_SESSION['flash_type'] = "success";
    
    // Redirect to refresh page and avoid form resubmission
    header("Location: viewings.php");
    exit();
}

// Delete a viewing
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $viewing_id = $_GET['delete'];
    
    $delete_query = $conn->prepare("DELETE FROM property_viewings WHERE viewing_id = ? AND agent_id = ?");
    $delete_query->bind_param("ii", $viewing_id, $agent_id);
    $delete_query->execute();
    
    // Add flash message for success
    $_SESSION['flash_message'] = "Viewing deleted successfully!";
    $_SESSION['flash_type'] = "success";
    
    // Redirect to refresh page
    header("Location: viewings.php");
    exit();
}

// Setup filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Prepare base query
$query = "
    SELECT pv.*, p.title, p.address, p.property_type, p.status as property_status, 
           u.first_name, u.last_name, u.email, u.phone
    FROM property_viewings pv
    JOIN properties p ON pv.property_id = p.property_id
    LEFT JOIN users u ON pv.user_id = u.user_id
    WHERE pv.agent_id = ?
";

$params = [$agent_id];
$types = "i";

// Add filters to query
if (!empty($status_filter)) {
    $query .= " AND pv.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($date_filter)) {
    switch ($date_filter) {
        case 'today':
            $query .= " AND DATE(pv.viewing_date) = CURDATE()";
            break;
        case 'tomorrow':
            $query .= " AND DATE(pv.viewing_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'this_week':
            $query .= " AND YEARWEEK(pv.viewing_date, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'next_week':
            $query .= " AND YEARWEEK(pv.viewing_date, 1) = YEARWEEK(DATE_ADD(CURDATE(), INTERVAL 1 WEEK), 1)";
            break;
        case 'past':
            $query .= " AND pv.viewing_date < NOW()";
            break;
        case 'future':
            $query .= " AND pv.viewing_date >= NOW()";
            break;
    }
}

if (!empty($search)) {
    $search_term = "%$search%";
    $query .= " AND (p.title LIKE ? OR p.address LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
    $types .= "sssss";
}

// Add ordering
$query .= " ORDER BY pv.viewing_date DESC";

// Prepare and execute the query
$viewings_query = $conn->prepare($query);
$viewings_query->bind_param($types, ...$params);
$viewings_query->execute();
$viewings_result = $viewings_query->get_result();

// Get count for each status for the sidebar badges
$status_counts = [
    'requested' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'canceled' => 0
];

$count_query = $conn->prepare("SELECT status, COUNT(*) as count FROM property_viewings WHERE agent_id = ? GROUP BY status");
$count_query->bind_param("i", $agent_id);
$count_query->execute();
$count_result = $count_query->get_result();

while ($row = $count_result->fetch_assoc()) {
    $status_counts[$row['status']] = $row['count'];
}

// Function to format status badge
function getStatusBadge($status) {
    switch ($status) {
        case 'requested':
            return '<span class="badge badge-warning">Requested</span>';
        case 'confirmed':
            return '<span class="badge badge-primary">Confirmed</span>';
        case 'completed':
            return '<span class="badge badge-success">Completed</span>';
        case 'canceled':
            return '<span class="badge badge-danger">Canceled</span>';
        default:
            return '<span class="badge badge-secondary">Unknown</span>';
    }
}

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

// Function to format date and time
function formatDateTime($dateTime) {
    $date = new DateTime($dateTime);
    return $date->format('M j, Y - g:i A');
}

// Function to get relative time for upcoming or past viewings
function getRelativeTime($dateTime) {
    $date = new DateTime($dateTime);
    $now = new DateTime();
    $interval = $now->diff($date);
    
    if ($date < $now) {
        // Past
        if ($interval->days == 0) {
            return '<span class="text-red-600">Today, ' . $date->format('g:i A') . '</span>';
        } elseif ($interval->days == 1) {
            return '<span class="text-red-600">Yesterday, ' . $date->format('g:i A') . '</span>';
        } elseif ($interval->days < 7) {
            return '<span class="text-red-600">' . $interval->days . ' days ago, ' . $date->format('g:i A') . '</span>';
        } else {
            return '<span class="text-red-600">' . $date->format('M j, Y - g:i A') . '</span>';
        }
    } else {
        // Future
        if ($interval->days == 0) {
            $hours = $interval->h;
            if ($hours < 1) {
                return '<span class="text-green-600">In ' . $interval->i . ' minutes</span>';
            } else {
                return '<span class="text-green-600">Today, ' . $date->format('g:i A') . '</span>';
            }
        } elseif ($interval->days == 1) {
            return '<span class="text-green-600">Tomorrow, ' . $date->format('g:i A') . '</span>';
        } elseif ($interval->days < 7) {
            return '<span class="text-green-600">In ' . $interval->days . ' days, ' . $date->format('g:i A') . '</span>';
        } else {
            return '<span class="text-green-600">' . $date->format('M j, Y - g:i A') . '</span>';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Viewings - PrimeEstate</title>
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
                                <span class="ml-auto px-2 py-0.5 text-xs rounded-full bg-indigo-100 text-indigo-800">
                                    <?php echo $status_counts['requested'] + $status_counts['confirmed']; ?>
                                </span>
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
                <h1 class="text-lg md:text-xl font-bold text-gray-800">Property Viewings</h1>
                
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
                                <span class="ml-auto px-2 py-0.5 text-xs rounded-full bg-indigo-100 text-indigo-800">
                                    <?php echo $status_counts['requested'] + $status_counts['confirmed']; ?>
                                </span>
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
                
                <!-- Top Action Bar -->
                <div class="mb-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">Property Viewings</h2>
                            <p class="text-gray-600 mt-1">Manage and track all property viewings with clients.</p>
                        </div>
                        <div class="mt-4 md:mt-0">
                            <a href="schedule-viewing.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <i class="fas fa-calendar-plus mr-2"></i>
                                Schedule Viewing
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Filter and Search Bar -->
                <div class="card p-4 mb-6">
                    <form action="viewings.php" method="GET" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select id="status" name="status" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                    <option value="">All Statuses</option>
                                    <option value="requested" <?php echo $status_filter == 'requested' ? 'selected' : ''; ?>>Requested</option>
                                    <option value="confirmed" <?php echo $status_filter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="canceled" <?php echo $status_filter == 'canceled' ? 'selected' : ''; ?>>Canceled</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="date_filter" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <select id="date_filter" name="date_filter" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                <option value="">All Dates</option>
                                    <option value="today" <?php echo $date_filter == 'today' ? 'selected' : ''; ?>>Today</option>
                                    <option value="tomorrow" <?php echo $date_filter == 'tomorrow' ? 'selected' : ''; ?>>Tomorrow</option>
                                    <option value="this_week" <?php echo $date_filter == 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                    <option value="next_week" <?php echo $date_filter == 'next_week' ? 'selected' : ''; ?>>Next Week</option>
                                    <option value="past" <?php echo $date_filter == 'past' ? 'selected' : ''; ?>>Past Viewings</option>
                                    <option value="future" <?php echo $date_filter == 'future' ? 'selected' : ''; ?>>Upcoming Viewings</option>
                                </select>
                            </div>
                            
                            <div class="sm:col-span-2">
                                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <div class="relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-search text-gray-400"></i>
                                    </div>
                                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" class="pl-10 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Search by property, client, or address...">
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-between">
                            <button type="submit" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <i class="fas fa-filter mr-2"></i> Filter Results
                            </button>
                            
                            <a href="viewings.php" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <i class="fas fa-redo mr-2"></i> Reset Filters
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Status Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <!-- Requested Viewings -->
                    <div class="card px-4 py-5 flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Requested</p>
                            <h3 class="text-2xl font-bold text-gray-900"><?php echo $status_counts['requested']; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-clock text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                    
                    <!-- Confirmed Viewings -->
                    <div class="card px-4 py-5 flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Confirmed</p>
                            <h3 class="text-2xl font-bold text-gray-900"><?php echo $status_counts['confirmed']; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-calendar-check text-blue-600 text-xl"></i>
                        </div>
                    </div>
                    
                    <!-- Completed Viewings -->
                    <div class="card px-4 py-5 flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Completed</p>
                            <h3 class="text-2xl font-bold text-gray-900"><?php echo $status_counts['completed']; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                    
                    <!-- Canceled Viewings -->
                    <div class="card px-4 py-5 flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Canceled</p>
                            <h3 class="text-2xl font-bold text-gray-900"><?php echo $status_counts['canceled']; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Viewings List -->
                <div class="card p-0 overflow-hidden">
                    <div class="border-b border-gray-200 bg-gray-50 px-6 py-4">
                        <h3 class="text-lg font-medium text-gray-900">All Viewings</h3>
                    </div>
                    
                    <?php if ($viewings_result->num_rows === 0): ?>
                        <div class="p-8 text-center">
                            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-gray-100">
                                <i class="fas fa-calendar-times text-gray-500"></i>
                            </div>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No viewings found</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                No viewings match your current filters.
                            </p>
                            <div class="mt-6">
                                <a href="schedule-viewing.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    <i class="fas fa-calendar-plus mr-2"></i>
                                    Schedule a Viewing
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-200">
                            <?php while ($viewing = $viewings_result->fetch_assoc()): ?>
                                <div class="px-6 py-4 hover:bg-gray-50 transition-colors">
                                    <div class="flex flex-col sm:flex-row justify-between">
                                        <div class="flex flex-col sm:flex-row sm:items-center">
                                            <div class="mr-4">
                                                <!-- Display viewing relative time -->
                                                <div class="font-medium text-sm">
                                                    <?php echo getRelativeTime($viewing['viewing_date']); ?>
                                                </div>
                                                
                                                <!-- Display viewing date and time in smaller text -->
                                                <div class="text-xs text-gray-500 mt-1">
                                                    <?php echo formatDateTime($viewing['viewing_date']); ?>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-2 sm:mt-0">
                                                <!-- Property Title and Type -->
                                                <h4 class="text-base font-medium text-gray-900">
                                                    <a href="../property.php?id=<?php echo $viewing['property_id']; ?>" class="hover:text-indigo-600">
                                                        <?php echo htmlspecialchars($viewing['title']); ?>
                                                    </a>
                                                </h4>
                                                
                                                <!-- Property Address -->
                                                <p class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($viewing['address']); ?>
                                                </p>
                                                
                                                <!-- Property Type and Status Badges -->
                                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                                    <span class="badge badge-secondary">
                                                        <?php echo ucfirst(str_replace('_', ' ', $viewing['property_type'])); ?>
                                                    </span>
                                                    <?php echo getPropertyStatusBadge($viewing['property_status']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-4 sm:mt-0 flex flex-col sm:items-end">
                                            <!-- Client Name if available -->
                                            <?php if ($viewing['user_id']): ?>
                                                <div class="text-sm font-medium mb-1">
                                                    <a href="client.php?id=<?php echo $viewing['user_id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                                        <?php echo htmlspecialchars($viewing['first_name'] . ' ' . $viewing['last_name']); ?>
                                                    </a>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo htmlspecialchars($viewing['email']); ?>
                                                    <?php if ($viewing['phone']): ?>
                                                        &middot; <?php echo htmlspecialchars($viewing['phone']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-sm text-gray-500">
                                                    No client associated
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Viewing Status -->
                                            <div class="mt-2">
                                                <?php echo getStatusBadge($viewing['status']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Viewing Notes (if any) -->
                                    <?php if ($viewing['notes']): ?>
                                        <div class="mt-2 text-sm text-gray-600 bg-gray-50 p-3 rounded-md">
                                            <i class="fas fa-sticky-note text-gray-400 mr-2"></i>
                                            <?php echo nl2br(htmlspecialchars($viewing['notes'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Actions Buttons -->
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <!-- Status Update Form -->
                                        <form method="POST" action="viewings.php" class="inline-flex">
                                            <input type="hidden" name="viewing_id" value="<?php echo $viewing['viewing_id']; ?>">
                                            <input type="hidden" name="update_status" value="1">
                                            
                                            <select name="new_status" class="text-sm border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 mr-2">
                                                <option value="requested" <?php echo $viewing['status'] == 'requested' ? 'selected' : ''; ?>>Requested</option>
                                                <option value="confirmed" <?php echo $viewing['status'] == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                                <option value="completed" <?php echo $viewing['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="canceled" <?php echo $viewing['status'] == 'canceled' ? 'selected' : ''; ?>>Canceled</option>
                                            </select>
                                            
                                            <button type="submit" class="text-xs bg-indigo-50 text-indigo-700 px-2 py-1 rounded hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                                Update Status
                                            </button>
                                        </form>
                                        
                                        <!-- Add Note Button -->
                                        <button type="button" class="text-xs bg-green-50 text-green-700 px-2 py-1 rounded hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500" 
                                                onclick="document.getElementById('note-modal-<?php echo $viewing['viewing_id']; ?>').classList.remove('hidden')">
                                            <i class="fas fa-sticky-note mr-1"></i> Add Note
                                        </button>
                                        
                                        <!-- Contact Client Button -->
                                        <?php if ($viewing['user_id'] && $viewing['email']): ?>
                                            <a href="mailto:<?php echo $viewing['email']; ?>" class="text-xs bg-blue-50 text-blue-700 px-2 py-1 rounded hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                <i class="fas fa-envelope mr-1"></i> Contact
                                            </a>
                                        <?php endif; ?>
                                        
                                        <!-- Delete Button -->
                                        <a href="viewings.php?delete=<?php echo $viewing['viewing_id']; ?>" onclick="return confirm('Are you sure you want to delete this viewing?')" 
                                           class="text-xs bg-red-50 text-red-700 px-2 py-1 rounded hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                            <i class="fas fa-trash-alt mr-1"></i> Delete
                                        </a>
                                    </div>
                                    
                                    <!-- Note Modal -->
                                    <div id="note-modal-<?php echo $viewing['viewing_id']; ?>" class="fixed inset-0 z-50 overflow-y-auto hidden">
                                        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                                            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                                                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
                                            </div>
                                            
                                            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                                            
                                            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                                                <form method="POST" action="viewings.php">
                                                    <input type="hidden" name="viewing_id" value="<?php echo $viewing['viewing_id']; ?>">
                                                    <input type="hidden" name="add_note" value="1">
                                                    
                                                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                                        <div>
                                                            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Add Note to Viewing</h3>
                                                            
                                                            <div>
                                                                <label for="notes-<?php echo $viewing['viewing_id']; ?>" class="block text-sm font-medium text-gray-700">Notes</label>
                                                                <div class="mt-1">
                                                                    <textarea id="notes-<?php echo $viewing['viewing_id']; ?>" name="notes" rows="4" class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"><?php echo htmlspecialchars($viewing['notes'] ?? ''); ?></textarea>
                                                                </div>
                                                                <p class="mt-2 text-sm text-gray-500">Add any relevant information about this viewing appointment.</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                                                        <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm">
                                                            Save Note
                                                        </button>
                                                        <button type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm" 
                                                                onclick="document.getElementById('note-modal-<?php echo $viewing['viewing_id']; ?>').classList.add('hidden')">
                                                            Cancel
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
    
    <script>
        // Toggle user dropdown menu
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenuDropdown = document.getElementById('user-menu-dropdown');
        
        userMenuButton.addEventListener('click', function() {
            userMenuDropdown.classList.toggle('hidden');
        });
        
        // Close user dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!userMenuButton.contains(event.target) && !userMenuDropdown.contains(event.target)) {
                userMenuDropdown.classList.add('hidden');
            }
        });
        
        // Mobile menu handling
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const closeMobileMenuButton = document.getElementById('close-mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const mobileMenu = document.getElementById('mobile-menu');
        
        mobileMenuButton.addEventListener('click', function() {
            mobileMenu.classList.remove('hidden');
        });
        
        function closeMobileMenu() {
            mobileMenu.classList.add('hidden');
        }
        
        closeMobileMenuButton.addEventListener('click', closeMobileMenu);
        mobileMenuOverlay.addEventListener('click', closeMobileMenu);
        
        // Auto-hide flash messages after 5 seconds
        const flashMessage = document.getElementById('flash-message');
        if (flashMessage) {
            setTimeout(function() {
                flashMessage.remove();
            }, 5000);
        }
    </script>
</body>
</html>