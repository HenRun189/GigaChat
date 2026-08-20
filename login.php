<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

$conn = new mysqli('localhost', 'root', 'WBNhN16u', 'chat');
if ($conn->connect_error) {
    die("DB-Verbindung fehlgeschlagen.");
}
$conn->query("SET time_zone = 'Europe/Berlin'");

$message = "";
$username_value = "";

// ── CSRF-Token generieren ────────────────────────────────────────────────────
if (empty($_SESSION['csrf_login_token'])) {
    $_SESSION['csrf_login_token'] = bin2hex(random_bytes(32));
}

// ── Rate-Limiting (Login-Versuche, IP-basiert) ────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rl_key = 'login_rl_' . md5($ip);
if (!isset($_SESSION[$rl_key])) {
    $_SESSION[$rl_key] = ['count' => 0, 'window_start' => time()];
}
$rl = &$_SESSION[$rl_key];
if (time() - $rl['window_start'] > 900) { // Fenster: 15 Minuten
    $rl = ['count' => 0, 'window_start' => time()];
}
$rate_limited = ($rl['count'] >= 10); // max. 10 Fehlversuche pro 15 min

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ── CSRF-Check ────────────────────────────────────────────────────────────
    $csrf_ok = isset($_POST['csrf_token']) &&
               hash_equals($_SESSION['csrf_login_token'], $_POST['csrf_token']);
    if (!$csrf_ok) {
        $message = "Ungültige Anfrage. Bitte Seite neu laden.";
    } elseif ($rate_limited) {
        $message = "Zu viele Fehlversuche. Bitte 15 Minuten warten.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $username_value = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
            $message = "Ungültiger Benutzername.";
        } else {
            $stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $storedHash = $row['password'];

                // NUR password_verify – kein hash_equals-Bypass mehr!
                if (password_verify($password, $storedHash)) {
                    $rl['count'] = 0; // Zähler zurücksetzen bei Erfolg
                    // CSRF-Token rotieren
                    $_SESSION['csrf_login_token'] = bin2hex(random_bytes(32));

                    session_regenerate_id(true);
                    $_SESSION['user_id']  = $row['id'];
                    $_SESSION['username'] = $username;

                    if (isset($_POST['remember']) && $_POST['remember'] == '1') {
                        $selector    = bin2hex(random_bytes(8));
                        $token       = random_bytes(32);
                        $hashedToken = password_hash($token, PASSWORD_DEFAULT);

                        $stmt_rm = $conn->prepare("INSERT INTO remember_tokens
                            (user_id, selector, hashed_token, expires_at)
                            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
                            ON DUPLICATE KEY UPDATE
                                hashed_token = VALUES(hashed_token),
                                expires_at   = VALUES(expires_at)");
                        $stmt_rm->bind_param("iss", $row['id'], $selector, $hashedToken);
                        $stmt_rm->execute();
                        $stmt_rm->close();

                        setcookie('remember', $selector . ':' . base64_encode($token), [
                            'expires'  => time() + (30 * 24 * 60 * 60),
                            'path'     => '/',
                            'secure'   => true,   // HTTPS erzwingen
                            'httponly' => true,
                            'samesite' => 'Strict'
                        ]);
                    }

                    header("Location: index.php");
                    exit;
                } else {
                    $rl['count']++; // Fehlversuch zählen
                    // Absichtlich gleiche Meldung (kein Username-Enumeration)
                    $message = "Benutzername oder Passwort falsch.";
                }
            } else {
                $rl['count']++;
                $message = "Benutzername oder Passwort falsch.";
            }
            $stmt->close();
        }
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login</title>
    <style>
        body { display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #191919; font-family: Arial, sans-serif; font-size: 20px; }
        form { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 0 20px rgba(0,0,0,0.2); display: flex; flex-direction: column; gap: 20px; min-width: 400px; }
        input { padding: 15px; border: 1px solid #ccc; border-radius: 6px; font-size: 18px; }
        button { padding: 15px; background-color: #28a745; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 18px; font-weight: bold; transition: background-color 0.3s; }
        button:hover { background-color: #218738; }
        .remember-me { display: flex; align-items: center; gap: 8px; margin: 15px 0; font-size: 14px; }
        .remember-me input[type="checkbox"] { width: 18px; height: 18px; accent-color: #4f46e5; }
        .message { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 6px; font-size: 16px; }
        .menu { background-color: #007bff; box-shadow: 0 0 10px rgba(0,0,0,0.2); color: white; padding: 10px; border-radius: 6px; }
        .menu:hover { background-color: #0056b3; cursor: pointer; }
        @media (max-width: 768px) { form { min-width: 90%; padding: 30px; gap: 15px; } input, button { padding: 18px; font-size: 20px; } }
        @media (max-width: 480px) { form { min-width: 95%; padding: 25px; gap: 12px; } input, button { padding: 20px; font-size: 22px; } }
    </style>
</head>
<body>
<form method="POST" autocomplete="off">
    <!-- CSRF-Token -->
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_login_token'], ENT_QUOTES, 'UTF-8') ?>">

    <input type="text" name="username" placeholder="Benutzername" value="<?= $username_value ?>" required>
    <input type="password" name="password" placeholder="Passwort" required>

    <label class="remember-me">
        <input type="checkbox" name="remember" value="1"> Angemeldet bleiben
    </label>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($rate_limited): ?>
        <div class="message">Zu viele Fehlversuche. Bitte 15 Minuten warten.</div>
    <?php else: ?>
        <button type="submit">Login</button>
    <?php endif; ?>

    <h1>Noch nicht registriert? <br><a href="register.php">Hier registrieren</a></h1>
    <h2 class="menu" onclick="window.open('menu/')">Menü andere Webseiten</h2>
</form>
</body>
</html>
