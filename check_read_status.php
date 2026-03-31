<?php
session_start();
if (!isset($_SESSION['user_id'])) exit;

$conn = new mysqli("localhost","root","WBNhN16u","chat");
$conn->query("SET time_zone = 'Europe/Berlin'");
$user = $_SESSION['user_id'];

$res = $conn->query("
  SELECT id 
  FROM messages 
  WHERE sender_id = $user 
    AND read_at IS NOT NULL
");

$ids = [];
while ($r = $res->fetch_assoc()) {
  $ids[] = (int)$r['id'];
}

echo json_encode($ids);
