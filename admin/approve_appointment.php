<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';
require_once '../includes/EmailService.php';
require_once '../includes/ActivityLogger.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_appointments.php?error=' . urlencode('Invalid appointment ID'));
    exit;
}

$appt_id = (int)$_GET['id'];

$stmt = $conn->prepare("
    SELECT a.id, a.patient_id, a.status, a.date, a.time,
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

if ($appt['status'] !== 'pending') {
    header('Location: manage_appointments.php?error=' . urlencode('Only pending appointments can be confirmed'));
    exit;
}

$upd = $conn->prepare("UPDATE appointments SET status='confirmed' WHERE id=?");
$upd->bind_param('i', $appt_id);

if ($upd->execute()) {
    $logger = new ActivityLogger($conn);
    $logger->log(
        $_SESSION['admin_id'], 'admin', $_SESSION['admin_name'],
        'APPROVE_APPOINTMENT',
        'Confirmed appointment #' . $appt_id . ' for: ' . $appt['patient_name']
    );

    if ($appt['patient_email']) {
        try {
            $emailService = new EmailService();
            $emailService->sendAppointmentApproved(
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

    if (!empty($appt['patient_id'])) {
        $title = "Appointment Confirmed";
        $message = "Your appointment with " . $appt['doctor_name'] . " has been confirmed.";
        $type = "appointment"; 
        $created_at = date('Y-m-d H:i:s');

        $notif_stmt = $conn->prepare("INSERT INTO notifications (patient_id, title, message, type, is_read, is_starred, created_at) VALUES (?, ?, ?, ?, 0, 0, ?)");
        $notif_stmt->bind_param("issss", $appt['patient_id'], $title, $message, $type, $created_at);
        $notif_stmt->execute();
        $notif_stmt->close();
    }

    $msg = 'Appointment confirmed. Patient has been notified.';
} else {
    $msg = 'Failed to confirm appointment.';
}
$upd->close();
$conn->close();
header('Location: manage_appointments.php?success=' . urlencode($msg));
exit;