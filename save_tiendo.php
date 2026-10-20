<?php
// CRITICAL: No whitespace or output before this line!
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

// Require logged-in user (manager or employee can create progress)
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
// current user for RBAC
$currentUser = auth_get_user();
$currentUid = $currentUser['id'] ?? null;
$currentRole = strtolower($currentUser['role'] ?? '');

$___json_sent = false;

function sendJson($data){
    global $___json_sent;
    if($___json_sent) return;
    
    ob_clean(); // Clean buffer completely
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    $___json_sent = true;
    exit;
}

function logError($msg){
    @file_put_contents(__DIR__.'/save_tiendo.log', date('Y-m-d H:i:s').' '.$msg."\n", FILE_APPEND);
}

// Check POST method
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    sendJson(['success' => false, 'error' => 'Chỉ chấp nhận POST request']);
}

try {
    // Get POST data (accept multiple casings)
    $matd = trim($_POST['matd'] ?? ($_POST['MATD'] ?? ''));
    $mahm = trim($_POST['mahm'] ?? ($_POST['MAHM'] ?? ''));
    $mahm_id = trim($_POST['mahm_id'] ?? ($_POST['MAHM_ID'] ?? ($_POST['hangmuc_id'] ?? '')));
    // also accept 'hangmuc_id' from the frontend (some pages use this name)
    $hangmuc_id = trim($_POST['hangmuc_id'] ?? ($_POST['hangmucId'] ?? ''));
    if(empty($mahm_id) && !empty($hangmuc_id)) $mahm_id = $hangmuc_id;
    $start_date = trim($_POST['start_date'] ?? ($_POST['NGAYBDTT'] ?? ''));
    $end_date = trim($_POST['end_date'] ?? ($_POST['NGAYKTTT'] ?? ''));
    $progress = trim($_POST['progress'] ?? ($_POST['PHANTRAM'] ?? '0'));
    $status = trim($_POST['status'] ?? 'pending');
    $note = trim($_POST['note'] ?? ($_POST['GHICHU'] ?? ''));
    $project_id = trim($_POST['project_id'] ?? '');

    logError('POST data: ' . json_encode($_POST, JSON_UNESCAPED_UNICODE));

        // Helpful debug of resolved identifiers
        // Normalize common select option formats like "HM001 - Name" -> "HM001"
        $mahm = preg_replace('/\s*-\s*.*$/u', '', $mahm);
        $mahm_id = preg_replace('/\s*-\s*.*$/u', '', $mahm_id);
        $hangmuc_id = preg_replace('/\s*-\s*.*$/u', '', $hangmuc_id);

        // Trim again after normalization
        $mahm = trim($mahm);
        $mahm_id = trim($mahm_id);
        $hangmuc_id = trim($hangmuc_id);

        logError('Resolved identifiers: mahm=' . $mahm . ' mahm_id=' . $mahm_id . ' hangmuc_id=' . $hangmuc_id);

    // Validate required fields
    if(empty($matd)){
        sendJson(['success' => false, 'error' => 'Vui lòng nhập Mã Tiến độ (MATD)']);
    }

    if(empty($mahm) && empty($mahm_id)){
        sendJson(['success' => false, 'error' => 'Vui lòng chọn Hạng mục']);
    }

    // Database connection
    $dbNames = ['quanlytiendo', 'quanlytiendo_db', 'construction_db'];
    $pdo = null;
    $usedDb = null;

    foreach($dbNames as $dbName){
        try {
            $pdo = new PDO(
                "mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4",
                'root',
                '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            $usedDb = $dbName;
            break;
        } catch(PDOException $e){
            continue;
        }
    }

    if(!$pdo){
        sendJson(['success' => false, 'error' => 'Không thể kết nối database']);
    }

    logError("Connected to DB: {$usedDb}");

    // Find category/hangmuc ID
    $category_id = null;

    // Method 1: Try with mahm_id first (if provided)
    if(!empty($mahm_id) && is_numeric($mahm_id)){
        // Try categories table
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$mahm_id]);
        $result = $stmt->fetch();
        
        if($result){
            $category_id = $result['id'];
            logError("Found in categories by ID: {$category_id}");
        } else {
            // Try hangmuc table
            $stmt = $pdo->prepare("SELECT id FROM hangmuc WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$mahm_id]);
            $result = $stmt->fetch();
            
            if($result){
                $category_id = $result['id'];
                logError("Found in hangmuc by ID: {$category_id}");
            }
        }
    }

    // Method 2: Try with mahm code (if ID not found)
    if(empty($category_id) && !empty($mahm)){
        // Try in categories by code
        $codeColumns = ['code', 'MAHM', 'mahm', 'ma', 'ma_hm'];
        
        foreach($codeColumns as $col){
            try {
                $stmt = $pdo->prepare("SELECT id FROM categories WHERE {$col} = ? LIMIT 1");
                $stmt->execute([$mahm]);
                $result = $stmt->fetch();
                
                if($result){
                    $category_id = $result['id'];
                    logError("Found in categories by {$col}: {$category_id}");
                    break;
                }
            } catch(PDOException $e){
                continue;
            }
        }

        // Try in hangmuc by code
        if(empty($category_id)){
            foreach($codeColumns as $col){
                try {
                    $stmt = $pdo->prepare("SELECT id FROM hangmuc WHERE {$col} = ? LIMIT 1");
                    $stmt->execute([$mahm]);
                    $result = $stmt->fetch();
                    
                    if($result){
                        $category_id = $result['id'];
                        logError("Found in hangmuc by {$col}: {$category_id}");
                        break;
                    }
                } catch(PDOException $e){
                    continue;
                }
            }
        }

        // Try as numeric ID if mahm looks like a number
        if(empty($category_id) && is_numeric($mahm)){
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$mahm]);
            $result = $stmt->fetch();
            
            if($result){
                $category_id = $result['id'];
                logError("Found in categories by numeric mahm: {$category_id}");
            } else {
                $stmt = $pdo->prepare("SELECT id FROM hangmuc WHERE id = ? LIMIT 1");
                $stmt->execute([(int)$mahm]);
                $result = $stmt->fetch();
                
                if($result){
                    $category_id = $result['id'];
                    logError("Found in hangmuc by numeric mahm: {$category_id}");
                }
            }
        }
    }

    if(empty($category_id)){
        logError("Category not found. mahm={$mahm}, mahm_id={$mahm_id}");
        sendJson(['success' => false, 'error' => 'Không tìm thấy hạng mục. Vui lòng kiểm tra lại.']);
    }

    // Find tasks table
    $tasksTable = null;
    $tasksCandidates = ['tasks', 'tiendo', 'tien_do', 'progress'];
    
    foreach($tasksCandidates as $table){
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if($stmt->fetch()){
            $tasksTable = $table;
            break;
        }
    }

    if(!$tasksTable){
        sendJson(['success' => false, 'error' => 'Không tìm thấy bảng tiến độ trong database']);
    }

    logError("Using tasks table: {$tasksTable}");

        // Inspect columns of the tasks table and determine identifier column(s)
        $taskColumns = $pdo->query("SHOW COLUMNS FROM {$tasksTable}")->fetchAll(PDO::FETCH_COLUMN);
        $taskColsLower = array_map('strtolower', $taskColumns ?: []);

        // Choose identifier column (prefer code, then matd, then other common names)
        $identCandidates = ['code','matd','MATD','madt','task_code','code_td'];
        $identColumn = null;
        foreach($identCandidates as $cand){
            if(in_array(strtolower($cand), $taskColsLower)){
                $idx = array_search(strtolower($cand), $taskColsLower);
                $identColumn = $taskColumns[$idx];
                break;
            }
        }

        if(!$identColumn){
            logError('No identifier column found in '.$tasksTable.' columns: '.json_encode($taskColumns));
            sendJson(['success' => false, 'error' => 'Bảng tiến độ không có cột mã (code/MATD). Vui lòng liên hệ admin.']);
        }

        // detect assigned/owner column in tasks table if exists
        $assignedCol = null;
        $assignedCandidates = ['assigned_to','assigned','nguoi','nguoi_phu_trach','user_id','manv','assigned_to_id'];
        foreach ($assignedCandidates as $cand) {
            if (in_array(strtolower($cand), $taskColsLower)) { $assignedCol = $taskColumns[array_search(strtolower($cand), $taskColsLower)]; break; }
        }
        // Accept assigned value from POST (if manager); normalize name options
        $assignedProvided = trim($_POST['assigned_to'] ?? $_POST['assigned'] ?? $_POST['manv'] ?? ($_POST['user_id'] ?? ''));

        // Check if MATD already exists using detected identifier column
        $checkSql = "SELECT COUNT(*) as cnt FROM {$tasksTable} WHERE `{$identColumn}` = ?";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([$matd]);
        $existing = $checkStmt->fetch();
        if($existing['cnt'] > 0){
            sendJson(['success' => false, 'error' => 'Mã tiến độ đã tồn tại. Vui lòng chọn mã khác.']);
        }

    // Prepare insert data mapping but only keep keys that exist in the tasks table
    $candidateInsert = [
        'category_id' => $category_id,
        'code' => $matd,
        'title' => 'Tiến độ ' . $matd,
        'description' => $note,
        'start_date' => $start_date ?: null,
        'end_date' => $end_date ?: null,
        'progress' => (int)$progress,
        'status' => $status
    ];

    // Map some common alternate column names for category / description / progress
    $colMap = [];
    // If table doesn't have 'category_id', try 'mahm_id' or 'hangmuc_id'
    if(!in_array('category_id', $taskColsLower)){
        foreach(['mahm_id','hangmuc_id','mahm','ma_hm'] as $alt){
            if(in_array(strtolower($alt), $taskColsLower)){
                $idx = array_search(strtolower($alt), $taskColsLower);
                $colMap['category_id'] = $taskColumns[$idx];
                break;
            }
        }
    } else {
        $colMap['category_id'] = 'category_id';
    }

    // description mapping
    if(!in_array('description', $taskColsLower)){
        foreach(['note','ghichu','description'] as $alt){
            if(in_array(strtolower($alt), $taskColsLower)){
                $idx = array_search(strtolower($alt), $taskColsLower);
                $colMap['description'] = $taskColumns[$idx];
                break;
            }
        }
    } else {
        $colMap['description'] = 'description';
    }

    // progress mapping
    if(!in_array('progress', $taskColsLower)){
        foreach(['phantram','percent'] as $alt){
            if(in_array(strtolower($alt), $taskColsLower)){
                $idx = array_search(strtolower($alt), $taskColsLower);
                $colMap['progress'] = $taskColumns[$idx];
                break;
            }
        }
    } else {
        $colMap['progress'] = 'progress';
    }

    // Build final insert data only using existing columns
    $insertData = [];
    foreach($candidateInsert as $k=>$v){
        if($k === 'category_id' && isset($colMap['category_id'])){
            $insertData[$colMap['category_id']] = $v;
            continue;
        }
        if($k === 'description' && isset($colMap['description'])){
            $insertData[$colMap['description']] = $v;
            continue;
        }
        if($k === 'progress' && isset($colMap['progress'])){
            $insertData[$colMap['progress']] = $v;
            continue;
        }
        // code/title/start_date/end_date/status use their names if present
        if(in_array(strtolower($k), $taskColsLower)){
            $idx = array_search(strtolower($k), $taskColsLower);
            $colName = $taskColumns[$idx];
            $insertData[$colName] = $v;
        }
    }

    // Enforce RBAC for assigned user: employees can only create progress assigned to themselves
    if ($assignedCol) {
        if ($currentRole === 'employee') {
            // set assigned to current user id
            $insertData[$assignedCol] = $currentUid;
        } else {
            // manager may specify assignedProvided; if provided and numeric use it
            if ($assignedProvided !== '') {
                if (is_numeric($assignedProvided)) {
                    $insertData[$assignedCol] = (int)$assignedProvided;
                } else {
                    // try to resolve to user id by email or user code
                    try {
                        $r = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                        $r->execute([$assignedProvided]);
                        $urow = $r->fetch();
                        if ($urow) $insertData[$assignedCol] = (int)$urow['id'];
                    } catch (Exception $e) { /* ignore resolution errors */ }
                }
            }
        }
    }

    if(empty($insertData)){
        logError('No compatible insert columns found for tasks table: '.json_encode($taskColumns));
        sendJson(['success'=>false,'error'=>'Không thể chèn tiến độ do cấu trúc bảng không khớp. Liên hệ admin.']);
    }

    // Build INSERT query using existing column names (escape with backticks)
    $columns = array_keys($insertData);
    $placeholders = array_fill(0, count($columns), '?');
    $colList = implode(', ', array_map(function($c){ return "`".$c."`"; }, $columns));
    $sql = "INSERT INTO {$tasksTable} (" . $colList . ") VALUES (" . implode(', ', $placeholders) . ")";
    
    logError("SQL: {$sql}");
    logError("Data: " . json_encode(array_values($insertData), JSON_UNESCAPED_UNICODE));

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($insertData));
    
    $insertId = $pdo->lastInsertId();
    
    logError("Insert successful. ID: {$insertId}");

    sendJson([
        'success' => true,
        'message' => 'Thêm tiến độ thành công!',
        'id' => $insertId,
        'matd' => $matd
    ]);

} catch(PDOException $e){
    logError("PDO Error: " . $e->getMessage());
    sendJson(['success' => false, 'error' => 'Lỗi database: ' . $e->getMessage()]);
} catch(Exception $e){
    logError("Error: " . $e->getMessage());
    sendJson(['success' => false, 'error' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
?>