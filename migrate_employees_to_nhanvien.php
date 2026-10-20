<?php
// migrate_employees_to_nhanvien.php
// Copies data from `employees` table into existing `nhanvien` table (mapping common columns),
// creates a backup of `employees` first, then drops `employees` after successful migration.
// Usage: open this script in your browser (http://localhost/quanlytiendo/migrate_employees_to_nhanvien.php)

header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 0);
$log = __DIR__ . '/migrate_employees.log';
function logmsg($m){ global $log; file_put_contents($log, "[".date('Y-m-d H:i:s')."] ".strip_tags($m)."\n", FILE_APPEND); }

// Try common DB names
$dbNames = ['quanlytiendo','quanlytiendo_db'];
$pdo = null; $usedDb = null; $lastEx = null;
foreach($dbNames as $db){
    try{
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        $usedDb = $db; break;
    }catch(PDOException $e){ $lastEx = $e; $pdo = null; }
}
if(!$pdo){ echo "<h2 style='color:red'>DB connection failed</h2><pre>".htmlspecialchars($lastEx->getMessage())."</pre>"; exit; }
logmsg("Connected to DB={$usedDb}");

// Check tables
$tablesStmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ("nhanvien","employees")');
$tablesStmt->execute([$usedDb]);
$foundTables = array_column($tablesStmt->fetchAll(), 'TABLE_NAME');

$hasNhanvien = in_array('nhanvien', $foundTables);
$hasEmployees = in_array('employees', $foundTables);

echo "<h2>Migration report</h2>";
echo "<p>DB: <b>".htmlspecialchars($usedDb)."</b></p>";
echo "<ul>";
echo "<li>nhanvien exists: ".($hasNhanvien?"Yes":"No")."</li>";
echo "<li>employees exists: ".($hasEmployees?"Yes":"No")."</li>";
echo "</ul>";

if(!$hasEmployees){ echo "<p>No `employees` table found — nothing to migrate.</p>"; logmsg('No employees table found; exiting.'); exit; }

if(!$hasNhanvien){
    // Create nhanvien with common columns if missing
    echo "<p><b>`nhanvien` not found — creating default `nhanvien` table with columns MANV,TENNV,CHUCVU,SDT.</b></p>";
    $createSql = "CREATE TABLE nhanvien (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        MANV VARCHAR(100) DEFAULT NULL,
        TENNV VARCHAR(255) DEFAULT NULL,
        CHUCVU VARCHAR(255) DEFAULT NULL,
        SDT VARCHAR(32) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    try{ $pdo->exec($createSql); $hasNhanvien = true; logmsg('Created nhanvien table.'); echo "<p>Created `nhanvien` table.</p>"; }catch(Exception $e){ echo "<p style='color:red'>Failed to create nhanvien: ".htmlspecialchars($e->getMessage())."</p>"; logmsg('Create nhanvien failed: '.$e->getMessage()); exit; }
}

// Backup employees table
$backupName = 'employees_backup_'.date('Ymd_His');
try{
    $pdo->exec("CREATE TABLE `{$backupName}` AS SELECT * FROM employees");
    echo "<p>Created backup table `<b>".htmlspecialchars($backupName)."</b>`.</p>";
    logmsg("Created backup table {$backupName}");
}catch(Exception $e){ echo "<p style='color:red'>Failed to create backup: ".htmlspecialchars($e->getMessage())."</p>"; logmsg('Backup failed: '.$e->getMessage()); exit; }

// Determine column mapping (case-insensitive)
function getColumns($pdo, $db, $table){
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $stmt->execute([$db,$table]);
    $cols = array_column($stmt->fetchAll(), 'COLUMN_NAME');
    $map = [];
    foreach($cols as $c){ $map[strtolower($c)] = $c; }
    return $map; // lower->actual
}

$srcCols = getColumns($pdo, $usedDb, 'employees');
$dstCols = getColumns($pdo, $usedDb, 'nhanvien');

// Candidate names
$manvCandidates = ['manv','ma_nv','ma_nv','manhanvien','ma','code','idcode','employee_code','manv'];
$nameCandidates = ['name','tennv','hoten','ho_ten','ten'];
$positionCandidates = ['position','chucvu','chuc_vu','title'];
$phoneCandidates = ['phone','sdt','mobile','phone_number','so_dien_thoai'];

function findFirst($map, $candidates){
    foreach($candidates as $cand){ if(isset($map[strtolower($cand)])) return $map[strtolower($cand)]; }
    return null;
}

$src_manv = findFirst($srcCols, $manvCandidates);
$src_name = findFirst($srcCols, $nameCandidates);
$src_position = findFirst($srcCols, $positionCandidates);
$src_phone = findFirst($srcCols, $phoneCandidates);

$dst_manv = findFirst($dstCols, ['manv','ma_nv','manv','manhanvien','ma']);
$dst_name = findFirst($dstCols, ['tennv','name','hoten','ho_ten','ten']);
$dst_position = findFirst($dstCols, ['chucvu','position','chuc_vu','title']);
$dst_phone = findFirst($dstCols, ['sdt','phone','mobile','phone_number','so_dien_thoai']);

echo "<h3>Column mapping</h3><ul>";
echo "<li>employees -> MANV column detected: <b>".htmlspecialchars($src_manv)."</b></li>";
echo "<li>employees -> NAME column detected: <b>".htmlspecialchars($src_name)."</b></li>";
echo "<li>nhanvien -> MANV column target: <b>".htmlspecialchars($dst_manv)."</b></li>";
echo "<li>nhanvien -> TENNV column target: <b>".htmlspecialchars($dst_name)."</b></li>";
echo "</ul>";
logmsg('Detected columns src: '.json_encode($srcCols)." dst: ".json_encode($dstCols));

// Build SELECT from employees
$selectCols = [];
if($src_manv) $selectCols['manv']=$src_manv;
if($src_name) $selectCols['name']=$src_name;
if($src_position) $selectCols['position']=$src_position;
if($src_phone) $selectCols['phone']=$src_phone;
if(empty($selectCols)){ echo "<p style='color:red'>No usable columns found in employees table to migrate.</p>"; logmsg('No usable columns in employees'); exit; }

$selList = implode(', ', array_map(function($c){ return "`".$c."`"; }, $selectCols));
$rows = $pdo->query("SELECT {$selList} FROM employees")->fetchAll();
$total = count($rows);
echo "<p>Found <b>{$total}</b> rows in `employees`.</p>";
logmsg("Found {$total} rows in employees");

// Prepare insert statement dynamically
$insertCols = [];
$insertPlaceholders = [];
$insertMap = []; // placeholder => value key
if($dst_manv && isset($selectCols['manv'])) { $insertCols[] = "`{$dst_manv}`"; $insertPlaceholders[]=':manv'; }
if($dst_name && isset($selectCols['name'])) { $insertCols[] = "`{$dst_name}`"; $insertPlaceholders[]=':name'; }
if($dst_position && isset($selectCols['position'])) { $insertCols[] = "`{$dst_position}`"; $insertPlaceholders[]=':position'; }
if($dst_phone && isset($selectCols['phone'])) { $insertCols[] = "`{$dst_phone}`"; $insertPlaceholders[]=':phone'; }

if(empty($insertCols)) { echo "<p style='color:red'>Target `nhanvien` has no mapped columns for insertion.</p>"; logmsg('No mapped target columns'); exit; }

$insSql = "INSERT INTO nhanvien (".implode(',',$insertCols).") VALUES (".implode(',',$insertPlaceholders).")";
$insStmt = $pdo->prepare($insSql);

$migrated = 0; $skipped = 0; $errors = [];
foreach($rows as $r){
    $params = [];
    if(isset($selectCols['manv'])) $params[':manv'] = $r[$selectCols['manv']];
    if(isset($selectCols['name'])) $params[':name'] = $r[$selectCols['name']];
    if(isset($selectCols['position'])) $params[':position'] = $r[$selectCols['position']];
    if(isset($selectCols['phone'])) $params[':phone'] = $r[$selectCols['phone']];

    // avoid duplicates: if dst has MANV, check existence
    $skip = false;
    if($dst_manv && !empty($params[':manv'])){
        $chk = $pdo->prepare("SELECT 1 FROM nhanvien WHERE `{$dst_manv}` = ? LIMIT 1");
        $chk->execute([$params[':manv']]);
        if($chk->fetch()){ $skipped++; $skip = true; continue; }
    }
    try{
        $insStmt->execute($params);
        $migrated++;
    }catch(Exception $e){ $errors[] = $e->getMessage(); logmsg('Insert error: '.$e->getMessage()); }
}

echo "<p>Migrated: <b>{$migrated}</b>, Skipped (duplicates): <b>{$skipped}</b></p>";
logmsg("Migrated={$migrated} Skipped={$skipped}");

// Show last 20 rows from nhanvien — chọn cột để sắp xếp nếu có
$orderCol = null;
$dstCols = array_keys($dstCols = getColumns($pdo, $usedDb, 'nhanvien'));
if (in_array('id', $dstCols)) $orderCol = 'id';
elseif (in_array('MANV', $dstCols)) $orderCol = 'MANV';
elseif (in_array('manv', $dstCols)) $orderCol = 'manv';
elseif (in_array('TENNV', $dstCols)) $orderCol = 'TENNV';
elseif (in_array('tennv', $dstCols)) $orderCol = 'tennv';

if ($orderCol) {
    $sample = $pdo->query("SELECT * FROM nhanvien ORDER BY `". $orderCol ."` DESC LIMIT 20")->fetchAll();
} else {
    $sample = $pdo->query('SELECT * FROM nhanvien LIMIT 20')->fetchAll();
}
if($sample){
    echo "<h3>Last rows in `nhanvien`</h3>";
    echo "<table border=1 cellpadding=6 cellspacing=0 style='border-collapse:collapse;'><tr>";
    foreach(array_keys($sample[0]) as $c) echo "<th>".htmlspecialchars($c)."</th>";
    echo "</tr>";
    foreach($sample as $row){
        echo "<tr>";
        foreach($row as $v) echo "<td>".htmlspecialchars($v)."</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Now drop employees table (after backup)
try{
    $pdo->exec('DROP TABLE employees');
    echo "<p style='color:green'>Dropped table `employees`.</p>";
    logmsg('Dropped table employees');
}catch(Exception $e){ echo "<p style='color:red'>Failed to drop employees: ".htmlspecialchars($e->getMessage())."</p>"; logmsg('Drop failed: '.$e->getMessage()); }

echo "<p>Migration finished. Check <code>migrate_employees.log</code> for details.</p>";

?>