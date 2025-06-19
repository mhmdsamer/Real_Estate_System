<?php
require_once 'connection.php';
session_start();

// Check if user is logged in
$logged_in = isset($_SESSION['user_id']);
$user_type = $logged_in ? $_SESSION['user_type'] : '';
$user_name = $logged_in ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : '';

// Fetch featured properties
$featured_properties = [];
$featuredQuery = "SELECT p.*, 
                       pi.image_url as primary_image,
                       u.first_name as agent_first_name,
                       u.last_name as agent_last_name,
                       u.profile_image as agent_profile_image
                FROM properties p
                LEFT JOIN property_images pi ON p.property_id = pi.property_id AND pi.is_primary = 1
                LEFT JOIN property_listings pl ON p.property_id = pl.property_id
                LEFT JOIN agents a ON pl.agent_id = a.agent_id
                LEFT JOIN users u ON a.user_id = u.user_id
                WHERE p.featured = 1 AND p.status IN ('for_sale', 'for_rent')
                ORDER BY p.created_at DESC
                LIMIT 6";

$result = $conn->query($featuredQuery);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $featured_properties[] = $row;
    }
}

// Fetch recent blog posts
$recent_posts = [];
$postsQuery = "SELECT bp.*, 
                     u.first_name as author_first_name, 
                     u.last_name as author_last_name,
                     u.profile_image as author_image
              FROM blog_posts bp
              JOIN users u ON bp.author_id = u.user_id
              WHERE bp.status = 'published'
              ORDER BY bp.published_at DESC
              LIMIT 3";

$result = $conn->query($postsQuery);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $recent_posts[] = $row;
    }
}

// Count total properties by type
$property_counts = [];
$countQuery = "SELECT property_type, COUNT(*) as count FROM properties GROUP BY property_type";
$result = $conn->query($countQuery);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $property_counts[$row['property_type']] = $row['count'];
    }
}

// Get total properties, agents, and clients for stats
$stats = [];
$statsQuery = "SELECT 
                (SELECT COUNT(*) FROM properties) as total_properties,
                (SELECT COUNT(*) FROM agents) as total_agents,
                (SELECT COUNT(*) FROM users WHERE user_type = 'client') as total_clients,
                (SELECT COUNT(*) FROM transactions WHERE status = 'completed') as completed_transactions";
$result = $conn->query($statsQuery);
if ($result && $result->num_rows > 0) {
    $stats = $result->fetch_assoc();
}

// Function to format price
function formatPrice($price) {
    if ($price >= 1000000) {
        return '$' . number_format($price / 1000000, 1) . 'M';
    } else {
        return '$' . number_format($price);
    }
}

// Function to limit text to a certain number of words
function limitWords($text, $limit) {
    $words = explode(' ', $text);
    if (count($words) > $limit) {
        return implode(' ', array_slice($words, 0, $limit)) . '...';
    }
    return $text;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PrimeEstate - Luxury Real Estate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>
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
            background-color: #f8fafc;
            overflow-x: hidden;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Montserrat', sans-serif;
        }
        
        .hero-pattern {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'%3E%3Cg fill-rule='evenodd'%3E%3Cg fill='%236366f1' fill-opacity='0.05'%3E%3Cpath opacity='.5' d='M96 95h4v1h-4v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9zm-1 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm9-10v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm9-10v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9z'/%3E%3Cpath d='M6 5V0H5v5H0v1h5v94h1V6h94V5H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        
        /* Glass Card Effect */
        .glass-card {
            backdrop-filter: blur(20px) saturate(180%);
            background-color: rgba(255, 255, 255, 0.85);
            border-radius: 16px;
            border: 1px solid rgba(209, 213, 219, 0.3);
            box-shadow: 
                0 10px 15px -3px rgba(0, 0, 0, 0.1),
                0 4px 6px -2px rgba(0, 0, 0, 0.05),
                0 0 0 1px rgba(255, 255, 255, 0.1) inset;
        }
        
        /* Property card hover effects */
        .property-card {
            transition: all 0.3s ease-out;
        }
        
        .property-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .image-overlay {
            background: linear-gradient(to bottom, rgba(0,0,0,0) 0%, rgba(0,0,0,0.7) 100%);
        }
        
        .blob {
            border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
        }
        
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
            100% { transform: translateY(0px) rotate(0deg); }
        }
        
        .float-animation {
            animation: float 8s ease-in-out infinite;
        }
        
        /* Hero section gradient */
        .hero-gradient {
            background: linear-gradient(135deg, rgba(67, 56, 202, 0.1) 0%, rgba(14, 165, 233, 0.1) 100%);
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
        
        /* Hamburger Menu */
        .hamburger {
            cursor: pointer;
            width: 24px;
            height: 24px;
            transition: all 0.25s;
            position: relative;
        }
        
        .hamburger-top,
        .hamburger-middle,
        .hamburger-bottom {
            position: absolute;
            width: 24px;
            height: 2px;
            top: 0;
            left: 0;
            background: #1e293b;
            transform: rotate(0);
            transition: all 0.5s;
        }
        
        .hamburger-middle {
            transform: translateY(7px);
        }
        
        .hamburger-bottom {
            transform: translateY(14px);
        }
        
        .open .hamburger-top {
            transform: rotate(45deg) translateY(6px) translateX(6px);
        }
        
        .open .hamburger-middle {
            display: none;
        }
        
        .open .hamburger-bottom {
            transform: rotate(-45deg) translateY(6px) translateX(-6px);
        }
        
        /* Stats counters */
        .stat-counter {
            font-variant-numeric: tabular-nums;
            transition: all 0.4s ease-out;
        }
        
        /* Testimonial quote */
        .testimonial-quote::before {
            content: '\201C';
            font-size: 6rem;
            font-family: Georgia, serif;
            color: rgba(99, 102, 241, 0.2);
            position: absolute;
            top: -1rem;
            left: -1.5rem;
            line-height: 1;
        }
    </style>
</head>
<body>
    <!-- Header & Navigation -->
    <header class="sticky top-0 z-50 bg-white shadow-md">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <!-- Logo -->
                <a href="index.php" class="flex items-center space-x-2">
                    <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-blue-600 rounded-lg flex items-center justify-center shadow-lg">
                        <i class="fas fa-home text-white text-lg"></i>
                    </div>
                    <h1 class="text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-blue-600">PrimeEstate</h1>
                </a>
                
                <!-- Desktop Navigation -->
                <nav class="hidden md:flex items-center space-x-8">
                    <a href="index.php" class="text-gray-800 hover:text-indigo-600 font-medium transition-colors duration-300">Home</a>
                    <a href="properties.php" class="text-gray-800 hover:text-indigo-600 font-medium transition-colors duration-300">Properties</a>
                    <a href="agents.php" class="text-gray-800 hover:text-indigo-600 font-medium transition-colors duration-300">Agents</a>
                    <a href="blog.php" class="text-gray-800 hover:text-indigo-600 font-medium transition-colors duration-300">Blog</a>
                    <a href="contact.php" class="text-gray-800 hover:text-indigo-600 font-medium transition-colors duration-300">Contact</a>
                </nav>
                
                <!-- Action Buttons -->
                <div class="hidden md:flex items-center space-x-4">
                    <?php if ($logged_in): ?>
                        <div class="relative group">
                            <button class="flex items-center space-x-2 text-gray-700 hover:text-indigo-600 font-medium transition-colors">
                                <span><?php echo htmlspecialchars($user_name); ?></span>
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            <div class="absolute right-0 mt-2 py-2 w-48 bg-white rounded-md shadow-xl z-10 hidden group-hover:block">
                                <?php if ($user_type === 'admin'): ?>
                                    <a href="admin/dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-600">Admin Dashboard</a>
                                <?php elseif ($user_type === 'agent'): ?>
                                    <a href="agent/dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-600">Agent Dashboard</a>
                                <?php else: ?>
                                    <a href="client/dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-600">My Dashboard</a>
                                <?php endif; ?>
                                <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-600">My Profile</a>
                                <a href="saved-properties.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-600">Saved Properties</a>
                                <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-600">Logout</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="login.php" class="text-gray-700 hover:text-indigo-600 font-medium transition-colors duration-300">Sign In</a>
                        <a href="signup.php" class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white px-5 py-2 rounded-full font-medium shadow-lg hover:shadow-xl transition-shadow duration-300">Sign Up</a>
                    <?php endif; ?>
                </div>
                
                <!-- Mobile Menu Button -->
                <div class="md:hidden flex items-center">
                    <button id="menu-toggle" class="hamburger focus:outline-none">
                        <span class="hamburger-top"></span>
                        <span class="hamburger-middle"></span>
                        <span class="hamburger-bottom"></span>
                    </button>
                </div>
            </div>
            
            <!-- Mobile Menu -->
            <div id="mobile-menu" class="hidden md:hidden bg-white py-4 border-t border-gray-200">
                <div class="flex flex-col space-y-4 px-4">
                    <a href="index.php" class="text-gray-800 hover:text-indigo-600 font-medium">Home</a>
                    <a href="properties.php" class="text-gray-800 hover:text-indigo-600 font-medium">Properties</a>
                    <a href="agents.php" class="text-gray-800 hover:text-indigo-600 font-medium">Agents</a>
                    <a href="blog.php" class="text-gray-800 hover:text-indigo-600 font-medium">Blog</a>
                    <a href="contact.php" class="text-gray-800 hover:text-indigo-600 font-medium">Contact</a>
                    
                    <div class="border-t border-gray-200 pt-4">
                        <?php if ($logged_in): ?>
                            <div class="flex flex-col space-y-2">
                                <?php if ($user_type === 'admin'): ?>
                                    <a href="admin/dashboard.php" class="text-gray-700 hover:text-indigo-600 font-medium">Admin Dashboard</a>
                                <?php elseif ($user_type === 'agent'): ?>
                                    <a href="agent/dashboard.php" class="text-gray-700 hover:text-indigo-600 font-medium">Agent Dashboard</a>
                                <?php else: ?>
                                    <a href="client/dashboard.php" class="text-gray-700 hover:text-indigo-600 font-medium">My Dashboard</a>
                                <?php endif; ?>
                                <a href="profile.php" class="text-gray-700 hover:text-indigo-600 font-medium">My Profile</a>
                                <a href="saved-properties.php" class="text-gray-700 hover:text-indigo-600 font-medium">Saved Properties</a>
                                <a href="logout.php" class="text-gray-700 hover:text-indigo-600 font-medium">Logout</a>
                            </div>
                        <?php else: ?>
                            <div class="flex flex-col space-y-2">
                                <a href="login.php" class="text-gray-700 hover:text-indigo-600 font-medium">Sign In</a>
                                <a href="signup.php" class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white px-5 py-2 rounded-full font-medium shadow text-center">Sign Up</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero-gradient py-16 md:py-32 hero-pattern overflow-hidden relative">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-2 gap-12 items-center">
                <div class="md:pr-8 z-10">
                    <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-gray-900 leading-tight">
                        Find Your <span class="bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-blue-600">Dream Home</span> With Ease
                    </h1>
                    <p class="mt-6 text-lg md:text-xl text-gray-700 max-w-lg">
                        Discover exclusive properties, connect with trusted agents, and make informed decisions with PrimeEstate.
                    </p>
                    <div class="mt-8 flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-4">
                        <a href="properties.php" class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white px-8 py-4 rounded-full font-semibold text-center shadow-lg hover:shadow-xl transition-shadow duration-300">
                            Browse Properties
                        </a>
                        <a href="contact.php" class="bg-white text-indigo-600 border border-indigo-600 px-8 py-4 rounded-full font-semibold text-center shadow-md hover:shadow-lg transition-shadow duration-300">
                            Contact Us
                        </a>
                    </div>
                    
                    <!-- Search Form -->
                    <div class="glass-card mt-10 p-6 max-w-2xl animate__animated animate__fadeInUp">
                        <form action="search.php" method="get" class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label for="property_type" class="block text-gray-700 font-medium mb-2">Property Type</label>
                                    <select id="property_type" name="property_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                        <option value="">All Types</option>
                                        <option value="apartment">Apartment</option>
                                        <option value="house">House</option>
                                        <option value="condo">Condo</option>
                                        <option value="townhouse">Townhouse</option>
                                        <option value="land">Land</option>
                                        <option value="commercial">Commercial</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="location" class="block text-gray-700 font-medium mb-2">Location</label>
                                    <input type="text" id="location" name="location" placeholder="City, State, ZIP" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                </div>
                                <div>
                                    <label for="price_range" class="block text-gray-700 font-medium mb-2">Price Range</label>
                                    <select id="price_range" name="price_range" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                        <option value="">Any Price</option>
                                        <option value="0-200000">Up to $200,000</option>
                                        <option value="200000-500000">$200,000 - $500,000</option>
                                        <option value="500000-1000000">$500,000 - $1,000,000</option>
                                        <option value="1000000-2000000">$1,000,000 - $2,000,000</option>
                                        <option value="2000000+">$2,000,000+</option>
                                    </select>
                                </div>
                            </div>
                            <div class="flex justify-center">
                                <button type="submit" class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white px-8 py-3 rounded-full font-semibold shadow-lg hover:shadow-xl transition-shadow duration-300">
                                    <i class="fas fa-search mr-2"></i> Search Properties
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="relative">
                    <div class="relative z-10 float-animation">
                        <img src="assets/images/hero-home.jpg" alt="Luxury Home" class="rounded-3xl shadow-2xl" onerror="this.src='https://via.placeholder.com/600x400/6366F1/FFFFFF?text=Luxury+Home'">
                        
                        <!-- Stats Overlay -->
                        <div class="absolute -bottom-6 -left-6 glass-card p-4 shadow-lg backdrop-blur">
                            <div class="flex items-center space-x-2">
                                <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
                                    <i class="fas fa-award text-indigo-600"></i>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-sm">Trusted by</p>
                                    <p class="font-bold text-gray-800 text-lg"><span class="stat-counter" data-target="<?php echo !empty($stats['total_clients']) ? $stats['total_clients'] : 500; ?>">0</span>+ clients</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="absolute -top-6 -right-6 glass-card p-4 shadow-lg backdrop-blur">
                            <div class="flex items-center space-x-2">
                                <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                                    <i class="fas fa-home text-blue-600"></i>
                                </div>
                                <div>
                                    <p class="text-gray-500 text-sm">Properties</p>
                                    <p class="font-bold text-gray-800 text-lg"><span class="stat-counter" data-target="<?php echo !empty($stats['total_properties']) ? $stats['total_properties'] : 200; ?>">0</span>+</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Decorative Elements -->
                    <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full h-full -z-10">
                        <div class="absolute top-0 right-0 blob bg-indigo-200/20 w-72 h-72 -z-10"></div>
                        <div class="absolute bottom-0 left-0 blob bg-blue-200/20 w-80 h-80 -z-10"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Featured Properties Section -->
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">Featured Properties</h2>
                <p class="mt-4 text-xl text-gray-600 max-w-2xl mx-auto">Discover our handpicked selection of premium properties that stand out for their exceptional quality and value.</p>
            </div>
            
            <?php if (!empty($featured_properties)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php foreach ($featured_properties as $property): ?>
                        <div class="property-card bg-white rounded-2xl overflow-hidden shadow-lg hover:shadow-xl transition-all duration-300">
                            <!-- Property Image -->
                            <div class="relative h-60 overflow-hidden">
                                <?php if ($property['primary_image']): ?>
                                    <img src="<?php echo htmlspecialchars($property['primary_image']); ?>" alt="<?php echo htmlspecialchars($property['title']); ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <img src="https://via.placeholder.com/600x400/6366F1/FFFFFF?text=No+Image" alt="No Image Available" class="w-full h-full object-cover">
                                <?php endif; ?>
                                
                                <!-- Status Badge -->
                                <div class="absolute top-4 left-4">
                                    <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $property['status'] == 'for_sale' ? 'bg-indigo-600 text-white' : 'bg-blue-600 text-white'; ?>">
                                        <?php echo $property['status'] == 'for_sale' ? 'For Sale' : 'For Rent'; ?>
                                    </span>
                                </div>
                                
                                <!-- Price Badge -->
                                <div class="absolute bottom-0 left-0 right-0 p-4 image-overlay">
                                    <span class="text-white font-bold text-xl">
                                        <?php echo formatPrice($property['price']); ?>
                                        <?php if ($property['status'] == 'for_rent'): ?>
                                            <span class="text-sm font-normal">/month</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Property Info -->
                            <div class="p-6">
                                <h3 class="text-xl font-bold text-gray-900 mb-2">
                                    <a href="property-details.php?id=<?php echo $property['property_id']; ?>" class="hover:text-indigo-600 transition-colors">
                                        <?php echo htmlspecialchars($property['title']); ?>
                                    </a>
                                </h3>
                                
                                <!-- Location -->
                                <div class="flex items-start mb-4">
                                    <i class="fas fa-map-marker-alt text-indigo-600 mt-1 mr-2"></i>
                                    <p class="text-gray-600"><?php echo htmlspecialchars($property['address'] . ', ' . $property['city'] . ', ' . $property['state']); ?></p>
                                </div>
                                
                                <!-- Property Features -->
                                <div class="flex justify-between mb-6 text-gray-700">
                                    <?php if ($property['bedrooms']): ?>
                                        <div class="flex items-center">
                                            <i class="fas fa-bed text-indigo-600 mr-2"></i>
                                            <span><?php echo $property['bedrooms']; ?> Beds</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($property['bathrooms']): ?>
                                        <div class="flex items-center">
                                            <i class="fas fa-bath text-indigo-600 mr-2"></i>
                                            <span><?php echo $property['bathrooms']; ?> Baths</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($property['area_sqft']): ?>
                                        <div class="flex items-center">
                                            <i class="fas fa-vector-square text-indigo-600 mr-2"></i>
                                            <span><?php echo number_format($property['area_sqft']); ?> sqft</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Agent Info -->
                                <?php if (!empty($property['agent_first_name'])): ?>
                                    <div class="flex items-center pt-4 border-t border-gray-200">
                                        <div class="w-10 h-10 rounded-full overflow-hidden mr-3">
                                            <?php if (!empty($property['agent_profile_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($property['agent_profile_image']); ?>" alt="Agent" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <div class="w-full h-full bg-indigo-100 flex items-center justify-center">
                                                    <i class="fas fa-user text-indigo-600"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($property['agent_first_name'] . ' ' . $property['agent_last_name']); ?></p>
                                            <p class="text-sm text-gray-500">Listing Agent</p>
                                        </div>
                                        <a href="property-details.php?id=<?php echo $property['property_id']; ?>" class="ml-auto text-indigo-600 hover:text-indigo-800 font-medium">
                                            View <i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="pt-4 border-t border-gray-200 text-right">
                                        <a href="property-details.php?id=<?php echo $property['property_id']; ?>" class="text-indigo-600 hover:text-indigo-800 font-medium">
                                            View Details <i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="text-center mt-12">
                    <a href="properties.php" class="inline-flex items-center px-6 py-3 border border-indigo-600 text-indigo-600 bg-white hover:bg-indigo-50 rounded-full font-medium transition-colors duration-300">
                        View All Properties <i class="fas fa-chevron-right ml-2"></i>
                    </a>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <p class="text-gray-600">No featured properties available at the moment.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Property Categories Section -->
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">Explore Property Types</h2>
                <p class="mt-4 text-xl text-gray-600 max-w-2xl mx-auto">Find your perfect property from our diverse collection of homes, apartments, and commercial spaces.</p>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-6">
                <!-- Property Type Cards -->
                <a href="properties.php?type=apartment" class="bg-gray-50 rounded-xl p-6 text-center shadow-md hover:shadow-lg transition-shadow duration-300 hover:bg-indigo-50 group">
                    <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:bg-indigo-200 transition-colors">
                        <i class="fas fa-building text-indigo-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-900 mb-2">Apartments</h3>
                    <p class="text-gray-600 text-sm"><?php echo isset($property_counts['apartment']) ? $property_counts['apartment'] : 0; ?> Properties</p>
                </a>
                
                <a href="properties.php?type=house" class="bg-gray-50 rounded-xl p-6 text-center shadow-md hover:shadow-lg transition-shadow duration-300 hover:bg-indigo-50 group">
                    <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:bg-indigo-200 transition-colors">
                        <i class="fas fa-home text-indigo-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-900 mb-2">Houses</h3>
                    <p class="text-gray-600 text-sm"><?php echo isset($property_counts['house']) ? $property_counts['house'] : 0; ?> Properties</p>
                </a>
                
                <a href="properties.php?type=condo" class="bg-gray-50 rounded-xl p-6 text-center shadow-md hover:shadow-lg transition-shadow duration-300 hover:bg-indigo-50 group">
                    <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:bg-indigo-200 transition-colors">
                        <i class="fas fa-city text-indigo-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-900 mb-2">Condos</h3>
                    <p class="text-gray-600 text-sm"><?php echo isset($property_counts['condo']) ? $property_counts['condo'] : 0; ?> Properties</p>
                </a>
                
                <a href="properties.php?type=townhouse" class="bg-gray-50 rounded-xl p-6 text-center shadow-md hover:shadow-lg transition-shadow duration-300 hover:bg-indigo-50 group">
                    <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:bg-indigo-200 transition-colors">
                        <i class="fas fa-house-user text-indigo-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-900 mb-2">Townhouses</h3>
                    <p class="text-gray-600 text-sm"><?php echo isset($property_counts['townhouse']) ? $property_counts['townhouse'] : 0; ?> Properties</p>
                </a>
                
                <a href="properties.php?type=land" class="bg-gray-50 rounded-xl p-6 text-center shadow-md hover:shadow-lg transition-shadow duration-300 hover:bg-indigo-50 group">
                    <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:bg-indigo-200 transition-colors">
                        <i class="fas fa-tree text-indigo-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-900 mb-2">Land</h3>
                    <p class="text-gray-600 text-sm"><?php echo isset($property_counts['land']) ? $property_counts['land'] : 0; ?> Properties</p>
                </a>
                
                <a href="properties.php?type=commercial" class="bg-gray-50 rounded-xl p-6 text-center shadow-md hover:shadow-lg transition-shadow duration-300 hover:bg-indigo-50 group">
                    <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:bg-indigo-200 transition-colors">
                        <i class="fas fa-store text-indigo-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-900 mb-2">Commercial</h3>
                    <p class="text-gray-600 text-sm"><?php echo isset($property_counts['commercial']) ? $property_counts['commercial'] : 0; ?> Properties</p>
                </a>
            </div>
        </div>
    </section>

    <!-- Recent Blog Posts -->
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">Recent Articles</h2>
                <p class="mt-4 text-xl text-gray-600 max-w-2xl mx-auto">Stay informed with our latest insights and trends in the real estate market.</p>
            </div>
            
            <?php if (!empty($recent_posts)): ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <?php foreach ($recent_posts as $post): ?>
                        <div class="bg-white rounded-2xl overflow-hidden shadow-md hover:shadow-lg transition-all">
                            <!-- Post Image -->
                            <div class="h-48 overflow-hidden">
                                <?php if (!empty($post['featured_image'])): ?>
                                    <img src="<?php echo htmlspecialchars($post['featured_image']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <img src="https://via.placeholder.com/800x400/6366F1/FFFFFF?text=Blog+Post" alt="Blog Post" class="w-full h-full object-cover">
                                <?php endif; ?>
                            </div>
                            
                            <!-- Post Content -->
                            <div class="p-6">
                                <h3 class="text-xl font-bold text-gray-900 mb-3">
                                    <a href="blog-post.php?slug=<?php echo $post['slug']; ?>" class="hover:text-indigo-600 transition-colors">
                                        <?php echo htmlspecialchars($post['title']); ?>
                                    </a>
                                </h3>
                                <?php if (!empty($post['excerpt'])): ?>
                                    <p class="text-gray-600 mb-4">
                                        <?php echo limitWords(htmlspecialchars($post['excerpt']), 20); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <!-- Author and Date -->
                                <div class="flex items-center pt-4 border-t border-gray-200">
                                    <div class="w-10 h-10 rounded-full overflow-hidden mr-3">
                                        <?php if (!empty($post['author_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($post['author_image']); ?>" alt="Author" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <div class="w-full h-full bg-indigo-100 flex items-center justify-center">
                                                <i class="fas fa-user text-indigo-600"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">
                                            <?php echo htmlspecialchars($post['author_first_name'] . ' ' . $post['author_last_name']); ?>
                                        </p>
                                        <p class="text-sm text-gray-500">
                                            <?php echo date('M d, Y', strtotime($post['published_at'])); ?>
                                        </p>
                                    </div>
                                    <a href="blog-post.php?slug=<?php echo $post['slug']; ?>" class="ml-auto text-indigo-600 hover:text-indigo-800 font-medium">
                                        Read <i class="fas fa-arrow-right ml-1"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="text-center mt-12">
                    <a href="blog.php" class="inline-flex items-center px-6 py-3 border border-indigo-600 text-indigo-600 bg-white hover:bg-indigo-50 rounded-full font-medium transition-colors duration-300">
                        View All Articles <i class="fas fa-chevron-right ml-2"></i>
                    </a>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <p class="text-gray-600">No blog posts available at the moment.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="py-16 bg-gradient-to-br from-indigo-600 to-blue-600 text-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold">Our Success in Numbers</h2>
                <p class="mt-4 text-xl text-indigo-100 max-w-2xl mx-auto">We pride ourselves on providing exceptional service and results for our clients.</p>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
                <div class="text-center">
                    <div class="text-4xl md:text-5xl font-bold mb-2">
                        <span class="stat-counter" data-target="<?php echo !empty($stats['total_properties']) ? $stats['total_properties'] : 200; ?>">0</span>+
                    </div>
                    <p class="text-indigo-100 text-lg">Properties Listed</p>
                </div>
                
                <div class="text-center">
                    <div class="text-4xl md:text-5xl font-bold mb-2">
                        <span class="stat-counter" data-target="<?php echo !empty($stats['total_clients']) ? $stats['total_clients'] : 500; ?>">0</span>+
                    </div>
                    <p class="text-indigo-100 text-lg">Happy Clients</p>
                </div>
                
                <div class="text-center">
                    <div class="text-4xl md:text-5xl font-bold mb-2">
                        <span class="stat-counter" data-target="<?php echo !empty($stats['total_agents']) ? $stats['total_agents'] : 50; ?>">0</span>+
                    </div>
                    <p class="text-indigo-100 text-lg">Expert Agents</p>
                </div>
                
                <div class="text-center">
                    <div class="text-4xl md:text-5xl font-bold mb-2">
                        <span class="stat-counter" data-target="<?php echo !empty($stats['completed_transactions']) ? $stats['completed_transactions'] : 300; ?>">0</span>+
                    </div>
                    <p class="text-indigo-100 text-lg">Completed Sales</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="py-16 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">What Our Clients Say</h2>
                <p class="mt-4 text-xl text-gray-600 max-w-2xl mx-auto">Discover why our clients trust us with their real estate needs.</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Testimonial 1 -->
                <div class="bg-gray-50 rounded-2xl p-8 shadow-md relative testimonial-quote">
                    <div class="mb-6">
                        <div class="flex mb-2">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <i class="fas fa-star text-yellow-400 mr-1"></i>
                            <?php endfor; ?>
                        </div>
                        <p class="text-gray-700 italic">"PrimeEstate helped me find my dream home in just two weeks! The team was responsive, professional, and truly understood my needs. I couldn't be happier with my new home."</p>
                    </div>
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-gray-300 rounded-full overflow-hidden mr-4">
                            <img src="https://randomuser.me/api/portraits/women/65.jpg" alt="Sarah Johnson" class="w-full h-full object-cover">
                        </div>
                        <div>
                            <h4 class="font-semibold text-gray-900">Sarah Johnson</h4>
                            <p class="text-gray-600 text-sm">Homebuyer</p>
                        </div>
                    </div>
                </div>
                
                <!-- Testimonial 2 -->
                <div class="bg-gray-50 rounded-2xl p-8 shadow-md relative testimonial-quote">
                    <div class="mb-6">
                        <div class="flex mb-2">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <i class="fas fa-star text-yellow-400 mr-1"></i>
                            <?php endfor; ?>
                        </div>
                        <p class="text-gray-700 italic">"Selling my property was seamless with PrimeEstate. Their market knowledge and marketing strategy got me multiple offers above asking price. The entire process was stress-free."</p>
                    </div>
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-gray-300 rounded-full overflow-hidden mr-4">
                            <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="Michael Chen" class="w-full h-full object-cover">
                        </div>
                        <div>
                            <h4 class="font-semibold text-gray-900">Michael Chen</h4>
                            <p class="text-gray-600 text-sm">Property Seller</p>
                        </div>
                    </div>
                </div>
                
                <!-- Testimonial 3 -->
                <div class="bg-gray-50 rounded-2xl p-8 shadow-md relative testimonial-quote">
                    <div class="mb-6">
                        <div class="flex mb-2">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <i class="fas fa-star text-yellow-400 mr-1"></i>
                            <?php endfor; ?>
                        </div>
                        <p class="text-gray-700 italic">"As an investor, I appreciate PrimeEstate's data-driven approach. They identified high-yield properties in emerging markets that perfectly fit my investment strategy. Highly recommended!"</p>
                    </div>
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-gray-300 rounded-full overflow-hidden mr-4">
                            <img src="https://randomuser.me/api/portraits/women/45.jpg" alt="Alexandra Patel" class="w-full h-full object-cover">
                        </div>
                        <div>
                            <h4 class="font-semibold text-gray-900">Alexandra Patel</h4>
                            <p class="text-gray-600 text-sm">Real Estate Investor</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="glass-card p-8 md:p-12 rounded-3xl bg-gradient-to-br from-indigo-600/10 to-blue-600/10">
                <div class="md:flex items-center justify-between">
                    <div class="md:w-2/3 mb-8 md:mb-0">
                        <h2 class="text-3xl font-bold text-gray-900 mb-4">Ready to Find Your Dream Property?</h2>
                        <p class="text-xl text-gray-700">Let us help you navigate the real estate market with confidence. Contact our expert agents today.</p>
                    </div>
                    <div>
                        <a href="contact.php" class="block w-full md:w-auto text-center bg-gradient-to-r from-indigo-600 to-blue-600 text-white px-8 py-4 rounded-full font-semibold shadow-lg hover:shadow-xl transition-shadow duration-300">
                            Get Started <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-900 text-white py-12">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <!-- Company Info -->
                <div>
                    <div class="flex items-center space-x-2 mb-6">
                        <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-blue-600 rounded-lg flex items-center justify-center shadow-lg">
                            <i class="fas fa-home text-white text-lg"></i>
                        </div>
                        <h1 class="text-2xl font-bold text-white">PrimeEstate</h1>
                    </div>
                    <p class="text-gray-400 mb-6">Connecting people with their perfect properties since 2010. Your trusted partner in real estate.</p>
                    <div class="flex space-x-4">
                        <a href="#" class="text-gray-400 hover:text-white transition-colors">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-white transition-colors">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-white transition-colors">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-white transition-colors">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div>
                    <h3 class="text-lg font-semibold mb-6 text-white">Quick Links</h3>
                    <ul class="space-y-3">
                        <li><a href="properties.php" class="text-gray-400 hover:text-white transition-colors">Properties</a></li>
                        <li><a href="agents.php" class="text-gray-400 hover:text-white transition-colors">Our Agents</a></li>
                        <li><a href="blog.php" class="text-gray-400 hover:text-white transition-colors">Blog & News</a></li>
                        <li><a href="about.php" class="text-gray-400 hover:text-white transition-colors">About Us</a></li>
                        <li><a href="contact.php" class="text-gray-400 hover:text-white transition-colors">Contact Us</a></li>
                    </ul>
                </div>
                
                <!-- Property Types -->
                <div>
                    <h3 class="text-lg font-semibold mb-6 text-white">Property Types</h3>
                    <ul class="space-y-3">
                        <li><a href="properties.php?type=apartment" class="text-gray-400 hover:text-white transition-colors">Apartments</a></li>
                        <li><a href="properties.php?type=house" class="text-gray-400 hover:text-white transition-colors">Houses</a></li>
                        <li><a href="properties.php?type=condo" class="text-gray-400 hover:text-white transition-colors">Condos</a></li>
                        <li><a href="properties.php?type=townhouse" class="text-gray-400 hover:text-white transition-colors">Townhouses</a></li>
                        <li><a href="properties.php?type=commercial" class="text-gray-400 hover:text-white transition-colors">Commercial</a></li>
                    </ul>
                </div>
                
                <!-- Contact Info -->
                <div>
                    <h3 class="text-lg font-semibold mb-6 text-white">Contact Us</h3>
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <i class="fas fa-map-marker-alt text-indigo-500 mt-1 mr-3"></i>
                            <span class="text-gray-400">123 Maple Street, Suite 600<br>New York, NY 10001</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-phone-alt text-indigo-500 mr-3"></i>
                            <span class="text-gray-400">(123) 456-7890</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-phone-alt text-indigo-500 mr-3"></i>
                            <span class="text-gray-400">(123) 456-7890</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-envelope text-indigo-500 mr-3"></i>
                            <span class="text-gray-400">info@primeestate.com</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-clock text-indigo-500 mr-3"></i>
                            <span class="text-gray-400">Mon-Fri: 9:00 AM - 5:00 PM</span>
                        </li>
                    </ul>
                </div>
            </div>
            
            <div class="border-t border-gray-800 mt-12 pt-8">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <p class="text-gray-400 text-sm mb-4 md:mb-0">© 2025 PrimeEstate. All rights reserved.</p>
                    <div class="flex space-x-6">
                        <a href="privacy-policy.php" class="text-gray-400 hover:text-white text-sm transition-colors">Privacy Policy</a>
                        <a href="terms-of-service.php" class="text-gray-400 hover:text-white text-sm transition-colors">Terms of Service</a>
                        <a href="sitemap.php" class="text-gray-400 hover:text-white text-sm transition-colors">Sitemap</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- JavaScript -->
    <script>
        // Mobile Menu Toggle
        document.getElementById('menu-toggle').addEventListener('click', function() {
            this.classList.toggle('open');
            document.getElementById('mobile-menu').classList.toggle('hidden');
        });
        
        // Stat Counter Animation
        document.addEventListener('DOMContentLoaded', function() {
            const counters = document.querySelectorAll('.stat-counter');
            
            counters.forEach(counter => {
                const target = parseInt(counter.getAttribute('data-target'));
                const duration = 2000; // 2 seconds
                const steps = 50;
                const stepTime = duration / steps;
                const increment = target / steps;
                let current = 0;
                
                const updateCounter = () => {
                    current += increment;
                    if(current < target) {
                        counter.textContent = Math.ceil(current);
                        setTimeout(updateCounter, stepTime);
                    } else {
                        counter.textContent = target;
                    }
                };
                
                // Start counting when element is in viewport
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if(entry.isIntersecting) {
                            updateCounter();
                            observer.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.5 });
                
                observer.observe(counter);
            });
        });
        
        // GSAP Animations
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize ScrollTrigger
            gsap.registerPlugin(ScrollTrigger);
            
            // Animate featured properties
            gsap.from(".property-card", {
                scrollTrigger: {
                    trigger: ".property-card",
                    start: "top bottom-=100",
                    toggleActions: "play none none none"
                },
                y: 50,
                opacity: 0,
                duration: 0.8,
                stagger: 0.2
            });
            
            // Animate property type cards
            gsap.from(".bg-gray-50.rounded-xl", {
                scrollTrigger: {
                    trigger: ".bg-gray-50.rounded-xl",
                    start: "top bottom-=100",
                    toggleActions: "play none none none"
                },
                scale: 0.9,
                opacity: 0,
                duration: 0.6,
                stagger: 0.1
            });
            
            // Animate blog posts
            gsap.from(".bg-white.rounded-2xl", {
                scrollTrigger: {
                    trigger: ".bg-white.rounded-2xl",
                    start: "top bottom-=100",
                    toggleActions: "play none none none"
                },
                y: 30,
                opacity: 0,
                duration: 0.7,
                stagger: 0.2
            });
            
            // Animate testimonials
            gsap.from(".testimonial-quote", {
                scrollTrigger: {
                    trigger: ".testimonial-quote",
                    start: "top bottom-=100",
                    toggleActions: "play none none none"
                },
                x: -30,
                opacity: 0,
                duration: 0.7,
                stagger: 0.2
            });
            
            // Animate CTA
            gsap.from(".glass-card", {
                scrollTrigger: {
                    trigger: ".glass-card",
                    start: "top bottom-=100",
                    toggleActions: "play none none none"
                },
                y: 30,
                opacity: 0,
                duration: 0.8
            });
        });
    </script>
</body>
</html>