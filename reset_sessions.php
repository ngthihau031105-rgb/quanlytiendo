<?php
// reset_sessions.php
// DEV-only: xoá tất cả file session PHP trên server để force logout mọi người dùng.
// Chỉ dùng trên môi trường phát triển.
header('Content-Type: application/json; charset=utf-8');

// Safety: prevent accidental run on production by checking host
if (php_sapi_name() !== 'cli') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    // allow localhost only
    if (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Chỉ cho phép chạy trên localhost (dev).']);
        exit;
    }
}

$savePath = ini_get('session.save_path');
if (!$savePath) $savePath = sys_get_temp_dir();
$deleted = 0; $errors = [];
if (is_dir($savePath)) {
    $files = scandir($savePath);
    foreach ($files as $f) {
        if (!$f) continue;
        // typical session files are named sess_<id>
        if (strpos($f, 'sess_') === 0) {
            $path = $savePath . DIRECTORY_SEPARATOR . $f;
            try {
                @unlink($path);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
    echo json_encode(['success'=>true,'deleted'=>$deleted,'session_path'=>$savePath,'errors'=>$errors], JSON_UNESCAPED_UNICODE);
    exit;
} else {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Không tìm thấy session.save_path: ' . $savePath], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
