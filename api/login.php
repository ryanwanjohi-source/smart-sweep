<?php
// ============================================================
//  Smart Sweep — Role login.
//
//  The login form (login.html) posts the username and
//  password here. PHP looks the user up in the users table
//  and checks the password against the stored hash with
//  password_verify — the password is never part of the
//  query, and a wrong password gives nothing away.
//
//  On success a session is started and the user is sent
//  to the home page of their own role, because each role
//  works in a different place:
//
//    Administrator     -> admin.html       (System Control)
//    Dispatcher        -> dispatcher.html (Routes & Drivers)
//    Collection Driver -> routes.html      (their routes)
//    Accountant        -> reports.html     (money)
//    Resident          -> dashboard.html   (their bins & bills)
//
//  The role comes from the account in the database — the
//  login form no longer asks the user to pick a role, so
//  nobody can walk into another role's pages by choosing
//  it on the form. A suspended account cannot log in at
//  all; an account with an unpaid invoice still can, so
//  the resident can reach the Pay Invoice page.
//
//  On failure the user goes back to login.html with
//  ?error=1 (wrong username or password) or ?error=2
//  (this account has no password set yet — the
//  administrator sets it), and the page shows the reason.
// ============================================================

session_start();
require __DIR__ . '/../config/db.php';

// Where each role lands after logging in.
$roleHome = [
    'Administrator'     => '../admin.html',
    'Dispatcher'        => '../dispatcher.html',
    'Collection Driver' => '../routes.html',
    'Accountant'        => '../reports.html',
    'Resident'          => '../dashboard.html',
];

// Already logged in? Skip the form and go home.
if (isset($_SESSION['user_id'])) {
    $home = $roleHome[$_SESSION['role']] ?? '../dashboard.html';
    header('Location: ' . $home);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.html');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header('Location: ../login.html?error=1');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: ../login.html?error=1');
    exit;
}

// Suspended accounts are locked out entirely.
if ($user['status'] === 'Suspended') {
    header('Location: ../login.html?error=3');
    exit;
}

// No password set on this account yet (the role-login
// migration has not been run on this database).
if (empty($user['password_hash'])) {
    header('Location: ../login.html?error=2');
    exit;
}

if (!password_verify($password, $user['password_hash'])) {
    header('Location: ../login.html?error=1');
    exit;
}

// Correct: remember who is logged in, then send them to
// the home page of their role.
session_regenerate_id(true);
$_SESSION['user_id']   = $user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role']      = $user['role'];

$home = $roleHome[$user['role']] ?? '../dashboard.html';
header('Location: ' . $home);
exit;
