<?php
// ─── CONFIG ────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'ai_rasm_generator');
define('DB_USER', 'root');
define('DB_PASS', '');
define('ADMIN_EMAIL', 'admin@example.com'); // Must match index.php

// ─── DB ────────────────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if($pdo) return $pdo;
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function jsonOut(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ─── SESSION & AUTH ────────────────────────────────────────────────────────
session_start();
if(!isset($_SESSION['user_id'])) { header('Location: login.html'); exit; }
if($_SESSION['email'] !== ADMIN_EMAIL) { header('Location: index.php'); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ─── API: ADD TOKENS ───────────────────────────────────────────────────────
if($action === 'add_tokens') {
    $uid    = trim($_POST['user_uid'] ?? '');
    $amount = (int)($_POST['amount'] ?? 0);
    if(!$uid || $amount <= 0) jsonOut(['success'=>false,'message'=>'Noto\'g\'ri ma\'lumot']);
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE user_uid=?");
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    if(!$u) jsonOut(['success'=>false,'message'=>'Bu ID bilan foydalanuvchi topilmadi']);
    $db->prepare("UPDATE users SET tokens=tokens+? WHERE user_uid=?")->execute([$amount, $uid]);
    jsonOut(['success'=>true,'message'=>"$amount token muvaffaqiyatli qo'shildi"]);
}

// ─── LOAD DATA ─────────────────────────────────────────────────────────────
$db = getDB();

// Stats
$totalUsers   = $db->query("SELECT COUNT(*) FROM users WHERE verified=1")->fetchColumn();
$totalImages  = $db->query("SELECT COUNT(*) FROM image_history")->fetchColumn();
$todayImages  = $db->query("SELECT COUNT(*) FROM image_history WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// Search
$search = trim($_GET['search'] ?? '');
$searchType = $_GET['search_type'] ?? 'email';

// Users
if($search) {
    if($searchType === 'uid') {
        $userStmt = $db->prepare("SELECT * FROM users WHERE user_uid LIKE ? ORDER BY id DESC");
        $userStmt->execute(["%$search%"]);
    } else {
        $userStmt = $db->prepare("SELECT * FROM users WHERE email LIKE ? ORDER BY id DESC");
        $userStmt->execute(["%$search%"]);
    }
} else {
    $userStmt = $db->query("SELECT * FROM users ORDER BY id DESC");
}
$users = $userStmt->fetchAll();

// Image history
if($search) {
    if($searchType === 'uid') {
        $histStmt = $db->prepare("SELECT ih.*, u.email FROM image_history ih LEFT JOIN users u ON u.id=ih.user_id WHERE ih.user_uid LIKE ? ORDER BY ih.created_at DESC LIMIT 100");
        $histStmt->execute(["%$search%"]);
    } else {
        $histStmt = $db->prepare("SELECT ih.*, u.email FROM image_history ih LEFT JOIN users u ON u.id=ih.user_id WHERE u.email LIKE ? ORDER BY ih.created_at DESC LIMIT 100");
        $histStmt->execute(["%$search%"]);
    }
} else {
    $histStmt = $db->query("SELECT ih.*, u.email FROM image_history ih LEFT JOIN users u ON u.id=ih.user_id ORDER BY ih.created_at DESC LIMIT 100");
}
$allHistory = $histStmt->fetchAll();

$adminInitial = strtoupper(substr($_SESSION['email'], 0, 1));
?>
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Admin Panel — AI Rasm Generator</title>
<link rel="stylesheet" href="style.css"/>
</head>
<body>

<!-- ─── ADMIN NAV ─────────────────────────────────────────────────────────── -->
<nav class="admin-nav">
  <a href="index.php" class="navbar-logo">
    <div class="logo-icon">✦</div>
    <span><span class="gradient-text">AI Rasm</span> Admin</span>
  </a>
  <div class="navbar-right">
    <div class="limit-badge" style="color:var(--neon-purple); border-color:rgba(129,140,248,0.25); background:rgba(129,140,248,0.08)">
      <div class="dot" style="background:var(--neon-purple); box-shadow:0 0 8px var(--neon-purple)"></div>
      Admin Panel
    </div>
    <div class="avatar-wrap">
      <button class="avatar-btn" style="background:linear-gradient(135deg,var(--neon-purple),var(--accent))" onclick="toggleDropdown()"><?= $adminInitial ?></button>
      <div class="profile-dropdown" id="profile-dropdown">
        <div class="dropdown-header">
          <div class="d-name">Admin</div>
          <div class="dropdown-meta">Email: <span><?= htmlspecialchars($_SESSION['email']) ?></span></div>
        </div>
        <a href="index.php" class="dropdown-item">🏠 Asosiy sahifa</a>
        <a href="index.php?action=logout" class="dropdown-item danger">↩ Chiqish</a>
      </div>
    </div>
  </div>
</nav>

<!-- ─── CONFIRM MODAL ─────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="confirm-modal">
  <div class="modal">
    <div class="modal-icon">⚠️</div>
    <h3 class="modal-title">Tasdiqlash</h3>
    <div class="modal-body" id="modal-body-text">
      Siz rostdan ham bu foydalanuvchiga token qo'shmoqchimisiz?
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal()">Bekor qilish</button>
      <button class="btn btn-success" onclick="confirmAddToken()">✓ Tasdiqlash</button>
    </div>
  </div>
</div>

<!-- ─── MAIN ─────────────────────────────────────────────────────────────── -->
<div class="main-wrap">
  <div class="container">
    <div class="page-content">

      <div class="admin-header">
        <div>
          <div class="section-heading">Boshqaruv paneli</div>
          <h1 class="admin-title">Admin <span>Panel</span></h1>
        </div>
      </div>

      <!-- ─── STATS ─────────────────────────────────────────────────────── -->
      <div class="admin-section">
        <div class="admin-section-title">Statistika</div>
        <div class="stats-grid">
          <div class="stat-card" style="--stat-color: linear-gradient(90deg, #38bdf8, #818cf8)">
            <div class="stat-label">Foydalanuvchilar soni</div>
            <div class="stat-value"><?= $totalUsers ?></div>
          </div>
          <div class="stat-card" style="--stat-color: linear-gradient(90deg, #34d399, #38bdf8)">
            <div class="stat-label">Bugungi generatsiyalar</div>
            <div class="stat-value"><?= $todayImages ?></div>
          </div>
          <div class="stat-card" style="--stat-color: linear-gradient(90deg, #a78bfa, #f472b6)">
            <div class="stat-label">Jami generatsiyalar</div>
            <div class="stat-value"><?= $totalImages ?></div>
          </div>
        </div>
      </div>

      <!-- ─── TOKEN MANAGEMENT ──────────────────────────────────────────── -->
      <div class="admin-section">
        <div class="admin-section-title">Token boshqaruvi</div>
        <div class="token-form-card">
          <div id="token-alert"></div>
          <div class="token-form-row">
            <div class="form-group">
              <label>Foydalanuvchi ID</label>
              <input type="text" id="token-uid" placeholder="masalan: 4832" maxlength="4"
                     oninput="this.value=this.value.replace(/\D/,'')"/>
            </div>
            <div class="form-group">
              <label>Token miqdori</label>
              <input type="number" id="token-amount" placeholder="masalan: 20" min="1" max="9999"/>
            </div>
            <div>
              <button class="btn btn-primary" onclick="openTokenModal()" style="margin-top:24px">
                ➕ Token qo'shish
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ─── TABS ──────────────────────────────────────────────────────── -->
      <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('users', this)">👥 Foydalanuvchilar</button>
        <button class="tab-btn" onclick="switchTab('history', this)">🖼 Rasm tarixi</button>
      </div>

      <!-- ─── SEARCH ────────────────────────────────────────────────────── -->
      <form method="GET" action="admin.php" class="search-bar" style="margin-bottom:20px">
        <select name="search_type" style="width:auto; flex:0 0 130px;">
          <option value="email" <?= $searchType==='email'?'selected':'' ?>>Email</option>
          <option value="uid" <?= $searchType==='uid'?'selected':'' ?>>User ID</option>
        </select>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Qidirish..."/>
        <button type="submit" class="btn btn-primary">🔍 Qidirish</button>
        <?php if($search): ?>
        <a href="admin.php" class="btn btn-ghost">✕ Tozalash</a>
        <?php endif; ?>
      </form>

      <!-- ─── USERS TAB ─────────────────────────────────────────────────── -->
      <div class="tab-content active" id="tab-users">
        <div class="admin-section">
          <div class="admin-section-title">
            Foydalanuvchilar &mdash; <span class="text-accent"><?= count($users) ?> ta</span>
          </div>
          <?php if(empty($users)): ?>
          <div class="empty-state">Foydalanuvchi topilmadi</div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Email</th>
                  <th>Bugungi limit</th>
                  <th>Token</th>
                  <th>Holat</th>
                  <th>Ro'yxatdan o'tgan</th>
                  <th>User ID</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                  <td>
                    <?= htmlspecialchars($u['email']) ?>
                    <?php if($u['email'] === ADMIN_EMAIL): ?>
                    <span style="font-size:0.7rem;background:rgba(251,191,36,0.15);color:var(--warning);padding:2px 7px;border-radius:20px;margin-left:6px;">Admin</span>
                    <?php endif; ?>
                    <?php if($u['google_user']): ?>
                    <span style="font-size:0.7rem;background:rgba(56,189,248,0.1);color:var(--accent);padding:2px 7px;border-radius:20px;margin-left:4px;">Google</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span style="font-family:'JetBrains Mono',monospace;color:<?= $u['daily_limit']>0?'var(--success)':'var(--danger)' ?>;font-weight:600;">
                      <?= $u['daily_limit'] ?>/8
                    </span>
                  </td>
                  <td><span class="token-badge"><?= $u['tokens'] ?></span></td>
                  <td>
                    <?php if($u['verified']): ?>
                    <span style="color:var(--success);font-size:0.78rem">✓ Tasdiqlangan</span>
                    <?php else: ?>
                    <span style="color:var(--danger);font-size:0.78rem">✗ Tasdiqlanmagan</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-muted" style="font-size:0.78rem;font-family:'JetBrains Mono',monospace">
                    <?= date('d.m.Y', strtotime($u['created_at'])) ?>
                  </td>
                  <td><span class="uid-badge"><?= htmlspecialchars($u['user_uid']) ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ─── HISTORY TAB ───────────────────────────────────────────────── -->
      <div class="tab-content" id="tab-history">
        <div class="admin-section">
          <div class="admin-section-title">
            Barcha rasm tarixi &mdash; <span class="text-accent"><?= count($allHistory) ?> ta</span>
          </div>
          <?php if(empty($allHistory)): ?>
          <div class="empty-state"><span class="empty-icon">🖼</span>Hali rasm yaratilmagan</div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Rasm</th>
                  <th>Email</th>
                  <th>User ID</th>
                  <th>Prompt</th>
                  <th>Sana</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($allHistory as $item): ?>
                <tr>
                  <td>
                    <a href="<?= htmlspecialchars($item['image_url']) ?>" target="_blank">
                      <img src="<?= htmlspecialchars($item['image_url']) ?>" class="img-thumb"
                           alt="rasm"
                           onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'52\' height=\'52\'%3E%3Crect fill=\'%231e293b\' width=\'52\' height=\'52\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' fill=\'%23475569\' font-size=\'20\' text-anchor=\'middle\' dy=\'.3em\'%3E🖼%3C/text%3E%3C/svg%3E'"/>
                    </a>
                  </td>
                  <td style="font-size:0.82rem"><?= htmlspecialchars($item['email'] ?? 'N/A') ?></td>
                  <td><span class="uid-badge"><?= htmlspecialchars($item['user_uid']) ?></span></td>
                  <td style="max-width:280px; font-size:0.8rem; color:var(--text-muted)">
                    <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px;" title="<?= htmlspecialchars($item['prompt']) ?>">
                      <?= htmlspecialchars($item['prompt']) ?>
                    </div>
                  </td>
                  <td class="text-muted" style="font-size:0.75rem;font-family:'JetBrains Mono',monospace;white-space:nowrap">
                    <?= date('d.m.Y H:i', strtotime($item['created_at'])) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
let pendingUID = '';
let pendingAmount = 0;

function toggleDropdown() {
  document.getElementById('profile-dropdown').classList.toggle('open');
}
document.addEventListener('click', function(e) {
  const btn = document.querySelector('.avatar-btn');
  if(btn && !btn.closest('.avatar-wrap').contains(e.target)) {
    document.getElementById('profile-dropdown').classList.remove('open');
  }
});

function switchTab(tab, btn) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  btn.classList.add('active');
}

function showTokenAlert(msg, type='error') {
  const box = document.getElementById('token-alert');
  box.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
  setTimeout(() => { box.innerHTML = ''; }, 5000);
}

function openTokenModal() {
  const uid = document.getElementById('token-uid').value.trim();
  const amount = parseInt(document.getElementById('token-amount').value);
  if(!uid || uid.length !== 4) { showTokenAlert('Iltimos, 4 raqamli User ID kiriting'); return; }
  if(!amount || amount <= 0) { showTokenAlert('Iltimos, musbat token miqdorini kiriting'); return; }
  pendingUID = uid;
  pendingAmount = amount;
  document.getElementById('modal-body-text').innerHTML =
    `Siz <strong style="color:var(--accent)">${uid}</strong> IDli foydalanuvchiga <strong style="color:var(--neon-purple)">${amount} token</strong> qo'shmoqchimisiz?`;
  document.getElementById('confirm-modal').classList.add('open');
}

function closeModal() {
  document.getElementById('confirm-modal').classList.remove('open');
}

async function confirmAddToken() {
  closeModal();
  try {
    const res = await fetch('admin.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `action=add_tokens&user_uid=${encodeURIComponent(pendingUID)}&amount=${encodeURIComponent(pendingAmount)}`
    });
    const data = await res.json();
    if(data.success) {
      showTokenAlert(data.message, 'success');
      document.getElementById('token-uid').value = '';
      document.getElementById('token-amount').value = '';
      setTimeout(() => location.reload(), 1500);
    } else {
      showTokenAlert(data.message || 'Xatolik yuz berdi');
    }
  } catch(e) {
    showTokenAlert('Server bilan bog\'lanishda xatolik');
  }
}

// Close modal on overlay click
document.getElementById('confirm-modal').addEventListener('click', function(e) {
  if(e.target === this) closeModal();
});
</script>
</body>
</html>
