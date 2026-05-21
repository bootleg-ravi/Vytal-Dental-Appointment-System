<?php
session_start();

if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once '../config/config.php';
require_once '../includes/PasswordReset.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $passwordReset = new PasswordReset($conn);
        $result = $passwordReset->sendResetLink($email, 'admin');
        
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Forgot Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        * { font-family: 'Inter', sans-serif; }
        body { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.2);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">
    <div class="glass-card p-8 w-full max-w-md">
        <div class="text-center mb-8">
            <div class="bg-gradient-to-r from-blue-600 to-blue-800 p-4 rounded-full inline-block mb-4">
                <i class="bi bi-shield-lock text-white text-4xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Admin Password Reset</h1>
            <p class="text-gray-600">Enter your admin email to reset password</p>
        </div>
        
        <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 p-4 mb-6 rounded">
            <p class="text-red-700"><?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="bg-green-100 border-l-4 border-green-500 p-4 mb-6 rounded">
            <p class="text-green-700"><?= htmlspecialchars($success) ?></p>
            <a href="login.php" class="text-green-800 font-semibold hover:underline mt-3 inline-block">
                ← Back to Admin Login
            </a>
        </div>
        <?php else: ?>
        
        <form method="POST" action="">
            <div class="mb-6">
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="bi bi-envelope mr-2"></i>Admin Email
                </label>
                <input type="email" name="email" required
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="admin@healthcare.com">
            </div>
            
            <button type="submit" 
                    class="w-full bg-gradient-to-r from-blue-600 to-blue-800 text-white py-3 rounded-lg font-semibold hover:shadow-lg transition-all">
                <i class="bi bi-send mr-2"></i>Send Reset Link
            </button>
        </form>
        
        <div class="mt-6 text-center">
            <a href="login.php" class="text-blue-600 font-semibold hover:text-blue-800">
                ← Back to Admin Login
            </a>
        </div>
        
        <?php endif; ?>
    </div>
</body>
</html>
