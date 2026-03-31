<?php
$conn = new mysqli('localhost','webapp_user','g679*.<cS5LK','chat');

$result = $conn->query("
SELECT username,pixels_set 
FROM users
ORDER BY pixels_set DESC
LIMIT 10
");

$data=[];

while($row=$result->fetch_assoc()){
$data[]=$row;
}

echo json_encode($data);