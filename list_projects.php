<?php
// list_projects.php
// Returns JSON list of projects (tries `congtrinh` first, then `projects`)
header('Content-Type: application/json; charset=utf-8');
// Require login and apply simple RBAC: managers see all, employees see only projects
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
$currentUser = auth_get_user();
$currentUid = $currentUser['id'] ?? null;
$currentRole = strtolower($currentUser['role'] ?? '');
// simple logging for debug
function _lp_log($t){ @file_put_contents(__DIR__.'/save_project.log', "[".date('Y-m-d H:i:s')."] LIST: ".$t."\n", FILE_APPEND|LOCK_EX); }

 $dbNames = ['quanlytiendo','quanlytiendo_db'];
 $pdo = null; $lastEx = null; $usedDb = null;
 foreach ($dbNames as $dbName) {
     try {
         $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
         $usedDb = $dbName; break;
     } catch (PDOException $e) { $lastEx = $e; $pdo = null; }
}
if (!$pdo) {
    http_response_code(500);
    $msg=''; if($lastEx instanceof Exception) $msg=$lastEx->getMessage();
    echo json_encode(['success'=>false,'error'=>'DB connect failed: '. $msg]);
    exit;
}
try {
    $tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $tableCheck->execute([$usedDb, 'congtrinh']);
    $has = (bool)$tableCheck->fetchColumn();

    // read query params
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $qname = isset($_GET['qname']) ? trim($_GET['qname']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $sort = isset($_GET['sort']) ? trim($_GET['sort']) : '';
    $page = isset($_GET['page']) && (int)$_GET['page']>0 ? (int)$_GET['page'] : 1;
    $pageSize = isset($_GET['pageSize']) && (int)$_GET['pageSize']>0 ? min(100,(int)$_GET['pageSize']) : 10;
    $offset = ($page-1)*$pageSize;

    try {
        if ($has) {
            // build WHERE clauses for congtrinh
            $clauses = [];
            $params = [];
            if ($qname !== '') {
                $clauses[] = 'TENCT LIKE ?'; $params[] = "%$qname%";
            } elseif ($q !== '') {
                // allow searching by MACT (code) or name/location/description
                $clauses[] = '(MACT LIKE ? OR TENCT LIKE ? OR DIADIEM LIKE ? OR MOTA LIKE ?)'; $params = ["%$q%","%$q%","%$q%","%$q%"];
            }
            // status filter based on dates
            if ($status !== '') {
                $s = strtolower($status);
                if ($s === 'completed' || $s === 'da_hoan_thanh' || $s === 'đã_hoàn_thành') {
                    $clauses[] = 'NGAYKT < CURDATE()';
                } elseif ($s === 'in_progress' || $s === 'dang_hoan_thanh' || $s === 'đang_hoàn_thành') {
                    $clauses[] = 'NGAYBD <= CURDATE() AND NGAYKT >= CURDATE()';
                } elseif ($s === 'pending' || $s === 'cho_xu_ly' || $s === 'chờ_xử_lý') {
                    $clauses[] = 'NGAYBD > CURDATE()';
                }
            }
            $where = empty($clauses) ? '1=1' : implode(' AND ', $clauses);
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM congtrinh WHERE $where");
            $countStmt->execute($params); $total = (int)$countStmt->fetchColumn();

            // sort mapping
            // Default: order by MACT (project code) ascending to match DB code order CT001..CT020
            $order = 'MACT ASC';
            if ($sort === 'start_asc') $order = 'NGAYBD ASC';
            if ($sort === 'start_desc') $order = 'NGAYBD DESC';
            if ($sort === 'end_asc') $order = 'NGAYKT ASC';
            if ($sort === 'end_desc') $order = 'NGAYKT DESC';
            if ($sort === 'code_asc') $order = 'MACT ASC';
            if ($sort === 'code_desc') $order = 'MACT DESC';

            // Do not restrict projects by assigned user here — show all projects to any logged-in user
            $sql = "SELECT MACT as id, TENCT as name, DIADIEM as location, NGANSACH as budget, MOTA as description, NGAYBD as start_date, NGAYKT as end_date, CASE WHEN NGAYKT < CURDATE() THEN 'completed' WHEN NGAYBD > CURDATE() THEN 'pending' WHEN NGAYBD <= CURDATE() AND NGAYKT >= CURDATE() THEN 'in_progress' ELSE '' END as status FROM congtrinh WHERE $where ORDER BY $order LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($sql);
            // bind search params as strings
            $bindIndex = 1;
            foreach ($params as $pval) {
                $stmt->bindValue($bindIndex, $pval, PDO::PARAM_STR);
                $bindIndex++;
            }
            // bind limit/offset as integers to avoid quoted numbers in SQL (MariaDB error)
            $stmt->bindValue($bindIndex, (int)$pageSize, PDO::PARAM_INT); $bindIndex++;
            $stmt->bindValue($bindIndex, (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            echo json_encode(['success'=>true,'total'=>$total,'page'=>$page,'pageSize'=>$pageSize,'count'=>count($rows),'data'=>$rows], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // fallback to projects table
        $clauses2 = [];
        $params2 = [];
        if ($qname !== '') {
            $clauses2[] = 'name LIKE ?'; $params2[] = "%$qname%";
        } elseif ($q !== '') {
            // search by id/code or name/description
            $clauses2[] = '(id LIKE ? OR name LIKE ? OR description LIKE ?)'; $params2 = ["%$q%","%$q%","%$q%"];
        }
        if ($status !== '') {
            // assume caller passes status values that match DB; fallback: pass through
            $clauses2[] = 'status = ?'; $params2[] = $status;
        }
        $where2 = empty($clauses2) ? '1=1' : implode(' AND ', $clauses2);
        $countStmt2 = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE $where2");
        $countStmt2->execute($params2); $total2 = (int)$countStmt2->fetchColumn();

        // For projects table default to id ascending (closest to code order)
        $order2 = 'id ASC';
        if ($sort === 'start_asc') $order2 = 'start_date ASC';
        if ($sort === 'start_desc') $order2 = 'start_date DESC';
        if ($sort === 'end_asc') $order2 = 'end_date ASC';
        if ($sort === 'end_desc') $order2 = 'end_date DESC';
        if ($sort === 'code_asc') $order2 = 'id ASC';
        if ($sort === 'code_desc') $order2 = 'id DESC';

        $sql2 = "SELECT id, name, description, start_date, end_date, status FROM projects WHERE $where2 ORDER BY $order2 LIMIT ? OFFSET ?";
        $stmt2 = $pdo->prepare($sql2);
        $bindIndex2 = 1;
        foreach ($params2 as $pval) {
            $stmt2->bindValue($bindIndex2, $pval, PDO::PARAM_STR);
            $bindIndex2++;
        }
        $stmt2->bindValue($bindIndex2, (int)$pageSize, PDO::PARAM_INT); $bindIndex2++;
        $stmt2->bindValue($bindIndex2, (int)$offset, PDO::PARAM_INT);
        $stmt2->execute();
        $rows2 = $stmt2->fetchAll();
        echo json_encode(['success'=>true,'total'=>$total2,'page'=>$page,'pageSize'=>$pageSize,'count'=>count($rows2),'data'=>$rows2], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        _lp_log('list error: '.$e->getMessage());
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    _lp_log('list error: '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    exit;
}

?>
