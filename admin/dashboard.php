<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../config/config.php';
require_once '../includes/ActivityLogger.php';

if (!isset($_SESSION['admin_role'])) {
    $uid = (int)$_SESSION['admin_id'];
    $r   = $conn->query("SELECT role FROM users WHERE id=$uid LIMIT 1");
    $_SESSION['admin_role'] = $r ? ($r->fetch_assoc()['role'] ?? 'staff') : 'staff';
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';

$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));

$stats = [];

$q = fn($sql) => (int)$conn->query($sql)->fetch_assoc()['c'];

$stats['today_total']     = $q("SELECT COUNT(*) AS c FROM appointments WHERE date='$today' AND status NOT IN ('cancelled')");
$stats['today_confirmed'] = $q("SELECT COUNT(*) AS c FROM appointments WHERE date='$today' AND status='confirmed'");
$stats['today_pending']   = $q("SELECT COUNT(*) AS c FROM appointments WHERE date='$today' AND status='pending'");
$stats['today_completed'] = $q("SELECT COUNT(*) AS c FROM appointments WHERE date='$today' AND status='completed'");
$stats['week_total']      = $q("SELECT COUNT(*) AS c FROM appointments WHERE date >= '$week_start' AND status NOT IN ('cancelled')");
$stats['total_patients']  = $q("SELECT COUNT(*) AS c FROM patients");
$stats['pending_approvals']= $q("SELECT COUNT(*) AS c FROM appointments WHERE status='pending'");
$stats['reschedule_requests']= $q("SELECT COUNT(*) AS c FROM appointments WHERE reschedule_requested=1");

$flow_days = [];
for ($i = 0; $i < 7; $i++) {
    $d   = date('Y-m-d', strtotime("+$i days"));
    $dow = date('D', strtotime($d));

    $cap_r = $conn->query("SELECT SUM(max_patients * FLOOR(TIME_TO_SEC(TIMEDIFF(end_time,start_time))/(slot_duration_minutes*60))) AS cap
                           FROM dentist_schedule WHERE day_of_week='" . date('l', strtotime($d)) . "' AND is_active=1");
    $cap = (int)($cap_r->fetch_assoc()['cap'] ?? 0);

    $booked = $q("SELECT COUNT(*) AS c FROM appointments WHERE date='$d' AND status NOT IN ('cancelled')");

    $flow_days[] = [
        'date'    => $d,
        'dow'     => $dow,
        'day'     => date('j', strtotime($d)),
        'booked'  => $booked,
        'cap'     => $cap,
        'pct'     => $cap > 0 ? min(100, round($booked / $cap * 100)) : 0,
        'status'  => $cap === 0 ? 'closed' : ($booked >= $cap ? 'full' : ($booked >= $cap * .75 ? 'limited' : 'open')),
        'is_today'=> $d === $today,
    ];
}

$today_schedule = [];
$res = $conn->query("SELECT a.id, a.time, a.status,
                        p.name AS patient_name,
                        COALESCE(p.name, a.guest_name) AS name,
                        d.name AS doctor,
                        s.name AS service
                     FROM appointments a
                     JOIN doctors d ON a.doctor_id = d.id
                     LEFT JOIN services s ON a.service_id = s.id
                     LEFT JOIN patients p ON a.patient_id = p.id
                     WHERE a.date = '$today' AND a.status NOT IN ('cancelled')
                     ORDER BY a.time ASC");
while ($r = $res->fetch_assoc()) $today_schedule[] = $r;

$pending_appts = [];
$res = $conn->query("SELECT a.id, a.date, a.time,
                        COALESCE(p.name, a.guest_name) AS patient_name,
                        d.name AS doctor, s.name AS service,
                        a.reschedule_requested
                     FROM appointments a
                     JOIN doctors d ON a.doctor_id = d.id
                     LEFT JOIN services s ON a.service_id = s.id
                     LEFT JOIN patients p ON a.patient_id = p.id
                     WHERE a.status = 'pending'
                     ORDER BY a.date ASC, a.time ASC
                     LIMIT 8");
while ($r = $res->fetch_assoc()) $pending_appts[] = $r;

$recent_logs = [];
$res = $conn->query("SELECT user_name, action, description, created_at, user_type
                     FROM activity_logs ORDER BY created_at DESC LIMIT 6");
while ($r = $res->fetch_assoc()) $recent_logs[] = $r;

$top_services = [];
$res = $conn->query("SELECT s.name, COUNT(*) AS cnt
                     FROM appointments a
                     JOIN services s ON a.service_id = s.id
                     WHERE DATE_FORMAT(a.date,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')
                     AND a.status NOT IN ('cancelled')
                     GROUP BY s.id ORDER BY cnt DESC LIMIT 5");
while ($r = $res->fetch_assoc()) $top_services[] = $r;
$max_svc = $top_services[0]['cnt'] ?? 1;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Admin Dashboard – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Lato:ital,wght@0,300;0,400;0,700;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>

:root {
    --bg:       #f7f5f0;
    --bg2:      #ffffff;
    --sidebar:  #111827;
    --border:   #e8e3da;
    --teal:     #0d9488;
    --teal2:    #0f766e;
    --teal-lt:  #f0fdf9;
    --amber:    #d97706;
    --red:      #dc2626;
    --green:    #16a34a;
    --blue:     #2563eb;
    --ink:      #111827;
    --slate:    #6b7280;
    --muted:    #9ca3af;
    --radius:   14px;
    --shadow:   0 1px 12px rgba(0,0,0,.06);
    --shadow2:  0 4px 24px rgba(0,0,0,.10);
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Lato',sans-serif; background:var(--bg); color:var(--ink); min-height:100vh; display:flex; }


.sidebar {
    width: 230px;
    background: var(--sidebar);
    min-height: 100vh;
    position: fixed;
    left:0; top:0; bottom:0;
    display: flex;
    flex-direction: column;
    z-index: 100;
    overflow-y: auto;
}
.sidebar-logo {
    padding: 22px 20px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex; align-items: center; gap: 10px;
    font-family: 'Syne', sans-serif;
    font-size: 1.1rem; font-weight: 800;
    color: #fff; letter-spacing: -.01em;
}
.sidebar-logo i { color: #00c9a7; font-size: 1.3rem; }
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
.nav-section { padding: 14px 16px 4px; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: rgba(255,255,255,.28); }
.nav-item {
    display: flex; align-items: center; gap: 11px;
    padding: 10px 20px;
    color: rgba(255,255,255,.55);
    text-decoration: none; font-size: .84rem; font-weight: 500;
    transition: all .18s; position: relative;
}
.nav-item:hover { color: #fff; background: rgba(255,255,255,.05); }
.nav-item.active { color: #00c9a7; background: rgba(0,201,167,.08); }
.nav-item.active::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:#00c9a7; border-radius:0 3px 3px 0; }
.nav-item i { font-size:.95rem; width:18px; text-align:center; }
.nav-badge { margin-left:auto; background:#ef4444; color:#fff; font-size:.6rem; font-weight:700; padding:1px 6px; border-radius:20px; }
.nav-badge.amber { background: #f59e0b; }
.sidebar-footer { margin-top:auto; padding:14px; border-top:1px solid rgba(255,255,255,.07); }
.admin-chip { display:flex; align-items:center; gap:10px; padding:9px 12px; background:rgba(255,255,255,.06); border-radius:10px; }
.admin-avatar { width:32px; height:32px; border-radius:50%; background:#00c9a7; display:flex; align-items:center; justify-content:center; font-family:'Syne',sans-serif; font-weight:700; color:#111; font-size:.9rem; flex-shrink:0; }
.admin-info .name { font-size:.82rem; font-weight:700; color:#fff; }
.admin-info .role { font-size:.68rem; color:rgba(255,255,255,.4); text-transform:capitalize; }

.main { margin-left:230px; flex:1; display:flex; flex-direction:column; min-width:0; }
.topbar {
    background: var(--bg2);
    border-bottom: 1px solid var(--border);
    padding: 16px 32px;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top:0; z-index:50;
}
.topbar h1 { font-family:'Syne',sans-serif; font-size:1.25rem; font-weight:800; color:var(--ink); }
.topbar p  { font-size:.78rem; color:var(--slate); margin-top:1px; }
.topbar-right { display:flex; align-items:center; gap:10px; }
.btn { display:inline-flex; align-items:center; gap:6px; padding:8px 18px; border-radius:25px; border:none; font-family:'Lato',sans-serif; font-size:.82rem; font-weight:700; cursor:pointer; transition:all .2s; text-decoration:none; }
.btn-teal { background:var(--teal); color:#fff; }
.btn-teal:hover { background:var(--teal2); transform:translateY(-1px); }
.btn-ghost { background:transparent; border:1px solid var(--border); color:var(--slate); }
.btn-ghost:hover { border-color:var(--ink); color:var(--ink); }

.content { padding:28px 32px; }

.stat-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
@media (max-width:1100px) { .stat-grid { grid-template-columns:repeat(2,1fr); } }
.stat-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
    transition: transform .2s, box-shadow .2s;
}
.stat-card:hover { transform:translateY(-2px); box-shadow:var(--shadow2); }
.stat-card::after {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 80px; height: 80px;
    border-radius: 50%;
    opacity: .06;
    transform: translate(20px,-20px);
}
.stat-card.teal::after  { background:var(--teal);  }
.stat-card.amber::after { background:var(--amber); }
.stat-card.green::after { background:var(--green); }
.stat-card.blue::after  { background:var(--blue);  }
.stat-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; margin-bottom:14px; }
.stat-icon.teal  { background:var(--teal-lt); color:var(--teal); }
.stat-icon.amber { background:#fffbeb; color:var(--amber); }
.stat-icon.green { background:#f0fdf4; color:var(--green); }
.stat-icon.blue  { background:#eff6ff; color:var(--blue); }
.stat-value { font-family:'Syne',sans-serif; font-size:2rem; font-weight:800; color:var(--ink); line-height:1; margin-bottom:4px; }
.stat-label { font-size:.78rem; color:var(--slate); font-weight:500; }
.stat-sub   { font-size:.7rem; color:var(--muted); margin-top:3px; }

.alert-banner {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: var(--radius);
    padding: 14px 18px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: .85rem;
}
.alert-banner i { color: var(--amber); font-size: 1.1rem; flex-shrink:0; }
.alert-banner a { color: var(--amber); font-weight: 700; }

.two-col { display:grid; grid-template-columns:1fr 360px; gap:20px; margin-bottom:20px; }
@media (max-width:1100px) { .two-col { grid-template-columns:1fr; } }

.panel {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}
.panel-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.panel-header h3 { font-family:'Syne',sans-serif; font-size:.95rem; font-weight:700; color:var(--ink); display:flex; align-items:center; gap:8px; }
.panel-header h3 i { color:var(--teal); }
.panel-header a { font-size:.78rem; color:var(--teal); text-decoration:none; font-weight:600; }
.panel-header a:hover { text-decoration:underline; }
.panel-body { padding:16px 20px; }

.flow-strip { display:flex; gap:8px; margin-bottom:20px; }
.flow-day {
    flex:1; min-width:0;
    border: 2px solid var(--border);
    border-radius: 12px;
    padding: 12px 8px 10px;
    text-align: center;
    cursor: default;
    transition: all .18s;
    position: relative;
}
.flow-day.today { border-color: var(--teal); background: var(--teal-lt); }
.flow-day .fd-dow  { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin-bottom:3px; }
.flow-day .fd-day  { font-family:'Syne',sans-serif; font-size:1.05rem; font-weight:800; color:var(--ink); line-height:1; margin-bottom:8px; }
.flow-bar-wrap { height:40px; display:flex; align-items:flex-end; justify-content:center; margin-bottom:6px; }
.flow-bar { width:20px; border-radius:4px 4px 0 0; transition:height .4s ease; min-height:2px; }
.flow-bar.open    { background:var(--green); }
.flow-bar.limited { background:var(--amber); }
.flow-bar.full    { background:var(--red); }
.flow-bar.closed  { background:var(--border); }
.flow-day .fd-count { font-size:.68rem; font-weight:700; color:var(--slate); }
.flow-day .fd-pct   { font-size:.6rem; color:var(--muted); }

.schedule-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.schedule-table th { text-align:left; padding:8px 10px; background:var(--bg); color:var(--slate); font-weight:700; font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid var(--border); }
.schedule-table td { padding:10px; border-bottom:1px solid var(--border); color:var(--ink); vertical-align:middle; }
.schedule-table tr:last-child td { border-bottom:none; }
.schedule-table tr:hover td { background:var(--bg); }
.time-chip { font-family:'Syne',sans-serif; font-size:.78rem; font-weight:700; color:var(--teal); }
.status-pill { display:inline-flex; align-items:center; padding:3px 9px; border-radius:20px; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.status-pending   { background:#fffbeb; color:#92400e; }
.status-confirmed { background:#f0fdf4; color:#14532d; }
.status-completed { background:#eff6ff; color:#1e3a8a; }
.status-no_show   { background:#f9fafb; color:#6b7280; }

.pending-item { display:flex; align-items:flex-start; gap:12px; padding:12px 0; border-bottom:1px solid var(--border); }
.pending-item:last-child { border-bottom:none; padding-bottom:0; }
.pending-date { background:var(--bg); border-radius:8px; padding:6px 10px; text-align:center; flex-shrink:0; }
.pending-date .pd-day { font-family:'Syne',sans-serif; font-size:.95rem; font-weight:800; color:var(--teal); line-height:1; }
.pending-date .pd-mon { font-size:.6rem; font-weight:700; text-transform:uppercase; color:var(--muted); }
.pending-info { flex:1; min-width:0; }
.pending-info .pi-name    { font-size:.84rem; font-weight:700; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pending-info .pi-detail  { font-size:.74rem; color:var(--slate); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pending-info .pi-time    { font-size:.7rem; color:var(--teal); font-weight:700; margin-top:2px; }
.pending-actions { display:flex; gap:5px; flex-shrink:0; }
.pa-btn { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:8px; border:none; cursor:pointer; font-size:.85rem; transition:all .15s; text-decoration:none; }
.pa-btn.approve  { background:#f0fdf4; color:var(--green); }
.pa-btn.approve:hover { background:var(--green); color:#fff; }
.pa-btn.reject   { background:#fef2f2; color:var(--red); }
.pa-btn.reject:hover { background:var(--red); color:#fff; }
.pa-btn.view     { background:#eff6ff; color:var(--blue); }
.pa-btn.view:hover { background:var(--blue); color:#fff; }
.reschedule-flag { display:inline-block; font-size:.6rem; font-weight:700; background:#fffbeb; color:var(--amber); border-radius:4px; padding:1px 5px; margin-left:4px; }

.svc-bar-row { margin-bottom:12px; }
.svc-bar-meta { display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; font-size:.8rem; }
.svc-bar-name  { color:var(--ink); font-weight:600; }
.svc-bar-count { color:var(--teal); font-weight:700; }
.svc-bar-track { height:6px; background:var(--bg); border-radius:4px; overflow:hidden; }
.svc-bar-fill  { height:100%; background:var(--teal); border-radius:4px; transition:width .6s ease; }

.log-item { display:flex; gap:10px; padding:9px 0; border-bottom:1px solid var(--border); align-items:flex-start; }
.log-item:last-child { border-bottom:none; }
.log-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:5px; }
.log-dot.admin   { background:var(--blue); }
.log-dot.patient { background:var(--teal); }
.log-text { flex:1; min-width:0; }
.log-action { font-size:.78rem; font-weight:700; color:var(--ink); }
.log-desc   { font-size:.72rem; color:var(--slate); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.log-time   { font-size:.68rem; color:var(--muted); flex-shrink:0; }

.empty-state { text-align:center; padding:32px 16px; color:var(--muted); }
.empty-state i { font-size:2rem; display:block; margin-bottom:8px; opacity:.3; }
.empty-state p { font-size:.82rem; }

@media (max-width:768px) {
    .sidebar { display:none; }
    .main    { margin-left:0; }
    .content { padding:16px; }
    .stat-grid { grid-template-columns:repeat(2,1fr); }
    .flow-strip { display:grid; grid-template-columns:repeat(4,1fr); }
    .two-col { grid-template-columns:1fr; }
}
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
    <?php
    $nav_items = [
        ['dashboard.php',           'bi-speedometer2',    'Dashboard',     null,                             null],
        ['manage_appointments.php', 'bi-calendar-check',  'Appointments',  (string)$stats['pending_approvals'], 'red'],
        ['manage_patients.php',     'bi-people',          'Patients',      null,                             null],
        ['manage_doctors.php',      'bi-person-badge',    'Dentists',      null,                             null],
        ['manage_services.php',     'bi-tooth',           'Services',      null,                             null],
    ];
    $current = basename($_SERVER['PHP_SELF']);
    foreach ($nav_items as [$href, $icon, $label, $badge, $badge_color]):
        if (in_array($href, ['manage_doctors.php','manage_services.php']) && !$is_admin) continue;
        $active = $current === $href ? 'active' : '';
    ?>
    <a href="<?= $href ?>" class="nav-item <?= $active ?>">
        <i class="bi <?= $icon ?>"></i> <?= $label ?>
        <?php if ($badge && (int)$badge > 0): ?>
        <span class="nav-badge <?= $badge_color === 'amber' ? 'amber' : '' ?>"><?= $badge ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <div class="nav-section">Analytics</div>
    <a href="patient_flow.php"         class="nav-item <?= $current==='patient_flow.php'?'active':'' ?>"><i class="bi bi-bar-chart-line"></i> Patient Flow</a>
    <a href="reports.php"              class="nav-item <?= $current==='reports.php'?'active':'' ?>"><i class="bi bi-file-earmark-text"></i> Reports</a>
      <a href="attendance_report.php"   class="nav-item"><i class="bi bi-clipboard2-pulse"></i> Attendance</a>
    <a href="activity_logs.php"        class="nav-item <?= $current==='activity_logs.php'?'active':'' ?>"><i class="bi bi-clipboard-data"></i> Activity Logs</a>
    <a href="accessibility_report.php" class="nav-item <?= $current==='accessibility_report.php'?'active':'' ?>"><i class="bi bi-universal-access"></i> Accessibility</a>

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
        <div>
            <h1>Dashboard</h1>
            <p><?= date('l, F j, Y') ?></p>
        </div>
        <div class="topbar-right">
            <?php if ($stats['reschedule_requests'] > 0): ?>
            <a href="manage_appointments.php?filter=reschedule" class="btn btn-ghost">
                <i class="bi bi-arrow-repeat"></i> <?= $stats['reschedule_requests'] ?> Reschedule<?= $stats['reschedule_requests']>1?'s':'' ?>
            </a>
            <?php endif; ?>
            <a href="manage_appointments.php" class="btn btn-teal"><i class="bi bi-plus-lg"></i> New Appointment</a>
        </div>
    </div>

    <div class="content">

        <?php if ($stats['pending_approvals'] > 0): ?>
        <div class="alert-banner">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><strong><?= $stats['pending_approvals'] ?> appointment<?= $stats['pending_approvals']>1?'s':'' ?></strong> waiting for your approval.
            <a href="manage_appointments.php?filter=pending">Review now →</a></span>
        </div>
        <?php endif; ?>

        <div class="stat-grid">
            <div class="stat-card teal">
                <div class="stat-icon teal"><i class="bi bi-calendar-day"></i></div>
                <div class="stat-value"><?= $stats['today_total'] ?></div>
                <div class="stat-label">Today's Appointments</div>
                <div class="stat-sub"><?= $stats['today_confirmed'] ?> confirmed · <?= $stats['today_pending'] ?> pending</div>
            </div>
            <div class="stat-card amber">
                <div class="stat-icon amber"><i class="bi bi-hourglass-split"></i></div>
                <div class="stat-value"><?= $stats['pending_approvals'] ?></div>
                <div class="stat-label">Pending Approvals</div>
                <div class="stat-sub">Requires your action</div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon green"><i class="bi bi-people"></i></div>
                <div class="stat-value"><?= $stats['total_patients'] ?></div>
                <div class="stat-label">Total Patients</div>
                <div class="stat-sub">Registered accounts</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-icon blue"><i class="bi bi-calendar-week"></i></div>
                <div class="stat-value"><?= $stats['week_total'] ?></div>
                <div class="stat-label">This Week</div>
                <div class="stat-sub">Mon–Sun total</div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:20px">
            <div class="panel-header">
                <h3><i class="bi bi-bar-chart-line"></i> 7-Day Patient Flow</h3>
                <a href="patient_flow.php">Full tracker →</a>
            </div>
            <div class="panel-body">
                <div class="flow-strip">
                    <?php foreach ($flow_days as $fd):
                        $bar_h = max(2, round($fd['pct'] / 100 * 40));
                    ?>
                    <div class="flow-day <?= $fd['is_today'] ? 'today' : '' ?>" title="<?= $fd['date'] ?>: <?= $fd['booked'] ?>/<?= $fd['cap'] ?> booked">
                        <div class="fd-dow"><?= $fd['dow'] ?></div>
                        <div class="fd-day"><?= $fd['day'] ?></div>
                        <div class="flow-bar-wrap">
                            <div class="flow-bar <?= $fd['status'] ?>" style="height:<?= $bar_h ?>px"></div>
                        </div>
                        <div class="fd-count"><?= $fd['booked'] ?><?= $fd['cap']>0 ? '/'.$fd['cap'] : '' ?></div>
                        <div class="fd-pct"><?= $fd['cap']>0 ? $fd['pct'].'%' : 'closed' ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:16px;font-size:.72rem;color:var(--slate)">
                    <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--green);margin-right:4px"></span>Open</span>
                    <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--amber);margin-right:4px"></span>Limited</span>
                    <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--red);margin-right:4px"></span>Full</span>
                    <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--border);border:1px solid #ccc;margin-right:4px"></span>Closed</span>
                </div>
            </div>
        </div>

        <div class="two-col">

            <div class="panel">
                <div class="panel-header">
                    <h3><i class="bi bi-calendar-day"></i> Today's Schedule</h3>
                    <a href="reports.php?date=<?= $today ?>">Daily report →</a>
                </div>
                <div class="panel-body" style="padding:0">
                    <?php if (empty($today_schedule)): ?>
                    <div class="empty-state" style="padding:28px">
                        <i class="bi bi-calendar-x"></i>
                        <p>No appointments scheduled for today.</p>
                    </div>
                    <?php else: ?>
                    <div style="overflow-x:auto">
                    <table class="schedule-table">
                        <thead><tr>
                            <th>Time</th>
                            <th>Patient</th>
                            <th>Dentist</th>
                            <th>Service</th>
                            <th>Status</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($today_schedule as $appt): ?>
                        <tr>
                            <td><span class="time-chip"><?= date('g:i A', strtotime($appt['time'])) ?></span></td>
                            <td><?= htmlspecialchars($appt['name'] ?? 'Guest') ?></td>
                            <td style="color:var(--slate);font-size:.78rem"><?= htmlspecialchars($appt['doctor']) ?></td>
                            <td style="color:var(--slate);font-size:.78rem;white-space:nowrap"><?= htmlspecialchars($appt['service'] ?? '—') ?></td>
                            <td><span class="status-pill status-<?= $appt['status'] ?>"><?= $appt['status'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:16px">

                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="bi bi-hourglass-split"></i> Pending Approval</h3>
                        <a href="manage_appointments.php?filter=pending">View all →</a>
                    </div>
                    <div class="panel-body" style="padding:12px 16px">
                        <?php if (empty($pending_appts)): ?>
                        <div class="empty-state" style="padding:20px">
                            <i class="bi bi-check2-all"></i>
                            <p>All caught up! No pending approvals.</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($pending_appts as $pa): ?>
                        <div class="pending-item">
                            <div class="pending-date">
                                <div class="pd-day"><?= date('d', strtotime($pa['date'])) ?></div>
                                <div class="pd-mon"><?= date('M', strtotime($pa['date'])) ?></div>
                            </div>
                            <div class="pending-info">
                                <div class="pi-name">
                                    <?= htmlspecialchars($pa['patient_name']) ?>
                                    <?php if ($pa['reschedule_requested']): ?>
                                    <span class="reschedule-flag">↺ Reschedule</span>
                                    <?php endif; ?>
                                </div>
                                <div class="pi-detail"><?= htmlspecialchars($pa['doctor']) ?> · <?= htmlspecialchars($pa['service'] ?? '—') ?></div>
                                <div class="pi-time"><?= date('g:i A', strtotime($pa['time'])) ?></div>
                            </div>
                            <div class="pending-actions">
                                <a href="approve_appointment.php?id=<?= $pa['id'] ?>" class="pa-btn approve" title="Approve"><i class="bi bi-check-lg"></i></a>
                                <a href="cancel_appointment.php?id=<?= $pa['id'] ?>"  class="pa-btn reject"  title="Cancel"
                                   onclick="return confirm('Cancel this appointment?')"><i class="bi bi-x-lg"></i></a>
                                <a href="reschedule_appointment.php?id=<?= $pa['id'] ?>" class="pa-btn view" title="View / Reschedule"><i class="bi bi-pencil"></i></a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="bi bi-tooth"></i> Top Services This Month</h3>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($top_services)): ?>
                        <div class="empty-state" style="padding:20px"><i class="bi bi-bar-chart"></i><p>No data yet.</p></div>
                        <?php else: ?>
                        <?php foreach ($top_services as $svc): ?>
                        <div class="svc-bar-row">
                            <div class="svc-bar-meta">
                                <span class="svc-bar-name"><?= htmlspecialchars($svc['name']) ?></span>
                                <span class="svc-bar-count"><?= $svc['cnt'] ?></span>
                            </div>
                            <div class="svc-bar-track">
                                <div class="svc-bar-fill" style="width:<?= round($svc['cnt']/$max_svc*100) ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="bi bi-activity"></i> Recent Activity</h3>
                        <a href="activity_logs.php">All logs →</a>
                    </div>
                    <div class="panel-body" style="padding:8px 16px">
                        <?php foreach ($recent_logs as $log): ?>
                        <div class="log-item">
                            <div class="log-dot <?= $log['user_type'] ?>"></div>
                            <div class="log-text">
                                <div class="log-action"><?= htmlspecialchars($log['user_name']) ?> · <span style="font-weight:400;color:var(--slate)"><?= htmlspecialchars($log['action']) ?></span></div>
                                <div class="log-desc"><?= htmlspecialchars($log['description']) ?></div>
                            </div>
                            <div class="log-time"><?= timeAgo($log['created_at']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<script src="/assets/js/toast.js"></script>
<script>
setTimeout(() => location.reload(), 120000);
</script>
</body>
</html>
<?php
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff/60).'m ago';
    if ($diff < 86400) return floor($diff/3600).'h ago';
    return date('M j', strtotime($dt));
}
?>