<?php
// reset_roles.php
// Reset roles: set all users to 'employee' then promote configured manager emails to 'manager'.
header('Content-Type: application/json; charset=utf-8');

$managers = [
    // list manager emails here (normalized)
    'nguyenhau03112005@gmail.com'
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
    echo json_encode(['success'=>false,'error'=>'Cannot connect DB: '.($lastEx ? $lastEx->getMessage() : '')], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // set all users to employee
    $pdo->beginTransaction();
    $pdo->exec("UPDATE users SET role = 'employee'");

    $promoted = [];
    $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))');
    foreach ($managers as $m) {
        $mNorm = strtolower(trim($m));
        $stmt->execute(['manager', $mNorm]);
        if ($stmt->rowCount() > 0) $promoted[] = $mNorm;
    }
    $pdo->commit();
    echo json_encode(['success'=>true,'db'=>$usedDb,'promoted'=>$promoted], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
