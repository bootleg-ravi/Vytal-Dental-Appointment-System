<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
    $to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
    $doc_id = (int)($_GET['doctor'] ?? 0);

    $where = "a.date BETWEEN '$from' AND '$to' AND a.status NOT IN ('cancelled')";
    if ($doc_id) $where .= " AND a.doctor_id=$doc_id";

    $res = $conn->query("SELECT a.date, a.time, a.status, a.attendance_status,
            COALESCE(p.name, a.guest_name) AS patient_name,
            COALESCE(p.email, a.guest_email) AS patient_email,
            d.name AS doctor, s.name AS service
        FROM appointments a
        JOIN doctors d ON a.doctor_id=d.id
        LEFT JOIN services s ON a.service_id=s.id
        LEFT JOIN patients p ON a.patient_id=p.id
        WHERE $where ORDER BY a.date DESC, a.time ASC");

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Time','Patient','Email','Dentist','Service','Apt Status','Attendance']);
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [$r['date'], date('g:i A',strtotime($r['time'])), $r['patient_name'],
            $r['patient_email'], $r['doctor'], $r['service'], $r['status'], $r['attendance_status']]);
    }
    fclose($out);
    $conn->close();
    exit;
}

$from     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']   ?? '') ? $_GET['from']   : date('Y-m-01');
$to       = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']     ?? '') ? $_GET['to']     : date('Y-m-d');
$doc_id   = (int)($_GET['doctor'] ?? 0);
$att_filter = in_array($_GET['attendance'] ?? '', ['','attended','no_show','pending']) ? ($_GET['attendance'] ?? '') : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;

$doctors = [];
$res = $conn->query("SELECT id, name FROM doctors WHERE is_active=1 ORDER BY name");
while ($r = $res->fetch_assoc()) $doctors[] = $r;


$base_where = "a.date BETWEEN '$from' AND '$to' AND a.status NOT IN ('cancelled')";
if ($doc_id) $base_where .= " AND a.doctor_id=$doc_id";

$sum_rows = $conn->query("SELECT attendance_status, COUNT(*) AS cnt, SUM(s.price) AS revenue
    FROM appointments a
    LEFT JOIN services s ON a.service_id=s.id
    WHERE $base_where
    GROUP BY attendance_status")->fetch_all(MYSQLI_ASSOC);

$summary = ['total'=>0,'attended'=>0,'no_show'=>0,'pending'=>0,'revenue_attended'=>0];
foreach ($sum_rows as $r) {
    $summary['total']    += $r['cnt'];
    $summary[$r['attendance_status']] = (int)$r['cnt'];
    if ($r['attendance_status'] === 'attended') $summary['revenue_attended'] = (float)$r['revenue'];
}
$show_rate = ($summary['attended'] + $summary['no_show']) > 0
    ? round($summary['attended'] / ($summary['attended'] + $summary['no_show']) * 100) : 0;


$trend_from = max($from, date('Y-m-d', strtotime('-29 days')));
$trend      = [];
$res = $conn->query("SELECT a.date,
    SUM(a.attendance_status='attended') AS attended,
    SUM(a.attendance_status='no_show')  AS no_show,
    COUNT(*) AS total
    FROM appointments a
    WHERE a.date BETWEEN '$trend_from' AND '$to'
    AND a.status NOT IN ('cancelled')
    " . ($doc_id ? "AND a.doctor_id=$doc_id" : "") . "
    GROUP BY a.date ORDER BY a.date ASC");
while ($r = $res->fetch_assoc()) $trend[$r['date']] = $r;

$trend_days = []; $d = $trend_from;
while ($d <= $to) {
    $trend_days[] = ['date'=>$d, 'attended'=>(int)($trend[$d]['attended']??0), 'no_show'=>(int)($trend[$d]['no_show']??0), 'total'=>(int)($trend[$d]['total']??0)];
    $d = date('Y-m-d', strtotime('+1 day', strtotime($d)));
}
$max_trend = max(1, ...array_map(fn($r) => $r['total'], $trend_days));

$dentist_attendance = [];
$res = $conn->query("SELECT d.id, d.name,
    COUNT(a.id) AS total,
    SUM(a.attendance_status='attended') AS attended,
    SUM(a.attendance_status='no_show')  AS no_show,
    SUM(a.attendance_status='pending')  AS pending
    FROM doctors d
    LEFT JOIN appointments a ON a.doctor_id=d.id
        AND a.date BETWEEN '$from' AND '$to'
        AND a.status NOT IN ('cancelled')
    WHERE d.is_active=1
    GROUP BY d.id ORDER BY total DESC");
while ($r = $res->fetch_assoc()) {
    $total = (int)$r['total'];
    $att   = (int)$r['attended'];
    $ns    = (int)$r['no_show'];
    $rate  = ($att + $ns) > 0 ? round($att/($att+$ns)*100) : 0;
    $dentist_attendance[] = array_merge($r, ['show_rate' => $rate]);
}
$max_dentist_total = max(1, ...array_column($dentist_attendance,'total') ?: [1]);

$repeat_noshows = [];
$res = $conn->query("SELECT COALESCE(p.name, a.guest_name) AS name,
    p.email, COUNT(*) AS no_show_count,
    MAX(a.date) AS last_date
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id=p.id
    WHERE a.attendance_status='no_show'
    AND a.date BETWEEN '$from' AND '$to'
    " . ($doc_id ? "AND a.doctor_id=$doc_id" : "") . "
    GROUP BY a.patient_id, a.guest_email
    HAVING no_show_count >= 2
    ORDER BY no_show_count DESC LIMIT 10");
while ($r = $res->fetch_assoc()) $repeat_noshows[] = $r;

$log_where = $base_where;
if ($att_filter) $log_where .= " AND a.attendance_status='$att_filter'";

$total_rows  = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments a WHERE $log_where")->fetch_assoc()['c'];
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$log_rows = [];
$res = $conn->query("SELECT a.id, a.date, a.time, a.status, a.attendance_status,
        COALESCE(p.name, a.guest_name) AS patient_name,
        COALESCE(p.email, a.guest_email) AS patient_email,
        d.name AS doctor, s.name AS service, s.price
    FROM appointments a
    JOIN doctors d ON a.doctor_id=d.id
    LEFT JOIN services s ON a.service_id=s.id
    LEFT JOIN patients p ON a.patient_id=p.id
    WHERE $log_where
    ORDER BY a.date DESC, a.time ASC
    LIMIT $per_page OFFSET $offset");
while ($r = $res->fetch_assoc()) $log_rows[] = $r;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Attendance Report – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--bg:#f7f5f0;--bg2:#fff;--sidebar:#111827;--border:#e8e3da;--teal:#0d9488;--teal2:#0f766e;--teal-lt:#f0fdf9;--amber:#d97706;--red:#dc2626;--green:#16a34a;--blue:#2563eb;--purple:#7c3aed;--ink:#111827;--slate:#6b7280;--muted:#9ca3af;--radius:14px;--shadow:0 1px 12px rgba(0,0,0,.06)}
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
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;flex-wrap:wrap;gap:10px}
.topbar h1{font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800}
.topbar p{font-size:.78rem;color:var(--slate);margin-top:1px}
.topbar-right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .2s;text-decoration:none;white-space:nowrap}
.btn-teal{background:var(--teal);color:#fff}.btn-teal:hover{background:var(--teal2)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--slate)}.btn-ghost:hover{border-color:var(--ink);color:var(--ink)}
.btn-print{background:#111827;color:#fff}.btn-print:hover{background:#1f2937}
.content{padding:24px 32px}

.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:22px}
@media(max-width:1100px){.stat-grid{grid-template-columns:repeat(3,1fr)}}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;box-shadow:var(--shadow)}
.sc-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.95rem;margin-bottom:10px}
.sc-icon.green{background:#f0fdf4;color:var(--green)}
.sc-icon.red  {background:#fef2f2;color:var(--red)}
.sc-icon.amber{background:#fffbeb;color:var(--amber)}
.sc-icon.blue {background:#eff6ff;color:var(--blue)}
.sc-icon.teal {background:var(--teal-lt);color:var(--teal)}
.sc-val{font-family:'Syne',sans-serif;font-size:1.75rem;font-weight:800;color:var(--ink);line-height:1}
.sc-lbl{font-size:.72rem;color:var(--slate);margin-top:4px}

.show-rate-bar{height:8px;background:var(--border);border-radius:4px;overflow:hidden;margin-top:8px}
.show-rate-fill{height:100%;border-radius:4px;transition:width .6s ease}

.filter-bar{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.filter-bar label{font-size:.76rem;font-weight:700;color:var(--slate)}
.filter-bar select,.filter-bar input{border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:.8rem;font-family:'Lato',sans-serif;color:var(--ink);background:var(--bg);outline:none;cursor:pointer}
.filter-bar select:focus,.filter-bar input:focus{border-color:var(--teal)}

.panel{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.panel-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.panel-header h3{font-family:'Syne',sans-serif;font-size:.92rem;font-weight:700;display:flex;align-items:center;gap:7px;color:var(--ink)}
.panel-header h3 i{color:var(--teal)}
.panel-header a{font-size:.76rem;color:var(--teal);text-decoration:none;font-weight:600}
.panel-body{padding:18px 20px}

.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
@media(max-width:1000px){.two-col{grid-template-columns:1fr}}

.stack-chart{display:flex;flex-direction:column;gap:10px}
.sc-row{}
.sc-row-meta{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;font-size:.78rem}
.sc-row-name{color:var(--ink);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px}
.sc-row-pct{color:var(--teal);font-weight:700;font-size:.75rem}
.sc-row-track{height:10px;background:var(--border);border-radius:5px;overflow:hidden;display:flex}
.sc-seg-attended{background:var(--green);height:100%}
.sc-seg-noshow  {background:var(--red);height:100%}
.sc-seg-pending {background:var(--amber);height:100%}

.trend-chart-wrap{position:relative;height:80px;display:flex;align-items:flex-end;gap:2px;padding:0 2px}
.tc-col{flex:1;display:flex;flex-direction:column;align-items:stretch;justify-content:flex-end;gap:1px;cursor:default;position:relative}
.tc-col:hover .tc-tip{display:block}
.tc-seg-attended{border-radius:2px 2px 0 0;min-height:2px}
.tc-seg-noshow  {min-height:2px}
.tc-tip{display:none;position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:#111;color:#fff;font-size:.62rem;padding:4px 7px;border-radius:6px;white-space:nowrap;z-index:10;font-family:'Lato',sans-serif;pointer-events:none}
.tc-tip::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:4px solid transparent;border-top-color:#111}
.tc-labels{display:flex;justify-content:space-between;font-size:.6rem;color:var(--muted);margin-top:4px;padding:0 2px}

.nst th{text-align:left;padding:9px 12px;background:var(--bg);color:var(--slate);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border)}
.nst td{padding:9px 12px;border-bottom:1px solid var(--border);font-size:.8rem}
.nst tr:last-child td{border-bottom:none}
.nst tr:hover td{background:#fff5f5}
.nst-count{display:inline-block;background:#fef2f2;color:var(--red);font-family:'Syne',sans-serif;font-weight:800;font-size:.85rem;padding:2px 10px;border-radius:20px}

.log-table{width:100%;border-collapse:collapse;font-size:.8rem}
.log-table th{text-align:left;padding:9px 12px;background:var(--bg);color:var(--slate);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border);white-space:nowrap}
.log-table td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--ink);vertical-align:middle}
.log-table tr:last-child td{border-bottom:none}
.log-table tr:hover td{background:#fafaf8}
.log-table .row-noshow td{background:#fff5f5}
.pill{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:20px;font-size:.67rem;font-weight:700;white-space:nowrap}
.pill-attended{background:#f0fdf4;color:#14532d}
.pill-no_show{background:#fef2f2;color:var(--red)}
.pill-pending{background:#f9fafb;color:var(--slate)}

.pagination{display:flex;align-items:center;gap:4px;justify-content:center;padding:16px 0}
.pag-btn{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--bg2);color:var(--slate);text-decoration:none;font-size:.8rem;font-weight:600;transition:all .15s;padding:0 8px}
.pag-btn:hover{border-color:var(--teal);color:var(--teal)}
.pag-btn.active{background:var(--teal);border-color:var(--teal);color:#fff}
.pag-btn.disabled{opacity:.4;pointer-events:none}

.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
.empty-state i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:.3}
.empty-state p{font-size:.82rem}

@media print{.sidebar,.topbar-right,.filter-bar,.pagination,.btn-print{display:none!important}.main{margin-left:0!important}.content{padding:0!important}@page{margin:12mm}}
@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:16px}.stat-grid{grid-template-columns:repeat(2,1fr)}.two-col{grid-template-columns:1fr}}
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
    <a href="reports.php"              class="nav-item"><i class="bi bi-file-earmark-text"></i> Reports</a>
    <a href="attendance_report.php"    class="nav-item active"><i class="bi bi-person-check"></i> Attendance</a>
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
        <div>
            <h1><i class="bi bi-person-check" style="color:var(--teal);margin-right:7px"></i>Attendance Tracking Report</h1>
            <p><?= date('F j', strtotime($from)) ?> – <?= date('F j, Y', strtotime($to)) ?> &nbsp;·&nbsp; <?= $summary['total'] ?> appointments</p>
        </div>
        <div class="topbar-right">
            <a href="reports.php" class="btn btn-ghost"><i class="bi bi-calendar-day"></i> Daily Schedule</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn btn-ghost">
                <i class="bi bi-download"></i> CSV
            </a>
            <button class="btn btn-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
    </div>

    <div class="content">

        <div class="stat-grid">
            <div class="stat-card">
                <div class="sc-icon teal"><i class="bi bi-calendar-check"></i></div>
                <div class="sc-val"><?= $summary['total'] ?></div>
                <div class="sc-lbl">Total Appointments</div>
            </div>
            <div class="stat-card">
                <div class="sc-icon green"><i class="bi bi-person-check"></i></div>
                <div class="sc-val"><?= $summary['attended'] ?></div>
                <div class="sc-lbl">Attended</div>
            </div>
            <div class="stat-card">
                <div class="sc-icon red"><i class="bi bi-person-x"></i></div>
                <div class="sc-val"><?= $summary['no_show'] ?></div>
                <div class="sc-lbl">No Shows</div>
            </div>
            <div class="stat-card">
                <div class="sc-icon amber"><i class="bi bi-hourglass"></i></div>
                <div class="sc-val"><?= $summary['pending'] ?></div>
                <div class="sc-lbl">Pending Attendance</div>
            </div>
            <div class="stat-card" style="border-<?= $show_rate >= 80 ? 'left' : 'left' ?>:3px solid <?= $show_rate>=80?'var(--green)':($show_rate>=60?'var(--amber)':'var(--red)') ?>">
                <div class="sc-icon <?= $show_rate>=80?'green':($show_rate>=60?'amber':'red') ?>"><i class="bi bi-graph-up"></i></div>
                <div class="sc-val" style="color:<?= $show_rate>=80?'var(--green)':($show_rate>=60?'var(--amber)':'var(--red)') ?>"><?= $show_rate ?>%</div>
                <div class="sc-lbl">Show Rate</div>
                <div class="show-rate-bar">
                    <div class="show-rate-fill" style="width:<?= $show_rate ?>%;background:<?= $show_rate>=80?'var(--green)':($show_rate>=60?'var(--amber)':'var(--red)') ?>"></div>
                </div>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <label>From</label>
            <input type="date" name="from" value="<?= $from ?>" max="<?= date('Y-m-d') ?>">
            <label>To</label>
            <input type="date" name="to"   value="<?= $to   ?>" max="<?= date('Y-m-d') ?>">
            <label>Dentist</label>
            <select name="doctor">
                <option value="0">All Dentists</option>
                <?php foreach ($doctors as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $doc_id==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Attendance</label>
            <select name="attendance">
                <option value="">All</option>
                <option value="attended" <?= $att_filter==='attended'?'selected':'' ?>>Attended</option>
                <option value="no_show"  <?= $att_filter==='no_show' ?'selected':'' ?>>No Show</option>
                <option value="pending"  <?= $att_filter==='pending' ?'selected':'' ?>>Pending</option>
            </select>
            <button type="submit" class="btn btn-teal">Apply</button>
            <a href="attendance_report.php" class="btn btn-ghost">Reset</a>
        </form>

        <div class="two-col">

            <div class="panel">
                <div class="panel-header">
                    <h3><i class="bi bi-graph-up"></i> Daily Attendance Trend</h3>
                    <span style="font-size:.72rem;color:var(--muted)"><?= count($trend_days) ?> days</span>
                </div>
                <div class="panel-body">
                    <div style="display:flex;gap:12px;font-size:.72rem;margin-bottom:12px">
                        <span><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--green);margin-right:4px"></span>Attended</span>
                        <span><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--red);margin-right:4px"></span>No Show</span>
                    </div>
                    <div class="trend-chart-wrap">
                        <?php foreach ($trend_days as $td):
                            $attended_h = $td['total'] > 0 ? round($td['attended']/$max_trend * 76) : 0;
                            $noshow_h   = $td['total'] > 0 ? round($td['no_show']/$max_trend * 76) : 0;
                        ?>
                        <div class="tc-col">
                            <div class="tc-tip"><?= date('M j', strtotime($td['date'])) ?><br>✅ <?= $td['attended'] ?> · ❌ <?= $td['no_show'] ?></div>
                            <?php if ($attended_h || $noshow_h): ?>
                            <div class="tc-seg-attended" style="height:<?= $attended_h ?>px;background:var(--green);border-radius:2px 2px 0 0"></div>
                            <div class="tc-seg-noshow"   style="height:<?= $noshow_h ?>px;background:var(--red)"></div>
                            <?php else: ?>
                            <div style="height:2px;background:var(--border);border-radius:2px"></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="tc-labels">
                        <?php
                        $first = $trend_days[0]['date']  ?? '';
                        $mid   = $trend_days[intval(count($trend_days)/2)]['date'] ?? '';
                        $last  = end($trend_days)['date'] ?? '';
                        ?>
                        <span><?= $first ? date('M j', strtotime($first)) : '' ?></span>
                        <span><?= $mid   ? date('M j', strtotime($mid))   : '' ?></span>
                        <span><?= $last  ? date('M j', strtotime($last))  : '' ?></span>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h3><i class="bi bi-person-badge"></i> Attendance by Dentist</h3>
                </div>
                <div class="panel-body">
                    <?php if (empty($dentist_attendance)): ?>
                    <div class="empty-state" style="padding:20px"><i class="bi bi-person-badge"></i><p>No data.</p></div>
                    <?php else: ?>
                    <div class="stack-chart">
                        <?php foreach ($dentist_attendance as $da):
                            $total = max(1,(int)$da['total']);
                            $att_w = round($da['attended']/$total*100);
                            $ns_w  = round($da['no_show']/$total*100);
                            $pd_w  = max(0, 100 - $att_w - $ns_w);
                        ?>
                        <div class="sc-row">
                            <div class="sc-row-meta">
                                <span class="sc-row-name"><?= htmlspecialchars($da['name']) ?></span>
                                <span class="sc-row-pct"><?= $da['show_rate'] ?>% show · <?= $da['total'] ?> total</span>
                            </div>
                            <div class="sc-row-track" title="<?= $da['attended'] ?> attended · <?= $da['no_show'] ?> no-show · <?= $da['pending'] ?> pending">
                                <div class="sc-seg-attended" style="width:<?= $att_w ?>%"></div>
                                <div class="sc-seg-noshow"   style="width:<?= $ns_w  ?>%"></div>
                                <div class="sc-seg-pending"  style="width:<?= $pd_w  ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:14px;font-size:.7rem;color:var(--slate);margin-top:14px">
                        <span><span style="display:inline-block;width:8px;height:8px;background:var(--green);border-radius:2px;margin-right:4px"></span>Attended</span>
                        <span><span style="display:inline-block;width:8px;height:8px;background:var(--red);border-radius:2px;margin-right:4px"></span>No Show</span>
                        <span><span style="display:inline-block;width:8px;height:8px;background:var(--amber);border-radius:2px;margin-right:4px"></span>Pending</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <?php if (!empty($repeat_noshows)): ?>
        <div class="panel">
            <div class="panel-header">
                <h3><i class="bi bi-exclamation-triangle" style="color:var(--red)"></i> Repeat No-Show Patients (2+ times this period)</h3>
            </div>
            <div style="overflow-x:auto">
            <table class="log-table nst" style="font-size:.82rem">
                <thead><tr>
                    <th>Patient</th>
                    <th>Email</th>
                    <th>No-Show Count</th>
                    <th>Last Date</th>
                    <th>Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($repeat_noshows as $rn): ?>
                <tr>
                    <td style="font-weight:700"><?= htmlspecialchars($rn['name']) ?></td>
                    <td style="color:var(--slate);font-size:.75rem"><?= htmlspecialchars($rn['email'] ?? '—') ?></td>
                    <td><span class="nst-count"><?= $rn['no_show_count'] ?>×</span></td>
                    <td style="color:var(--slate);font-size:.75rem"><?= date('M j, Y', strtotime($rn['last_date'])) ?></td>
                    <td>
                        <?php if ($rn['email']): ?>
                        <a href="mailto:<?= htmlspecialchars($rn['email']) ?>?subject=Your+Vytal+Dental+Appointment"
                           style="font-size:.75rem;color:var(--teal);font-weight:700;text-decoration:none">
                            <i class="bi bi-envelope"></i> Follow Up
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-header">
                <h3><i class="bi bi-table"></i> Appointment Attendance Log</h3>
                <span style="font-size:.72rem;color:var(--muted)">
                    <?= $total_rows ?> records &nbsp;·&nbsp; Page <?= $page ?> of <?= $total_pages ?>
                </span>
            </div>
            <?php if (empty($log_rows)): ?>
            <div class="empty-state"><i class="bi bi-journal-x"></i><p>No records match your filters.</p></div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="log-table">
                <thead><tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Patient</th>
                    <th>Dentist</th>
                    <th>Service</th>
                    <th>Fee</th>
                    <th>Appt Status</th>
                    <th>Attendance</th>
                </tr></thead>
                <tbody>
                <?php foreach ($log_rows as $r): ?>
                <tr class="<?= $r['attendance_status']==='no_show'?'row-noshow':'' ?>">
                    <td style="font-weight:600;white-space:nowrap"><?= date('M j, Y', strtotime($r['date'])) ?></td>
                    <td style="font-family:'Syne',sans-serif;font-size:.8rem;font-weight:700;color:var(--teal);white-space:nowrap"><?= date('g:i A', strtotime($r['time'])) ?></td>
                    <td>
                        <div style="font-weight:700"><?= htmlspecialchars($r['patient_name']) ?></div>
                        <div style="font-size:.7rem;color:var(--muted)"><?= htmlspecialchars($r['patient_email'] ?? '') ?></div>
                    </td>
                    <td style="color:var(--slate);font-size:.78rem"><?= htmlspecialchars($r['doctor']) ?></td>
                    <td style="font-size:.78rem"><?= htmlspecialchars($r['service'] ?? '—') ?></td>
                    <td style="font-family:'Syne',sans-serif;font-weight:700;color:var(--teal);font-size:.8rem">
                        <?= $r['price'] ? '₱'.number_format($r['price']) : '—' ?>
                    </td>
                    <td>
                        <?php
                        $sc_colors = ['pending'=>['#fffbeb','#92400e'],'confirmed'=>['#f0fdf4','#14532d'],'completed'=>['#eff6ff','#1e3a8a']];
                        $sc = $sc_colors[$r['status']] ?? ['#f3f4f6','#374151'];
                        ?>
                        <span class="pill" style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>"><?= ucfirst($r['status']) ?></span>
                    </td>
                    <td>
                        <span class="pill pill-<?= $r['attendance_status'] ?>">
                            <?= $r['attendance_status']==='attended'?'✅':($r['attendance_status']==='no_show'?'❌':'⏳') ?>
                            <?= ucfirst(str_replace('_',' ',$r['attendance_status'])) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php
                $base_params = array_diff_key($_GET, ['page'=>'']);
                $prev = $page - 1; $next = $page + 1;
                ?>
                <a href="?<?= http_build_query(array_merge($base_params, ['page'=>$prev])) ?>"
                   class="pag-btn <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                <a href="?<?= http_build_query(array_merge($base_params, ['page'=>$p])) ?>"
                   class="pag-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?<?= http_build_query(array_merge($base_params, ['page'=>$next])) ?>"
                   class="pag-btn <?= $page>=$total_pages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
</div>
</body>
</html>