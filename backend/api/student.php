<?php
ini_set('display_errors','0');
error_reporting(0);
require_once '../config/db.php';
require_once '../includes/helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

requireRole('student');

$action    = $_GET['action'] ?? '';
$data      = json_decode(file_get_contents('php://input'), true) ?? [];
$studentId = $_SESSION['user_id'];
$levelId   = $_SESSION['level_id'] ?? null;

// fallback: if level_id missing in session, fetch from DB
if (empty($levelId) && !empty($studentId)) {
    $tmpDb = getDB();
    $tmpStmt = $tmpDb->prepare("SELECT level_id FROM users WHERE id=?");
    $tmpStmt->execute([$studentId]);
    $tmpRow = $tmpStmt->fetch();
    if ($tmpRow) { $levelId = $tmpRow['level_id']; $_SESSION['level_id'] = $levelId; }
}

switch ($action) {
    case 'get_pws':         getAvailablePWs($levelId);             break;
    case 'get_slots':       getAvailableSlots($data);              break;
    case 'reserve':         reserveSlot($data, $studentId);        break;
    case 'cancel':          cancelReservation($data, $studentId);  break;
    case 'my_reservations': getMyReservations($studentId);         break;
    case 'check_access':    checkPWAccess($data, $studentId);      break;
    case 'enter_session':   enterSession($data, $studentId);       break;
    case 'profile':         getProfile($studentId);                break;
    default: jsonResponse(false, 'Invalid action');
}

// ============================================================
// الحصص المتاحة للمستوى الدراسي للطالب
// ============================================================
function getAvailablePWs($levelId) {
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT p.id, p.title, p.description, p.start_date, p.end_date,
                COALESCE(r.capacity, 30) AS max_students,
                u.full_name AS teacher_name,
                r.name AS room_name, r.id AS room_id,
                COUNT(DISTINCT s.id) AS total_slots,
                COUNT(DISTINCT CASE WHEN s.is_reserved=0 THEN s.id END) AS free_slots,
                COUNT(DISTINCT res.id) AS reserved_count
         FROM pws p
         JOIN users u ON p.teacher_id=u.id
         LEFT JOIN rooms r ON p.room_id=r.id
         LEFT JOIN slots s ON s.pw_id=p.id
         LEFT JOIN reservations res ON res.pw_id=p.id AND res.status='active'
         WHERE p.level_id=? AND p.is_active=1
         GROUP BY p.id, r.capacity
         ORDER BY p.created_at DESC"
    );
    $stmt->execute([$levelId]);
    jsonResponse(true,'OK',['pws'=>$stmt->fetchAll()]);
}

// ============================================================
// الفترات الزمنية المتاحة (غير محجوزة) لحصة معينة
// ============================================================
function getAvailableSlots($data) {
    $pwId = intval($_GET['pw_id'] ?? 0);
    if (!$pwId) jsonResponse(false,'PW ID required');
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT s.id, s.slot_date, s.start_time, s.end_time, s.is_reserved,
                r.name AS room_name
         FROM slots s
         JOIN pws p ON s.pw_id = p.id
         LEFT JOIN rooms r ON p.room_id = r.id
         WHERE s.pw_id=?
         ORDER BY s.slot_date, s.start_time"
    );
    $stmt->execute([$pwId]);
    jsonResponse(true,'OK',['slots'=>$stmt->fetchAll()]);
}

// ============================================================
// حجز فترة زمنية
//
// قواعد الحجز:
//  1. الطالب لا يحجز نفس الـ PW أكثر من مرة
//  2. الطالب لا يحجز أي PW آخر في نفس التاريخ والوقت
//  3. السعة القصوى للصالة/الحصة
// ============================================================
function reserveSlot($data, $studentId) {
    $slotId = intval($data['slot_id'] ?? 0);
    $pwId   = intval($data['pw_id']   ?? 0);
    if (!$slotId || !$pwId) jsonResponse(false,'Slot and PW ID required');

    $db = getDB();

    // ── قاعدة 1: هل حجز هذا الطالب هذه الحصة من قبل؟ ──
    $q1 = $db->prepare(
        "SELECT id FROM reservations WHERE student_id=? AND pw_id=? AND status='active'"
    );
    $q1->execute([$studentId, $pwId]);
    if ($q1->fetch()) {
        jsonResponse(false,'You already have a reservation for this PW session');
    }

    // ── جلب تفاصيل الـ slot المطلوب ──
    $slotInfo = $db->prepare(
        "SELECT s.slot_date, s.start_time, s.end_time, s.is_reserved
         FROM slots s WHERE s.id=?"
    );
    $slotInfo->execute([$slotId]);
    $slot = $slotInfo->fetch();
    if (!$slot) jsonResponse(false,'Slot not found');
    if ($slot['is_reserved']) jsonResponse(false,'This slot is no longer available');

    // ── قاعدة 2: هل الطالب حجز بالفعل في نفس التاريخ والوقت لحصة أخرى؟ ──
    // نتحقق من أي حجز نشط لنفس الطالب يتداخل مع هذا الوقت
    $q2 = $db->prepare(
        "SELECT r.id, p.title
         FROM reservations r
         JOIN slots s2  ON r.slot_id = s2.id
         JOIN pws p     ON r.pw_id   = p.id
         WHERE r.student_id = ?
           AND r.status     = 'active'
           AND s2.slot_date = ?
           AND s2.start_time < ?
           AND s2.end_time   > ?
           AND r.pw_id != ?"
    );
    $q2->execute([
        $studentId,
        $slot['slot_date'],
        $slot['end_time'],
        $slot['start_time'],
        $pwId
    ]);
    $conflict = $q2->fetch();
    if ($conflict) {
        jsonResponse(false,
            'You already have a reservation for "' . $conflict['title'] .
            '" at this same time (' . substr($slot['start_time'],0,5) .
            '). You cannot book two PW sessions at the same time.'
        );
    }

    // ── قاعدة جديدة: هل هناك أي حجز لنفس الوقت في المنصة؟ (مبدأ الطابعة) ──
    // لا يمكن لطالبين الدخول في نفس الوقت
    $qConflict = $db->prepare(
        "SELECT r.id, u.full_name, p.title
         FROM reservations r
         JOIN slots s2  ON r.slot_id = s2.id
         JOIN pws p     ON r.pw_id   = p.id
         JOIN users u   ON r.student_id = u.id
         WHERE r.status     = 'active'
           AND s2.slot_date = ?
           AND s2.start_time < ?
           AND s2.end_time   > ?"
    );
    $qConflict->execute([
        $slot['slot_date'],
        $slot['end_time'],
        $slot['start_time']
    ]);
    $globalConflict = $qConflict->fetch();
    if ($globalConflict) {
        jsonResponse(false,
            'This time slot is already booked by another student (' . $globalConflict['full_name'] . 
            ') for "' . $globalConflict['title'] . '". Only one student can book at any given time.'
        );
    }

    // ── قاعدة 3: السعة القصوى ──
    $maxQ = $db->prepare(
        "SELECT COALESCE(r.capacity, 30) AS max_students, COUNT(res.id) AS cnt
         FROM pws p
         LEFT JOIN rooms r ON p.room_id=r.id
         LEFT JOIN reservations res ON res.pw_id=p.id AND res.status='active'
         WHERE p.id=? GROUP BY p.id, r.capacity"
    );
    $maxQ->execute([$pwId]);
    $pwInfo = $maxQ->fetch();
    if ($pwInfo && $pwInfo['max_students'] && $pwInfo['cnt'] >= $pwInfo['max_students']) {
        jsonResponse(false,'This PW session is full');
    }

    // ── تسجيل الحجز ──
    $db->beginTransaction();
    try {
        $db->prepare(
            "INSERT INTO reservations (student_id, slot_id, pw_id) VALUES (?,?,?)"
        )->execute([$studentId, $slotId, $pwId]);

        $db->prepare("UPDATE slots SET is_reserved=1 WHERE id=?")->execute([$slotId]);

        $db->commit();
        jsonResponse(true,'Reservation confirmed successfully');
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(false,'Reservation failed, please try again');
    }
}

// ============================================================
// إلغاء حجز
// ============================================================
function cancelReservation($data, $studentId) {
    $reservationId = intval($data['reservation_id'] ?? 0);
    $db = getDB();

    $res = $db->prepare(
        "SELECT r.id, r.slot_id, s.slot_date, s.start_time
         FROM reservations r JOIN slots s ON r.slot_id=s.id
         WHERE r.id=? AND r.student_id=? AND r.status='active'"
    );
    $res->execute([$reservationId, $studentId]);
    $reservation = $res->fetch();
    if (!$reservation) jsonResponse(false,'Reservation not found');

    $slotDateTime = $reservation['slot_date'].' '.$reservation['start_time'];
    if (strtotime($slotDateTime) <= time()) {
        jsonResponse(false,'Cannot cancel a slot that has already started');
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE reservations SET status='cancelled' WHERE id=?")->execute([$reservationId]);
        $db->prepare("UPDATE slots SET is_reserved=0 WHERE id=?")->execute([$reservation['slot_id']]);
        $db->commit();
        jsonResponse(true,'Reservation cancelled');
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(false,'Cancellation failed');
    }
}

// ============================================================
// حجوزات الطالب
// ============================================================
function getMyReservations($studentId) {
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT r.id AS reservation_id, r.status, r.reserved_at,
                p.title AS pw_title,
                s.slot_date, s.start_time, s.end_time,
                rm.name AS room_name
         FROM reservations r
         JOIN pws p   ON r.pw_id=p.id
         JOIN slots s ON r.slot_id=s.id
         LEFT JOIN rooms rm ON p.room_id=rm.id
         WHERE r.student_id=?
         ORDER BY s.slot_date, s.start_time"
    );
    $stmt->execute([$studentId]);
    jsonResponse(true,'OK',['reservations'=>$stmt->fetchAll()]);
}

// ============================================================
// التحقق من الوصول للحصة (هل الوقت الحالي ضمن الفترة المحجوزة)
// ============================================================
function checkPWAccess($data, $studentId) {
    $pwId = intval($_GET['pw_id'] ?? 0);
    if (!$pwId) jsonResponse(false,'PW ID required');
    $db = getDB();
    $now = date('Y-m-d H:i:s');

    $stmt = $db->prepare(
        "SELECT r.id AS reservation_id, s.slot_date, s.start_time, s.end_time
         FROM reservations r JOIN slots s ON r.slot_id=s.id
         WHERE r.student_id=? AND r.pw_id=? AND r.status='active'"
    );
    $stmt->execute([$studentId, $pwId]);
    $slot = $stmt->fetch();
    if (!$slot) jsonResponse(false,'No active reservation found',['access'=>false]);

    $start = $slot['slot_date'].' '.$slot['start_time'];
    $end   = $slot['slot_date'].' '.$slot['end_time'];

    if ($now >= $start && $now <= $end) {
        jsonResponse(true,'Access granted',[
            'access'=>true,
            'end_time'=>$end,
            'reservation_id'=>$slot['reservation_id']
        ]);
    } else {
        jsonResponse(false,'Outside your time slot',[
            'access'=>false,
            'next_slot_start'=>$start,
            'next_slot_end'=>$end
        ]);
    }
}

// ============================================================
// تسجيل دخول الطالب الفعلي للحصة عند بدء وقتها
// ============================================================
function enterSession($data, $studentId) {
    $resId = intval($data['reservation_id'] ?? 0);
    if (!$resId) jsonResponse(false, 'Reservation ID required');
    
    $db = getDB();
    
    // التأكد من أن الحجز ينتمي لهذا الطالب وحالته نشطة
    $stmt = $db->prepare("SELECT id FROM reservations WHERE id=? AND student_id=? AND status='active'");
    $stmt->execute([$resId, $studentId]);
    if (!$stmt->fetch()) {
        jsonResponse(false, 'Reservation not found or not active');
    }
    
    // تسجيل تاريخ ووقت الدخول الفعلي وتحديث الحالة إلى completed (لأنه دخل بالفعل واجتاز الحصة)
    $stmt = $db->prepare("UPDATE reservations SET entered_at=NOW(), status='completed' WHERE id=?");
    $stmt->execute([$resId]);
    
    jsonResponse(true, 'Entered session successfully');
}

// ============================================================
// جلب المعلومات الشخصية للطالب (المستوى، القروب، الخ)
// ============================================================
function getProfile($studentId) {
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT u.full_name, u.username, u.email, u.group_num, l.name AS level_name
         FROM users u
         LEFT JOIN levels l ON u.level_id = l.id
         WHERE u.id = ?"
    );
    $stmt->execute([$studentId]);
    $profile = $stmt->fetch();
    if (!$profile) jsonResponse(false, 'Profile not found');
    jsonResponse(true, 'OK', ['profile' => $profile]);
}
?>
