<?php
// open.php
// 1x1 tracking pixel to log when a Secret Santa email is opened.
// Usage from email: <img src=".../open.php?pid=###&year=YYYY" ...>

$config = require __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // If DB fails, just return the pixel; don't break the email.
}

// Get inputs
$pid  = isset($_GET['pid'])  ? (int)$_GET['pid']  : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($pid > 0 && $year > 0 && isset($pdo)) {
    try {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Insert or update
        $sql = "
            INSERT INTO email_opens (participant_id, year, first_opened_at, last_opened_at, open_count, last_ip, last_user_agent)
            VALUES (:pid, :year, NOW(), NOW(), 1, :ip, :ua)
            ON DUPLICATE KEY UPDATE
                last_opened_at = NOW(),
                open_count = open_count + 1,
                last_ip = :ip,
                last_user_agent = :ua
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':pid'  => $pid,
            ':year' => $year,
            ':ip'   => $ip,
            ':ua'   => $ua,
        ]);
    } catch (PDOException $e) {
        // Swallow logging errors; pixel still returns
    }
}

// Return a 1x1 transparent PNG
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 1x1 transparent PNG bytes
echo base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg=='
);
exit;

