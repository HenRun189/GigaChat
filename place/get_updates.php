<?php
header("Content-Type: application/json");

$file = __DIR__."/updates.json";

if(!file_exists($file)){
    file_put_contents($file,json_encode([]));
}

echo file_get_contents($file);