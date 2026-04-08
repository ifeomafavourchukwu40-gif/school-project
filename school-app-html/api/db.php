<?php
// Buffer ALL output so stray PHP warnings/whitespace can't corrupt JSON responses
ob_start();
// Global CORS setup for all APIs
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

// Global Exception Handler so PHP fatal errors are sent as JSON
set_exception_handler(function($e) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server Error: ' . $e->getMessage()]);
    exit;
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

session_start();

// ── MySQL (XAMPP) Connection ─────────────────────────────────────────────────
$dbHost = 'localhost';
$dbName = 'school_db';
$dbUser = 'root';
$dbPass = '';          // XAMPP default: no password

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
} catch (PDOException $e) {
    // Database doesn't exist yet — create it then reconnect
    $tmp = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass);
    $tmp->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    unset($tmp);
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function sendJson($data, $status = 200) {
    ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getGlobalSettings($pdo) {
    $stmt = $pdo->query("SELECT * FROM school_settings LIMIT 1");
    return $stmt->fetch();
}

// ── Table Definitions ────────────────────────────────────────────────────────

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(64) PRIMARY KEY,
    fullName VARCHAR(255),
    firstName VARCHAR(100),
    middleName VARCHAR(100),
    lastName VARCHAR(100),
    email VARCHAR(255) UNIQUE,
    password VARCHAR(255),
    phone VARCHAR(30),
    address TEXT,
    state VARCHAR(100),
    capital VARCHAR(100),
    parentPhone VARCHAR(30),
    nextOfKin VARCHAR(255),
    avatarDataUrl LONGTEXT,
    role VARCHAR(20) DEFAULT 'student',
    age INT,
    classLevel VARCHAR(20),
    verificationCode VARCHAR(10),
    isVerified TINYINT(1) DEFAULT 0,
    createdAt VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Extended student details (linked to users)
$pdo->exec("CREATE TABLE IF NOT EXISTS students (
    id VARCHAR(64) PRIMARY KEY,
    user_id VARCHAR(64) UNIQUE,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    class VARCHAR(20),
    age INT,
    address TEXT,
    state VARCHAR(100),
    parent_phone VARCHAR(30),
    next_of_kin VARCHAR(255),
    passport LONGTEXT,
    createdAt VARCHAR(50),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Structured fee table — one row per class/term/session with fixed categories
$pdo->exec("CREATE TABLE IF NOT EXISTS fees (
    id VARCHAR(64) PRIMARY KEY,
    class_name VARCHAR(20),
    term VARCHAR(30),
    session VARCHAR(20),
    school_fee DECIMAL(12,2) DEFAULT 0,
    uniform DECIMAL(12,2) DEFAULT 0,
    books DECIMAL(12,2) DEFAULT 0,
    dormitory DECIMAL(12,2) DEFAULT 0,
    toiletries DECIMAL(12,2) DEFAULT 0,
    practical DECIMAL(12,2) DEFAULT 0,
    activities DECIMAL(12,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Payments — tracks each payment attempt (pending = manual transfer, success = approved)
$pdo->exec("CREATE TABLE IF NOT EXISTS payments (
    id VARCHAR(64) PRIMARY KEY,
    student_id VARCHAR(64),
    amount DECIMAL(12,2),
    fee_type VARCHAR(50),
    status VARCHAR(20) DEFAULT 'pending',
    payment_date VARCHAR(50),
    approved_by VARCHAR(64),
    receipt_no VARCHAR(50),
    term VARCHAR(30),
    session VARCHAR(20),
    INDEX idx_student (student_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Invoices (printed receipts)
$pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
    id VARCHAR(64) PRIMARY KEY,
    invoiceNo VARCHAR(50) UNIQUE,
    studentId VARCHAR(64),
    term VARCHAR(30),
    year VARCHAR(20),
    status VARCHAR(20),
    createdAt VARCHAR(50),
    INDEX idx_student_term (studentId, term, year, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS invoice_items (
    id VARCHAR(64) PRIMARY KEY,
    invoiceId VARCHAR(64),
    name VARCHAR(255),
    amount DECIMAL(12,2),
    FOREIGN KEY (invoiceId) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS activities (
    id VARCHAR(64) PRIMARY KEY,
    title VARCHAR(255),
    date VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS school_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255),
    address TEXT,
    current_term VARCHAR(30),
    current_year VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Term completion tracking for class promotion
$pdo->exec("CREATE TABLE IF NOT EXISTS term_completions (
    id VARCHAR(64) PRIMARY KEY,
    student_id VARCHAR(64),
    term VARCHAR(30),
    session VARCHAR(20),
    completed_at VARCHAR(50),
    UNIQUE KEY uniq_completion (student_id, term, session)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Add missing columns to existing tables (safe ALTER, ignored if exists) ──
$alterStatements = [
    "ALTER TABLE users ADD COLUMN state VARCHAR(100) AFTER address",
    "ALTER TABLE users ADD COLUMN capital VARCHAR(100) AFTER state",
    "ALTER TABLE users ADD COLUMN parentPhone VARCHAR(30) AFTER capital",
    "ALTER TABLE users ADD COLUMN nextOfKin VARCHAR(255) AFTER parentPhone",
    "ALTER TABLE school_settings ADD COLUMN paystack_public_key VARCHAR(255) AFTER current_year",
    "ALTER TABLE school_settings ADD COLUMN paystack_secret_key VARCHAR(255) AFTER paystack_public_key",
    "ALTER TABLE payments ADD COLUMN reference VARCHAR(100) UNIQUE AFTER session"
];
foreach ($alterStatements as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) { /* column already exists */ }
}

// ── Initialize school settings if empty ─────────────────────────────────────
$stmt = $pdo->query("SELECT COUNT(*) FROM school_settings");
if ($stmt->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO school_settings (name, address, current_term, current_year)
                VALUES ('My School Name', 'School Address Here', 'First Term', '2025/2026')");
}
