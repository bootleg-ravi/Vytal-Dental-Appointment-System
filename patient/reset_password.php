<?php
session_start();

if (isset($_SESSION['patient_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once '../config/config.php';
require_once '../includes/PasswordReset.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: forgot_password.php');
    exit;
}

$passwordReset = new PasswordReset($conn);
$verification = $passwordReset->verifyToken($token, 'patient');

if (!$verification['valid']) {
    $error = $verification['message'];
    $invalid_token = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($invalid_token)) {
    $new_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $result = $passwordReset->resetPassword($token, $new_password, 'patient');
        
        if ($result['success']) {
            $success = $result['message'];
            $password_reset_success = true;
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
    <title>Reset Password - Patient Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        * { font-family: 'Inter', sans-serif; }
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.2);
        }
        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 8px;
            transition: all 0.3s ease;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">
    <div class="glass-card p-8 w-full max-w-md">
        <div class="text-center mb-8">
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 p-4 rounded-full inline-block mb-4">
                <i class="bi bi-shield-lock text-white text-4xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Reset Password</h1>
            <p class="text-gray-600">Create a new password for your account</p>
        </div>
        
        <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 p-4 mb-6 rounded">
            <div class="flex items-center gap-3">
                <i class="bi bi-exclamation-triangle-fill text-red-500 text-xl"></i>
                <p class="text-red-700"><?= htmlspecialchars($error) ?></p>
            </div>
            <?php if (isset($invalid_token)): ?>
            <a href="forgot_password.php" class="text-red-700 font-semibold hover:underline mt-3 inline-block">
                Request a new reset link →
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($success && isset($password_reset_success)): ?>
        <div class="bg-green-100 border-l-4 border-green-500 p-4 mb-6 rounded">
            <div class="flex items-center gap-3">
                <i class="bi bi-check-circle-fill text-green-500 text-xl"></i>
                <p class="text-green-700"><?= htmlspecialchars($success) ?></p>
            </div>
            <a href="login.php" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-semibold mt-4 inline-block transition-all">
                Proceed to Login →
            </a>
        </div>
        <?php elseif (!isset($invalid_token)): ?>
        
        <form method="POST" action="" id="resetForm">
            <div class="mb-4">
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="bi bi-lock mr-2"></i>New Password
                </label>
                <input type="password" name="password" id="password" required minlength="6"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                       placeholder="Enter new password">
                <div id="passwordStrength" class="password-strength"></div>
                <p class="text-xs text-gray-500 mt-2">Minimum 6 characters</p>
            </div>
            
            <div class="mb-6">
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                    <i class="bi bi-lock-fill mr-2"></i>Confirm Password
                </label>
                <input type="password" name="confirm_password" id="confirm_password" required minlength="6"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                       placeholder="Confirm new password">
                <p id="matchMessage" class="text-xs mt-2"></p>
            </div>
            
            <button type="submit" id="submitBtn"
                    class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-3 rounded-lg font-semibold hover:shadow-lg transition-all">
                <i class="bi bi-check-circle mr-2"></i>Reset Password
            </button>
        </form>
        
        <div class="mt-6 text-center">
            <a href="login.php" class="text-indigo-600 font-semibold hover:text-indigo-800">
                ← Back to Login
            </a>
        </div>
        
        <?php endif; ?>
    </div>
    
    <script>
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const strengthBar = document.getElementById('passwordStrength');
        const matchMessage = document.getElementById('matchMessage');
        const submitBtn = document.getElementById('submitBtn');
        
        if (password) {
            password.addEventListener('input', function() {
                const value = this.value;
                const length = value.length;
                
                if (length === 0) {
                    strengthBar.style.width = '0%';
                    strengthBar.style.backgroundColor = '';
                } else if (length < 6) {
                    strengthBar.style.width = '33%';
                    strengthBar.style.backgroundColor = '#ef4444';
                } else if (length < 10) {
                    strengthBar.style.width = '66%';
                    strengthBar.style.backgroundColor = '#f59e0b';
                } else {
                    strengthBar.style.width = '100%';
                    strengthBar.style.backgroundColor = '#10b981';
                }
            });
        }
        
        if (confirmPassword) {
            confirmPassword.addEventListener('input', function() {
                if (this.value === '') {
                    matchMessage.textContent = '';
                    matchMessage.className = 'text-xs mt-2';
                } else if (this.value === password.value) {
                    matchMessage.textContent = '✓ Passwords match';
                    matchMessage.className = 'text-xs mt-2 text-green-600';
                    submitBtn.disabled = false;
                } else {
                    matchMessage.textContent = '✗ Passwords do not match';
                    matchMessage.className = 'text-xs mt-2 text-red-600';
                    submitBtn.disabled = true;
                }
            });
        }
    </script>
</body>
</html>
