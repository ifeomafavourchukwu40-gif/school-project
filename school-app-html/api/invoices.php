<?php
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Require auth
if (!isset($_SESSION['session']['userId'])) {
    sendJson(['error' => 'Not authenticated'], 401);
}

// Restrict global access, but allow students specific actions
$role = $_SESSION['session']['role'];
$userId = $_SESSION['session']['userId'];

if ($role !== 'admin' && !in_array($action, ['my_invoices', 'get'])) {
    sendJson(['error' => 'Forbidden: Admins only'], 403);
}

if ($method === 'GET') {
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT * FROM invoices ORDER BY createdAt DESC");
        sendJson($stmt->fetchAll());
    }
    
    if ($action === 'get') {
        $id = $_GET['id'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        if ($invoice) {
            if ($role !== 'admin' && $invoice['studentId'] !== $userId) {
                sendJson(['error' => 'Forbidden: Access Denied'], 403);
            }
            $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoiceId = ?");
            $stmt->execute([$id]);
            $invoice['items'] = $stmt->fetchAll();
        }
        sendJson($invoice ?: null);
    }

    if ($action === 'my_invoices') {
        $term = $_GET['term'] ?? '';
        $year = $_GET['year'] ?? '';
        
        $where = ["studentId = ?"];
        $params = [$userId];
        
        if ($term) { $where[] = "term = ?"; $params[] = $term; }
        if ($year) { $where[] = "year = ?"; $params[] = $year; }
        
        $whereStr = implode(" AND ", $where);
        
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE $whereStr ORDER BY createdAt DESC");
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();
        
        // Fetch items for all these invoices
        foreach ($invoices as &$inv) {
            $stmt = $pdo->prepare("SELECT amount FROM invoice_items WHERE invoiceId = ?");
            $stmt->execute([$inv['id']]);
            $items = $stmt->fetchAll();
            $total = 0;
            foreach ($items as $itm) { $total += $itm['amount']; }
            $inv['totalAmount'] = $total;
        }
        sendJson($invoices);
    }
    if ($action === 'tracking') {
        $settings = getGlobalSettings($pdo);
        $term = $_GET['term'] ?? $settings['current_term'];
        $year = $_GET['year'] ?? $settings['current_year'];

        $stmt = $pdo->prepare("
            SELECT 
                u.id as studentId, 
                u.fullName, 
                u.classLevel,
                (SELECT IFNULL(SUM(it.amount), 0) FROM invoices i JOIN invoice_items it ON i.id = it.invoiceId WHERE i.studentId = u.id AND i.term = ? AND i.year = ?) as totalInvoiced,
                (SELECT IFNULL(SUM(it.amount), 0) FROM invoices i JOIN invoice_items it ON i.id = it.invoiceId WHERE i.studentId = u.id AND i.status = 'PAID' AND i.term = ? AND i.year = ?) as totalPaid
            FROM users u
            WHERE u.role = 'student'
            ORDER BY u.classLevel, u.fullName
        ");
        $stmt->execute([$term, $year, $term, $year]);
        $results = $stmt->fetchAll();
        foreach ($results as &$r) {
            $r['balance'] = (float)$r['totalInvoiced'] - (float)$r['totalPaid'];
        }
        sendJson($results);
    }

    if ($action === 'summary') {
        $stmt = $pdo->query("
            SELECT 
                i.year, 
                i.term, 
                IFNULL(SUM(it.amount), 0) as totalInvoiced,
                IFNULL(SUM(CASE WHEN i.status = 'PAID' THEN it.amount ELSE 0 END), 0) as totalPaid
            FROM invoices i
            LEFT JOIN invoice_items it ON i.id = it.invoiceId
            GROUP BY i.year, i.term
            ORDER BY i.year DESC, i.term ASC
        ");
        $results = $stmt->fetchAll();
        foreach ($results as &$r) {
            $r['totalInvoiced'] = (float)$r['totalInvoiced'];
            $r['totalPaid'] = (float)$r['totalPaid'];
            $r['balance'] = $r['totalInvoiced'] - $r['totalPaid'];
        }
        sendJson($results);
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($action === 'open_or_create') {
        $studentId = $input['studentId'] ?? '';
        $term = $input['term'] ?? '';
        $year = $input['year'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE studentId = ? AND term = ? AND year = ?");
        $stmt->execute([$studentId, $term, $year]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoiceId = ?");
            $stmt->execute([$existing['id']]);
            $existing['items'] = $stmt->fetchAll();
            sendJson($existing);
        }
        
        // Generate Invoice No
        $yearStr = date('Y');
        $prefix = "INV-$yearStr-";
        $stmt = $pdo->prepare("SELECT invoiceNo FROM invoices WHERE invoiceNo LIKE ? ORDER BY createdAt DESC LIMIT 1");
        $stmt->execute(["$prefix%"]);
        $last = $stmt->fetchColumn();
        
        if ($last) {
            $num = (int) substr($last, -6);
            $next = str_pad($num + 1, 6, "0", STR_PAD_LEFT);
        } else {
            $next = "000001";
        }
        $invoiceNo = $prefix . $next;
        
        $id = bin2hex(random_bytes(16));
        $createdAt = date('c');
        $status = "UNPAID";
        
        $stmt = $pdo->prepare("INSERT INTO invoices (id, invoiceNo, studentId, term, year, status, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $invoiceNo, $studentId, $term, $year, $status, $createdAt]);
        
        sendJson([
            'id' => $id,
            'invoiceNo' => $invoiceNo,
            'studentId' => $studentId,
            'term' => $term,
            'year' => $year,
            'status' => $status,
            'createdAt' => $createdAt,
            'items' => []
        ]);
    }
    
    if ($action === 'update_status') {
        $id = $input['id'] ?? '';
        $status = $input['status'] ?? '';
        $stmt = $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        sendJson(['success' => true]);
    }
    
    if ($action === 'add_item') {
        $invoiceId = $input['invoiceId'] ?? '';
        $name = $input['name'] ?? '';
        $amount = (float)($input['amount'] ?? 0);
        
        $id = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO invoice_items (id, invoiceId, name, amount) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $invoiceId, $name, $amount]);
        
        sendJson(['success' => true, 'id' => $id]);
    }
    
    if ($action === 'remove_item') {
        $itemId = $input['itemId'] ?? '';
        $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE id = ?");
        $stmt->execute([$itemId]);
        sendJson(['success' => true]);
    }
}

sendJson(['error' => 'Invalid action'], 400);
