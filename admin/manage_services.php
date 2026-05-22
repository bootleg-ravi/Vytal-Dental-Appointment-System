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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $name  = trim($_POST['name']        ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $cat   = trim($_POST['category']    ?? '');
    $price = $_POST['price'] !== '' ? floatval($_POST['price']) : null;
    $dur   = $_POST['duration_minutes'] !== '' ? (int)$_POST['duration_minutes'] : null;
    if ($name) {
        $stmt = $conn->prepare("INSERT INTO services (name,description,price,category,duration_minutes,is_active) VALUES (?,?,?,?,?,1)");
        $stmt->bind_param("ssdsi", $name,$desc,$price,$cat,$dur);
        $stmt->execute(); $stmt->close();
        header("Location: manage_services.php?success=" . urlencode("Service added!")); exit;
    } else $error = "Service name is required.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_service'])) {
    $id    = (int)$_POST['service_id'];
    $name  = trim($_POST['name']        ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $cat   = trim($_POST['category']    ?? '');
    $price = $_POST['price'] !== '' ? floatval($_POST['price']) : null;
    $dur   = $_POST['duration_minutes'] !== '' ? (int)$_POST['duration_minutes'] : null;
    $act   = isset($_POST['is_active']) ? 1 : 0;
    if ($name) {
        $stmt = $conn->prepare("UPDATE services SET name=?,description=?,price=?,category=?,duration_minutes=?,is_active=? WHERE id=?");
        $stmt->bind_param("ssdsiii", $name,$desc,$price,$cat,$dur,$act,$id);
        $stmt->execute(); $stmt->close();
        header("Location: manage_services.php?success=" . urlencode("Service updated!")); exit;
    } else $error = "Service name is required.";
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $conn->query("DELETE FROM services WHERE id=" . (int)$_GET['delete']);
    header("Location: manage_services.php?success=" . urlencode("Service removed.")); exit;
}

$services = $conn->query("SELECT s.*,
    (SELECT COUNT(*) FROM appointments a WHERE a.service_id=s.id) AS usage_count
    FROM services s ORDER BY s.category ASC, s.name ASC")->fetch_all(MYSQLI_ASSOC);

$categories = ['Preventive','Restorative','Cosmetic','Orthodontics','Oral Surgery','Endodontics','Emergency','Consultation'];
$conn->close();

// Group by category
$grouped = [];
foreach ($services as $s) {
    $cat = $s['category'] ?: 'Uncategorized';
    $grouped[$cat][] = $s;
}

$cat_icons = ['Preventive'=>'bi-shield-check','Restorative'=>'bi-tools','Cosmetic'=>'bi-stars','Orthodontics'=>'bi-arrows-angle-expand','Oral Surgery'=>'bi-scissors','Endodontics'=>'bi-activity','Emergency'=>'bi-lightning','Consultation'=>'bi-chat-dots','Uncategorized'=>'bi-grid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Manage Services – Vytal Dental</title>
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
.alert{border-radius:var(--radius);padding:11px 15px;margin-bottom:16px;display:flex;align-items:center;gap:9px;font-size:.84rem}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:var(--red)}

/* CATEGORY SECTION */
.cat-section{margin-bottom:28px}
.cat-header{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.cat-icon{width:32px;height:32px;border-radius:8px;background:var(--ink);display:flex;align-items:center;justify-content:center;font-size:.85rem;color:#00c9a7}
.cat-title{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:700;color:var(--ink)}
.cat-count{font-size:.72rem;color:var(--muted);margin-left:4px}
.service-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}

/* SERVICE CARD */
.svc-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:16px;box-shadow:var(--shadow);transition:all .2s}
.svc-card:hover{border-color:rgba(0,0,0,.12);transform:translateY(-1px);box-shadow:0 4px 16px rgba(0,0,0,.07)}
.svc-card.inactive{opacity:.55}
.svc-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;gap:8px}
.svc-name{font-family:'Syne',sans-serif;font-size:.9rem;font-weight:700;color:var(--ink)}
.svc-active-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;margin-top:4px}
.dot-on{background:var(--green)}.dot-off{background:var(--muted)}
.svc-desc{font-size:.76rem;color:var(--slate);line-height:1.5;margin-bottom:10px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.svc-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700}
.chip-price{background:var(--teal-lt);color:var(--teal)}
.chip-dur  {background:#eff6ff;color:#2563eb}
.chip-use  {background:#f9fafb;color:var(--slate)}
.svc-actions{display:flex;gap:6px;padding-top:10px;border-top:1px solid var(--border)}
.sa{flex:1;display:flex;align-items:center;justify-content:center;gap:4px;padding:6px;border-radius:7px;border:none;cursor:pointer;font-size:.74rem;font-weight:700;font-family:'Lato',sans-serif;transition:all .15s;text-decoration:none}
.sa-edit{background:var(--teal-lt);color:var(--teal)}.sa-edit:hover{background:var(--teal);color:#fff}
.sa-delete{background:#fef2f2;color:var(--red)}.sa-delete:hover{background:var(--red);color:#fff}

/* DRAWER */
.drawer-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200}
.drawer-overlay.open{display:block}
.drawer{position:fixed;top:0;right:-480px;width:440px;max-width:95vw;height:100vh;background:var(--bg2);box-shadow:-8px 0 40px rgba(0,0,0,.15);z-index:201;display:flex;flex-direction:column;transition:right .3s cubic-bezier(.4,0,.2,1)}
.drawer.open{right:0}
.drawer-hdr{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.drawer-hdr h2{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800}
.drawer-close{background:var(--bg);border:1px solid var(--border);border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;color:var(--slate)}
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
.btn-save{flex:1;padding:11px;background:var(--teal);color:#fff;border:none;border-radius:10px;font-size:.88rem;font-weight:700;font-family:'Lato',sans-serif;cursor:pointer}
.btn-save:hover{background:var(--teal2)}
.btn-cancel-d{padding:11px 18px;border:1px solid var(--border);border-radius:10px;background:transparent;font-size:.88rem;font-weight:700;font-family:'Lato',sans-serif;color:var(--slate);cursor:pointer}
.btn-cancel-d:hover{border-color:var(--ink);color:var(--ink)}

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
    <a href="manage_doctors.php"       class="nav-item"><i class="bi bi-person-badge"></i> Dentists</a>
    <a href="manage_services.php"      class="nav-item active"><i class="bi bi-tooth"></i> Services</a>
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
            <h1>Manage Services</h1>
            <p><?= count($services) ?> service<?= count($services)!==1?'s':'' ?> across <?= count($grouped) ?> categories</p>
        </div>
        <button class="btn btn-teal" onclick="openDrawer('add')"><i class="bi bi-plus-lg"></i> Add Service</button>
    </div>

    <div class="content">
        <?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-error"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if (empty($grouped)): ?>
        <div style="text-align:center;padding:56px 20px;color:var(--muted)">
            <i class="bi bi-tooth" style="font-size:3rem;display:block;margin-bottom:12px;opacity:.25"></i>
            <p>No services yet. Add your first one.</p>
        </div>
        <?php else: foreach ($grouped as $cat => $svcs): ?>
        <div class="cat-section">
            <div class="cat-header">
                <div class="cat-icon"><i class="bi <?= $cat_icons[$cat] ?? 'bi-grid' ?>"></i></div>
                <span class="cat-title"><?= htmlspecialchars($cat) ?></span>
                <span class="cat-count"><?= count($svcs) ?> service<?= count($svcs)!==1?'s':'' ?></span>
            </div>
            <div class="service-grid">
                <?php foreach ($svcs as $s): ?>
                <div class="svc-card <?= $s['is_active']?'':'inactive' ?>">
                    <div class="svc-top">
                        <div class="svc-name"><?= htmlspecialchars($s['name']) ?></div>
                        <div class="svc-active-dot <?= $s['is_active']?'dot-on':'dot-off' ?>" title="<?= $s['is_active']?'Active':'Inactive' ?>"></div>
                    </div>
                    <?php if ($s['description']): ?>
                    <div class="svc-desc"><?= htmlspecialchars($s['description']) ?></div>
                    <?php endif; ?>
                    <div class="svc-chips">
                        <?php if ($s['price']): ?><span class="chip chip-price"><i class="bi bi-cash"></i>₱<?= number_format($s['price']) ?></span><?php endif; ?>
                        <?php if ($s['duration_minutes']): ?><span class="chip chip-dur"><i class="bi bi-clock"></i><?= $s['duration_minutes'] ?>min</span><?php endif; ?>
                        <span class="chip chip-use"><i class="bi bi-bar-chart"></i><?= $s['usage_count'] ?> bookings</span>
                    </div>
                    <div class="svc-actions">
                        <button class="sa sa-edit" onclick='openDrawer("edit",<?= json_encode($s) ?>)'>
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <a href="?delete=<?= $s['id'] ?>" class="sa sa-delete"
                           onclick="return confirm('Delete <?= htmlspecialchars(addslashes($s['name'])) ?>?')">
                            <i class="bi bi-trash"></i> Delete
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="drawer">
    <div class="drawer-hdr">
        <h2 id="drawerTitle">Add Service</h2>
        <button class="drawer-close" onclick="closeDrawer()"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" id="drawerForm">
        <input type="hidden" name="add_service" id="formMode" value="1">
        <input type="hidden" name="service_id"  id="formSvcId" value="">
        <div class="drawer-body">
            <div class="field">
                <label>Service Name</label>
                <input type="text" name="name" id="fName" placeholder="e.g. Dental Check-up" required>
            </div>
            <div class="field">
                <label>Category <span class="opt">(optional)</span></label>
                <select name="category" id="fCat">
                    <option value="">None</option>
                    <?php foreach ($categories as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="grid-2">
                <div class="field">
                    <label>Price (₱) <span class="opt">(optional)</span></label>
                    <input type="number" name="price" id="fPrice" placeholder="500" min="0" step="0.01">
                </div>
                <div class="field">
                    <label>Duration (min) <span class="opt">(optional)</span></label>
                    <input type="number" name="duration_minutes" id="fDur" placeholder="30" min="5" step="5">
                </div>
            </div>
            <div class="field">
                <label>Description <span class="opt">(optional)</span></label>
                <textarea name="description" id="fDesc" placeholder="Brief description shown to patients…"></textarea>
            </div>
            <div class="field" id="activeField" style="display:none">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="is_active" id="fActive" value="1" style="width:auto;padding:0"> Active (visible to patients)
                </label>
            </div>
        </div>
        <div class="drawer-footer">
            <button type="submit" class="btn-save" id="drawerSaveBtn">Add Service</button>
            <button type="button" class="btn-cancel-d" onclick="closeDrawer()">Cancel</button>
        </div>
    </form>
</div>

<script>
function openDrawer(mode, svc) {
    document.getElementById('drawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('open');
    if (mode === 'add') {
        document.getElementById('drawerTitle').textContent = 'Add Service';
        document.getElementById('drawerSaveBtn').textContent = 'Add Service';
        document.getElementById('formMode').name = 'add_service';
        ['fName','fPrice','fDur','fDesc'].forEach(id => document.getElementById(id).value = '');
        document.getElementById('fCat').value = '';
        document.getElementById('activeField').style.display = 'none';
    } else {
        document.getElementById('drawerTitle').textContent = 'Edit Service';
        document.getElementById('drawerSaveBtn').textContent = 'Save Changes';
        document.getElementById('formMode').name = 'edit_service';
        document.getElementById('formSvcId').value = svc.id;
        document.getElementById('fName').value  = svc.name || '';
        document.getElementById('fCat').value   = svc.category || '';
        document.getElementById('fPrice').value = svc.price || '';
        document.getElementById('fDur').value   = svc.duration_minutes || '';
        document.getElementById('fDesc').value  = svc.description || '';
        document.getElementById('fActive').checked = svc.is_active == 1;
        document.getElementById('activeField').style.display = 'block';
    }
}
function closeDrawer() {
    document.getElementById('drawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
}
</script>
</body>
</html>