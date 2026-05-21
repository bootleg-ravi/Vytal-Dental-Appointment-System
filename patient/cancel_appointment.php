<?php
session_start();
if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../config/config.php';
require_once '../includes/ActivityLogger.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: appointments.php?error=' . urlencode('Invalid appointment ID'));
    exit;
}

$appointment_id = intval($_GET['id']);
$patient_id = $_SESSION['patient_id'];
$patient_name = $_SESSION['patient_name'];

$stmt = $conn->prepare("SELECT a.*, d.name as doctor_name, s.name as service_name 
                        FROM appointments a 
                        JOIN doctors d ON a.doctor_id = d.id 
                        LEFT JOIN services s ON a.service_id = s.id 
                        WHERE a.id = ? AND a.patient_id = ? AND a.status = 'pending'");
$stmt->bind_param('ii', $appointment_id, $patient_id);
$stmt->execute();
$result = $stmt->get_result();
$appointment = $result->fetch_assoc();
$stmt->close();

if (!$appointment) {
    header('Location: appointments.php?error=' . urlencode('Cannot cancel this appointment'));
    exit;
}

$stmt = $conn->prepare("UPDATE appointments SET status='cancelled' WHERE id = ? AND patient_id = ?");
$stmt->bind_param('ii', $appointment_id, $patient_id);

if ($stmt->execute()) {
    $logger = new ActivityLogger($conn);
    $logger->log(
        $patient_id,
        'patient',
        $patient_name,
        'CANCEL_APPOINTMENT',
        'Cancelled appointment ID: ' . $appointment_id . ' with ' . $appointment['doctor_name']
    );
    
    $success = 'Appointment cancelled successfully.';
} else {
    $error = 'Failed to cancel appointment.';
}

$stmt->close();
$conn->close();

header('Location: appointments.php?success=' . urlencode($success ?? $error));
exit;
?>
