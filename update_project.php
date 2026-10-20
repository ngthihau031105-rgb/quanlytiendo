<?php
// update_project.php
// Updates a project (either `congtrinh` with MACT or `projects` by id)
header('Content-Type: application/json; charset=utf-8');
session_start();
// allow any logged-in user to update projects (employees may edit)
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
// Only managers may update projects
auth_require_role('manager');

function _log($t){ @file_put_contents(__DIR__.'/save_project.log', "[".date('Y-m-d H:i:s')."] UPDATE: ".$t."\n", FILE_APPEND|LOCK_EX); }

if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', 'POST') !== 0) {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Method not allowed, POST required']);
    exit;
}

$input = $_POST;
$id = isset($input['id']) ? trim($input['id']) : '';
if ($id === '') { echo json_encode(['success'=>false,'error'=>'Missing id']); exit; }

$name = isset($input['name']) ? trim($input['name']) : null;
$location = isset($input['location']) ? trim($input['location']) : null;
$budget = isset($input['budget']) && $input['budget'] !== '' ? (float)$input['budget'] : null;
$start_date = isset($input['start_date']) && $input['start_date'] !== '' ? $input['start_date'] : null;
$end_date = isset($input['end_date']) && $input['end_date'] !== '' ? $input['end_date'] : null;
$description = isset($input['description']) ? trim($input['description']) : null;
$status = isset($input['status']) ? trim($input['status']) : null;

$dbNames = ['quanlytiendo'];
$pdo = null; $lastEx = null; $usedDb = null;
foreach ($dbNames as $dbName) {
    try { $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $usedDb=$dbName; break; } catch (PDOException $e) { $lastEx = $e; $pdo = null; }
}
if (!$pdo) { http_response_code(500); $msg=''; if($lastEx instanceof Exception) $msg=$lastEx->getMessage(); echo json_encode(['success'=>false,'error'=>'DB connect failed: '. $msg]); exit; }

try {
    $tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $tableCheck->execute([$usedDb, 'congtrinh']);
    $has = (bool)$tableCheck->fetchColumn();

    if ($has) {
        // update congtrinh by MACT
        $fields = [];
        $params = [];
        if ($name !== null) { $fields[] = 'TENCT = :name'; $params[':name']=$name; }
        if ($location !== null) { $fields[] = 'DIADIEM = :location'; $params[':location']=$location; }
        if ($budget !== null) { $fields[] = 'NGANSACH = :budget'; $params[':budget']=$budget; }
        if ($description !== null) { $fields[] = 'MOTA = :description'; $params[':description']=$description; }
        if ($start_date !== null) { $fields[] = 'NGAYBD = :start_date'; $params[':start_date']=$start_date; }
        if ($end_date !== null) { $fields[] = 'NGAYKT = :end_date'; $params[':end_date']=$end_date; }
        // if client passes status, try to persist it in congtrinh if a matching column exists
        if ($status !== null && $status !== '') {
            // check for common column names
            $colStmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name IN ("TRANGTHAI","trangthai","status") LIMIT 1');
            $colStmt->execute([$usedDb, 'congtrinh']);
            $col = $colStmt->fetchColumn();
            if ($col) {
                $fields[] = "$col = :status";
                $params[':status'] = $status;
            } else {
                // no status column found; do not persist but allow client to continue
                _log('congtrinh has no status column; cannot persist status for MACT='.$id);
            }
        }
        if (empty($fields)) { echo json_encode(['success'=>false,'error'=>'No fields to update']); exit; }
        $params[':mact'] = $id;
        $sql = 'UPDATE congtrinh SET '.implode(', ', $fields).' WHERE MACT = :mact';
        _log('EXEC: '.$sql.' params: '.json_encode($params, JSON_UNESCAPED_UNICODE));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        // fetch updated row
        $sel = $pdo->prepare('SELECT MACT as id, TENCT as name, DIADIEM as location, NGANSACH as budget, MOTA as description, NGAYBD as start_date, NGAYKT as end_date'.(isset($params[':status'])?(', '.$col.' as status'):('')).' FROM congtrinh WHERE MACT = ? LIMIT 1');
        $sel->execute([$id]);
        $row = $sel->fetch();
        echo json_encode(['success'=>true,'message'=>'Chỉnh sửa công trình thành công','data'=>$row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // fallback to projects table (numeric id expected)
    $fields = [];
    $params = [];
    if ($name !== null) { $fields[] = 'name = :name'; $params[':name']=$name; }
    if ($description !== null) { $fields[] = 'description = :description'; $params[':description']=$description; }
    if ($start_date !== null) { $fields[] = 'start_date = :start_date'; $params[':start_date']=$start_date; }
    if ($end_date !== null) { $fields[] = 'end_date = :end_date'; $params[':end_date']=$end_date; }
    if ($status !== null) { $fields[] = 'status = :status'; $params[':status']=$status; }
    if (empty($fields)) { echo json_encode(['success'=>false,'error'=>'No fields to update']); exit; }
    $params[':id'] = $id;
    $sql = 'UPDATE projects SET '.implode(', ', $fields).' WHERE id = :id';
    _log('EXEC: '.$sql.' params: '.json_encode($params, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sel = $pdo->prepare('SELECT id, name, description, start_date, end_date FROM projects WHERE id = ? LIMIT 1');
    $sel->execute([$id]);
    $row = $sel->fetch();
    echo json_encode(['success'=>true,'message'=>'Chỉnh sửa công trình thành công','data'=>$row], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    _log('error: '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>'Update failed: '.$e->getMessage()]);
    exit;
}

?>
