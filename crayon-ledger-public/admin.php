<?php
/* ============================================================
   蜡笔小账本 · 账户管理后台
   功能：查看所有注册账户、重置某人密码、删除账号
   ============================================================ */
require __DIR__ . '/config.php';
session_start();

$ADMIN_PASS = defined('ADMIN_PASS') ? ADMIN_PASS : 'change-me';

// 退出
if (isset($_GET['logout'])) { unset($_SESSION['admin_ok']); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }
// 登录
if (isset($_POST['admin_login'])) {
    if (($_POST['pass'] ?? '') === $ADMIN_PASS) $_SESSION['admin_ok'] = true;
    else $err = '管理密码不正确';
}
$authed = !empty($_SESSION['admin_ok']);

// 导出某用户账单为 CSV（需已登录）
if ($authed && isset($_GET['export'])) {
    $email = strtolower(trim($_GET['export']));
    $uu = users_load();
    if (!isset($uu[$email])) { http_response_code(404); echo '账号不存在'; exit; }
    $id = $uu[$email]['id'];
    $f = user_data_file($id);
    $data = is_file($f) ? json_decode(file_get_contents($f), true) : [];
    $expenses = $data['expenses'] ?? [];
    $savings  = $data['savingsLog'] ?? [];
    usort($expenses, function($a,$b){ return ($b['date']??0) <=> ($a['date']??0); });
    usort($savings,  function($a,$b){ return ($b['date']??0) <=> ($a['date']??0); });
    $fname = '账单_' . preg_replace('/[^a-zA-Z0-9_.@-]/','_',$email) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    echo "\xEF\xBB\xBF"; // Excel 中文不乱码
    $out = fopen('php://output','w');
    fputcsv($out, ['日期','类型','分类/来源','金额','备注']);
    foreach ($expenses as $e) {
        fputcsv($out, [ date('Y-m-d', intval(($e['date']??0)/1000)), '支出',
            ($CATS[$e['cat']??'other'] ?? '其他'), $e['amount']??0, $e['note']??'' ]);
    }
    foreach ($savings as $s) {
        $isIn = (($s['type']??'')==='in');
        fputcsv($out, [ date('Y-m-d', intval(($s['date']??0)/1000)), $isIn?'存入':'取出',
            $isIn ? ($INCOME[$s['src']??'other'] ?? '其他收入') : '', $s['amount']??0, $s['note']??'' ]);
    }
    fclose($out);
    exit;
}

// 操作（登录后才执行）
$msg = ''; $msgType = 'ok';
if ($authed && isset($_POST['do'])) {
    $users = users_load();
    $email = strtolower(trim($_POST['email'] ?? ''));
    if ($_POST['do'] === 'reset') {
        $np = (string)($_POST['newpass'] ?? '');
        if (!isset($users[$email])) { $msg = '该邮箱不存在'; $msgType='bad'; }
        else if (strlen($np) < 6) { $msg = '新密码至少 6 位'; $msgType='bad'; }
        else {
            $users[$email]['pass'] = password_hash($np, PASSWORD_DEFAULT);
            users_save($users);
            $msg = "已把 {$email} 的密码重置为：{$np}（请转告对方，并建议其登录后再自行修改）";
        }
    } else if ($_POST['do'] === 'del') {
        if (isset($users[$email])) {
            $id = $users[$email]['id'];
            unset($users[$email]); users_save($users);
            @unlink(user_data_file($id));
            $msg = "已删除账号 {$email} 及其数据";
        } else { $msg = '该邮箱不存在'; $msgType='bad'; }
    }
}
$users = $authed ? users_load() : [];
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function nick_of($id){
    if (!$id) return '';
    $f = user_data_file($id);
    if (!is_file($f)) return '';
    $d = json_decode(file_get_contents($f), true);
    return $d['profile']['name'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>蜡笔小账本 · 账户管理</title>
<style>
  body{font-family:"PingFang SC","Microsoft YaHei",system-ui,sans-serif;background:#FDF6E9;color:#5A4636;margin:0;padding:24px;}
  .wrap{max-width:760px;margin:0 auto;}
  h1{font-size:22px;margin:0 0 4px;} .sub{color:#8A7A66;font-size:13px;margin-bottom:20px;}
  .card{background:#fff;border:2px solid #5A4636;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:3px 4px 0 rgba(90,70,54,.1);}
  .card h2{font-size:16px;margin:0 0 12px;}
  label{font-size:13px;color:#8A7A66;display:block;margin:8px 0 4px;}
  input,select{font-family:inherit;font-size:15px;padding:9px 12px;border:2px solid #5A4636;border-radius:10px;outline:none;width:100%;box-sizing:border-box;}
  button{font-family:inherit;font-size:15px;font-weight:700;padding:10px 18px;border:2px solid #5A4636;border-radius:10px;cursor:pointer;background:#A9DDC4;color:#5A4636;box-shadow:2px 3px 0 rgba(90,70,54,.15);}
  button.danger{background:#F7B9C4;}
  button:active{transform:translate(2px,3px);box-shadow:none;}
  table{width:100%;border-collapse:collapse;font-size:14px;}
  th,td{text-align:left;padding:10px 8px;border-bottom:1px dashed #E0D2BB;}
  th{color:#8A7A66;font-weight:600;}
  .msg{padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;}
  .msg.ok{background:#E3F3EA;border:1.5px solid #6FC79E;} .msg.bad{background:#FBE6EA;border:1.5px solid #E98FA0;}
  .warn{background:#FFF6DD;border:1.5px solid #EBBE57;padding:11px 14px;border-radius:10px;font-size:13px;margin-bottom:16px;}
  .row2{display:flex;gap:12px;flex-wrap:wrap;} .row2>div{flex:1;min-width:220px;}
  .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
  a.logout{font-size:13px;color:#E98FA0;}
  code{background:#FBEFD9;padding:1px 5px;border-radius:4px;}
</style>
</head>
<body>
<div class="wrap">
<?php if (!$authed): ?>
  <h1>蜡笔小账本 · 账户管理</h1>
  <div class="sub">请输入管理密码进入</div>
  <div class="card" style="max-width:360px;">
    <?php if (!empty($err)): ?><div class="msg bad"><?=h($err)?></div><?php endif; ?>
    <form method="post">
      <label>管理密码</label>
      <input type="password" name="pass" autofocus>
      <div style="margin-top:14px;"><button type="submit" name="admin_login" value="1">进入</button></div>
    </form>
  </div>
<?php else: ?>
  <div class="topbar">
    <div><h1 style="margin:0;">蜡笔小账本 · 账户管理</h1><div class="sub" style="margin:2px 0 0;">共 <?=count($users)?> 个账户</div></div>
    <a class="logout" href="?logout=1">退出</a>
  </div>

  <?php if ($ADMIN_PASS === 'change-me' || $ADMIN_PASS === 'change-this-admin-password'): ?>
    <div class="warn">⚠️ 你还没改管理密码！请在 <code>config.php</code> 里设置 <code>ADMIN_PASS</code>，否则别人也能进来。</div>
  <?php endif; ?>

  <?php if ($msg): ?><div class="msg <?=$msgType?>"><?=h($msg)?></div><?php endif; ?>

  <div class="card">
    <h2>所有账户</h2>
    <?php if (!$users): ?>
      <div class="sub">还没有人注册。</div>
    <?php else: ?>
      <table>
        <tr><th>邮箱（账号）</th><th>昵称</th><th>注册时间</th><th>账户 ID</th><th>操作</th></tr>
        <?php foreach ($users as $em => $u): ?>
          <tr>
            <td><?=h($em)?></td>
            <td><?=h(nick_of($u['id'] ?? '')) ?: '<span style="color:#B9A88E">—</span>'?></td>
            <td><?=h(date('Y-m-d H:i', $u['created'] ?? 0))?></td>
            <td><code><?=h($u['id'] ?? '')?></code></td>
            <td><a href="?export=<?=urlencode($em)?>">导出账单</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="row2">
    <div class="card">
      <h2>重置密码</h2>
      <form method="post" onsubmit="return confirm('确定重置该账户密码？');">
        <input type="hidden" name="do" value="reset">
        <label>选择账户</label>
        <select name="email" required>
          <option value="">— 请选择 —</option>
          <?php foreach ($users as $em => $u): ?><option value="<?=h($em)?>"><?=h($em)?></option><?php endforeach; ?>
        </select>
        <label>新密码（至少 6 位）</label>
        <input type="text" name="newpass" placeholder="新密码" required>
        <div style="margin-top:14px;"><button type="submit">重置密码</button></div>
      </form>
    </div>

    <div class="card">
      <h2>删除账号</h2>
      <form method="post" onsubmit="return confirm('删除后该账户的数据也会一并删除，且不可恢复，确定？');">
        <input type="hidden" name="do" value="del">
        <label>选择账户</label>
        <select name="email" required>
          <option value="">— 请选择 —</option>
          <?php foreach ($users as $em => $u): ?><option value="<?=h($em)?>"><?=h($em)?></option><?php endforeach; ?>
        </select>
        <div style="margin-top:14px;"><button type="submit" class="danger">删除账号</button></div>
      </form>
    </div>
  </div>

  <div class="sub">提示：密码以加盐哈希存储，这里看不到用户的原密码；只能给对方重置一个新的。</div>
<?php endif; ?>
</div>
</body>
</html>
