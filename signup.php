<?php
require_once 'connection.php';

$signup_success = false;
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve form data
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $user_type = $_POST['user_type'];
    
    // Basic validation
    if (empty($email) || empty($password) || empty($confirm_password) || empty($first_name) || empty($last_name)) {
        $error_message = "All required fields must be filled out";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match";
    } elseif (strlen($password) < 8) {
        $error_message = "Password must be at least 8 characters long";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error_message = "Email already exists. Please use a different email or login.";
        } else {
            // Hash password for security
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert user data
                $stmt = $conn->prepare("INSERT INTO users (email, password, first_name, last_name, phone, user_type) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $email, $hashed_password, $first_name, $last_name, $phone, $user_type);
                $stmt->execute();
                
                $user_id = $conn->insert_id;
                
                // If user is an agent, collect additional information
                if ($user_type === 'agent' && isset($_POST['license_number'])) {
                    $license_number = trim($_POST['license_number']);
                    $brokerage = isset($_POST['brokerage']) ? trim($_POST['brokerage']) : null;
                    $experience_years = isset($_POST['experience_years']) ? (int)$_POST['experience_years'] : null;
                    $specialties = isset($_POST['specialties']) ? trim($_POST['specialties']) : null;
                    
                    $stmt = $conn->prepare("INSERT INTO agents (user_id, license_number, brokerage, experience_years, specialties) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("issis", $user_id, $license_number, $brokerage, $experience_years, $specialties);
                    $stmt->execute();
                }
                
                // Commit transaction
                $conn->commit();
                $signup_success = true;
                
                // Set a session variable to show a success message on the login page
                session_start();
                $_SESSION['signup_success'] = true;
                $_SESSION['first_name'] = $first_name;
                
                // Redirect to login page
                header("Location: login.php");
                exit();
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $error_message = "Registration failed: " . $e->getMessage();
            }
        }
        
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create an Account - PrimeEstate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
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
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'%3E%3Cg fill-rule='evenodd'%3E%3Cg fill='%236366f1' fill-opacity='0.05'%3E%3Cpath opacity='.5' d='M96 95h4v1h-4v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4h-9v4h-1v-4H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15v-9H0v-1h15V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h9V0h1v15h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9h4v1h-4v9zm-1 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm9-10v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm9-10v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm9-10v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-10 0v-9h-9v9h9zm-9-10h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9zm10 0h9v-9h-9v9z'/%3E%3Cpath d='M6 5V0H5v5H0v1h5v94h1V6h94V5H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Montserrat', sans-serif;
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
        
        /* Custom form styling */
        .form-input {
            border-radius: 12px;
            border: 1px solid rgba(209, 213, 219, 0.5);
            padding: 0.75rem 1rem;
            font-size: 1rem;
            transition: all 0.2s ease;
            background-color: rgba(255, 255, 255, 0.8);
        }
        
        .form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.2);
            outline: none;
        }
        
        .floating-label {
            top: 50%;
            transform: translateY(-50%);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            pointer-events: none;
            padding: 0 0.25rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .input-group:focus-within .floating-label,
        .input-filled .floating-label {
            top: 0;
            transform: translateY(-50%) scale(0.85);
            background: linear-gradient(180deg, rgba(255,255,255,0) 0%, rgba(255,255,255,1) 45%, rgba(255,255,255,1) 55%, rgba(255,255,255,0) 100%);
            color: var(--primary);
            font-weight: 600;
        }
        
        /* Gorgeous button styles */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            color: white;
            font-weight: 600;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(67, 56, 202, 0.2), 0 2px 4px -1px rgba(67, 56, 202, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(67, 56, 202, 0.3), 0 4px 6px -2px rgba(67, 56, 202, 0.2);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .btn-primary::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0) 50%);
            z-index: -1;
        }
        
        /* Toggle button styling */
        .toggle-container {
            background: rgba(241, 245, 249, 0.8);
            border-radius: 16px;
            padding: 0.25rem;
            position: relative;
            box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.05);
        }
        
        .toggle-btn {
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        .toggle-btn.active {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(67, 56, 202, 0.2), 0 2px 4px -1px rgba(67, 56, 202, 0.1);
        }
        
        /* Checkbox styling */
        .custom-checkbox {
            width: 1.25rem;
            height: 1.25rem;
            border-radius: 6px;
            border: 2px solid #cbd5e1;
            position: relative;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .custom-checkbox.checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .custom-checkbox.checked::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 6px;
            width: 6px;
            height: 12px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        
        /* Error styling */
        .input-error {
            border-color: var(--danger);
            background-color: rgba(254, 242, 242, 0.8);
        }
        
        .error-message {
            color: var(--danger);
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
        }
        
        .error-message::before {
            content: '⚠️';
            margin-right: 0.5rem;
        }
        
        /* Password strength meter */
        .password-strength {
            height: 4px;
            border-radius: 2px;
            background-color: #e2e8f0;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        
        .strength-weak { background-color: var(--danger); width: 25%; }
        .strength-fair { background-color: var(--warning); width: 50%; }
        .strength-good { background-color: var(--secondary); width: 75%; }
        .strength-strong { background-color: var(--success); width: 100%; }
        
        /* Animations */
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
        
        .float-animation {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(99, 102, 241, 0); }
            100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0); }
        }
        
        .pulse-animation {
            animation: pulse 2s infinite;
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
        
        /* Progress steps */
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .progress-step {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #e2e8f0;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            color: #64748b;
            position: relative;
            z-index: 1;
        }
        
        .progress-step.active {
            background-color: var(--primary);
            color: white;
        }
        
        .progress-step.completed {
            background-color: var(--success);
            color: white;
        }
        
        .progress-bar {
            position: absolute;
            top: 16px;
            left: 16px;
            right: 16px;
            height: 2px;
            background-color: #e2e8f0;
            z-index: 0;
        }
        
        .progress-fill {
            height: 100%;
            background-color: var(--primary);
            width: 0%;
            transition: width 0.5s ease;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-6xl w-full mx-auto">
        <!-- Decorative shapes -->
        <div class="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none z-0">
            <div class="absolute top-1/4 left-10 w-64 h-64 bg-gradient-to-r from-indigo-200 to-indigo-300 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob"></div>
            <div class="absolute top-1/3 right-10 w-80 h-80 bg-gradient-to-r from-purple-200 to-purple-300 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-2000"></div>
            <div class="absolute -bottom-10 left-1/3 w-72 h-72 bg-gradient-to-r from-blue-200 to-blue-300 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-4000"></div>
        </div>
        
        <!-- Header with animations -->
        <div class="text-center mb-8 relative z-10">
            <a href="index.php" class="inline-block mb-6 transform hover:scale-105 transition-transform duration-300">
                <div class="flex items-center justify-center space-x-2">
                    <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-blue-600 rounded-lg flex items-center justify-center shadow-lg">
                        <i class="fas fa-home text-white text-lg"></i>
                    </div>
                    <h1 class="text-3xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-blue-600">PrimeEstate</h1>
                </div>
            </a>
            <h2 class="mt-4 text-4xl md:text-5xl font-extrabold text-gray-900 flex flex-col sm:flex-row items-center justify-center">
                <span>Join Our</span> 
                <span class="ml-2 bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 via-blue-500 to-indigo-600">Community</span>
            </h2>
            <p class="mt-3 max-w-2xl mx-auto text-xl text-gray-600">Begin your journey to finding the perfect property or showcase your listings with us.</p>
        </div>
        
        <!-- Main Content -->
        <div class="glass-card max-w-3xl mx-auto p-8 mb-16 relative z-10">
            <!-- Success/Error Messages -->
            <?php if (!empty($error_message)): ?>
                <div class="p-4 mb-6 rounded-xl bg-red-50 border border-red-200 animate__animated animate__shakeX">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center">
                                <i class="fas fa-exclamation-circle text-red-500"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-700"><?php echo $error_message; ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- User Type Toggle -->
            <div class="mb-10 text-center">
                <p class="text-sm font-medium text-gray-600 mb-3">I am registering as...</p>
                <div class="toggle-container inline-flex mx-auto" id="toggle-container">
                    <button type="button" id="client-toggle" class="toggle-btn active" data-type="client">
                        <i class="fas fa-user mr-2"></i> Client
                    </button>
                    <button type="button" id="agent-toggle" class="toggle-btn" data-type="agent">
                        <i class="fas fa-id-badge mr-2"></i> Agent
                    </button>
                </div>
                <p class="mt-3 text-xs text-gray-500" id="toggle-description">Looking to buy or rent properties</p>
            </div>

            <!-- Registration Form -->
            <form id="signup-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6" novalidate>
                <input type="hidden" name="user_type" id="user_type" value="client">
                
                <!-- Name Fields (2 columns) -->
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="relative input-group">
                        <input type="text" id="first_name" name="first_name" class="form-input w-full pr-10" required>
                        <label for="first_name" class="floating-label absolute left-4 text-gray-500">First Name</label>
                        <i class="fas fa-user absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                    <div class="relative input-group">
                        <input type="text" id="last_name" name="last_name" class="form-input w-full pr-10" required>
                        <label for="last_name" class="floating-label absolute left-4 text-gray-500">Last Name</label>
                        <i class="fas fa-user absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>
                
                <!-- Email -->
                <div class="relative input-group">
                    <input type="email" id="email" name="email" class="form-input w-full pr-10" required>
                    <label for="email" class="floating-label absolute left-4 text-gray-500">Email Address</label>
                    <i class="fas fa-envelope absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                </div>
                
                <!-- Phone -->
                <div class="relative input-group">
                    <input type="tel" id="phone" name="phone" class="form-input w-full pr-10">
                    <label for="phone" class="floating-label absolute left-4 text-gray-500">Phone Number (optional)</label>
                    <i class="fas fa-phone absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                </div>
                
                <!-- Password Fields (2 columns) -->
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="relative input-group">
                        <input type="password" id="password" name="password" class="form-input w-full pr-10" required>
                        <label for="password" class="floating-label absolute left-4 text-gray-500">Password</label>
                        <button type="button" class="password-toggle absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                        <div class="password-strength mt-2">
                            <div class="password-strength-bar"></div>
                        </div>
                        <div class="password-feedback text-xs text-gray-500 mt-1"></div>
                    </div>
                    <div class="relative input-group">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input w-full pr-10" required>
                        <label for="confirm_password" class="floating-label absolute left-4 text-gray-500">Confirm Password</label>
                        <button type="button" class="password-toggle absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Agent Fields (Hidden by default) -->
                <div id="agent-fields" class="space-y-6 hidden">
                    <div class="relative input-group">
                        <input type="text" id="license_number" name="license_number" class="form-input w-full pr-10">
                        <label for="license_number" class="floating-label absolute left-4 text-gray-500">License Number</label>
                        <i class="fas fa-id-card absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                    
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="relative input-group">
                            <input type="text" id="brokerage" name="brokerage" class="form-input w-full pr-10">
                            <label for="brokerage" class="floating-label absolute left-4 text-gray-500">Brokerage (optional)</label>
                            <i class="fas fa-building absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        <div class="relative input-group">
                            <input type="number" id="experience_years" name="experience_years" min="0" max="100" class="form-input w-full pr-10">
                            <label for="experience_years" class="floating-label absolute left-4 text-gray-500">Years of Experience</label>
                            <i class="fas fa-calendar-alt absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                    </div>
                    
                    <div class="relative input-group">
                    <textarea id="specialties" name="specialties" class="form-input w-full pr-10 h-24" placeholder="Residential, Commercial, Luxury, etc."></textarea>
                        <label for="specialties" class="floating-label absolute left-4 text-gray-500">Specialties (optional)</label>
                        <i class="fas fa-star absolute right-4 top-8 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>
                
                <!-- Terms and Conditions -->
                <div class="flex items-start">
                    <div class="flex items-center h-5">
                        <input id="terms" name="terms" type="checkbox" class="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" required>
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="terms" class="font-medium text-gray-700">I agree to the <a href="#" class="text-indigo-600 hover:text-indigo-500">Terms of Service</a> and <a href="#" class="text-indigo-600 hover:text-indigo-500">Privacy Policy</a></label>
                    </div>
                </div>
                
                <!-- Submit Button -->
                <div class="text-center">
                    <button type="submit" class="btn-primary w-full sm:w-auto px-8 py-3 relative overflow-hidden group">
                        <span class="relative z-10">Create Account</span>
                        <span class="absolute top-0 right-full w-full h-full bg-white opacity-10 transform group-hover:translate-x-full transition-transform duration-300"></span>
                    </button>
                    <p class="mt-4 text-sm text-gray-600">
                        Already have an account? <a href="login.php" class="font-medium text-indigo-600 hover:text-indigo-500 hover:underline">Sign in</a>
                    </p>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Input animation
            const inputs = document.querySelectorAll('.form-input');
            
            inputs.forEach(input => {
                // Initial state check
                if (input.value !== '') {
                    input.parentElement.classList.add('input-filled');
                }
                
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('input-filled');
                });
                
                input.addEventListener('blur', function() {
                    if (this.value === '') {
                        this.parentElement.classList.remove('input-filled');
                    }
                });
                
                input.addEventListener('input', function() {
                    if (this.value === '') {
                        this.parentElement.classList.remove('input-filled');
                    } else {
                        this.parentElement.classList.add('input-filled');
                    }
                });
            });
            
            // Password visibility toggle
            const passwordToggles = document.querySelectorAll('.password-toggle');
            passwordToggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const passwordInput = this.previousElementSibling.previousElementSibling;
                    const icon = this.querySelector('i');
                    
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        passwordInput.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            });
            
            // Password strength meter
            const passwordInput = document.getElementById('password');
            const strengthBar = document.querySelector('.password-strength-bar');
            const feedbackElement = document.querySelector('.password-feedback');
            
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                let feedback = '';
                
                // Calculate strength
                if (password.length >= 8) strength += 1;
                if (password.match(/[A-Z]/)) strength += 1;
                if (password.match(/[0-9]/)) strength += 1;
                if (password.match(/[^A-Za-z0-9]/)) strength += 1;
                
                // Update UI
                strengthBar.className = 'password-strength-bar';
                
                if (password.length === 0) {
                    strengthBar.style.width = '0';
                    feedbackElement.textContent = '';
                } else if (strength === 1) {
                    strengthBar.classList.add('strength-weak');
                    feedback = 'Weak password';
                } else if (strength === 2) {
                    strengthBar.classList.add('strength-fair');
                    feedback = 'Fair password';
                } else if (strength === 3) {
                    strengthBar.classList.add('strength-good');
                    feedback = 'Good password';
                } else if (strength === 4) {
                    strengthBar.classList.add('strength-strong');
                    feedback = 'Strong password';
                }
                
                feedbackElement.textContent = feedback;
            });
            
            // User type toggle
            const clientToggle = document.getElementById('client-toggle');
            const agentToggle = document.getElementById('agent-toggle');
            const userTypeInput = document.getElementById('user_type');
            const agentFields = document.getElementById('agent-fields');
            const toggleDescription = document.getElementById('toggle-description');
            
            clientToggle.addEventListener('click', function() {
                clientToggle.classList.add('active');
                agentToggle.classList.remove('active');
                userTypeInput.value = 'client';
                agentFields.classList.add('hidden');
                toggleDescription.textContent = 'Looking to buy or rent properties';
            });
            
            agentToggle.addEventListener('click', function() {
                agentToggle.classList.add('active');
                clientToggle.classList.remove('active');
                userTypeInput.value = 'agent';
                agentFields.classList.remove('hidden');
                toggleDescription.textContent = 'Selling or renting out properties';
            });
            
            // Form validation
            const form = document.getElementById('signup-form');
            form.addEventListener('submit', function(event) {
                let isValid = true;
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                
                // Reset previous errors
                document.querySelectorAll('.input-error').forEach(el => {
                    el.classList.remove('input-error');
                });
                document.querySelectorAll('.error-message').forEach(el => {
                    el.remove();
                });
                
                // Validate required fields
                const requiredFields = ['first_name', 'last_name', 'email', 'password', 'confirm_password'];
                if (userTypeInput.value === 'agent') {
                    requiredFields.push('license_number');
                }
                
                requiredFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (!field.value.trim()) {
                        field.classList.add('input-error');
                        const errorMsg = document.createElement('div');
                        errorMsg.className = 'error-message';
                        errorMsg.textContent = 'This field is required';
                        field.parentElement.appendChild(errorMsg);
                        isValid = false;
                    }
                });
                
                // Email validation
                const email = document.getElementById('email').value;
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (email && !emailRegex.test(email)) {
                    const emailField = document.getElementById('email');
                    emailField.classList.add('input-error');
                    const errorMsg = document.createElement('div');
                    errorMsg.className = 'error-message';
                    errorMsg.textContent = 'Please enter a valid email address';
                    emailField.parentElement.appendChild(errorMsg);
                    isValid = false;
                }
                
                // Password match validation
                if (password && confirmPassword && password !== confirmPassword) {
                    const confirmField = document.getElementById('confirm_password');
                    confirmField.classList.add('input-error');
                    const errorMsg = document.createElement('div');
                    errorMsg.className = 'error-message';
                    errorMsg.textContent = 'Passwords do not match';
                    confirmField.parentElement.appendChild(errorMsg);
                    isValid = false;
                }
                
                // Terms validation
                const termsCheckbox = document.getElementById('terms');
                if (!termsCheckbox.checked) {
                    const errorMsg = document.createElement('div');
                    errorMsg.className = 'error-message';
                    errorMsg.textContent = 'You must agree to the Terms of Service';
                    termsCheckbox.parentElement.parentElement.appendChild(errorMsg);
                    isValid = false;
                }
                
                if (!isValid) {
                    event.preventDefault();
                }
            });
            
            // Animation effects
            const animationElements = document.querySelectorAll('.animate');
            animationElements.forEach(element => {
                gsap.from(element, {
                    y: 20,
                    opacity: 0,
                    duration: 0.6,
                    scrollTrigger: {
                        trigger: element,
                        start: "top bottom-=100",
                        toggleActions: "play none none none"
                    }
                });
            });
        });
    </script>
</body>
</html>