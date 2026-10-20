<?php
// save_hangmuc.php - Auto-generate unique code if duplicate
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__.'/save_hangmuc.log');
session_start();
// Only managers may create new hạng mục
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
auth_require_role('manager');

function dbg($m){ 
    @file_put_contents(__DIR__.'/save_hangmuc.log', date('c').' '.$m."\n", FILE_APPEND); 
}

ob_start();
set_exception_handler(function($e){ 
    if(ob_get_level()) @ob_clean(); 
    http_response_code(500); 
    dbg('Uncaught exception: '.$e->getMessage()); 
    echo json_encode(['success'=>false,'error'=>'Lỗi máy chủ nội bộ']); 
    exit; 
});

register_shutdown_function(function(){ 
    $err = error_get_last(); 
    if($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])){ 
        if(ob_get_level()) @ob_clean(); 
        dbg('Shutdown error: '.print_r($err, true)); 
        http_response_code(500); 
        echo json_encode(['success'=>false,'error'=>'Lỗi máy chủ (shutdown)']); 
    } 
});

function sendJson($data, $status=200){ 
    if(ob_get_level()) @ob_clean(); 
    http_response_code($status); 
    header('Content-Type: application/json; charset=utf-8'); 
    echo json_encode($data, JSON_UNESCAPED_UNICODE); 
    exit; 
}

if(strcasecmp($_SERVER['REQUEST_METHOD'] ?? '','POST') !== 0) {
    sendJson(['success'=>false,'error'=>'Endpoint chỉ chấp nhận POST'], 405);
}

// Get input
$name = trim($_POST['name'] ?? '');
$congtrinh = trim($_POST['congtrinh'] ?? '');
$nguoi = trim($_POST['nguoi_phu_trach'] ?? '');
// accept alternate manager POST keys from various frontends
if($nguoi === '') {
    $nguoi = trim($_POST['manv'] ?? '');
}
if($nguoi === '') {
    $nguoi = trim($_POST['nguoi'] ?? '');
}
if($nguoi === '') {
    $nguoi = trim($_POST['manager'] ?? '');
}

// Accept alternate date keys
$ngaybd = trim($_POST['ngay_bat_dau'] ?? '');
if($ngaybd === '') $ngaybd = trim($_POST['ngaybd'] ?? '');
$ngaykt = trim($_POST['ngay_ket_thuc'] ?? '');
if($ngaykt === '') $ngaykt = trim($_POST['ngaykt'] ?? '');
$mota = trim($_POST['mota'] ?? '');
$mahm = trim($_POST['mahm'] ?? '');
$status = trim($_POST['status'] ?? '');

if($name === '') {
    sendJson(['success'=>false,'error'=>'Tên hạng mục là bắt buộc'], 400);
}

// Database connection
$dbCandidates = ['quanlytiendo','quanlytiendo_db','quanlytiendo_copy'];
$pdo = null;
$usedDb = null;

try{
    $probe = new PDO('mysql:host=127.0.0.1;dbname=mysql;charset=utf8mb4','root','', [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    
    $placeholders = implode(',', array_fill(0, count($dbCandidates), '?'));
    $st = $probe->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ($placeholders)");
    $st->execute($dbCandidates);
    $found = $st->fetchAll(PDO::FETCH_COLUMN);
    
    foreach($found as $d){
        $t = $probe->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $t->execute([$d,'categories']); 
        if((bool)$t->fetchColumn()){ 
            $usedDb = $d; 
            break; 
        }
    }
    
    if(!$usedDb && !empty($found)) $usedDb = $found[0];
    if(!$usedDb) $usedDb = $dbCandidates[0];
    
    $pdo = new PDO("mysql:host=127.0.0.1;dbname={$usedDb};charset=utf8mb4", 'root','', [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    
}catch(PDOException $e){ 
    dbg('DB connect error: '.$e->getMessage()); 
    sendJson(['success'=>false,'error'=>'Không thể kết nối tới cơ sở dữ liệu'], 500); 
}
// Log selected database for debugging
dbg('save_hangmuc: using DB -> '.($usedDb ?? 'unknown'));

// Function to generate unique code
function generateUniqueCode($pdo, $table, $codeCol, $baseCode) {
    if($baseCode === '') {
        // Generate from name if no code provided
        $baseCode = 'HM' . substr(preg_replace('/[^A-Z0-9]/i', '', strtoupper($GLOBALS['name'])), 0, 4);
        if(strlen($baseCode) < 4) $baseCode = 'HM' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
    
    $originalCode = $baseCode;
    $counter = 1;
    $maxAttempts = 100;
    
    while($counter < $maxAttempts) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$codeCol` = ?");
        $check->execute([$baseCode]);
        
        if($check->fetchColumn() == 0) {
            dbg("generateUniqueCode: unique found={$baseCode} (table={$table} col={$codeCol})");
            return $baseCode; // Found unique code
        }
        
        // Generate new code with suffix
        if(preg_match('/^(.+?)(\d+)$/', $originalCode, $matches)) {
            // If original has number, increment it
            $baseCode = $matches[1] . str_pad((int)$matches[2] + $counter, strlen($matches[2]), '0', STR_PAD_LEFT);
        } else {
            // Add counter suffix
            $baseCode = $originalCode . str_pad($counter, 2, '0', STR_PAD_LEFT);
        }
        
        $counter++;
    }
    
    // Fallback: use timestamp
    $fallback = $originalCode . '_' . time();
    dbg("generateUniqueCode: fallback={$fallback}");
    return $fallback;
}

try{
    // Build full description
    $metaParts = [];
    if($nguoi !== '') $metaParts[] = 'Người phụ trách: '.$nguoi;
    if($status !== ''){
        $map = ['pending'=>'Chưa bắt đầu','in_progress'=>'Đang thực hiện','under_review'=>'Đang kiểm tra','completed'=>'Đã hoàn thành'];
        $metaParts[] = 'Trạng thái: '.($map[$status] ?? $status);
    }
    if($ngaybd !== '') $metaParts[] = 'Ngày bắt đầu: '.$ngaybd;
    if($ngaykt !== '') $metaParts[] = 'Ngày kết thúc: '.$ngaykt;
    $fullDescription = $mota . ($metaParts ? "\n\n".implode("\n",$metaParts) : '');

    // Determine project_id
    $project_id = null;
    if(!empty($_POST['project_id'])){
        $project_id = (int)$_POST['project_id'];
    } elseif(!empty($congtrinh)){
        $pchk = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $pchk->execute([$usedDb,'projects']);
        if((bool)$pchk->fetchColumn()){
            $q = $pdo->prepare('SELECT id FROM projects WHERE name = ? LIMIT 1');
            $q->execute([$congtrinh]); 
            $row = $q->fetch(); 
            if($row) $project_id = $row['id'];
        }
    }

    // Prefer using legacy 'hangmuc' table only: insert into hangmuc
    $chk2 = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $chk2->execute([$usedDb,'hangmuc']);
    
    if((bool)$chk2->fetchColumn()){
        $colStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $colStmt->execute([$usedDb,'hangmuc']); 
        $cols = array_map('strtolower', $colStmt->fetchAll(PDO::FETCH_COLUMN));
        
        $candidates = [
            'name'=>['ten','name','ten_hang_muc','tenhm'],
            'congtrinh'=>['congtrinh','project','project_id','ma_ct','mact'],
            'nguoi'=>['nguoi_phu_trach','nguoi','nguoi_pt','manv','ma_nv'],
            'ngaybd'=>['ngay_bd','ngaybatdau','ngay_bat_dau','ngaybd'],
            'ngaykt'=>['ngay_kt','ngay_ket_thuc','ngaykt'],
            'mota'=>['mota','description','mo_ta','ghi_chu'],
            'status'=>['status','trangthai','trang_thai','state']
        ];
        
        $toInsert = [];
        $params = [];
        $findCol = function($opts) use ($cols){ 
            foreach($opts as $o) if(in_array($o,$cols)) return $o; 
            return null; 
        };

        if($c = $findCol($candidates['name'])){ $toInsert[]=$c; $params[$c]=$name; }
        if($c = $findCol($candidates['congtrinh'])){ $toInsert[]=$c; $params[$c]=($congtrinh==='')?null:$congtrinh; }
        if($c = $findCol($candidates['nguoi'])){ $toInsert[]=$c; $params[$c]=$nguoi; }
        if($c = $findCol($candidates['ngaybd'])){ $toInsert[]=$c; $params[$c]=($ngaybd===''?null:$ngaybd); }
        if($c = $findCol($candidates['ngaykt'])){ $toInsert[]=$c; $params[$c]=($ngaykt===''?null:$ngaykt); }
        if($c = $findCol($candidates['mota'])){ $toInsert[]=$c; $params[$c]=$mota; }
        // status for legacy hangmuc
        if($c = $findCol($candidates['status'])){ $toInsert[]=$c; $params[$c]=($status===''?null:$status); }

        $codeCandidates = ['code','mahm','ma_hm','ma_hang_muc','ma','mamh']; 
        $codeCol = null; 
        foreach($codeCandidates as $c) if(in_array($c,$cols)){ $codeCol = $c; break; }

        $pkStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'");
        $pkStmt->execute([$usedDb,'hangmuc']); 
        $pkCols = array_map('strtolower', $pkStmt->fetchAll(PDO::FETCH_COLUMN));

        // Generate unique code for legacy table
        $uniqueCode = '';
        if($codeCol) {
            $uniqueCode = generateUniqueCode($pdo, 'hangmuc', $codeCol, $mahm);
            if($uniqueCode !== $mahm && $mahm !== '') {
                dbg("Legacy: Code '$mahm' exists, generated: '$uniqueCode'");
            }
            if($uniqueCode !== '') {
                $toInsert[] = $codeCol;
                $params[$codeCol] = $uniqueCode;
            }
        }

        $finalCols = [];
        foreach($toInsert as $col){ 
            if(in_array(strtolower($col), $pkCols) && ($params[$col] === '' || $params[$col]===null)) continue; 
            $finalCols[]=$col; 
        }
        
        if(empty($finalCols)) sendJson(['success'=>false,'error'=>'Không có cột để chèn vào hangmuc'],400);

        $colsList = implode(',', array_map(function($v){ return "`$v`"; }, $finalCols));
        $placeholders = implode(',', array_map(function($v){ return ':'.$v; }, $finalCols));
        $insSql = "INSERT INTO hangmuc ({$colsList}) VALUES ({$placeholders})";
        $ins = $pdo->prepare($insSql);
        
        foreach($finalCols as $col){ 
            $val = $params[$col]; 
            $isInt = (strpos(strtolower($col),'manv') !== false) || preg_match('/(^ma_|_id$|\bid\b)/', strtolower($col)); 
            if($isInt && $val !== '' && is_numeric($val)) {
                $ins->bindValue(':'.$col, (int)$val, PDO::PARAM_INT); 
            } else {
                $ins->bindValue(':'.$col, $val === '' ? null : $val, PDO::PARAM_STR); 
            }
        }
        
        dbg('Executing SQL (hangmuc): '.$insSql.' binds: '.json_encode($params, JSON_UNESCAPED_UNICODE));
        $ins->execute(); 
        $id = $pdo->lastInsertId();
        
        $msg = ($uniqueCode !== $mahm && $mahm !== '') ? "Đã tạo mã mới '$uniqueCode' (mã '$mahm' đã tồn tại)" : 'Thêm hạng mục mới thành công (hangmuc)';
        // After insert, if status provided, propagate to related tiến độ records
        if($status !== ''){
            try{
                // map status to progress percentage
                $s = mb_strtolower($status,'UTF-8');
                $newProgress = null;
                if(strpos($s,'hoàn')!==false || strpos($s,'complete')!==false || strpos($s,'done')!==false) $newProgress = 100;
                elseif(strpos($s,'chưa')!==false || strpos($s,'pending')!==false || strpos($s,'not')!==false) $newProgress = 0;
                elseif(strpos($s,'đang')!==false || strpos($s,'dang')!==false || strpos($s,'progress')!==false || strpos($s,'in_progress')!==false) $newProgress = 50;

                if($newProgress !== null){
                    // find tasks table and columns
                    $tasksTable = null;
                    foreach(['tasks','tiendo','tien_do','progress'] as $tname){ $check=$pdo->prepare("SHOW TABLES LIKE ?"); $check->execute([$tname]); if($check->fetch()){ $tasksTable=$tname; break; } }
                    if($tasksTable){
                        $taskCols = $pdo->query("SHOW COLUMNS FROM {$tasksTable}")->fetchAll(PDO::FETCH_COLUMN);
                        $taskColsLower = array_map('strtolower',$taskCols);
                        // find link column
                        $linkCol = null; $cands=['category_id','hangmuc_id','mahm','MAHM','ma','mahm_code'];
                        foreach($cands as $c){ $idx=array_search(strtolower($c), $taskColsLower, true); if($idx!==false){ $linkCol=$taskCols[$idx]; break; } }
                        // detect progress column
                        $progressCol = null; foreach(['progress','PHANTRAM','phantram','phan_tram','percent'] as $pc){ $idx=array_search(strtolower($pc), $taskColsLower, true); if($idx!==false){ $progressCol=$taskCols[$idx]; break; } }
                        if(!$progressCol) $progressCol='progress';
                        // find hangmuc code column in hangmuc table
                        $hangCols = $pdo->query("SHOW COLUMNS FROM hangmuc")->fetchAll(PDO::FETCH_COLUMN);
                        $hangCodeCol = null; foreach(['MAHM','mahm','code','ma'] as $hc){ if(in_array($hc,$hangCols)){ $hangCodeCol=$hc; break; } }

                        if($linkCol){
                            if(preg_match('/id$/i',$linkCol) && $id){
                                $upd = $pdo->prepare("UPDATE `{$tasksTable}` SET `{$progressCol}` = ? WHERE `{$linkCol}` = ?");
                                $upd->execute([(int)$newProgress, $id]);
                                dbg('Propagated progress to '.$upd->rowCount().' rows after insert');
                            } elseif($hangCodeCol && $uniqueCode){
                                $upd = $pdo->prepare("UPDATE `{$tasksTable}` SET `{$progressCol}` = ? WHERE `{$linkCol}` = ?");
                                $upd->execute([(int)$newProgress, $uniqueCode]);
                                dbg('Propagated progress to '.$upd->rowCount().' rows after insert by code');
                            }
                        }
                    }
                }
            }catch(Exception $e){ dbg('Propagation after insert error: '.$e->getMessage()); }
        }
        sendJson(['success'=>true,'id'=>$id,'message'=>$msg,'generated_code'=>$uniqueCode,'original_code'=>$mahm]);
    }

    

    sendJson(['success'=>false,'error'=>'Bảng categories hoặc hangmuc không tồn tại'],500);

}catch(Exception $e){ 
    dbg('ERR: '.$e->getMessage()); 
    sendJson(['success'=>false,'error'=>'Lỗi khi thêm: '.$e->getMessage()],500); 
}
?>