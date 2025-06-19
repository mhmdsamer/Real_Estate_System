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
$brokerage_filter = isset($_GET['brokerage']) ? $_GET['brokerage'] : '';
$exp_filter = isset($_GET['experience']) ? $_GET['experience'] : '';
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($current_page - 1) * $per_page;

// Handle delete action
if (isset($_POST['delete_agent']) && isset($_POST['agent_id'])) {
    $agent_id = (int)$_POST['agent_id'];
    
    // Check if agent has properties before deleting
    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM property_listings WHERE agent_id = ?");
    $checkStmt->bind_param('i', $agent_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        $error_message = "Cannot delete agent with active property listings. Reassign or remove the listings first.";
    } else {
        // Get user_id from agent record for reference
        $userStmt = $conn->prepare("SELECT user_id FROM agents WHERE agent_id = ?");
        $userStmt->bind_param('i', $agent_id);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userRow = $userResult->fetch_assoc();
        $user_id = $userRow['user_id'];
        
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Delete agent record
            $agentStmt = $conn->prepare("DELETE FROM agents WHERE agent_id = ?");
            $agentStmt->bind_param('i', $agent_id);
            $agentResult = $agentStmt->execute();
            
            if ($agentResult) {
                // Update user type to client
                $updateStmt = $conn->prepare("UPDATE users SET user_type = 'client' WHERE user_id = ?");
                $updateStmt->bind_param('i', $user_id);
                $updateStmt->execute();
                
                $conn->commit();
                $success_message = "Agent removed successfully. User account converted to client.";
            } else {
                throw new Exception("Error deleting agent");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error: " . $e->getMessage() . " - " . $conn->error;
        }
    }
}

// Get list of all brokerages for the filter dropdown
$brokerageQuery = "SELECT DISTINCT brokerage FROM agents WHERE brokerage IS NOT NULL AND brokerage != '' ORDER BY brokerage";
$brokerageResult = $conn->query($brokerageQuery);
$brokerages = [];
while ($row = $brokerageResult->fetch_assoc()) {
    $brokerages[] = $row['brokerage'];
}

// Build the base query
$query = "SELECT a.agent_id, a.license_number, a.brokerage, a.experience_years, a.specialties,
                 u.user_id, u.email, u.first_name, u.last_name, u.phone, u.created_at, u.last_login,
                 (SELECT COUNT(*) FROM property_listings pl WHERE pl.agent_id = a.agent_id) as property_count
          FROM agents a
          JOIN users u ON a.user_id = u.user_id";

$countQuery = "SELECT COUNT(*) as total FROM agents a JOIN users u ON a.user_id = u.user_id";

$whereConditions = [];
$params = [];
$paramTypes = '';

// Add search condition if search is provided
if (!empty($search)) {
    $searchTerm = "%$search%";
    $whereConditions[] = "(u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ? OR a.license_number LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $paramTypes .= 'sssss';
}

// Add brokerage filter if provided
if (!empty($brokerage_filter)) {
    $whereConditions[] = "a.brokerage = ?";
    $params[] = $brokerage_filter;
    $paramTypes .= 's';
}

// Add experience filter if provided
if (!empty($exp_filter)) {
    switch ($exp_filter) {
        case 'novice':
            $whereConditions[] = "a.experience_years < 3";
            break;
        case 'intermediate':
            $whereConditions[] = "a.experience_years >= 3 AND a.experience_years < 7";
            break;
        case 'experienced':
            $whereConditions[] = "a.experience_years >= 7 AND a.experience_years < 15";
            break;
        case 'veteran':
            $whereConditions[] = "a.experience_years >= 15";
            break;
    }
}

// Combine where conditions if any exist
if (!empty($whereConditions)) {
    $query .= " WHERE " . implode(' AND ', $whereConditions);
    $countQuery .= " WHERE " . implode(' AND ', $whereConditions);
}

// Add order by and limit
$query .= " ORDER BY u.last_name ASC, u.first_name ASC LIMIT ?, ?";
$params[] = $offset;
$params[] = $per_page;
$paramTypes .= 'ii';

// Get total agents count for pagination
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
$total_agents = $countRow['total'];
$total_pages = ceil($total_agents / $per_page);

// Get agents for current page
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$agents = [];
while ($row = $result->fetch_assoc()) {
    $agents[] = $row;
}

// Get stats for dashboard
$statsQuery = "SELECT 
                 COUNT(*) as total_agents,
                 AVG(experience_years) as avg_experience,
                 MAX(experience_years) as max_experience,
                 (SELECT COUNT(*) FROM property_listings) as total_listings,
                 (SELECT COUNT(DISTINCT brokerage) FROM agents WHERE brokerage IS NOT NULL AND brokerage != '') as brokerage_count
               FROM agents";
$statsResult = $conn->query($statsQuery);
$stats = $statsResult->fetch_assoc();

// Get top 5 agents by property count
$topAgentsQuery = "SELECT a.agent_id, u.first_name, u.last_name, a.brokerage, 
                     COUNT(pl.listing_id) as listing_count
                   FROM agents a
                   JOIN users u ON a.user_id = u.user_id
                   JOIN property_listings pl ON a.agent_id = pl.agent_id
                   GROUP BY a.agent_id
                   ORDER BY listing_count DESC
                   LIMIT 5";
$topAgentsResult = $conn->query($topAgentsQuery);
$topAgents = [];
while ($row = $topAgentsResult->fetch_assoc()) {
    $topAgents[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Agents - PrimeEstate</title>
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Manage Agents</h1>
                
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
            
            <!-- Agent Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Total Agents Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-indigo-500 to-indigo-600 text-white fade-in-up">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Total Agents</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $stats['total_agents']; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-user-tie text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Average Experience Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-blue-500 to-blue-600 text-white fade-in-up delay-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Avg. Experience</p>
                            <p class="text-3xl font-bold mt-1"><?php echo round($stats['avg_experience'], 1); ?> <span class="text-sm">years</span></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-chart-line text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Brokerages Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-teal-500 to-teal-600 text-white fade-in-up delay-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Brokerages</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $stats['brokerage_count']; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-building text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Agents Card -->
            <?php if(!empty($topAgents)): ?>
            <div class="dashboard-card p-6 mb-6 fade-in-up delay-300">
                <h2 class="text-lg font-semibold mb-4">Top Agents by Listings</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Agent</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Brokerage</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Listings</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach($topAgents as $agent): ?>
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8 rounded-full bg-indigo-100 flex items-center justify-center">
                                            <i class="fas fa-user-tie text-indigo-600"></i>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($agent['brokerage']); ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            <?php echo $agent['listing_count']; ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Search and Filter Bar -->
            <div class="dashboard-card p-6 mb-6 fade-in-up delay-300">
                <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search Agents</label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-10 pr-12 sm:text-sm border-gray-300 rounded-md"
                                   placeholder="Name, email, license number">
                        </div>
                    </div>
                    
                    <div>
                        <label for="brokerage" class="block text-sm font-medium text-gray-700 mb-1">Brokerage</label>
                        <select name="brokerage" id="brokerage" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="">All Brokerages</option>
                            <?php foreach($brokerages as $brokerage): ?>
                                <option value="<?php echo htmlspecialchars($brokerage); ?>" <?php echo $brokerage_filter === $brokerage ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($brokerage); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="experience" class="block text-sm font-medium text-gray-700 mb-1">Experience Level</label>
                        <select name="experience" id="experience" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="">All Experience Levels</option>
                            <option value="novice" <?php echo $exp_filter === 'novice' ? 'selected' : ''; ?>>Novice (0-2 years)</option>
                            <option value="intermediate" <?php echo $exp_filter === 'intermediate' ? 'selected' : ''; ?>>Intermediate (3-6 years)</option>
                            <option value="experienced" <?php echo $exp_filter === 'experienced' ? 'selected' : ''; ?>>Experienced (7-14 years)</option>
                            <option value="veteran" <?php echo $exp_filter === 'veteran' ? 'selected' : ''; ?>>Veteran (15+ years)</option>
                        </select>
                    </div>
                    
                    <div class="md:col-span-3 flex justify-end">
                        <button type="submit" class="bg-indigo-600 border border-transparent rounded-md shadow-sm py-2 px-4 flex items-center justify-center text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-filter mr-2"></i> Filter Results
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Action buttons -->
            <div class="flex flex-col md:flex-row justify-between mb-6">
                <div class="mb-4 md:mb-0">
                    <a href="agent-add.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-plus mr-2"></i> Add New Agent
                    </a>
                </div>
                
                <div class="text-sm text-gray-600">
                    Showing <?php echo count($agents); ?> of <?php echo $total_agents; ?> agents
                </div>
            </div>
            
            <!-- Agents Table -->
            <div class="dashboard-card overflow-hidden mb-6 fade-in-up">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Agent
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Contact Info
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    License
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Experience
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Listings
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Brokerage
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if(empty($agents)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-4 text-center text-sm font-medium text-gray-500">
                                        No agents found matching your criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($agents as $agent): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                                                    <i class="fas fa-user-tie text-indigo-600"></i>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        Member since <?php echo date('M Y', strtotime($agent['created_at'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($agent['email']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($agent['phone']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($agent['license_number']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo $agent['experience_years']; ?> years</div>
                                            <?php if(!empty($agent['specialties'])): ?>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($agent['specialties']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                <?php echo $agent['property_count']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($agent['brokerage']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex space-x-3 justify-end">
                                                <a href="agent-edit.php?id=<?php echo $agent['agent_id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="Edit Agent">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="agent-view.php?id=<?php echo $agent['agent_id']; ?>" class="text-blue-600 hover:text-blue-900" title="View Agent Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if($agent['property_count'] == 0): ?>
                                                    <form method="POST" action="" class="inline-block" onsubmit="return confirm('Are you sure you want to remove this agent? The user account will be converted to a client.');">
                                                        <input type="hidden" name="agent_id" value="<?php echo $agent['agent_id']; ?>">
                                                        <button type="submit" name="delete_agent" class="text-red-600 hover:text-red-900" title="Remove Agent">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if($total_pages > 1): ?>
            <div class="flex justify-center mt-6">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php if($current_page > 1): ?>
                        <a href="?page=<?php echo $current_page - 1; ?>&search=<?php echo urlencode($search); ?>&brokerage=<?php echo urlencode($brokerage_filter); ?>&experience=<?php echo urlencode($exp_filter); ?>" 
                           class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>
                    
                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if($i == $current_page): ?>
                            <span class="relative inline-flex items-center px-4 py-2 border border-indigo-500 bg-indigo-50 text-sm font-medium text-indigo-600">
                                <?php echo $i; ?>
                            </span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&brokerage=<?php echo urlencode($brokerage_filter); ?>&experience=<?php echo urlencode($exp_filter); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if($current_page < $total_pages): ?>
                        <a href="?page=<?php echo $current_page + 1; ?>&search=<?php echo urlencode($search); ?>&brokerage=<?php echo urlencode($brokerage_filter); ?>&experience=<?php echo urlencode($exp_filter); ?>" 
                           class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
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
    </script>
</body>
</html>