<?php
class Validator {
    
    public static function sanitizeString($input) {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    public static function validateEmail($email) {
        $email = trim($email);
        if (empty($email)) {
            return ['valid' => false, 'message' => 'Email is required.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'message' => 'Invalid email format.'];
        }
        return ['valid' => true];
    }
    
 
    public static function validatePhone($phone) {
        $phone = trim($phone);
        if (empty($phone)) {
            return ['valid' => true]; 
        }
        
        $phone_clean = preg_replace('/[\s\-\(\)]/', '', $phone);
        
        if (!preg_match('/^(\+?63|0)?9\d{9}$/', $phone_clean)) {
            return ['valid' => false, 'message' => 'Invalid phone number format. Use 09xxxxxxxxx format.'];
        }
        
        return ['valid' => true];
    }
    
    public static function validatePassword($password, $min_length = 6) {
        if (empty($password)) {
            return ['valid' => false, 'message' => 'Password is required.'];
        }
        if (strlen($password) < $min_length) {
            return ['valid' => false, 'message' => "Password must be at least {$min_length} characters long."];
        }
        return ['valid' => true];
    }
    
    public static function validateDate($date, $allow_past = false) {
        if (empty($date)) {
            return ['valid' => false, 'message' => 'Date is required.'];
        }
        
        $date_obj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $date) {
            return ['valid' => false, 'message' => 'Invalid date format.'];
        }
        
        if (!$allow_past) {
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            if ($date_obj < $today) {
                return ['valid' => false, 'message' => 'Date cannot be in the past.'];
            }
        }
        
        return ['valid' => true];
    }
    
    public static function validateTime($time) {
        if (empty($time)) {
            return ['valid' => false, 'message' => 'Time is required.'];
        }
        
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $time)) {
            return ['valid' => false, 'message' => 'Invalid time format.'];
        }
        
        return ['valid' => true];
    }
    
    public static function validateName($name) {
        $name = trim($name);
        if (empty($name)) {
            return ['valid' => false, 'message' => 'Name is required.'];
        }
        if (strlen($name) < 2) {
            return ['valid' => false, 'message' => 'Name must be at least 2 characters long.'];
        }
        if (!preg_match('/^[a-zA-Z\s\-\.]+$/', $name)) {
            return ['valid' => false, 'message' => 'Name can only contain letters, spaces, hyphens, and periods.'];
        }
        return ['valid' => true];
    }

    public static function validatePositiveNumber($number, $field_name = 'Number') {
        if (!is_numeric($number)) {
            return ['valid' => false, 'message' => "{$field_name} must be a number."];
        }
        if ($number < 0) {
            return ['valid' => false, 'message' => "{$field_name} must be positive."];
        }
        return ['valid' => true];
    }
    

    public static function validateInteger($value, $field_name = 'Value') {
        if (!filter_var($value, FILTER_VALIDATE_INT)) {
            return ['valid' => false, 'message' => "{$field_name} must be a valid integer."];
        }
        return ['valid' => true];
    }
    

    public static function validateRequired($value, $field_name = 'Field') {
        if (empty(trim($value))) {
            return ['valid' => false, 'message' => "{$field_name} is required."];
        }
        return ['valid' => true];
    }
    

    public static function validateSelect($value, $allowed_options, $field_name = 'Option') {
        if (!in_array($value, $allowed_options)) {
            return ['valid' => false, 'message' => "Invalid {$field_name} selected."];
        }
        return ['valid' => true];
    }
    

    public static function validateImageUpload($file, $max_size = 2097152) { // 2MB default
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['valid' => true];
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'message' => 'File upload error.'];
        }
        
        if ($file['size'] > $max_size) {
            $max_mb = $max_size / 1048576;
            return ['valid' => false, 'message' => "File size must be less than {$max_mb}MB."];
        }
        
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types)) {
            return ['valid' => false, 'message' => 'Only JPG, PNG, and GIF images are allowed.'];
        }
        
        return ['valid' => true];
    }
    

    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    

    public static function verifyCSRFToken($token) {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    

    public static function sanitizeFilename($filename) {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        return $filename;
    }
}
?>
