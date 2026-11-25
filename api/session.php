<?php
// ============================================================
//  session.php — who is logged in right now?
//
//  GET session.php -> JSON:
//    { "logged_in": true, "username": "dkamau",
//      "full_name": "David Kamau", "role": "Dispatcher" }
//  or, when nobody is logged in:
//    { "logged_in": false }
//
//  The pages use it to show the logged-in person's name
//  and to guard role pages (see js/guard.js).
// ============================================================

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['logged_in' => false]);
    exit;
}

echo json_encode([
    'logged_in' => true,
    'username'  => $_SESSION['username'],
    'full_name' => $_SESSION['full_name'],
    'role'      => $_SESSION['role'],
]);
