<?php
require_once 'connection.php';

session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect based on user type
    switch ($_SESSION['user_type']) {
        case 'admin':
            header("Location: admin/dashboard.php");
            exit();
        case 'agent':
            header("Location: agent/dashboard.php");
            exit();
        case 'client':
        default:
            header("Location: homepage.php");
            exit();
    }
}

$login_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve form data
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $remember_me = isset($_POST['remember_me']) ? true : false;
    
    // Basic validation
    if (empty($email) || empty($password)) {
        $login_error = "Email and password are required";
    } else {
        // Check user credentials
        $stmt = $conn->prepare("SELECT user_id, email, password, first_name, last_name, user_type FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['user_type'] = $user['user_type'];
                
                // Update last login timestamp
                $update_stmt = $conn->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE user_id = ?");
                $update_stmt->bind_param("i", $user['user_id']);
                $update_stmt->execute();
                
                // Set remember me cookie if checked
                if ($remember_me) {
                    // Generate a secure token
                    $token = bin2hex(random_bytes(32));
                    $token_hash = hash('sha256', $token);
                    $expiry = time() + (30 * 24 * 60 * 60); // 30 days
                    
                    // Store the token in the database
                    $remember_stmt = $conn->prepare("INSERT INTO user_tokens (user_id, token_hash, expiry) VALUES (?, ?, FROM_UNIXTIME(?)) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expiry = VALUES(expiry)");
                    $remember_stmt->bind_param("isi", $user['user_id'], $token_hash, $expiry);
                    $remember_stmt->execute();
                    
                    // Set cookie
                    setcookie('remember_token', $token, $expiry, '/', '', true, true);
                }
                
                // Redirect based on user type
                switch ($user['user_type']) {
                    case 'admin':
                        header("Location: admin/dashboard.php");
                        exit();
                    case 'agent':
                        header("Location: agent/dashboard.php");
                        exit();
                    case 'client':
                    default:
                        header("Location: homepage.php");
                        exit();
                }
            } else {
                $login_error = "Invalid email or password";
            }
        } else {
            $login_error = "Invalid email or password";
        }
        
        $stmt->close();
    }
}

// Check for remember me cookie
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $token_hash = hash('sha256', $token);
    
    $stmt = $conn->prepare("
        SELECT u.user_id, u.email, u.first_name, u.last_name, u.user_type 
        FROM users u 
        JOIN user_tokens t ON u.user_id = t.user_id 
        WHERE t.token_hash = ? AND t.expiry > NOW()
    ");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Set session variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['user_type'] = $user['user_type'];
        
        // Update last login timestamp
        $update_stmt = $conn->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE user_id = ?");
        $update_stmt->bind_param("i", $user['user_id']);
        $update_stmt->execute();
        
        // Redirect based on user type
        switch ($user['user_type']) {
            case 'admin':
                header("Location: admin/dashboard.php");
                exit();
            case 'agent':
                header("Location: agent/dashboard.php");
                exit();
            case 'client':
            default:
                header("Location: homepage.php");
                exit();
        }
    }
    
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PrimeEstate</title>
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
    </style>
</head>
<body class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full mx-auto">
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
                <span>Welcome</span> 
                <span class="ml-2 bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 via-blue-500 to-indigo-600">Back</span>
            </h2>
            <p class="mt-3 max-w-md mx-auto text-xl text-gray-600">Sign in to access your account</p>
        </div>
        
        <!-- Main Content -->
        <div class="glass-card p-8 mb-16 relative z-10">
            <!-- Error Message -->
            <?php if (!empty($login_error)): ?>
                <div class="p-4 mb-6 rounded-xl bg-red-50 border border-red-200 animate__animated animate__shakeX">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center">
                                <i class="fas fa-exclamation-circle text-red-500"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-700"><?php echo $login_error; ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form id="login-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6" novalidate>
                <!-- Email -->
                <div class="relative input-group">
                    <input type="email" id="email" name="email" class="form-input w-full pr-10" required>
                    <label for="email" class="floating-label absolute left-4 text-gray-500">Email Address</label>
                    <i class="fas fa-envelope absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                </div>
                
                <!-- Password -->
                <div class="relative input-group">
                    <input type="password" id="password" name="password" class="form-input w-full pr-10" required>
                    <label for="password" class="floating-label absolute left-4 text-gray-500">Password</label>
                    <button type="button" class="password-toggle absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none" tabindex="-1">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                
                <!-- Remember Me & Forgot Password -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input id="remember_me" name="remember_me" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <label for="remember_me" class="ml-2 block text-sm text-gray-700">Remember me</label>
                    </div>
                    <a href="forgot-password.php" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 hover:underline">Forgot password?</a>
                </div>
                
                <!-- Submit Button -->
                <div class="text-center">
                    <button type="submit" class="btn-primary w-full px-8 py-3 relative overflow-hidden group">
                        <span class="relative z-10">Sign In</span>
                        <span class="absolute top-0 right-full w-full h-full bg-white opacity-10 transform group-hover:translate-x-full transition-transform duration-300"></span>
                    </button>
                    <p class="mt-4 text-sm text-gray-600">
                        Don't have an account? <a href="signup.php" class="font-medium text-indigo-600 hover:text-indigo-500 hover:underline">Sign up</a>
                    </p>
                </div>
            </form>
            
            <!-- OR Divider -->
            <div class="relative flex items-center justify-center mt-8">
                <div class="border-t border-gray-300 absolute w-full"></div>
                <div class="bg-white px-4 relative text-sm text-gray-500">Or continue with</div>
            </div>
            
            <!-- Social Login Buttons -->
            <div class="mt-6 grid grid-cols-3 gap-3">
                <a href="#" class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-300">
                    <i class="fab fa-google text-red-500"></i>
                </a>
                <a href="#" class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-300">
                    <i class="fab fa-facebook-f text-blue-600"></i>
                </a>
                <a href="#" class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-300">
                    <i class="fab fa-twitter text-blue-400"></i>
                </a>
            </div>
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
            const passwordToggle = document.querySelector('.password-toggle');
            if (passwordToggle) {
                passwordToggle.addEventListener('click', function() {
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
            }
            
            // Form validation
            const form = document.getElementById('login-form');
            form.addEventListener('submit', function(event) {
                let isValid = true;
                
                // Reset previous errors
                document.querySelectorAll('.input-error').forEach(el => {
                    el.classList.remove('input-error');
                });
                document.querySelectorAll('.error-message').forEach(el => {
                    el.remove();
                });
                
                // Validate required fields
                const requiredFields = ['email', 'password'];
                
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