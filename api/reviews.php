<?php
/**
 * Smart Sweep — Resident Reviews API
 * ---------------------------------------------------------------------------
 * Where residents send their reviews of the service.
 *
 *   GET   api/reviews.php
 *         → the most recent reviews, the total count and the average rating.
 *
 *   POST  api/reviews.php        (JSON body)
 *         {"resident_name": "Faith Njeri", "estate": "Ruaka",
 *          "rating": 5, "message": "The bins are emptied on time..."}
 *         → saves the review and returns it.
 *
 * Ratings are 1 to 5. Reviews are public inside the system: what one
 * resident writes, the office and other residents can read — that is
 * what makes them useful.
 */

header('Content-Type: application/json');

function db(): PDO
{
    $pdo = new PDO(
        'mysql:host=localhost;dbname=smart_sweep;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function bodyJson(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'];

    /* ------------------------- read the reviews ------------------------- */
    if ($method === 'GET') {
        $rows = $pdo->query(
            'SELECT resident_name, estate, rating, message, review_date
               FROM reviews ORDER BY id DESC LIMIT 20'
        )->fetchAll(PDO::FETCH_ASSOC);

        $stats = $pdo->query(
            'SELECT COUNT(*) AS total, AVG(rating) AS average FROM reviews'
        )->fetch(PDO::FETCH_ASSOC);

        $reviews = array_map(fn($r) => [
            'resident' => $r['resident_name'],
            'estate'   => $r['estate'],
            'rating'   => (int) $r['rating'],
            'message'  => $r['message'],
            'date'     => $r['review_date'],
        ], $rows);

        out([
            'success' => true,
            'total'   => (int) $stats['total'],
            'average' => $stats['average'] !== null ? round((float) $stats['average'], 1) : null,
            'reviews' => $reviews,
        ]);
    }

    /* ------------------------- send a review ---------------------------- */
    if ($method === 'POST') {
        $in = bodyJson();
        $name    = trim($in['resident_name'] ?? '');
        $estate  = trim($in['estate'] ?? '');
        $rating  = (int) ($in['rating'] ?? 0);
        $message = trim($in['message'] ?? '');

        if ($name === '' || $estate === '') {
            out(['success' => false, 'message' => 'Tell us your name and your estate, so the review carries its weight.'], 422);
        }
        if ($rating < 1 || $rating > 5) {
            out(['success' => false, 'message' => 'Give a rating from 1 to 5.'], 422);
        }
        if (strlen($message) < 5) {
            out(['success' => false, 'message' => 'Write a few words in the review — what was good, or what should improve.'], 422);
        }

        $date = date('j M Y');
        $stmt = $pdo->prepare(
            'INSERT INTO reviews (resident_name, estate, rating, message, review_date)
             VALUES (:n, :e, :r, :m, :d)'
        );
        $stmt->execute([':n' => $name, ':e' => $estate, ':r' => $rating, ':m' => $message, ':d' => $date]);

        out([
            'success' => true,
            'message' => 'Thank you, ' . $name . '. Your review has been received.',
            'review'  => [
                'resident' => $name, 'estate' => $estate, 'rating' => $rating,
                'message' => $message, 'date' => $date,
            ],
        ], 201);
    }

    out(['success' => false, 'message' => 'Use GET to read reviews or POST to send one.'], 405);

} catch (Throwable $e) {
    out(['success' => false, 'message' => 'The reviews service hit a problem: ' . $e->getMessage()], 500);
}
