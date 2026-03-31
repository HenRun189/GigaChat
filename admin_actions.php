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

$stmt = $conn->prepare("SELECT role FROM users WHERE id=?");
$stmt->bind_param("i", $me);
$stmt->execute();
$myRole = (int)$stmt->get_result()->fetch_assoc()['role'];
$stmt->close();

// Nur Admin oder Owner dürfen Aktionen ausführen
if ($myRole < 2) {
    http_response_code(403);
    die("Forbidden - only admins can do this");
}

if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
  http_response_code(400); die("Bad CSRF");
}

$uid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;
$action = $_POST['action'] ?? '';
if ($uid <= 0) { header("Location: admin.php"); exit; }

// Passe den Spaltennamen ggf. an (falls bei dir anders!)
$PASSWORD_COL = "password_hash"; // z.B. "password" oder "pass_hash"

function back($uid){ header("Location: admin.php?uid=".$uid); exit; }

if ($action === 'revoke_push') {
  $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE user_id=?");
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  $stmt->close();
  back($uid);
}

if ($action === 'revoke_remember') {
  $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id=?");
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  $stmt->close();
  back($uid);
}

if ($action === 'reset_password') {
  $new = trim((string)($_POST['new_password'] ?? ''));
  if ($new === '' || strlen($new) < 6) { die("Passwort zu kurz (min 6)."); }

  $hash = password_hash($new, PASSWORD_DEFAULT);

  // Dynamisch SQL bauen (wegen Spaltenname)
  $sql = "UPDATE users SET {$PASSWORD_COL}=? WHERE id=?";
  $stmt = $conn->prepare($sql);
  if (!$stmt) die("Prepare failed: ".$conn->error);

  $stmt->bind_param("si", $hash, $uid);
  $stmt->execute();
  $stmt->close();

  // Optional: remember tokens killen, damit alte Sessions rausfliegen
  $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id=?");
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  $stmt->close();

  back($uid);
}

if ($action === 'delete_message') {
  $msg_id = isset($_POST['msg_id']) ? (int)$_POST['msg_id'] : 0;
  $type = $_POST['msg_type'] ?? 'private';
  if ($msg_id <= 0) die("Ungültige Message-ID");

  $conn->begin_transaction();
  try {
    if ($type === 'group') {
      // Datei-Links löschen
      $stmt = $conn->prepare("DELETE FROM group_message_files WHERE message_id=?");
      $stmt->bind_param("i", $msg_id);
      $stmt->execute();
      $stmt->close();

      $stmt = $conn->prepare("DELETE FROM group_messages WHERE id=?");
      $stmt->bind_param("i", $msg_id);
      $stmt->execute();
      $stmt->close();
    } else {
      $stmt = $conn->prepare("DELETE FROM message_files WHERE message_id=?");
      $stmt->bind_param("i", $msg_id);
      $stmt->execute();
      $stmt->close();

      $stmt = $conn->prepare("DELETE FROM messages WHERE id=?");
      $stmt->bind_param("i", $msg_id);
      $stmt->execute();
      $stmt->close();
    }
    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    die("Fehler: ".$e->getMessage());
  }

  back($uid);
}

if ($action === 'delete_account') {
  // Alles sauber löschen: erst Abhängigkeiten, dann users
  $conn->begin_transaction();
  try {
    // Push / remember / presence / aliases
    $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE user_id=?"); $stmt->bind_param("i",$uid); $stmt->execute(); $stmt->close();
    $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id=?");   $stmt->bind_param("i",$uid); $stmt->execute(); $stmt->close();
    $stmt = $conn->prepare("DELETE FROM user_presence WHERE user_id=?");     $stmt->bind_param("i",$uid); $stmt->execute(); $stmt->close();
    $stmt = $conn->prepare("DELETE FROM user_aliases WHERE owner_id=? OR target_id=?");
    $stmt->bind_param("ii",$uid,$uid); $stmt->execute(); $stmt->close();

    // Private messages + files
    $stmt = $conn->prepare("SELECT id FROM messages WHERE sender_id=? OR receiver_id=?");
    $stmt->bind_param("ii",$uid,$uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while($r=$res->fetch_assoc()) $ids[]=(int)$r['id'];
    $stmt->close();

    if ($ids) {
      $in = implode(',', array_fill(0, count($ids), '?'));
      $types = str_repeat('i', count($ids));
      $stmt = $conn->prepare("DELETE FROM message_files WHERE message_id IN ($in)");
      $stmt->bind_param($types, ...$ids);
      $stmt->execute(); $stmt->close();

      $stmt = $conn->prepare("DELETE FROM messages WHERE id IN ($in)");
      $stmt->bind_param($types, ...$ids);
      $stmt->execute(); $stmt->close();
    }

    // Group messages sent by user + files
    $stmt = $conn->prepare("SELECT id FROM group_messages WHERE sender_id=?");
    $stmt->bind_param("i",$uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $gids = [];
    while($r=$res->fetch_assoc()) $gids[]=(int)$r['id'];
    $stmt->close();

    if ($gids) {
      $in = implode(',', array_fill(0, count($gids), '?'));
      $types = str_repeat('i', count($gids));
      $stmt = $conn->prepare("DELETE FROM group_message_files WHERE message_id IN ($in)");
      $stmt->bind_param($types, ...$gids);
      $stmt->execute(); $stmt->close();

      $stmt = $conn->prepare("DELETE FROM group_messages WHERE id IN ($in)");
      $stmt->bind_param($types, ...$gids);
      $stmt->execute(); $stmt->close();
    }

    // Group membership
    $stmt = $conn->prepare("DELETE FROM group_members WHERE user_id=?");
    $stmt->bind_param("i",$uid);
    $stmt->execute(); $stmt->close();

    // User löschen
    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param("i",$uid);
    $stmt->execute(); $stmt->close();

    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    die("Fehler: ".$e->getMessage());
  }
  header("Location: admin.php"); exit;
}

if ($_POST['action'] === 'change_role') {

    // Nur Owner darf Rollen ändern
    $stmt = $conn->prepare("SELECT role FROM users WHERE id=?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $myRole = (int)$stmt->get_result()->fetch_assoc()['role'];
    $stmt->close();

    if ($myRole !== 3) {
        die("Forbidden");
    }

    $uid = (int)$_POST['uid'];
    $newRole = (int)$_POST['new_role'];

    $stmt = $conn->prepare("UPDATE users SET role=? WHERE id=?");
    $stmt->bind_param("ii", $newRole, $uid);
    $stmt->execute();
    $stmt->close();

    header("Location: admin.php?uid=" . $uid);
    exit;
}

back($uid);
