<?php
header('Content-Type: application/json; charset=utf-8');
// require manager role to view employee list
require_once __DIR__ . '/auth.php';
auth_require_role('manager');
// List nhanvien with search and pagination
$dbNames = ['quanlytiendo','quanlytiendo_db'];
$pdo = null; $lastEx = null; $usedDb = null;
foreach ($dbNames as $dbName) {
    try { $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]); $usedDb=$dbName; break; } catch (PDOException $e){ $lastEx=$e; }
}
if (!$pdo) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'Kết nối DB thất bại']); exit; }

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$pageSize = isset($_GET['pageSize']) ? max(1,(int)$_GET['pageSize']) : 10;
$offset = ($page-1)*$pageSize;

// Find table name (nhanvien expected)
$tbl = 'nhanvien';
$tcheck = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
$tcheck->execute([$usedDb,$tbl]);
if (!$tcheck->fetch()) { echo json_encode(['success'=>false,'error'=>'Bảng nhanvien không tồn tại']); exit; }

// find columns for search mapping
$colsStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
$colsStmt->execute([$usedDb,$tbl]);
$cols = array_map('strtolower', $colsStmt->fetchAll(PDO::FETCH_COLUMN));
$col_manv = in_array('manv',$cols)?'MANV':(in_array('ma_nv',$cols)?'ma_nv':(in_array('ma',$cols)?'ma':null));
$col_name = in_array('tennv',$cols)?'TENNV':(in_array('name',$cols)?'name':null);

$params = [];
$where = [];
if ($q !== ''){
    if ($col_manv && $col_name){
        $where[] = "( {$col_manv} LIKE :q OR {$col_name} LIKE :q2 )";
        $params[':q'] = "%{$q}%"; $params[':q2'] = "%{$q}%";
    } elseif ($col_name){ $where[] = "({$col_name} LIKE :q)"; $params[':q'] = "%{$q}%"; }
    elseif ($col_manv){ $where[] = "({$col_manv} LIKE :q)"; $params[':q'] = "%{$q}%"; }
}

$whereSql = $where?('WHERE '.implode(' AND ',$where)) : '';

$totalStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM {$tbl} {$whereSql}");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$dataStmt = $pdo->prepare("SELECT * FROM {$tbl} {$whereSql} LIMIT :limit OFFSET :offset");
foreach ($params as $k=>$v) $dataStmt->bindValue($k,$v);
$dataStmt->bindValue(':limit',$pageSize,PDO::PARAM_INT);
$dataStmt->bindValue(':offset',$offset,PDO::PARAM_INT);
$dataStmt->execute();
$rows = $dataStmt->fetchAll();

echo json_encode(['success'=>true,'total'=>$total,'page'=>$page,'pageSize'=>$pageSize,'data'=>$rows]);
exit;

?>
