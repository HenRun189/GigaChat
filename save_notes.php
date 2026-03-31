<?php
session_start();
if (!isset($_SESSION['user_id'])) exit;

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

$uid = (int)$_SESSION['user_id'];
$content = $_POST['content'] ?? "";

$stmt = $conn->prepare("
  INSERT INTO user_notes (user_id, content)
  VALUES (?, ?)
  ON DUPLICATE KEY UPDATE content=VALUES(content)
");
$stmt->bind_param("is", $uid, $content);
$stmt->execute();

echo "ok";
