<?php
// add_employee.php
// Accepts POST: manv, name, position, phone, role
header('Content-Type: application/json; charset=utf-8');
// Ghi log đơn giản để debug
$logFile = __DIR__ . '/add_employee.log';
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Received POST: " . json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
// require manager role to add employees
require_once __DIR__ . '/auth.php';
auth_require_role('manager');
if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', 'POST') !== 0) {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Method not allowed']);
    exit;
}

$manv = isset($_POST['manv']) ? trim($_POST['manv']) : '';
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$position = isset($_POST['position']) ? trim($_POST['position']) : '';
$phone = isset($_POST['phone']) ? preg_replace('/[^0-9]/','',trim($_POST['phone'])) : '';
$role = isset($_POST['role']) ? trim($_POST['role']) : 'employee';

if ($manv === '' || $name === '' || $position === '' || $phone === ''){
    echo json_encode(['success'=>false,'error'=>'Vui lòng điền đầy đủ Mã NV, Họ tên, Chức vụ và Số điện thoại']);
    exit;
}

// connect to DB (try common names)
$dbNames = ['quanlytiendo','quanlytiendo_db'];
$pdo = null; $lastEx = null; $usedDb = null;
foreach ($dbNames as $dbName) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        $usedDb = $dbName; break;
    } catch (PDOException $e) { $lastEx = $e; $pdo = null; }
}
if (!$pdo){ http_response_code(500); $msg=''; if($lastEx instanceof Exception) $msg=$lastEx->getMessage(); echo json_encode(['success'=>false,'error'=>'DB connect failed: '. $msg]); exit; }

try{
    // Tìm bảng nhanvien trước (không phân biệt hoa thường), nếu có dùng luôn; nếu không có, dùng employees hoặc tạo fallback
    $tblStmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ("nhanvien","employees")');
    $tblStmt->execute([$usedDb]);
    $foundRaw = $tblStmt->fetchAll(PDO::FETCH_COLUMN);
    $found = array_map('strtolower', $foundRaw);
    if (in_array('nhanvien', $found)) {
        // lấy tên thực tế (giữ case) nếu cần
        $idx = array_search('nhanvien', $found);
        $preferredTable = $foundRaw[$idx];
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Sử dụng bảng `{$preferredTable}` trong DB={$usedDb}\n", FILE_APPEND);
    } elseif (in_array('employees', $found)) {
        // If only legacy `employees` exists, create a `nhanvien` table and use it
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Tìm thấy bảng legacy `employees` nhưng sẽ tạo/ưu tiên `nhanvien` trong DB={$usedDb}\n", FILE_APPEND);
        $createSql = "CREATE TABLE IF NOT EXISTS nhanvien (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            MANV VARCHAR(100) DEFAULT NULL,
            TENNV VARCHAR(255) DEFAULT NULL,
            CHUCVU VARCHAR(255) DEFAULT NULL,
            SDT VARCHAR(32) DEFAULT NULL,
            role VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($createSql);
        $preferredTable = 'nhanvien';
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Đã tạo/ưu tiên bảng `nhanvien` trong DB={$usedDb}\n", FILE_APPEND);
    } else {
        // tạo bảng nhanvien mặc định (theo yêu cầu của bạn lưu vào nhanvien)
        $createSql = "CREATE TABLE nhanvien (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            MANV VARCHAR(100) DEFAULT NULL,
            TENNV VARCHAR(255) DEFAULT NULL,
            CHUCVU VARCHAR(255) DEFAULT NULL,
            SDT VARCHAR(32) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($createSql);
        $preferredTable = 'nhanvien';
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Tạo bảng `nhanvien` mới trong DB={$usedDb}\n", FILE_APPEND);
    }

    // Lấy danh sách cột của bảng đích, map không phân biệt hoa thường
    $colStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $colStmt->execute([$usedDb, $preferredTable]);
    $colsRaw = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    $colsLowerMap = [];
    foreach ($colsRaw as $c) { $colsLowerMap[strtolower($c)] = $c; }

    // Helper: trả về tên cột thực tế nếu tìm thấy (không phân biệt hoa thường)
    $findCol = function(array $candidates) use ($colsLowerMap) {
        foreach ($candidates as $c) {
            $k = strtolower($c);
            if (isset($colsLowerMap[$k])) return $colsLowerMap[$k];
        }
        return null;
    };

    $col_manv = $findCol(['MANV','manv','ma_nv','maNV','mNV','code','ma','manhanvien']);
    $col_name = $findCol(['TENNV','tennv','name','hoten','ho_ten','ten']);
    $col_position = $findCol(['CHUCVU','chucvu','chuc_vu','position','title']);
    $col_phone = $findCol(['SDT','sdt','phone','mobile','phone_number','so_dien_thoai']);
    $col_role = $findCol(['role','vaitro','role_id']);

    // Kiểm tra trùng lặp trên nhanvien ưu tiên theo mã
    if ($col_manv) {
        $chk = $pdo->prepare("SELECT 1 FROM {$preferredTable} WHERE {$col_manv} = ? LIMIT 1");
        $chk->execute([$manv]);
        if ($chk->fetch()) { echo json_encode(['success'=>false,'error'=>'Mã nhân viên đã tồn tại']); exit; }
    } elseif ($col_name) {
        $chk2 = $pdo->prepare("SELECT 1 FROM {$preferredTable} WHERE {$col_name} = ? LIMIT 1");
        $chk2->execute([$name]);
        if ($chk2->fetch()) { echo json_encode(['success'=>false,'error'=>'Họ tên nhân viên đã tồn tại']); exit; }
    }

    // Build insert dynamically using only available columns
    $insertCols = [];
    $insertParams = [];
    if ($col_manv) { $insertCols[] = $col_manv; $insertParams[':manv'] = $manv; }
    if ($col_name) { $insertCols[] = $col_name; $insertParams[':name'] = $name; }
    if ($col_position) { $insertCols[] = $col_position; $insertParams[':position'] = $position; }
    if ($col_phone) { $insertCols[] = $col_phone; $insertParams[':phone'] = $phone; }
    if ($col_role) { $insertCols[] = $col_role; $insertParams[':role'] = $role; }

    if (empty($insertCols)) {
        throw new Exception('No suitable columns found in target table to insert employee data');
    }

    $colList = implode(', ', $insertCols);
    $placeList = implode(', ', array_keys($insertParams));

    $insSql = "INSERT INTO {$preferredTable} ({$colList}) VALUES ({$placeList})";
    $ins = $pdo->prepare($insSql);
    $ins->execute($insertParams);
    $id = (int)$pdo->lastInsertId();
    file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Inserted into {$preferredTable} id={$id} in DB={$usedDb}\n", FILE_APPEND);

    echo json_encode(['success'=>true,'message'=>'Thêm mới nhân viên thành công','id'=>$id,'table'=>$preferredTable]);
    exit;

} catch (Exception $e){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'Insert failed: '.$e->getMessage()]); exit; }

?>
