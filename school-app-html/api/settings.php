<?php
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Require auth
if (!isset($_SESSION['session']['userId'])) {
    sendJson(['error' => 'Not authenticated'], 401);
}

if ($method === 'GET') {
    if ($action === 'school') {
        $stmt = $pdo->query("SELECT * FROM school_settings LIMIT 1");
        sendJson($stmt->fetch());
    }
    
    if ($action === 'activities') {
        $stmt = $pdo->query("SELECT * FROM activities ORDER BY date DESC");
        sendJson($stmt->fetchAll());
    }
}

if ($method === 'POST') {
    if ($_SESSION['session']['role'] !== 'admin') {
        sendJson(['error' => 'Forbidden: Admins only'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($action === 'save_school') {
        $name = $input['name'] ?? '';
        $address = $input['address'] ?? '';
        $stmt = $pdo->prepare("UPDATE school_settings SET name = ?, address = ?");
        $stmt->execute([$name, $address]);
        sendJson(['success' => true]);
    }

    if ($action === 'save_session') {
        $session = $input['session'] ?? '';
        $stmt = $pdo->prepare("UPDATE school_settings SET current_year = ?");
        $stmt->execute([$session]);
        sendJson(['success' => true]);
    }

    if ($action === 'save_term') {
        $term = $input['term'] ?? '';
        $stmt = $pdo->prepare("UPDATE school_settings SET current_term = ?");
        $stmt->execute([$term]);
        sendJson(['success' => true]);
    }
    
    if ($action === 'add_activity') {
        $title = $input['title'] ?? '';
        $date = $input['date'] ?? '';
        $id = bin2hex(random_bytes(16));
        
        $stmt = $pdo->prepare("INSERT INTO activities (id, title, date) VALUES (?, ?, ?)");
        $stmt->execute([$id, $title, $date]);
        sendJson(['success' => true, 'id' => $id]);
    }

    if ($action === 'save_paystack_keys') {
        $pk = $input['public_key'] ?? '';
        $sk = $input['secret_key'] ?? '';
        $stmt = $pdo->prepare("UPDATE school_settings SET paystack_public_key = ?, paystack_secret_key = ?");
        $stmt->execute([$pk, $sk]);
        sendJson(['success' => true]);
    }
}

sendJson(['error' => 'Invalid action'], 400);
