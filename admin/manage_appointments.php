<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';
$success    = $_GET['success'] ?? '';
$error      = $_GET['error']   ?? '';

if ($is_admin && isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $conn->query("DELETE FROM appointments WHERE id=" . (int)$_GET['delete']);
    header("Location: manage_appointments.php?success=" . urlencode("Appointment deleted."));
    exit;
}

$fs  = $_GET['status']    ?? 'all';
$fd  = $_GET['doctor']    ?? 'all';
$fsv = $_GET['service']   ?? 'all';
$ff  = $_GET['date_from'] ?? '';
$ft  = $_GET['date_to']   ?? '';
$fq  = trim($_GET['q']    ?? '');

$where = [];
if ($fs !== 'all') $where[] = "a.status='"    . $conn->real_escape_string($fs)  . "'";
if ($fd !== 'all') $where[] = "a.doctor_id="  . (int)$fd;
if ($fsv!== 'all') $where[] = "a.service_id=" . (int)$fsv;
if ($ff)           $where[] = "a.date>='"     . $conn->real_escape_string($ff) . "'";
if ($ft)           $where[] = "a.date<='"     . $conn->real_escape_string($ft) . "'";
if ($fq)           $where[] = "(COALESCE(p.name,a.guest_name) LIKE '%" . $conn->real_escape_string($fq) . "%' OR d.name LIKE '%" . $conn->real_escape_string($fq) . "%')";
$wc = $where ? "WHERE " . implode(" AND ", $where) : "";

$cols = $conn->query("SHOW COLUMNS FROM appointments")->fetch_all(MYSQLI_ASSOC);
$col_names = array_column($cols, 'Field');
$has_guest      = in_array('is_guest',    $col_names);
$has_guest_name = in_array('guest_name',  $col_names);
$has_guest_ph   = in_array('guest_phone', $col_names);
$has_rr         = in_array('reschedule_requested', $col_names);

$sel_guest   = $has_guest      ? "IFNULL(a.is_guest, 0)"    : "0";
$sel_gname   = $has_guest_name ? "IFNULL(a.guest_name, '')" : "''";
$sel_gph     = $has_guest_ph   ? "a.guest_phone"            : "NULL";
$sel_rr      = $has_rr         ? "IFNULL(a.reschedule_requested, 0)" : "0";
$sel_patient = $has_guest_name ? "COALESCE(p.name, a.guest_name, 'Guest')" : "COALESCE(p.name, 'Guest')";
$sel_phone   = $has_guest_ph   ? "COALESCE(p.phone, a.guest_phone, '')"    : "COALESCE(p.phone, '')";

$appointments = [];
$res = $conn->query("SELECT a.id, a.date, a.time, a.status,
        $sel_rr      AS reschedule_requested,
        $sel_gname   AS guest_name,
        $sel_guest   AS is_guest,
        $sel_patient AS patient,
        $sel_phone   AS patient_phone,
        d.name AS doctor, d.specialty,
        s.name AS service, s.price
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id=p.id
    JOIN doctors d ON a.doctor_id=d.id
    LEFT JOIN services s ON a.service_id=s.id
    $wc ORDER BY a.date DESC, a.time DESC");
while ($r = $res->fetch_assoc()) {
    if (empty($r['status'])) $r['status'] = 'pending';
    $appointments[] = $r;
}

$counts = ['all'=>0,'pending'=>0,'confirmed'=>0,'completed'=>0,'cancelled'=>0];
$res = $conn->query("SELECT status, COUNT(*) AS c FROM appointments GROUP BY status");
while ($r = $res->fetch_assoc()) { $counts[$r['status']] = (int)$r['c']; $counts['all'] += (int)$r['c']; }

$doctors  = $conn->query("SELECT id,name FROM doctors WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$services = $conn->query("SELECT id,name FROM services WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$conn->close();

$status_cfg = [
    'pending'   => ['bg'=>'#fffbeb','color'=>'#92400e','icon'=>'bi-hourglass-split'],
    'confirmed' => ['bg'=>'#f0fdf4','color'=>'#14532d','icon'=>'bi-check-circle'],
    'completed' => ['bg'=>'#eff6ff','color'=>'#1e3a8a','icon'=>'bi-check2-all'],
    'cancelled' => ['bg'=>'#f9fafb','color'=>'#6b7280','icon'=>'bi-x-circle'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Manage Appointments – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--bg:#f7f5f0;--bg2:#fff;--sidebar:#111827;--border:#e8e3da;--teal:#0d9488;--teal2:#0f766e;--teal-lt:#f0fdf9;--amber:#d97706;--red:#dc2626;--green:#16a34a;--blue:#2563eb;--ink:#111827;--slate:#6b7280;--muted:#9ca3af;--radius:14px;--shadow:0 1px 10px rgba(0,0,0,.05)}
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
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;flex-wrap:wrap;gap:10px}
.topbar h1{font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800}
.topbar p{font-size:.78rem;color:var(--slate);margin-top:1px}
.content{padding:24px 32px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .2s;text-decoration:none;white-space:nowrap}
.btn-teal{background:var(--teal);color:#fff}.btn-teal:hover{background:var(--teal2)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--slate)}.btn-ghost:hover{border-color:var(--ink);color:var(--ink)}

.status-tabs{display:flex;gap:4px;background:var(--bg2);border:1px solid var(--border);border-radius:30px;padding:4px;margin-bottom:18px;flex-wrap:wrap}
.stab{padding:6px 14px;border-radius:25px;border:none;background:transparent;color:var(--slate);font-size:.78rem;font-weight:600;cursor:pointer;font-family:'Lato',sans-serif;transition:all .2s;text-decoration:none;display:flex;align-items:center;gap:5px}
.stab:hover{color:var(--ink)}
.stab.active{background:var(--teal);color:#fff}
.stab .cnt{background:rgba(255,255,255,.2);border-radius:20px;padding:0 5px;font-size:.62rem}
.stab:not(.active) .cnt{background:var(--border);color:var(--slate)}

.filter-bar{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.filter-bar select,.filter-bar input{border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:.8rem;font-family:'Lato',sans-serif;color:var(--ink);background:var(--bg);outline:none;cursor:pointer}
.filter-bar select:focus,.filter-bar input:focus{border-color:var(--teal)}
.search-wrap{position:relative;flex:1;min-width:180px}
.search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--slate);font-size:.85rem;pointer-events:none}
.search-wrap input{width:100%;padding-left:32px}

.alert{border-radius:var(--radius);padding:11px 15px;margin-bottom:16px;display:flex;align-items:center;gap:9px;font-size:.84rem}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:var(--red)}

.table-wrap{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.appt-table{width:100%;border-collapse:collapse;font-size:.82rem}
.appt-table th{text-align:left;padding:10px 14px;background:var(--bg);color:var(--slate);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--border);white-space:nowrap}
.appt-table td{padding:12px 14px;border-bottom:1px solid var(--border);color:var(--ink);vertical-align:middle}
.appt-table tr:last-child td{border-bottom:none}
.appt-table tbody tr:hover td{background:#fafaf8}
.time-chip{font-family:'Syne',sans-serif;font-size:.8rem;font-weight:700;color:var(--teal);display:block}
.date-chip{font-size:.75rem;color:var(--slate)}
.patient-name{font-weight:700}
.patient-meta{font-size:.72rem;color:var(--muted);margin-top:1px}
.guest-tag{display:inline-block;background:#fdf4ff;color:#7e22ce;font-size:.6rem;font-weight:700;padding:1px 5px;border-radius:10px;margin-left:3px}
.doctor-name{font-weight:600}
.doctor-spec{font-size:.7rem;color:var(--muted)}
.status-pill{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.reschedule-flag{display:inline-flex;align-items:center;gap:3px;font-size:.62rem;font-weight:700;background:#fffbeb;color:var(--amber);padding:1px 6px;border-radius:10px;margin-left:4px}
.action-btns{display:flex;gap:4px;flex-wrap:nowrap}
.ab{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;border:none;cursor:pointer;font-size:.82rem;transition:all .15s;text-decoration:none}
.ab-view    {background:#eff6ff;color:var(--blue)}.ab-view:hover{background:var(--blue);color:#fff}
.ab-approve {background:#f0fdf4;color:var(--green)}.ab-approve:hover{background:var(--green);color:#fff}
.ab-reschedule{background:#fffbeb;color:var(--amber)}.ab-reschedule:hover{background:var(--amber);color:#fff}
.ab-complete{background:#eff6ff;color:var(--blue)}.ab-complete:hover{background:var(--blue);color:#fff}
.ab-cancel  {background:#fef2f2;color:var(--red)}.ab-cancel:hover{background:var(--red);color:#fff}
.ab-delete  {background:#f9fafb;color:var(--slate)}.ab-delete:hover{background:var(--red);color:#fff}
.price-chip{font-family:'Syne',sans-serif;font-size:.8rem;font-weight:700;color:var(--teal)}

.empty-state{text-align:center;padding:56px 20px;color:var(--muted)}
.empty-state i{font-size:3rem;display:block;margin-bottom:12px;opacity:.25}
.empty-state p{font-size:.84rem}

@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:16px}}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-logo"><i class="bi bi-tooth"></i> Vytal Dental</div>
    <div class="nav-section">Main</div>
    <a href="dashboard.php"            class="nav-item"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="manage_appointments.php"  class="nav-item active"><i class="bi bi-calendar-check"></i> Appointments
        <?php if ($counts['pending']): ?><span class="nav-badge"><?= $counts['pending'] ?></span><?php endif; ?>
    </a>
    <a href="manage_patients.php"      class="nav-item"><i class="bi bi-people"></i> Patients</a>
    <?php if ($is_admin): ?>
    <a href="manage_doctors.php"       class="nav-item"><i class="bi bi-person-badge"></i> Dentists</a>
    <a href="manage_services.php"      class="nav-item"><i class="bi bi-tooth"></i> Services</a>
    <?php endif; ?>
    <div class="nav-section">Analytics</div>
    <a href="patient_flow.php"         class="nav-item"><i class="bi bi-bar-chart-line"></i> Patient Flow</a>
    <a href="reports.php"              class="nav-item"><i class="bi bi-file-earmark-text"></i> Reports</a>
    <a href="attendance_report.php"    class="nav-item"><i class="bi bi-person-check"></i> Attendance</a>
    <a href="activity_logs.php"        class="nav-item"><i class="bi bi-clipboard-data"></i> Activity Logs</a>
    <a href="accessibility_report.php" class="nav-item"><i class="bi bi-universal-access"></i> Accessibility</a>
    <div class="nav-section">Account</div>
    <a href="logout.php" class="nav-item" style="color:rgba(255,100,100,.6)"><i class="bi bi-box-arrow-right"></i> Logout</a>
    <div class="sidebar-footer">
        <div class="admin-chip">
            <div class="admin-avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div>
            <div class="admin-info"><div class="name"><?= htmlspecialchars($admin_name) ?></div><div class="role"><?= $admin_role ?></div></div>
        </div>
    </div>
</aside>

<div class="main">
    <div class="topbar">
        <div>
            <h1>Manage Appointments</h1>
            <p><?= $counts['all'] ?> total &nbsp;·&nbsp; <?= $counts['pending'] ?> pending approval</p>
        </div>
        <a href="reports.php" class="btn btn-ghost"><i class="bi bi-printer"></i> Daily Report</a>
    </div>

    <div class="content">
        <?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-error"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="status-tabs">
            <?php
            $tabs = [['all','All',$counts['all']],['pending','Pending',$counts['pending']],['confirmed','Confirmed',$counts['confirmed']],['completed','Completed',$counts['completed']],['cancelled','Cancelled',$counts['cancelled']]];
            foreach ($tabs as [$key,$label,$cnt]):
            ?>
            <a href="?status=<?= $key ?>&doctor=<?= $fd ?>&service=<?= $fsv ?>&date_from=<?= $ff ?>&date_to=<?= $ft ?>&q=<?= urlencode($fq) ?>"
               class="stab <?= $fs===$key?'active':'' ?>">
                <?= $label ?><span class="cnt"><?= $cnt ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <form method="GET" class="filter-bar">
            <input type="hidden" name="status" value="<?= htmlspecialchars($fs) ?>">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="q" placeholder="Search patient or dentist…" value="<?= htmlspecialchars($fq) ?>">
            </div>
            <select name="doctor">
                <option value="all">All Dentists</option>
                <?php foreach ($doctors as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $fd==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="service">
                <option value="all">All Services</option>
                <?php foreach ($services as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $fsv==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" value="<?= htmlspecialchars($ff) ?>" placeholder="From">
            <input type="date" name="date_to"   value="<?= htmlspecialchars($ft) ?>" placeholder="To">
            <button type="submit" class="btn btn-teal">Filter</button>
            <a href="manage_appointments.php" class="btn btn-ghost">Reset</a>
        </form>

        <?php if (empty($appointments)): ?>
        <div class="table-wrap">
            <div class="empty-state"><i class="bi bi-calendar-x"></i><p>No appointments match your filters.</p></div>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <div style="overflow-x:auto">
            <table class="appt-table">
                <thead><tr>
                    <th>Date / Time</th>
                    <th>Patient</th>
                    <th>Dentist</th>
                    <th>Service</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($appointments as $a):
                    $sc = $status_cfg[$a['status']] ?? ['bg'=>'#f3f4f6','color'=>'#374151','icon'=>'bi-circle'];
                ?>
                <tr>
                    <td>
                        <span class="time-chip"><?= date('g:i A', strtotime($a['time'])) ?></span>
                        <span class="date-chip"><?= date('M j, Y', strtotime($a['date'])) ?></span>
                    </td>
                    <td>
                        <div class="patient-name">
                            <?= htmlspecialchars($a['patient']) ?>
                            <?php if ($a['is_guest']): ?><span class="guest-tag">Guest</span><?php endif; ?>
                            <?php if ($a['reschedule_requested']): ?><span class="reschedule-flag"><i class="bi bi-arrow-repeat"></i> Reschedule</span><?php endif; ?>
                        </div>
                        <?php if ($a['patient_phone']): ?><div class="patient-meta"><?= htmlspecialchars($a['patient_phone']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <div class="doctor-name"><?= htmlspecialchars($a['doctor']) ?></div>
                        <div class="doctor-spec"><?= htmlspecialchars($a['specialty'] ?? '') ?></div>
                    </td>
                    <td><?= htmlspecialchars($a['service'] ?? '—') ?></td>
                    <td><?php if ($a['price']): ?><span class="price-chip">₱<?= number_format($a['price']) ?></span><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
                    <td>
                        <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                            <i class="bi <?= $sc['icon'] ?>"></i> <?= ucfirst($a['status']) ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-btns">
                            <a href="appointment_summary.php?id=<?= $a['id'] ?>" class="ab ab-view" title="View details"><i class="bi bi-eye"></i></a>
                            <?php if ($a['status'] === 'pending'): ?>
                            <a href="approve_appointment.php?id=<?= $a['id'] ?>" class="ab ab-approve" title="Confirm appointment" onclick="return confirm('Confirm this appointment?')"><i class="bi bi-check-lg"></i></a>
                            <?php endif; ?>
                            <?php if (in_array($a['status'], ['pending','confirmed'])): ?>
                            <a href="complete_appointment.php?id=<?= $a['id'] ?>" class="ab ab-complete" title="Mark as completed" onclick="return confirm('Mark this appointment as completed?')"><i class="bi bi-check2-all"></i></a>
                            <a href="cancel_appointment.php?id=<?= $a['id'] ?>" class="ab ab-cancel" title="Cancel appointment" onclick="return confirm('Cancel this appointment? This cannot be undone.')"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                            <?php if ($is_admin): ?>
                            <a href="?delete=<?= $a['id'] ?>" class="ab ab-delete" title="Delete record"
                               onclick="return confirm('Permanently delete this record?')"><i class="bi bi-trash"></i></a>
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
</body>
</html>