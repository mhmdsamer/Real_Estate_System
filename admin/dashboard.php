<?php
require_once '../connection.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Function to get total count from any table
function getTotalCount($conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM $table");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

// Get dashboard statistics
$totalProperties = getTotalCount($conn, 'properties');
$totalUsers = getTotalCount($conn, 'users');
$totalAgents = getTotalCount($conn, 'agents');
$totalInquiries = getTotalCount($conn, 'inquiries');

// Get recent properties - CHANGED FROM 5 TO 3
$recentProperties = [];
$stmt = $conn->prepare("
    SELECT p.property_id, p.title, p.price, p.status, p.created_at, 
           p.city, p.state, p.bedrooms, p.bathrooms,
           CONCAT(u.first_name, ' ', u.last_name) as agent_name
    FROM properties p
    LEFT JOIN property_listings pl ON p.property_id = pl.property_id
    LEFT JOIN agents a ON pl.agent_id = a.agent_id
    LEFT JOIN users u ON a.user_id = u.user_id
    ORDER BY p.created_at DESC
    LIMIT 3
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentProperties[] = $row;
}

// Get recent users - CHANGED FROM 5 TO 3
$recentUsers = [];
$stmt = $conn->prepare("
    SELECT user_id, email, CONCAT(first_name, ' ', last_name) as name, 
           user_type, created_at, last_login
    FROM users
    ORDER BY created_at DESC
    LIMIT 3
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentUsers[] = $row;
}

// Get recent inquiries - CHANGED FROM 5 TO 3
$recentInquiries = [];
$stmt = $conn->prepare("
    SELECT i.inquiry_id, i.name, i.email, i.status, i.created_at,
           p.title as property_title
    FROM inquiries i
    JOIN properties p ON i.property_id = p.property_id
    ORDER BY i.created_at DESC
    LIMIT 3
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentInquiries[] = $row;
}

// Get property status distribution for chart
$propertyStatusData = [];
$stmt = $conn->prepare("
    SELECT status, COUNT(*) as count 
    FROM properties 
    GROUP BY status
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $propertyStatusData[$row['status']] = (int)$row['count'];
}

// Get property type distribution for chart
$propertyTypeData = [];
$stmt = $conn->prepare("
    SELECT property_type, COUNT(*) as count 
    FROM properties 
    GROUP BY property_type
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $propertyTypeData[$row['property_type']] = (int)$row['count'];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - PrimeEstate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .statistic-card {
            position: relative;
            overflow: hidden;
        }
        
        .statistic-card::after {
            content: "";
            position: absolute;
            top: 0;
            right: 0;
            width: 30%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0) 50%);
            z-index: 1;
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
        
        /* Animation for info cards */
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
        
        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        .delay-400 { animation-delay: 0.4s; }
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
                
                <a href="dashboard.php" class="sidebar-link active flex items-center px-4 py-3 my-1">
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Admin Dashboard</h1>
                
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
            <!-- Welcome message -->
            <div class="mb-6">
                <h2 class="text-lg md:text-2xl font-bold text-gray-800">Welcome, <?php echo $_SESSION['first_name']; ?>!</h2>
                <p class="text-gray-600">Here's what's happening with your properties today.</p>
            </div>
            
            <!-- Stats overview -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <!-- Properties statistic -->
                <div class="dashboard-card statistic-card p-6 bg-gradient-to-br from-indigo-500 to-indigo-600 text-white animate-fade-in-up">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Total Properties</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $totalProperties; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-building text-2xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 text-sm">
                        <a href="properties.php" class="flex items-center hover:underline">
                            <span>View all properties</span>
                            <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
                
                <!-- Users statistic -->
                <div class="dashboard-card statistic-card p-6 bg-gradient-to-br from-blue-500 to-blue-600 text-white animate-fade-in-up delay-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Total Users</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $totalUsers; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-users text-2xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 text-sm">
                        <a href="users.php" class="flex items-center hover:underline">
                            <span>Manage users</span>
                            <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
                
                <!-- Agents statistic -->
                <div class="dashboard-card statistic-card p-6 bg-gradient-to-br from-teal-500 to-teal-600 text-white animate-fade-in-up delay-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Total Agents</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $totalAgents; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-user-tie text-2xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 text-sm">
                        <a href="agents.php" class="flex items-center hover:underline">
                            <span>View all agents</span>
                            <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
                
                <!-- Inquiries statistic -->
                <div class="dashboard-card statistic-card p-6 bg-gradient-to-br from-amber-500 to-amber-600 text-white animate-fade-in-up delay-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Inquiries</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $totalInquiries; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-envelope text-2xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 text-sm">
                        <a href="inquiries.php" class="flex items-center hover:underline">
                            <span>View all inquiries</span>
                            <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Charts section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Property Status Chart -->
                <div class="dashboard-card p-6 animate-fade-in-up delay-100">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Property Status Distribution</h3>
                    <div class="h-64">
                        <canvas id="propertyStatusChart"></canvas>
                    </div>
                </div>
                
                <!-- Property Type Chart -->
                <div class="dashboard-card p-6 animate-fade-in-up delay-200">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Property Type Distribution</h3>
                    <div class="h-64">
                        <canvas id="propertyTypeChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Recent items section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Properties -->
                <div class="dashboard-card p-6 animate-fade-in-up delay-300">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Properties</h3>
                        <a href="properties.php" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">View All</a>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Property</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($recentProperties)): ?>
                                    <tr>
                                        <td colspan="4" class="px-4 py-4 text-center text-sm text-gray-500">No properties found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentProperties as $property): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-4">
                                                <div class="flex items-center">
                                                    <div>
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($property['title']); ?></div>
                                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($property['city'] . ', ' . $property['state']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-900">$<?php echo number_format($property['price']); ?></td>
                                            <td class="px-4 py-4">
                                                <?php 
                                                    $statusClasses = [
                                                        'for_sale' => 'bg-green-100 text-green-800',
                                                        'for_rent' => 'bg-blue-100 text-blue-800',
                                                        'sold' => 'bg-gray-100 text-gray-800',
                                                        'rented' => 'bg-gray-100 text-gray-800',
                                                        'pending' => 'bg-yellow-100 text-yellow-800'
                                                    ];
                                                    $statusText = [
                                                        'for_sale' => 'For Sale',
                                                        'for_rent' => 'For Rent',
                                                        'sold' => 'Sold',
                                                        'rented' => 'Rented',
                                                        'pending' => 'Pending'
                                                    ];
                                                    $class = $statusClasses[$property['status']] ?? 'bg-gray-100 text-gray-800';
                                                    $text = $statusText[$property['status']] ?? ucfirst($property['status']);
                                                ?>
                                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $class; ?>">
                                                    <?php echo $text; ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-500">
                                                <?php echo date('M j, Y', strtotime($property['created_at'])); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Recent Users -->
                <div class="dashboard-card p-6 animate-fade-in-up delay-400">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Users</h3>
                        <a href="users.php" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">View All</a>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Joined</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Login</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($recentUsers)): ?>
                                    <tr>
                                        <td colspan="4" class="px-4 py-4 text-center text-sm text-gray-500">No users found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentUsers as $user): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-4">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8 rounded-full bg-indigo-100 flex items-center justify-center">
                                                        <span class="text-indigo-600 font-medium text-sm">
                                                            <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                                        </span>
                                                    </div>
                                                    <div class="ml-3">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['name']); ?></div>
                                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4">
                                                <?php 
                                                    $roleClasses = [
                                                        'admin' => 'bg-purple-100 text-purple-800',
                                                        'agent' => 'bg-blue-100 text-blue-800',
                                                        'client' => 'bg-green-100 text-green-800'
                                                    ];
                                                    $class = $roleClasses[$user['user_type']] ?? 'bg-gray-100 text-gray-800';
                                                ?>
                                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $class; ?>">
                                                    <?php echo ucfirst($user['user_type']); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-500">
                                                <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-500">
                                                <?php echo $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : 'Never'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Recent Inquiries -->
                <div class="dashboard-card p-6 lg:col-span-2 animate-fade-in-up delay-400">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Recent Inquiries</h3>
                        <a href="inquiries.php" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">View All</a>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sender</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Property</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($recentInquiries)): ?>
                                    <tr>
                                        <td colspan="5" class="px-4 py-4 text-center text-sm text-gray-500">No inquiries found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentInquiries as $inquiry): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-4">
                                                <div class="flex items-center">
                                                    <div>
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($inquiry['name']); ?></div>
                                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($inquiry['email']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($inquiry['property_title']); ?></td>
                                            <td class="px-4 py-4">
                                                <?php 
                                                    $statusClasses = [
                                                        'new' => 'bg-blue-100 text-blue-800',
                                                        'in_progress' => 'bg-yellow-100 text-yellow-800',
                                                        'completed' => 'bg-green-100 text-green-800',
                                                        'closed' => 'bg-gray-100 text-gray-800'
                                                    ];
                                                    $class = $statusClasses[$inquiry['status']] ?? 'bg-gray-100 text-gray-800';
                                                    $text = str_replace('_', ' ', ucfirst($inquiry['status']));
                                                ?>
                                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $class; ?>">
                                                    <?php echo $text; ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-500">
                                                <?php echo date('M j, Y', strtotime($inquiry['created_at'])); ?>
                                            </td>
                                            <td class="px-4 py-4 text-sm">
                                                <a href="inquiries-view.php?id=<?php echo $inquiry['inquiry_id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="inquiries-edit.php?id=<?php echo $inquiry['inquiry_id']; ?>" class="text-blue-600 hover:text-blue-900">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Scripts -->
    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('-translate-x-full');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            
            if (window.innerWidth < 768 && 
                !sidebar.contains(event.target) && 
                !mobileMenuButton.contains(event.target) &&
                !sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.add('-translate-x-full');
            }
        });
        
        // Property Status Chart
        const propertyStatusCtx = document.getElementById('propertyStatusChart').getContext('2d');
        const propertyStatusChart = new Chart(propertyStatusCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(function($key) {
                    return ucwords(str_replace('_', ' ', $key));
                }, array_keys($propertyStatusData))); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($propertyStatusData)); ?>,
                    backgroundColor: [
                        '#4f46e5', // indigo-600
                        '#0ea5e9', // sky-500
                        '#10b981', // emerald-500
                        '#f59e0b', // amber-500
                        '#ef4444'  // red-500
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            font: {
                                family: 'Nunito'
                            }
                        }
                    },
                    tooltip: {
                        bodyFont: {
                            family: 'Nunito'
                        },
                        titleFont: {
                            family: 'Montserrat'
                        }
                    }
                },
                cutout: '65%',
                animation: {
                    animateScale: true,
                    animateRotate: true
                }
            }
        });
        
        // Property Type Chart
        const propertyTypeCtx = document.getElementById('propertyTypeChart').getContext('2d');
        const propertyTypeChart = new Chart(propertyTypeCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($key) {
                    return ucwords(str_replace('_', ' ', $key));
                }, array_keys($propertyTypeData))); ?>,
                datasets: [{
                    label: 'Number of Properties',
                    data: <?php echo json_encode(array_values($propertyTypeData)); ?>,
                    backgroundColor: [
                        'rgba(79, 70, 229, 0.7)', // indigo-600
                        'rgba(14, 165, 233, 0.7)', // sky-500
                        'rgba(16, 185, 129, 0.7)', // emerald-500
                        'rgba(245, 158, 11, 0.7)', // amber-500
                        'rgba(239, 68, 68, 0.7)'   // red-500
                    ],
                    borderColor: [
                        'rgb(79, 70, 229)', // indigo-600
                        'rgb(14, 165, 233)', // sky-500
                        'rgb(16, 185, 129)', // emerald-500
                        'rgb(245, 158, 11)', // amber-500
                        'rgb(239, 68, 68)'   // red-500
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false
                        },
                        ticks: {
                            font: {
                                family: 'Nunito'
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                family: 'Nunito'
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        bodyFont: {
                            family: 'Nunito'
                        },
                        titleFont: {
                            family: 'Montserrat'
                        }
                    }
                },
                animation: {
                    duration: 1500
                }
            }
        });
        
        // Add responsive behavior for window resize
        window.addEventListener('resize', function() {
            propertyStatusChart.resize();
            propertyTypeChart.resize();
        });
    </script>
</body>
</html>