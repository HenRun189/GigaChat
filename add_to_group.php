<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');


session_start();
if (!isset($_SESSION['user_id'])) exit;

$owner_id = (int)$_SESSION['user_id'];
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$user_raw = $_GET['user_id'] ?? '';

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

if ($conn->connect_error) {
    http_response_code(500);
    exit("db_error");
}

// === Username -> ID auflösen oder direkt ID benutzen ===
if (!ctype_digit($user_raw)) {
    $stmtU = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmtU->bind_param("s", $user_raw);
    $stmtU->execute();
    $resU = $stmtU->get_result();
    $rowU = $resU->fetch_assoc();
    $stmtU->close();

    if (!$rowU) {
        http_response_code(400);
        exit("unknown_user");
    }
    $user_id = (int)$rowU['id'];
} else {
    $user_id = (int)$user_raw;
}

// === Prüfen: ist aktueller User der Ersteller der Gruppe? ===
$stmt = $conn->prepare("SELECT created_by FROM chat_groups WHERE id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row || $row['created_by'] != $owner_id) {
    http_response_code(403);
    exit("forbidden");
}

// === Schon Mitglied? ===
$stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=?");
$stmt->bind_param("ii", $group_id, $user_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows == 0) {
    $stmt->close();

    $stmt2 = $conn->prepare(
        "INSERT INTO group_members (group_id, user_id, joined_at)
         VALUES (?, ?, NOW())"
    );
    $stmt2->bind_param("ii", $group_id, $user_id);
    $stmt2->execute();
    $stmt2->close();
} else {
    $stmt->close();
}

echo "ok";
