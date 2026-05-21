<?php
session_start();
if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit;
}
require_once '../config/config.php';

$patient_id   = (int)$_SESSION['patient_id'];
$patient_name = $_SESSION['patient_name'] ?? 'Patient';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'mark_read') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("UPDATE notifications SET is_read=1 WHERE id=$id AND patient_id=$patient_id");
        } else {
            $conn->query("UPDATE notifications SET is_read=1 WHERE patient_id=$patient_id");
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($_POST['action'] === 'toggle_star') {
        $id = intval($_POST['id'] ?? 0);
        $conn->query("UPDATE notifications SET is_starred = NOT is_starred WHERE id=$id AND patient_id=$patient_id");
        $res = $conn->query("SELECT is_starred FROM notifications WHERE id=$id LIMIT 1");
        echo json_encode(['starred' => (bool)$res->fetch_assoc()['is_starred']]);
        exit;
    }
    if ($_POST['action'] === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $conn->query("DELETE FROM notifications WHERE id=$id AND patient_id=$patient_id");
        echo json_encode(['ok' => true]);
        exit;
    }
    echo json_encode(['ok' => false]);
    exit;
}

$filter    = $_GET['filter'] ?? 'all';
$type_map  = ['all'=>null,'unread'=>null,'starred'=>null,'appointment'=>'appointment','reminder'=>'reminder','system'=>'system'];
$where     = "patient_id = $patient_id";
if ($filter === 'unread')   $where .= " AND is_read = 0";
if ($filter === 'starred')  $where .= " AND is_starred = 1";
if (isset($type_map[$filter]) && $type_map[$filter]) $where .= " AND type = '" . $conn->real_escape_string($type_map[$filter]) . "'";

$notifications = [];
$res = $conn->query("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT 100");
while ($r = $res->fetch_assoc()) $notifications[] = $r;

$unread_count = (int)$conn->query("SELECT COUNT(*) AS c FROM notifications WHERE patient_id=$patient_id AND is_read=0")->fetch_assoc()['c'];
$total_count  = (int)$conn->query("SELECT COUNT(*) AS c FROM notifications WHERE patient_id=$patient_id")->fetch_assoc()['c'];

$conn->close();

$type_cfg = [
    'appointment' => ['icon'=>'bi-calendar-check', 'color'=>'#0d9488', 'bg'=>'rgba(13,148,136,.12)'],
    'reminder'    => ['icon'=>'bi-bell',            'color'=>'#f59e0b', 'bg'=>'rgba(245,158,11,.12)'],
    'system'      => ['icon'=>'bi-gear',            'color'=>'#6366f1', 'bg'=>'rgba(99,102,241,.12)'],
    'info'        => ['icon'=>'bi-info-circle',     'color'=>'#60a5fa', 'bg'=>'rgba(96,165,250,.12)'],
    'feedback'    => ['icon'=>'bi-star',            'color'=>'#ec4899', 'bg'=>'rgba(236,72,153,.12)'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Notifications – Vytal Dental</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<style>
:root {
    --bg:      #0e1621;
    --bg2:     #151f2e;
    --bg3:     #1c2a3e;
    --surface: #1e2d42;
    --border:  rgba(255,255,255,.08);
    --teal:    #00c9a7;
    --teal2:   #00a88d;
    --slate:   #94a3b8;
    --text:    #e2e8f0;
    --white:   #ffffff;
    --radius:  14px;
    --muted:   #64748b;
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Lato',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }
.layout { display:flex; min-height:100vh; }

.sidebar {
    width:220px; background:var(--bg2); border-right:1px solid var(--border);
    padding:0; flex-shrink:0; display:flex; flex-direction:column;
    position:sticky; top:0; height:100vh; overflow-y:auto;
}
.sidebar-logo {
    padding:20px 18px; border-bottom:1px solid var(--border);
    font-family:'Syne',sans-serif; font-size:1rem; font-weight:800;
    color:#fff; display:flex; align-items:center; gap:9px;
}
.sidebar-logo i { font-size:1.2rem; color:var(--teal); }
.nav-item {
    display:flex; align-items:center; gap:10px; padding:9px 18px;
    color:var(--slate); text-decoration:none; font-size:.82rem; font-weight:500;
    transition:all .15s; position:relative;
}
.nav-item:hover { color:var(--text); background:rgba(255,255,255,.04); }
.nav-item.active { color:var(--teal); background:rgba(0,201,167,.08); }
.nav-item.active::before { content:''; position:absolute; left:0; top:15%; bottom:15%; width:3px; background:var(--teal); border-radius:0 2px 2px 0; }
.nav-item i { font-size:.9rem; width:16px; text-align:center; }
.nav-badge { margin-left:auto; background:#ef4444; color:#fff; font-size:.58rem; font-weight:700; padding:1px 5px; border-radius:20px; }
.sidebar-footer { margin-top:auto; padding:12px; border-top:1px solid var(--border); }
.user-chip { display:flex; align-items:center; gap:9px; padding:8px 10px; background:rgba(255,255,255,.04); border-radius:8px; border:1px solid var(--border); }
.user-avatar { width:30px; height:30px; background:var(--teal); border-radius:50%; display:flex; align-items:center; justify-content:center; font-family:'Syne',sans-serif; font-weight:800; color:#0e1621; font-size:.8rem; flex-shrink:0; }
.u-name { font-size:.78rem; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.u-role { font-size:.65rem; color:var(--muted); }

.main { flex:1; display:flex; flex-direction:column; min-width:0; }
.topbar { padding:0 28px; height:56px; border-bottom:1px solid var(--border); background:var(--bg2); position:sticky; top:0; z-index:50; display:flex; align-items:center; justify-content:space-between; }
.topbar-title { font-family:'Syne',sans-serif; font-size:.95rem; font-weight:800; color:var(--text); }
.topbar-sub   { font-size:.72rem; color:var(--muted); }
.content { padding:24px 32px; flex:1; max-width:800px; }

.filter-tabs { display:flex; gap:4px; background:var(--bg2); border:1px solid var(--border); border-radius:30px; padding:4px; margin-bottom:22px; flex-wrap:wrap; }
.filter-tab {
    padding:7px 16px; border-radius:25px; border:none; background:transparent;
    color:var(--slate); font-size:.8rem; font-weight:600; cursor:pointer;
    font-family:'Lato',sans-serif; transition:all .2s; text-decoration:none;
    display:flex; align-items:center; gap:5px;
}
.filter-tab:hover { color:var(--text); }
.filter-tab.active { background:var(--teal); color:var(--bg); }
.tab-count { background:rgba(255,255,255,.15); border-radius:20px; padding:1px 6px; font-size:.65rem; }
.filter-tab.active .tab-count { background:rgba(0,0,0,.2); }

.actions-bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
.actions-bar-left { font-size:.82rem; color:var(--slate); }
.btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:25px; border:none; font-family:'Lato',sans-serif; font-size:.8rem; font-weight:700; cursor:pointer; transition:all .2s; text-decoration:none; }
.btn-ghost { background:transparent; color:var(--slate); border:1px solid var(--border); }
.btn-ghost:hover { color:var(--text); border-color:rgba(255,255,255,.2); }
.btn-teal { background:var(--teal); color:var(--bg); }
.btn-teal:hover { background:var(--teal2); }

.notif-list { display:flex; flex-direction:column; gap:8px; }
.notif-item {
    display:flex; gap:14px; padding:16px 18px;
    background:var(--bg2); border:1px solid var(--border);
    border-radius:var(--radius); transition:all .2s; position:relative;
    cursor:pointer;
}
.notif-item:hover { border-color:rgba(255,255,255,.15); background:var(--bg3); }
.notif-item.unread { border-left:3px solid var(--teal); background:rgba(0,201,167,.04); }
.notif-item.unread .notif-title { color:var(--white); }
.notif-icon-wrap {
    width:42px; height:42px; border-radius:12px;
    display:flex; align-items:center; justify-content:center;
    font-size:1.1rem; flex-shrink:0;
}
.notif-body { flex:1; min-width:0; }
.notif-title { font-size:.88rem; font-weight:700; color:var(--text); margin-bottom:4px; }
.notif-msg   { font-size:.8rem;  color:var(--slate); line-height:1.5; }
.notif-time  { font-size:.7rem;  color:var(--slate); margin-top:5px; }
.notif-actions { display:flex; align-items:flex-start; gap:6px; flex-shrink:0; }
.icon-btn {
    background:none; border:none; color:var(--slate); cursor:pointer;
    padding:5px; border-radius:8px; font-size:.95rem; transition:all .2s;
    display:flex; align-items:center; justify-content:center;
}
.icon-btn:hover { color:var(--text); background:rgba(255,255,255,.07); }
.icon-btn.starred { color:#fbbf24; }
.unread-dot {
    width:8px; height:8px; border-radius:50%; background:var(--teal);
    position:absolute; top:14px; right:14px; flex-shrink:0;
}

.empty-state { text-align:center; padding:64px 20px; color:var(--slate); }
.empty-state i { font-size:3rem; display:block; margin-bottom:12px; opacity:.3; }
.empty-state h3 { font-family:'Syne',sans-serif; font-size:1.1rem; color:var(--text); margin-bottom:8px; }
.empty-state p  { font-size:.85rem; }

@media (max-width:768px) {
    .sidebar { display:none; }
    .content { padding:16px; }
    .topbar  { padding:14px 16px; }
    .filter-tabs { gap:2px; }
    .filter-tab { padding:6px 10px; font-size:.75rem; }
}
</style>
</head>
<body>
<div class="layout">

<aside class="sidebar">
    <div class="sidebar-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
    <div style="padding:14px 16px 4px;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.2)">Menu</div>
    <a href="dashboard.php"             class="nav-item"><i class="bi bi-house-fill"></i> Dashboard</a>
    <a href="book_appointment.php"      class="nav-item"><i class="bi bi-calendar-plus"></i> Book Appointment</a>
    <a href="appointments_calendar.php" class="nav-item"><i class="bi bi-calendar3"></i> Calendar</a>
    <a href="appointments.php"          class="nav-item"><i class="bi bi-list-check"></i> My Appointments</a>
    <a href="notifications.php"         class="nav-item active">
        <i class="bi bi-bell"></i> Notifications
        <?php if ($unread_count > 0): ?><span class="nav-badge"><?= $unread_count ?></span><?php endif; ?>
    </a>
    <a href="profile.php"               class="nav-item"><i class="bi bi-person"></i> Profile</a>
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
            <div class="topbar-title">Notifications</div>
            <div class="topbar-sub"><?= $total_count ?> total &nbsp;·&nbsp; <?= $unread_count ?> unread</div>
        </div>
    </div>

    <div class="content">
        <div class="filter-tabs">
            <?php
            $tabs = [
                ['all',         'All',          'bi-inbox',          $total_count],
                ['unread',      'Unread',        'bi-circle-fill',    $unread_count],
                ['starred',     'Starred',       'bi-star',           null],
                ['appointment', 'Appointments',  'bi-calendar-check', null],
                ['reminder',    'Reminders',     'bi-bell',           null],
                ['system',      'System',        'bi-gear',           null],
            ];
            foreach ($tabs as [$key, $label, $icon, $count]):
            ?>
            <a href="?filter=<?= $key ?>" class="filter-tab <?= $filter === $key ? 'active' : '' ?>">
                <i class="bi <?= $icon ?>"></i> <?= $label ?>
                <?php if ($count !== null): ?><span class="tab-count"><?= $count ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="actions-bar">
            <span class="actions-bar-left">
                <?= count($notifications) ?> notification<?= count($notifications) !== 1 ? 's' : '' ?>
            </span>
            <div style="display:flex;gap:8px">
                <?php if ($unread_count > 0): ?>
                <button class="btn btn-ghost" onclick="markAllRead()">
                    <i class="bi bi-check2-all"></i> Mark All Read
                </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($notifications)): ?>
        <div class="empty-state">
            <i class="bi bi-bell-slash"></i>
            <h3>No notifications here</h3>
            <p>You're all caught up! Notifications about your appointments will appear here.</p>
        </div>
        <?php else: ?>
        <div class="notif-list" id="notifList">
            <?php foreach ($notifications as $n):
                $cfg = $type_cfg[$n['type']] ?? $type_cfg['info'];
                $is_unread = !$n['is_read'];
                $is_starred = $n['is_starred'];
                $time_ago = timeAgo($n['created_at']);
            ?>
            <div class="notif-item <?= $is_unread ? 'unread' : '' ?>" id="notif-<?= $n['id'] ?>"
                 onclick="markRead(<?= $n['id'] ?>, this)">
                <?php if ($is_unread): ?><div class="unread-dot"></div><?php endif; ?>
                <div class="notif-icon-wrap" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>">
                    <i class="bi <?= $cfg['icon'] ?>"></i>
                </div>
                <div class="notif-body">
                    <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
                    <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
                    <div class="notif-time">
                        <i class="bi bi-clock me-1"></i><?= $time_ago ?>
                        &nbsp;·&nbsp; <span style="text-transform:capitalize"><?= $n['type'] ?></span>
                    </div>
                </div>
                <div class="notif-actions" onclick="event.stopPropagation()">
                    <button class="icon-btn <?= $is_starred ? 'starred' : '' ?>"
                            onclick="toggleStar(<?= $n['id'] ?>, this)"
                            title="<?= $is_starred ? 'Unstar' : 'Star' ?>">
                        <i class="bi <?= $is_starred ? 'bi-star-fill' : 'bi-star' ?>"></i>
                    </button>
                    <button class="icon-btn" onclick="deleteNotif(<?= $n['id'] ?>, this)" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<script src="/assets/js/toast.js"></script>
<script>
async function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    const res = await fetch('', { method:'POST', body:fd });
    return res.json();
}

async function markRead(id, el) {
    el.classList.remove('unread');
    const dot = el.querySelector('.unread-dot');
    if (dot) dot.remove();
    await post({ action:'mark_read', id });
    updateUnreadBadge(-1);
}

async function markAllRead() {
    document.querySelectorAll('.notif-item.unread').forEach(el => {
        el.classList.remove('unread');
        el.querySelector('.unread-dot')?.remove();
    });
    await post({ action:'mark_read', id:0 });
    updateUnreadBadge(0, true);
    Toast.success('All notifications marked as read');
}

async function toggleStar(id, btn) {
    const data = await post({ action:'toggle_star', id });
    const isStarred = data.starred;
    btn.classList.toggle('starred', isStarred);
    btn.querySelector('i').className = 'bi ' + (isStarred ? 'bi-star-fill' : 'bi-star');
    btn.title = isStarred ? 'Unstar' : 'Star';
    Toast.info(isStarred ? 'Notification starred' : 'Notification unstarred');
}

async function deleteNotif(id, btn) {
    const item = document.getElementById('notif-' + id);
    item.style.opacity = '.4';
    await post({ action:'delete', id });
    item.style.animation = 'toastOut .25s ease forwards';
    setTimeout(() => item.remove(), 280);
    Toast.info('Notification deleted');
}

function updateUnreadBadge(delta, reset = false) {
    const badge = document.querySelector('.nav-item.active .badge');
    if (!badge) return;
    if (reset) { badge.style.display = 'none'; return; }
    const cur = parseInt(badge.textContent || '0');
    const next = Math.max(0, cur + delta);
    if (next === 0) badge.style.display = 'none';
    else badge.textContent = next;
}
</script>
</body>
</html>
<?php
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff/60) . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j', strtotime($datetime));
}
?>