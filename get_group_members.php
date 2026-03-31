<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

if(!isset($_SESSION['user_id'])) exit;

$user_id = intval($_SESSION['user_id']);
$group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");


$sql = "
    SELECT u.id, u.username, ua.alias, u.color, u.emoji
    FROM group_members gm
    JOIN users u ON gm.user_id = u.id
    LEFT JOIN user_aliases ua 
        ON ua.owner_id = $user_id AND ua.target_id = u.id
    WHERE gm.group_id = $group_id
";

$result = $conn->query($sql);
$members = [];
while($row = $result->fetch_assoc()){
    $members[] = $row;
}

header('Content-Type: application/json');
echo json_encode($members);
