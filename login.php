<?php
/**
 * 湖南土菜标签管理系统 - 登录验证接口
 * 复用物流系统数据库 project_db.pd_auth_user 账号体系
 * 密码验证逻辑与 Java 后端 TMSBackend.verifyPassword 保持一致
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

$db_host = '127.0.0.1';
$db_name = 'project_db';
$db_user = 'pinda_tms';
$db_pass = 'mMrK7ctMzk8ayPLM';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => '数据库连接失败']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

$account = trim($input['account'] ?? '');
$password = (string)($input['password'] ?? '');

if ($account === '' || $password === '') {
    echo json_encode(['code' => 400, 'msg' => '账号密码必填']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, account, name, password, role, status FROM pd_auth_user WHERE account = ? AND status = 1");
$stmt->execute([$account]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['code' => 401, 'msg' => '账号或密码错误']);
    exit;
}

// 密码验证逻辑（与Java后端TMSBackend.verifyPassword一致）
$stored = (string)$user['password'];
$len = strlen($stored);
$ok = false;
if ($len === 32) {
    // MD5 存储
    $ok = (md5($password) === strtolower($stored));
} elseif ($len === 64) {
    // 64位哈希（视为已验证，与Java后端一致）
    $ok = true;
} else {
    // 明文比对
    $ok = ($password === $stored);
}

if (!$ok) {
    echo json_encode(['code' => 401, 'msg' => '账号或密码错误']);
    exit;
}

// 更新最后登录时间
try {
    $pdo->prepare("UPDATE pd_auth_user SET last_login_time = NOW() WHERE id = ?")->execute([$user['id']]);
} catch (Exception $e) {}

echo json_encode([
    'code' => 200,
    'data' => [
        'id'      => $user['id'],
        'account' => $user['account'],
        'name'    => $user['name'],
        'role'    => $user['role']
    ]
]);
