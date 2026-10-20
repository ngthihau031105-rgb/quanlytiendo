<?php
// create_tranbich_user.php
// One-off script to insert a new employee user + nhanvien record for Tran Thi Bich
// Run by visiting this URL once: http://localhost/quanlytiendo/create_tranbich_user.php
header('Content-Type: text/plain; charset=utf-8');

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
    echo "DB connect failed: " . ($lastEx ? $lastEx->getMessage() : 'unknown') . "\n";
    exit;
}

$email = 'tranbich12@gmail.com';
$password = '12346789';
$name = 'Trần Thị Bích';
$role = 'employee';
$manv = 'NV002';

try {
    // normalize email
    $email = trim($email);
    // check existing user by email
    $chk = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
    $chk->execute([$email]);
    if ($chk->fetch()) {
        echo "User with email {$email} already exists.\n";
    } else {
        $pwHash = hash('sha256', $password);
        $ins = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, created_at) VALUES (:email, :pw, :name, :role, NOW())');
        $ins->execute([':email'=>$email, ':pw'=>$pwHash, ':name'=>$name, ':role'=>$role]);
        $uid = $pdo->lastInsertId();
        echo "Inserted user id={$uid} email={$email}\n";
    }

    // Insert into nhanvien table if exists or create it
    $tblStmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $tblStmt->execute([$usedDb, 'nhanvien']);
    $hasNh = (bool)$tblStmt->fetchColumn();
    if (!$hasNh) {
        // create basic nhanvien table
        $create = "CREATE TABLE IF NOT EXISTS nhanvien (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            MANV VARCHAR(100) DEFAULT NULL,
            TENNV VARCHAR(255) DEFAULT NULL,
            CHUCVU VARCHAR(255) DEFAULT NULL,
            SDT VARCHAR(32) DEFAULT NULL,
            role VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($create);
        echo "Created table nhanvien\n";
    }
    // check duplicate MANV
    $chk2 = $pdo->prepare('SELECT id FROM nhanvien WHERE MANV = ? LIMIT 1');
    $chk2->execute([$manv]);
    if ($chk2->fetch()) {
        echo "Nhanvien with MANV {$manv} already exists.\n";
    } else {
        $ins2 = $pdo->prepare('INSERT INTO nhanvien (MANV, TENNV, CHUCVU, SDT, role, created_at) VALUES (:manv, :ten, :cv, :sdt, :role, NOW())');
        $ins2->execute([':manv'=>$manv, ':ten'=>$name, ':cv'=>'Nhân viên', ':sdt'=>'', ':role'=>$role]);
        $nid = $pdo->lastInsertId();
        echo "Inserted nhanvien id={$nid} MANV={$manv}\n";
    }

    echo "Done. You can now login with email={$email} and password={$password}\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>