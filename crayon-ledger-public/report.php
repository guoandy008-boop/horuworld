<?php
/* 每天早上 8 点：给“主人”发群小报（照旧），并给每个已绑定用户私聊各自的小报 */
require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['token'] ?? '') !== CRON_TOKEN) { http_response_code(403); echo 'bad token'; exit; }

// 根据某人的账本数据，生成“昨日小报”文本
function build_daily($data, $CATS){
    $expenses   = $data['expenses']   ?? [];
    $savingsLog = $data['savingsLog'] ?? [];
    $budget     = (float)($data['budget'] ?? 0);
    $y0 = strtotime('yesterday 00:00:00') * 1000;
    $y1 = strtotime('today 00:00:00') * 1000;
    $m0 = strtotime(date('Y-m-01 00:00:00')) * 1000;
    $spend=0; $byCat=[];
    foreach ($expenses as $e){ $t=$e['date']??0; if($t>=$y0&&$t<$y1){ $spend+=$e['amount']; $c=$e['cat']??'other'; $byCat[$c]=($byCat[$c]??0)+$e['amount']; } }
    $inY=0;$outY=0;
    foreach ($savingsLog as $l){ $t=$l['date']??0; if($t>=$y0&&$t<$y1){ if(($l['type']??'')==='in')$inY+=$l['amount']; else $outY+=$l['amount']; } }
    $sTotal=0; foreach($savingsLog as $l){ $sTotal += (($l['type']??'')==='in'?$l['amount']:-$l['amount']); }
    $mSpend=0; foreach($expenses as $e){ if(($e['date']??0)>=$m0)$mSpend+=$e['amount']; }
    $yd=date('n月j日', strtotime('yesterday'));
    $L=[]; $L[]="🐷 蜡笔小账本 · 昨日小报"; $L[]="{$yd}，你花了 ¥".number_format($spend,2);
    if($byCat){ arsort($byCat); $p=[]; foreach($byCat as $c=>$v){ $p[]=($CATS[$c]??'其他')." ¥".number_format($v,2);} $L[]="・".implode("，",$p); }
    if($inY>0||$outY>0){ $s="昨日储蓄："; if($inY>0)$s.="存入 ¥".number_format($inY,2)." "; if($outY>0)$s.="取出 ¥".number_format($outY,2); $L[]=$s; }
    $L[]="——————"; $L[]="目前总储蓄 ¥".number_format($sTotal,2); $L[]="本月累计已花 ¥".number_format($mSpend,2);
    if($budget>0){ $left=$budget-$mSpend; $L[]= $left>=0 ? "本月预算 ¥".number_format($budget,2)."，还剩 ¥".number_format($left,2)." 💪" : "本月预算 ¥".number_format($budget,2)."，已超支 ¥".number_format(-$left,2)." ⚠️"; }
    return implode("\n",$L);
}

$sent = 0;

// ① 主人：维持原来的群 webhook 小报，不变
$ownerId = FEISHU_OWNER_EMAIL !== '' ? uid_by_email(FEISHU_OWNER_EMAIL) : '';
$ownerFile = $ownerId ? user_data_file($ownerId) : DATA_FILE;
$webhookResp = '(未发送)';
if (is_file($ownerFile)) {
    $d = json_decode(file_get_contents($ownerFile), true);
    if (is_array($d)) { $webhookResp = feishu_send(build_daily($d, $CATS)); $sent++; }
}

// ② 其他已绑定用户：各自私聊小报（跳过主人，避免重复）
foreach (binds_load() as $oid => $uid) {
    if ($ownerId && $uid === $ownerId) continue;
    $f = user_data_file($uid);
    if (!is_file($f)) continue;
    $d = json_decode(file_get_contents($f), true);
    if (!is_array($d)) continue;
    feishu_send_to_user($oid, build_daily($d, $CATS));
    $sent++;
}

// 执行留痕：每次运行都记一行，方便排查自动执行到底发了没
@file_put_contents(
    __DIR__ . '/report_run.log',
    date('Y-m-d H:i:s') . " | sent={$sent} | webhook=" . substr((string)$webhookResp, 0, 200) . "\n",
    FILE_APPEND | LOCK_EX
);

echo "sent {$sent} reports\n\n--- 群 webhook 飞书返回 ---\n";
echo $webhookResp;
