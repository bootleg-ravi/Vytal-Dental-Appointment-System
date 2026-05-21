<?php
session_start();
if (!isset($_SESSION['patient_id'])) {
    header("Location: login.php");
    exit;
}
include('../config/config.php');
$patient_id = intval($_SESSION['patient_id']);
$err = $success = '';

$stmt = $conn->prepare("SELECT name, email, phone, address, birthdate, gender, profile_picture FROM patients WHERE id=? LIMIT 1");
$stmt->bind_param('i', $patient_id);
$stmt->execute();
$res = $stmt->get_result();
$profile = $res->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar']) && isset($_FILES['profile_picture'])) {
    if ($_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $size = $_FILES['profile_picture']['size'];
        if (!in_array($ext, $allowed)) {
            $err = "Profile picture must be a JPG or PNG file.";
        } elseif ($size > 2*1024*1024) {
            $err = "Profile picture must be less than 2MB.";
        } else {
            $filename = "patient_" . $patient_id . "_" . uniqid() . "." . $ext;
            $destination = "../uploads/profile_pictures/" . $filename;
            if (!file_exists("../uploads/profile_pictures/")) {
                mkdir("../uploads/profile_pictures/", 0775, true);
            }
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destination)) {
                $stmt = $conn->prepare("UPDATE patients SET profile_picture=? WHERE id=?");
                $stmt->bind_param('si', $filename, $patient_id);
                $stmt->execute();
                $stmt->close();
                $profile['profile_picture'] = $filename;
                $success = "Profile picture updated!";
            } else {
                $err = "Failed to save profile picture. Please try again.";
            }
        }
    } else {
        $err = "No profile picture uploaded or upload error.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['upload_avatar'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $gender = $_POST['gender'] ?? '';
    if (!$name || !$email) {
        $err = "Name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = "Invalid email format.";
    } elseif ($gender && !in_array($gender, ['Male', 'Female', 'Other'])) {
        $err = "Invalid gender selected.";
    } elseif ($birthdate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
        $err = "Invalid birthdate.";
    } else {
        $stmt = $conn->prepare("UPDATE patients SET name=?, email=?, phone=?, address=?, birthdate=?, gender=? WHERE id=?");
        $stmt->bind_param('ssssssi', $name, $email, $phone, $address, $birthdate, $gender, $patient_id);
        if ($stmt->execute()) {
            $success = "Profile updated successfully!";
            $_SESSION['patient_name'] = $name;
            header("Location: profile.php?update=1");
            exit;
        } else {
            $err = "Failed to update profile.";
        }
        $stmt->close();
    }
}

$profile = $profile ?? [];
$name = htmlspecialchars($_POST['name'] ?? $profile['name'] ?? '');
$email = htmlspecialchars($_POST['email'] ?? $profile['email'] ?? '');
$phone = htmlspecialchars($_POST['phone'] ?? $profile['phone'] ?? '');
$address = htmlspecialchars($_POST['address'] ?? $profile['address'] ?? '');
$birthdate = htmlspecialchars($_POST['birthdate'] ?? $profile['birthdate'] ?? '');
$gender = htmlspecialchars($_POST['gender'] ?? $profile['gender'] ?? '');
$patient = htmlspecialchars($_SESSION['patient_name'] ?? $_SESSION['patient_username'] ?? 'Patient');
$patient_id = intval($_SESSION['patient_id']);
$stmt = $conn->prepare("SELECT profile_picture FROM patients WHERE id=? LIMIT 1");
$stmt->bind_param('i', $patient_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$sidebar_pic = $row['profile_picture'] ?? '';
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Edit Profile | Hospital Appointment System</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
    <style>
body {
    background: linear-gradient(120deg, #ddefff 0%, #f8fdff 98%);
    min-height: 100vh;
}
.sidebar {
    background: rgba(30,120,210,0.98);
    color: #fff;
    min-height: 100vh;
    padding: 2.3rem 1rem 2rem 1rem;
    border-top-right-radius: 40px;
    box-shadow: 2px 0 24px 0 #52a7e544;
    position: sticky; top: 0; left: 0; z-index: 11;
}
.sidebar .nav-link, .sidebar .action-link {
    color: #fff; font-weight:500; border-radius: 14px; margin: 0.5rem 0;
    display: flex;align-items:center;gap:.9rem; font-size:1.10rem;
    background:rgba(255,255,255,0.07);padding:.72rem .9rem;
    box-shadow:0 1px 4px #0490df14;transition:background .16s;
}
.sidebar .nav-link:hover, .sidebar .action-link:hover {
    background: #fff; color:#206fb7; text-decoration:none;
}
.sidebar .nav-link .bi, .sidebar .action-link .bi {font-size:1.43em;}
.sidebar .badge {margin-left:auto;}
.sidebar-toggler {
    border:none;background:none;color:#fff;font-size:1.4rem;float:right;margin-top:-13px;margin-right:-5px;outline:none;}
.sidebar .avatar { margin: 0 auto 1.1rem auto; width:50px;height:50px;font-size:1.33rem;}
.card-glass {
    background: rgba(255,255,255,0.28);
    box-shadow: 0 6px 32px 0 rgba(44,62,80,0.10), 0 1px 6px 1px #ccd7ec81;
    border-radius: 18px;
    padding: 2.2rem 2.2rem 1.6rem 2.2rem;
    margin: 38px auto;
    border: none;
    backdrop-filter: blur(8px);
    position:relative;
    max-width:520px;
}
.card-glass .card-title { font-size:1.23rem; font-weight:700; color: #0984e3;}
.form-label {font-weight:500; color:#1676ac}
input, select, textarea {border-radius: 12px !important;}
.btn-outline-secondary { border-radius:16px }
.avatar-icon, .profile-pic-thumb {
    width: 90px; height: 90px;
    border-radius: 50%; background: #2196F3; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 2.6rem; font-weight:700; margin:auto 0 16px 0;
    border:4px solid #fff4; box-shadow:0 1px 8px #87baff66;
    object-fit: cover;
}
</style>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
</script>
</head>
<body>
<div class="container-fluid">
  <div class="row flex-nowrap" style="min-height:100vh;">
    <nav id="sidebar" class="sidebar col-auto d-flex flex-column align-items-center align-items-sm-start shadow-lg">
      <button class="sidebar-toggler d-lg-none" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
<?php if (!empty($sidebar_pic) && file_exists("../uploads/profile_pictures/" . $sidebar_pic)): ?>
  <img src="../uploads/profile_pictures/<?= htmlspecialchars($sidebar_pic) ?>" class="profile-pic-thumb shadow avatar-icon mb-2" style="width:55px;height:55px;object-fit:cover;" alt="Avatar">
<?php else: ?>
  <div class="avatar bg-primary text-white d-flex align-items-center justify-content-center fw-bold shadow"><?= strtoupper(substr($patient,0,1)) ?></div>
<?php endif; ?>
      <div class="mb-4 fw-bolder fs-5"><?= $patient ?></div>
      <a href="dashboard.php" class="nav-link"><i class="bi bi-house"></i><span>Dashboard</span></a>
      <a href="book_appointment.php" class="nav-link"><i class="bi bi-clipboard-plus"></i><span>Book Appointment</span></a>
      <a href="profile.php" class="nav-link"><i class="bi bi-person"></i><span>My Profile</span></a>
      <a href="edit_profile.php" class="nav-link"><i class="bi bi-pencil"></i><span>Edit Profile</span></a>
      <a href="support.php" class="nav-link"><i class="bi bi-question-circle"></i><span>Support / Help</span></a>
      <a href="notifications.php" class="action-link">
        <i class="bi bi-bell"></i> <span>Notifications</span>
      </a>
      <a href="logout.php" class="nav-link mt-auto"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </nav>
    <div class="main-content col d-flex justify-content-center align-items-center flex-column">
      <div class="card card-glass shadow border-0">
        <div class="w-100 text-center mb-3">
          <div class="card-title mb-3"><i class="bi bi-pencil"></i> Edit Profile</div>
        </div>
        <?php if($err): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>
        <?php if($success): ?>
          <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form action="edit_profile.php" method="post" enctype="multipart/form-data" class="mb-4">
          <div class="text-center mb-3">
            <?php if (!empty($profile['profile_picture']) && file_exists("../uploads/profile_pictures/" . $profile['profile_picture'])): ?>
              <img src="../uploads/profile_pictures/<?= htmlspecialchars($profile['profile_picture']) ?>" class="profile-pic-thumb shadow mb-2" alt="Profile Picture">
            <?php else: ?>
              <div class="avatar-icon mb-2 shadow"><i class="bi bi-person"></i></div>
            <?php endif; ?>
            <div>
              <input type="file" name="profile_picture" accept="image/*" class="form-control mb-2" style="max-width:340px;display:inline-block;">
              <button type="submit" name="upload_avatar" class="btn btn-primary btn-sm"><i class="bi bi-upload"></i> Upload Picture</button>
            </div>
          </div>
        </form>

        <form action="edit_profile.php" method="post" class="mb-2">
          <div class="mb-3">
            <label class="form-label" for="name">Full Name</label>
            <input type="text" id="name" name="name" class="form-control" required value="<?= $name ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control" required value="<?= $email ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="phone">Phone</label>
            <input type="text" id="phone" name="phone" class="form-control" value="<?= $phone ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="address">Address</label>
            <textarea name="address" id="address" class="form-control" rows="2"><?= $address ?></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label" for="birthdate">Birthdate</label>
            <input type="date" id="birthdate" name="birthdate" class="form-control" value="<?= $birthdate ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="gender">Gender</label>
            <select name="gender" id="gender" class="form-control">
              <option value="">Select…</option>
              <option value="Male" <?= $gender=='Male'?'selected':'' ?>>Male</option>
              <option value="Female" <?= $gender=='Female'?'selected':'' ?>>Female</option>
              <option value="Other" <?= $gender=='Other'?'selected':'' ?>>Other</option>
            </select>
          </div>
          <div class="text-center">
            <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save"></i> Save Changes</button>
            <a href="profile.php" class="btn btn-outline-secondary ms-2"><i class="bi bi-arrow-left"></i> Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<footer class="text-center mt-4 text-muted py-3">
    &copy; 2025 Hospital Appointment System. All rights reserved.
</footer>
<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
