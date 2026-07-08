<?php
/* ============================================================
   蜡笔小账本 · 登录 / 注册 / 改密 / 退出
   用邮箱 + 密码；密码以加盐哈希存储，服务器上看不到明文。
   登录状态用 session（浏览器 cookie），api.php 据此认人。
   ============================================================ */
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$__body = json_decode(file_get_contents('php://input'), true);
if (!is_array($__body)) $__body = [];

// 登录/注册：按「记住我」决定会话时长（勾选=30天免登录，不勾=关浏览器即退出）
if ($action === 'login' || $action === 'register') {
    $remember = !array_key_exists('remember', $__body) || !empty($__body['remember']);
    $life = $remember ? 60*60*24*30 : 0;   // 0 = 浏览器关闭即失效
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if ($life > 0) ini_set('session.gc_maxlifetime', $life);
        session_set_cookie_params(['lifetime'=>$life, 'path'=>'/', 'httponly'=>true, 'samesite'=>'Lax']);
        session_start();
    }
} else {
    start_session();
}
header('Content-Type: application/json; charset=utf-8');

function body(){ global $__body; return $__body; }
function out($x){ echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }

// 当前登录状态
if ($action === 'me') {
    if (!empty($_SESSION['uid'])) out(['ok'=>true, 'user'=>['email'=>$_SESSION['email']??'', 'created'=>$_SESSION['created']??0]]);
    out(['ok'=>false]);
}

// 注册
if ($action === 'register') {
    $b = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $pass  = (string)($b['pass'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(['ok'=>false,'msg'=>'邮箱格式不正确']);
    if (strlen($pass) < 6) out(['ok'=>false,'msg'=>'密码至少 6 位']);
    $users = users_load();
    if (isset($users[$email])) out(['ok'=>false,'msg'=>'该邮箱已注册，请直接登录']);
    $id = new_uid(); $created = time();
    $users[$email] = ['id'=>$id, 'pass'=>password_hash($pass, PASSWORD_DEFAULT), 'created'=>$created];
    users_save($users);
    file_put_contents(user_data_file($id),
        json_encode(['savings'=>0,'savingsLog'=>[],'expenses'=>[],'trash'=>[],'budget'=>0], JSON_UNESCAPED_UNICODE), LOCK_EX);
    $_SESSION['uid']=$id; $_SESSION['email']=$email; $_SESSION['created']=$created;
    out(['ok'=>true, 'user'=>['email'=>$email,'created'=>$created]]);
}

// 登录
if ($action === 'login') {
    $b = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $pass  = (string)($b['pass'] ?? '');
    $users = users_load();
    if (!isset($users[$email]) || !password_verify($pass, $users[$email]['pass']))
        out(['ok'=>false,'msg'=>'邮箱或密码不正确']);
    $_SESSION['uid']=$users[$email]['id']; $_SESSION['email']=$email; $_SESSION['created']=$users[$email]['created']??0;
    out(['ok'=>true, 'user'=>['email'=>$email,'created'=>$_SESSION['created']]]);
}

// 退出
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    out(['ok'=>true]);
}

// 改密
if ($action === 'changepw') {
    if (empty($_SESSION['uid'])) out(['ok'=>false,'msg'=>'未登录']);
    $b = body();
    $old = (string)($b['old'] ?? ''); $new = (string)($b['new'] ?? '');
    if (strlen($new) < 6) out(['ok'=>false,'msg'=>'新密码至少 6 位']);
    $email = $_SESSION['email']; $users = users_load();
    if (!isset($users[$email]) || !password_verify($old, $users[$email]['pass'])) out(['ok'=>false,'msg'=>'原密码不正确']);
    $users[$email]['pass'] = password_hash($new, PASSWORD_DEFAULT);
    users_save($users);
    out(['ok'=>true]);
}

out(['ok'=>false,'msg'=>'unknown action']);
