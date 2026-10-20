<?php
header('Content-Type: application/json; charset=utf-8');

$logFile = __DIR__ . '/delete_nhanvien.log';
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Received POST: " . json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

// Require manager role
require_once __DIR__ . '/auth.php';
auth_require_role('manager');

if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', 'POST') !== 0) {
	http_response_code(405);
	echo json_encode(['success'=>false,'error'=>'Method not allowed']);
	exit;
}

$dbNames = ['quanlytiendo','quanlytiendo_db'];
$pdo = null; $usedDb = null; $lastEx = null;
foreach ($dbNames as $db) {
	try {
		$pdo = new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
		$usedDb = $db; break;
	} catch (PDOException $e) { $lastEx = $e; $pdo = null; }
}
if (!$pdo) {
	http_response_code(500);
	$msg = 'Kết nối DB thất bại: ' . ($lastEx->getMessage() ?? '');
	file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] ERROR: {$msg}\n", FILE_APPEND);
	echo json_encode(['success'=>false,'error'=>$msg]); exit;
}

try {
	$id = isset($_POST['id']) ? trim((string)$_POST['id']) : null;
	$manv = isset($_POST['manv']) ? trim((string)$_POST['manv']) : null;
	// If client sent the personnel code in the `id` field (e.g. NV0016),
	// treat it as manv when manv is not provided and id is non-numeric.
	if ($id && !is_numeric($id) && !$manv) {
		$manv = $id;
		$id = null; // clear numeric id path
	}
	if (!$id && !$manv) { echo json_encode(['success'=>false,'error'=>'Thiếu id hoặc MANV để xóa']); exit; }

	$tbl = 'nhanvien';
	$tcheck = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
	$tcheck->execute([$usedDb, $tbl]);
	if (!$tcheck->fetch()) { echo json_encode(['success'=>false,'error'=>'Bảng nhanvien không tồn tại']); exit; }

	$cols = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
	$cols->execute([$usedDb, $tbl]);
	$colsA = array_map('strtolower', $cols->fetchAll(PDO::FETCH_COLUMN));

	if (in_array('id', $colsA) && $id !== null && is_numeric($id)) {
		$stmt = $pdo->prepare('DELETE FROM `'.$tbl.'` WHERE `id` = ? LIMIT 1');
		$stmt->execute([(int)$id]);
		$affected = $stmt->rowCount();
	} else {
		// try to delete by MANV (case-insensitive column name detection)
		$manvCol = null;
		foreach ($colsA as $c) { if (in_array($c, ['manv','ma_nv','ma'])) { $manvCol = $c; break; } }
		if (!$manvCol && in_array('manv', $colsA)) $manvCol = 'manv';
		if ($manvCol) {
			$realCol = null;
			// find actual case-sensitive name
			$colStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
			$colStmt->execute([$usedDb, $tbl]);
			$allCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
			foreach ($allCols as $ac) { if (strtolower($ac) === $manvCol) { $realCol = $ac; break; } }
			if (!$realCol) $realCol = $manvCol;
			$delSql = "DELETE FROM `{$tbl}` WHERE `{$realCol}` = ? LIMIT 1";
			$stmt = $pdo->prepare($delSql);
			$stmt->execute([$manv]);
			$affected = $stmt->rowCount();
		} else {
			echo json_encode(['success'=>false,'error'=>'Không tìm thấy cột MANV để xóa']); exit;
		}
	}

	file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] DELETE affected={$affected}\n", FILE_APPEND);
	if (!empty($affected)) {
		// attempt to remove dependent references (cascade) — find FKs that reference nhanvien
		try {
			$fk = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?');
			$fk->execute([$usedDb, 'nhanvien']);
			$refs = $fk->fetchAll();
			foreach ($refs as $r) {
				$childTable = $r['TABLE_NAME']; $childCol = $r['COLUMN_NAME'];
				if (!$childTable || !$childCol) continue;
				// delete rows referencing this MANV or id
				if (!empty($id) && is_numeric($id)) {
					$del = $pdo->prepare("DELETE FROM `".str_replace('`','',$childTable)."` WHERE `".str_replace('`','',$childCol)."` = ?");
					$del->execute([(int)$id]);
				}
				if (!empty($manv)) {
					$del2 = $pdo->prepare("DELETE FROM `".str_replace('`','',$childTable)."` WHERE `".str_replace('`','',$childCol)."` = ?");
					$del2->execute([$manv]);
				}
			}
		} catch(Exception $e) {
			// log but continue
			file_put_contents($logFile, "[".date('c')."] FK delete error: " . $e->getMessage() . "\n", FILE_APPEND);
		}
		echo json_encode(['success'=>true,'message'=>'Xóa thông tin nhân viên thành công']);
	} else {
		echo json_encode(['success'=>false,'error'=>'Không tìm thấy bản ghi để xóa']);
	}
	exit;

} catch (Exception $e) {
	http_response_code(500);
	file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
	echo json_encode(['success'=>false,'error'=>'Lỗi khi xóa: '.$e->getMessage()]);
	exit;
}
?>
