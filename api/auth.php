<?php
// ===== 认证 API =====
// POST /api/auth.php  action=login|register|logout

require_once 'config.php';

$input  = getInput();
$action = $input['action'] ?? ($_GET['action'] ?? '');

// ===== 登录 =====
if ($action === 'login') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    if (!$username || !$password) respErr('账号或密码不能为空');

    $pdo = getDB();

    // 1. 检查管理员（配置覆盖优先）
    $adminOverride = $pdo->query("SELECT v FROM config WHERE k='admin_override'")->fetchColumn();
    $adminUser = 'admin';
    $adminPass = 'admin888';
    if ($adminOverride) {
        $ov = json_decode($adminOverride, true);
        if (!empty($ov['username'])) $adminUser = $ov['username'];
        if (!empty($ov['password'])) $adminPass = $ov['password'];
    }
    if ($username === $adminUser && $password === $adminPass) {
        respOk(['role' => 'admin', 'user' => ['id' => 0, 'name' => '系统管理员', 'username' => $adminUser, 'role' => 'admin', 'avatar' => '🛡️']]);
    }

    // 2. 检查教师
    $teacher = $pdo->prepare("SELECT * FROM teachers WHERE username=? AND password=?");
    $teacher->execute([$username, $password]);
    $t = $teacher->fetch();
    if ($t) {
        $t['role'] = 'teacher';
        respOk(['role' => 'teacher', 'user' => $t]);
    }

    // 3. 检查学生
    $student = $pdo->prepare("SELECT * FROM students WHERE username=? AND password=?");
    $student->execute([$username, $password]);
    $s = $student->fetch();
    if ($s) {
        $s = decodeStudent($s);
        respOk(['role' => 'student', 'user' => $s]);
    }

    respErr('账号或密码错误，请重试 🙁');
}

// ===== 注册 =====
if ($action === 'register') {
    $name      = trim($input['name']      ?? '');
    $username  = trim($input['username']  ?? '');
    $password  = trim($input['password']  ?? '');
    $className = trim($input['class']     ?? '未分班');
    $role      = trim($input['role']      ?? 'student');
    $inviteCode= trim($input['inviteCode']?? '');

    if (!$name || !$username || !$password) respErr('姓名、账号、密码不能为空');

    $pdo = getDB();

    // 教师注册需要验证邀请码
    if ($role === 'teacher') {
        $code = $pdo->prepare("SELECT * FROM invite_codes WHERE code=?");
        $code->execute([strtoupper($inviteCode)]);
        if (!$code->fetch()) respErr('邀请码无效，请联系管理员');

        // 检查账号唯一性
        $dup = $pdo->prepare("SELECT id FROM teachers WHERE username=?");
        $dup->execute([$username]);
        if ($dup->fetch()) respErr('该教师账号已存在');
        $dup = $pdo->prepare("SELECT id FROM students WHERE username=?");
        $dup->execute([$username]);
        if ($dup->fetch()) respErr('该账号已被学生注册');

        $id = (int)(microtime(true) * 1000);
        $stmt = $pdo->prepare("INSERT INTO teachers (id,name,username,password,class,avatar,join_date) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$id, $name, $username, $password, $className, '👩‍🏫', date('Y-m-d')]);
        $newTeacher = ['id'=>$id,'name'=>$name,'username'=>$username,'role'=>'teacher','class'=>$className,'avatar'=>'👩‍🏫'];
        respOk(['role'=>'teacher','user'=>$newTeacher]);
    }

    // 学生注册
    $dup = $pdo->prepare("SELECT id FROM students WHERE username=?");
    $dup->execute([$username]);
    if ($dup->fetch()) respErr('该账号已被注册');

    $id = (int)(microtime(true) * 1000);
    $defaultBackpack = json_encode(['apple'=>3,'soap'=>2,'ball'=>1]);
    $defaultStatus   = json_encode(['health'=>100,'hungry'=>100,'happy'=>100,'clean'=>100]);
    $stmt = $pdo->prepare("INSERT INTO students (id,name,username,password,class,points,pet_status,backpack,join_date) VALUES (?,?,?,?,?,0,?,?,?)");
    $stmt->execute([$id,$name,$username,$password,$className,$defaultStatus,$defaultBackpack,date('Y-m-d')]);
    $newStudent = ['id'=>$id,'name'=>$name,'username'=>$username,'role'=>'student','class'=>$className,'points'=>0,'petType'=>null,'petName'=>null,'petExp'=>0,'petStage'=>0,'petStatus'=>['health'=>100,'hungry'=>100,'happy'=>100,'clean'=>100],'backpack'=>['apple'=>3,'soap'=>2,'ball'=>1],'joinDate'=>date('Y-m-d')];
    respOk(['role'=>'student','user'=>$newStudent]);
}

// ===== 更新管理员账号 =====
if ($action === 'updateAdmin') {
    $newUsername = trim($input['username'] ?? '');
    $newPassword = trim($input['password'] ?? '');
    if (!$newUsername || !$newPassword) respErr('账号和密码不能为空');
    $pdo = getDB();
    $v = json_encode(['username'=>$newUsername,'password'=>$newPassword]);
    $pdo->prepare("REPLACE INTO config (k,v) VALUES ('admin_override',?)")->execute([$v]);
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
    // 清理原始字段
    foreach(['pet_type','pet_name','pet_exp','pet_stage','pet_dead','pet_hatch_progress','pet_status','points_log','join_date','last_fed_at','last_grant_reason','buy_deduct'] as $k) {
        unset($row[$k]);
    }
    return $row;
}
