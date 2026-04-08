<?php
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if (!isset($_SESSION['session']['userId'])) {
    sendJson(['error' => 'Not authenticated'], 401);
}
$userId = $_SESSION['session']['userId'];
$isAdmin = ($_SESSION['session']['role'] ?? '') === 'admin';

// Fee categories used throughout
define('FEE_CATS', ['school_fee', 'uniform', 'books', 'dormitory', 'toiletries', 'practical', 'activities']);
define('FEE_LABELS', [
    'school_fee'  => 'School Fees',
    'uniform'     => 'Uniform',
    'books'       => 'Books',
    'dormitory'   => 'Dormitory',
    'toiletries'  => 'Toiletries',
    'practical'   => 'Practical',
    'activities'  => 'Activities',
]);

if ($method === 'GET') {

    // ── Student: fetch my fees for current term ──────────────────────────────
    if ($action === 'my_fees') {
        $stmt = $pdo->prepare("SELECT classLevel FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userClass = $stmt->fetchColumn();

        $settings = getGlobalSettings($pdo);
        $term    = $_GET['term']    ?? ($settings['current_term'] ?? 'First Term');
        $session = $_GET['session'] ?? ($settings['current_year'] ?? '2025/2026');

        // Look up fee row for this class/term/session
        $stmt = $pdo->prepare("SELECT * FROM fees WHERE class_name = ? AND term = ? AND session = ?");
        $stmt->execute([$userClass, $term, $session]);
        $feeRow = $stmt->fetch();

        $feeItems = [];
        if ($feeRow) {
            foreach (FEE_CATS as $cat) {
                $amt = floatval($feeRow[$cat] ?? 0);
                if ($amt > 0) {
                    $feeItems[] = ['description' => FEE_LABELS[$cat], 'key' => $cat, 'amount' => $amt];
                }
            }
        }

        // Check for payments already made this term (success or pending)
        $stmt = $pdo->prepare(
            "SELECT fee_type, status FROM payments WHERE student_id = ? AND term = ? AND session = ?"
        );
        $stmt->execute([$userId, $term, $session]);
        $existingPayments = $stmt->fetchAll();

        $paidTypes    = [];
        $pendingTypes = [];
        foreach ($existingPayments as $p) {
            if ($p['status'] === 'success')  $paidTypes[]    = $p['fee_type'];
            if ($p['status'] === 'pending')  $pendingTypes[] = $p['fee_type'];
        }

        // Mark each fee item's payment status
        foreach ($feeItems as &$item) {
            if (in_array($item['key'], $paidTypes))    $item['payStatus'] = 'paid';
            elseif (in_array($item['key'], $pendingTypes)) $item['payStatus'] = 'pending';
            else $item['payStatus'] = 'unpaid';
        }
        unset($item);

        sendJson([
            'term'       => $term,
            'session'    => $session,
            'classLevel' => $userClass,
            'fees'       => $feeItems,
        ]);
    }

    // ── Admin: list all fee rows ─────────────────────────────────────────────
    if ($action === 'list_all') {
        if (!$isAdmin) sendJson(['error' => 'Forbidden'], 403);

        $session = $_GET['session'] ?? '';
        $term    = $_GET['term']    ?? '';

        if ($session && $term) {
            $stmt = $pdo->prepare("SELECT * FROM fees WHERE session = ? AND term = ? ORDER BY class_name");
            $stmt->execute([$session, $term]);
        } elseif ($session) {
            $stmt = $pdo->prepare("SELECT * FROM fees WHERE session = ? ORDER BY class_name, term");
            $stmt->execute([$session]);
        } else {
            $stmt = $pdo->query("SELECT * FROM fees ORDER BY session DESC, class_name, term");
        }
        sendJson($stmt->fetchAll());
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    // ── Student: record an instant (success) or manual (pending) payment ────
    if ($action === 'pay_fee') {
        $term    = $input['term']    ?? '';
        $session = $input['session'] ?? $input['year'] ?? '';
        $items   = $input['items']   ?? [];  // [{ key, description, amount, paymentType }]

        foreach ($items as $item) {
            $paymentType = $item['paymentType'] ?? 'instant'; // 'instant' or 'manual'
            $status      = $paymentType === 'manual' ? 'pending' : 'success';
            $receiptNo   = $status === 'success'
                ? 'RCPT-' . date('Y') . '-' . sprintf("%06d", mt_rand(1, 999999))
                : null;

            $pid = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("INSERT INTO payments
                (id, student_id, amount, fee_type, status, payment_date, receipt_no, term, session)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $pid,
                $userId,
                $item['amount'] ?? 0,
                $item['key']    ?? $item['description'] ?? 'Fee',
                $status,
                date('c'),
                $receiptNo,
                $term,
                $session,
            ]);

            // Also create a PAID invoice entry for successful instant payments
            if ($status === 'success') {
                $invId  = bin2hex(random_bytes(16));
                $stmt2 = $pdo->prepare("INSERT INTO invoices
                    (id, invoiceNo, studentId, term, year, status, createdAt)
                    VALUES (?, ?, ?, ?, ?, 'PAID', ?)");
                $stmt2->execute([$invId, $receiptNo, $userId, $term, $session, date('c')]);

                $itmId = bin2hex(random_bytes(16));
                $pdo->prepare("INSERT INTO invoice_items (id, invoiceId, name, amount) VALUES (?, ?, ?, ?)")
                    ->execute([$itmId, $invId, $item['description'] ?? 'Fee', $item['amount'] ?? 0]);
            }
        }

        sendJson(['success' => true]);
    }

    // ── Student: verify and record an instant Paystack payment ────────────────
    if ($action === 'verify_payment') {
        $term      = $input['term']      ?? '';
        $session   = $input['session']   ?? '';
        $items     = $input['items']     ?? [];
        $reference = $input['reference'] ?? '';

        if (!$reference) sendJson(['error' => 'No reference provided'], 400);

        // Fetch Paystack Secret Key
        $stmt = $pdo->query("SELECT paystack_secret_key FROM school_settings LIMIT 1");
        $sk = $stmt->fetchColumn();
        if (!$sk) sendJson(['error' => 'Payment gateway not configured'], 400);

        // Call Paystack API
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $sk,
                "Cache-Control: no-cache",
            ],
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) sendJson(['error' => 'cURL Error: ' . $err], 500);

        $tranx = json_decode($response);
        if (!$tranx || !$tranx->status) {
            sendJson(['error' => 'API Error: ' . ($tranx->message ?? 'Verification failed')], 400);
        }
        if ('success' !== $tranx->data->status) {
            sendJson(['error' => 'Transaction was not successful'], 400);
        }

        // Check if reference already processed
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE reference = ?");
        $stmt->execute([$reference]);
        if ($stmt->fetchColumn() > 0) {
            sendJson(['error' => 'Transaction already processed'], 400);
        }

        $receiptNo = 'RCPT-' . date('Y') . '-' . sprintf("%06d", mt_rand(1, 999999));

        foreach ($items as $item) {
            $pid = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("INSERT INTO payments
                (id, student_id, amount, fee_type, status, payment_date, receipt_no, term, session, reference)
                VALUES (?, ?, ?, ?, 'success', ?, ?, ?, ?, ?)");
            $stmt->execute([
                $pid,
                $userId,
                $item['amount'] ?? 0,
                $item['key']    ?? $item['description'] ?? 'Fee',
                date('c'),
                $receiptNo,
                $term,
                $session,
                $reference
            ]);

            // Create PAID invoice
            $invId  = bin2hex(random_bytes(16));
            $stmt2 = $pdo->prepare("INSERT INTO invoices
                (id, invoiceNo, studentId, term, year, status, createdAt)
                VALUES (?, ?, ?, ?, ?, 'PAID', ?)");
            $stmt2->execute([$invId, $receiptNo, $userId, $term, $session, date('c')]);

            $itmId = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO invoice_items (id, invoiceId, name, amount) VALUES (?, ?, ?, ?)")
                ->execute([$itmId, $invId, $item['description'] ?? 'Fee', $item['amount'] ?? 0]);
        }

        sendJson(['success' => true]);
    }

    // ── Admin: add fee row ───────────────────────────────────────────────────
    if ($action === 'add') {
        if (!$isAdmin) sendJson(['error' => 'Forbidden'], 403);

        $term      = $input['term']      ?? '';
        $session   = $input['session']   ?? $input['year'] ?? '2025/2026';
        $className = $input['class_name'] ?? '';

        if (!$term || !$session || !$className) {
            sendJson(['error' => 'term, session, and class_name are required'], 400);
        }

        // Upsert: delete existing row then insert
        $pdo->prepare("DELETE FROM fees WHERE class_name = ? AND term = ? AND session = ?")
            ->execute([$className, $term, $session]);

        $id    = bin2hex(random_bytes(16));
        $cats  = FEE_CATS;
        $cols  = implode(', ', array_map(fn($c) => "`$c`", $cats));
        $marks = implode(', ', array_fill(0, count($cats), '?'));

        $stmt = $pdo->prepare(
            "INSERT INTO fees (id, class_name, term, session, $cols)
             VALUES (?, ?, ?, ?, $marks)"
        );
        $values = [$id, $className, $term, $session];
        foreach ($cats as $cat) {
            $values[] = floatval($input[$cat] ?? 0);
        }
        $stmt->execute($values);
        sendJson(['success' => true]);
    }

    // ── Admin: update fee row ────────────────────────────────────────────────
    if ($action === 'update') {
        if (!$isAdmin) sendJson(['error' => 'Forbidden'], 403);

        $id = $input['id'] ?? '';
        if (!$id) sendJson(['error' => 'id required'], 400);

        $setClauses = [];
        $values     = [];
        foreach (FEE_CATS as $cat) {
            if (isset($input[$cat])) {
                $setClauses[] = "`$cat` = ?";
                $values[]     = floatval($input[$cat]);
            }
        }
        foreach (['class_name', 'term', 'session'] as $col) {
            if (isset($input[$col])) {
                $setClauses[] = "`$col` = ?";
                $values[]     = $input[$col];
            }
        }
        if (!$setClauses) sendJson(['success' => true]);

        $values[] = $id;
        $pdo->prepare("UPDATE fees SET " . implode(', ', $setClauses) . " WHERE id = ?")
            ->execute($values);
        sendJson(['success' => true]);
    }

    // ── Admin: delete fee row ────────────────────────────────────────────────
    if ($action === 'delete') {
        if (!$isAdmin) sendJson(['error' => 'Forbidden'], 403);

        $id = $input['id'] ?? '';
        $pdo->prepare("DELETE FROM fees WHERE id = ?")->execute([$id]);
        sendJson(['success' => true]);
    }

    // ── Admin: batch-add fees for all classes at once ─────────────────────
    if ($action === 'batch_add') {
        if (!$isAdmin) sendJson(['error' => 'Forbidden'], 403);

        $term    = $input['term']    ?? '';
        $session = $input['session'] ?? $input['year'] ?? '2025/2026';
        $rows    = $input['rows']    ?? []; // [{ class_name, school_fee, uniform, ... }]

        foreach ($rows as $row) {
            $className = $row['class_name'] ?? '';
            if (!$className) continue;

            $pdo->prepare("DELETE FROM fees WHERE class_name = ? AND term = ? AND session = ?")
                ->execute([$className, $term, $session]);

            $id   = bin2hex(random_bytes(16));
            $cats = FEE_CATS;
            $cols = implode(', ', array_map(fn($c) => "`$c`", $cats));
            $mks  = implode(', ', array_fill(0, count($cats), '?'));
            $stmt = $pdo->prepare(
                "INSERT INTO fees (id, class_name, term, session, $cols) VALUES (?, ?, ?, ?, $mks)"
            );
            $vals = [$id, $className, $term, $session];
            foreach ($cats as $cat) {
                $vals[] = floatval($row[$cat] ?? 0);
            }
            $stmt->execute($vals);
        }
        sendJson(['success' => true]);
    }
}

sendJson(['error' => 'Invalid action'], 400);
