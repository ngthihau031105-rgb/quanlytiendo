<?php
header('Content-Type: application/json; charset=utf-8');
// api_chart_congtrinh.php
// manager-only: restrict access to reporting/chart endpoints
require_once __DIR__ . '/auth.php';
auth_require_role('manager');
@file_put_contents(__DIR__.'/save_project.log', "[".date('c')."] api_chart_congtrinh.php called\n", FILE_APPEND|LOCK_EX);
// Returns summary + chart1/2/3 for projects/congtrinh
$dbNames = ['quanlytiendo','quanlytiendo_db'];
$pdo = null; $lastEx = null; $usedDb = null;
foreach ($dbNames as $dbName) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $usedDb = $dbName; break;
    } catch (PDOException $e) { $lastEx = $e; $pdo = null; }
}
if (!$pdo) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'DB connect failed']); exit; }
try {
    // detect table
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $stmt->execute([$usedDb, 'congtrinh']);
    $has = (bool)$stmt->fetchColumn();

    if ($has) {
        // congtrinh schema may have NGANSACH, TENCT, NGAYBD, NGAYKT
        // summary
        $total = (int)$pdo->query('SELECT COUNT(*) FROM congtrinh')->fetchColumn();
        $budgetSum = 0;
        $hasBudget = false;
        // check if NGANSACH column exists
        $colStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
        $colStmt->execute([$usedDb, 'congtrinh', 'NGANSACH']);
        if ((int)$colStmt->fetchColumn() > 0) {
            $hasBudget = true;
            $budgetSum = (float)$pdo->query('SELECT COALESCE(SUM(NGANSACH),0) FROM congtrinh')->fetchColumn();
        }
        $active = (int)$pdo->query("SELECT COUNT(*) FROM congtrinh WHERE NGAYBD <= CURDATE() AND NGAYKT >= CURDATE()")->fetchColumn();
        $late = (int)$pdo->query("SELECT COUNT(*) FROM congtrinh WHERE NGAYKT < CURDATE()")->fetchColumn();

        // chart2: top 5 budget or fallback to count of hangmuc per congtrinh
        if ($hasBudget) {
            $q = $pdo->query("SELECT TENCT as label, NGANSACH as value FROM congtrinh ORDER BY NGANSACH DESC LIMIT 5");
            $chart2 = $q->fetchAll();
        } else {
            // try Vietnamese hangmuc table
            $hasHang = false; $hangTable = null;
            $t = $pdo->prepare("SHOW TABLES LIKE ?");
            $t->execute(['hangmuc']); if ($t->fetch()) { $hasHang = true; $hangTable = 'hangmuc'; }
            if ($hasHang) {
                $q = $pdo->query("SELECT p.TENCT as label, COUNT(h.MAHM) as value FROM congtrinh p LEFT JOIN hangmuc h ON h.MACT = p.MACT GROUP BY p.MACT ORDER BY value DESC LIMIT 5");
                $chart2 = $q->fetchAll();
            } else {
                // attempt english categories
                $q = $pdo->query("SELECT p.TENCT as label, COUNT(c.id) as value FROM congtrinh p LEFT JOIN categories c ON c.project_id = p.id GROUP BY p.MACT ORDER BY value DESC LIMIT 5");
                $chart2 = $q->fetchAll();
            }
        }

        // chart1: status distribution
        $statusQ = $pdo->query("SELECT CASE WHEN NGAYKT < CURDATE() THEN 'completed' WHEN NGAYBD > CURDATE() THEN 'pending' WHEN NGAYBD <= CURDATE() AND NGAYKT >= CURDATE() THEN 'in_progress' ELSE 'other' END as label, COUNT(*) as value FROM congtrinh GROUP BY label");
        $chart1 = $statusQ->fetchAll();

        // chart3: density of categories/hạng mục per project
        $t2 = $pdo->prepare("SHOW TABLES LIKE ?");
        $chart3 = [];
        $t2->execute(['hangmuc']);
        if ($t2->fetch()) {
            $q3 = $pdo->query("SELECT p.TENCT as label, COUNT(h.MAHM) as value FROM congtrinh p LEFT JOIN hangmuc h ON h.MACT = p.MACT GROUP BY p.MACT ORDER BY value DESC LIMIT 10");
            $chart3 = $q3->fetchAll();
        } else {
            $q3 = $pdo->query("SELECT p.TENCT as label, COUNT(c.id) as value FROM congtrinh p LEFT JOIN categories c ON c.project_id = p.id GROUP BY p.MACT ORDER BY value DESC LIMIT 10");
            $chart3 = $q3->fetchAll();
        }

        echo json_encode(['success'=>true,'data'=>['summary'=>['total'=>$total,'budget'=>$budgetSum,'active'=>$active,'late'=>$late],'chart1'=>$chart1,'chart2'=>$chart2,'chart3'=>$chart3]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // fallback to projects table
    $total = (int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    $budgetSum = 0; // projects table doesn't have budget by default
    $active = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE status = 'active'")->fetchColumn();
    $late = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE end_date < CURDATE()")->fetchColumn();

    // chart2: top 5 by number of categories
    $q = $pdo->query("SELECT p.name as label, COUNT(c.id) as value FROM projects p LEFT JOIN categories c ON c.project_id = p.id GROUP BY p.id ORDER BY value DESC LIMIT 5");
    $chart2 = $q->fetchAll();

    // chart1: status distribution
    $statusQ = $pdo->query("SELECT status as label, COUNT(*) as value FROM projects GROUP BY status");
    $chart1 = $statusQ->fetchAll();

    // chart3: density of categories per project
    $q3 = $pdo->query("SELECT p.name as label, COUNT(c.id) as value FROM projects p LEFT JOIN categories c ON c.project_id = p.id GROUP BY p.id ORDER BY value DESC LIMIT 10");
    $chart3 = $q3->fetchAll();

    echo json_encode(['success'=>true,'data'=>['summary'=>['total'=>$total,'budget'=>$budgetSum,'active'=>$active,'late'=>$late],'chart1'=>$chart1,'chart2'=>$chart2,'chart3'=>$chart3]], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    exit;
}
?>