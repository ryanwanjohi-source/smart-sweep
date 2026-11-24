<?php
// ============================================================
//  Smart Sweep — Telemetry System
//
//  This program is the telemetry system for the smart bins.
//  In the real system, an ultrasonic sensor inside each bin
//  measures the distance to the garbage, works out how full
//  the bin is, and sends the reading to the database.
//
//  This program simulates those sensor readings, so the whole
//  system can be demonstrated without the hardware. Every
//  cycle it does four things:
//
//    1. Each online bin "fills up" a little (a new reading).
//    2. The reading is saved: the bins table is updated and
//       the reading is logged in telemetry_readings.
//    3. When a bin reaches 80% full, an alert is raised in
//       the alerts table — that is the system telling the
//       collection team the bin must be emptied.
//    4. When a bin is completely full, a collection is
//       simulated: the bin is emptied and its open alert is
//       marked acknowledged.
//
//  HOW TO RUN (XAMPP MySQL must be running):
//    C:\xampp\php\php.exe telemetry.php       runs until Ctrl+C
//    C:\xampp\php\php.exe telemetry.php 12    runs 12 cycles only
// ============================================================

// ---- Connect to the smart_sweep database ----
$pdo = new PDO(
    "mysql:host=localhost;dbname=smart_sweep;charset=utf8mb4",
    "root",
    "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// How many cycles to run: 0 means run forever.
$maxCycles = isset($argv[1]) ? (int) $argv[1] : 0;
$wait = ($maxCycles > 0) ? 1 : 5;   // seconds between cycles

echo "Smart Sweep telemetry system started.\n";
echo "Watching the bins — an alert is raised at 80% full.\n\n";

$cycle = 0;
while (true) {
    $cycle++;

    // Read every bin that is online.
    $bins = $pdo->query(
        "SELECT * FROM bins WHERE status <> 'Offline' ORDER BY bin_code"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bins as $bin) {
        $code = $bin['bin_code'];
        $fill = (int) $bin['fill_level'];

        if ($fill >= 98) {
            // The bin is completely full: simulate a collection.
            // The truck empties it, so the level drops, and the
            // open alert for the bin is marked acknowledged.
            $fill = rand(5, 15);
            $pdo->prepare(
                "UPDATE alerts SET status = 'Acknowledged'
                 WHERE subject LIKE ? AND status = 'Not Acknowledged'"
            )->execute([$code . '%']);
            echo "$code was collected and emptied (now $fill%).\n";
        } else {
            // A new sensor reading: the bin has filled up a bit.
            $fill = min(100, $fill + rand(0, 6));
        }

        // The weight grows with the fill level.
        $weight = round($fill * 1.6, 1);

        // Work out the bin's status from its fill level.
        if ($fill >= 80) {
            $status = 'Full';
        } elseif ($fill >= 60) {
            $status = 'Near Full';
        } else {
            $status = 'Normal';
        }

        // Save the reading: update the bin and log the reading.
        $pdo->prepare(
            "UPDATE bins SET fill_level = ?, weight_kg = ?, status = ?
             WHERE bin_code = ?"
        )->execute([$fill, $weight, $status, $code]);

        $pdo->prepare(
            "INSERT INTO telemetry_readings (bin_code, fill_level, weight_kg)
             VALUES (?, ?, ?)"
        )->execute([$code, $fill, $weight]);

        echo str_pad($code, 9) . str_pad($fill . '%', 6) . $status . "\n";

        // ---- Send an alert when the bin is full ----
        if ($status === 'Full') {
            // Only raise one open alert per bin.
            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM alerts
                 WHERE subject LIKE ? AND alert_type = 'Fill Level'
                   AND status = 'Not Acknowledged'"
            );
            $check->execute([$code . '%']);

            if ($check->fetchColumn() == 0) {
                // The next alert code: AL-1043, AL-1044, ...
                $next = (int) $pdo->query(
                    "SELECT MAX(CAST(SUBSTRING(alert_code, 4) AS UNSIGNED)) FROM alerts"
                )->fetchColumn() + 1;
                $alertCode = 'AL-' . $next;

                $area = explode(',', $bin['location'])[0];
                $pdo->prepare(
                    "INSERT INTO alerts (alert_code, subject, alert_type, message, status)
                     VALUES (?, ?, 'Fill Level', ?, 'Not Acknowledged')"
                )->execute([
                    $alertCode,
                    $code . ' — ' . $area,
                    $code . ' reached ' . $fill . '% — fill-level alert',
                ]);

                echo "*** ALERT SENT: $alertCode — $code reached $fill%, collection needed ***\n";
            }
        }
    }

    echo "--- cycle $cycle complete ---\n\n";

    if ($maxCycles > 0 && $cycle >= $maxCycles) {
        break;
    }
    sleep($wait);
}

echo "Telemetry system stopped after $cycle cycles.\n";
