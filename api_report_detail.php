<?php
header('Content-Type: application/json; charset=utf-8');
// api_report_detail.php?type=...&filter=...
// Require login to control report visibility
require_once __DIR__ . '/auth.php';
auth_require_logged_in();
$currentUser = auth_get_user();
$currentUid = $currentUser['id'] ?? null;
$currentRole = strtolower($currentUser['role'] ?? '');
// Deny access to reports for employees per RBAC policy
if ($currentRole === 'employee') {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Quyền truy cập báo cáo không đủ']);
    exit;
}
$dbNames = ['quanlytiendo','quanlytiendo_db']; $pdo=null; $usedDb=null; $lastEx=null;
@file_put_contents(__DIR__.'/save_project.log', "[".date('c')."] api_report_detail.php called type=".($_GET['type']??'')." filter=".($_GET['filter']??'')."\n", FILE_APPEND|LOCK_EX);
foreach ($dbNames as $dbName) { try { $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $usedDb=$dbName; break;} catch (PDOException $e){ $lastEx=$e; $pdo=null;} }
if (!$pdo) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'DB connect failed']); exit; }
$type = isset($_GET['type']) ? $_GET['type'] : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
try {
    $result = ['success'=>true,'headers'=>[], 'data'=>[]];
    switch ($type) {
        case 'ct_ngansach':
            $result['headers'] = ['ID','Tên Công Trình','Ngân sách','Trạng thái'];
            $stmt = $pdo->prepare("SELECT id, COALESCE(TENCT, name) as name, COALESCE(NGANSACH,0) as budget, COALESCE(status,'') as status FROM congtrinh");
            $stmt->execute();
            foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['id'],$r['name'],$r['budget'],$r['status']];
            break;
        case 'ct_trangthai':
            $result['headers'] = ['Tên Công Trình','Trạng thái','Số Hạng Mục'];
            $stmt = $pdo->query("SELECT COALESCE(p.TENCT,p.name) as name, CASE WHEN COALESCE(p.NGAYKT,p.end_date) < CURDATE() THEN 'completed' WHEN COALESCE(p.NGAYBD,p.start_date) > CURDATE() THEN 'pending' WHEN COALESCE(p.NGAYBD,p.start_date) <= CURDATE() AND COALESCE(p.NGAYKT,p.end_date) >= CURDATE() THEN 'in_progress' ELSE '' END as status, COUNT(c.id) as cnt FROM projects p LEFT JOIN categories c ON c.project_id = p.id GROUP BY p.id");
            foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['name'],$r['status'],$r['cnt']];
            break;
        case 'ct_matdo':
            $result['headers'] = ['Tên Công Trình','Số Hạng Mục','TB Tiến Độ (%)'];
            // For employees, compute averages only over tasks assigned to them
            if ($currentRole === 'employee' && $currentUid) {
                $stmt = $pdo->prepare("SELECT p.name as name, COUNT(DISTINCT c.id) as cat_count, ROUND(COALESCE(AVG(t.progress),0),1) as avg_progress FROM projects p LEFT JOIN categories c ON c.project_id = p.id LEFT JOIN tasks t ON t.category_id = c.id AND t.assigned_to = ? GROUP BY p.id");
                $stmt->execute([(int)$currentUid]);
            } else {
                $stmt = $pdo->query("SELECT p.name as name, COUNT(DISTINCT c.id) as cat_count, ROUND(COALESCE(AVG(t.progress),0),1) as avg_progress FROM projects p LEFT JOIN categories c ON c.project_id = p.id LEFT JOIN tasks t ON t.category_id = c.id GROUP BY p.id");
            }
            foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['name'],$r['cat_count'],$r['avg_progress']];
            break;
        case 'hm_trangthai':
            $result['headers'] = ['Hạng mục','Số công việc','Hoàn thành'];
            $stmt = $pdo->query("SELECT c.name as name, COUNT(t.id) as tasks_total, SUM(t.progress>=100) as done FROM categories c LEFT JOIN tasks t ON t.category_id = c.id GROUP BY c.id");
            foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['name'],$r['tasks_total'],$r['done']];
            break;
        case 'hm_trehan':
            $result['headers'] = ['Hạng mục','Công việc','Người thực hiện','Hạn','Tiến độ'];
            $sql = "SELECT c.name as category, t.title as task, u.name as user, t.end_date, t.progress FROM tasks t JOIN categories c ON c.id = t.category_id LEFT JOIN users u ON u.id = t.assigned_to WHERE t.end_date < CURDATE() AND t.progress < 100";
            if ($currentRole === 'employee' && $currentUid) {
                $sql .= ' AND t.assigned_to = ' . (int)$currentUid;
            }
            $sql .= ' ORDER BY t.end_date DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['category'],$r['task'],$r['user'],$r['end_date'],$r['progress']];
            break;
        case 'hm_thang':
            $result['headers'] = ['Tháng','Số hạng mục mới'];
            $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') as m, COUNT(*) as c FROM categories WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY m ORDER BY m");
            $stmt->execute();
            foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['m'],$r['c']];
            break;
        case 'ns_workload':
            $result['headers'] = ['Nhân viên','Số việc','Đã xong','Chưa xong'];
            // Prefer nhanvien table; fallback to users
            $hasNhan = false; $nhanTable = null;
            $t = $pdo->prepare("SHOW TABLES LIKE ?"); $t->execute(['nhanvien']); if ($t->fetch()) { $hasNhan = true; $nhanTable = 'nhanvien'; }
            // find tasks table
            $taskTable = null; foreach(['tiendo','tasks'] as $tc){ $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$tc]); if($s->fetch()){ $taskTable=$tc; break; } }
            if ($hasNhan && $taskTable) {
                // find identifier columns
                $ncols = $pdo->query("SHOW COLUMNS FROM {$nhanTable}")->fetchAll(PDO::FETCH_COLUMN);
                $ncolsLower = array_map('strtolower',$ncols);
                $equiv = null; foreach (['manv','id','ma_nv','nv_id'] as $cand){ $idx=array_search($cand,$ncolsLower); if($idx!==false){ $equiv=$ncols[$idx]; break; } }
                $tcols = $pdo->query("SHOW COLUMNS FROM {$taskTable}")->fetchAll(PDO::FETCH_COLUMN);
                $tcolsLower = array_map('strtolower',$tcols);
                $assignCol = null; foreach(['manv','assigned_to','user_id','nguoi','nguoi_phu_trach'] as $cand){ $idx=array_search($cand,$tcolsLower); if($idx!==false){ $assignCol=$tcols[$idx]; break; } }
                if ($equiv && $assignCol) {
                    if ($currentRole === 'employee' && $currentUid) {
                        $sql = "SELECT COALESCE(n.TENNV,n.name,n.ten,n.TEN) as name, COUNT(t.id) as total, SUM(t.progress>=100) as done, SUM(t.progress<100) as pending FROM {$nhanTable} n LEFT JOIN {$taskTable} t ON t.`{$assignCol}` = n.`{$equiv}` WHERE n.`{$equiv}` = ? GROUP BY n.`{$equiv}`";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([(int)$currentUid]);
                    } else {
                        $sql = "SELECT COALESCE(n.TENNV,n.name,n.ten,n.TEN) as name, COUNT(t.id) as total, SUM(t.progress>=100) as done, SUM(t.progress<100) as pending FROM {$nhanTable} n LEFT JOIN {$taskTable} t ON t.`{$assignCol}` = n.`{$equiv}` GROUP BY n.`{$equiv}` ORDER BY total DESC";
                        $stmt = $pdo->query($sql);
                    }
                    foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['name'],$r['total'],$r['done'],$r['pending']];
                } else {
                    // return employees with zero counts
                    $stmt = $pdo->query("SELECT COALESCE(n.TENNV,n.name,n.ten,n.TEN) as name, 0 as total, 0 as done, 0 as pending FROM {$nhanTable} n ORDER BY name");
                    foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['name'],$r['total'],$r['done'],$r['pending']];
                }
            } elseif ($taskTable) {
                // fallback to users/tasks
                if ($currentRole === 'employee' && $currentUid) {
                    $stmt = $pdo->prepare("SELECT u.name as name, COUNT(t.id) as total, SUM(t.progress>=100) as done, SUM(t.progress<100) as pending FROM users u LEFT JOIN tasks t ON t.assigned_to = u.id WHERE u.id = ? GROUP BY u.id");
                    $stmt->execute([(int)$currentUid]);
                } else {
                    $stmt = $pdo->query("SELECT u.name as name, COUNT(t.id) as total, SUM(t.progress>=100) as done, SUM(t.progress<100) as pending FROM users u LEFT JOIN tasks t ON t.assigned_to = u.id GROUP BY u.id ORDER BY total DESC");
                }
                foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['name'],$r['total'],$r['done'],$r['pending']];
            } else {
                // no tasks; list employees only
                if ($hasNhan) {
                    $stmt = $pdo->query("SELECT COALESCE(n.TENNV,n.name,n.ten,n.TEN) as name FROM {$nhanTable} n ORDER BY name");
                    foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['name'],0,0,0];
                } else {
                    $stmt = $pdo->query("SELECT name FROM users"); foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['name'],0,0,0];
                }
            }
            break;
        case 'ns_hm':
            // list hangmuc for a given employee code or name (filter)
            $result['headers'] = ['Mã HM','Tên Hạng Mục','Công trình','Trạng thái'];
            // detect hangmuc table name
            $hangTable = null; foreach(['hangmuc','categories'] as $hname){ $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$hname]); if($s->fetch()){ $hangTable=$hname; break; } }
            if ($hangTable) {
                // detect manager column in hangmuc
                $hcols = $pdo->query("SHOW COLUMNS FROM {$hangTable}")->fetchAll(PDO::FETCH_COLUMN);
                $hcolsLower = array_map('strtolower',$hcols);
                $hangManvCol = null; foreach(['manv','MANV','nguoi_phu_trach','nguoi'] as $cand){ $idx = array_search(strtolower($cand), $hcolsLower); if($idx!==false){ $hangManvCol = $hcols[$idx]; break; } }
                $nameCol = null; foreach(['tenhm','name','ten','title'] as $cand){ $idx = array_search(strtolower($cand), $hcolsLower); if($idx!==false){ $nameCol = $hcols[$idx]; break; } }
                $codeCol = null; foreach(['mahm','MAHM','id','code'] as $cand){ $idx = array_search(strtolower($cand), $hcolsLower); if($idx!==false){ $codeCol = $hcols[$idx]; break; } }
                $statusCol = null; foreach(['trangthai','status'] as $cand){ $idx = array_search(strtolower($cand), $hcolsLower); if($idx!==false){ $statusCol = $hcols[$idx]; break; } }
                if ($hangManvCol) {
                    // If nhanvien exists, attempt to resolve the filter (name or code) to possible stored values
                    $candidates = [$filter];
                    $t = $pdo->prepare("SHOW TABLES LIKE ?"); $t->execute(['nhanvien']); $hasNhan = $t->fetch() ? true : false;
                    if ($hasNhan) {
                        // find possible manv and name columns
                        $ncols = $pdo->query("SHOW COLUMNS FROM nhanvien")->fetchAll(PDO::FETCH_COLUMN);
                        $ncolsLower = array_map('strtolower',$ncols);
                        $manvCol = null; foreach(['manv','id','ma_nv','nv_id'] as $cand){ $idx=array_search($cand,$ncolsLower); if($idx!==false){ $manvCol=$ncols[$idx]; break; } }
                        $tenCol = null; foreach(['tennv','name','ten','TENNV'] as $cand){ $idx=array_search($cand,$ncolsLower); if($idx!==false){ $tenCol=$ncols[$idx]; break; } }
                        if ($manvCol && $tenCol) {
                            $s = $pdo->prepare("SELECT `{$manvCol}` as manv, COALESCE(`{$tenCol}`,`name`,`ten`,`TENNV`) as ten FROM nhanvien WHERE `{$manvCol}` = ? OR `{$tenCol}` = ? LIMIT 5");
                            $s->execute([$filter,$filter]);
                            foreach($s->fetchAll() as $er) { if(!in_array($er['manv'],$candidates)) $candidates[] = $er['manv']; if(!in_array($er['ten'],$candidates)) $candidates[] = $er['ten']; }
                        } else {
                            // attempt to search by name across any text columns
                            $s = $pdo->prepare("SELECT * FROM nhanvien WHERE TENNV = ? OR name = ? OR ten = ? LIMIT 5");
                            $s->execute([$filter,$filter,$filter]);
                            foreach($s->fetchAll() as $er) { foreach($er as $val) { if(is_scalar($val) && !in_array($val,$candidates)) $candidates[] = $val; } }
                        }
                    }

                    // Build IN clause placeholders
                    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
                    $sql = "SELECT ".($codeCol?"`{$codeCol}`":"id")." as code, COALESCE(`{$nameCol}`,'' ) as name, COALESCE(MACT,'') as project, COALESCE(`{$statusCol}`,'') as status FROM {$hangTable} WHERE `{$hangManvCol}` IN ({$placeholders}) ORDER BY ".($codeCol?"`{$codeCol}`":"id");
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($candidates);
                    foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['code'],$r['name'],$r['project'],$r['status']];
                }
            }
            break;
        case 'ns_chucvu':
            $result['headers'] = ['Nhân viên','Chức vụ'];
            // prefer nhanvien table
            $t = $pdo->prepare("SHOW TABLES LIKE ?"); $t->execute(['nhanvien']); if ($t->fetch()) {
                // detect name and role columns
                $ncols = $pdo->query("SHOW COLUMNS FROM nhanvien")->fetchAll(PDO::FETCH_COLUMN);
                $ncolsLower = array_map('strtolower',$ncols);
                $nameCol = in_array('tennv',$ncolsLower)?$ncols[array_search('tennv',$ncolsLower)]: (in_array('name',$ncolsLower)?$ncols[array_search('name',$ncolsLower)]: (in_array('ten',$ncolsLower)?$ncols[array_search('ten',$ncolsLower)]:$ncols[0]));
                $roleCol = in_array('chucvu',$ncolsLower)?$ncols[array_search('chucvu',$ncolsLower)]: (in_array('role',$ncolsLower)?$ncols[array_search('role',$ncolsLower)]: null);
                if ($roleCol) {
                    $stmt = $pdo->query("SELECT COALESCE(`{$nameCol}`,`name`,`ten`,`TENNV`) as name, COALESCE(`{$roleCol}`,'') as role FROM nhanvien");
                } else {
                    $stmt = $pdo->query("SELECT COALESCE(`{$nameCol}`,`name`,`ten`,`TENNV`) as name, '' as role FROM nhanvien");
                }
                foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['name'],$r['role']];
            } else {
                $stmt = $pdo->query("SELECT name, role FROM users"); foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['name'],$r['role']];
            }
            break;
        case 'td_nhom':
            $result['headers'] = ['Nhóm','Số bản ghi'];
            $stmt = $pdo->query("SELECT CASE WHEN progress < 50 THEN '0-49' WHEN progress < 80 THEN '50-79' WHEN progress < 100 THEN '80-99' ELSE '100' END as bucket, COUNT(*) as c FROM tasks GROUP BY bucket");
            foreach ($stmt->fetchAll() as $r) $result['data'][] = [$r['bucket'],$r['c']];
            break;
        default:
            $result = ['success'=>false,'error'=>'Unknown type'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit; }
?>