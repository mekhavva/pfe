-- ============================================================
-- قاعدة البيانات الخاصة بمنصة حصص PW
-- اسم قاعدة البيانات: pw_platform
-- ============================================================

CREATE DATABASE IF NOT EXISTS pw_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pw_platform;

-- ============================================================
-- جدول المستويات الدراسية (مثل L1, L2, L3, Ing1, M1...)
-- يقوم Admin بإنشائها كما يشاء
-- ============================================================
CREATE TABLE levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,         -- اسم المستوى مثل L1 أو Ing1
    description VARCHAR(255),                 -- وصف اختياري للمستوى
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- جدول المستخدمين (admin, teacher, student)
-- كل أدوار النظام في جدول واحد مع حقل role
-- ============================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,     -- اسم المستخدم للدخول
    password VARCHAR(255) NOT NULL,           -- كلمة المرور مشفرة بـ bcrypt
    full_name VARCHAR(100) NOT NULL,          -- الاسم الكامل
    email VARCHAR(100) UNIQUE,               -- البريد الإلكتروني
    role ENUM('admin', 'teacher', 'student') NOT NULL,  -- الدور في النظام
    level_id INT NULL,                        -- المستوى الدراسي (للطلاب فقط)
    is_active TINYINT(1) DEFAULT 1,          -- هل الحساب مفعل؟ 1=نعم 0=لا
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE SET NULL
);

-- ============================================================
-- جدول حصص PW (التجارب العملية)
-- الأستاذ هو من يقوم بإنشائها
-- ============================================================
CREATE TABLE pws (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,              -- عنوان الحصة مثل "PW 1 - Arrays"
    description TEXT,                         -- وصف الحصة
    teacher_id INT NOT NULL,                  -- الأستاذ المسؤول
    level_id INT NOT NULL,                    -- المستوى الدراسي المستهدف
    is_active TINYINT(1) DEFAULT 1,          -- هل الحصة مفتوحة؟
    start_date DATE,                          -- تاريخ بداية الفترة
    end_date DATE,                            -- تاريخ نهاية الفترة
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE CASCADE
);

-- ============================================================
-- جدول الفترات الزمنية (slots) لكل PW
-- كل slot مدته ساعتان (2h) ولا يمكن إلا لطالب واحد في كل مرة
-- ============================================================
CREATE TABLE slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pw_id INT NOT NULL,                       -- الحصة التي تنتمي إليها هذه الفترة
    slot_date DATE NOT NULL,                  -- تاريخ الفترة
    start_time TIME NOT NULL,                 -- وقت البداية
    end_time TIME NOT NULL,                   -- وقت النهاية (بعد ساعتين)
    is_reserved TINYINT(1) DEFAULT 0,        -- هل محجوزة؟ 0=لا 1=نعم
    FOREIGN KEY (pw_id) REFERENCES pws(id) ON DELETE CASCADE
);

-- ============================================================
-- جدول الحجوزات (reservations)
-- كل طالب يحجز فترة واحدة فقط لكل PW
-- ============================================================
CREATE TABLE reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,                  -- الطالب الذي قام بالحجز
    slot_id INT NOT NULL,                     -- الفترة المحجوزة
    pw_id INT NOT NULL,                       -- الحصة المعنية
    status ENUM('active', 'cancelled', 'completed', 'missed') DEFAULT 'active', -- حالة الحجز
    entered_at TIMESTAMP NULL DEFAULT NULL,   -- تاريخ ووقت الدخول الفعلي للجلسة
    reserved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE,
    FOREIGN KEY (pw_id) REFERENCES pws(id) ON DELETE CASCADE,
    -- لا يمكن لطالب أن يحجز أكثر من مرة لنفس الحصة
    UNIQUE KEY unique_student_pw (student_id, pw_id)
);

-- ============================================================
-- إدراج بيانات أولية للتجربة
-- كلمة مرور admin هي: admin123 (مشفرة بـ password_hash)
-- ============================================================
INSERT INTO levels (name, description) VALUES 
('L1', 'Licence 1ère année'),
('L2', 'Licence 2ème année'),
('L3', 'Licence 3ème année'),
('Ing1', 'Ingénieur 1ère année'),
('M1', 'Master 1ère année');

-- إدراج admin افتراضي (username: admin | password: admin123)
-- الكلمة مشفرة بـ password_hash في PHP
INSERT INTO users (username, password, full_name, email, role) VALUES 
('admin', '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77bqvq', 'System Administrator', 'admin@pw-platform.com', 'admin');
