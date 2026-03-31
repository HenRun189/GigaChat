<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false]);
  exit;
}

$user_id   = (int)$_SESSION['user_id'];
$chat_with = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

if ($chat_with > 0) {
  $conn->query("
    UPDATE messages 
    SET read_at = NOW() 
    WHERE sender_id = $chat_with
      AND receiver_id = $user_id
      AND read_at IS NULL
  ");
}

echo json_encode(['ok' => true]);
