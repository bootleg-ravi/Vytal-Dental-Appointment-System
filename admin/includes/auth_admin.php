<?php
if (session_status() === PHP_SESSION_NONE) session_start();

class AdminAuth {
    private static array $permissions = [
        'admin' => [
            'view_dashboard',
            'manage_appointments',
            'manage_patients',
            'manage_doctors',
            'manage_services',
            'view_reports',
            'view_activity_logs',
            'view_accessibility_logs',
            'manage_staff',          
            'delete_records',        
            'export_data',
            'manage_settings',       
        ],
        'staff' => [
            'view_dashboard',
            'manage_appointments',
            'manage_patients',
            'view_reports',
            'view_activity_logs',
        ],
    ];

    public static function requireLogin(): void {
        if (!isset($_SESSION['admin_id'])) {
            header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
            exit;
        }
    }

    public static function requireRole(string $role): void {
        self::requireLogin();
        $current = self::role();
        if ($role === 'admin' && $current !== 'admin') {
            self::denyAccess("This section requires Admin privileges.");
        }
    }

    public static function can(string $permission): bool {
        $role = self::role();
        return in_array($permission, self::$permissions[$role] ?? []);
    }


    public static function denyAccess(string $msg = 'Access denied.'): void {
        http_response_code(403);
        if (isset($GLOBALS['conn'])) {
            $uid  = self::id();
            $name = $GLOBALS['conn']->real_escape_string(self::name());
            $uri  = $GLOBALS['conn']->real_escape_string($_SERVER['REQUEST_URI'] ?? '');
            $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $GLOBALS['conn']->query("INSERT INTO activity_logs
                (user_id, user_type, user_name, action, description, ip_address)
                VALUES ($uid, 'admin', '$name', 'ACCESS_DENIED', 'Attempted: $uri', '$ip')");
        }
        include __DIR__ . '/partials/403.php';
        exit;
    }


    public static function id(): int   { return (int)($_SESSION['admin_id']   ?? 0); }
    public static function name(): string { return $_SESSION['admin_name'] ?? 'Admin'; }
    public static function role(): string { return $_SESSION['admin_role'] ?? 'staff'; }


    public static function login(array $user): void {
        $_SESSION['admin_id']   = $user['id'];
        $_SESSION['admin_name'] = $user['username'];
        $_SESSION['admin_role'] = $user['role'] ?? 'staff';
    }


    public static function logout(): void {
        $_SESSION = [];
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        session_destroy();
    }

    public static function navItems(): array {
        $all = [
            ['href'=>'dashboard.php',           'icon'=>'bi-speedometer2',   'label'=>'Dashboard',         'perm'=>'view_dashboard'],
            ['href'=>'manage_appointments.php',  'icon'=>'bi-calendar-check', 'label'=>'Appointments',      'perm'=>'manage_appointments'],
            ['href'=>'manage_patients.php',      'icon'=>'bi-people',         'label'=>'Patients',          'perm'=>'manage_patients'],
            ['href'=>'manage_doctors.php',       'icon'=>'bi-person-badge',   'label'=>'Dentists',          'perm'=>'manage_doctors'],
            ['href'=>'manage_services.php',      'icon'=>'bi-tooth',          'label'=>'Services',          'perm'=>'manage_services'],
            ['href'=>'patient_flow.php',         'icon'=>'bi-bar-chart-line', 'label'=>'Patient Flow',      'perm'=>'view_reports'],
            ['href'=>'reports.php',              'icon'=>'bi-file-earmark-text','label'=>'Reports',         'perm'=>'view_reports'],
            ['href'=>'activity_logs.php',        'icon'=>'bi-clipboard-data', 'label'=>'Activity Logs',     'perm'=>'view_activity_logs'],
            ['href'=>'accessibility_report.php', 'icon'=>'bi-universal-access','label'=>'Accessibility',   'perm'=>'view_accessibility_logs'],
        ];
        return array_filter($all, fn($item) => self::can($item['perm']));
    }
}

AdminAuth::requireLogin();
?>