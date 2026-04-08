<?php
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST') {
    if ($action === 'signup') {
        $firstName  = trim($input['firstName']  ?? '');
        $middleName = trim($input['middleName'] ?? '');
        $lastName   = trim($input['lastName']   ?? '');
        $fullName   = trim("$firstName $middleName $lastName");

        $email    = strtolower(trim($input['email']    ?? ''));
        $password = $input['password'] ?? '';
        $role     = $input['role']     ?? 'student';

        if ($role !== 'admin' && $role !== 'student') $role = 'student';

        if (!$email || strlen($password) < 6 || !$firstName || !$lastName) {
            sendJson(['error' => 'Please fill required fields (password min 6 chars)'], 400);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            sendJson(['error' => 'Email already exists'], 400);
        }

        $id        = bin2hex(random_bytes(16));
        $createdAt = date('c');
        $code      = sprintf("%06d", mt_rand(1, 999999));
        $hashed    = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("INSERT INTO users
            (id, fullName, firstName, middleName, lastName, email, password, phone, address,
             avatarDataUrl, role, verificationCode, isVerified, createdAt)
            VALUES (?, ?, ?, ?, ?, ?, ?, '', '', '', ?, ?, 0, ?)");
        $stmt->execute([$id, $fullName, $firstName, $middleName, $lastName,
                        $email, $hashed, $role, $code, $createdAt]);

        // Return mockCode only (no real email service)
        sendJson(['success' => true, 'message' => 'Verification required', 'mockCode' => $code]);
    }

    if ($action === 'verify') {
        $email = strtolower(trim($input['email'] ?? ''));
        $code  = trim($input['code'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND verificationCode = ? AND isVerified = 0");
        $stmt->execute([$email, $code]);
        $user = $stmt->fetch();

        if (!$user) {
            sendJson(['error' => 'Invalid verification code or already verified'], 400);
        }

        $pdo->prepare("UPDATE users SET isVerified = 1, verificationCode = NULL WHERE id = ?")
            ->execute([$user['id']]);

        $_SESSION['session'] = ['userId' => $user['id'], 'role' => $user['role']];
        sendJson(['success' => true, 'id' => $user['id'], 'role' => $user['role']]);
    }

    if ($action === 'login') {
        $email    = strtolower(trim($input['email']    ?? ''));
        $password = $input['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            sendJson(['error' => 'Invalid login details'], 401);
        }

        if (isset($user['isVerified']) && $user['isVerified'] == 0) {
            sendJson(['error' => 'Account not verified. Please check your email for the code.'], 403);
        }

        $_SESSION['session'] = ['userId' => $user['id'], 'role' => $user['role']];
        sendJson(['success' => true, 'id' => $user['id'], 'role' => $user['role']]);
    }

    if ($action === 'logout') {
        session_destroy();
        sendJson(['success' => true]);
    }

    if ($action === 'update_profile') {
        if (!isset($_SESSION['session']['userId'])) {
            sendJson(['error' => 'Not authenticated'], 401);
        }
        $userId  = $_SESSION['session']['userId'];
        $fields  = [];
        $values  = [];
        $allowed = [
            'fullName', 'firstName', 'middleName', 'lastName',
            'phone', 'address', 'state', 'capital', 'parentPhone', 'nextOfKin',
            'avatarDataUrl', 'age', 'classLevel'
        ];
        foreach ($allowed as $field) {
            if (isset($input[$field])) {
                $fields[] = "`$field` = ?";
                $values[] = $input[$field];
            }
        }
        if (count($fields) > 0) {
            $values[] = $userId;
            $sql      = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($values);
        }
        sendJson(['success' => true]);
    }

    if ($action === 'delete_account') {
        if (!isset($_SESSION['session']['userId'])) {
            sendJson(['error' => 'Not authenticated'], 401);
        }
        $userId   = $_SESSION['session']['userId'];
        $password = $input['password'] ?? '';

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            sendJson(['error' => 'Incorrect password'], 403);
        }

        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        session_destroy();
        sendJson(['success' => true]);
    }
}

if ($method === 'GET') {
    if ($action === 'me') {
        if (!isset($_SESSION['session']['userId'])) {
            sendJson(['user' => null]);
        }
        $stmt = $pdo->prepare("SELECT id, fullName, firstName, middleName, lastName,
            email, phone, address, state, capital, parentPhone, nextOfKin,
            avatarDataUrl, role, age, classLevel, createdAt
            FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['session']['userId']]);
        $user = $stmt->fetch();
        sendJson(['user' => $user ?: null]);
    }

    if ($action === 'session') {
        if (isset($_SESSION['session']['userId'])) {
            sendJson(['session' => $_SESSION['session']]);
        } else {
            sendJson(['session' => null]);
        }
    }
}

sendJson(['error' => 'Invalid action'], 400);
