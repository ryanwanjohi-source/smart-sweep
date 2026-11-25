<?php
// ============================================================
//  logout.php — ends the session and returns to the
//  login page. The "Log Out" link on the role pages
//  points here, so logging out really logs out.
// ============================================================

session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: ../login.html');
exit;
