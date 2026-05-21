<?php
require_once 'includes/EmailService.php';

$emailService = new EmailService();

$test_details = [
    'date' => 'January 10, 2026',
    'time' => '10:00 AM',
    'doctor' => 'Dr. Juan Dela Cruz',
    'service' => 'General Checkup'
];

$result = $emailService->sendAppointmentConfirmation(
    'rayvin.ds@gmail.com', 
    'Test Patient',
    $test_details
);

if ($result) {
    echo "✅ Email sent successfully! Check your inbox.";
} else {
    echo "❌ Failed to send email. Check your config/email_config.php settings.";
}
?>
