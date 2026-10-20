<?php
ob_start();
error_reporting(0);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

function sendJson($d){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($d, JSON_UNESCAPED_UNICODE);
  exit;
}

try{
  $page = max(1, (int)($_GET['page']??1));
  $pageSize = max(1, min(1000, (int)($_GET['pageSize']??50)));
  $offset = ($page-1)*$pageSize;

  $dbNames = ['quanlytiendo','quanlytiendo_db','construction_db'];
  $pdo = null; $usedDb = null; $tasksTable = null;

  // candidate table names for tasks/progress
  $candidates = ['tasks','tiendo','tien_do','progress'];

  // Try each database: connect then verify it actually contains a tasks-like table.
  foreach($dbNames as $n){
    try{
      $tmp = new PDO("mysql:host=127.0.0.1;dbname={$n};charset=utf8mb4", 'root','', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
      // check for tasks table in this DB
      foreach($candidates as $t){
        $stmt = $tmp->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$t]);
        if($stmt->fetch()){
          $pdo = $tmp;
          $usedDb = $n;
          $tasksTable = $t;
          break 2; // found DB with tasks table
        }
      }
      // no tasks table here; close tmp and continue
      $tmp = null;
    }catch(Exception $e){
      continue;
    }
  }

  if(!$pdo){
    sendJson(['success'=>false,'error'=>'Không thể kết nối database hoặc không tìm thấy bảng tiến độ trong các DB được cấu hình']);
  }

  // try to join hangmuc/categories for display
  $hasHangmuc = false; $hangmucTable = null;
  foreach(['hangmuc','categories'] as $ht){ $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$ht]); if($s->fetch()){ $hasHangmuc=true; $hangmucTable=$ht; break; } }

  // Discover actual columns in tasks table to avoid referencing non-existing columns
  $taskCols = $pdo->query("SHOW COLUMNS FROM {$tasksTable}")->fetchAll(PDO::FETCH_COLUMN);
  $taskColsLower = array_map('strtolower', $taskCols);

  // helper to find real column name by candidates
  $findCol = function($cands) use ($taskCols, $taskColsLower){
    foreach($cands as $c){
      $idx = array_search(strtolower($c), $taskColsLower, true);
      if($idx !== false) return $taskCols[$idx];
    }
    return null;
  };

  // choose columns
  $idCol = $findCol(['id','ID','matd','MATD','code']);
  $codeCol = $findCol(['code','matd','MATD']);
  $progressCol = $findCol(['progress','PHANTRAM']);
  $startCol = $findCol(['start_date','NGAYBDTT','NGAYBD','NGAYBATDAU']);
  $endCol = $findCol(['end_date','NGAYKTTT','NGAYKT','NGAYKETTHUC']);
  $noteCol = $findCol(['description','note','ghichu','GHICHU']);

  // Build SELECT parts with safe backticks for existing columns; fallbacks to NULL
  $selectParts = [];
  if($idCol) $selectParts[] = "t.`{$idCol}` AS id"; else $selectParts[] = "NULL AS id";
  if($codeCol) $selectParts[] = "t.`{$codeCol}` AS code"; else $selectParts[] = "NULL AS code";
  if($progressCol) $selectParts[] = "t.`{$progressCol}` AS progress"; else $selectParts[] = "NULL AS progress";
  if($startCol) $selectParts[] = "t.`{$startCol}` AS start_date"; else $selectParts[] = "NULL AS start_date";
  if($endCol) $selectParts[] = "t.`{$endCol}` AS end_date"; else $selectParts[] = "NULL AS end_date";
  if($noteCol) $selectParts[] = "t.`{$noteCol}` AS note"; else $selectParts[] = "NULL AS note";

  // Prepare join with hangmuc if available by finding matching keys
  $joinClause = '';
  $mahmSelects = ['NULL AS mahm_id','NULL AS mahm_code','NULL AS mahm_name'];
  if($hasHangmuc){
    $hangCols = $pdo->query("SHOW COLUMNS FROM {$hangmucTable}")->fetchAll(PDO::FETCH_COLUMN);
    $hangColsLower = array_map('strtolower', $hangCols);
    $findHangCol = function($cands) use ($hangCols, $hangColsLower){
      foreach($cands as $c){ $idx = array_search(strtolower($c), $hangColsLower, true); if($idx !== false) return $hangCols[$idx]; }
      return null;
    };

    // possible keys on tasks to join with hangmuc
    $taskKey = $findCol(['mahm','MAHM','mahm_code','category_id','hangmuc_id']);
    $hangKey = $findHangCol(['id','ID','mahm','MAHM','code']);

    if($taskKey && $hangKey){
      $joinClause = " LEFT JOIN {$hangmucTable} h ON (h.`{$hangKey}` = t.`{$taskKey}`)";
      // pick hangmuc display columns
      $hang_code = $findHangCol(['code','MAHM','mahm','ma']);
      $hang_name = $findHangCol(['name','TENHM','ten','title']);
      $mahmSelects = [];
      $mahmSelects[] = $hangKey ? "h.`{$hangKey}` AS mahm_id" : "NULL AS mahm_id";
      $mahmSelects[] = $hang_code ? "h.`{$hang_code}` AS mahm_code" : "NULL AS mahm_code";
      $mahmSelects[] = $hang_name ? "h.`{$hang_name}` AS mahm_name" : "NULL AS mahm_name";
    }
  }

  $selectParts = array_merge($selectParts, $mahmSelects);

  $sql = "SELECT " . implode(', ', $selectParts) . " FROM {$tasksTable} t" . $joinClause . " ORDER BY ";
  // order by id or code if possible
  if($idCol) $sql .= "t.`{$idCol}` DESC";
  elseif($codeCol) $sql .= "t.`{$codeCol}` DESC";
  else $sql .= "1";

  $sql .= " LIMIT ? OFFSET ?";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(1, (int)$pageSize, PDO::PARAM_INT);
  $stmt->bindValue(2, (int)$offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll();

  sendJson(['success'=>true,'data'=>$rows,'page'=>$page,'pageSize'=>$pageSize,'meta'=>['db'=>$usedDb,'tasksTable'=>$tasksTable,'hasHangmuc'=>$hasHangmuc,'rows'=>count($rows)]]);

}catch(Exception $e){ sendJson(['success'=>false,'error'=>'Lỗi hệ thống: '. $e->getMessage()]); }

?>
