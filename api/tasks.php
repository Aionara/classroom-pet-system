<?php
// ===== 任务 API =====
// GET  /api/tasks.php?action=list
// POST /api/tasks.php  action=create|delete|submit|resubmit|review

require_once 'config.php';

$input  = getInput();
$action = $input['action'] ?? ($_GET['action'] ?? '');
$pdo    = getDB();

// ===== 获取任务列表（含提交记录） =====
if ($action === 'list') {
    $tasks = $pdo->query("SELECT * FROM tasks ORDER BY id DESC")->fetchAll();
    foreach ($tasks as &$task) {
        $subs = $pdo->prepare("SELECT * FROM task_submissions WHERE task_id=?");
        $subs->execute([$task['id']]);
        $task['submissions'] = array_map(function($s){
            return [
                'studentId'   => (int)$s['student_id'],
                'status'      => $s['status'],
                'content'     => $s['content'],
                'submittedAt' => $s['submitted_at'],
                'reviewedAt'  => $s['reviewed_at'],
                'resubmitted' => (bool)$s['resubmitted'],
            ];
        }, $subs->fetchAll());
        $task['id']          = (int)$task['id'];
        $task['points']      = (int)$task['points'];
        $task['createdBy']   = (int)$task['created_by'];
        $task['createdAt']   = $task['created_at'];
        $task['desc']        = $task['description'];
        unset($task['created_by'], $task['created_at'], $task['description']);
    }
    respOk(['tasks' => $tasks]);
}

// ===== 发布任务 =====
if ($action === 'create') {
    $title     = trim($input['title']    ?? '');
    $desc      = trim($input['desc']     ?? '');
    $points    = (int)($input['points']  ?? 0);
    $icon      = trim($input['icon']     ?? '📝');
    $subject   = trim($input['subject']  ?? '');
    $deadline  = trim($input['deadline'] ?? '');
    $createdBy = (int)($input['createdBy'] ?? 0);

    if (!$title) respErr('任务标题不能为空');

    $id = (int)(microtime(true) * 1000);
    $createdAt = date('Y-m-d H:i', time());

    try {
        $stmt = $pdo->prepare("INSERT INTO tasks (id,title,description,points,icon,subject,deadline,status,created_by,created_at) VALUES (?,?,?,?,?,?,?,'active',?,?)");
        $stmt->execute([$id, $title, $desc, $points, $icon, $subject, $deadline, $createdBy, $createdAt]);
    } catch (Exception $e) {
        respErr('插入失败: ' . $e->getMessage());
    }

    respOk(['task' => [
        'id' => $id, 'title' => $title, 'desc' => $desc,
        'points' => $points, 'icon' => $icon, 'subject' => $subject,
        'deadline' => $deadline, 'status' => 'active',
        'createdBy' => $createdBy, 'createdAt' => $createdAt,
        'submissions' => [],
    ]]);
}

// ===== 删除任务 =====
if ($action === 'delete') {
    $taskId = (int)($input['taskId'] ?? 0);
    if (!$taskId) respErr('taskId 不能为空');
    $pdo->prepare("DELETE FROM task_submissions WHERE task_id=?")->execute([$taskId]);
    $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$taskId]);
    respOk();
}

// ===== 提交任务 =====
if ($action === 'submit') {
    $taskId    = (int)($input['taskId']    ?? 0);
    $studentId = (int)($input['studentId'] ?? 0);
    $content   = trim($input['content']    ?? '');

    if (!$taskId || !$studentId) respErr('参数不完整');

    // 检查任务是否存在
    $task = $pdo->prepare("SELECT id FROM tasks WHERE id=? AND status='active'");
    $task->execute([$taskId]);
    if (!$task->fetch()) respErr('任务不存在或已关闭');

    // 检查是否已提交
    $existing = $pdo->prepare("SELECT id,status FROM task_submissions WHERE task_id=? AND student_id=?");
    $existing->execute([$taskId, $studentId]);
    $ex = $existing->fetch();
    if ($ex && $ex['status'] !== 'rejected') respErr('已提交过该任务');

    $now = date('Y-m-d H:i');
    if ($ex) {
        // 驳回后重新提交
        $pdo->prepare("UPDATE task_submissions SET status='submitted',content=?,submitted_at=?,reviewed_at=NULL,resubmitted=1 WHERE task_id=? AND student_id=?")
            ->execute([$content, $now, $taskId, $studentId]);
    } else {
        $pdo->prepare("INSERT INTO task_submissions (task_id,student_id,status,content,submitted_at) VALUES (?,?,'submitted',?,?)")
            ->execute([$taskId, $studentId, $content, $now]);
    }
    respOk();
}

// ===== 审核任务 =====
if ($action === 'review') {
    $taskId    = (int)($input['taskId']    ?? 0);
    $studentId = (int)($input['studentId'] ?? 0);
    $approved  = (bool)($input['approved'] ?? false);

    if (!$taskId || !$studentId) respErr('参数不完整');

    $task = $pdo->prepare("SELECT * FROM tasks WHERE id=?");
    $task->execute([$taskId]);
    $taskRow = $task->fetch();
    if (!$taskRow) respErr('任务不存在');

    $now = date('Y-m-d H:i');

    if ($approved) {
        // 批准：更新提交状态
        $pdo->prepare("UPDATE task_submissions SET status='completed',reviewed_at=? WHERE task_id=? AND student_id=?")
            ->execute([$now, $taskId, $studentId]);

        // 给学生加积分
        $pts = (int)$taskRow['points'];
        $student = $pdo->prepare("SELECT * FROM students WHERE id=?");
        $student->execute([$studentId]);
        $s = $student->fetch();
        if (!$s) respErr('学生不存在');

        $newPoints   = (int)$s['points'] + $pts;
        $addExp      = round($pts * 0.5);
        $newPetExp   = (int)$s['pet_exp'] + $addExp;
        $newPetStage = getPetLevel($newPetExp);
        $reason      = "完成任务「{$taskRow['title']}」";
        $icon        = $taskRow['icon'] ?: '📝';

        // 更新积分日志
        $log = $s['points_log'] ? json_decode($s['points_log'], true) : [];
        $log[] = ['icon'=>$icon,'label'=>$reason,'delta'=>$pts,'time'=>date('Y-m-d H:i'),'total'=>$newPoints];

        $pdo->prepare("UPDATE students SET points=?,pet_exp=?,pet_stage=?,points_log=?,last_grant_reason=? WHERE id=?")
            ->execute([$newPoints, $newPetExp, $newPetStage, json_encode($log, JSON_UNESCAPED_UNICODE), $reason, $studentId]);

        respOk(['newPoints'=>$newPoints,'newPetExp'=>$newPetExp,'newPetStage'=>$newPetStage,'levelUp'=>($newPetStage > (int)$s['pet_stage'])]);
    } else {
        // 拒绝
        $pdo->prepare("UPDATE task_submissions SET status='rejected',reviewed_at=? WHERE task_id=? AND student_id=?")
            ->execute([$now, $taskId, $studentId]);
        respOk();
    }
}

respErr('未知操作');

// ===== 工具：计算宠物等级 =====
function getPetLevel($exp) {
    $stages = [0,100,300,600,1000,1500,2200,3100,4200,5600,7200,9000,11200,13700,16500,20000,24000,28500,33500,39000,45000];
    for ($i = count($stages)-1; $i >= 0; $i--) {
        if ($exp >= $stages[$i]) return $i;
    }
    return 0;
}
