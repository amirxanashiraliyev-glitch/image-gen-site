<?php
// ─── CONFIG — barcha qiymatlar Render environment variables dan ────────────
define('DB_HOST',     getenv('DB_HOST'));
define('DB_NAME',     getenv('DB_NAME'));
define('DB_USER',     getenv('DB_USER'));
define('DB_PASS',     getenv('DB_PASS'));
define('SITE_URL',    getenv('SITE_URL'));
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL'));
define('RESEND_KEY',  getenv('RESEND_API_KEY'));
define('FROM_EMAIL',  getenv('FROM_EMAIL'));   // masalan: noreply@yoursite.com

// ─── DB CONNECTION (PostgreSQL — Render) ──────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $url = getenv('DATABASE_URL');

    if (!$url) {
        throw new \RuntimeException('DATABASE_URL environment variable is not set.');
    }

    $parsed = parse_url($url);

    if (!$parsed || empty($parsed['host'])) {
        throw new \RuntimeException('DATABASE_URL could not be parsed. Check its format.');
    }

    $host   = $parsed['host'];
    $port   = isset($parsed['port']) ? (int)$parsed['port'] : 5432;
    $dbname = isset($parsed['path']) ? ltrim($parsed['path'], '/') : '';
    $user   = $parsed['user'] ?? '';
    $pass   = $parsed['pass'] ?? '';

    if (!$dbname) {
        throw new \RuntimeException('DATABASE_URL does not contain a database name.');
    }

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT         => false,
    ]);

    return $pdo;
}

// ─── INIT DB TABLES (PostgreSQL) ──────────────────────────────────────────
function initDB(): void {
    $db = getDB();

    // ── users table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id          SERIAL PRIMARY KEY,
        email       VARCHAR(255) NOT NULL UNIQUE,
        password    VARCHAR(255),
        verified    SMALLINT DEFAULT 0,
        google_user SMALLINT DEFAULT 0,
        daily_limit INT DEFAULT 8,
        tokens      INT DEFAULT 0,
        last_reset  DATE DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // ── Migration: add user_uid if missing (safe for existing deployments)
    $db->exec("
        DO \$\$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name = 'users' AND column_name = 'user_uid'
            ) THEN
                ALTER TABLE users ADD COLUMN user_uid VARCHAR(10);
            END IF;
        END
        \$\$;
    ");

    // ── Unique index on user_uid (safe — skips if already exists)
    $db->exec("
        DO \$\$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_indexes
                WHERE tablename = 'users' AND indexname = 'idx_users_user_uid'
            ) THEN
                CREATE UNIQUE INDEX idx_users_user_uid ON users(user_uid)
                WHERE user_uid IS NOT NULL;
            END IF;
        END
        \$\$;
    ");

    // ── verification_codes table
    $db->exec("CREATE TABLE IF NOT EXISTS verification_codes (
        id         SERIAL PRIMARY KEY,
        email      VARCHAR(255) NOT NULL,
        code       VARCHAR(10)  NOT NULL,
        expires_at TIMESTAMP    NOT NULL
    )");

    // ── image_history table
    $db->exec("CREATE TABLE IF NOT EXISTS image_history (
        id         SERIAL PRIMARY KEY,
        user_id    INT  NOT NULL,
        user_uid   VARCHAR(10) NOT NULL,
        prompt     TEXT NOT NULL,
        image_url  TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // ── Indexes (idempotent)
    $db->exec("CREATE INDEX IF NOT EXISTS idx_verif_email     ON verification_codes(email)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_history_user_id ON image_history(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_history_uid     ON image_history(user_uid)");
}

// ─── HELPER: JSON RESPONSE ─────────────────────────────────────────────────
function jsonOut(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ─── HELPER: GENERATE UNIQUE UID ──────────────────────────────────────────
function generateUID(): string {
    $db = getDB();
    do {
        $uid = str_pad((string)mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        $row = $db->prepare("SELECT id FROM users WHERE user_uid = ?");
        $row->execute([$uid]);
    } while($row->fetch());
    return $uid;
}

// ─── HELPER: SEND VERIFICATION EMAIL via Resend API ───────────────────────
function sendVerificationEmail(string $email, string $code): bool {
    $payload = json_encode([
        'from'    => 'AI Rasm Generator <' . FROM_EMAIL . '>',
        'to'      => [$email],
        'subject' => 'AI Rasm Generator — Email tasdiqlash kodi',
        'html'    => '
        <div style="font-family:sans-serif;max-width:480px;margin:0 auto;background:#0f172a;color:#f1f5f9;padding:32px;border-radius:16px;">
          <h2 style="color:#38bdf8;margin-bottom:8px;">✦ AI Rasm Generator</h2>
          <p style="color:#94a3b8;margin-bottom:24px;">Email manzilingizni tasdiqlang</p>
          <div style="background:#1e293b;border:1px solid #263347;border-radius:12px;padding:24px;text-align:center;margin-bottom:24px;">
            <p style="color:#94a3b8;font-size:14px;margin-bottom:12px;">Tasdiqlash kodingiz:</p>
            <div style="font-size:36px;font-weight:800;letter-spacing:10px;color:#38bdf8;font-family:monospace;">' . $code . '</div>
          </div>
          <p style="color:#475569;font-size:13px;">Kod <strong style="color:#f1f5f9;">10 daqiqa</strong> ichida amal qiladi.</p>
          <p style="color:#475569;font-size:12px;margin-top:16px;">Agar siz ro\'yxatdan o\'tmagan bo\'lsangiz, bu xabarni e\'tiborsiz qoldiring.</p>
        </div>'
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_KEY,
            'Content-Type: application/json'
        ]
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status === 200 || $status === 201;
}

// ─── HELPER: CHECK & RESET DAILY LIMIT ────────────────────────────────────
function checkAndResetLimit(array &$user): void {
    $today = date('Y-m-d');
    if($user['last_reset'] !== $today) {
        $db = getDB();
        $db->prepare("UPDATE users SET daily_limit=8, last_reset=? WHERE id=?")
           ->execute([$today, $user['id']]);
        $user['daily_limit'] = 8;
        $user['last_reset'] = $today;
        $_SESSION['daily_limit'] = 8;
    }
}

// ─── SESSION ──────────────────────────────────────────────────────────────
session_start();
initDB();

// ─── ROUTE ACTIONS ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── REGISTER ──────────────────────────────────────────────────────────────
if($action === 'register') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['success'=>false,'message'=>'Email noto\'g\'ri']);
    if(strlen($password) < 8) jsonOut(['success'=>false,'message'=>'Parol kamida 8 ta belgi bo\'lishi kerak']);

    $db = getDB();
    $check = $db->prepare("SELECT id, verified FROM users WHERE email=?");
    $check->execute([$email]);
    $existing = $check->fetch();

    if($existing && $existing['verified']) jsonOut(['success'=>false,'message'=>'Bu email allaqachon ro\'yxatdan o\'tgan']);

    $uid  = generateUID();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $today = date('Y-m-d');

    if($existing) {
        $db->prepare("UPDATE users SET password=?, user_uid=?, last_reset=? WHERE email=?")
           ->execute([$hash, $uid, $today, $email]);
    } else {
        $db->prepare("INSERT INTO users (user_uid,email,password,verified,daily_limit,tokens,last_reset) VALUES (?,?,?,0,8,0,?)")
           ->execute([$uid, $email, $hash, $today]);
    }

    // Generate code
    $code = str_pad((string)mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare("DELETE FROM verification_codes WHERE email=?")->execute([$email]);
    $db->prepare("INSERT INTO verification_codes (email,code,expires_at) VALUES (?,?,?)")
       ->execute([$email, $code, date('Y-m-d H:i:s', time()+600)]);

    sendVerificationEmail($email, $code);
    jsonOut(['success'=>true,'message'=>'Kod yuborildi']);
}

// ── RESEND CODE ────────────────────────────────────────────────────────────
if($action === 'resend_code') {
    $email = trim($_POST['email'] ?? '');
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['success'=>false,'message'=>'Email noto\'g\'ri']);
    $db = getDB();
    $code = str_pad((string)mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare("DELETE FROM verification_codes WHERE email=?")->execute([$email]);
    $db->prepare("INSERT INTO verification_codes (email,code,expires_at) VALUES (?,?,?)")
       ->execute([$email, $code, date('Y-m-d H:i:s', time()+600)]);
    sendVerificationEmail($email, $code);
    jsonOut(['success'=>true]);
}

// ── VERIFY EMAIL ───────────────────────────────────────────────────────────
if($action === 'verify_email') {
    $email = trim($_POST['email'] ?? '');
    $code  = trim($_POST['code'] ?? '');
    $db    = getDB();
    $row   = $db->prepare("SELECT * FROM verification_codes WHERE email=? AND code=? AND expires_at > NOW()");
    $row->execute([$email, $code]);
    $vc = $row->fetch();
    if(!$vc) jsonOut(['success'=>false,'message'=>'Kod noto\'g\'ri yoki muddati o\'tgan']);

    $db->prepare("UPDATE users SET verified=1 WHERE email=?")->execute([$email]);
    $db->prepare("DELETE FROM verification_codes WHERE email=?")->execute([$email]);

    $user = $db->prepare("SELECT * FROM users WHERE email=?");
    $user->execute([$email]);
    $u = $user->fetch();
    if($u) {
        $_SESSION['user_id']    = $u['id'];
        $_SESSION['email']      = $u['email'];
        $_SESSION['user_uid']   = $u['user_uid'];
        $_SESSION['daily_limit']= $u['daily_limit'];
        $_SESSION['tokens']     = $u['tokens'];
    }
    jsonOut(['success'=>true]);
}

// ── LOGIN ──────────────────────────────────────────────────────────────────
if($action === 'login') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email=?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if(!$u) jsonOut(['success'=>false,'message'=>'Email yoki parol noto\'g\'ri']);
    if(!$u['verified']) jsonOut(['success'=>false,'message'=>'Iltimos avval emailingizni tasdiqlang']);
    if(!password_verify($password, $u['password'])) jsonOut(['success'=>false,'message'=>'Email yoki parol noto\'g\'ri']);

    $today = date('Y-m-d');
    if($u['last_reset'] !== $today) {
        $db->prepare("UPDATE users SET daily_limit=8, last_reset=? WHERE id=?")->execute([$today, $u['id']]);
        $u['daily_limit'] = 8;
    }

    $_SESSION['user_id']    = $u['id'];
    $_SESSION['email']      = $u['email'];
    $_SESSION['user_uid']   = $u['user_uid'];
    $_SESSION['daily_limit']= $u['daily_limit'];
    $_SESSION['tokens']     = $u['tokens'];
    jsonOut(['success'=>true]);
}

// ── LOGOUT ─────────────────────────────────────────────────────────────────
if($action === 'logout') {
    session_destroy();
    header('Location: login.html');
    exit;
}

// ── GOOGLE AUTH (stub - redirects to signup) ───────────────────────────────
if($action === 'google_auth') {
    // In production, integrate with Google OAuth2
    // For now, show stub message
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><link rel="stylesheet" href="style.css"></head><body class="auth-page">
    <div class="auth-card"><div class="auth-logo"><div class="logo-mark">✦</div><h1>AI Rasm Generator</h1></div>
    <div class="alert alert-info">Google OAuth integratsiyasi uchun Google Cloud Console\'da OAuth 2.0 sozlamalari kerak. Hozircha email/parol orqali kiring.</div>
    <div class="auth-footer" style="margin-top:20px;"><a href="login.html">← Kirish sahifasiga qaytish</a></div>
    </div></body></html>';
    exit;
}

// ── SAVE IMAGE ─────────────────────────────────────────────────────────────
if($action === 'save_image') {
    if(!isset($_SESSION['user_id'])) jsonOut(['success'=>false,'message'=>'Autentifikatsiya kerak']);
    $prompt    = trim($_POST['prompt'] ?? '');
    $image_url = trim($_POST['image_url'] ?? '');
    if(!$prompt || !$image_url) jsonOut(['success'=>false,'message'=>'Ma\'lumot to\'liq emas']);

    $db = getDB();
    $user = $db->prepare("SELECT * FROM users WHERE id=?");
    $user->execute([$_SESSION['user_id']]);
    $u = $user->fetch();
    if(!$u) jsonOut(['success'=>false,'message'=>'Foydalanuvchi topilmadi']);

    checkAndResetLimit($u);

    // Check limits
    if($u['daily_limit'] <= 0 && $u['tokens'] <= 0) {
        jsonOut(['success'=>false,'message'=>'Bugungi limit tugadi. Ertaga qayta urinib ko\'ring yoki token sotib oling.','limit_reached'=>true]);
    }

    // Deduct
    if($u['daily_limit'] > 0) {
        $db->prepare("UPDATE users SET daily_limit=daily_limit-1 WHERE id=?")->execute([$u['id']]);
        $_SESSION['daily_limit'] = $u['daily_limit'] - 1;
    } else {
        $db->prepare("UPDATE users SET tokens=tokens-1 WHERE id=?")->execute([$u['id']]);
        $_SESSION['tokens'] = $u['tokens'] - 1;
    }

    $db->prepare("INSERT INTO image_history (user_id,user_uid,prompt,image_url) VALUES (?,?,?,?)")
       ->execute([$u['id'], $u['user_uid'], $prompt, $image_url]);

    $updated = $db->prepare("SELECT daily_limit, tokens FROM users WHERE id=?");
    $updated->execute([$u['id']]);
    $upd = $updated->fetch();
    $_SESSION['daily_limit'] = $upd['daily_limit'];
    $_SESSION['tokens'] = $upd['tokens'];

    jsonOut(['success'=>true,'daily_limit'=>$upd['daily_limit'],'tokens'=>$upd['tokens']]);
}

// ─── AUTH GUARD ────────────────────────────────────────────────────────────
// API action bo'lsa redirect qilma — faqat brauzerdan kirish bo'lsa redirect
$publicActions = ['register', 'login', 'resend_code', 'verify_email', 'google_auth', 'logout'];

if (!isset($_SESSION['user_id'])) {
    if ($action !== '') {
        // API request — session yo'q, lekin public action emas
        if (!in_array($action, $publicActions)) {
            jsonOut(['success' => false, 'message' => 'Autentifikatsiya kerak', 'redirect' => 'login.html']);
        }
        // public actions (register, login, verify_email...) — davom etsin
    } else {
        // Brauzerdan to'g'ridan sahifaga kirish — login ga yo'naltir
        header('Location: login.html');
        exit;
    }
}

// ─── LOAD USER DATA ────────────────────────────────────────────────────────
$db   = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if(!$user) { session_destroy(); header('Location: login.html'); exit; }

checkAndResetLimit($user);

// Load history
$histStmt = $db->prepare("SELECT * FROM image_history WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$histStmt->execute([$user['id']]);
$history = $histStmt->fetchAll();

$limitDisplay = $user['daily_limit'];
$tokenDisplay = $user['tokens'];
$totalDisplay = $limitDisplay + $tokenDisplay;
$emailInitial = strtoupper(substr($user['email'], 0, 1));
?>
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>AI Rasm Generator</title>
<link rel="stylesheet" href="style.css"/>
</head>
<body>

<!-- ─── NAVBAR ──────────────────────────────────────────────────────────── -->
<nav class="navbar">
  <a href="index.php" class="navbar-logo">
    <div class="logo-icon">✦</div>
    <span><span class="gradient-text">AI Rasm</span> Generator</span>
  </a>
  <div class="navbar-right">
    <div class="limit-badge">
      <div class="dot"></div>
      Bugungi limit: <strong id="nav-limit"><?= $limitDisplay ?></strong>
      <?php if($tokenDisplay > 0): ?>
        &nbsp;+&nbsp;<span style="color:var(--neon-purple)"><?= $tokenDisplay ?> token</span>
      <?php endif; ?>
    </div>
    <div class="avatar-wrap">
      <button class="avatar-btn" onclick="toggleDropdown()" id="avatar-btn"><?= $emailInitial ?></button>
      <div class="profile-dropdown" id="profile-dropdown">
        <div class="dropdown-header">
          <div class="d-name">Profil</div>
          <div class="dropdown-meta">
            Foydalanuvchi ID: <span class="mono"><?= htmlspecialchars($user['user_uid']) ?></span><br/>
            Email: <span><?= htmlspecialchars($user['email']) ?></span><br/>
            Limit: <span><?= $limitDisplay ?></span> &nbsp;|&nbsp; Token: <span style="color:var(--neon-purple)"><?= $tokenDisplay ?></span>
          </div>
        </div>
        <?php if($user['email'] === ADMIN_EMAIL): ?>
        <a href="admin.php" class="dropdown-item">⚙️ Admin Panel</a>
        <?php endif; ?>
        <a href="index.php?action=logout" class="dropdown-item danger" onclick="return confirm('Chiqmoqchimisiz?')">↩ Chiqish</a>
      </div>
    </div>
  </div>
</nav>

<!-- ─── MAIN ─────────────────────────────────────────────────────────────── -->
<div class="main-wrap">
  <div class="container">
    <div class="page-content">

      <!-- GENERATOR -->
      <div class="generator-section">
        <div class="section-heading">Rasm Yaratish</div>
        <h2 class="section-title">Tasavvuringizni rasmga aylantiring</h2>

        <div class="generator-card">
          <div id="gen-alert"></div>

          <div class="prompt-area">
            <textarea
              class="prompt-textarea"
              id="prompt-input"
              placeholder="Rasm uchun prompt yozing... masalan: 'koinotda suzayotgan kit, neon ranglar, ultra realistic'"
              maxlength="500"
              oninput="updateCharCount()"
            ></textarea>
          </div>

          <div class="generator-actions">
            <div class="char-count"><span id="char-count">0</span>/500</div>
            <button class="gen-btn" id="gen-btn" onclick="generateImage()">
              <span id="gen-btn-icon">✦</span>&nbsp;
              <span id="gen-btn-text">Rasm yaratish</span>
            </button>
          </div>
        </div>

        <!-- Loading -->
        <div class="loading-area" id="loading-area">
          <div class="spinner"></div>
          <div class="loading-dots">
            <span></span><span></span><span></span>
          </div>
          <div class="loading-text">Rasm yaratilmoqda...</div>
        </div>

        <!-- Result -->
        <div class="result-area" id="result-area">
          <div class="result-img-wrap">
            <img id="result-img" src="" alt="Yaratilgan rasm"/>
          </div>
          <div class="result-actions">
            <a id="download-btn" href="#" download="ai-rasm.jpg" class="btn btn-primary" target="_blank">
              ⬇ Yuklab olish
            </a>
            <button class="btn btn-ghost" onclick="regenerate()">↻ Qayta yaratish</button>
          </div>
        </div>
      </div>

      <!-- HISTORY -->
      <div class="history-section">
        <div class="section-heading">Tarix</div>
        <h2 class="section-title" style="font-size:1.5rem">Mening yaratgan rasmlarim</h2>

        <?php if(empty($history)): ?>
        <div class="empty-state">
          <span class="empty-icon">🖼</span>
          Hali rasm yaratilmagan.<br/>Yuqoridagi maydondan birinchi rasmingizni yarating!
        </div>
        <?php else: ?>
        <div class="history-grid">
          <?php foreach($history as $item): ?>
          <div class="history-card">
            <img
              src="<?= htmlspecialchars($item['image_url']) ?>"
              alt="<?= htmlspecialchars(substr($item['prompt'],0,50)) ?>"
              loading="lazy"
              onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%231e293b\' width=\'200\' height=\'200\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' fill=\'%23475569\' font-size=\'40\' text-anchor=\'middle\' dy=\'.3em\'%3E🖼%3C/text%3E%3C/svg%3E'"
            />
            <div class="history-card-body">
              <div class="history-prompt"><?= htmlspecialchars($item['prompt']) ?></div>
              <div class="history-meta">
                <div class="history-date"><?= date('d.m.Y H:i', strtotime($item['created_at'])) ?></div>
                <a href="<?= htmlspecialchars($item['image_url']) ?>" download="ai-rasm.jpg" target="_blank" class="btn btn-ghost btn-sm" title="Yuklab olish">⬇</a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<script>
let currentPrompt = '';
let currentImgUrl = '';

function toggleDropdown() {
  const dd = document.getElementById('profile-dropdown');
  dd.classList.toggle('open');
}

document.addEventListener('click', function(e) {
  const wrap = document.getElementById('avatar-btn').closest('.avatar-wrap');
  if(!wrap.contains(e.target)) {
    document.getElementById('profile-dropdown').classList.remove('open');
  }
});

function updateCharCount() {
  const val = document.getElementById('prompt-input').value.length;
  document.getElementById('char-count').textContent = val;
}

function showGenAlert(msg, type='error') {
  const box = document.getElementById('gen-alert');
  box.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
  setTimeout(() => { box.innerHTML = ''; }, 6000);
}

async function generateImage() {
  const prompt = document.getElementById('prompt-input').value.trim();
  if(!prompt) { showGenAlert('Iltimos, prompt kiriting'); return; }

  currentPrompt = prompt;
  setLoading(true);

  try {
    const apiUrl = 'https://img-gen.wwiw.uz/?prompt=' + encodeURIComponent(prompt);
    const img = new Image();

    img.onload = async function() {
      currentImgUrl = apiUrl;
      document.getElementById('result-img').src = apiUrl;
      document.getElementById('download-btn').href = apiUrl;

      // Save to DB
      try {
        const res = await fetch('index.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: `action=save_image&prompt=${encodeURIComponent(prompt)}&image_url=${encodeURIComponent(apiUrl)}`
        });
        const data = await res.json();
        if(data.success) {
          document.getElementById('nav-limit').textContent = data.daily_limit;
        } else if(data.limit_reached) {
          showGenAlert(data.message, 'warning');
        }
      } catch(e) { /* silent */ }

      setLoading(false);
      document.getElementById('result-area').classList.add('visible');
    };

    img.onerror = function() {
      setLoading(false);
      showGenAlert('Rasm yaratishda xatolik yuz berdi. Qayta urinib ko\'ring.');
    };

    img.src = apiUrl;

  } catch(err) {
    setLoading(false);
    showGenAlert('Xatolik yuz berdi: ' + err.message);
  }
}

function setLoading(state) {
  const btn = document.getElementById('gen-btn');
  const loadArea = document.getElementById('loading-area');
  const resultArea = document.getElementById('result-area');
  const btnText = document.getElementById('gen-btn-text');

  btn.disabled = state;
  btnText.textContent = state ? 'Yaratilmoqda...' : 'Rasm yaratish';

  if(state) {
    loadArea.classList.add('visible');
    resultArea.classList.remove('visible');
  } else {
    loadArea.classList.remove('visible');
  }
}

function regenerate() {
  document.getElementById('result-area').classList.remove('visible');
  generateImage();
}
</script>
</body>
</html>
