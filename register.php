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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $username_value = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

    if (strlen($username) < 1) {
        $message = "Benutzername muss mindestens 1 Zeichen haben.";
    } elseif (!preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
        $message = "Benutzername darf nur Buchstaben, Zahlen, ., _ und - enthalten.";
    } elseif (strlen($password) < 3) {
        $message = "Passwort muss mindestens 3 Zeichen haben.";
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
?>

<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registrierung</title>
<style>
form .message {
    background: #f8d7da; 
    color: #721c24;      
    padding: 10px;
    border-radius: 6px; 
    margin-top: 10px;
    font-size: 16px;
}
</style>
</head>
<body>

<form method="post" autocomplete="off">
    <input type="text" name="username" placeholder="Benutzername" value="<?= $username_value ?>" required>
    <input type="password" name="password" placeholder="Passwort" required>

    <?php if ($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <button>Registrieren</button>
    <h1>Bereits registriert? <br><a href="login.php">Hier einloggen</a></h1>

</form>

</body>
</html>



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

    input {
        padding: 15px;
        border: 1px solid #ccc;
        border-radius: 6px;
        font-size: 18px;
    }

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

    form .message {
    background: #f8d7da; /* rotlicher Hintergrund für Fehler */
    color: #721c24;      /* rote Schrift */
    padding: 10px;
    border-radius: 6px;
    margin-top: 10px;    /* Abstand zu den Inputs */
    font-size: 16px;
}


    button:hover, a:hover {
        background-color: #218838;
    }

    /* ======== Mobile Anpassungen ======== */
    @media (max-width: 768px) {
        form, .message {
            min-width: 90%;
            padding: 30px;
            gap: 15px;
        }
        input, button, a {
            padding: 18px;
            font-size: 20px;
        }
    }

    @media (max-width: 480px) {
        form, .message {
            min-width: 95%;
            padding: 25px;
            gap: 12px;
        }
        input, button, a {
            padding: 20px;
            font-size: 22px;
        }
    }
  </style>