<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';
$success    = $_GET['success'] ?? '';
$error      = $_GET['error']   ?? '';

if ($is_admin && isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    $cnt = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments WHERE patient_id=$did")->fetch_assoc()['c'];
    if ($cnt > 0) header("Location: manage_patients.php?error=" . urlencode("Cannot delete patient with existing appointments."));
    else { $conn->query("DELETE FROM patients WHERE id=$did"); header("Location: manage_patients.php?success=" . urlencode("Patient deleted.")); }
    exit;
}

$q  = trim($_GET['q']      ?? '');
$gf = $_GET['gender']      ?? '';
$sf = $_GET['sort']        ?? 'name';
$valid_sorts = ['name','created_at','email'];
if (!in_array($sf,$valid_sorts)) $sf = 'name';

$where = [];
if ($q)  $where[] = "(name LIKE '%" . $conn->real_escape_string($q) . "%' OR email LIKE '%" . $conn->real_escape_string($q) . "%' OR phone LIKE '%" . $conn->real_escape_string($q) . "%')";
if ($gf) $where[] = "gender='" . $conn->real_escape_string($gf) . "'";
$wc = $where ? "WHERE " . implode(" AND ", $where) : "";

$patients = $conn->query("SELECT p.id,p.name,p.email,p.phone,p.address,p.birthdate,p.gender,p.profile_picture,p.created_at,p.allergies,
    (SELECT COUNT(*) FROM appointments a WHERE a.patient_id=p.id) AS appt_count,
    (SELECT COUNT(*) FROM appointments a WHERE a.patient_id=p.id AND a.status='completed') AS completed_count
    FROM patients p $wc ORDER BY p.$sf ASC")->fetch_all(MYSQLI_ASSOC);

$total_patients = count($patients);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Manage Patients – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--bg:#f7f5f0;--bg2:#fff;--sidebar:#111827;--border:#e8e3da;--teal:#0d9488;--teal2:#0f766e;--teal-lt:#f0fdf9;--amber:#d97706;--red:#dc2626;--green:#16a34a;--ink:#111827;--slate:#6b7280;--muted:#9ca3af;--radius:14px;--shadow:0 1px 10px rgba(0,0,0,.05)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Lato',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex}
.sidebar{width:230px;background:var(--sidebar);min-height:100vh;position:fixed;left:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100;overflow-y:auto}
.sidebar-logo{padding:22px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px;font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:#fff}
.sidebar-logo i{color:#00c9a7;font-size:1.3rem}
.sidebar-role {
    margin: 0 12px 8px;
    padding: 7px 12px;
    background: rgba(255,255,255,.05);
    border-radius: 8px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: rgba(255,255,255,.4);
    display: flex; align-items: center; gap: 6px;
    margin-top: 14px;
}
.sidebar-role .role-badge {
    background: <?= $is_admin ? '#00c9a7' : '#f59e0b' ?>;
    color: #111;
    border-radius: 4px;
    padding: 1px 6px;
    font-size: .65rem;
}
.nav-section{padding:14px 16px 4px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28)}
.nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,.55);text-decoration:none;font-size:.84rem;font-weight:500;transition:all .18s;position:relative}
.nav-item:hover{color:#fff;background:rgba(255,255,255,.05)}
.nav-item.active{color:#00c9a7;background:rgba(0,201,167,.08)}
.nav-item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:#00c9a7;border-radius:0 3px 3px 0}
.nav-item i{font-size:.95rem;width:18px;text-align:center}
.sidebar-footer{margin-top:auto;padding:14px;border-top:1px solid rgba(255,255,255,.07)}
.admin-chip{display:flex;align-items:center;gap:10px;padding:9px 12px;background:rgba(255,255,255,.06);border-radius:10px}
.admin-avatar{width:32px;height:32px;border-radius:50%;background:#00c9a7;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700;color:#111;font-size:.9rem;flex-shrink:0}
.admin-info .name{font-size:.82rem;font-weight:700;color:#fff}
.admin-info .role{font-size:.68rem;color:rgba(255,255,255,.4);text-transform:capitalize}
.main{margin-left:230px;flex:1;min-width:0}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;flex-wrap:wrap;gap:10px}
.topbar h1{font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800}
.topbar p{font-size:.78rem;color:var(--slate);margin-top:1px}
.content{padding:24px 32px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .2s;text-decoration:none;white-space:nowrap}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--slate)}.btn-ghost:hover{border-color:var(--ink);color:var(--ink)}
.filter-bar{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.filter-bar select,.filter-bar input{border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:.8rem;font-family:'Lato',sans-serif;color:var(--ink);background:var(--bg);outline:none;cursor:pointer}
.filter-bar select:focus,.filter-bar input:focus{border-color:var(--teal)}
.search-wrap{position:relative;flex:1;min-width:200px}
.search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--slate);font-size:.85rem;pointer-events:none}
.search-wrap input{width:100%;padding-left:32px}
.alert{border-radius:var(--radius);padding:11px 15px;margin-bottom:16px;display:flex;align-items:center;gap:9px;font-size:.84rem}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:var(--red)}

/* PATIENT GRID */
.patient-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.pat-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow);transition:all .2s}
.pat-card:hover{border-color:rgba(0,0,0,.12);box-shadow:0 4px 20px rgba(0,0,0,.08);transform:translateY(-2px)}
.pat-card-top{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.pat-avatar{width:44px;height:44px;border-radius:50%;background:var(--teal);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;color:#fff;font-size:1rem;flex-shrink:0;overflow:hidden}
.pat-avatar img{width:100%;height:100%;object-fit:cover}
.pat-name{font-family:'Syne',sans-serif;font-size:.92rem;font-weight:700;color:var(--ink);margin-bottom:1px}
.pat-email{font-size:.74rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px}
.pat-stats{display:flex;gap:10px;margin-bottom:14px}
.pat-stat{flex:1;background:var(--bg);border-radius:8px;padding:7px 10px;text-align:center}
.pat-stat-val{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:var(--teal)}
.pat-stat-lbl{font-size:.62rem;color:var(--muted);margin-top:1px}
.pat-meta{display:flex;flex-direction:column;gap:4px;margin-bottom:14px}
.pat-meta-row{display:flex;align-items:center;gap:6px;font-size:.76rem;color:var(--slate)}
.pat-meta-row i{font-size:.7rem;color:var(--teal);width:12px;text-align:center;flex-shrink:0}
.allergy-tag{display:inline-flex;align-items:center;gap:3px;background:#fef2f2;color:var(--red);border:1px solid #fecaca;border-radius:6px;padding:2px 8px;font-size:.67rem;font-weight:700;margin-top:2px}
.pat-actions{display:flex;gap:6px;padding-top:12px;border-top:1px solid var(--border)}
.pa{flex:1;display:flex;align-items:center;justify-content:center;gap:5px;padding:7px;border-radius:8px;border:none;cursor:pointer;font-size:.75rem;font-weight:700;font-family:'Lato',sans-serif;transition:all .15s;text-decoration:none}
.pa-appts{background:var(--teal-lt);color:var(--teal)}.pa-appts:hover{background:var(--teal);color:#fff}
.pa-delete{background:#fef2f2;color:var(--red)}.pa-delete:hover{background:var(--red);color:#fff}
.empty-state{grid-column:1/-1;text-align:center;padding:56px 20px;color:var(--muted)}
.empty-state i{font-size:3rem;display:block;margin-bottom:12px;opacity:.25}
.empty-state p{font-size:.84rem}

@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:16px}}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-logo"><i class="bi bi-tooth"></i> Vytal Dental</div>
        <div class="sidebar-role">
        <span>Logged in as</span>
        <span class="role-badge"><?= strtoupper($admin_role) ?></span>
    </div>
    <div class="nav-section">Main</div>
    <a href="dashboard.php"            class="nav-item"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="manage_appointments.php"  class="nav-item"><i class="bi bi-calendar-check"></i> Appointments</a>
    <a href="manage_patients.php"      class="nav-item active"><i class="bi bi-people"></i> Patients</a>
    <?php if ($is_admin): ?>
    <a href="manage_doctors.php"       class="nav-item"><i class="bi bi-person-badge"></i> Dentists</a>
    <a href="manage_services.php"      class="nav-item"><i class="bi bi-tooth"></i> Services</a>
    <?php endif; ?>
    <div class="nav-section">Analytics</div>
    <a href="patient_flow.php"         class="nav-item"><i class="bi bi-bar-chart-line"></i> Patient Flow</a>
    <a href="reports.php"              class="nav-item"><i class="bi bi-file-earmark-text"></i> Reports</a>
    <a href="attendance_report.php"    class="nav-item"><i class="bi bi-person-check"></i> Attendance</a>
    <a href="activity_logs.php"        class="nav-item"><i class="bi bi-clipboard-data"></i> Activity Logs</a>
    <a href="accessibility_report.php" class="nav-item"><i class="bi bi-universal-access"></i> Accessibility</a>
    <div class="nav-section">Account</div>
    <a href="logout.php" class="nav-item" style="color:rgba(255,100,100,.6)"><i class="bi bi-box-arrow-right"></i> Logout</a>
    <div class="sidebar-footer">
        <div class="admin-chip">
            <div class="admin-avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div>
            <div class="admin-info"><div class="name"><?= htmlspecialchars($admin_name) ?></div><div class="role"><?= $admin_role ?></div></div>
        </div>
    </div>
</aside>

<div class="main">
    <div class="topbar">
        <div>
            <h1>Manage Patients</h1>
            <p><?= $total_patients ?> patient<?= $total_patients!==1?'s':'' ?> registered</p>
        </div>
    </div>

    <div class="content">
        <?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-error"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="GET" class="filter-bar">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="q" placeholder="Search name, email, phone…" value="<?= htmlspecialchars($q) ?>">
            </div>
            <select name="gender" onchange="this.form.submit()">
                <option value="">All Genders</option>
                <option value="Male"   <?= $gf==='Male'  ?'selected':'' ?>>Male</option>
                <option value="Female" <?= $gf==='Female'?'selected':'' ?>>Female</option>
                <option value="Other"  <?= $gf==='Other' ?'selected':'' ?>>Other</option>
            </select>
            <select name="sort" onchange="this.form.submit()">
                <option value="name"       <?= $sf==='name'      ?'selected':'' ?>>Sort: Name</option>
                <option value="created_at" <?= $sf==='created_at'?'selected':'' ?>>Sort: Newest</option>
                <option value="email"      <?= $sf==='email'     ?'selected':'' ?>>Sort: Email</option>
            </select>
            <button type="submit" class="btn btn-ghost"><i class="bi bi-search"></i> Search</button>
            <?php if ($q || $gf): ?><a href="manage_patients.php" class="btn btn-ghost">Clear</a><?php endif; ?>
        </form>

        <div class="patient-grid">
            <?php if (empty($patients)): ?>
            <div class="empty-state"><i class="bi bi-people"></i><p>No patients found<?= $q?" matching \"$q\"":'' ?>.</p></div>
            <?php else: foreach ($patients as $p):
                $avatar_url = ($p['profile_picture'] ?? null) ? "/uploads/profile_pictures/{$p['profile_picture']}" : null;
                $age = $p['birthdate'] ? (int)date_diff(date_create($p['birthdate']),date_create('today'))->y : null;
            ?>
            <div class="pat-card">
                <div class="pat-card-top">
                    <div class="pat-avatar">
                        <?php if ($avatar_url): ?><img src="<?= htmlspecialchars($avatar_url) ?>" alt=""><?php else: ?><?= strtoupper(substr($p['name'],0,1)) ?><?php endif; ?>
                    </div>
                    <div style="min-width:0">
                        <div class="pat-name"><?= htmlspecialchars($p['name']) ?></div>
                        <div class="pat-email"><?= htmlspecialchars($p['email']) ?></div>
                    </div>
                </div>

                <div class="pat-stats">
                    <div class="pat-stat"><div class="pat-stat-val"><?= $p['appt_count'] ?></div><div class="pat-stat-lbl">Appointments</div></div>
                    <div class="pat-stat"><div class="pat-stat-val"><?= $p['completed_count'] ?></div><div class="pat-stat-lbl">Completed</div></div>
                    <?php if ($age): ?><div class="pat-stat"><div class="pat-stat-val"><?= $age ?></div><div class="pat-stat-lbl">Age</div></div><?php endif; ?>
                </div>

                <div class="pat-meta">
                    <?php if ($p['phone']): ?><div class="pat-meta-row"><i class="bi bi-telephone"></i><?= htmlspecialchars($p['phone']) ?></div><?php endif; ?>
                    <?php if ($p['gender']): ?><div class="pat-meta-row"><i class="bi bi-gender-ambiguous"></i><?= htmlspecialchars($p['gender']) ?></div><?php endif; ?>
                    <div class="pat-meta-row"><i class="bi bi-calendar-plus"></i>Joined <?= date('M Y', strtotime($p['created_at'])) ?></div>
                    <?php if ($p['allergies']): ?>
                    <div style="margin-top:2px"><span class="allergy-tag"><i class="bi bi-exclamation-triangle"></i><?= htmlspecialchars(mb_substr($p['allergies'],0,40)) ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="pat-actions">
                    <a href="manage_appointments.php?q=<?= urlencode($p['name']) ?>" class="pa pa-appts">
                        <i class="bi bi-calendar-check"></i> Appointments
                    </a>
                    <?php if ($is_admin): ?>
                    <a href="?delete=<?= $p['id'] ?>" class="pa pa-delete"
                       onclick="return confirm('Delete <?= htmlspecialchars(addslashes($p['name'])) ?>? This cannot be undone.')">
                        <i class="bi bi-trash"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
</body>
</html>