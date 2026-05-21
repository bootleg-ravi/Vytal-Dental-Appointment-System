<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/EmailService.php';

class PasswordReset {
    private $conn;
    private $emailService;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
        $this->emailService = new EmailService();
    }
    
    public function sendResetLink($email, $user_type = 'patient') {
        $table = ($user_type === 'admin') ? 'admins' : 'patients';
        $stmt = $this->conn->prepare("SELECT id, name, email FROM $table WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (!$user) {
            return ['success' => false, 'message' => 'Email address not found.'];
        }
        
        $token = bin2hex(random_bytes(32));
        
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $stmt = $this->conn->prepare("DELETE FROM password_resets WHERE email = ? AND user_type = ?");
        $stmt->bind_param('ss', $email, $user_type);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $this->conn->prepare("INSERT INTO password_resets (email, token, user_type, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $email, $token, $user_type, $expires_at);
        
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'message' => 'Failed to generate reset token.'];
        }
        $stmt->close();
        
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
        $reset_path = ($user_type === 'admin') ? '/admin/reset_password.php' : '/patient/reset_password.php';
        $reset_link = $base_url . dirname($_SERVER['PHP_SELF']) . $reset_path . '?token=' . $token;
        
        $email_sent = $this->emailService->sendPasswordReset($email, $user['name'], $reset_link);
        
        if ($email_sent) {
            return ['success' => true, 'message' => 'Password reset link sent to your email.'];
        } else {
            return ['success' => false, 'message' => 'Failed to send reset email. Please try again.'];
        }
    }
    
    public function verifyToken($token, $user_type = 'patient') {
        $stmt = $this->conn->prepare("SELECT email, expires_at, used FROM password_resets 
                                       WHERE token = ? AND user_type = ? AND used = 0");
        $stmt->bind_param('ss', $token, $user_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $reset = $result->fetch_assoc();
        $stmt->close();
        
        if (!$reset) {
            return ['valid' => false, 'message' => 'Invalid or already used reset token.'];
        }
        
        if (strtotime($reset['expires_at']) < time()) {
            return ['valid' => false, 'message' => 'Reset token has expired. Please request a new one.'];
        }
        
        return ['valid' => true, 'email' => $reset['email']];
    }
    
    public function resetPassword($token, $new_password, $user_type = 'patient') {
        $verification = $this->verifyToken($token, $user_type);
        
        if (!$verification['valid']) {
            return ['success' => false, 'message' => $verification['message']];
        }
        
        $email = $verification['email'];
        
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $table = ($user_type === 'admin') ? 'admins' : 'patients';
        $stmt = $this->conn->prepare("UPDATE $table SET password = ? WHERE email = ?");
        $stmt->bind_param('ss', $hashed_password, $email);
        
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'message' => 'Failed to update password.'];
        }
        $stmt->close();
        
        $stmt = $this->conn->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
        
        return ['success' => true, 'message' => 'Password reset successfully. You can now login with your new password.'];
    }
    

    public function cleanupExpiredTokens() {
        $stmt = $this->conn->prepare("DELETE FROM password_resets WHERE expires_at < NOW() OR used = 1");
        return $stmt->execute();
    }
}
?>
