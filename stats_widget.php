<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

require_once __DIR__ . '/config_stats.php';

if (!isset($_GET['token']) || $_GET['token'] !== STATS_WIDGET_TOKEN) {
  http_response_code(403);
  exit('forbidden');
}

$conn = new mysqli('localhost', 'root', 'WBNhN16u', 'chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

if ($conn->connect_error) die("DB Fehler");

$online_since = date('Y-m-d H:i:s', time() - ONLINE_WINDOW_SECONDS);
$resOnline = $conn->prepare("SELECT COUNT(*) as c FROM user_presence WHERE last_seen >= ?");
$resOnline->bind_param("s", $online_since);
$resOnline->execute();
$onlineCount = (int)$resOnline->get_result()->fetch_assoc()['c'];
$resOnline->close();

$today = date('Y-m-d');
$resToday = $conn->prepare("
  SELECT COALESCE(SUM(views),0) as v, COALESCE(SUM(unique_visitors),0) as u
  FROM site_stats_daily WHERE day = ?
");
$resToday->bind_param("s", $today);
$resToday->execute();
$row = $resToday->get_result()->fetch_assoc();
$views = (int)$row['v'];
$unique = (int)$row['u'];
$resToday->close();
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gigachat Stats</title>
  <style>
    body{font-family:Arial,sans-serif;margin:0;padding:12px}
    .card{border:1px solid #ccc;border-radius:12px;padding:12px}
    .big{font-size:22px;font-weight:bold}
    .muted{opacity:.8}
  </style>
</head>
<body>
  <div class="card">
    <div class="big">Gigachat Statistik</div>
    <div class="muted">Heute: <?= htmlspecialchars($today) ?></div>
    <hr>
    <div>Online: <strong><?= $onlineCount ?></strong></div>
    <div>Views heute: <strong><?= $views ?></strong></div>
    <div>Unique heute: <strong><?= $unique ?></strong></div>
  </div>
</body>
</html>
