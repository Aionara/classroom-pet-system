<?php
// ===== 数据库配置 =====
define('DB_HOST', 'mysql.ct8.pl');
define('DB_PORT', 3306);
define('DB_NAME', 'm53235_pet_system');
define('DB_USER', 'm53235_gsgsy');
define('DB_PASS', 'TX^w8)0ZrEqdxYgYf5mk');
define('DB_CHARSET', 'utf8mb4');

// 跨域允许（允许任意来源，因为前端和后端在同一域名下也可以）
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理 OPTIONS 预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ===== 获取数据库连接 =====
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'msg' => '数据库连接失败: ' . $e->getMessage()]);
            exit();
        }
    }
    return $pdo;
}

// ===== 统一响应函数 =====
function resp($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function respOk($data = []) {
    resp(array_merge(['success' => true], $data));
}

function respErr($msg) {
    resp(['success' => false, 'msg' => $msg]);
}

// ===== 获取 POST JSON 输入 =====
function getInput() {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?: []) : [];
}
