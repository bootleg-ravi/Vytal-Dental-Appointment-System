<?php
class ActivityLogger {
    private $conn;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }
    
    public function log($user_id, $user_type, $user_name, $action, $description = '') {
        $ip_address = $this->getIpAddress();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt = $this->conn->prepare("INSERT INTO activity_logs (user_id, user_type, user_name, action, description, ip_address, user_agent) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issssss', $user_id, $user_type, $user_name, $action, $description, $ip_address, $user_agent);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
    
    private function getIpAddress() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        }
    }
    
    public function getRecentLogs($limit = 50, $user_type = null, $user_id = null) {
        $query = "SELECT * FROM activity_logs WHERE 1=1";
        $params = [];
        $types = '';
        
        if ($user_type !== null) {
            $query .= " AND user_type = ?";
            $params[] = $user_type;
            $types .= 's';
        }
        
        if ($user_id !== null) {
            $query .= " AND user_id = ?";
            $params[] = $user_id;
            $types .= 'i';
        }
        
        $query .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= 'i';
        
        $stmt = $this->conn->prepare($query);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = [];
        
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        
        $stmt->close();
        return $logs;
    }

    public function getLogsByDateRange($start_date, $end_date, $user_type = null) {
        $query = "SELECT * FROM activity_logs WHERE DATE(created_at) BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
        $types = 'ss';
        
        if ($user_type !== null) {
            $query .= " AND user_type = ?";
            $params[] = $user_type;
            $types .= 's';
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = [];
        
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        
        $stmt->close();
        return $logs;
    }
    
    public function getActionStats($days = 30) {
        $start_date = date('Y-m-d', strtotime("-$days days"));
        
        $stmt = $this->conn->prepare("SELECT action, COUNT(*) as count 
                                       FROM activity_logs 
                                       WHERE DATE(created_at) >= ? 
                                       GROUP BY action 
                                       ORDER BY count DESC");
        $stmt->bind_param('s', $start_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = [];
        
        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }
        
        $stmt->close();
        return $stats;
    }
}
?>
