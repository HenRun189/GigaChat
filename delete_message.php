<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'error'=>'NOT_LOGGED_IN']);
    exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!isset($data['message_id'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'MISSING_ID']);
    exit;
}

$user_id    = (int)$_SESSION['user_id'];
$message_id = (int)$data['message_id'];

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

// Prüfen: eigene Nachricht?
$check = $conn->prepare("
    SELECT id FROM messages
    WHERE id = ? AND sender_id = ? AND deleted = 0
");
$check->bind_param("ii", $message_id, $user_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    echo json_encode(['ok'=>false, 'error'=>'NOT_ALLOWED']);
    exit;
}
$check->close();

// Soft-Delete (WhatsApp-Style)
$stmt = $conn->prepare("
    UPDATE messages
    SET message = '[gelöscht]', deleted = 1
    WHERE id = ?
");
$stmt->bind_param("i", $message_id);
$stmt->execute();
$stmt->close();

echo json_encode(['ok'=>true]);
