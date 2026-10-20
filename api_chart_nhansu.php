<?php
header('Content-Type: application/json; charset=utf-8');
// api_chart_nhansu.php
// manager-only
require_once __DIR__ . '/auth.php';
auth_require_role('manager');
$dbNames = ['quanlytiendo','quanlytiendo_db']; $pdo = null; $usedDb=null; $lastEx=null;
@file_put_contents(__DIR__.'/save_project.log', "[".date('c')."] api_chart_nhansu.php called\n", FILE_APPEND|LOCK_EX);
foreach ($dbNames as $dbName) { try { $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $usedDb=$dbName; break;} catch (PDOException $e){ $lastEx=$e; $pdo=null;} }
if (!$pdo) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'DB connect failed']); exit; }
try {
    // Support both 'users' and 'nhanvien' tables and tasks 'tasks' or 'tiendo'
    $hasNhan = false; $nhanTable = null;
    $t = $pdo->prepare("SHOW TABLES LIKE ?"); $t->execute(['nhanvien']); if ($t->fetch()) { $hasNhan = true; $nhanTable = 'nhanvien'; }
    if ($hasNhan) {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM nhanvien')->fetchColumn();
        // try to map roles if column exists
        $cols = $pdo->query("SHOW COLUMNS FROM nhanvien")->fetchAll(PDO::FETCH_COLUMN);
        $colsLower = array_map('strtolower',$cols);
        $roleCol = in_array('chucvu',$colsLower)?'chucvu':(in_array('role',$colsLower)?'role':null);
        if ($roleCol) {
            $q1 = $pdo->query("SELECT `{$roleCol}` as label, COUNT(*) as value FROM nhanvien GROUP BY `{$roleCol}`");
            $chart1 = $q1->fetchAll();
        } else {
            $chart1 = [['label'=>'staff','value'=>$total]];
        }

        // workload: count tiendo assigned to MANV or assigned_to
        $taskTable = null; $taskCandidates = ['tiendo','tasks'];
        foreach($taskCandidates as $tc){ $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$tc]); if($s->fetch()){ $taskTable=$tc; break; } }
        $chart2 = [];
        if ($taskTable) {
            // find assigned column
            $tcols = $pdo->query("SHOW COLUMNS FROM {$taskTable}")->fetchAll(PDO::FETCH_COLUMN);
            $tcolsLower = array_map('strtolower',$tcols);
            $assignCol = null;
            foreach(['manv','assigned_to','user_id','nguoi','nguoi_phu_trach'] as $cand) { $idx=array_search($cand,$tcolsLower); if($idx!==false){ $assignCol=$tcols[$idx]; break; } }
            if ($assignCol) {
                // Find best matching key column on nhanvien to join with task assigned column
                $nKey = null;
                if (in_array(strtolower($assignCol), $colsLower)) {
                    $nKey = $assignCol; // same column exists on nhanvien
                } else {
                    $fallbacks = ['id','manv','ma','uid','nv_id','nhanvien_id'];
                    foreach ($fallbacks as $f) {
                        $idx2 = array_search($f, $colsLower);
                        if ($idx2 !== false) { $nKey = $cols[$idx2]; break; }
                    }
                }
                if (!$nKey) { $nKey = $cols[0]; }

                $q2 = $pdo->query(
                    "SELECT COALESCE(n.TENNV, n.name, n.ten, n.TEN) as label, COUNT(t.`$assignCol`) as value FROM {$nhanTable} n LEFT JOIN {$taskTable} t ON t.`$assignCol` = n.`$nKey` GROUP BY n.`$nKey` ORDER BY value DESC LIMIT 7"
                );
                $chart2 = $q2->fetchAll();
            }
        }

        // chart3 overall done vs pending
        if ($taskTable) {
            $pCol = null; foreach(['phantram','progress'] as $c) { $idx=array_search($c,$tcolsLower); if($idx!==false){ $pCol=$tcols[$idx]; break; } }
            if ($pCol) {
                $done = (int)$pdo->query("SELECT COUNT(*) FROM {$taskTable} WHERE COALESCE(`{$pCol}`,0) >= 100")->fetchColumn();
                $pending = (int)$pdo->query("SELECT COUNT(*) FROM {$taskTable} WHERE COALESCE(`{$pCol}`,0) < 100")->fetchColumn();
                $chart3 = [ ['label'=>'Done','value'=>$done], ['label'=>'Pending','value'=>$pending] ];
            } else {
                $chart3 = [ ['label'=>'Done','value'=>0], ['label'=>'Pending','value'=>0] ];
            }
        } else {
            $chart2 = [];
            $chart3 = [];
        }
        $engineers = 0; $supervisors = 0; $workers = $total;
        $managers = 0; $employees = $total;
        $supervisors = $managers; $workers = $employees;
    } else {
        // english users/tasks
        $total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $managers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='manager'")->fetchColumn();
        $employees = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='employee'")->fetchColumn();
        $engineers = 0; $supervisors = $managers; $workers = $employees;
        $q2 = $pdo->query("SELECT u.name as label, COUNT(t.id) as value, u.id as uid FROM users u LEFT JOIN tasks t ON t.assigned_to = u.id GROUP BY u.id ORDER BY value DESC LIMIT 7");
        $chart2 = $q2->fetchAll();
        $q1 = $pdo->query("SELECT role as label, COUNT(*) as value FROM users GROUP BY role"); $chart1 = $q1->fetchAll();
        $done = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE progress >= 100")->fetchColumn();
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE progress < 100")->fetchColumn();
        $chart3 = [ ['label'=>'Done','value'=>$done], ['label'=>'Pending','value'=>$pending] ];
    }

    echo json_encode(['success'=>true,'data'=>['summary'=>['total'=>$total,'engineers'=>$engineers,'supervisors'=>$supervisors,'workers'=>$workers],'chart1'=>$chart1,'chart2'=>$chart2,'chart3'=>$chart3]], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit; }
?>