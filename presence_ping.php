<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');


require_once __DIR__ . '/config_stats.php';

// DB: nutze deine bestehenden Daten
$conn = new mysqli('localhost', 'root', 'WBNhN16u', 'chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

if ($conn->connect_error) {
  http_response_code(500);
  exit('db_error');
}

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  exit('not_logged_in');
}

$user_id = (int)$_SESSION['user_id'];

$page = isset($_POST['page']) ? substr($_POST['page'], 0, 255) : 'index.php';
$now = date('Y-m-d H:i:s');
$day = date('Y-m-d');

// IP & UA nur gehasht speichern (privacy)
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

$ip_hash = hash('sha256', $ip . '|' . STATS_SALT);
$ua_hash = hash('sha256', $ua . '|' . STATS_SALT);

// --- Presence upsert ---
$stmt = $conn->prepare("
  INSERT INTO user_presence (user_id, last_seen, last_page, last_ip_hash, last_ua_hash)
  VALUES (?, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    last_seen = VALUES(last_seen),
    last_page = VALUES(last_page),
    last_ip_hash = VALUES(last_ip_hash),
    last_ua_hash = VALUES(last_ua_hash)
");
$stmt->bind_param("issss", $user_id, $now, $page, $ip_hash, $ua_hash);
$stmt->execute();
$stmt->close();

// --- Daily views + uniques ---
// visitor_hash: kombiniere user_id + ip_hash + ua_hash (immer noch Hash)
$visitor_hash = hash('sha256', $user_id . '|' . $ip_hash . '|' . $ua_hash . '|' . STATS_SALT);

// Unique markieren
$is_new_unique = false;
$stmt = $conn->prepare("
  INSERT IGNORE INTO site_stats_uniques (day, page, visitor_hash, first_seen)
  VALUES (?, ?, ?, ?)
");
$stmt->bind_param("ssss", $day, $page, $visitor_hash, $now);
$stmt->execute();
$is_new_unique = ($stmt->affected_rows > 0);
$stmt->close();

// Daily counter upsert
if ($is_new_unique) {
  $stmt = $conn->prepare("
    INSERT INTO site_stats_daily (day, page, views, unique_visitors)
    VALUES (?, ?, 1, 1)
    ON DUPLICATE KEY UPDATE
      views = views + 1,
      unique_visitors = unique_visitors + 1
  ");
  $stmt->bind_param("ss", $day, $page);
} else {
  $stmt = $conn->prepare("
    INSERT INTO site_stats_daily (day, page, views, unique_visitors)
    VALUES (?, ?, 1, 0)
    ON DUPLICATE KEY UPDATE
      views = views + 1
  ");
  $stmt->bind_param("ss", $day, $page);
}
$stmt->execute();
$stmt->close();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  'ts' => $now
]);
