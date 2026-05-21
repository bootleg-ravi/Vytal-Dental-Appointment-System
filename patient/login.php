<?php
session_start();
if (isset($_SESSION['patient_id'])) { header('Location: dashboard.php'); exit; }
require_once '../config/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, email, password FROM patients WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($patient && password_verify($password, $patient['password'])) {
            $_SESSION['patient_id']   = $patient['id'];
            $_SESSION['patient_name'] = $patient['name'];
            require_once '../includes/ActivityLogger.php';
            (new ActivityLogger($conn))->log($patient['id'],'patient',$patient['name'],'LOGIN','Patient logged in successfully');
            $conn->close();
            header('Location: dashboard.php');
            exit;
        } else {
            require_once '../includes/ActivityLogger.php';
            (new ActivityLogger($conn))->log(0,'patient',$email,'LOGIN_FAILED','Failed login attempt: '.$email);
            $error = 'Invalid email or password.';
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
<title>Sign In – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root{--teal:#0d9488;--teal2:#0f766e;--ink:#111827;--slate:#6b7280;--border:#e5e0d5;--bg:#fdfaf5;--error:#dc2626}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:var(--bg);min-height:100vh;display:flex}

.split{display:flex;min-height:100vh;width:100%}
.split-left{flex:1;background:var(--ink);position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:flex-end;padding:56px;min-height:100vh}
.split-left::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 30% 50%, rgba(13,148,136,.25) 0%, transparent 60%)}
.split-left::after{content:'';position:absolute;top:-120px;right:-120px;width:380px;height:380px;border-radius:50%;border:80px solid rgba(13,148,136,.07)}
.brand{position:relative;z-index:1}
.brand-logo{font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;margin-bottom:48px}
.brand-logo i{color:#00c9a7}
.brand-tagline{font-size:.85rem;font-weight:500;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.35);margin-bottom:16px}
.brand-headline{font-family:'Syne',sans-serif;font-size:2.4rem;font-weight:800;color:#fff;line-height:1.18;margin-bottom:24px}
.brand-headline span{color:#00c9a7}
.brand-features{display:flex;flex-direction:column;gap:10px}
.brand-feat{display:flex;align-items:center;gap:10px;font-size:.85rem;color:rgba(255,255,255,.6)}
.brand-feat i{color:#00c9a7;font-size:.9rem;flex-shrink:0}
.deco-circles{position:absolute;bottom:-60px;left:-60px;width:260px;height:260px;border-radius:50%;border:60px solid rgba(13,148,136,.05);z-index:0}

.split-right{width:480px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:48px 40px;background:var(--bg)}
.form-box{width:100%;max-width:380px}
.form-box h1{font-family:'Syne',sans-serif;font-size:1.9rem;font-weight:800;color:var(--ink);margin-bottom:6px}
.form-box .sub{font-size:.88rem;color:var(--slate);margin-bottom:36px}
.form-box .sub a{color:var(--teal);text-decoration:none;font-weight:600}

.field{margin-bottom:20px}
.field label{display:block;font-size:.8rem;font-weight:600;color:var(--ink);margin-bottom:6px}
.input-wrap{position:relative}
.input-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--slate);font-size:.95rem;pointer-events:none}
.input-wrap input{width:100%;padding:12px 14px 12px 40px;border:1.5px solid var(--border);border-radius:12px;font-size:.9rem;font-family:'DM Sans',sans-serif;color:var(--ink);background:#fff;outline:none;transition:border-color .2s,box-shadow .2s}
.input-wrap input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.1)}
.input-wrap input.err{border-color:var(--error)}
.toggle-pw{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--slate);cursor:pointer;font-size:.9rem;padding:2px}
.toggle-pw:hover{color:var(--ink)}
.field-meta{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.field-meta label{margin-bottom:0}
.field-meta a{font-size:.78rem;color:var(--teal);text-decoration:none;font-weight:600}
.field-meta a:hover{text-decoration:underline}

.error-box{background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:11px 14px;display:flex;align-items:center;gap:9px;font-size:.84rem;color:var(--error);margin-bottom:20px}

.btn-submit{width:100%;padding:13px;background:var(--teal);color:#fff;border:none;border-radius:12px;font-size:.92rem;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-submit:hover{background:var(--teal2);transform:translateY(-1px);box-shadow:0 4px 18px rgba(13,148,136,.3)}
.btn-submit:active{transform:none}

.divider{display:flex;align-items:center;gap:12px;margin:22px 0;font-size:.78rem;color:var(--slate)}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border)}

.btn-guest{width:100%;padding:12px;border:1.5px solid var(--border);border-radius:12px;background:#fff;font-size:.88rem;font-weight:600;font-family:'DM Sans',sans-serif;color:var(--ink);cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none}
.btn-guest:hover{border-color:var(--teal);color:var(--teal);background:var(--bg)}

@media(max-width:900px){.split-left{display:none}.split-right{width:100%}}
@media(max-width:480px){.split-right{padding:32px 24px}}
</style>
</head>
<body>
<div class="split">

    <div class="split-left">
        <div class="deco-circles"></div>
        <div class="brand">
            <div class="brand-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
            <p class="brand-tagline">Patient Portal</p>
            <h2 class="brand-headline">Your smile is<br>our <span>priority</span>.</h2>
            <div class="brand-features">
                <div class="brand-feat"><i class="bi bi-calendar-check-fill"></i> Book & manage appointments online</div>
                <div class="brand-feat"><i class="bi bi-bell-fill"></i> Get email reminders before your visit</div>
                <div class="brand-feat"><i class="bi bi-shield-check-fill"></i> Secure patient health records</div>
                <div class="brand-feat"><i class="bi bi-clock-fill"></i> View real-time slot availability</div>
            </div>
        </div>
    </div>

    <div class="split-right">
        <div class="form-box">
            <h1>Welcome back</h1>
            <p class="sub">Don't have an account? <a href="register.php">Create one →</a></p>

            <?php if ($error): ?>
            <div class="error-box"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <div class="field">
                    <label>Email address</label>
                    <div class="input-wrap">
                        <i class="bi bi-envelope"></i>
                        <input type="email" name="email" placeholder="you@email.com"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               autocomplete="email" required
                               class="<?= $error ? 'err' : '' ?>">
                    </div>
                </div>

                <div class="field">
                    <div class="field-meta">
                        <label>Password</label>
                        <a href="forgot_password.php">Forgot password?</a>
                    </div>
                    <div class="input-wrap">
                        <i class="bi bi-lock"></i>
                        <input type="password" name="password" id="pw" placeholder="••••••••"
                               autocomplete="current-password" required
                               class="<?= $error ? 'err' : '' ?>">
                        <button type="button" class="toggle-pw" onclick="togglePw()" id="pwToggle">
                            <i class="bi bi-eye" id="pwIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                </button>
            </form>

            <div class="divider">or</div>
            <a href="book_appointment.php?guest=1" class="btn-guest">
                <i class="bi bi-calendar-plus"></i> Book as Guest (no account needed)
            </a>
        </div>
    </div>
</div>

<script>
function togglePw() {
    const pw = document.getElementById('pw');
    const ic = document.getElementById('pwIcon');
    if (pw.type === 'password') { pw.type = 'text';     ic.className = 'bi bi-eye-slash'; }
    else                        { pw.type = 'password'; ic.className = 'bi bi-eye'; }
}
</script>
</body>
</html>