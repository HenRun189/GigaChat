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

$conn = new mysqli('localhost','webapp_user','g679*.<cS5LK','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");
if ($conn->connect_error) {
    http_response_code(500);
    exit('DB error');
}

$user_id   = (int)$_SESSION['user_id'];             // owner_id
$target_id = (int)($_POST['target_id'] ?? 0);
$action    = $_POST['action'] ?? '';                // 'pin' | 'archive' | 'alias'
$alias     = trim($_POST['alias'] ?? '');

if (!$target_id) {
    http_response_code(400);
    exit('bad request');
}

/*
 * Wir wollen immer genau eine Zeile in user_aliases haben.
 * Darum zuerst sicherstellen, dass sie existiert.
 */
$conn->query("
    INSERT IGNORE INTO user_aliases (owner_id, target_id)
    VALUES ($user_id, $target_id)
");

if ($action === 'pin' || $action === 'archive') {

    $field = $action === 'pin' ? 'pinned' : 'archived';

    // Toggle 0 ↔ 1
    $sql = "
        UPDATE user_aliases
        SET $field = IF($field = 1, 0, 1)
        WHERE owner_id = $user_id AND target_id = $target_id
    ";
    $conn->query($sql);

    echo json_encode(['ok' => true, 'action' => $action]);
    exit;
}

if ($action === 'alias') {

    if ($alias === '') {
        // leer = Alias + Flags für diesen Kontakt komplett löschen
        $stmt = $conn->prepare("
            DELETE FROM user_aliases
            WHERE owner_id=? AND target_id=?
        ");
        $stmt->bind_param("ii", $user_id, $target_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => true, 'alias_deleted' => true]);
        exit;
    }

    // Alias setzen oder updaten, Flags bleiben unverändert
    $stmt = $conn->prepare("
        INSERT INTO user_aliases (owner_id, target_id, alias)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE alias = VALUES(alias)
    ");
    $stmt->bind_param("iis", $user_id, $target_id, $alias);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true, 'alias' => $alias]);
    exit;
}

http_response_code(400);
echo 'unknown action';
