<?php
// seed_users.php
// Chèn / cập nhật 4 tài khoản nhân viên vào bảng `users`.
header('Content-Type: application/json; charset=utf-8');
// Không cần session; script này chỉ chèn dữ liệu idempotent

$accounts = [
    ['email' => 'hangcan18@gmail.com', 'password' => 'abcxyzlmn', 'name' => 'Minh Hang'],
    ['email' => 'hanh2005@gmail.com', 'password' => '12345678', 'name' => 'My Hanh'],
    ['email' => 'myhanh@gmail.com', 'password' => 'hanh2005', 'name' => 'Hanh Nguyen'],
    ['email' => 'behanh06205@gmail.com', 'password' => '123321', 'name' => 'Thuy Hanh'],
];

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
    echo json_encode(['success'=>false,'error'=>'Không thể kết nối cơ sở dữ liệu: '.($lastEx ? $lastEx->getMessage() : '')], JSON_UNESCAPED_UNICODE);
    exit;
}

$results = [];
try {
    foreach ($accounts as $a) {
        // normalize to trimmed lowercase to match existing DB entries robustly
        $email = strtolower(trim($a['email']));
        $name = $a['name'];
        $pwd = $a['password'];

        // match by normalized email (lowercase + trimmed)
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        $hash = hash('sha256', $pwd);

        if ($row) {
            // cập nhật email (normalized), password/name/role
            $u = $pdo->prepare('UPDATE users SET email = ?, password_hash = ?, name = ?, role = ? WHERE id = ?');
            $u->execute([$email, $hash, $name, 'employee', $row['id']]);
            $results[] = ['email'=>$email,'status'=>'updated','id'=>$row['id'],'role'=>'employee'];
        } else {
            $i = $pdo->prepare('INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)');
            $i->execute([$email, $hash, $name, 'employee']);
            $results[] = ['email'=>$email,'status'=>'inserted','id'=>$pdo->lastInsertId(),'role'=>'employee'];
        }
    }

    echo json_encode(['success'=>true,'db'=>$usedDb,'results'=>$results], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Lỗi khi chèn/cập nhật: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
