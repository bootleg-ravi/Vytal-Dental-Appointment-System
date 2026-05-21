<?php
session_start();

$is_logged_in = isset($_SESSION['patient_id']);
$patient_id   = $is_logged_in ? (int)$_SESSION['patient_id'] : null;
$patient_name = $is_logged_in ? ($_SESSION['patient_name'] ?? '') : '';

require_once '../config/config.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'save_draft') {
        $key  = $is_logged_in ? 'patient_' . $patient_id : (session_id());
        $data = $conn->real_escape_string($_POST['draft_data'] ?? '{}');
        $step = intval($_POST['step'] ?? 1);
        $conn->query("INSERT INTO booking_drafts (session_key, draft_data, step)
                      VALUES ('$key','$data',$step)
                      ON DUPLICATE KEY UPDATE draft_data='$data', step=$step, updated_at=NOW()");
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_POST['action'] === 'load_draft') {
        $key  = $is_logged_in ? 'patient_' . $patient_id : (session_id());
        $key  = $conn->real_escape_string($key);
        $res  = $conn->query("SELECT draft_data, step FROM booking_drafts WHERE session_key='$key' LIMIT 1");
        $row  = $res ? $res->fetch_assoc() : null;
        echo json_encode($row ?: ['draft_data' => null, 'step' => 1]);
        exit;
    }

    if ($_POST['action'] === 'get_slots') {
        $doctor_id = intval($_POST['doctor_id']);
        $date      = $conn->real_escape_string($_POST['date'] ?? '');
        $dow       = date('l', strtotime($date)); 

        $sched = $conn->query("SELECT start_time, end_time, slot_duration_minutes, max_patients
                               FROM dentist_schedule
                               WHERE doctor_id=$doctor_id AND day_of_week='$dow' AND is_active=1
                               LIMIT 1");
        if (!$sched || $sched->num_rows === 0) {
            echo json_encode(['slots' => [], 'message' => 'No schedule on this day.']);
            exit;
        }
        $s   = $sched->fetch_assoc();
        $dur = (int)$s['slot_duration_minutes'];
        $max = (int)$s['max_patients'];

        $start = strtotime($date . ' ' . $s['start_time']);
        $end   = strtotime($date . ' ' . $s['end_time']);
        $slots = [];
        for ($t = $start; $t < $end; $t += $dur * 60) {
            $time_str = date('H:i:s', $t);
            $booked_res = $conn->query("SELECT COUNT(*) as cnt FROM appointments
                                        WHERE doctor_id=$doctor_id AND date='$date'
                                        AND time='$time_str'
                                        AND status NOT IN ('cancelled')");
            $booked = $booked_res ? (int)$booked_res->fetch_assoc()['cnt'] : 0;
            $slots[] = [
                'time'      => $time_str,
                'label'     => date('g:i A', $t),
                'available' => $booked < $max,
                'booked'    => $booked,
                'max'       => $max,
            ];
        }
        $suggestions = [];
        if (!empty(array_filter($slots, fn($s) => !$s['available']))) {
            for ($d = 1; $d <= 14 && count($suggestions) < 3; $d++) {
                $next_date = date('Y-m-d', strtotime($date . " +$d days"));
                $next_dow  = date('l', strtotime($next_date));
                $ns = $conn->query("SELECT start_time, end_time, slot_duration_minutes, max_patients
                                    FROM dentist_schedule
                                    WHERE doctor_id=$doctor_id AND day_of_week='$next_dow' AND is_active=1
                                    LIMIT 1");
                if (!$ns || $ns->num_rows === 0) continue;
                $ns_row = $ns->fetch_assoc();
                $ns_dur = (int)$ns_row['slot_duration_minutes'];
                $ns_start = strtotime($next_date . ' ' . $ns_row['start_time']);
                $ns_end   = strtotime($next_date . ' ' . $ns_row['end_time']);
                for ($nt = $ns_start; $nt < $ns_end && count($suggestions) < 3; $nt += $ns_dur * 60) {
                    $nt_str = date('H:i:s', $nt);
                    $nb = $conn->query("SELECT COUNT(*) as cnt FROM appointments
                                        WHERE doctor_id=$doctor_id AND date='$next_date'
                                        AND time='$nt_str' AND status NOT IN ('cancelled')");
                    $nb_cnt = $nb ? (int)$nb->fetch_assoc()['cnt'] : 0;
                    if ($nb_cnt < $ns_row['max_patients']) {
                        $suggestions[] = [
                            'date'  => $next_date,
                            'label' => date('D, M j', strtotime($next_date)) . ' at ' . date('g:i A', $nt),
                            'time'  => $nt_str,
                        ];
                    }
                }
            }
        }
        echo json_encode(['slots' => $slots, 'suggestions' => $suggestions]);
        exit;
    }

    if ($_POST['action'] === 'get_doctors') {
        $service_id = intval($_POST['service_id']);
        $res = $conn->query("SELECT d.id, d.name, d.specialty, d.bio, d.availability
                             FROM doctors d
                             WHERE d.service_id=$service_id AND d.is_active=1
                             ORDER BY d.name ASC");
        $docs = [];
        while ($row = $res->fetch_assoc()) $docs[] = $row;
        if (empty($docs)) {
            $res2 = $conn->query("SELECT id, name, specialty, bio, availability FROM doctors WHERE is_active=1 ORDER BY name ASC");
            while ($row = $res2->fetch_assoc()) $docs[] = $row;
        }
        echo json_encode(['doctors' => $docs]);
        exit;
    }

    echo json_encode(['ok' => false]);
    exit;
}


$booking_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_booking'])) {
    $service_id = intval($_POST['service_id']);
    $doctor_id  = intval($_POST['doctor_id']);
    $date       = $conn->real_escape_string($_POST['date'] ?? '');
    $time       = date('H:i:s', strtotime($_POST['time'] ?? ''));
    $complaint  = $conn->real_escape_string(trim($_POST['chief_complaint'] ?? ''));

    $errors = [];
    if (!$service_id) $errors[] = 'Please select a service.';
    if (!$doctor_id)  $errors[] = 'Please select a dentist.';
    if (!$date)       $errors[] = 'Please select a date.';
    if (!$time)       $errors[] = 'Please select a time slot.';

    if ($is_logged_in) {
        if (!$errors) {
            $chk = $conn->prepare("SELECT id FROM appointments WHERE patient_id=? AND date=? AND time=? AND status!='cancelled'");
            $chk->bind_param('iss', $patient_id, $date, $time);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) $errors[] = 'You already have an appointment at this time.';
            $chk->close();
        }
        if (!$errors) {
            $stmt = $conn->prepare("INSERT INTO appointments (patient_id,doctor_id,service_id,date,time,chief_complaint,status) VALUES (?,?,?,?,?,'pending',?)");
            $stmt->bind_param('iiisss', $patient_id, $doctor_id, $service_id, $date, $time, $complaint);
            if ($stmt->execute()) {
                $appt_id = $stmt->insert_id;
                $remind_at = date('Y-m-d H:i:s', strtotime("$date $time") - 86400);
                $conn->query("INSERT INTO appointment_reminders (appointment_id,remind_at,type) VALUES ($appt_id,'$remind_at','24h')");
                $key = 'patient_' . $patient_id;
                $conn->query("DELETE FROM booking_drafts WHERE session_key='$key'");
                $booking_result = ['success' => true, 'appointment_id' => $appt_id];
            } else {
                $errors[] = 'Booking failed. Please try again.';
            }
            $stmt->close();
        }
    } else {
        $g_name  = trim($_POST['guest_name'] ?? '');
        $g_email = trim($_POST['guest_email'] ?? '');
        $g_phone = trim($_POST['guest_phone'] ?? '');
        if (!$g_name)  $errors[] = 'Please enter your name.';
        if (!$g_email || !filter_var($g_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email.';
        if (!$errors) {
            $token = bin2hex(random_bytes(16));
            $g_name_esc  = $conn->real_escape_string($g_name);
            $g_email_esc = $conn->real_escape_string($g_email);
            $g_phone_esc = $conn->real_escape_string($g_phone);
            $stmt = $conn->prepare("INSERT INTO appointments (guest_name,guest_email,guest_phone,doctor_id,service_id,date,time,chief_complaint,status,booking_token) VALUES (?,?,?,?,?,?,?,?,'pending',?)");
            $stmt->bind_param('sssiiisss', $g_name, $g_email, $g_phone, $doctor_id, $service_id, $date, $time, $complaint, $token);
            if ($stmt->execute()) {
                $appt_id = $stmt->insert_id;
                $remind_at = date('Y-m-d H:i:s', strtotime("$date $time") - 86400);
                $conn->query("INSERT INTO appointment_reminders (appointment_id,remind_at,type) VALUES ($appt_id,'$remind_at','24h')");
                $booking_result = ['success' => true, 'appointment_id' => $appt_id, 'token' => $token, 'guest' => true];
            } else {
                $errors[] = 'Booking failed. Please try again.';
            }
            $stmt->close();
        }
    }
    if (!empty($errors)) {
        $booking_result = ['success' => false, 'errors' => $errors];
    }
}

$services_res = $conn->query("SELECT id, name, description, price, duration_minutes, category FROM services WHERE is_active=1 ORDER BY category, name");
$services = [];
while ($row = $services_res->fetch_assoc()) $services[] = $row;

$conn->close();

$summary = [];
if ($booking_result && $booking_result['success'] ?? false) {
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Book Appointment – Vytal Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>

:root {
    --teal:      #00c9a7;
    --teal-dark: #00a88d;
    --teal-light:rgba(0,201,167,.12);
    --cream:     #0e1621;
    --sand:      #151f2e;
    --charcoal:  #e2e8f0;
    --slate:     #94a3b8;
    --muted:     #64748b;
    --white:     #ffffff;
    --red:       #f87171;
    --amber:     #fbbf24;
    --green:     #34d399;
    --border:    rgba(255,255,255,.08);
    --radius:    14px;
    --shadow:    0 4px 24px rgba(0,0,0,.3);
    --bg2:       #151f2e;
    --bg3:       #1c2a3e;
    --font-scale: 1;
}
.hc {
    --teal:      #00e8c0;
    --cream:     #000;
    --sand:      #111;
    --charcoal:  #fff;
    --slate:     #ccc;
    --muted:     #aaa;
    --border:    rgba(255,255,255,.3);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: calc(16px * var(--font-scale)); }
body {
    font-family: 'Lato', sans-serif;
    background: var(--cream);
    color: var(--charcoal);
    min-height: 100vh;
}
    
.a11y-bar {
    background: #0a1120;
    color: #fff;
    padding: 6px 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    font-size: .78rem;
    flex-wrap: wrap;
}
.a11y-bar span { color: var(--muted); }
.a11y-btn {
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.2);
    color: #fff;
    padding: 3px 12px;
    border-radius: 20px;
    cursor: pointer;
    font-size: .75rem;
    font-family: inherit;
    transition: background .2s;
}
.a11y-btn:hover { background: rgba(255,255,255,.22); }
.a11y-btn.active { background: var(--teal); border-color: var(--teal); }

.site-header {
    background: var(--sand);
    border-bottom: 1px solid var(--border);
    padding: 0 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 64px;
    position: sticky; top: 0; z-index: 100;
}
.logo {
    font-family: 'Syne', sans-serif;
    font-size: 1.4rem;
    color: var(--teal);
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}
.logo i { font-size: 1.6rem; }
.header-links { display: flex; align-items: center; gap: 16px; font-size: .9rem; }
.header-links a { color: var(--slate); text-decoration: none; font-weight: 500; }
.header-links a:hover { color: var(--teal); }
.btn-login {
    background: var(--teal);
    color: #fff !important;
    padding: 7px 18px;
    border-radius: 20px;
    font-size: .85rem;
}

.page-wrap {
    max-width: 860px;
    margin: 0 auto;
    padding: 40px 20px 80px;
}
.page-title {
    font-family: 'Syne', sans-serif;
    font-size: 2.1rem;
    color: var(--charcoal);
    margin-bottom: 4px;
}
.page-sub { color: var(--muted); font-size: .95rem; margin-bottom: 32px; }

.progress-wrap {
    display: flex;
    align-items: center;
    margin-bottom: 40px;
    position: relative;
}
.progress-wrap::before {
    content: '';
    position: absolute;
    top: 20px;
    left: 20px;
    right: 20px;
    height: 2px;
    background: var(--border);
    z-index: 0;
}
.progress-line {
    position: absolute;
    top: 20px;
    left: 20px;
    height: 2px;
    background: var(--teal);
    z-index: 1;
    transition: width .5s cubic-bezier(.4,0,.2,1);
}
.step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
    z-index: 2;
    cursor: default;
}
.step-circle {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: var(--sand);
    border: 2px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: .85rem;
    color: var(--muted);
    transition: all .3s;
    margin-bottom: 6px;
}
.step-item.done .step-circle  { background: var(--teal); border-color: var(--teal); color: #fff; }
.step-item.active .step-circle{ background: var(--teal); border-color: var(--teal); color: #fff; box-shadow: 0 0 0 4px rgba(0,201,167,.2); }
.step-label { font-size: .72rem; color: var(--muted); font-weight: 500; text-align: center; white-space: nowrap; }
.step-item.active .step-label { color: var(--teal); font-weight: 600; }
.step-item.done  .step-label  { color: var(--charcoal); }

.step-panel { display: none; animation: fadeIn .3s ease; }
.step-panel.active { display: block; }
@keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }

.panel-card {
    background: var(--sand);
    border-radius: var(--radius);
    border: 1px solid rgba(255,255,255,.08);
    padding: 28px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}
.panel-card h3 {
    font-family: 'Syne', sans-serif;
    font-size: 1.25rem;
    color: var(--charcoal);
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.panel-card h3 i { color: var(--teal); font-size: 1.1rem; }

.auth-toggle {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 24px;
}
.auth-opt {
    border: 2px solid var(--border);
    border-radius: var(--radius);
    padding: 18px;
    cursor: pointer;
    text-align: center;
    transition: all .2s;
}
.auth-opt:hover { border-color: var(--teal); }
.auth-opt.selected { border-color: var(--teal); background: var(--teal-light); }
.auth-opt i { font-size: 1.8rem; color: var(--teal); display: block; margin-bottom: 6px; }
.auth-opt strong { display: block; font-size: .95rem; color: var(--charcoal); }
.auth-opt small { color: var(--muted); font-size: .78rem; }

.service-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
}
.service-card {
    border: 2px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    cursor: pointer;
    transition: all .2s;
    position: relative;
}
.service-card:hover { border-color: var(--teal); transform: translateY(-2px); }
.service-card.selected { border-color: var(--teal); background: var(--teal-light); }
.service-card .check {
    position: absolute; top: 10px; right: 10px;
    width: 22px; height: 22px;
    background: var(--teal); border-radius: 50%;
    display: none; align-items: center; justify-content: center;
    color: #fff; font-size: .7rem;
}
.service-card.selected .check { display: flex; }
.svc-cat {
    display: inline-block;
    font-size: .65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 2px 8px;
    border-radius: 20px;
    margin-bottom: 8px;
    background: var(--sand);
    color: var(--slate);
}
.svc-name { font-weight: 600; font-size: .95rem; color: var(--charcoal); margin-bottom: 4px; }
.svc-price { color: var(--teal); font-weight: 700; font-size: .9rem; }
.svc-dur { color: var(--muted); font-size: .75rem; margin-top: 2px; }

.doctor-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.doctor-card {
    border: 2px solid var(--border);
    border-radius: var(--radius);
    padding: 18px;
    cursor: pointer;
    transition: all .2s;
    position: relative;
}
.doctor-card:hover { border-color: var(--teal); }
.doctor-card.selected { border-color: var(--teal); background: var(--teal-light); }
.doctor-card .check { position: absolute; top: 10px; right: 10px; width: 22px; height: 22px; background: var(--teal); border-radius: 50%; display: none; align-items: center; justify-content: center; color: #fff; font-size: .7rem; }
.doctor-card.selected .check { display: flex; }
.doc-avatar {
    width: 48px; height: 48px;
    background: var(--teal-light);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif;
    font-size: 1.3rem;
    color: var(--teal);
    margin-bottom: 10px;
}
.doc-name { font-weight: 600; color: var(--charcoal); font-size: .95rem; }
.doc-spec { color: var(--teal); font-size: .8rem; font-weight: 500; margin-bottom: 6px; }
.doc-avail { color: var(--muted); font-size: .75rem; line-height: 1.4; }
.loading-spinner {
    text-align: center; padding: 32px;
    color: var(--muted); font-size: .9rem;
}
.loading-spinner i { display: block; font-size: 1.8rem; margin-bottom: 8px; animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.date-picker-wrap {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 24px;
    align-items: start;
}
@media (max-width: 600px) { .date-picker-wrap { grid-template-columns: 1fr; } }

input[type="date"] {
    font-family: inherit;
    font-size: .95rem;
    padding: 10px 14px;
    border: 2px solid var(--border);
    border-radius: var(--radius);
    color: var(--charcoal);
    background: var(--sand);
    outline: none;
    transition: border-color .2s;
}
input[type="date"]:focus { border-color: var(--teal); }

.slots-wrap { }
.slots-label { font-size: .85rem; font-weight: 600; color: var(--slate); margin-bottom: 10px; }
.slots-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(95px, 1fr)); gap: 8px; }
.slot-btn {
    border: 2px solid var(--border);
    border-radius: 10px;
    padding: 8px 6px;
    text-align: center;
    cursor: pointer;
    font-size: .8rem;
    font-weight: 600;
    transition: all .2s;
    font-family: inherit;
    background: var(--sand);
    color: var(--charcoal);
}
.slot-btn.available { border-color: var(--green); color: var(--green); }
.slot-btn.available:hover { background: var(--green); color: #fff; }
.slot-btn.available.selected { background: var(--green); color: #fff; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
.slot-btn.full { border-color: var(--border); background: var(--sand); color: var(--muted); cursor: not-allowed; text-decoration: line-through; }
.slot-legend { display: flex; gap: 16px; margin-top: 12px; font-size: .75rem; }
.legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 4px; }
.legend-dot.green { background: var(--green); }
.legend-dot.red   { background: #e2e8f0; }

.suggestions-box {
    background: #fff8e1;
    border: 1px solid #fcd34d;
    border-radius: var(--radius);
    padding: 14px 16px;
    margin-top: 14px;
    font-size: .85rem;
}
.suggestions-box strong { color: var(--charcoal); display: block; margin-bottom: 8px; }
.suggestion-chip {
    display: inline-block;
    background: var(--sand);
    border: 1px solid #fcd34d;
    border-radius: 20px;
    padding: 4px 12px;
    margin: 3px;
    cursor: pointer;
    transition: all .2s;
    font-size: .8rem;
}
.suggestion-chip:hover { background: var(--amber); color: #fff; border-color: var(--amber); }

.form-group { margin-bottom: 18px; }
.form-label { display: block; font-size: .85rem; font-weight: 600; color: var(--slate); margin-bottom: 6px; }
.form-control {
    width: 100%;
    font-family: inherit;
    font-size: .95rem;
    padding: 10px 14px;
    border: 2px solid var(--border);
    border-radius: var(--radius);
    color: var(--charcoal);
    background: var(--sand);
    outline: none;
    transition: border-color .2s;
}
.form-control:focus { border-color: var(--teal); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 500px) { .form-row { grid-template-columns: 1fr; } }
textarea.form-control { resize: vertical; min-height: 80px; }

.summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 500px) { .summary-grid { grid-template-columns: 1fr; } }
.summary-item { background: var(--sand); border-radius: 10px; padding: 14px 16px; }
.summary-item .label { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin-bottom: 4px; }
.summary-item .value { font-size: .95rem; font-weight: 600; color: var(--charcoal); }

.btn-row { display: flex; gap: 12px; justify-content: space-between; margin-top: 24px; }
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 24px;
    border-radius: 30px;
    border: none;
    font-family: inherit;
    font-size: .9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s;
    text-decoration: none;
}
.btn-primary { background: var(--teal); color: #fff; }
.btn-primary:hover { background: var(--teal-dark); transform: translateY(-1px); box-shadow: 0 4px 16px rgba(13,148,136,.3); }
.btn-primary:disabled { background: var(--muted); cursor: not-allowed; transform: none; }
.btn-ghost { background: transparent; color: var(--slate); border: 2px solid var(--border); }
.btn-ghost:hover { border-color: var(--slate); }

.success-wrap {
    text-align: center;
    padding: 48px 24px;
    display: none;
}
.success-wrap.show { display: block; }
.success-icon {
    width: 80px; height: 80px;
    background: var(--teal-light);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 20px;
    font-size: 2.2rem;
    color: var(--teal);
}
.success-wrap h2 { font-family: 'Syne', sans-serif; font-size: 1.8rem; margin-bottom: 8px; }
.success-wrap p { color: var(--muted); margin-bottom: 24px; }

.draft-toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: var(--sand);
    border-bottom: 1px solid var(--border);
    color: #fff;
    padding: 10px 18px;
    border-radius: 20px;
    font-size: .8rem;
    opacity: 0;
    transition: opacity .3s;
    pointer-events: none;
    z-index: 999;
    display: flex;
    align-items: center;
    gap: 8px;
}
.draft-toast.show { opacity: 1; }

.error-box {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: var(--radius);
    padding: 14px 16px;
    margin-bottom: 20px;
    color: var(--red);
    font-size: .88rem;
}
.error-box ul { padding-left: 18px; margin: 0; }

.no-results { text-align: center; padding: 32px; color: var(--muted); }
.no-results i { font-size: 2rem; display: block; margin-bottom: 8px; }
.inline-login-note {
    background: var(--sand);
    border-radius: var(--radius);
    padding: 14px 18px;
    font-size: .85rem;
    color: var(--slate);
    margin-top: 12px;
}
.inline-login-note a { color: var(--teal); font-weight: 600; }


@media (max-width: 640px) {
    .page-title { font-size: 1.6rem; }
    .panel-card { padding: 18px; }
    .auth-toggle { grid-template-columns: 1fr; }
    .step-label { display: none; }
}
</style>
</head>
<body>

<div class="a11y-bar" role="toolbar" aria-label="Accessibility tools">
    <span><i class="bi bi-universal-access"></i> Accessibility:</span>
    <button class="a11y-btn" id="btnContrast" onclick="toggleContrast()" title="Toggle high-contrast mode">
        <i class="bi bi-circle-half"></i> High Contrast
    </button>
    <button class="a11y-btn" id="btnTextSm" onclick="setTextSize('normal')" title="Normal text size">A</button>
    <button class="a11y-btn" id="btnTextMd" onclick="setTextSize('large')" title="Larger text">A+</button>
    <button class="a11y-btn" id="btnTextLg" onclick="setTextSize('xlarge')" title="Largest text">A++</button>
</div>

<header class="site-header">
    <a href="../index.php" class="logo"><i class="bi bi-tooth"></i> Vytal Dental</a>
    <nav class="header-links">
        <?php if ($is_logged_in): ?>
            <a href="dashboard.php"><i class="bi bi-house me-1"></i>Dashboard</a>
            <a href="appointments.php">My Appointments</a>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="login.php" class="btn-login">Login / Register</a>
        <?php endif; ?>
    </nav>
</header>

<div class="page-wrap">
    <h1 class="page-title">Book an Appointment</h1>
    <p class="page-sub">Schedule your dental visit in just a few easy steps</p>

    <div class="progress-wrap" id="progressWrap">
        <div class="progress-line" id="progressLine"></div>
        <?php
        $steps = [
            ['num'=>1,'icon'=>'bi-person','label'=>'Patient Info'],
            ['num'=>2,'icon'=>'bi-heart-pulse','label'=>'Service'],
            ['num'=>3,'icon'=>'bi-person-badge','label'=>'Dentist'],
            ['num'=>4,'icon'=>'bi-calendar3','label'=>'Schedule'],
            ['num'=>5,'icon'=>'bi-clipboard-check','label'=>'Review'],
        ];
        foreach ($steps as $st):
        ?>
        <div class="step-item" id="stepItem<?= $st['num'] ?>">
            <div class="step-circle" id="stepCircle<?= $st['num'] ?>">
                <span class="step-num"><?= $st['num'] ?></span>
            </div>
            <span class="step-label"><?= $st['label'] ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (isset($booking_result) && !$booking_result['success']): ?>
    <div class="error-box">
        <strong><i class="bi bi-exclamation-triangle me-2"></i>Please fix the following:</strong>
        <ul><?php foreach ($booking_result['errors'] as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form id="bookingForm" method="POST" action="">
        <input type="hidden" name="submit_booking" value="1">
        <input type="hidden" name="service_id"  id="fServiceId"  value="">
        <input type="hidden" name="doctor_id"   id="fDoctorId"   value="">
        <input type="hidden" name="date"         id="fDate"       value="">
        <input type="hidden" name="time"         id="fTime"       value="">

        <div class="step-panel active" id="panel1">
            <div class="panel-card">
                <h3><i class="bi bi-person-circle"></i> Your Information</h3>

                <?php if (!$is_logged_in): ?>
                <div class="auth-toggle">
                    <div class="auth-opt selected" id="optGuest" onclick="selectAuth('guest')">
                        <i class="bi bi-person-fill-check"></i>
                        <strong>Continue as Guest</strong>
                        <small>No account needed — quick booking</small>
                    </div>
                    <div class="auth-opt" id="optLogin" onclick="window.location='login.php?redirect=book_appointment.php'">
                        <i class="bi bi-box-arrow-in-right"></i>
                        <strong>Login / Register</strong>
                        <small>Track all your appointments</small>
                    </div>
                </div>

                <div id="guestFields">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="guest_name">Full Name *</label>
                            <input type="text" class="form-control" id="guest_name" name="guest_name" placeholder="Juan dela Cruz" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="guest_phone">Phone Number</label>
                            <input type="tel" class="form-control" id="guest_phone" name="guest_phone" placeholder="09XXXXXXXXX">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="guest_email">Email Address *</label>
                        <input type="email" class="form-control" id="guest_email" name="guest_email" placeholder="you@email.com" required>
                        <small style="color:var(--muted);font-size:.75rem;margin-top:4px;display:block">Booking confirmation will be sent here</small>
                    </div>
                    <div class="inline-login-note">
                        <i class="bi bi-info-circle me-1"></i>
                        Want to track your appointments later? <a href="register.php">Create a free account</a> — it only takes a minute.
                    </div>
                </div>

                <?php else: ?>
                <div style="background:var(--teal-light);border-radius:var(--radius);padding:16px 18px;display:flex;align-items:center;gap:14px;">
                    <div style="width:42px;height:42px;background:var(--teal);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;font-family:'DM Serif Display',serif;">
                        <?= strtoupper(substr($patient_name,0,1)) ?>
                    </div>
                    <div>
                        <strong><?= htmlspecialchars($patient_name) ?></strong>
                        <div style="font-size:.8rem;color:var(--slate)">Logged-in patient</div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-group" style="margin-top:18px">
                    <label class="form-label" for="chief_complaint">Chief Complaint / Reason for Visit</label>
                    <textarea class="form-control" id="chief_complaint" name="chief_complaint" placeholder="e.g. Toothache on lower left, routine cleaning, etc."></textarea>
                </div>
            </div>

            <div class="btn-row">
                <div></div>
                <button type="button" class="btn btn-primary" onclick="goToStep(2)">
                    Next: Choose Service <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>

        <div class="step-panel" id="panel2">
            <div class="panel-card">
                <h3><i class="bi bi-heart-pulse"></i> Select a Dental Service</h3>
                <div class="service-grid" id="serviceGrid">
                    <?php foreach ($services as $svc): ?>
                    <div class="service-card"
                         onclick="selectService(<?= $svc['id'] ?>, this)"
                         data-id="<?= $svc['id'] ?>"
                         data-name="<?= htmlspecialchars($svc['name']) ?>"
                         data-price="<?= number_format($svc['price'],2) ?>"
                         data-dur="<?= $svc['duration_minutes'] ?>">
                        <div class="check"><i class="bi bi-check"></i></div>
                        <div class="svc-cat"><?= htmlspecialchars(ucfirst($svc['category'])) ?></div>
                        <div class="svc-name"><?= htmlspecialchars($svc['name']) ?></div>
                        <div class="svc-price">₱<?= number_format($svc['price'],2) ?></div>
                        <div class="svc-dur"><i class="bi bi-clock me-1"></i><?= $svc['duration_minutes'] ?> min</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="btn-row">
                <button type="button" class="btn btn-ghost" onclick="goToStep(1)"><i class="bi bi-arrow-left"></i> Back</button>
                <button type="button" class="btn btn-primary" id="btnStep2Next" onclick="goToStep(3)" disabled>
                    Next: Choose Dentist <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>

        <div class="step-panel" id="panel3">
            <div class="panel-card">
                <h3><i class="bi bi-person-badge"></i> Select a Dentist</h3>
                <div id="doctorGrid" class="doctor-grid">
                    <div class="loading-spinner"><i class="bi bi-arrow-repeat"></i>Loading dentists…</div>
                </div>
            </div>
            <div class="btn-row">
                <button type="button" class="btn btn-ghost" onclick="goToStep(2)"><i class="bi bi-arrow-left"></i> Back</button>
                <button type="button" class="btn btn-primary" id="btnStep3Next" onclick="goToStep(4)" disabled>
                    Next: Pick Schedule <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>

        <div class="step-panel" id="panel4">
            <div class="panel-card">
                <h3><i class="bi bi-calendar3"></i> Choose Date &amp; Time</h3>
                <div class="date-picker-wrap">
                    <div>
                        <div class="form-group">
                            <label class="form-label">Select Date</label>
                            <input type="date" id="apptDate" min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                   onchange="loadSlots(this.value)" style="min-width:170px">
                        </div>
                    </div>
                    <div id="slotsArea">
                        <p style="color:var(--muted);font-size:.85rem;padding-top:30px"><i class="bi bi-arrow-left me-1"></i>Pick a date to see available slots</p>
                    </div>
                </div>
                <div id="suggestionsArea"></div>
            </div>
            <div class="btn-row">
                <button type="button" class="btn btn-ghost" onclick="goToStep(3)"><i class="bi bi-arrow-left"></i> Back</button>
                <button type="button" class="btn btn-primary" id="btnStep4Next" onclick="goToStep(5)" disabled>
                    Next: Review <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>

        <div class="step-panel" id="panel5">
            <div class="panel-card">
                <h3><i class="bi bi-clipboard-check"></i> Review Your Appointment</h3>
                <p style="font-size:.85rem;color:var(--muted);margin-bottom:20px">Please review all details before confirming.</p>
                <div class="summary-grid" id="summaryGrid">
                </div>
            </div>
            <div class="panel-card" style="background:var(--sand);border:none;">
                <p style="font-size:.82rem;color:var(--slate)">
                    <i class="bi bi-info-circle me-2 text-teal"></i>
                    By submitting, your appointment will be marked as <strong>Pending</strong> until confirmed by our clinic staff.
                    You will receive an email confirmation once approved.
                </p>
            </div>
            <div class="btn-row">
                <button type="button" class="btn btn-ghost" onclick="goToStep(4)"><i class="bi bi-arrow-left"></i> Back</button>
                <button type="submit" class="btn btn-primary" id="btnConfirm">
                    <i class="bi bi-calendar-check"></i> Confirm Booking
                </button>
            </div>
        </div>
    </form>

    <div class="success-wrap" id="successWrap"
        <?php if (isset($booking_result) && $booking_result['success']): ?>style="display:block"<?php endif; ?>>
        <div class="success-icon"><i class="bi bi-calendar-check-fill"></i></div>
        <h2>Appointment Requested!</h2>
        <?php if (isset($booking_result) && ($booking_result['guest'] ?? false)): ?>
        <p>Thank you! Your booking has been submitted and is pending confirmation.<br>
           A confirmation email will be sent to your email address.</p>
        <p style="font-size:.8rem;color:var(--muted);margin-top:8px">
            Booking reference: <strong>#<?= $booking_result['appointment_id'] ?></strong>
        </p>
        <?php else: ?>
        <p>Your appointment has been submitted and is pending confirmation.<br>
           We'll send you an email once it's approved.</p>
        <?php endif; ?>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
            <?php if ($is_logged_in): ?>
            <a href="appointments.php" class="btn btn-primary"><i class="bi bi-list-ul"></i> View My Appointments</a>
            <?php endif; ?>
            <a href="book_appointment.php" class="btn btn-ghost"><i class="bi bi-plus-circle"></i> Book Another</a>
        </div>
    </div>
</div>

<div class="draft-toast" id="draftToast"><i class="bi bi-cloud-check"></i> Progress saved</div>

<script>

const state = {
    step: 1,
    authMode: 'guest',
    service: null,   
    doctor:  null,  
    date:    '',
    time:    '',
    timeLabel: '',
};
const TOTAL_STEPS = 5;
const isLoggedIn  = <?= $is_logged_in ? 'true' : 'false' ?>;

function toggleContrast() {
    document.documentElement.classList.toggle('hc');
    const on = document.documentElement.classList.contains('hc');
    document.getElementById('btnContrast').classList.toggle('active', on);
    logA11y(on ? 'high_contrast' : null);
    savePrefs();
}
function setTextSize(size) {
    const map = { normal: 1, large: 1.15, xlarge: 1.3 };
    document.documentElement.style.setProperty('--font-scale', map[size]);
    ['btnTextSm','btnTextMd','btnTextLg'].forEach(id => document.getElementById(id)?.classList.remove('active'));
    const btnMap = { normal:'btnTextSm', large:'btnTextMd', xlarge:'btnTextLg' };
    document.getElementById(btnMap[size])?.classList.add('active');
    logA11y('text_resize_' + size);
    savePrefs();
}
function logA11y(feature) {
    if (!feature) return;
    fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=log_a11y&feature=' + encodeURIComponent(feature) });
}
function savePrefs() {
    const hc = document.documentElement.classList.contains('hc');
    const scale = getComputedStyle(document.documentElement).getPropertyValue('--font-scale').trim();
    localStorage.setItem('a11y_hc', hc ? '1' : '0');
    localStorage.setItem('a11y_scale', scale);
}
function loadPrefs() {
    if (localStorage.getItem('a11y_hc') === '1') { document.documentElement.classList.add('hc'); document.getElementById('btnContrast')?.classList.add('active'); }
    const scale = localStorage.getItem('a11y_scale');
    if (scale) { document.documentElement.style.setProperty('--font-scale', scale); }
}
loadPrefs();

function updateProgress() {
    for (let i = 1; i <= TOTAL_STEPS; i++) {
        const item   = document.getElementById('stepItem' + i);
        const circle = document.getElementById('stepCircle' + i);
        item.classList.remove('active','done');
        if (i < state.step) {
            item.classList.add('done');
            circle.innerHTML = '<i class="bi bi-check-lg"></i>';
        } else if (i === state.step) {
            item.classList.add('active');
            circle.innerHTML = '<span class="step-num">' + i + '</span>';
        } else {
            circle.innerHTML = '<span class="step-num">' + i + '</span>';
        }
    }
    const pct = ((state.step - 1) / (TOTAL_STEPS - 1)) * 100;
    document.getElementById('progressLine').style.width = 'calc(' + pct + '% - 40px + ' + (pct/100*40) + 'px)';
    const wrap = document.getElementById('progressWrap');
    const lineEl = document.getElementById('progressLine');
    const totalW = wrap.clientWidth - 40;
    lineEl.style.width = (pct / 100) * totalW + 'px';
}


function goToStep(n) {
    if (n === 3 && !state.service) { alert('Please select a service first.'); return; }
    if (n === 4 && !state.doctor)  { alert('Please select a dentist first.'); return; }
    if (n === 5 && (!state.date || !state.time)) { alert('Please select a date and time slot.'); return; }

    document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel' + n)?.classList.add('active');
    state.step = n;
    updateProgress();

    if (n === 3) loadDoctors();
    if (n === 5) buildSummary();

    saveDraft();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function selectAuth(mode) {
    state.authMode = mode;
    document.getElementById('optGuest')?.classList.toggle('selected', mode==='guest');
}


function selectService(id, el) {
    document.querySelectorAll('.service-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    state.service = {
        id:    id,
        name:  el.dataset.name,
        price: el.dataset.price,
        dur:   el.dataset.dur,
    };
    document.getElementById('fServiceId').value = id;
    document.getElementById('btnStep2Next').disabled = false;
    saveDraft();
}

async function loadDoctors() {
    const grid = document.getElementById('doctorGrid');
    if (!state.service) { grid.innerHTML = '<div class="no-results"><i class="bi bi-exclamation-circle"></i>Select a service first.</div>'; return; }
    grid.innerHTML = '<div class="loading-spinner"><i class="bi bi-arrow-repeat"></i>Loading dentists…</div>';

    const fd = new FormData();
    fd.append('action', 'get_doctors');
    fd.append('service_id', state.service.id);
    const res = await fetch('', { method:'POST', body: fd });
    const data = await res.json();

    if (!data.doctors || data.doctors.length === 0) {
        grid.innerHTML = '<div class="no-results"><i class="bi bi-person-x"></i>No dentists available for this service.</div>';
        return;
    }
    grid.innerHTML = '';
    data.doctors.forEach(doc => {
        const initial = doc.name.replace('Dr. ','').charAt(0).toUpperCase();
        const div = document.createElement('div');
        div.className = 'doctor-card' + (state.doctor?.id == doc.id ? ' selected' : '');
        div.innerHTML = `
            <div class="check"><i class="bi bi-check"></i></div>
            <div class="doc-avatar">${initial}</div>
            <div class="doc-name">${doc.name}</div>
            <div class="doc-spec">${doc.specialty}</div>
            <div class="doc-avail"><i class="bi bi-calendar2-week me-1"></i>${doc.availability || 'Schedule varies'}</div>`;
        div.onclick = () => {
            document.querySelectorAll('.doctor-card').forEach(c => c.classList.remove('selected'));
            div.classList.add('selected');
            state.doctor = { id: doc.id, name: doc.name, specialty: doc.specialty };
            document.getElementById('fDoctorId').value = doc.id;
            document.getElementById('btnStep3Next').disabled = false;
            state.date = ''; state.time = '';
            document.getElementById('fDate').value = '';
            document.getElementById('fTime').value = '';
            document.getElementById('btnStep4Next').disabled = true;
            saveDraft();
        };
        grid.appendChild(div);
    });
    if (state.doctor) document.getElementById('btnStep3Next').disabled = false;
}

async function loadSlots(date) {
    state.date = date;
    document.getElementById('fDate').value = date;
    state.time = '';
    document.getElementById('fTime').value = '';
    document.getElementById('btnStep4Next').disabled = true;

    const area = document.getElementById('slotsArea');
    area.innerHTML = '<div class="loading-spinner"><i class="bi bi-arrow-repeat"></i>Loading slots…</div>';

    const fd = new FormData();
    fd.append('action', 'get_slots');
    fd.append('doctor_id', state.doctor.id);
    fd.append('date', date);
    const res  = await fetch('', { method:'POST', body: fd });
    const data = await res.json();

    if (!data.slots || data.slots.length === 0) {
        area.innerHTML = `<div class="no-results"><i class="bi bi-calendar-x"></i>${data.message || 'No slots available on this date.'}</div>`;
        renderSuggestions(data.suggestions || []);
        return;
    }

    const allFull = data.slots.every(s => !s.available);
    area.innerHTML = `
        <div class="slots-label"><i class="bi bi-clock me-1"></i>Available time slots for ${new Date(date+'T00:00').toLocaleDateString('en-PH',{weekday:'long',month:'long',day:'numeric'})}</div>
        <div class="slots-grid" id="slotsGrid"></div>
        <div class="slot-legend">
            <div><span class="legend-dot green"></span>Available</div>
            <div><span class="legend-dot red"></span>Fully Booked</div>
        </div>`;

    const grid = document.getElementById('slotsGrid');
    data.slots.forEach(slot => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'slot-btn ' + (slot.available ? 'available' : 'full');
        btn.textContent = slot.label;
        btn.disabled = !slot.available;
        if (state.time === slot.time) btn.classList.add('selected');
        btn.onclick = () => {
            document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            state.time = slot.time;
            state.timeLabel = slot.label;
            document.getElementById('fTime').value = slot.time;
            document.getElementById('btnStep4Next').disabled = false;
            saveDraft();
        };
        grid.appendChild(btn);
    });

    renderSuggestions(allFull ? (data.suggestions || []) : []);
}

function renderSuggestions(suggestions) {
    const area = document.getElementById('suggestionsArea');
    if (!suggestions || suggestions.length === 0) { area.innerHTML = ''; return; }
    let html = `<div class="suggestions-box"><strong><i class="bi bi-lightbulb me-2"></i>This date is fully booked! Try these alternatives:</strong>`;
    suggestions.forEach(s => {
        html += `<span class="suggestion-chip" onclick="applySuggestion('${s.date}','${s.time}','${s.label}')">${s.label}</span>`;
    });
    html += '</div>';
    area.innerHTML = html;
}

async function applySuggestion(date, time, label) {
    document.getElementById('apptDate').value = date;
    await loadSlots(date);
    setTimeout(() => {
        document.querySelectorAll('.slot-btn').forEach(btn => {
            if (btn.dataset && btn.textContent.trim() === label.split(' at ')[1]?.trim()) btn.click();
        });
    }, 300);
}


function buildSummary() {
    const grid = document.getElementById('summaryGrid');
    const gName  = document.getElementById('guest_name')?.value || '(logged in)';
    const gEmail = document.getElementById('guest_email')?.value || '';
    const complaint = document.getElementById('chief_complaint')?.value || '—';
    const dateLabel = state.date ? new Date(state.date+'T00:00').toLocaleDateString('en-PH',{weekday:'long',year:'numeric',month:'long',day:'numeric'}) : '—';

    const items = isLoggedIn
        ? [['Patient', '<?= htmlspecialchars($patient_name) ?>'],]
        : [['Patient Name', gName], ['Email', gEmail]];

    const all = [
        ...items,
        ['Service',   state.service?.name || '—'],
        ['Price',     state.service ? '₱' + state.service.price : '—'],
        ['Duration',  state.service ? state.service.dur + ' minutes' : '—'],
        ['Dentist',   state.doctor?.name || '—'],
        ['Specialty', state.doctor?.specialty || '—'],
        ['Date',      dateLabel],
        ['Time',      state.timeLabel || state.time || '—'],
        ['Reason',    complaint],
    ];

    grid.innerHTML = all.map(([l,v]) => `
        <div class="summary-item">
            <div class="label">${l}</div>
            <div class="value">${v}</div>
        </div>`).join('');
}


let draftTimer = null;
function saveDraft() {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(async () => {
        const draft = {
            step: state.step,
            service: state.service,
            doctor: state.doctor,
            date: state.date,
            time: state.time,
            timeLabel: state.timeLabel,
            guest_name:  document.getElementById('guest_name')?.value || '',
            guest_email: document.getElementById('guest_email')?.value || '',
            guest_phone: document.getElementById('guest_phone')?.value || '',
            complaint:   document.getElementById('chief_complaint')?.value || '',
        };
        const fd = new FormData();
        fd.append('action', 'save_draft');
        fd.append('draft_data', JSON.stringify(draft));
        fd.append('step', state.step);
        await fetch('', { method:'POST', body: fd });
        showDraftToast();
    }, 1500);
}

async function loadDraft() {
    const fd = new FormData();
    fd.append('action', 'load_draft');
    const res = await fetch('', { method:'POST', body: fd });
    const data = await res.json();
    if (!data.draft_data) return;
    try {
        const d = JSON.parse(data.draft_data);
        if (d.guest_name)  { const el = document.getElementById('guest_name');  if(el) el.value = d.guest_name; }
        if (d.guest_email) { const el = document.getElementById('guest_email'); if(el) el.value = d.guest_email; }
        if (d.guest_phone) { const el = document.getElementById('guest_phone'); if(el) el.value = d.guest_phone; }
        if (d.complaint)   { const el = document.getElementById('chief_complaint'); if(el) el.value = d.complaint; }
        if (d.service) {
            state.service = d.service;
            document.getElementById('fServiceId').value = d.service.id;
            const card = document.querySelector(`.service-card[data-id="${d.service.id}"]`);
            if (card) { card.classList.add('selected'); document.getElementById('btnStep2Next').disabled = false; }
        }
        if (d.doctor) {
            state.doctor = d.doctor;
            document.getElementById('fDoctorId').value = d.doctor.id;
        }
        if (d.date)  { state.date = d.date;  document.getElementById('fDate').value = d.date; document.getElementById('apptDate').value = d.date; }
        if (d.time)  { state.time = d.time;  state.timeLabel = d.timeLabel || d.time; document.getElementById('fTime').value = d.time; }
        if (d.step && d.step > 1) {
            if (confirm('We saved your booking progress. Would you like to continue where you left off?')) {
                goToStep(Math.min(d.step, 4)); 
            }
        }
    } catch(e) { console.warn('Draft parse error', e); }
}

function showDraftToast() {
    const t = document.getElementById('draftToast');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2000);
}

document.querySelectorAll('.form-control').forEach(el => {
    el.addEventListener('input', () => saveDraft());
});

document.addEventListener('DOMContentLoaded', () => {
    updateProgress();
    loadDraft();

    <?php if (isset($booking_result) && $booking_result['success']): ?>
    document.getElementById('bookingForm').style.display = 'none';
    document.getElementById('progressWrap').style.display = 'none';
    <?php endif; ?>
});
</script>
</body>
</html>