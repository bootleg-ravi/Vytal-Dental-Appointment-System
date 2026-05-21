<?php
session_start();

require_once '../config/config.php';

$is_logged_in = isset($_SESSION['patient_id']);
$patient_id   = $is_logged_in ? (int)$_SESSION['patient_id'] : null;

$appointment_id = intval($_GET['id'] ?? 0);
$token          = trim($_GET['token'] ?? '');

if (!$appointment_id) {
    header('Location: ' . ($is_logged_in ? 'appointments.php' : 'login.php'));
    exit;
}

$stmt = $conn->prepare("SELECT a.*,
    d.name AS doctor_name, d.specialty, d.contact AS doctor_contact, d.license_number,
    s.name AS service_name, s.price, s.duration_minutes, s.category, s.description AS service_desc,
    p.name AS patient_name, p.email AS patient_email, p.phone AS patient_phone
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN patients p ON a.patient_id = p.id
    WHERE a.id = ?");
$stmt->bind_param('i', $appointment_id);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appt) {
    header('Location: ' . ($is_logged_in ? 'appointments.php' : 'login.php'));
    exit;
}

$authorized = false;
if ($is_logged_in && $appt['patient_id'] == $patient_id) $authorized = true;
if (!empty($token) && $appt['booking_token'] === $token)   $authorized = true;

if (!$authorized) {
    header('Location: ' . ($is_logged_in ? 'appointments.php' : 'login.php'));
    exit;
}

$display_name = $appt['patient_name'] ?? $appt['guest_name'] ?? 'Guest';
$display_email = $appt['patient_email'] ?? $appt['guest_email'] ?? '';
$display_phone = $appt['patient_phone'] ?? $appt['guest_phone'] ?? '';

$conn->close();

$status_config = [
    'pending'   => ['color' => '#fbbf24', 'bg' => 'rgba(251,191,36,.12)',  'icon' => 'bi-hourglass-split',   'label' => 'Pending Confirmation'],
    'confirmed' => ['color' => '#34d399', 'bg' => 'rgba(52,211,153,.12)',   'icon' => 'bi-calendar-check',    'label' => 'Confirmed'],
    'completed' => ['color' => '#60a5fa', 'bg' => 'rgba(96,165,250,.12)',   'icon' => 'bi-check-circle',      'label' => 'Completed'],
    'cancelled' => ['color' => '#f87171', 'bg' => 'rgba(248,113,113,.12)',  'icon' => 'bi-x-circle',          'label' => 'Cancelled'],
    'no_show'   => ['color' => '#94a3b8', 'bg' => 'rgba(148,163,184,.12)', 'icon' => 'bi-person-x',          'label' => 'No Show'],
];
$sc = $status_config[$appt['status']] ?? $status_config['pending'];

$cat_icons = [
    'preventive'   => 'bi-shield-check',
    'restorative'  => 'bi-wrench',
    'cosmetic'     => 'bi-stars',
    'orthodontic'  => 'bi-align-center',
    'surgical'     => 'bi-scissors',
    'emergency'    => 'bi-lightning',
];
$cat_icon = $cat_icons[$appt['category'] ?? 'preventive'] ?? 'bi-heart-pulse';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Appointment Summary – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,600;0,9..144,700;1,9..144,300&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root {
    --bg:      #f4f1eb;
    --bg2:     #ede9e0;
    --white:   #ffffff;
    --ink:     #1a1a2e;
    --muted:   #6b7280;
    --border:  #e5e0d5;
    --teal:    #0d9488;
    --teal2:   #0f766e;
    --teal-lt: #ccfbf1;
    --amber:   #d97706;
    --radius:  16px;
    --shadow:  0 2px 20px rgba(0,0,0,.07);
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body {
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: var(--bg);
    color: var(--ink);
    min-height: 100vh;
}


.site-header {
    background: var(--white);
    border-bottom: 1px solid var(--border);
    padding: 0 32px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky; top:0; z-index:50;
}
.logo {
    font-family: 'Fraunces', serif;
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--teal);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
}
.back-link {
    color: var(--muted);
    text-decoration: none;
    font-size: .85rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: color .2s;
}
.back-link:hover { color: var(--ink); }

.page {
    max-width: 720px;
    margin: 0 auto;
    padding: 40px 20px 80px;
}

.hero-card {
    background: var(--white);
    border-radius: 24px;
    box-shadow: var(--shadow);
    overflow: hidden;
    margin-bottom: 20px;
    border: 1px solid var(--border);
}
.hero-top {
    padding: 32px 32px 28px;
    position: relative;
    overflow: hidden;
}
.hero-top::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 180px; height: 180px;
    border-radius: 50%;
    background: var(--teal-lt);
    opacity: .5;
}
.hero-ref {
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--muted);
    margin-bottom: 10px;
}
.hero-service {
    font-family: 'Fraunces', serif;
    font-size: 1.9rem;
    font-weight: 700;
    color: var(--ink);
    line-height: 1.15;
    margin-bottom: 12px;
}
.hero-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}
.meta-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: .78rem;
    font-weight: 600;
    background: var(--bg);
    color: var(--muted);
}
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 18px;
    border-radius: 30px;
    font-size: .82rem;
    font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

.hero-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    border-top: 1px solid var(--border);
}
@media (max-width: 540px) { .hero-body { grid-template-columns: 1fr; } }

.detail-cell {
    padding: 20px 28px;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}
.detail-cell:nth-child(2n) { border-right: none; }
.detail-label {
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .09em;
    color: var(--muted);
    margin-bottom: 5px;
}
.detail-value {
    font-size: .95rem;
    font-weight: 600;
    color: var(--ink);
}
.detail-value.accent { color: var(--teal); }

.info-card {
    background: var(--white);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    overflow: hidden;
    margin-bottom: 16px;
}
.info-card-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    font-family: 'Fraunces', serif;
    font-size: .95rem;
    font-weight: 600;
    color: var(--ink);
    display: flex;
    align-items: center;
    gap: 8px;
}
.info-card-header i { color: var(--teal); }
.info-card-body { padding: 18px 20px; }

.row-detail {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
    gap: 12px;
}
.row-detail:last-child { border-bottom: none; padding-bottom: 0; }
.rd-label { font-size: .8rem; color: var(--muted); font-weight: 500; }
.rd-value { font-size: .85rem; font-weight: 600; color: var(--ink); text-align: right; }

.complaint-box {
    background: var(--bg);
    border-radius: 12px;
    padding: 14px 16px;
    font-size: .88rem;
    color: var(--ink);
    font-style: italic;
    border-left: 3px solid var(--teal);
    line-height: 1.6;
}

.action-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 24px;
}
.btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 11px 22px;
    border-radius: 30px;
    border: none;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: .85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
    text-decoration: none;
}
.btn-primary { background: var(--teal);  color: #fff; }
.btn-primary:hover { background: var(--teal2); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(13,148,136,.3); }
.btn-outline { background: transparent; color: var(--ink); border: 2px solid var(--border); }
.btn-outline:hover { border-color: var(--ink); }
.btn-danger  { background: transparent; color: #ef4444; border: 2px solid #fecaca; }
.btn-danger:hover  { background: #fef2f2; }


@media print {
    .site-header, .action-row, .back-link { display: none !important; }
    body { background: white; }
    .hero-card, .info-card { box-shadow: none; border: 1px solid #ddd; }
    .page { padding: 0; max-width: 100%; }
}
</style>
</head>
<body>

<header class="site-header">
    <a href="../index.php" class="logo"><i class="bi bi-tooth"></i> Vytal Dental</a>
    <?php if ($is_logged_in): ?>
    <a href="appointments.php" class="back-link"><i class="bi bi-arrow-left"></i> My Appointments</a>
    <?php else: ?>
    <a href="login.php" class="back-link"><i class="bi bi-house"></i> Home</a>
    <?php endif; ?>
</header>

<div class="page">

    <div class="hero-card">
        <div class="hero-top">
            <div class="hero-ref">Appointment #<?= str_pad($appt['id'], 5, '0', STR_PAD_LEFT) ?></div>
            <div class="hero-service"><?= htmlspecialchars($appt['service_name'] ?? 'Dental Appointment') ?></div>
            <div class="hero-meta">
                <span class="status-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                    <i class="bi <?= $sc['icon'] ?>"></i> <?= $sc['label'] ?>
                </span>
                <span class="meta-chip">
                    <i class="bi <?= $cat_icon ?>"></i>
                    <?= htmlspecialchars(ucfirst($appt['category'] ?? 'Dental')) ?>
                </span>
                <?php if ($appt['duration_minutes']): ?>
                <span class="meta-chip"><i class="bi bi-clock"></i> <?= $appt['duration_minutes'] ?> min</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="hero-body">
            <div class="detail-cell">
                <div class="detail-label">Date</div>
                <div class="detail-value accent">
                    <?= date('l, F j, Y', strtotime($appt['date'])) ?>
                </div>
            </div>
            <div class="detail-cell">
                <div class="detail-label">Time</div>
                <div class="detail-value accent">
                    <?= date('g:i A', strtotime($appt['time'])) ?>
                </div>
            </div>
            <div class="detail-cell">
                <div class="detail-label">Dentist</div>
                <div class="detail-value"><?= htmlspecialchars($appt['doctor_name']) ?></div>
            </div>
            <div class="detail-cell">
                <div class="detail-label">Specialty</div>
                <div class="detail-value"><?= htmlspecialchars($appt['specialty']) ?></div>
            </div>
            <div class="detail-cell">
                <div class="detail-label">Patient</div>
                <div class="detail-value"><?= htmlspecialchars($display_name) ?></div>
            </div>
            <div class="detail-cell">
                <div class="detail-label">Fee</div>
                <div class="detail-value">₱<?= number_format($appt['price'] ?? 0, 2) ?></div>
            </div>
        </div>
    </div>

    <?php if ($appt['service_desc']): ?>
    <div class="info-card">
        <div class="info-card-header"><i class="bi bi-info-circle"></i> About This Service</div>
        <div class="info-card-body">
            <p style="font-size:.88rem;color:var(--muted);line-height:1.7"><?= htmlspecialchars($appt['service_desc']) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($appt['chief_complaint']): ?>
    <div class="info-card">
        <div class="info-card-header"><i class="bi bi-chat-text"></i> Chief Complaint / Reason</div>
        <div class="info-card-body">
            <div class="complaint-box">"<?= htmlspecialchars($appt['chief_complaint']) ?>"</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="info-card">
        <div class="info-card-header"><i class="bi bi-person"></i> Patient Information</div>
        <div class="info-card-body">
            <div class="row-detail">
                <span class="rd-label">Full Name</span>
                <span class="rd-value"><?= htmlspecialchars($display_name) ?></span>
            </div>
            <?php if ($display_email): ?>
            <div class="row-detail">
                <span class="rd-label">Email</span>
                <span class="rd-value"><?= htmlspecialchars($display_email) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($display_phone): ?>
            <div class="row-detail">
                <span class="rd-label">Phone</span>
                <span class="rd-value"><?= htmlspecialchars($display_phone) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($appt['booking_token']): ?>
            <div class="row-detail">
                <span class="rd-label">Booking Reference</span>
                <span class="rd-value" style="font-family:monospace;font-size:.78rem"><?= htmlspecialchars($appt['booking_token']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="info-card">
        <div class="info-card-header"><i class="bi bi-person-badge"></i> Dentist Information</div>
        <div class="info-card-body">
            <div class="row-detail">
                <span class="rd-label">Name</span>
                <span class="rd-value"><?= htmlspecialchars($appt['doctor_name']) ?></span>
            </div>
            <div class="row-detail">
                <span class="rd-label">Specialty</span>
                <span class="rd-value"><?= htmlspecialchars($appt['specialty']) ?></span>
            </div>
            <?php if ($appt['license_number']): ?>
            <div class="row-detail">
                <span class="rd-label">PRC License</span>
                <span class="rd-value" style="font-family:monospace"><?= htmlspecialchars($appt['license_number']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>


    <?php if ($appt['reschedule_requested']): ?>
    <div class="info-card" style="border-color:#fcd34d">
        <div class="info-card-header" style="background:#fff8e1">
            <i class="bi bi-arrow-repeat" style="color:var(--amber)"></i>
            <span style="color:var(--amber)">Reschedule Request Pending</span>
        </div>
        <div class="info-card-body">
            <div class="row-detail">
                <span class="rd-label">Requested New Date</span>
                <span class="rd-value"><?= date('F j, Y', strtotime($appt['new_date'])) ?></span>
            </div>
            <div class="row-detail">
                <span class="rd-label">Requested New Time</span>
                <span class="rd-value"><?= date('g:i A', strtotime($appt['new_time'])) ?></span>
            </div>
            <?php if ($appt['reschedule_reason']): ?>
            <div class="row-detail">
                <span class="rd-label">Reason</span>
                <span class="rd-value"><?= htmlspecialchars($appt['reschedule_reason']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>


    <div class="info-card">
        <div class="info-card-header"><i class="bi bi-receipt"></i> Booking Details</div>
        <div class="info-card-body">
            <div class="row-detail">
                <span class="rd-label">Booked On</span>
                <span class="rd-value"><?= date('F j, Y \a\t g:i A', strtotime($appt['created_at'])) ?></span>
            </div>
            <div class="row-detail">
                <span class="rd-label">Appointment ID</span>
                <span class="rd-value" style="font-family:monospace">#<?= str_pad($appt['id'],5,'0',STR_PAD_LEFT) ?></span>
            </div>
            <div class="row-detail">
                <span class="rd-label">Status</span>
                <span class="rd-value">
                    <span class="status-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;padding:4px 12px;font-size:.75rem">
                        <i class="bi <?= $sc['icon'] ?>"></i> <?= $sc['label'] ?>
                    </span>
                </span>
            </div>
        </div>
    </div>

 
    <div class="action-row">
        <?php if ($is_logged_in): ?>
            <a href="appointments.php" class="btn btn-outline"><i class="bi bi-arrow-left"></i> Back to Appointments</a>
            <?php if (in_array($appt['status'], ['pending','confirmed'])): ?>
                <a href="request_reschedule.php?id=<?= $appt['id'] ?>" class="btn btn-outline">
                    <i class="bi bi-arrow-repeat"></i> Request Reschedule
                </a>
                <?php if ($appt['status'] === 'pending'): ?>
                <a href="cancel_appointment.php?id=<?= $appt['id'] ?>" class="btn btn-danger"
                   onclick="return confirm('Are you sure you want to cancel this appointment?')">
                    <i class="bi bi-x-circle"></i> Cancel Appointment
                </a>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <a href="login.php" class="btn btn-primary"><i class="bi bi-box-arrow-in-right"></i> Login to Manage</a>
            <a href="book_appointment.php" class="btn btn-outline"><i class="bi bi-plus-circle"></i> Book Another</a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-outline"><i class="bi bi-printer"></i> Print</button>
    </div>

</div>
</body>
</html>