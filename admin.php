<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');


$conn = new mysqli('localhost', 'root', 'WBNhN16u', 'chat');
$conn->query("SET time_zone = 'Europe/Berlin'");
if ($conn->connect_error) die("DB Fehler");

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$me = (int)$_SESSION['user_id'];

// Rolle holen
$stmt = $conn->prepare("SELECT role FROM users WHERE id=?");
$stmt->bind_param("i", $me);
$stmt->execute();
$myRole = (int)$stmt->get_result()->fetch_assoc()['role'];
$stmt->close();

// Nur Admin oder Owner dürfen rein
if ($myRole < 2) {
    http_response_code(403);
    die("Forbidden");
}

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

$selected = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;

// Users list
$users = $conn->query("SELECT id, username, gigascore, role FROM users ORDER BY id ASC");
// Details
$info = null;
$counts = [
  'messages_sent' => 0,
  'messages_received' => 0,
  'group_messages_sent' => 0,
  'groups' => 0,
  'push_subs' => 0,
  'remember_tokens' => 0,
];
$presence = null;

if ($selected > 0) {
  $stmt = $conn->prepare("SELECT id, username, gigascore, role FROM users WHERE id=?");
  $stmt->bind_param("i", $selected);
  $stmt->execute();
  $info = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  // counts
  $stmt = $conn->prepare("SELECT COUNT(*) c FROM messages WHERE sender_id=?");
  $stmt->bind_param("i", $selected); $stmt->execute();
  $counts['messages_sent'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(*) c FROM messages WHERE receiver_id=?");
  $stmt->bind_param("i", $selected); $stmt->execute();
  $counts['messages_received'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(*) c FROM group_messages WHERE sender_id=?");
  $stmt->bind_param("i", $selected); $stmt->execute();
  $counts['group_messages_sent'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(DISTINCT group_id) c FROM group_members WHERE user_id=?");
  $stmt->bind_param("i", $selected); $stmt->execute();
  $counts['groups'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(*) c FROM push_subscriptions WHERE user_id=?");
  $stmt->bind_param("i", $selected); $stmt->execute();
  $counts['push_subs'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

  $stmt = $conn->prepare("SELECT COUNT(*) c FROM remember_tokens WHERE user_id=?");
  $stmt->bind_param("i", $selected); $stmt->execute();
  $counts['remember_tokens'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

  $stmt = $conn->prepare("SELECT last_seen, last_page FROM user_presence WHERE user_id=?");
  $stmt->bind_param("i", $selected); $stmt->execute();
  $presence = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin – Gigachat</title>
<style>
  body{font-family:Arial,sans-serif;max-width:1100px;margin:24px auto;padding:0 16px;line-height:1.4}
  .grid{display:grid;grid-template-columns:320px 1fr;gap:14px}
  .box{border:1px solid #ccc;border-radius:12px;padding:12px}
  table{border-collapse:collapse;width:100%}
  td,th{border:1px solid #ddd;padding:8px;text-align:left}
  a{color:inherit}
  .muted{opacity:.75}
  input,button,select{padding:10px;border-radius:10px;border:1px solid #aaa}
  button{cursor:pointer}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .danger{border-color:#c00}
</style>
</head>
<body>

<h1>Admin Panel</h1>
<a class="btn" href="index.php">← Zurück zur Startseite</a>
<div class="grid">
  <div class="box">
    <h2>Users</h2>
    <table>
      <tr><th>ID</th><th>Name</th><th>Score</th><th>Role</th></tr>      <?php while($u=$users->fetch_assoc()): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><a href="?uid=<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?></a></td>
          <td><?= (int)$u['gigascore'] ?></td>
          <td>
          <?php
            switch ((int)$u['role']) {
              case 3: echo "Owner"; break;
              case 2: echo "Admin"; break;
              case 1: echo "Beta"; break;
              default: echo "User";
            }
          ?>
          </td>
        </tr>
      <?php endwhile; ?>
    </table>
  </div>

  <div class="box">
    <h2>Details</h2>

    <?php if(!$info): ?>
      <div class="muted">Links einen User auswählen.</div>
    <?php else: ?>
      <div class="row">
        <div><strong>User:</strong> <?= htmlspecialchars($info['username']) ?> (ID <?= (int)$info['id'] ?>)</div>
        <div><strong>GigaScore:</strong> <?= (int)$info['gigascore'] ?></div>
        <div>
          <strong>Role:</strong>
          <?php
            switch ((int)$info['role']) {
              case 3: echo "👑 Owner"; break;
              case 2: echo "🛠 Admin"; break;
              case 1: echo "🧪 Beta"; break;
              default: echo "User";
            }
          ?>
          </div>
      </div>

      <h3>Aktivität</h3>
      <table>
        <tr><th>Letzte Aktivität</th><td><?= htmlspecialchars($presence['last_seen'] ?? '-') ?></td></tr>
        <tr><th>Letzte Seite</th><td><?= htmlspecialchars($presence['last_page'] ?? '-') ?></td></tr>
        <tr><th>Privat gesendet</th><td><?= (int)$counts['messages_sent'] ?></td></tr>
        <tr><th>Privat empfangen</th><td><?= (int)$counts['messages_received'] ?></td></tr>
        <tr><th>Gruppen gesendet</th><td><?= (int)$counts['group_messages_sent'] ?></td></tr>
        <tr><th>Gruppen Mitglied</th><td><?= (int)$counts['groups'] ?></td></tr>
        <tr><th>Push Subscriptions</th><td><?= (int)$counts['push_subs'] ?></td></tr>
        <tr><th>Remember Tokens</th><td><?= (int)$counts['remember_tokens'] ?></td></tr>
      </table>

      <h3>Aktionen (Support)</h3>

      <div class="box">
        <form class="row" method="post" action="admin_actions.php" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="uid" value="<?= (int)$selected ?>">
          <button name="action" value="revoke_push">🔕 Push deaktivieren</button>
          <button name="action" value="revoke_remember">🚪 „Angemeldet bleiben“ zurücksetzen</button>
        </form>
        <div class="muted">Push deaktivieren = Subscriptions löschen. Remember zurücksetzen = alle Tokens löschen.</div>
      </div>

      <div class="box">
        <form class="row" method="post" action="admin_actions.php" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="uid" value="<?= (int)$selected ?>">
          <input type="password" name="new_password" placeholder="Neues Passwort setzen">
          <button name="action" value="reset_password">🔁 Passwort zurücksetzen</button>
        </form>
        <div class="muted">Sicherer als „Hash anzeigen“: du setzt ein neues Passwort und gibst es der Person.</div>
      </div>

      <?php if ($myRole === 3): ?>
      <div class="box">
        <form class="row" method="post" action="admin_actions.php">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="uid" value="<?= (int)$selected ?>">
            
          <select name="new_role">
            <option value="0">User</option>
            <option value="1">Beta Tester</option>
            <option value="2">Admin</option>
            <option value="3">Owner</option>
          </select>
            
          <button name="action" value="change_role">🎭 Rolle ändern</button>
        </form>
      </div>
      <?php endif; ?>

      <div class="box">
        <form class="row" method="post" action="admin_actions.php" onsubmit="return confirm('Account wirklich komplett löschen?');" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="uid" value="<?= (int)$selected ?>">
          <button class="danger" name="action" value="delete_account">❌ Account komplett löschen</button>
        </form>
        <div class="muted">Löscht Daten über mehrere Tabellen in richtiger Reihenfolge (Transaktion).</div>
      </div>


    <?php if ($info): ?>
    <div class="box">
      <h3>Letzte Nachrichten von / an <?= htmlspecialchars($info['username']) ?></h3>

      <table>
        <tr>
          <th>Typ</th>
          <th>Von</th>
          <th>An</th>
          <th>Inhalt</th>
          <th>Zeit</th>
          <th>Aktion</th>
        </tr>

    <?php
    // === Private Nachrichten ===
    $stmt = $conn->prepare("
      SELECT m.id, m.sender_id, m.receiver_id, m.message, m.created_at,
             u1.username AS sender_name,
             u2.username AS receiver_name
      FROM messages m
      JOIN users u1 ON u1.id = m.sender_id
      JOIN users u2 ON u2.id = m.receiver_id
      WHERE m.sender_id = ? OR m.receiver_id = ?
      ORDER BY m.created_at DESC
      LIMIT 500
    ");
    $stmt->bind_param("ii", $selected, $selected);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()):
    ?>
    <tr>
      <td>Privat</td>
      <td><?= htmlspecialchars($r['sender_name']) ?></td>
      <td><?= htmlspecialchars($r['receiver_name']) ?></td>
      <td><?= htmlspecialchars($r['message']) ?></td>
      <td><?= htmlspecialchars($r['created_at']) ?></td>
      <td>
        <form method="post" action="admin_actions.php" style="margin:0">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="delete_message">
          <input type="hidden" name="msg_type" value="private">
          <input type="hidden" name="msg_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="uid" value="<?= (int)$selected ?>">
          <button class="danger">🗑️</button>
        </form>
      </td>
    </tr>
    <?php endwhile; $stmt->close(); ?>

    <?php
    // === Gruppen-Nachrichten ===
    $stmt = $conn->prepare("
      SELECT gm.id, gm.sender_id, gm.message, gm.created_at,
             u.username AS sender_name,
             g.name AS group_name
      FROM group_messages gm
      JOIN users u ON u.id = gm.sender_id
      JOIN chat_groups g ON g.id = gm.group_id
      WHERE gm.sender_id = ?
      ORDER BY gm.created_at DESC
      LIMIT 500
    ");
    $stmt->bind_param("i", $selected);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()):
    ?>
    <tr>
      <td>Gruppe</td>
      <td><?= htmlspecialchars($r['sender_name']) ?></td>
      <td><?= htmlspecialchars($r['group_name']) ?></td>
      <td><?= htmlspecialchars($r['message']) ?></td>
      <td><?= htmlspecialchars($r['created_at']) ?></td>
      <td>
        <form method="post" action="admin_actions.php" style="margin:0">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="delete_message">
          <input type="hidden" name="msg_type" value="group">
          <input type="hidden" name="msg_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="uid" value="<?= (int)$selected ?>">
          <button class="danger">🗑️</button>
        </form>
      </td>
    </tr>
    <?php endwhile; $stmt->close(); ?>

      </table>
      <div class="muted">Zeigt jeweils die letzten 500 Nachrichten.</div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

</body>
</html>
