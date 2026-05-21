<?php
session_start();
if (!isset($_SESSION['patient_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$patient_id   = (int)$_SESSION['patient_id'];
$patient_name = $_SESSION['patient_name'] ?? 'Patient';
$error = $success = '';

$appointment_id = intval($_GET['id'] ?? 0);
if (!$appointment_id) {
    header('Location: appointments.php?error=' . urlencode('Invalid appointment ID'));
    exit;
}

$stmt = $conn->prepare("
    SELECT a.id, a.date, a.time, a.status, a.reschedule_requested,
           a.new_date, a.new_time, a.reschedule_reason,
           d.name AS doctor_name, d.specialty,
           s.name AS service_name, s.price
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN services s ON a.service_id = s.id
    WHERE a.id = ? AND a.patient_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $appointment_id, $patient_id);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appt) {
    header('Location: appointments.php?error=' . urlencode('Appointment not found'));
    exit;
}

if (in_array($appt['status'], ['cancelled', 'completed'])) {
    header('Location: appointments.php?error=' . urlencode('Cannot reschedule a ' . $appt['status'] . ' appointment'));
    exit;
}

$unread = (int)$conn->query("SELECT COUNT(*) AS c FROM notifications WHERE patient_id=$patient_id AND is_read=0")->fetch_assoc()['c'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$appt['reschedule_requested']) {
    $new_date = trim($_POST['new_date'] ?? '');
    $new_time = trim($_POST['new_time'] ?? '');
    $reason   = trim($_POST['reason']   ?? '');

    if (!$new_date || !$new_time) {
        $error = 'Please provide both a new date and time.';
    } elseif (strtotime("$new_date $new_time") <= time()) {
        $error = 'The requested date and time must be in the future.';
    } else {
        $stmt = $conn->prepare("UPDATE appointments SET reschedule_requested=1, new_date=?, new_time=?, reschedule_reason=? WHERE id=? AND patient_id=?");
        $stmt->bind_param('sssii', $new_date, $new_time, $reason, $appointment_id, $patient_id);
        if ($stmt->execute()) {
            try {
                require_once '../includes/EmailService.php';
                $admin_email = 'admin@vytalclinic.com';
                (new EmailService())->sendRescheduleRequestToAdmin($admin_email, $patient_name, [
                    'current_date' => date('F j, Y', strtotime($appt['date'])),
                    'current_time' => date('g:i A',  strtotime($appt['time'])),
                    'new_date'     => date('F j, Y', strtotime($new_date)),
                    'new_time'     => date('g:i A',  strtotime($new_time)),
                    'reason'       => $reason ?: 'No reason provided',
                ]);
            } catch (Exception $e) {}
            $success = 'Reschedule request submitted! Our clinic will review and confirm your new schedule.';
            $appt['reschedule_requested'] = 1;
            $appt['new_date'] = $new_date;
            $appt['new_time'] = $new_time;
        } else {
            $error = 'Failed to submit request. Please try again.';
        }
        $stmt->close();
    }
}

$conn->close();

$status_cfg = [
    'pending'   => ['color'=>'#fbbf24','bg'=>'rgba(251,191,36,.12)','icon'=>'bi-hourglass-split'],
    'confirmed' => ['color'=>'#34d399','bg'=>'rgba(52,211,153,.12)','icon'=>'bi-check-circle'],
    'completed' => ['color'=>'#60a5fa','bg'=>'rgba(96,165,250,.12)','icon'=>'bi-check2-all'],
    'cancelled' => ['color'=>'#94a3b8','bg'=>'rgba(148,163,184,.1)','icon'=>'bi-x-circle'],
];
$sc = $status_cfg[$appt['status']] ?? $status_cfg['pending'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Request Reschedule – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--bg:#0e1621;--bg2:#151f2e;--bg3:#1c2a3e;--surface:#1e2d42;--border:rgba(255,255,255,.08);--teal:#00c9a7;--teal2:#00a88d;--amber:#fbbf24;--red:#f87171;--slate:#94a3b8;--text:#e2e8f0;--green:#34d399;--muted:#64748b;--radius:12px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
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
.sidebar-footer{margin-top:auto;padding:12px;border-top:1px solid var(--border)}
.user-chip{display:flex;align-items:center;gap:9px;padding:8px 10px;background:rgba(255,255,255,.04);border-radius:8px;border:1px solid var(--border)}
.user-avatar{width:30px;height:30px;border-radius:50%;background:var(--teal);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;color:#0e1621;font-size:.8rem;flex-shrink:0}
.u-name{font-size:.78rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.u-role{font-size:.65rem;color:var(--muted)}

.main{margin-left:220px;flex:1;display:flex;flex-direction:column}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 28px;height:56px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:50}
.back-btn{display:inline-flex;align-items:center;gap:6px;color:var(--slate);text-decoration:none;font-size:.82rem;font-weight:600;padding:6px 12px;border-radius:20px;border:1px solid var(--border);transition:all .18s}
.back-btn:hover{color:var(--text);border-color:rgba(255,255,255,.2)}
.topbar-title{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;color:var(--text)}

.content{padding:24px 28px;display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;max-width:900px}
@media(max-width:900px){.content{grid-template-columns:1fr}}

.card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:16px}
.card:last-child{margin-bottom:0}
.card-header{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;font-family:'Syne',sans-serif;font-size:.85rem;font-weight:800;color:var(--text)}
.card-header i{color:var(--teal)}
.card-body{padding:18px}

.appt-strip{display:flex;align-items:center;gap:14px;padding:16px 18px;border-bottom:1px solid var(--border)}
.strip-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.strip-service{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;color:var(--text)}
.strip-meta{font-size:.77rem;color:var(--slate);margin-top:3px;display:flex;gap:12px;flex-wrap:wrap}
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-left:auto;flex-shrink:0}

.compare-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border);border-radius:9px;overflow:hidden}
.compare-cell{background:var(--bg2);padding:12px 14px}
.compare-label{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px}
.compare-val{font-size:.9rem;font-weight:700;color:var(--text)}
.compare-sub{font-size:.7rem;color:var(--slate);margin-top:1px}

.pending-notice{background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.2);border-radius:var(--radius);padding:14px 16px;display:flex;gap:10px;margin-bottom:16px}
.pending-notice i{color:var(--amber);flex-shrink:0;margin-top:2px}
.pending-notice strong{color:var(--amber);display:block;font-size:.84rem;margin-bottom:4px}
.pending-notice p{font-size:.8rem;color:var(--slate)}
.pending-dates{margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
.pd-cell{background:rgba(251,191,36,.06);border-radius:7px;padding:8px 10px}
.pd-label{font-size:.62rem;font-weight:700;text-transform:uppercase;color:rgba(251,191,36,.6);margin-bottom:2px}
.pd-val{font-size:.82rem;font-weight:700;color:var(--amber)}

.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.form-field label{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:6px}
.form-field input,.form-field textarea{width:100%;padding:10px 12px;background:var(--bg3);border:1.5px solid var(--border);border-radius:9px;font-size:.88rem;font-family:'Lato',sans-serif;color:var(--text);outline:none;transition:border-color .18s}
.form-field input:focus,.form-field textarea:focus{border-color:var(--teal)}
.form-field input::-webkit-calendar-picker-indicator{filter:invert(.6)}
.form-field textarea{resize:vertical;min-height:80px}

.alert{border-radius:var(--radius);padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:8px;font-size:.83rem}
.alert-error  {background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);color:var(--red)}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;white-space:nowrap}
.btn-teal {background:var(--teal);color:#0e1621}.btn-teal:hover{background:var(--teal2);transform:translateY(-1px)}
.btn-ghost{background:transparent;border:1.5px solid var(--border);color:var(--slate)}.btn-ghost:hover{color:var(--text);border-color:rgba(255,255,255,.2)}

.info-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:3px}
.info-val{font-size:.86rem;font-weight:600;color:var(--text)}
.info-sub{font-size:.73rem;color:var(--slate);margin-top:1px}

.success-state{text-align:center;padding:32px 20px}
.success-state .check{width:56px;height:56px;border-radius:50%;background:rgba(52,211,153,.12);border:2px solid rgba(52,211,153,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.5rem;color:var(--green)}
.success-state h3{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:var(--text);margin-bottom:6px}
.success-state p{font-size:.82rem;color:var(--slate);margin-bottom:18px;line-height:1.6}

@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:14px}.form-row{grid-template-columns:1fr}.compare-grid{grid-template-columns:1fr}.pending-dates{grid-template-columns:1fr}}
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
  <hr style="margin:6px 14px;border:none;border-top:1px solid var(--border)">
  <a href="logout.php" class="nav-item" style="color:rgba(248,113,113,.5)" onmouseover="this.style.color='#f87171';this.style.background='rgba(248,113,113,.07)'" onmouseout="this.style.color='rgba(248,113,113,.5)';this.style.background=''"><i class="bi bi-box-arrow-right"></i> Logout</a>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar"><?= strtoupper(substr($patient_name,0,1)) ?></div>
      <div><div class="u-name"><?= htmlspecialchars($patient_name) ?></div><div class="u-role">Patient</div></div>
    </div>
  </div>
</aside>

<div class="main">
  <div class="topbar">
    <a href="appointments.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back</a>
    <span class="topbar-title">Request Reschedule</span>
  </div>

  <div class="content">

    <div>
      <?php if ($error): ?>
      <div class="alert alert-error"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="card">
        <div class="appt-strip">
          <div class="strip-icon" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
            <i class="bi <?= $sc['icon'] ?>"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div class="strip-service"><?= htmlspecialchars($appt['service_name'] ?? 'Dental Visit') ?></div>
            <div class="strip-meta">
              <span><i class="bi bi-person-badge" style="margin-right:3px"></i><?= htmlspecialchars($appt['doctor_name']) ?></span>
              <?php if ($appt['specialty']): ?>
              <span style="color:var(--muted)"><?= htmlspecialchars($appt['specialty']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
            <i class="bi <?= $sc['icon'] ?>"></i> <?= ucfirst($appt['status']) ?>
          </span>
        </div>
        <div class="card-body">
          <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:8px">Current Schedule</div>
          <div class="compare-grid">
            <div class="compare-cell">
              <div class="compare-label">Date</div>
              <div class="compare-val"><?= date('M j, Y', strtotime($appt['date'])) ?></div>
              <div class="compare-sub"><?= date('l', strtotime($appt['date'])) ?></div>
            </div>
            <div class="compare-cell">
              <div class="compare-label">Time</div>
              <div class="compare-val"><?= date('g:i A', strtotime($appt['time'])) ?></div>
            </div>
          </div>
        </div>
      </div>

      <?php if ($appt['reschedule_requested'] && !$success): ?>
      <div class="pending-notice">
        <i class="bi bi-hourglass-split"></i>
        <div style="flex:1">
          <strong>Reschedule Request Pending</strong>
          <p>Your request is waiting for admin approval. You'll be notified once it's reviewed.</p>
          <?php if ($appt['new_date']): ?>
          <div class="pending-dates">
            <div class="pd-cell">
              <div class="pd-label">Requested Date</div>
              <div class="pd-val"><?= date('M j, Y', strtotime($appt['new_date'])) ?></div>
            </div>
            <div class="pd-cell">
              <div class="pd-label">Requested Time</div>
              <div class="pd-val"><?= date('g:i A', strtotime($appt['new_time'])) ?></div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <?php if ($success): ?>
        <div class="success-state">
          <div class="check"><i class="bi bi-send-check"></i></div>
          <h3>Request Submitted!</h3>
          <p><?= htmlspecialchars($success) ?></p>
          <a href="appointments.php" class="btn btn-teal"><i class="bi bi-list-check"></i> Back to Appointments</a>
        </div>
        <?php elseif (!$appt['reschedule_requested']): ?>
        <div class="card-header"><i class="bi bi-calendar-event"></i> Preferred New Schedule</div>
        <div class="card-body">
          <form method="POST">
            <div class="form-row">
              <div class="form-field">
                <label>New Date</label>
                <input type="date" name="new_date" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
              </div>
              <div class="form-field">
                <label>New Time</label>
                <input type="time" name="new_time" required>
              </div>
            </div>
            <div class="form-field" style="margin-bottom:16px">
              <label>Reason <span style="color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
              <textarea name="reason" placeholder="Let us know why you'd like to reschedule…"></textarea>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button type="submit" class="btn btn-teal"><i class="bi bi-send"></i> Submit Request</button>
              <a href="appointments.php" class="btn btn-ghost"><i class="bi bi-x"></i> Cancel</a>
            </div>
          </form>
        </div>
        <?php else: ?>
        <div class="card-body" style="text-align:center;padding:28px 20px">
          <p style="font-size:.84rem;color:var(--slate)">You already have a pending reschedule request for this appointment.</p>
          <a href="appointments.php" class="btn btn-ghost" style="margin-top:14px"><i class="bi bi-arrow-left"></i> Back to Appointments</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-header"><i class="bi bi-info-circle"></i> Appointment Info</div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
          <div>
            <div class="info-label">Service</div>
            <div class="info-val"><?= htmlspecialchars($appt['service_name'] ?? '—') ?></div>
          </div>
          <div>
            <div class="info-label">Dentist</div>
            <div class="info-val"><?= htmlspecialchars($appt['doctor_name']) ?></div>
            <div class="info-sub"><?= htmlspecialchars($appt['specialty'] ?? '') ?></div>
          </div>
          <?php if ($appt['price']): ?>
          <div>
            <div class="info-label">Fee</div>
            <div class="info-val" style="color:var(--teal);font-family:'Syne',sans-serif">₱<?= number_format($appt['price']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><i class="bi bi-lightbulb"></i> How it works</div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
          <?php foreach ([
            ['bi-send',        'Submit your preferred new date and time'],
            ['bi-hourglass',   'Our clinic reviews and approves the request'],
            ['bi-bell',        "You'll be notified once it's confirmed"],
          ] as [$icon, $text]): ?>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <div style="width:28px;height:28px;border-radius:8px;background:rgba(0,201,167,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi <?= $icon ?>" style="color:var(--teal);font-size:.8rem"></i>
            </div>
            <span style="font-size:.8rem;color:var(--slate);padding-top:4px"><?= $text ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>
</div>
</body>
</html>