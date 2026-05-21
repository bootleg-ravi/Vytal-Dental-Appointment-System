<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';
$error = '';
$success = '';

$appointment_id = intval($_GET['id'] ?? 0);
if (!$appointment_id) {
    header('Location: manage_appointments.php?error=' . urlencode('Invalid appointment ID'));
    exit;
}

$stmt = $conn->prepare("
    SELECT a.*,
           COALESCE(p.name,  a.guest_name)  AS patient_name,
           COALESCE(p.email, a.guest_email) AS patient_email,
           d.name AS doctor_name, d.specialty,
           s.name AS service_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    JOIN  doctors  d ON a.doctor_id  = d.id
    LEFT JOIN services s ON a.service_id = s.id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $appointment_id);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    header('Location: manage_appointments.php?error=' . urlencode('Appointment not found'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_reschedule' && $appointment['reschedule_requested']) {
        $stmt = $conn->prepare("UPDATE appointments SET date=new_date, time=new_time, reschedule_requested=0, new_date=NULL, new_time=NULL, reschedule_reason=NULL WHERE id=?");
        $stmt->bind_param('i', $appointment_id);
        if ($stmt->execute()) {
            if ($appointment['patient_email']) {
                try {
                    require_once '../includes/EmailService.php';
                    (new EmailService())->sendRescheduleApproved(
                        $appointment['patient_email'], $appointment['patient_name'],
                        ['date'=>date('F j, Y',strtotime($appointment['new_date'])),'time'=>date('g:i A',strtotime($appointment['new_time'])),'doctor'=>$appointment['doctor_name'],'service'=>$appointment['service_name']]
                    );
                } catch (Exception $e) {}
            }
            header('Location: manage_appointments.php?success=' . urlencode('Reschedule approved. Patient has been notified.'));
            exit;
        }
        $error = 'Failed to approve reschedule.';
        $stmt->close();

    } elseif ($action === 'deny_reschedule' && $appointment['reschedule_requested']) {
        $stmt = $conn->prepare("UPDATE appointments SET reschedule_requested=0, new_date=NULL, new_time=NULL, reschedule_reason=NULL WHERE id=?");
        $stmt->bind_param('i', $appointment_id);
        if ($stmt->execute()) {
            if ($appointment['patient_email']) {
                try {
                    require_once '../includes/EmailService.php';
                    (new EmailService())->sendRescheduleDenied(
                        $appointment['patient_email'], $appointment['patient_name'],
                        ['date'=>date('F j, Y',strtotime($appointment['date'])),'time'=>date('g:i A',strtotime($appointment['time'])),'doctor'=>$appointment['doctor_name'],'service'=>$appointment['service_name']]
                    );
                } catch (Exception $e) {}
            }
            header('Location: manage_appointments.php?success=' . urlencode('Reschedule request denied. Patient has been notified.'));
            exit;
        }
        $error = 'Failed to deny reschedule.';
        $stmt->close();

    } elseif ($action === 'admin_reschedule') {
        $new_date = $_POST['new_date'] ?? '';
        $new_time = $_POST['new_time'] ?? '';
        if (!$new_date || !$new_time) {
            $error = 'Please provide both a new date and time.';
        } else {
            $stmt = $conn->prepare("UPDATE appointments SET date=?, time=?, reschedule_requested=0, new_date=NULL, new_time=NULL WHERE id=?");
            $stmt->bind_param('ssi', $new_date, $new_time, $appointment_id);
            if ($stmt->execute()) {
                header('Location: manage_appointments.php?success=' . urlencode('Appointment rescheduled successfully.'));
                exit;
            }
            $error = 'Failed to reschedule appointment.';
            $stmt->close();
        }
    }
}

$conn->close();

$status_cfg = [
    'pending'   => ['color'=>'#d97706','bg'=>'#fffbeb','icon'=>'bi-hourglass-split'],
    'confirmed' => ['color'=>'#16a34a','bg'=>'#f0fdf4','icon'=>'bi-check-circle'],
    'completed' => ['color'=>'#2563eb','bg'=>'#eff6ff','icon'=>'bi-check2-all'],
    'cancelled' => ['color'=>'#6b7280','bg'=>'#f9fafb','icon'=>'bi-x-circle'],
];
$sc = $status_cfg[$appointment['status']] ?? $status_cfg['pending'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Reschedule Appointment – Vytal Dental Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--bg:#f7f5f0;--bg2:#fff;--sidebar:#111827;--border:#e8e3da;--teal:#0d9488;--teal2:#0f766e;--amber:#d97706;--red:#dc2626;--green:#16a34a;--ink:#111827;--slate:#6b7280;--muted:#9ca3af;--radius:14px;--shadow:0 1px 10px rgba(0,0,0,.05)}
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
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:14px 28px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:50}
.back-btn{display:inline-flex;align-items:center;gap:6px;color:var(--slate);text-decoration:none;font-size:.82rem;font-weight:600;padding:6px 12px;border-radius:20px;border:1px solid var(--border);transition:all .18s;white-space:nowrap}
.back-btn:hover{color:var(--ink);border-color:var(--ink)}
.topbar-title{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800}

.content{padding:24px 28px;display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;max-width:1100px}
@media(max-width:960px){.content{grid-template-columns:1fr}}

.card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:16px}
.card:last-child{margin-bottom:0}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.card-header h3{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:800;display:flex;align-items:center;gap:7px}
.card-header h3 i{color:var(--teal)}
.card-body{padding:18px 20px}

.appt-strip{display:flex;align-items:center;gap:14px;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg)}
.strip-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.strip-service{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800}
.strip-meta{font-size:.78rem;color:var(--slate);margin-top:3px;display:flex;gap:12px;flex-wrap:wrap}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:25px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-left:auto;flex-shrink:0}

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.info-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px}
.info-val{font-size:.87rem;font-weight:600}
.info-sub{font-size:.74rem;color:var(--slate);margin-top:1px}

.request-box{background:#fffbeb;border:1px solid #fde68a;border-radius:var(--radius);overflow:hidden;margin-bottom:16px}
.request-box-hdr{padding:12px 18px;background:#fef3c7;border-bottom:1px solid #fde68a;display:flex;align-items:center;gap:8px;font-family:'Syne',sans-serif;font-size:.84rem;font-weight:800;color:#92400e}
.request-box-hdr i{color:var(--amber)}
.request-box-body{padding:16px 18px}
.request-dates{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.request-reason{background:#fff;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;font-size:.82rem;color:#78350f;margin-bottom:14px}
.request-reason .label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#b45309;margin-bottom:3px}
.request-actions{display:flex;gap:8px}

.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px}
.form-field label{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:6px}
.form-field input[type="date"],
.form-field input[type="time"]{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:.88rem;font-family:'Lato',sans-serif;color:var(--ink);background:var(--bg);outline:none;transition:border-color .18s}
.form-field input:focus{border-color:var(--teal);background:#fff}
.form-actions{display:flex;gap:8px;flex-wrap:wrap}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;white-space:nowrap}
.btn-teal  {background:var(--teal);color:#fff}.btn-teal:hover{background:var(--teal2);transform:translateY(-1px)}
.btn-green {background:var(--green);color:#fff}.btn-green:hover{background:#15803d}
.btn-red   {background:var(--red);color:#fff}.btn-red:hover{background:#b91c1c}
.btn-ghost {background:transparent;border:1.5px solid var(--border);color:var(--slate)}.btn-ghost:hover{border-color:var(--ink);color:var(--ink)}
.btn-sm{padding:7px 14px;font-size:.76rem}

.alert{border-radius:var(--radius);padding:10px 15px;margin-bottom:16px;display:flex;align-items:center;gap:8px;font-size:.84rem}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:var(--red)}

.compare-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border);border-radius:10px;overflow:hidden;margin-bottom:16px}
.compare-cell{background:var(--bg2);padding:12px 16px}
.compare-cell.highlight{background:#f0fdf4}
.compare-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px}
.compare-val{font-size:.9rem;font-weight:700}

@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:14px}.form-row{grid-template-columns:1fr}.info-grid{grid-template-columns:1fr}.request-dates{grid-template-columns:1fr}.compare-grid{grid-template-columns:1fr}}
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
    <a href="manage_appointments.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back</a>
    <span class="topbar-title">Reschedule Appointment #<?= $appointment_id ?></span>
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
            <div class="strip-service"><?= htmlspecialchars($appointment['service_name'] ?? 'Dental Visit') ?></div>
            <div class="strip-meta">
              <span><i class="bi bi-person" style="margin-right:3px"></i><?= htmlspecialchars($appointment['patient_name']) ?></span>
              <span><i class="bi bi-person-badge" style="margin-right:3px"></i><?= htmlspecialchars($appointment['doctor_name']) ?></span>
            </div>
          </div>
          <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
            <i class="bi <?= $sc['icon'] ?>"></i> <?= ucfirst($appointment['status']) ?>
          </span>
        </div>

        <div class="card-body">
          <div class="compare-grid">
            <div class="compare-cell">
              <div class="compare-label">Current Date</div>
              <div class="compare-val"><?= date('M j, Y', strtotime($appointment['date'])) ?></div>
              <div style="font-size:.72rem;color:var(--slate)"><?= date('l', strtotime($appointment['date'])) ?></div>
            </div>
            <div class="compare-cell">
              <div class="compare-label">Current Time</div>
              <div class="compare-val"><?= date('g:i A', strtotime($appointment['time'])) ?></div>
            </div>
          </div>
        </div>
      </div>

      <?php if ($appointment['reschedule_requested'] && ($appointment['new_date'] ?? null)): ?>
      <div class="request-box">
        <div class="request-box-hdr">
          <i class="bi bi-arrow-repeat"></i> Patient Reschedule Request
        </div>
        <div class="request-box-body">
          <div class="request-dates">
            <div>
              <div class="info-label">Requested Date</div>
              <div class="info-val" style="color:var(--amber)"><?= date('F j, Y', strtotime($appointment['new_date'])) ?></div>
              <div class="info-sub"><?= date('l', strtotime($appointment['new_date'])) ?></div>
            </div>
            <div>
              <div class="info-label">Requested Time</div>
              <div class="info-val" style="color:var(--amber)"><?= date('g:i A', strtotime($appointment['new_time'])) ?></div>
            </div>
          </div>
          <?php if ($appointment['reschedule_reason'] ?? null): ?>
          <div class="request-reason">
            <div class="label">Patient's Reason</div>
            <?= htmlspecialchars($appointment['reschedule_reason']) ?>
          </div>
          <?php endif; ?>
          <div class="request-actions">
            <form method="POST">
              <input type="hidden" name="action" value="approve_reschedule">
              <button type="submit" class="btn btn-green btn-sm"><i class="bi bi-check-lg"></i> Approve Request</button>
            </form>
            <form method="POST">
              <input type="hidden" name="action" value="deny_reschedule">
              <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);border-color:#fecaca" onclick="return confirm('Deny this reschedule request?')"><i class="bi bi-x-lg"></i> Deny Request</button>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <h3><i class="bi bi-calendar-event"></i> Set New Date & Time</h3>
        </div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="admin_reschedule">
            <div class="form-row">
              <div class="form-field">
                <label>New Date</label>
                <input type="date" name="new_date" required
                       min="<?= date('Y-m-d') ?>"
                       value="<?= htmlspecialchars($appointment['new_date'] ?? $appointment['date']) ?>">
              </div>
              <div class="form-field">
                <label>New Time</label>
                <input type="time" name="new_time" required
                       value="<?= htmlspecialchars($appointment['new_time'] ?? $appointment['time']) ?>">
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-teal"><i class="bi bi-calendar-check"></i> Save Reschedule</button>
              <a href="manage_appointments.php" class="btn btn-ghost"><i class="bi bi-x"></i> Cancel</a>
            </div>
          </form>
        </div>
      </div>

    </div>

    <div>

      <div class="card">
        <div class="card-header"><h3><i class="bi bi-person"></i> Patient</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
          <div>
            <div class="info-label">Name</div>
            <div class="info-val"><?= htmlspecialchars($appointment['patient_name']) ?></div>
          </div>
          <?php if ($appointment['patient_email']): ?>
          <div>
            <div class="info-label">Email</div>
            <div style="font-size:.82rem;color:var(--slate);word-break:break-all"><?= htmlspecialchars($appointment['patient_email']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3><i class="bi bi-person-badge"></i> Dentist</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
          <div>
            <div class="info-label">Name</div>
            <div class="info-val"><?= htmlspecialchars($appointment['doctor_name']) ?></div>
            <div class="info-sub"><?= htmlspecialchars($appointment['specialty'] ?? '') ?></div>
          </div>
          <div>
            <div class="info-label">Service</div>
            <div class="info-val"><?= htmlspecialchars($appointment['service_name'] ?? '—') ?></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3><i class="bi bi-link-45deg"></i> Quick Links</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
          <a href="appointment_summary.php?id=<?= $appointment_id ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i> View Full Details</a>
          <a href="manage_appointments.php" class="btn btn-ghost btn-sm"><i class="bi bi-calendar-check"></i> All Appointments</a>
        </div>
      </div>

          </div>
  </div>
</div>

</body>
</html>