<?php
ob_start(); error_reporting(0); ini_set('display_errors','0'); header('Content-Type: application/json; charset=utf-8');
function send($d){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
try{
  $id = trim($_GET['id'] ?? '');
  if($id==='') send(['success'=>false,'error'=>'Missing id']);

  $dbNames = ['quanlytiendo','quanlytiendo_db','construction_db']; $pdo=null; $usedDb=null; $tasksTable=null;
  $candidates = ['tasks','tiendo','tien_do','progress'];
  foreach($dbNames as $n){ try{ $tmp=new PDO("mysql:host=127.0.0.1;dbname={$n};charset=utf8mb4","root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); foreach($candidates as $t){ $s=$tmp->prepare("SHOW TABLES LIKE ?"); $s->execute([$t]); if($s->fetch()){ $pdo=$tmp; $usedDb=$n; $tasksTable=$t; break 2; } } $tmp=null;}catch(Exception $e){continue;} }
  if(!$pdo) send(['success'=>false,'error'=>'DB connection failed']);

  // discover columns
  $cols = $pdo->query("SHOW COLUMNS FROM {$tasksTable}")->fetchAll(PDO::FETCH_COLUMN);
  $lower = array_map('strtolower',$cols);
  $find = function($cands) use ($cols,$lower){ foreach($cands as $c){ $i=array_search(strtolower($c), $lower, true); if($i!==false) return $cols[$i]; } return null; };
  $idCol = $find(['id','ID','matd','MATD','code']);
  $codeCol = $find(['code','matd','MATD']);
  $progressCol = $find(['progress','PHANTRAM']);
  $startCol = $find(['start_date','NGAYBDTT','NGAYBD','NGAYBATDAU']);
  $endCol = $find(['end_date','NGAYKTTT','NGAYKT','NGAYKETTHUC']);
  $noteCol = $find(['description','note','ghichu','GHICHU']);

  $where = '';$params=[];
  if(preg_match('/^\d+$/',$id) && $idCol){ $where = "{$idCol} = ?"; $params[] = $id; }
  else if($codeCol){ $where = "COALESCE(`{$codeCol}`,`{$idCol}`) = ?"; $params[] = $id; }
  else { send(['success'=>false,'error'=>'No suitable id/ code column found']); }

  $select = [];
  $select[] = $idCol?"`{$idCol}` AS id":"NULL AS id";
  $select[] = $codeCol?"`{$codeCol}` AS code":"NULL AS code";
  $select[] = $progressCol?"`{$progressCol}` AS progress":"NULL AS progress";
  $select[] = $startCol?"`{$startCol}` AS start_date":"NULL AS start_date";
  $select[] = $endCol?"`{$endCol}` AS end_date":"NULL AS end_date";
  $select[] = $noteCol?"`{$noteCol}` AS note":"NULL AS note";

  $sql = "SELECT " . implode(',',$select) . " FROM `{$tasksTable}` WHERE {$where} LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $row = $stmt->fetch();
  if(!$row) send(['success'=>false,'error'=>'Not found']);
  send(['success'=>true,'data'=>$row]);
}catch(Exception $e){ send(['success'=>false,'error'=>'Error: '.$e->getMessage()]); }

?>
