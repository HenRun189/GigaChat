<?php
session_start();
if (!isset($_SESSION['user_id'])) exit;

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone='Europe/Berlin'");

$group_id = (int)($_GET['group_id'] ?? 0);
$self_id = (int)$_SESSION['user_id'];

/* alle User die NICHT in der Gruppe sind */
$sql = "
SELECT u.id, u.username
FROM users u
WHERE u.id != ?
AND u.id NOT IN (
  SELECT user_id FROM group_members WHERE group_id = ?
)
ORDER BY u.username ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $self_id, $group_id);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($r = $res->fetch_assoc()) {
  $out[] = $r;
}

header('Content-Type: application/json');
echo json_encode($out);
