<?php
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if (!isset($_SESSION['session']['userId'])) {
    sendJson(['error' => 'Not authenticated'], 401);
}
$userId  = $_SESSION['session']['userId'];
$isAdmin = ($_SESSION['session']['role'] ?? '') === 'admin';

if ($method === 'GET') {

    // ── Admin: list all payments (with filters) ──────────────────────────────
    if ($action === 'list') {
        if (!$isAdmin) sendJson(['error' => 'Forbidden'], 403);

        $status  = $_GET['status']  ?? '';   // 'pending', 'success', or ''
        $term    = $_GET['term']    ?? '';
        $session = $_GET['session'] ?? '';

        $where  = [];
        $params = [];

        if ($status)  { $where[] = "p.status = ?";  $params[] = $status; }
        if ($term)    { $where[] = "p.term = ?";     $params[] = $term; }
        if ($session) { $where[] = "p.session = ?";  $params[] = $session; }

        $whereStr = $where ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $pdo->prepare("
            SELECT p.*, u.fullName, u.classLevel
            FROM payments p
            LEFT JOIN users u ON u.id = p.student_id
            $whereStr
            ORDER BY p.payment_date DESC
        ");
        $stmt->execute($params);
        sendJson($stmt->fetchAll());
    }

    // ── Student: my payments ─────────────────────────────────────────────────
    if ($action === 'my_payments') {
        $term    = $_GET['term']    ?? '';
        $session = $_GET['session'] ?? '';

        $where  = ["p.student_id = ?"];
        $params = [$userId];
        if ($term)    { $where[] = "p.term = ?";    $params[] = $term; }
        if ($session) { $where[] = "p.session = ?"; $params[] = $session; }

        $whereStr = "WHERE " . implode(" AND ", $where);
        $stmt = $pdo->prepare("SELECT * FROM payments $whereStr ORDER BY payment_date DESC");
        $stmt->execute($params);
        sendJson($stmt->fetchAll());
    }

    // ── Admin: payment summary stats ─────────────────────────────────────────
    if ($action === 'summary') {
        if (!$isAdmin) sendJson(['error' => 'Forbidden'], 403);

        $term    = $_GET['term']    ?? '';
        $session = $_GET['session'] ?? '';

        $where  = [];
        $params = [];
        if ($term)    { $where[] = "term = ?";    $params[] = $term; }
        if ($session) { $where[] = "session = ?"; $params[] = $session; }
        $whereStr = $where ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) AS total_paid,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS total_pending,
                COUNT(CASE WHEN status = 'pending' THEN 1 END)           AS pending_count,
                COUNT(CASE WHEN status = 'success' THEN 1 END)           AS success_count
            FROM payments $whereStr
        ");
        $stmt->execute($params);
        sendJson($stmt->fetch());
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    // ── Admin: approve a pending payment ─────────────────────────────────────
    if ($action === 'approve') {
        if (!$isAdmin) sendJson(['error' => 'Forbidden'], 403);

        $paymentId = $input['id'] ?? '';
        if (!$paymentId) sendJson(['error' => 'Payment id required'], 400);

        // Fetch the payment
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) sendJson(['error' => 'Payment not found'], 404);
        if ($payment['status'] !== 'pending') sendJson(['error' => 'Payment is not pending'], 400);

        $receiptNo = 'RCPT-' . date('Y') . '-' . sprintf("%06d", mt_rand(1, 999999));

        // Update payment to success
        $pdo->prepare("UPDATE payments SET status = 'success', approved_by = ?, receipt_no = ? WHERE id = ?")
            ->execute([$userId, $receiptNo, $paymentId]);

        // Create a PAID invoice for the receipt
        $invId = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO invoices (id, invoiceNo, studentId, term, year, status, createdAt)
                       VALUES (?, ?, ?, ?, ?, 'PAID', ?)")
            ->execute([$invId, $receiptNo, $payment['student_id'],
                       $payment['term'], $payment['session'], date('c')]);

        $itmId = bin2hex(random_bytes(16));
        $label = ucfirst(str_replace('_', ' ', $payment['fee_type']));
        $pdo->prepare("INSERT INTO invoice_items (id, invoiceId, name, amount) VALUES (?, ?, ?, ?)")
            ->execute([$itmId, $invId, $label, $payment['amount']]);

        sendJson(['success' => true, 'receipt_no' => $receiptNo]);
    }

    // ── Admin: reject/delete a pending payment ───────────────────────────────
    if ($action === 'reject') {
        if (!$isAdmin) sendJson(['error' => 'Forbidden'], 403);

        $paymentId = $input['id'] ?? '';
        if (!$paymentId) sendJson(['error' => 'Payment id required'], 400);

        $pdo->prepare("DELETE FROM payments WHERE id = ? AND status = 'pending'")->execute([$paymentId]);
        sendJson(['success' => true]);
    }
}

sendJson(['error' => 'Invalid action'], 400);
