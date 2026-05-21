<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';
require_once '../includes/ActivityLogger.php';
require_once '../includes/EmailService.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_appointments.php?error=' . urlencode('Invalid appointment ID'));
    exit;
}

$appt_id = (int)$_GET['id'];

$stmt = $conn->prepare("
    SELECT a.id, a.status, a.date, a.time,
           COALESCE(p.name,  a.guest_name)  AS patient_name,
           COALESCE(p.email, a.guest_email) AS patient_email,
           d.name AS doctor_name,
           s.name AS service_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    JOIN doctors  d ON a.doctor_id  = d.id
    LEFT JOIN services s ON a.service_id = s.id
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

if (in_array($appt['status'], ['cancelled', 'completed'])) {
    header('Location: manage_appointments.php?error=' . urlencode('This appointment cannot be cancelled'));
    exit;
}

$upd = $conn->prepare("UPDATE appointments SET status='cancelled' WHERE id=?");
$upd->bind_param('i', $appt_id);

if ($upd->execute()) {
    $logger = new ActivityLogger($conn);
    $logger->log(
        $_SESSION['admin_id'], 'admin', $_SESSION['admin_name'],
        'CANCEL_APPOINTMENT',
        'Cancelled appointment #' . $appt_id . ' for: ' . $appt['patient_name']
    );

    if ($appt['patient_email']) {
        try {
            $emailService = new EmailService();
            $emailService->sendAppointmentCancelled(
                $appt['patient_email'],
                $appt['patient_name'],
                [
                    'date'    => date('F j, Y', strtotime($appt['date'])),
                    'time'    => date('g:i A',  strtotime($appt['time'])),
                    'doctor'  => $appt['doctor_name'],
                    'service' => $appt['service_name'],
                ]
            );
        } catch (Exception $e) {
            
        }
    }

    $msg = 'Appointment cancelled successfully.';
} else {
    $msg = 'Failed to cancel appointment.';
}

$upd->close();
$conn->close();
header('Location: manage_appointments.php?success=' . urlencode($msg));
exit;