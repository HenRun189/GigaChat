<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Not logged in');
}
$userId = intval($_SESSION['user_id']);

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || !isset($data['endpoint'])) {
    http_response_code(400);
    exit('Invalid subscription');
}

$endpoint = $data['endpoint'];
$p256dh   = $data['keys']['p256dh'] ?? null;
$auth     = $data['keys']['auth'] ?? null;

$conn = new mysqli('localhost', 'root', 'WBNhN16u', 'chat');
if ($conn->connect_error) {
    http_response_code(500);
    exit('DB error');
}

$stmt = $conn->prepare("
    INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
    VALUES (?, ?, ?, ?)
");
$stmt->bind_param('isss', $userId, $endpoint, $p256dh, $auth);
$stmt->execute();

echo 'ok';
