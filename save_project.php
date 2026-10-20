<?php
// save_project.php
// Receives POST from themmoict.html and inserts into projects table.
header('Content-Type: application/json; charset=utf-8');
session_start();
// Require logged-in user (employees may create/edit projects per RBAC)
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
// Only managers may create projects
auth_require_role('manager');

// Simple server-side logging to help debugging (append-only)
function _sp_log($txt) {
    $logfile = __DIR__ . '/save_project.log';
    $time = date('Y-m-d H:i:s');
    $entry = "[$time] " . $txt . "\n";
    @file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
}
// Log incoming request basics
_sp_log("REQUEST: " . ($_SERVER['REQUEST_METHOD'] ?? '') . " " . ($_SERVER['REQUEST_URI'] ?? '') );
if (!empty($_POST)) {
    _sp_log('POST: ' . json_encode($_POST, JSON_UNESCAPED_UNICODE));
}
if (!empty($_GET)) {
    _sp_log('GET: ' . json_encode($_GET, JSON_UNESCAPED_UNICODE));
}
// log raw body (helpful for non-form clients)
$raw = file_get_contents('php://input');
if ($raw !== false && trim($raw) !== '') {
    _sp_log('RAW_BODY: ' . substr($raw, 0, 2000));
}

// If not POST, return a clear diagnostic response to help debugging
if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', 'POST') !== 0) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => 'Endpoint chỉ chấp nhận POST. Vui lòng gửi dữ liệu bằng phương thức POST.','method'=>$_SERVER['REQUEST_METHOD'] ?? '']);
    exit;
}

$required = ['name'];
foreach ($required as $r) {
    if (empty($_POST[$r])) {
        echo json_encode(['success' => false, 'error' => "Trường $r là bắt buộc."]);
        exit;
    }
}

$name = trim($_POST['name']);
$location = isset($_POST['location']) ? trim($_POST['location']) : null;
$budget = isset($_POST['budget']) && $_POST['budget'] !== '' ? (float) $_POST['budget'] : null;
$start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
$end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
$description = isset($_POST['description']) ? trim($_POST['description']) : null;

$dbNames = ['quanlytiendo','quanlytiendo_db','quanlytiendo_copy'];
$pdo = null;
$lastEx = null;
$usedDb = null;
foreach ($dbNames as $dbName) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        // success
        $usedDb = $dbName;
        break;
    } catch (PDOException $e) {
        $lastEx = $e;
        $pdo = null;
        continue;
    }
}

if (!$pdo) {
    http_response_code(500);
    $msg = '';
    if ($lastEx instanceof Exception) $msg = $lastEx->getMessage();
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . ($msg ?: 'unknown')]);
    exit;
}

try {
    // If your DB has a Vietnamese table `congtrinh` (as in your screenshot), insert there.
    $tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $tableCheck->execute([$usedDb, 'congtrinh']);
    $hasCongtrinh = (bool)$tableCheck->fetchColumn();
    _sp_log('hasCongtrinh=' . ($hasCongtrinh ? '1' : '0') . ' in db=' . $usedDb);

    if ($hasCongtrinh) {
        // require dates (NGAYBD, NGAYKT appear NOT NULL in your table)
        if (empty($start_date) || empty($end_date)) {
            echo json_encode(['success' => false, 'error' => 'Vui lòng cung cấp Ngày bắt đầu và Ngày hoàn thành (NGAYBD, NGAYKT).']);
            exit;
        }

        // If the client supplied a MACT, use it (but ensure uniqueness). Otherwise generate unique MACT.
        $mact = null;
        if (!empty($_POST['mact'])) {
            $provided = trim($_POST['mact']);
            if ($provided !== '') {
                $chk = $pdo->prepare('SELECT 1 FROM congtrinh WHERE MACT = ? LIMIT 1');
                $chk->execute([$provided]);
                if ($chk->fetch()) {
                    echo json_encode(['success'=>false,'error'=>'Mã công trình (MACT) đã tồn tại. Vui lòng nhập mã khác.']);
                    exit;
                }
                $mact = $provided;
            }
        }
        if (!$mact) {
            $attempts = 0;
            while ($attempts < 5 && !$mact) {
                $candidate = 'CT' . date('ymdHis') . sprintf('%03d', rand(0,999));
                $chk = $pdo->prepare('SELECT 1 FROM congtrinh WHERE MACT = ? LIMIT 1');
                $chk->execute([$candidate]);
                if (!$chk->fetch()) {
                    $mact = $candidate;
                    break;
                }
                usleep(100000);
                $attempts++;
            }
            if (!$mact) {
                throw new Exception('Không thể tạo mã MACT duy nhất, thử lại sau.');
            }
        }

        $ins = $pdo->prepare('INSERT INTO congtrinh (MACT, TENCT, DIADIEM, NGANSACH, MOTA, NGAYBD, NGAYKT) VALUES (:mact, :tenct, :diadiem, :ngansach, :mota, :ngaybd, :ngaykt)');
        $params = [
            ':mact' => $mact,
            ':tenct' => $name,
            ':diadiem' => $location,
            ':ngansach' => $budget,
            ':mota' => $description,
            ':ngaybd' => $start_date,
            ':ngaykt' => $end_date
        ];
        _sp_log('EXECUTE congtrinh INSERT with params: ' . json_encode($params, JSON_UNESCAPED_UNICODE));
        $ins->execute($params);
        _sp_log('Inserted into congtrinh MACT=' . $mact);
        // fetch inserted row
        $sel = $pdo->prepare('SELECT MACT as id, TENCT as name, DIADIEM as location, NGANSACH as budget, MOTA as description, NGAYBD as start_date, NGAYKT as end_date FROM congtrinh WHERE MACT = ? LIMIT 1');
        $sel->execute([$mact]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'id' => $mact, 'data' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // fallback: insert into projects
    $stmt = $pdo->prepare('INSERT INTO projects (name, description, start_date, end_date, status) VALUES (:name, :description, :start_date, :end_date, :status)');
    $projParams = [
        ':name' => $name,
        ':description' => $description,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':status' => 'active'
    ];
    _sp_log('EXECUTE projects INSERT with params: ' . json_encode($projParams, JSON_UNESCAPED_UNICODE));
    $stmt->execute($projParams);
    $insertId = (int)$pdo->lastInsertId();
    _sp_log('Inserted into projects id=' . $insertId);
    $sel2 = $pdo->prepare('SELECT id, name, description, start_date, end_date FROM projects WHERE id = ? LIMIT 1');
    $sel2->execute([$insertId]);
    $row2 = $sel2->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'id' => $insertId, 'data' => $row2], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Insert failed: ' . $e->getMessage()]);
    exit;
}

?>
