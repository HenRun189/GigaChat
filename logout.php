<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');


// Datenbankverbindung
$conn = new mysqli('localhost', 'webapp_user', 'g679*.<cS5LK', 'chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

if ($conn->connect_error) {
    // Bei DB-Fehler trotzdem logout
    $conn = null;
}

// Alle Sessions killen
session_destroy();
session_start(); // Neu starten zum Cookie-Clearen
session_unset();

// Remember Me Cookie löschen (falls vorhanden)
if (isset($_COOKIE['remember'])) {
    list($selector) = explode(':', $_COOKIE['remember'], 2);
    
    if ($conn && $selector) {
        // Token aus DB löschen
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE selector = ?");
        if ($stmt) {
            $stmt->bind_param("s", $selector);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Cookie löschen
    setcookie('remember', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}

if ($conn) {
    $conn->close();
}

// Alle Cookies löschen (optional)
if (isset($_COOKIE)) {
    foreach ($_COOKIE as $name => $value) {
        setcookie($name, '', time() - 3600, '/');
    }
}

header("Location: login.php");
exit;
?>
