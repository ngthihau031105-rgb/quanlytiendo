<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
$currentUser = auth_get_user();
$currentUid = $currentUser['id'] ?? null;
$currentRole = strtolower($currentUser['role'] ?? '');

$id = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($id === '') {
    echo json_encode(['success' => false, 'error' => 'Thiếu ID']);
    exit;
}

// Kết nối database
try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=quanlytiendo;charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Lỗi kết nối: ' . $e->getMessage()]);
    exit;
}

try {
    // Kiểm tra cấu trúc bảng
    $checkCols = $pdo->query("SHOW COLUMNS FROM hangmuc")->fetchAll(PDO::FETCH_COLUMN);
    
    // Xây dựng SQL SELECT động
    $sql = "SELECT ";
    $selectParts = [];
    
    // ID
    if (in_array('id', $checkCols)) $selectParts[] = "h.id";
    else if (in_array('hangmuc_id', $checkCols)) $selectParts[] = "h.hangmuc_id as id";
    else if (in_array('MAHM', $checkCols)) $selectParts[] = "h.MAHM as id";
    
    // Code
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
    else $selectParts[] = "NULL as start_date";
    
    // Ngày kết thúc
    if (in_array('end_date', $checkCols)) $selectParts[] = "h.end_date";
    else if (in_array('ngaykt', $checkCols)) $selectParts[] = "h.ngaykt as end_date";
    else if (in_array('NGAYKT', $checkCols)) $selectParts[] = "h.NGAYKT as end_date";
    else if (in_array('ngay_ket_thuc', $checkCols)) $selectParts[] = "h.ngay_ket_thuc as end_date";
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
    
    // Project name (JOIN nếu có) - build safe JOIN only using existing columns
    $joinClause = "";
    $projectNameSelect = "NULL as project_name";

    $tables = $pdo->query("SHOW TABLES LIKE 'congtrinh'")->fetchAll();
    if (count($tables) > 0) {
        $ctCols = $pdo->query("SHOW COLUMNS FROM congtrinh")->fetchAll(PDO::FETCH_COLUMN);
        $ctColsLower = array_map('strtolower', $ctCols);
        $hColsLower = array_map('strtolower', $checkCols);

        // possible join key pairs
        $ctKeyCandidates = ['id','mact','MACT','MACT'];
        $hKeyCandidates = ['project_id','congtrinh','mact','MACT','ma'];

        $joinConds = [];
        foreach ($ctKeyCandidates as $ctKey) {
            // find actual ct column with same lowercase
            $foundCt = null;
            foreach ($ctCols as $c) {
                if (strtolower($c) === strtolower($ctKey)) { $foundCt = $c; break; }
            }
            if (!$foundCt) continue;

            foreach ($hColsLower as $i => $hcLower) {
                $hc = $checkCols[$i];
                foreach ($hKeyCandidates as $hKey) {
                    if ($hcLower === strtolower($hKey)) {
                        $joinConds[] = "ct.`$foundCt` = h.`$hc`";
                    }
                }
            }
        }

        if (!empty($joinConds)) {
            $joinClause = ' LEFT JOIN congtrinh ct ON ' . implode(' OR ', $joinConds);
        }

        if (in_array('name', $ctColsLower)) $projectNameSelect = "ct.name as project_name";
        else if (in_array('ten', $ctColsLower)) $projectNameSelect = "ct.ten as project_name";
        else if (in_array('tenct', $ctColsLower)) $projectNameSelect = "ct.TENCT as project_name";
    }

    $selectParts[] = $projectNameSelect;

    // Attempt to include manager columns (MANV-like and users_id-like) so frontend can authorize edits
    $colsLower = array_map('strtolower', $checkCols);
    // MANV-like
    foreach (['manv','nguoi_phu_trach','nguoi','nguoi_phu_trach','nguoi_phu_trach','nguoi_phu_trach'] as $mc) {
        foreach ($checkCols as $c) {
            if (strtolower($c) === $mc) {
                $selectParts[] = "h.`$c` as manager";
                break 2;
            }
        }
    }
    // users_id-like
    foreach (['users_id','user_id','manager_user_id','manager_users_id','usersid'] as $uc) {
        foreach ($checkCols as $c) {
            if (strtolower($c) === $uc) {
                $selectParts[] = "h.`$c` as users_id";
                break 2;
            }
        }
    }

    $sql .= implode(", ", $selectParts);
    $sql .= " FROM hangmuc h" . $joinClause;
    
    // WHERE: tìm theo ID hoặc code
    $whereParts = [];
    $params = [];
    
    if (is_numeric($id)) {
        if (in_array('id', $checkCols)) {
            $whereParts[] = "h.id = ?";
            $params[] = (int)$id;
        } else if (in_array('hangmuc_id', $checkCols)) {
            $whereParts[] = "h.hangmuc_id = ?";
            $params[] = (int)$id;
        }
    }
    
    // Tìm theo code
    if (in_array('code', $checkCols)) {
        $whereParts[] = "h.code = ?";
        $params[] = $id;
    } else if (in_array('mahm', $checkCols)) {
        $whereParts[] = "h.mahm = ?";
        $params[] = $id;
    } else if (in_array('MAHM', $checkCols)) {
        $whereParts[] = "h.MAHM = ?";
        $params[] = $id;
    } else if (in_array('ma', $checkCols)) {
        $whereParts[] = "h.ma = ?";
        $params[] = $id;
    }
    
    if (empty($whereParts)) {
        echo json_encode(['success' => false, 'error' => 'Không thể xác định cột ID']);
        exit;
    }
    
    $sql .= " WHERE (" . implode(" OR ", $whereParts) . ") LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    
    $stmt->execute();
    $row = $stmt->fetch();
    
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Không tìm thấy hạng mục']);
        exit;
    }
    // Determine whether current user is allowed to edit this hangmuc.
    $editable = true; // default allow for non-employee roles
    if ($currentRole === 'employee') {
        $editable = false;
        // try to detect manager/assigned column on hangmuc (match by MANV or by users_id-like fields)
        $checkColsLower = array_map('strtolower', $checkCols);
        $manvCols = ['manv','nguoi_phu_trach','nguoi'];
        foreach ($manvCols as $c) {
            if (in_array($c, $checkColsLower)) {
                $real = $checkCols[array_search($c, $checkColsLower)];
                if (isset($row[$real]) && (string)$row[$real] === (string)$currentUid) { $editable = true; break; }
            }
        }
        // check users_id-like fields on the hangmuc row
        if (!$editable) {
            $userIdFields = ['users_id','user_id','manager_user_id','manager_users_id','usersid'];
            foreach ($userIdFields as $f) {
                foreach ($checkCols as $c) {
                    if (strtolower($c) === strtolower($f) && isset($row[$c]) && (string)$row[$c] !== '') {
                        if ((string)$row[$c] === (string)$currentUid) { $editable = true; break 2; }
                    }
                }
            }
        }
        // fallback: check tasks table for any task assigned to user under this category
        if (!$editable) {
            $tasksCandidates = ['tasks','tiendo','tien_do','progress'];
            foreach ($tasksCandidates as $tt) {
                try {
                    $s = $pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$tt]);
                    if (!$s->fetch()) continue;
                    $tcols = $pdo->query("SHOW COLUMNS FROM {$tt}")->fetchAll(PDO::FETCH_COLUMN);
                    $tcolsLower = array_map('strtolower',$tcols);
                    $catCol = null; foreach(['category_id','mahm_id','hangmuc_id','mahm','ma_hm'] as $cand){ if(in_array($cand,$tcolsLower)){ $catCol=$tcols[array_search($cand,$tcolsLower)]; break; } }
                    $assignCol = null; foreach(['assigned_to','manv','user_id','nguoi','nguoi_phu_trach'] as $cand){ if(in_array($cand,$tcolsLower)){ $assignCol=$tcols[array_search($cand,$tcolsLower)]; break; } }
                    if ($catCol && $assignCol && isset($row['id'])) {
                        $q = $pdo->prepare("SELECT 1 FROM {$tt} WHERE `{$catCol}` = ? AND `{$assignCol}` = ? LIMIT 1");
                        $q->execute([$row['id'], (int)$currentUid]);
                        if ($q->fetch()) { $editable = true; break; }
                    }
                } catch (Exception $e) { continue; }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $row,
        'editable' => (bool)$editable
    ], JSON_UNESCAPED_UNICODE);
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Lỗi: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>