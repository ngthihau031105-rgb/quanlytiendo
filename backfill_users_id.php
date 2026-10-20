<?php
// backfill_users_id.php
// Script chạy 1 lần để điền cột `users_id` trong `nhanvien` bằng cách khớp tên với bảng `users`.
// Chạy từ trình duyệt (dev) hoặc CLI: php backfill_users_id.php
header('Content-Type: application/json; charset=utf-8');

$dbNames = ['quanlytiendo','quanlytiendo_db'];
$pdo = null; $lastEx = null; $usedDb = null;
foreach ($dbNames as $dbName) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $usedDb = $dbName; break;
    } catch (PDOException $e) { $lastEx = $e; }
}

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Không thể kết nối DB: '.($lastEx ? $lastEx->getMessage() : '')], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Kiểm tra cột users_id tồn tại?
    $colChk = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $colChk->execute([$usedDb, 'nhanvien', 'users_id']);
    if (!$colChk->fetch()) {
        echo json_encode(['success'=>false,'error'=>'Bảng `nhanvien` chưa có cột `users_id`. Vui lòng chạy migration trước.','db'=>$usedDb], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lấy tất cả các bản ghi cần fill (users_id IS NULL)
    $rows = $pdo->query('SELECT * FROM nhanvien WHERE users_id IS NULL OR users_id = 0')->fetchAll();
    $updated = [];
    $findUser = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    $findByEmail = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
    $upd = $pdo->prepare('UPDATE nhanvien SET users_id = ? WHERE Manv = ?');

    foreach ($rows as $r) {
        $ten = isset($r['Tennv']) ? $r['Tennv'] : null;
        $manv = isset($r['Manv']) ? $r['Manv'] : null;
        $found = null;
        if ($ten) {
            $findUser->execute([$ten]);
            $f = $findUser->fetch();
            if ($f) $found = $f['id'];
        }
        // Nếu chưa tìm theo tên, thử khớp email nếu có cột email trong nhanvien
        if (!$found && isset($r['email'])) {
            $findByEmail->execute([$r['email']]);
            $f2 = $findByEmail->fetch();
            if ($f2) $found = $f2['id'];
        }

        if ($found && $manv) {
            $upd->execute([$found, $manv]);
            if ($upd->rowCount() > 0) $updated[] = ['Manv'=>$manv,'users_id'=>$found];
        }
    }

    echo json_encode(['success'=>true,'db'=>$usedDb,'updated'=>$updated,'attempted'=>count($rows)], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
