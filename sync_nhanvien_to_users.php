<?php
// sync_nhanvien_to_users.php
// Đồng bộ thông tin từ bảng `nhanvien` vào `users` và cập nhật nhanvien.users_id -> users.id khi có thể.
// Chạy 1 lần từ CLI hoặc trình duyệt (dev). Không tự động tạo tài khoản user mới.
header('Content-Type: application/json; charset=utf-8');

$dbNames = ['quanlytiendo','quanlytiendo_db','quanlytiendo_copy'];
$pdo = null; $lastEx = null; $usedDb = null;
foreach ($dbNames as $dbName) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $usedDb = $dbName; break;
    } catch (PDOException $e) { $lastEx = $e; }
}

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Không thể kết nối DB: '.($lastEx? $lastEx->getMessage(): '')], JSON_UNESCAPED_UNICODE);
    exit;
}

$report = ['updated_users'=>[], 'linked_nhanvien'=>[], 'skipped_nhanvien'=>[]];
try {
    // Load all nhanvien
    $nvRows = $pdo->query('SELECT * FROM nhanvien')->fetchAll();

    // Prepare user lookup by normalized name and (if exists) by email
    $uByName = $pdo->query("SELECT id, email, name, manv FROM users")->fetchAll();
    $mapByName = [];
    $mapByEmail = [];
    foreach ($uByName as $u) {
        $n = isset($u['name']) ? strtolower(trim($u['name'])) : null;
        if ($n) $mapByName[$n] = $u;
        $em = isset($u['email']) ? strtolower(trim($u['email'])) : null;
        if ($em) $mapByEmail[$em] = $u;
    }

    $updUser = $pdo->prepare('UPDATE users SET manv = ?, sdt = ?, chucvu = ? WHERE id = ?');
    $updNhanvienUsersId = $pdo->prepare('UPDATE nhanvien SET users_id = ? WHERE Manv = ?');

    foreach ($nvRows as $nv) {
        $ten = isset($nv['Tennv']) ? trim($nv['Tennv']) : '';
        $manv = isset($nv['Manv']) ? trim($nv['Manv']) : '';
        $sdt = isset($nv['SDT']) ? trim($nv['SDT']) : null;
        $chucvu = isset($nv['Chucvu']) ? trim($nv['Chucvu']) : null;
        $nvEmail = null;
        // if nhanvien has an email-like column, use it
        foreach (['email','Email'] as $ec) if (isset($nv[$ec]) && strlen(trim($nv[$ec]))>0) { $nvEmail = strtolower(trim($nv[$ec])); break; }

        $foundUser = null;
        if ($ten) {
            $key = strtolower($ten);
            if (isset($mapByName[$key])) $foundUser = $mapByName[$key];
        }
        if (!$foundUser && $nvEmail && isset($mapByEmail[$nvEmail])) $foundUser = $mapByEmail[$nvEmail];

        if ($foundUser) {
            // update users with manv/sdt/chucvu
            $updUser->execute([$manv, $sdt, $chucvu, $foundUser['id']]);
            $report['updated_users'][] = ['user_id'=>$foundUser['id'],'manv'=>$manv];
            // update nhanvien.users_id to point to the user id if not set
            if (empty($nv['users_id']) || $nv['users_id'] == 0) {
                $updNhanvienUsersId->execute([$foundUser['id'], $manv]);
                if ($updNhanvienUsersId->rowCount()>0) $report['linked_nhanvien'][] = ['Manv'=>$manv,'users_id'=>$foundUser['id']];
            }
        } else {
            $report['skipped_nhanvien'][] = ['Manv'=>$manv,'Tennv'=>$ten,'email'=>$nvEmail];
        }
    }

    echo json_encode(['success'=>true,'db'=>$usedDb,'report'=>$report], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
