<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>VAPID Keys</title>
</head>
<body>
<h1>VAPID Keys</h1>
<pre>
<?php
require __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "PUBLIC:  " . $keys['publicKey'] . "\n";
echo "PRIVATE: " . $keys['privateKey'] . "\n";
?>

<h1>phpinfo</h1>
<?php phpinfo();
?>
</pre>
</body>
</html>
