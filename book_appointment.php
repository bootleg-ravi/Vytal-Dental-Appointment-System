<?php
include('config/config.php');
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = $_SESSION['patient_id'];
    $doctor_id  = $_POST['doctor_id'];
    $service_id = $_POST['service_id'];
    $date       = $_POST['date'];
    $time       = $_POST['time'];

    $stmt = $conn->prepare('SELECT id FROM appointments WHERE doctor_id = ? AND date = ? AND time = ? AND status IN ("pending","confirmed")');
    $stmt->bind_param('iss', $doctor_id, $date, $time);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        echo "Time slot already booked.";
} else {
       $stmt = $conn->prepare('INSERT INTO appointments (patient_id, doctor_id, service_id, date, time) VALUES (?, ?, ?, ?, ?)');
        
        $stmt->bind_param('iiiss', $patient_id, $doctor_id, $service_id, $date, $time);
        
        if ($stmt->execute()) {
            echo "Appointment booked successfully!";
        } else {
            echo "Booking failed: " . $stmt->error;
        }
    }
    $stmt->close();
    $conn->close();
}
?>
<?php
include('../includes/footer.php');
?>