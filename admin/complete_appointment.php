<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';
require_once '../includes/ActivityLogger.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_appointments.php?error=' . urlencode('Invalid appointment ID'));
    exit;
}

$appt_id = (int)$_GET['id'];

$stmt = $conn->prepare("
    SELECT a.id, a.status,
           COALESCE(p.name, a.guest_name) AS patient_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $appt_id);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appt) {
    header('Location: manage_appointments.php?error=' . urlencode('Appointment not found'));
    exit;
}

if (!in_array($appt['status'], ['pending', 'confirmed'])) {
    header('Location: manage_appointments.php?error=' . urlencode('Only pending or confirmed appointments can be completed'));
    exit;
}

$upd = $conn->prepare("UPDATE appointments SET status='completed' WHERE id=?");
$upd->bind_param('i', $appt_id);

if ($upd->execute()) {
    $logger = new ActivityLogger($conn);
    $logger->log(
        $_SESSION['admin_id'], 'admin', $_SESSION['admin_name'],
        'COMPLETE_APPOINTMENT',
        'Marked appointment #' . $appt_id . ' as completed for: ' . $appt['patient_name']
    );
    $msg = 'Appointment marked as completed.';
} else {
    $msg = 'Failed to update appointment.';
}

$upd->close();
$conn->close();
header('Location: manage_appointments.php?success=' . urlencode($msg));
exit;