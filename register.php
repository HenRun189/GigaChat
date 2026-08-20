<?php
session_start();
$debug = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === 1 && isset($_GET['debug']));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

$conn = new mysqli('localhost', 'webapp_user', 'g679*.<cS5LK', 'chat');
$conn->query("SET time_zone = 'Europe/Berlin'");

$message = "";
$username_value = "";
$defaultColor = '';
$emoji = '';

// ── CSRF-Token generieren ────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Rate-Limiting (IP-basiert, Session-gestützt) ─────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rl_key = 'reg_rl_' . md5($ip);
if (!isset($_SESSION[$rl_key])) {
    $_SESSION[$rl_key] = ['count' => 0, 'window_start' => time()];
}
$rl = &$_SESSION[$rl_key];
if (time() - $rl['window_start'] > 3600) { // Fenster: 1 Stunde
    $rl = ['count' => 0, 'window_start' => time()];
}
$rate_limited = ($rl['count'] >= 5); // max. 5 Registrierungen pro IP/Stunde

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ── CSRF-Check ────────────────────────────────────────────────────────────
    $csrf_ok = isset($_POST['csrf_token']) &&
               hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    if (!$csrf_ok) {
        $message = "Ungültige Anfrage (CSRF). Bitte Seite neu laden.";
    }
    // ── Honeypot-Check (Bot füllt dieses Feld aus, echter User nicht) ────────
    elseif (!empty($_POST['website'])) {
        // Bot erkannt – still ignorieren (kein Fehler ausgeben)
        header("Location: register.php");
        exit;
    }
    // ── Rate-Limit-Check ──────────────────────────────────────────────────────
    elseif ($rate_limited) {
        $message = "Zu viele Registrierungsversuche. Bitte später erneut versuchen.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $username_value = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

        if (strlen($username) < 3) {
            $message = "Benutzername muss mindestens 3 Zeichen haben.";
        } elseif (strlen($username) > 30) {
            $message = "Benutzername darf maximal 30 Zeichen haben.";
        } elseif (!preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
            $message = "Benutzername darf nur Buchstaben, Zahlen, ., _ und - enthalten.";
        } elseif (strlen($password) < 8) {
            $message = "Passwort muss mindestens 8 Zeichen haben.";
        } else {
            $pw_hash = password_hash($password, PASSWORD_DEFAULT);

            $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check->bind_param("s", $username);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $message = "Benutzername bereits vergeben.";
            } else {
                $stmt = $conn->prepare("INSERT INTO users (username, password, color, emoji) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $username, $pw_hash, $defaultColor, $emoji);

                if ($stmt->execute()) {
                    $rl['count']++; // Rate-Limit-Zähler erhöhen
                    // CSRF-Token invalidieren nach erfolgreicher Registrierung
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $newUserId = $stmt->insert_id;
                    $_SESSION['user_id'] = $newUserId;
                    $_SESSION['username'] = $username;
                    header("Location: index.php");
                    exit;
                } else {
                    $message = "Fehler bei der Registrierung.";
                }
            }
            $check->close();
        }
    }
}
?>

<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registrierung</title>
<style>
    body {
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        background-color: #191919;
        font-family: Arial, sans-serif;
        font-size: 20px;
        margin: 0;
    }
    form, .message {
        background: white;
        padding: 40px;
        border-radius: 12px;
        box-shadow: 0 0 20px rgba(0,0,0,0.2);
        display: flex;
        flex-direction: column;
        gap: 20px;
        min-width: 400px;
        text-align: center;
    }
    input { padding: 15px; border: 1px solid #ccc; border-radius: 6px; font-size: 18px; }
    button, a {
        padding: 15px;
        background-color: #28a745;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 18px;
        font-weight: bold;
        text-decoration: none;
        transition: background-color 0.3s;
    }
    button:hover, a:hover { background-color: #218838; }
    .message { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 6px; margin-top: 10px; font-size: 16px; }
    /* Honeypot verstecken */
    .hp-field { display: none !important; }
    @media (max-width: 768px) {
        form, .message { min-width: 90%; padding: 30px; gap: 15px; }
        input, button, a { padding: 18px; font-size: 20px; }
    }
    @media (max-width: 480px) {
        form, .message { min-width: 95%; padding: 25px; gap: 12px; }
        input, button, a { padding: 20px; font-size: 22px; }
    }
</style>
</head>
<body>

<form method="post" autocomplete="off">
    <!-- CSRF-Token -->
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

    <!-- Honeypot: für echte User unsichtbar, Bots füllen es aus -->
    <div class="hp-field">
        <input type="text" name="website" value="" tabindex="-1" autocomplete="off">
    </div>

    <input type="text" name="username" placeholder="Benutzername (mind. 3 Zeichen)" value="<?= $username_value ?>" required>
    <input type="password" name="password" placeholder="Passwort (mind. 8 Zeichen)" required>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($rate_limited): ?>
        <div class="message">Zu viele Versuche. Bitte warte eine Stunde.</div>
    <?php else: ?>
        <button type="submit">Registrieren</button>
    <?php endif; ?>

    <a href="login.php">Bereits registriert? Hier einloggen</a>
</form>

</body>
</html>
