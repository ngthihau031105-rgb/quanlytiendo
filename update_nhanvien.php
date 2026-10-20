<?php
header('Content-Type: application/json; charset=utf-8');
// require manager role to update nhân viên
require_once __DIR__ . '/auth.php';
auth_require_role('manager');
if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', 'POST') !== 0) { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }
$dbNames = ['quanlytiendo','quanlytiendo_db','quanlytiendo_copy']; $pdo=null;$usedDb=null;$lastEx=null;
foreach($dbNames as $db){ try{ $pdo=new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $usedDb=$db; break;}catch(PDOException $e){ $lastEx=$e; }}
if(!$pdo){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'Kết nối DB thất bại']); exit; }

$id = $_POST['id'] ?? null; $manv = $_POST['manv'] ?? null; $name = $_POST['name'] ?? null; $position = $_POST['position'] ?? null; $phone = $_POST['phone'] ?? null;
if (!$id && !$manv) { echo json_encode(['success'=>false,'error'=>'Thiếu id hoặc MANV để cập nhật']); exit; }

$tbl='nhanvien'; $tcheck = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?'); $tcheck->execute([$usedDb,$tbl]); if(!$tcheck->fetch()){ echo json_encode(['success'=>false,'error'=>'Bảng nhanvien không tồn tại']); exit; }

// build update using available columns
$colStmt = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
$colStmt->execute([$usedDb,$tbl]);
$colsInfo = $colStmt->fetchAll(PDO::FETCH_ASSOC);
$colsRaw = array_column($colsInfo, 'COLUMN_NAME');
$colsMap = [];
foreach ($colsRaw as $c) { $colsMap[strtolower($c)] = $c; }

// Determine primary key column (if any)
$pkCol = null;
foreach ($colsInfo as $ci) {
	if (!empty($ci['COLUMN_KEY']) && strtoupper($ci['COLUMN_KEY']) === 'PRI') { $pkCol = $ci['COLUMN_NAME']; break; }
}
if (!$pkCol) {
	if (isset($colsMap['id'])) $pkCol = $colsMap['id'];
	elseif (isset($colsMap['manv'])) $pkCol = $colsMap['manv'];
}

// Determine MANV-like column name
$manvCol = null;
foreach (['manv','ma_nv','ma'] as $cand) { if (isset($colsMap[$cand])) { $manvCol = $colsMap[$cand]; break; } }

// Locate the existing row to update (by PK if possible, otherwise by MANV)
$foundRow = null;
if ($pkCol && $id) {
	$sel = $pdo->prepare("SELECT * FROM `{$tbl}` WHERE `{$pkCol}` = ? LIMIT 1");
	$sel->execute([$id]);
	$foundRow = $sel->fetch();
}
if (!$foundRow && $manv && $manvCol) {
	$sel = $pdo->prepare("SELECT * FROM `{$tbl}` WHERE `{$manvCol}` = ? LIMIT 1");
	$sel->execute([$manv]);
	$foundRow = $sel->fetch();
}
if (!$foundRow && $id && $manvCol) {
	// maybe client sent MANV in id
	$sel = $pdo->prepare("SELECT * FROM `{$tbl}` WHERE `{$manvCol}` = ? LIMIT 1");
	$sel->execute([$id]);
	$foundRow = $sel->fetch();
}

if (!$foundRow) { echo json_encode(['success'=>false,'error'=>'Không tìm thấy bản ghi để cập nhật']); exit; }

// Determine target PK value and target MANV value
$targetPkVal = null; $targetManvVal = null;
if ($pkCol && isset($foundRow[$pkCol])) $targetPkVal = $foundRow[$pkCol];
if ($manvCol && isset($foundRow[$manvCol])) $targetManvVal = $foundRow[$manvCol];

// Prevent duplicate MANV in other rows (allow same MANV for the current row)
if ($manv !== null && $manvCol) {
	if ($targetPkVal !== null && $pkCol) {
		$dupSql = "SELECT 1 FROM `{$tbl}` WHERE `{$manvCol}` = ? AND `{$pkCol}` <> ? LIMIT 1";
		$dupStmt = $pdo->prepare($dupSql);
		$dupStmt->execute([$manv, $targetPkVal]);
	} else {
		$dupSql = "SELECT 1 FROM `{$tbl}` WHERE `{$manvCol}` = ? AND `{$manvCol}` <> ? LIMIT 1";
		$dupStmt = $pdo->prepare($dupSql);
		$dupStmt->execute([$manv, $targetManvVal]);
	}
	if ($dupStmt->fetch()) { echo json_encode(['success'=>false,'error'=>'Mã nhân viên đã tồn tại']); exit; }
}

$updateParts = []; $params = [];
if ($manv !== null && isset($colsMap['manv'])) { $col = $colsMap['manv']; $updateParts[] = "`{$col}` = :manv"; $params[':manv'] = $manv; }
if ($name !== null) {
	if (isset($colsMap['tennv'])) $colName = $colsMap['tennv'];
	elseif (isset($colsMap['name'])) $colName = $colsMap['name'];
	else $colName = null;
	if ($colName) { $updateParts[] = "`{$colName}` = :name"; $params[':name'] = $name; }
}
if ($position !== null) {
	if (isset($colsMap['chucvu'])) $colPos = $colsMap['chucvu'];
	elseif (isset($colsMap['position'])) $colPos = $colsMap['position'];
	else $colPos = null;
	if ($colPos) { $updateParts[] = "`{$colPos}` = :position"; $params[':position'] = $position; }
}
if ($phone !== null) {
	if (isset($colsMap['sdt'])) $colPhone = $colsMap['sdt'];
	elseif (isset($colsMap['phone'])) $colPhone = $colsMap['phone'];
	else $colPhone = null;
	if ($colPhone) { $updateParts[] = "`{$colPhone}` = :phone"; $params[':phone'] = $phone; }
}

if (empty($updateParts)) { echo json_encode(['success'=>false,'error'=>'Không có trường để cập nhật']); exit; }

// Build WHERE based on the located target primary key or MANV value
$where = '';
if ($pkCol && $targetPkVal !== null) {
	$where = "`{$pkCol}` = :target_pk";
	$params[':target_pk'] = $targetPkVal;
} elseif ($manvCol && $targetManvVal !== null) {
	$where = "`{$manvCol}` = :target_manv";
	$params[':target_manv'] = $targetManvVal;
} else {
	echo json_encode(['success'=>false,'error'=>'Thiếu khóa để xác định bản ghi cần cập nhật']); exit;
}

$sql = "UPDATE `{$tbl}` SET ".implode(', ', $updateParts)." WHERE {$where} LIMIT 1";
$stmt = $pdo->prepare($sql);
try { $stmt->execute($params); echo json_encode(['success'=>true,'message'=>'Sửa thông tin nhân viên thành công']); exit; } catch(Exception $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'Lỗi khi cập nhật: '.$e->getMessage()]); exit; }

?>
