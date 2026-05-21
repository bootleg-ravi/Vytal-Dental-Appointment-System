<?php
session_start();
if (isset($_SESSION['admin_id'])) { header('Location: dashboard.php'); exit; }
require_once '../config/config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username=? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['admin_id']   = $user['id'];
            $_SESSION['admin_name'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'] ?? 'staff';
            $conn->close();
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Admin Sign In – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--teal:#0d9488;--teal2:#0f766e;--ink:#111827;--border:#1f2937;--bg:#0e1621;--surface:#151f2e;--error:#ef4444}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Lato',sans-serif;background:var(--bg);min-height:100vh;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}

body::before{content:'';position:absolute;top:-200px;right:-200px;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(13,148,136,.12),transparent 70%);pointer-events:none}
body::after{content:'';position:absolute;bottom:-150px;left:-150px;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(13,148,136,.08),transparent 70%);pointer-events:none}

.card{background:var(--surface);border:1px solid rgba(255,255,255,.07);border-radius:20px;padding:48px 44px;width:100%;max-width:420px;position:relative;z-index:1;box-shadow:0 24px 64px rgba(0,0,0,.4)}

.logo{display:flex;align-items:center;gap:10px;margin-bottom:36px;justify-content:center}
.logo i{color:#00c9a7;font-size:1.8rem}
.logo span{font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:#fff}
.logo .admin-tag{background:rgba(0,201,167,.15);color:#00c9a7;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;padding:2px 8px;border-radius:6px;align-self:center;margin-left:4px}

h1{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#fff;text-align:center;margin-bottom:6px}
.sub{font-size:.85rem;color:rgba(255,255,255,.4);text-align:center;margin-bottom:32px}

.error-box{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:11px 14px;display:flex;align-items:center;gap:9px;font-size:.84rem;color:#fca5a5;margin-bottom:20px}

.field{margin-bottom:18px}
.field label{display:block;font-size:.78rem;font-weight:700;color:rgba(255,255,255,.6);margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em}
.input-wrap{position:relative}
.input-wrap i.ico{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.25);font-size:.9rem;pointer-events:none}
.input-wrap input{width:100%;padding:12px 14px 12px 42px;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.08);border-radius:11px;font-size:.9rem;font-family:'Lato',sans-serif;color:#fff;outline:none;transition:border-color .2s,box-shadow .2s}
.input-wrap input::placeholder{color:rgba(255,255,255,.2)}
.input-wrap input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.15)}
.input-wrap input.err{border-color:var(--error)}
.pw-toggle{position:absolute;right:13px;top:50%;transform:translateY(-50%);background:none;border:none;color:rgba(255,255,255,.3);cursor:pointer;font-size:.9rem;padding:2px;transition:color .15s}
.pw-toggle:hover{color:rgba(255,255,255,.7)}

.btn-submit{width:100%;padding:13px;background:var(--teal);color:#fff;border:none;border-radius:11px;font-size:.92rem;font-weight:700;font-family:'Lato',sans-serif;cursor:pointer;transition:all .2s;margin-top:6px;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-submit:hover{background:var(--teal2);transform:translateY(-1px);box-shadow:0 4px 20px rgba(13,148,136,.35)}

.footer-link{text-align:center;margin-top:20px;font-size:.8rem;color:rgba(255,255,255,.3)}
.footer-link a{color:#00c9a7;text-decoration:none;font-weight:600}
.footer-link a:hover{text-decoration:underline}

.dots{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:0;opacity:.3}
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <i class="bi bi-tooth-fill"></i>
        <span>Vytal Dental</span>
        <span class="admin-tag">Admin</span>
    </div>
    <h1>Staff Sign In</h1>
    <p class="sub">Access restricted to clinic staff only</p>

    <?php if ($error): ?>
    <div class="error-box"><i class="bi bi-shield-exclamation"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
        <div class="field">
            <label>Username</label>
            <div class="input-wrap">
                <i class="bi bi-person ico"></i>
                <input type="text" name="username" placeholder="Enter your username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username" class="<?= $error?'err':'' ?>" required>
            </div>
        </div>
        <div class="field">
            <label>Password</label>
            <div class="input-wrap">
                <i class="bi bi-lock ico"></i>
                <input type="password" name="password" id="pw" placeholder="••••••••"
                       autocomplete="current-password" class="<?= $error?'err':'' ?>" required>
                <button type="button" class="pw-toggle" onclick="togglePw()"><i class="bi bi-eye" id="pwi"></i></button>
            </div>
        </div>
        <button type="submit" class="btn-submit"><i class="bi bi-shield-check"></i> Sign In to Admin Panel</button>
    </form>
    <div class="footer-link">Back to <a href="../patient/login.php">Patient Portal</a></div>
</div>

<script>
function togglePw() {
    const pw = document.getElementById('pw');
    const i  = document.getElementById('pwi');
    pw.type  = pw.type === 'password' ? 'text' : 'password';
    i.className = pw.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>
</body>
</html>