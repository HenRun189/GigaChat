<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$conn = new mysqli('localhost', 'webapp_user', 'g679*.<cS5LK', 'chat');
if ($conn->connect_error) die("DB Fehler.");

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($myRole);
$stmt->fetch();
$stmt->close();

$_SESSION['role'] = (int)$myRole;

$message = "";
$user_id = (int)$_SESSION['user_id'];

if ($_SERVER["REQUEST_METHOD"] === "POST" 
    && isset($_POST['title']) 
    && isset($_POST['description'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';

    if ($title && $description) {

        $type = $_POST['type'] ?? 'bug';

        $stmt = $conn->prepare("
            INSERT INTO bug_reports (user_id, type, title, description, priority)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issss", $user_id, $type, $title, $description, $priority);

        if ($stmt->execute()) {
            $message = "Bug erfolgreich gemeldet 🚀";
        } else {
            $message = "Fehler beim Speichern.";
        }
        $stmt->close();
    } else {
        $message = "Bitte Titel & Beschreibung ausfüllen.";
    }
}

if (isset($_POST['change_status']) && $_SESSION['user_id'] == 1) {
    $id = (int)$_POST['change_status'];
    $new = $_POST['new_status'];

    $stmt = $conn->prepare("UPDATE bug_reports SET status=? WHERE id=?");
    $stmt->bind_param("si", $new, $id);
    $stmt->execute();
    $stmt->close();
}

if (isset($_POST['reply_user']) && $_SESSION['user_id'] == 1) {
    $receiver = (int)$_POST['reply_user'];
    $text = $_POST['reply_text'];

    $stmt = $conn->prepare("
        INSERT INTO messages (sender_id, receiver_id, message, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iis", $_SESSION['user_id'], $receiver, $text);
    $stmt->execute();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Bug Report</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>

<div class="page">
    <div class="settings-header">
        <h1>🐞 Bug melden</h1>
        <div class="subtitle">Hilf Gigachat besser zu machen 💙</div>
    </div>

    <div class="layout">
        <div class="column">
            <section class="card">
              <form method="POST">
              
                  <select name="type" required>
                      <option value="bug">🐞 Bug melden</option>
                      <option value="feature">✨ Funktionsvorschlag</option>
                      <option value="question">❓ Frage stellen</option>
                  </select>
              
                  <input type="text" name="title"
                      placeholder="Kurzer Titel"
                      required>
              
                  <textarea name="description"
                      placeholder="Beschreibe dein Anliegen so genau wie möglich..."
                      rows="6" required></textarea>
              
                  <select name="priority">
                      <option value="low">🟢 Niedrig</option>
                      <option value="medium" selected>🟡 Mittel</option>
                      <option value="high">🔴 Hoch</option>
                  </select>
              
                  <?php if ($message): ?>
                      <div class="message"><?= htmlspecialchars($message) ?></div>
                  <?php endif; ?>
                  
                  <button class="primary">🚀 Absenden</button>
                  
              </form>
            </section>

            <section class="card back-card">
                <a href="settings.php">← Zurück</a>
            </section>
        </div>
    </div>
</div>


<?php if ($_SESSION['role'] >= 2): ?>

<section class="card" style="margin-top:40px;">
<h2>👑 Admin Übersicht</h2>

<form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">

<select name="filter_type">
<option value="">Alle Typen</option>
<option value="bug">🐞 Bug</option>
<option value="feature">✨ Feature</option>
<option value="question">❓ Frage</option>
</select>

<select name="filter_status">
<option value="">Alle Status</option>
<option value="open">🟢 Offen</option>
<option value="in_progress">🟡 In Arbeit</option>
<option value="closed">🔴 Erledigt</option>
</select>

<select name="sort">
<option value="new">Neueste zuerst</option>
<option value="old">Älteste zuerst</option>
</select>

<button class="primary">Filtern</button>
</form>

<?php
$where = [];
$order = "ORDER BY br.created_at DESC";

if (!empty($_GET['filter_type'])) {
    $type = $conn->real_escape_string($_GET['filter_type']);
    $where[] = "br.type = '$type'";
}

if (!empty($_GET['filter_status'])) {
    $status = $conn->real_escape_string($_GET['filter_status']);
    $where[] = "br.status = '$status'";
}

if (!empty($_GET['sort']) && $_GET['sort'] === 'old') {
    $order = "ORDER BY br.created_at ASC";
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

$result = $conn->query("
SELECT br.*, u.username 
FROM bug_reports br
JOIN users u ON u.id = br.user_id
$whereSQL
$order
");
?>

<div style="overflow-x:auto;">
<table style="width:100%; border-collapse:collapse;">
<tr style="border-bottom:1px solid #555;">
<th>Typ</th>
<th>Titel</th>
<th>User</th>
<th>Datum</th>
<th>Status</th>
<th>Aktion</th>
</tr>

<?php while($row = $result->fetch_assoc()): ?>
<tr style="border-bottom:1px solid #333;">
<td><?= htmlspecialchars($row['type']) ?></td>
<td><?= htmlspecialchars($row['title']) ?></td>
<td><?= htmlspecialchars($row['username']) ?></td>
<td><?= date("d.m.Y H:i", strtotime($row['created_at'])) ?></td>
<td><?= htmlspecialchars($row['status']) ?></td>
<td>

<form method="POST" style="display:inline;">
<input type="hidden" name="change_status" value="<?= $row['id'] ?>">
<select name="new_status" onchange="this.form.submit()">
<option value="open">Open</option>
<option value="in_progress">In Arbeit</option>
<option value="closed">Erledigt</option>
</select>
</form>

<form method="POST" style="display:inline;" 
onsubmit="return sendReply(this);">
<input type="hidden" name="reply_user" value="<?= $row['user_id'] ?>">
<input type="hidden" name="ticket_title" value="<?= htmlspecialchars($row['title']) ?>">
<input type="hidden" name="reply_text">
<button type="submit">💬</button>
</form>

</td>
</tr>
<?php endwhile; ?>

</table>
</div>
</section>

<?php endif; ?>
<script>
function sendReply(form) {
    const text = prompt("Rückfrage eingeben:");
    if (!text || text.trim().length < 2) {
        return false;
    }

    const ticket = form.ticket_title.value;

    form.reply_text.value =
        "Rückfrage zu deinem Ticket: " + ticket + "\n\n" + text;

    return true;
}
</script>
</body>
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


textarea, select {
  padding: 12px;
  border-radius: 10px;
  border: none;
  font-size: 16px;
  font-family: inherit;
}

textarea {
  resize: vertical;
}

textarea, select {
  padding: 12px;
  border-radius: 10px;
  border: none;
  font-size: 16px;
  font-family: inherit;
}

textarea {
  resize: vertical;
}
</style>