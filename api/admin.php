<?php
// ============================================================
//  Smart Sweep — Admin API (System Control).
//
//  Everything the administrator's control panel needs,
//  in one place. Every call here requires a logged-in
//  Administrator — anyone else is turned away, so the
//  controls cannot be used from another role's pages.
//
//  GET  admin.php
//       -> { "stats": { users, bins, open_alerts, routes,
//            unpaid_invoices, payments, reviews, readings },
//            "users": [ {id, full_name, username, role,
//            phone, status} ... ],
//            "alerts": [ {alert_code, subject, alert_type,
//            message, status} ... ] }   (open alerts only)
//
//  POST admin.php
//       { "action": "set_role", "username": "fnjeri",
//         "role": "Dispatcher" }
//         -> changes that user's role. The administrator
//           cannot change their own role (that would lock
//           them out of this panel).
//
//       { "action": "set_status", "username": "jkiprop",
//         "status": "Suspended" }
//         -> Active or Suspended. A suspended account cannot
//           log in at all. The administrator cannot suspend
//           their own account. "Unpaid Invoice" is a billing
//           note, not a login state, so it is never set here.
//
//       { "action": "acknowledge_alert",
//         "alert_code": "AL-1042" }
//         -> marks the alert Acknowledged.
// ============================================================

session_start();
header('Content-Type: application/json');
require __DIR__ . '/../config/db.php';

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Administrator') {
    respond(['success' => false, 'message' => 'This page is for the administrator. Please log in as the administrator.'], 403);
}

$roles = ['Administrator', 'Dispatcher', 'Collection Driver', 'Accountant', 'Resident'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stats = [
        'users'           => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'bins'            => (int) $pdo->query('SELECT COUNT(*) FROM bins')->fetchColumn(),
        'open_alerts'     => (int) $pdo->query("SELECT COUNT(*) FROM alerts WHERE status = 'Not Acknowledged'")->fetchColumn(),
        'routes'          => (int) $pdo->query('SELECT COUNT(*) FROM routes')->fetchColumn(),
        'unpaid_invoices' => (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'Unpaid'")->fetchColumn(),
        'payments'        => (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
        'reviews'         => (int) $pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn(),
        'readings'        => (int) $pdo->query('SELECT COUNT(*) FROM telemetry_readings')->fetchColumn(),
    ];

    $users = $pdo->query(
        'SELECT id, full_name, username, role, phone, status FROM users ORDER BY full_name'
    )->fetchAll(PDO::FETCH_ASSOC);

    $alerts = $pdo->query(
        "SELECT alert_code, subject, alert_type, message, status FROM alerts
         WHERE status = 'Not Acknowledged' ORDER BY alert_code DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    respond(['success' => true, 'stats' => $stats, 'users' => $users, 'alerts' => $alerts]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Use GET to read, POST to change.'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$username = trim($body['username'] ?? '');

if ($action === 'acknowledge_alert') {
    $code = trim($body['alert_code'] ?? '');
    if ($code === '') {
        respond(['success' => false, 'message' => 'No alert code was given.'], 400);
    }
    $stmt = $pdo->prepare("UPDATE alerts SET status = 'Acknowledged' WHERE alert_code = ?");
    $stmt->execute([$code]);
    if ($stmt->rowCount() === 0) {
        respond(['success' => false, 'message' => 'There is no alert with code ' . $code . '.'], 404);
    }
    respond(['success' => true, 'message' => 'Alert ' . $code . ' is now acknowledged.']);
}

// set_role and set_status both act on a user account.
if ($username === '') {
    respond(['success' => false, 'message' => 'No username was given.'], 400);
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    respond(['success' => false, 'message' => 'There is no user with username ' . $username . '.'], 404);
}

// The administrator cannot lock themself out of this panel.
if ($user['username'] === $_SESSION['username']) {
    respond(['success' => false, 'message' => 'You cannot change your own role or status — that could lock you out of System Control. Ask another administrator.'], 403);
}

if ($action === 'set_role') {
    $role = trim($body['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        respond(['success' => false, 'message' => 'That is not a role in this system.'], 400);
    }
    $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE username = ?');
    $stmt->execute([$role, $username]);
    $article = in_array(strtolower($role[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    respond(['success' => true, 'message' => $user['full_name'] . ' is now ' . $article . ' ' . $role . '. They will land on the ' . $role . ' pages at their next login.']);
}

if ($action === 'set_status') {
    $status = trim($body['status'] ?? '');
    if (!in_array($status, ['Active', 'Suspended'], true)) {
        respond(['success' => false, 'message' => 'Status must be Active or Suspended.'], 400);
    }
    $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE username = ?');
    $stmt->execute([$status, $username]);
    $note = $status === 'Suspended'
        ? ' They cannot log in until an administrator makes them Active again.'
        : ' They can log in again.';
    respond(['success' => true, 'message' => $user['full_name'] . ' is now ' . $status . '.' . $note]);
}

respond(['success' => false, 'message' => 'Unknown action.'], 400);
