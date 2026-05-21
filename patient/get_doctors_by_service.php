<?php
session_start();
require_once '../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['patient_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$service_id = intval($_GET['service_id'] ?? 0);

if (!$service_id) {
    echo json_encode(['error' => 'Invalid service']);
    exit;
}

$stmt = $conn->prepare("SELECT name FROM services WHERE id = ?");
$stmt->bind_param('i', $service_id);
$stmt->execute();
$result = $stmt->get_result();
$service = $result->fetch_assoc();
$stmt->close();

if (!$service) {
    echo json_encode(['error' => 'Service not found']);
    exit;
}

$service_specialty_map = [
    'dental care' => ['dentistry', 'dental', 'dentist'],
    'cardiology' => ['cardiology', 'cardiologist', 'heart specialist'],
    'pediatric care' => ['pediatrics', 'pediatrician', 'child care'],
    'dermatology' => ['dermatology', 'dermatologist', 'skin specialist'],
    'orthopedics' => ['orthopedics', 'orthopedist', 'orthopedic surgeon'],
    'psychiatry' => ['psychiatry', 'psychiatrist', 'mental health'],
    'general consultation' => ['general practitioner', 'general medicine', 'family medicine'],
    'gynecology' => ['gynecology', 'gynecologist', 'obgyn'],
    'neurology' => ['neurology', 'neurologist', 'brain specialist'],
    'ophthalmology' => ['ophthalmology', 'ophthalmologist', 'eye specialist'],
    'ent' => ['ent', 'ear nose throat', 'otolaryngology'],
];

$service_lower = strtolower($service['name']);
$matching_specialties = [];

foreach ($service_specialty_map as $service_key => $specialties) {
    if (stripos($service_lower, $service_key) !== false || stripos($service_key, $service_lower) !== false) {
        $matching_specialties = array_merge($matching_specialties, $specialties);
        break;
    }
}

if (empty($matching_specialties)) {
    $matching_specialties = [strtolower($service['name'])];
}

$specialty_conditions = [];
foreach ($matching_specialties as $specialty) {
    $specialty_conditions[] = "LOWER(specialty) LIKE '%" . $conn->real_escape_string($specialty) . "%'";
}

$where_clause = implode(' OR ', $specialty_conditions);

$query = "SELECT id, name, specialty, contact FROM doctors WHERE " . $where_clause . " ORDER BY name ASC";
$result = $conn->query($query);

$doctors = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $doctors[] = $row;
    }
}

$conn->close();

if (empty($doctors)) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $result = $conn->query("SELECT id, name, specialty, contact FROM doctors ORDER BY name ASC");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $doctors[] = $row;
        }
    }
    $conn->close();
}

echo json_encode(['doctors' => $doctors]);
?>
