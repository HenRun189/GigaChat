<?php
$exts = ['php','js','css','html'];

function formatBytes($bytes) {
    $units = ['B','KB','MB','GB','TB'];
    for ($i = 0; $bytes >= 1024 && $i < count($units)-1; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function emptyStats() {
    return [
        'files' => 0,
        'lines' => 0,
        'words' => 0,
        'chars' => 0,
        'chars_no_spaces' => 0,
        'size'  => 0,
    ];
}

/* =========================
   🌍 GANZER SERVER / PROJEKT
   ========================= */
$server = emptyStats();
$dirServer = __DIR__;

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dirServer, FilesystemIterator::SKIP_DOTS)
);

foreach ($rii as $file) {
    if ($file->isDir()) continue;

    $server['size'] += $file->getSize();

    $ext = strtolower($file->getExtension());
    if (!in_array($ext, $exts)) continue;

    $server['files']++;
    $content = file_get_contents($file->getPathname());

    $server['lines'] += substr_count($content, "\n") + 1;
    $server['words'] += str_word_count(strip_tags($content));
    $server['chars'] += strlen($content);
    $server['chars_no_spaces'] += strlen(str_replace(' ', '', $content));
}

/* =========================
   🚀 GIGACHAT ROOT ONLY
   ========================= */
$gigachat = emptyStats();
$dirGigachat = '/var/www/html';

$files = scandir($dirGigachat);
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;

    $path = $dirGigachat . '/' . $file;
    if (!is_file($path)) continue; // ❌ keine Unterordner

    $gigachat['size'] += filesize($path);

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, $exts)) continue;

    $gigachat['files']++;
    $content = file_get_contents($path);

    $gigachat['lines'] += substr_count($content, "\n") + 1;
    $gigachat['words'] += str_word_count(strip_tags($content));
    $gigachat['chars'] += strlen($content);
    $gigachat['chars_no_spaces'] += strlen(str_replace(' ', '', $content));
}

/* =========================
   🧠 SERVER STATS
   ========================= */
$cpu = sys_getloadavg();

$diskTotal = disk_total_space('/');
$diskFree  = disk_free_space('/');

$memTotal = $memFree = null;
if (file_exists('/proc/meminfo')) {
    foreach (file('/proc/meminfo') as $line) {
        if (str_starts_with($line, 'MemTotal')) {
            $memTotal = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT) * 1024;
        }
        if (str_starts_with($line, 'MemAvailable')) {
            $memFree = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT) * 1024;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Gigachat – Full Stats</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
:root {
    --bg:#020617;
    --card:#0f172a;
    --text:#e5e7eb;
    --muted:#94a3b8;
    --accent:#6366f1;
}
* { box-sizing:border-box; font-family:system-ui,sans-serif; }
body {
    margin:0;
    background:radial-gradient(circle at top,#0f172a,#020617);
    color:var(--text);
    min-height:100vh;
    display:flex;
    justify-content:center;
    align-items:flex-start;
}
.wrap {
    width:100%;
    max-width:1100px;
    padding:20px;
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(320px,1fr));
    gap:18px;
}
.card {
    background:rgba(15,23,42,.9);
    border-radius:18px;
    padding:22px;
    box-shadow:0 20px 60px rgba(0,0,0,.6);
}
h2 { margin:0 0 14px; font-size:18px; }
.stat {
    display:flex;
    justify-content:space-between;
    padding:7px 0;
    border-bottom:1px solid rgba(255,255,255,.06);
}
.stat:last-child { border-bottom:none; }
.label { color:var(--muted); }
.value { font-weight:600; }
.badge {
    margin-top:14px;
    background:linear-gradient(135deg,var(--accent),#818cf8);
    padding:8px;
    border-radius:10px;
    text-align:center;
    font-size:13px;
    font-weight:600;
}
</style>
</head>
<body>

<div class="wrap">

<div class="card">
<h2>🌍 Ganzer Server / Projekt</h2>
<div class="stat"><span class="label">Dateien</span><span class="value"><?= $server['files'] ?></span></div>
<div class="stat"><span class="label">Zeilen</span><span class="value"><?= $server['lines'] ?></span></div>
<div class="stat"><span class="label">Wörter</span><span class="value"><?= $server['words'] ?></span></div>
<div class="stat"><span class="label">Zeichen</span><span class="value"><?= $server['chars'] ?></span></div>
<div class="stat"><span class="label">Zeichen (ohne Space)</span><span class="value"><?= $server['chars_no_spaces'] ?></span></div>
<div class="stat"><span class="label">Größe</span><span class="value"><?= formatBytes($server['size']) ?></span></div>
<div class="badge">rekursiv • alles drin</div>
</div>

<div class="card">
<h2>🚀 Gigachat (Root)</h2>
<div class="stat"><span class="label">Dateien</span><span class="value"><?= $gigachat['files'] ?></span></div>
<div class="stat"><span class="label">Zeilen</span><span class="value"><?= $gigachat['lines'] ?></span></div>
<div class="stat"><span class="label">Wörter</span><span class="value"><?= $gigachat['words'] ?></span></div>
<div class="stat"><span class="label">Zeichen</span><span class="value"><?= $gigachat['chars'] ?></span></div>
<div class="stat"><span class="label">Zeichen (ohne Space)</span><span class="value"><?= $gigachat['chars_no_spaces'] ?></span></div>
<div class="stat"><span class="label">Größe</span><span class="value"><?= formatBytes($gigachat['size']) ?></span></div>
<div class="badge">nur /var/www/html</div>
</div>

<div class="card">
<h2>🧠 Server Status</h2>
<div class="stat"><span class="label">CPU Load</span><span class="value"><?= implode(' | ', $cpu) ?></span></div>
<?php if ($memTotal): ?>
<div class="stat"><span class="label">RAM frei</span><span class="value"><?= formatBytes($memFree) ?></span></div>
<div class="stat"><span class="label">RAM gesamt</span><span class="value"><?= formatBytes($memTotal) ?></span></div>
<?php endif; ?>
<div class="stat"><span class="label">Disk frei</span><span class="value"><?= formatBytes($diskFree) ?></span></div>
<div class="stat"><span class="label">Disk gesamt</span><span class="value"><?= formatBytes($diskTotal) ?></span></div>
<div class="stat"><span class="label">PHP</span><span class="value"><?= PHP_VERSION ?></span></div>
<div class="stat"><span class="label">OS</span><span class="value"><?= php_uname('s') ?></span></div>
<div class="badge">full server stats 🔥</div>
</div>

</div>
</body>
</html>
