<?php
header('Content-Type: application/json; charset=utf-8');
// api_chart_hangmuc.php
// manager-only
require_once __DIR__ . '/auth.php';
auth_require_role('manager');
$dbNames = ['quanlytiendo','quanlytiendo_db']; $pdo = null; $lastEx = null; $usedDb = null;
@file_put_contents(__DIR__.'/save_project.log', "[".date('c')."] api_chart_hangmuc.php called\n", FILE_APPEND|LOCK_EX);
foreach ($dbNames as $dbName) {
    try { $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $usedDb=$dbName; break; } catch (PDOException $e) { $lastEx=$e; $pdo=null; }
}
if (!$pdo) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'DB connect failed']); exit; }
try {
    // Support both english 'categories' and vietnamese 'hangmuc' + tasks 'tasks' or 'tiendo'
    $hasHang = false; $hangTable = null;
    $t = $pdo->prepare("SHOW TABLES LIKE ?"); $t->execute(['hangmuc']); if ($t->fetch()) { $hasHang = true; $hangTable = 'hangmuc'; }
    if ($hasHang) {
        // fetch hangmuc and tiendo rows and aggregate in PHP
        $hangs = $pdo->query('SELECT * FROM hangmuc')->fetchAll(PDO::FETCH_ASSOC);
        $total = count($hangs);
        $tiendoRows = [];
        $s = $pdo->prepare("SHOW TABLES LIKE ?"); $s->execute(['tiendo']); if($s->fetch()) { $tiendoRows = $pdo->query('SELECT * FROM tiendo')->fetchAll(PDO::FETCH_ASSOC); }
        // helper
        $get = function($row, $keys, $def=null){ foreach($keys as $k){ if(array_key_exists($k,$row)) return $row[$k]; $lk=strtolower($k); foreach($row as $rk=>$rv){ if(strtolower($rk)=== $lk) return $rv; } } return $def; };

        // build map of hangmuc by code
        $hangMap = []; foreach($hangs as $h){ $key = $get($h, ['MAHM','mahm','code','id']); $hangMap[(string)$key] = $h; }

        // compute completed: hạng mục whose all related tiến độ have progress >=100
        $completed = 0; $late = 0; $upcoming = 0;
        foreach($hangs as $h){ $key = (string)$get($h,['MAHM','mahm','code','id']); $related = array_filter($tiendoRows, function($t) use($key,&$get){ $v=$get($t,['MAHM','mahm','ma_hm','category_id']); return (string)$v === (string)$key; });
            if(empty($related)) continue;
            $allDone = true; $hasLate=false; $hasUpcoming=false;
            foreach($related as $t){ $p = (float)$get($t,['PHANTRAM','phantram','progress'],0); if($p < 100) $allDone=false; $end = $get($t,['NGAYKT','end_date'],null); if($end && strtotime($end) < strtotime(date('Y-m-d')) && $p < 100) $hasLate=true; $start=$get($t,['NGAYBD','start_date'],null); if($start && strtotime($start) > strtotime(date('Y-m-d'))) $hasUpcoming=true; }
            if($allDone) $completed++; if($hasLate) $late++; if($hasUpcoming) $upcoming++; }

        // chart2: status distribution from tiendo
        $chart2Map = []; foreach($tiendoRows as $t){ $label = $get($t,['TRANGTHAI','status'],'unknown'); $chart2Map[$label] = ($chart2Map[$label] ?? 0) + 1; }
        $chart2 = []; foreach($chart2Map as $k=>$v) $chart2[]=['label'=>$k,'value'=>$v];

        $totalCats = $total; $lateCats = $late; $onTime = max(0, $totalCats - $lateCats);
        $chart1 = [ ['label'=>'On time','value'=>$onTime], ['label'=>'Late','value'=>$lateCats] ];

        // chart3: created per month if created_at exists
        $chart3 = [];
        foreach($hangs as $h){ $created = $get($h,['created_at','ngaytao','created'],null); if(!$created) continue; $m = date('Y-m', strtotime($created)); $chart3[$m]=($chart3[$m]??0)+1; }
        ksort($chart3);
        $chart3 = array_map(function($k,$v){ return ['label'=>$k,'value'=>$v]; }, array_keys($chart3), $chart3);
    } else {
        // english categories/tasks - aggregate in PHP
        $cats = $pdo->query('SELECT * FROM categories')->fetchAll(PDO::FETCH_ASSOC);
        $total = count($cats);
        $tasks = [];
        $s = $pdo->prepare("SHOW TABLES LIKE ?"); $s->execute(['tasks']); if($s->fetch()){ $tasks = $pdo->query('SELECT * FROM tasks')->fetchAll(PDO::FETCH_ASSOC); }
        $get = function($row, $keys, $def=null){ foreach($keys as $k){ if(array_key_exists($k,$row)) return $row[$k]; $lk=strtolower($k); foreach($row as $rk=>$rv){ if(strtolower($rk)=== $lk) return $rv; } } return $def; };
        $completed=0; $late=0; $upcoming=0;
        foreach($cats as $c){ $cid = $get($c,['id','ID','category_id']); $related = array_filter($tasks, function($t) use($cid,$get){ return isset($t['category_id']) && (string)$t['category_id'] === (string)$cid; }); if(empty($related)) continue; $allDone=true; $hasLate=false; $hasUpcoming=false; foreach($related as $t){ $p=(float)$get($t,['progress','PHANTRAM'],0); if($p<100) $allDone=false; $end=$get($t,['end_date','NGAYKT'],null); if($end && strtotime($end) < strtotime(date('Y-m-d')) && $p<100) $hasLate=true; $start=$get($t,['start_date','NGAYBD'],null); if($start && strtotime($start) > strtotime(date('Y-m-d'))) $hasUpcoming=true; } if($allDone) $completed++; if($hasLate) $late++; if($hasUpcoming) $upcoming++; }
        $chart2Map=[]; foreach($tasks as $t){ $label = $get($t,['status','TRANGTHAI'],'unknown'); $chart2Map[$label]=($chart2Map[$label]??0)+1; } $chart2=[]; foreach($chart2Map as $k=>$v) $chart2[]=['label'=>$k,'value'=>$v];
        $totalCats=$total; $lateCats=$late; $onTime = max(0,$totalCats-$lateCats); $chart1 = [['label'=>'On time','value'=>$onTime],['label'=>'Late','value'=>$lateCats]];
        $chart3=[]; foreach($cats as $c){ $created = $get($c,['created_at','ngaytao'],null); if(!$created) continue; $m = date('Y-m', strtotime($created)); $chart3[$m]=($chart3[$m]??0)+1; } ksort($chart3); $chart3 = array_map(function($k,$v){ return ['label'=>$k,'value'=>$v]; }, array_keys($chart3), $chart3);
    }

    echo json_encode(['success'=>true,'data'=>['summary'=>['total'=>$total,'completed'=>$completed,'late'=>$late,'upcoming'=>$upcoming],'chart1'=>$chart1,'chart2'=>$chart2,'chart3'=>$chart3]], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit; }
?>