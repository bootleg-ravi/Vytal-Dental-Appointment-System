<?php
session_start();
if (!isset($_SESSION['patient_id'])) { header('Location: login.php'); exit; }
require_once '../config/config.php';

$patient_id   = $_SESSION['patient_id'];
$patient_name = $_SESSION['patient_name'] ?? 'Patient';
$error = $success = '';

if (isset($_GET['update']) && $_GET['update'] == 1) $success = 'Profile updated successfully!';

$stmt = $conn->prepare("SELECT name,email,phone,address,birthdate,gender,profile_picture,created_at,allergies,dental_notes,emergency_contact_name,emergency_contact_phone FROM patients WHERE id=? LIMIT 1");
$stmt->bind_param('i', $patient_id); $stmt->execute();
$profile = $stmt->get_result()->fetch_assoc(); $stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $ext  = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $size = $_FILES['profile_picture']['size'];
        if (!in_array($ext, ['jpg','jpeg','png']))  { $error = "JPG or PNG only."; }
        elseif ($size > 2*1024*1024)                { $error = "Max file size is 2MB."; }
        else {
            $fn   = "patient_{$patient_id}_" . uniqid() . ".$ext";
            $dest = "../uploads/profile_pictures/$fn";
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0775, true);
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dest)) {
                $s = $conn->prepare("UPDATE patients SET profile_picture=? WHERE id=?");
                $s->bind_param('si', $fn, $patient_id); $s->execute(); $s->close();
                $profile['profile_picture'] = $fn;
                $success = "Profile picture updated!";
            } else { $error = "Upload failed. Try again."; }
        }
    } else { $error = "Please select a file."; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['upload_avatar'])) {
    $name  = trim($_POST['name']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $addr  = trim($_POST['address'] ?? '');
    $dob   = trim($_POST['birthdate'] ?? '');
    $gen   = $_POST['gender'] ?? '';
    $allg  = trim($_POST['allergies'] ?? '');
    $notes = trim($_POST['dental_notes'] ?? '');
    $ec_n  = trim($_POST['emergency_contact_name']  ?? '');
    $ec_p  = trim($_POST['emergency_contact_phone'] ?? '');

    if (!$name || !$email) { $error = "Name and email are required."; }
    else {
        $stmt = $conn->prepare("UPDATE patients SET name=?,email=?,phone=?,address=?,birthdate=?,gender=?,allergies=?,dental_notes=?,emergency_contact_name=?,emergency_contact_phone=? WHERE id=?");
        $dob_val = $dob ?: null;
        $stmt->bind_param('ssssssssssi', $name,$email,$phone,$addr,$dob_val,$gen,$allg,$notes,$ec_n,$ec_p,$patient_id);
        if ($stmt->execute()) {
            $_SESSION['patient_name'] = $name; $patient_name = $name;
            header('Location: profile.php?update=1'); exit;
        } else { $error = "Update failed. Try again."; }
        $stmt->close();
    }
}

$unread          = (int)$conn->query("SELECT COUNT(*) AS c FROM notifications WHERE patient_id=$patient_id AND is_read=0")->fetch_assoc()['c'];
$appt_count      = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments WHERE patient_id=$patient_id")->fetch_assoc()['c'];
$completed_count = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments WHERE patient_id=$patient_id AND status='completed'")->fetch_assoc()['c'];
$conn->close();

$avatar_url = $profile['profile_picture'] ? "/uploads/profile_pictures/{$profile['profile_picture']}" : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>My Profile – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--bg:#0e1621;--bg2:#151f2e;--bg3:#1c2a3e;--surface:#1e2d42;--border:rgba(255,255,255,.08);--teal:#00c9a7;--teal2:#00a88d;--red:#f87171;--slate:#94a3b8;--text:#e2e8f0;--green:#34d399;--blue:#60a5fa;--muted:#64748b;--radius:12px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:'Lato',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}

.sidebar{width:220px;background:var(--bg2);min-height:100vh;position:fixed;left:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100;border-right:1px solid var(--border)}
.sidebar-logo{padding:20px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px;font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:#fff}
.sidebar-logo i{color:var(--teal);font-size:1.2rem}
.nav-section{padding:14px 16px 4px;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.2)}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 18px;color:var(--slate);text-decoration:none;font-size:.82rem;font-weight:500;transition:all .15s;position:relative}
.nav-item i{font-size:.9rem;width:16px;text-align:center}
.nav-item:hover{color:var(--text);background:rgba(255,255,255,.04)}
.nav-item.active{color:var(--teal);background:rgba(0,201,167,.08)}
.nav-item.active::before{content:'';position:absolute;left:0;top:15%;bottom:15%;width:3px;background:var(--teal);border-radius:0 2px 2px 0}
.nav-badge{margin-left:auto;background:#ef4444;color:#fff;font-size:.58rem;font-weight:700;padding:1px 5px;border-radius:20px}
.nav-divider{margin:6px 14px;border:none;border-top:1px solid var(--border)}
.nav-danger{color:rgba(248,113,113,.5)}
.nav-danger:hover{color:var(--red)!important;background:rgba(248,113,113,.07)!important}
.sidebar-footer{margin-top:auto;padding:12px;border-top:1px solid var(--border)}
.user-chip{display:flex;align-items:center;gap:9px;padding:8px 10px;background:rgba(255,255,255,.04);border-radius:8px;border:1px solid var(--border)}
.user-avatar{width:30px;height:30px;border-radius:50%;background:var(--teal);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;color:#0e1621;font-size:.8rem;flex-shrink:0;overflow:hidden}
.user-avatar img{width:100%;height:100%;object-fit:cover}
.u-name{font-size:.78rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.u-role{font-size:.65rem;color:var(--muted)}

.main{margin-left:220px;flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 28px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-title{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;color:var(--text)}
.topbar-sub{font-size:.72rem;color:var(--muted)}
.content{padding:24px 28px;max-width:860px}

.profile-hero{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:24px;display:flex;align-items:center;gap:22px;margin-bottom:20px;flex-wrap:wrap}
.avatar-wrap{position:relative;flex-shrink:0}
.avatar-img{width:84px;height:84px;border-radius:50%;object-fit:cover;border:3px solid var(--teal)}
.avatar-initials{width:84px;height:84px;border-radius:50%;background:rgba(0,201,167,.15);border:3px solid var(--teal);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:var(--teal)}
.avatar-edit{position:absolute;bottom:0;right:0;width:26px;height:26px;border-radius:50%;background:var(--bg3);border:2px solid var(--bg2);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--slate);font-size:.72rem;transition:all .15s}
.avatar-edit:hover{background:var(--teal);color:#0e1621}
.profile-meta{flex:1;min-width:0}
.profile-meta h2{font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800;color:#fff;margin-bottom:2px}
.profile-meta p{font-size:.82rem;color:var(--slate)}
.profile-stats{display:flex;gap:22px;margin-top:14px;flex-wrap:wrap}
.pstat-val{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--teal)}
.pstat-lbl{font-size:.68rem;color:var(--slate)}
.allergy-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(248,113,113,.1);color:var(--red);border:1px solid rgba(248,113,113,.2);border-radius:6px;padding:3px 9px;font-size:.74rem;font-weight:600;margin-top:6px}

.panel{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:18px}
.panel-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.panel-header h3{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:7px}
.panel-header h3 i{color:var(--teal)}
.panel-body{padding:20px}
.section-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--slate);margin:20px 0 14px;padding-top:16px;border-top:1px solid var(--border)}
.section-label:first-child{margin-top:0;padding-top:0;border-top:none}

.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.field{margin-bottom:0}
.field label{display:block;font-size:.76rem;font-weight:700;color:var(--slate);margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em}
.field label .opt{font-weight:400;color:var(--muted);font-size:.7rem;text-transform:none}
.input-wrap{position:relative}
.input-wrap i.ico{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.82rem;pointer-events:none}
.input-wrap input,.input-wrap select,.input-wrap textarea{width:100%;padding:9px 11px 9px 34px;border:1.5px solid var(--border);border-radius:9px;font-size:.845rem;font-family:'Lato',sans-serif;color:var(--text);background:var(--bg3);outline:none;transition:border-color .18s,box-shadow .18s}
.input-wrap textarea{padding-top:9px;padding-bottom:9px;resize:vertical;min-height:72px}
.input-wrap input:focus,.input-wrap select:focus,.input-wrap textarea:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(0,201,167,.1)}
.input-wrap select option{background:var(--bg2);color:var(--text)}
.no-ico input,.no-ico select,.no-ico textarea{padding-left:11px}

.alert{border-radius:var(--radius);padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:8px;font-size:.82rem;border:1px solid}
.alert-success{background:rgba(52,211,153,.08);border-color:rgba(52,211,153,.2);color:var(--green)}
.alert-error  {background:rgba(248,113,113,.08);border-color:rgba(248,113,113,.2);color:var(--red)}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border-radius:25px;border:none;font-family:'Lato',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none}
.btn-teal{background:var(--teal);color:#0e1621}
.btn-teal:hover{background:var(--teal2)}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--slate)}
.btn-ghost:hover{border-color:rgba(255,255,255,.2);color:var(--text)}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:200;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:26px;max-width:360px;width:90%}
.modal h3{font-family:'Syne',sans-serif;font-weight:800;color:#fff;margin-bottom:6px}
.modal p{font-size:.82rem;color:var(--slate);margin-bottom:16px}
.file-drop{border:2px dashed var(--border);border-radius:9px;padding:24px;text-align:center;cursor:pointer;transition:border-color .2s}
.file-drop:hover{border-color:var(--teal);background:rgba(0,201,167,.05)}
.file-drop i{font-size:1.8rem;color:var(--teal);display:block;margin-bottom:8px}
.file-drop p{font-size:.8rem;color:var(--slate)}
.file-drop input{display:none}

@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.content{padding:14px}}
@media(max-width:600px){.grid-2{grid-template-columns:1fr}}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
  <div class="nav-section">Menu</div>
  <a href="dashboard.php"             class="nav-item"><i class="bi bi-house-fill"></i> Dashboard</a>
  <a href="book_appointment.php"      class="nav-item"><i class="bi bi-calendar-plus"></i> Book Appointment</a>
  <a href="appointments_calendar.php" class="nav-item"><i class="bi bi-calendar3"></i> Calendar</a>
  <a href="appointments.php"          class="nav-item"><i class="bi bi-list-check"></i> My Appointments</a>
  <a href="notifications.php"         class="nav-item">
    <i class="bi bi-bell"></i> Notifications
    <?php if ($unread > 0): ?><span class="nav-badge"><?= $unread ?></span><?php endif; ?>
  </a>
  <a href="profile.php" class="nav-item active"><i class="bi bi-person"></i> Profile</a>
  <hr class="nav-divider">
  <a href="logout.php" class="nav-item nav-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar">
        <?php if ($avatar_url): ?><img src="<?= htmlspecialchars($avatar_url) ?>" alt=""><?php else: ?><?= strtoupper(substr($patient_name,0,1)) ?><?php endif; ?>
      </div>
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
      <div class="topbar-title">My Profile</div>
      <div class="topbar-sub">Manage your personal information and preferences</div>
    </div>
  </div>

  <div class="content">
    <?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="profile-hero">
      <div class="avatar-wrap">
        <?php if ($avatar_url): ?>
        <img src="<?= htmlspecialchars($avatar_url) ?>" alt="Avatar" class="avatar-img">
        <?php else: ?>
        <div class="avatar-initials"><?= strtoupper(substr($profile['name'],0,1)) ?></div>
        <?php endif; ?>
        <div class="avatar-edit" onclick="document.getElementById('avatarModal').classList.add('open')" title="Change photo">
          <i class="bi bi-camera"></i>
        </div>
      </div>
      <div class="profile-meta">
        <h2><?= htmlspecialchars($profile['name']) ?></h2>
        <p><?= htmlspecialchars($profile['email']) ?></p>
        <?php if ($profile['allergies']): ?>
        <div><span class="allergy-badge"><i class="bi bi-exclamation-triangle"></i> Allergies: <?= htmlspecialchars(mb_substr($profile['allergies'],0,60)) ?></span></div>
        <?php endif; ?>
        <div class="profile-stats">
          <div><div class="pstat-val"><?= $appt_count ?></div><div class="pstat-lbl">Appointments</div></div>
          <div><div class="pstat-val"><?= $completed_count ?></div><div class="pstat-lbl">Completed</div></div>
          <?php if ($profile['created_at']): ?><div><div class="pstat-lbl" style="margin-top:4px">Member since <?= date('M Y', strtotime($profile['created_at'])) ?></div></div><?php endif; ?>
        </div>
      </div>
    </div>

    <form method="POST" action="">
      <div class="panel">
        <div class="panel-header"><h3><i class="bi bi-person"></i> Personal Information</h3></div>
        <div class="panel-body">
          <div class="grid-2">
            <div class="field" style="grid-column:1/-1">
              <label>Full Name</label>
              <div class="input-wrap"><i class="bi bi-person ico"></i><input type="text" name="name" value="<?= htmlspecialchars($profile['name']) ?>" required></div>
            </div>
            <div class="field">
              <label>Email Address</label>
              <div class="input-wrap"><i class="bi bi-envelope ico"></i><input type="email" name="email" value="<?= htmlspecialchars($profile['email']) ?>" required></div>
            </div>
            <div class="field">
              <label>Phone <span class="opt">(optional)</span></label>
              <div class="input-wrap"><i class="bi bi-telephone ico"></i><input type="tel" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>"></div>
            </div>
            <div class="field">
              <label>Date of Birth <span class="opt">(optional)</span></label>
              <div class="input-wrap"><i class="bi bi-calendar ico"></i><input type="date" name="birthdate" value="<?= htmlspecialchars($profile['birthdate'] ?? '') ?>"></div>
            </div>
            <div class="field">
              <label>Gender <span class="opt">(optional)</span></label>
              <div class="input-wrap"><i class="bi bi-gender-ambiguous ico"></i>
                <select name="gender" style="appearance:none">
                  <option value="">Prefer not to say</option>
                  <option value="Male"   <?= ($profile['gender']??'')==='Male'  ?'selected':'' ?>>Male</option>
                  <option value="Female" <?= ($profile['gender']??'')==='Female'?'selected':'' ?>>Female</option>
                  <option value="Other"  <?= ($profile['gender']??'')==='Other' ?'selected':'' ?>>Other</option>
                </select>
              </div>
            </div>
            <div class="field" style="grid-column:1/-1">
              <label>Address <span class="opt">(optional)</span></label>
              <div class="input-wrap"><i class="bi bi-geo-alt ico"></i><input type="text" name="address" value="<?= htmlspecialchars($profile['address'] ?? '') ?>"></div>
            </div>
          </div>

          <div class="section-label">Dental &amp; Medical Information</div>
          <div class="grid-2">
            <div class="field">
              <label>Known Allergies <span class="opt">(medications, materials)</span></label>
              <div class="input-wrap no-ico"><textarea name="allergies" placeholder="e.g. penicillin, latex..."><?= htmlspecialchars($profile['allergies'] ?? '') ?></textarea></div>
            </div>
            <div class="field">
              <label>Dental Notes <span class="opt">(for your dentist)</span></label>
              <div class="input-wrap no-ico"><textarea name="dental_notes" placeholder="e.g. sensitive teeth, dental anxiety..."><?= htmlspecialchars($profile['dental_notes'] ?? '') ?></textarea></div>
            </div>
          </div>

          <div class="section-label">Emergency Contact</div>
          <div class="grid-2">
            <div class="field">
              <label>Contact Name <span class="opt">(optional)</span></label>
              <div class="input-wrap"><i class="bi bi-person-lines-fill ico"></i><input type="text" name="emergency_contact_name" value="<?= htmlspecialchars($profile['emergency_contact_name'] ?? '') ?>"></div>
            </div>
            <div class="field">
              <label>Contact Phone <span class="opt">(optional)</span></label>
              <div class="input-wrap"><i class="bi bi-telephone-fill ico"></i><input type="tel" name="emergency_contact_phone" value="<?= htmlspecialchars($profile['emergency_contact_phone'] ?? '') ?>"></div>
            </div>
          </div>

          <div style="margin-top:22px;display:flex;gap:10px;flex-wrap:wrap">
            <button type="submit" class="btn btn-teal"><i class="bi bi-check-lg"></i> Save Changes</button>
            <a href="edit_profile.php" class="btn btn-ghost"><i class="bi bi-lock"></i> Change Password</a>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="avatarModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <h3>Update Profile Photo</h3>
    <p>JPG or PNG, max 2MB. Appears across your patient portal.</p>
    <form method="POST" action="" enctype="multipart/form-data">
      <input type="hidden" name="upload_avatar" value="1">
      <div class="file-drop" onclick="document.getElementById('avatarFile').click()">
        <i class="bi bi-cloud-upload"></i>
        <p>Click to browse or drag &amp; drop</p>
        <input type="file" name="profile_picture" id="avatarFile" accept="image/jpeg,image/png" onchange="this.form.submit()">
      </div>
    </form>
    <div style="margin-top:12px;text-align:right">
      <button class="btn btn-ghost" onclick="document.getElementById('avatarModal').classList.remove('open')">Cancel</button>
    </div>
  </div>
</div>
</body>
</html>