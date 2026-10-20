<?php
header('Content-Type: application/json; charset=utf-8');
// Simple logger for debugging
function dbg_update($msg){
    @file_put_contents(__DIR__.'/update_hangmuc.log', date('c').' '.$msg."\n", FILE_APPEND);
}

// Require auth and enforce RBAC: managers can update any, employees only their assigned items
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
// we'll perform per-item checks after loading the existing row

if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', 'POST') !== 0) {
    echo json_encode(['success' => false, 'error' => 'Yêu cầu POST']);
    exit;
}

$id = trim($_POST['id'] ?? '');
$name = trim($_POST['name'] ?? '');
// accept either project_id or congtrinh from client
$project_id = trim($_POST['project_id'] ?? ($_POST['congtrinh'] ?? ''));
// accept description or mota
$description = trim($_POST['description'] ?? ($_POST['mota'] ?? ''));
// accept nguoi (person in charge)
$nguoi = trim($_POST['nguoi'] ?? '');
$status = trim($_POST['status'] ?? '');
// accept manv (manager code)
$manv = trim($_POST['manv'] ?? ($_POST['MANV'] ?? ''));

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
    
    // Chuẩn bị các field để UPDATE
    $updateFields = [];
    $params = [];
    
    // Tên hạng mục
    if ($name !== '') {
        if (in_array('name', $checkCols)) {
            $updateFields[] = "name = ?";
            $params[] = $name;
        } else if (in_array('ten', $checkCols)) {
            $updateFields[] = "ten = ?";
            $params[] = $name;
        } else if (in_array('TEN', $checkCols)) {
            $updateFields[] = "TEN = ?";
            $params[] = $name;
        } else if (in_array('TENHM', $checkCols)) {
            $updateFields[] = "TENHM = ?";
            $params[] = $name;
        }
    }
    
    // Project ID
    if ($project_id !== '') {
        if (in_array('project_id', $checkCols)) {
            $updateFields[] = "project_id = ?";
            $params[] = $project_id;
        } else if (in_array('congtrinh', $checkCols)) {
            $updateFields[] = "congtrinh = ?";
            $params[] = $project_id;
        } else if (in_array('MACT', $checkCols)) {
            $updateFields[] = "MACT = ?";
            $params[] = $project_id;
        }
    }

    // Người phụ trách (nguoi)
    if ($nguoi !== '') {
        if (in_array('nguoi_phu_trach', $checkCols)) {
            $updateFields[] = "nguoi_phu_trach = ?";
            $params[] = $nguoi;
        } else if (in_array('nguoi', $checkCols)) {
            $updateFields[] = "nguoi = ?";
            $params[] = $nguoi;
        }
    }

    // MANV / mã nhân viên
    if ($manv !== '') {
        if (in_array('MANV', $checkCols)) {
            $updateFields[] = "MANV = ?";
            $params[] = $manv;
        } else if (in_array('manv', $checkCols)) {
            $updateFields[] = "manv = ?";
            $params[] = $manv;
        } else if (in_array('MAN_NV', $checkCols)) {
            $updateFields[] = "MAN_NV = ?";
            $params[] = $manv;
        }
    }
    
    // Mô tả
    if ($description !== '') {
        if (in_array('description', $checkCols)) {
            $updateFields[] = "description = ?";
            $params[] = $description;
        } else if (in_array('mota', $checkCols)) {
            $updateFields[] = "mota = ?";
            $params[] = $description;
        } else if (in_array('MOTA', $checkCols)) {
            $updateFields[] = "MOTA = ?";
            $params[] = $description;
        }
    }
    
    // Trạng thái
    if ($status !== '') {
        if (in_array('status', $checkCols)) {
            $updateFields[] = "status = ?";
            $params[] = $status;
        } else if (in_array('trangthai', $checkCols)) {
            $updateFields[] = "trangthai = ?";
            $params[] = $status;
        } else if (in_array('TRANGTHAI', $checkCols)) {
            $updateFields[] = "TRANGTHAI = ?";
            $params[] = $status;
        } else if (in_array('trang_thai', $checkCols)) {
            $updateFields[] = "trang_thai = ?";
            $params[] = $status;
        }
    }
    
    if (empty($updateFields)) {
        echo json_encode(['success' => false, 'error' => 'Không có trường nào để cập nhật']);
        exit;
    }
    
    // Xây dựng WHERE clause
    $whereParts = [];
    $whereParams = [];
    
    if (is_numeric($id)) {
        if (in_array('id', $checkCols)) {
            $whereParts[] = "id = ?";
            $whereParams[] = (int)$id;
        } else if (in_array('hangmuc_id', $checkCols)) {
            $whereParts[] = "hangmuc_id = ?";
            $whereParams[] = (int)$id;
        }
    }
    
    // Tìm theo code
    if (in_array('code', $checkCols)) {
        $whereParts[] = "code = ?";
        $whereParams[] = $id;
    } else if (in_array('mahm', $checkCols)) {
        $whereParts[] = "mahm = ?";
        $whereParams[] = $id;
    } else if (in_array('MAHM', $checkCols)) {
        $whereParts[] = "MAHM = ?";
        $whereParams[] = $id;
    } else if (in_array('ma', $checkCols)) {
        $whereParts[] = "ma = ?";
        $whereParams[] = $id;
    }
    
    if (empty($whereParts)) {
        echo json_encode(['success' => false, 'error' => 'Không thể xác định điều kiện WHERE']);
        exit;
    }

    // Find primary key column for hangmuc to update reliably
    $pkStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'");
    $pkStmt->execute([ $pdo->query("select database()")->fetchColumn(), 'hangmuc' ]);
    $pkCols = $pkStmt->fetchAll(PDO::FETCH_COLUMN);
    $primaryCol = !empty($pkCols) ? $pkCols[0] : null;

    // Try to locate the existing row using the whereParts (search by any candidate)
    $selSql = "SELECT ".($primaryCol?"`$primaryCol`":"*" )." FROM hangmuc WHERE (" . implode(" OR ", $whereParts) . ") LIMIT 1";
    dbg_update('SELECT to find row: '.$selSql.' params: '.json_encode($whereParams, JSON_UNESCAPED_UNICODE));
    $selStmt = $pdo->prepare($selSql);
    foreach ($whereParams as $i => $val) {
        $selStmt->bindValue($i + 1, $val);
    }
    $selStmt->execute();
    $found = $selStmt->fetch(PDO::FETCH_ASSOC);

    // RBAC: only managers may update hangmuc records
    $currentUser = auth_get_user();
    $currentRole = strtolower(trim($currentUser['role'] ?? ''));
    if ($currentRole !== 'manager') {
        if (function_exists('log_unauthorized_action')) @log_unauthorized_action('update','hangmuc',$id,'Bạn không có quyền sửa hạng mục này');
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Bạn không có quyền làm điều này'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$found) {
        dbg_update('No matching row found for where params: '.json_encode($whereParams, JSON_UNESCAPED_UNICODE));
        echo json_encode([
            'success' => false,
            'error' => 'Không tìm thấy bản ghi để cập nhật hoặc dữ liệu không thay đổi'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Build UPDATE using primary key (preferred) or original whereParts as fallback
    if ($primaryCol && isset($found[$primaryCol])) {
        $whereSql = "`$primaryCol` = ?";
        $whereValues = [ $found[$primaryCol] ];
    } else {
        $whereSql = "(" . implode(" OR ", $whereParts) . ")";
        $whereValues = $whereParams;
    }

    $sql = "UPDATE hangmuc SET " . implode(", ", $updateFields) . " WHERE " . $whereSql;
    $allParams = array_merge($params, $whereValues);

    dbg_update('Executing UPDATE: '.$sql.' params: '.json_encode($allParams, JSON_UNESCAPED_UNICODE));
    $stmt = $pdo->prepare($sql);
    foreach ($allParams as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->execute();

    dbg_update('Affected rows: '.$stmt->rowCount());

    if ($stmt->rowCount() > 0) {
        // After updating hangmuc status/name, optionally propagate status -> progress to related tiến độ records
        try {
            if ($status !== '') {
                // map common status keywords to a progress percentage
                $s = mb_strtolower($status, 'UTF-8');
                $newProgress = null;
                if (strpos($s, 'hoàn') !== false || strpos($s, 'complete') !== false || strpos($s, 'done') !== false) $newProgress = 100;
                elseif (strpos($s, 'chưa') !== false || strpos($s, 'pending') !== false || strpos($s, 'not') !== false) $newProgress = 0;
                elseif (strpos($s, 'đang') !== false || strpos($s, 'dang') !== false || strpos($s, 'progress') !== false || strpos($s, 'in_progress') !== false) $newProgress = 50;

                if ($newProgress !== null) {
                    // find tasks/tiendo table
                    $tasksTable = null;
                    foreach (['tasks','tiendo','tien_do','progress'] as $tname) {
                        $check = $pdo->prepare("SHOW TABLES LIKE ?");
                        $check->execute([$tname]);
                        if ($check->fetch()) { $tasksTable = $tname; break; }
                    }

                    if ($tasksTable) {
                        // inspect task table columns to find linking column and progress column
                        $taskCols = $pdo->query("SHOW COLUMNS FROM {$tasksTable}")->fetchAll(PDO::FETCH_COLUMN);
                        $taskColsLower = array_map('strtolower', $taskCols);

                        $linkCol = null; // column in tasks that refers to hangmuc
                        $candidates = ['category_id','hangmuc_id','mahm','mahm_code','MAHM','ma','congtrinh','mact','ma_hm'];
                        foreach ($candidates as $c) {
                            $idx = array_search(strtolower($c), $taskColsLower, true);
                            if ($idx !== false) { $linkCol = $taskCols[$idx]; break; }
                        }

                        // detect progress column name in tasks table
                        $progressCol = null;
                        foreach (['progress','PHANTRAM','phantram','phan_tram','percent','percent_complete'] as $pc) {
                            $idx = array_search(strtolower($pc), $taskColsLower, true);
                            if ($idx !== false) { $progressCol = $taskCols[$idx]; break; }
                        }
                        if (!$progressCol) $progressCol = 'progress'; // fallback

                        // find hangmuc code column to use if tasks link by code
                        $hangCodeCol = null;
                        foreach (['MAHM','mahm','code','ma'] as $hc) {
                            if (in_array($hc, $checkCols)) { $hangCodeCol = $hc; break; }
                        }

                        // perform update depending on link type
                        if ($linkCol) {
                            // if linkCol looks numeric (category_id) and we have primary key value
                            if (preg_match('/id$/i', $linkCol) && $primaryCol && isset($found[$primaryCol])) {
                                $upd = $pdo->prepare("UPDATE `{$tasksTable}` SET `{$progressCol}` = ? WHERE `{$linkCol}` = ?");
                                $upd->execute([(int)$newProgress, $found[$primaryCol]]);
                                dbg_update('Propagated progress to '.$upd->rowCount().' rows by id (col '.$progressCol.')');
                            } elseif ($hangCodeCol) {
                                // use code match
                                $codeVal = $found[$hangCodeCol] ?? null;
                                if ($codeVal) {
                                    $upd = $pdo->prepare("UPDATE `{$tasksTable}` SET `{$progressCol}` = ? WHERE `{$linkCol}` = ?");
                                    $upd->execute([(int)$newProgress, $codeVal]);
                                    dbg_update('Propagated progress to '.$upd->rowCount().' rows by code (col '.$progressCol.')');
                                }
                            }
                        }
                    }
                }
            }
        } catch(Exception $e) {
            dbg_update('Propagation error: '.$e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'message' => 'Cập nhật hạng mục thành công'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Không tìm thấy bản ghi để cập nhật hoặc dữ liệu không thay đổi'
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Lỗi: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>