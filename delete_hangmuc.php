<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
auth_require_logged_in();

if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', 'POST') !== 0) {
    echo json_encode(['success' => false, 'error' => 'Yêu cầu POST']);
    exit;
}

$id = trim($_POST['id'] ?? '');
if ($id === '') {
    echo json_encode(['success' => false, 'error' => 'Thiếu ID']);
    exit;
}

// Note: skip trusting session role here; we'll verify role from DB after connecting.

// Kết nối database (try common DB names)
try {
    $dbNames = ['quanlytiendo','quanlytiendo_db'];
    $pdo = null; $usedDb = null; $lastEx = null;
    foreach ($dbNames as $dbn) {
        try {
            $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbn};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            $usedDb = $dbn; break;
        } catch (PDOException $e) { $lastEx = $e; }
    }
    if (!$pdo) throw $lastEx ?: new Exception('DB connect failed');
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Lỗi kết nối: ' . $e->getMessage()]);
    exit;
}

// Defensive DB-backed role check: ensure session user's role is manager
$sessUser = auth_get_user();
if (!$sessUser || empty($sessUser['id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Chưa đăng nhập'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $roleChk = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $roleChk->execute([$sessUser['id']]);
    $rrow = $roleChk->fetch(PDO::FETCH_ASSOC);
    $currentRole = isset($rrow['role']) ? strtolower(trim($rrow['role'])) : '';
    if ($currentRole !== 'manager') {
        if (function_exists('log_unauthorized_action')) @log_unauthorized_action('delete','hangmuc',$id,'Bạn không có quyền làm điều này (db-role)');
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Bạn không có quyền làm điều này'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch(Exception $e) { /* ignore and continue to rely on earlier checks */ }

try {
    // Determine possible matching columns for hangmuc
    $cols = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $cols->execute([$usedDb, 'hangmuc']);
    $checkCols = array_map('strtolower', $cols->fetchAll(PDO::FETCH_COLUMN));

    $whereParts = [];
    $params = [];
    if (is_numeric($id)) {
        if (in_array('id', $checkCols)) { $whereParts[] = 'id = ?'; $params[] = (int)$id; }
        elseif (in_array('hangmuc_id', $checkCols)) { $whereParts[] = 'hangmuc_id = ?'; $params[] = (int)$id; }
    }
    // code/value columns
    foreach (['code','mahm','ma','MAHM'] as $c) {
        if (in_array(strtolower($c), $checkCols)) { $whereParts[] = "`$c` = ?"; $params[] = $id; break; }
    }
    if (empty($whereParts)) {
        echo json_encode(['success' => false, 'error' => 'Không thể xác định điều kiện xóa']); exit;
    }

    // Begin transaction and delete dependents first using information_schema
    $pdo->beginTransaction();

    // fetch parent rows that match the where clause
    $selSql = "SELECT * FROM hangmuc WHERE (" . implode(' OR ', $whereParts) . ")";
    $sel = $pdo->prepare($selSql);
    foreach ($params as $i => $v) $sel->bindValue($i+1, $v);
    $sel->execute();
    $parents = $sel->fetchAll(PDO::FETCH_ASSOC);

    // find FKs referencing hangmuc
    $fkStmt = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?');
    $fkStmt->execute([$usedDb, 'hangmuc']);
    $refs = $fkStmt->fetchAll();

    // For each parent row: delete referencing child rows, delete related tasks, then delete parent
    foreach ($parents as $p) {
        // 1) delete rows in tables that have FK to hangmuc
        foreach ($refs as $r) {
            $childTable = $r['TABLE_NAME']; $childCol = $r['COLUMN_NAME']; $refCol = $r['REFERENCED_COLUMN_NAME'];
            if (!$childTable || !$childCol) continue;
            // determine matching value in parent row
            $matchVal = null;
            if ($refCol && array_key_exists($refCol, $p)) {
                $matchVal = $p[$refCol];
            } elseif (array_key_exists('id', $p)) {
                $matchVal = $p['id'];
            } elseif (array_key_exists('mahm', $p)) {
                $matchVal = $p['mahm'];
            } elseif (array_key_exists('code', $p)) {
                $matchVal = $p['code'];
            }
            if ($matchVal === null) continue;
            try {
                $delChild = $pdo->prepare("DELETE FROM `".str_replace('`','',$childTable)."` WHERE `".str_replace('`','',$childCol)."` = ?");
                $delChild->execute([$matchVal]);
            } catch (Exception $e) {
                @file_put_contents(__DIR__.'/save_project.log', date('c').' delete_hangmuc: child-delete error: '.$e->getMessage()."\n", FILE_APPEND);
            }
        }

        // 2) delete related progress/tasks (try common table names)
        try {
            $taskCandidates = ['tasks','tiendo','tien_do','progress'];
            foreach ($taskCandidates as $t) {
                $chk = $pdo->prepare('SHOW TABLES LIKE ?'); $chk->execute([$t]);
                if (!$chk->fetch()) continue;
                // delete by numeric parent id mapping
                if (isset($p['id']) && is_numeric($p['id'])) {
                    try { $d1 = $pdo->prepare("DELETE FROM `".str_replace('`','',$t)."` WHERE `category_id` = ?"); $d1->execute([$p['id']]); } catch(Exception $e){}
                    try { $d2 = $pdo->prepare("DELETE FROM `".str_replace('`','',$t)."` WHERE `hangmuc_id` = ?"); $d2->execute([$p['id']]); } catch(Exception $e){}
                }
                // delete by code-like fields
                $codeVal = $p['code'] ?? $p['mahm'] ?? $p['MAHM'] ?? null;
                if ($codeVal !== null) {
                    try { $d3 = $pdo->prepare("DELETE FROM `".str_replace('`','',$t)."` WHERE COALESCE(`code`,`matd`,`mahm`,`ma`) = ?"); $d3->execute([$codeVal]); } catch(Exception $e){}
                    try { $d4 = $pdo->prepare("DELETE FROM `".str_replace('`','',$t)."` WHERE `mahm` = ?"); $d4->execute([$codeVal]); } catch(Exception $e){}
                }
            }
        } catch(Exception $e) {
            @file_put_contents(__DIR__.'/save_project.log', date('c').' delete_hangmuc: task-delete error: '.$e->getMessage()."\n", FILE_APPEND);
        }
    }

    // finally delete parent(s)
    $sql = "DELETE FROM hangmuc WHERE (" . implode(' OR ', $whereParts) . ")";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $i => $val) $stmt->bindValue($i+1, $val);
    $stmt->execute();
    $affected = $stmt->rowCount();
    $pdo->commit();
    if ($affected>0) {
        echo json_encode(['success'=>true,'message'=>'Xóa hạng mục thành công'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success'=>false,'error'=>'Không tìm thấy bản ghi để xóa'], JSON_UNESCAPED_UNICODE);
    }

} catch(Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false,'error'=>'Lỗi: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>