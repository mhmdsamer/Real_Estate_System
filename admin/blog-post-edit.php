<?php
require_once '../connection.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Initialize variables
$post_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error_message = '';
$success_message = '';

// Check if post exists
if ($post_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM blog_posts WHERE post_id = ?");
    $stmt->bind_param('i', $post_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header('Location: blog.php');
        exit();
    }
    
    $post = $result->fetch_assoc();
    $stmt->close();
    
    // Get post categories
    $stmt = $conn->prepare("SELECT category_id FROM post_has_categories WHERE post_id = ?");
    $stmt->bind_param('i', $post_id);
    $stmt->execute();
    $category_result = $stmt->get_result();
    $post_categories = [];
    
    while ($row = $category_result->fetch_assoc()) {
        $post_categories[] = $row['category_id'];
    }
    $stmt->close();
} else {
    header('Location: blog.php');
    exit();
}

// Get all categories
$categories = [];
$categoryStmt = $conn->prepare("SELECT category_id, name FROM blog_categories ORDER BY name");
$categoryStmt->execute();
$categoryResult = $categoryStmt->get_result();

while ($row = $categoryResult->fetch_assoc()) {
    $categories[] = $row;
}
$categoryStmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_post'])) {
    // Get form data
    $title = trim($_POST['title']);
    $slug = trim($_POST['slug']);
    $content = $_POST['content'];
    $excerpt = trim($_POST['excerpt']);
    $status = $_POST['status'];
    $selected_categories = isset($_POST['categories']) ? $_POST['categories'] : [];
    
    // Generate slug if empty
    if (empty($slug)) {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
    }
    
    // Validate inputs
    if (empty($title)) {
        $error_message = "Post title is required.";
    } elseif (empty($content)) {
        $error_message = "Post content is required.";
    } else {
        // Check if slug is unique (excluding current post)
        $slug_check = $conn->prepare("SELECT post_id FROM blog_posts WHERE slug = ? AND post_id != ?");
        $slug_check->bind_param('si', $slug, $post_id);
        $slug_check->execute();
        $slug_result = $slug_check->get_result();
        
        if ($slug_result->num_rows > 0) {
            $error_message = "URL slug already exists. Please choose a different one.";
        } else {
            // Begin transaction
            $conn->begin_transaction();
            
            try {
                // Set published_at if status is changed to published
                $published_at = null;
                if ($status === 'published' && $post['status'] !== 'published') {
                    $published_at = date('Y-m-d H:i:s');
                } elseif ($status === 'published' && $post['status'] === 'published') {
                    $published_at = $post['published_at'];
                }
                
                // Update blog post
                $update_stmt = $conn->prepare("
                    UPDATE blog_posts 
                    SET title = ?, slug = ?, content = ?, excerpt = ?, status = ?, published_at = ?
                    WHERE post_id = ?
                ");
                $update_stmt->bind_param('ssssssi', $title, $slug, $content, $excerpt, $status, $published_at, $post_id);
                $update_stmt->execute();
                
                // Delete existing category relationships
                $delete_cat_stmt = $conn->prepare("DELETE FROM post_has_categories WHERE post_id = ?");
                $delete_cat_stmt->bind_param('i', $post_id);
                $delete_cat_stmt->execute();
                
                // Insert new category relationships
                if (!empty($selected_categories)) {
                    $insert_cat_stmt = $conn->prepare("INSERT INTO post_has_categories (post_id, category_id) VALUES (?, ?)");
                    
                    foreach ($selected_categories as $category_id) {
                        $insert_cat_stmt->bind_param('ii', $post_id, $category_id);
                        $insert_cat_stmt->execute();
                    }
                    
                    $insert_cat_stmt->close();
                }
                
                // Commit transaction
                $conn->commit();
                
                $success_message = "Blog post updated successfully.";
                
                // Refresh post data
                $stmt = $conn->prepare("SELECT * FROM blog_posts WHERE post_id = ?");
                $stmt->bind_param('i', $post_id);
                $stmt->execute();
                $post = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                // Refresh post categories
                $stmt = $conn->prepare("SELECT category_id FROM post_has_categories WHERE post_id = ?");
                $stmt->bind_param('i', $post_id);
                $stmt->execute();
                $category_result = $stmt->get_result();
                $post_categories = [];
                
                while ($row = $category_result->fetch_assoc()) {
                    $post_categories[] = $row['category_id'];
                }
                $stmt->close();
                
            } catch (Exception $e) {
                // Rollback on error
                $conn->rollback();
                $error_message = "Error updating blog post: " . $e->getMessage();
            }
        }
    }
}

// Handle featured image upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_image'])) {
    // Check if image was uploaded without error
    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        // Validate file type and size
        if (!in_array($_FILES['featured_image']['type'], $allowed_types)) {
            $error_message = "Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.";
        } elseif ($_FILES['featured_image']['size'] > $max_size) {
            $error_message = "File size too large. Maximum size is 2MB.";
        } else {
            // Create upload directory if it doesn't exist
            $upload_dir = '../uploads/blog/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate unique filename
            $file_ext = pathinfo($_FILES['featured_image']['name'], PATHINFO_EXTENSION);
            $filename = 'post_' . $post_id . '_' . time() . '.' . $file_ext;
            $target_file = $upload_dir . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $target_file)) {
                // Delete old featured image if exists
                if (!empty($post['featured_image']) && file_exists('../' . $post['featured_image'])) {
                    unlink('../' . $post['featured_image']);
                }
                
                // Update database with new image path
                $image_path = 'uploads/blog/' . $filename;
                $update_img_stmt = $conn->prepare("UPDATE blog_posts SET featured_image = ? WHERE post_id = ?");
                $update_img_stmt->bind_param('si', $image_path, $post_id);
                
                if ($update_img_stmt->execute()) {
                    $success_message = "Featured image uploaded successfully.";
                    // Update post data
                    $post['featured_image'] = $image_path;
                } else {
                    $error_message = "Error updating featured image in database.";
                }
                
                $update_img_stmt->close();
            } else {
                $error_message = "Error uploading image.";
            }
        }
    } else {
        $error_message = "No image uploaded or upload error occurred.";
    }
}

// Remove featured image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_image'])) {
    if (!empty($post['featured_image'])) {
        // Delete file if exists
        if (file_exists('../' . $post['featured_image'])) {
            unlink('../' . $post['featured_image']);
        }
        
        // Update database
        $update_stmt = $conn->prepare("UPDATE blog_posts SET featured_image = NULL WHERE post_id = ?");
        $update_stmt->bind_param('i', $post_id);
        
        if ($update_stmt->execute()) {
            $success_message = "Featured image removed successfully.";
            $post['featured_image'] = null;
        } else {
            $error_message = "Error removing featured image from database.";
        }
        
        $update_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Blog Post - PrimeEstate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
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
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            tinymce.init({
                selector: '#content',
                height: 500,
                menubar: true,
                plugins: [
                    'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                    'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                    'insertdatetime', 'media', 'table', 'help', 'wordcount'
                ],
                toolbar: 'undo redo | formatselect | ' +
                    'bold italic backcolor | alignleft aligncenter ' +
                    'alignright alignjustify | bullist numlist outdent indent | ' +
                    'removeformat | link image media | help',
                content_style: 'body { font-family: "Nunito", sans-serif; font-size: 16px }'
            });
            
            // Generate slug from title
            document.getElementById('title').addEventListener('blur', function() {
                const titleInput = this.value.trim();
                const slugInput = document.getElementById('slug');
                
                if (titleInput && (!slugInput.value || !slugInput.dataset.manually_edited)) {
                    const slug = titleInput
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/^-+|-+$/g, '');
                    
                    slugInput.value = slug;
                }
            });
            
            // Track if slug was manually edited
            document.getElementById('slug').addEventListener('input', function() {
                this.dataset.manually_edited = 'true';
            });
            
            // Preview thumbnail image
            document.getElementById('featured_image').addEventListener('change', function() {
                const file = this.files[0];
                const preview = document.getElementById('image_preview');
                const previewContainer = document.getElementById('image_preview_container');
                
                if (file) {
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        preview.src = e.target.result;
                        previewContainer.classList.remove('hidden');
                    }
                    
                    reader.readAsDataURL(file);
                }
            });
        });
    </script>
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Edit Blog Post</h1>
                
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
            
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <a href="blog.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-indigo-600 bg-white hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Blog Posts
                    </a>
                </div>
                
                <div>
                    <a href="../blog/<?php echo htmlspecialchars($post['slug']); ?>" target="_blank" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-eye mr-2"></i> View Post
                    </a>
                </div>
            </div>
            
            <!-- Post Edit Form -->
            <div class="dashboard-card p-6 mb-6">
                <h2 class="text-xl font-semibold mb-6">Edit Blog Post</h2>
                
                <form action="" method="POST">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Main Content Column -->
                        <div class="md:col-span-2">
                            <div class="mb-6">
                                <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Post Title</label>
                                <input type="text" name="title" id="title" required
                                    class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
                                    value="<?php echo htmlspecialchars($post['title']); ?>">
                            </div>
                            
                            <div class="mb-6">
                                <label for="slug" class="block text-sm font-medium text-gray-700 mb-1">URL Slug</label>
                                <div class="flex rounded-md shadow-sm">
                                    <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-500 sm:text-sm">
                                        /blog/
                                    </span>
                                    <input type="text" name="slug" id="slug" 
                                        class="flex-1 min-w-0 block w-full px-3 py-2 rounded-none rounded-r-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm border-gray-300"
                                        value="<?php echo htmlspecialchars($post['slug']); ?>">
                                </div>
                                <p class="mt-1 text-xs text-gray-500">The URL slug must be unique. Leave blank to auto-generate from title.</p>
                            </div>
                            
                            <div class="mb-6">
                                <label for="content" class="block text-sm font-medium text-gray-700 mb-1">Content</label>
                                <textarea name="content" id="content" rows="20" 
                                    class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
                                ><?php echo htmlspecialchars($post['content']); ?></textarea>
                            </div>
                            
                            <div class="mb-6">
                                <label for="excerpt" class="block text-sm font-medium text-gray-700 mb-1">Excerpt</label>
                                <textarea name="excerpt" id="excerpt" rows="3" 
                                    class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
                                ><?php echo htmlspecialchars($post['excerpt']); ?></textarea>
                                <p class="mt-1 text-xs text-gray-500">A short summary of the post to display on blog listing pages.</p>
                            </div>
                        </div>
                        
                        <!-- Sidebar Column -->
                        <div class="md:col-span-1">
                            <div class="mb-6">
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Post Status</label>
                                <select name="status" id="status" 
                                    class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                    <option value="draft" <?php echo $post['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="published" <?php echo $post['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                                    <option value="archived" <?php echo $post['status'] === 'archived' ? 'selected' : ''; ?>>Archived</option>
                                </select>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Post Information</label>
                                <div class="bg-gray-50 rounded-md p-4 text-sm">
                                    <p class="mb-2">
                                        <span class="font-medium">Created:</span> 
                                        <?php echo date('M j, Y \a\t g:i a', strtotime($post['created_at'])); ?>
                                    </p>
                                    
                                    <?php if ($post['updated_at'] !== $post['created_at']): ?>
                                    <p class="mb-2">
                                        <span class="font-medium">Last Updated:</span> 
                                        <?php echo date('M j, Y \a\t g:i a', strtotime($post['updated_at'])); ?>
                                    </p>
                                    <?php endif; ?>
                                    
                                    <?php if ($post['status'] === 'published' && $post['published_at']): ?>
                                    <p>
                                        <span class="font-medium">Published:</span> 
                                        <?php echo date('M j, Y \a\t g:i a', strtotime($post['published_at'])); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Categories</label>
                                <div class="bg-white rounded-md border border-gray-300 p-4 max-h-60 overflow-y-auto">
                                    <?php if (count($categories) > 0): ?>
                                        <?php foreach ($categories as $category): ?>
                                            <div class="flex items-center mb-2">
                                                <input type="checkbox" id="category_<?php echo $category['category_id']; ?>" 
                                                    name="categories[]" value="<?php echo $category['category_id']; ?>"
                                                    <?php echo in_array($category['category_id'], $post_categories) ? 'checked' : ''; ?>
                                                    class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                                <label for="category_<?php echo $category['category_id']; ?>" class="ml-2 block text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($category['name']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-gray-500 text-sm">No categories found.</p>
                                    <?php endif; ?>
                                </div>
                                <p class="mt-2 text-xs text-gray-500">
                                    <a href="blog-categories.php" class="text-indigo-600 hover:text-indigo-500">
                                        Manage categories
                                    </a></p>
                            </div>
                            
                            <div class="mt-6">
                                <button type="submit" name="update_post" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    Update Post
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Featured Image Section -->
            <div class="dashboard-card p-6">
                <h2 class="text-xl font-semibold mb-6">Featured Image</h2>
                
                <?php if (!empty($post['featured_image'])): ?>
                    <div class="mb-6">
                        <div class="w-full rounded-lg overflow-hidden shadow-md">
                            <img src="../<?php echo htmlspecialchars($post['featured_image']); ?>" alt="Featured Image" class="w-full h-auto">
                        </div>
                        <form action="" method="POST" class="mt-4">
                            <button type="submit" name="remove_image" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                <i class="fas fa-trash-alt mr-2"></i> Remove Image
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
                
                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="space-y-6">
                        <div>
                            <label for="featured_image" class="block text-sm font-medium text-gray-700">
                                Upload New Image
                            </label>
                            <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                                <div class="space-y-1 text-center">
                                    <div class="mx-auto h-12 w-12 text-gray-400">
                                        <i class="fas fa-image text-3xl"></i>
                                    </div>
                                    <div class="flex text-sm text-gray-600">
                                        <label for="featured_image" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                            <span>Upload a file</span>
                                            <input id="featured_image" name="featured_image" type="file" class="sr-only" accept="image/*">
                                        </label>
                                        <p class="pl-1">or drag and drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500">
                                        PNG, JPG, GIF, WEBP up to 2MB
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <div id="image_preview_container" class="hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Preview</label>
                            <div class="w-full rounded-lg overflow-hidden shadow-md">
                                <img id="image_preview" src="#" alt="Preview" class="w-full h-auto">
                            </div>
                        </div>
                        
                        <div>
                            <button type="submit" name="upload_image" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <i class="fas fa-upload mr-2"></i> Upload Image
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>
    
    <!-- JavaScript for mobile menu functionality -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mobileMenuBtn = document.getElementById('mobile-menu-button');
            
            mobileMenuBtn.addEventListener('click', function() {
                if (sidebar.classList.contains('-translate-x-full')) {
                    sidebar.classList.remove('-translate-x-full');
                    sidebar.classList.add('translate-x-0');
                } else {
                    sidebar.classList.add('-translate-x-full');
                    sidebar.classList.remove('translate-x-0');
                }
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnMobileMenuBtn = mobileMenuBtn.contains(event.target);
                
                if (!isClickInsideSidebar && !isClickOnMobileMenuBtn && !sidebar.classList.contains('-translate-x-full') && window.innerWidth < 768) {
                    sidebar.classList.add('-translate-x-full');
                    sidebar.classList.remove('translate-x-0');
                }
            });
        });
    </script>
</body>
</html>