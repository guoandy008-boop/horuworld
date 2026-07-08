<?php
/* 每日备份：把 users.json 和所有 userdata 复制到 backups/日期/，保留最近 7 天 */
require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['token'] ?? '') !== CRON_TOKEN) { http_response_code(403); echo 'bad token'; exit; }

$today = date('Y-m-d');
$dir = BACKUP_DIR . '/' . $today;
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$n = 0;
if (is_file(USERS_FILE)) { @copy(USERS_FILE, $dir.'/users.json'); $n++; }
if (is_dir(DATA_DIR)) {
    foreach (glob(DATA_DIR.'/data_*.json') as $f) { @copy($f, $dir.'/'.basename($f)); $n++; }
}

// 清理 7 天前的备份
foreach (glob(BACKUP_DIR.'/*', GLOB_ONLYDIR) as $d) {
    $name = basename($d);
    $ts = strtotime($name);
    if ($ts && $ts < strtotime('-7 days')) {
        foreach (glob($d.'/*') as $ff) @unlink($ff);
        @rmdir($d);
    }
}
echo "backup done: {$n} files -> {$dir}";
