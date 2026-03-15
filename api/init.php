<?php
// ===== 数据库初始化脚本 =====
// 访问一次这个文件即可建表并写入初始数据
// 建议初始化完成后删除或重命名此文件

require_once 'config.php';

$pdo = getDB();

// ===== 建表 =====
$tables = [];

// 学生表
$tables[] = "CREATE TABLE IF NOT EXISTS `students` (
  `id`           BIGINT       NOT NULL,
  `name`         VARCHAR(50)  NOT NULL,
  `username`     VARCHAR(50)  NOT NULL UNIQUE,
  `password`     VARCHAR(100) NOT NULL,
  `role`         VARCHAR(20)  NOT NULL DEFAULT 'student',
  `class`        VARCHAR(50)  DEFAULT '未分班',
  `points`       INT          NOT NULL DEFAULT 0,
  `points_log`   LONGTEXT     DEFAULT NULL COMMENT 'JSON数组',
  `pet_type`     VARCHAR(30)  DEFAULT NULL,
  `pet_name`     VARCHAR(50)  DEFAULT NULL,
  `pet_exp`      INT          NOT NULL DEFAULT 0,
  `pet_stage`    INT          NOT NULL DEFAULT 0,
  `pet_status`   TEXT         DEFAULT NULL COMMENT 'JSON对象 {health,hungry,happy,clean}',
  `backpack`     TEXT         DEFAULT NULL COMMENT 'JSON对象',
  `pet_dead`     TINYINT(1)   NOT NULL DEFAULT 0,
  `pet_hatch_progress` INT    NOT NULL DEFAULT 0,
  `last_fed_at`  BIGINT       DEFAULT NULL COMMENT 'Unix毫秒时间戳',
  `last_grant_reason` VARCHAR(200) DEFAULT NULL,
  `buy_deduct`   INT          NOT NULL DEFAULT 0,
  `join_date`    VARCHAR(20)  DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// 教师表
$tables[] = "CREATE TABLE IF NOT EXISTS `teachers` (
  `id`       BIGINT       NOT NULL,
  `name`     VARCHAR(50)  NOT NULL,
  `username` VARCHAR(50)  NOT NULL UNIQUE,
  `password` VARCHAR(100) NOT NULL,
  `role`     VARCHAR(20)  NOT NULL DEFAULT 'teacher',
  `class`    VARCHAR(50)  DEFAULT '未分班',
  `avatar`   VARCHAR(10)  DEFAULT '👩‍🏫',
  `join_date` VARCHAR(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// 任务表
$tables[] = "CREATE TABLE IF NOT EXISTS `tasks` (
  `id`          BIGINT        NOT NULL,
  `title`       VARCHAR(100)  NOT NULL,
  `description` TEXT          DEFAULT NULL,
  `points`      INT           NOT NULL DEFAULT 0,
  `icon`        VARCHAR(10)   DEFAULT '📝',
  `subject`     VARCHAR(30)   DEFAULT NULL,
  `deadline`    VARCHAR(50)   DEFAULT NULL,
  `status`      VARCHAR(20)   NOT NULL DEFAULT 'active',
  `created_by`  BIGINT        DEFAULT NULL,
  `created_at`  VARCHAR(50)   DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// 任务提交表
$tables[] = "CREATE TABLE IF NOT EXISTS `task_submissions` (
  `id`           BIGINT        NOT NULL AUTO_INCREMENT,
  `task_id`      BIGINT        NOT NULL,
  `student_id`   BIGINT        NOT NULL,
  `status`       VARCHAR(20)   NOT NULL DEFAULT 'pending' COMMENT 'pending/submitted/completed/rejected',
  `content`      TEXT          DEFAULT NULL,
  `submitted_at` VARCHAR(50)   DEFAULT NULL,
  `reviewed_at`  VARCHAR(50)   DEFAULT NULL,
  `resubmitted`  TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_student` (`task_id`, `student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// 邀请码表
$tables[] = "CREATE TABLE IF NOT EXISTS `invite_codes` (
  `code`       VARCHAR(50)  NOT NULL,
  `note`       VARCHAR(200) DEFAULT '',
  `used`       TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` VARCHAR(20)  DEFAULT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// 系统配置表（管理员账号覆盖等）
$tables[] = "CREATE TABLE IF NOT EXISTS `config` (
  `k` VARCHAR(100) NOT NULL,
  `v` TEXT         DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

foreach ($tables as $sql) {
    $pdo->exec($sql);
}

// ===== 写入初始数据（仅当表为空时） =====

// 初始学生
$count = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
if ($count == 0) {
    $initialStudents = [
        [1, '小明', 'xiaoming', '123456', '三年一班', 320, 'dragon', '小火龙', 220, 2, '{"health":75,"hungry":60,"happy":80,"clean":70}', '{"apple":3,"cake":1,"soap":2,"ball":1,"medicine":1,"star":2}', '2026-02-01'],
        [2, '小红', 'xiaohong', '123456', '三年一班', 580, 'cat',    '星星猫', 530, 3, '{"health":90,"hungry":70,"happy":95,"clean":85}', '{"apple":5,"cake":2,"soap":3,"ball":2,"medicine":0,"star":3,"rainbow":1}', '2026-02-01'],
        [3, '小刚', 'xiaogang', '123456', '三年一班', 180, 'bunny',  '棉花兔', 150, 1, '{"health":55,"hungry":30,"happy":40,"clean":50}', '{"apple":1,"soap":1,"medicine":2}', '2026-02-01'],
        [4, '小美', 'xiaomei',  '123456', '三年一班', 440, 'fairy',  '梦精灵', 420, 2, '{"health":85,"hungry":65,"happy":88,"clean":92}', '{"apple":4,"cake":1,"soap":2,"yarn":2,"star":1}', '2026-02-01'],
        [5, '小强', 'xiaoqiang','123456', '三年一班', 260, 'bird',   '彩翼鸟', 250, 2, '{"health":70,"hungry":55,"happy":65,"clean":75}', '{"apple":2,"soap":1,"ball":3,"medicine":1}', '2026-02-01'],
        [6, '小丽', 'xiaoli',   '123456', '三年一班', 150, 'dog',    '旺财狗', 100, 1, '{"health":60,"hungry":40,"happy":55,"clean":45}', '{"apple":2,"medicine":1}', '2026-02-01'],
    ];
    $stmt = $pdo->prepare("INSERT INTO students (id,name,username,password,class,points,pet_type,pet_name,pet_exp,pet_stage,pet_status,backpack,join_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($initialStudents as $s) {
        $stmt->execute($s);
    }
}

// 初始教师
$count = $pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
if ($count == 0) {
    $teachers = [
        [100, '王老师', 'teacher',  'teacher123', '三年一班', '👩‍🏫'],
        [101, '李老师', 'teacher2', 'teacher123', '三年二班', '👨‍🏫'],
    ];
    $stmt = $pdo->prepare("INSERT INTO teachers (id,name,username,password,class,avatar) VALUES (?,?,?,?,?,?)");
    foreach ($teachers as $t) {
        $stmt->execute($t);
    }
}

// 初始任务
$count = $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
if ($count == 0) {
    $tasks = [
        [1737849600000, '完成数学作业', '完成今日数学练习题（P45-P47）并提交照片', 50, '📐', '数学', '2026-03-14 18:00', 'active', 100, '2026-03-14 08:00'],
        [1737849600001, '背诵古诗《静夜思》', '背诵全文，明日课堂当场检查', 35, '📝', '语文', '2026-03-15 08:00', 'active', 100, '2026-03-14 08:00'],
        [1737849600002, '课堂积极回答问题', '在今日课堂上积极举手回答3个问题', 20, '🙋', '综合', '2026-03-14 16:00', 'active', 100, '2026-03-14 08:00'],
        [1737849600003, '阅读打卡', '阅读课外书籍30分钟并做简单笔记上传', 25, '📖', '语文', '2026-03-16 20:00', 'active', 100, '2026-03-14 08:00'],
    ];
    $stmt = $pdo->prepare("INSERT INTO tasks (id,title,description,points,icon,subject,deadline,status,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($tasks as $t) {
        $stmt->execute($t);
    }

    // 初始提交记录
    $submissions = [
        [1737849600000, 1, 'completed', '已完成所有题目',     '2026-03-14 15:30', '2026-03-14 16:00'],
        [1737849600000, 2, 'completed', '完成并检查了两遍',   '2026-03-14 14:20', '2026-03-14 16:00'],
        [1737849600001, 2, 'completed', '已背熟',             '2026-03-14 16:00', '2026-03-14 17:00'],
        [1737849600001, 4, 'submitted', '背了好多遍了',       '2026-03-14 17:00', null],
        [1737849600002, 1, 'completed', '回答了5个问题！',     '2026-03-14 14:00', '2026-03-14 15:00'],
        [1737849600002, 4, 'completed', '认真参与了',         '2026-03-14 15:00', '2026-03-14 15:30'],
        [1737849600002, 5, 'completed', '举手3次',            '2026-03-14 15:30', '2026-03-14 16:00'],
    ];
    $stmt = $pdo->prepare("INSERT INTO task_submissions (task_id,student_id,status,content,submitted_at,reviewed_at) VALUES (?,?,?,?,?,?)");
    foreach ($submissions as $sub) {
        $stmt->execute($sub);
    }
}

// 初始邀请码
$count = $pdo->query("SELECT COUNT(*) FROM invite_codes")->fetchColumn();
if ($count == 0) {
    $codes = [
        ['TEACHER2026', '默认通用邀请码', 0, '2026-01-01'],
        ['CLASS2026A',  '三年一班专属',  0, '2026-01-01'],
    ];
    $stmt = $pdo->prepare("INSERT INTO invite_codes (code,note,used,created_at) VALUES (?,?,?,?)");
    foreach ($codes as $c) {
        $stmt->execute($c);
    }
}

echo json_encode([
    'success' => true,
    'msg'     => '✅ 数据库初始化完成！所有表已创建，初始数据已写入。请访问完成后删除或重命名 init.php 文件以防止重复调用。'
], JSON_UNESCAPED_UNICODE);
