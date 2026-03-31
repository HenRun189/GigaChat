<?php
session_start();
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) exit;

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

$user_id = (int)$_SESSION['user_id'];
$chat    = (int)$data['chat'];
$text    = trim($data['text']);

if (!$user_id || !$chat || !$text) exit;

// User-Chat
$stmt = $conn->prepare("
  INSERT INTO messages (sender_id, receiver_id, message)
  VALUES (?, ?, ?)
");
$stmt->bind_param("iis", $user_id, $chat, $text);
$stmt->execute();

echo "ok";
