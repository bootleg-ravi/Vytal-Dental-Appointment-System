<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    $doc_id = (int)($_GET['doctor'] ?? 0);

    $where = "a.date='$date' AND a.status NOT IN ('cancelled')";
    if ($doc_id) $where .= " AND a.doctor_id=$doc_id";

    $res = $conn->query("SELECT a.id, a.time, a.status, a.attendance_status,
            COALESCE(p.name, a.guest_name) AS patient_name,
            COALESCE(p.email, a.guest_email) AS patient_email,
            COALESCE(p.phone, a.guest_phone) AS patient_phone,
            d.name AS doctor, s.name AS service, s.price, s.duration_minutes,
            a.chief_complaint
        FROM appointments a
        JOIN doctors d ON a.doctor_id=d.id
        LEFT JOIN services s  ON a.service_id=s.id
        LEFT JOIN patients p  ON a.patient_id=p.id
        WHERE $where ORDER BY a.time ASC");

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="schedule_' . $date . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#','Time','Patient','Email','Phone','Dentist','Service','Fee','Duration','Status','Attendance','Chief Complaint']);
    $i = 1;
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $i++,
            date('g:i A', strtotime($r['time'])),
            $r['patient_name'],
            $r['patient_email'],
            $r['patient_phone'],
            $r['doctor'],
            $r['service'],
            $r['price'] ? '₱'.$r['price'] : '',
            $r['duration_minutes'] ? $r['duration_minutes'].'min' : '',
            $r['status'],
            $r['attendance_status'],
            $r['chief_complaint'],
        ]);
    }
    fclose($out);
    $conn->close();
    exit;
}


$view_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']   ?? '') ? $_GET['date']   : date('Y-m-d');
$view_doctor = (int)($_GET['doctor'] ?? 0);
$view_status = in_array($_GET['status'] ?? '', ['','pending','confirmed','completed','no_show']) ? ($_GET['status'] ?? '') : '';


$doctors = [];
$res = $conn->query("SELECT id, name, specialty FROM doctors WHERE is_active=1 ORDER BY name");
while ($r = $res->fetch_assoc()) $doctors[] = $r;

$where  = "a.date='$view_date' AND a.status NOT IN ('cancelled')";
if ($view_doctor) $where .= " AND a.doctor_id=$view_doctor";
if ($view_status) {
    if ($view_status === 'no_show') $where .= " AND a.attendance_status='no_show'";
    else $where .= " AND a.status='$view_status'";
}

$appointments = [];
$res = $conn->query("SELECT a.id, a.time, a.status, a.attendance_status,
        COALESCE(p.name, a.guest_name) AS patient_name,
        COALESCE(p.email, a.guest_email) AS patient_email,
        COALESCE(p.phone, a.guest_phone) AS patient_phone,
        d.name AS doctor, d.specialty,
        s.name AS service, s.price, s.duration_minutes, s.category,
        a.chief_complaint, a.is_guest,
        p.allergies, p.dental_notes
    FROM appointments a
    JOIN doctors d ON a.doctor_id=d.id
    LEFT JOIN services s  ON a.service_id=s.id
    LEFT JOIN patients p  ON a.patient_id=p.id
    WHERE $where ORDER BY a.time ASC, d.name ASC");
while ($r = $res->fetch_assoc()) $appointments[] = $r;


$all_day = $conn->query("SELECT status, attendance_status,
        s.price, s.duration_minutes
    FROM appointments a
    LEFT JOIN services s ON a.service_id=s.id
    WHERE a.date='$view_date' AND a.status NOT IN ('cancelled')")->fetch_all(MYSQLI_ASSOC);

$day_stats = [
    'total'     => count($all_day),
    'confirmed' => count(array_filter($all_day, fn($r) => $r['status']==='confirmed')),
    'pending'   => count(array_filter($all_day, fn($r) => $r['status']==='pending')),
    'completed' => count(array_filter($all_day, fn($r) => $r['status']==='completed')),
    'attended'  => count(array_filter($all_day, fn($r) => $r['attendance_status']==='attended')),
    'no_show'   => count(array_filter($all_day, fn($r) => $r['attendance_status']==='no_show')),
    'revenue'   => array_sum(array_column(array_filter($all_day, fn($r) => $r['status']==='completed'), 'price')),
    'chair_mins'=> array_sum(array_column($all_day, 'duration_minutes')),
];


$by_doctor = [];
foreach ($appointments as $a) {
    $by_doctor[$a['doctor']][] = $a;
}


$week_start = date('Y-m-d', strtotime('monday this week', strtotime($view_date)));
$week_glance = [];
for ($i = 0; $i < 7; $i++) {
    $d   = date('Y-m-d', strtotime("+$i days", strtotime($week_start)));
    $cnt = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments
        WHERE date='$d' AND status NOT IN ('cancelled')")->fetch_assoc()['c'];
    $week_glance[] = ['date'=>$d, 'count'=>$cnt, 'is_view'=>$d===$view_date, 'is_today'=>$d===date('Y-m-d')];
}

$conn->close();


$status_cfg = [
    'pending'   => ['label'=>'Pending',   'bg'=>'#fffbeb','color'=>'#92400e','icon'=>'bi-hourglass-split'],
    'confirmed' => ['label'=>'Confirmed', 'bg'=>'#f0fdf4','color'=>'#14532d','icon'=>'bi-check-circle'],
    'completed' => ['label'=>'Completed', 'bg'=>'#eff6ff','color'=>'#1e3a8a','icon'=>'bi-check2-all'],
];
$attend_cfg = [
    'attended' => ['bg'=>'#f0fdf4','color'=>'#14532d','label'=>'Attended'],
    'no_show'  => ['bg'=>'#fef2f2','color'=>'#991b1b','label'=>'No Show'],
    'pending'  => ['bg'=>'#f9fafb','color'=>'#6b7280','label'=>'Pending'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Daily Schedule – <?= $view_date ?> – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Fraunces:ital,wght@0,700;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{
    --bg:#f7f5f0;--bg2:#fff;--sidebar:#111827;
    --border:#e8e3da;--teal:#0d9488;--teal2:#0f766e;--teal-lt:#f0fdf9;
    --amber:#d97706;--red:#dc2626;--green:#16a34a;--blue:#2563eb;
    --ink:#111827;--slate:#6b7280;--muted:#9ca3af;
    --radius:14px;--shadow:0 1px 12px rgba(0,0,0,.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Lato',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex}

.sidebar{width:230px;background:var(--sidebar);min-height:100vh;position:fixed;left:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100;overflow-y:auto}
.sidebar-logo{padding:22px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px;font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:#fff}
.sidebar-logo i{color:#00c9a7;font-size:1.3rem}
.sidebar-role {
    margin: 0 12px 8px;
    padding: 7px 12px;
    background: rgba(255,255,255,.05);
    border-radius: 8px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: rgba(255,255,255,.4);
    display: flex; align-items: center; gap: 6px;
    margin-top: 14px;
}
.sidebar-role .role-badge {
    background: <?= $is_admin ? '#00c9a7' : '#f59e0b' ?>;
    color: #111;
    border-radius: 4px;
    padding: 1px 6px;
    font-size: .65rem;
}
.nav-section{padding:14px 16px 4px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28)}
.nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,.55);text-decoration:none;font-size:.84rem;font-weight:500;transition:all .18s;position:relative}
.nav-item:hover{color:#fff;background:rgba(255,255,255,.05)}
.nav-item.active{color:#00c9a7;background:rgba(0,201,167,.08)}
.nav-item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:#00c9a7;border-radius:0 3px 3px 0}
.nav-item i{font-size:.95rem;width:18px;text-align:center}
.sidebar-footer{margin-top:auto;padding:14px;border-top:1px solid rgba(255,255,255,.07)}
.admin-chip{display:flex;align-items:center;gap:10px;padding:9px 12px;background:rgba(255,255,255,.06);border-radius:10px}
.admin-avatar{width:32px;height:32px;border-radius:50%;background:#00c9a7;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700;color:#111;font-size:.9rem;flex-shrink:0}
.admin-info .name{font-size:.82rem;font-weight:700;color:#fff}
.admin-info .role{font-size:.68rem;color:rgba(255,255,255,.4);text-transform:capitalize}

.main{margin-left:230px;flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;gap:12px;flex-wrap:wrap}
.topbar-left h1{font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800;color:var(--ink)}
.topbar-left p{font-size:.78rem;color:var(--slate);margin-top:1px}
.topbar-right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .2s;text-decoration:none;white-space:nowrap}
.btn-teal{background:var(--teal);color:#fff}.btn-teal:hover{background:var(--teal2)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--slate)}.btn-ghost:hover{border-color:var(--ink);color:var(--ink)}
.btn-print{background:#111827;color:#fff}.btn-print:hover{background:#1f2937}
.content{padding:24px 32px}

.date-nav{display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.date-nav-arrow{display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;border:1px solid var(--border);background:var(--bg2);color:var(--slate);text-decoration:none;font-size:.9rem;transition:all .15s}
.date-nav-arrow:hover{border-color:var(--teal);color:var(--teal)}
.date-label{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:var(--ink)}
.date-sub{font-size:.78rem;color:var(--slate);margin-left:4px}
.today-btn{font-size:.75rem}

.week-strip{display:flex;gap:6px;margin-bottom:22px}
.wday{flex:1;border:1px solid var(--border);border-radius:10px;padding:8px 6px;text-align:center;text-decoration:none;transition:all .2s}
.wday:hover{border-color:var(--teal);background:var(--teal-lt)}
.wday.active{border-color:var(--teal);background:var(--teal);color:#fff}
.wday.today:not(.active){border-color:var(--teal);background:var(--teal-lt)}
.wday .wd-dow{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:inherit;opacity:.7;margin-bottom:2px}
.wday .wd-day{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;color:inherit;line-height:1}
.wday .wd-cnt{font-size:.62rem;font-weight:700;color:inherit;opacity:.7;margin-top:3px}
.wday.active .wd-dow,.wday.active .wd-cnt{color:#fff;opacity:.8}

.stat-row{display:grid;grid-template-columns:repeat(7,1fr);gap:10px;margin-bottom:22px}
@media(max-width:1200px){.stat-row{grid-template-columns:repeat(4,1fr)}}
@media(max-width:768px) {.stat-row{grid-template-columns:repeat(2,1fr)}}
.stat-mini{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:12px 14px;box-shadow:var(--shadow)}
.sm-val{font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:var(--ink);line-height:1}
.sm-lbl{font-size:.68rem;color:var(--slate);margin-top:3px}

.filter-bar{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.filter-bar label{font-size:.76rem;font-weight:700;color:var(--slate)}
.filter-bar select,.filter-bar input[type=date]{border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:.8rem;font-family:'Lato',sans-serif;color:var(--ink);background:var(--bg);outline:none;cursor:pointer}
.filter-bar select:focus,.filter-bar input:focus{border-color:var(--teal)}

.schedule-wrap{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.schedule-table{width:100%;border-collapse:collapse;font-size:.82rem}
.schedule-table th{text-align:left;padding:10px 14px;background:var(--bg);color:var(--slate);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--border);white-space:nowrap}
.schedule-table td{padding:12px 14px;border-bottom:1px solid var(--border);color:var(--ink);vertical-align:top}
.schedule-table tr:last-child td{border-bottom:none}
.schedule-table tbody tr:hover td{background:#fafaf8}
.row-no-show td{background:#fff5f5 !important;opacity:.85}
.row-completed td{background:#f9fffe !important}

.time-chip{font-family:'Syne',sans-serif;font-size:.82rem;font-weight:800;color:var(--teal);white-space:nowrap}
.appt-num{display:inline-block;width:22px;height:22px;border-radius:50%;background:var(--bg);border:1px solid var(--border);font-size:.65rem;font-weight:700;text-align:center;line-height:21px;color:var(--slate);margin-right:4px;flex-shrink:0}
.patient-name{font-weight:700;color:var(--ink)}
.patient-meta{font-size:.72rem;color:var(--slate);margin-top:2px}
.patient-note{font-size:.7rem;color:var(--amber);margin-top:3px;font-style:italic}
.guest-tag{display:inline-block;background:#fdf4ff;color:#7e22ce;font-size:.62rem;font-weight:700;padding:1px 6px;border-radius:20px;margin-left:4px}
.doctor-cell{font-size:.8rem;color:var(--ink);font-weight:600}
.doctor-spec{font-size:.68rem;color:var(--muted);margin-top:1px}
.service-cell{font-size:.8rem;font-weight:600;color:var(--ink)}
.service-meta{font-size:.68rem;color:var(--slate);margin-top:1px}
.price-chip{font-family:'Syne',sans-serif;font-size:.82rem;font-weight:700;color:var(--teal)}
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.attend-pill{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:.67rem;font-weight:700;white-space:nowrap}
.attend-select{border:1px solid var(--border);border-radius:6px;padding:3px 7px;font-size:.72rem;font-family:'Lato',sans-serif;cursor:pointer;background:var(--bg);outline:none;color:var(--ink)}
.attend-select:focus{border-color:var(--teal)}
.attend-select.attended{background:#f0fdf4;border-color:#86efac;color:#14532d}
.attend-select.no_show{background:#fef2f2;border-color:#fca5a5;color:var(--red)}
.action-btns{display:flex;gap:4px}
.ab{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:7px;border:none;cursor:pointer;font-size:.8rem;transition:all .15s;text-decoration:none}
.ab-view   {background:#eff6ff;color:var(--blue)}.ab-view:hover{background:var(--blue);color:#fff}
.ab-ok     {background:#f0fdf4;color:var(--green)}.ab-ok:hover{background:var(--green);color:#fff}
.ab-cancel {background:#fef2f2;color:var(--red)}.ab-cancel:hover{background:var(--red);color:#fff}

.doc-section{margin-bottom:28px}
.doc-section-header{display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--sidebar);border-radius:10px;margin-bottom:10px}
.doc-section-avatar{width:34px;height:34px;border-radius:50%;background:#00c9a7;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;color:#111;font-size:.85rem;flex-shrink:0}
.doc-section-name{font-family:'Syne',sans-serif;font-size:.9rem;font-weight:700;color:#fff}
.doc-section-spec{font-size:.7rem;color:rgba(255,255,255,.5)}
.doc-section-count{margin-left:auto;background:rgba(255,255,255,.12);color:rgba(255,255,255,.7);font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:20px}

.empty-state{text-align:center;padding:56px 20px;color:var(--muted)}
.empty-state i{font-size:3rem;display:block;margin-bottom:12px;opacity:.25}
.empty-state h3{font-family:'Syne',sans-serif;font-size:1rem;color:var(--slate);margin-bottom:6px}
.empty-state p{font-size:.82rem}

@media print {
    .sidebar,.topbar-right,.filter-bar,.date-nav,.week-strip,.action-btns,
    .attend-select,.btn-print,.no-print{display:none!important}
    body{background:#fff!important}
    .main{margin-left:0!important}
    .content{padding:0!important}
    .schedule-wrap{border:none!important;box-shadow:none!important}
    .schedule-table th{background:#f0f0f0!important;-webkit-print-color-adjust:exact}
    .status-pill,.attend-pill{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .doc-section-header{background:#222!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .print-header{display:block!important}
    @page{margin:15mm 12mm}
}
.print-header{display:none;font-family:'Lato',sans-serif;margin-bottom:16px;border-bottom:2px solid #111;padding-bottom:10px}
.print-header h2{font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800}
.print-header p{font-size:.78rem;color:#555;margin-top:3px}

@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:16px}.week-strip{display:grid;grid-template-columns:repeat(4,1fr)}}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-logo"><i class="bi bi-tooth"></i> Vytal Dental</div>
        <div class="sidebar-role">
        <span>Logged in as</span>
        <span class="role-badge"><?= strtoupper($admin_role) ?></span>
    </div>
    <div class="nav-section">Main</div>
    <a href="dashboard.php"            class="nav-item"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="manage_appointments.php"  class="nav-item"><i class="bi bi-calendar-check"></i> Appointments</a>
    <a href="manage_patients.php"      class="nav-item"><i class="bi bi-people"></i> Patients</a>
    <?php if ($is_admin): ?>
    <a href="manage_doctors.php"       class="nav-item"><i class="bi bi-person-badge"></i> Dentists</a>
    <a href="manage_services.php"      class="nav-item"><i class="bi bi-tooth"></i> Services</a>
    <?php endif; ?>
    <div class="nav-section">Analytics</div>
    <a href="patient_flow.php"         class="nav-item"><i class="bi bi-bar-chart-line"></i> Patient Flow</a>
    <a href="reports.php"              class="nav-item active"><i class="bi bi-file-earmark-text"></i> Reports</a>
    <a href="attendance_report.php"    class="nav-item"><i class="bi bi-person-check"></i> Attendance</a>
    <a href="activity_logs.php"        class="nav-item"><i class="bi bi-clipboard-data"></i> Activity Logs</a>
    <a href="accessibility_report.php" class="nav-item"><i class="bi bi-universal-access"></i> Accessibility</a>
    <div class="nav-section">Account</div>
    <a href="logout.php" class="nav-item" style="color:rgba(255,100,100,.6)"><i class="bi bi-box-arrow-right"></i> Logout</a>
    <div class="sidebar-footer">
        <div class="admin-chip">
            <div class="admin-avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div>
            <div class="admin-info">
                <div class="name"><?= htmlspecialchars($admin_name) ?></div>
                <div class="role"><?= $admin_role ?></div>
            </div>
        </div>
    </div>
</aside>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h1><i class="bi bi-file-earmark-text" style="color:var(--teal);margin-right:7px"></i>Daily Schedule Report</h1>
            <p><?= date('l, F j, Y', strtotime($view_date)) ?> &nbsp;·&nbsp; <?= count($appointments) ?> appointment<?= count($appointments)!==1?'s':'' ?></p>
        </div>
        <div class="topbar-right">
            <a href="attendance_report.php" class="btn btn-ghost"><i class="bi bi-person-check"></i> Attendance Report</a>
            <a href="?date=<?= $view_date ?>&doctor=<?= $view_doctor ?>&export=csv" class="btn btn-ghost">
                <i class="bi bi-download"></i> CSV
            </a>
            <button class="btn btn-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
    </div>

    <div class="content">

        <div class="print-header">
            <h2>🦷 Vytal Dental Clinic — Daily Schedule</h2>
            <p><?= date('l, F j, Y', strtotime($view_date)) ?> &nbsp;|&nbsp; Generated: <?= date('M j, Y g:i A') ?> &nbsp;|&nbsp; By: <?= htmlspecialchars($admin_name) ?></p>
        </div>

        <div class="date-nav no-print">
            <a href="?date=<?= date('Y-m-d', strtotime('-1 day', strtotime($view_date))) ?>&doctor=<?= $view_doctor ?>"
               class="date-nav-arrow" title="Previous day"><i class="bi bi-chevron-left"></i></a>
            <span class="date-label"><?= date('F j, Y', strtotime($view_date)) ?></span>
            <span class="date-sub"><?= date('l', strtotime($view_date)) ?></span>
            <a href="?date=<?= date('Y-m-d', strtotime('+1 day', strtotime($view_date))) ?>&doctor=<?= $view_doctor ?>"
               class="date-nav-arrow" title="Next day"><i class="bi bi-chevron-right"></i></a>
            <?php if ($view_date !== date('Y-m-d')): ?>
            <a href="?date=<?= date('Y-m-d') ?>&doctor=<?= $view_doctor ?>" class="btn btn-ghost today-btn">Today</a>
            <?php endif; ?>
        </div>

        <div class="week-strip no-print">
            <?php foreach ($week_glance as $wd): ?>
            <a href="?date=<?= $wd['date'] ?>&doctor=<?= $view_doctor ?>"
               class="wday <?= $wd['is_view']?'active':($wd['is_today']?'today':'') ?>">
                <div class="wd-dow"><?= date('D', strtotime($wd['date'])) ?></div>
                <div class="wd-day"><?= date('j', strtotime($wd['date'])) ?></div>
                <div class="wd-cnt"><?= $wd['count'] ?: '—' ?></div>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="stat-row">
            <div class="stat-mini"><div class="sm-val" style="color:var(--teal)"><?= $day_stats['total'] ?></div><div class="sm-lbl">Total</div></div>
            <div class="stat-mini"><div class="sm-val" style="color:var(--green)"><?= $day_stats['confirmed'] ?></div><div class="sm-lbl">Confirmed</div></div>
            <div class="stat-mini"><div class="sm-val" style="color:var(--amber)"><?= $day_stats['pending'] ?></div><div class="sm-lbl">Pending</div></div>
            <div class="stat-mini"><div class="sm-val" style="color:var(--blue)"><?= $day_stats['completed'] ?></div><div class="sm-lbl">Completed</div></div>
            <div class="stat-mini"><div class="sm-val" style="color:var(--green)"><?= $day_stats['attended'] ?></div><div class="sm-lbl">Attended</div></div>
            <div class="stat-mini"><div class="sm-val" style="color:var(--red)"><?= $day_stats['no_show'] ?></div><div class="sm-lbl">No Shows</div></div>
            <div class="stat-mini">
                <div class="sm-val" style="color:var(--teal)">₱<?= number_format($day_stats['revenue']) ?></div>
                <div class="sm-lbl">Revenue (completed)</div>
            </div>
        </div>

        <form method="GET" class="filter-bar no-print">
            <input type="hidden" name="date" value="<?= $view_date ?>">
            <label>Dentist</label>
            <select name="doctor" onchange="this.form.submit()">
                <option value="0">All Dentists</option>
                <?php foreach ($doctors as $doc): ?>
                <option value="<?= $doc['id'] ?>" <?= $view_doctor==$doc['id']?'selected':'' ?>>
                    <?= htmlspecialchars($doc['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <label>Status</label>
            <select name="status" onchange="this.form.submit()">
                <option value="">All</option>
                <option value="pending"   <?= $view_status==='pending'  ?'selected':'' ?>>Pending</option>
                <option value="confirmed" <?= $view_status==='confirmed'?'selected':'' ?>>Confirmed</option>
                <option value="completed" <?= $view_status==='completed'?'selected':'' ?>>Completed</option>
                <option value="no_show"   <?= $view_status==='no_show'  ?'selected':'' ?>>No Show</option>
            </select>
            <label>Jump to date</label>
            <input type="date" name="date" value="<?= $view_date ?>" onchange="this.form.submit()" style="cursor:pointer">
            <?php if ($view_doctor || $view_status): ?>
            <a href="?date=<?= $view_date ?>" class="btn btn-ghost" style="font-size:.76rem">Clear filters</a>
            <?php endif; ?>
        </form>

        <?php if (empty($appointments)): ?>
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h3>No appointments found</h3>
            <p>No scheduled appointments for this day matching your filters.</p>
        </div>
        <?php else: ?>

        <div class="print-only" style="display:none">
            <?php foreach ($by_doctor as $doc_name => $appts): ?>
            <div class="doc-section">
                <div class="doc-section-header">
                    <div class="doc-section-avatar"><?= strtoupper(substr($doc_name,0,1)) ?></div>
                    <div>
                        <div class="doc-section-name"><?= htmlspecialchars($doc_name) ?></div>
                        <div class="doc-section-spec"><?= htmlspecialchars($appts[0]['specialty'] ?? '') ?></div>
                    </div>
                    <span class="doc-section-count"><?= count($appts) ?> patient<?= count($appts)!==1?'s':'' ?></span>
                </div>
                <table class="schedule-table" style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
                    <thead><tr>
                        <th>#</th><th>Time</th><th>Patient</th><th>Service</th><th>Fee</th><th>Status</th><th>Attendance</th><th>Notes</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($appts as $i => $a):
                        $sc = $status_cfg[$a['status']]   ?? ['bg'=>'#f3f4f6','color'=>'#374151','label'=>ucfirst($a['status'])];
                        $ac = $attend_cfg[$a['attendance_status']] ?? $attend_cfg['pending'];
                    ?>
                    <tr class="<?= $a['attendance_status']==='no_show'?'row-no-show':($a['status']==='completed'?'row-completed':'') ?>">
                        <td style="color:var(--muted);font-size:.72rem"><?= $i+1 ?></td>
                        <td><span class="time-chip"><?= date('g:i A', strtotime($a['time'])) ?></span></td>
                        <td>
                            <div class="patient-name"><?= htmlspecialchars($a['patient_name'] ?? 'Guest') ?>
                                <?php if ($a['is_guest']): ?><span class="guest-tag">Guest</span><?php endif; ?>
                            </div>
                            <?php if ($a['patient_phone'] ?? null): ?>
                            <div class="patient-meta"><i class="bi bi-telephone" style="font-size:.65rem"></i> <?= htmlspecialchars($a['patient_phone']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="service-cell"><?= htmlspecialchars($a['service'] ?? '—') ?></div>
                            <?php if ($a['duration_minutes']): ?>
                            <div class="service-meta"><i class="bi bi-clock" style="font-size:.65rem"></i> <?= $a['duration_minutes'] ?>min</div>
                            <?php endif; ?>
                        </td>
                        <td><?php if ($a['price']): ?><span class="price-chip">₱<?= number_format($a['price']) ?></span><?php else: ?>—<?php endif; ?></td>
                        <td><span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>"><?= $sc['label'] ?></span></td>
                        <td><span class="attend-pill" style="background:<?= $ac['bg'] ?>;color:<?= $ac['color'] ?>"><?= $ac['label'] ?></span></td>
                        <td style="font-size:.7rem;color:var(--slate);max-width:160px">
                            <?php if ($a['chief_complaint']): ?>
                            <span style="color:var(--amber)"><i class="bi bi-chat-dots"></i> <?= htmlspecialchars(mb_substr($a['chief_complaint'],0,60)) ?><?= mb_strlen($a['chief_complaint'])>60?'…':'' ?></span>
                            <?php endif; ?>
                            <?php if ($a['allergies']): ?>
                            <div style="color:var(--red);font-size:.67rem"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars(mb_substr($a['allergies'],0,40)) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="schedule-wrap screen-only">
            <div style="overflow-x:auto">
            <table class="schedule-table">
                <thead><tr>
                    <th>#</th>
                    <th>Time</th>
                    <th>Patient</th>
                    <th>Dentist</th>
                    <th>Service</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th>Attendance</th>
                    <th class="no-print">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($appointments as $i => $a):
                    $sc = $status_cfg[$a['status']]   ?? ['bg'=>'#f3f4f6','color'=>'#374151','label'=>ucfirst($a['status']),'icon'=>'bi-circle'];
                    $ac = $attend_cfg[$a['attendance_status']] ?? $attend_cfg['pending'];
                ?>
                <tr class="<?= $a['attendance_status']==='no_show'?'row-no-show':($a['status']==='completed'?'row-completed':'') ?>">
                    <td style="color:var(--muted);font-size:.72rem"><?= $i+1 ?></td>
                    <td><span class="time-chip"><?= date('g:i A', strtotime($a['time'])) ?></span></td>
                    <td>
                        <div class="patient-name"><?= htmlspecialchars($a['patient_name'] ?? 'Guest') ?>
                            <?php if ($a['is_guest']): ?><span class="guest-tag">Guest</span><?php endif; ?>
                        </div>
                        <?php if ($a['patient_phone'] ?? null): ?>
                        <div class="patient-meta"><i class="bi bi-telephone" style="font-size:.65rem"></i> <?= htmlspecialchars($a['patient_phone']) ?></div>
                        <?php endif; ?>
                        <?php if ($a['chief_complaint']): ?>
                        <div class="patient-note"><i class="bi bi-chat-dots"></i> <?= htmlspecialchars(mb_substr($a['chief_complaint'],0,50)) ?><?= mb_strlen($a['chief_complaint'])>50?'…':'' ?></div>
                        <?php endif; ?>
                        <?php if ($a['allergies']): ?>
                        <div class="patient-note" style="color:var(--red)"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars(mb_substr($a['allergies'],0,40)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="doctor-cell"><?= htmlspecialchars($a['doctor']) ?></div>
                        <div class="doctor-spec"><?= htmlspecialchars($a['specialty'] ?? '') ?></div>
                    </td>
                    <td>
                        <div class="service-cell"><?= htmlspecialchars($a['service'] ?? '—') ?></div>
                        <?php if ($a['duration_minutes']): ?>
                        <div class="service-meta"><?= $a['duration_minutes'] ?>min · <?= htmlspecialchars($a['category'] ?? '') ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php if ($a['price']): ?><span class="price-chip">₱<?= number_format($a['price']) ?></span><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
                    <td>
                        <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                            <i class="bi <?= $sc['icon'] ?>"></i> <?= $sc['label'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($is_admin): ?>
                        <select class="attend-select <?= $a['attendance_status'] ?>"
                                onchange="updateAttendance(<?= $a['id'] ?>, this)">
                            <option value="pending"  <?= $a['attendance_status']==='pending' ?'selected':'' ?>>⏳ Pending</option>
                            <option value="attended" <?= $a['attendance_status']==='attended'?'selected':'' ?>>✅ Attended</option>
                            <option value="no_show"  <?= $a['attendance_status']==='no_show' ?'selected':'' ?>>❌ No Show</option>
                        </select>
                        <?php else: ?>
                        <span class="attend-pill" style="background:<?= $ac['bg'] ?>;color:<?= $ac['color'] ?>"><?= $ac['label'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="no-print">
                        <div class="action-btns">
                            <a href="../patient/appointment_summary.php?id=<?= $a['id'] ?>" class="ab ab-view" title="View"><i class="bi bi-eye"></i></a>
                            <?php if ($a['status']==='pending'): ?>
                            <a href="approve_appointment.php?id=<?= $a['id'] ?>" class="ab ab-ok" title="Approve"><i class="bi bi-check"></i></a>
                            <a href="cancel_appointment.php?id=<?= $a['id'] ?>"  class="ab ab-cancel" title="Cancel"
                               onclick="return confirm('Cancel this appointment?')"><i class="bi bi-x"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script src="/assets/js/toast.js"></script>
<script>
const printDiv  = document.querySelector('.print-only');
const screenDiv = document.querySelector('.screen-only');
window.addEventListener('beforeprint', () => {
    if (printDiv)  printDiv.style.display  = 'block';
    if (screenDiv) screenDiv.style.display = 'none';
});
window.addEventListener('afterprint', () => {
    if (printDiv)  printDiv.style.display  = 'none';
    if (screenDiv) screenDiv.style.display = 'block';
});

<?php if ($is_admin): ?>
async function updateAttendance(id, sel) {
    sel.className = 'attend-select ' + sel.value;
    const fd = new FormData();
    fd.append('action','update_attendance');
    fd.append('id', id);
    fd.append('status', sel.value);
    const res = await fetch('patient_flow.php', {method:'POST',body:fd}).then(r=>r.json());
    if (res.ok) Toast.success('Attendance updated');
    else Toast.error('Could not update attendance');
}
<?php endif; ?>
</script>
</body>
</html>