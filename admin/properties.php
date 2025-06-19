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
$city_filter = isset($_GET['city']) ? $_GET['city'] : '';
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($current_page - 1) * $per_page;

// Handle delete action
if (isset($_POST['delete_property']) && isset($_POST['property_id'])) {
    $property_id = (int)$_POST['property_id'];
    
    $stmt = $conn->prepare("DELETE FROM properties WHERE property_id = ?");
    $stmt->bind_param('i', $property_id);
    if ($stmt->execute()) {
        $success_message = "Property deleted successfully.";
    } else {
        $error_message = "Error deleting property: " . $conn->error;
    }
    $stmt->close();
}

// Handle feature toggle
if (isset($_POST['toggle_feature']) && isset($_POST['property_id'])) {
    $property_id = (int)$_POST['property_id'];
    
    // First get current featured status
    $stmt = $conn->prepare("SELECT featured FROM properties WHERE property_id = ?");
    $stmt->bind_param('i', $property_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $property = $result->fetch_assoc();
    $featured = $property['featured'] ? 0 : 1; // Toggle status
    
    // Update featured status
    $updateStmt = $conn->prepare("UPDATE properties SET featured = ? WHERE property_id = ?");
    $updateStmt->bind_param('ii', $featured, $property_id);
    if ($updateStmt->execute()) {
        $success_message = "Property featured status updated.";
    } else {
        $error_message = "Error updating featured status: " . $conn->error;
    }
    $updateStmt->close();
}

// Build the base query
$query = "SELECT p.*, 
                 (SELECT COUNT(*) FROM property_images WHERE property_id = p.property_id) AS image_count,
                 (SELECT COUNT(*) FROM inquiries WHERE property_id = p.property_id) AS inquiry_count,
                 CONCAT(a.first_name, ' ', a.last_name) AS agent_name
          FROM properties p
          LEFT JOIN property_listings pl ON p.property_id = pl.property_id
          LEFT JOIN agents ag ON pl.agent_id = ag.agent_id
          LEFT JOIN users a ON ag.user_id = a.user_id";

$countQuery = "SELECT COUNT(*) as total FROM properties p";

$whereConditions = [];
$params = [];
$paramTypes = '';

// Add search condition if search is provided
if (!empty($search)) {
    $searchTerm = "%$search%";
    $searchQuery = " LEFT JOIN property_listings pl_search ON p.property_id = pl_search.property_id
                     LEFT JOIN agents ag_search ON pl_search.agent_id = ag_search.agent_id
                     LEFT JOIN users a_search ON ag_search.user_id = a_search.user_id";
    $query .= $searchQuery;
    $countQuery .= $searchQuery;
    
    $whereConditions[] = "(p.title LIKE ? OR p.address LIKE ? OR p.city LIKE ? OR p.postal_code LIKE ? OR a_search.first_name LIKE ? OR a_search.last_name LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $paramTypes .= 'ssssss';
}

// Add property type filter if provided
if (!empty($type_filter)) {
    $whereConditions[] = "p.property_type = ?";
    $params[] = $type_filter;
    $paramTypes .= 's';
}

// Add status filter if provided
if (!empty($status_filter)) {
    $whereConditions[] = "p.status = ?";
    $params[] = $status_filter;
    $paramTypes .= 's';
}

// Add city filter if provided
if (!empty($city_filter)) {
    $whereConditions[] = "p.city = ?";
    $params[] = $city_filter;
    $paramTypes .= 's';
}

// Combine where conditions if any exist
if (!empty($whereConditions)) {
    $query .= " WHERE " . implode(' AND ', $whereConditions);
    $countQuery .= " WHERE " . implode(' AND ', $whereConditions);
}

// Add order by and limit
$query .= " GROUP BY p.property_id ORDER BY p.created_at DESC LIMIT ?, ?";
$params[] = $offset;
$params[] = $per_page;
$paramTypes .= 'ii';

// Get total properties count for pagination
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
$total_properties = $countRow['total'];
$total_pages = ceil($total_properties / $per_page);

// Get properties for current page
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$properties = [];
while ($row = $result->fetch_assoc()) {
    $properties[] = $row;
}

// Count properties by type for statistics
$propertyStats = [];
$typeStmt = $conn->prepare("SELECT property_type, COUNT(*) as count FROM properties GROUP BY property_type");
$typeStmt->execute();
$typeResult = $typeStmt->get_result();
while ($row = $typeResult->fetch_assoc()) {
    $propertyStats[$row['property_type']] = $row['count'];
}

// Count properties by status for statistics
$statusStats = [];
$statusStmt = $conn->prepare("SELECT status, COUNT(*) as count FROM properties GROUP BY status");
$statusStmt->execute();
$statusResult = $statusStmt->get_result();
while ($row = $statusResult->fetch_assoc()) {
    $statusStats[$row['status']] = $row['count'];
}

// Get all cities for the filter dropdown
$cityStmt = $conn->prepare("SELECT DISTINCT city FROM properties ORDER BY city ASC");
$cityStmt->execute();
$cityResult = $cityStmt->get_result();
$cities = [];
while ($row = $cityResult->fetch_assoc()) {
    $cities[] = $row['city'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Properties - PrimeEstate</title>
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
                
                <a href="properties.php" class="sidebar-link active flex items-center px-4 py-3 my-1">
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Manage Properties</h1>
                
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
            
            <!-- Property Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <!-- Total Properties Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-indigo-500 to-indigo-600 text-white fade-in-up">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Total Properties</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $total_properties; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-building text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- For Sale Properties Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-blue-500 to-blue-600 text-white fade-in-up delay-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">For Sale</p>
                            <p class="text-3xl font-bold mt-1"><?php echo isset($statusStats['for_sale']) ? $statusStats['for_sale'] : 0; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-tag text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- For Rent Properties Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-teal-500 to-teal-600 text-white fade-in-up delay-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">For Rent</p>
                            <p class="text-3xl font-bold mt-1"><?php echo isset($statusStats['for_rent']) ? $statusStats['for_rent'] : 0; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-key text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Sold Properties Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-purple-500 to-purple-600 text-white fade-in-up delay-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Sold/Rented</p>
                            <p class="text-3xl font-bold mt-1">
                                <?php 
                                    $soldRented = (isset($statusStats['sold']) ? $statusStats['sold'] : 0) + 
                                                 (isset($statusStats['rented']) ? $statusStats['rented'] : 0);
                                    echo $soldRented;
                                ?>
                            </p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Search and Filter Bar -->
            <div class="dashboard-card p-6 mb-6 fade-in-up">
                <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search Properties</label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-10 pr-12 sm:text-sm border-gray-300 rounded-md"
                                   placeholder="Title, address, agent...">
                        </div>
                    </div>
                    
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Property Type</label>
                        <select name="type" id="type" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="">All Types</option>
                            <option value="apartment" <?php echo $type_filter === 'apartment' ? 'selected' : ''; ?>>Apartment</option>
                            <option value="house" <?php echo $type_filter === 'house' ? 'selected' : ''; ?>>House</option>
                            <option value="condo" <?php echo $type_filter === 'condo' ? 'selected' : ''; ?>>Condo</option>
                            <option value="townhouse" <?php echo $type_filter === 'townhouse' ? 'selected' : ''; ?>>Townhouse</option>
                            <option value="land" <?php echo $type_filter === 'land' ? 'selected' : ''; ?>>Land</option>
                            <option value="commercial" <?php echo $type_filter === 'commercial' ? 'selected' : ''; ?>>Commercial</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" id="status" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="">All Statuses</option>
                            <option value="for_sale" <?php echo $status_filter === 'for_sale' ? 'selected' : ''; ?>>For Sale</option>
                            <option value="for_rent" <?php echo $status_filter === 'for_rent' ? 'selected' : ''; ?>>For Rent</option>
                            <option value="sold" <?php echo $status_filter === 'sold' ? 'selected' : ''; ?>>Sold</option>
                            <option value="rented" <?php echo $status_filter === 'rented' ? 'selected' : ''; ?>>Rented</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="city" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                        <select name="city" id="city" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="">All Cities</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>" <?php echo $city_filter === $city ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($city); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="md:col-span-4 flex justify-end">
                        <button type="submit" class="bg-indigo-600 border border-transparent rounded-md shadow-sm py-2 px-4 flex items-center justify-center text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-filter mr-2"></i> Filter Results
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Action buttons -->
            <div class="flex flex-col md:flex-row justify-between mb-6">
                <div class="mb-4 md:mb-0">
                    <a href="property-add.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-plus mr-2"></i> Add New Property
                    </a>
                </div>
                
                <div class="text-sm text-gray-600">
                    Showing <?php echo count($properties); ?> of <?php echo $total_properties; ?> properties
                </div>
            </div>
            
            <!-- Properties Table -->
            <div class="dashboard-card overflow-hidden mb-6 fade-in-up">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Property
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Location
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Details
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Price
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($properties)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        No properties found matching your criteria
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($properties as $property): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 rounded bg-gray-200 flex items-center justify-center">
                                                    <?php if ($property['image_count'] > 0): ?>
                                                        <i class="fas fa-camera text-gray-400"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-building text-gray-400"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($property['title']); ?>
                                                        <?php if($property['featured']): ?>
                                                            <span class="ml-1 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                                Featured
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        ID: <?php echo $property['property_id']; ?> | Type: <?php echo ucfirst(str_replace('_', ' ', $property['property_type'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($property['city'] . ', ' . $property['state']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($property['address']); ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900">
                                                <?php if($property['bedrooms']): ?>
                                                    <span class="mr-2"><i class="fas fa-bed text-gray-400 mr-1"></i><?php echo $property['bedrooms']; ?></span>
                                                <?php endif; ?>
                                                <?php if($property['bathrooms']): ?>
                                                    <span class="mr-2"><i class="fas fa-bath text-gray-400 mr-1"></i><?php echo $property['bathrooms']; ?></span>
                                                <?php endif; ?>
                                                <?php if($property['area_sqft']): ?>
                                                    <span><i class="fas fa-vector-square text-gray-400 mr-1"></i><?php echo number_format($property['area_sqft']); ?> sq.ft</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php if($property['inquiry_count'] > 0): ?>
                                                    <span class="text-blue-600"><?php echo $property['inquiry_count']; ?> inquiries</span>
                                                <?php else: ?>
                                                    <span class="text-gray-500">No inquiries</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php
                                                $statusClasses = [
                                                    'for_sale' => 'bg-green-100 text-green-800',
                                                    'for_rent' => 'bg-blue-100 text-blue-800',
                                                    'sold' => 'bg-gray-100 text-gray-800',
                                                    'rented' => 'bg-purple-100 text-purple-800',
                                                    'pending' => 'bg-yellow-100 text-yellow-800'
                                                ];
                                                $statusText = [
                                                    'for_sale' => 'For Sale',
                                                    'for_rent' => 'For Rent',
                                                    'sold' => 'Sold',
                                                    'rented' => 'Rented',
                                                    'pending' => 'Pending'
                                                ];
                                                $statusClass = isset($statusClasses[$property['status']]) ? $statusClasses[$property['status']] : 'bg-gray-100 text-gray-800';
                                                $statusDisplay = isset($statusText[$property['status']]) ? $statusText[$property['status']] : ucfirst(str_replace('_', ' ', $property['status']));
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                                <?php echo $statusDisplay; ?>
                                            </span>
                                            
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?php if($property['agent_name']): ?>
                                                    Agent: <?php echo htmlspecialchars($property['agent_name']); ?>
                                                <?php else: ?>
                                                    <span class="text-red-500">No agent assigned</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                $<?php echo number_format($property['price']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                Added: <?php echo date('M j, Y', strtotime($property['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-right text-sm font-medium whitespace-nowrap">
                                            <div class="flex justify-end space-x-2">
                                                <a href="../property-detail.php?id=<?php echo $property['property_id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="property-edit.php?id=<?php echo $property['property_id']; ?>" class="text-blue-600 hover:text-blue-900" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                
                                                <form method="post" class="inline-block" onsubmit="return confirm('Are you sure you want to toggle featured status?');">
                                                    <input type="hidden" name="property_id" value="<?php echo $property['property_id']; ?>">
                                                    <input type="hidden" name="toggle_feature" value="1">
                                                    <button type="submit" class="<?php echo $property['featured'] ? 'text-yellow-500 hover:text-yellow-700' : 'text-gray-400 hover:text-gray-600'; ?>" title="<?php echo $property['featured'] ? 'Remove from featured' : 'Add to featured'; ?>">
                                                        <i class="fas fa-star"></i>
                                                    </button>
                                                </form>
                                                
                                                <form method="post" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this property? This action cannot be undone.');">
                                                    <input type="hidden" name="property_id" value="<?php echo $property['property_id']; ?>">
                                                    <input type="hidden" name="delete_property" value="1">
                                                    <button type="submit" class="text-red-600 hover:text-red-900" title="Delete">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
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
            <?php if ($total_pages > 1): ?>
                <div class="flex justify-center my-6">
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php if ($current_page > 1): ?>
                            <a href="?page=<?php echo $current_page - 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>&status=<?php echo urlencode($status_filter); ?>&city=<?php echo urlencode($city_filter); ?>" 
                               class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <span class="sr-only">Previous</span>
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                                <span class="sr-only">Previous</span>
                                <i class="fas fa-chevron-left"></i>
                            </span>
                        <?php endif; ?>
                        
                        <?php
                        // Show limited page numbers with ellipsis
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        // Always show first page
                        if ($start_page > 1) {
                            echo '<a href="?page=1&search=' . urlencode($search) . '&type=' . urlencode($type_filter) . '&status=' . urlencode($status_filter) . '&city=' . urlencode($city_filter) . '" 
                                     class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>';
                            if ($start_page > 2) {
                                echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                            }
                        }
                        
                        // Page links
                        for ($i = $start_page; $i <= $end_page; $i++) {
                            if ($i == $current_page) {
                                echo '<span aria-current="page" class="z-10 bg-indigo-50 border-indigo-500 text-indigo-600 relative inline-flex items-center px-4 py-2 border text-sm font-medium">' . $i . '</span>';
                            } else {
                                echo '<a href="?page=' . $i . '&search=' . urlencode($search) . '&type=' . urlencode($type_filter) . '&status=' . urlencode($status_filter) . '&city=' . urlencode($city_filter) . '" 
                                         class="bg-white border-gray-300 text-gray-500 hover:bg-gray-50 relative inline-flex items-center px-4 py-2 border text-sm font-medium">' . $i . '</a>';
                            }
                        }
                        
                        // Always show last page
                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) {
                                echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                            }
                            echo '<a href="?page=' . $total_pages . '&search=' . urlencode($search) . '&type=' . urlencode($type_filter) . '&status=' . urlencode($status_filter) . '&city=' . urlencode($city_filter) . '" 
                                     class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">' . $total_pages . '</a>';
                        }
                        ?>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?page=<?php echo $current_page + 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>&status=<?php echo urlencode($status_filter); ?>&city=<?php echo urlencode($city_filter); ?>" 
                               class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <span class="sr-only">Next</span>
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                                <span class="sr-only">Next</span>
                                <i class="fas fa-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
            
        </main>
        
        <!-- Footer -->
        <footer class="bg-white p-4 border-t">
            <div class="text-center text-sm text-gray-500">
                &copy; <?php echo date('Y'); ?> PrimeEstate. All rights reserved.
            </div>
        </footer>
    </div>
    
    <!-- Mobile menu overlay -->
    <div id="mobile-menu-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden transition-opacity"></div>
    
    <!-- JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarEl = document.getElementById('sidebar');
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const menuOverlay = document.getElementById('mobile-menu-overlay');
            
            function toggleMobileMenu() {
                sidebarEl.classList.toggle('-translate-x-full');
                menuOverlay.classList.toggle('hidden');
                document.body.classList.toggle('overflow-hidden');
            }
            
            mobileMenuButton.addEventListener('click', toggleMobileMenu);
            menuOverlay.addEventListener('click', toggleMobileMenu);
            
            // Animation delay for statistics cards
            const cards = document.querySelectorAll('.dashboard-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
            });
        });
    </script>
</body>
</html>