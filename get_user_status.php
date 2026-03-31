<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) exit;

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

$uid = (int)($_GET['user_id'] ?? 0);

$res = $conn->query("
  SELECT
    UNIX_TIMESTAMP(last_seen) AS lastSeenTs,
    TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds
  FROM users
  WHERE id = $uid
");

$row = $res->fetch_assoc();

$seconds = $row['seconds'] ?? null;
$lastSeenTs = $row['lastSeenTs'] ?? null;

$online = ($seconds !== null && $seconds <= 30);

echo json_encode([
  "online"     => $online,
  "seconds"    => $seconds,
  "lastSeenTs" => $lastSeenTs
]);
