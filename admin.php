<?php
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL'));

function getDB(): PDO {
    static $pdo = null;
    if($pdo) return $pdo;
    $dsn    = getenv('DATABASE_URL');
    $parsed = parse_url($dsn);
    $host   = $parsed['host'];
    $port   = $parsed['port'] ?? 5432;
    $dbname = ltrim($parsed['path'], '/');
    $user   = $parsed['user'];
    $pass   = $parsed['pass'];
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function jsonOut(array $data){
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

session_start();
if(!isset($_SESSION['user_id'])){ header("Location: login.html"); exit; }
if($_SESSION['email'] !== ADMIN_EMAIL){ header("Location: index.php"); exit; }

$db     = getDB();
$action = $_POST['action'] ?? '';

if($action === "add_tokens"){
    $uid    = trim($_POST['user_uid'] ?? '');
    $amount = (int)($_POST['amount'] ?? 0);
    if(!$uid || $amount <= 0) jsonOut(['success'=>false,'message'=>"Noto'g'ri ma'lumot"]);
    $stmt = $db->prepare("SELECT id FROM users WHERE user_uid=:uid");
    $stmt->bindValue(":uid", $uid);
    $stmt->execute();
    if(!$stmt->fetch()) jsonOut(['success'=>false,'message'=>'User topilmadi']);
    $upd = $db->prepare("UPDATE users SET tokens = tokens + :amount WHERE user_uid = :uid");
    $upd->bindValue(":amount", $amount, PDO::PARAM_INT);
    $upd->bindValue(":uid", $uid);
    $upd->execute();
    jsonOut(['success'=>true,'message'=>"Token qo'shildi"]);
}

$totalUsers  = $db->query("SELECT COUNT(*) FROM users WHERE verified IS TRUE")->fetchColumn();
$totalImages = $db->query("SELECT COUNT(*) FROM image_history")->fetchColumn();
$todayImages = $db->query("SELECT COUNT(*) FROM image_history WHERE created_at::date = CURRENT_DATE")->fetchColumn();
$users       = $db->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
$history     = $db->query("
    SELECT ih.*, u.email FROM image_history ih
    LEFT JOIN users u ON u.id = ih.user_id
    ORDER BY ih.created_at DESC LIMIT 100
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>ADMIN // TERMINAL v2.0</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Orbitron:wght@400;700;900&family=VT323&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --g:#00ff41;--g2:#00cc33;--g3:#009922;--gdark:#001a06;
  --c:#00ffff;--y:#ffff00;--r:#ff0040;--p:#bf00ff;
  --bg:#000300;--bg2:#010801;--bg3:#020d03;
  --card:rgba(0,255,65,.03);--border:rgba(0,255,65,.18);
  --brite:rgba(0,255,65,.55);--font:'Share Tech Mono',monospace;
  --display:'Orbitron',monospace;--vt:'VT323',monospace;
}
html{scroll-behavior:smooth}
body{font-family:var(--font);background:var(--bg);color:var(--g);
  min-height:100vh;overflow-x:hidden;cursor:none;user-select:none}

/* CANVAS */
#stars{position:fixed;inset:0;z-index:0;pointer-events:none}

/* SCANLINES */
.scan{position:fixed;inset:0;z-index:1;pointer-events:none;
  background:repeating-linear-gradient(0deg,transparent,transparent 3px,
  rgba(0,0,0,.12) 3px,rgba(0,0,0,.12) 4px)}

/* CRT VIGNETTE */
.vignette{position:fixed;inset:0;z-index:1;pointer-events:none;
  background:radial-gradient(ellipse at 50% 50%,transparent 55%,rgba(0,0,0,.85) 100%)}

/* CURSOR */
#cur{position:fixed;pointer-events:none;z-index:9999;transform:translate(-50%,-50%)}
#cur svg{display:block;transition:all .1s}
#cur-glow{position:fixed;pointer-events:none;z-index:9998;
  width:60px;height:60px;transform:translate(-50%,-50%);
  background:radial-gradient(circle,rgba(0,255,65,.12) 0%,transparent 70%);
  transition:left .08s,top .08s;border-radius:50%}

/* WRAP */
.wrap{position:relative;z-index:2;max-width:1400px;margin:0 auto;padding:0 28px 100px}

/* HEADER */
header{
  padding:28px 0 20px;
  border-bottom:1px solid var(--border);
  margin-bottom:36px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:16px;
}
.logo{font-family:var(--display);font-weight:900;font-size:1.05rem;
  letter-spacing:.22em;display:flex;align-items:center;gap:10px}
.logo-bracket{color:var(--g3);font-size:.8em}
.logo-text{position:relative;color:var(--g);
  text-shadow:0 0 10px var(--g),0 0 30px rgba(0,255,65,.4),0 0 60px rgba(0,255,65,.15)}
.logo-text::after{content:attr(data-t);position:absolute;inset:0;
  color:var(--c);clip-path:inset(35% 0 45% 0);
  animation:glitch1 3.5s infinite step-end;opacity:.7;text-shadow:2px 0 var(--r)}
@keyframes glitch1{
  0%,89%{clip-path:inset(35% 0 45% 0);transform:translate(0)}
  90%{clip-path:inset(10% 0 70% 0);transform:translate(-3px)}
  92%{clip-path:inset(60% 0 15% 0);transform:translate(3px)}
  94%{clip-path:inset(80% 0 5% 0);transform:translate(-2px)}
  96%,100%{clip-path:inset(35% 0 45% 0);transform:translate(0)}
}
.blink-cur{display:inline-block;width:10px;height:18px;
  background:var(--g);animation:blink .7s step-end infinite;
  box-shadow:0 0 10px var(--g);vertical-align:middle;margin-left:4px}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}

.hdr-right{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.clock{font-family:var(--vt);font-size:1.4rem;color:var(--c);
  text-shadow:0 0 10px var(--c);letter-spacing:.06em}
.root-badge{padding:4px 12px;border:1px solid var(--g);border-radius:2px;
  font-size:.65rem;letter-spacing:.2em;color:var(--g);
  animation:pulse-border 2s infinite;text-shadow:0 0 6px var(--g)}
@keyframes pulse-border{
  0%,100%{box-shadow:0 0 6px rgba(0,255,65,.3),inset 0 0 6px rgba(0,255,65,.05)}
  50%{box-shadow:0 0 14px rgba(0,255,65,.5),inset 0 0 10px rgba(0,255,65,.1)}}
.exit-btn{font-family:var(--font);font-size:.72rem;padding:6px 14px;
  background:transparent;border:1px solid var(--r);color:var(--r);
  border-radius:2px;cursor:pointer;text-decoration:none;letter-spacing:.1em;
  transition:all .2s}
.exit-btn:hover{background:rgba(255,0,64,.1);box-shadow:0 0 14px rgba(255,0,64,.35);
  text-shadow:0 0 8px var(--r)}

/* SECTION TITLE */
.sec{display:flex;align-items:center;gap:10px;margin-bottom:18px;margin-top:36px}
.sec h2{font-family:var(--display);font-size:.72rem;font-weight:700;
  letter-spacing:.22em;color:var(--g);text-shadow:0 0 8px rgba(0,255,65,.4)}
.sec::before{content:'//';color:var(--g3);font-size:.8rem;letter-spacing:-.05em}
.sec::after{content:'';flex:1;height:1px;
  background:linear-gradient(90deg,rgba(0,255,65,.3),transparent)}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:3px;
  padding:22px 24px;position:relative;overflow:hidden;transition:all .3s;cursor:default}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:var(--sc,var(--g));box-shadow:0 0 12px var(--sc,var(--g))}
.stat::after{content:'';position:absolute;bottom:0;right:0;
  width:80px;height:80px;
  background:radial-gradient(circle at bottom right,rgba(0,255,65,.06),transparent 70%)}
.stat:hover{border-color:var(--brite);background:rgba(0,255,65,.055);
  box-shadow:0 0 24px rgba(0,255,65,.08);transform:translateY(-2px)}
.stat-lbl{font-size:.63rem;letter-spacing:.18em;color:var(--g3);text-transform:uppercase;margin-bottom:10px}
.stat-val{font-family:var(--display);font-size:2.2rem;font-weight:900;
  color:var(--sc,var(--g));text-shadow:0 0 20px var(--sc,var(--g));
  line-height:1;letter-spacing:-.02em}
.stat-sub{font-size:.62rem;color:var(--g3);margin-top:6px;letter-spacing:.12em}

/* TOKEN PANEL */
.tok-panel{background:var(--card);border:1px solid var(--border);border-radius:3px;
  padding:26px;position:relative;margin-bottom:4px}
.tok-panel::before{content:'> TOKEN_INJECTOR.exe ■';position:absolute;top:-11px;left:14px;
  background:var(--bg);padding:0 10px;font-size:.65rem;color:var(--c);
  letter-spacing:.1em;text-shadow:0 0 8px var(--c)}
.tok-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.field{display:flex;flex-direction:column;gap:7px;flex:1;min-width:130px}
.field label{font-size:.62rem;letter-spacing:.15em;color:var(--g3);text-transform:uppercase}
.inp{background:rgba(0,255,65,.03);border:1px solid var(--border);border-radius:2px;
  padding:11px 14px;font-family:var(--font);font-size:.88rem;color:var(--g);
  outline:none;transition:all .2s;letter-spacing:.05em;width:100%}
.inp:focus{border-color:var(--g);background:rgba(0,255,65,.06);
  box-shadow:0 0 0 2px rgba(0,255,65,.1),0 0 18px rgba(0,255,65,.07)}
.inp::placeholder{color:var(--g3)}

.inject-btn{position:relative;overflow:hidden;background:transparent;
  border:1px solid var(--g);color:var(--g);padding:11px 28px;
  font-family:var(--display);font-size:.68rem;font-weight:700;
  letter-spacing:.18em;cursor:pointer;border-radius:2px;white-space:nowrap;
  transition:color .25s;text-transform:uppercase}
.inject-btn::before{content:'';position:absolute;inset:0;
  background:var(--g);transform:translateX(-101%);transition:transform .25s cubic-bezier(.4,0,.2,1)}
.inject-btn:hover::before{transform:translateX(0)}
.inject-btn:hover{color:var(--bg);box-shadow:0 0 24px rgba(0,255,65,.45)}
.inject-btn span{position:relative;z-index:1}

#tmsg{margin-top:14px;font-size:.76rem;padding:9px 14px;border-radius:2px;
  display:none;letter-spacing:.04em;border-left:3px solid}
#tmsg.ok{background:rgba(0,255,65,.07);border-color:var(--g);color:var(--g);display:block}
#tmsg.err{background:rgba(255,0,64,.07);border-color:var(--r);color:var(--r);display:block}

/* TABS */
.tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:22px}
.tab{background:transparent;border:none;border-bottom:2px solid transparent;
  padding:10px 22px;font-family:var(--font);font-size:.72rem;letter-spacing:.12em;
  color:var(--g3);cursor:pointer;transition:all .2s;text-transform:uppercase;margin-bottom:-1px}
.tab:hover{color:var(--g)}
.tab.on{color:var(--g);border-bottom-color:var(--g);text-shadow:0 0 8px rgba(0,255,65,.5)}
.tc{display:none;animation:fadeup .3s ease}
.tc.on{display:block}
@keyframes fadeup{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

/* TABLE */
.tbl-wrap{border:1px solid var(--border);border-radius:3px;overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.76rem}
thead tr{background:rgba(0,255,65,.04);border-bottom:1px solid var(--border)}
th{padding:12px 16px;font-family:var(--display);font-size:.58rem;
  letter-spacing:.18em;color:var(--g3);text-align:left;white-space:nowrap;text-transform:uppercase}
td{padding:11px 16px;color:rgba(0,255,65,.7);border-bottom:1px solid rgba(0,255,65,.05);
  vertical-align:middle;letter-spacing:.02em}
tbody tr{transition:background .15s}
tbody tr:hover td{background:rgba(0,255,65,.04);color:var(--g)}
tbody tr:last-child td{border-bottom:none}

.uid{display:inline-flex;align-items:center;padding:3px 10px;
  background:rgba(0,255,65,.05);border:1px solid rgba(0,255,65,.22);
  border-radius:2px;font-size:.72rem;color:var(--g);
  text-shadow:0 0 5px rgba(0,255,65,.35);letter-spacing:.1em}
.badge-root{font-size:.6rem;background:rgba(255,255,0,.1);color:var(--y);
  padding:2px 7px;border-radius:2px;margin-left:6px;
  text-shadow:0 0 6px var(--y);border:1px solid rgba(255,255,0,.2)}
.online{color:var(--g);font-size:.7rem;text-shadow:0 0 6px var(--g)}
.pending{color:var(--g3);font-size:.7rem}
.token-val{color:var(--c);text-shadow:0 0 6px var(--c)}
.limit-val{color:var(--g)}
.limit-low{color:var(--r);text-shadow:0 0 6px var(--r)}
.bar{width:56px;height:3px;background:rgba(0,255,65,.1);border-radius:2px;
  overflow:hidden;display:inline-block;vertical-align:middle;margin-left:7px}
.bar-fill{height:100%;background:var(--g);box-shadow:0 0 5px var(--g);border-radius:2px}
.prompt-td{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  color:var(--g3);font-size:.72rem}
.date-td{font-size:.68rem;color:var(--g3);white-space:nowrap}

/* SCROLLBAR */
::-webkit-scrollbar{width:3px;height:3px}
::-webkit-scrollbar-track{background:var(--bg2)}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
::-webkit-scrollbar-thumb:hover{background:var(--g)}

/* BOOT LINE */
.boot{font-family:var(--vt);font-size:1.1rem;color:var(--g3);
  letter-spacing:.05em;margin-bottom:8px;
  border-left:3px solid var(--g3);padding-left:12px;opacity:.6}

@media(max-width:700px){
  .stats{grid-template-columns:1fr 1fr}
  .tok-row{flex-direction:column}
  .logo-text{font-size:.85rem}
}
</style>
</head>
<body>
<canvas id="stars"></canvas>
<div class="scan"></div>
<div class="vignette"></div>
<div id="cur">
  <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
    <circle cx="11" cy="11" r="9" stroke="#00ff41" stroke-width="1"/>
    <line x1="11" y1="2" x2="11" y2="7" stroke="#00ff41" stroke-width="1.5"/>
    <line x1="11" y1="15" x2="11" y2="20" stroke="#00ff41" stroke-width="1.5"/>
    <line x1="2" y1="11" x2="7" y2="11" stroke="#00ff41" stroke-width="1.5"/>
    <line x1="15" y1="11" x2="20" y2="11" stroke="#00ff41" stroke-width="1.5"/>
    <circle cx="11" cy="11" r="2" fill="#00ff41" opacity=".8"/>
  </svg>
</div>
<div id="cur-glow"></div>

<div class="wrap">

  <!-- BOOT LINES -->
  <div class="boot" style="margin-top:20px;animation:fadeup .5s .1s both">SYSTEM BOOT... OK</div>
  <div class="boot" style="animation:fadeup .5s .25s both">POSTGRESQL CONNECTED... OK</div>
  <div class="boot" style="animation:fadeup .5s .4s both">AUTH VERIFIED [ROOT] ... OK</div>

  <!-- HEADER -->
  <header>
    <div class="logo">
      <span class="logo-bracket">[</span>
      <span class="logo-text" data-t="ADMIN::TERMINAL">ADMIN::TERMINAL</span>
      <span class="logo-bracket">]</span>
      <span class="blink-cur"></span>
    </div>
    <div class="hdr-right">
      <div class="clock" id="clock">--:--:--</div>
      <div class="root-badge">ROOT ACCESS</div>
      <a href="index.php" class="exit-btn">[ EXIT ]</a>
    </div>
  </header>

  <!-- STATS -->
  <div class="sec"><h2>SYSTEM_STATS</h2></div>
  <div class="stats">
    <div class="stat" style="--sc:#00ff41">
      <div class="stat-lbl">// users_active</div>
      <div class="stat-val"><?= $totalUsers ?></div>
      <div class="stat-sub">VERIFIED NODES</div>
    </div>
    <div class="stat" style="--sc:#00ffff">
      <div class="stat-lbl">// renders_today</div>
      <div class="stat-val"><?= $todayImages ?></div>
      <div class="stat-sub">LAST 24H</div>
    </div>
    <div class="stat" style="--sc:#ffff00">
      <div class="stat-lbl">// total_renders</div>
      <div class="stat-val"><?= $totalImages ?></div>
      <div class="stat-sub">ALL TIME</div>
    </div>
  </div>

  <!-- TOKEN PANEL -->
  <div class="sec" style="margin-top:40px"><h2>TOKEN_INJECTOR</h2></div>
  <div class="tok-panel">
    <div class="tok-row">
      <div class="field">
        <label>target_uid</label>
        <input class="inp" id="uid" type="text" placeholder="0000" maxlength="4"
               oninput="this.value=this.value.replace(/\D/,'')"/>
      </div>
      <div class="field">
        <label>token_amount</label>
        <input class="inp" id="amount" type="number" placeholder="0" min="1" max="9999"/>
      </div>
      <button class="inject-btn" onclick="addToken()"><span>INJECT &gt;&gt;</span></button>
    </div>
    <div id="tmsg"></div>
  </div>

  <!-- TABS -->
  <div class="sec" style="margin-top:40px"><h2>DATABASE</h2></div>
  <div class="tabs">
    <button class="tab on" onclick="sw('users',this)">USERS_TABLE [<?= count($users) ?>]</button>
    <button class="tab" onclick="sw('history',this)">IMAGE_LOG [<?= count($history) ?>]</button>
  </div>

  <!-- USERS TAB -->
  <div class="tc on" id="tab-users">
    <div class="tbl-wrap">
      <table>
        <thead><tr>
          <th>email</th><th>user_uid</th><th>daily_limit</th>
          <th>tokens</th><th>verified</th><th>created_at</th>
        </tr></thead>
        <tbody>
        <?php foreach($users as $u): ?>
        <tr>
          <td>
            <?= htmlspecialchars($u['email']) ?>
            <?php if($u['email'] === ADMIN_EMAIL): ?>
            <span class="badge-root">ROOT</span>
            <?php endif; ?>
          </td>
          <td><span class="uid"><?= htmlspecialchars($u['user_uid'] ?? '----') ?></span></td>
          <td>
            <?php $lv = (int)$u['daily_limit']; ?>
            <span class="<?= $lv>0?'limit-val':'limit-low' ?>"><?= $lv ?></span>
            <span class="bar"><span class="bar-fill" style="width:<?= min(100,($lv/8)*100) ?>%"></span></span>
          </td>
          <td class="token-val"><?= (int)$u['tokens'] ?></td>
          <td>
            <?php if($u['verified']): ?>
            <span class="online">● ONLINE</span>
            <?php else: ?>
            <span class="pending">○ PENDING</span>
            <?php endif; ?>
          </td>
          <td class="date-td"><?= date('d.m.Y', strtotime($u['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- HISTORY TAB -->
  <div class="tc" id="tab-history">
    <div class="tbl-wrap">
      <table>
        <thead><tr>
          <th>email</th><th>user_uid</th><th>prompt</th><th>timestamp</th>
        </tr></thead>
        <tbody>
        <?php foreach($history as $h): ?>
        <tr>
          <td style="font-size:.73rem"><?= htmlspecialchars($h['email'] ?? 'N/A') ?></td>
          <td><span class="uid"><?= htmlspecialchars($h['user_uid']) ?></span></td>
          <td class="prompt-td" title="<?= htmlspecialchars($h['prompt']) ?>"><?= htmlspecialchars($h['prompt']) ?></td>
          <td class="date-td"><?= date('d.m.Y H:i', strtotime($h['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /wrap -->

<script>
// ── STARFIELD ──────────────────────────────────────────────────────────────
const cv = document.getElementById('stars');
const cx = cv.getContext('2d');
let W, H, stars = [], mx = 0, my = 0;

function resize(){ W = cv.width = innerWidth; H = cv.height = innerHeight; }

function mkStars(){
  stars = [];
  const n = Math.floor(W * H / 2800);
  for(let i=0;i<n;i++) stars.push({
    x: Math.random()*W, y: Math.random()*H,
    r: Math.random()*1.5+.2,
    spd: Math.random()*.25+.04,
    a: Math.random()*.75+.25,
    ph: Math.random()*Math.PI*2,
    col: Math.random()>.93?'#00ffff':Math.random()>.87?'#ccffdd':'#00ff41',
    size: Math.random()
  });
}

function drawStars(){
  cx.clearRect(0,0,W,H);
  const ox = (mx/W-.5), oy = (my/H-.5);
  for(const s of stars){
    s.ph += .009; s.y += s.spd*.15;
    if(s.y>H) s.y=0;
    const alpha = s.a*(.65+.35*Math.sin(s.ph));
    const px = s.x + ox*s.r*12, py = s.y + oy*s.r*12;
    cx.beginPath(); cx.arc(px,py,s.r,0,Math.PI*2);
    cx.fillStyle = s.col; cx.globalAlpha = alpha; cx.fill();
    if(s.r>1){
      const g = cx.createRadialGradient(px,py,0,px,py,s.r*4);
      g.addColorStop(0,s.col+'55'); g.addColorStop(1,'transparent');
      cx.beginPath(); cx.arc(px,py,s.r*4,0,Math.PI*2);
      cx.fillStyle=g; cx.globalAlpha=alpha*.35; cx.fill();
    }
    cx.globalAlpha=1;
  }
  // aurora
  const ag = cx.createRadialGradient(mx,my,0,mx,my,250);
  ag.addColorStop(0,'rgba(0,255,65,.035)'); ag.addColorStop(1,'transparent');
  cx.fillStyle=ag; cx.fillRect(0,0,W,H);
  requestAnimationFrame(drawStars);
}
window.addEventListener('resize',()=>{resize();mkStars()});
resize(); mkStars(); drawStars();

// ── CURSOR ─────────────────────────────────────────────────────────────────
const cur = document.getElementById('cur');
const glow = document.getElementById('cur-glow');
let tx=0,ty=0,gx=0,gy=0;

document.addEventListener('mousemove',e=>{
  mx=e.clientX; my=e.clientY; tx=e.clientX; ty=e.clientY;
  cur.style.left=tx+'px'; cur.style.top=ty+'px';
});
document.addEventListener('mousedown',()=>cur.querySelector('svg').style.transform='scale(.8)');
document.addEventListener('mouseup',()=>cur.querySelector('svg').style.transform='scale(1)');

(function lag(){
  gx+=(tx-gx)*.1; gy+=(ty-gy)*.1;
  glow.style.left=gx+'px'; glow.style.top=gy+'px';
  requestAnimationFrame(lag);
})();

// ── CLOCK ──────────────────────────────────────────────────────────────────
const pad=n=>String(n).padStart(2,'0');
function tick(){
  const d=new Date();
  document.getElementById('clock').textContent=
    pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
}
tick(); setInterval(tick,1000);

// ── TABS ───────────────────────────────────────────────────────────────────
function sw(id,btn){
  document.querySelectorAll('.tc').forEach(t=>t.classList.remove('on'));
  document.querySelectorAll('.tab').forEach(b=>b.classList.remove('on'));
  document.getElementById('tab-'+id).classList.add('on');
  btn.classList.add('on');
}

// ── ADD TOKENS ─────────────────────────────────────────────────────────────
async function addToken(){
  const uid = document.getElementById('uid').value.trim();
  const amt = document.getElementById('amount').value.trim();
  const msg = document.getElementById('tmsg');
  if(!uid||uid.length!==4){showMsg('ERR: user_uid must be 4 digits',0);return}
  if(!amt||parseInt(amt)<=0){showMsg('ERR: invalid amount',0);return}
  try{
    const res  = await fetch('admin.php',{method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:`action=add_tokens&user_uid=${encodeURIComponent(uid)}&amount=${encodeURIComponent(amt)}`});
    const data = await res.json();
    if(data.success){
      showMsg('OK >> '+data.message,1);
      document.getElementById('uid').value='';
      document.getElementById('amount').value='';
      setTimeout(()=>location.reload(),1800);
    } else showMsg('ERR: '+data.message,0);
  }catch(e){showMsg('ERR: network failure',0)}
}
function showMsg(t,ok){
  const el=document.getElementById('tmsg');
  el.textContent='> '+t;
  el.className=ok?'ok':'err';
}
</script>
</body>
</html>
