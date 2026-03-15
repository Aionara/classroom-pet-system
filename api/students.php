<?php
// ===== 学生数据 API =====
// GET  /api/students.php?action=list|get&id=xxx
// POST /api/students.php  action=update|addPoints|deductPoints|grantPoints|buyItem|useItem|adoptPet|tick|checkPenalty|add|delete|resetPassword

require_once 'config.php';

$input  = getInput();
$action = $input['action'] ?? ($_GET['action'] ?? '');
$pdo    = getDB();

// ===== 获取所有学生 =====
if ($action === 'list') {
    $rows = $pdo->query("SELECT * FROM students")->fetchAll();
    respOk(['students' => array_map('decodeStudent', $rows)]);
}

// ===== 获取单个学生 =====
if ($action === 'get') {
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $row = $pdo->prepare("SELECT * FROM students WHERE id=?");
    $row->execute([$id]);
    $s = $row->fetch();
    if (!$s) respErr('学生不存在');
    respOk(['student' => decodeStudent($s)]);
}

// ===== 添加学生（教师端） =====
if ($action === 'add') {
    $name     = trim($input['name']     ?? '');
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    $class    = trim($input['class']    ?? '未分班');
    if (!$name || !$username || !$password) respErr('姓名/账号/密码不能为空');

    $dup = $pdo->prepare("SELECT id FROM students WHERE username=?");
    $dup->execute([$username]);
    if ($dup->fetch()) respErr('账号已存在');

    $id = (int)(microtime(true) * 1000);
    $defStatus   = json_encode(['health'=>100,'hungry'=>100,'happy'=>100,'clean'=>100]);
    $defBackpack = json_encode(['apple'=>3,'soap'=>2,'ball'=>1]);
    $pdo->prepare("INSERT INTO students (id,name,username,password,class,points,pet_status,backpack,join_date) VALUES (?,?,?,?,?,0,?,?,?)")
        ->execute([$id,$name,$username,$password,$class,$defStatus,$defBackpack,date('Y-m-d')]);

    $newS = ['id'=>$id,'name'=>$name,'username'=>$username,'role'=>'student','class'=>$class,'points'=>0,'petType'=>null,'petName'=>null,'petExp'=>0,'petStage'=>0,'petStatus'=>['health'=>100,'hungry'=>100,'happy'=>100,'clean'=>100],'backpack'=>['apple'=>3,'soap'=>2,'ball'=>1],'joinDate'=>date('Y-m-d'),'pointsLog'=>[]];
    respOk(['student'=>$newS]);
}

// ===== 删除学生 =====
if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    $pdo->prepare("DELETE FROM students WHERE id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM task_submissions WHERE student_id=?")->execute([$id]);
    respOk();
}

// ===== 重置学生密码 =====
if ($action === 'resetPassword') {
    $id  = (int)($input['id']       ?? 0);
    $pwd = trim($input['password']  ?? '');
    if (!$id || !$pwd) respErr('参数不完整');
    $pdo->prepare("UPDATE students SET password=? WHERE id=?")->execute([$pwd, $id]);
    respOk();
}

// ===== 领取宠物 =====
if ($action === 'adoptPet') {
    $id      = (int)($input['id']      ?? 0);
    $petType = trim($input['petType']  ?? '');
    $petName = trim($input['petName']  ?? '');
    if (!$id || !$petType) respErr('参数不完整');
    $defStatus = json_encode(['health'=>100,'hungry'=>80,'happy'=>80,'clean'=>100]);
    $pdo->prepare("UPDATE students SET pet_type=?,pet_name=?,pet_exp=0,pet_stage=0,pet_dead=0,pet_hatch_progress=0,pet_status=? WHERE id=?")
        ->execute([$petType, $petName, $defStatus, $id]);
    respOk();
}

// ===== 手动发放积分（教师/管理员） =====
if ($action === 'grantPoints') {
    $id     = (int)($input['id']     ?? 0);
    $pts    = (int)($input['points'] ?? 0);
    $reason = trim($input['reason']  ?? "老师奖励了 {$pts} 积分");
    if (!$id || $pts <= 0) respErr('参数不完整');
    addPointsDB($pdo, $id, $pts, $reason, '🎁');
    respOk();
}

// ===== 扣除积分 =====
if ($action === 'deductPoints') {
    $id     = (int)($input['id']     ?? 0);
    $pts    = (int)($input['points'] ?? 0);
    $reason = trim($input['reason']  ?? "老师扣除了 {$pts} 积分");
    if (!$id || $pts <= 0) respErr('参数不完整');

    $s = $pdo->prepare("SELECT points,points_log FROM students WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) respErr('学生不存在');

    $deduct    = min($pts, (int)$row['points']);
    $newPoints = max(0, (int)$row['points'] - $pts);
    $log       = $row['points_log'] ? json_decode($row['points_log'], true) : [];
    $log[]     = ['icon'=>'📉','label'=>$reason,'delta'=>-$deduct,'time'=>date('Y-m-d H:i'),'total'=>$newPoints];

    $pdo->prepare("UPDATE students SET points=?,points_log=?,last_grant_reason=? WHERE id=?")
        ->execute([$newPoints, json_encode($log, JSON_UNESCAPED_UNICODE), $reason, $id]);
    respOk(['deducted'=>$deduct]);
}

// ===== 购买道具 =====
if ($action === 'buyItem') {
    $id     = (int)($input['id']     ?? 0);
    $itemId = trim($input['itemId']  ?? '');
    $cost   = (int)($input['cost']   ?? 0);
    $name   = trim($input['name']    ?? $itemId);
    if (!$id || !$itemId) respErr('参数不完整');

    $s = $pdo->prepare("SELECT * FROM students WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) respErr('学生不存在');
    if ((int)$row['points'] < $cost) respErr("积分不足，需要{$cost}积分");

    $backpack = $row['backpack'] ? json_decode($row['backpack'], true) : [];
    $backpack[$itemId] = ($backpack[$itemId] ?? 0) + 1;

    $newPoints = (int)$row['points'] - $cost;
    $log       = $row['points_log'] ? json_decode($row['points_log'], true) : [];
    $log[]     = ['icon'=>'🛒','label'=>"购买道具「{$name}」",'delta'=>-$cost,'time'=>date('Y-m-d H:i'),'total'=>$newPoints];
    $buyDeduct = (int)$row['buy_deduct'] + $cost;

    $pdo->prepare("UPDATE students SET points=?,backpack=?,points_log=?,buy_deduct=? WHERE id=?")
        ->execute([$newPoints, json_encode($backpack, JSON_UNESCAPED_UNICODE), json_encode($log, JSON_UNESCAPED_UNICODE), $buyDeduct, $id]);
    respOk(['newPoints'=>$newPoints,'backpack'=>$backpack,'buyDeduct'=>$buyDeduct]);
}

// ===== 使用道具 =====
if ($action === 'useItem') {
    $id     = (int)($input['id']     ?? 0);
    $itemId = trim($input['itemId']  ?? '');
    $effect = $input['effect']       ?? [];   // 由前端传入 effect 对象
    if (!$id || !$itemId) respErr('参数不完整');

    $s = $pdo->prepare("SELECT * FROM students WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) respErr('学生不存在');

    $backpack = $row['backpack'] ? json_decode($row['backpack'], true) : [];
    if (empty($backpack[$itemId]) || $backpack[$itemId] <= 0) respErr('背包中没有该道具！');

    $status = $row['pet_status'] ? json_decode($row['pet_status'], true) : ['health'=>100,'hungry'=>100,'happy'=>100,'clean'=>100];
    $petDead = (bool)$row['pet_dead'];
    $petExp  = (int)$row['pet_exp'];
    $petStage= (int)$row['pet_stage'];
    $lastFed = $row['last_fed_at'];
    $hatchProgress = (int)$row['pet_hatch_progress'];

    $itemType = $input['itemType'] ?? '';
    $levelUp  = false;
    $hatched  = false;

    // 食物道具处理（包含孵化逻辑）
    if ($itemType === 'food') {
        $lastFed = (int)(microtime(true) * 1000);
        if ($petDead) {
            $hatchProgress++;
            $backpack[$itemId]--;
            if ($hatchProgress >= 3) {
                $petDead = false; $petExp = 0; $petStage = 0; $hatchProgress = 0;
                $status  = ['health'=>100,'hungry'=>80,'happy'=>80,'clean'=>100];
                $hatched = true;
            }
            $pdo->prepare("UPDATE students SET backpack=?,pet_dead=?,pet_exp=?,pet_stage=?,pet_hatch_progress=?,pet_status=?,last_fed_at=? WHERE id=?")
                ->execute([json_encode($backpack), $hatched?0:1, $petExp, $petStage, $hatchProgress, json_encode($status), $lastFed, $id]);
            respOk(['hatched'=>$hatched,'hatchProgress'=>$hatchProgress,'levelUp'=>false]);
        }
    }

    // 死亡状态下非食物道具不可用
    if ($petDead) respErr('宠物还是一颗蛋，请先用食物喂食孵化它！');

    // 应用效果
    foreach (['hungry','health','happy','clean'] as $k) {
        if (isset($effect[$k])) $status[$k] = min(100, max(0, ($status[$k]??0) + (int)$effect[$k]));
    }
    if (isset($effect['exp'])) {
        $oldStage = $petStage;
        $petExp   = max(0, $petExp + (int)$effect['exp']);
        $petStage = getPetLevel($petExp);
        $levelUp  = $petStage > $oldStage;
    }

    $backpack[$itemId]--;
    $updateFields = [json_encode($backpack), json_encode($status), $petExp, $petStage];
    if ($itemType === 'food') $updateFields[] = (int)(microtime(true)*1000);
    $sql = $itemType === 'food'
        ? "UPDATE students SET backpack=?,pet_status=?,pet_exp=?,pet_stage=?,last_fed_at=? WHERE id=?"
        : "UPDATE students SET backpack=?,pet_status=?,pet_exp=?,pet_stage=? WHERE id=?";
    $updateFields[] = $id;
    $pdo->prepare($sql)->execute($updateFields);

    respOk(['levelUp'=>$levelUp,'newStage'=>$petStage,'backpack'=>$backpack,'petStatus'=>$status,'petExp'=>$petExp]);
}

// ===== 宠物状态衰减（tick） =====
if ($action === 'tick') {
    $id = (int)($input['id'] ?? 0);
    $s = $pdo->prepare("SELECT * FROM students WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row || !$row['pet_type'] || $row['pet_dead']) respOk(['skipped'=>true]);

    $status = json_decode($row['pet_status'], true);
    $status['hungry'] = max(0, ($status['hungry']??100) - 2);
    $status['happy']  = max(0, ($status['happy'] ??100) - 1);
    $status['clean']  = max(0, ($status['clean'] ??100) - 1);
    if ($status['hungry'] < 20 || $status['clean'] < 20) {
        $status['health'] = max(0, ($status['health']??100) - 3);
    }

    if ($status['health'] <= 0) {
        // 宠物死亡
        $status = ['health'=>0,'hungry'=>0,'happy'=>0,'clean'=>0];
        $lostPts = (int)$row['points'];
        $log = $row['points_log'] ? json_decode($row['points_log'], true) : [];
        $log[] = ['icon'=>'💔','label'=>"宠物因太久没照顾而离开了...积分全部清零",'delta'=>-$lostPts,'time'=>date('Y-m-d H:i'),'total'=>0];
        $pdo->prepare("UPDATE students SET pet_dead=1,pet_hatch_progress=0,pet_status=?,points=0,points_log=? WHERE id=?")
            ->execute([json_encode($status), json_encode($log, JSON_UNESCAPED_UNICODE), $id]);
        respOk(['died'=>true,'petStatus'=>$status]);
    }

    $pdo->prepare("UPDATE students SET pet_status=? WHERE id=?")->execute([json_encode($status), $id]);
    respOk(['petStatus'=>$status]);
}

// ===== 检查每日离线惩罚 =====
if ($action === 'checkPenalty') {
    $id = (int)($input['id'] ?? 0);
    $s = $pdo->prepare("SELECT * FROM students WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row || !$row['pet_type'] || $row['pet_dead']) respOk(['skipped'=>true]);

    $now     = (int)(microtime(true) * 1000);
    $lastFed = $row['last_fed_at'] ? (int)$row['last_fed_at'] : $now;

    if (!$row['last_fed_at']) {
        $pdo->prepare("UPDATE students SET last_fed_at=? WHERE id=?")->execute([$now, $id]);
        respOk(['skipped'=>true]);
    }

    $msPerDay    = 24 * 60 * 60 * 1000;
    $daysMissed  = floor(($now - $lastFed) / $msPerDay);
    if ($daysMissed <= 0) respOk(['skipped'=>true]);

    $expPenalty  = $daysMissed * 5;
    $hpPenalty   = $daysMissed * 10;
    $status      = json_decode($row['pet_status'], true);
    $petExp      = max(0, (int)$row['pet_exp'] - $expPenalty);
    $petStage    = getPetLevel($petExp);
    $status['health'] = max(0, ($status['health']??100) - $hpPenalty);
    $status['hungry'] = max(0, ($status['hungry']??100) - $daysMissed * 15);

    $log = $row['points_log'] ? json_decode($row['points_log'], true) : [];
    $log[] = ['icon'=>'⚠️','label'=>"宠物因 {$daysMissed} 天未喂食，经验 -{$expPenalty}，生命 -{$hpPenalty}",'delta'=>0,'time'=>date('Y-m-d H:i'),'total'=>(int)$row['points']];

    $died = false;
    if ($status['health'] <= 0) {
        $status = ['health'=>0,'hungry'=>0,'happy'=>0,'clean'=>0];
        $died   = true;
        $lostPts = (int)$row['points'];
        $log[] = ['icon'=>'💔','label'=>"宠物死亡，积分清零",'delta'=>-$lostPts,'time'=>date('Y-m-d H:i'),'total'=>0];
        $pdo->prepare("UPDATE students SET pet_dead=1,pet_hatch_progress=0,pet_exp=?,pet_stage=?,pet_status=?,points=0,points_log=?,last_fed_at=? WHERE id=?")
            ->execute([$petExp, $petStage, json_encode($status), json_encode($log, JSON_UNESCAPED_UNICODE), $now, $id]);
    } else {
        $pdo->prepare("UPDATE students SET pet_exp=?,pet_stage=?,pet_status=?,points_log=?,last_fed_at=? WHERE id=?")
            ->execute([$petExp, $petStage, json_encode($status), json_encode($log, JSON_UNESCAPED_UNICODE), $now, $id]);
    }

    respOk(['daysMissed'=>$daysMissed,'expPenalty'=>$expPenalty,'hpPenalty'=>$hpPenalty,'died'=>$died]);
}

// ===== 更新学生数据（通用） =====
if ($action === 'update') {
    $id      = (int)($input['id'] ?? 0);
    $updates = $input['updates'] ?? [];
    if (!$id) respErr('id 不能为空');

    $allowed = ['pet_status','backpack','points_log','pet_exp','pet_stage','pet_dead','pet_hatch_progress','last_fed_at','last_grant_reason','buy_deduct'];
    $sets = [];
    $vals = [];

    // 映射前端字段名到数据库字段名
    $fieldMap = [
        'petStatus'       => 'pet_status',
        'backpack'        => 'backpack',
        'pointsLog'       => 'points_log',
        'petExp'          => 'pet_exp',
        'petStage'        => 'pet_stage',
        'petDead'         => 'pet_dead',
        'petHatchProgress'=> 'pet_hatch_progress',
        '_lastFedAt'      => 'last_fed_at',
        '_lastGrantReason'=> 'last_grant_reason',
        '_buyDeduct'      => 'buy_deduct',
        'petType'         => 'pet_type',
        'petName'         => 'pet_name',
        'points'          => 'points',
    ];

    foreach ($updates as $key => $val) {
        $dbKey = $fieldMap[$key] ?? $key;
        if (is_array($val) || is_object($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
        $sets[] = "`{$dbKey}`=?";
        $vals[] = $val;
    }
    if (empty($sets)) respErr('没有可更新的字段');
    $vals[] = $id;
    $pdo->prepare("UPDATE students SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
    respOk();
}

respErr('未知操作');

// ===== 工具函数 =====
function decodeStudent($row) {
    if (!$row) return null;
    $row['role']       = 'student';
    $row['petType']    = $row['pet_type'];
    $row['petName']    = $row['pet_name'];
    $row['petExp']     = (int)$row['pet_exp'];
    $row['petStage']   = (int)$row['pet_stage'];
    $row['petDead']    = (bool)$row['pet_dead'];
    $row['petHatchProgress'] = (int)$row['pet_hatch_progress'];
    $row['petStatus']  = $row['pet_status']  ? json_decode($row['pet_status'], true)  : ['health'=>100,'hungry'=>100,'happy'=>100,'clean'=>100];
    $row['backpack']   = $row['backpack']    ? json_decode($row['backpack'], true)     : (object)[];
    $row['pointsLog']  = $row['points_log']  ? json_decode($row['points_log'], true)  : [];
    $row['joinDate']   = $row['join_date'];
    $row['_lastFedAt'] = $row['last_fed_at'] ? (int)$row['last_fed_at'] : null;
    $row['_lastGrantReason'] = $row['last_grant_reason'];
    $row['_buyDeduct'] = (int)$row['buy_deduct'];
    $row['points']     = (int)$row['points'];
    $row['id']         = (int)$row['id'];
    foreach(['pet_type','pet_name','pet_exp','pet_stage','pet_dead','pet_hatch_progress','pet_status','points_log','join_date','last_fed_at','last_grant_reason','buy_deduct'] as $k) {
        unset($row[$k]);
    }
    return $row;
}

function addPointsDB($pdo, $studentId, $pts, $reason, $icon='⭐') {
    $s = $pdo->prepare("SELECT * FROM students WHERE id=?");
    $s->execute([$studentId]);
    $row = $s->fetch();
    if (!$row) return;

    $newPoints = (int)$row['points'] + $pts;
    $addExp    = round($pts * 0.5);
    $petExp    = (int)$row['pet_exp'] + ($row['pet_type'] ? $addExp : 0);
    $petStage  = getPetLevel($petExp);
    $log       = $row['points_log'] ? json_decode($row['points_log'], true) : [];
    $log[]     = ['icon'=>$icon,'label'=>$reason,'delta'=>$pts,'time'=>date('Y-m-d H:i'),'total'=>$newPoints];

    $pdo->prepare("UPDATE students SET points=?,pet_exp=?,pet_stage=?,points_log=?,last_grant_reason=? WHERE id=?")
        ->execute([$newPoints, $petExp, $petStage, json_encode($log, JSON_UNESCAPED_UNICODE), $reason, $studentId]);
}

function getPetLevel($exp) {
    $stages = [0,100,300,600,1000,1500,2200,3100,4200,5600,7200,9000,11200,13700,16500,20000,24000,28500,33500,39000,45000];
    for ($i = count($stages)-1; $i >= 0; $i--) {
        if ($exp >= $stages[$i]) return $i;
    }
    return 0;
}
