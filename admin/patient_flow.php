<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'get_day_detail') {
        $date = $conn->real_escape_string($_POST['date'] ?? date('Y-m-d'));
        $rows = [];
        $res  = $conn->query("
            SELECT a.id, a.time, a.status, a.attendance_status,
                   COALESCE(p.name, a.guest_name) AS patient_name,
                   d.name AS doctor,
                   s.name AS service,
                   s.duration_minutes
            FROM appointments a
            JOIN doctors d ON a.doctor_id = d.id
            LEFT JOIN services s  ON a.service_id  = s.id
            LEFT JOIN patients p  ON a.patient_id  = p.id
            WHERE a.date = '$date' AND a.status NOT IN ('cancelled')
            ORDER BY a.time ASC
        ");
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['appointments' => $rows, 'date' => $date]);
        exit;
    }

    if ($_POST['action'] === 'update_attendance' && $is_admin) {
        $id     = (int)$_POST['id'];
        $status = in_array($_POST['status'], ['attended','no_show','pending']) ? $_POST['status'] : 'pending';
        $conn->query("UPDATE appointments SET attendance_status='$status' WHERE id=$id");
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false]);
    exit;
}

$view_date   = $_GET['date']   ?? date('Y-m-d');
$view_month  = $_GET['month']  ?? date('Y-m');
$view_doctor = (int)($_GET['doctor'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}$/', $view_month)) $view_month = date('Y-m');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $view_date)) $view_date = date('Y-m-d');

$month_start = $view_month . '-01';
$month_end   = date('Y-m-t', strtotime($month_start));

$cal_data = [];
$res = $conn->query("
    SELECT date, COUNT(*) AS total,
           SUM(status='confirmed')  AS confirmed,
           SUM(status='pending')    AS pending,
           SUM(status='completed')  AS completed,
           SUM(attendance_status='attended')  AS attended,
           SUM(attendance_status='no_show')   AS no_show
    FROM appointments
    WHERE date BETWEEN '$month_start' AND '$month_end'
    AND   status NOT IN ('cancelled')
    " . ($view_doctor ? "AND doctor_id=$view_doctor" : "") . "
    GROUP BY date
");
while ($r = $res->fetch_assoc()) $cal_data[$r['date']] = $r;

$ms = [];
$ms['total']       = array_sum(array_column($cal_data,'total'));
$ms['confirmed']   = array_sum(array_column($cal_data,'confirmed'));
$ms['pending']     = array_sum(array_column($cal_data,'pending'));
$ms['completed']   = array_sum(array_column($cal_data,'completed'));
$ms['attended']    = array_sum(array_column($cal_data,'attended'));
$ms['no_show']     = array_sum(array_column($cal_data,'no_show'));
$ms['show_rate']   = $ms['attended'] + $ms['no_show'] > 0
    ? round($ms['attended'] / ($ms['attended'] + $ms['no_show']) * 100) : 0;

$max_day = max(1, ...array_column($cal_data, 'total') ?: [1]);

$dentist_stats = [];
$res = $conn->query("
    SELECT d.id, d.name, d.specialty,
           COUNT(a.id) AS total,
           SUM(a.status='completed') AS completed,
           SUM(a.attendance_status='no_show') AS no_shows
    FROM doctors d
    LEFT JOIN appointments a ON a.doctor_id=d.id
        AND DATE_FORMAT(a.date,'%Y-%m')='$view_month'
        AND a.status NOT IN ('cancelled')
    WHERE d.is_active=1
    GROUP BY d.id
    ORDER BY total DESC
");
while ($r = $res->fetch_assoc()) $dentist_stats[] = $r;
$max_dentist = max(1, ...array_column($dentist_stats,'total') ?: [1]);

$day_appts = [];
$res = $conn->query("
    SELECT a.id, a.time, a.status, a.attendance_status,
           COALESCE(p.name, a.guest_name) AS patient_name,
           d.name AS doctor, s.name AS service, s.duration_minutes
    FROM appointments a
    JOIN doctors d      ON a.doctor_id  = d.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN patients p ON a.patient_id = p.id
    WHERE a.date = '$view_date' AND a.status NOT IN ('cancelled')
    " . ($view_doctor ? "AND a.doctor_id=$view_doctor" : "") . "
    ORDER BY a.time ASC
");
while ($r = $res->fetch_assoc()) $day_appts[] = $r;

$doctors = [];
$res = $conn->query("SELECT id, name FROM doctors WHERE is_active=1 ORDER BY name");
while ($r = $res->fetch_assoc()) $doctors[] = $r;

$conn->close();

$cal_month_ts    = strtotime($month_start);
$cal_days_in_month = (int)date('t', $cal_month_ts);
$cal_first_dow   = (int)date('N', $cal_month_ts);
$prev_month = date('Y-m', strtotime('-1 month', $cal_month_ts));
$next_month = date('Y-m', strtotime('+1 month', $cal_month_ts));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Patient Flow Tracker – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root {
    --bg:#f7f5f0; --bg2:#fff; --sidebar:#111827;
    --border:#e8e3da; --teal:#0d9488; --teal2:#0f766e; --teal-lt:#f0fdf9;
    --amber:#d97706; --red:#dc2626; --green:#16a34a; --blue:#2563eb;
    --ink:#111827; --slate:#6b7280; --muted:#9ca3af;
    --radius:14px; --shadow:0 1px 12px rgba(0,0,0,.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Lato',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex}

.sidebar{width:230px;background:var(--sidebar);min-height:100vh;position:fixed;left:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100;overflow-y:auto}
.sidebar-logo{padding:22px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px;font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:#fff}
.sidebar-logo i{color:#00c9a7;font-size:1.3rem}
.nav-section{padding:14px 16px 4px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28)}
.nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,.55);text-decoration:none;font-size:.84rem;font-weight:500;transition:all .18s;position:relative}
.nav-item:hover{color:#fff;background:rgba(255,255,255,.05)}
.nav-item.active{color:#00c9a7;background:rgba(0,201,167,.08)}
.nav-item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:#00c9a7;border-radius:0 3px 3px 0}
.nav-item i{font-size:.95rem;width:18px;text-align:center}
.nav-badge{margin-left:auto;background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;padding:1px 6px;border-radius:20px}
.sidebar-footer{margin-top:auto;padding:14px;border-top:1px solid rgba(255,255,255,.07)}
.admin-chip{display:flex;align-items:center;gap:10px;padding:9px 12px;background:rgba(255,255,255,.06);border-radius:10px}
.admin-avatar{width:32px;height:32px;border-radius:50%;background:#00c9a7;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700;color:#111;font-size:.9rem;flex-shrink:0}
.admin-info .name{font-size:.82rem;font-weight:700;color:#fff}
.admin-info .role{font-size:.68rem;color:rgba(255,255,255,.4);text-transform:capitalize}

.main{margin-left:230px;flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar h1{font-family:'Syne',sans-serif;font-size:1.25rem;font-weight:800;color:var(--ink)}
.topbar p{font-size:.78rem;color:var(--slate);margin-top:1px}
.content{padding:24px 32px}

.stat-row{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:22px}
@media(max-width:1100px){.stat-row{grid-template-columns:repeat(3,1fr)}}
.stat-mini{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow)}
.sm-val{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--ink);line-height:1}
.sm-lbl{font-size:.72rem;color:var(--slate);margin-top:3px}
.sm-val.teal{color:var(--teal)} .sm-val.amber{color:var(--amber)} .sm-val.green{color:var(--green)} .sm-val.red{color:var(--red)} .sm-val.blue{color:var(--blue)}

.filter-bar{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.filter-bar label{font-size:.78rem;font-weight:700;color:var(--slate)}
.filter-bar select,.filter-bar input{border:1px solid var(--border);border-radius:8px;padding:7px 12px;font-size:.82rem;font-family:'Lato',sans-serif;color:var(--ink);background:var(--bg);outline:none;cursor:pointer}
.filter-bar select:focus,.filter-bar input:focus{border-color:var(--teal)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-teal{background:var(--teal);color:#fff}
.btn-teal:hover{background:var(--teal2)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--slate)}
.btn-ghost:hover{border-color:var(--ink);color:var(--ink)}

.two-panel{display:grid;grid-template-columns:1fr 340px;gap:20px}
@media(max-width:1100px){.two-panel{grid-template-columns:1fr}}

.cal-wrap{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.cal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border)}
.cal-header h3{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800}
.cal-nav{display:flex;gap:6px}
.cal-nav a{display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;border:1px solid var(--border);color:var(--slate);text-decoration:none;font-size:.9rem;transition:all .15s}
.cal-nav a:hover{border-color:var(--teal);color:var(--teal)}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr)}
.cal-dow{padding:8px 4px;text-align:center;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);border-bottom:1px solid var(--border)}
.cal-cell{min-height:70px;padding:6px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;position:relative}
.cal-cell:hover{background:var(--teal-lt)}
.cal-cell.selected{background:var(--teal-lt);outline:2px solid var(--teal);outline-offset:-2px}
.cal-cell.today .cc-num{background:var(--teal);color:#fff;border-radius:50%}
.cal-cell.other-month{background:#fafaf8;opacity:.5;cursor:default}
.cal-cell.other-month:hover{background:#fafaf8}
.cc-num{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;font-size:.78rem;font-weight:700;color:var(--ink);margin-bottom:4px}
.cc-bar-wrap{height:22px;display:flex;align-items:flex-end;gap:1px;justify-content:flex-start}
.cc-bar{border-radius:2px 2px 0 0;min-width:4px;transition:height .3s}
.cc-bar.open{background:var(--green)}
.cc-bar.limited{background:var(--amber)}
.cc-bar.full{background:var(--red)}
.cc-count{font-size:.6rem;color:var(--slate);font-weight:700;margin-top:2px}
.cc-dot-row{display:flex;gap:2px;margin-top:2px;flex-wrap:wrap}
.cc-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0}

.day-panel{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column}
.day-panel-header{padding:16px 20px;border-bottom:1px solid var(--border)}
.day-panel-header h3{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;color:var(--ink)}
.day-panel-header p{font-size:.75rem;color:var(--slate);margin-top:2px}
.day-appt-list{flex:1;overflow-y:auto;max-height:480px}
.day-appt-item{padding:12px 18px;border-bottom:1px solid var(--border);display:flex;gap:12px;align-items:flex-start}
.day-appt-item:last-child{border-bottom:none}
.da-time{font-family:'Syne',sans-serif;font-size:.8rem;font-weight:800;color:var(--teal);flex-shrink:0;width:54px}
.da-body{flex:1;min-width:0}
.da-name{font-size:.84rem;font-weight:700;color:var(--ink)}
.da-meta{font-size:.73rem;color:var(--slate);margin-top:2px}
.da-service{font-size:.7rem;color:var(--teal);margin-top:2px}
.attend-select{border:1px solid var(--border);border-radius:6px;padding:3px 7px;font-size:.7rem;font-family:'Lato',sans-serif;color:var(--ink);cursor:pointer;background:var(--bg);outline:none;margin-top:5px}
.attend-select:focus{border-color:var(--teal)}
.attend-select.attended{background:#f0fdf4;border-color:#86efac;color:#14532d}
.attend-select.no_show{background:#fef2f2;border-color:#fca5a5;color:var(--red)}

.dentist-table{width:100%;border-collapse:collapse;font-size:.82rem;margin-top:20px}
.dentist-table th{text-align:left;padding:9px 12px;background:var(--bg);color:var(--slate);font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border)}
.dentist-table td{padding:10px 12px;border-bottom:1px solid var(--border);color:var(--ink);vertical-align:middle}
.dentist-table tr:last-child td{border-bottom:none}
.dentist-table tr:hover td{background:var(--teal-lt)}
.dt-bar-cell{width:120px}
.dt-bar-track{height:5px;background:var(--border);border-radius:3px;overflow:hidden}
.dt-bar-fill{height:100%;background:var(--teal);border-radius:3px}
.tag{display:inline-block;font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:20px;text-transform:uppercase;letter-spacing:.04em}
.tag-attended{background:#f0fdf4;color:#14532d}
.tag-noshow  {background:#fef2f2;color:var(--red)}

.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
.empty-state i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:.3}
.empty-state p{font-size:.83rem}

.panel{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-top:20px}
.panel-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.panel-header h3{font-family:'Syne',sans-serif;font-size:.93rem;font-weight:700;display:flex;align-items:center;gap:7px;color:var(--ink)}
.panel-header h3 i{color:var(--teal)}

@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:16px}.stat-row{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-logo"><i class="bi bi-tooth"></i> Vytal Dental</div>
    <div class="nav-section">Main</div>
    <a href="dashboard.php"            class="nav-item"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="manage_appointments.php"  class="nav-item"><i class="bi bi-calendar-check"></i> Appointments</a>
    <a href="manage_patients.php"      class="nav-item"><i class="bi bi-people"></i> Patients</a>
    <?php if ($is_admin): ?>
    <a href="manage_doctors.php"       class="nav-item"><i class="bi bi-person-badge"></i> Dentists</a>
    <a href="manage_services.php"      class="nav-item"><i class="bi bi-tooth"></i> Services</a>
    <?php endif; ?>
    <div class="nav-section">Analytics</div>
    <a href="patient_flow.php"         class="nav-item active"><i class="bi bi-bar-chart-line"></i> Patient Flow</a>
    <a href="reports.php"              class="nav-item"><i class="bi bi-file-earmark-text"></i> Reports</a>
      <a href="attendance_report.php"   class="nav-item"><i class="bi bi-clipboard2-pulse"></i> Attendance</a>
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
            <h1><i class="bi bi-bar-chart-line" style="color:var(--teal);margin-right:8px"></i>Patient Flow Tracker</h1>
            <p><?= date('F Y', strtotime($view_month.'-01')) ?></p>
        </div>
        <a href="reports.php" class="btn btn-ghost"><i class="bi bi-file-earmark-text"></i> Full Reports</a>
    </div>

    <div class="content">

        <div class="stat-row">
            <div class="stat-mini"><div class="sm-val teal"><?= $ms['total'] ?></div><div class="sm-lbl">Total Appointments</div></div>
            <div class="stat-mini"><div class="sm-val green"><?= $ms['confirmed'] ?></div><div class="sm-lbl">Confirmed</div></div>
            <div class="stat-mini"><div class="sm-val amber"><?= $ms['pending'] ?></div><div class="sm-lbl">Pending</div></div>
            <div class="stat-mini"><div class="sm-val blue"><?= $ms['completed'] ?></div><div class="sm-lbl">Completed</div></div>
            <div class="stat-mini"><div class="sm-val green"><?= $ms['attended'] ?></div><div class="sm-lbl">Attended</div></div>
            <div class="stat-mini">
                <div class="sm-val <?= $ms['show_rate'] >= 80 ? 'green' : ($ms['show_rate'] >= 60 ? 'amber' : 'red') ?>">
                    <?= $ms['show_rate'] ?>%
                </div>
                <div class="sm-lbl">Show Rate</div>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <label>Month</label>
            <input type="month" name="month" value="<?= $view_month ?>" max="<?= date('Y-m', strtotime('+3 months')) ?>">
            <label>Dentist</label>
            <select name="doctor">
                <option value="0">All Dentists</option>
                <?php foreach ($doctors as $doc): ?>
                <option value="<?= $doc['id'] ?>" <?= $view_doctor == $doc['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($doc['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-teal">Apply</button>
            <a href="patient_flow.php" class="btn btn-ghost">Reset</a>
        </form>

        <div class="two-panel">

            <div class="cal-wrap">
                <div class="cal-header">
                    <h3><?= date('F Y', strtotime($view_month.'-01')) ?></h3>
                    <div class="cal-nav">
                        <a href="?month=<?= $prev_month ?>&doctor=<?= $view_doctor ?>"><i class="bi bi-chevron-left"></i></a>
                        <a href="?month=<?= date('Y-m') ?>&doctor=<?= $view_doctor ?>">Today</a>
                        <a href="?month=<?= $next_month ?>&doctor=<?= $view_doctor ?>"><i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>
                <div class="cal-grid">
                    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dow): ?>
                    <div class="cal-dow"><?= $dow ?></div>
                    <?php endforeach; ?>

                    <?php
                    for ($i = 1; $i < $cal_first_dow; $i++):
                    ?><div class="cal-cell other-month"></div><?php
                    endfor;

                    for ($d = 1; $d <= $cal_days_in_month; $d++):
                        $cell_date = $view_month . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                        $data      = $cal_data[$cell_date] ?? null;
                        $total     = (int)($data['total'] ?? 0);
                        $bar_h     = $total > 0 ? max(4, round($total / $max_day * 18)) : 0;
                        $status    = 'open';
                        if ($data) {
                            if ($total >= 10) $status = 'full';
                            elseif ($total >= 5) $status = 'limited';
                        }
                        $is_today    = $cell_date === date('Y-m-d');
                        $is_selected = $cell_date === $view_date;
                        $classes     = 'cal-cell' . ($is_today?' today':'') . ($is_selected?' selected':'');
                    ?>
                    <div class="<?= $classes ?>" onclick="selectDay('<?= $cell_date ?>')"
                         data-date="<?= $cell_date ?>" title="<?= $total ?> appointment<?= $total!==1?'s':'' ?>">
                        <div class="cc-num"><?= $d ?></div>
                        <?php if ($total > 0): ?>
                        <div class="cc-bar-wrap">
                            <div class="cc-bar <?= $status ?>" style="height:<?= $bar_h ?>px;width:<?= min(100,round($total/$max_day*100)) ?>%"></div>
                        </div>
                        <div class="cc-count"><?= $total ?></div>
                        <?php if (($data['no_show'] ?? 0) > 0): ?>
                        <div class="cc-dot-row">
                            <?php for ($ns = 0; $ns < min(4,(int)$data['no_show']); $ns++): ?>
                            <div class="cc-dot" style="background:var(--red)" title="No shows"></div>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="day-panel" id="dayPanel">
                <div class="day-panel-header">
                    <h3 id="dayPanelTitle">
                        <?= date('l, F j', strtotime($view_date)) ?>
                    </h3>
                    <p id="dayPanelSub"><?= count($day_appts) ?> appointment<?= count($day_appts)!==1?'s':'' ?></p>
                </div>
                <div class="day-appt-list" id="dayApptList">
                    <?php if (empty($day_appts)): ?>
                    <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <p>No appointments on this day.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($day_appts as $a): ?>
                    <div class="day-appt-item">
                        <div class="da-time"><?= date('g:i A', strtotime($a['time'])) ?></div>
                        <div class="da-body">
                            <div class="da-name"><?= htmlspecialchars($a['patient_name'] ?? 'Guest') ?></div>
                            <div class="da-meta"><?= htmlspecialchars($a['doctor']) ?></div>
                            <div class="da-service"><?= htmlspecialchars($a['service'] ?? '—') ?>
                                <?php if ($a['duration_minutes']): ?> · <?= $a['duration_minutes'] ?>min<?php endif; ?>
                            </div>
                            <?php if ($is_admin): ?>
                            <select class="attend-select <?= $a['attendance_status'] ?>"
                                    onchange="updateAttendance(<?= $a['id'] ?>, this)"
                                    data-appt="<?= $a['id'] ?>">
                                <option value="pending"  <?= $a['attendance_status']==='pending'  ?'selected':'' ?>>⏳ Pending</option>
                                <option value="attended" <?= $a['attendance_status']==='attended' ?'selected':'' ?>>✅ Attended</option>
                                <option value="no_show"  <?= $a['attendance_status']==='no_show'  ?'selected':'' ?>>❌ No Show</option>
                            </select>
                            <?php else: ?>
                            <span class="tag <?= $a['attendance_status']==='attended'?'tag-attended':($a['attendance_status']==='no_show'?'tag-noshow':'') ?>">
                                <?= $a['attendance_status'] ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <div class="panel">
            <div class="panel-header">
                <h3><i class="bi bi-person-badge"></i> Dentist Breakdown — <?= date('F Y', strtotime($view_month.'-01')) ?></h3>
            </div>
            <div style="overflow-x:auto;padding:0 4px">
            <table class="dentist-table">
                <thead><tr>
                    <th>Dentist</th>
                    <th>Specialty</th>
                    <th>Appointments</th>
                    <th>Completed</th>
                    <th>No Shows</th>
                    <th class="dt-bar-cell">Load</th>
                </tr></thead>
                <tbody>
                <?php foreach ($dentist_stats as $ds): ?>
                <tr>
                    <td style="font-weight:700"><?= htmlspecialchars($ds['name']) ?></td>
                    <td style="color:var(--slate);font-size:.78rem"><?= htmlspecialchars($ds['specialty'] ?? '—') ?></td>
                    <td style="font-family:'Syne',sans-serif;font-weight:700;color:var(--teal)"><?= $ds['total'] ?></td>
                    <td><?= $ds['completed'] ?></td>
                    <td><?php if ($ds['no_shows'] > 0): ?>
                        <span class="tag tag-noshow"><?= $ds['no_shows'] ?></span>
                    <?php else: ?>
                        <span style="color:var(--muted)">0</span>
                    <?php endif; ?></td>
                    <td class="dt-bar-cell">
                        <div class="dt-bar-track">
                            <div class="dt-bar-fill" style="width:<?= round($ds['total']/$max_dentist*100) ?>%"></div>
                        </div>
                        <span style="font-size:.68rem;color:var(--muted)"><?= round($ds['total']/$max_dentist*100) ?>%</span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

    </div>
</div>

<script src="/assets/js/toast.js"></script>
<script>
let selectedDate = '<?= $view_date ?>';

function selectDay(date) {
    document.querySelectorAll('.cal-cell').forEach(c => c.classList.remove('selected'));
    const cell = document.querySelector(`[data-date="${date}"]`);
    if (cell) cell.classList.add('selected');
    selectedDate = date;

    const list    = document.getElementById('dayApptList');
    const title   = document.getElementById('dayPanelTitle');
    const sub     = document.getElementById('dayPanelSub');
    list.innerHTML = '<div class="empty-state"><i class="bi bi-arrow-clockwise" style="animation:spin .7s linear infinite;display:block;font-size:2rem;margin-bottom:8px;opacity:.3"></i><p>Loading…</p></div>';

    const fd = new FormData();
    fd.append('action','get_day_detail');
    fd.append('date', date);
    fetch('', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            const appts = data.appointments || [];
            const d = new Date(date + 'T00:00:00');
            title.textContent = d.toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric'});
            sub.textContent   = appts.length + ' appointment' + (appts.length!==1?'s':'');

            if (!appts.length) {
                list.innerHTML = '<div class="empty-state"><i class="bi bi-calendar-x"></i><p>No appointments on this day.</p></div>';
                return;
            }
            list.innerHTML = appts.map(a => `
                <div class="day-appt-item">
                    <div class="da-time">${fmtTime(a.time)}</div>
                    <div class="da-body">
                        <div class="da-name">${esc(a.patient_name||'Guest')}</div>
                        <div class="da-meta">${esc(a.doctor)}</div>
                        <div class="da-service">${esc(a.service||'—')}${a.duration_minutes?' · '+a.duration_minutes+'min':''}</div>
                        <?= $is_admin ? "`+attendSelect(a)+`" : '' ?>
                    </div>
                </div>
            `).join('');
        });
}

<?php if ($is_admin): ?>
function attendSelect(a) {
    const opts = [
        ['pending','⏳ Pending'],
        ['attended','✅ Attended'],
        ['no_show','❌ No Show'],
    ];
    const options = opts.map(([v,l]) =>
        `<option value="${v}" ${a.attendance_status===v?'selected':''}>${l}</option>`
    ).join('');
    return `<select class="attend-select ${a.attendance_status}" onchange="updateAttendance(${a.id},this)" data-appt="${a.id}">${options}</select>`;
}

async function updateAttendance(id, sel) {
    sel.className = 'attend-select ' + sel.value;
    const fd = new FormData();
    fd.append('action','update_attendance');
    fd.append('id', id);
    fd.append('status', sel.value);
    const res = await fetch('', {method:'POST',body:fd}).then(r=>r.json());
    if (res.ok) Toast.success('Attendance updated');
    else Toast.error('Could not update attendance');
}
<?php endif; ?>

function fmtTime(t) {
    const [h,m] = t.split(':');
    const hr = parseInt(h);
    return (hr%12||12) + ':' + m + ' ' + (hr<12?'AM':'PM');
}
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

const style = document.createElement('style');
style.textContent='@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(style);
</script>
</body>
</html>