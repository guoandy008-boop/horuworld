<?php
/* ============================================================
   蜡笔小账本 · 飞书消息记账（多用户 · 单聊）
   - 用户单聊机器人发「绑定 123456」完成账号绑定
   - 绑定后，单聊发「午餐35」记到他自己账本
   - 未绑定者：退回记到“主人”账本（FEISHU_OWNER_EMAIL），保持你原有用法不变
   ============================================================ */
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) { echo 'ok'; exit; }

/* 1) 地址验证握手 */
if (isset($in['type']) && $in['type'] === 'url_verification') {
    if (FEISHU_VERIFY_TOKEN !== '' && ($in['token'] ?? '') !== FEISHU_VERIFY_TOKEN) { http_response_code(403); echo 'bad token'; exit; }
    echo json_encode(['challenge' => $in['challenge'] ?? '']); exit;
}

/* 2) 校验 token（如填了） */
$evtToken = $in['header']['token'] ?? ($in['token'] ?? '');
if (FEISHU_VERIFY_TOKEN !== '' && $evtToken !== FEISHU_VERIFY_TOKEN) { http_response_code(403); echo 'bad token'; exit; }

/* 3) 只处理收到消息事件 */
if (($in['header']['event_type'] ?? '') !== 'im.message.receive_v1') { echo 'ok'; exit; }

$event   = $in['event'] ?? [];
$message = $event['message'] ?? [];
$chatId  = $message['chat_id'] ?? '';
$msgId   = $message['message_id'] ?? '';
$openId  = $event['sender']['sender_id']['open_id'] ?? '';

/* 4) 去重 */
$seenFile = __DIR__ . '/feishu_seen.json';
$seen = is_file($seenFile) ? json_decode(file_get_contents($seenFile), true) : [];
if (!is_array($seen)) $seen = [];
if ($msgId && in_array($msgId, $seen)) { echo 'ok'; exit; }
if ($msgId) { $seen[] = $msgId; $seen = array_slice($seen, -300); file_put_contents($seenFile, json_encode($seen), LOCK_EX); }

/* 5) 取文本 */
$text = '';
if (($message['message_type'] ?? '') === 'text') {
    $c = json_decode($message['content'] ?? '{}', true);
    $text = trim($c['text'] ?? '');
}
$text = trim(preg_replace('/@_user_\d+/', '', $text));
if ($text === '') { echo 'ok'; exit; }

/* 6) 绑定指令：支持「绑定 123456」，也支持直接发 6 位数字（只要匹配到有效绑定码） */
$bindCode = '';
if (preg_match('/绑定\s*([0-9]{6})/u', $text, $bm)) {
    $bindCode = $bm[1];
} else if (preg_match('/^\s*([0-9]{6})\s*$/u', $text, $bm)) {
    // 用户只发了 6 位数字：若正好是有效绑定码，就当绑定处理
    $codesPeek = bindcodes_load();
    if (isset($codesPeek[$bm[1]]) && ($codesPeek[$bm[1]]['exp'] ?? 0) >= time()) {
        $bindCode = $bm[1];
    }
}
if ($bindCode !== '') {
    $code = $bindCode;
    $codes = bindcodes_load();
    $now = time();
    if (!isset($codes[$code]) || ($codes[$code]['exp'] ?? 0) < $now) {
        feishu_reply($chatId, "绑定码无效或已过期，请回 app 重新获取。");
        echo 'ok'; exit;
    }
    $uid = $codes[$code]['uid'];
    $binds = binds_load();
    $binds[$openId] = $uid;
    binds_save($binds);
    unset($codes[$code]); bindcodes_save($codes);
    $df = user_data_file($uid);
    $d = is_file($df) ? json_decode(file_get_contents($df), true) : [];
    $name = $d['profile']['name'] ?? '';
    feishu_reply($chatId, "绑定成功 ✅ " . ($name ? "你好，{$name}！" : '') . "\n以后直接发「午餐35」这样就能记到你的账本啦～");
    echo 'ok'; exit;
}

/* 7) 帮助 */
if (preg_match('/帮助|怎么用|help|说明/i', $text)) {
    feishu_reply($chatId,
        "🐷 蜡笔小账本\n第一次用：先在 app 里【我的→数据→绑定飞书】拿到绑定码，发：绑定 123456\n之后直接发，例如：\n・午餐35\n・打车23\n・工资到账8000\n・存5000 工资\n・取2000");
    echo 'ok'; exit;
}

/* 8) 找这条消息该记到谁的账本：必须已绑定，否则提示绑定（不再退回主人，杜绝串账） */
$uid = $openId ? uid_by_openid($openId) : '';
if ($uid === '') {
    feishu_reply($chatId, "你还没绑定账号哦～\n请打开「蜡笔小账本」app →【我的→数据→飞书记账】获取绑定码，然后发给我：绑定 6位数字");
    echo 'ok'; exit;
}
$DF = user_data_file($uid);

/* 9) 智能解析金额 + 动作 + 分类 */
if (!preg_match('/(\d+(?:\.\d+)?)/u', $text, $m)) {
    feishu_reply($chatId, "没看到金额哦～试试「午餐35」，或回「帮助」看用法。");
    echo 'ok'; exit;
}
$amount = (float)$m[1];
if ($amount <= 0) { echo 'ok'; exit; }
$rest = trim(preg_replace('/\d+(?:\.\d+)?元?块?钱?/u', '', $text));
$kindHas = function($kw) use ($text){ return preg_match('/'.$kw.'/u', $text); };

$now = round(microtime(true) * 1000);
$id  = $now . '_' . substr(md5(mt_rand()), 0, 4);
$data = is_file($DF) ? json_decode(file_get_contents($DF), true) : null;
if (!is_array($data)) $data = ['savings'=>0,'savingsLog'=>[],'expenses'=>[],'trash'=>[],'budget'=>0];
foreach (['savingsLog','expenses','trash'] as $k) if (!isset($data[$k]) || !is_array($data[$k])) $data[$k] = [];

$reply = '';
if ($kindHas('取|取出|拿出|提现|支取')) {
    array_unshift($data['savingsLog'], ['id'=>$id,'type'=>'out','amount'=>$amount,'note'=>$rest,'date'=>$now]);
    $reply = "🐷 取出 ¥" . number_format($amount,2);
} else if ($kindHas('存|工资|薪|到账|进账|收入|奖金|年终|红包|理财|基金|股票|利息|收益|存款')) {
    $src='other';
    if ($kindHas('工资|薪|月薪|发工资')) $src='salary';
    else if ($kindHas('奖金|年终')) $src='bonus';
    else if ($kindHas('红包|礼金')) $src='gift';
    else if ($kindHas('理财|基金|股票|利息|收益')) $src='invest';
    array_unshift($data['savingsLog'], ['id'=>$id,'type'=>'in','amount'=>$amount,'src'=>$src,'note'=>$rest,'date'=>$now]);
    $reply = "🐷 存入 " . ($INCOME[$src] ?? '其他收入') . " ¥" . number_format($amount,2);
} else {
    $cat='other';
    if ($kindHas('吃|饭|餐|外卖|奶茶|咖啡|早餐|午餐|晚餐|夜宵|零食|水果|菜|食堂|喝|火锅|烧烤')) $cat='food';
    else if ($kindHas('车|打车|地铁|公交|高铁|火车|飞机|机票|油|加油|停车|滴滴|出行|过路|船')) $cat='trans';
    else if ($kindHas('买|衣|鞋|裤|淘宝|京东|拼多多|购物|化妆|护肤|包包|数码|手机|电器|快递')) $cat='shop';
    else if ($kindHas('电影|游戏|会员|娱乐|唱歌|ktv|旅游|玩|门票|景点|演唱会|健身')) $cat='fun';
    else if ($kindHas('房租|水电|物业|家|日用|家具|电费|水费|燃气|宽带|话费|清洁')) $cat='home';
    array_unshift($data['expenses'], ['id'=>$id,'amount'=>$amount,'cat'=>$cat,'note'=>$rest,'date'=>$now]);
    $mStart = strtotime(date('Y-m-01 00:00:00')) * 1000;
    $mSpend = 0; foreach ($data['expenses'] as $e) if (($e['date']??0) >= $mStart) $mSpend += $e['amount'];
    $noteStr = $rest !== '' ? "（{$rest}）" : '';
    $reply = "🧾 已记支出 " . ($CATS[$cat] ?? '其他') . "{$noteStr} ¥" . number_format($amount,2)
           . "\n本月累计已花 ¥" . number_format($mSpend,2);
}
file_put_contents($DF, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
feishu_reply($chatId, $reply . "  ✅");
echo 'ok';
