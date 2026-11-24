<?php
/**
 * Smart Sweep — M-Pesa PayBill confirmation (Daraja C2B callback)
 * ---------------------------------------------------------------------------
 * Safaricom calls this page when a resident pays a Smart Sweep
 * invoice through Lipa na M-Pesa → PayBill, quoting their invoice
 * code as the account number (BillRefNumber).
 *
 * What it does:
 *   1. Finds the invoice by the account number quoted.
 *   2. If a Pending "M-Pesa PayBill" payment is waiting for that
 *      invoice, completes it; otherwise records the payment new.
 *   3. Saves Safaricom's transaction ID as the M-Pesa receipt.
 *   4. Marks the invoice Paid once it is covered.
 *   5. Answers Safaricom with the acceptance reply it expects.
 *
 * Register this page's public URL as the C2B ConfirmationURL with
 * Safaricom. Until then, paybill payments are confirmed by the
 * Accountant through the payments API (action "confirm").
 */

header('Content-Type: application/json');

function db(): PDO
{
    return new PDO(
        'mysql:host=localhost;dbname=smart_sweep;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function accept(): void
{
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

$raw = file_get_contents('php://input');
$cb  = json_decode($raw, true);
if (!is_array($cb)) {
    accept();   // nothing readable — still answer politely
}

// Daraja C2B fields; simple field names are also accepted so the
// endpoint can be exercised in tests.
$transId = $cb['TransID']       ?? $cb['trans_id']     ?? null;
$account = $cb['BillRefNumber'] ?? $cb['invoice_code'] ?? null;
$amount  = (int) ($cb['TransAmount'] ?? $cb['amount'] ?? 0);
$phone   = $cb['MSISDN']        ?? $cb['phone']        ?? null;

if ($account === null || $transId === null) {
    accept();
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE invoice_code = ?');
    $stmt->execute([$account]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        accept();   // a payment for an invoice we do not hold
    }
    if ($amount <= 0) {
        $amount = (int) $inv['amount'];
    }

    // A paybill payment the resident started on the Pay page?
    $stmt = $pdo->prepare(
        "SELECT * FROM payments
          WHERE invoice_code = ? AND method = 'M-Pesa PayBill' AND status = 'Pending'
          ORDER BY id ASC LIMIT 1"
    );
    $stmt->execute([$account]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pending) {
        $pdo->prepare(
            "UPDATE payments SET status = 'Completed', mpesa_ref = ?, phone = COALESCE(?, phone)
              WHERE payment_code = ?"
        )->execute([$transId, $phone, $pending['payment_code']]);
    } else {
        $row = $pdo->query('SELECT MAX(payment_code) AS m FROM payments')->fetch(PDO::FETCH_ASSOC);
        $next = $row['m'] ? ((int) substr($row['m'], 4)) + 1 : 1;
        $code = 'PAY-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        $pdo->prepare(
            'INSERT INTO payments (payment_code, invoice_code, method, mpesa_ref, reference, status, phone, pay_date, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$code, $account, 'M-Pesa PayBill', $transId, 'PB-' . $code, 'Completed', $phone, date('j M Y'), $amount]);
    }

    // Settle the invoice if its completed payments now cover it.
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_code = ? AND status = 'Completed'"
    );
    $stmt->execute([$account]);
    if ((int) $stmt->fetchColumn() >= (int) $inv['amount']) {
        $pdo->prepare("UPDATE invoices SET status = 'Paid' WHERE invoice_code = ?")
            ->execute([$account]);
    }
} catch (Throwable $e) {
    // Never argue with Safaricom — the payment is reconciled by the
    // Accountant if anything here failed.
}

accept();
