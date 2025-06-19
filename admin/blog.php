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
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($current_page - 1) * $per_page;

// Handle delete action
if (isset($_POST['delete_post']) && isset($_POST['post_id'])) {
    $post_id = (int)$_POST['post_id'];
    
    $stmt = $conn->prepare("DELETE FROM blog_posts WHERE post_id = ?");
    $stmt->bind_param('i', $post_id);
    if ($stmt->execute()) {
        $success_message = "Blog post deleted successfully.";
    } else {
        $error_message = "Error deleting blog post: " . $conn->error;
    }
    $stmt->close();
}

// Build the base query
$query = "SELECT bp.post_id, bp.title, bp.slug, bp.excerpt, bp.status, 
                 bp.published_at, bp.created_at, bp.updated_at,
                 u.first_name, u.last_name,
                 (SELECT GROUP_CONCAT(bc.name SEPARATOR ', ') 
                  FROM post_has_categories phc 
                  JOIN blog_categories bc ON phc.category_id = bc.category_id 
                  WHERE phc.post_id = bp.post_id) as categories
          FROM blog_posts bp
          JOIN users u ON bp.author_id = u.user_id";

$countQuery = "SELECT COUNT(*) as total FROM blog_posts bp";

$whereConditions = [];
$params = [];
$paramTypes = '';

// Add search condition if search is provided
if (!empty($search)) {
    $searchTerm = "%$search%";
    $whereConditions[] = "(bp.title LIKE ? OR bp.content LIKE ? OR bp.excerpt LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $paramTypes .= 'sss';
}

// Add category filter if provided
if (!empty($category_filter)) {
    $countQuery .= " JOIN post_has_categories phc ON bp.post_id = phc.post_id";
    $whereConditions[] = "phc.category_id = ?";
    $params[] = $category_filter;
    $paramTypes .= 'i';
}

// Add status filter if provided
if (!empty($status_filter)) {
    $whereConditions[] = "bp.status = ?";
    $params[] = $status_filter;
    $paramTypes .= 's';
}

// Combine where conditions if any exist
if (!empty($whereConditions)) {
    $query .= " WHERE " . implode(' AND ', $whereConditions);
    $countQuery .= " WHERE " . implode(' AND ', $whereConditions);
}

// Add order by and limit
$query .= " ORDER BY bp.created_at DESC LIMIT ?, ?";
$params[] = $offset;
$params[] = $per_page;
$paramTypes .= 'ii';

// Get total posts count for pagination
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
$total_posts = $countRow['total'];
$total_pages = ceil($total_posts / $per_page);

// Get blog posts for current page
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$posts = [];
while ($row = $result->fetch_assoc()) {
    $posts[] = $row;
}

// Count posts by status for statistics
$post_stats = [];
$statusStmt = $conn->prepare("SELECT status, COUNT(*) as count FROM blog_posts GROUP BY status");
$statusStmt->execute();
$statusResult = $statusStmt->get_result();
while ($row = $statusResult->fetch_assoc()) {
    $post_stats[$row['status']] = $row['count'];
}

// Get all categories for filter dropdown
$categories = [];
$categoryStmt = $conn->prepare("SELECT category_id, name FROM blog_categories ORDER BY name");
$categoryStmt->execute();
$categoryResult = $categoryStmt->get_result();
while ($row = $categoryResult->fetch_assoc()) {
    $categories[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Blog Posts - PrimeEstate</title>
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
                
                <a href="inquiries.php" class="sidebar-link flex items-center px-4 py-3 my-1">
                    <i class="fas fa-envelope text-indigo-600 w-5"></i>
                    <span class="ml-3">Inquiries</span>
                </a>
                
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider ml-4 mt-6 mb-2">Content</p>
                
                <a href="blog.php" class="sidebar-link active flex items-center px-4 py-3 my-1">
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Manage Blog Posts</h1>
                
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
            
            <!-- Blog Post Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Total Posts Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-indigo-500 to-indigo-600 text-white fade-in-up">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Total Posts</p>
                            <p class="text-3xl font-bold mt-1"><?php echo $total_posts; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-blog text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Published Posts Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-green-500 to-green-600 text-white fade-in-up delay-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Published Posts</p>
                            <p class="text-3xl font-bold mt-1"><?php echo isset($post_stats['published']) ? $post_stats['published'] : 0; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Draft Posts Card -->
                <div class="dashboard-card p-6 bg-gradient-to-br from-yellow-500 to-yellow-600 text-white fade-in-up delay-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">Draft Posts</p>
                            <p class="text-3xl font-bold mt-1"><?php echo isset($post_stats['draft']) ? $post_stats['draft'] : 0; ?></p>
                        </div>
                        <div class="rounded-full p-3 bg-white bg-opacity-20">
                            <i class="fas fa-pencil-alt text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Search and Filter Bar -->
            <div class="dashboard-card p-6 mb-6 fade-in-up delay-300">
                <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search Posts</label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-10 pr-12 sm:text-sm border-gray-300 rounded-md"
                                   placeholder="Title, content or excerpt">
                        </div>
                    </div>
                    
                    <div>
                        <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                        <select name="category" id="category" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['category_id']; ?>" <?php echo $category_filter == $category['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" id="status" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                            <option value="">All Statuses</option>
                            <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="published" <?php echo $status_filter === 'published' ? 'selected' : ''; ?>>Published</option>
                            <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
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
                    <a href="blog-post-add.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-plus mr-2"></i> Add New Post
                    </a>
                    <a href="blog-categories.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-indigo-600 bg-white border-indigo-600 hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 ml-2">
                        <i class="fas fa-tags mr-2"></i> Manage Categories
                    </a>
                </div>
                
                <div class="text-sm text-gray-600">
                    Showing <?php echo count($posts); ?> of <?php echo $total_posts; ?> posts
                </div>
            </div>
            
            <!-- Blog Posts Table -->
            <div class="dashboard-card overflow-hidden mb-6 fade-in-up">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Title
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Author
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Categories
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
                            <?php if (empty($posts)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        No blog posts found matching your criteria
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($posts as $post): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="ml-0">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($post['title']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php 
                                                            $excerpt = $post['excerpt'] ? htmlspecialchars(substr($post['excerpt'], 0, 60)) . (strlen($post['excerpt']) > 60 ? '...' : '') : 'No excerpt';
                                                            echo $excerpt;
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($post['first_name'] . ' ' . $post['last_name']); ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if (!empty($post['categories'])): ?>
                                                <?php 
                                                    $categories = explode(', ', $post['categories']);
                                                    foreach ($categories as $category): 
                                                ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 mr-1 mb-1">
                                                        <?php echo htmlspecialchars($category); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-sm text-gray-500">No categories</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php 
                                                $statusClasses = [
                                                    'published' => 'bg-green-100 text-green-800',
                                                    'draft' => 'bg-yellow-100 text-yellow-800',
                                                    'archived' => 'bg-gray-100 text-gray-800'
                                                ];
                                                $class = $statusClasses[$post['status']] ?? 'bg-gray-100 text-gray-800';
                                            ?>
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $class; ?>">
                                                <?php echo ucfirst($post['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php 
                                                if ($post['status'] === 'published' && $post['published_at']) {
                                                    echo 'Published: ' . date('M j, Y', strtotime($post['published_at']));
                                                } else {
                                                    echo 'Created: ' . date('M j, Y', strtotime($post['created_at']));
                                                }
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="../blog/<?php echo $post['slug']; ?>" target="_blank" class="text-green-600 hover:text-green-900 mr-3" title="View Post">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="blog-post-edit.php?id=<?php echo $post['post_id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3" title="Edit Post">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this post? This action cannot be undone.')">
                                                <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                                <button type="submit" name="delete_post" class="text-red-600 hover:text-red-900" title="Delete Post">
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
                <div class="flex items-center justify-between border-t border-gray-200 px-4 py-3 sm:px-6">
                    <div class="flex-1 flex justify-between sm:hidden">
                        <?php if ($current_page > 1): ?>
                            <a href="?page=<?php echo $current_page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($category_filter) ? '&category=' . urlencode($category_filter) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?page=<?php echo $current_page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($category_filter) ? '&category=' . urlencode($category_filter) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>" 
                               class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Next
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700">
                                Showing <span class="font-medium"><?php echo min(($current_page - 1) * $per_page + 1, $total_posts); ?></span> to 
                                <span class="font-medium"><?php echo min($current_page * $per_page, $total_posts); ?></span> of 
                                <span class="font-medium"><?php echo $total_posts; ?></span> results
                            </p>
                        </div>
                        
                        <div>
                            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                <?php if ($current_page > 1): ?>
                                    <a href="?page=<?php echo $current_page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($category_filter) ? '&category=' . urlencode($category_filter) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>" 
                                       class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <span class="sr-only">Previous</span>
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php 
                                    $start_page = max(1, $current_page - 2);
                                    $end_page = min($total_pages, $current_page + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++): 
                                ?>
                                    <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($category_filter) ? '&category=' . urlencode($category_filter) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>" 
                                       class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $i === $current_page ? 'text-indigo-600 bg-indigo-50 z-10' : 'text-gray-500 hover:bg-gray-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($current_page < $total_pages): ?>
                                    <a href="?page=<?php echo $current_page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($category_filter) ? '&category=' . urlencode($category_filter) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>" 
                                       class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <span class="sr-only">Next</span>
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
        
        <!-- Footer -->
        <footer class="mt-auto py-4 px-6 border-t border-gray-200">
            <div class="flex justify-between items-center">
                <p class="text-sm text-gray-500">
                    &copy; <?php echo date('Y'); ?> PrimeEstate. All rights reserved.
                </p>
                <div class="text-sm text-gray-500">
                    Version 1.0.0
                </div>
            </div>
        </footer>
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
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            
            if (window.innerWidth < 768 && 
                !sidebar.contains(event.target) && 
                !mobileMenuButton.contains(event.target) &&
                !sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.remove('translate-x-0');
                sidebar.classList.add('-translate-x-full');
            }
        });
        
        // Animation delay for cards
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.dashboard-card');
            cards.forEach((card, index) => {
                setTimeout(() => {
                    card.classList.add('fade-in-up');
                }, index * 100);
            });
        });
    </script>
</body>
</html>