<?php
// ============================================================
//  db.php — the one database connection for the login,
//  session, routes and admin APIs.
//
//  Same connection as the payments and reviews APIs:
//  MySQL on localhost, database smart_sweep, root with no
//  password (the XAMPP default). Any API that needs the
//  database includes this file and uses $pdo.
// ============================================================

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=smart_sweep;charset=utf8mb4",
        "root",
        "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'The system cannot reach the database. Is XAMPP MySQL running?',
    ]);
    exit;
}
