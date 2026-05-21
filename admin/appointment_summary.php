<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';
require_once '../includes/ActivityLogger.php';
require_once '../includes/EmailService.php';

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_appointments.php?error=' . urlencode('Invalid appointment ID'));
    exit;
}

$appt_id = (int)$_GET['id'];
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $logger = new ActivityLogger($conn);

    if ($action === 'approve') {
        $conn->query("UPDATE appointments SET status='confirmed' WHERE id=$appt_id AND status='pending'");
        $logger->log($_SESSION['admin_id'], 'admin', $admin_name, 'approve_appointment', "Approved appointment #$appt_id");
        $success = 'Appointment confirmed successfully.';

    } elseif ($action === 'complete') {
        $conn->query("UPDATE appointments SET status='completed' WHERE id=$appt_id AND status IN ('pending','confirmed')");
        $logger->log($_SESSION['admin_id'], 'admin', $admin_name, 'complete_appointment', "Completed appointment #$appt_id");
        $success = 'Appointment marked as completed.';

    } elseif ($action === 'cancel') {
        $reason = trim($_POST['cancel_reason'] ?? '');
        $conn->query("UPDATE appointments SET status='cancelled' WHERE id=$appt_id AND status NOT IN ('cancelled','completed')");
        $logger->log($_SESSION['admin_id'], 'admin', $admin_name, 'cancel_appointment', "Cancelled appointment #$appt_id" . ($reason ? ": $reason" : ''));
        $success = 'Appointment cancelled.';

    } elseif ($action === 'add_note') {
        $note = trim($_POST['admin_note'] ?? '');
        if ($note) {
            $stmt = $conn->prepare("UPDATE appointments SET admin_notes=? WHERE id=?");
            $stmt->bind_param('si', $note, $appt_id);
            $stmt->execute();
            $stmt->close();
            $success = 'Note saved.';
        }
    }
}

$stmt = $conn->prepare("
    SELECT a.*,
           COALESCE(p.name,  a.guest_name)  AS patient_name,
           COALESCE(p.email, a.guest_email) AS patient_email,
           COALESCE(p.phone, a.guest_phone) AS patient_phone,
           p.allergies, p.dental_notes, p.birthdate, p.gender,
           p.emergency_contact_name, p.emergency_contact_phone,
           d.name AS doctor_name, d.specialty, d.license_number,
           s.name AS service_name, s.price, s.duration_minutes, s.category
    FROM appointments a
    LEFT JOIN patients p  ON a.patient_id  = p.id
    JOIN  doctors  d  ON a.doctor_id   = d.id
    LEFT JOIN services s  ON a.service_id  = s.id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $appt_id);
$stmt->execute();
$a = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$a) {
    header('Location: manage_appointments.php?error=' . urlencode('Appointment not found'));
    exit;
}

if ($success) {
    $r = $conn->query("SELECT status, admin_notes FROM appointments WHERE id=$appt_id");
    $row = $r->fetch_assoc();
    $a['status']      = $row['status'];
    $a['admin_notes'] = $row['admin_notes'];
}

$other = [];
if ($a['patient_id']) {
    $res = $conn->query("SELECT a.id, a.date, a.time, a.status, s.name AS service
        FROM appointments a LEFT JOIN services s ON a.service_id=s.id
        WHERE a.patient_id={$a['patient_id']} AND a.id != $appt_id
        ORDER BY a.date DESC LIMIT 5");
    while ($r = $res->fetch_assoc()) $other[] = $r;
}

$conn->close();

$status_cfg = [
    'pending'   => ['label'=>'Pending',   'color'=>'#d97706','bg'=>'#fffbeb','icon'=>'bi-hourglass-split'],
    'confirmed' => ['label'=>'Confirmed', 'color'=>'#16a34a','bg'=>'#f0fdf4','icon'=>'bi-check-circle'],
    'completed' => ['label'=>'Completed', 'color'=>'#2563eb','bg'=>'#eff6ff','icon'=>'bi-check2-all'],
    'cancelled' => ['label'=>'Cancelled', 'color'=>'#6b7280','bg'=>'#f9fafb','icon'=>'bi-x-circle'],
];
$sc = $status_cfg[$a['status']] ?? $status_cfg['pending'];
$is_actionable = in_array($a['status'], ['pending','confirmed']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Appointment #<?= $appt_id ?> – Vytal Dental Admin</title>
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
.sidebar-footer{margin-top:auto;padding:14px;border-top:1px solid rgba(255,255,255,.07)}
.admin-chip{display:flex;align-items:center;gap:10px;padding:9px 12px;background:rgba(255,255,255,.06);border-radius:10px}
.admin-avatar{width:32px;height:32px;border-radius:50%;background:#00c9a7;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700;color:#111;font-size:.9rem;flex-shrink:0}
.a-name{font-size:.82rem;font-weight:700;color:#fff}
.a-role{font-size:.68rem;color:rgba(255,255,255,.4);text-transform:capitalize}

.main{margin-left:230px;flex:1;display:flex;flex-direction:column}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;gap:12px;flex-wrap:wrap}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:inline-flex;align-items:center;gap:6px;color:var(--slate);text-decoration:none;font-size:.82rem;font-weight:600;padding:6px 12px;border-radius:20px;border:1px solid var(--border);transition:all .18s}
.back-btn:hover{color:var(--ink);border-color:var(--ink)}
.topbar-title{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800}
.topbar-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

.content{padding:24px 28px;display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start}

.card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:16px}
.card:last-child{margin-bottom:0}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.card-header h3{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:800;display:flex;align-items:center;gap:7px}
.card-header h3 i{color:var(--teal)}
.card-body{padding:18px 20px}

.status-banner{padding:20px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:1px solid var(--border)}
.sb-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
.sb-appt-id{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin-bottom:4px}
.sb-service{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800}
.sb-meta{font-size:.8rem;color:var(--slate);margin-top:4px;display:flex;gap:14px;flex-wrap:wrap}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:25px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.info-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px}
.info-val{font-size:.87rem;font-weight:600}
.info-sub{font-size:.74rem;color:var(--slate);margin-top:1px}
.divider{grid-column:1/-1;height:1px;background:var(--border);margin:2px 0}

.allergy-alert{background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:10px 14px;display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--red);margin-bottom:14px}

.alert{border-radius:var(--radius);padding:10px 15px;margin-bottom:16px;display:flex;align-items:center;gap:8px;font-size:.84rem}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:var(--red)}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;white-space:nowrap}
.btn-teal  {background:var(--teal);color:#fff}.btn-teal:hover{background:var(--teal2)}
.btn-green {background:var(--green);color:#fff}.btn-green:hover{background:#15803d}
.btn-amber {background:var(--amber);color:#fff}.btn-amber:hover{background:#b45309}
.btn-red   {background:var(--red);color:#fff}.btn-red:hover{background:#b91c1c}
.btn-ghost {background:transparent;border:1.5px solid var(--border);color:var(--slate)}.btn-ghost:hover{border-color:var(--ink);color:var(--ink)}
.btn-sm{padding:6px 14px;font-size:.76rem}
.btn-full{width:100%;justify-content:center}

.action-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:16px}
.action-card-hdr{padding:12px 16px;border-bottom:1px solid var(--border);font-family:'Syne',sans-serif;font-size:.82rem;font-weight:800;display:flex;align-items:center;gap:6px}
.action-card-hdr i{color:var(--teal)}
.action-card-body{padding:14px 16px;display:flex;flex-direction:column;gap:8px}

.cancel-box{display:none;padding:12px;background:#fef2f2;border:1px solid #fecaca;border-radius:9px;margin-top:4px}
.cancel-box.open{display:block}
.cancel-box p{font-size:.78rem;color:var(--red);font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:5px}
.cancel-box textarea{width:100%;padding:8px 10px;border:1.5px solid #fecaca;border-radius:8px;font-size:.8rem;font-family:'Lato',sans-serif;resize:vertical;min-height:58px;outline:none;background:#fff}
.cancel-box textarea:focus{border-color:var(--red)}

.note-textarea{width:100%;padding:9px 11px;border:1.5px solid var(--border);border-radius:9px;font-size:.8rem;font-family:'Lato',sans-serif;resize:vertical;min-height:72px;outline:none;margin-bottom:8px}
.note-textarea:focus{border-color:var(--teal)}

.reschedule-box{background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:12px 14px;font-size:.8rem;color:#78350f;margin-bottom:16px;display:flex;gap:9px}
.reschedule-box i{color:var(--amber);flex-shrink:0;margin-top:2px}

.hist-item{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid var(--border)}
.hist-item:last-child{border-bottom:none}
.hist-date{font-size:.74rem;font-weight:700;color:var(--slate);flex-shrink:0;min-width:56px}
.hist-service{font-size:.8rem;font-weight:600}
.hist-pill{display:inline-flex;padding:1px 7px;border-radius:20px;font-size:.62rem;font-weight:700;margin-top:3px}

@media(max-width:1100px){.content{grid-template-columns:1fr}}
@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:14px}}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
  <div class="nav-section">Main</div>
  <a href="dashboard.php"            class="nav-item"><i class="bi bi-speedometer2"></i> Dashboard</a>
  <a href="manage_appointments.php"  class="nav-item active"><i class="bi bi-calendar-check"></i> Appointments</a>
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
      <div><div class="a-name"><?= htmlspecialchars($admin_name) ?></div><div class="a-role"><?= $admin_role ?></div></div>
    </div>
  </div>
</aside>

<div class="main">

  <div class="topbar">
    <div class="topbar-left">
      <a href="manage_appointments.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back</a>
      <span class="topbar-title">Appointment #<?= $appt_id ?></span>
    </div>
    <div class="topbar-right">
      <?php if ($a['status'] === 'pending'): ?>
      <form method="POST" style="margin:0">
        <input type="hidden" name="action" value="approve">
        <button type="submit" class="btn btn-green btn-sm"><i class="bi bi-check-lg"></i> Confirm</button>
      </form>
      <?php endif; ?>
      <?php if ($is_actionable): ?>
      <form method="POST" style="margin:0">
        <input type="hidden" name="action" value="complete">
        <button type="submit" class="btn btn-teal btn-sm" onclick="return confirm('Mark this appointment as completed?')"><i class="bi bi-check2-all"></i> Complete</button>
      </form>
      <button class="btn btn-ghost btn-sm" style="color:var(--red)" onclick="toggleCancel()"><i class="bi bi-x-circle"></i> Cancel</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="content">

    <div>

      <?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="alert alert-error"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="card">
        <div class="status-banner">
          <div style="display:flex;align-items:center;gap:14px">
            <div class="sb-icon" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
              <i class="bi <?= $sc['icon'] ?>"></i>
            </div>
            <div>
              <div class="sb-appt-id">Appointment #<?= $appt_id ?></div>
              <div class="sb-service"><?= htmlspecialchars($a['service_name'] ?? 'Dental Visit') ?></div>
              <div class="sb-meta">
                <span><i class="bi bi-calendar3" style="margin-right:3px"></i><?= date('F j, Y', strtotime($a['date'])) ?></span>
                <span><i class="bi bi-clock" style="margin-right:3px"></i><?= date('g:i A', strtotime($a['time'])) ?></span>
                <?php if ($a['duration_minutes']): ?>
                <span><i class="bi bi-stopwatch" style="margin-right:3px"></i><?= $a['duration_minutes'] ?> min</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
            <i class="bi <?= $sc['icon'] ?>"></i> <?= $sc['label'] ?>
          </span>
        </div>

        <div class="cancel-box" id="cancelBox" style="margin:0 20px 16px">
          <form method="POST">
            <input type="hidden" name="action" value="cancel">
            <p><i class="bi bi-exclamation-triangle-fill"></i> This cannot be undone.</p>
            <textarea name="cancel_reason" placeholder="Reason for cancellation (optional)…"></textarea>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button type="submit" class="btn btn-red btn-sm"><i class="bi bi-x-circle"></i> Confirm Cancel</button>
              <button type="button" class="btn btn-ghost btn-sm" onclick="toggleCancel()">Never mind</button>
            </div>
          </form>
        </div>
      </div>

      <?php if ($a['reschedule_requested'] && ($a['new_date'] ?? null)): ?>
      <div class="reschedule-box">
        <i class="bi bi-arrow-repeat"></i>
        <div>
          <strong>Patient requested a reschedule</strong><br>
          Proposed: <?= date('F j, Y', strtotime($a['new_date'])) ?> at <?= date('g:i A', strtotime($a['new_time'])) ?>
          <?php if ($a['reschedule_reason'] ?? null): ?><br><em><?= htmlspecialchars($a['reschedule_reason']) ?></em><?php endif; ?>
          <div style="margin-top:8px;display:flex;gap:8px">
            <a href="reschedule_appointment.php?id=<?= $appt_id ?>" class="btn btn-amber btn-sm"><i class="bi bi-calendar-event"></i> Handle Reschedule</a>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h3><i class="bi bi-calendar-check"></i> Appointment Details</h3></div>
        <div class="card-body">
          <div class="info-grid">
            <div>
              <div class="info-label">Service</div>
              <div class="info-val"><?= htmlspecialchars($a['service_name'] ?? '—') ?></div>
              <?php if ($a['category']): ?><div class="info-sub"><?= htmlspecialchars($a['category']) ?></div><?php endif; ?>
            </div>
            <div>
              <div class="info-label">Fee</div>
              <div class="info-val" style="color:var(--teal);font-family:'Syne',sans-serif;font-size:1rem">
                <?= $a['price'] ? '₱' . number_format($a['price']) : '—' ?>
              </div>
            </div>
            <div>
              <div class="info-label">Date</div>
              <div class="info-val"><?= date('F j, Y', strtotime($a['date'])) ?></div>
              <div class="info-sub"><?= date('l', strtotime($a['date'])) ?></div>
            </div>
            <div>
              <div class="info-label">Time</div>
              <div class="info-val"><?= date('g:i A', strtotime($a['time'])) ?></div>
              <?php if ($a['duration_minutes']): ?><div class="info-sub"><?= $a['duration_minutes'] ?> minutes</div><?php endif; ?>
            </div>
            <div class="divider"></div>
            <div>
              <div class="info-label">Dentist</div>
              <div class="info-val"><?= htmlspecialchars($a['doctor_name']) ?></div>
              <div class="info-sub"><?= htmlspecialchars($a['specialty'] ?? '') ?></div>
            </div>
            <div>
              <div class="info-label">PRC License</div>
              <div class="info-val"><?= htmlspecialchars($a['license_number'] ?? '—') ?></div>
            </div>
            <?php if ($a['admin_notes'] ?? null): ?>
            <div style="grid-column:1/-1">
              <div class="info-label">Admin Notes</div>
              <div style="font-size:.84rem;color:var(--slate);margin-top:4px"><?= nl2br(htmlspecialchars($a['admin_notes'])) ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h3><i class="bi bi-person"></i> Patient Information</h3>
          <?php if ($a['is_guest'] ?? false): ?>
          <span style="background:#fdf4ff;color:#7e22ce;font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:auto">Guest</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($a['allergies'] ?? null): ?>
          <div class="allergy-alert"><i class="bi bi-exclamation-triangle-fill"></i><strong>Allergy on file:</strong>&nbsp;<?= htmlspecialchars($a['allergies']) ?></div>
          <?php endif; ?>
          <div class="info-grid">
            <div>
              <div class="info-label">Full Name</div>
              <div class="info-val"><?= htmlspecialchars($a['patient_name'] ?? '—') ?></div>
            </div>
            <div>
              <div class="info-label">Phone</div>
              <div class="info-val"><?= htmlspecialchars($a['patient_phone'] ?? '—') ?></div>
            </div>
            <div>
              <div class="info-label">Email</div>
              <div class="info-val" style="word-break:break-all;font-weight:400"><?= htmlspecialchars($a['patient_email'] ?? '—') ?></div>
            </div>
            <div>
              <div class="info-label">Gender / DOB</div>
              <div class="info-val"><?= htmlspecialchars($a['gender'] ?? '—') ?></div>
              <?php if ($a['birthdate'] ?? null): ?><div class="info-sub"><?= date('M j, Y', strtotime($a['birthdate'])) ?></div><?php endif; ?>
            </div>
            <?php if ($a['dental_notes'] ?? null): ?>
            <div style="grid-column:1/-1">
              <div class="info-label">Dental Notes</div>
              <div style="font-size:.83rem;color:var(--slate);margin-top:4px"><?= htmlspecialchars($a['dental_notes']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($a['emergency_contact_name'] ?? null): ?>
            <div class="divider"></div>
            <div>
              <div class="info-label">Emergency Contact</div>
              <div class="info-val"><?= htmlspecialchars($a['emergency_contact_name']) ?></div>
              <div class="info-sub"><?= htmlspecialchars($a['emergency_contact_phone'] ?? '') ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>

    <div>

      <?php if ($is_actionable): ?>
      <div class="action-card">
        <div class="action-card-hdr"><i class="bi bi-lightning-fill"></i> Actions</div>
        <div class="action-card-body">
          <?php if ($a['status'] === 'pending'): ?>
          <form method="POST">
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn btn-green btn-full"><i class="bi bi-check-lg"></i> Confirm Appointment</button>
          </form>
          <?php endif; ?>
          <form method="POST">
            <input type="hidden" name="action" value="complete">
            <button type="submit" class="btn btn-teal btn-full" onclick="return confirm('Mark this appointment as completed?')">
              <i class="bi bi-check2-all"></i> Mark as Completed
            </button>
          </form>
          <a href="reschedule_appointment.php?id=<?= $appt_id ?>" class="btn btn-ghost btn-full"><i class="bi bi-calendar-event"></i> Reschedule</a>
          <button class="btn btn-full" style="background:#fef2f2;color:var(--red);border:1.5px solid #fecaca" onclick="toggleCancel()">
            <i class="bi bi-x-circle"></i> Cancel Appointment
          </button>
        </div>
      </div>
      <?php endif; ?>

      <div class="action-card">
        <div class="action-card-hdr"><i class="bi bi-sticky"></i> Admin Note</div>
        <div class="action-card-body">
          <form method="POST">
            <input type="hidden" name="action" value="add_note">
            <textarea class="note-textarea" name="admin_note" placeholder="Notes visible to clinic staff only…"><?= htmlspecialchars($a['admin_notes'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-ghost btn-sm"><i class="bi bi-save"></i> Save Note</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3><i class="bi bi-info-circle"></i> Booking Info</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
          <div>
            <div class="info-label">Booked On</div>
            <div class="info-val"><?= date('M j, Y', strtotime($a['created_at'])) ?></div>
            <div class="info-sub"><?= date('g:i A', strtotime($a['created_at'])) ?></div>
          </div>
          <div>
            <div class="info-label">Booking Type</div>
            <div class="info-val"><?= ($a['is_guest'] ?? false) ? 'Guest / Walk-in' : 'Registered Patient' ?></div>
          </div>
          <?php if ($a['patient_id']): ?>
          <a href="manage_patients.php?search=<?= urlencode($a['patient_name']) ?>" class="btn btn-ghost btn-sm">
            <i class="bi bi-person-lines-fill"></i> View Patient Record
          </a>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($other)): ?>
      <div class="card">
        <div class="card-header"><h3><i class="bi bi-clock-history"></i> Patient History</h3></div>
        <div class="card-body" style="padding:8px 16px">
          <?php foreach ($other as $o):
            $osc = $status_cfg[$o['status']] ?? ['color'=>'#6b7280','bg'=>'#f9fafb','label'=>ucfirst($o['status'])];
          ?>
          <div class="hist-item">
            <div class="hist-date"><?= date('M j', strtotime($o['date'])) ?><br><span style="font-weight:400;font-size:.68rem;color:var(--muted)"><?= date('Y', strtotime($o['date'])) ?></span></div>
            <div style="flex:1;min-width:0">
              <div class="hist-service"><?= htmlspecialchars($o['service'] ?? 'Visit') ?></div>
              <span class="hist-pill" style="background:<?= $osc['bg'] ?>;color:<?= $osc['color'] ?>"><?= $osc['label'] ?></span>
            </div>
            <a href="appointment_summary.php?id=<?= $o['id'] ?>" style="color:var(--teal);font-size:.75rem;font-weight:600;text-decoration:none;align-self:center;flex-shrink:0">View</a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
function toggleCancel() {
    const box = document.getElementById('cancelBox');
    box.classList.toggle('open');
    if (box.classList.contains('open')) box.scrollIntoView({ behavior:'smooth', block:'nearest' });
}
</script>
</body>
</html>