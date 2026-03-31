<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

require_once __DIR__ . '/config_stats.php';

$conn = new mysqli('localhost', 'root', 'WBNhN16u', 'chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

if ($conn->connect_error) die("DB Fehler");

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_id = (int)$_SESSION['user_id'];
$is_owner = ($user_id === OWNER_USER_ID);

// Hilfsfunktion: finde eine Zeitspalte (created_at / sent_at / timestamp ...) für eine Tabelle
function detect_time_column(mysqli $conn, string $table): string {
  $candidates = ['created_at','sent_at','timestamp','created','time','createdAt','created_on','sentOn'];
  $stmt = $conn->prepare("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ");
  if (!$stmt) return 'created_at';
  $stmt->bind_param("s", $table);
  $stmt->execute();
  $res = $stmt->get_result();
  $cols = [];
  while ($r = $res->fetch_assoc()) $cols[] = $r['COLUMN_NAME'];
  $stmt->close();

  foreach ($candidates as $c) {
    if (in_array($c, $cols, true)) return $c;
  }
  // fallback: erste DATETIME/TIMESTAMP-Spalte nehmen
  $stmt2 = $conn->prepare("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND DATA_TYPE IN ('datetime','timestamp','date')
    ORDER BY ORDINAL_POSITION ASC
    LIMIT 1
  ");
  if ($stmt2) {
    $stmt2->bind_param("s", $table);
    $stmt2->execute();
    $r2 = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    if ($r2 && !empty($r2['COLUMN_NAME'])) return $r2['COLUMN_NAME'];
  }
  return 'created_at';
}

$messages_time_col = detect_time_column($conn, 'messages');
$group_messages_time_col = detect_time_column($conn, 'group_messages');


/**
 * QUICK FIX:
 * - Präsenz + Views/Unique auch auf statistik.php zählen, damit:
 *   1) du selbst als "online" auftauchst
 *   2) views/unique heute nicht 0 bleiben, wenn nur diese Seite besucht wird
 */
function record_presence_and_stats(mysqli $conn, int $user_id, string $page): void {
  if (!defined('STATS_SALT')) return;

  $now = date('Y-m-d H:i:s');
  $day = date('Y-m-d');

  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

  $ip_hash = hash('sha256', $ip . '|' . STATS_SALT);
  $ua_hash = hash('sha256', $ua . '|' . STATS_SALT);

  // presence upsert
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

  // daily stats (unique + views)
  $visitor_hash = hash('sha256', $user_id . '|' . $ip_hash . '|' . $ua_hash . '|' . STATS_SALT);

  $is_new_unique = false;
  $stmt = $conn->prepare("
    INSERT IGNORE INTO site_stats_uniques (day, page, visitor_hash, first_seen)
    VALUES (?, ?, ?, ?)
  ");
  if ($stmt) {
    $stmt->bind_param("ssss", $day, $page, $visitor_hash, $now);
    $stmt->execute();
    $is_new_unique = ($stmt->affected_rows > 0);
    $stmt->close();
  }

  if ($is_new_unique) {
    $stmt = $conn->prepare("
      INSERT INTO site_stats_daily (day, page, views, unique_visitors)
      VALUES (?, ?, 1, 1)
      ON DUPLICATE KEY UPDATE
        views = views + 1,
        unique_visitors = unique_visitors + 1
    ");
    if ($stmt) {
      $stmt->bind_param("ss", $day, $page);
      $stmt->execute();
      $stmt->close();
    }
  } else {
    $stmt = $conn->prepare("
      INSERT INTO site_stats_daily (day, page, views, unique_visitors)
      VALUES (?, ?, 1, 0)
      ON DUPLICATE KEY UPDATE
        views = views + 1
    ");
    if ($stmt) {
      $stmt->bind_param("ss", $day, $page);
      $stmt->execute();
      $stmt->close();
    }
  }
}

// stats erfassen
// record_presence_and_stats($conn, $user_id, 'statistik.php');

/**
 * Erweiterte Statistiken:
 * - NICHT als "Backdoor", sondern als zusätzlicher Schutz.
 * - Voraussetzung: User ist eingeloggt.
 * - Freischaltung per Passwort für 30 Minuten (Session).
 *
 * In config_stats.php hinzufügen:
 *   define('STATS_UNLOCK_HASH', password_hash('fc b189', PASSWORD_DEFAULT));
 * oder einmalig den Hash erzeugen und hier eintragen.
 */
$unlocked = $is_owner;

if (!$unlocked) {
  $until = $_SESSION['stats_unlock_until'] ?? 0;
  if (is_numeric($until) && (int)$until > time()) {
    $unlocked = true;
  }
}

$unlock_error = null;
if (!$unlocked && isset($_POST['unlock_password'])) {
  $pw = trim((string)$_POST['unlock_password']);
  if ($pw === '') {
    $unlock_error = "Bitte Passwort eingeben.";
  } elseif (!defined('STATS_UNLOCK_HASH')) {
    $unlock_error = "STATS_UNLOCK_HASH fehlt in config_stats.php.";
  } else {
    if (password_verify($pw, STATS_UNLOCK_HASH)) {
      $_SESSION['stats_unlock_until'] = time() + 1800; // 30 Minuten
      $unlocked = true;
    } else {
      $unlock_error = "Falsches Passwort.";
    }
  }
}

$online_since = date('Y-m-d H:i:s', time() - ONLINE_WINDOW_SECONDS);

// Online count
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM user_presence WHERE last_seen >= ?");
$stmt->bind_param("s", $online_since);
$stmt->execute();
$online_count = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Online user list (für alle sichtbar)
$onlineListRes = null;
$online_list = $conn->prepare("
  SELECT u.username, p.last_seen
  FROM user_presence p
  JOIN users u ON u.id = p.user_id
  WHERE p.last_seen >= ?
  ORDER BY p.last_seen DESC
  LIMIT 30
");
if ($online_list) {
  $online_list->bind_param("s", $online_since);
  $online_list->execute();
  $onlineListRes = $online_list->get_result();
}


// Today totals (Views/Unique über alle Seiten)
$today = date('Y-m-d');
$stmt = $conn->prepare("SELECT COALESCE(SUM(views),0) AS v, COALESCE(SUM(unique_visitors),0) AS u
                        FROM site_stats_daily WHERE day = ?");
$stmt->bind_param("s", $today);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$views_today = (int)$row['v'];
$unique_today = (int)$row['u'];
// Gesamt-Views/Unique (alle Tage)
if ($stmt = $conn->prepare("SELECT COALESCE(SUM(views),0) AS v, COALESCE(SUM(unique_visitors),0) AS u FROM site_stats_daily")) {
  $stmt->execute();
  $rowAll = $stmt->get_result()->fetch_assoc();
  $views_total  = (int)($rowAll['v'] ?? 0);
  $unique_total = (int)($rowAll['u'] ?? 0);
  $stmt->close();
} else {
  $views_total = 0; $unique_total = 0;
}

// Anzahl Accounts (Users)
if ($stmt = $conn->prepare("SELECT COUNT(*) AS c FROM users")) {
  $stmt->execute();
  $accounts_total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
  $stmt->close();
} else { $accounts_total = 0; }

// Nachrichten-Verlauf 30 Tage (privat + gruppen zusammen)
$msg30 = $conn->query("
  SELECT d, SUM(c) AS messages
  FROM (
    SELECT DATE(`$messages_time_col`) AS d, COUNT(*) AS c
    FROM messages
    WHERE `$messages_time_col` >= (CURDATE() - INTERVAL 30 DAY)
    GROUP BY DATE(`$messages_time_col`)

    UNION ALL

    SELECT DATE(`$group_messages_time_col`) AS d, COUNT(*) AS c
    FROM group_messages
    WHERE `$group_messages_time_col` >= (CURDATE() - INTERVAL 30 DAY)
    GROUP BY DATE(`$group_messages_time_col`)
  ) x
  GROUP BY d
  ORDER BY d ASC
");

$msg30_labels = [];
$msg30_values = [];
if ($msg30) {
  while ($r = $msg30->fetch_assoc()) {
    $msg30_labels[] = $r['d'];
    $msg30_values[] = (int)$r['messages'];
  }
}

// Total messages (all time)
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM messages");
$stmt->execute();
$total_messages = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Messages today (schnell & zuverlässig, nutzt Index besser)
$stmt = $conn->prepare("
  SELECT
    (SELECT COUNT(*) FROM messages WHERE `$messages_time_col` >= CURDATE() AND `$messages_time_col` < (CURDATE() + INTERVAL 1 DAY))
  + (SELECT COUNT(*) FROM group_messages WHERE `$group_messages_time_col` >= CURDATE() AND `$group_messages_time_col` < (CURDATE() + INTERVAL 1 DAY))
  AS c
");
$stmt->execute();
$messages_today = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Top 10 senders (all time) – private + group zusammen
$top_senders = $conn->query("
  SELECT username, SUM(c) AS c
  FROM (
    SELECT u.username AS username, COUNT(*) AS c
    FROM messages m
    JOIN users u ON u.id = m.sender_id
    GROUP BY m.sender_id

    UNION ALL

    SELECT u.username AS username, COUNT(*) AS c
    FROM group_messages gm
    JOIN users u ON u.id = gm.sender_id
    GROUP BY gm.sender_id
  ) x
  GROUP BY username
  ORDER BY c DESC
  LIMIT 10
");

// Last 14 days (Views/Unique)
$stats = $conn->query("
  SELECT day, page, views, unique_visitors
  FROM site_stats_daily
  WHERE day >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
  ORDER BY day DESC, page ASC
");

// Messages last 14 days (privat + gruppen zusammen)
$msg14 = $conn->query("
  SELECT d, SUM(c) AS messages
  FROM (
    SELECT DATE(`$messages_time_col`) AS d, COUNT(*) AS c
    FROM messages
    WHERE `$messages_time_col` >= (CURDATE() - INTERVAL 14 DAY)
    GROUP BY DATE(`$messages_time_col`)

    UNION ALL

    SELECT DATE(`$group_messages_time_col`) AS d, COUNT(*) AS c
    FROM group_messages
    WHERE `$group_messages_time_col` >= (CURDATE() - INTERVAL 14 DAY)
    GROUP BY DATE(`$group_messages_time_col`)
  ) x
  GROUP BY d
  ORDER BY d DESC
");

$msgByDay = [];
if ($msg14) {
  while ($r = $msg14->fetch_assoc()) {
    $msgByDay[$r['d']] = (int)$r['messages'];
  }
}

// Erweiterte: Zuletzt online Liste
$last_online = null;
if ($unlocked) {
  $last_online = $conn->query("
    SELECT u.username, p.last_seen, p.last_page
    FROM user_presence p
    JOIN users u ON u.id = p.user_id
    ORDER BY p.last_seen DESC
    LIMIT 50
  ");
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Statistik – Gigachat</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:1100px;margin:24px auto;padding:0 16px;line-height:1.5}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #ccc;padding:8px;text-align:left}
    .box{border:1px solid #ccc;border-radius:10px;padding:12px;margin:12px 0}
    a.btn{display:inline-block;padding:10px 14px;border:1px solid #888;border-radius:8px;text-decoration:none}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
    .kpi{border:1px solid #ccc;border-radius:10px;padding:12px}
    .kpi .big{font-size:22px;font-weight:bold}
    .muted{opacity:.75}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    input[type=password]{padding:10px 12px;border:1px solid #aaa;border-radius:10px;min-width:220px}
    button{padding:10px 12px;border:1px solid #888;border-radius:10px;background:transparent;cursor:pointer}
    .err{color:#b00020;font-weight:bold}
    .chart-wrap{height: 220px;width: 100%;max-width: 100%;overflow: hidden;}
  </style>
</head>
<body>

<a class="btn" href="index.php">← Zurück zur Startseite</a>

<h1>Statistik</h1>

<div class="grid">
  <div class="kpi">
    <div class="muted">Gerade online</div>
    <div class="big"><?= (int)$online_count ?></div>
  </div>
  <div class="kpi">
    <div class="muted">Views heute</div>
    <div class="big"><?= (int)$views_today ?></div>
  </div>
  <div class="kpi">
    <div class="muted">Unique heute</div>
    <div class="big"><?= (int)$unique_today ?></div>
  </div>

  <div class="kpi">
    <div class="muted">Views gesamt</div>
    <div class="big"><?= (int)$views_total ?></div>
  </div>
  <div class="kpi">
    <div class="muted">Unique gesamt</div>
    <div class="big"><?= (int)$unique_total ?></div>
  </div>
  <div class="kpi">
    <div class="muted">Accounts gesamt</div>
    <div class="big"><?= (int)$accounts_total ?></div>
  </div>
  <div class="kpi">
    <div class="muted">Nachrichten heute</div>
    <div class="big"><?= (int)$messages_today ?></div>
  </div>
  <div class="kpi">
    <div class="muted">Nachrichten gesamt</div>
    <div class="big"><?= (int)$total_messages ?></div>
  </div>
</div>

<div class="box">
  <h2>Gerade online (sichtbar für alle)</h2>
  <table>
    <tr><th>User</th><th>Zuletzt aktiv</th></tr>
    <?php if ($onlineListRes): while($r = $onlineListRes->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($r['username']) ?></td>
        <td><?= htmlspecialchars($r['last_seen']) ?></td>
      </tr>
    <?php endwhile; else: ?>
      <tr><td colspan="2">(Noch keine Online-Daten – Tabellen/Stats noch nicht eingerichtet)</td></tr>
    <?php endif; ?>
  </table>
  <div class="muted">Online = aktiv in den letzten <?= (int)ONLINE_WINDOW_SECONDS ?> Sekunden.</div>
</div>

<div class="box">
  <h2>Nachrichten-Verlauf (letzte 30 Tage)</h2>
<div class="chart-wrap">
  <canvas id="msgChart"></canvas>
</div>
  <div class="muted">Privat + Gruppen zusammen.</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const labels = <?= json_encode($msg30_labels, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const values = <?= json_encode($msg30_values, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

  const ctx = document.getElementById('msgChart').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Nachrichten pro Tag',
        data: values,
        tension: 0.25,
        fill: false
      }]
    },
  options: {
    responsive: true,
    maintainAspectRatio: false,   // WICHTIG: nutzt die .chart-wrap Höhe
    animation: false,             // verhindert Browser-Lag/Freeze
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 } },
      x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } } // weniger Labels
    },
    plugins: {
      legend: { display: true }
    }
  }
  });
</script>

<div class="box">
  <h2>Top 10 Nutzer nach gesendeten Nachrichten</h2>
  <table>
    <tr><th>Platz</th><th>User</th><th>Nachrichten</th></tr>
    <?php $rank = 1; while($t = $top_senders->fetch_assoc()): ?>
      <tr>
        <td><?= $rank++ ?></td>
        <td><?= htmlspecialchars($t['username']) ?></td>
        <td><?= (int)$t['c'] ?></td>
      </tr>
    <?php endwhile; ?>
  </table>
</div>

<div class="box">
  <h2>Letzte 14 Tage (Views / Unique / Nachrichten)</h2>
  <table>
    <tr><th>Tag</th><th>Seite</th><th>Views</th><th>Unique</th><th>Nachrichten (Tag)</th></tr>
    <?php while($s = $stats->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($s['day']) ?></td>
        <td><?= htmlspecialchars($s['page']) ?></td>
        <td><?= (int)$s['views'] ?></td>
        <td><?= (int)$s['unique_visitors'] ?></td>
        <td><?= (int)($msgByDay[$s['day']] ?? 0) ?></td>
      </tr>
    <?php endwhile; ?>
  </table>
</div>

<div class="box">
  <h2>Erweiterte Statistiken</h2>

  <?php if ($unlocked): ?>
    <div class="muted">Freigeschaltet ✅</div>

    <div class="box">
      <h3>Zuletzt online (max. 50)</h3>
      <table>
        <tr><th>User</th><th>Zuletzt aktiv</th><th>Letzte Seite</th></tr>
        <?php if ($last_online): while($r = $last_online->fetch_assoc()): ?>
          <tr>
            <td><?= htmlspecialchars($r['username']) ?></td>
            <td><?= htmlspecialchars($r['last_seen']) ?></td>
            <td><?= htmlspecialchars($r['last_page'] ?? '') ?></td>
          </tr>
        <?php endwhile; endif; ?>
      </table>
    </div>

  <?php else: ?>
    <form method="post" class="row" autocomplete="off">
      <input type="password" name="unlock_password" placeholder="Passwort für mehr Statistik">
      <button type="submit">Freischalten</button>
      <?php if ($unlock_error): ?>
        <span class="err"><?= htmlspecialchars($unlock_error) ?></span>
      <?php endif; ?>
      <div class="muted" style="flex-basis:100%;">Freischaltung gilt 30 Minuten (nur wenn du eingeloggt bist).</div>
    </form>
  <?php endif; ?>
</div>

</body>
</html>
