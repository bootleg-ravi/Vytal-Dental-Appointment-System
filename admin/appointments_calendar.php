<?php
session_start();
if (!isset($_SESSION['patient_id'])) {
    header("Location: login.php");
    exit;
}
include('../config/config.php');

$appointments = [];
$stmt = $conn->prepare("SELECT date, time, doctor_id, service_id, status
    FROM appointments
    WHERE patient_id=? AND status!='cancelled'");
$stmt->bind_param('i', $_SESSION['patient_id']);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $appointments[] = $row;
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <title>My Appointments Calendar | Hospital Appointment System</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css"/>
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
    <style>
body {
    background: linear-gradient(120deg, #ddefff 0%, #f8fdff 98%);
    min-height: 100vh;
}
.card-glass {
    background: rgba(255,255,255,0.39);
    box-shadow: 0 6px 32px 0 rgba(44,62,80,0.10), 0 1px 6px 1px #ccd7ec81;
    border-radius: 22px;
    padding: 2.7rem 2.9rem 2.1rem 2.9rem;
    max-width: 680px;
    width: 100%;
    margin: 55px auto;
    backdrop-filter: blur(7px);
    border: none;
    position: relative;
}
.card-title {
    font-size: 1.38rem;
    font-weight: 700;
    color: #0984e3;
}
.bg-logo {
    text-align:center;
    margin-bottom: 19px;
}
.bg-logo i {
    font-size: 2.7rem;
    color: #0983f0;
    background: #fff;
    border-radius: 50%;
    padding: 16px;
    box-shadow: 0 2px 13px #65b7fd22;
}
.fc {
    background: rgba(255,255,255,0.89);
    border-radius: 18px;
    margin-bottom: 1.4rem;
}
.btn-primary { border-radius: 15px }
</style>
</head>
<body>
<div class="card card-glass">
    <div class="bg-logo mb-2">
        <i class="bi bi-calendar3"></i>
    </div>
    <div class="card-title text-center mb-4"><i class="bi bi-calendar-event"></i> My Appointments Calendar</div>
    <div id="calendar"></div>
    <div class="text-center mt-1 mb-2">
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var events = [
    <?php foreach($appointments as $appt): ?>
        {
            title: "Booked",
            start: "<?= htmlspecialchars($appt['date']) ?>",
            allDay: true,
            color: "#2196F3"
        },
    <?php endforeach; ?>
    ];
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: ''
        },
        events: events,
        height: 480,
        eventClick: function(info) {
            alert("Booking on " + info.event.startStr);
        }
    });
    calendar.render();
});
</script>
</body>
</html>
