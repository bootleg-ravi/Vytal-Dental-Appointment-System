<?php
session_start();
if (!isset($_SESSION['patient_id'])) {
    header("Location: login.php");
    exit;
}
require_once '../config/config.php';

$patient_id   = (int)$_SESSION['patient_id'];
$patient_name = $_SESSION['patient_name'] ?? 'Patient';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'get_month_data') {
        $year  = intval($_POST['year']  ?? date('Y'));
        $month = intval($_POST['month'] ?? date('m'));
        $month_str = sprintf('%04d-%02d', $year, $month);

        $my = $conn->query("SELECT a.id, a.date, a.time, a.status,
                               d.name AS doctor, s.name AS service, s.category
                            FROM appointments a
                            JOIN doctors d  ON a.doctor_id  = d.id
                            LEFT JOIN services s ON a.service_id = s.id
                            WHERE a.patient_id = $patient_id
                            AND DATE_FORMAT(a.date,'%Y-%m') = '$month_str'
                            ORDER BY a.date, a.time");
        $my_appts = [];
        while ($r = $my->fetch_assoc()) $my_appts[] = $r;

        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $avail = [];
        for ($d = 1; $d <= $days_in_month; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $dow  = date('l', strtotime($date));

            $cap_res = $conn->query("SELECT SUM(max_patients * 
                                        FLOOR(TIME_TO_SEC(TIMEDIFF(end_time,start_time)) / (slot_duration_minutes*60)))
                                     AS total_cap
                                     FROM dentist_schedule
                                     WHERE day_of_week = '$dow' AND is_active = 1");
            $cap = (int)($cap_res->fetch_assoc()['total_cap'] ?? 0);

            $bk_res = $conn->query("SELECT COUNT(*) AS cnt FROM appointments
                                    WHERE date='$date' AND status NOT IN ('cancelled')");
            $bk = (int)($bk_res->fetch_assoc()['cnt'] ?? 0);

            if ($cap === 0) {
                $avail[$date] = 'closed'; 
            } elseif ($bk >= $cap) {
                $avail[$date] = 'full';
            } elseif ($bk >= $cap * 0.75) {
                $avail[$date] = 'limited'; 
            } else {
                $avail[$date] = 'open';
            }
        }

        echo json_encode(['appointments' => $my_appts, 'availability' => $avail]);
        exit;
    }

    if ($_POST['action'] === 'get_day_slots') {
        $date      = $conn->real_escape_string($_POST['date'] ?? '');
        $doctor_id = intval($_POST['doctor_id'] ?? 0);
        $dow       = date('l', strtotime($date));

        $where = $doctor_id ? "doctor_id=$doctor_id" : "1=1";
        $scheds = $conn->query("SELECT ds.doctor_id, ds.start_time, ds.end_time,
                                       ds.slot_duration_minutes, ds.max_patients,
                                       d.name AS doctor_name, d.specialty
                                FROM dentist_schedule ds
                                JOIN doctors d ON ds.doctor_id = d.id
                                WHERE ds.day_of_week='$dow' AND ds.is_active=1
                                AND d.is_active=1 AND $where
                                ORDER BY d.name, ds.start_time");
        $result = [];
        while ($sch = $scheds->fetch_assoc()) {
            $dur   = (int)$sch['slot_duration_minutes'];
            $max   = (int)$sch['max_patients'];
            $start = strtotime($date . ' ' . $sch['start_time']);
            $end   = strtotime($date . ' ' . $sch['end_time']);
            $slots = [];
            for ($t = $start; $t < $end; $t += $dur * 60) {
                $ts  = date('H:i:s', $t);
                $bkd = $conn->query("SELECT COUNT(*) AS c FROM appointments
                                     WHERE doctor_id={$sch['doctor_id']} AND date='$date'
                                     AND time='$ts' AND status NOT IN ('cancelled')");
                $cnt = (int)$bkd->fetch_assoc()['c'];
                $slots[] = [
                    'time'      => $ts,
                    'label'     => date('g:i A', $t),
                    'available' => $cnt < $max,
                    'booked'    => $cnt,
                    'max'       => $max,
                ];
            }
            $result[] = [
                'doctor_id'   => $sch['doctor_id'],
                'doctor_name' => $sch['doctor_name'],
                'specialty'   => $sch['specialty'],
                'slots'       => $slots,
            ];
        }
        echo json_encode(['dentists' => $result]);
        exit;
    }

    if ($_POST['action'] === 'get_suggestions') {
        $suggestions = [];
        $from = date('Y-m-d', strtotime('+1 day'));
        for ($d = 1; $d <= 30 && count($suggestions) < 5; $d++) {
            $chk_date = date('Y-m-d', strtotime($from . " +$d days"));
            $chk_dow  = date('l', strtotime($chk_date));
            $scheds = $conn->query("SELECT ds.doctor_id, ds.start_time, ds.end_time,
                                           ds.slot_duration_minutes, ds.max_patients,
                                           d.name AS doctor_name, d.specialty
                                    FROM dentist_schedule ds
                                    JOIN doctors d ON ds.doctor_id=d.id
                                    WHERE ds.day_of_week='$chk_dow' AND ds.is_active=1 AND d.is_active=1
                                    LIMIT 10");
            while ($sch = $scheds->fetch_assoc()) {
                if (count($suggestions) >= 5) break;
                $dur   = (int)$sch['slot_duration_minutes'];
                $max   = (int)$sch['max_patients'];
                $start = strtotime($chk_date . ' ' . $sch['start_time']);
                $end   = strtotime($chk_date . ' ' . $sch['end_time']);
                for ($t = $start; $t < $end; $t += $dur * 60) {
                    $ts  = date('H:i:s', $t);
                    $bk  = $conn->query("SELECT COUNT(*) AS c FROM appointments
                                         WHERE doctor_id={$sch['doctor_id']} AND date='$chk_date'
                                         AND time='$ts' AND status NOT IN ('cancelled')");
                    if ((int)$bk->fetch_assoc()['c'] < $max) {
                        $suggestions[] = [
                            'date'        => $chk_date,
                            'date_label'  => date('D, M j', strtotime($chk_date)),
                            'time'        => $ts,
                            'time_label'  => date('g:i A', $t),
                            'doctor_id'   => $sch['doctor_id'],
                            'doctor_name' => $sch['doctor_name'],
                            'specialty'   => $sch['specialty'],
                        ];
                        break; 
                    }
                }
            }
        }
        echo json_encode(['suggestions' => $suggestions]);
        exit;
    }

    echo json_encode(['ok' => false]);
    exit;
}

$upcoming = $conn->query("SELECT a.id, a.date, a.time, a.status,
                            d.name AS doctor, s.name AS service, s.category
                          FROM appointments a
                          JOIN doctors d ON a.doctor_id = d.id
                          LEFT JOIN services s ON a.service_id = s.id
                          WHERE a.patient_id=$patient_id
                          AND a.date >= CURDATE()
                          AND a.status NOT IN ('cancelled','completed')
                          ORDER BY a.date, a.time
                          LIMIT 5");
$upcoming_list = [];
while ($r = $upcoming->fetch_assoc()) $upcoming_list[] = $r;

$notif_res = $conn->query("SELECT COUNT(*) AS cnt FROM notifications WHERE patient_id=$patient_id AND is_read=0");
$notif_count = (int)$notif_res->fetch_assoc()['cnt'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Appointment Calendar – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>

:root {
    --bg:       #0e1621;
    --bg2:      #151f2e;
    --bg3:      #1c2a3e;
    --surface:  #1e2d42;
    --border:   rgba(255,255,255,.08);
    --teal:     #00c9a7;
    --teal2:    #00a88d;
    --amber:    #fbbf24;
    --red:      #f87171;
    --slate:    #94a3b8;
    --text:     #e2e8f0;
    --white:    #ffffff;
    --green:    #34d399;
    --open:     #34d399;
    --limited:  #fbbf24;
    --full:     #f87171;
    --closed:   #2d3a4a;
    --radius:   12px;
    --muted:    #64748b;
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html, body { height:100%; }
body {
    font-family: 'Lato', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.layout { display: flex; flex: 1; min-height: 0; }

.sidebar {
    width: 220px;
    background: var(--bg2);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    padding: 0;
    flex-shrink: 0;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
}
.sidebar-logo {
    padding: 20px 18px;
    border-bottom: 1px solid var(--border);
    font-family: 'Syne', sans-serif;
    font-size: 1rem;
    font-weight: 800;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 9px;
}
.sidebar-logo i { font-size: 1.2rem; color: var(--teal); }
.nav-section { padding: 14px 16px 4px; font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: rgba(255,255,255,.2); }
.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 18px;
    color: var(--slate);
    text-decoration: none;
    font-size: .82rem;
    font-weight: 500;
    transition: all .15s;
    position: relative;
}
.nav-item:hover { color: var(--text); background: rgba(255,255,255,.04); }
.nav-item.active { color: var(--teal); background: rgba(0,201,167,.08); }
.nav-item.active::before {
    content: '';
    position: absolute;
    left: 0; top: 15%; bottom: 15%;
    width: 3px;
    background: var(--teal);
    border-radius: 0 2px 2px 0;
}
.nav-item i { font-size: .9rem; width: 16px; text-align: center; }
.nav-badge {
    margin-left: auto;
    background: #ef4444;
    color: #fff;
    font-size: .58rem;
    font-weight: 700;
    padding: 1px 5px;
    border-radius: 20px;
}
.sidebar-footer {
    margin-top: auto;
    padding: 12px;
    border-top: 1px solid var(--border);
}
.user-chip {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 10px;
    background: rgba(255,255,255,.04);
    border-radius: 8px;
    border: 1px solid var(--border);
}
.user-avatar {
    width: 30px; height: 30px;
    background: var(--teal);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    color: #0e1621;
    font-size: .8rem;
    flex-shrink: 0;
}
.u-name { font-size: .78rem; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.u-role { font-size: .65rem; color: var(--muted); }

.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow-y: auto;
}
.topbar {
    padding: 0 28px;
    height: 56px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bg2);
    position: sticky;
    top: 0;
    z-index: 50;
}
.topbar-title { font-family: 'Syne', sans-serif; font-size: .95rem; font-weight: 800; color: var(--text); }
.topbar-sub   { font-size: .72rem; color: var(--muted); }
.topbar-actions { display: flex; align-items: center; gap: 10px; }
.btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 20px;
    border-radius: 30px;
    border: none;
    font-family: 'Lato', sans-serif;
    font-size: .85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
    text-decoration: none;
}
.btn-teal { background: var(--teal); color: var(--bg); }
.btn-teal:hover { background: var(--teal2); transform: translateY(-1px); }
.btn-ghost { background: transparent; color: var(--slate); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--text); border-color: rgba(255,255,255,.2); }

.content { padding: 28px 32px; flex: 1; }

.cal-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 24px;
    align-items: start;
}
@media (max-width: 1024px) { .cal-grid { grid-template-columns: 1fr; } }

.cal-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 20px;
    overflow: hidden;
}
.cal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
}
.cal-month {
    font-family: 'Syne', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--white);
    letter-spacing: -.02em;
}
.cal-nav-btn {
    width: 36px; height: 36px;
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 50%;
    color: var(--slate);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    font-size: 1rem;
    transition: all .2s;
}
.cal-nav-btn:hover { color: var(--teal); border-color: var(--teal); }

.cal-dow {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    padding: 12px 20px 0;
}
.dow-cell {
    text-align: center;
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--slate);
    padding: 6px 0;
}

.cal-body {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
    padding: 8px 20px 20px;
}
.day-cell {
    aspect-ratio: 1;
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    transition: all .18s;
    border: 2px solid transparent;
    min-height: 44px;
}
.day-cell:hover:not(.empty):not(.closed) { border-color: var(--teal); transform: scale(1.06); z-index: 2; }
.day-cell.empty { cursor: default; }
.day-cell.closed { opacity: .25; cursor: not-allowed; }
.day-cell.past   { opacity: .35; cursor: default; }
.day-num {
    font-family: 'Syne', sans-serif;
    font-size: .85rem;
    font-weight: 700;
    line-height: 1;
    color: var(--text);
}
.day-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    margin-top: 4px;
}

.day-cell.open    { background: rgba(52,211,153,.08); }
.day-cell.open    .day-num { color: var(--open); }
.day-cell.open    .day-dot { background: var(--open); }
.day-cell.limited { background: rgba(251,191,36,.08); }
.day-cell.limited .day-num { color: var(--limited); }
.day-cell.limited .day-dot { background: var(--limited); }
.day-cell.full    { background: rgba(248,113,113,.06); }
.day-cell.full    .day-num { color: var(--full); }
.day-cell.full    .day-dot { background: var(--full); }
.day-cell.closed  { background: transparent; }

.day-cell.has-appt::after {
    content: '';
    position: absolute;
    top: 5px; right: 5px;
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--teal);
    box-shadow: 0 0 0 2px var(--bg2);
}
.day-cell.selected { border-color: var(--teal) !important; box-shadow: 0 0 0 3px rgba(0,201,167,.25); transform: scale(1.08); z-index:3; }
.day-cell.today .day-num::after {
    content: '•';
    display: block;
    font-size: .4rem;
    text-align: center;
    color: var(--teal);
    line-height: 1;
}

.cal-legend {
    display: flex;
    gap: 16px;
    padding: 14px 24px;
    border-top: 1px solid var(--border);
    flex-wrap: wrap;
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .72rem;
    color: var(--slate);
}
.legend-dot { width: 9px; height: 9px; border-radius: 50%; }

.right-panel { display: flex; flex-direction: column; gap: 18px; }

.panel-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 20px;
    overflow: hidden;
}
.panel-card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    font-family: 'Syne', sans-serif;
    font-size: .9rem;
    font-weight: 700;
    color: var(--white);
    display: flex;
    align-items: center;
    gap: 8px;
}
.panel-card-header i { color: var(--teal); }
.panel-card-body { padding: 16px 20px; }

.day-detail-date {
    font-family: 'Syne', sans-serif;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--white);
    margin-bottom: 14px;
}
.dentist-block {
    margin-bottom: 16px;
}
.dentist-name {
    font-size: .8rem;
    font-weight: 700;
    color: var(--teal);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.dentist-name span { color: var(--slate); font-weight: 400; }
.slots-mini {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}
.slot-pill {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: .72rem;
    font-weight: 700;
    border: 1px solid;
    cursor: pointer;
    transition: all .15s;
    font-family: 'Lato', sans-serif;
}
.slot-pill.open   { border-color: var(--open);   color: var(--open);   }
.slot-pill.open:hover { background: var(--open); color: var(--bg); }
.slot-pill.full   { border-color: var(--border); color: var(--slate); cursor: not-allowed; text-decoration: line-through; }
.slot-pill.my-appt { background: var(--teal); border-color: var(--teal); color: var(--bg); }
.no-slots { color: var(--slate); font-size: .82rem; text-align: center; padding: 20px 0; }
.no-slots i { display: block; font-size: 1.5rem; margin-bottom: 6px; opacity: .5; }

.appt-item {
    display: flex;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
}
.appt-item:last-child { border-bottom: none; }
.appt-date-badge {
    background: var(--bg3);
    border-radius: 10px;
    width: 44px;
    height: 44px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.appt-date-badge .day { font-family: 'Syne', sans-serif; font-size: .95rem; font-weight: 800; color: var(--teal); line-height: 1; }
.appt-date-badge .mon { font-size: .6rem; font-weight: 700; color: var(--slate); text-transform: uppercase; }
.appt-info { flex: 1; min-width: 0; }
.appt-service { font-size: .83rem; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.appt-doctor  { font-size: .73rem; color: var(--slate); margin-top: 2px; }
.appt-time    { font-size: .7rem; color: var(--teal); font-weight: 700; margin-top: 3px; }
.status-chip {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: .62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    flex-shrink: 0;
    align-self: flex-start;
    margin-top: 2px;
}
.status-pending   { background: rgba(251,191,36,.15); color: var(--amber); }
.status-confirmed { background: rgba(52,211,153,.15); color: var(--green); }

.suggestion-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    gap: 10px;
}
.suggestion-item:last-child { border-bottom: none; }
.sug-info .date  { font-size: .8rem; font-weight: 700; color: var(--text); }
.sug-info .doc   { font-size: .72rem; color: var(--slate); margin-top: 2px; }
.sug-info .time  { font-size: .72rem; color: var(--teal); font-weight: 700; margin-top: 2px; }
.btn-book-sug {
    background: rgba(0,201,167,.12);
    color: var(--teal);
    border: 1px solid rgba(0,201,167,.3);
    border-radius: 20px;
    padding: 5px 12px;
    font-size: .72rem;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    font-family: 'Lato', sans-serif;
    transition: all .2s;
    text-decoration: none;
}
.btn-book-sug:hover { background: var(--teal); color: var(--bg); }

.skeleton {
    background: linear-gradient(90deg, var(--bg3) 25%, var(--surface) 50%, var(--bg3) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
    border-radius: 6px;
}
@keyframes shimmer { to { background-position: -200% 0; } }

.day-detail-empty {
    text-align: center;
    padding: 32px 16px;
    color: var(--slate);
}
.day-detail-empty i { font-size: 2rem; display: block; margin-bottom: 8px; opacity: .4; }
.day-detail-empty p { font-size: .82rem; }

.cal-loading {
    position: absolute;
    inset: 0;
    background: rgba(14,22,33,.7);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    backdrop-filter: blur(2px);
}
.cal-loading i { font-size: 2rem; color: var(--teal); animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.cal-body-wrap { position: relative; }

@media (max-width: 768px) {
    .sidebar { display: none; }
    .content { padding: 16px; }
    .topbar  { padding: 14px 16px; }
    .cal-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="layout">


<aside class="sidebar">
    <div class="sidebar-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
    <div class="nav-section">Menu</div>
    <a href="dashboard.php"              class="nav-item"><i class="bi bi-house-fill"></i> Dashboard</a>
    <a href="book_appointment.php"       class="nav-item"><i class="bi bi-calendar-plus"></i> Book Appointment</a>
    <a href="appointments_calendar.php"  class="nav-item active"><i class="bi bi-calendar3"></i> Calendar</a>
    <a href="appointments.php"           class="nav-item"><i class="bi bi-list-check"></i> My Appointments</a>
    <a href="notifications.php"          class="nav-item">
        <i class="bi bi-bell"></i> Notifications
        <?php if ($notif_count > 0): ?><span class="nav-badge"><?= $notif_count ?></span><?php endif; ?>
    </a>
    <a href="profile.php"                class="nav-item"><i class="bi bi-person"></i> Profile</a>
    <hr style="margin:6px 14px;border:none;border-top:1px solid var(--border)">
    <a href="logout.php" class="nav-item" style="color:rgba(248,113,113,.5)" onmouseover="this.style.color='#f87171';this.style.background='rgba(248,113,113,.07)'" onmouseout="this.style.color='rgba(248,113,113,.5)';this.style.background=''"><i class="bi bi-box-arrow-right"></i> Logout</a>
    <div class="sidebar-footer">
        <div class="user-chip">
            <div class="user-avatar"><?= strtoupper(substr($patient_name,0,1)) ?></div>
            <div>
                <div class="u-name"><?= htmlspecialchars($patient_name) ?></div>
                <div class="u-role">Patient</div>
            </div>
        </div>
    </div>
</aside>


<div class="main">
    <div class="topbar">
        <div>
            <div class="topbar-title">Appointment Calendar</div>
            <div class="topbar-sub">View availability and schedule your dental visits</div>
        </div>
        <div class="topbar-actions">
            <a href="book_appointment.php" class="btn btn-teal"><i class="bi bi-plus-lg"></i> Book Now</a>
        </div>
    </div>

    <div class="content">
        <div class="cal-grid">

            <div>
                <div class="cal-card">
                    <div class="cal-header">
                        <button class="cal-nav-btn" onclick="changeMonth(-1)"><i class="bi bi-chevron-left"></i></button>
                        <div class="cal-month" id="calMonthLabel">Loading…</div>
                        <button class="cal-nav-btn" onclick="changeMonth(1)"><i class="bi bi-chevron-right"></i></button>
                    </div>

                    <div class="cal-dow">
                        <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                        <div class="dow-cell"><?= $d ?></div>
                        <?php endforeach; ?>
                    </div>

                    <div class="cal-body-wrap">
                        <div class="cal-body" id="calBody">
                        
                        </div>
                        <div class="cal-loading" id="calLoading" style="display:none">
                            <i class="bi bi-arrow-repeat"></i>
                        </div>
                    </div>

                    <div class="cal-legend">
                        <div class="legend-item"><span class="legend-dot" style="background:var(--open)"></span> Available</div>
                        <div class="legend-item"><span class="legend-dot" style="background:var(--limited)"></span> Limited Slots</div>
                        <div class="legend-item"><span class="legend-dot" style="background:var(--full)"></span> Fully Booked</div>
                        <div class="legend-item"><span class="legend-dot" style="background:var(--closed)"></span> Clinic Closed</div>
                        <div class="legend-item"><span class="legend-dot" style="background:var(--teal);box-shadow:0 0 0 2px var(--bg2)"></span> Your Appointment</div>
                    </div>
                </div>
            </div>


            <div class="right-panel">

                <div class="panel-card" id="dayDetailCard">
                    <div class="panel-card-header">
                        <i class="bi bi-calendar-day"></i>
                        <span id="dayDetailTitle">Select a Date</span>
                    </div>
                    <div class="panel-card-body" id="dayDetailBody">
                        <div class="day-detail-empty">
                            <i class="bi bi-calendar3"></i>
                            <p>Click any date to see available slots and dentists.</p>
                        </div>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-card-header">
                        <i class="bi bi-clock-history"></i> Upcoming Appointments
                    </div>
                    <div class="panel-card-body">
                        <?php if (empty($upcoming_list)): ?>
                        <div class="day-detail-empty">
                            <i class="bi bi-calendar-x"></i>
                            <p>No upcoming appointments. <a href="book_appointment.php" style="color:var(--teal)">Book one now!</a></p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($upcoming_list as $ap): ?>
                        <div class="appt-item">
                            <div class="appt-date-badge">
                                <div class="day"><?= date('d', strtotime($ap['date'])) ?></div>
                                <div class="mon"><?= date('M', strtotime($ap['date'])) ?></div>
                            </div>
                            <div class="appt-info">
                                <div class="appt-service"><?= htmlspecialchars($ap['service'] ?? 'Appointment') ?></div>
                                <div class="appt-doctor"><?= htmlspecialchars($ap['doctor']) ?></div>
                                <div class="appt-time"><i class="bi bi-clock me-1"></i><?= date('g:i A', strtotime($ap['time'])) ?></div>
                            </div>
                            <span class="status-chip status-<?= $ap['status'] ?>"><?= $ap['status'] ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-card-header">
                        <i class="bi bi-lightbulb"></i> Next Available Slots
                    </div>
                    <div class="panel-card-body" id="suggestionsBody">
                        <div class="day-detail-empty">
                            <i class="bi bi-arrow-repeat" style="animation:spin 1.5s linear infinite;font-size:1.4rem;opacity:.5"></i>
                            <p>Loading suggestions…</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
const today = new Date();
today.setHours(0,0,0,0);
let curYear  = today.getFullYear();
let curMonth = today.getMonth() + 1; // 1-based
let selectedDate  = null;
let monthData     = null; 
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

const myApptDates = new Set(<?= json_encode(array_column($upcoming_list, 'date')) ?>);

async function loadMonth(year, month) {
    document.getElementById('calLoading').style.display = 'flex';
    document.getElementById('calMonthLabel').textContent = MONTHS[month-1] + ' ' + year;

    const fd = new FormData();
    fd.append('action', 'get_month_data');
    fd.append('year',   year);
    fd.append('month',  month);
    const res  = await fetch('', { method:'POST', body: fd });
    monthData  = await res.json();

    renderCalendar(year, month, monthData);
    document.getElementById('calLoading').style.display = 'none';
}

function renderCalendar(year, month, data) {
    const body  = document.getElementById('calBody');
    body.innerHTML = '';

    const firstDay   = new Date(year, month-1, 1).getDay(); 
    const daysInMonth = new Date(year, month, 0).getDate();
    const todayStr   = formatDate(today);

    for (let i = 0; i < firstDay; i++) {
        const el = document.createElement('div');
        el.className = 'day-cell empty';
        body.appendChild(el);
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = sprintf('%04d-%02d-%02d', year, month, d);
        const avail   = data.availability[dateStr] || 'closed';
        const dateObj = new Date(year, month-1, d);
        const isPast  = dateObj < today;
        const isToday = dateStr === todayStr;

        const hasMyAppt = data.appointments.some(a => a.date === dateStr);

        const el = document.createElement('div');
        let cls = 'day-cell';
        if (!isPast) cls += ' ' + avail;
        if (isPast)  cls += ' past';
        if (isToday) cls += ' today';
        if (hasMyAppt) cls += ' has-appt';
        if (selectedDate === dateStr) cls += ' selected';
        el.className = cls;
        el.innerHTML = `<span class="day-num">${d}</span>${avail !== 'closed' && !isPast ? '<div class="day-dot"></div>' : ''}`;

        if (!isPast && avail !== 'closed') {
            el.onclick = () => selectDay(dateStr, el);
        }
        body.appendChild(el);
    }
}

async function selectDay(dateStr, el) {
    document.querySelectorAll('.day-cell.selected').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    selectedDate = dateStr;

    const title = document.getElementById('dayDetailTitle');
    const body  = document.getElementById('dayDetailBody');
    title.textContent = formatDateLong(dateStr);
    body.innerHTML = '<div class="no-slots"><i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite"></i></div>';

    const fd = new FormData();
    fd.append('action', 'get_day_slots');
    fd.append('date', dateStr);
    const res  = await fetch('', { method:'POST', body: fd });
    const data = await res.json();

    if (!data.dentists || data.dentists.length === 0) {
        body.innerHTML = '<div class="no-slots"><i class="bi bi-calendar-x"></i>No dentists scheduled on this day.</div>';
        return;
    }

    const myAppts = monthData?.appointments?.filter(a => a.date === dateStr) || [];

    let html = '';
    if (myAppts.length > 0) {
        html += `<div style="background:rgba(0,201,167,.08);border:1px solid rgba(0,201,167,.2);border-radius:10px;padding:10px 12px;margin-bottom:14px;font-size:.8rem;">
            <strong style="color:var(--teal)"><i class="bi bi-calendar-check me-1"></i>Your appointments on this day:</strong>`;
        myAppts.forEach(a => {
            html += `<div style="margin-top:6px;color:var(--text)">${formatTime(a.time)} — ${a.service || 'Appointment'} with ${a.doctor}
                <span class="status-chip status-${a.status}" style="margin-left:4px">${a.status}</span></div>`;
        });
        html += '</div>';
    }

    data.dentists.forEach(doc => {
        const hasSlots = doc.slots.some(s => s.available);
        html += `<div class="dentist-block">
            <div class="dentist-name">
                <i class="bi bi-person-badge"></i> ${doc.doctor_name}
                <span>· ${doc.specialty}</span>
            </div>
            <div class="slots-mini">`;
        doc.slots.forEach(slot => {
            const isMySlot = myAppts.some(a => a.time === slot.time);
            if (isMySlot) {
                html += `<span class="slot-pill my-appt" title="Your appointment"><i class="bi bi-check me-1"></i>${slot.label}</span>`;
            } else if (slot.available) {
                html += `<a href="book_appointment.php?doctor_id=${doc.doctor_id}&date=${dateStr}&time=${encodeURIComponent(slot.time)}"
                            class="slot-pill open">${slot.label}</a>`;
            } else {
                html += `<span class="slot-pill full" title="Fully booked">${slot.label}</span>`;
            }
        });
        if (!hasSlots) html += `<span style="font-size:.75rem;color:var(--slate)">All slots booked</span>`;
        html += '</div></div>';
    });

    body.innerHTML = html;
}

async function loadSuggestions() {
    const fd = new FormData();
    fd.append('action', 'get_suggestions');
    const res  = await fetch('', { method:'POST', body: fd });
    const data = await res.json();
    const body = document.getElementById('suggestionsBody');

    if (!data.suggestions || data.suggestions.length === 0) {
        body.innerHTML = '<div class="no-slots"><i class="bi bi-calendar-check"></i>All upcoming slots are available!</div>';
        return;
    }

    let html = '';
    data.suggestions.forEach(s => {
        html += `<div class="suggestion-item">
            <div class="sug-info">
                <div class="date">${s.date_label}</div>
                <div class="doc">${s.doctor_name} · ${s.specialty}</div>
                <div class="time">${s.time_label}</div>
            </div>
            <a href="book_appointment.php?doctor_id=${s.doctor_id}&date=${s.date}&time=${encodeURIComponent(s.time)}"
               class="btn-book-sug">Book →</a>
        </div>`;
    });
    body.innerHTML = html;
}

function changeMonth(delta) {
    curMonth += delta;
    if (curMonth > 12) { curMonth = 1; curYear++; }
    if (curMonth < 1)  { curMonth = 12; curYear--; }
    selectedDate = null;
    document.getElementById('dayDetailTitle').textContent = 'Select a Date';
    document.getElementById('dayDetailBody').innerHTML = `<div class="day-detail-empty"><i class="bi bi-calendar3"></i><p>Click any date to see available slots.</p></div>`;
    loadMonth(curYear, curMonth);
}


function formatDate(d) {
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}
function formatDateLong(str) {
    const d = new Date(str + 'T00:00');
    return d.toLocaleDateString('en-PH', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
}
function formatTime(t) {
    const [h, m] = t.split(':');
    const hr = parseInt(h);
    return (hr > 12 ? hr-12 : hr||12) + ':' + m + ' ' + (hr >= 12 ? 'PM' : 'AM');
}
function sprintf(fmt, ...args) {
    let i = 0;
    return fmt.replace(/%0(\d+)d/g, (_,w) => String(args[i++]).padStart(parseInt(w),'0'))
              .replace(/%(\d*)d/g, () => args[i++]);
}

loadMonth(curYear, curMonth);
loadSuggestions();
</script>
</body>
</html>