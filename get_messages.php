<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Nicht eingeloggt!";
    exit;
}


$conn = new mysqli('localhost', 'root', 'WBNhN16u', 'chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

$user_id = intval($_SESSION['user_id']);
$chat_with = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$last_id   = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
$before_id= isset($_GET['before_id']) ? intval($_GET['before_id']) : 0;
$limit     = isset($_GET['limit']) ? min(50, intval($_GET['limit'])) : 30;


$where = "
  ((m.sender_id = $user_id AND m.receiver_id = $chat_with)
   OR
   (m.sender_id = $chat_with AND m.receiver_id = $user_id))
";

if ($last_id > 0) {
    $where .= " AND m.id > $last_id";
    $order = "ORDER BY m.id ASC";
} elseif ($before_id > 0) {
    $where .= " AND m.id < $before_id";
    $order = "ORDER BY m.id DESC";
} else {
    $order = "ORDER BY m.id DESC";
}

$sql = "
SELECT 
    m.*,
    m.gif_url,
    COALESCE(NULLIF(ua.alias,''), u.username) AS display_name,
    u.color,
    u.emoji,
    f.path          AS file_path,
    f.is_image      AS is_image,
    f.original_name AS original_name
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    LEFT JOIN user_aliases ua 
      ON ua.owner_id = $user_id AND ua.target_id = u.id
    LEFT JOIN message_files f
      ON f.message_id = m.id
    WHERE $where
    $order
    LIMIT $limit
";


$result = $conn->query($sql);
$messages = [];
while($row = $result->fetch_assoc()){
    $messages[] = [
        "id"           => $row['id'],
        "sender_id"    => $row['sender_id'],
        "username"     => htmlspecialchars($row['display_name']),
        "text"         => htmlspecialchars($row['message']),
        "gif_url"      => $row['gif_url'],  
        "created_at"   => $row['created_at'],
        "color"        => $row['color'],
        "emoji"        => $row['emoji'],
        "read_at"      => $row['read_at'],
        "file_path"    => $row['file_path'],    
        "is_image"     => (int)$row['is_image'],
        "original_name"=> $row['original_name'],
        "edited"  => (int)$row['edited'],
        "deleted" => (int)$row['deleted'],
    ];
}

if ($before_id > 0 || $last_id === 0) {
    $messages = array_reverse($messages);
}


header('Content-Type: application/json');
echo json_encode($messages);
