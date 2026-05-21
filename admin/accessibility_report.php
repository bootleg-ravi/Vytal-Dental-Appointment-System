<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';


$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');


$q = fn($sql) => $conn->query($sql)->fetch_assoc();

$hc = $q("SELECT
    COUNT(DISTINCT patient_id) AS users,
    COUNT(*) AS events,
    SUM(event_type='high_contrast_enabled')  AS enabled,
    SUM(event_type='high_contrast_disabled') AS disabled
    FROM accessibility_logs WHERE event_type IN ('high_contrast_enabled','high_contrast_disabled') AND DATE(created_at) BETWEEN '$from' AND '$to'");

$ts = $q("SELECT COUNT(DISTINCT patient_id) AS users, COUNT(*) AS events
    FROM accessibility_logs WHERE event_type='text_size_changed' AND DATE(created_at) BETWEEN '$from' AND '$to'");

$active_patients = (int)$conn->query("SELECT COUNT(DISTINCT id) AS c FROM patients
    WHERE (accessibility_high_contrast=1 OR accessibility_text_size != 'normal')
    AND id IS NOT NULL")->fetch_assoc()['c'];

$total_patients = (int)$conn->query("SELECT COUNT(*) AS c FROM patients")->fetch_assoc()['c'];

$text_sizes = [];
$res = $conn->query("SELECT accessibility_text_size AS size, COUNT(*) AS cnt
    FROM patients WHERE accessibility_text_size IS NOT NULL
    GROUP BY accessibility_text_size ORDER BY cnt DESC");
while ($r = $res->fetch_assoc()) $text_sizes[] = $r;
$max_size = max(1, ...array_column($text_sizes,'cnt') ?: [1]);

$trend_data = [];
$trend_labels = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $cnt = (int)$conn->query("SELECT COUNT(*) AS c FROM accessibility_logs
        WHERE DATE(created_at)='$d'")->fetch_assoc()['c'];
    $trend_data[]   = $cnt;
    $trend_labels[] = date('M j', strtotime($d));
}

$logs = [];
$res  = $conn->query("SELECT al.*, COALESCE(p.name,'Guest') AS patient_name
    FROM accessibility_logs al
    LEFT JOIN patients p ON al.patient_id = p.id
    WHERE DATE(al.created_at) BETWEEN '$from' AND '$to'
    ORDER BY al.created_at DESC
    LIMIT 50");
while ($r = $res->fetch_assoc()) $logs[] = $r;

$top_users = [];
$res = $conn->query("SELECT al.patient_id, COALESCE(p.name,'Guest') AS name,
    COUNT(*) AS events, MAX(al.created_at) AS last_seen
    FROM accessibility_logs al
    LEFT JOIN patients p ON al.patient_id = p.id
    WHERE DATE(al.created_at) BETWEEN '$from' AND '$to'
    GROUP BY al.patient_id ORDER BY events DESC LIMIT 10");
while ($r = $res->fetch_assoc()) $top_users[] = $r;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Accessibility Report – Vytal Dental</title>
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
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar h1{font-family:'Syne',sans-serif;font-size:1.25rem;font-weight:800;color:var(--ink)}
.topbar p{font-size:.78rem;color:var(--slate);margin-top:1px}
.content{padding:24px 32px}

.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
@media(max-width:1100px){.stat-grid{grid-template-columns:repeat(2,1fr)}}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;box-shadow:var(--shadow)}
.sc-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;margin-bottom:12px}
.sc-icon.purple{background:#f5f3ff;color:var(--purple)}
.sc-icon.teal{background:var(--teal-lt);color:var(--teal)}
.sc-icon.amber{background:#fffbeb;color:var(--amber)}
.sc-icon.blue{background:#eff6ff;color:var(--blue)}
.sc-val{font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;color:var(--ink);line-height:1}
.sc-lbl{font-size:.75rem;color:var(--slate);margin-top:4px}
.sc-sub{font-size:.7rem;color:var(--muted);margin-top:2px}

.filter-bar{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.filter-bar label{font-size:.78rem;font-weight:700;color:var(--slate)}
.filter-bar input{border:1px solid var(--border);border-radius:8px;padding:7px 12px;font-size:.82rem;font-family:'Lato',sans-serif;color:var(--ink);background:var(--bg);outline:none}
.filter-bar input:focus{border-color:var(--teal)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-teal{background:var(--teal);color:#fff}.btn-teal:hover{background:var(--teal2)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--slate)}.btn-ghost:hover{border-color:var(--ink);color:var(--ink)}

.panel{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.panel-header{padding:15px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.panel-header h3{font-family:'Syne',sans-serif;font-size:.93rem;font-weight:700;display:flex;align-items:center;gap:7px;color:var(--ink)}
.panel-header h3 i{color:var(--purple)}
.panel-body{padding:20px}

.sparkline-wrap{display:flex;align-items:flex-end;gap:3px;height:60px;padding:0 4px}
.spark-bar{flex:1;border-radius:3px 3px 0 0;background:var(--purple);opacity:.7;min-width:4px;transition:height .4s ease;cursor:default}
.spark-bar:hover{opacity:1}
.spark-labels{display:flex;justify-content:space-between;font-size:.6rem;color:var(--muted);margin-top:4px;padding:0 4px}

.size-bars{display:flex;flex-direction:column;gap:12px}
.sb-row{}
.sb-meta{display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:4px}
.sb-meta .sbl-name{color:var(--ink);font-weight:600;text-transform:capitalize}
.sb-meta .sbl-cnt {color:var(--purple);font-weight:700}
.sb-track{height:8px;background:var(--border);border-radius:4px;overflow:hidden}
.sb-fill{height:100%;background:var(--purple);border-radius:4px}

.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media(max-width:900px){.two-col{grid-template-columns:1fr}}

.donut-wrap{display:flex;align-items:center;justify-content:center;gap:28px;padding:12px 0}
.donut-svg{flex-shrink:0}
.donut-legend{display:flex;flex-direction:column;gap:8px}
.dl-row{display:flex;align-items:center;gap:8px;font-size:.8rem}
.dl-swatch{width:10px;height:10px;border-radius:3px;flex-shrink:0}

.log-table{width:100%;border-collapse:collapse;font-size:.8rem}
.log-table th{text-align:left;padding:9px 12px;background:var(--bg);color:var(--slate);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border)}
.log-table td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--ink);vertical-align:middle}
.log-table tr:last-child td{border-bottom:none}
.log-table tr:hover td{background:var(--teal-lt)}
.feat-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:700}
.feat-hc{background:#f5f3ff;color:var(--purple)}
.feat-ts{background:#eff6ff;color:var(--blue)}
.action-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.65rem;font-weight:700}
.action-enabled{background:#f0fdf4;color:var(--green)}
.action-disabled{background:#fef2f2;color:var(--red)}
.action-changed{background:#fffbeb;color:var(--amber)}

.empty-state{text-align:center;padding:36px 20px;color:var(--muted)}
.empty-state i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:.3}
.empty-state p{font-size:.82rem}

@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:16px}.stat-grid{grid-template-columns:1fr 1fr}.two-col{grid-template-columns:1fr}}
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
    <a href="patient_flow.php"         class="nav-item"><i class="bi bi-bar-chart-line"></i> Patient Flow</a>
    <a href="reports.php"              class="nav-item"><i class="bi bi-file-earmark-text"></i> Reports</a>
      <a href="attendance_report.php"   class="nav-item"><i class="bi bi-clipboard2-pulse"></i> Attendance</a>
    <a href="activity_logs.php"        class="nav-item"><i class="bi bi-clipboard-data"></i> Activity Logs</a>
    <a href="accessibility_report.php" class="nav-item active"><i class="bi bi-universal-access"></i> Accessibility</a>
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
            <h1><i class="bi bi-universal-access" style="color:var(--purple);margin-right:8px"></i>Accessibility Usage Report</h1>
            <p>Track how patients use accessibility features</p>
        </div>
        <button class="btn btn-ghost" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>

    <div class="content">

        <div class="stat-grid">
            <div class="stat-card">
                <div class="sc-icon purple"><i class="bi bi-universal-access"></i></div>
                <div class="sc-val"><?= $active_patients ?></div>
                <div class="sc-lbl">Patients Using Accessibility</div>
                <div class="sc-sub"><?= $total_patients > 0 ? round($active_patients/$total_patients*100) : 0 ?>% of all patients</div>
            </div>
            <div class="stat-card">
                <div class="sc-icon teal"><i class="bi bi-circle-half"></i></div>
                <div class="sc-val"><?= $hc['users'] ?? 0 ?></div>
                <div class="sc-lbl">High Contrast Users</div>
                <div class="sc-sub"><?= $hc['events'] ?? 0 ?> toggle events this period</div>
            </div>
            <div class="stat-card">
                <div class="sc-icon blue"><i class="bi bi-type"></i></div>
                <div class="sc-val"><?= $ts['users'] ?? 0 ?></div>
                <div class="sc-lbl">Text Resize Users</div>
                <div class="sc-sub"><?= $ts['events'] ?? 0 ?> resize events this period</div>
            </div>
            <div class="stat-card">
                <div class="sc-icon amber"><i class="bi bi-activity"></i></div>
                <div class="sc-val"><?= count($logs) ?></div>
                <div class="sc-lbl">Log Events (period)</div>
                <div class="sc-sub"><?= $from ?> → <?= $to ?></div>
            </div>
        </div>

        <form method="GET" class="filter-bar">
            <label>From</label>
            <input type="date" name="from" value="<?= $from ?>" max="<?= date('Y-m-d') ?>">
            <label>To</label>
            <input type="date" name="to"   value="<?= $to   ?>" max="<?= date('Y-m-d') ?>">
            <button type="submit" class="btn btn-teal">Apply</button>
            <a href="accessibility_report.php" class="btn btn-ghost">Reset</a>
        </form>

        <div class="two-col">

            <div class="panel">
                <div class="panel-header">
                    <h3><i class="bi bi-graph-up"></i> Daily Usage — Last 30 Days</h3>
                </div>
                <div class="panel-body">
                    <?php
                    $max_trend = max(1, ...$trend_data);
                    ?>
                    <div class="sparkline-wrap" id="sparkline">
                        <?php foreach ($trend_data as $i => $val): ?>
                        <div class="spark-bar"
                             style="height:<?= round($val / $max_trend * 100) ?>%"
                             title="<?= $trend_labels[$i] ?>: <?= $val ?> event<?= $val!==1?'s':'' ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="spark-labels">
                        <span><?= $trend_labels[0]  ?></span>
                        <span><?= $trend_labels[14] ?></span>
                        <span><?= $trend_labels[29] ?></span>
                    </div>
                    <p style="font-size:.72rem;color:var(--muted);margin-top:8px;text-align:center">
                        Hover bars to see exact date &amp; count
                    </p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h3><i class="bi bi-pie-chart"></i> Feature Adoption</h3>
                </div>
                <div class="panel-body">
                    <?php
                    $adopt_pct = $total_patients > 0 ? round($active_patients/$total_patients*100) : 0;
                    $hc_on     = (int)($hc['enabled']  ?? 0);
                    $hc_off    = (int)($hc['disabled'] ?? 0);
                    $r = 40; $cx = 54; $cy = 54;
                    $circumference = 2 * M_PI * $r;
                    $fill = $circumference * ($adopt_pct / 100);
                    $gap  = $circumference - $fill;
                    ?>
                    <div class="donut-wrap">
                        <svg class="donut-svg" width="108" height="108" viewBox="0 0 108 108">
                            <circle cx="<?=$cx?>" cy="<?=$cy?>" r="<?=$r?>"
                                fill="none" stroke="var(--border)" stroke-width="14"/>
                            <circle cx="<?=$cx?>" cy="<?=$cy?>" r="<?=$r?>"
                                fill="none" stroke="var(--purple)" stroke-width="14"
                                stroke-dasharray="<?= round($fill,2) ?> <?= round($gap,2) ?>"
                                stroke-dashoffset="<?= $circumference * 0.25 ?>"
                                stroke-linecap="round"/>
                            <text x="<?=$cx?>" y="<?=$cy?>" text-anchor="middle" dominant-baseline="middle"
                                font-family="Syne,sans-serif" font-size="16" font-weight="800" fill="var(--ink)">
                                <?= $adopt_pct ?>%
                            </text>
                        </svg>
                        <div class="donut-legend">
                            <div class="dl-row"><div class="dl-swatch" style="background:var(--purple)"></div>Using accessibility (<?= $active_patients ?>)</div>
                            <div class="dl-row"><div class="dl-swatch" style="background:var(--border)"></div>Standard (<?= $total_patients - $active_patients ?>)</div>
                        </div>
                    </div>

                    <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

                    <p style="font-size:.78rem;font-weight:700;color:var(--slate);margin-bottom:10px">High Contrast Toggles (this period)</p>
                    <div style="display:flex;gap:12px">
                        <div style="flex:1;background:#f0fdf4;border-radius:10px;padding:12px;text-align:center">
                            <div style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--green)"><?= $hc_on ?></div>
                            <div style="font-size:.7rem;color:var(--slate)">Enabled</div>
                        </div>
                        <div style="flex:1;background:#fef2f2;border-radius:10px;padding:12px;text-align:center">
                            <div style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--red)"><?= $hc_off ?></div>
                            <div style="font-size:.7rem;color:var(--slate)">Disabled</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="two-col">

            <div class="panel">
                <div class="panel-header">
                    <h3><i class="bi bi-type"></i> Text Size Preference (All Patients)</h3>
                </div>
                <div class="panel-body">
                    <?php if (empty($text_sizes)): ?>
                    <div class="empty-state"><i class="bi bi-type"></i><p>No text size data yet.</p></div>
                    <?php else: ?>
                    <div class="size-bars">
                        <?php foreach ($text_sizes as $sz): ?>
                        <div class="sb-row">
                            <div class="sb-meta">
                                <span class="sbl-name"><?= htmlspecialchars($sz['size'] ?: 'normal') ?></span>
                                <span class="sbl-cnt"><?= $sz['cnt'] ?> patient<?= $sz['cnt']!=1?'s':'' ?></span>
                            </div>
                            <div class="sb-track">
                                <div class="sb-fill" style="width:<?= round($sz['cnt']/$max_size*100) ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p style="font-size:.72rem;color:var(--muted);margin-top:14px">
                        Based on current patient preferences (not time-filtered).
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h3><i class="bi bi-people"></i> Most Active Accessibility Users</h3>
                </div>
                <div style="overflow-x:auto">
                <table class="log-table">
                    <thead><tr>
                        <th>Patient</th>
                        <th>Events</th>
                        <th>Last Seen</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($top_users)): ?>
                    <tr><td colspan="3"><div class="empty-state" style="padding:20px"><i class="bi bi-people"></i><p>No data for this period.</p></div></td></tr>
                    <?php else: ?>
                    <?php foreach ($top_users as $tu): ?>
                    <tr>
                        <td style="font-weight:700"><?= htmlspecialchars($tu['name']) ?></td>
                        <td style="font-family:'Syne',sans-serif;font-weight:700;color:var(--purple)"><?= $tu['events'] ?></td>
                        <td style="color:var(--slate);font-size:.75rem"><?= date('M j, Y g:ia', strtotime($tu['last_seen'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

        </div>

        <div class="panel">
            <div class="panel-header">
                <h3><i class="bi bi-clipboard-data"></i> Accessibility Event Log</h3>
                <span style="font-size:.75rem;color:var(--muted)"><?= count($logs) ?> events</span>
            </div>
            <?php if (empty($logs)): ?>
            <div class="empty-state"><i class="bi bi-journal-x"></i><p>No accessibility events in this period.</p></div>
            <?php else: ?>
            <div style="overflow-x:auto;max-height:400px;overflow-y:auto">
            <table class="log-table">
                <thead style="position:sticky;top:0;z-index:1">
                <tr>
                    <th>Patient</th>
                    <th>Feature</th>
                    <th>Action</th>
                    <th>Value</th>
                    <th>Page</th>
                    <th>Time</th>
                </tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="font-weight:600"><?= htmlspecialchars($log['patient_name']) ?></td>
                    <td>
                        <span class="feat-badge <?= str_contains($log['event_type'],'high_contrast')?'feat-hc':'feat-ts' ?>">
                            <i class="bi <?= str_contains($log['event_type'],'high_contrast')?'bi-circle-half':'bi-type' ?>"></i>
                            <?= htmlspecialchars(str_replace('_',' ', $log['event_type'])) ?>
                        </span>
                    </td>
                    <td>
                        <span class="action-badge action-<?= str_contains($log['event_type'],'enabled')?'enabled':(str_contains($log['event_type'],'disabled')?'disabled':'changed') ?>">
                            <?= htmlspecialchars($log['event_data'] ?? $log['event_type']) ?>
                        </span>
                    </td>
                    <td style="color:var(--slate);font-size:.75rem"><?= htmlspecialchars($log['value'] ?? '—') ?></td>
                    <td style="color:var(--muted);font-size:.72rem;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($log['page'] ?? '') ?>">
                        <?= htmlspecialchars(basename($log['page'] ?? '—')) ?>
                    </td>
                    <td style="color:var(--slate);font-size:.72rem;white-space:nowrap">
                        <?= date('M j, g:ia', strtotime($log['created_at'])) ?>
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

<style>
@media print {
    .sidebar, .filter-bar, button, .topbar { display: none !important; }
    .main { margin-left: 0 !important; }
    .content { padding: 12px !important; }
    .panel { break-inside: avoid; }
}
</style>
</body>
</html>