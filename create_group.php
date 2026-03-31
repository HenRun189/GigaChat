<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$conn = new mysqli('localhost', 'root', 'WBNhN16u', 'chat');
$conn->query("SET time_zone = 'Europe/Berlin'");
if ($conn->connect_error) die("DB Fehler");

/* User für Dropdown */
$users = [];
$res = $conn->query("SELECT id, username FROM users WHERE id != $user_id ORDER BY username");
while ($r = $res->fetch_assoc()) $users[] = $r;

/* POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');
    $members = $_POST['members'] ?? [];

    if (mb_strlen($name) < 3) {
        $error = "Gruppenname zu kurz.";
    } else {

        $name = "👥 " . $name;

        $stmt = $conn->prepare(
            "INSERT INTO chat_groups (name, created_by, created_at)
             VALUES (?, ?, NOW())"
        );
        $stmt->bind_param("si", $name, $user_id);
        $stmt->execute();

        $group_id = $stmt->insert_id;

        $stmt2 = $conn->prepare(
            "INSERT INTO group_members (group_id, user_id, joined_at)
             VALUES (?, ?, NOW())"
        );

        /* Creator */
        $stmt2->bind_param("ii", $group_id, $user_id);
        $stmt2->execute();

        /* Weitere */
        foreach ($members as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                $stmt2->bind_param("ii", $group_id, $uid);
                $stmt2->execute();
            }
        }

        header("Location: index.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Neue Gruppe</title>

<style>
/* ==============================
   RESET / BASE
   ============================== */
* { box-sizing: border-box; }
body {
  margin: 0;
  background: #0f172a;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto;
  color: #e5e7eb;
}

/* ==============================
   LAYOUT
   ============================== */
.page {
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}

.card {
  width: 100%;
  max-width: 420px;
  background: #111827;
  border-radius: 22px;
  padding: 22px;
  box-shadow: 0 25px 60px rgba(0,0,0,.55);
}

/* ==============================
   HEADER
   ============================== */
.card h2 {
  margin: 0 0 18px;
  font-size: 22px;
  font-weight: 700;
  text-align: center;
}

/* ==============================
   FORM
   ============================== */
label {
  font-size: 13px;
  color: #9ca3af;
}

input, select {
  width: 100%;
  padding: 14px;
  border-radius: 14px;
  border: 1px solid #1f2937;
  background: #020617;
  color: #fff;
  font-size: 16px;
  outline: none;
}

input:focus, select:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 2px rgba(59,130,246,.25);
}

/* ==============================
   USER ADD
   ============================== */
.add-wrap {
  margin-top: 10px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.user-tag {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border-radius: 999px;
  background: rgba(59,130,246,.18);
  border: 1px solid rgba(59,130,246,.35);
  font-size: 14px;
  width: fit-content;
}

.user-tag span {
  cursor: pointer;
  font-size: 18px;
  opacity: .8;
}

/* ==============================
   BUTTONS
   ============================== */
.actions {
  display: flex;
  gap: 10px;
  margin-top: 18px;
}

button {
  flex: 1;
  padding: 14px;
  border-radius: 999px;
  border: none;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
}

.btn-main {
  background: linear-gradient(135deg, #3b82f6, #6366f1);
  color: #fff;
}

.btn-ghost {
  background: transparent;
  border: 1px solid #1f2937;
  color: #9ca3af;
}

button:active {
  transform: scale(.97);
}

/* ==============================
   ERROR
   ============================== */
.error {
  color: #f87171;
  font-size: 14px;
  margin-bottom: 10px;
}

/* ==============================
   MOBILE TWEAKS
   ============================== */
@media (max-width: 480px) {
  .card {
    border-radius: 26px;
    padding: 20px;
  }
}
</style>
</head>

<body>

<div class="page">
  <div class="card">

    <h2>Neue Gruppe</h2>

    <?php if (!empty($error)): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">

      <label>Gruppenname</label><br>
      <input type="text" name="name" required minlength="3"><br><br>

      <label>Mitglieder hinzufügen</label>
      <div id="addUsers" class="add-wrap"></div>

      <div class="actions">
        <button class="btn-main" type="submit">Erstellen</button>
        <button class="btn-ghost" type="button" onclick="location.href='index.php'">Abbrechen</button>
      </div>

    </form>

  </div>
</div>

<script>
const users = <?= json_encode($users) ?>;
const wrap = document.getElementById("addUsers");
const selected = [];

function addDropdown(){
  const select = document.createElement("select");
  select.innerHTML = `<option value="">➕ Benutzer auswählen</option>`;

  users.forEach(u=>{
    if(!selected.includes(u.id)){
      const o=document.createElement("option");
      o.value=u.id;
      o.textContent=u.username;
      select.appendChild(o);
    }
  });

  select.onchange = ()=>{
    if(!select.value) return;
    const uid=parseInt(select.value);
    selected.push(uid);

    const tag=document.createElement("div");
    tag.className="user-tag";
    tag.textContent=select.options[select.selectedIndex].text;

    const x=document.createElement("span");
    x.textContent="×";
    x.onclick=()=>{
      selected.splice(selected.indexOf(uid),1);
      tag.remove();
      hidden.remove();
      rebuild();
    };

    tag.appendChild(x);
    wrap.insertBefore(tag,select);

    const hidden=document.createElement("input");
    hidden.type="hidden";
    hidden.name="members[]";
    hidden.value=uid;
    wrap.appendChild(hidden);

    select.remove();
    addDropdown();
  };

  wrap.appendChild(select);
}

function rebuild(){
  wrap.innerHTML="";
  selected.forEach(id=>{
    const u=users.find(x=>x.id==id);
    const tag=document.createElement("div");
    tag.className="user-tag";
    tag.textContent=u.username;

    const x=document.createElement("span");
    x.textContent="×";
    x.onclick=()=>{
      selected.splice(selected.indexOf(id),1);
      rebuild();
    };

    tag.appendChild(x);
    wrap.appendChild(tag);

    const hidden=document.createElement("input");
    hidden.type="hidden";
    hidden.name="members[]";
    hidden.value=id;
    wrap.appendChild(hidden);
  });
  addDropdown();
}

addDropdown();
</script>

</body>
</html>
