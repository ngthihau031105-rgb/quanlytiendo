<?php
session_start();
// record logout event into DB table `user_actions` (create if necessary)
$dbNames = ['quanlytiendo','quanlytiendo_db'];
$pdo = null; $lastEx = null; $usedDb = null;
foreach ($dbNames as $dbName) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $usedDb = $dbName; break;
    } catch (PDOException $e) { $lastEx = $e; $pdo = null; }
}

$userIdentifier = isset($_SESSION['user']) ? (is_array($_SESSION['user']) ? ($_SESSION['user']['email'] ?? $_SESSION['user']['name'] ?? '') : $_SESSION['user']) : '';
if ($pdo) {
    try {
        // create table if not exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_actions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_identifier VARCHAR(255) DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            meta TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $stmt = $pdo->prepare('INSERT INTO user_actions (user_identifier, action, meta) VALUES (:u,:a,:m)');
        $stmt->execute([':u'=>$userIdentifier, ':a'=>'logout', ':m'=>json_encode(['ip'=>$_SERVER['REMOTE_ADDR'] ?? '', 'ua'=>$_SERVER['HTTP_USER_AGENT'] ?? ''])]);
    } catch (Exception $e) {
        @file_put_contents(__DIR__.'/save_project.log', "[".date('c')."] logout insert error: " . $e->getMessage() . "\n", FILE_APPEND|LOCK_EX);
    }
} else {
    @file_put_contents(__DIR__.'/save_project.log', "[".date('c')."] logout (no db) user=".$userIdentifier."\n", FILE_APPEND|LOCK_EX);
}

// destroy session and redirect to login page
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}
session_destroy();
header('Location: index.html');
exit;
?>
