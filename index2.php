<?php
session_start();

if (!isset($_SESSION['role']) || (int)$_SESSION['role'] < 1) {
    header("Location: index.php");
    exit;
}

/* ===== DEBUG ===== */
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

/* ===== PHP TIMEZONE ===== */
date_default_timezone_set('Europe/Berlin');

/* ===== DB VERBINDUNG ===== */
$conn = new mysqli('localhost', 'root', 'WBNhN16u', 'chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($myRole);
$stmt->fetch();
$stmt->close();

$_SESSION['role'] = (int)$myRole;

if ($conn->connect_error) {
    die("DB-Verbindung fehlgeschlagen: " . $conn->connect_error);
}

/* MySQL Zeitzone */
$conn->query("SET time_zone = 'Europe/Berlin'");

/* ===== STATS ===== */
require_once __DIR__ . '/config_stats.php';
require_once __DIR__ . '/stats_bootstrap.php';

if (
    isset($_SESSION['user_id']) &&
    function_exists('record_presence_and_stats')
) {
    record_presence_and_stats(
        $conn,
        (int)$_SESSION['user_id'],
        basename($_SERVER['PHP_SELF'])
    );
}


/* ===== REMEMBER-ME LOGIN ===== */
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember'])) {
    list($selector, $token) = explode(':', $_COOKIE['remember'], 2);

    if ($selector && $token) {
        $token = base64_decode($token);

        if (strlen($token) === 32) {
            $stmt = $conn->prepare("
                SELECT user_id, hashed_token 
                FROM remember_tokens 
                WHERE selector = ? AND expires_at > NOW()
            ");
            $stmt->bind_param("s", $selector);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($row = $res->fetch_assoc()) {
                if (password_verify($token, $row['hashed_token'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$row['user_id'];
                    $_SESSION['gigacoin'] = (int)$row['gigacoin'];

                    $u = $conn->prepare("SELECT username FROM users WHERE id = ?");
                    $u->bind_param("i", $_SESSION['user_id']);
                    $u->execute();
                    $_SESSION['username'] = $u->get_result()->fetch_assoc()['username'] ?? null;
                    $u->close();
                }
            }
            $stmt->close();
        }
    }
}

/* ===== LOGIN CHECK ===== */
if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];

/* ===== LAST SEEN UPDATE ===== */
$conn->query("UPDATE users SET last_seen = NOW() WHERE id = $user_id");

/* ===== EIGENER GIGASCORE ===== */
$stmt = $conn->prepare("SELECT gigascore, active_theme FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

$myScore = (int)($row['gigascore'] ?? 0);
$_SESSION['active_theme'] = $row['active_theme'] ?? 'default';

$stmt->close();

/* ===== AKTUELLEN CHAT ===== */
$current_chat = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (
    $current_chat === 0 &&
    isset($_COOKIE['last_chat_user']) &&
    !isset($_GET['from_push'])
) {
    $lc = (int)$_COOKIE['last_chat_user'];
    if ($lc > 0 && $lc !== $user_id) {
        $current_chat = $lc;
    }
}

/* ===== USER & GRUPPEN ===== */

$groups = $conn->query("
    SELECT g.id, g.name
    FROM chat_groups g
    JOIN group_members gm ON g.id = gm.group_id
    WHERE gm.user_id = $user_id
    ORDER BY g.id ASC
");

// Markiere ungelesene Nachrichten als gelesen
if($current_chat > 0){
    $conn->query("UPDATE messages 
                  SET read_at = NOW() 
                  WHERE sender_id = $current_chat 
                    AND receiver_id = $user_id
                    AND read_at IS NULL");
}

// Kontakte laden
$contacts = $conn->query("
    SELECT u.id, u.username, ua.alias, u.color, u.emoji
    FROM users u
    LEFT JOIN user_aliases ua 
        ON ua.owner_id = $user_id AND ua.target_id = u.id
    WHERE u.id != $user_id
");
if(!$contacts){
    die("Fehler beim Laden der Kontakte: " . $conn->error);
}


// Aktuellen Chat-Partner laden
$currentUser = null;
if ($current_chat > 0) {
    $stmtCU = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmtCU->bind_param("i", $current_chat);
    $stmtCU->execute();
    $resCU = $stmtCU->get_result();
    $currentUser = $resCU->fetch_assoc();
    $stmtCU->close();
}


if (isset($_POST['activate_theme'])) {

    $theme = $_POST['activate_theme'];

    $allowed = [
        'default',
        'purple',
        'ocean',
        'forest',
        'sunset',
        'cyber',
        'midnight_cherry',
        'aurora_borealis'
    ];

    if (in_array($theme, $allowed)) {

        $stmt = $conn->prepare("UPDATE users SET active_theme = ? WHERE id = ?");
        $stmt->bind_param("si", $theme, $user_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['active_theme'] = $theme;

        header("Location: index.php");
        exit;
    }
}
?>
<!--HTML -->
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo.png">
    <title>Gigachat</title>
    <link rel="stylesheet" href="wie.css">

    <!-- PWA -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#111111">
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js')
            .then(reg => console.log('SW registered', reg.scope))
            .catch(err => console.error('SW failed', err));
    }
    </script>
</head>
<body class="theme-<?php echo $_SESSION['active_theme'] ?? 'default'; ?>">

  <a href="/place/place.php" class="floating-button">
    🖼️
</a>


  <!-- benachichtigung reminder -->
<div id="push-modal" class="push-modal" style="display:none;">
  <div class="push-card">
    <h3>🔔 Benachrichtigungen aktivieren</h3>
    <p id="push-text"></p>

    <div class="push-actions">
      <button id="push-action">Aktivieren</button>
      <button id="push-close" class="ghost">Später</button>
    </div>
  </div>
</div>


<!--
<footer class="tob-bar">
        <button class="top-left" onclick="window.location.href='create_group.php'">🆕 Gruppe</button>
        <button class="top-left" onclick="window.location.href='settings.php'">⚙️ Einstellungen</button>
    <strong> GigaChat 2.7</strong>
    <a href="release-notes-2.7.html" target="_blank">
        <span style="text-decoration:underline;">Release-Notes 2.7</span>
    </a>
    | <a href="impressum.html">Impressum</a>
    | <a href="datenschutz.html">Datenschutz</a>
    | Für Vorschläge oder Bugs: <strong>HenRun189</strong>
</footer>
-->
</div>

<div class="gigacoin">
    🪙 <?php echo (int)$_SESSION['gigacoin']; ?> Gigacoins
</div>

<div class="container">
    <!-- Chatbereich links -->
    <div class="chat-card">
        <h2 id="chatWith" class="chat-header clickable-group">
          <span class="chat-left">
            Chat mit: <span id="chatWithName">
              <?php echo htmlspecialchars($currentUser['username'] ?? 'NIEMANDEM CRAZY (BETA)'); ?>
            </span>
            <span class="arrow">▼</span>
          </span>

          <div id="userStatus" style="font-size:12px;opacity:.8;">
          –
          </div>


          <span class="chat-center">
            <span class="score-number" id="theirGigaScore">–</span>
            <span class="score-label">GigaScore</span>
          </span>

          <span class="chat-right">
            <span class="score-number">
              <?php echo (int)$myScore; ?>
            </span>
            <span class="score-label">Dein GigaScore</span>
          </span>
        </h2>


        <!-- Menü für Gruppen oder Personen -->
        <div id="chatMenu" class="chat-menu" style="display:none;">
            <h3 id="menuTitle"></h3>
            <ul id="menuMembers"></ul>
            <button id="menuActionBtn" style="display:none;"></button>
        </div>

        <div id="chatbox"></div>
            <form id="chatForm" class="chat-input">
                <input type="hidden" name="receiver_id" id="receiver_id" value="<?php echo $current_chat; ?>">
                <input type="text" name="message" id="message" placeholder="Nachricht">

                <!-- verstecktes File-Input -->
                <input type="file" id="imageInput" accept="image/*" style="display:none">

                <!-- GIF-Button und Suchfeld -->

                <button type="button" id="gifBtn">GIF</button>

                <input id="gifSearch" placeholder="GIF suchen…" style="display:none">
                <div id="gifResults"></div>

                <!-- Plus-Button für Bilder -->
                <button type="button" id="addImageBtn">➕</button>

                <button type="submit">Senden</button>
            </form>
        </div>

        <!-- Benutzerliste rechts -->
        <div class="users-card">
            <?php
            $users = $conn->query("
                SELECT u.id, u.username, ua.alias, u.color, u.emoji 
                FROM users u
                LEFT JOIN user_aliases ua 
                    ON ua.owner_id = $user_id AND ua.target_id = u.id
                WHERE u.id != $user_id
                ORDER BY u.id ASC
            ");
            ?>
            <ul class="users-list">
                <?php while($row = $users->fetch_assoc()): ?>
                    <li data-id="<?= $row['id'] ?>" data-type="user">
                        <div class="user-btn" style="color: <?= htmlspecialchars($row['color']) ?>">
                            <?= htmlspecialchars(($row['emoji'] ? $row['emoji'] . ' ' : '') . ($row['alias'] ?: $row['username'])) ?>
                        </div>
                    </li>
                <?php endwhile; ?>
                
                <?php while($row = $groups->fetch_assoc()): ?>
                    <li data-id="<?= $row['id'] ?>" data-type="group">
                        <div class="user-btn">
                            <?= htmlspecialchars($row['name']) ?>
                        </div>
                    </li>
                <?php endwhile; ?>
                
                <li style="list-style: none;">
                    <div class="user-btn" onclick="window.open('menu/')" style="background:#6b6bff; color:#fff; cursor:pointer; text-align:center;">
                        📱 Menü andere Webseiten
                    </div>
                </li>
                <li style="list-style: none;">
                    <div class="user-btn" style="background:#ff6b6b; color:#fff; cursor:pointer; text-align:center;" onclick="window.location.href='logout.php'">
                        🔒 Logout
                    </div>
                </li>
            </ul>
        </div>
</div>

<!--
<footer class="footer-bottom">
    Alle Rechte beim Owner <strong>Henri Himmelmann</strong>.
</footer>
-->

<div id="imageOverlay" class="image-overlay" style="display:none;">
  <div class="image-overlay-inner">
    <img id="overlayImg" src="" alt="">
    <div class="image-overlay-actions">
      <button id="overlayClose">Schließen</button>
      <button id="overlayOpenTab">In neuem Tab (nicht in App drücken)</button>
      <button id="overlayDownload">Speichern (nicht in App drücken)</button>
    </div>
  </div>
</div>
<div id="notesOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999;">
  <div style="background:#111;color:#fff;max-width:600px;margin:10vh auto;padding:15px;border-radius:12px;">
    <h3>📝 Private Notizen</h3>
    <textarea id="notesText" style="width:100%;height:300px;"></textarea>
    <div style="margin-top:10px;text-align:right;">
      <button onclick="saveNotes()">💾 Speichern</button>
      <button onclick="closeNotes()">Schließen</button>
    </div>
  </div>
</div>

<!-- GIF Overlay (Mobile-friendly) -->
<div id="gifOverlay" class="gif-overlay" style="display:none;">
  <div class="gif-panel">
    <div class="gif-header">
      <input id="gifSearchOverlay" placeholder="GIF suchen…" />
      <button id="gifClose">✕</button>
    </div>

    <div id="gifResultsOverlay" class="gif-results"></div>
  </div>
</div>


</body>
</html>

<!-- Konfetti-Bibliothek -->
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>

<script>
let currentChat = <?php echo $current_chat; ?>;
receiver_id = currentChat;
let lastUnread = {};
let currentType = 'user';
let lastId = 0;
let totalNewMessages = 0;
const chatbox = document.getElementById("chatbox");
const chatForm = document.getElementById("chatForm");
const messageInput = document.getElementById("message");
const cooldown = 500;
let canSend = true;
const chatWithEl = document.getElementById("chatWith");
let loadingOlder = false;
let reachedTop = false;
let autoScroll = true;
const USER_ID = <?php echo (int)$user_id; ?>;
let initialLoading = true;

const SESSION_USER_ID = <?php echo (int)$_SESSION['user_id']; ?>;
const SESSION_ROLE = <?php echo (int)($_SESSION['role'] ?? 0); ?>;

function toggleMode() {
  document.body.classList.toggle('dark');

  // merken im Browser
  if (document.body.classList.contains('dark')) {
    localStorage.setItem('theme', 'dark');
  } else {
    localStorage.setItem('theme', 'light');
  }
}

chatbox.addEventListener('scroll', () => {
  // AutoScroll
  const nearBottom =
    chatbox.scrollTop + chatbox.clientHeight >= chatbox.scrollHeight - 50;
  autoScroll = nearBottom;

  // Infinite Scroll nach oben
  if (!initialLoading && !reachedTop && chatbox.scrollTop <= 120 && !loadingOlder) {
    loadOlderMessages();
  }
});

// === Nachrichten laden ===
function loadMessages(newOnly = true) {
  let url = currentType === 'user' 
    ? `get_messages.php?user_id=${currentChat}&last_id=${newOnly ? lastId : 0}&limit=${newOnly ? 30 : 80}`
    : `get_group_messages.php?group_id=${currentChat}&last_id=${newOnly ? lastId : 0}&limit=${newOnly ? 30 : 80}`;

  fetch(url)
    .then(r => r.json())
    .then(data => {
      if (!data || data.length === 0) {
        if (autoScroll) chatbox.scrollTop = chatbox.scrollHeight;
        return;
      }

      if (!newOnly) {
        chatbox.innerHTML = "";
        window.lastDay = null;
        lastId = 0;
      }

      const fragment = document.createDocumentFragment();

      data.forEach(msg => {

        if (document.getElementById("msg_" + msg.id)) return;

        const msgDate = new Date(msg.created_at);
        const dayString = msgDate.toLocaleDateString('de-DE', {
          weekday:'short', day:'2-digit', month:'2-digit', year:'numeric'
        });

        if (window.lastDay !== dayString) {
          const dateDiv = document.createElement("div");
          dateDiv.className = "chat-date";
          dateDiv.textContent = dayString;
          fragment.appendChild(dateDiv);
          window.lastDay = dayString;
        }

        const div = document.createElement("div");
        div.id = "msg_" + msg.id;
        div.dataset.id = msg.id;
        div.className = "message " + (msg.sender_id == USER_ID ? "me" : "");

        if (msg.deleted == 1) {
          div.classList.add("deleted");
        }

        const color = msg.color && msg.color !== 'none' ? msg.color : 'inherit';
        const emoji = msg.emoji && msg.emoji !== 'none' ? msg.emoji + " " : "";
        const timeString = msgDate.toLocaleTimeString('de-DE', { hour:'2-digit', minute:'2-digit' });

        let content = "";
        let status = "";

        if (msg.deleted == 1) {
          content = `<span class="msg-deleted">Nachricht gelöscht</span>`;
        }
        else if (msg.gif_url) {
          content = `<img loading="lazy" src="${msg.gif_url}" class="chat-gif">`;
        }
        else if (msg.is_image == 1 && msg.file_path) {
          content = `<img loading="lazy" src="${msg.file_path}" class="chat-image" data-full="${msg.file_path}">`;
        }
        else if (msg.file_path) {
          const fname = msg.original_name || "Datei";
          content = `<a href="${msg.file_path}" target="_blank">📎 ${fname}</a>`;
        }
        else {
          content = linkify(msg.text || "");
        }

        if (msg.edited == 1) status += "✏️ ";
        if (msg.sender_id == USER_ID) {
          status += msg.read_at ? "👁️" : "✔️";
        }

        div.innerHTML = `
          <b style="color:${color}">
            ${emoji}${msg.username || (msg.sender_id == USER_ID ? "Du" : "Unbekannt")}
          </b>
          <span class="msg-main">${content}</span>
          <span class="msg-status">${msg.deleted == 1 ? "" : status}</span>
          <span class="msg-time">${timeString}</span>
        `;

        fragment.appendChild(div);

        lastId = Math.max(lastId, msg.id);
      });

      chatbox.appendChild(fragment);

    if (autoScroll) {
        // Alle Bilder im aktuellen Fragment abwarten bevor gescrollt wird,
        // sonst scrollt es zu früh und landet nicht ganz unten
        const newImages = Array.from(chatbox.querySelectorAll('img')).filter(img => !img.complete);

        if (newImages.length > 0) {
            // Bilder noch am Laden → warten bis alle fertig, dann scrollen
            const promises = newImages.map(img => new Promise(res => {
                img.onload  = res;
                img.onerror = res; // auch bei Fehler weitermachen
            }));

            Promise.all(promises).then(() => {
                requestAnimationFrame(() => {
                    chatbox.scrollTop = chatbox.scrollHeight;
                });
            });
        } else {
            // Kein Bild → sofort scrollen
            requestAnimationFrame(() => {
                chatbox.scrollTop = chatbox.scrollHeight;
            });
        }
    }

      clearNotificationsForChat(currentChat, currentType);
    });
}

function updateTitleBadge() {
    let newTotal = 0;
    document.querySelectorAll('.users-list .new-badge').forEach(el => {
        newTotal += parseInt(el.textContent) || 0;
    });

    totalNewMessages = newTotal;

    if(totalNewMessages > 0){
        document.title = `(${totalNewMessages}) Neue Nachricht${totalNewMessages > 1 ? 'en' : ''} – Chat`;
    } else {
        document.title = 'Chat';
    }
}

function gigaScoreEmoji(score) {
    if (SESSION_ROLE >= 1) {
         const s = parseInt(score || 0);
        if (s >= 6400) return "💠";
        if (s >= 5600) return "🐉";
        if (s >= 4900) return "👑";
        if (s >= 4200) return "💎";
        if (s >= 3600) return "🦅";
        if (s >= 3000) return "🏆";
        if (s >= 2400) return "🎮";
        if (s >= 1900) return "🧠";
        if (s >= 1400) return "🚀";
        if (s >= 1000) return "🔥";
        if (s >= 700)  return "⚡";
        if (s >= 500)  return "🍗";
        if (s >= 400)  return "🐔";
        if (s >= 300)  return "🐥";
        if (s >= 200)  return "🌲";
        if (s >= 100)  return "🪴";
        if (s >= 50)  return "🍀";
        if (s >= 10)   return "🌱";
        return "🍼"; 
    } else {
        const s = parseInt(score || 0, 10);
        if (s >= 2000) return "👑";
        if (s >= 1000) return "💎";
        if (s >= 500)  return "⭐";
        if (s >= 150)  return "🔥";
        if (s >= 50)   return "⚡";
          return "🌱";
        }
}
function loadContacts() {
  fetch("get_contacts.php?current_chat=" + currentChat)
    .then(r => r.json())
    .then(data => {
      const list = document.querySelector(".users-list");
      if (!list) return;

      list.innerHTML = "";

      const normal = [];
      const archived = [];

      data.forEach(c => {
        // Gruppen und normale/pinned User oben
        if (c.type !== 'user' || (c.archived ?? 0) === 0) normal.push(c);
        else archived.push(c);
      });

      function buildLi(c) {
        const li = document.createElement("li");
        li.dataset.type = c.type || 'user';
        li.dataset.id = c.id;
                      
        if (c.type === 'group' && c.new > 0) {
          li.classList.add('has-new');
        }

        // für Header-GigaScore (dein "Sein GigaScore")
        li.dataset.gigascore = c.gigascore ?? 0;

        const color = c.color && c.color !== 'none' ? c.color : 'inherit';
        const emoji = c.emoji && c.emoji !== 'none' ? (c.emoji + " ") : "";

        const isPinned = c.pinned && c.pinned > 0;
        if (isPinned) li.classList.add('pinned');

        const rightEmoji = (c.type === 'group')
          ? "👥"
          : gigaScoreEmoji(c.gigascore ?? 0);

        li.innerHTML = `
          <div class="user-btn" style="color:${color}">
            <span class="left">
              <span class="name">${emoji}${c.username}</span>
              ${c.new > 0 ? `<span class="new-badge">${c.new}</span>` : ""}
            </span>
            <span class="score-emoji" title="GigaScore">${rightEmoji}</span>
          </div>
        `;
        return li;
      }

      // 1) normale + pinned + gruppen
      normal.forEach(c => list.appendChild(buildLi(c)));

      // 2) Archiv
      if (archived.length > 0) {
        const headerLi = document.createElement("li");
        headerLi.className = "archive-header";
        headerLi.innerHTML = `
          <div class="user-btn archive-toggle">
            📁 Archivierte Kontakte <span class="arrow">▼</span>
          </div>
        `;
        list.appendChild(headerLi);

        const archContainer = document.createElement("ul");
        archContainer.className = "archive-list";
        archContainer.style.display = "none";
        list.appendChild(archContainer);

        archived.forEach(c => archContainer.appendChild(buildLi(c)));

        headerLi.querySelector(".archive-toggle").addEventListener("click", () => {
          const visible = archContainer.style.display !== "none";
          archContainer.style.display = visible ? "none" : "block";
        });
      }

      // Menü-Button
      const liMenuBtn = document.createElement("li");
      liMenuBtn.style.listStyle = "none";
      liMenuBtn.innerHTML = `
        <div class="user-btn" style="background:#6b6bff; color:#fff; cursor:pointer; text-align:center;"
             onclick="window.open('menu/')">
          📱 Menü andere Webseiten
        </div>
      `;
      list.appendChild(liMenuBtn);

      // Notizen-Button
      const liNotesBtn = document.createElement("li");
      liNotesBtn.style.listStyle = "none";
      liNotesBtn.innerHTML = `
        <div class="user-btn" style="background:#00b894;color:#fff;text-align:center;cursor:pointer;">
          📝 Notizen (privat)
        </div>
      `;
      liNotesBtn.onclick = openNotes;
      list.appendChild(liNotesBtn);


      // Menü-Button
      const liEinstellungenBtn = document.createElement("li");
      liEinstellungenBtn.style.listStyle = "none";
      liEinstellungenBtn.innerHTML = `
        <div class="user-btn" style="background: #606060; color: #fff; cursor:pointer; text-align:center;"
             onclick="window.open('settings.php')">
          ⚙️ Einstellungen
        </div>
      `;
      list.appendChild(liEinstellungenBtn);

      // Logout-Button
      const liLogoutBtn = document.createElement("li");
      liLogoutBtn.style.listStyle = "none";
      liLogoutBtn.innerHTML = `
        <div class="user-btn" style="background:#ff6b6b; color:#fff; cursor:pointer; text-align:center;"
             onclick="window.location.href='logout.php'">
          🔒 Logout
        </div>
      `;
      list.appendChild(liLogoutBtn);

      updateTitleBadge();
    })
    .catch(err => {
      console.error("loadContacts failed", err);
    });
}

function updateChatWithName(name) {
    document.getElementById("chatWithName").textContent = name;
}

// === Nachricht senden ===
chatForm.addEventListener("submit", e => {
    e.preventDefault();
    if(!canSend) return;
    const text = messageInput.value.trim();
    if(text.length < 2) return;

    canSend = false;
    setTimeout(() => canSend = true, cooldown);

    let formData = new FormData();
    formData.append("message", text);
    if(currentType === 'user'){
        formData.append("receiver_id", currentChat);
    } else {
        formData.append("group_id", currentChat);
    }

    const sendUrl = currentType === 'user' ? "send_message.php" : "send_group_message.php";

    fetch(sendUrl, { method:"POST", body: formData })
        .then(() => {
            messageInput.value = "";
            loadMessages(true);
        });
});

const chatMenu = document.getElementById("chatMenu");
const menuTitle = document.getElementById("menuTitle");
const menuMembersList = document.getElementById("menuMembers");
const menuActionBtn = document.getElementById("menuActionBtn");

// Klick auf Chatname
chatWithEl.addEventListener("click", () => {
    openMenu(currentType, currentChat, chatWithEl.textContent.trim());
});

// Klick außerhalb schließt Menü
document.addEventListener("click", (e)=>{
    if(!chatMenu.contains(e.target) && e.target !== chatWithEl){
        chatMenu.style.display = "none";
    }
});

// === Push-Benachrichtigungen ===

const vapidPublicKey = 'BGKaUZpoIpfq4mOBjwj4SnTol4lTpimNI113onzGdUMNMGt0VMiz9sJdYdXwhEeQ_3Z_Jiz7XTZwelw617tcpI8';

async function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

async function subscribeForPush() {
  if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
    alert('Push wird von diesem Browser nicht unterstützt');
    return;
  }

    const permission = await Notification.requestPermission(); // muss im Klick-Handler laufen
  if (permission !== 'granted') {
    alert('Benachrichtigungen nicht erlaubt');
    return;
  }

  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: await urlBase64ToUint8Array(vapidPublicKey)
  });

  await fetch('/save-subscription.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(sub)
  });

  alert('Benachrichtigungen aktiviert');
}
/*
document.getElementById('enable-notifications').addEventListener('click', () => {
  subscribeForPush().catch(console.error);
});*/


// === Init ===
document.addEventListener("DOMContentLoaded", () => {
    
    // Nur einmal pro Browser: Version-Flag prüfen für konfetti 
    const VERSION_FLAG = 'gigachat_3_0_shown';
    const alreadyShown = localStorage.getItem(VERSION_FLAG) === '1';

    if (!alreadyShown) {
        showReleaseBanner();
        localStorage.setItem(VERSION_FLAG, '1');
    }
   
    const saved = localStorage.getItem('theme');

    if (saved === 'light') {
      document.body.classList.remove('dark');
    } else {
      // default = dark
      document.body.classList.add('dark');
    }

    loadContacts();  

    loadMessages(false);

    setTimeout(() => {
      chatbox.scrollTop = chatbox.scrollHeight;
      initialLoading = false;
    }, 400);


    setTimeout(() => {

      let tick = 0;
                      
      setInterval(() => {
        if (document.hidden) return;
                      
        tick++;
                      
        if (currentChat) {
          loadMessages(true);
          loadUserStatus();
        }
                      
        // Kontakte nur alle 15 Sekunden neu laden
        if (tick % 3 === 0) {
          loadContacts();
          updateTitleBadge();
        }
                      
      }, 5000);

    }, 800);



    const usersList = document.querySelector(".users-list");


    function switchChat(el) {
      // ── State komplett zurücksetzen ──────────────────────────────
      lastId         = 0;
      reachedTop     = false;   // FIX: sonst lädt alter Chat keinen Older-Load mehr
      loadingOlder   = false;   // FIX: sonst wird loadOlderMessages() geblockt
      initialLoading = true;    // FIX: HAUPTBUG — verhindert dass der Scroll-Listener
                                //      auf dem leeren Chatbox sofort loadOlderMessages() aufruft
      autoScroll     = true;
      chatbox.innerHTML = '';
      window.lastDay    = null;

      // ── Chat-Metadaten setzen ────────────────────────────────────
      currentChat  = parseInt(el.parentElement.dataset.id);
      currentType = el.parentElement.dataset.type || 'user';

      document.cookie = "last_chat_user=" + currentChat +
                        "; max-age=" + (60 * 60 * 24 * 30) + "; path=/";

      document.getElementById("receiver_id").value = currentChat;
      updateChatWithName(el.textContent.trim());

      const li = el.parentElement;
      currentChatGigaScore = li.dataset.gigascore || 0;
      document.getElementById('theirGigaScore').textContent = currentChatGigaScore;

      // ── Nachrichten laden + danach scrollen & Lock freigeben ─────
      loadMessages(false);

      setTimeout(() => {
          autoScroll           = true;
          chatbox.scrollTop    = chatbox.scrollHeight;
          initialLoading       = false;   // FIX: erst NACH dem Scroll freigeben
      }, 650); // etwas länger als vorher (250ms), damit Bilder geladen sind

      // ── Badges, Status, Notifications ───────────────────────────
      fetch(`mark_read.php?user_id=${currentChat}`);

      if (currentType === 'group') {
          fetch(`mark_group_read.php?group_id=${currentChat}`);
      }

      loadContacts();
      updateTitleBadge();
      clearNotificationsForChat(currentChat, currentType);
      notifySWChatState();
      loadUserStatus();
    }

    usersList.addEventListener("click", e => {
        const btn = e.target.closest(".user-btn");
        if (!btn) return;

        // Archiv-Header NICHT als Chat behandeln
        if (btn.classList.contains('archive-toggle')) {
            return;
        }

        const li = btn.parentElement;
        let currentChatGigaScore = 0;
        switchChat(btn);

        const type = li.dataset.type || 'user';
        const id   = parseInt(li.dataset.id);
        const name = btn.textContent.trim();

        openMenu(type, id, name);

        notifySWChatState();
    });

    const overlay      = document.getElementById('imageOverlay');
    const overlayImg   = document.getElementById('overlayImg');
    const btnClose     = document.getElementById('overlayClose');
    const btnOpenTab   = document.getElementById('overlayOpenTab');
    const btnDownload  = document.getElementById('overlayDownload');
    let currentImgUrl  = null;

    // Delegation: auf Bilder im Chat reagieren
    chatbox.addEventListener('click', e => {
      const img = e.target.closest('.chat-image');
      if (!img) return;

      currentImgUrl = img.getAttribute('data-full') || img.src;
      overlayImg.src = currentImgUrl;
      overlay.style.display = 'flex';
    });

    btnClose.addEventListener('click', () => {
      overlay.style.display = 'none';
      overlayImg.src = '';
    });

    btnOpenTab.addEventListener('click', () => {
      if (!currentImgUrl) return;

      const newWindow = window.open(currentImgUrl, '_blank');

      // iOS PWA blockt das manchmal → Fallback
      if (!newWindow) {
        window.location.href = `https://www.google.com/url?q=${encodeURIComponent(currentImgUrl)}`;
      }
    });


    btnDownload.addEventListener('click', () => {
      if (!currentImgUrl) return;
      const a = document.createElement('a');
      a.href = currentImgUrl;
      a.download = currentImgUrl.split('/').pop();
      a.target = '_blank'; // erzwingt Browser-UI
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    });


    // Klick auf dunklen Hintergrund schließt Overlay
    overlay.addEventListener('click', e => {
      if (e.target === overlay) {
        overlay.style.display = 'none';
        overlayImg.src = '';
      }
    });

});

function openMenu(type, chatId, chatName){
    menuMembersList.innerHTML = "";
    menuActionBtn.style.display = "none";

    if(type === "group"){
        menuTitle.textContent = "Gruppenmitglieder";

        // 1. Gruppeninfo + Owner prüfen
        fetch(`get_group_info.php?group_id=${chatId}`)
            .then(r => r.json())
            .then(info => {
                const isOwner = info.is_owner;

                // 2. Mitglieder laden
                fetch(`get_group_members.php?group_id=${chatId}`)
                    .then(r => r.json())
                    .then(data => {
                        data.forEach(m => {
                            const li = document.createElement("li");
                            li.textContent = (m.emoji ? m.emoji + " " : "") + (m.alias || m.username);

                            // Entfernen‑Button nur wenn Owner und nicht er selbst
                            if (isOwner && m.id !== <?php echo $user_id; ?>) {
                                const btn = document.createElement("button");
                                btn.textContent = "Entfernen";
                                btn.style.marginLeft = "8px";
                                btn.onclick = () => {
                                    if(confirm("Benutzer aus Gruppe entfernen?")){
                                        fetch(`remove_from_group.php?group_id=${chatId}&user_id=${m.id}`)
                                            .then(()=> openMenu("group", chatId, chatName)); // neu laden
                                    }
                                };
                                li.appendChild(btn);
                            }

                            menuMembersList.appendChild(li);
                        });

                        // Gruppe verlassen (alle)
                        menuActionBtn.style.display = "block";
                        menuActionBtn.textContent = "🚪 Gruppe verlassen";
                        menuActionBtn.onclick = () => {
                            if(confirm("Willst du die Gruppe wirklich verlassen?")){
                                fetch(`leave_group.php?group_id=${chatId}`).then(()=>{
                                    alert("Du hast die Gruppe verlassen.");
                                    chatMenu.style.display = "none";
                                    loadContacts();
                                    chatbox.innerHTML = "";
                                });
                            }
                        };

                        // Extra‑Buttons nur für Owner
                        if (isOwner) {
                            const addBtn = document.createElement("button");
                            addBtn.textContent = "➕ Benutzer hinzufügen";
                            addBtn.onclick = () => {
                                const uid = prompt("User‑ID, die du hinzufügen willst:");
                                if(uid){
                                    fetch(`add_to_group.php?group_id=${chatId}&user_id=${encodeURIComponent(uid)}`)
                                        .then(()=> openMenu("group", chatId, chatName));
                                }
                            };
                            menuMembersList.appendChild(addBtn);
                        }
                    });
            });

        } else if (type === "user") {
            menuTitle.textContent = "Kontakt";
        
            // Name anzeigen
            const nameLi = document.createElement("li");
            nameLi.textContent = chatName;
            menuMembersList.appendChild(nameLi);
        
            // Alias-Anzeige / -Bearbeitung
            const aliasLi = document.createElement("li");
            aliasLi.innerHTML = `
                <label>Alias: 
                    <input type="text" id="aliasInput" style="width:150px;">
                </label>
                <button id="aliasSaveBtn">Speichern</button>
                <button id="aliasClearBtn">Löschen</button>
            `;
            menuMembersList.appendChild(aliasLi);
        
            // Pin / Archiv Buttons
            const actionsLi = document.createElement("li");
            actionsLi.innerHTML = `
                <button id="pinBtn">⭐ An-/Abpinnen</button>
                <button id="archBtn" style="margin-left:8px;">📁 Archivieren / Zurückholen</button>
            `;
            menuMembersList.appendChild(actionsLi);
        
            // Alias des Kontakts vorbefüllen
           // fetch(`get_single_alias.php?target_id=${chatId}`)
           //     .then(r => r.json())
           //     .then(a => {
           //         const inp = document.getElementById('aliasInput');
           //         if (a && a.alias) inp.value = a.alias;
           //     });
            
            // Alias speichern
            document.getElementById('aliasSaveBtn').onclick = () => {
                const val = document.getElementById('aliasInput').value.trim();
                const fd = new URLSearchParams();
                fd.append('target_id', chatId);
                fd.append('action', 'alias');
                fd.append('alias', val);
            
                fetch('toggle_contact.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: fd.toString()
                }).then(() => {
                    loadContacts();
                    chatMenu.style.display = "none";
                });
            };
            // Alias löschen
            document.getElementById('aliasClearBtn').onclick = () => {
                const fd = new URLSearchParams();
                fd.append('target_id', chatId);
                fd.append('action', 'alias');
                fd.append('alias', '');              // leer ⇒ löschen
            
                fetch('toggle_contact.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: fd.toString()
                }).then(() => {
                    loadContacts();
                    chatMenu.style.display = "none";
                });
            };

        
            // Pin / Archiv toggeln
            document.getElementById('pinBtn').onclick = () => toggleContact(chatId, 'pin');
            document.getElementById('archBtn').onclick = () => toggleContact(chatId, 'archive');
        }
    chatMenu.style.display = "block";
}



function linkify(text) {
    if (!text) return "";
    return text.replace(/(^|\s)((https?:\/\/|www\.)\S+)/gi, function(match, space, url) {
        let href = url;
        if (!href.match(/^https?:\/\//i)) {
            href = "http://" + href;
        }
        return space + '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
    });
}

const imageInput  = document.getElementById('imageInput');
const addImageBtn = document.getElementById('addImageBtn');

addImageBtn.addEventListener('click', () => {
    imageInput.click();
});

imageInput.addEventListener('change', () => {
  if (!imageInput.files.length) return;
  const file = imageInput.files[0];

  const formData = new FormData();
  formData.append('file', file);
  formData.append('receiver_id', currentChat);
  formData.append('type', currentType);

    fetch('upload_file.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.text())
    .then(text => {
        console.log('UPLOAD RESPONSE:', text);
        try {
            const res = JSON.parse(text);
            if (!res.ok) {
                alert('Upload-Fehler: ' + res.error);
                return;
            }
            imageInput.value = "";
            loadMessages(true);
        } catch(e) {
            alert('Serverfehler: ' + text);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Netzwerkfehler beim Upload');
    }); 
});

function toggleContact(id, action) {
    const fd = new URLSearchParams();
    fd.append('target_id', id);
    fd.append('action', action); // 'pin' oder 'archive'

    fetch('toggle_contact.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: fd.toString()
    }).then(() => {
        loadContacts();
        chatMenu.style.display = "none";
    });
}

function markCurrentChatAsRead() {
  if (!currentChat) return;

  fetch(`mark_read.php?user_id=${currentChat}`)
    .then(() => {
      document.querySelectorAll('.message.unread').forEach(msg => {
        msg.classList.remove('unread');
        msg.classList.add('read');
      });
    });
}

function clearNotificationsForChat(chatId, type = 'user') {
  if (!navigator.serviceWorker) return;

  navigator.serviceWorker.getRegistrations().then(regs => {
    regs.forEach(reg => {
      reg.getNotifications().then(notifications => {
        notifications.forEach(n => {
          if (
            n.data &&
            n.data.chat == chatId &&
            n.data.type === type
          ) {
            n.close();
          }
        });
      });
    });
  });
}

document.addEventListener("visibilitychange", () => {
  notifySWChatState();

  if (!document.hidden) {
    markCurrentChatAsRead();
    clearNotificationsForChat(currentChat, currentType);
  }
});

function startReadStatusPolling() {
  setInterval(() => {
    fetch("check_read_status.php")
      .then(r => r.json())
      .then(ids => {
        ids.forEach(id => {
          const msg = document.querySelector('.message[data-id="' + id + '"]');
          if (msg) {
            msg.classList.remove("unread");
            msg.classList.add("read");

            const status = msg.querySelector(".msg-status");
            if (status) {
              status.textContent = "👁️"; // Auge setzen
            }
          }
        });
      })
      .catch(() => {});
  }, 10000); // alle 10 Sekunden
}


function loadOlderMessages() {
  loadingOlder = true;

  const firstMsg = chatbox.querySelector('.message[data-id]');
  if (!firstMsg) {
    loadingOlder = false;
    return;
  }
  const oldHeight = chatbox.scrollHeight;

  fetch(`get_messages.php?user_id=${currentChat}&before_id=${parseInt(firstMsg.dataset.id, 10)}&limit=30`)
    .then(r => r.json())
    .then(data => {
      if (!data.length) {
        reachedTop = true;
        return;
      }


      data.reverse().forEach(msg => {
        if (document.getElementById("msg_" + msg.id)) return;

        const div = document.createElement("div");
        div.id = "msg_" + msg.id;
        div.dataset.id = msg.id;
        div.className = "message " + (msg.sender_id == USER_ID ? "me" : "");
        div.innerHTML = `<b>${msg.username}</b>: ${linkify(msg.text)}`;

        chatbox.insertBefore(div, chatbox.firstChild);
      });

      const newHeight = chatbox.scrollHeight;
      chatbox.scrollTop = newHeight - oldHeight;
    })
    .finally(() => {
      loadingOlder = false;
    });
}



/*ZULEZT ONLINE*/

function formatLastSeen(seconds, lastSeenTs) {
  if (!lastSeenTs || seconds == null) return "–";

  if (seconds < 60) return "gerade eben";
  if (seconds < 1800) {
    return "vor " + Math.floor(seconds / 60) + " Min";
  }

  const d = new Date(lastSeenTs * 1000);
  const now = new Date();

  const sameDay =
    d.getDate() === now.getDate() &&
    d.getMonth() === now.getMonth() &&
    d.getFullYear() === now.getFullYear();

  const hour = d.getHours().toString().padStart(2, "0") + ":" +
               d.getMinutes().toString().padStart(2, "0");

  if (sameDay) {
    return hour;
  }

  return `${d.getDate()}.${d.getMonth() + 1} · ${hour}`;
}




function loadUserStatus() {
  if (currentType !== 'user' || !currentChat) return;

  fetch(`get_user_status.php?user_id=${currentChat}`)
    .then(r => r.json())
    .then(d => {
      const el = document.getElementById("userStatus");
      if (!el) return;

      if (d.online) {
        el.innerHTML = "🟢 online";
        return;
      }

      const text = formatLastSeen(d.seconds, d.lastSeenTs);
      el.innerHTML = "⚪ zuletzt gesehen " + text;
    });

}



setInterval(() => {
  if (!document.hidden) {
    fetch("ping.php");
  }
}, 10000);




/*=== Nachicht bearbeiten/löschen ===*/

let activeMsgMenu = null;

chatbox.addEventListener("click", e => {
  const msg = e.target.closest(".message.me");
  if (!msg) return;
  if (msg.classList.contains("deleted")) return;

  e.stopPropagation();
  openMsgMenu(msg);
});


function openMsgMenu(msg) {
  closeMsgMenu();

  const id = msg.dataset.id;
  if (!id) return;

  const menu = document.createElement("div");
  menu.className = "msg-menu";
  menu.innerHTML = `
    <button onclick="editMessage(${id})">✏️ Bearbeiten</button>
    <button onclick="deleteMessage(${id})">🗑️ Löschen</button>
  `;

  msg.appendChild(menu);
  activeMsgMenu = menu;
}

function closeMsgMenu() {
  if (activeMsgMenu) {
    activeMsgMenu.remove();
    activeMsgMenu = null;
  }
}

document.addEventListener("click", e => {
  if (!e.target.closest(".msg-menu")) {
    closeMsgMenu();
  }
});

function editMessage(id) {
  const msg = document.getElementById("msg_" + id);
  if (!msg) return;

  const textEl = msg.querySelector(".msg-main");
  if (!textEl) return;

  const old = textEl.innerText.trim();


  const neu = prompt("Nachricht bearbeiten:", old);
  if (!neu || neu.trim() === old.trim()) return;

  fetch("edit_message.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      message_id: id,
      text: neu.trim()
    })
    }).then(() => {
      textEl.innerText = neu.trim();
    });
}

function deleteMessage(id) {
  if (!confirm("Nachricht wirklich löschen?")) return;

  fetch("delete_message.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ message_id: id })
  })
  .then(() => {
    const msg = document.getElementById("msg_" + id);
    if (!msg) return;

    msg.classList.add("deleted");

    const main = msg.querySelector(".msg-main");
    if (main) {
      main.innerHTML = '<span class="msg-deleted">Nachricht gelöscht</span>';
    }

    const status = msg.querySelector(".msg-status");
    if (status) status.innerHTML = "";
  });
}



/*=== GIFS ===*/

const API_KEY = "aM5QrJ1y4DWS9HwKT0z0EBCN9o1JKhqK";

const gifBtn        = document.getElementById("gifBtn");
const gifOverlay    = document.getElementById("gifOverlay");
const gifSearch     = document.getElementById("gifSearchOverlay");
const gifResults    = document.getElementById("gifResultsOverlay");
const gifClose      = document.getElementById("gifClose");

/* Overlay öffnen */
gifBtn.addEventListener("click", () => {
  gifOverlay.style.display = "flex";
  gifSearch.value = "";
  gifResults.innerHTML = "";
  setTimeout(() => gifSearch.focus(), 100);
});

/* Overlay schließen (X) */
gifClose.addEventListener("click", () => {
  gifOverlay.style.display = "none";
});

/* Overlay schließen bei Klick auf dunklen Hintergrund */
gifOverlay.addEventListener("click", (e) => {
  if (e.target === gifOverlay) {
    gifOverlay.style.display = "none";
  }
});

/* GIF suchen */
gifSearch.addEventListener("input", async () => {
  const q = gifSearch.value.trim();
  if (q.length < 2) return;

  try {
    const res = await fetch(
      `https://api.giphy.com/v1/gifs/search?api_key=${API_KEY}&q=${encodeURIComponent(q)}&limit=20`
    );
    const data = await res.json();

    gifResults.innerHTML = "";

    data.data.forEach(gif => {
      const img = document.createElement("img");
      img.src = gif.images.fixed_height.url;
      img.alt = "GIF";

      img.addEventListener("click", () => {
        sendGIF(gif.images.original.url);
        gifOverlay.style.display = "none";
      });

      gifResults.appendChild(img);
    });

  } catch (err) {
    console.error("GIF SEARCH ERROR", err);
  }
});

/* GIF senden */
function sendGIF(url) {
  const isGroup = currentType === 'group';

  fetch(isGroup ? "send_group_message.php" : "send_message.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      type: "gif",
      gif_url: url,
      ...(isGroup ? { group_id: currentChat } : { receiver_id: currentChat })
    })
  })
  .then(r => r.json())
  .then(res => {
    if (res.ok) {
      loadMessages(true);
    } else {
      console.error("GIF SEND FAIL", res);
    }
  })
  .catch(err => console.error("GIF SEND ERROR", err));
}





/*NOTIZEN*/ 

function openNotes(){
  fetch("get_notes.php")
    .then(r=>r.json())
    .then(d=>{
      document.getElementById("notesText").value = d.content || "";
      document.getElementById("notesOverlay").style.display = "block";
    });
}

function closeNotes(){
  document.getElementById("notesOverlay").style.display = "none";
}

function saveNotes(){
  const fd = new FormData();
  fd.append("content", document.getElementById("notesText").value);

  fetch("save_notes.php", { method:"POST", body: fd })
    .then(()=> closeNotes());
}

/* BENACHICHTIGUNGEN AKIVIEREN REMINDER*/

function isIosSafari() {
  const ua = navigator.userAgent;
  const isIOS = /iPhone|iPad|iPod/i.test(ua);
  const isSafari = /^((?!chrome|android).)*safari/i.test(ua);
  return isIOS && isSafari && !isStandalone();
}

async function isPushEnabled() {
  if (!("serviceWorker" in navigator)) return false;
  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.getSubscription();
  return !!sub;
}
function isStandalone() {
  return window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true; // iOS
}
function showInstallTutorial() {
  alert(
    "📲 So fügst du Gigachat hinzu:\n\n" +
    "iPhone / iPad:\n" +
    "• Teilen-Button unten\n" +
    "• ‚Zum Home-Bildschirm‘\n\n" +
    "Android:\n" +
    "• ⋮ Menü\n" +
    "• ‚App installieren‘"
  );
}
function notifySWChatState() {
  if (!navigator.serviceWorker?.controller) return;

  navigator.serviceWorker.controller.postMessage({
    type: "CHAT_STATE",
    chatId: currentChat,
    visible: !document.hidden
  });
}

document.addEventListener("DOMContentLoaded", async () => {

  const modal = document.getElementById("push-modal");
  const text  = document.getElementById("push-text");
  const btn   = document.getElementById("push-action");
  const close = document.getElementById("push-close");

  if (!modal || !btn || !text || !close) return;

  // Nicht öfter als 1x pro Tag nerven
  const lastShown = localStorage.getItem("push_hint_day");
  const today = new Date().toISOString().slice(0,10);

  if (lastShown === today) return;

  const pushEnabled = await isPushEnabled();

  if (pushEnabled) return;

  modal.style.display = "flex";
  localStorage.setItem("push_hint_day", today);

  if (isStandalone() && !isIosSafari()) {
    // ✅ Web-App → Push aktivieren
    text.textContent = "Aktiviere Benachrichtigungen, damit du neue Nachrichten sofort bekommst – auch wenn die App geschlossen ist.";
    btn.textContent = "Benachrichtigungen aktivieren";

    btn.onclick = async () => {
      await subscribeForPush();
      modal.style.display = "none";
    };
  } else {
    // ❌ Safari / Browser ohne Web-App
    text.innerHTML = `
      Für Benachrichtigungen musst du Gigachat als App hinzufügen:
      <br><br>
      📲 <b>iPhone / iPad</b><br>
      • Teilen-Button<br>
      • „Zum Home-Bildschirm“
      <br><br>
      🤖 <b>Android</b><br>
      • ⋮ Menü<br>
      • „App installieren“
    `;
    btn.textContent = "So geht’s";

    btn.onclick = () => {
      showInstallTutorial();
    };
  }

  close.onclick = () => {
    modal.style.display = "none";
  };
});

navigator.serviceWorker?.addEventListener("message", event => {
  if (event.data?.type !== "OPEN_CHAT") return;

  const { chatId, chatType } = event.data;

  const tryOpen = () => {
    const el = document.querySelector(
      `.users-list li[data-id="${chatId}"][data-type="${chatType}"] .user-btn`
    );

    if (el) {
      el.click();
    } else {
      // nochmal versuchen (Kontakte laden async)
      setTimeout(tryOpen, 100);
    }
  };

  tryOpen();
});




// ==== Konfetti + Release-Banner ====

function showReleaseBanner() {
  // 1) Konfetti für ca. 1.5 Sekunden
  const duration = 1500;
  const end = Date.now() + duration;

  (function frame() {
    confetti({
      particleCount: 5,
      angle: 60,
      spread: 55,
      origin: { x: 0 }
    });
    confetti({
      particleCount: 5,
      angle: 120,
      spread: 55,
      origin: { x: 1 }
    });
    if (Date.now() < end) {
      requestAnimationFrame(frame);
    }
  })();

  // 2) Banner-Element bauen
const banner = document.createElement('div');
banner.id = 'gigaToast';
banner.innerHTML = `
  <div class="giga-toast-main">
    <span class="giga-toast-title">🎉 GigaChat 3.0 ist draußen!</span>

    <div class="giga-toast-actions">
      <button class="giga-toast-more">Was ist neu?</button>
      <a href="release-notes.html" class="giga-toast-release" target="_self">
        Release Notes
      </a>
      <button class="giga-toast-close">&times;</button>
    </div>
  </div>

  <div class="giga-toast-details">
    <p><strong>Neu & verbessert in 3.0:</strong></p>
    <ul class="giga-toast-list">
      <li>📝 <strong>Notizen-Tool</strong> (neu)</li>
      <li>🟢 <strong>Online-Status & zuletzt online</strong></li>
      <li>👁️ <strong>Lesebestätigung</strong> im Chat</li>
      <li>💬 <strong>Direkt auf Nachrichten antworten</strong> 
        <span class="hint">(nicht auf iOS)</span>
      </li>
      <li>🎉 <strong>GIFs verschicken</strong></li>
      <li>📩 Mitteilungen verschwinden korrekt beim Öffnen</li>
      <li>🔔 Push-Benachrichtigungen gefixt 
        <span class="hint">(kein Fake-„gelesen“ mehr)</span>
      </li>
      <li>📱 Handy-Version deutlich verbessert</li>
      <li>👥 Gruppen erstellen & verwalten – schöner & stabiler</li>
      <li>📊 Statistik & Einstellungen überarbeitet</li>
    </ul>

    <p class="hint">
      👉 Alle Details findest du in den vollständigen Release Notes.
    </p>

    <div class="giga-toast-links">
      <a href="release-notes.html" target="_self">📄 Release Notes öffnen</a>
    </div>
  </div>
`;

  document.body.appendChild(banner);

  const moreBtn   = banner.querySelector('.giga-toast-more');
  const closeBtn  = banner.querySelector('.giga-toast-close');
  const details   = banner.querySelector('.giga-toast-details');

  let pinnedOpen = false;

  // 3) Auto-Hide nach 10s, falls NICHT auf "Mehr Infos" geklickt
  const autoTimer = setTimeout(() => {
    if (!pinnedOpen) {
      banner.classList.add('giga-toast-hide');
      setTimeout(() => banner.remove(), 500);
    }
  }, 10000);

  // Mehr Infos: Details anzeigen und Auto-Hide deaktivieren
  moreBtn.addEventListener('click', () => {
    pinnedOpen = true;
    clearTimeout(autoTimer);
    details.classList.toggle('giga-toast-details-open');
  });

  // Schließen-Button: immer direkt schließen
  closeBtn.addEventListener('click', () => {
    banner.classList.add('giga-toast-hide');
    setTimeout(() => banner.remove(), 500);
  });
}



</script>