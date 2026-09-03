<?php
/**
 * 湖南土菜标签管理系统 - 数据同步接口
 *
 * 功能：把前端保存在 localStorage 里的自定义数据（模板/布局/内容/分组/历史版本）
 *       同步到服务器，这样任何电脑打开网站都能看到最新修改。
 *
 * 接口：
 *   GET  sync.php?action=load  读取服务器端保存的数据
 *   POST sync.php?action=save  保存前端数据到服务器（需携带 token）
 *
 * 数据文件存放在网站根目录之外（/www/wwwroot/labels_data_sync/），避免被直接下载。
 *
 * 2026-08-19 数据丢失修复：
 *   1. 保存时记录时间戳 _ts，供前端判断"哪边数据更新"
 *   2. 空数据保护：服务器已有布局数据时，拒绝被"明显更少"的空数据覆盖（合并保留）
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// 数据文件存放目录（网站根目录之外，防止被直接访问下载）
$store_dir  = dirname(__DIR__) . '/labels_data_sync';
$store_file = $store_dir . '/label_sync_data.json';

// 同步密钥（与 index.html 中 SYNC_TOKEN 一致）
$token = 'ybhzixi_sync_20260813';

$action = trim($_GET['action'] ?? '');

/* ============ 读取数据 ============ */
if ($action === 'load') {
    if (is_file($store_file)) {
        $raw = @file_get_contents($store_file);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = null;
        echo json_encode(['code' => 200, 'data' => $data, 'msg' => 'ok']);
    } else {
        echo json_encode(['code' => 200, 'data' => null, 'msg' => 'no data']);
    }
    exit;
}

/* ============ 保存数据 ============ */
if ($action === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['code' => 405, 'msg' => 'method not allowed']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['code' => 400, 'msg' => 'invalid json']);
        exit;
    }
    // 校验同步密钥
    if (!isset($input['token']) || $input['token'] !== $token) {
        echo json_encode(['code' => 403, 'msg' => 'token error']);
        exit;
    }
    $data = $input['data'] ?? null;
    if (!is_array($data)) {
        echo json_encode(['code' => 400, 'msg' => 'invalid data']);
        exit;
    }
    // 只允许保存白名单内的键，防止写入垃圾数据
    $allowed = [
        'labelCustomTemplates',
        'labelCustomGroups_v1',
        'labelLayouts_60x40_v55',
        'labelOverrides_v1',
        'labelVersionHistory_v1',
        '_ts'
    ];
    $clean = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $data)) {
            $clean[$k] = $data[$k];
        }
    }
    if (empty($clean)) {
        echo json_encode(['code' => 400, 'msg' => 'empty data']);
        exit;
    }
    // 确保目录存在
    if (!is_dir($store_dir)) {
        @mkdir($store_dir, 0755, true);
    }

    // ===== 空数据保护（2026-08-19 修复）=====
    // 服务器已有数据时，若新提交的数据明显更少/为空（疑似空数据覆盖），合并保留服务器数据
    $existing = null;
    if (is_file($store_file)) {
        $existing = json_decode(@file_get_contents($store_file), true);
    }
    if (is_array($existing)) {
        // 1. 布局保护：服务器已有布局时，合并保留（新数据只覆盖同键，其余键保留）
        $exLay = $existing['labelLayouts_60x40_v55'] ?? null;
        if (is_array($exLay) && count($exLay) > 0) {
            $exCount = count($exLay);
            $newCount = isset($clean['labelLayouts_60x40_v55']) && is_array($clean['labelLayouts_60x40_v55'])
                ? count($clean['labelLayouts_60x40_v55']) : 0;
            if ($newCount < $exCount) {
                $merged = $exLay;
                foreach (($clean['labelLayouts_60x40_v55'] ?? []) as $k => $v) {
                    $merged[$k] = $v;
                }
                $clean['labelLayouts_60x40_v55'] = $merged;
            }
        }
        // 2. 自定义模板保护：服务器已有模板，而新提交为空时，保留服务器模板
        $exTpl = $existing['labelCustomTemplates'] ?? null;
        if (is_array($exTpl) && count($exTpl) > 0) {
            $newTpl = $clean['labelCustomTemplates'] ?? null;
            if (!is_array($newTpl) || count($newTpl) === 0) {
                $clean['labelCustomTemplates'] = $exTpl;
            }
        }
        // 3. 内容修改保护：同上
        $exOv = $existing['labelOverrides_v1'] ?? null;
        if (is_array($exOv) && count($exOv) > 0) {
            $newOv = $clean['labelOverrides_v1'] ?? null;
            if (!is_array($newOv) || count($newOv) === 0) {
                $clean['labelOverrides_v1'] = $exOv;
            }
        }
        // 4. 版本历史保护：服务器已有版本历史，而新提交为空/更少时，保留服务器版本历史
        $exVh = $existing['labelVersionHistory_v1'] ?? null;
        if (is_array($exVh) && is_array($exVh['versions'] ?? null) && count($exVh['versions']) > 0) {
            $exVerCount = count($exVh['versions']);
            $newVh = $clean['labelVersionHistory_v1'] ?? null;
            $newVerCount = (is_array($newVh) && is_array($newVh['versions'] ?? null)) ? count($newVh['versions']) : 0;
            if ($newVerCount < $exVerCount) {
                $clean['labelVersionHistory_v1'] = $exVh;
            }
        }
    }

    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = @file_put_contents($store_file, $json, LOCK_EX);
    if ($ok === false) {
        echo json_encode(['code' => 500, 'msg' => 'write failed']);
        exit;
    }
    echo json_encode(['code' => 200, 'msg' => 'saved']);
    exit;
}

echo json_encode(['code' => 400, 'msg' => 'unknown action']);
