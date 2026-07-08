<?php
/* ============================================================
   蜡笔小账本 · 接口（登录后才能用，各人只读写自己的账本）
   - GET  ?action=load      读取“我”的账本
   - POST ?action=save      保存“我”的账本
   - POST ?action=announce  推一条新记录到飞书（仅“账本主人”生效）
   身份用 session 认（登录后浏览器自带 cookie），无需再传口令
   ============================================================ */
require __DIR__ . '/config.php';
start_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'未登录']); exit; }
$uid   = $_SESSION['uid'];
$email = strtolower($_SESSION['email'] ?? '');
$file  = user_data_file($uid);
$action = $_GET['action'] ?? '';

if ($action === 'load') {
    $data = is_file($file) ? json_decode(file_get_contents($file), true) : null;
    if (!is_array($data)) $data = null;
    echo json_encode(['ok'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'bad json']); exit; }
    $ok = file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    echo json_encode(['ok'=>$ok!==false]);
    exit;
}

if ($action === 'announce') {
    // 只有“飞书账本主人”记账才推飞书，避免别的用户打扰你的飞书
    if (FEISHU_OWNER_EMAIL !== '' && $email === strtolower(FEISHU_OWNER_EMAIL)) {
        $a = json_decode(file_get_contents('php://input'), true);
        if (is_array($a)) {
            $amt  = number_format((float)($a['amount'] ?? 0), 2);
            $note = !empty($a['note']) ? ('（' . $a['note'] . '）') : '';
            $msg  = '';
            if (($a['kind'] ?? '') === 'expense') {
                $cat = $CATS[$a['cat'] ?? 'other'] ?? '其他';
                $msg = "🧾 记一笔支出：{$cat}{$note}  -¥{$amt}";
            } else if (($a['kind'] ?? '') === 'saving') {
                if (($a['type'] ?? '') === 'in') {
                    $src = $INCOME[$a['src'] ?? 'other'] ?? '其他收入';
                    $msg = "🐷 存入：{$src}{$note}  +¥{$amt}";
                } else {
                    $msg = "🐷 取出{$note}  -¥{$amt}";
                }
            }
            if ($msg !== '') feishu_send($msg);
        }
    }
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'bindcode') {
    $codes = bindcodes_load();
    $now = time();
    foreach ($codes as $k=>$v) { if (($v['exp'] ?? 0) < $now) unset($codes[$k]); } // 清过期
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codes[$code] = ['uid'=>$uid, 'exp'=>$now+600];
    bindcodes_save($codes);
    echo json_encode(['ok'=>true, 'code'=>$code, 'expire'=>600]);
    exit;
}

if ($action === 'bindstatus') {
    echo json_encode(['ok'=>true, 'bound'=>(openid_by_uid($uid) !== '')]);
    exit;
}

if ($action === 'unbind') {
    $b = binds_load(); $changed = false;
    foreach ($b as $oid=>$u) { if ($u === $uid) { unset($b[$oid]); $changed = true; } }
    if ($changed) binds_save($b);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'msg'=>'unknown action']);
