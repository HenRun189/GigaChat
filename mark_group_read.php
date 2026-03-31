<?php
session_start();
if (!isset($_SESSION['user_id'])) exit;

$user_id  = (int)$_SESSION['user_id'];
$group_id = (int)($_GET['group_id'] ?? 0);
if (!$group_id) exit;

$conn = new mysqli('localhost','root','WBNhN16u','chat');

$res = $conn->query("
  SELECT MAX(id) AS last_id
  FROM group_messages
  WHERE group_id = $group_id
");
$lastId = (int)($res->fetch_assoc()['last_id'] ?? 0);

$stmt = $conn->prepare("
  INSERT INTO group_reads (user_id, group_id, last_read_msg_id, first_read_at)
  VALUES (?, ?, ?, NOW())
  ON DUPLICATE KEY UPDATE
    last_read_msg_id = GREATEST(last_read_msg_id, VALUES(last_read_msg_id)),
    first_read_at = IF(first_read_at IS NULL, NOW(), first_read_at)
");
$stmt->bind_param("iii", $user_id, $group_id, $lastId);
$stmt->execute();
$stmt->close();

