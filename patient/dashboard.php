<?php
session_start();
if (!isset($_SESSION['patient_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$patient_id   = $_SESSION['patient_id'];
$patient_name = $_SESSION['patient_name'] ?? 'Patient';

$total     = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments WHERE patient_id=$patient_id")->fetch_assoc()['c'];
$upcoming  = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments WHERE patient_id=$patient_id AND status IN ('pending','confirmed') AND date>=CURDATE()")->fetch_assoc()['c'];
$completed = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments WHERE patient_id=$patient_id AND status='completed'")->fetch_assoc()['c'];
$cancelled = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments WHERE patient_id=$patient_id AND status='cancelled'")->fetch_assoc()['c'];
$unread    = (int)$conn->query("SELECT COUNT(*) AS c FROM notifications WHERE patient_id=$patient_id AND is_read=0")->fetch_assoc()['c'];

$next = null;
$r = $conn->query("SELECT a.id,a.date,a.time,a.status,d.name AS doctor,s.name AS service,s.price
    FROM appointments a JOIN doctors d ON a.doctor_id=d.id LEFT JOIN services s ON a.service_id=s.id
    WHERE a.patient_id=$patient_id AND a.status IN ('pending','confirmed') AND a.date>=CURDATE()
    ORDER BY a.date ASC,a.time ASC LIMIT 1");
if ($row = $r->fetch_assoc()) $next = $row;

$recent = [];
$r = $conn->query("SELECT a.id,a.date,a.time,a.status,d.name AS doctor,s.name AS service,s.price
    FROM appointments a JOIN doctors d ON a.doctor_id=d.id LEFT JOIN services s ON a.service_id=s.id
    WHERE a.patient_id=$patient_id ORDER BY a.date DESC,a.time DESC LIMIT 5");
while ($row = $r->fetch_assoc()) $recent[] = $row;

$notifs = [];
$r = $conn->query("SELECT id,title,message,type,is_read,created_at FROM notifications
    WHERE patient_id=$patient_id ORDER BY created_at DESC LIMIT 4");
while ($row = $r->fetch_assoc()) $notifs[] = $row;

$stmt = $conn->prepare("SELECT name,email,profile_picture,allergies FROM patients WHERE id=? LIMIT 1");
$stmt->bind_param('i', $patient_id); $stmt->execute();
$pinfo = $stmt->get_result()->fetch_assoc(); $stmt->close();
$conn->close();

$cancel_success = $_SESSION['cancel_success'] ?? ''; unset($_SESSION['cancel_success']);
$cancel_error   = $_SESSION['cancel_error']   ?? ''; unset($_SESSION['cancel_error']);

$status_cfg = [
    'pending'   => ['label'=>'Pending',   'color'=>'#fbbf24','bg'=>'rgba(251,191,36,.12)'],
    'confirmed' => ['label'=>'Confirmed', 'color'=>'#34d399','bg'=>'rgba(52,211,153,.12)'],
    'completed' => ['label'=>'Completed', 'color'=>'#60a5fa','bg'=>'rgba(96,165,250,.12)'],
    'cancelled' => ['label'=>'Cancelled', 'color'=>'#94a3b8','bg'=>'rgba(148,163,184,.1)'],
];
$avatar_url = ($pinfo['profile_picture'] ?? null) ? "/uploads/profile_pictures/{$pinfo['profile_picture']}" : null;
$days_until_next = $next ? max(0,(int)round((strtotime($next['date'])-strtotime(date('Y-m-d')))/86400)) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Dashboard – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--bg:#0e1621;--bg2:#151f2e;--bg3:#1c2a3e;--surface:#1e2d42;--border:rgba(255,255,255,.08);--teal:#00c9a7;--teal2:#00a88d;--amber:#fbbf24;--red:#f87171;--slate:#94a3b8;--text:#e2e8f0;--green:#34d399;--blue:#60a5fa;--purple:#a78bfa;--muted:#64748b;--radius:12px}
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
.user-avatar{width:30px;height:30px;border-radius:50%;background:var(--teal);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;color:#0e1621;font-size:.8rem;flex-shrink:0;overflow:hidden}
.user-avatar img{width:100%;height:100%;object-fit:cover}
.u-name{font-size:.78rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.u-role{font-size:.65rem;color:var(--muted)}

.main{margin-left:220px;flex:1;display:flex;flex-direction:column;min-width:0}

.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 28px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-title{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;color:var(--text)}
.topbar-sub{font-size:.72rem;color:var(--muted)}
.topbar-right{display:flex;align-items:center;gap:10px}
.date-chip{font-size:.75rem;color:var(--slate);background:var(--bg3);padding:4px 10px;border-radius:20px;border:1px solid var(--border)}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;white-space:nowrap}
.btn-teal{background:var(--teal);color:#0e1621}
.btn-teal:hover{background:var(--teal2);transform:translateY(-1px)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--slate)}
.btn-ghost:hover{border-color:var(--teal);color:var(--teal)}

.hero{background:linear-gradient(135deg,var(--bg2) 0%,var(--bg3) 100%);border-bottom:1px solid var(--border);padding:28px 32px;position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;top:-80px;right:-60px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(0,201,167,.12),transparent 70%);pointer-events:none}
.hero-greeting{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--teal);margin-bottom:4px}
.hero-name{font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#fff;margin-bottom:3px}
.hero-sub{font-size:.83rem;color:var(--slate);margin-bottom:18px}
.hero-btns{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:20px}

.next-card{background:rgba(0,201,167,.08);border:1px solid rgba(0,201,167,.2);border-radius:var(--radius);padding:14px 18px;display:flex;align-items:center;gap:14px;max-width:460px}
.nc-icon{width:40px;height:40px;border-radius:10px;background:rgba(0,201,167,.15);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--teal);flex-shrink:0}
.nc-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--teal);opacity:.7;margin-bottom:3px}
.nc-service{font-family:'Syne',sans-serif;font-size:.9rem;font-weight:800;color:#fff;margin-bottom:3px}
.nc-meta{font-size:.74rem;color:var(--slate);display:flex;gap:10px;flex-wrap:wrap}
.nc-badge{background:rgba(0,201,167,.2);color:var(--teal);font-size:.62rem;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:auto;white-space:nowrap;align-self:center}

.content{padding:24px 28px}

.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:18px;transition:border-color .2s}
.stat-card:hover{border-color:rgba(0,201,167,.3)}
.sc-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.95rem;margin-bottom:10px}
.sc-val{font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;color:#fff;line-height:1}
.sc-lbl{font-size:.72rem;color:var(--slate);margin-top:4px}

.two-col{display:grid;grid-template-columns:1fr 340px;gap:16px}

.panel{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.panel-hdr{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.panel-hdr h3{font-family:'Syne',sans-serif;font-size:.85rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:7px}
.panel-hdr h3 i{color:var(--teal)}
.panel-link{font-size:.75rem;color:var(--teal);text-decoration:none;font-weight:600}
.panel-link:hover{text-decoration:underline}

.appt-row{display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid var(--border);transition:background .12s}
.appt-row:last-child{border-bottom:none}
.appt-row:hover{background:rgba(255,255,255,.02)}
.appt-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.appt-service{font-size:.83rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.appt-meta{font-size:.71rem;color:var(--slate);margin-top:1px}
.status-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.appt-date{font-size:.74rem;color:var(--slate);margin-top:2px;text-align:right}

.notif-row{display:flex;gap:11px;padding:11px 18px;border-bottom:1px solid var(--border);transition:background .12s}
.notif-row:last-child{border-bottom:none}
.notif-row:hover{background:rgba(255,255,255,.02)}
.notif-row.unread{background:rgba(0,201,167,.04)}
.notif-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0}
.notif-title{font-size:.8rem;font-weight:700;color:var(--text);margin-bottom:2px}
.notif-body{font-size:.72rem;color:var(--slate);line-height:1.4}
.notif-time{font-size:.66rem;color:var(--muted);margin-top:2px}
.unread-dot{width:6px;height:6px;border-radius:50%;background:var(--teal);flex-shrink:0;margin-top:7px}

.quick-links{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:14px}
.ql{display:flex;align-items:center;gap:9px;padding:11px 12px;border:1px solid var(--border);border-radius:9px;text-decoration:none;color:var(--slate);transition:all .18s}
.ql:hover{border-color:var(--teal);color:var(--teal);background:rgba(0,201,167,.05)}
.ql i{font-size:1rem;color:var(--teal)}
.ql-label{font-size:.78rem;font-weight:700;color:var(--text)}
.ql-sub{font-size:.66rem;color:var(--muted)}

.alert{border-radius:var(--radius);padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:8px;font-size:.82rem;border:1px solid}
.alert-success{background:rgba(52,211,153,.08);border-color:rgba(52,211,153,.2);color:var(--green)}
.alert-error{background:rgba(248,113,113,.08);border-color:rgba(248,113,113,.2);color:var(--red)}
.allergy-banner{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);border-radius:9px;padding:10px 14px;display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--red);margin-bottom:16px}

.empty-state{padding:32px 18px;text-align:center;color:var(--muted)}
.empty-state i{font-size:2rem;display:block;margin-bottom:8px;opacity:.3}
.empty-state p{font-size:.82rem}

@media(max-width:1100px){.two-col{grid-template-columns:1fr}}
@media(max-width:900px){.stat-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.hero{padding:20px}.content{padding:14px}}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
  <div class="nav-section">Menu</div>
  <a href="dashboard.php"             class="nav-item active"><i class="bi bi-house-fill"></i> Dashboard</a>
  <a href="book_appointment.php"      class="nav-item"><i class="bi bi-calendar-plus"></i> Book Appointment</a>
  <a href="appointments_calendar.php" class="nav-item"><i class="bi bi-calendar3"></i> Calendar</a>
  <a href="appointments.php"          class="nav-item"><i class="bi bi-list-check"></i> My Appointments</a>
  <a href="notifications.php"         class="nav-item">
    <i class="bi bi-bell"></i> Notifications
    <?php if ($unread > 0): ?><span class="nav-badge"><?= $unread ?></span><?php endif; ?>
  </a>
  <a href="profile.php" class="nav-item"><i class="bi bi-person"></i> Profile</a>
  <hr class="nav-divider">
  <a href="logout.php" class="nav-item nav-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar">
        <?php if ($avatar_url): ?><img src="<?= htmlspecialchars($avatar_url) ?>" alt=""><?php else: ?><?= strtoupper(substr($patient_name,0,1)) ?><?php endif; ?>
      </div>
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
      <div class="topbar-title">Dashboard</div>
      <div class="topbar-sub">Welcome back, <?= htmlspecialchars(explode(' ',$patient_name)[0]) ?></div>
    </div>
    <div class="topbar-right">
      <span class="date-chip"><i class="bi bi-calendar3" style="margin-right:4px"></i><?= date('D, M j Y') ?></span>
      <a href="book_appointment.php" class="btn btn-teal"><i class="bi bi-plus-lg"></i> Book Now</a>
    </div>
  </div>

  <!-- HERO -->
  <div class="hero">
    <div class="hero-greeting">Patient Dashboard</div>
    <div class="hero-name">Hello, <?= htmlspecialchars(explode(' ',$patient_name)[0]) ?> 👋</div>
    <div class="hero-sub"><?= date('l, F j, Y') ?></div>
    <div class="hero-btns">
      <a href="book_appointment.php"      class="btn btn-teal"><i class="bi bi-plus-lg"></i> Book Appointment</a>
      <a href="appointments_calendar.php" class="btn btn-ghost"><i class="bi bi-calendar3"></i> View Calendar</a>
    </div>
    <?php if ($next): ?>
    <div class="next-card">
      <div class="nc-icon"><i class="bi bi-calendar-check"></i></div>
      <div style="flex:1;min-width:0">
        <div class="nc-label">Next Appointment</div>
        <div class="nc-service"><?= htmlspecialchars($next['service'] ?? 'Dental Visit') ?></div>
        <div class="nc-meta">
          <span><i class="bi bi-calendar3" style="margin-right:3px"></i><?= date('M j, Y', strtotime($next['date'])) ?></span>
          <span><i class="bi bi-clock" style="margin-right:3px"></i><?= date('g:i A', strtotime($next['time'])) ?></span>
          <span><i class="bi bi-person-badge" style="margin-right:3px"></i><?= htmlspecialchars($next['doctor']) ?></span>
        </div>
      </div>
      <?php if ($days_until_next===0): ?><span class="nc-badge">Today!</span>
      <?php elseif ($days_until_next===1): ?><span class="nc-badge">Tomorrow</span>
      <?php elseif ($days_until_next<=7): ?><span class="nc-badge">In <?= $days_until_next ?> days</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="content">
    <?php if ($cancel_success): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($cancel_success) ?></div><?php endif; ?>
    <?php if ($cancel_error):   ?><div class="alert alert-error"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($cancel_error) ?></div><?php endif; ?>
    <?php if ($pinfo['allergies'] ?? null): ?>
    <div class="allergy-banner"><i class="bi bi-exclamation-triangle-fill"></i><strong>Allergy on file:</strong>&nbsp;<?= htmlspecialchars(mb_substr($pinfo['allergies'],0,100)) ?>&nbsp;·&nbsp;<a href="profile.php" style="color:inherit;font-weight:700">Update →</a></div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stat-row">
      <div class="stat-card">
        <div class="sc-icon" style="background:rgba(0,201,167,.12);color:var(--teal)"><i class="bi bi-calendar-heart"></i></div>
        <div class="sc-val"><?= $total ?></div><div class="sc-lbl">Total Appointments</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon" style="background:rgba(251,191,36,.1);color:var(--amber)"><i class="bi bi-hourglass-split"></i></div>
        <div class="sc-val"><?= $upcoming ?></div><div class="sc-lbl">Upcoming</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon" style="background:rgba(96,165,250,.1);color:var(--blue)"><i class="bi bi-check2-all"></i></div>
        <div class="sc-val"><?= $completed ?></div><div class="sc-lbl">Completed</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon" style="background:rgba(148,163,184,.1);color:var(--slate)"><i class="bi bi-x-circle"></i></div>
        <div class="sc-val"><?= $cancelled ?></div><div class="sc-lbl">Cancelled</div>
      </div>
    </div>

    <div class="two-col">
      <!-- LEFT -->
      <div>
        <div class="panel" style="margin-bottom:14px">
          <div class="panel-hdr">
            <h3><i class="bi bi-clock-history"></i> Recent Appointments</h3>
            <a href="appointments.php" class="panel-link">View all →</a>
          </div>
          <?php if (empty($recent)): ?>
          <div class="empty-state"><i class="bi bi-calendar-x"></i><p>No appointments yet. <a href="book_appointment.php" style="color:var(--teal);font-weight:600">Book one now →</a></p></div>
          <?php else: ?>
          <?php foreach ($recent as $a):
            $sc = $status_cfg[$a['status']] ?? ['color'=>'#94a3b8','bg'=>'rgba(148,163,184,.1)','label'=>ucfirst($a['status'])];
          ?>
          <div class="appt-row">
            <div class="appt-dot" style="background:<?= $sc['color'] ?>"></div>
            <div style="flex:1;min-width:0">
              <div class="appt-service"><?= htmlspecialchars($a['service'] ?? 'Dental Visit') ?></div>
              <div class="appt-meta"><?= htmlspecialchars($a['doctor']) ?><?= $a['price'] ? ' · ₱'.number_format($a['price']) : '' ?></div>
            </div>
            <div style="text-align:right;flex-shrink:0">
              <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>"><?= $sc['label'] ?></span>
              <div class="appt-date"><?= date('M j, Y', strtotime($a['date'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="panel">
          <div class="panel-hdr"><h3><i class="bi bi-grid"></i> Quick Actions</h3></div>
          <div class="quick-links">
            <a href="book_appointment.php"      class="ql"><i class="bi bi-calendar-plus"></i><div><div class="ql-label">Book</div><div class="ql-sub">New appointment</div></div></a>
            <a href="appointments.php"          class="ql"><i class="bi bi-list-check"></i><div><div class="ql-label">History</div><div class="ql-sub">All appointments</div></div></a>
            <a href="appointments_calendar.php" class="ql"><i class="bi bi-calendar3"></i><div><div class="ql-label">Calendar</div><div class="ql-sub">Monthly view</div></div></a>
            <a href="profile.php"               class="ql"><i class="bi bi-person-gear"></i><div><div class="ql-label">Profile</div><div class="ql-sub">Update info</div></div></a>
          </div>
        </div>
      </div>

      <!-- RIGHT: NOTIFICATIONS -->
      <div>
        <div class="panel">
          <div class="panel-hdr">
            <h3><i class="bi bi-bell"></i> Notifications
              <?php if ($unread): ?><span style="background:var(--red);color:#fff;font-size:.58rem;font-weight:700;padding:1px 6px;border-radius:20px"><?= $unread ?></span><?php endif; ?>
            </h3>
            <a href="notifications.php" class="panel-link">All →</a>
          </div>
          <?php if (empty($notifs)): ?>
          <div class="empty-state"><i class="bi bi-bell-slash"></i><p>No notifications yet.</p></div>
          <?php else:
          $ni_ico = ['info'=>'bi-info-circle','success'=>'bi-check-circle','warning'=>'bi-exclamation-triangle','error'=>'bi-x-circle','reminder'=>'bi-alarm','appointment'=>'bi-calendar-check'];
          $ni_col = ['info'=>'rgba(96,165,250,.15)','success'=>'rgba(52,211,153,.15)','warning'=>'rgba(251,191,36,.12)','error'=>'rgba(248,113,113,.12)','reminder'=>'rgba(0,201,167,.12)','appointment'=>'rgba(52,211,153,.12)'];
          $ni_tc  = ['info'=>'#60a5fa','success'=>'#34d399','warning'=>'#fbbf24','error'=>'#f87171','reminder'=>'#00c9a7','appointment'=>'#34d399'];
          foreach ($notifs as $n):
            $nt = $n['type'] ?? 'info';
          ?>
          <div class="notif-row <?= $n['is_read']?'':'unread' ?>">
            <div class="notif-icon" style="background:<?= $ni_col[$nt]??$ni_col['info'] ?>;color:<?= $ni_tc[$nt]??$ni_tc['info'] ?>"><i class="bi <?= $ni_ico[$nt]??'bi-bell' ?>"></i></div>
            <div style="flex:1;min-width:0">
              <div class="notif-title"><?= htmlspecialchars($n['title']??'') ?></div>
              <div class="notif-body"><?= htmlspecialchars(mb_substr($n['message']??'',0,90)) ?></div>
              <div class="notif-time"><?= date('M j · g:i A', strtotime($n['created_at'])) ?></div>
            </div>
            <?php if (!$n['is_read']): ?><div class="unread-dot"></div><?php endif; ?>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="/assets/js/toast.js"></script>
</body>
</html>