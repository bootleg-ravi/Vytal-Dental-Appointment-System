<?php
session_start();
if (isset($_SESSION['patient_id'])) { header('Location: dashboard.php'); exit; }
require_once '../config/config.php';
require_once '../includes/Validator.php';
require_once '../includes/ErrorHandler.php';
require_once '../includes/ActivityLogger.php';

$errorHandler = new ErrorHandler();
$success = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!Validator::verifyCSRFToken($csrf_token)) {
        $errorHandler->addError('csrf', 'Invalid security token. Please refresh and try again.');
    }

    $name             = Validator::sanitizeString($_POST['name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $dob              = trim($_POST['dob'] ?? '');
    $gender           = trim($_POST['gender'] ?? '');
    $address          = Validator::sanitizeString($_POST['address'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $old = compact('name','email','phone','dob','gender','address');

    if (!Validator::validateName($name)['valid']) $errorHandler->addError('name', 'Please enter a valid full name.');
    $ev = Validator::validateEmail($email);
    if (!$ev['valid']) { $errorHandler->addError('email', $ev['message']); }
    else {
        $stmt = $conn->prepare("SELECT id FROM patients WHERE email=?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $errorHandler->addError('email', 'Email already registered.');
        $stmt->close();
    }
    if (!empty($phone) && !Validator::validatePhone($phone)['valid']) $errorHandler->addError('phone', 'Enter a valid phone number.');
    if (!empty($dob)) {
        if (!Validator::validateDate($dob, true)['valid']) $errorHandler->addError('dob', 'Invalid date of birth.');
        elseif (strtotime($dob) > time()) $errorHandler->addError('dob', 'Date of birth cannot be in the future.');
    }
    $pv = Validator::validatePassword($password, 6);
    if (!$pv['valid']) $errorHandler->addError('password', $pv['message']);
    elseif ($password !== $confirm_password) $errorHandler->addError('confirm_password', 'Passwords do not match.');

    if (!$errorHandler->hasErrors()) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO patients (name,email,phone,birthdate,gender,address,password,created_at) VALUES (?,?,?,?,?,?,?,NOW())");
        $stmt->bind_param('sssssss', $name, $email, $phone, $dob, $gender, $address, $hashed);
        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            (new ActivityLogger($conn))->log($new_id,'patient',$name,'REGISTER','New patient registered');
            $stmt->close();
            $_SESSION['patient_id']   = $new_id;
            $_SESSION['patient_name'] = $name;
            $conn->close();
            header('Location: dashboard.php');
            exit;
        } else {
            $errorHandler->addError('general','Registration failed. Please try again.');
        }
        $stmt->close();
    }
}

$conn->close();
$errors = $errorHandler->getErrors();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Create Account – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--teal:#0d9488;--teal2:#0f766e;--teal-lt:#f0fdf9;--ink:#111827;--slate:#6b7280;--border:#e5e0d5;--bg:#fdfaf5;--error:#dc2626}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:var(--bg);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px}
.card{background:#fff;border:1px solid var(--border);border-radius:24px;box-shadow:0 8px 40px rgba(0,0,0,.07);width:100%;max-width:620px;padding:48px}
.card-logo{font-family:'Syne',sans-serif;font-size:1.25rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;margin-bottom:28px}
.card-logo i{color:var(--teal)}
.card h1{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;color:var(--ink);margin-bottom:6px}
.card .sub{font-size:.88rem;color:var(--slate);margin-bottom:32px}
.card .sub a{color:var(--teal);text-decoration:none;font-weight:600}
.card .sub a:hover{text-decoration:underline}

.steps{display:flex;gap:6px;margin-bottom:32px}
.step{flex:1;height:4px;border-radius:2px;background:var(--border);transition:background .3s}
.step.done{background:var(--teal)}
.step.active{background:var(--teal);opacity:.5}

.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:520px){.grid-2{grid-template-columns:1fr}}

.field{margin-bottom:18px}
.field label{display:block;font-size:.8rem;font-weight:600;color:var(--ink);margin-bottom:6px}
.field label .opt{font-weight:400;color:var(--slate);font-size:.75rem}
.input-wrap{position:relative}
.input-wrap i.ico{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--slate);font-size:.9rem;pointer-events:none}
.input-wrap input,.input-wrap select{width:100%;padding:11px 12px 11px 38px;border:1.5px solid var(--border);border-radius:11px;font-size:.875rem;font-family:'DM Sans',sans-serif;color:var(--ink);background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;appearance:none}
.input-wrap input:focus,.input-wrap select:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.1)}
.input-wrap input.err,.input-wrap select.err{border-color:var(--error)}
.field-error{font-size:.74rem;color:var(--error);margin-top:4px;display:flex;align-items:center;gap:4px}
.pw-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--slate);cursor:pointer;font-size:.9rem}
.pw-toggle:hover{color:var(--ink)}

.pw-strength{margin-top:6px}
.pw-bars{display:flex;gap:3px;margin-bottom:4px}
.pw-bar{flex:1;height:3px;border-radius:2px;background:var(--border);transition:background .3s}
.pw-hint{font-size:.72rem;color:var(--slate)}

.section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--slate);margin:24px 0 14px;padding-top:20px;border-top:1px solid var(--border)}

.btn-submit{width:100%;padding:13px;background:var(--teal);color:#fff;border:none;border-radius:12px;font-size:.92rem;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all .2s;margin-top:8px}
.btn-submit:hover{background:var(--teal2);transform:translateY(-1px);box-shadow:0 4px 16px rgba(13,148,136,.3)}
.back-link{display:flex;align-items:center;justify-content:center;gap:6px;font-size:.84rem;color:var(--slate);margin-top:16px;text-decoration:none}
.back-link:hover{color:var(--teal)}

.error-box{background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:11px 14px;font-size:.84rem;color:var(--error);margin-bottom:20px;display:flex;align-items:center;gap:8px}
</style>
</head>
<body>
<div class="card">
    <div class="card-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
    <h1>Create your account</h1>
    <p class="sub">Already have one? <a href="login.php">Sign in →</a></p>

    <?php if (isset($errors['general'])): ?>
    <div class="error-box"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>
    <?php if (isset($errors['csrf'])): ?>
    <div class="error-box"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($errors['csrf']) ?></div>
    <?php endif; ?>

    <form method="POST" action="" novalidate id="regForm">
        <input type="hidden" name="csrf_token" value="<?= Validator::generateCSRFToken() ?>">

        <div class="section-label" style="margin-top:0;padding-top:0;border-top:none">Personal Information</div>

        <div class="grid-2">
            <div class="field" style="grid-column:1/-1">
                <label>Full Name</label>
                <div class="input-wrap">
                    <i class="bi bi-person ico"></i>
                    <input type="text" name="name" placeholder="Juan dela Cruz"
                           value="<?= htmlspecialchars($old['name'] ?? '') ?>"
                           class="<?= isset($errors['name'])?'err':'' ?>" required>
                </div>
                <?php if (isset($errors['name'])): ?><div class="field-error"><i class="bi bi-x-circle"></i><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label>Email Address</label>
                <div class="input-wrap">
                    <i class="bi bi-envelope ico"></i>
                    <input type="email" name="email" placeholder="you@email.com"
                           value="<?= htmlspecialchars($old['email'] ?? '') ?>"
                           class="<?= isset($errors['email'])?'err':'' ?>" required>
                </div>
                <?php if (isset($errors['email'])): ?><div class="field-error"><i class="bi bi-x-circle"></i><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label>Phone <span class="opt">(optional)</span></label>
                <div class="input-wrap">
                    <i class="bi bi-telephone ico"></i>
                    <input type="tel" name="phone" placeholder="+63 9XX XXX XXXX"
                           value="<?= htmlspecialchars($old['phone'] ?? '') ?>"
                           class="<?= isset($errors['phone'])?'err':'' ?>">
                </div>
                <?php if (isset($errors['phone'])): ?><div class="field-error"><i class="bi bi-x-circle"></i><?= htmlspecialchars($errors['phone']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label>Date of Birth <span class="opt">(optional)</span></label>
                <div class="input-wrap">
                    <i class="bi bi-calendar ico"></i>
                    <input type="date" name="dob" value="<?= htmlspecialchars($old['dob'] ?? '') ?>"
                           class="<?= isset($errors['dob'])?'err':'' ?>">
                </div>
                <?php if (isset($errors['dob'])): ?><div class="field-error"><i class="bi bi-x-circle"></i><?= htmlspecialchars($errors['dob']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label>Gender <span class="opt">(optional)</span></label>
                <div class="input-wrap">
                    <i class="bi bi-gender-ambiguous ico"></i>
                    <select name="gender" class="<?= isset($errors['gender'])?'err':'' ?>">
                        <option value="">Select gender</option>
                        <option value="Male"   <?= ($old['gender']??'')==='Male'  ?'selected':'' ?>>Male</option>
                        <option value="Female" <?= ($old['gender']??'')==='Female'?'selected':'' ?>>Female</option>
                        <option value="Other"  <?= ($old['gender']??'')==='Other' ?'selected':'' ?>>Other / Prefer not to say</option>
                    </select>
                </div>
            </div>

            <div class="field" style="grid-column:1/-1">
                <label>Address <span class="opt">(optional)</span></label>
                <div class="input-wrap">
                    <i class="bi bi-geo-alt ico"></i>
                    <input type="text" name="address" placeholder="Street, City, Province"
                           value="<?= htmlspecialchars($old['address'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="section-label">Security</div>
        <div class="grid-2">
            <div class="field">
                <label>Password</label>
                <div class="input-wrap">
                    <i class="bi bi-lock ico"></i>
                    <input type="password" name="password" id="pw1" placeholder="Min. 6 characters"
                           class="<?= isset($errors['password'])?'err':'' ?>"
                           oninput="checkStrength(this.value)">
                    <button type="button" class="pw-toggle" onclick="togglePw('pw1','pwi1')"><i class="bi bi-eye" id="pwi1"></i></button>
                </div>
                <div class="pw-strength">
                    <div class="pw-bars"><div class="pw-bar" id="b1"></div><div class="pw-bar" id="b2"></div><div class="pw-bar" id="b3"></div><div class="pw-bar" id="b4"></div></div>
                    <div class="pw-hint" id="pwHint">Enter a password</div>
                </div>
                <?php if (isset($errors['password'])): ?><div class="field-error"><i class="bi bi-x-circle"></i><?= htmlspecialchars($errors['password']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label>Confirm Password</label>
                <div class="input-wrap">
                    <i class="bi bi-lock-fill ico"></i>
                    <input type="password" name="confirm_password" id="pw2" placeholder="Repeat password"
                           class="<?= isset($errors['confirm_password'])?'err':'' ?>">
                    <button type="button" class="pw-toggle" onclick="togglePw('pw2','pwi2')"><i class="bi bi-eye" id="pwi2"></i></button>
                </div>
                <?php if (isset($errors['confirm_password'])): ?><div class="field-error"><i class="bi bi-x-circle"></i><?= htmlspecialchars($errors['confirm_password']) ?></div><?php endif; ?>
            </div>
        </div>

        <button type="submit" class="btn-submit">Create Account →</button>
    </form>
    <a href="login.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to sign in</a>
</div>

<script>
function togglePw(id, icoId) {
    const f = document.getElementById(id);
    const i = document.getElementById(icoId);
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
function checkStrength(v) {
    const bars  = ['b1','b2','b3','b4'];
    const hint  = document.getElementById('pwHint');
    let score   = 0;
    if (v.length >= 6)  score++;
    if (v.length >= 10) score++;
    if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
    if (/\d/.test(v) || /[^a-zA-Z\d]/.test(v)) score++;
    const colors = ['','#ef4444','#f59e0b','#3b82f6','#16a34a'];
    const labels = ['','Weak','Fair','Good','Strong'];
    bars.forEach((id, i) => {
        document.getElementById(id).style.background = i < score ? colors[score] : 'var(--border)';
    });
    hint.textContent = v.length ? labels[score] || 'Weak' : 'Enter a password';
    hint.style.color = colors[score] || 'var(--slate)';
}
</script>
</body>
</html>