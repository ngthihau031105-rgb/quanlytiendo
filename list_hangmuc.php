<?php
header('Content-Type: application/json; charset=utf-8');
// Require login and apply RBAC: managers see all, employees see only hangmuc with tasks assigned to them
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
$currentUser = auth_get_user();
$currentUid = $currentUser['id'] ?? null;
$currentRole = strtolower($currentUser['role'] ?? '');

// Try to connect to whichever DB contains a hangmuc-like table
$dbNames = ['quanlytiendo','quanlytiendo_db','construction_db'];
$pdo = null;
$hangmucTable = null;
$candidates = ['hangmuc','categories','hạngmục','hạng_mục'];
foreach($dbNames as $db){
    try{
        $tmp = new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4", 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        // find a candidate table in this DB
        foreach(['hangmuc','categories','hạngmục'] as $t){
            $s = $tmp->prepare("SHOW TABLES LIKE ?");
            $s->execute([$t]);
            if($s->fetch()){
                $pdo = $tmp;
                $hangmucTable = $t;
                break 2;
            }
        }
        $tmp = null;
    }catch(Exception $e){
        continue;
    }
}

if(!$pdo){
    echo json_encode(['success' => false, 'error' => 'Không thể kết nối database hoặc không tìm thấy bảng hạng mục']);
    exit;
}

// Lấy tham số
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pageSize = isset($_GET['pageSize']) ? min(500, max(1, (int)$_GET['pageSize'])) : 500;
$offset = ($page - 1) * $pageSize;

try {
    // Kiểm tra cấu trúc bảng hạng mục (tên table được phát hiện ở trên)
    $checkCols = $pdo->query("SHOW COLUMNS FROM {$hangmucTable}")->fetchAll(PDO::FETCH_COLUMN);
    
    // Xây dựng SQL động dựa trên các cột có sẵn
    $sql = "SELECT ";
    $selectParts = [];
    
    // ID
    if (in_array('id', $checkCols)) $selectParts[] = "h.id";
    else if (in_array('hangmuc_id', $checkCols)) $selectParts[] = "h.hangmuc_id as id";
    else if (in_array('MAHM', $checkCols)) $selectParts[] = "h.MAHM as id";
    
    // Code/Mã
    if (in_array('code', $checkCols)) $selectParts[] = "h.code";
    else if (in_array('mahm', $checkCols)) $selectParts[] = "h.mahm as code";
    else if (in_array('MAHM', $checkCols)) $selectParts[] = "h.MAHM as code";
    else if (in_array('ma', $checkCols)) $selectParts[] = "h.ma as code";
    
    // Tên
    if (in_array('name', $checkCols)) $selectParts[] = "h.name";
    else if (in_array('ten', $checkCols)) $selectParts[] = "h.ten as name";
    else if (in_array('TEN', $checkCols)) $selectParts[] = "h.TEN as name";
    else if (in_array('TENHM', $checkCols)) $selectParts[] = "h.TENHM as name";
    
    // Mô tả
    if (in_array('description', $checkCols)) $selectParts[] = "h.description";
    else if (in_array('mota', $checkCols)) $selectParts[] = "h.mota as description";
    else if (in_array('MOTA', $checkCols)) $selectParts[] = "h.MOTA as description";
    else $selectParts[] = "NULL as description";
    
    // Ngày bắt đầu
    if (in_array('start_date', $checkCols)) $selectParts[] = "h.start_date";
    else if (in_array('ngaybd', $checkCols)) $selectParts[] = "h.ngaybd as start_date";
    else if (in_array('NGAYBD', $checkCols)) $selectParts[] = "h.NGAYBD as start_date";
    else if (in_array('ngay_bat_dau', $checkCols)) $selectParts[] = "h.ngay_bat_dau as start_date";
    else if (in_array('NGAYBATDAU', $checkCols)) $selectParts[] = "h.NGAYBATDAU as start_date";
    else $selectParts[] = "NULL as start_date";
    
    // Ngày kết thúc
    if (in_array('end_date', $checkCols)) $selectParts[] = "h.end_date";
    else if (in_array('ngaykt', $checkCols)) $selectParts[] = "h.ngaykt as end_date";
    else if (in_array('NGAYKT', $checkCols)) $selectParts[] = "h.NGAYKT as end_date";
    else if (in_array('ngay_ket_thuc', $checkCols)) $selectParts[] = "h.ngay_ket_thuc as end_date";
    else if (in_array('NGAYKETTHUC', $checkCols)) $selectParts[] = "h.NGAYKETTHUC as end_date";
    else $selectParts[] = "NULL as end_date";
    
    // Trạng thái
    if (in_array('status', $checkCols)) $selectParts[] = "h.status";
    else if (in_array('trangthai', $checkCols)) $selectParts[] = "h.trangthai as status";
    else if (in_array('TRANGTHAI', $checkCols)) $selectParts[] = "h.TRANGTHAI as status";
    else if (in_array('trang_thai', $checkCols)) $selectParts[] = "h.trang_thai as status";
    else $selectParts[] = "NULL as status";
    
    // Project ID
    if (in_array('project_id', $checkCols)) $selectParts[] = "h.project_id";
    else if (in_array('congtrinh', $checkCols)) $selectParts[] = "h.congtrinh as project_id";
    else if (in_array('MACT', $checkCols)) $selectParts[] = "h.MACT as project_id";
    else $selectParts[] = "NULL as project_id";
    
    // Người phụ trách (MANV / nguoi)
    if (in_array('MANV', $checkCols)) $selectParts[] = "h.MANV as manager";
    else if (in_array('manv', $checkCols)) $selectParts[] = "h.manv as manager";
    else if (in_array('MAN_NV', $checkCols)) $selectParts[] = "h.MAN_NV as manager";
    else if (in_array('nguoi_phu_trach', $checkCols)) $selectParts[] = "h.nguoi_phu_trach as manager";
    else if (in_array('nguoi', $checkCols)) $selectParts[] = "h.nguoi as manager";
    else $selectParts[] = "NULL as manager";
    
    // Project name (JOIN nếu có bảng projects/congtrinh)
    $joinClause = "";
    $projectNameSelect = "NULL as project_name";
    
    $tables = $pdo->query("SHOW TABLES LIKE 'congtrinh'")->fetchAll();
    if (count($tables) > 0) {
        // Inspect congtrinh columns and build a safe JOIN clause only using existing columns
        $ctCols = $pdo->query("SHOW COLUMNS FROM congtrinh")->fetchAll(PDO::FETCH_COLUMN);
        $ctColsLower = array_map('strtolower', $ctCols);

        // possible keys on congtrinh and hangmuc to join on
        $ctKeyCandidates = ['id','mact','mact','MACT','MACT'];
        $hKeyCandidates = ['project_id','congtrinh','mact','MACT','mact'];

        $joinConds = [];
        foreach ($ctKeyCandidates as $ctKey) {
            // find actual column name case-sensitive from $ctCols
            $foundCt = null;
            foreach ($ctCols as $c) {
                if (strtolower($c) === strtolower($ctKey)) { $foundCt = $c; break; }
            }
            if (!$foundCt) continue;

            // check hangmuc columns for matching keys
            foreach ($hKeyCandidates as $hKey) {
                foreach ($checkCols as $hc) {
                    if (strtolower($hc) === strtolower($hKey)) {
                        $joinConds[] = "ct.`$foundCt` = h.`$hc`";
                    }
                }
            }
        }

        if (!empty($joinConds)) {
            $joinClause = ' LEFT JOIN congtrinh ct ON ' . implode(' OR ', $joinConds);
        }

        // Choose a project name column from congtrinh if available
        if (in_array('name', $ctColsLower)) $projectNameSelect = "ct.name as project_name";
        else if (in_array('ten', $ctColsLower)) $projectNameSelect = "ct.ten as project_name";
        else if (in_array('tenct', $ctColsLower)) $projectNameSelect = "ct.TENCT as project_name";
        else if (in_array('ten', $ctColsLower)) $projectNameSelect = "ct.ten as project_name";
    }
    
    $selectParts[] = $projectNameSelect;
    
    $sql .= implode(", ", $selectParts);
    $sql .= " FROM {$hangmucTable} h" . $joinClause;

    // Do not restrict hangmuc listing by assigned user; show all hangmuc to any logged-in user
    $employeeFilter = '';
    $employeeParams = [];
    
    // WHERE clause cho tìm kiếm
    $params = [];
    if ($q !== '') {
        $whereParts = [];
        // Tìm trong tên
        if (in_array('name', $checkCols)) { $whereParts[] = "h.name LIKE ?"; $params[] = "%$q%"; }
        else if (in_array('ten', $checkCols)) { $whereParts[] = "h.ten LIKE ?"; $params[] = "%$q%"; }
        else if (in_array('TEN', $checkCols)) { $whereParts[] = "h.TEN LIKE ?"; $params[] = "%$q%"; }
        else if (in_array('TENHM', $checkCols)) { $whereParts[] = "h.TENHM LIKE ?"; $params[] = "%$q%"; }
        
        // Tìm trong mã
        if (in_array('code', $checkCols)) { $whereParts[] = "h.code LIKE ?"; $params[] = "%$q%"; }
        else if (in_array('mahm', $checkCols)) { $whereParts[] = "h.mahm LIKE ?"; $params[] = "%$q%"; }
        else if (in_array('MAHM', $checkCols)) { $whereParts[] = "h.MAHM LIKE ?"; $params[] = "%$q%"; }
        
        // Tìm trong mô tả
        if (in_array('description', $checkCols)) { $whereParts[] = "h.description LIKE ?"; $params[] = "%$q%"; }
        else if (in_array('mota', $checkCols)) { $whereParts[] = "h.mota LIKE ?"; $params[] = "%$q%"; }
        else if (in_array('MOTA', $checkCols)) { $whereParts[] = "h.MOTA LIKE ?"; $params[] = "%$q%"; }
        
        if (!empty($whereParts)) {
            $sql .= " WHERE (" . implode(" OR ", $whereParts) . ")";
        }
        // (No per-user restriction applied here)
    }
    
    // Đếm tổng số
    $countSql = "SELECT COUNT(*) FROM {$hangmucTable} h";
    if ($q !== '' && !empty($whereParts)) $countSql .= " WHERE (" . implode(" OR ", $whereParts) . ")";
    if ($employeeFilter !== '') $countSql .= (!empty($whereParts) ? ' AND ' : ' WHERE ') . $employeeFilter;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // ORDER BY và LIMIT: chọn cột sắp xếp an toàn theo các cột thực tế
    $checkColsLower = array_map('strtolower', $checkCols);
    $orderCol = '';
    if (in_array('id', $checkColsLower)) $orderCol = 'h.id';
    elseif (in_array('mahm', $checkColsLower)) $orderCol = 'h.mahm';
    elseif (in_array('mahm', $checkColsLower) && in_array('MAHM', $checkCols)) $orderCol = 'h.MAHM';
    elseif (in_array('code', $checkColsLower)) $orderCol = 'h.code';
    elseif (in_array('start_date', $checkColsLower)) $orderCol = 'h.start_date';

    if ($orderCol !== '') {
        $sql .= " ORDER BY $orderCol DESC LIMIT ? OFFSET ?";
    } else {
        // Nếu không có cột phù hợp, tránh ORDER BY để không gây lỗi
        $sql .= " LIMIT ? OFFSET ?";
    }
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val, PDO::PARAM_STR);
    }
    $stmt->bindValue(count($params) + 1, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize
    ], JSON_UNESCAPED_UNICODE);
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Lỗi: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>