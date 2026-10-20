<?php
// Simple migration runner for migrate_add_matd_to_tasks.sql
// Usage: open this script in browser or run via PHP CLI: php migrate_add_matd.php
$fn = __DIR__ . '/db/migrate_add_matd_to_tasks.sql';
if(!file_exists($fn)){ echo "Migration file not found: $fn"; exit; }
$sql = file_get_contents($fn);
try{
    $dbNames = ['quanlytiendo','quanlytiendo_db'];
    $pdo = null; $lastEx = null;
    foreach($dbNames as $db){
        try{ $pdo = new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4", 'root','', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $usedDb = $db; break; }catch(PDOException $e){ $lastEx = $e; }
    }
    if(!$pdo) {
        $msg = '';
        if ($lastEx instanceof Exception) $msg = $lastEx->getMessage();
        throw new Exception('DB connect failed: '. $msg);
    }

    // Split statements by semicolon — simple approach, works for this migration
    $stmts = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
    foreach($stmts as $stmt){ if($stmt==='') continue; $pdo->exec($stmt); }
    echo "Migration applied successfully to DB: $usedDb";
}catch(Exception $e){ echo "Migration failed: ".htmlspecialchars($e->getMessage()); }
