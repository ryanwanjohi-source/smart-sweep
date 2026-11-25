<?php
// ============================================================
//  Smart Sweep — Routes API (dispatcher allocation).
//
//  This is how the dispatcher allocates routes to drivers.
//
//  GET  routes.php
//       -> { "routes": [ {route_code, name, driver,
//            vehicle, status, stops} ... ],
//            "drivers": [ {username, full_name} ... ],
//            "alerts": [ {alert_code, subject, alert_type,
//            message} ... ] }   (alerts still waiting)
//       The drivers are the active accounts whose role is
//       Collection Driver. The alerts are the ones still
//       waiting for a route to be planned. Any logged-in
//       user may read it.
//
//  POST routes.php
//       { "route_code": "R-05", "driver": "jmwangi" }
//       -> the route's driver is set to that driver's full
//         name, and the route is returned.
//       Only a Dispatcher or the Administrator may do this,
//       and the driver must be a real Collection Driver
//       account — so a route can never be allocated to a
//       resident, an accountant, or a name that does not
//       exist. Anything else is rejected with a reason.
// ============================================================

session_start();
header('Content-Type: application/json');
require __DIR__ . '/../config/db.php';

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Reading or allocating both need a logged-in user.
if (!isset($_SESSION['user_id'])) {
    respond(['success' => false, 'message' => 'Please log in first.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $routes = $pdo->query(
        'SELECT route_code, name, driver, vehicle, status, stops
         FROM routes ORDER BY route_code'
    )->fetchAll(PDO::FETCH_ASSOC);

    $drivers = $pdo->query(
        "SELECT username, full_name FROM users
         WHERE role = 'Collection Driver' AND status = 'Active'
         ORDER BY full_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $alerts = $pdo->query(
        "SELECT alert_code, subject, alert_type, message FROM alerts
         WHERE status = 'Not Acknowledged' ORDER BY alert_code DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    respond(['success' => true, 'routes' => $routes, 'drivers' => $drivers, 'alerts' => $alerts]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Use GET to read routes, POST to allocate one.'], 405);
}

// Only the dispatcher (and the administrator) allocates.
if (!in_array($_SESSION['role'], ['Dispatcher', 'Administrator'], true)) {
    respond(['success' => false, 'message' => 'Only a dispatcher can allocate routes to drivers.'], 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$routeCode = trim($body['route_code'] ?? '');
$driverUsername = trim($body['driver'] ?? '');

if ($routeCode === '' || $driverUsername === '') {
    respond(['success' => false, 'message' => 'Choose a route and a driver.'], 400);
}

$stmt = $pdo->prepare('SELECT * FROM routes WHERE route_code = ?');
$stmt->execute([$routeCode]);
$route = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$route) {
    respond(['success' => false, 'message' => 'There is no route with code ' . $routeCode . '.'], 404);
}

$stmt = $pdo->prepare(
    "SELECT * FROM users WHERE username = ? AND role = 'Collection Driver' AND status = 'Active'"
);
$stmt->execute([$driverUsername]);
$driver = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$driver) {
    respond(['success' => false, 'message' => 'That driver does not exist or is not an active collection driver.'], 404);
}

// The route keeps its vehicle and stops; only the driver changes.
$stmt = $pdo->prepare('UPDATE routes SET driver = ? WHERE route_code = ?');
$stmt->execute([$driver['full_name'], $routeCode]);

$stmt = $pdo->prepare('SELECT route_code, name, driver, vehicle, status, stops FROM routes WHERE route_code = ?');
$stmt->execute([$routeCode]);
respond([
    'success' => true,
    'message' => $routeCode . ' (' . $route['name'] . ') is now allocated to ' . $driver['full_name'] . '.',
    'route'   => $stmt->fetch(PDO::FETCH_ASSOC),
]);
