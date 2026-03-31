<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Debug nur für User 1 mit ?debug
$debug = ($user_id === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

// DB-Verbindung (wie bei dir im Chat)
$conn = new mysqli('localhost', 'webapp_user', 'g679*.<cS5LK', 'chat');
if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'db_connect_failed']);
    exit;
}
$conn->query("SET time_zone = 'Europe/Berlin'");

// Pixels auslesen
$stmt = $conn->prepare("SELECT pixels_set FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

$pixels = (int)($row['pixels_set'] ?? 0);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['pixels_set' => $pixels]);
