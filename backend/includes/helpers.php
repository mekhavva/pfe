<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PW_SESSION');
    ini_set('session.cookie_path', '/');
    ini_set('session.gc_maxlifetime', 86400);
    ini_set('session.cookie_lifetime', 86400);
    session_start();
}

// restore session from cookie if empty
if (empty($_SESSION['user_id']) && !empty($_COOKIE['pw_user'])) {
    $d = json_decode(base64_decode($_COOKIE['pw_user']), true);
    if ($d && isset($d['exp']) && $d['exp'] > time()) {
        $_SESSION['user_id']   = $d['uid'];
        $_SESSION['username']  = $d['un'];
        $_SESSION['full_name'] = $d['name'];
        $_SESSION['role']      = $d['role'];
        $_SESSION['level_id']  = $d['lid'];
    }
}

// Note: session_write_close() was removed — closing the session here
// prevented requireRole() from reading $_SESSION['role'], causing 403 on all API calls.

function isLoggedIn() { return !empty($_SESSION['user_id']); }
function getUserRole() { return $_SESSION['role'] ?? null; }

function requireLogin() {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }
}

function requireRole($role) {
    requireLogin();
    if (getUserRole() !== $role) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
}

function jsonResponse($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function cleanupLateReservations() {
    try {
        $db  = getDB();
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare(
            "SELECT r.id, r.slot_id FROM reservations r
             JOIN slots s ON r.slot_id = s.id
             WHERE r.status='active' AND r.entered_at IS NULL
               AND CONCAT(s.slot_date,' ',s.start_time) < DATE_SUB(?, INTERVAL 15 MINUTE)"
        );
        $stmt->execute([$now]);
        $late = $stmt->fetchAll();
        if (!empty($late)) {
            $db->beginTransaction();
            $ur = $db->prepare("UPDATE reservations SET status='missed' WHERE id=?");
            $us = $db->prepare("UPDATE slots SET is_reserved=0 WHERE id=?");
            foreach ($late as $r) { $ur->execute([$r['id']]); $us->execute([$r['slot_id']]); }
            $db->commit();
        }
    } catch (Exception $e) {}
}

cleanupLateReservations();
?>
