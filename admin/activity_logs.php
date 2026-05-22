<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }

require_once '../config/config.php';
require_once '../includes/ActivityLogger.php';

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';
$logger     = new ActivityLogger($conn);

$filter_type   = $_GET['type']   ?? 'all';
$filter_action = $_GET['action'] ?? 'all';
$filter_date   = $_GET['date']   ?? '';
$filter_search = $_GET['search'] ?? '';
$limit         = max(10, min(500, intval($_GET['limit'] ?? 50)));

$logs = $filter_type === 'all'
    ? $logger->getRecentLogs($limit)
    : $logger->getRecentLogs($limit, $filter_type);

if ($filter_action !== 'all') {
    $logs = array_values(array_filter($logs, fn($l) => $l['action'] === $filter_action));
}

if ($filter_date) {
    $logs = array_values(array_filter($logs, fn($l) => date('Y-m-d', strtotime($l['created_at'])) === $filter_date));
}

if ($filter_search) {
    $s = strtolower($filter_search);
    $logs = array_values(array_filter($logs, fn($l) =>
        str_contains(strtolower($l['user_name'] ?? ''), $s) ||
        str_contains(strtolower($l['description'] ?? ''), $s) ||
        str_contains(strtolower($l['action'] ?? ''), $s)
    ));
}

$actions = [];
$res = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC");
if ($res) while ($r = $res->fetch_assoc()) $actions[] = $r['action'];

$stats  = $logger->getActionStats(30);
$total  = count($logs);
$admins = count(array_filter($logs, fn($l) => $l['user_type'] === 'admin'));
$patients = $total - $admins;
$today  = count(array_filter($logs, fn($l) => date('Y-m-d', strtotime($l['created_at'])) === date('Y-m-d')));

$conn->close();

$action_cfg = [
    'login'               => ['bg'=>'#eff6ff','color'=>'#1d4ed8','icon'=>'bi-box-arrow-in-right'],
    'logout'              => ['bg'=>'#f9fafb','color'=>'#6b7280','icon'=>'bi-box-arrow-right'],
    'register'            => ['bg'=>'#f0fdf4','color'=>'#15803d','icon'=>'bi-person-plus'],
    'book_appointment'    => ['bg'=>'#fdf4ff','color'=>'#7e22ce','icon'=>'bi-calendar-plus'],
    'cancel_appointment'  => ['bg'=>'#fef2f2','color'=>'#b91c1c','icon'=>'bi-calendar-x'],
    'approve_appointment' => ['bg'=>'#f0fdf4','color'=>'#15803d','icon'=>'bi-calendar-check'],
    'complete_appointment'=> ['bg'=>'#eff6ff','color'=>'#1d4ed8','icon'=>'bi-check2-all'],
    'reschedule'          => ['bg'=>'#fffbeb','color'=>'#b45309','icon'=>'bi-calendar-event'],
    'update_profile'      => ['bg'=>'#f0fdf4','color'=>'#0f766e','icon'=>'bi-pencil-square'],
    'delete'              => ['bg'=>'#fef2f2','color'=>'#b91c1c','icon'=>'bi-trash'],
    'password_reset'      => ['bg'=>'#fdf4ff','color'=>'#7e22ce','icon'=>'bi-key'],
];
$default_cfg = ['bg'=>'#f9fafb','color'=>'#374151','icon'=>'bi-activity'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Activity Logs – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root {
  --bg:      #f7f5f0;
  --bg2:     #ffffff;
  --sidebar: #111827;
  --border:  #e8e3da;
  --teal:    #0d9488;
  --teal2:   #0f766e;
  --teal-lt: #f0fdf9;
  --amber:   #d97706;
  --red:     #dc2626;
  --green:   #16a34a;
  --blue:    #2563eb;
  --purple:  #7c3aed;
  --ink:     #111827;
  --slate:   #6b7280;
  --muted:   #9ca3af;
  --radius:  14px;
  --shadow:  0 1px 12px rgba(0,0,0,.06);
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Lato', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; }

.sidebar { width: 230px; background: var(--sidebar); min-height: 100vh; position: fixed; left: 0; top: 0; bottom: 0; display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
.sidebar-logo { padding: 22px 20px; border-bottom: 1px solid rgba(255,255,255,.07); display: flex; align-items: center; gap: 10px; font-family: 'Syne', sans-serif; font-size: 1.1rem; font-weight: 800; color: #fff; }
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
.nav-item { display: flex; align-items: center; gap: 11px; padding: 10px 20px; color: rgba(255,255,255,.55); text-decoration: none; font-size: .84rem; font-weight: 500; transition: all .18s; position: relative; }
.nav-item:hover { background: rgba(255,255,255,.06); color: #fff; }
.nav-item.active { background: rgba(13,148,136,.18); color: #fff; }
.nav-item.active::before { content: ''; position: absolute; left: 0; top: 20%; bottom: 20%; width: 3px; background: #00c9a7; border-radius: 0 3px 3px 0; }
.nav-item i { font-size: 1rem; width: 18px; text-align: center; }
.nav-divider { margin: 8px 16px; border: none; border-top: 1px solid rgba(255,255,255,.07); }
.nav-item.danger { color: rgba(239,68,68,.6); }
.nav-item.danger:hover { background: rgba(239,68,68,.1); color: #ef4444; }
.sidebar-footer { margin-top: auto; padding: 16px; border-top: 1px solid rgba(255,255,255,.07); }
.admin-chip{display:flex;align-items:center;gap:10px;padding:9px 12px;background:rgba(255,255,255,.06);border-radius:10px}
.admin-avatar{width:32px;height:32px;border-radius:50%;background:#00c9a7;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700;color:#111;font-size:.9rem;flex-shrink:0}
.admin-info .name{font-size:.82rem;font-weight:700;color:#fff}
.admin-info .role{font-size:.68rem;color:rgba(255,255,255,.4);text-transform:capitalize}

.main { margin-left: 230px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

.topbar { background: var(--bg2); border-bottom: 1px solid var(--border); padding: 0 32px; height: 60px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
.topbar-left { display: flex; flex-direction: column; }
.topbar-title { font-family: 'Syne', sans-serif; font-size: 1.05rem; font-weight: 800; color: var(--ink); }
.topbar-sub { font-size: .75rem; color: var(--muted); }
.topbar-right { display: flex; align-items: center; gap: 12px; }
.topbar-date { font-size: .78rem; color: var(--slate); background: var(--bg); padding: 5px 12px; border-radius: 20px; border: 1px solid var(--border); }

.page-body { padding: 28px 32px; flex: 1; }

.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px; }
.stat-card { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow); }
.stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
.stat-val { font-family: 'Syne', sans-serif; font-size: 1.6rem; font-weight: 800; line-height: 1; color: var(--ink); }
.stat-lbl { font-size: .73rem; color: var(--slate); margin-top: 3px; }

.filter-bar { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; margin-bottom: 20px; box-shadow: var(--shadow); }
.filter-bar-title { font-family: 'Syne', sans-serif; font-size: .85rem; font-weight: 800; margin-bottom: 14px; display: flex; align-items: center; gap: 7px; }
.filter-bar-title i { color: var(--teal); }
.filter-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1.5fr auto; gap: 10px; align-items: end; }
.field label { display: block; font-size: .72rem; font-weight: 700; color: var(--slate); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; }
.field select, .field input { width: 100%; padding: 8px 12px; border: 1.5px solid var(--border); border-radius: 10px; font-size: .83rem; font-family: 'Lato', sans-serif; color: var(--ink); background: var(--bg); outline: none; transition: border-color .18s; }
.field select:focus, .field input:focus { border-color: var(--teal); }
.filter-actions { display: flex; gap: 8px; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; border-radius: 25px; font-size: .8rem; font-weight: 700; font-family: 'Lato', sans-serif; cursor: pointer; border: none; text-decoration: none; transition: all .18s; white-space: nowrap; }
.btn-teal { background: var(--teal); color: #fff; }
.btn-teal:hover { background: var(--teal2); }
.btn-ghost { background: var(--bg); border: 1.5px solid var(--border); color: var(--slate); }
.btn-ghost:hover { border-color: var(--ink); color: var(--ink); }

.log-card { background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
.log-card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.log-card-title { font-family: 'Syne', sans-serif; font-size: .9rem; font-weight: 800; display: flex; align-items: center; gap: 7px; }
.log-card-title i { color: var(--teal); }
.log-count { font-size: .75rem; color: var(--muted); background: var(--bg); padding: 3px 10px; border-radius: 20px; border: 1px solid var(--border); }
.log-table { width: 100%; border-collapse: collapse; }
.log-table th { padding: 10px 16px; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); background: var(--bg); border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; }
.log-table td { padding: 12px 16px; border-bottom: 1px solid var(--border); font-size: .82rem; vertical-align: middle; }
.log-table tr:last-child td { border-bottom: none; }
.log-table tbody tr { transition: background .15s; }
.log-table tbody tr:hover { background: #fafaf8; }

.log-table tbody tr.type-admin  td:first-child { border-left: 3px solid var(--blue); }
.log-table tbody tr.type-patient td:first-child { border-left: 3px solid var(--teal); }

.type-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; font-size: .67rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; white-space: nowrap; }
.type-admin-pill   { background: #eff6ff; color: #1d4ed8; }
.type-patient-pill { background: var(--teal-lt); color: var(--teal2); }

.action-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; font-size: .67rem; font-weight: 700; white-space: nowrap; }

.log-desc { color: var(--ink); max-width: 340px; }
.log-meta { font-size: .72rem; color: var(--muted); margin-top: 2px; display: flex; align-items: center; gap: 4px; }

.log-user { font-weight: 700; color: var(--ink); white-space: nowrap; }

.log-ip { font-size: .75rem; color: var(--muted); font-family: monospace; }

.log-time { white-space: nowrap; }
.log-time-date { font-weight: 700; font-size: .8rem; }
.log-time-clock { font-size: .72rem; color: var(--muted); margin-top: 2px; }

.empty-state { padding: 64px 24px; text-align: center; }
.empty-state i { font-size: 2.5rem; color: var(--muted); display: block; margin-bottom: 12px; }
.empty-state p { color: var(--slate); font-size: .9rem; }

.live-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--green); display: inline-block; margin-right: 5px; animation: livePulse 2s infinite; }
@keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.8)} }
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">
    <i class="bi bi-tooth-fill"></i> Vytal Dental
  </div>
      <div class="sidebar-role">
        <span>Logged in as</span>
        <span class="role-badge"><?= strtoupper($admin_role) ?></span>
    </div>

  <div class="nav-section">Main</div>
  <a href="dashboard.php"           class="nav-item"><i class="bi bi-speedometer2"></i> Dashboard</a>
  <a href="manage_appointments.php" class="nav-item"><i class="bi bi-calendar-check"></i> Appointments</a>
  <a href="manage_patients.php"     class="nav-item"><i class="bi bi-people"></i> Patients</a>
  <?php if ($is_admin): ?>
  <a href="manage_doctors.php"      class="nav-item"><i class="bi bi-person-badge"></i> Dentists</a>
  <a href="manage_services.php"     class="nav-item"><i class="bi bi-tooth"></i> Services</a>
  <?php endif; ?>

  <div class="nav-section">Analytics</div>
    <a href="patient_flow.php"         class="nav-item"><i class="bi bi-bar-chart-line"></i> Patient Flow</a>
    <a href="reports.php"              class="nav-item"><i class="bi bi-file-earmark-text"></i> Reports</a>
    <a href="attendance_report.php"    class="nav-item"><i class="bi bi-person-check"></i> Attendance</a>
    <a href="activity_logs.php"        class="nav-item active"><i class="bi bi-clipboard-data"></i> Activity Logs</a>
    <a href="accessibility_report.php" class="nav-item"><i class="bi bi-universal-access"></i> Accessibility</a>

  <div class="nav-section">Account</div>
  <a href="logout.php" class="nav-item danger"><i class="bi bi-box-arrow-right"></i> Logout</a>

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
      <div class="topbar-title">Activity Logs</div>
      <div class="topbar-sub">Full audit trail of all system actions</div>
    </div>
    <div class="topbar-right">
      <span class="topbar-date"><span class="live-dot"></span><?= date('D, M j Y') ?></span>
    </div>
  </div>

  <div class="page-body">

    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:var(--blue)"><i class="bi bi-list-check"></i></div>
        <div>
          <div class="stat-val"><?= number_format($total) ?></div>
          <div class="stat-lbl">Logs shown</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:var(--blue)"><i class="bi bi-shield-check"></i></div>
        <div>
          <div class="stat-val"><?= number_format($admins) ?></div>
          <div class="stat-lbl">Admin actions</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--teal-lt);color:var(--teal)"><i class="bi bi-people"></i></div>
        <div>
          <div class="stat-val"><?= number_format($patients) ?></div>
          <div class="stat-lbl">Patient actions</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fdf4ff;color:var(--purple)"><i class="bi bi-calendar-day"></i></div>
        <div>
          <div class="stat-val"><?= number_format($today) ?></div>
          <div class="stat-lbl">Today's events</div>
        </div>
      </div>
    </div>

    <div class="filter-bar">
      <div class="filter-bar-title"><i class="bi bi-funnel-fill"></i> Filter Logs</div>
      <form method="GET">
        <div class="filter-grid">
          <div class="field">
            <label>User Type</label>
            <select name="type">
              <option value="all"     <?= $filter_type==='all'     ?'selected':'' ?>>All Users</option>
              <option value="admin"   <?= $filter_type==='admin'   ?'selected':'' ?>>Admin Only</option>
              <option value="patient" <?= $filter_type==='patient' ?'selected':'' ?>>Patient Only</option>
            </select>
          </div>
          <div class="field">
            <label>Action</label>
            <select name="action">
              <option value="all">All Actions</option>
              <?php foreach ($actions as $a): ?>
              <option value="<?= htmlspecialchars($a) ?>" <?= $filter_action===$a?'selected':'' ?>><?= htmlspecialchars(ucwords(str_replace('_',' ',$a))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Limit</label>
            <select name="limit">
              <?php foreach ([25,50,100,200,500] as $l): ?>
              <option value="<?= $l ?>" <?= $limit===$l?'selected':'' ?>><?= $l ?> records</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Search user / action / description</label>
            <input type="text" name="search" placeholder="e.g. Maria, login, booking…" value="<?= htmlspecialchars($filter_search) ?>">
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn btn-teal"><i class="bi bi-search"></i> Filter</button>
            <a href="activity_logs.php" class="btn btn-ghost"><i class="bi bi-x-lg"></i> Clear</a>
          </div>
        </div>
      </form>
    </div>

    <div class="log-card">
      <div class="log-card-header">
        <div class="log-card-title"><i class="bi bi-journal-text"></i> Recent Activity</div>
        <span class="log-count"><?= number_format($total) ?> record<?= $total!==1?'s':'' ?></span>
      </div>

      <?php if (empty($logs)): ?>
      <div class="empty-state">
        <i class="bi bi-journal-x"></i>
        <p>No activity logs found for the selected filters.</p>
      </div>
      <?php else: ?>
      <div style="overflow-x:auto">
        <table class="log-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>User</th>
              <th>Action</th>
              <th>Description</th>
              <th>IP Address</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $log):
              $cfg = $action_cfg[$log['action']] ?? $default_cfg;
              $is_adm = $log['user_type'] === 'admin';
              $dt = strtotime($log['created_at']);
            ?>
            <tr class="type-<?= $log['user_type'] ?>">
              <td>
                <span class="type-pill <?= $is_adm ? 'type-admin-pill' : 'type-patient-pill' ?>">
                  <i class="bi <?= $is_adm ? 'bi-shield-fill' : 'bi-person-fill' ?>"></i>
                  <?= $is_adm ? 'Admin' : 'Patient' ?>
                </span>
              </td>
              <td>
                <div class="log-user"><?= htmlspecialchars($log['user_name'] ?? '—') ?></div>
              </td>
              <td>
                <span class="action-badge" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>">
                  <i class="bi <?= $cfg['icon'] ?>"></i>
                  <?= htmlspecialchars(ucwords(str_replace('_',' ', $log['action']))) ?>
                </span>
              </td>
              <td>
                <div class="log-desc"><?= htmlspecialchars($log['description'] ?? '—') ?></div>
              </td>
              <td>
                <div class="log-ip"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></div>
              </td>
              <td class="log-time">
                <div class="log-time-date"><?= date('M j, Y', $dt) ?></div>
                <div class="log-time-clock"><i class="bi bi-clock"></i> <?= date('g:i A', $dt) ?></div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

</body>
</html>