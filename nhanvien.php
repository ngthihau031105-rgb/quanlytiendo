<?php
// add_employee.php
// Accepts POST: manv, name, position, phone, role
header('Content-Type: application/json; charset=utf-8');
// simple request logging for debugging
$logFile = __DIR__ . '/add_employee.log';
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Received POST: " . json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
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
    echo json_encode(['success'=>false,'error'=>'Missing required fields']);
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
    // Prefer existing table `nhanvien` if present; otherwise fall back to `employees` (legacy)
    $preferredTable = null;
    $tblStmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ("nhanvien","employees")');
    $tblStmt->execute([$usedDb]);
    $found = $tblStmt->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('nhanvien', $found)) {
        $preferredTable = 'nhanvien';
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Using existing table `nhanvien` in DB={$usedDb}\n", FILE_APPEND);
    } elseif (in_array('employees', $found)) {
        $preferredTable = 'employees';
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Using existing table `employees` in DB={$usedDb}\n", FILE_APPEND);
    } else {
        // create fallback employees table
        $pdo->exec("CREATE TABLE IF NOT EXISTS nhanvien (
            Manv VARCHAR(100) NOT NULL UNIQUE,
            Tennv VARCHAR(255) NOT NULL,
            Chucvu VARCHAR(255) DEFAULT NULL,
            SDT VARCHAR(32) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(Manv)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $preferredTable = 'employees';
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Created fallback table `nhanvien` in DB={$usedDb}\n", FILE_APPEND);
    }

    // Query available columns for the chosen table
    $colStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $colStmt->execute([$usedDb, $preferredTable]);
    $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

    // Helper to find the first matching column from a list of candidates
    $findCol = function(array $candidates) use ($cols) {
        foreach ($candidates as $c) {
            if (in_array($c, $cols)) return $c;
        }
        return null;
    };

    $col_manv = $findCol(['manv','ma_nv','maNV','mNV','code','ma','manhanvien','ma_nv']);
    $col_name = $findCol(['name','tennv','hoten','ho_ten','ten']);
    $col_position = $findCol(['position','chucvu','chuc_vu','chuc_vu','title','position']);
    $col_phone = $findCol(['phone','sdt','mobile','phone_number','so_dien_thoai']);
    $col_role = $findCol(['role','vaitro','role_id']);
    // Hỗ trợ cột liên kết tới bảng users nếu có
    $col_users_id = $findCol(['users_id','user_id','usersid']);

    // At minimum require an identifier column to check duplicates; prefer manv then name
    if ($col_manv) {
        $chk = $pdo->prepare("SELECT * FROM {$preferredTable} WHERE {$col_manv} = ? LIMIT 1");
        $chk->execute([$manv]);
        if ($chk->fetch()) { echo json_encode(['success'=>false,'error'=>'Mã nhân viên đã tồn tại']); exit; }
    } elseif ($col_name) {
        $chk2 = $pdo->prepare("SELECT * FROM {$preferredTable} WHERE {$col_name} = ? LIMIT 1");
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

    // Nếu bảng có cột users_id, ưu tiên dùng giá trị POST nếu có, ngược lại cố gắng tự liên kết
    if ($col_users_id) {
        if (isset($_POST['users_id']) && strlen(trim($_POST['users_id']))>0) {
            $insertCols[] = $col_users_id;
            $insertParams[':users_id'] = (int)$_POST['users_id'];
        } else {
            // Thử khớp bằng tên nhân viên với cột `name` trong bảng users
            $matchedUserId = null;
            if ($col_name && $name) {
                $mstmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
                $mstmt->execute([$name]);
                $mf = $mstmt->fetch();
                if ($mf) $matchedUserId = (int)$mf['id'];
            }
            // Nếu bảng nhanvien có cột email và trùng với users.email thì cũng map
            if (!$matchedUserId) {
                // kiểm tra cột email tồn tại và có dữ liệu POST
                $colEmail = null;
                $colStmt2 = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN ("email","Email") LIMIT 1');
                $colStmt2->execute([$usedDb, $preferredTable]);
                $colEmail = $colStmt2->fetchColumn();
                if ($colEmail && isset($_POST['email']) && trim($_POST['email'])!=='') {
                    $eStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
                    $eStmt->execute([$_POST['email']]);
                    $ef = $eStmt->fetch();
                    if ($ef) $matchedUserId = (int)$ef['id'];
                }
            }

            if ($matchedUserId) {
                $insertCols[] = $col_users_id;
                $insertParams[':users_id'] = $matchedUserId;
                file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Auto-linked nhanvien={$manv} -> users_id={$matchedUserId}\n", FILE_APPEND);
            }
        }
    }

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
