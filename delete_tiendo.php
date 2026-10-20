<?php
ob_start(); error_reporting(0); ini_set('display_errors','0'); header('Content-Type: application/json; charset=utf-8');
// Require logged-in; enforce manager for deletes with logging
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
function send($d){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function logit($m){ @file_put_contents(__DIR__.'/delete_tiendo.log', date('Y-m-d H:i:s').' '.$m."\n", FILE_APPEND); @file_put_contents(__DIR__.'/save_project.log', date('c').' delete_tiendo: '.$m."\n", FILE_APPEND); }
if($_SERVER['REQUEST_METHOD']!=='POST') send(['success'=>false,'error'=>'Chỉ chấp nhận POST']);
try{
  $id = trim($_POST['id'] ?? '');
  $matd = trim($_POST['matd'] ?? '');
  logit('POST: '.json_encode($_POST, JSON_UNESCAPED_UNICODE));

  // If user is not manager, log attempt and return table-specific message
  if (!auth_user_has_role('manager')) {
    if (function_exists('log_unauthorized_action')) {
      @log_unauthorized_action('delete','tiendo', $id ?: $matd, 'Bạn không có quyền làm điều này');
    }
    send(['success'=>false,'error'=>'Bạn không có quyền làm điều này']);
  }

  // Connect to DB first, then do a DB-backed role check to ensure current DB role is manager
  $dbNames = ['quanlytiendo','quanlytiendo_db','construction_db']; $pdo=null; $usedDb=null; foreach($dbNames as $n){ try{ $pdo=new PDO("mysql:host=127.0.0.1;dbname={$n};charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $usedDb=$n; break;}catch(Exception $e){continue;} }
  if(!$pdo) send(['success'=>false,'error'=>'Không thể kết nối database']);

  // Defensive DB-backed role check to ensure current DB role is manager
  $sessUser = auth_get_user();
  if (!$sessUser || empty($sessUser['id'])) {
    send(['success'=>false,'error'=>'Chưa đăng nhập']);
  }
  try {
    $uStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $uStmt->execute([$sessUser['id']]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
    $curRole = isset($uRow['role']) ? strtolower(trim($uRow['role'])) : '';
    if ($curRole !== 'manager') {
      if (function_exists('log_unauthorized_action')) @log_unauthorized_action('delete','tiendo', $id ?: $matd, 'Bạn không có quyền làm điều này (db-role)');
      send(['success'=>false,'error'=>'Bạn không có quyền làm điều này']);
    }
  } catch(Exception $e) {
    // ignore and continue
  }
  $candidates = ['tasks','tiendo','tien_do','progress']; $tasksTable=null; foreach($candidates as $t){ $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$t]); if($s->fetch()){ $tasksTable=$t; break; } }
  if(!$tasksTable) send(['success'=>false,'error'=>'Không tìm thấy bảng tiến độ']);

  if($id === '' && $matd === '') send(['success'=>false,'error'=>'Không có định danh để xóa']);

  // Inspect columns of the tasks table to choose correct identifier column(s)
  $colsRes = $pdo->query("SHOW COLUMNS FROM `{$tasksTable}`")->fetchAll(PDO::FETCH_ASSOC);
  $cols = array_map(function($r){ return strtolower($r['Field']); }, $colsRes);

  // Determine primary key column if available
  $pk = null;
  try{
    $pkStmt = $pdo->prepare("SHOW KEYS FROM `{$tasksTable}` WHERE Key_name = 'PRIMARY'");
    $pkStmt->execute();
    $pkRow = $pkStmt->fetch(PDO::FETCH_ASSOC);
    if($pkRow && !empty($pkRow['Column_name'])) $pk = $pkRow['Column_name'];
  } catch(Exception $e){ /* ignore */ }

  if(!$pk){
    if(in_array('id', $cols)) $pk = 'id';
  }

  // Candidate names for code-style identifier columns
  $codeCandidates = ['code','matd','matd_code','ma_td','ma_tiendo','ma','mahm','code_td','td_code','ma_tien_do','ma_tiendo'];
  $foundCodeCols = array_values(array_filter($cols, function($c) use ($codeCandidates){ return in_array($c, $codeCandidates); }));

  // Helper to quote a column name safely
  $quote = function($c){ return "`".str_replace("`","",$c)."`"; };

  if($id !== ''){
    // Prefer primary key if available
    if($pk){
      $where = $quote($pk) . " = ?";
      $params = [is_numeric($id) ? (int)$id : $id];
    } else {
      // If no pk, try id-like column
      if(in_array('id', $cols)){
        $where = "`id` = ?";
        $params = [is_numeric($id) ? (int)$id : $id];
      } else {
        // fallback: try to match by any code-like column
        if(!empty($foundCodeCols)){
          if(count($foundCodeCols) === 1){
            $where = $quote($foundCodeCols[0]) . " = ?";
            $params = [$id];
          } else {
            $coalesced = implode(',', array_map(function($c) use ($quote){ return $quote($c); }, $foundCodeCols));
            $where = "COALESCE({$coalesced}) = ?";
            $params = [$id];
          }
        } else {
          // last resort: try matching any text column by name heuristics
          $where = "COALESCE(`matd`, `code`) = ?"; // will likely fail but kept as last fallback
          $params = [$id];
        }
      }
    }
  } else {
    // matd provided
    // choose best code column
    if(!empty($foundCodeCols)){
      // prefer exact 'matd' then 'code'
      $pref = null;
      foreach(['matd','code'] as $p) if(in_array($p, $foundCodeCols)){ $pref = $p; break; }
      if(!$pref) $pref = $foundCodeCols[0];
      $where = $quote($pref) . " = ?";
      $params = [$matd];
    } else {
      // try common column names even if not detected (case variations)
      $tryCols = ['MATD','matd','code','CODE'];
      $existing = [];
      foreach($tryCols as $c){ if(in_array(strtolower($c), $cols)) $existing[] = $c; }
      if(!empty($existing)){
        $where = $quote($existing[0]) . " = ?";
        $params = [$matd];
      } else {
        // fallback to searching by concatenated columns (will fail if none exist)
        $where = "COALESCE(`code`, `matd`) = ?";
        $params = [$matd];
      }
    }
  }

  // Attempt to read the target row BEFORE we start clearing FKs so we can
  // identify a related hạng mục (1-1 relationship) to delete as well.
  $targetRow = null;
  try {
    $selRow = $pdo->prepare("SELECT * FROM `{$tasksTable}` WHERE {$where} LIMIT 1");
    $selRow->execute($params);
    $targetRow = $selRow->fetch(PDO::FETCH_ASSOC);
  } catch(Exception $e) {
    // ignore - we'll still attempt delete normally
    logit('Could not fetch target row: '.$e->getMessage());
  }

  // Determine if a hangmuc-like table exists and prepare delete instructions
  $hangmucTable = null;
  foreach(['hangmuc','categories'] as $ht){
    $t = $pdo->prepare("SHOW TABLES LIKE ?"); $t->execute([$ht]); if($t->fetch()){ $hangmucTable = $ht; break; }
  }

  $hangmucDelete = [];
  if($targetRow && $hangmucTable){
    // prefer numeric foreign key columns
    if(isset($targetRow['category_id']) && $targetRow['category_id']){
      $hangmucDelete[] = ['col'=>'id','val'=>$targetRow['category_id']];
    } elseif(isset($targetRow['mahm_id']) && $targetRow['mahm_id']){
      $hangmucDelete[] = ['col'=>'id','val'=>$targetRow['mahm_id']];
    } elseif(isset($targetRow['mahm']) && $targetRow['mahm']){
      $hangmucDelete[] = ['col'=>'mahm','val'=>$targetRow['mahm']];
    } elseif(isset($targetRow['ma']) && $targetRow['ma']){
      $hangmucDelete[] = ['col'=>'ma','val'=>$targetRow['ma']];
    } elseif(isset($targetRow['code']) && $targetRow['code']){
      $hangmucDelete[] = ['col'=>'code','val'=>$targetRow['code']];
    }
  }

  // Begin transaction and safely clear referencing FK values so other data remains
  $pdo->beginTransaction();
  try{
    $fkStmt = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME IS NOT NULL");
    $fkStmt->execute([$usedDb, $tasksTable]);
    $refs = $fkStmt->fetchAll();
    foreach($refs as $r){
      $childTable = $r['TABLE_NAME']; $childCol = $r['COLUMN_NAME'];
      if(!$childTable || !$childCol) continue;
      // inspect column nullability and data type
      $colStmt = $pdo->prepare("SELECT IS_NULLABLE, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
      $colStmt->execute([$usedDb, $childTable, $childCol]);
      $colInfo = $colStmt->fetch();
      $isNullable = ($colInfo['IS_NULLABLE'] ?? 'YES') === 'YES';
      $dataType = strtolower($colInfo['DATA_TYPE'] ?? '');

      // decide replacement value: prefer NULL if allowed, otherwise an empty string or 0
      if($isNullable){
        $updSql = "UPDATE `".str_replace('`','',$childTable)."` SET `".str_replace('`','',$childCol)."` = NULL WHERE `".str_replace('`','',$childCol)."` = ?";
        $upd = $pdo->prepare($updSql);
        if($id !== '') $upd->execute([$id]);
        if($matd !== '') $upd->execute([$matd]);
      } else {
        if(in_array($dataType, ['int','bigint','smallint','mediumint','tinyint','decimal','float','double'])){
          $updSql = "UPDATE `".str_replace('`','',$childTable)."` SET `".str_replace('`','',$childCol)."` = 0 WHERE `".str_replace('`','',$childCol)."` = ?";
        } else {
          $updSql = "UPDATE `".str_replace('`','',$childTable)."` SET `".str_replace('`','',$childCol)."` = '' WHERE `".str_replace('`','',$childCol)."` = ?";
        }
        $upd = $pdo->prepare($updSql);
        if($id !== '') $upd->execute([$id]);
        if($matd !== '') $upd->execute([$matd]);
      }
      logit("Cleared FK in {$childTable}.{$childCol}");
    }
  } catch(Exception $e){ logit('FK clear error: '.$e->getMessage()); }

  $stmt = $pdo->prepare("DELETE FROM `{$tasksTable}` WHERE {$where} LIMIT 1");
  $stmt->execute($params);
  $aff = $stmt->rowCount();
  logit('DELETE affected='.$aff.' where='.json_encode($params, JSON_UNESCAPED_UNICODE));
  if($aff>0){
    // If we have hangmuc delete instructions, try to delete corresponding hạng mục(s)
    if(!empty($hangmucDelete) && $hangmucTable){
      foreach($hangmucDelete as $del){
        try{
          $col = preg_replace('/[^a-z0-9_]/i','',$del['col']);
          $dstmt = $pdo->prepare("DELETE FROM `".str_replace('`','',$hangmucTable)."` WHERE `".$col."` = ?");
          $dstmt->execute([$del['val']]);
          logit('Deleted hangmuc from '.$hangmucTable.' where '.$col.'=' . $del['val'] . ' affected=' . $dstmt->rowCount());
        } catch(Exception $e){
          logit('Hangmuc delete error: '.$e->getMessage());
        }
      }
    }
    $pdo->commit();
    send(['success'=>true,'message'=>'Xóa tiến độ thành công']);
  } else {
    $pdo->rollBack();
    send(['success'=>false,'error'=>'Không tìm thấy bản ghi để xóa']);
  }

}catch(Exception $e){ if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); logit('Error: '.$e->getMessage()); send(['success'=>false,'error'=>'Lỗi hệ thống: '.$e->getMessage()]); }

?>
