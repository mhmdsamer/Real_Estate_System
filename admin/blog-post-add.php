<?php
require_once '../connection.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Get all categories for the form dropdown
$categories = [];
$categoryStmt = $conn->prepare("SELECT category_id, name FROM blog_categories ORDER BY name");
$categoryStmt->execute();
$categoryResult = $categoryStmt->get_result();
while ($row = $categoryResult->fetch_assoc()) {
    $categories[] = $row;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize inputs
    $title = trim($_POST['title']);
    $content = $_POST['content'];
    $excerpt = trim($_POST['excerpt']);
    $status = $_POST['status'];
    $author_id = $_SESSION['user_id']; // Current logged in admin
    $selected_categories = isset($_POST['categories']) ? $_POST['categories'] : [];
    
    // Generate slug from title
    $slug = strtolower(str_replace(' ', '-', preg_replace('/[^A-Za-z0-9\-\s]/', '', $title)));
    
    // Check if slug already exists
    $slugCheckStmt = $conn->prepare("SELECT post_id FROM blog_posts WHERE slug = ?");
    $slugCheckStmt->bind_param('s', $slug);
    $slugCheckStmt->execute();
    $slugCheckResult = $slugCheckStmt->get_result();
    
    if ($slugCheckResult->num_rows > 0) {
        // Slug exists, append a timestamp to make it unique
        $slug .= '-' . time();
    }
    
    // Set published_at date if status is published
    $published_at = null;
    if ($status === 'published') {
        $published_at = date('Y-m-d H:i:s');
    }
    
    // Handle featured image upload if provided
    $featured_image = null;
    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/blog/';
        
        // Create directory if it doesn't exist
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileName = basename($_FILES['featured_image']['name']);
        $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
        $newFileName = 'post-' . time() . '-' . rand(1000, 9999) . '.' . $fileExtension;
        $uploadFile = $uploadDir . $newFileName;
        
        // List of allowed file types
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array(strtolower($fileExtension), $allowedTypes)) {
            if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $uploadFile)) {
                $featured_image = str_replace('../', '', $uploadFile); // Store relative path
            } else {
                $upload_error = "Failed to upload image. Please try again.";
            }
        } else {
            $upload_error = "Invalid file type. Allowed types: JPG, JPEG, PNG, GIF, WEBP";
        }
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert blog post
        $stmt = $conn->prepare("INSERT INTO blog_posts (author_id, title, slug, content, featured_image, excerpt, status, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssssss', $author_id, $title, $slug, $content, $featured_image, $excerpt, $status, $published_at);
        
        if ($stmt->execute()) {
            $post_id = $conn->insert_id;
            
            // Insert categories
            if (!empty($selected_categories)) {
                $categoryInsertStmt = $conn->prepare("INSERT INTO post_has_categories (post_id, category_id) VALUES (?, ?)");
                
                foreach ($selected_categories as $category_id) {
                    $categoryInsertStmt->bind_param('ii', $post_id, $category_id);
                    $categoryInsertStmt->execute();
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            // Redirect to the blog management page with success message
            $_SESSION['success_message'] = "Blog post created successfully.";
            header('Location: blog.php');
            exit();
        } else {
            throw new Exception("Failed to create blog post.");
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error_message = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Blog Post - PrimeEstate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <!-- TinyMCE for rich text editor -->
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        tinymce.init({
            selector: 'textarea#content',
            height: 500,
            plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount',
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat',
            images_upload_url: '../upload-handler.php',
            automatic_uploads: true,
            file_picker_types: 'image',
            file_picker_callback: function (cb, value, meta) {
                var input = document.createElement('input');
                input.setAttribute('type', 'file');
                input.setAttribute('accept', 'image/*');

                input.onchange = function () {
                    var file = this.files[0];
                    var reader = new FileReader();
                    reader.onload = function () {
                        var id = 'blobid' + (new Date()).getTime();
                        var blobCache =  tinymce.activeEditor.editorUpload.blobCache;
                        var base64 = reader.result.split(',')[1];
                        var blobInfo = blobCache.create(id, file, base64);
                        blobCache.add(blobInfo);
                        cb(blobInfo.blobUri(), { title: file.name });
                    };
                    reader.readAsDataURL(file);
                };
                input.click();
            }
        });
    </script>
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
                
                <h1 class="text-xl md:text-2xl font-bold text-gray-800 mx-auto md:mx-0">Add New Blog Post</h1>
                
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
            <?php if(isset($error_message)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if(isset($upload_error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $upload_error; ?></p>
                </div>
            <?php endif; ?>
            
            <div class="dashboard-card p-6 mb-6">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" enctype="multipart/form-data">
                    <!-- Title -->
                    <div class="mb-6">
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Post Title</label>
                        <input type="text" name="title" id="title" required
                               class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                               value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                    </div>
                    
                    <!-- Content -->
                    <div class="mb-6">
                        <label for="content" class="block text-sm font-medium text-gray-700 mb-1">Content</label>
                        <textarea name="content" id="content" rows="15"
                                  class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"><?php echo isset($_POST['content']) ? htmlspecialchars($_POST['content']) : ''; ?></textarea>
                    </div>
                    
                    <!-- Featured Image -->
                    <div class="mb-6">
                        <label for="featured_image" class="block text-sm font-medium text-gray-700 mb-1">Featured Image</label>
                        <div class="mt-1 flex items-center">
                            <span class="inline-block h-12 w-12 rounded-md overflow-hidden bg-gray-100">
                                <svg class="h-full w-full text-gray-300" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M24 20.993V24H0v-2.996A14.977 14.977 0 0112.004 15c4.904 0 9.26 2.354 11.996 5.993zM16.002 8.999a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                            </span>
                            <input type="file" name="featured_image" id="featured_image" accept="image/*"
                                   class="ml-5 bg-white py-2 px-3 border border-gray-300 rounded-md shadow-sm text-sm leading-4 font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        </div>
                        <p class="mt-1 text-sm text-gray-500">
                            Recommended size: 1200×800 pixels. Maximum file size: 2MB.
                        </p>
                    </div>
                    
                    <!-- Excerpt -->
                    <div class="mb-6">
                        <label for="excerpt" class="block text-sm font-medium text-gray-700 mb-1">Excerpt</label>
                        <textarea name="excerpt" id="excerpt" rows="3"
                                  class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                  placeholder="Brief summary of the blog post..."><?php echo isset($_POST['excerpt']) ? htmlspecialchars($_POST['excerpt']) : ''; ?></textarea>
                        <p class="mt-1 text-sm text-gray-500">
                            A short excerpt of the blog post used in listings and search results. Max 255 characters.
                        </p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Categories -->
                        <div>
                            <label for="categories" class="block text-sm font-medium text-gray-700 mb-1">Categories</label>
                            <div class="mt-1 grid grid-cols-2 gap-2">
                                <?php foreach($categories as $category): ?>
                                    <div class="flex items-start">
                                        <div class="flex items-center h-5">
                                            <input type="checkbox" name="categories[]" id="category-<?php echo $category['category_id']; ?>" value="<?php echo $category['category_id']; ?>"
                                                   class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
                                                   <?php echo (isset($_POST['categories']) && in_array($category['category_id'], $_POST['categories'])) ? 'checked' : ''; ?>>
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="category-<?php echo $category['category_id']; ?>" class="font-medium text-gray-700">
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-2 flex items-center">
                                <i class="fas fa-plus-circle text-indigo-600 mr-1"></i>
                                <a href="blog-categories.php" class="text-sm text-indigo-600 hover:text-indigo-900">Add new category</a>
                            </div>
                        </div>
                        
                        <!-- Status -->
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" id="status"
                                    class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                <option value="draft" <?php echo (isset($_POST['status']) && $_POST['status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                <option value="published" <?php echo (isset($_POST['status']) && $_POST['status'] === 'published') ? 'selected' : ''; ?>>Published</option>
                                <option value="archived" <?php echo (isset($_POST['status']) && $_POST['status'] === 'archived') ? 'selected' : ''; ?>>Archived</option>
                            </select>
                            <p class="mt-1 text-sm text-gray-500">
                                Only published posts will be visible on the website.
                            </p>
                        </div>
                    </div>
                    
                    <!-- Buttons -->
                    <div class="flex justify-end space-x-3">
                        <a href="blog.php" class="inline-flex justify-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Cancel
                        </a>
                        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Create Post
                        </button>
                    </div>
                </form>
            </div>
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
        
        // Preview feature for featured image
        document.getElementById('featured_image').addEventListener('change', function(event) {
            const fileInput = event.target;
            const file = fileInput.files[0];
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const imgContainer = fileInput.parentElement.querySelector('span');
                    imgContainer.innerHTML = `<img src="${e.target.result}" class="h-full w-full object-cover" alt="Preview">`;
                };
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>