<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

if (!isset($_SESSION['user_id'])) exit;

$owner_id = (int)$_SESSION['user_id'];
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$user_id  = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

// Owner‑Check
$stmt = $conn->prepare("SELECT created_by FROM chat_groups WHERE id=?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if(!$row || $row['created_by'] != $owner_id){
    http_response_code(403);
    exit("forbidden");
}

// User aus Gruppe löschen
$stmt = $conn->prepare(
    "DELETE FROM group_members WHERE group_id=? AND user_id=?"
);
$stmt->bind_param("ii", $group_id, $user_id);
$stmt->execute();
$stmt->close();

echo "ok";
