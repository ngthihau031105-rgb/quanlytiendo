<?php
ob_start(); error_reporting(0); ini_set('display_errors','0'); header('Content-Type: application/json; charset=utf-8');
// Require logged-in user to update progress (employees may update their progress)
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
// get current user info for RBAC
$currentUser = auth_get_user();
$currentUid = $currentUser['id'] ?? null;
$currentRole = strtolower($currentUser['role'] ?? '');
function send($d){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function logit($m){ @file_put_contents(__DIR__.'/update_tiendo.log', date('Y-m-d H:i:s').' '.$m."\n", FILE_APPEND); }
if($_SERVER['REQUEST_METHOD']!=='POST') send(['success'=>false,'error'=>'Chỉ chấp nhận POST']);
try{
  // Accept multiple possible POST key casings
  $id = trim($_POST['id'] ?? ($_POST['ID'] ?? ''));
  $matd = trim($_POST['matd'] ?? ($_POST['MATD'] ?? ''));
  $mahm = trim($_POST['mahm'] ?? ($_POST['MAHM'] ?? ''));
  $mahm_id = trim($_POST['mahm_id'] ?? ($_POST['MAHM_ID'] ?? ($_POST['hangmuc_id'] ?? ($_POST['hangmucId'] ?? ''))));
  $start = trim($_POST['start_date'] ?? ($_POST['NGAYBDTT'] ?? ($_POST['startDate'] ?? '')));
  $end = trim($_POST['end_date'] ?? ($_POST['NGAYKTTT'] ?? ($_POST['endDate'] ?? '')));
  $progress = trim($_POST['progress'] ?? ($_POST['PHANTRAM'] ?? '0'));
  $note = trim($_POST['note'] ?? ($_POST['GHICHU'] ?? ''));

  logit('POST: '.json_encode($_POST, JSON_UNESCAPED_UNICODE));

  $dbNames = ['quanlytiendo','quanlytiendo_db','quanlytiendo_copy','construction_db']; $pdo=null; foreach($dbNames as $n){ try{ $pdo=new PDO("mysql:host=127.0.0.1;dbname={$n};charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); break;}catch(Exception $e){continue;} }
  if(!$pdo) send(['success'=>false,'error'=>'Không thể kết nối database']);

  $candidates = ['tasks','tiendo','tien_do','progress']; $tasksTable=null; foreach($candidates as $t){ $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$t]); if($s->fetch()){ $tasksTable=$t; break; } }
  if(!$tasksTable) send(['success'=>false,'error'=>'Không tìm thấy bảng tiến độ']);

  // Fetch table columns once and determine primary key (if any)
  $columns = $pdo->query("SHOW COLUMNS FROM {$tasksTable}")->fetchAll(PDO::FETCH_COLUMN);
  $colsLower = array_map('strtolower', $columns ?: []);

  $dbName = $pdo->query("select database()")->fetchColumn();
  $pkStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'");
  $pkStmt->execute([ $dbName, $tasksTable ]);
  $pkCols = $pkStmt->fetchAll(PDO::FETCH_COLUMN);
  $tasksPrimary = !empty($pkCols) ? $pkCols[0] : null;
  if($tasksPrimary && !in_array(strtolower($tasksPrimary), $colsLower)) $tasksPrimary = null;

  // find target row: prefer numeric id matched to table primary key; non-numeric ids are treated as codes
  $where = '';$params = [];
  // Determine which identifier column exists in the tasks table (prefer code, then matd, then other variants)
  $identCandidates = ['code','matd','MATD','MA','ma','ma_td','madt','code_td','task_code'];
  $identColumn = null;
  foreach($identCandidates as $cand){
    if(in_array(strtolower($cand), $colsLower)){
      // find original column name casing
      $idx = array_search(strtolower($cand), $colsLower);
      $identColumn = $columns[$idx];
      break;
    }
  }

  if($id !== ''){
    if(is_numeric($id) && $tasksPrimary){
      $where = "`{$tasksPrimary}` = ?";
      $params[] = (int)$id;
    } elseif(is_numeric($id) && in_array('id', $colsLower)){
      $idx = array_search('id', $colsLower);
      $col = $columns[$idx];
      $where = "`{$col}` = ?";
      $params[] = (int)$id;
    } else {
      if($identColumn){
        $where = "`{$identColumn}` = ?";
        $params[] = $id;
      } else {
        logit('No identifier column in tasks table to match non-numeric id. Columns: '.json_encode($columns));
        send(['success'=>false,'error'=>'Bảng tiến độ không có cột mã (code/MATD) để đối chiếu. Liên hệ admin.']);
      }
    }
  } elseif($matd !== ''){
    if($identColumn){
      $where = "`{$identColumn}` = ?";
      $params[] = $matd;
    } else {
      logit('No identifier column in tasks table to match MATD. Columns: '.json_encode($columns));
      send(['success'=>false,'error'=>'Bảng tiến độ không có cột mã (code/MATD) để đối chiếu. Liên hệ admin.']);
    }
  } else send(['success'=>false,'error'=>'Không có định danh để cập nhật']);

  $stmt = $pdo->prepare("SELECT * FROM {$tasksTable} WHERE {$where} LIMIT 1"); $stmt->execute($params); $row=$stmt->fetch();
  if(!$row) send(['success'=>false,'error'=>'Không tìm thấy bản ghi để cập nhật']);

    // Enforce RBAC: if current user is employee, they can only update tasks assigned to them
    if ($currentRole === 'employee') {
      // find assigned column name in row (common names)
      $assignedCol = null;
      foreach ($row as $rk => $rv) {
        $lk = strtolower($rk);
        if (in_array($lk, ['assigned_to','assigned','nguoi','nguoi_phu_trach','user_id','manv','assigned_to_id'])) { $assignedCol = $rk; break; }
      }
      if ($assignedCol !== null) {
        $assignedVal = $row[$assignedCol];
        if (is_null($assignedVal) || (string)$assignedVal !== (string)$currentUid) {
          send(['success'=>false,'error'=>'Quyền truy cập không đủ: bạn chỉ được cập nhật tiến độ được phân công cho mình'], 403);
        }
      } else {
        // If no assigned column is detectable, deny to avoid accidental exposure
        send(['success'=>false,'error'=>'Quyền truy cập không đủ: không thể xác định người được phân công cho bản ghi này'], 403);
      }
    }

  // determine current code/matd in row (case-insensitive)
  $currentCode = '';
  foreach($row as $rk=>$rv){ if(strtolower($rk)==='code' || strtolower($rk)==='matd' || strtolower($rk)==='id'){ if($rv!=='') { $currentCode = $rv; break; } } }

  // If current progress is >=100, block changing the MATD code
  $currentProgress = 0;
  foreach($row as $rk=>$rv){ if(in_array(strtolower($rk), ['progress','phantram'])){ $currentProgress = (int)$rv; break; } }

  if($matd !== '' && $currentCode !== '' && $matd !== $currentCode && $currentProgress >= 100){
    send(['success'=>false,'error'=>'Không được thay đổi Mã Tiến Độ sau khi hoàn thành']);
  }

  // build update fields
  $updates=[]; $vals=[];
  // update identifier column (use detected identColumn)
  if($matd !== ''){
    if($identColumn){
      $updates[] = "`{$identColumn}` = ?";
      $vals[] = $matd;
    } else {
      // fallback: attempt to update 'code' if exists
      if(in_array('code', $colsLower)){
        $updates[]='code = ?'; $vals[]=$matd;
      }
    }
  }

  // Resolve MAHM code to numeric category_id when a code string is provided
  if($mahm !== '' && !is_numeric($mahm) && empty($mahm_id)){
    $codeColumns = ['code','MAHM','mahm','ma','ma_hm'];
    foreach($codeColumns as $col){
      try{
        $s = $pdo->prepare("SELECT id FROM categories WHERE {$col} = ? LIMIT 1");
        $s->execute([$mahm]);
        $r = $s->fetch();
        if($r){ $mahm_id = $r['id']; break; }
      }catch(Exception $e){ continue; }
    }
    if(empty($mahm_id)){
      foreach($codeColumns as $col){
        try{
          $s = $pdo->prepare("SELECT id FROM hangmuc WHERE {$col} = ? LIMIT 1");
          $s->execute([$mahm]);
          $r = $s->fetch();
          if($r){ $mahm_id = $r['id']; break; }
        }catch(Exception $e){ continue; }
      }
    }
  }

  // Map category_id column name if necessary
  if($mahm_id !== ''){
    $catCol = null;
    if(in_array('category_id', $colsLower)) $catCol = $columns[array_search('category_id',$colsLower)];
    else {
      foreach(['mahm_id','hangmuc_id','mahm','ma_hm'] as $alt){ if(in_array(strtolower($alt), $colsLower)){ $catCol = $columns[array_search(strtolower($alt),$colsLower)]; break; } }
    }
    if($catCol){ $updates[] = "`{$catCol}` = ?"; $vals[] = $mahm_id; }
  }

  if($start !== ''){
    if(in_array('start_date',$colsLower)) $col = $columns[array_search('start_date',$colsLower)]; else $col = (in_array('ngaybdtt',$colsLower)?$columns[array_search('ngaybdtt',$colsLower)]:null);
    if($col){ $updates[] = "`{$col}` = ?"; $vals[] = $start; }
  }
  if($end !== ''){
    if(in_array('end_date',$colsLower)) $col = $columns[array_search('end_date',$colsLower)]; else $col = (in_array('ngaykttt',$colsLower)?$columns[array_search('ngaykttt',$colsLower)]:null);
    if($col){ $updates[] = "`{$col}` = ?"; $vals[] = $end; }
  }
  if($progress !== ''){
    if(in_array('progress',$colsLower)) $col = $columns[array_search('progress',$colsLower)]; else $col = (in_array('phantram',$colsLower)?$columns[array_search('phantram',$colsLower)]:null);
    if($col){ $updates[] = "`{$col}` = ?"; $vals[] = (int)$progress; }
  }
  if($note !== ''){
    if(in_array('description',$colsLower)) $col = $columns[array_search('description',$colsLower)]; else $col = (in_array('ghichu',$colsLower)?$columns[array_search('ghichu',$colsLower)]:null);
    if($col){ $updates[] = "`{$col}` = ?"; $vals[] = $note; }
  }

  if(count($updates)===0) send(['success'=>false,'error'=>'Không có trường nào để cập nhật']);

  $sql = "UPDATE {$tasksTable} SET " . implode(', ', $updates) . " WHERE {$where} LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $executeParams = array_merge($vals, $params);
  $stmt->execute($executeParams);
  logit('Updated SQL: '.$sql.' params: '.json_encode($executeParams, JSON_UNESCAPED_UNICODE));
  send(['success'=>true,'message'=>'Sửa thông tin tiến độ thành công']);

}catch(Exception $e){ logit('Error: '.$e->getMessage()); send(['success'=>false,'error'=>'Lỗi hệ thống: '.$e->getMessage()]); }

?>
