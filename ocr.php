<?php
/**
 * 齐云山华润 - 图片文字识别接口
 * 后端调用百度智能云OCR（通用文字识别-高精度版）
 * API密钥仅保存在服务端，不暴露给前端
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// ===== 百度OCR 配置（请勿外泄）=====
$BAIDU_API_KEY    = 'cz7hudtVFCpXEQ8ZBSXxmRd2';
$BAIDU_SECRET_KEY = 'wBUBZVsqshaqO5ibEHP3iE54Ah1zRbpa';
$TOKEN_CACHE_FILE = __DIR__ . '/.baidu_ocr_token.json';

// ===== 获取百度 access_token（带缓存，30天有效）=====
function get_baidu_token($apiKey, $secretKey, $cacheFile) {
    // 尝试读缓存
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && isset($cached['token'], $cached['expire_at'])) {
            // 提前600秒过期刷新
            if ($cached['expire_at'] - time() > 600) {
                return $cached['token'];
            }
        }
    }
    $url = 'https://aip.baidubce.com/oauth/2.0/token?grant_type=client_credentials'
         . '&client_id=' . urlencode($apiKey)
         . '&client_secret=' . urlencode($secretKey);
    $resp = @file_get_contents($url);
    if ($resp === false) {
        return null;
    }
    $data = json_decode($resp, true);
    if (!isset($data['access_token'])) {
        return null;
    }
    $expire = time() + (int)($data['expires_in'] ?? 2592000);
    @file_put_contents($cacheFile, json_encode([
        'token'     => $data['access_token'],
        'expire_at' => $expire,
    ]));
    return $data['access_token'];
}

// ===== 读取上传图片 =====
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

$imageData = $input['image'] ?? '';
if (empty($imageData) && isset($_FILES['image'])) {
    $imageData = 'data:' . $_FILES['image']['type'] . ';base64,' . base64_encode(file_get_contents($_FILES['image']['tmp_name']));
}
if (empty($imageData)) {
    echo json_encode(['code' => 400, 'msg' => '未接收到图片']);
    exit;
}

// 提取 base64 部分
if (strpos($imageData, 'base64,') !== false) {
    $b64 = substr($imageData, strpos($imageData, 'base64,') + 7);
} else {
    $b64 = $imageData;
}
$b64 = trim($b64);

if ($b64 === '') {
    echo json_encode(['code' => 400, 'msg' => '图片数据为空']);
    exit;
}

// ===== 图片压缩（百度限制图片 base64 4MB 内，压缩到最长边1600px）=====
function compress_image($b64, $maxSide = 1600, $quality = 88) {
    $raw = base64_decode($b64);
    if ($raw === false || $raw === '') return $b64;
    $img = @imagecreatefromstring($raw);
    if (!$img) return $b64;
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= $maxSide && $h <= $maxSide) {
        // 未超尺寸，直接原样返回（转jpg质量损失，保留原样）
        imagedestroy($img);
        return $b64;
    }
    $scale = $maxSide / max($w, $h);
    $nw = (int)round($w * $scale);
    $nh = (int)round($h * $scale);
    $dst = imagecreatetruecolor($nw, $nh);
    // 保持 PNG 透明
    if (function_exists('imagealphablending')) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    if (strpos($b64, 'image/png') !== false || true) {
        // 优先输出png保持清晰
        imagepng($dst, null, 6);
    }
    $out = ob_get_clean();
    imagedestroy($img);
    imagedestroy($dst);
    if ($out === '') return $b64;
    return base64_encode($out);
}

$b64 = compress_image($b64);

// ===== 获取 token 并调用百度OCR =====
$token = get_baidu_token($BAIDU_API_KEY, $BAIDU_SECRET_KEY, $TOKEN_CACHE_FILE);
if (!$token) {
    echo json_encode(['code' => 500, 'msg' => '百度OCR授权失败，请检查API密钥']);
    exit;
}

// 高精度含位置版：返回每个字的坐标(left/top/width/height)，
// 前端据此精确对齐表格列，避免"数字顺序猜列"导致门店对应错位。
$url = 'https://aip.baidubce.com/rest/2.0/ocr/v1/accurate?access_token=' . urlencode($token);
$postData = http_build_query([
    'image'         => $b64,
    'language_type' => 'CHN_ENG',
    'detect_direction' => 'true',
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$resp = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    echo json_encode(['code' => 500, 'msg' => 'OCR服务请求失败: ' . $curlErr]);
    exit;
}

$result = json_decode($resp, true);

// 处理百度返回错误
if (isset($result['error_code'])) {
    // token过期则清缓存重试一次
    if ($result['error_code'] == 110 || $result['error_code'] == 111) {
        @unlink($TOKEN_CACHE_FILE);
        $token = get_baidu_token($BAIDU_API_KEY, $BAIDU_SECRET_KEY, $TOKEN_CACHE_FILE);
        if ($token) {
            // 重试一次
            $url = 'https://aip.baidubce.com/rest/2.0/ocr/v1/accurate?access_token=' . urlencode($token);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'image' => $b64, 'language_type' => 'CHN_ENG', 'detect_direction' => 'true'
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            $resp = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($resp, true);
        }
    }
    if (isset($result['error_code'])) {
        echo json_encode([
            'code' => 500,
            'msg'  => 'OCR识别失败: ' . ($result['error_msg'] ?? '未知错误'),
            'detail' => $result
        ]);
        exit;
    }
}

// 拼接识别文本
$text = '';
$words = [];
foreach (($result['words_result'] ?? []) as $item) {
    $w = $item['words'] ?? '';
    $text .= $w . "\n";
    // 保留每个词的坐标信息（百度accurate_basic返回location），
    // 前端可据此精确对齐表格列，避免"数字顺序猜列"导致门店对应错位。
    $words[] = [
        'words' => $w,
        'left'   => $item['location']['left'] ?? 0,
        'top'    => $item['location']['top'] ?? 0,
        'width'  => $item['location']['width'] ?? 0,
        'height' => $item['location']['height'] ?? 0,
    ];
}
$text = trim($text);

if ($text === '') {
    echo json_encode(['code' => 404, 'msg' => '未识别到文字内容']);
    exit;
}

echo json_encode(['code' => 200, 'text' => $text, 'words' => $words]);
