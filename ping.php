<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false]);
  exit;
}

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

$uid = (int)$_SESSION['user_id'];
$conn->query("UPDATE users SET last_seen = NOW() WHERE id = $uid");

echo json_encode(['ok' => true]);