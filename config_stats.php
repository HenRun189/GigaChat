<?php
// config_stats.php

// Salt für Hashes (bitte zufällig setzen)
define('STATS_SALT', 'BITTE_HIER_EIN_SEHR_LANGES_ZUFAELLIGES_SALT_EINTRAGEN_123');

// Token für öffentliches Widget (einfach eigenes langes Geheimnis)
define('STATS_WIDGET_TOKEN', 'MEIN_SUPER_LANGES_GEHEIMES_WIDGET_TOKEN_ABC_123');

// Owner-User-ID (oder Username) – damit nur du Statistik sehen kannst
define('OWNER_USER_ID', 1);

// "online", wenn last_seen in den letzten X Sekunden war:
define('ONLINE_WINDOW_SECONDS', 90);

define('STATS_UNLOCK_HASH', '$2y$10$$2y$10$9xfnmDm3v.A8hez2ovWWRuOJRK5yBB9gIx0xMQJbzvZNwfHv0uD2m'); 
