<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['message_id'], $data['text'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$msg_id  = (int)$data['message_id'];
$text    = trim($data['text']);

if ($text === '') {
    echo json_encode(['ok'=>false]);
    exit;
}

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone='Europe/Berlin'");

// darf nur eigene Nachricht bearbeiten
$check = $conn->prepare("
    SELECT id FROM group_messages
    WHERE id = ? AND sender_id = ? AND deleted = 0
");
$check->bind_param("ii", $msg_id, $user_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    echo json_encode(['ok'=>false]);
    exit;
}
$check->close();

$stmt = $conn->prepare("
    UPDATE group_messages
    SET message = ?, edited = 1
    WHERE id = ?
");
$stmt->bind_param("si", $text, $msg_id);
$stmt->execute();

echo json_encode(['ok'=>true]);
