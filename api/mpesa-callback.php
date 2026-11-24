<?php
// ============================================================
//  Smart Sweep — M-Pesa callback
//
//  Safaricom calls this page when a payer answers the STK
//  Push sent by api/payments.php. It is not a page for
//  people; Daraja posts JSON here and expects JSON back.
//
//  The callback carries the CheckoutRequestID the payment
//  was saved with (in the payments.reference column):
//    ResultCode 0  -> the payer paid: the payment completes,
//                     the real M-Pesa receipt is saved, and
//                     the invoice is marked Paid if covered.
//    Anything else -> the payer cancelled or it timed out:
//                     the payment is marked Failed.
//
//  Point the Daraja CallBackURL (config/payments.php) at the
//  public address of this file for live payments.
// ============================================================

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$callback = $data['Body']['stkCallback'] ?? null;
if (!$callback) {
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'No callback data']);
    exit;
}

$checkoutId = $callback['CheckoutRequestID'] ?? '';
$resultCode = (int) ($callback['ResultCode'] ?? -1);

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=smart_sweep;charset=utf8mb4",
        "root",
        "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Database unavailable']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM payments WHERE reference = ?");
$stmt->execute([$checkoutId]);
$pay = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pay) {
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Payment not found']);
    exit;
}

if ($resultCode === 0) {
    // Read the real receipt number out of the metadata.
    $receipt = null;
    foreach ($callback['CallbackMetadata']['Item'] ?? [] as $item) {
        if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
            $receipt = $item['Value'];
        }
    }
    $pdo->prepare("UPDATE payments SET status = 'Completed', mpesa_ref = COALESCE(?, mpesa_ref) WHERE payment_code = ?")
        ->execute([$receipt, $pay['payment_code']]);

    // Mark the invoice Paid once completed payments cover it.
    $stmt = $pdo->prepare("SELECT amount FROM invoices WHERE invoice_code = ?");
    $stmt->execute([$pay['invoice_code']]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE invoice_code = ? AND status = 'Completed'");
    $stmt->execute([$pay['invoice_code']]);
    $paid = (int) $stmt->fetch(PDO::FETCH_ASSOC)['paid'];
    if ($inv && $paid >= (int) $inv['amount']) {
        $pdo->prepare("UPDATE invoices SET status = 'Paid' WHERE invoice_code = ?")
            ->execute([$pay['invoice_code']]);
    }
} else {
    $pdo->prepare("UPDATE payments SET status = 'Failed' WHERE payment_code = ?")
        ->execute([$pay['payment_code']]);
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
