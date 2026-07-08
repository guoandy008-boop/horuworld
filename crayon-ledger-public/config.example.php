<?php
/* ============================================================
   蜡笔小账本 · 示例配置

   使用方法：
   1. 复制本文件为 config.php
   2. 填入自己的飞书 Webhook、App ID、App Secret、定时任务 token
   3. config.php 不要提交到 GitHub
   ============================================================ */

define('FEISHU_WEBHOOK', '');
define('FEISHU_APP_ID', '');
define('FEISHU_APP_SECRET', '');
define('FEISHU_VERIFY_TOKEN', '');
define('FEISHU_OWNER_EMAIL', '');

define('CRON_TOKEN', 'change-this-cron-token');
define('ADMIN_PASS', 'change-this-admin-password');

define('DATA_FILE', __DIR__ . '/data.json');
define('USERS_FILE', __DIR__ . '/users.json');
define('DATA_DIR', __DIR__ . '/userdata');
define('BACKUP_DIR', __DIR__ . '/backups');
define('BIND_FILE', __DIR__ . '/feishu_bind.json');
define('BINDCODE_FILE', __DIR__ . '/feishu_bindcodes.json');

date_default_timezone_set('Asia/Shanghai');

$CATS = [
    'food' => '吃饭',
    'trans' => '交通',
    'shop' => '购物',
    'fun' => '娱乐',
    'home' => '居家',
    'other' => '其他',
];

$INCOME = [
    'salary' => '工资',
    'bonus' => '奖金',
    'gift' => '红包',
    'invest' => '理财',
    'other' => '其他收入',
];

function http_json($url, $payload = null, $headers = []){
    $ch = curl_init($url);
    $h = array_merge(['Content-Type: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function feishu_send($text){
    if (FEISHU_WEBHOOK === '') return false;
    $payload = json_encode(['msg_type' => 'text', 'content' => ['text' => $text]], JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init(FEISHU_WEBHOOK);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 8,
    ]]);
    return @file_get_contents(FEISHU_WEBHOOK, false, $ctx);
}

function feishu_token(){
    if (FEISHU_APP_ID === '' || FEISHU_APP_SECRET === '') return '';
    $r = http_json('https://open.feishu.cn/open-apis/auth/v3/tenant_access_token/internal', [
        'app_id' => FEISHU_APP_ID,
        'app_secret' => FEISHU_APP_SECRET,
    ]);
    return $r['tenant_access_token'] ?? '';
}

function feishu_reply($chat_id, $text){
    $token = feishu_token();
    if (!$token || !$chat_id) return false;
    return http_json(
        'https://open.feishu.cn/open-apis/im/v1/messages?receive_id_type=chat_id',
        [
            'receive_id' => $chat_id,
            'msg_type' => 'text',
            'content' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        ],
        ['Authorization: Bearer ' . $token]
    );
}

function feishu_send_to_user($open_id, $text){
    $token = feishu_token();
    if (!$token || !$open_id) return false;
    return http_json(
        'https://open.feishu.cn/open-apis/im/v1/messages?receive_id_type=open_id',
        [
            'receive_id' => $open_id,
            'msg_type' => 'text',
            'content' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        ],
        ['Authorization: Bearer ' . $token]
    );
}

function users_load(){
    if (!is_file(USERS_FILE)) return [];
    $d = json_decode(file_get_contents(USERS_FILE), true);
    return is_array($d) ? $d : [];
}

function users_save($u){
    file_put_contents(USERS_FILE, json_encode($u, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function new_uid(){
    return 'u_' . bin2hex(random_bytes(5));
}

function user_data_file($id){
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', $id);
    return DATA_DIR . '/data_' . $id . '.json';
}

function uid_by_email($email){
    $email = strtolower(trim($email));
    $u = users_load();
    return isset($u[$email]) ? $u[$email]['id'] : '';
}

function start_session(){
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $life = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', $life);
    session_set_cookie_params([
        'lifetime' => $life,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function binds_load(){
    if (!is_file(BIND_FILE)) return [];
    $d = json_decode(file_get_contents(BIND_FILE), true);
    return is_array($d) ? $d : [];
}

function binds_save($b){
    file_put_contents(BIND_FILE, json_encode($b, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function uid_by_openid($oid){
    $b = binds_load();
    return $b[$oid] ?? '';
}

function openid_by_uid($uid){
    foreach (binds_load() as $oid => $u) {
        if ($u === $uid) return $oid;
    }
    return '';
}

function bindcodes_load(){
    if (!is_file(BINDCODE_FILE)) return [];
    $d = json_decode(file_get_contents(BINDCODE_FILE), true);
    return is_array($d) ? $d : [];
}

function bindcodes_save($c){
    file_put_contents(BINDCODE_FILE, json_encode($c), LOCK_EX);
}

