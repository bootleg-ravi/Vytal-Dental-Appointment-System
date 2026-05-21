<?php
$db_host = 'localhost';
$db_user = 'root';       
$db_pass = '';           
$db_name = 'dental_appointment_system'; 
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    die('Database connection problem, please try again later.');
}

$conn->set_charset('utf8mb4');
?>
