<?php
header('Content-Type: application/json; charset=utf-8');
// api_chart_tiendo.php
// manager-only
require_once __DIR__ . '/auth.php';
auth_require_role('manager');
$dbNames = ['quanlytiendo','quanlytiendo_db']; $pdo=null; $usedDb=null; $lastEx=null;
@file_put_contents(__DIR__.'/save_project.log', "[".date('c')."] api_chart_tiendo.php called\n", FILE_APPEND|LOCK_EX);
foreach ($dbNames as $dbName) { try { $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $usedDb=$dbName; break;} catch (PDOException $e){ $lastEx=$e; $pdo=null;} }
if (!$pdo) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'DB connect failed']); exit; }
try {
    // support both 'tasks' and Vietnamese 'tiendo' with hangmuc/congtrinh
    $taskTable = null; foreach(['tiendo','tasks','tien_do','progress'] as $tc){ $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$tc]); if($s->fetch()){ $taskTable=$tc; break; } }
    if (!$taskTable) { echo json_encode(['success'=>false,'error'=>'No tasks/tiendo table found']); exit; }

    // Fetch all task rows and compute aggregates in PHP to avoid schema-specific SQL
    $allTasks = $pdo->query("SELECT * FROM {$taskTable}")->fetchAll(PDO::FETCH_ASSOC);
    $total_td = count($allTasks);

    // helper to read a field case-insensitively
    $getField = function($row, $candidates, $default=null){
        foreach($candidates as $c){
            if(array_key_exists($c, $row)) return $row[$c];
            $lc = strtolower($c);
            foreach($row as $rk=>$rv){ if(strtolower($rk) === $lc) return $rv; }
        }
        return $default;
    };

    // compute progress values
    $progressVals = [];
    foreach($allTasks as $r){
        $p = $getField($r, ['progress','PHANTRAM','phantram','percent','progress_pct'], 0);
        $p = is_numeric($p) ? (float)$p : 0;
        $progressVals[] = $p;
    }
    $avg_pct = $total_td ? round(array_sum($progressVals)/$total_td,1) : 0;
    $finished = count(array_filter($progressVals, function($v){ return $v >= 100; }));
    $at_risk = count(array_filter($progressVals, function($v){ return $v < 50; }));

    // Build chart1 buckets
    $buckets = [ ['label'=>'0-49','value'=>0], ['label'=>'50-79','value'=>0], ['label'=>'80-99','value'=>0], ['label'=>'100','value'=>0] ];
    foreach($progressVals as $v){
        if($v < 50) $buckets[0]['value']++;
        elseif($v < 80) $buckets[1]['value']++;
        elseif($v < 100) $buckets[2]['value']++;
        else $buckets[3]['value']++;
    }

    // Prepare hangmuc map if exists to compute avg per project/group
    $hasHang = false; $hangMap = [];
    $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute(['hangmuc']); if($s->fetch()){ $hasHang=true; $hangs = $pdo->query('SELECT * FROM hangmuc')->fetchAll(PDO::FETCH_ASSOC); foreach($hangs as $h){ $key = $getField($h, ['MAHM','mahm','code','id']); $hangMap[(string)$key] = $h; } }

    // chart2: average progress per project (group by project name inferred via hangmuc or categories)
    $grouped = [];
    foreach($allTasks as $r){
        // determine group label
        $label = null;
        if($hasHang){
            $mahm = $getField($r, ['MAHM','mahm','ma_hm']);
            $h = isset($hangMap[(string)$mahm]) ? $hangMap[(string)$mahm] : null;
            if($h){ $label = $getField($h, ['TENCT','TENHM','name','TENHM']); }
        }
        if(!$label){
            // try categories/projects
            $catId = $getField($r, ['category_id','category','cat_id','mahm'], null);
            if($catId){
                // try to find category row
                try{ $cat = $pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1'); $cat->execute([$catId]); $c = $cat->fetch(PDO::FETCH_ASSOC); if($c) $label = $getField($c, ['name','TENHM','TENHM']); }catch(Exception $e){}
            }
        }
        if(!$label) $label = 'Khác';
        if(!isset($grouped[$label])) $grouped[$label] = ['sum'=>0,'cnt'=>0];
        $p = $getField($r, ['progress','PHANTRAM','phantram','percent'], 0); $p = is_numeric($p)?(float)$p:0;
        $grouped[$label]['sum'] += $p; $grouped[$label]['cnt']++;
    }
    $chart2 = [];
    foreach($grouped as $lbl=>$g){ $chart2[] = ['label'=>$lbl,'value'=> round($g['sum'] / max(1,$g['cnt']),1)]; }
    usort($chart2, function($a,$b){ return $b['value'] <=> $a['value']; }); $chart2 = array_slice($chart2,0,10);

    // chart3: top categories/hangmuc with delayed tasks
    $delays = [];
    foreach($allTasks as $r){
        $p = $getField($r, ['progress','PHANTRAM','phantram'], 0); $p = is_numeric($p)?(float)$p:0;
        $end = $getField($r, ['NGAYKT','end_date','NGAYKTTT'], null);
        if($end && strtotime($end) < strtotime(date('Y-m-d')) && $p < 100){
            $mahm = $getField($r, ['MAHM','mahm','ma_hm','category_id']);
            $label = $mahm;
            if($hasHang && isset($hangMap[(string)$mahm])){ $h = $hangMap[(string)$mahm]; $label = $getField($h, ['TENHM','name','TENHM']); }
            if(!isset($delays[$label])) $delays[$label]=0; $delays[$label]++;
        }
    }
    $chart3 = [];
    foreach($delays as $lbl=>$v) $chart3[]=['label'=>$lbl,'value'=>$v];
    usort($chart3, function($a,$b){ return $b['value'] <=> $a['value']; }); $chart3 = array_slice($chart3,0,10);

    echo json_encode(['success'=>true,'data'=>['summary'=>['total_td'=>$total_td,'avg_pct'=>round($avg_pct,1),'finished'=>$finished,'at_risk'=>$at_risk],'chart1'=>$buckets,'chart2'=>$chart2,'chart3'=>$chart3]], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit; }
?>