<?php
session_start();
if (!isset($_SESSION['user_id'])) exit;

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");
$uid = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT content FROM user_notes WHERE user_id=?");
$stmt->bind_param("i",$uid);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

echo json_encode([
  "content" => $res['content'] ?? ""
]);
