<?php
// Lightweight auth helpers for endpoints (standardized).
if (session_status() === PHP_SESSION_NONE) session_start();

function _auth_send($payload, $code = 403) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function auth_get_user() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    // Expect session user in shape: ['id'=>..., 'email'=>..., 'role'=>..., 'name'=>...]
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function auth_user_has_role($roles) {
    $user = auth_get_user();
    if (!$user || empty($user['role'])) return false;
    $current = strtolower(trim((string)$user['role']));
    if (is_array($roles)) {
        foreach ($roles as $r) if (strtolower((string)$r) === $current) return true;
        return false;
    }
    return strtolower(trim((string)$roles)) === $current;
}

function auth_require_logged_in() {
    $u = auth_get_user();
    if (!$u) {
        _auth_send(['success' => false, 'error' => 'Chưa đăng nhập'], 401);
    }
    return $u;
}

function auth_require_role($roles) {
    $u = auth_get_user();
    if (!$u) {
        _auth_send(['success' => false, 'error' => 'Chưa đăng nhập'], 401);
    }
    if (!auth_user_has_role($roles)) {
        // best-effort logging of unauthorized role access
        if (function_exists('log_unauthorized_action')) {
            $action = ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '');
            @log_unauthorized_action($action, 'role_check', null, 'role denied');
        }
        // Unified message required
        _auth_send(['success' => false, 'error' => 'Bạn không có quyền làm điều này'], 403);
    }
    return $u;
}

// Log unauthorized actions to DB (best-effort). Creates a small table if missing.
function log_unauthorized_action($action, $target_type = null, $target_id = null, $message = null) {
    $user = auth_get_user();
    $user_id = $user['id'] ?? null;
    $user_email = $user['email'] ?? null;
    $role = $user['role'] ?? null;

    $dbNames = ['quanlytiendo','quanlytiendo_db'];
    $pdo = null; $lastEx = null; $usedDb = null;
    foreach ($dbNames as $dbName) {
        try {
            $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $usedDb = $dbName;
            break;
        } catch (PDOException $e) { $lastEx = $e; $pdo = null; }
    }
    if (!$pdo) {
        // fallback to file log
        @file_put_contents(__DIR__ . '/access_denied.log', date('c') . " UNLOGGED: user={$user_email} action={$action} target={$target_type}/{$target_id} msg={$message}\n", FILE_APPEND);
        return false;
    }

    try {
        $create = "CREATE TABLE IF NOT EXISTS `access_denied_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` VARCHAR(64) NULL,
            `user_email` VARCHAR(255) NULL,
            `role` VARCHAR(64) NULL,
            `action` VARCHAR(128) NOT NULL,
            `target_type` VARCHAR(128) NULL,
            `target_id` VARCHAR(255) NULL,
            `message` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $pdo->exec($create);

        $ins = $pdo->prepare('INSERT INTO access_denied_logs (user_id,user_email,role,action,target_type,target_id,message) VALUES (:uid,:email,:role,:action,:tgt,:tid,:msg)');
        $ins->execute([
            ':uid' => $user_id,
            ':email' => $user_email,
            ':role' => $role,
            ':action' => $action,
            ':tgt' => $target_type,
            ':tid' => $target_id,
            ':msg' => $message
        ]);
        return true;
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/access_denied.log', date('c') . " INSERT-ERR: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}
?>