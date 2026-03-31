<?php
session_start();
$data = json_decode(file_get_contents("php://input"), true);

$conn = new mysqli("localhost","root","WBNhN16u","chat");

$stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
$stmt->bind_param("s", $data['endpoint']);
$stmt->execute();
