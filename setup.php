<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PW Platform — Setup</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; max-width: 640px; margin: 50px auto; background: #f0f7fc; padding: 0 16px; }
        .box { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,0.1); }
        h2   { color: #4a9fd4; margin-bottom: 4px; }
        .sub { color: #999; font-size: 0.85rem; margin-bottom: 20px; }
        .sec { background: #f7fbff; border-radius: 8px; padding: 12px 16px; margin-bottom: 12px; border-left: 3px solid #4a9fd4; }
        .step{ font-weight: 700; color: #3d6f90; margin: 0 0 6px; font-size: 0.9rem; }
        p    { margin: 3px 0; font-size: 0.86rem; }
        .ok  { color: #2d7a57; font-weight: 600; }
        .err { color: #c0392b; font-weight: 600; }
        pre  { background: #f0f6fb; padding: 12px; border-radius: 8px; font-size: 0.82rem; line-height: 1.7; margin-top: 10px; }
        .btn { display:inline-block; margin-top:16px; padding:11px 30px; background:#4a9fd4; color:white; border-radius:8px; text-decoration:none; font-weight:700; }
        hr   { border:none; border-top:1px solid #e8f0f8; margin:16px 0; }
    </style>
</head>
<body><div class="box">
<h2>PW Platform — Setup & Fix</h2>
<p class="sub">إصلاح قاعدة البيانات وإضافة الجداول الجديدة</p>

<?php
$host='localhost'; $dbu='root'; $dbp=''; $dbn='pw_platform';

echo "<div class='sec'><p class='step'>الاتصال بـ MySQL</p>";
try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4",$dbu,$dbp,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    echo "<p class='ok'>✓ Connected</p>";
} catch(PDOException $e){ echo "<p class='err'>✗ ".$e->getMessage()."</p></div></div></body></html>"; exit; }
echo "</div>";

echo "<div class='sec'><p class='step'>قاعدة البيانات</p>";
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbn` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$dbn`");
echo "<p class='ok'>✓ Database ready</p></div>";

echo "<div class='sec'><p class='step'>الجداول</p>";

// levels
$pdo->exec("CREATE TABLE IF NOT EXISTS levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
echo "<p class='ok'>✓ levels</p>";

// users — مع دعم pending
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE,
    role ENUM('pending','student','teacher','admin') NOT NULL DEFAULT 'pending',
    level_id INT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE SET NULL
)");
// إصلاح ENUM القديم
try { $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('pending','student','teacher','admin') NOT NULL DEFAULT 'pending'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE users DROP COLUMN is_approved"); } catch(Exception $e){}
// إضافة group_num إذا لم يكن موجوداً (رقم القروب لكل طالب)
try { $pdo->exec("ALTER TABLE users ADD COLUMN group_num INT NULL DEFAULT NULL"); echo "<p class='ok'>✓ group_num added</p>"; } catch(Exception $e){ echo "<p class='ok'>✓ group_num already exists</p>"; }
echo "<p class='ok'>✓ users (pending supported)</p>";

// ============================================================
// جدول الصالات — جديد
// Admin يضيف الصالات ويحدد سعتها
// ============================================================
$pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    capacity INT NOT NULL DEFAULT 30,
    description VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
echo "<p class='ok'>✓ rooms (new)</p>";

// pws — مع room_id
$pdo->exec("CREATE TABLE IF NOT EXISTS pws (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    teacher_id INT NOT NULL,
    level_id INT NOT NULL,
    room_id INT NULL,
    is_active TINYINT(1) DEFAULT 1,
    start_date DATE,
    end_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
)");
try { $pdo->exec("ALTER TABLE pws ADD COLUMN room_id INT NULL, ADD FOREIGN KEY fk_pw_room (room_id) REFERENCES rooms(id) ON DELETE SET NULL"); } catch(Exception $e){}
echo "<p class='ok'>✓ pws (room_id added)</p>";

// slots
$pdo->exec("CREATE TABLE IF NOT EXISTS slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pw_id INT NOT NULL,
    slot_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_reserved TINYINT(1) DEFAULT 0,
    FOREIGN KEY (pw_id) REFERENCES pws(id) ON DELETE CASCADE
)");
echo "<p class='ok'>✓ slots</p>";

// reservations
$pdo->exec("CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    slot_id INT NOT NULL,
    pw_id INT NOT NULL,
    status ENUM('active','cancelled','completed','missed') DEFAULT 'active',
    entered_at TIMESTAMP NULL DEFAULT NULL,
    reserved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE,
    FOREIGN KEY (pw_id) REFERENCES pws(id) ON DELETE CASCADE,
    UNIQUE KEY one_per_pw (student_id, pw_id)
)");
try { $pdo->exec("ALTER TABLE reservations MODIFY COLUMN status ENUM('active','cancelled','completed','missed') DEFAULT 'active'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE reservations ADD COLUMN entered_at TIMESTAMP NULL DEFAULT NULL"); } catch(Exception $e){}
echo "<p class='ok'>✓ reservations (updated status enum and entered_at)</p>";
echo "</div>";

echo "<div class='sec'><p class='step'>البيانات الأولية</p>";
foreach([['L1','Licence 1'],['L2','Licence 2'],['L3','Licence 3'],['Ing1','Ingénieur 1'],['Ing2','Ingénieur 2'],['M1','Master 1'],['M2','Master 2']] as [$n,$d]){
    $pdo->prepare("INSERT IGNORE INTO levels (name,description) VALUES (?,?)")->execute([$n,$d]);
}
// صالات افتراضية
foreach([['Salle A',30,'Laboratoire informatique A'],['Salle B',25,'Laboratoire informatique B'],['Salle C',20,'Laboratoire informatique C']] as [$n,$c,$d]){
    $pdo->prepare("INSERT IGNORE INTO rooms (name,capacity,description) VALUES (?,?,?)")->execute([$n,$c,$d]);
}
echo "<p class='ok'>✓ ".($pdo->query("SELECT COUNT(*) FROM levels")->fetchColumn())." levels</p>";
echo "<p class='ok'>✓ ".($pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn())." rooms</p>";
echo "</div>";

echo "<div class='sec'><p class='step'>حساب Admin</p>";
$pdo->exec("DELETE FROM users WHERE username='admin'");
$h=password_hash('admin123',PASSWORD_BCRYPT);
$pdo->prepare("INSERT INTO users (username,password,full_name,email,role,is_active) VALUES ('admin',?,'System Administrator','admin@pw-platform.com','admin',1)")->execute([$h]);
$a=$pdo->query("SELECT password FROM users WHERE username='admin'")->fetch();
echo (password_verify('admin123',$a['password'])) ? "<p class='ok'>✓ admin / admin123 verified</p>" : "<p class='err'>✗ Password error</p>";

// اختبار pending
$pdo->exec("DELETE FROM users WHERE username='__test__'");
$pdo->prepare("INSERT INTO users (username,password,full_name,role) VALUES ('__test__','x','T','pending')")->execute();
$f=$pdo->query("SELECT role FROM users WHERE username='__test__'")->fetch();
echo ($f && $f['role']==='pending') ? "<p class='ok'>✓ pending role works</p>" : "<p class='err'>✗ pending broken</p>";
$pdo->exec("DELETE FROM users WHERE username='__test__'");
echo "</div>";

echo "<hr><h3 style='color:#2d7a57'>✓ Setup Complete!</h3><pre>URL: localhost/pw-platform\nUser: admin | Pass: admin123</pre>";
echo "<a class='btn' href='index.html'>Go to Login →</a>";
?>
</div></body></html>
