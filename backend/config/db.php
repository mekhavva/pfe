<?php
// ============================================================
// ملف الاتصال بقاعدة البيانات
// يجب تعديل هذه القيم حسب إعدادات الخادم الخاص بك
// ============================================================

define('DB_HOST', 'localhost');       // عنوان الخادم (في الغالب localhost)
define('DB_USER', 'root');            // اسم مستخدم MySQL
define('DB_PASS', '');                // كلمة مرور MySQL (فارغة في XAMPP)
define('DB_NAME', 'pw_platform');     // اسم قاعدة البيانات

// ============================================================
// دالة الاتصال بقاعدة البيانات باستخدام PDO
// PDO أكثر أماناً من mysqli وتحمي من SQL Injection
// ============================================================
function getDB() {
    static $pdo = null; // نحفظ الاتصال لتجنب إعادة الفتح في كل مرة
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,      // إظهار الأخطاء
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // النتائج كـ array
                PDO::ATTR_EMULATE_PREPARES => false,              // استعلامات آمنة
            ]);
        } catch (PDOException $e) {
            // في حالة فشل الاتصال نوقف البرنامج ونظهر رسالة خطأ
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database connection failed']);
            exit;
        }
    }
    
    return $pdo;
}
?>
