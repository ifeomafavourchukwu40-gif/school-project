<?php
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if (!isset($_SESSION['session']['userId'])) {
    sendJson(['error' => 'Not authenticated'], 401);
}
if ($_SESSION['session']['role'] !== 'admin') {
    sendJson(['error' => 'Forbidden: Admins only'], 403);
}

$FIELDS = "id, fullName, firstName, middleName, lastName, email, phone, role, classLevel, isVerified, avatarDataUrl, createdAt";

if ($method === 'GET') {

    if ($action === 'list') {
        $q = trim($_GET['q'] ?? '');
        if ($q) {
            // Search by name, phone number, or ID
            $like = '%' . $q . '%';
            $stmt = $pdo->prepare(
                "SELECT $FIELDS FROM users
                 WHERE fullName LIKE ? OR phone LIKE ? OR id LIKE ?
                 ORDER BY fullName ASC"
            );
            $stmt->execute([$like, $like, $like]);
        } else {
            $stmt = $pdo->query(
                "SELECT $FIELDS FROM users ORDER BY fullName ASC"
            );
        }
        sendJson($stmt->fetchAll());
    }

    if ($action === 'get') {
        $id   = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT $FIELDS FROM users WHERE id = ?");
        $stmt->execute([$id]);
        sendJson($stmt->fetch() ?: null);
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if ($action === 'add') {
        $id         = bin2hex(random_bytes(16));
        $firstName  = trim($input['firstName']  ?? '');
        $middleName = trim($input['middleName'] ?? '');
        $lastName   = trim($input['lastName']   ?? '');
        $fullName   = trim("$firstName $middleName $lastName");
        $email      = $input['email']      ?? '';
        $phone      = $input['phone']      ?? '';
        $role       = $input['role']       ?? 'student';
        $classLevel = $input['classLevel'] ?? '';
        $rawPass    = $input['password']   ?? 'password123';
        $hashed     = password_hash($rawPass, PASSWORD_DEFAULT);
        $createdAt  = date('c');

        $stmt = $pdo->prepare(
            "INSERT INTO users (id, fullName, firstName, middleName, lastName, email, phone,
             role, classLevel, password, isVerified, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
        );
        $stmt->execute([$id, $fullName, $firstName, $middleName, $lastName,
                        $email, $phone, $role, $classLevel, $hashed, $createdAt]);
        sendJson(['success' => true, 'id' => $id]);
    }

    if ($action === 'edit') {
        $id         = $input['id']         ?? '';
        $firstName  = trim($input['firstName']  ?? '');
        $middleName = trim($input['middleName'] ?? '');
        $lastName   = trim($input['lastName']   ?? '');
        $fullName   = trim("$firstName $middleName $lastName");
        $email      = $input['email']      ?? '';
        $phone      = $input['phone']      ?? '';
        $role       = $input['role']       ?? 'student';
        $classLevel = $input['classLevel'] ?? '';

        $pdo->prepare(
            "UPDATE users SET fullName=?, firstName=?, middleName=?, lastName=?,
             email=?, phone=?, role=?, classLevel=? WHERE id=?"
        )->execute([$fullName, $firstName, $middleName, $lastName,
                    $email, $phone, $role, $classLevel, $id]);

        if (!empty($input['password'])) {
            $hashed = password_hash($input['password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hashed, $id]);
        }

        // Update passport (avatar) if provided
        if (!empty($input['avatarDataUrl'])) {
            $pdo->prepare("UPDATE users SET avatarDataUrl=? WHERE id=?")
                ->execute([$input['avatarDataUrl'], $id]);
        }

        sendJson(['success' => true]);
    }

    if ($action === 'delete') {
        $id = $input['id'] ?? '';
        if ($id === $_SESSION['session']['userId']) {
            sendJson(['error' => 'Cannot delete your own admin account'], 400);
        }
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        sendJson(['success' => true]);
    }

    // ── Mark a term as complete for all students ─────────────────────────────
    if ($action === 'mark_term_complete') {
        $term    = $input['term']    ?? '';
        $session = $input['session'] ?? '';
        if (!$term || !$session) sendJson(['error' => 'term and session required'], 400);

        // Get all students
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'student'");
        $students = $stmt->fetchAll();

        foreach ($students as $s) {
            $id = bin2hex(random_bytes(16));
            // INSERT IGNORE equivalent: catch duplicate key
            try {
                $pdo->prepare(
                    "INSERT INTO term_completions (id, student_id, term, session, completed_at)
                     VALUES (?, ?, ?, ?, ?)"
                )->execute([$id, $s['id'], $term, $session, date('c')]);
            } catch (Exception $e) { /* already recorded */ }
        }
        sendJson(['success' => true]);
    }

    // ── Get students eligible for promotion (3+ terms completed in current class) ──
    if ($action === 'eligible_promotion') {
        // Count term completions per student
        $stmt = $pdo->query(
            "SELECT u.id, u.fullName, u.classLevel,
                    COUNT(tc.id) AS termsCompleted
             FROM users u
             LEFT JOIN term_completions tc ON tc.student_id = u.id
             WHERE u.role = 'student'
             GROUP BY u.id
             HAVING termsCompleted >= 3"
        );
        sendJson($stmt->fetchAll());
    }

    // ── Promote a student to next class ─────────────────────────────────────
    if ($action === 'promote') {
        $id         = $input['id']         ?? '';
        $classLevel = $input['classLevel'] ?? '';
        if (!$id || !$classLevel) sendJson(['error' => 'id and classLevel required'], 400);

        $pdo->prepare("UPDATE users SET classLevel = ? WHERE id = ?")->execute([$classLevel, $id]);

        // Clear their term_completions so the cycle resets
        $pdo->prepare("DELETE FROM term_completions WHERE student_id = ?")->execute([$id]);

        sendJson(['success' => true]);
    }
}

sendJson(['error' => 'Invalid action'], 400);
