<?php
if (php_sapi_name() !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'])) {
        http_response_code(403);
        die('Access denied.');
    }
}

define('CRON_RUN', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/EmailService.php';

$emailService = new EmailService();
$now          = date('Y-m-d H:i:s');
$base_url     = 'http://localhost/patient'; 

$sent = 0; $failed = 0;

log_msg("========================================");
log_msg("Reminder run started: $now");


$pending = $conn->query("
    SELECT ar.id AS reminder_id, ar.type, ar.appointment_id,
           a.date, a.time, a.patient_id,
           a.guest_name, a.guest_email, a.booking_token,
           p.name  AS patient_name,
           p.email AS patient_email,
           d.name  AS doctor_name,
           s.name  AS service_name,
           s.price
    FROM appointment_reminders ar
    JOIN appointments a ON ar.appointment_id = a.id
    JOIN doctors d      ON a.doctor_id  = d.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN patients p ON a.patient_id  = p.id
    WHERE ar.sent = 0
    AND ar.remind_at <= NOW()
    AND a.status NOT IN ('cancelled','completed')
    ORDER BY ar.remind_at ASC
");

while ($row = $pending->fetch_assoc()) {
    $to_email = $row['patient_email'] ?? $row['guest_email'] ?? null;
    $to_name  = $row['patient_name']  ?? $row['guest_name']  ?? 'Patient';

    if (!$to_email) {
        log_msg("  SKIP reminder #{$row['reminder_id']} — no email address");
        mark_sent($conn, $row['reminder_id']);
        continue;
    }

    $appt_id     = $row['appointment_id'];
    $token_param = $row['booking_token'] ? '&token=' . $row['booking_token'] : '';
    $summary_url = $base_url . "/appointment_summary.php?id={$appt_id}{$token_param}";

    $details = [
        'date'        => date('l, F j, Y', strtotime($row['date'])),
        'time'        => date('g:i A',     strtotime($row['time'])),
        'doctor'      => $row['doctor_name'],
        'service'     => $row['service_name'],
        'price'       => $row['price'] ? '₱' . number_format($row['price'], 2) : null,
        'summary_url' => $summary_url,
    ];

    $ok = false;
    if ($row['type'] === '24h') {
        log_msg("  Sending 24h reminder to {$to_email} for appointment #{$appt_id}");
        $ok = $emailService->sendAppointmentReminder24h($to_email, $to_name, $details);
    } elseif ($row['type'] === '1h') {
        log_msg("  Sending 1h reminder to {$to_email} for appointment #{$appt_id}");
        $ok = $emailService->sendAppointmentReminder1h($to_email, $to_name, $details);
    }

    if ($ok) {
        mark_sent($conn, $row['reminder_id']);
        $conn->query("UPDATE appointments SET reminder_sent=1 WHERE id={$appt_id}");
        $sent++;
        log_msg("    ✓ Sent");
    } else {
        $failed++;
        log_msg("    ✗ Failed");
    }
}

$upcoming_1h = $conn->query("
    SELECT a.id
    FROM appointments a
    WHERE a.status = 'confirmed'
    AND TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(a.date,' ',a.time)) BETWEEN 55 AND 65
    AND a.id NOT IN (
        SELECT appointment_id FROM appointment_reminders WHERE type='1h'
    )
");

while ($row = $upcoming_1h->fetch_assoc()) {
    $appt_id   = $row['id'];
    $remind_at = date('Y-m-d H:i:s', strtotime('-1 hour', strtotime('now + 5 minutes')));
    $conn->query("INSERT IGNORE INTO appointment_reminders (appointment_id, remind_at, type)
                  VALUES ($appt_id, '$remind_at', '1h')");
    log_msg("  Queued 1h reminder for appointment #$appt_id");
}

$followups = $conn->query("
    SELECT ar.id AS reminder_id, ar.appointment_id,
           a.patient_id,
           p.name  AS patient_name,
           p.email AS patient_email
    FROM appointment_reminders ar
    JOIN appointments a ON ar.appointment_id = a.id
    LEFT JOIN patients p ON a.patient_id = p.id
    WHERE ar.type    = 'followup'
    AND   ar.sent    = 0
    AND   ar.remind_at <= NOW()
    AND   p.email IS NOT NULL
");

while ($row = $followups->fetch_assoc()) {
    log_msg("  Sending follow-up reminder to {$row['patient_email']}");
    $ok = $emailService->sendFollowUpReminder($row['patient_email'], $row['patient_name'], [
        'book_url' => $base_url . '/book_appointment.php',
    ]);
    if ($ok) { mark_sent($conn, $row['reminder_id']); $sent++; log_msg("    ✓ Sent"); }
    else      { $failed++; log_msg("    ✗ Failed"); }
}

$completed = $conn->query("
    SELECT a.id
    FROM appointments a
    WHERE a.status = 'completed'
    AND a.patient_id IS NOT NULL
    AND a.id NOT IN (
        SELECT appointment_id FROM appointment_reminders WHERE type='followup'
    )
");

while ($row = $completed->fetch_assoc()) {
    $appt_id   = $row['id'];
    $remind_at = date('Y-m-d H:i:s', strtotime('+6 months'));
    $conn->query("INSERT IGNORE INTO appointment_reminders (appointment_id, remind_at, type)
                  VALUES ($appt_id, '$remind_at', 'followup')");
    log_msg("  Queued 6-month follow-up for appointment #$appt_id");
}

$conn->query("DELETE FROM appointment_reminders WHERE sent=1 AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

log_msg("----------------------------------------");
log_msg("Done. Sent: $sent  |  Failed: $failed");
log_msg("========================================\n");

$conn->close();

function mark_sent(mysqli $conn, int $id): void {
    $conn->query("UPDATE appointment_reminders SET sent=1, sent_at=NOW() WHERE id=$id");
}

function log_msg(string $msg): void {
    $ts = date('[Y-m-d H:i:s]');
    echo "$ts $msg\n";
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
    @file_put_contents($log_dir . '/reminders.log', "$ts $msg\n", FILE_APPEND);
}
?>