<?php
session_start();

header("Content-Type: application/json; charset=utf-8");

// Login prüfen
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["error"=>"not_logged_in"]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// DB verbinden
$conn = new mysqli('localhost','webapp_user','g679*.<cS5LK','chat');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["error"=>"db_connect_failed"]);
    exit;
}

$conn->query("SET time_zone = 'Europe/Berlin'");

$cooldown = 60;


// ----------------------
// INPUT LESEN
// ----------------------

// funktioniert für JSON und FormData
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input || !is_array($input)) {
    $input = $_POST;
}

if (!isset($input["x"]) || !isset($input["y"]) || !isset($input["color"])) {
    echo json_encode(["error"=>"invalid_input"]);
    exit;
}

$x = intval($input["x"]);
$y = intval($input["y"]);
$color = $input["color"];


// ----------------------
// VALIDATION
// ----------------------

if ($x < 0 || $x > 199 || $y < 0 || $y > 199) {
    echo json_encode(["error"=>"out_of_bounds"]);
    exit;
}

if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
    echo json_encode(["error"=>"bad_color"]);
    exit;
}


// ----------------------
// COOLDOWN
// ----------------------

if (!isset($_SESSION["last_pixel"])) {
    $_SESSION["last_pixel"] = 0;
}

if (time() - $_SESSION["last_pixel"] < $cooldown) {
    $remain = $cooldown - (time() - $_SESSION["last_pixel"]);
    echo json_encode(["error"=>"cooldown","seconds"=>$remain]);
    exit;
}


// ----------------------
// CANVAS LADEN
// ----------------------

$file = __DIR__ . "/canvas.json";

if (!file_exists($file)) {
    $data = ["pixels"=>array_fill(0,40000,"#ffffff")];
} else {
    $data = json_decode(file_get_contents($file),true);

    if (!is_array($data) || !isset($data["pixels"]) || count($data["pixels"]) !== 40000) {
        $data = ["pixels"=>array_fill(0,40000,"#ffffff")];
    }
}


// ----------------------
// PIXEL SETZEN
// ----------------------

$index = $y * 200 + $x;
$data["pixels"][$index] = $color;


// Update loggen
$updatesFile = __DIR__."/updates.json";

$updates = [];
if(file_exists($updatesFile)){
    $updates = json_decode(file_get_contents($updatesFile),true);
}

$updates[] = [
    "x"=>$x,
    "y"=>$y,
    "color"=>$color,
    "t"=>time()
];

// nur letzte 200 Updates behalten
if(count($updates) > 200){
    $updates = array_slice($updates,-200);
}

file_put_contents($updatesFile,json_encode($updates));

// ----------------------
// SPEICHERN
// ----------------------

$result = file_put_contents(
    $file,
    json_encode($data, JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if($result === false){
    echo json_encode(["error"=>"write_failed","file"=>$file]);
    exit;
}


// ----------------------
// USER STAT UPDATE
// ----------------------

$stmt = $conn->prepare("UPDATE users SET pixels_set = pixels_set + 1 WHERE id=?");
$stmt->bind_param("i",$user_id);
$stmt->execute();
$stmt->close();


// cooldown setzen
$_SESSION["last_pixel"] = time();

echo json_encode(["success"=>true]);