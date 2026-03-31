<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

$user_id    = (int)$_SESSION['user_id'];
$receiver_id = (int)($_POST['receiver_id'] ?? 0);
$type        = $_POST['type'] ?? 'user'; // 'user' oder 'group'

$conn = new mysqli('localhost','root','WBNhN16u','chat');
$conn->query("SET time_zone = 'Europe/Berlin'");
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

/* === Datei prüfen === */
if (empty($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_file']);
    exit;
}

// 20 MB Limit
$maxBytes = 20 * 1024 * 1024;
if ($_FILES['file']['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'too_large']);
    exit;
}

$originalName = $_FILES['file']['name'];
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

$allowedExtensions = [
    'jpg','jpeg','png','gif','webp',
    'pdf','doc','docx','xls','xlsx','ppt','pptx','txt'
];
if (!in_array($ext, $allowedExtensions, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'type_not_allowed', 'ext' => $ext]);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $_FILES['file']['tmp_name']);
finfo_close($finfo);

$dir = 'uploads';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$filename = uniqid('file_', true) . '.' . $ext;
$pathRel  = $dir . '/' . $filename;
$pathAbs  = __DIR__ . '/' . $pathRel;

$isImage = 0;

/* === Upload-Fehler prüfen === */
if (isset($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'upload_error', 'code' => (int)$_FILES['file']['error']]);
    exit;
}

$dir = 'uploads';
if (!is_dir($dir)) {
    if (!@mkdir($dir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'mkdir_failed']);
        exit;
    }
}
if (!is_writable($dir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'uploads_not_writable']);
    exit;
}

$filename = uniqid('file_', true) . '.' . $ext;
$pathRel  = $dir . '/' . $filename;
$pathAbs  = __DIR__ . '/' . $pathRel;

$isImage = 0;

/* === Bild oder Datei speichern (stabil) === */
$looksLikeImage = (strpos($mime, 'image/') === 0) || in_array($ext, ['jpg','jpeg','png','gif','webp'], true);

if ($looksLikeImage) {
    $isImage = 1;

    // Versuch: Bild verkleinern (für Performance). Wenn das fehlschlägt -> Original speichern.
    $raw = @file_get_contents($_FILES['file']['tmp_name']);
    $src = $raw ? @imagecreatefromstring($raw) : false;

    if ($src !== false) {
        $w = imagesx($src);
        $h = imagesy($src);
        $max   = 1280;
        $scale = min(1, $max / max($w, $h));
        $newW  = max(1, (int)($w * $scale));
        $newH  = max(1, (int)($h * $scale));

        $dst = imagecreatetruecolor($newW, $newH);

        // Transparenz für PNG/GIF halbwegs erhalten
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

        $saved = false;

        // Speichere passend zum Format, sonst JPG
        if ($ext === 'png') {
            $saved = @imagepng($dst, $pathAbs, 6);
        } elseif ($ext === 'gif') {
            $saved = @imagegif($dst, $pathAbs);
        } elseif ($ext === 'webp' && function_exists('imagewebp')) {
            $saved = @imagewebp($dst, $pathAbs, 75);
        } else {
            // JPG/JPEG oder Fallback
            // Wenn ext nicht jpg ist, machen wir trotzdem jpg, damit Inhalt/Endung passt
            if (!in_array($ext, ['jpg','jpeg'], true)) {
                $ext = 'jpg';
                $filename = uniqid('file_', true) . '.jpg';
                $pathRel  = $dir . '/' . $filename;
                $pathAbs  = __DIR__ . '/' . $pathRel;
            }
            $saved = @imagejpeg($dst, $pathAbs, 80);
        }

        imagedestroy($src);
        imagedestroy($dst);

        if (!$saved) {
            // Fallback: Original speichern
            $isImage = 1;
            $filename = uniqid('file_', true) . '.' . $ext;
            $pathRel  = $dir . '/' . $filename;
            $pathAbs  = __DIR__ . '/' . $pathRel;

            if (!move_uploaded_file($_FILES['file']['tmp_name'], $pathAbs)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'save_failed']);
                exit;
            }
        }
    } else {
        // GD konnte das Bild nicht lesen -> Original speichern
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $pathAbs)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'move_failed']);
            exit;
        }
    }
} else {
    // normale Datei: einfach speichern
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $pathAbs)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'move_failed']);
        exit;
    }
}

/* === Nachricht in messages oder group_messages speichern === */
$msgText = $isImage ? '[image]' : $originalName;

if ($type === 'user') {
    // Direktnachricht
    $stmt = $conn->prepare("
        INSERT INTO messages (sender_id, receiver_id, message, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iis", $user_id, $receiver_id, $msgText);
    $stmt->execute();
    $message_id = $stmt->insert_id;
    $stmt->close();
    // === GigaScore (Privat Upload): +1 Sender, +1 Empfänger ===
    $scoreStmt = $conn->prepare("
      UPDATE users
      SET gigascore = gigascore + 1
      WHERE id IN (?, ?)
    ");
    $scoreStmt->bind_param("ii", $user_id, $receiver_id);
    $scoreStmt->execute();
    $scoreStmt->close();


    // Datei-Infos in message_files
    $stmt2 = $conn->prepare("
        INSERT INTO message_files
            (message_id, path, is_image, original_name)
        VALUES (?, ?, ?, ?)
    ");
    $stmt2->bind_param("isis", $message_id, $pathRel, $isImage, $originalName);
    $stmt2->execute();
    $stmt2->close();

    $senderName = $_SESSION['username'] ?? 'Jemand';

    $bodyText = $isImage
        ? $senderName . ' hat ein Bild geschickt'
        : $senderName . ' hat eine Datei geschickt';

    $payload = json_encode([
      'title' => 'Neue Nachricht',
      'body'  => $bodyText,
      'tag'   => 'chat_' . $user_id,
      'chat'  => $user_id,
      'type'  => 'user'
    ]);


} else {
    // Gruppen-Nachricht
    $group_id = $receiver_id; // bei type=group steckt hier die group_id

    $stmt = $conn->prepare("
        INSERT INTO group_messages (group_id, sender_id, message, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iis", $group_id, $user_id, $msgText);
    $stmt->execute();
    $message_id = $stmt->insert_id;
    $stmt->close();
    // === GigaScore (Gruppe Upload): +1 Sender ===
    $scoreSender = $conn->prepare("UPDATE users SET gigascore = gigascore + 1 WHERE id = ?");
    $scoreSender->bind_param("i", $user_id);
    $scoreSender->execute();
    $scoreSender->close();
    // === GigaScore (Gruppe Upload): +1 an alle Mitglieder außer Sender ===
    $scoreMembers = $conn->prepare("
      UPDATE users u
      JOIN group_members gm ON gm.user_id = u.id
      SET u.gigascore = u.gigascore + 1
      WHERE gm.group_id = ? AND gm.user_id <> ?
    ");
    $scoreMembers->bind_param("ii", $group_id, $user_id);
    $scoreMembers->execute();
    $scoreMembers->close();


    // Datei-Infos in group_message_files (eigene Tabelle!)
    $stmt2 = $conn->prepare("
        INSERT INTO group_message_files
            (message_id, path, is_image, original_name)
        VALUES (?, ?, ?, ?)
    ");
    $stmt2->bind_param("isis", $message_id, $pathRel, $isImage, $originalName);
    $stmt2->execute();
    $stmt2->close();

    $payload = json_encode([
      'title' => 'Neue Nachricht',
      'body' => $senderName . ' hat eine Datei in der Gruppe geteilt',
      'tag'   => 'group_' . $group_id,
      'group' => $group_id,
      'type'  => 'group'
    ]);
}

echo json_encode(['ok' => true]);
