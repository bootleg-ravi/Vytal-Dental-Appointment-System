<?php
echo '<pre>';
print_r($_POST);
echo '</pre>';
session_start();
if (!isset($_SESSION['patient_id'])) {
    header("Location: login.php");
    exit;
}

include('../config/config.php');
$patient_id = $_SESSION['patient_id'];
$service_id = intval($_POST['service_id'] ?? 0);
$doctor_id = intval($_POST['doctor_id'] ?? 0);
$date = $_POST['date'] ?? '';
$time = $_POST['time'] ?? '';
$normalized_time = date("H:i:s", strtotime($time));
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$service_id || !$doctor_id || !$date || !$time) {
        $error = "Please complete all fields.";
    }
    elseif (strtotime("$date $time") <= strtotime('now')) {
        $error = "Cannot book an appointment in the past.";
    }
    else {
        $query = "SELECT id FROM doctors WHERE id = ? AND service_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('ii', $doctor_id, $service_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $error = "Selected doctor does not provide the selected service.";
        }
        $stmt->close();
    }
    if (!$error) {
        $checkQuery = "SELECT id FROM appointments WHERE patient_id = ? AND date = ? AND time = ? AND status != 'cancelled'";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param('iss', $patient_id, $date, $normalized_time);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $error = "You already have an appointment at this time.";
        }
        $stmt->close();
    }
    if (!$error) {
        $insert = "INSERT INTO appointments (patient_id, doctor_id, service_id, date, time, status) VALUES (?, ?, ?, ?, ?, 'pending')";
        $stmt = $conn->prepare($insert);
        $stmt->bind_param('iiiss', $patient_id, $doctor_id, $service_id, $date, $normalized_time);
        if ($stmt->execute()) {
            $success = "Appointment requested! Wait for admin confirmation.";
        } else {
            $error = "Failed to book appointment. Try again.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Confirm Appointment - Hospital Booking System</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
</head>
<body style="background:#eef3fa;">
    <div class="container">
        <div class="row justify-content-center" style="min-height:90vh;align-items:center;">
            <div class="col-md-6">
                <div class="card shadow p-4 mt-5">
                    <h3 class="mb-3 text-center">Appointment Booking Confirmation</h3>
                    <?php if ($error): ?>
                        <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
                        <div class="text-center mt-3">
                            <a href="book_appointment.php" class="btn btn-primary">Back to Booking</a>
                        </div>
                    <?php elseif ($success): ?>
                        <div class="alert alert-success text-center"><?= htmlspecialchars($success) ?></div>
                        <div class="text-center mt-3">
                            <a href="dashboard.php" class="btn btn-success">Go to Dashboard</a>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning text-center">Invalid access.</div>
                        <div class="text-center mt-3">
                            <a href="book_appointment.php" class="btn btn-primary">Back to Booking</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
