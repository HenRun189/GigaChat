<?php
session_start();

/* ===== DEBUG ===== */
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

/* ===== LOGIN CHECK ===== */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['ok'=>false, 'error'=>'not_logged_in']));
}

$sender = (int)$_SESSION['user_id'];

/* ===== DB ===== */
$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

if ($conn->connect_error) {
    http_response_code(500);
    exit('DB ERROR');
}

/* ⚠️ ZEIT SO LASSEN WIE BEI DIR */
$conn->query("SET time_zone = 'Europe/Berlin'");

/* =================================================
   INPUT CHECK (JSON ODER POST)
   ================================================= */
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

$isGif = is_array($data) && ($data['type'] ?? '') === 'gif';

$group_id = (int)($isGif ? ($data['group_id'] ?? 0) : ($_POST['group_id'] ?? 0));
$message  = $isGif ? '' : trim($_POST['message'] ?? '');
$gif      = $isGif ? trim($data['gif_url'] ?? '') : '';

if (!$group_id) {
    http_response_code(400);
    exit(json_encode(['ok'=>false, 'error'=>'no_group']));
}

if (!$isGif && strlen($message) < 1) {
    http_response_code(400);
    exit(json_encode(['ok'=>false, 'error'=>'message_too_short']));
}

if ($isGif && !$gif) {
    http_response_code(400);
    exit(json_encode(['ok'=>false, 'error'=>'bad_gif']));
}

/* =================================================
   SICHERHEIT: IST USER IN DER GRUPPE?
   ================================================= */
$memberCheck = $conn->prepare("
  SELECT 1 FROM group_members
  WHERE group_id = ? AND user_id = ?
  LIMIT 1
");
$memberCheck->bind_param("ii", $group_id, $sender);
$memberCheck->execute();
$memberCheck->store_result();

if ($memberCheck->num_rows === 0) {
    http_response_code(403);
    exit(json_encode(['ok'=>false, 'error'=>'not_group_member']));
}
$memberCheck->close();

/* =================================================
   DUPLIKAT / SPAM SCHUTZ (1 SEK)
   ================================================= */
if ($isGif) {
    $check = $conn->prepare("
      SELECT id FROM group_messages
      WHERE group_id = ?
        AND sender_id = ?
        AND gif_url = ?
        AND created_at > (NOW() - INTERVAL 1 SECOND)
      LIMIT 1
    ");
    $check->bind_param("iis", $group_id, $sender, $gif);
} else {
    $check = $conn->prepare("
      SELECT id FROM group_messages
      WHERE group_id = ?
        AND sender_id = ?
        AND message = ?
        AND created_at > (NOW() - INTERVAL 1 SECOND)
      LIMIT 1
    ");
    $check->bind_param("iis", $group_id, $sender, $message);
}

$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo json_encode(['ok'=>true, 'duplicate'=>true]);
    exit;
}
$check->close();

/* =================================================
   INSERT MESSAGE
   ================================================= */
if ($isGif) {
    $stmt = $conn->prepare("
        INSERT INTO group_messages (group_id, sender_id, message, gif_url, created_at)
        VALUES (?, ?, '[gif]', ?, NOW())
    ");
    $stmt->bind_param("iis", $group_id, $sender, $gif);
} else {
    $stmt = $conn->prepare("
        INSERT INTO group_messages (group_id, sender_id, message, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iis", $group_id, $sender, $message);
}

$stmt->execute();

if ($stmt->affected_rows !== 1) {
    http_response_code(500);
    exit(json_encode(['ok'=>false, 'error'=>'insert_failed']));
}
$stmt->close();

/* =================================================
   GIGASCORE – ALLE GRUPPENMITGLIEDER +1
   ================================================= */
$scoreAll = $conn->prepare("
  UPDATE users u
  JOIN group_members gm ON gm.user_id = u.id
  SET u.gigascore = u.gigascore + 1
  WHERE gm.group_id = ?
");
$scoreAll->bind_param("i", $group_id);
$scoreAll->execute();
$scoreAll->close();


/* =================================================
   PUSH BENACHRICHTIGUNGEN
   ================================================= */
require __DIR__ . '/vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$vapid = [
    'subject'    => 'mailto:henri@linhi.de',
    'publicKey'  => 'BGKaUZpoIpfq4mOBjwj4SnTol4lTpimNI113onzGdUMNMGt0VMiz9sJdYdXwhEeQ_3Z_Jiz7XTZwelw617tcpI8',
    'privateKey' => 'MI218rNHsQfxhlOWvSfbMOc8yeTMNQC5u1evbb5wSIc',
];

// Gruppenname
$stmt = $conn->prepare("SELECT name FROM chat_groups WHERE id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$stmt->bind_result($groupName);
$stmt->fetch();
$stmt->close();

$senderName = $_SESSION['username'] ?? 'Unbekannt';
if ($isGif) {
    $preview = 'hat ein GIF geschickt';
} else {
    $text = trim($message);
    if ($text === '') {
        $text = 'Nachricht';
    }
    $preview = mb_strimwidth($text, 0, 80, '…');
}

$webPush = new WebPush(['VAPID' => $vapid]);

$sql = "
  SELECT ps.endpoint, ps.p256dh, ps.auth
  FROM group_members gm
  JOIN push_subscriptions ps ON ps.user_id = gm.user_id
  WHERE gm.group_id = ?
    AND gm.user_id <> ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $group_id, $sender);
$stmt->execute();
$res = $stmt->get_result();

$payload = json_encode([
  'title' => 'Neue Nachricht',
  'body'  => $groupName . ' • ' . $senderName . ': ' . $preview,
  'chat'  => $group_id,
  'type'  => 'group'
]);

while ($row = $res->fetch_assoc()) {
    $sub = Subscription::create([
        'endpoint'  => $row['endpoint'],
        'publicKey' => $row['p256dh'],
        'authToken' => $row['auth'],
    ]);
    $webPush->queueNotification($sub, $payload);
}
$stmt->close();

// Abgelaufene Push-Subs löschen
foreach ($webPush->flush() as $report) {
    if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
        $ep = $report->getEndpoint();
        $del = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
        $del->bind_param('s', $ep);
        $del->execute();
    }
}

echo json_encode(['ok'=>true]);
