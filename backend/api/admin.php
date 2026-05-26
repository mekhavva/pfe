<?php
ini_set('display_errors','0');
error_reporting(0);
// ============================================================
// API الأدمن — كل الصلاحيات:
// - إدارة المستخدمين (pending → student/teacher)
// - إدارة الصالات (اسم، سعة، وصف)
// - رؤية الطلاب بأسمائهم + الأماكن المتبقية
// ============================================================
require_once '../config/db.php';
require_once '../includes/helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

requireRole('admin');

$action = $_GET['action'] ?? '';
$data   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    // مستويات
    case 'get_levels':       getLevels();              break;
    case 'add_level':        addLevel($data);          break;
    case 'delete_level':     deleteLevel($data);       break;
    // مستخدمون
    case 'get_users':        getUsers();               break;
    case 'get_pending':      getPendingUsers();        break;
    case 'approve_user':     approveUser($data);       break;
    case 'reject_user':      rejectUser($data);        break;
    case 'add_user':         addUser($data);           break;
    case 'toggle_user':      toggleUser($data);        break;
    case 'delete_user':      deleteUser($data);        break;
    // صالات
    case 'get_rooms':        getRooms();               break;
    case 'add_room':         addRoom($data);           break;
    case 'edit_room':        editRoom($data);          break;
    case 'delete_room':      deleteRoom($data);        break;
    case 'toggle_room':      toggleRoom($data);        break;
    // حصص TP
    case 'get_pws':          getAllPWs();              break;
    case 'get_reservations': getAllReservations();     break;
    case 'delete_pw':        deletePW($data);          break;
    case 'toggle_pw':        togglePW($data);          break;
    // تفاصيل طلاب TP بأسمائهم + الأماكن المتبقية
    case 'get_pw_detail':    getPWDetail($data);       break;
    // الحجوزات الفائتة
    case 'get_missed_sessions': getMissedSessions();   break;
    // إحصائيات
    case 'stats':            getStats();               break;
    default: jsonResponse(false, 'Invalid action');
}

// ============================================================
// المستويات
// ============================================================
function getLevels() {
    $db = getDB();
    // ترتيب L1 L2 L3 Ing1 Ing2 M1 M2 ... (prefix أبجدي ثم رقم)
    $stmt = $db->query(
        "SELECT * FROM levels
         ORDER BY
           REGEXP_REPLACE(name, '[0-9]+', '') ASC,
           CAST(REGEXP_REPLACE(name, '[^0-9]+', '') AS UNSIGNED) ASC"
    );
    jsonResponse(true,'OK',['levels'=>$stmt->fetchAll()]);
}
function addLevel($data) {
    $name = strtoupper(sanitize($data['name']??''));
    $desc = sanitize($data['description']??'');
    if(!$name) jsonResponse(false,'Level name required');
    try {
        getDB()->prepare("INSERT INTO levels (name,description) VALUES (?,?)")->execute([$name,$desc]);
        jsonResponse(true,'Level added');
    } catch(Exception $e){ jsonResponse(false,'Level already exists'); }
}
function deleteLevel($data) {
    $id=intval($data['id']??0); if(!$id) jsonResponse(false,'ID required');
    getDB()->prepare("DELETE FROM levels WHERE id=?")->execute([$id]);
    jsonResponse(true,'Deleted');
}

// ============================================================
// المستخدمون
// ============================================================
function getUsers() {
    $db = getDB();
    // نُعيد كل المستخدمين غير pending — الترتيب يتم في JavaScript
    $stmt = $db->query(
        "SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active,
                l.name AS level_name,
                REGEXP_REPLACE(l.name, '[0-9]+', '') AS level_prefix,
                CAST(REGEXP_REPLACE(COALESCE(l.name,''), '[^0-9]+', '') AS UNSIGNED) AS level_num
         FROM users u
         LEFT JOIN levels l ON u.level_id = l.id
         WHERE u.role != 'pending'
         ORDER BY u.full_name ASC"
    );
    jsonResponse(true,'OK',['users'=>$stmt->fetchAll()]);
}

function getPendingUsers() {
    $db=$getDB=getDB();
    $stmt=$db->query("SELECT id,username,full_name,email,created_at FROM users WHERE role='pending' ORDER BY created_at DESC");
    jsonResponse(true,'OK',['pending'=>$stmt->fetchAll()]);
}

// الأدمن يوافق على مستخدم ويحدد دوره (student أو teacher) ومستواه ورقم قروبه
function approveUser($data) {
    $id       = intval($data['id']       ?? 0);
    $role     = $data['role']             ?? '';
    $levelId  = intval($data['level_id'] ?? 0) ?: null;
    $groupNum = intval($data['group_num'] ?? 0) ?: null;

    if(!$id) jsonResponse(false,'ID required');
    if(!in_array($role,['student','teacher'])) jsonResponse(false,'Role must be student or teacher');
    if($role==='student' && !$levelId) jsonResponse(false,'Level required for students');

    $db=$getDB=getDB();
    $db->prepare("UPDATE users SET role=?,level_id=?,group_num=? WHERE id=? AND role='pending'")
       ->execute([$role,$levelId,$groupNum,$id]);
    jsonResponse(true,'User approved as '.$role);
}

function rejectUser($data) {
    $id=intval($data['id']??0);
    getDB()->prepare("DELETE FROM users WHERE id=? AND role='pending'")->execute([$id]);
    jsonResponse(true,'Rejected');
}

function addUser($data) {
    $username=sanitize($data['username']??'');$password=$data['password']??'';
    $fullName=sanitize($data['full_name']??'');$email=sanitize($data['email']??'');
    $role=$data['role']??'teacher';
    if(!in_array($role,['admin','teacher'])) jsonResponse(false,'Invalid role');
    if(!$username||!$password||!$fullName) jsonResponse(false,'Fields missing');
    $db=getDB();
    $c=$db->prepare("SELECT id FROM users WHERE username=?");$c->execute([$username]);
    if($c->fetch()) jsonResponse(false,'Username exists');
    $db->prepare("INSERT INTO users (username,password,full_name,email,role) VALUES (?,?,?,?,?)")
       ->execute([$username,password_hash($password,PASSWORD_BCRYPT),$fullName,$email,$role]);
    jsonResponse(true,'User added');
}
function toggleUser($data) {
    $id=intval($data['id']??0);
    getDB()->prepare("UPDATE users SET is_active=NOT is_active WHERE id=?")->execute([$id]);
    jsonResponse(true,'Updated');
}
function deleteUser($data) {
    $id=intval($data['id']??0);$my=$_SESSION['user_id'];
    if(!$id||$id==$my) jsonResponse(false,'Cannot delete');
    getDB()->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    jsonResponse(true,'Deleted');
}

// ============================================================
// الصالات — إدارة كاملة من الأدمن
// ============================================================
function getRooms() {
    $db   = getDB();
    // عدد الـ TPs المسندة لكل صالة
    $stmt = $db->query(
        "SELECT r.*,COUNT(DISTINCT p.id) AS assigned_pws
         FROM rooms r LEFT JOIN pws p ON p.room_id=r.id
         GROUP BY r.id ORDER BY r.name"
    );
    jsonResponse(true,'OK',['rooms'=>$stmt->fetchAll()]);
}
function addRoom($data) {
    $name = sanitize($data['name']        ?? '');
    $cap  = intval($data['capacity']      ?? 30);
    $desc = sanitize($data['description'] ?? '');
    if(!$name) jsonResponse(false,'Room name required');
    if($cap<1) $cap=1;
    try {
        getDB()->prepare("INSERT INTO rooms (name,capacity,description) VALUES (?,?,?)")->execute([$name,$cap,$desc]);
        jsonResponse(true,'Room added');
    } catch(Exception $e){ jsonResponse(false,'Room name already exists'); }
}
function editRoom($data) {
    $id   = intval($data['id']            ?? 0);
    $name = sanitize($data['name']        ?? '');
    $cap  = intval($data['capacity']      ?? 30);
    $desc = sanitize($data['description'] ?? '');
    if(!$id||!$name) jsonResponse(false,'ID and name required');
    getDB()->prepare("UPDATE rooms SET name=?,capacity=?,description=? WHERE id=?")->execute([$name,$cap,$desc,$id]);
    jsonResponse(true,'Room updated');
}
function deleteRoom($data) {
    $id=intval($data['id']??0);
    getDB()->prepare("DELETE FROM rooms WHERE id=?")->execute([$id]);
    jsonResponse(true,'Deleted');
}
function toggleRoom($data) {
    $id=intval($data['id']??0);
    getDB()->prepare("UPDATE rooms SET is_active=NOT is_active WHERE id=?")->execute([$id]);
    jsonResponse(true,'Updated');
}

// ============================================================
// حصص TP
// ============================================================
function getAllPWs() {
    $db = getDB();
    $stmt = $db->query(
        "SELECT p.*, u.full_name AS teacher_name, l.name AS level_name,
                r.name AS room_name, r.capacity AS room_capacity,
                COUNT(DISTINCT s.id)   AS total_slots,
                COUNT(DISTINCT res.id) AS total_reservations,
                REGEXP_REPLACE(l.name, '[0-9]+', '') AS level_prefix,
                CAST(REGEXP_REPLACE(l.name, '[^0-9]+', '') AS UNSIGNED) AS level_num
         FROM pws p
         JOIN users u  ON p.teacher_id = u.id
         JOIN levels l ON p.level_id   = l.id
         LEFT JOIN rooms r        ON p.room_id  = r.id
         LEFT JOIN slots s        ON s.pw_id    = p.id
         LEFT JOIN reservations res ON res.pw_id = p.id AND res.status = 'active'
         GROUP BY p.id
         ORDER BY level_prefix ASC, level_num ASC, p.title ASC"
    );
    jsonResponse(true,'OK',['pws'=>$stmt->fetchAll()]);
}
// ============================================================
// الحجوزات
// ============================================================
function getAllReservations() {
    $db = getDB();
    $stmt = $db->query(
        "SELECT res.id, res.status,
                u.full_name AS student_name, u.username,
                p.title AS pw_title,
                s.slot_date, s.start_time, s.end_time,
                l.name AS level_name,
                t.full_name AS teacher_name
         FROM reservations res
         JOIN users u  ON res.student_id  = u.id
         JOIN pws p    ON res.pw_id       = p.id
         JOIN slots s  ON res.slot_id     = s.id
         LEFT JOIN levels l ON u.level_id = l.id
         LEFT JOIN users t  ON p.teacher_id= t.id
         WHERE res.status = 'active'
         ORDER BY s.slot_date DESC, s.start_time ASC"
    );
    jsonResponse(true,'OK',['reservations'=>$stmt->fetchAll()]);
}

function deletePW($data) {
    getDB()->prepare("DELETE FROM pws WHERE id=?")->execute([intval($data['id']??0)]);
    jsonResponse(true,'Deleted');
}
function togglePW($data) {
    getDB()->prepare("UPDATE pws SET is_active=NOT is_active WHERE id=?")->execute([intval($data['id']??0)]);
    jsonResponse(true,'Updated');
}

// ============================================================
// تفاصيل TP للأدمن: أسماء الطلاب + أماكن متبقية في الصالة
// ============================================================
function getPWDetail($data) {
    $pwId = intval($_GET['pw_id'] ?? $data['pw_id'] ?? 0);
    if(!$pwId) jsonResponse(false,'PW ID required');

    $db = getDB();

    // معلومات الصالة
    $info = $db->prepare(
        "SELECT p.title, r.name AS room_name, r.capacity,
                COUNT(DISTINCT res.id) AS total_reserved
         FROM pws p
         LEFT JOIN rooms r ON p.room_id=r.id
         LEFT JOIN reservations res ON res.pw_id=p.id AND res.status='active'
         WHERE p.id=?"
    );
    $info->execute([$pwId]);
    $pw = $info->fetch();

    // الطلاب المسجلين بأسمائهم لكل فترة
    $students = $db->prepare(
        "SELECT s.slot_date, s.start_time, s.end_time,
                u.full_name, u.username,
                l.name AS level_name
         FROM reservations res
         JOIN users u ON res.student_id=u.id
         JOIN slots s ON res.slot_id=s.id
         LEFT JOIN levels l ON u.level_id=l.id
         WHERE res.pw_id=? AND res.status='active'
         ORDER BY s.slot_date, s.start_time, u.full_name"
    );
    $students->execute([$pwId]);

    $free_places = $pw ? max(0, intval($pw['capacity']) - intval($pw['total_reserved'])) : null;

    jsonResponse(true,'OK',[
        'pw_info'     => $pw,
        'free_places' => $free_places,
        'students'    => $students->fetchAll()
    ]);
}

// ============================================================
// إحصائيات
// ============================================================
function getStats() {
    $db=$getDB=getDB();
    jsonResponse(true,'OK',['stats'=>[
        'total_students'     => $db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn(),
        'total_teachers'     => $db->query("SELECT COUNT(*) FROM users WHERE role='teacher'")->fetchColumn(),
        'total_pws'          => $db->query("SELECT COUNT(*) FROM pws")->fetchColumn(),
        'total_reservations' => $db->query("SELECT COUNT(*) FROM reservations WHERE status='active'")->fetchColumn(),
        'total_levels'       => $db->query("SELECT COUNT(*) FROM levels")->fetchColumn(),
        'total_rooms'        => $db->query("SELECT COUNT(*) FROM rooms WHERE is_active=1")->fetchColumn(),
        'pending_users'      => $db->query("SELECT COUNT(*) FROM users WHERE role='pending'")->fetchColumn(),
        'total_missed'       => $db->query("SELECT COUNT(*) FROM reservations WHERE status='missed'")->fetchColumn(),
    ]]);
}

// ============================================================
// الحجوزات الفائتة (الطلاب الذين تأخروا ولم يدخلوا في الوقت المحدد)
// ============================================================
function getMissedSessions() {
    $db = getDB();
    $stmt = $db->query(
        "SELECT res.id, res.status, res.reserved_at,
                u.full_name AS student_name, u.username,
                p.title AS pw_title,
                s.slot_date, s.start_time, s.end_time,
                l.name AS level_name,
                t.full_name AS teacher_name
         FROM reservations res
         JOIN users u  ON res.student_id  = u.id
         JOIN pws p    ON res.pw_id       = p.id
         JOIN slots s  ON res.slot_id     = s.id
         LEFT JOIN levels l ON u.level_id = l.id
         LEFT JOIN users t  ON p.teacher_id= t.id
         WHERE res.status = 'missed'
         ORDER BY s.slot_date DESC, s.start_time ASC"
    );
    jsonResponse(true, 'OK', ['missed_sessions' => $stmt->fetchAll()]);
}
?>
