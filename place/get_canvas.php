<?php
session_start();

// Debug optional wie bei dir
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$debug = ($user_id === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

// DB ist hier nicht zwingend nötig, aber falls du später was loggen willst:
$conn = new mysqli('localhost', 'webapp_user', 'g679*.<cS5LK', 'chat');
if (!$conn->connect_error) {
    $conn->query("SET time_zone = 'Europe/Berlin'");
}

$file = __DIR__ . "/canvas.json";

if (!file_exists($file)) {
    $data = ["pixels" => array_fill(0, 40000, "#ffffff")];
    file_put_contents($file, json_encode($data));
} else {
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data["pixels"]) || count($data["pixels"]) !== 40000){
        $data = ["pixels" => array_fill(0, 40000, "#ffffff")];
        file_put_contents($file, json_encode($data));
    }
}

header("Content-Type: application/json; charset=utf-8");
echo json_encode($data);
