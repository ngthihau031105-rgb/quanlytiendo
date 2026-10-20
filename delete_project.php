<?php
// delete_project.php
// Deletes a project (either `congtrinh` with MACT or `projects` by id)
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/auth.php';
// require logged in and enforce manager-only for deletes with logging
auth_require_logged_in();

// Quick session-level role check: block non-managers early (UX-level)
// Accept role stored in session under either $_SESSION['user']['role'] or $_SESSION['role']
$sessRole = '';
if (isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['role'])) {
    $sessRole = strtolower(trim((string)$_SESSION['user']['role']));
} elseif (isset($_SESSION['role'])) {
    $sessRole = strtolower(trim((string)$_SESSION['role']));
}
if ($sessRole !== 'manager') {
    if (function_exists('log_unauthorized_action')) {
        @log_unauthorized_action('delete', 'project', null, 'Bạn không có quyền làm điều này (session-role)');
    }
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Bạn không có quyền làm điều này'], JSON_UNESCAPED_UNICODE);
    exit;
}

function _log($t){ @file_put_contents(__DIR__.'/save_project.log', "[".date('Y-m-d H:i:s')."] DELETE: ".$t."\n", FILE_APPEND|LOCK_EX); }

if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', 'POST') !== 0) {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Yêu cầu POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

 $id = isset($_POST['id']) ? trim($_POST['id']) : '';
 if ($id === '') { echo json_encode(['success'=>false,'error'=>'Thiếu id'], JSON_UNESCAPED_UNICODE); exit; }

// Note: do NOT trust session role; we'll verify role from DB after connecting.

// Try possible DB names for compatibility with different installs
$dbNames = ['quanlytiendo','quanlytiendo_db'];
$pdo = null; $lastEx = null; $usedDb = null;
foreach ($dbNames as $dbName) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $usedDb=$dbName; break;
    } catch (PDOException $e) { $lastEx = $e; $pdo = null; }
}
if (!$pdo) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'Lỗi kết nối cơ sở dữ liệu: '.($lastEx->getMessage() ?? '')], JSON_UNESCAPED_UNICODE); exit; }

try {
    // Defensive: ensure session user's role in DB is manager (in case session was stale)
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
            if (function_exists('log_unauthorized_action')) @log_unauthorized_action('delete','project',$id,'Bạn không có quyền làm điều này (db-role)');
            http_response_code(403);
            echo json_encode(['success'=>false,'error'=>'Bạn không có quyền làm điều này'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch(Exception $e) {
        // continue and rely on earlier auth checks if DB role check fails
    }
    $tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $tableCheck->execute([$usedDb, 'congtrinh']);
    $has = (bool)$tableCheck->fetchColumn();

    // Use transaction to ensure we remove dependent rows first (child tables),
    // then delete parent. This avoids FK constraint errors when ON DELETE is not CASCADE.
    $pdo->beginTransaction();
    if ($has) {
        // find referencing foreign keys that point to congtrinh(MACT)
        $fkStmt = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.key_column_usage WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ? AND TABLE_SCHEMA = ?");
        $fkStmt->execute([$usedDb, 'congtrinh', 'MACT', $usedDb]);
        $refs = $fkStmt->fetchAll();
        foreach ($refs as $r) {
            $childTable = $r['TABLE_NAME'];
            $childCol = $r['COLUMN_NAME'];
            if (!$childTable || !$childCol) continue;
            // delete dependent rows
            try {
                $delChild = $pdo->prepare("DELETE FROM `".str_replace("`","",$childTable)."` WHERE `".str_replace("`","",$childCol)."` = ?");
                _log('Deleting child rows from '.$childTable.'.'.$childCol.' for parent MACT='.$id);
                $delChild->execute([$id]);
                _log('Deleted '.$delChild->rowCount().' rows from '.$childTable);
            } catch (Exception $e) {
                // rollback and return error
                $pdo->rollBack();
                http_response_code(500);
                _log('Child delete error: '.$e->getMessage());
                echo json_encode(['success'=>false,'error'=>'Lỗi xóa (bản ghi phụ): '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // now delete the parent row
        $del = $pdo->prepare('DELETE FROM congtrinh WHERE MACT = ?');
        _log('EXEC DELETE congtrinh MACT='.$id);
        $del->execute([$id]);
        $pdo->commit();
        echo json_encode(['success'=>true,'message'=>'Xóa công trình thành công','id'=>$id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // fallback to projects table (id)
    $fkStmt2 = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.key_column_usage WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ? AND TABLE_SCHEMA = ?");
    $fkStmt2->execute([$usedDb, 'projects', 'id', $usedDb]);
    $refs2 = $fkStmt2->fetchAll();
    foreach ($refs2 as $r) {
        $childTable = $r['TABLE_NAME'];
        $childCol = $r['COLUMN_NAME'];
        if (!$childTable || !$childCol) continue;
        try {
            $delChild = $pdo->prepare("DELETE FROM `".str_replace("`","",$childTable)."` WHERE `".str_replace("`","",$childCol)."` = ?");
            _log('Deleting child rows from '.$childTable.'.'.$childCol.' for parent id='.$id);
            $delChild->execute([$id]);
            _log('Deleted '.$delChild->rowCount().' rows from '.$childTable);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            _log('Child delete error: '.$e->getMessage());
            echo json_encode(['success'=>false,'error'=>'Lỗi xóa (bản ghi phụ): '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    // delete parent
    $del = $pdo->prepare('DELETE FROM projects WHERE id = ?');
    _log('EXEC DELETE projects id='.$id);
    $del->execute([$id]);
    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>'Xóa công trình thành công','id'=>$id], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    _log('error: '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>'Lỗi xóa: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
