<?php
if (!defined('STATS_SALT')) return;

// ---- Funktion global verfügbar ----
function record_presence_and_stats(mysqli $conn, int $user_id, string $page): void {
  $now = date('Y-m-d H:i:s');
  $day = date('Y-m-d');

  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

  $ip_hash = hash('sha256', $ip . '|' . STATS_SALT);
  $ua_hash = hash('sha256', $ua . '|' . STATS_SALT);

  // presence
  $stmt = $conn->prepare("
    INSERT INTO user_presence (user_id, last_seen, last_page, last_ip_hash, last_ua_hash)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      last_seen = VALUES(last_seen),
      last_page = VALUES(last_page),
      last_ip_hash = VALUES(last_ip_hash),
      last_ua_hash = VALUES(last_ua_hash)
  ");
  if ($stmt) {
    $stmt->bind_param("issss", $user_id, $now, $page, $ip_hash, $ua_hash);
    $stmt->execute();
    $stmt->close();
  }

  // stats
  $visitor_hash = hash('sha256', $ip_hash . '|' . $ua_hash . '|' . STATS_SALT);

  $stmt = $conn->prepare("
    INSERT IGNORE INTO site_stats_uniques (day, page, visitor_hash, first_seen)
    VALUES (?, ?, ?, ?)
  ");
  $stmt->bind_param("ssss", $day, $page, $visitor_hash, $now);
  $stmt->execute();
  $is_new = ($stmt->affected_rows > 0);
  $stmt->close();

  if ($is_new) {
    $stmt = $conn->prepare("
      INSERT INTO site_stats_daily (day, page, views, unique_visitors)
      VALUES (?, ?, 1, 1)
      ON DUPLICATE KEY UPDATE
        views = views + 1,
        unique_visitors = unique_visitors + 1
    ");
  } else {
    $stmt = $conn->prepare("
      INSERT INTO site_stats_daily (day, page, views, unique_visitors)
      VALUES (?, ?, 1, 0)
      ON DUPLICATE KEY UPDATE
        views = views + 1
    ");
  }
  $stmt->bind_param("ss", $day, $page);
  $stmt->execute();
  $stmt->close();
}
