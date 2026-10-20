<?php
header('Content-Type: application/json; charset=utf-8');
$dbNames = ['quanlytiendo','quanlytiendo_db']; $pdo=null;$usedDb=null;$lastEx=null;
foreach($dbNames as $db){ try{ $pdo=new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $usedDb=$db; break;}catch(PDOException $e){ $lastEx=$e; }}
if(!$pdo){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'Kết nối DB thất bại']); exit; }

$id = isset($_GET['id'])? trim($_GET['id']):null; // id or MANV
if(!$id){ echo json_encode(['success'=>false,'error'=>'Thiếu id']); exit; }

$tbl='nhanvien'; $tcheck = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
$tcheck->execute([$usedDb,$tbl]); if(!$tcheck->fetch()){ echo json_encode(['success'=>false,'error'=>'Bảng nhanvien không tồn tại']); exit; }

// prefer id column if exists
$cols = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?'); $cols->execute([$usedDb,$tbl]); $colsA = array_map('strtolower',$cols->fetchAll(PDO::FETCH_COLUMN));
if(in_array('id',$colsA)){
    $stmt = $pdo->prepare('SELECT * FROM nhanvien WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
} else {
    // assume MANV
    $stmt = $pdo->prepare('SELECT * FROM nhanvien WHERE MANV = ? LIMIT 1');
    $stmt->execute([$id]);
}
$row = $stmt->fetch();
if(!$row) echo json_encode(['success'=>false,'error'=>'Không tìm thấy nhân viên']); else echo json_encode(['success'=>true,'data'=>$row]);
exit;
?>
