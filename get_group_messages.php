<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

$user_id  = (int)$_SESSION['user_id'];
$group_id = (int)($_GET['group_id'] ?? 0);
$last_id  = (int)($_GET['last_id'] ?? 0);
$limit    = min(50, (int)($_GET['limit'] ?? 50));

$sql = "
SELECT 
    gm.id,
    gm.sender_id,
    gm.message,
    gm.gif_url,
    gm.created_at,
    gm.edited,
    gm.deleted,
    COALESCE(NULLIF(ua.alias,''), u.username) AS display_name,
    u.color,
    u.emoji,
    gf.path AS file_path,
    gf.is_image,
    gf.original_name
FROM group_messages gm
JOIN users u ON gm.sender_id = u.id
LEFT JOIN user_aliases ua
  ON ua.owner_id = $user_id AND ua.target_id = u.id
LEFT JOIN group_message_files gf
  ON gf.message_id = gm.id
WHERE gm.group_id = $group_id
  AND gm.id > $last_id
ORDER BY gm.id ASC
LIMIT $limit
";

$result = $conn->query($sql);
if (!$result) {
    http_response_code(500);
    echo json_encode(["error" => $conn->error]);
    exit;
}

$messages = [];
while ($row = $result->fetch_assoc()) {
  $messages[] = [
      "id"         => (int)$row['id'],
      "sender_id"  => (int)$row['sender_id'],
      "username"   => htmlspecialchars($row['display_name']),
      "text"       => htmlspecialchars($row['message']),
      "gif_url"    => $row['gif_url'],
      "created_at" => $row['created_at'],
      "color"      => $row['color'],
      "emoji"      => $row['emoji'],
      "file_path"  => $row['file_path'],
      "is_image"   => (int)$row['is_image'],
      "original_name" => $row['original_name'],
      "edited"     => (int)$row['edited'],
      "deleted"    => (int)$row['deleted'],
  ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($messages);
