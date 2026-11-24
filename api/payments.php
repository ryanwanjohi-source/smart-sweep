<?php
// ============================================================
//  Smart Sweep — Payments API
//
//  One API for paying a Smart Sweep invoice with whatever
//  mode the payer has:
//
//    M-Pesa         — STK Push to the payer's phone (Daraja).
//    M-Pesa PayBill — the resident pays on their phone (Lipa
//                     na M-Pesa → PayBill), quoting the invoice
//                     code as the account number; the payment
//                     starts Pending and Safaricom's C2B
//                     confirmation (mpesa-c2b.php) completes it.
//    Bank Transfer  — the payer transfers to the Smart Sweep
//                     account; the payment starts Pending and
//                     the Accountant confirms it.
//    Cash           — recorded at the office, completed at once.
//
//  Endpoints (JSON in, JSON out):
//
//    GET  payments.php?invoice=INV-2212
//         -> the invoice, and the payments already made on it.
//
//    GET  payments.php?payment=PAY-0872
//         -> one payment and its status.
//
//    POST payments.php
//         {"action":"initiate", "invoice_code":"INV-2212",
//          "method":"M-Pesa", "phone":"0712 345 678"}
//         -> starts a payment. M-Pesa sends the STK Push;
//            Bank Transfer
//            returns the account details and the reference to
//            quote; Cash is recorded as received.
//
//    POST payments.php
//         {"action":"confirm", "payment_code":"PAY-0872"}
//         -> the Accountant confirms a Pending payment (a bank
//            transfer that has landed in the account).
//
//  When the completed payments on an invoice cover its
//  amount, the invoice's status becomes Paid — the same
//  position the Reports page shows.
//
//  TEST MODE: until real gateway details are put in
//  config/payments.php (copy config/payments.sample.php and
//  fill it in), the API runs in test mode and no real money
//  moves: M-Pesa payments complete with generated
//  test references, and every response carries
//  "test_mode": true so nobody mistakes them for real ones.
//  With Daraja details in the config, M-Pesa sends a real
//  STK Push, the payment waits as Pending, and Safaricom's
//  callback to api/mpesa-callback.php completes it.
// ============================================================

header('Content-Type: application/json');

// ---- Settings (defaults: test mode; real file overrides) ----
$config = [
    'test_mode' => true,
    'daraja' => [
        'environment'   => 'sandbox',   // 'sandbox' or 'live'
        'consumer_key'  => '',
        'consumer_secret' => '',
        'shortcode'     => '',          // the paybill / till number
        'passkey'       => '',
        'callback_url'  => '',          // public URL of api/mpesa-callback.php
    ],
    'bank' => [
        'bank_name'      => 'Co-operative Bank',
        'account_name'   => 'Smart Sweep Collections',
        'account_number' => '01134567891000',
    ],
    'paybill' => [
        'business_number' => '123456',   // sample — the real one goes in config/payments.php
    ],
];
$configFile = __DIR__ . '/../config/payments.php';
if (file_exists($configFile)) {
    $loaded = require $configFile;
    if (is_array($loaded)) {
        $config = array_replace_recursive($config, $loaded);
    }
}
$darajaReady = $config['daraja']['consumer_key'] !== ''
            && $config['daraja']['consumer_secret'] !== ''
            && $config['daraja']['shortcode'] !== ''
            && $config['daraja']['passkey'] !== '';
if ($darajaReady) {
    $config['test_mode'] = false;   // real gateway details: go live
}

// ---- Connect to the smart_sweep database (same as telemetry) ----
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=smart_sweep;charset=utf8mb4",
        "root",
        "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    respond(['success' => false, 'message' => 'The payments service cannot reach the database. Is XAMPP MySQL running?'], 500);
}

// ---- Small helpers ----
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail($message, $code = 400) {
    respond(['success' => false, 'message' => $message], $code);
}

// The next payment code: PAY-0872 after PAY-0871, and so on.
function nextPaymentCode($pdo) {
    $row = $pdo->query("SELECT MAX(CAST(SUBSTRING(payment_code, 5) AS UNSIGNED)) AS n FROM payments")->fetch(PDO::FETCH_ASSOC);
    $n = ($row && $row['n']) ? (int) $row['n'] + 1 : 1;
    return 'PAY-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

// A test receipt in the M-Pesa shape (10 letters and numbers).
function testReceipt() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ1234567890';
    $out = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

// Mark the invoice Paid once completed payments cover it.
function refreshInvoiceStatus($pdo, $invoiceCode) {
    $stmt = $pdo->prepare("SELECT amount, status FROM invoices WHERE invoice_code = ?");
    $stmt->execute([$invoiceCode]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE invoice_code = ? AND status = 'Completed'");
    $stmt->execute([$invoiceCode]);
    $paid = (int) $stmt->fetch(PDO::FETCH_ASSOC)['paid'];
    if ($paid >= (int) $inv['amount'] && $inv['status'] !== 'Paid') {
        $pdo->prepare("UPDATE invoices SET status = 'Paid' WHERE invoice_code = ?")
            ->execute([$invoiceCode]);
    }
}

// A Kenyan mobile number in the 254... shape Daraja expects.
function normalisePhone($phone) {
    $p = preg_replace('/[\s\-]/', '', (string) $phone);
    if (str_starts_with($p, '+254')) $p = substr($p, 1);
    if (str_starts_with($p, '0'))    $p = '254' . substr($p, 1);
    return preg_match('/^254[17]\d{8}$/', $p) ? $p : null;
}


// ---- Daraja (M-Pesa) STK Push — used when real details are set ----
function darajaStkPush($cfg, $phone, $amount, $accountRef) {
    $base = $cfg['environment'] === 'live'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';

    // 1. Access token
    $ch = curl_init($base . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $cfg['consumer_key'] . ':' . $cfg['consumer_secret'],
    ]);
    $tokenReply = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($tokenReply['access_token'])) {
        return [null, 'M-Pesa did not issue an access token. Check the Daraja details in config/payments.php.'];
    }

    // 2. The STK Push itself
    $timestamp = date('YmdHis');
    $password = base64_encode($cfg['shortcode'] . $cfg['passkey'] . $timestamp);
    $body = [
        'BusinessShortCode' => $cfg['shortcode'],
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => $amount,
        'PartyA'            => $phone,
        'PartyB'            => $cfg['shortcode'],
        'PhoneNumber'       => $phone,
        'CallBackURL'       => $cfg['callback_url'],
        'AccountReference'  => $accountRef,
        'TransactionDesc'   => 'Smart Sweep invoice ' . $accountRef,
    ];
    $ch = curl_init($base . '/mpesa/stkpush/v1/processrequest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $tokenReply['access_token'],
        ],
    ]);
    $reply = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (isset($reply['ResponseCode']) && $reply['ResponseCode'] === '0') {
        return [$reply['CheckoutRequestID'], null];
    }
    return [null, 'M-Pesa refused the payment request: ' . ($reply['errorMessage'] ?? $reply['ResponseDescription'] ?? 'unknown error')];
}

// ============================================================
//  GET — look an invoice or a payment up
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['invoice'])) {
        $stmt = $pdo->prepare("SELECT invoice_code, resident_name, estate, amount, status FROM invoices WHERE invoice_code = ?");
        $stmt->execute([strtoupper(trim($_GET['invoice']))]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) fail('No invoice with that code. Check the code on the invoice and try again.', 404);
        $stmt = $pdo->prepare("SELECT payment_code, method, mpesa_ref, reference, status, phone, pay_date, amount FROM payments WHERE invoice_code = ? ORDER BY id");
        $stmt->execute([$inv['invoice_code']]);
        respond([
            'success'   => true,
            'test_mode' => $config['test_mode'],
            'invoice'   => [
                'code'     => $inv['invoice_code'],
                'resident' => $inv['resident_name'],
                'estate'   => $inv['estate'],
                'amount'   => (int) $inv['amount'],
                'status'   => $inv['status'],
            ],
            'payments'  => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'methods'   => ['M-Pesa', 'M-Pesa PayBill', 'Bank Transfer', 'Cash'],
        ]);
    }
    if (!empty($_GET['payment'])) {
        $stmt = $pdo->prepare("SELECT payment_code, invoice_code, method, mpesa_ref, reference, status, phone, pay_date, amount FROM payments WHERE payment_code = ?");
        $stmt->execute([strtoupper(trim($_GET['payment']))]);
        $pay = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pay) fail('No payment with that code.', 404);
        respond(['success' => true, 'test_mode' => $config['test_mode'], 'payment' => $pay]);
    }
    fail('Say what you are looking up: ?invoice=INV-2212 or ?payment=PAY-0872.');
}

// ============================================================
//  POST — start a payment, or confirm one
// ============================================================
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['action'])) {
    fail('Send JSON with an "action": "initiate" or "confirm".');
}

// ---- Confirm a Pending payment (bank transfer landed) ----
if ($input['action'] === 'confirm') {
    $code = strtoupper(trim($input['payment_code'] ?? ''));
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE payment_code = ?");
    $stmt->execute([$code]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) fail('No payment with that code.', 404);
    if ($pay['status'] === 'Completed') fail('That payment is already completed.');
    if ($pay['status'] === 'Failed')    fail('That payment failed and cannot be confirmed. Start a new payment instead.');
    $pdo->prepare("UPDATE payments SET status = 'Completed' WHERE payment_code = ?")->execute([$code]);
    refreshInvoiceStatus($pdo, $pay['invoice_code']);
    respond([
        'success' => true, 'test_mode' => $config['test_mode'],
        'message' => "Payment $code confirmed as received.",
        'payment_code' => $code, 'status' => 'Completed',
    ]);
}

if ($input['action'] !== 'initiate') {
    fail('Unknown action. Use "initiate" or "confirm".');
}

// ---- Start a payment ----
$invoiceCode = strtoupper(trim($input['invoice_code'] ?? ''));
$method      = trim($input['method'] ?? '');
$methods     = ['M-Pesa', 'M-Pesa PayBill', 'Bank Transfer', 'Cash'];
if (!in_array($method, $methods, true)) {
    fail('Choose a payment method: ' . implode(', ', $methods) . '.');
}
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_code = ?");
$stmt->execute([$invoiceCode]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) fail('No invoice with that code. Check the code on the invoice and try again.', 404);
if ($inv['status'] === 'Paid') fail("Invoice $invoiceCode is already paid. There is nothing left to pay on it.");

$amount      = (int) $inv['amount'];
$paymentCode = nextPaymentCode($pdo);
$today       = date('j M Y');
$status      = 'Pending';
$mpesaRef    = null;
$reference   = null;
$phone       = null;
$extra       = [];

// Each mode does its own work before the payment is saved.
if ($method === 'M-Pesa') {
    $phone = normalisePhone($input['phone'] ?? '');
    if ($phone === null) fail('Enter the M-Pesa phone number in the shape 07.. or 254.., for example 0712 345 678.');
    if ($config['test_mode']) {
        // No real money moves: the prompt is simulated and the
        // payment completes with a generated test receipt.
        $mpesaRef = testReceipt();
        $reference = $mpesaRef;
        $status = 'Completed';
        $extra['note'] = 'Test mode: no STK Push was sent and no money moved. The receipt is a generated test reference.';
    } else {
        [$checkoutId, $error] = darajaStkPush($config['daraja'], $phone, $amount, $invoiceCode);
        if ($error) fail($error, 502);
        $reference = $checkoutId;   // the callback finds the payment by this
        $status = 'Pending';
        $extra['note'] = 'An M-Pesa prompt has been sent to the phone. The payment completes when the payer enters their PIN and Safaricom calls back.';
    }
}

if ($method === 'M-Pesa PayBill') {
    // The resident pays on their own phone: Lipa na M-Pesa → PayBill →
    // the business number, with the invoice code as the account number.
    // The payment waits as Pending; Safaricom's C2B confirmation
    // (api/mpesa-c2b.php) completes it, or the Accountant confirms it.
    $reference = 'PB-' . $paymentCode;
    $status = 'Pending';
    $extra['paybill'] = [
        'business_number' => $config['paybill']['business_number'],
        'account_number'  => $invoiceCode,
        'amount'          => $amount,
    ];
    $extra['note'] = 'On your phone: Lipa na M-Pesa → PayBill → business number '
        . $config['paybill']['business_number'] . ', account number ' . $invoiceCode
        . ', amount KSh ' . number_format($amount) . '. The payment completes when '
        . 'Safaricom confirms it, or when the Accountant confirms it has landed.';
}

if ($method === 'Bank Transfer') {
    $reference = 'SSB-' . $paymentCode;
    $status = 'Pending';
    $extra['bank'] = $config['bank'];
    $extra['note'] = 'Transfer the amount to this account and quote the reference. The payment completes when the Accountant confirms the transfer has landed (action "confirm").';
}

if ($method === 'Cash') {
    $reference = 'CASH-' . $paymentCode;
    $status = 'Completed';
    $extra['note'] = 'Recorded as received in cash at the Smart Sweep office.';
}

// Save the payment, then settle the invoice if it is covered.
$pdo->prepare("INSERT INTO payments (payment_code, invoice_code, method, mpesa_ref, reference, status, phone, pay_date, amount)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([$paymentCode, $invoiceCode, $method, $mpesaRef, $reference, $status, $phone, $today, $amount]);
if ($status === 'Completed') {
    refreshInvoiceStatus($pdo, $invoiceCode);
}
$stmt = $pdo->prepare("SELECT status FROM invoices WHERE invoice_code = ?");
$stmt->execute([$invoiceCode]);
$invoiceStatus = $stmt->fetch(PDO::FETCH_ASSOC)['status'];

respond([
    'success'       => true,
    'test_mode'     => $config['test_mode'],
    'message'       => $status === 'Completed'
        ? "Payment $paymentCode completed. Invoice $invoiceCode is now $invoiceStatus."
        : "Payment $paymentCode started and is pending. Invoice $invoiceCode is still $invoiceStatus.",
    'payment_code'  => $paymentCode,
    'invoice_code'  => $invoiceCode,
    'method'        => $method,
    'amount'        => $amount,
    'status'        => $status,
    'reference'     => $reference,
    'mpesa_ref'     => $mpesaRef,
    'invoice_status' => $invoiceStatus,
] + $extra);
