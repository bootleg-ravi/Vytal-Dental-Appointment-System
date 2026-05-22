<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = $_SESSION['admin_role'] ?? 'staff';
$is_admin   = $admin_role === 'admin';
$success    = $_GET['success'] ?? '';
$error      = '';

if (!$is_admin) { header("Location: dashboard.php?error=" . urlencode("Access denied.")); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_doctor'])) {
    $name     = trim($_POST['name'] ?? '');
    $spec     = trim($_POST['specialty'] ?? '');
    $contact  = trim($_POST['contact'] ?? '');
    $bio      = trim($_POST['bio'] ?? '');
    $license  = trim($_POST['license_number'] ?? '');
    $avail    = trim($_POST['availability'] ?? '') ?: null;
    if ($name && $spec) {
        $stmt = $conn->prepare("INSERT INTO doctors (name,specialty,contact,availability,bio,license_number,is_active) VALUES (?,?,?,?,?,?,1)");
        $stmt->bind_param("ssssss", $name,$spec,$contact,$avail,$bio,$license);
        $stmt->execute(); $stmt->close();
        header("Location: manage_doctors.php?success=" . urlencode("Dentist added successfully!")); exit;
    } else { $error = "Name and specialty are required."; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_doctor'])) {
    $id      = (int)$_POST['doctor_id'];
    $name    = trim($_POST['name'] ?? '');
    $spec    = trim($_POST['specialty'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $bio     = trim($_POST['bio'] ?? '');
    $license = trim($_POST['license_number'] ?? '');
    $avail   = trim($_POST['availability'] ?? '') ?: null;
    $active  = isset($_POST['is_active']) ? 1 : 0;
    if ($name && $spec) {
        $stmt = $conn->prepare("UPDATE doctors SET name=?,specialty=?,contact=?,availability=?,bio=?,license_number=?,is_active=? WHERE id=?");
        $stmt->bind_param("ssssssi i", $name,$spec,$contact,$avail,$bio,$license,$active,$id);
        $stmt->execute(); $stmt->close();
        header("Location: manage_doctors.php?success=" . urlencode("Dentist updated!")); exit;
    } else { $error = "Name and specialty are required."; }
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $conn->query("DELETE FROM doctors WHERE id=" . (int)$_GET['delete']);
    header("Location: manage_doctors.php?success=" . urlencode("Dentist removed.")); exit;
}

if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $did = (int)$_GET['toggle'];
    $conn->query("UPDATE doctors SET is_active = NOT is_active WHERE id=$did");
    header("Location: manage_doctors.php?success=" . urlencode("Status updated.")); exit;
}

$doctors = $conn->query("SELECT d.*,
    (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id=d.id) AS total_appts,
    (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id=d.id AND a.date>=CURDATE() AND a.status NOT IN ('cancelled')) AS upcoming_appts
    FROM doctors d ORDER BY d.name ASC")->fetch_all(MYSQLI_ASSOC);

$specialties = ['General Dentistry','Oral Surgery','Orthodontics','Cosmetic Dentistry','Endodontics','Periodontics','Pediatric Dentistry','Prosthodontics'];
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Manage Dentists – Vytal Dental</title>
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
.btn-teal{background:var(--teal);color:#fff}.btn-teal:hover{background:var(--teal2)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--slate)}.btn-ghost:hover{border-color:var(--ink);color:var(--ink)}
.alert{border-radius:var(--radius);padding:11px 15px;margin-bottom:16px;display:flex;align-items:center;gap:9px;font-size:.84rem}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:var(--red)}

.doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px}
.doc-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;transition:all .2s}
.doc-card:hover{border-color:rgba(0,0,0,.12);box-shadow:0 4px 20px rgba(0,0,0,.08);transform:translateY(-2px)}
.doc-card.inactive{opacity:.6}
.doc-card-top{background:var(--ink);padding:20px;display:flex;align-items:center;gap:14px;position:relative}
.doc-initial{width:48px;height:48px;border-radius:50%;background:#00c9a7;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;color:#111;font-size:1.2rem;flex-shrink:0}
.doc-name{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:700;color:#fff}
.doc-spec{font-size:.76rem;color:rgba(255,255,255,.5);margin-top:2px}
.active-badge{position:absolute;top:14px;right:14px;width:8px;height:8px;border-radius:50%}
.active-badge.on{background:#00c9a7;box-shadow:0 0 0 3px rgba(0,201,167,.2)}
.active-badge.off{background:var(--slate)}
.doc-body{padding:16px}
.doc-stats{display:flex;gap:8px;margin-bottom:14px}
.ds{flex:1;background:var(--bg);border-radius:8px;padding:8px;text-align:center}
.ds-val{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:var(--teal)}
.ds-lbl{font-size:.62rem;color:var(--muted)}
.doc-meta{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.doc-meta-row{display:flex;align-items:center;gap:7px;font-size:.77rem;color:var(--slate)}
.doc-meta-row i{font-size:.72rem;color:var(--teal);width:12px;text-align:center}
.doc-bio{font-size:.76rem;color:var(--slate);line-height:1.5;margin-bottom:12px;font-style:italic;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.doc-actions{display:flex;gap:6px;padding-top:12px;border-top:1px solid var(--border)}
.da{flex:1;display:flex;align-items:center;justify-content:center;gap:5px;padding:7px;border-radius:8px;border:none;cursor:pointer;font-size:.75rem;font-weight:700;font-family:'Lato',sans-serif;transition:all .15s;text-decoration:none}
.da-edit{background:var(--teal-lt);color:var(--teal)}.da-edit:hover{background:var(--teal);color:#fff}
.da-toggle{background:#fffbeb;color:var(--amber)}.da-toggle:hover{background:var(--amber);color:#fff}
.da-delete{background:#fef2f2;color:var(--red)}.da-delete:hover{background:var(--red);color:#fff}

.drawer-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200}
.drawer-overlay.open{display:block}
.drawer{position:fixed;top:0;right:-500px;width:460px;max-width:95vw;height:100vh;background:var(--bg2);box-shadow:-8px 0 40px rgba(0,0,0,.15);z-index:201;display:flex;flex-direction:column;transition:right .3s cubic-bezier(.4,0,.2,1)}
.drawer.open{right:0}
.drawer-hdr{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.drawer-hdr h2{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800}
.drawer-close{background:var(--bg);border:1px solid var(--border);border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;color:var(--slate);transition:all .15s}
.drawer-close:hover{background:var(--red);color:#fff;border-color:var(--red)}
.drawer-body{flex:1;overflow-y:auto;padding:24px}
.field{margin-bottom:16px}
.field label{display:block;font-size:.78rem;font-weight:600;color:var(--ink);margin-bottom:5px}
.field label .opt{font-weight:400;color:var(--muted);font-size:.72rem}
.field input,.field select,.field textarea{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px;font-size:.875rem;font-family:'Lato',sans-serif;color:var(--ink);background:#fff;outline:none;transition:border-color .2s,box-shadow .2s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.1)}
.field textarea{resize:vertical;min-height:80px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.drawer-footer{padding:16px 24px;border-top:1px solid var(--border);display:flex;gap:10px;flex-shrink:0}
.btn-save{flex:1;padding:11px;background:var(--teal);color:#fff;border:none;border-radius:10px;font-size:.88rem;font-weight:700;font-family:'Lato',sans-serif;cursor:pointer;transition:all .2s}
.btn-save:hover{background:var(--teal2)}
.btn-cancel-drawer{padding:11px 18px;border:1px solid var(--border);border-radius:10px;background:transparent;font-size:.88rem;font-weight:700;font-family:'Lato',sans-serif;color:var(--slate);cursor:pointer;transition:all .15s}
.btn-cancel-drawer:hover{border-color:var(--ink);color:var(--ink)}
.empty-state{grid-column:1/-1;text-align:center;padding:56px 20px;color:var(--muted)}
.empty-state i{font-size:3rem;display:block;margin-bottom:12px;opacity:.25}

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
    <a href="manage_patients.php"      class="nav-item"><i class="bi bi-people"></i> Patients</a>
    <a href="manage_doctors.php"       class="nav-item active"><i class="bi bi-person-badge"></i> Dentists</a>
    <a href="manage_services.php"      class="nav-item"><i class="bi bi-tooth"></i> Services</a>
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
            <h1>Manage Dentists</h1>
            <p><?= count($doctors) ?> dentist<?= count($doctors)!==1?'s':'' ?> on staff</p>
        </div>
        <button class="btn btn-teal" onclick="openDrawer('add')"><i class="bi bi-plus-lg"></i> Add Dentist</button>
    </div>

    <div class="content">
        <?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-error"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="doc-grid">
            <?php if (empty($doctors)): ?>
            <div class="empty-state"><i class="bi bi-person-badge"></i><p>No dentists yet. Add your first one.</p></div>
            <?php else: foreach ($doctors as $d): ?>
            <div class="doc-card <?= $d['is_active']?'':'inactive' ?>">
                <div class="doc-card-top">
                    <div class="doc-initial"><?= strtoupper(substr($d['name'],0,1)) ?></div>
                    <div>
                        <div class="doc-name"><?= htmlspecialchars($d['name']) ?></div>
                        <div class="doc-spec"><?= htmlspecialchars($d['specialty']) ?></div>
                    </div>
                    <div class="active-badge <?= $d['is_active']?'on':'off' ?>" title="<?= $d['is_active']?'Active':'Inactive' ?>"></div>
                </div>
                <div class="doc-body">
                    <div class="doc-stats">
                        <div class="ds"><div class="ds-val"><?= $d['total_appts'] ?></div><div class="ds-lbl">Total Appts</div></div>
                        <div class="ds"><div class="ds-val"><?= $d['upcoming_appts'] ?></div><div class="ds-lbl">Upcoming</div></div>
                    </div>
                    <div class="doc-meta">
                        <?php if ($d['contact']): ?><div class="doc-meta-row"><i class="bi bi-telephone"></i><?= htmlspecialchars($d['contact']) ?></div><?php endif; ?>
                        <?php if ($d['license_number']??null): ?><div class="doc-meta-row"><i class="bi bi-patch-check"></i>PRC <?= htmlspecialchars($d['license_number']) ?></div><?php endif; ?>
                        <?php if ($d['availability']): ?><div class="doc-meta-row"><i class="bi bi-clock"></i><?= htmlspecialchars($d['availability']) ?></div><?php endif; ?>
                    </div>
                    <?php if ($d['bio']??null): ?><div class="doc-bio"><?= htmlspecialchars($d['bio']) ?></div><?php endif; ?>
                    <div class="doc-actions">
                        <button class="da da-edit" onclick='openDrawer("edit",<?= json_encode($d) ?>)'>
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <a href="?toggle=<?= $d['id'] ?>" class="da da-toggle" title="<?= $d['is_active']?'Deactivate':'Activate' ?>">
                            <i class="bi bi-toggle-<?= $d['is_active']?'on':'off' ?>"></i> <?= $d['is_active']?'Active':'Inactive' ?>
                        </a>
                        <a href="?delete=<?= $d['id'] ?>" class="da da-delete"
                           onclick="return confirm('Remove Dr. <?= htmlspecialchars(addslashes($d['name'])) ?>?')">
                            <i class="bi bi-trash"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<div class="drawer" id="drawer">
    <div class="drawer-hdr">
        <h2 id="drawerTitle">Add Dentist</h2>
        <button class="drawer-close" onclick="closeDrawer()"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="" id="drawerForm">
        <input type="hidden" name="add_doctor" id="formMode" value="1">
        <input type="hidden" name="doctor_id"  id="formDocId" value="">
        <div class="drawer-body">
            <div class="grid-2">
                <div class="field" style="grid-column:1/-1">
                    <label>Full Name</label>
                    <input type="text" name="name" id="fName" placeholder="Dr. Juan dela Cruz" required>
                </div>
                <div class="field">
                    <label>Specialty</label>
                    <select name="specialty" id="fSpec" required>
                        <option value="">Select…</option>
                        <?php foreach ($specialties as $sp): ?>
                        <option value="<?= $sp ?>"><?= $sp ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Contact <span class="opt">(optional)</span></label>
                    <input type="tel" name="contact" id="fContact" placeholder="+63 9XX XXX XXXX">
                </div>
                <div class="field">
                    <label>PRC License No. <span class="opt">(optional)</span></label>
                    <input type="text" name="license_number" id="fLicense" placeholder="0012345">
                </div>
                <div class="field">
                    <label>Availability <span class="opt">(optional)</span></label>
                    <input type="text" name="availability" id="fAvail" placeholder="Mon–Fri, 9am–5pm">
                </div>
            </div>
            <div class="field">
                <label>Bio <span class="opt">(optional)</span></label>
                <textarea name="bio" id="fBio" placeholder="Short professional bio…"></textarea>
            </div>
            <div class="field" id="activeField" style="display:none">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="is_active" id="fActive" value="1" style="width:auto;padding:0"> Active (visible to patients)
                </label>
            </div>
        </div>
        <div class="drawer-footer">
            <button type="submit" class="btn-save" id="drawerSaveBtn">Add Dentist</button>
            <button type="button" class="btn-cancel-drawer" onclick="closeDrawer()">Cancel</button>
        </div>
    </form>
</div>

<script>
function openDrawer(mode, doc) {
    const d = document.getElementById('drawer');
    document.getElementById('drawerOverlay').classList.add('open');
    d.classList.add('open');
    if (mode === 'add') {
        document.getElementById('drawerTitle').textContent = 'Add Dentist';
        document.getElementById('drawerSaveBtn').textContent = 'Add Dentist';
        document.getElementById('formMode').name = 'add_doctor';
        document.getElementById('fName').value = '';
        document.getElementById('fSpec').value = '';
        document.getElementById('fContact').value = '';
        document.getElementById('fLicense').value = '';
        document.getElementById('fAvail').value = '';
        document.getElementById('fBio').value = '';
        document.getElementById('activeField').style.display = 'none';
    } else {
        document.getElementById('drawerTitle').textContent = 'Edit Dentist';
        document.getElementById('drawerSaveBtn').textContent = 'Save Changes';
        document.getElementById('formMode').name = 'edit_doctor';
        document.getElementById('formDocId').value = doc.id;
        document.getElementById('fName').value = doc.name || '';
        document.getElementById('fSpec').value = doc.specialty || '';
        document.getElementById('fContact').value = doc.contact || '';
        document.getElementById('fLicense').value = doc.license_number || '';
        document.getElementById('fAvail').value = doc.availability || '';
        document.getElementById('fBio').value = doc.bio || '';
        document.getElementById('fActive').checked = doc.is_active == 1;
        document.getElementById('activeField').style.display = 'block';
    }
}
function closeDrawer() {
    document.getElementById('drawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
}
<?php if ($error): ?>window.addEventListener('DOMContentLoaded',()=>openDrawer('add'));<?php endif; ?>
</script>
</body>
</html>