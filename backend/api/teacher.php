<?php
ini_set('display_errors','0');
error_reporting(0);
require_once '../config/db.php';
require_once '../includes/helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

requireLogin();
if (!in_array(getUserRole(), ['teacher','admin']))
    jsonResponse(false, 'Access denied');

$action    = $_GET['action'] ?? '';
$data      = json_decode(file_get_contents('php://input'), true) ?? [];
$teacherId = $_SESSION['user_id'];

switch ($action) {
    case 'get_my_pws':          getMyPWs($teacherId);                    break;
    case 'create_pw':           createPW($data, $teacherId);             break;
    case 'delete_pw':           deleteMyPW($data, $teacherId);           break;
    case 'add_slot':            addSlot($data, $teacherId);              break;
    case 'generate_slots':      generateSlots($data, $teacherId);        break;
    case 'get_slots':           getSlots($data, $teacherId);             break;
    case 'delete_slot':         deleteSlot($data, $teacherId);           break;
    case 'force_free_slot':     forceFreeSlot($data, $teacherId);        break;
    case 'get_reservations':    getReservationsCount($teacherId);        break;
    case 'get_levels':          getLevels();                             break;
    case 'get_level_students':  getLevelStudents();                      break;
    case 'get_level_groups':    getLevelGroups();                        break;
    case 'get_available_rooms': getAvailableRooms($data);               break;
    case 'get_busy_dates':      getBusyDates();                          break;
    default: jsonResponse(false, 'Invalid action');
}

// ============================================================
// حصص الأستاذ
// ============================================================
function getMyPWs($teacherId) {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT p.*, l.name AS level_name, r.name AS room_name,
                COUNT(DISTINCT s.id)   AS total_slots,
                COUNT(DISTINCT res.id) AS reserved_slots
         FROM pws p
         JOIN levels l ON p.level_id = l.id
         LEFT JOIN rooms r   ON p.room_id = r.id
         LEFT JOIN slots s   ON s.pw_id   = p.id
         LEFT JOIN reservations res ON res.pw_id=p.id AND res.status='active'
         WHERE p.teacher_id=?
         GROUP BY p.id
         ORDER BY p.created_at DESC"
    );
    $stmt->execute([$teacherId]);
    jsonResponse(true, 'OK', ['pws' => $stmt->fetchAll()]);
}

// ============================================================
// إنشاء TP جديد
// ============================================================
function createPW($data, $teacherId) {
    $title     = sanitize($data['title']       ?? '');
    $desc      = sanitize($data['description'] ?? '');
    $levelId   = intval($data['level_id']      ?? 0);
    $roomId    = intval($data['room_id']        ?? 0) ?: null;
    $startDate = $data['start_date']            ?? null;
    $endDate   = $data['end_date']              ?? null;

    if (empty($title) || !$levelId)
        jsonResponse(false, 'Title and level are required');

    $db = getDB();
if ($roomId && $startDate && $endDate) {
        $roomConflict = $db->prepare(
            "SELECT p.title FROM pws p
             WHERE p.room_id=? AND p.id != 0
               AND NOT (p.end_date < ? OR p.start_date > ?)"
        );
        $roomConflict->execute([$roomId, $startDate, $endDate]);
        $cf = $roomConflict->fetch();
        if ($cf) jsonResponse(false, 'Room is already booked for "'.$cf['title'].'" during this period.');
    }

    $db->prepare(
        "INSERT INTO pws (title,description,teacher_id,level_id,room_id,start_date,end_date)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([$title,$desc,$teacherId,$levelId,$roomId,$startDate,$endDate]);

    jsonResponse(true, 'TP created successfully', ['pw_id' => $db->lastInsertId()]);
}

function deleteMyPW($data, $teacherId) {
    $id = intval($data['id'] ?? 0);
    $db = getDB();
    $check = $db->prepare("SELECT id FROM pws WHERE id=? AND teacher_id=?");
    $check->execute([$id, $teacherId]);
    if (!$check->fetch()) jsonResponse(false, 'Access denied');
    $db->prepare("DELETE FROM pws WHERE id=?")->execute([$id]);
    jsonResponse(true, 'TP deleted');
}

// ============================================================
// جلب عدد الطلاب في مستوى معين (مع تصفية اختيارية بالقروب)
// ============================================================
function getLevelStudents() {
    $levelId  = intval($_GET['level_id']  ?? 0);
    $groupNum = intval($_GET['group_num'] ?? 0); // اختياري: رقم القروب
    if (!$levelId) jsonResponse(false, 'Level ID required');
    $db = getDB();

    if ($groupNum > 0) {
        // جلب طلاب قروب محدد
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS student_count FROM users
             WHERE level_id=? AND role='student' AND is_active=1 AND group_num=?"
        );
        $stmt->execute([$levelId, $groupNum]);
    } else {
        // جلب كل طلاب المستوى
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS student_count FROM users
             WHERE level_id=? AND role='student' AND is_active=1"
        );
        $stmt->execute([$levelId]);
    }
    $row = $stmt->fetch();
    jsonResponse(true, 'OK', ['student_count' => (int)$row['student_count']]);
}

// ============================================================
// جلب القروبات المتاحة في مستوى معين
// إذا كان العمود group_num موجوداً في جدول users
// ============================================================
function getLevelGroups() {
    $levelId = intval($_GET['level_id'] ?? 0);
    if (!$levelId) jsonResponse(false, 'Level ID required');
    $db = getDB();

    // نتحقق إذا كان عمود group_num موجوداً
    try {
        $check = $db->query("SHOW COLUMNS FROM users LIKE 'group_num'");
        if (!$check->fetch()) {
            // العمود غير موجود — نعيد قائمة فارغة
            jsonResponse(true, 'OK', ['groups' => []]);
        }
    } catch(Exception $e) {
        jsonResponse(true, 'OK', ['groups' => []]);
    }

    $stmt = $db->prepare(
        "SELECT group_num,
                COUNT(*) AS student_count,
                CONCAT('Group ', group_num) AS label
         FROM users
         WHERE level_id=? AND role='student' AND is_active=1
           AND group_num IS NOT NULL AND group_num > 0
         GROUP BY group_num
         ORDER BY group_num"
    );
    $stmt->execute([$levelId]);
    jsonResponse(true, 'OK', ['groups' => $stmt->fetchAll()]);
}

// ============================================================
// توليد Slots تلقائياً بناءً على عدد الطلاب والمدة والأيام
// ============================================================
function generateSlots($data, $teacherId) {
    $pwId         = intval($data['pw_id']          ?? 0);
    $studentCount = intval($data['student_count']  ?? 0);
    $durationMin  = intval($data['duration_min']   ?? 60);  // مدة كل طالب بالدقائق
    $startDate    = $data['start_date']             ?? '';
    $numDays      = intval($data['num_days']        ?? 5);   // 4 أو 7
    $dayStartH    = intval($data['day_start']       ?? 8);   // ساعة البداية (8 صباحاً)
    $dayEndH      = intval($data['day_end']         ?? 22);  // ساعة النهاية (22 مساءً)

    if (!$pwId || !$studentCount || !$startDate)
        jsonResponse(false, 'Missing required fields');

    $db    = getDB();
    $check = $db->prepare("SELECT id FROM pws WHERE id=? AND teacher_id=?");
    $check->execute([$pwId, $teacherId]);
    if (!$check->fetch()) jsonResponse(false, 'Access denied');

    // حذف الـ slots القديمة غير المحجوزة
    $db->prepare(
        "DELETE s FROM slots s
         LEFT JOIN reservations r ON r.slot_id=s.id AND r.status='active'
         WHERE s.pw_id=? AND r.id IS NULL"
    )->execute([$pwId]);

    $dayMinutes    = ($dayEndH - $dayStartH) * 60;          // دقائق متاحة في اليوم
    $slotsPerDay   = floor($dayMinutes / $durationMin);      // طلاب في اليوم
    $totalGenerated = 0;
    $remaining      = $studentCount;
    $insert         = $db->prepare(
        "INSERT INTO slots (pw_id, slot_date, start_time, end_time) VALUES (?,?,?,?)"
    );

    for ($d = 0; $d < $numDays && $remaining > 0; $d++) {
        $date       = date('Y-m-d', strtotime($startDate . " +$d days"));
        $dayWeek    = date('N', strtotime($date)); // 1=Mon ... 7=Sun
        // تخطي الجمعة (5) والسبت (6) إذا أردت — اتركها مفتوحة الآن
        $curMinutes = $dayStartH * 60;

        $todaySlots = min($slotsPerDay, $remaining);
        for ($i = 0; $i < $todaySlots; $i++) {
            $endMinutes = $curMinutes + $durationMin;
            if ($endMinutes > $dayEndH * 60) break;

            $startStr = sprintf('%02d:%02d:00', intdiv($curMinutes, 60), $curMinutes % 60);
            $endStr   = sprintf('%02d:%02d:00', intdiv($endMinutes, 60), $endMinutes % 60);

            $insert->execute([$pwId, $date, $startStr, $endStr]);
            $curMinutes = $endMinutes;
            $remaining--;
            $totalGenerated++;
        }
    }

    jsonResponse(true, "Generated $totalGenerated slots for $studentCount students", [
        'generated' => $totalGenerated,
        'remaining' => $remaining
    ]);
}

// ============================================================
// إضافة فترة زمنية يدوية
// ============================================================
function addSlot($data, $teacherId) {
    $pwId      = intval($data['pw_id']      ?? 0);
    $slotDate  = $data['slot_date']          ?? '';
    $startTime = $data['start_time']         ?? '';
    $endTime   = $data['end_time']           ?? '';

    if (!$pwId || empty($slotDate) || empty($startTime))
        jsonResponse(false, 'PW, date and start time required');

    $db    = getDB();
    $check = $db->prepare("SELECT id,room_id FROM pws WHERE id=? AND teacher_id=?");
    $check->execute([$pwId, $teacherId]);
    $pw = $check->fetch();
    if (!$pw) jsonResponse(false, 'Access denied');

    if (empty($endTime)) {
        $endTime = date('H:i:s', strtotime($startTime . ' +2 hours'));
    }

    $db->prepare("INSERT INTO slots (pw_id,slot_date,start_time,end_time) VALUES (?,?,?,?)")
       ->execute([$pwId, $slotDate, $startTime, $endTime]);
    jsonResponse(true, 'Slot added');
}

function getSlots($data, $teacherId) {
    $pwId = intval($data['pw_id'] ?? $_GET['pw_id'] ?? 0);
    if (!$pwId) jsonResponse(false, 'PW ID required');

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT s.id, s.slot_date, s.start_time, s.end_time, s.is_reserved,
                u.full_name AS reserved_by
         FROM slots s
         LEFT JOIN reservations r ON r.slot_id=s.id AND r.status='active'
         LEFT JOIN users u ON u.id=r.student_id
         WHERE s.pw_id=?
         ORDER BY s.slot_date, s.start_time"
    );
    $stmt->execute([$pwId]);
    jsonResponse(true, 'OK', ['slots' => $stmt->fetchAll()]);
}

function deleteSlot($data, $teacherId) {
    $slotId = intval($data['id'] ?? 0);
    $db     = getDB();
    $check  = $db->prepare("SELECT s.id FROM slots s JOIN pws p ON s.pw_id=p.id WHERE s.id=? AND p.teacher_id=?");
    $check->execute([$slotId, $teacherId]);
    if (!$check->fetch()) jsonResponse(false, 'Access denied');
    $db->prepare("DELETE FROM slots WHERE id=?")->execute([$slotId]);
    jsonResponse(true, 'Slot deleted');
}

function forceFreeSlot($data, $teacherId) {
    $slotId = intval($data['slot_id'] ?? 0);
    $db     = getDB();
    $check  = $db->prepare("SELECT s.id FROM slots s JOIN pws p ON s.pw_id=p.id WHERE s.id=? AND p.teacher_id=?");
    $check->execute([$slotId, $teacherId]);
    if (!$check->fetch()) jsonResponse(false, 'Access denied');
    $db->prepare("UPDATE reservations SET status='cancelled' WHERE slot_id=?")->execute([$slotId]);
    $db->prepare("UPDATE slots SET is_reserved=0 WHERE id=?")->execute([$slotId]);
    jsonResponse(true, 'Slot freed');
}

// ============================================================
// الحجوزات
// ============================================================
function getReservationsCount($teacherId) {
    $pwId = intval($_GET['pw_id'] ?? 0);
    if (!$pwId) jsonResponse(false, 'PW ID required');

    $db  = getDB();
    $own = $db->prepare("SELECT id FROM pws WHERE id=? AND teacher_id=?");
    $own->execute([$pwId, $teacherId]);
    if (!$own->fetch()) jsonResponse(false, 'Access denied');

    $stmt = $db->prepare(
        "SELECT s.id AS slot_id, s.slot_date, s.start_time, s.end_time,
                COUNT(r.id) AS student_count
         FROM slots s
         LEFT JOIN reservations r ON r.slot_id=s.id AND r.status='active'
         WHERE s.pw_id=?
         GROUP BY s.id
         ORDER BY s.slot_date, s.start_time"
    );
    $stmt->execute([$pwId]);
    jsonResponse(true, 'OK', ['slots_count' => $stmt->fetchAll()]);
}

// ============================================================
// الصالات المتاحة
// ============================================================
function getAvailableRooms($data) {
    $startDate = $data['start_date'] ?? '';
    $endDate   = $data['end_date']   ?? $startDate;

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT r.id, r.name, r.capacity, r.description
         FROM rooms r
         WHERE r.is_active=1
           AND r.id NOT IN (
               SELECT p.room_id FROM pws p
               WHERE p.room_id IS NOT NULL
                 AND NOT (p.end_date < ? OR p.start_date > ?)
           )
         ORDER BY r.name"
    );
    $stmt->execute([$startDate, $endDate]);
    jsonResponse(true, 'OK', ['rooms' => $stmt->fetchAll()]);
}

function getLevels() {
    $db = getDB();
    jsonResponse(true, 'OK', ['levels' => $db->query("SELECT * FROM levels ORDER BY name")->fetchAll()]);
}

// ============================================================
// جلب الأيام المحجوزة لكل مستوى (لمنع التعارض في الرزنامة)
// ============================================================
function getBusyDates() {
    $levelId = intval($_GET['level_id'] ?? 0);
    if (!$levelId) jsonResponse(false, 'Level ID required');

    $db = getDB();
    // جلب كل الـ slots المرتبطة بـ PWs من نفس المستوى
    $stmt = $db->prepare(
        "SELECT DISTINCT s.slot_date, p.title AS pw_title, p.teacher_id
         FROM slots s
         JOIN pws p ON s.pw_id = p.id
         WHERE p.level_id = ?
         ORDER BY s.slot_date"
    );
    $stmt->execute([$levelId]);
    $rows = $stmt->fetchAll();

    $busy = [];
    foreach ($rows as $row) {
        $busy[$row['slot_date']] = $row['pw_title'];
    }
    jsonResponse(true, 'OK', ['busy' => $busy]);
}
?>
