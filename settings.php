<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];


$conn = new mysqli('localhost', 'webapp_user', 'g679*.<cS5LK', 'chat');
if ($conn->connect_error) die("DB-Verbindung fehlgeschlagen.");
$conn->query("SET time_zone = 'Europe/Berlin'");

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($myRole);
$stmt->fetch();
$stmt->close();

$_SESSION['role'] = (int)$myRole;

if (isset($_SESSION['role']) && (int)$_SESSION['role'] >= 1) {
    header("Location: settings2.php");
    exit;
}

// CSRF Token erzeugen
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];


$message = "";
$username_value = "";


// aktueller Benutzername laden
$stmt = $conn->prepare("SELECT username, color, emoji FROM users WHERE id = ?");$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($username_value, $color_value, $emoji_value);
$stmt->fetch();
$stmt->close();

$user_color = $color_value ?: '#ffffff';
$user_emoji = $emoji_value ?: '';

// Account-Daten ändern (Username/Passwort)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['username']) && isset($_POST['current_password'])) {
    $new_username     = trim($_POST['username'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $new_color = $_POST['color'] ?? '';
    $new_emoji = trim($_POST['emoji'] ?? '');

    $username_value = htmlspecialchars($new_username, ENT_QUOTES, 'UTF-8');

    // aktuelles Passwort prüfen
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($hashed_password);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($current_password, $hashed_password)) {
        $message = "Falsches aktuelles Passwort!";
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $new_username)) {
        $message = "Ungültiger Benutzername (3-30 Zeichen, Buchstaben/Zahlen/._-)";
    } elseif ($new_password && $new_password !== $confirm_password) {
        $message = "Neues Passwort stimmt nicht mit Bestätigung überein!";
    } else {
        // username und ggf. password updaten
        if ($new_password) {
            $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, color = ?, emoji = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $new_username, $new_hash, $new_color, $new_emoji, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username = ?, color = ?, emoji = ? WHERE id = ?");
            $stmt->bind_param("sssi", $new_username, $new_color, $new_emoji, $user_id);
        }
        if ($stmt->execute()) {
            $_SESSION['username'] = $new_username;
            $_SESSION['user_color'] = $new_color;
            $_SESSION['user_emoji'] = $new_emoji;
            $message = "Daten erfolgreich aktualisiert!";
        } else {
            $message = "Fehler beim Speichern.";
        }
        $stmt->close();
    }
}

/*
 * Alias setzen / updaten (extra Formular ganz oben)
 * Leer = löschen (wie bisher)
 */
if (isset($_POST['set_alias']) && isset($_POST['target_id']) && !isset($_POST['status'])) {
    $target_id  = (int)$_POST['target_id'];
    $alias_name = trim($_POST['alias_name'] ?? '');

    if ($alias_name === '') {
        // leeren Alias behandeln wie löschen
        $stmt = $conn->prepare("DELETE FROM user_aliases WHERE owner_id=? AND target_id=?");
        $stmt->bind_param("ii", $user_id, $target_id);
        if ($stmt->execute()) {
            $message = "Alias gelöscht.";
        } else {
            $message = "Fehler beim Löschen des Alias.";
        }
        $stmt->close();
    } else {
        // Alias hinzufügen oder updaten
        $stmt = $conn->prepare("
            INSERT INTO user_aliases (owner_id, target_id, alias)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE alias = VALUES(alias)
        ");
        $stmt->bind_param("iis", $user_id, $target_id, $alias_name);
        if ($stmt->execute()) {
            $message = "Alias gespeichert.";
        } else {
            $message = "Fehler beim Speichern des Alias.";
        }
        $stmt->close();
    }
}

// Alias löschen direkt aus der Tabelle
if (isset($_POST['delete_alias']) && isset($_POST['target_id'])) {
    $target_id = (int)$_POST['target_id'];
    $stmt = $conn->prepare("DELETE FROM user_aliases WHERE owner_id=? AND target_id=?");
    $stmt->bind_param("ii", $user_id, $target_id);
    if ($stmt->execute()) {
        $message = "Alias gelöscht.";
    } else {
        $message = "Fehler beim Löschen des Alias.";
    }
    $stmt->close();
}

/*
 * Status (normal / pinned / archived) aus Tabelle setzen
 */
if (isset($_POST['set_status']) && isset($_POST['target_id'])) {
    $target_id = (int)$_POST['target_id'];
    $status    = $_POST['status'] ?? 'normal'; // 'normal','pinned','archived'

    // sicherstellen, dass Zeile existiert
    $conn->query("
        INSERT IGNORE INTO user_aliases (owner_id, target_id)
        VALUES ($user_id, $target_id)
    ");

    $pinned   = 0;
    $archived = 0;
    if ($status === 'pinned')   $pinned = 1;
    if ($status === 'archived') $archived = 1;

    $stmt = $conn->prepare("
        UPDATE user_aliases
        SET pinned = ?, archived = ?
        WHERE owner_id = ? AND target_id = ?
    ");
    $stmt->bind_param("iiii", $pinned, $archived, $user_id, $target_id);
    $stmt->execute();
    $stmt->close();

    $message = "Status aktualisiert.";
}

// Alle anderen User für Auswahl laden
$other_users = $conn->query("SELECT id, username FROM users WHERE id != $user_id");

// gesetzte Aliasse laden (inkl. pinned/archived)
$aliases = $conn->query("
    SELECT ua.target_id, ua.alias, u.username,
           COALESCE(ua.pinned,0)   AS pinned,
           COALESCE(ua.archived,0) AS archived
    FROM user_aliases ua
    JOIN users u ON u.id = ua.target_id
    WHERE ua.owner_id = $user_id
");
?>


<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Einstellungen</title>
   <!-- <link rel="stylesheet" href="style.css"> -->
    <meta name="viewport"
      content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-orientation" content="portrait">
   
   <script type="module">
      import 'https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.js';
    </script>
</head>

<body>
  <a href="/place/place.php" class="floating-button">
    🖼️
</a>
    <div class="page">

    <div class="settings-header">
      <h1>⚙️ Einstellungen</h1>
      <div class="app-name">Gigachat <span class="version">3.0</span></div>
      <div class="subtitle">Für Vorschläge oder Bugs: <strong>HenRun189</strong></div>
      <div class="subtitle">
         Rolle:
         <?php
           if ($myRole == 3) echo "👑 Owner";
           elseif ($myRole == 2) echo "🛠 Admin";
           elseif ($myRole == 1) echo "🧪 Beta Tester";
           else echo "User";
         ?>
</div>
    </div>

      <div class="layout">

        <!-- LINKE SPALTE -->
        <div class="column">

        <section class="action-grid">
          <a class="action-card" href="#" id="toggle-theme">🌙 Dark / Light</a>
          <a class="action-card" href="#" id="toggle-notifications">🔔 Benachrichtigungen</a>
          <a class="action-card" href="statistik.php">📊 Statistiken</a>
          <a class="action-card" href="create_group.php">👥 Neue Gruppe</a>
          <a class="action-card" href="release-notes-3.0.html">📰 Release Notes</a>
          <a class="action-card" href="bug_report.php">
            🐞 Bug/Vorschlag/Frage
            <?php if ($myRole >= 2): ?> Antworten<?php endif; ?>
          </a>
          <?php if ($myRole >= 1): ?>
            <a class="action-card" href="ideen.html">🛠 (Beta) Funtionen in zukunft?</a>
          <?php endif; ?>
          <?php if ($myRole >= 2): ?>
            <a class="action-card" href="admin.php">🛠 Admin Panel</a>
          <?php endif; ?>
        </section>


        <section class="card back-card">
          <a href="index.php">← Zurück zum Chat</a>
        </section>


        </div>

        <!-- RECHTE SPALTE -->
        <div class="column">
            <section class="card">
              <h2>👤 Account</h2>
            
              <form method="POST" autocomplete="off">
                <input type="text" name="username" placeholder="Benutzername"
                       value="<?= htmlspecialchars($username_value) ?>" required>
            
                <input type="password" name="current_password"
                       placeholder="Aktuelles Passwort" required>
            
                <input type="password" name="new_password"
                       placeholder="Neues Passwort (optional)">
            
                <input type="password" name="confirm_password"
                       placeholder="Passwort bestätigen">
            
                <?php if ($message): ?>
                  <div class="message"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                
                <div class="profile-style-row">

                  <div class="style-field">
                    <label>Farbe</label>
                    <input type="color" name="color"
                           value="<?= htmlspecialchars($user_color) ?>">
                  </div>

                  <div class="style-field">
                    <label>Emoji</label>
                    <input type="text" name="emoji" id="emojiInput"
                           value="<?= htmlspecialchars($user_emoji) ?>"
                           readonly
                           class="emoji-input">
                  </div>

                </div>

                <emoji-picker id="emojiPicker" style="display:none;"></emoji-picker>
                <button type="submit">Speichern</button>
              </form>
            </section>

          <section class="card danger">
            <form method="post"
                  action="admin_actions.php"
                  onsubmit="return confirm('Account wirklich KOMPLETT löschen?');">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="uid" value="<?= (int)$user_id ?>">
              <button class="danger-btn" name="action" value="delete_account">
                ❌ Account endgültig löschen
              </button>
            </form>
            <p class="muted">
              Löscht alle Nachrichten und Daten des Accounts endgültig.
            </p>
          </section>

        </div>

      </div>
    </div>

    <div class="settings-footer">
      <div class="footer-links">
        <a href="impressum.php">Impressum</a>
        <a href="datenschutz.php">Datenschutz</a>
      </div>

      <div class="footer-copy">
        © 2026 gigachat.fun · Alle Rechte bei <strong>Henri Himmelmann</strong>
      </div>
    </div>


</body>

<script>
    // settings-tools.js

    if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js');
}

// ===== Dark/Light =====
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("toggle-theme");
  if (!btn) return;

  // index.php ist die Quelle der Wahrheit
  const isDark = document.body.classList.contains("dark");

  // BUTTON-TEXT RICHTIG SETZEN
  btn.textContent = isDark
    ? "☀️ Light Mode aktivieren"
    : "🌙 Dark Mode aktivieren";

  btn.addEventListener("click", () => {
    // nur umdrehen
    document.body.classList.toggle("dark");

    const nowDark = document.body.classList.contains("dark");
    localStorage.setItem("theme", nowDark ? "dark" : "light");

    btn.textContent = nowDark
      ? "☀️ Light Mode aktivieren"
      : "🌙 Dark Mode aktivieren";
  });
});

(function initTheme(){
  const t = localStorage.getItem("theme");
  if (t === "dark") document.body.classList.add("dark");
})();


// ===== Push (iOS-sicher & robust) =====
const vapidPublicKey = 'BGKaUZpoIpfq4mOBjwj4SnTol4lTpimNI113onzGdUMNMGt0VMiz9sJdYdXwhEeQ_3Z_Jiz7XTZwelw617tcpI8';

function isIOS() {
  return /iPad|iPhone|iPod/.test(navigator.userAgent);
}

function isIOSPWA() {
  return window.matchMedia('(display-mode: standalone)').matches
      || window.navigator.standalone === true;
}

async function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

async function subscribeForPush() {
  if (!('Notification' in window) || !('serviceWorker' in navigator)) {
    alert('Push wird von diesem Browser nicht unterstützt');
    return;
  }

  if (isIOS() && !isIOSPWA()) {
    alert('Auf iOS nur in der installierten Web-App möglich 📱\n\nApp zum Home-Bildschirm hinzufügen.');
    return;
  }

  const reg = await navigator.serviceWorker.ready;

  if (!reg.pushManager) {
    alert('Push nur in der installierten Web-App verfügbar 📱');
    return;
  }

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    alert('Benachrichtigungen abgelehnt');
    return;
  }

  // iOS Race-Condition Fix
  await new Promise(r => setTimeout(r, 300));

  let sub;
  try {
    sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: await urlBase64ToUint8Array(vapidPublicKey)
    });
  } catch (e) {
    alert('Push konnte nicht aktiviert werden 😕\n\nTipp: App löschen und neu installieren.');
    return;
  }

  await fetch('/save-subscription.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(sub)
  });

  alert('Benachrichtigungen aktiviert 🔔');
}

async function unsubscribePush() {
  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.getSubscription();
  if (!sub) return;

  await fetch('unsubscribe_push.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ endpoint: sub.endpoint })
  });

  await sub.unsubscribe();
}

// ===== Button Handling =====
document.addEventListener("DOMContentLoaded", async () => {
  const btn = document.getElementById("toggle-notifications");
  if (!btn) return;

  btn.classList.add("disabled");

  await navigator.serviceWorker.ready;

  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.getSubscription();

  if (sub) {
    btn.textContent = "🔕 Benachrichtigungen deaktivieren";
    btn.dataset.enabled = "1";
  } else {
    btn.textContent = "🔔 Benachrichtigungen aktivieren";
    btn.dataset.enabled = "0";
  }

  btn.classList.remove("disabled");

  btn.addEventListener("click", async () => {
    const enabled = btn.dataset.enabled === "1";

    if (!enabled) {
      await subscribeForPush();
      btn.textContent = "🔕 Benachrichtigungen deaktivieren";
      btn.dataset.enabled = "1";
    } else {
      await unsubscribePush();
      btn.textContent = "🔔 Benachrichtigungen aktivieren";
      btn.dataset.enabled = "0";
    }
  });
});

document.addEventListener("DOMContentLoaded", () => {
  const input = document.getElementById("emojiInput");
  const picker = document.getElementById("emojiPicker");

  input.addEventListener("click", () => {
    picker.style.display = picker.style.display === "none" ? "block" : "none";
  });

  picker.addEventListener("emoji-click", event => {
    input.value = event.detail.unicode;
    picker.style.display = "none";
  });
});

function checkOrientation() {
  if (window.innerWidth > window.innerHeight && window.innerWidth < 900) {
    document.body.classList.add("force-portrait");
  } else {
    document.body.classList.remove("force-portrait");
  }
}

window.addEventListener("resize", checkOrientation);
window.addEventListener("orientationchange", checkOrientation);
checkOrientation();

</script>
</html>
<style>
/* =======================
   THEME
======================= */
:root {
  --bg: #f4f6fb;
  --card: #ffffff;
  --text: #0f1115;
  --muted: #555;
  --accent: #4f7cff;
  --danger: #ff5c5c;
  --radius: 16px;
}

body.dark {
  --bg: #0f1115;
  --card: #1a1d24;
  --text: #eaeaf0;
  --muted: #9aa0b4;
}

/* =======================
   BASE
======================= */
body {
  margin: 0;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--bg);
  color: var(--text);
}

.page {
  max-width: 900px;
  margin: auto;
  padding: 24px;
}

/* =======================
   HEADER
======================= */
.settings-header {
  text-align: center;
  margin-bottom: 28px;
}

.settings-header h1 {
  margin: 0;
  font-size: 32px;
}

.app-name {
  font-size: 18px;
  font-weight: 600;
  opacity: 0.85;
}

.version {
  font-size: 16px;
  opacity: 0.6;
}

.subtitle {
  font-size: 13px;
  color: var(--muted);
  margin-top: 4px;
}

/* =======================
   LAYOUT
======================= */
.layout {
  display: grid;
  grid-template-columns: 1fr 1.2fr;
  gap: 24px;
}

.column {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* =======================
   ACTION BUTTONS
======================= */

/* PC + iPad: untereinander, gleiche Breite */
.action-grid {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.action-card {
  background: var(--card);
  padding: 18px;
  border-radius: var(--radius);
  font-weight: 600;
  color: var(--text);
  text-decoration: none;
  box-shadow: 0 6px 20px rgba(0,0,0,0.15);
  text-align: left;
}

.action-card:hover {
  transform: translateY(-2px);
}

/* =======================
   CARDS
======================= */
.card {
  background: var(--card);
  border-radius: var(--radius);
  padding: 16px;
}

.back-card a {
  display: block;
  color: var(--accent);
  font-weight: 600;
  text-decoration: none;
}

/* Danger */
.danger {
  border: 1px solid var(--danger);
}

.danger-btn {
  width: 100%;
  background: var(--danger);
  color: white;
  padding: 14px;
  border-radius: 12px;
  font-weight: bold;
  border: none;
}

/* =======================
   FORMS
======================= */
form {
  display: grid;
  gap: 12px;
}

input {
  padding: 12px;
  border-radius: 10px;
  border: none;
  font-size: 16px;
}

button.primary {
  background: var(--accent);
  color: white;
  padding: 12px;
  border-radius: 10px;
  font-weight: bold;
}

.message {
  color: var(--danger);
  text-align: center;
}

.muted {
  color: var(--muted);
  font-size: 14px;
}

/* =======================
   FOOTER (kein unnötiger Abstand!)
======================= */
.settings-footer {
  margin-top: 40px;
  padding-top: 16px;
  border-top: 1px solid rgba(255,255,255,0.1);
  text-align: center;
  font-size: 13px;
  color: var(--muted);
}

.footer-links {
  display: flex;
  justify-content: center;
  gap: 20px;
  margin-bottom: 8px;
}

.footer-links a {
  color: var(--muted);
  text-decoration: none;
}

.footer-links a:hover {
  color: var(--text);
}

/* =======================
   RESPONSIVE
======================= */

/* iPhone: 1 Spalte + 2x2 Buttons */
@media (max-width: 600px) {
  .layout {
    grid-template-columns: 1fr;
  }

  .action-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
  }

  .action-card {
    text-align: center;
    padding: 20px;
  }

  .page {
    padding: 16px;
  }
}


.profile-style-row {
  display: flex;
  gap: 20px;
  align-items: flex-end;
  margin-top: 15px;
}

.style-field {
  display: flex;
  flex-direction: column;
  font-size: 14px;
}

.emoji-input {
  width: 60px;
  font-size: 22px;
  text-align: center;
  cursor: pointer;
}

@media (max-width: 600px) {
  .profile-style-row {
    flex-direction: column;
    gap: 10px;
  }
}

.floating-button{
position:fixed;

bottom:20px;
left:20px;

width:52px;
height:52px;

border-radius:50%;

background:rgba(15,23,42,0.9);
border:1px solid rgba(55,65,81,0.9);

display:flex;
align-items:center;
justify-content:center;

font-size:20px;
color:white;
text-decoration:none;

backdrop-filter:blur(10px);

z-index:999;

transition:all .2s ease;
}

.floating-button:hover{
transform:scale(1.08);
background:rgba(30,41,59,0.95);
}
</style>