<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Nicht eingeloggt!";
    exit;
}

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

if ($conn->connect_error) {
    http_response_code(500);
    echo "DB-Verbindung fehlgeschlagen: ".$conn->connect_error;
    exit;
}

$current_chat = isset($_GET['current_chat']) ? (int)$_GET['current_chat'] : 0;
$user_id      = (int)$_SESSION['user_id'];

$contacts = [];

/*
 * USER-KONTAKTE
 * - pinned zuerst
 * - normale
 * - archivierte (für „Archiv“-Bereich)
 * - innerhalb jeder Gruppe nach letzter Nachricht
 */
$sqlUsers = "
    SELECT 
        u.id,
        u.username,
        ua.alias,
        u.color,
        u.emoji,
        u.gigascore,
        COALESCE(ua.pinned, 0)   AS pinned,
        COALESCE(ua.archived, 0) AS archived,
        (
            SELECT MAX(m.created_at)
            FROM messages m
            WHERE (m.sender_id = u.id AND m.receiver_id = ?)
               OR (m.sender_id = ? AND m.receiver_id = u.id)
        ) AS last_msg_at
    FROM users u
    LEFT JOIN user_aliases ua 
        ON ua.owner_id = ? AND ua.target_id = u.id
    WHERE u.id != ?
    ORDER BY
        COALESCE(ua.archived, 0) ASC,          -- 0 oben, 1 Archiv
        COALESCE(ua.pinned, 0)  DESC,          -- pinned ganz oben

        -- Zuerst Kontakte MIT letzter Nachricht (1), dann ohne (2)
        CASE WHEN last_msg_at IS NULL THEN 2 ELSE 1 END ASC,

        -- innerhalb der „mit Nachricht“ nach Datum
        last_msg_at DESC,

        -- innerhalb der „ohne Nachricht“ nach ID
        u.id ASC
";


$stmt = $conn->prepare($sqlUsers);
$stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    // ungelesene Nachrichten (nur wenn nicht aktuell offen)
    $newCount = 0;
    if ($current_chat != (int)$row['id'] && (int)$row['archived'] === 0) {
        $sqlNew = "
            SELECT COUNT(*) AS cnt
            FROM messages
            WHERE sender_id = {$row['id']}
              AND receiver_id = $user_id
              AND read_at IS NULL
        ";
        $resNew   = $conn->query($sqlNew);
        $newCount = (int)$resNew->fetch_assoc()['cnt'];
    }

    $contacts[] = [
        'id'        => (int)$row['id'],
        'username'  => $row['alias'] ?: $row['username'],
        'new'       => $newCount,
        'type'      => 'user',
        'color'     => $row['color'],
        'emoji'     => $row['emoji'],
        'pinned'    => (int)$row['pinned'],
        'archived'  => (int)$row['archived'],
        'last_msg'  => $row['last_msg_at'],
        'gigascore' => (int)($row['gigascore'] ?? 0),

    ];
}
$stmt->close();

$sqlGroups = "
    SELECT 
        g.id,
        g.name,
        (
            SELECT COUNT(*)
            FROM group_messages gm
            LEFT JOIN group_reads gr
              ON gr.group_id = g.id
              AND gr.user_id = $user_id
            WHERE gm.group_id = g.id
              AND gm.sender_id != $user_id
              AND (
                   gr.last_read_msg_id IS NULL
                   OR gm.id > gr.last_read_msg_id
              )
        ) AS new
    FROM chat_groups g
    JOIN group_members gm2 ON gm2.group_id = g.id
    WHERE gm2.user_id = $user_id
";

$groups = $conn->query($sqlGroups);

while ($g = $groups->fetch_assoc()) {
    $contacts[] = [
        'id'       => (int)$g['id'],
        'username' => $g['name'],
        'new'      => (int)$g['new'],
        'type'     => 'group',
        'color'    => null,
        'emoji'    => null,
        'pinned'   => 0,
        'archived' => 0,
        'last_msg' => null,
    ];
}

header('Content-Type: application/json');
echo json_encode($contacts);
