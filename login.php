<?php
session_start();
// Hide PHP warnings from output, capture any accidental output, and log requests
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
ob_start();
$logFile = __DIR__ . '/login.log';
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Received POST: " . json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

function send_json_and_exit($response) {
    global $logFile;
    // capture and log any unexpected output before sending json
    $extra = ob_get_clean();
    if ($extra !== '') {
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Unexpected output before JSON:\n" . $extra . "\n", FILE_APPEND);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$role = isset($_POST['role']) ? $_POST['role'] : '';

$response = ['success' => false];

if ($method !== 'POST') {
    http_response_code(405);
    $response['error'] = 'Phương thức không được cho phép (vui lòng dùng POST)';
    send_json_and_exit($response);
}

if (empty($email) || empty($password)) {
    $response['error'] = 'Vui lòng nhập email và mật khẩu.';
    send_json_and_exit($response);
}

// Try to find the user across possible DB names
$dbNames = ['quanlytiendo','quanlytiendo_db'];
$pdo = null; $lastEx = null; $user = null; $usedDb = null;
foreach ($dbNames as $dbName) {
    try {
        $tryPdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // match trimmed, case-insensitive email to tolerate stored values with newlines/spaces
        // Build a safe SELECT based on existing columns to avoid SQL errors on schemas without manv/sdt/chucvu
        $availableCols = [];
        try {
            $cols = $tryPdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $colsLower = array_map('strtolower', $cols ?: []);
        } catch (Exception $e) {
            $cols = [];
            $colsLower = [];
        }
        $wanted = ['id','email','password_hash','role','name','manv','sdt','chucvu'];
        foreach ($wanted as $w) {
            // match case-insensitive
            foreach ($cols as $c) {
                if (strtolower($c) === strtolower($w)) { $availableCols[] = "`$c`"; break; }
            }
        }
        if (empty($availableCols)) {
            // fallback to minimal columns if SHOW COLUMNS failed
            $availableCols = ['id','email','password_hash','role','name'];
        }
        $selectSql = 'SELECT ' . implode(', ', $availableCols) . ' FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1';
        $stmt = $tryPdo->prepare($selectSql);
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo = $tryPdo;
            $user = $row;
            $usedDb = $dbName;
            break;
        }
        // keep last connection if none found (fallback)
        if (!$pdo) $pdo = $tryPdo;
    } catch (PDOException $e) {
        $lastEx = $e;
        continue;
    }
}

if (!$pdo) {
    http_response_code(500);
    $response['error'] = 'Lỗi kết nối cơ sở dữ liệu';
    file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] PDO/Conn error: " . ($lastEx ? $lastEx->getMessage() : 'unknown') . "\n", FILE_APPEND);
    send_json_and_exit($response);
}

if (!$user) {
    $response['error'] = 'Tài khoản không tồn tại.';
    send_json_and_exit($response);
}

// Determine if stored password looks like SHA-256 (64 hex chars)
$dbHash = $user['password_hash'];
$authenticated = false;
if (is_string($dbHash) && strlen($dbHash) === 64 && preg_match('/^[0-9a-f]{64}$/i', $dbHash)) {
    // Stored as SHA-256 hex
    if (hash_equals($dbHash, hash('sha256', $password))) {
        $authenticated = true;
    }
} else {
    // Stored as plaintext (migration path): compare directly, then migrate to SHA-256
    if (hash_equals((string)$dbHash, $password)) {
        // migrate: update stored hash to SHA-256
        $update = $pdo->prepare('UPDATE users SET password_hash = SHA2(?, 256) WHERE id = ?');
        $update->execute([$password, $user['id']]);
        $authenticated = true;
    }
}

if (!$authenticated) {
    $response['error'] = 'Mật khẩu không đúng.';
    send_json_and_exit($response);
}

// Ignore client-supplied role; use role stored in database for permissions
// Success: set session (normalize role to lowercase)
$_SESSION['user'] = [
    'id' => $user['id'],
    'email' => trim($user['email']),
    'role' => strtolower($user['role'] ?? 'employee'),
    'name' => $user['name'] ?? null,
    'manv' => isset($user['manv']) ? $user['manv'] : null,
    'sdt' => isset($user['sdt']) ? $user['sdt'] : null,
    'chucvu' => isset($user['chucvu']) ? $user['chucvu'] : null,
];

// Attempt to enrich session with MANV if a matching nhanvien row exists
try {
    $manv = null;
    if (!empty($user['name'])) {
        $mstmt = $pdo->prepare('SELECT MANV FROM nhanvien WHERE LOWER(TRIM(TENNV)) = LOWER(TRIM(?)) LIMIT 1');
        $mstmt->execute([$user['name']]);
        $mv = $mstmt->fetchColumn();
        if ($mv) $manv = $mv;
    }
    if (!$manv) {
        // try matching by email if nhanvien has email-like column
        $m2 = $pdo->prepare("SELECT MANV FROM nhanvien WHERE LOWER(TRIM(COALESCE(email, ''))) = LOWER(TRIM(?)) LIMIT 1");
        $m2->execute([trim($user['email'])]);
        $mv2 = $m2->fetchColumn();
        if ($mv2) $manv = $mv2;
    }
    if ($manv) $_SESSION['user']['manv'] = $manv;
} catch (Exception $e) {
    // ignore enrichment failures
}

$response['success'] = true;
$response['redirect'] = 'tongquan.html';
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Authenticated user id={$user['id']} email={$user['email']} role={$user['role']}\n", FILE_APPEND);
send_json_and_exit($response);

?>
-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 22, 2025 at 03:00 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `quanlytiendo`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` char(64) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `role` enum('manager','employee') NOT NULL DEFAULT 'employee',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `name`, `role`, `created_at`) VALUES
(1, 'nguyenhau03112005@gmail.com', 'f6561e9885dda0fab21b6f8efd6df93879451810d1f78fb714f280306a7887c8', 'Hau Nguyen', 'manager', '2025-12-06 18:20:00'),
(2, 'hangcan18@gmail.com\r\n', 'abcxyzlmn', 'Minh Hang', 'employee', '2025-12-10 09:20:35'),
(3, 'hanh2005@gmail.com', '12345678', 'My Hanh', 'employee', '2025-12-10 09:21:44'),
(4, 'myhanh@gmail.com ', 'hanh2005', 'Hanh Nguyen', 'employee', '2025-12-10 09:36:12'),
(5, 'behanh06205@gmail.com ', '123321', 'Thuy Hanh', 'employee', '0000-00-00 00:00:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

