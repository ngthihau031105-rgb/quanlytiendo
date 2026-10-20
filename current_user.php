<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth.php';
// Return minimal session user info (id, email, role, name)
session_start();
if (!function_exists('auth_get_user')) {
    // fallback: try to read session directly
    $u = $_SESSION['user'] ?? null;
    if (!$u) {
        echo json_encode(['success'=>false,'error'=>'Not logged in']); exit;
    }
    echo json_encode(['success'=>true,'user'=>['id'=>$u['id']??null,'email'=>$u['email']??null,'role'=>$u['role']??null,'name'=>$u['name']??null]], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    auth_require_logged_in();
    $u = auth_get_user();
    $respUser = ['id'=>$u['id']??null,'email'=>$u['email']??null,'role'=>strtolower($u['role']??''),'name'=>$u['name']??null];
    if (isset($u['manv'])) $respUser['manv'] = $u['manv'];
    echo json_encode(['success'=>true,'user'=>$respUser], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    echo json_encode(['success'=>false,'error'=>'Not logged in']);
    exit;
}

?>
