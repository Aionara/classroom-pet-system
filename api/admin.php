<?php
// ===== 教师/管理员专用 API =====
// POST /api/admin.php  action=getTeachers|deleteTeacher|resetTeacherPassword|getInviteCodes|addInviteCode|removeInviteCode|nukeAll|resetDemo

require_once 'config.php';

$input  = getInput();
$action = $input['action'] ?? ($_GET['action'] ?? '');
$pdo    = getDB();

// ===== 获取教师列表 =====
if ($action === 'getTeachers') {
    $rows = $pdo->query("SELECT id,name,username,role,class,avatar,join_date FROM teachers")->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['role'] = 'teacher'; }
    respOk(['teachers' => $rows]);
}

// ===== 删除教师 =====
if ($action === 'deleteTeacher') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) respErr('id 不能为空');
    $pdo->prepare("DELETE FROM teachers WHERE id=?")->execute([$id]);
    respOk();
}

// ===== 重置教师密码 =====
if ($action === 'resetTeacherPassword') {
    $id  = (int)($input['id']      ?? 0);
    $pwd = trim($input['password'] ?? '');
    if (!$id || !$pwd) respErr('参数不完整');
    $pdo->prepare("UPDATE teachers SET password=? WHERE id=?")->execute([$pwd, $id]);
    respOk();
}

// ===== 获取邀请码 =====
if ($action === 'getInviteCodes') {
    $rows = $pdo->query("SELECT * FROM invite_codes ORDER BY created_at DESC")->fetchAll();
    foreach ($rows as &$r) { $r['used'] = (bool)$r['used']; }
    respOk(['inviteCodes' => $rows]);
}

// ===== 新增邀请码 =====
if ($action === 'addInviteCode') {
    $code = strtoupper(trim($input['code'] ?? ''));
    $note = trim($input['note'] ?? '');
    if (!$code) respErr('邀请码不能为空');

    $dup = $pdo->prepare("SELECT code FROM invite_codes WHERE code=?");
    $dup->execute([$code]);
    if ($dup->fetch()) respErr('该邀请码已存在');

    $pdo->prepare("INSERT INTO invite_codes (code,note,used,created_at) VALUES (?,?,0,?)")
        ->execute([$code, $note, date('Y-m-d')]);
    respOk();
}

// ===== 删除邀请码 =====
if ($action === 'removeInviteCode') {
    $code = strtoupper(trim($input['code'] ?? ''));
    if (!$code) respErr('code 不能为空');
    $pdo->prepare("DELETE FROM invite_codes WHERE code=?")->execute([$code]);
    respOk();
}

// ===== 重置演示数据 =====
if ($action === 'resetDemo') {
    // 清空任务提交
    $pdo->exec("DELETE FROM task_submissions");
    $pdo->exec("DELETE FROM tasks");
    $pdo->exec("DELETE FROM students");

    // 重新写入初始数据（同 init.php）
    $initialStudents = [
        [1, '小明', 'xiaoming', '123456', '三年一班', 320, 'dragon', '小火龙', 220, 2, '{"health":75,"hungry":60,"happy":80,"clean":70}', '{"apple":3,"cake":1,"soap":2,"ball":1,"medicine":1,"star":2}', '2026-02-01'],
        [2, '小红', 'xiaohong', '123456', '三年一班', 580, 'cat',    '星星猫', 530, 3, '{"health":90,"hungry":70,"happy":95,"clean":85}', '{"apple":5,"cake":2,"soap":3,"ball":2,"medicine":0,"star":3,"rainbow":1}', '2026-02-01'],
        [3, '小刚', 'xiaogang', '123456', '三年一班', 180, 'bunny',  '棉花兔', 150, 1, '{"health":55,"hungry":30,"happy":40,"clean":50}', '{"apple":1,"soap":1,"medicine":2}', '2026-02-01'],
        [4, '小美', 'xiaomei',  '123456', '三年一班', 440, 'fairy',  '梦精灵', 420, 2, '{"health":85,"hungry":65,"happy":88,"clean":92}', '{"apple":4,"cake":1,"soap":2,"yarn":2,"star":1}', '2026-02-01'],
        [5, '小强', 'xiaoqiang','123456', '三年一班', 260, 'bird',   '彩翼鸟', 250, 2, '{"health":70,"hungry":55,"happy":65,"clean":75}', '{"apple":2,"soap":1,"ball":3,"medicine":1}', '2026-02-01'],
        [6, '小丽', 'xiaoli',   '123456', '三年一班', 150, 'dog',    '旺财狗', 100, 1, '{"health":60,"hungry":40,"happy":55,"clean":45}', '{"apple":2,"medicine":1}', '2026-02-01'],
    ];
    $stmt = $pdo->prepare("INSERT INTO students (id,name,username,password,class,points,pet_type,pet_name,pet_exp,pet_stage,pet_status,backpack,join_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($initialStudents as $s) $stmt->execute($s);

    $tasks = [
        [1737849600000, '完成数学作业', '完成今日数学练习题（P45-P47）并提交照片', 50, '📐', '数学', '2026-03-14 18:00', 'active', 100, '2026-03-14 08:00'],
        [1737849600001, '背诵古诗《静夜思》', '背诵全文，明日课堂当场检查', 35, '📝', '语文', '2026-03-15 08:00', 'active', 100, '2026-03-14 08:00'],
        [1737849600002, '课堂积极回答问题', '在今日课堂上积极举手回答3个问题', 20, '🙋', '综合', '2026-03-14 16:00', 'active', 100, '2026-03-14 08:00'],
        [1737849600003, '阅读打卡', '阅读课外书籍30分钟并做简单笔记上传', 25, '📖', '语文', '2026-03-16 20:00', 'active', 100, '2026-03-14 08:00'],
    ];
    $stmt = $pdo->prepare("INSERT INTO tasks (id,title,description,points,icon,subject,deadline,status,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($tasks as $t) $stmt->execute($t);

    respOk(['msg' => '演示数据已重置']);
}

// ===== 清空全部数据 =====
if ($action === 'nukeAll') {
    $pdo->exec("DELETE FROM task_submissions");
    $pdo->exec("DELETE FROM tasks");
    $pdo->exec("DELETE FROM students");
    $pdo->exec("DELETE FROM teachers");
    $pdo->exec("DELETE FROM invite_codes");

    // 恢复默认教师和邀请码
    $pdo->exec("INSERT INTO teachers (id,name,username,password,class,avatar) VALUES (100,'王老师','teacher','teacher123','三年一班','👩‍🏫'),(101,'李老师','teacher2','teacher123','三年二班','👨‍🏫')");
    $pdo->exec("INSERT INTO invite_codes (code,note,used,created_at) VALUES ('TEACHER2026','默认通用邀请码',0,'2026-01-01'),('CLASS2026A','三年一班专属',0,'2026-01-01')");

    respOk(['msg' => '所有数据已清空并恢复默认']);
}

respErr('未知操作');
