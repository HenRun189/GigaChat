<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

if (!isset($_SESSION['user_id'])) exit;

$user_id  = (int)$_SESSION['user_id'];
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");


$sql = "SELECT id, name, created_by FROM chat_groups WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $group_id);
$stmt->execute();
$res = $stmt->get_result();
$group = $res->fetch_assoc();
$stmt->close();

if (!$group) {
    echo json_encode(['error' => 'not_found']);
    exit;
}

$group['is_owner'] = ($group['created_by'] == $user_id);
header('Content-Type: application/json');
echo json_encode($group);
