<?php
session_start();
if (!isset($_SESSION['patient_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$patient_id   = $_SESSION['patient_id'];
$patient_name = $_SESSION['patient_name'] ?? 'Patient';
$success      = $_GET['success'] ?? '';
$error        = $_GET['error']   ?? '';

$filter = $_GET['filter'] ?? 'all';
$valid_filters = ['all','upcoming','pending','confirmed','completed','cancelled'];
if (!in_array($filter, $valid_filters)) $filter = 'all';

$where = "a.patient_id = $patient_id";
if ($filter === 'upcoming') $where .= " AND a.status IN ('pending','confirmed') AND a.date >= CURDATE()";
elseif ($filter !== 'all')  $where .= " AND a.status = '$filter'";

$appointments = [];
$res = $conn->query("SELECT a.id, a.date, a.time, a.status, a.reschedule_requested,
        a.new_date, a.new_time, a.reschedule_reason,
        d.name AS doctor, d.specialty,
        s.name AS service, s.price
    FROM appointments a
    JOIN doctors d ON a.doctor_id=d.id
    LEFT JOIN services s ON a.service_id=s.id
    WHERE $where ORDER BY a.date DESC, a.time DESC");
while ($r = $res->fetch_assoc()) $appointments[] = $r;

$counts = ['all'=>0,'upcoming'=>0,'pending'=>0,'confirmed'=>0,'completed'=>0,'cancelled'=>0];
$res = $conn->query("SELECT status, COUNT(*) AS c FROM appointments WHERE patient_id=$patient_id GROUP BY status");
while ($r = $res->fetch_assoc()) { $counts[$r['status']] = (int)$r['c']; $counts['all'] += (int)$r['c']; }
$counts['upcoming'] = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments
    WHERE patient_id=$patient_id AND status IN ('pending','confirmed') AND date >= CURDATE()")->fetch_assoc()['c'];

$unread = (int)$conn->query("SELECT COUNT(*) AS c FROM notifications WHERE patient_id=$patient_id AND is_read=0")->fetch_assoc()['c'];
$conn->close();

$status_cfg = [
    'pending'   => ['label'=>'Pending',   'color'=>'#fbbf24','bg'=>'rgba(251,191,36,.12)', 'icon'=>'bi-hourglass-split'],
    'confirmed' => ['label'=>'Confirmed', 'color'=>'#34d399','bg'=>'rgba(52,211,153,.12)', 'icon'=>'bi-check-circle'],
    'completed' => ['label'=>'Completed', 'color'=>'#60a5fa','bg'=>'rgba(96,165,250,.12)', 'icon'=>'bi-check2-all'],
    'cancelled' => ['label'=>'Cancelled', 'color'=>'#94a3b8','bg'=>'rgba(148,163,184,.1)', 'icon'=>'bi-x-circle'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>My Appointments – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--bg:#0e1621;--bg2:#151f2e;--bg3:#1c2a3e;--surface:#1e2d42;--border:rgba(255,255,255,.08);--teal:#00c9a7;--teal2:#00a88d;--amber:#fbbf24;--red:#f87171;--slate:#94a3b8;--text:#e2e8f0;--green:#34d399;--blue:#60a5fa;--muted:#64748b;--radius:12px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:'Lato',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}

.sidebar{width:220px;background:var(--bg2);min-height:100vh;position:fixed;left:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100;border-right:1px solid var(--border)}
.sidebar-logo{padding:20px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px;font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:#fff}
.sidebar-logo i{color:var(--teal);font-size:1.2rem}
.nav-section{padding:14px 16px 4px;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.2)}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 18px;color:var(--slate);text-decoration:none;font-size:.82rem;font-weight:500;transition:all .15s;position:relative}
.nav-item i{font-size:.9rem;width:16px;text-align:center}
.nav-item:hover{color:var(--text);background:rgba(255,255,255,.04)}
.nav-item.active{color:var(--teal);background:rgba(0,201,167,.08)}
.nav-item.active::before{content:'';position:absolute;left:0;top:15%;bottom:15%;width:3px;background:var(--teal);border-radius:0 2px 2px 0}
.nav-badge{margin-left:auto;background:#ef4444;color:#fff;font-size:.58rem;font-weight:700;padding:1px 5px;border-radius:20px}
.nav-divider{margin:6px 14px;border:none;border-top:1px solid var(--border)}
.nav-danger{color:rgba(248,113,113,.5)}
.nav-danger:hover{color:var(--red)!important;background:rgba(248,113,113,.07)!important}
.sidebar-footer{margin-top:auto;padding:12px;border-top:1px solid var(--border)}
.user-chip{display:flex;align-items:center;gap:9px;padding:8px 10px;background:rgba(255,255,255,.04);border-radius:8px;border:1px solid var(--border)}
.user-avatar{width:30px;height:30px;border-radius:50%;background:var(--teal);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;color:#0e1621;font-size:.8rem;flex-shrink:0}
.u-name{font-size:.78rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.u-role{font-size:.65rem;color:var(--muted)}

.main{margin-left:220px;flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 28px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-title{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;color:var(--text)}
.topbar-sub{font-size:.72rem;color:var(--muted)}
.content{padding:24px 28px}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;white-space:nowrap}
.btn-teal{background:var(--teal);color:#0e1621}
.btn-teal:hover{background:var(--teal2);transform:translateY(-1px)}

/* FILTER TABS */
.filter-tabs{display:flex;gap:3px;background:var(--bg2);border:1px solid var(--border);border-radius:30px;padding:4px;margin-bottom:22px;flex-wrap:wrap}
.ftab{padding:6px 14px;border-radius:25px;border:none;background:transparent;color:var(--slate);font-size:.78rem;font-weight:700;cursor:pointer;font-family:'Lato',sans-serif;transition:all .18s;text-decoration:none;display:flex;align-items:center;gap:5px}
.ftab:hover{color:var(--text)}
.ftab.active{background:var(--teal);color:#0e1621}
.ftab .cnt{border-radius:20px;padding:0 6px;font-size:.62rem}
.ftab.active .cnt{background:rgba(0,0,0,.15);color:#0e1621}
.ftab:not(.active) .cnt{background:var(--bg3);color:var(--slate)}

/* ALERTS */
.alert{border-radius:var(--radius);padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:8px;font-size:.82rem;border:1px solid}
.alert-success{background:rgba(52,211,153,.08);border-color:rgba(52,211,153,.2);color:var(--green)}
.alert-error{background:rgba(248,113,113,.08);border-color:rgba(248,113,113,.2);color:var(--red)}

/* APPOINTMENT CARDS */
.appt-list{display:flex;flex-direction:column;gap:10px}
.appt-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:border-color .2s,transform .2s}
.appt-card:hover{border-color:rgba(0,201,167,.25);transform:translateY(-1px)}
.appt-card-inner{display:flex}
.appt-date-block{background:var(--bg3);border-right:1px solid var(--border);padding:18px 16px;display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:72px;flex-shrink:0}
.adb-month{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
.adb-day{font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;color:#fff;line-height:1}
.adb-dow{font-size:.65rem;font-weight:600;color:var(--slate);margin-top:2px}
.appt-body{flex:1;padding:14px 18px;min-width:0}
.appt-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:8px;flex-wrap:wrap}
.appt-service{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;color:#fff}
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.appt-meta{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:10px}
.appt-meta-item{display:flex;align-items:center;gap:4px;font-size:.78rem;color:var(--slate)}
.appt-meta-item i{font-size:.78rem;color:var(--teal)}
.appt-price{color:var(--teal)!important;font-weight:700}
.reschedule-notice{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:8px;padding:8px 12px;font-size:.74rem;color:var(--amber);margin-bottom:10px;display:flex;align-items:center;gap:6px}
.appt-actions{display:flex;gap:6px;flex-wrap:wrap}
.btn-sm{padding:5px 12px;font-size:.74rem;border-radius:20px;border:none;font-family:'Lato',sans-serif;font-weight:700;cursor:pointer;transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
.btn-view{background:rgba(0,201,167,.12);color:var(--teal)}.btn-view:hover{background:var(--teal);color:#0e1621}
.btn-reschedule{background:rgba(96,165,250,.1);color:var(--blue)}.btn-reschedule:hover{background:var(--blue);color:#fff}
.btn-cancel{background:rgba(248,113,113,.1);color:var(--red)}.btn-cancel:hover{background:var(--red);color:#fff}

/* EMPTY */
.empty-state{text-align:center;padding:72px 20px}
.empty-state i{font-size:3rem;display:block;margin-bottom:14px;color:var(--border);opacity:.5}
.empty-state h3{font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:800;margin-bottom:7px;color:var(--text)}
.empty-state p{font-size:.83rem;color:var(--slate);margin-bottom:20px}

@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:14px}.appt-date-block{display:none}}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
  <div class="nav-section">Menu</div>
  <a href="dashboard.php"             class="nav-item"><i class="bi bi-house-fill"></i> Dashboard</a>
  <a href="book_appointment.php"      class="nav-item"><i class="bi bi-calendar-plus"></i> Book Appointment</a>
  <a href="appointments_calendar.php" class="nav-item"><i class="bi bi-calendar3"></i> Calendar</a>
  <a href="appointments.php"          class="nav-item active"><i class="bi bi-list-check"></i> My Appointments</a>
  <a href="notifications.php"         class="nav-item">
    <i class="bi bi-bell"></i> Notifications
    <?php if ($unread > 0): ?><span class="nav-badge"><?= $unread ?></span><?php endif; ?>
  </a>
  <a href="profile.php" class="nav-item"><i class="bi bi-person"></i> Profile</a>
  <hr class="nav-divider">
  <a href="logout.php" class="nav-item nav-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar"><?= strtoupper(substr($patient_name,0,1)) ?></div>
      <div>
        <div class="u-name"><?= htmlspecialchars($patient_name) ?></div>
        <div class="u-role">Patient</div>
      </div>
    </div>
  </div>
</aside>

<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title">My Appointments</div>
      <div class="topbar-sub"><?= $counts['all'] ?> total &nbsp;·&nbsp; <?= $counts['upcoming'] ?> upcoming</div>
    </div>
    <a href="book_appointment.php" class="btn btn-teal"><i class="bi bi-plus-lg"></i> New Appointment</a>
  </div>

  <div class="content">
    <?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="filter-tabs">
      <?php
      $tabs = [
        ['all','All',$counts['all']],['upcoming','Upcoming',$counts['upcoming']],
        ['pending','Pending',$counts['pending']],['confirmed','Confirmed',$counts['confirmed']],
        ['completed','Completed',$counts['completed']],['cancelled','Cancelled',$counts['cancelled']],
      ];
      foreach ($tabs as [$key,$label,$cnt]): ?>
      <a href="?filter=<?= $key ?>" class="ftab <?= $filter===$key?'active':'' ?>"><?= $label ?> <span class="cnt"><?= $cnt ?></span></a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($appointments)): ?>
    <div class="empty-state">
      <i class="bi bi-calendar-x"></i>
      <h3>No appointments found</h3>
      <p>You don't have any <?= $filter !== 'all' ? $filter : '' ?> appointments yet.</p>
      <a href="book_appointment.php" class="btn btn-teal"><i class="bi bi-calendar-plus"></i> Book Your First Appointment</a>
    </div>
    <?php else: ?>
    <div class="appt-list">
      <?php foreach ($appointments as $a):
        $cfg = $status_cfg[$a['status']] ?? ['label'=>ucfirst($a['status']),'color'=>'#94a3b8','bg'=>'rgba(148,163,184,.1)','icon'=>'bi-circle'];
        $is_upcoming = in_array($a['status'],['pending','confirmed']) && strtotime($a['date']) >= strtotime(date('Y-m-d'));
      ?>
      <div class="appt-card">
        <div class="appt-card-inner">
          <div class="appt-date-block">
            <div class="adb-month"><?= date('M', strtotime($a['date'])) ?></div>
            <div class="adb-day"><?= date('j', strtotime($a['date'])) ?></div>
            <div class="adb-dow"><?= date('D', strtotime($a['date'])) ?></div>
          </div>
          <div class="appt-body">
            <div class="appt-top">
              <div class="appt-service"><?= htmlspecialchars($a['service'] ?? 'Dental Appointment') ?></div>
              <span class="status-pill" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>"><i class="bi <?= $cfg['icon'] ?>"></i> <?= $cfg['label'] ?></span>
            </div>
            <div class="appt-meta">
              <span class="appt-meta-item"><i class="bi bi-calendar3"></i><?= date('F j, Y', strtotime($a['date'])) ?></span>
              <span class="appt-meta-item"><i class="bi bi-clock"></i><?= date('g:i A', strtotime($a['time'])) ?></span>
              <span class="appt-meta-item"><i class="bi bi-person-badge"></i><?= htmlspecialchars($a['doctor']) ?></span>
              <?php if ($a['specialty']): ?><span class="appt-meta-item" style="color:var(--muted)"><?= htmlspecialchars($a['specialty']) ?></span><?php endif; ?>
              <?php if ($a['price']): ?><span class="appt-meta-item appt-price"><i class="bi bi-cash"></i>₱<?= number_format($a['price']) ?></span><?php endif; ?>
            </div>
            <?php if ($a['reschedule_requested'] && $a['new_date']): ?>
            <div class="reschedule-notice"><i class="bi bi-arrow-repeat"></i>Reschedule requested to <?= date('F j, Y', strtotime($a['new_date'])) ?> at <?= date('g:i A', strtotime($a['new_time'])) ?>&nbsp;·&nbsp;<em><?= htmlspecialchars(mb_substr($a['reschedule_reason']??'',0,60)) ?></em></div>
            <?php endif; ?>
            <div class="appt-actions">
              <a href="appointment_summary.php?id=<?= $a['id'] ?>" class="btn-sm btn-view"><i class="bi bi-eye"></i> View</a>
              <?php if ($is_upcoming && !$a['reschedule_requested']): ?>
              <a href="request_reschedule.php?id=<?= $a['id'] ?>" class="btn-sm btn-reschedule"><i class="bi bi-arrow-repeat"></i> Reschedule</a>
              <?php endif; ?>
              <?php if ($is_upcoming): ?>
              <a href="cancel_appointment.php?id=<?= $a['id'] ?>" class="btn-sm btn-cancel" onclick="return confirm('Cancel this appointment?')"><i class="bi bi-x-circle"></i> Cancel</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script src="/assets/js/toast.js"></script>
</body>
</html>