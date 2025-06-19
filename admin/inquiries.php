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
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($current_page - 1) * $per_page;

// Handle update status action
if (isset($_POST['update_status']) && isset($_POST['inquiry_id']) && isset($_POST['status'])) {
    $inquiry_id = (int)$_POST['inquiry_id'];
    $status = $_POST['status'];
    
    // Validate status
    $valid_statuses = ['new', 'in_progress', 'responded', 'closed'];
    if (in_array($status, $valid_statuses)) {
        $stmt = $conn->prepare("UPDATE inquiries SET status = ? WHERE inquiry_id = ?");
        $stmt->bind_param('si', $status, $inquiry_id);
        if ($stmt->execute()) {
            $success_message = "Inquiry status updated successfully.";
        } else {
            $error_message = "Error updating inquiry status: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error_message = "Invalid status value.";
    }
}

// Handle delete action
if (isset($_POST['delete_inquiry']) && isset($_POST['inquiry_id'])) {
    $inquiry_id = (int)$_POST['inquiry_id'];
    
    $stmt = $conn->prepare("DELETE FROM inquiries WHERE inquiry_id = ?");
    $stmt->bind_param('i', $inquiry_id);
    if ($stmt->execute()) {
        $success_message = "Inquiry deleted successfully.";
    } else {
        $error_message = "Error deleting inquiry: " . $conn->error;
    }
    $stmt->close();
}

// Build the base query
$query = "SELECT i.inquiry_id, i.name, i.email, i.phone, i.message, i.status, i.created_at, 
                p.property_id, p.title as property_title, p.address, p.city, p.state, 
                u.user_id, u.first_name, u.last_name
          FROM inquiries i
          LEFT JOIN properties p ON i.property_id = p.property_id
          LEFT JOIN users u ON i.user_id = u.user_id";

$countQuery = "SELECT COUNT(*) as total FROM inquiries i
               LEFT JOIN properties p ON i.property_id = p.property_id
               LEFT JOIN users u ON i.user_id = u.user_id";

$whereConditions = [];
$params = [];
$paramTypes = '';

// Add search condition if search is provided
if (!empty($search)) {
    $searchTerm = "%$search%";
    $whereConditions[] = "(i.name LIKE ? OR i.email LIKE ? OR i.phone LIKE ? OR i.message LIKE ? OR p.title LIKE ? OR p.address LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $paramTypes .= 'ssssss';
}

// Add status filter if provided
if (!empty($status_filter)) {
    $whereConditions[] = "i.status = ?";
    $params[] = $status_filter;
    $paramTypes .= 's';
}

// Combine where conditions if any exist
if (!empty($whereConditions)) {
    $query .= " WHERE " . implode(' AND ', $whereConditions);
    $countQuery .= " WHERE " . implode(' AND ', $whereConditions);
}

// Add order by and limit
$query .= " ORDER BY i.created_at DESC LIMIT ?, ?";
$params[] = $offset;
$params[] = $per_page;
$paramTypes .= 'ii';

// Get total inquiries count for pagination
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
$total_inquiries = $countRow['total'];
$total_pages = ceil($total_inquiries / $per_page);

// Get inquiries for current page
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$inquiries = [];
while ($row = $result->fetch_assoc()) {
    $inquiries[] = $row;
}

// Count inquiries by status for statistics
$inquiry_stats = [];
$statusStmt = $conn->prepare("SELECT status, COUNT(*) as count FROM inquiries GROUP BY status");
$statusStmt->execute();
$statusResult = $statusStmt->get_result();
while ($row = $statusResult->fetch_assoc()) {
    $inquiry_stats[$row['status']] = $row['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Inquiries - PrimeEstate</title>
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
                
                <a href="agents.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-user-tie text-indigo-600 w-5"></i>
                    <span class="ml-3">Agents</span>
                </a>
                
                <a href="inquiries.php" class="sidebar-link active flex items-center px-4 py-3 my-1">
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Manage Inquiries</h1>
                
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
            
            <!-- Inquiry Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <!-- Total Inquiries Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-indigo-500 to-indigo-600 text-white fade-in-up">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Total Inquiries</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $total_inquiries; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-envelope text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Status Cards -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-red-500 to-red-600 text-white fade-in-up delay-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">New Inquiries</p>
                            <p class="text-3xl font-bold mt-1"><?php echo isset($inquiry_stats['new']) ? $inquiry_stats['new'] : 0; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-inbox text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-card p-6 bg-gradient-to-br from-amber-500 to-amber-600 text-white fade-in-up delay-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">In Progress</p>
                            <p class="text-3xl font-bold mt-1"><?php echo isset($inquiry_stats['in_progress']) ? $inquiry_stats['in_progress'] : 0; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-card p-6 bg-gradient-to-br from-teal-500 to-teal-600 text-white fade-in-up delay-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Resolved</p>
                            <p class="text-3xl font-bold mt-1"><?php echo (isset($inquiry_stats['responded']) ? $inquiry_stats['responded'] : 0) + (isset($inquiry_stats['closed']) ? $inquiry_stats['closed'] : 0); ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Search and Filter Bar -->
            <div class="dashboard-card p-6 mb-6 fade-in-up delay-300">
                <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search Inquiries</label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-10 pr-12 sm:text-sm border-gray-300 rounded-md"
                                   placeholder="Name, email, message, or property">
                        </div>
                    </div>
                    
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" id="status" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="">All Statuses</option>
                            <option value="new" <?php echo $status_filter === 'new' ? 'selected' : ''; ?>>New</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="responded" <?php echo $status_filter === 'responded' ? 'selected' : ''; ?>>Responded</option>
                            <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-indigo-600 border border-transparent rounded-md shadow-sm py-2 px-4 flex items-center justify-center text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-filter mr-2"></i> Filter Results
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Action buttons -->
            <div class="flex justify-between mb-6">
                <div>
                    <a href="../contact.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-plus mr-2"></i> Contact Form
                    </a>
                </div>
                
                <div class="text-sm text-gray-600">
                    Showing <?php echo count($inquiries); ?> of <?php echo $total_inquiries; ?> inquiries
                </div>
            </div>
            
            <!-- Inquiries Table -->
            <div class="dashboard-card overflow-hidden mb-6 fade-in-up">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Contact Info
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Property
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Message
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($inquiries)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        No inquiries found matching your criteria
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inquiries as $inquiry): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($inquiry['name']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($inquiry['email']); ?></div>
                                            <?php if ($inquiry['phone']): ?>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($inquiry['phone']); ?></div>
                                            <?php endif; ?>
                                            
                                            <?php if ($inquiry['user_id']): ?>
                                                <div class="mt-1">
                                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                        Registered User
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($inquiry['property_id']): ?>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <a href="../property.php?id=<?php echo $inquiry['property_id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                                        <?php echo htmlspecialchars($inquiry['property_title']); ?>
                                                    </a>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo htmlspecialchars($inquiry['address'] . ', ' . $inquiry['city'] . ', ' . $inquiry['state']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-500">General Inquiry</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900 max-w-xs truncate">
                                                <?php echo htmlspecialchars(substr($inquiry['message'], 0, 100)) . (strlen($inquiry['message']) > 100 ? '...' : ''); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php 
                                                $statusClasses = [
                                                    'new' => 'bg-red-100 text-red-800',
                                                    'in_progress' => 'bg-yellow-100 text-yellow-800',
                                                    'responded' => 'bg-blue-100 text-blue-800',
                                                    'closed' => 'bg-green-100 text-green-800'
                                                ];
                                                $class = $statusClasses[$inquiry['status']] ?? 'bg-gray-100 text-gray-800';
                                                $statusLabel = [
                                                    'new' => 'New',
                                                    'in_progress' => 'In Progress',
                                                    'responded' => 'Responded',
                                                    'closed' => 'Closed'
                                                ];
                                                $label = $statusLabel[$inquiry['status']] ?? ucfirst($inquiry['status']);
                                            ?>
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $class; ?>">
                                                <?php echo $label; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('M j, Y', strtotime($inquiry['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="inquiry-view.php?id=<?php echo $inquiry['inquiry_id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button onclick="openStatusModal(<?php echo $inquiry['inquiry_id']; ?>, '<?php echo $inquiry['status']; ?>')" class="text-blue-600 hover:text-blue-900 mr-3">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this inquiry? This action cannot be undone.')">
                                                <input type="hidden" name="inquiry_id" value="<?php echo $inquiry['inquiry_id']; ?>">
                                                <button type="submit" name="delete_inquiry" class="text-red-600 hover:text-red-900">
    <i class="fas fa-trash"></i>
</button>
                                            </form>
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
                <div class="flex items-center justify-between mt-6">
                    <div class="flex-1 flex justify-between sm:hidden">
                        <?php if ($current_page > 1): ?>
                            <a href="?page=<?php echo $current_page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?page=<?php echo $current_page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Next
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700">
                                Showing <span class="font-medium"><?php echo ($current_page - 1) * $per_page + 1; ?></span> to 
                                <span class="font-medium"><?php echo min($current_page * $per_page, $total_inquiries); ?></span> of 
                                <span class="font-medium"><?php echo $total_inquiries; ?></span> results
                            </p>
                        </div>
                        
                        <div>
                            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                <?php if ($current_page > 1): ?>
                                    <a href="?page=<?php echo $current_page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <span class="sr-only">Previous</span>
                                        <i class="fas fa-chevron-left h-5 w-5"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php 
                                    // Calculate range of pages to show
                                    $range = 2; // Show 2 pages before and after current page
                                    $start_page = max(1, $current_page - $range);
                                    $end_page = min($total_pages, $current_page + $range);
                                    
                                    // Always show first page
                                    if ($start_page > 1) {
                                        echo '<a href="?page=1' . (!empty($search) ? '&search=' . urlencode($search) : '') . (!empty($status_filter) ? '&status=' . urlencode($status_filter) : '') . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>';
                                        
                                        if ($start_page > 2) {
                                            echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                                        }
                                    }
                                    
                                    // Loop through the range of pages
                                    for ($i = $start_page; $i <= $end_page; $i++) {
                                        $active_class = $i === $current_page ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50';
                                        
                                        echo '<a href="?page=' . $i . (!empty($search) ? '&search=' . urlencode($search) : '') . (!empty($status_filter) ? '&status=' . urlencode($status_filter) : '') . '" class="relative inline-flex items-center px-4 py-2 border ' . $active_class . ' text-sm font-medium">' . $i . '</a>';
                                    }
                                    
                                    // Always show last page
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) {
                                            echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                                        }
                                        
                                        echo '<a href="?page=' . $total_pages . (!empty($search) ? '&search=' . urlencode($search) : '') . (!empty($status_filter) ? '&status=' . urlencode($status_filter) : '') . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">' . $total_pages . '</a>';
                                    }
                                ?>
                                
                                <?php if ($current_page < $total_pages): ?>
                                    <a href="?page=<?php echo $current_page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <span class="sr-only">Next</span>
                                        <i class="fas fa-chevron-right h-5 w-5"></i>
                                    </a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Update Status Modal -->
    <div id="statusModal" class="fixed inset-0 z-50 overflow-y-auto hidden bg-gray-900 bg-opacity-50">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
                <button type="button" onclick="closeStatusModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times text-xl"></i>
                </button>
                
                <h3 class="text-lg font-medium text-gray-900 mb-4">Update Inquiry Status</h3>
                
                <form id="updateStatusForm" action="" method="POST">
                    <input type="hidden" id="inquiry_id_input" name="inquiry_id" value="">
                    
                    <div class="mb-4">
                        <label for="status_select" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select id="status_select" name="status" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="new">New</option>
                            <option value="in_progress">In Progress</option>
                            <option value="responded">Responded</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="button" onclick="closeStatusModal()" class="mr-3 px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Cancel
                        </button>
                        <button type="submit" name="update_status" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.add('translate-x-0');
            } else {
                sidebar.classList.remove('translate-x-0');
                sidebar.classList.add('-translate-x-full');
            }
        });
        
        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            
            if (!sidebar.contains(event.target) && !mobileMenuButton.contains(event.target) && !sidebar.classList.contains('-translate-x-full') && window.innerWidth < 768) {
                sidebar.classList.remove('translate-x-0');
                sidebar.classList.add('-translate-x-full');
            }
        });
        
        // Status modal functions
        function openStatusModal(inquiryId, currentStatus) {
            document.getElementById('inquiry_id_input').value = inquiryId;
            document.getElementById('status_select').value = currentStatus;
            document.getElementById('statusModal').classList.remove('hidden');
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
        }
        
        // Close modal when clicking outside
        document.getElementById('statusModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeStatusModal();
            }
        });
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('[role="alert"]');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 1s ease-out';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.style.display = 'none';
                }, 1000);
            });
        }, 5000);
    </script>
</body>
</html>