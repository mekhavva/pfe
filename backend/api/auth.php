<?php
ini_set('display_errors','0');
error_reporting(0);
require_once '../config/db.php';
require_once '../includes/helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action = $_GET['action'] ?? '';
switch ($action) {
    case 'login':      handleLogin();    break;
    case 'register':   handleRegister(); break;
    case 'logout':     handleLogout();   break;
    case 'check':      checkSession();   break;
    case 'get_levels': getLevelsAuth();  break;
    default: jsonResponse(false, 'Invalid action');
}

function handleLogin() {
    $data     = json_decode(file_get_contents('php://input'), true);
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    if (!$username || !$password)
        jsonResponse(false, 'Username and password are required');

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password']))
        jsonResponse(false, 'Incorrect username or password');

    if (!$user['is_active'])
        jsonResponse(false, 'Your account has been disabled');

    if ($user['role'] === 'pending')
        jsonResponse(false, 'Your account is awaiting admin approval.');

    // set session
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['level_id']  = $user['level_id'];

    // set cookie manually (works across all paths)
    $cookieVal = base64_encode(json_encode([
        'uid'  => $user['id'],
        'role' => $user['role'],
        'name' => $user['full_name'],
        'lid'  => $user['level_id'],
        'un'   => $user['username'],
        'exp'  => time() + 86400
    ]));
    setcookie('pw_user', $cookieVal, time()+86400, '/', '', false, false);

    jsonResponse(true, 'Login successful', [
        'role' => $user['role'],
        'name' => $user['full_name'],
    ]);
}

function checkSession() {
    // try session first
    if (!empty($_SESSION['user_id'])) {
        jsonResponse(true, 'OK', [
            'role'    => $_SESSION['role'],
            'name'    => $_SESSION['full_name'],
            'user_id' => $_SESSION['user_id']
        ]);
    }

    // try cookie
    if (!empty($_COOKIE['pw_user'])) {
        $data = json_decode(base64_decode($_COOKIE['pw_user']), true);
        if ($data && isset($data['exp']) && $data['exp'] > time()) {
            // restore session from cookie
            $_SESSION['user_id']   = $data['uid'];
            $_SESSION['username']  = $data['un'];
            $_SESSION['full_name'] = $data['name'];
            $_SESSION['role']      = $data['role'];
            $_SESSION['level_id']  = $data['lid'];
            jsonResponse(true, 'OK', [
                'role'    => $data['role'],
                'name'    => $data['name'],
                'user_id' => $data['uid']
            ]);
        }
    }

    jsonResponse(false, 'Not logged in');
}

function handleLogout() {
    setcookie('pw_user', '', time()-3600, '/');
    session_unset();
    session_destroy();
    jsonResponse(true, 'Logged out');
}

function handleRegister() {
    try {
        $data     = json_decode(file_get_contents('php://input'), true);
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $fullName = trim($data['full_name'] ?? '');
        $email    = trim($data['email'] ?? '');

        if (!$fullName || !$username || !$password)
            jsonResponse(false, 'Name, username and password are required');
        if (strlen($password) < 6)
            jsonResponse(false, 'Password must be at least 6 characters');

        $db    = getDB();
        $check = $db->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) jsonResponse(false, 'Username already taken');

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare(
            "INSERT INTO users (username,password,full_name,email,role,is_active)
             VALUES (?,?,?,?,'pending',1)"
        )->execute([$username, $hash, $fullName, $email]);

        jsonResponse(true, 'Account request submitted!');
    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage());
    }
}

function getLevelsAuth() {
    $db   = getDB();
    $stmt = $db->query("SELECT id,name FROM levels ORDER BY name");
    jsonResponse(true, 'OK', ['levels' => $stmt->fetchAll()]);
}
?>
