<?php
// import_users.php
// Christmas-themed CSV import page for participants.
//
// - Auth: simple key via ?key=changeme
// - Upload a CSV with columns:
//     first_name,last_name,email,family_unit
// - Inserts each valid row into participants
// - Generates wish_key for new users
// - Skips rows with missing required fields
// - Skips emails that already exist and reports them

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

if (!empty($config['app']['timezone'])) {
    date_default_timezone_set($config['app']['timezone']);
}

// ---------------------------------------------------------
// Simple key auth
// ---------------------------------------------------------
$adminKey = 'changeme'; // adjust to match your admin_secret_santa.php
$providedKey = $_GET['key'] ?? '';

if ($providedKey !== $adminKey) {
    http_response_code(403);
    echo "Forbidden.";
    exit;
}

// ---------------------------------------------------------
// DB connection
// ---------------------------------------------------------
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
    http_response_code(500);
    echo "Database connection failed.";
    error_log("Secret Santa import_users DB error: " . $e->getMessage());
    exit;
}

// ---------------------------------------------------------
// Helper
// ---------------------------------------------------------
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------
// Handle upload + import
// ---------------------------------------------------------
$results = [];
$summary = [
    'processed' => 0,
    'inserted'  => 0,
    'skipped'   => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $results[] = [
            'row'    => '-',
            'name'   => '',
            'email'  => '',
            'status' => 'error',
            'note'   => 'File upload failed. Error code: ' . $file['error'],
        ];
    } else {
        $tmpName = $file['tmp_name'];

        if (!is_uploaded_file($tmpName)) {
            $results[] = [
                'row'    => '-',
                'name'   => '',
                'email'  => '',
                'status' => 'error',
                'note'   => 'Invalid uploaded file.',
            ];
        } else {
            $handle = fopen($tmpName, 'r');
            if ($handle === false) {
                $results[] = [
                    'row'    => '-',
                    'name'   => '',
                    'email'  => '',
                    'status' => 'error',
                    'note'   => 'Unable to open uploaded file.',
                ];
            } else {
                $lineNo       = 0;
                $headerParsed = false;
                $headerMap    = [
                    'first_name'  => null,
                    'last_name'   => null,
                    'email'       => null,
                    'family_unit' => null,
                ];

                // Preload existing emails to skip duplicates
                $existingStmt = $pdo->query("SELECT email FROM participants");
                $existingEmails = array_map('strtolower', array_column($existingStmt->fetchAll(), 'email'));

                while (($row = fgetcsv($handle)) !== false) {
                    $lineNo++;

		    // Skip completely empty lines
		    if (count($row) === 1 && trim((string)($row[0] ?? '')) === '') {
                        continue;
                    }

                    // Detect header on first non-empty line
                    if (!$headerParsed) {
                        $headerParsed = true;

                        // Try to map header columns if they look like "first_name,email" etc.
                        $lower = array_map('strtolower', $row);
                        foreach ($lower as $idx => $col) {
                            if (array_key_exists($col, $headerMap)) {
                                $headerMap[$col] = $idx;
                            }
                        }

                        // If header row is exactly our expected names in order, this is fine.
                        // If not, assume the row is STILL data if we can't find "email".
                        if ($headerMap['email'] === null) {
                            // This row probably isn't a header; treat it as data instead.
                            $headerParsed = true;
                            $lineNo--; // reprocess this line as data
                            // Reset pointer back one line
                            fseek($handle, 0);
                        }

                        // If we successfully mapped, move on to next line
                        if ($lineNo === 1 && $headerMap['email'] !== null) {
                            continue; // skip header row
                        }
                    }


		    $summary['processed']++;

		    // If we don't have a header map, assume fixed order:
		    // first_name,last_name,email,family_unit
	 	    if ($headerMap['email'] === null) {

    			$firstName  = trim((string)($row[0] ?? ''));
    			$lastName   = trim((string)($row[1] ?? ''));
    			$email      = trim((string)($row[2] ?? ''));
    			$familyUnit = trim((string)($row[3] ?? ''));

		    } else {

    			$firstName  = trim((string)($row[$headerMap['first_name']] ?? ''));
    			$lastName   = trim((string)($row[$headerMap['last_name']] ?? ''));
    			$email      = trim((string)($row[$headerMap['email']] ?? ''));
    			$familyUnit = trim((string)($row[$headerMap['family_unit']] ?? ''));
		    }

                    $displayName = trim($firstName . ' ' . $lastName);

                    // Basic validation
                    if ($email === '' || $familyUnit === '' || $firstName === '') {
                        $summary['skipped']++;
                        $results[] = [
                            'row'    => $lineNo,
                            'name'   => $displayName,
                            'email'  => $email,
                            'status' => 'skipped',
                            'note'   => 'Missing required fields (first_name, email, or family_unit).',
                        ];
                        continue;
                    }

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $summary['skipped']++;
                        $results[] = [
                            'row'    => $lineNo,
                            'name'   => $displayName,
                            'email'  => $email,
                            'status' => 'skipped',
                            'note'   => 'Invalid email format.',
                        ];
                        continue;
                    }

                    if (!ctype_digit($familyUnit)) {
                        $summary['skipped']++;
                        $results[] = [
                            'row'    => $lineNo,
                            'name'   => $displayName,
                            'email'  => $email,
                            'status' => 'skipped',
                            'note'   => 'family_unit must be an integer.',
                        ];
                        continue;
                    }

                    // Check duplicate email
                    if (in_array(strtolower($email), $existingEmails, true)) {
                        $summary['skipped']++;
                        $results[] = [
                            'row'    => $lineNo,
                            'name'   => $displayName,
                            'email'  => $email,
                            'status' => 'skipped',
                            'note'   => 'Email already exists in participants; skipped.',
                        ];
                        continue;
                    }

                    // Generate wish_key
                    try {
                        $wishKey = bin2hex(random_bytes(16));
                    } catch (Exception $e) {
                        $wishKey = null;
                    }

                    // Insert
                    try {
                        $insert = $pdo->prepare("
                            INSERT INTO participants (first_name, last_name, email, family_unit, wish_key)
                            VALUES (:first_name, :last_name, :email, :family_unit, :wish_key)
                        ");
                        $insert->execute([
                            ':first_name'  => $firstName,
                            ':last_name'   => $lastName,
                            ':email'       => $email,
                            ':family_unit' => (int)$familyUnit,
                            ':wish_key'    => $wishKey,
                        ]);

                        $summary['inserted']++;
                        $existingEmails[] = strtolower($email);

                        $results[] = [
                            'row'    => $lineNo,
                            'name'   => $displayName,
                            'email'  => $email,
                            'status' => 'inserted',
                            'note'   => 'User added.',
                        ];
                    } catch (PDOException $e) {
                        $summary['skipped']++;
                        $results[] = [
                            'row'    => $lineNo,
                            'name'   => $displayName,
                            'email'  => $email,
                            'status' => 'error',
                            'note'   => 'DB error: ' . $e->getMessage(),
                        ];
                    }
                }

                fclose($handle);
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Secret Santa - Import Participants</title>
<style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: radial-gradient(circle at top, #234 0, #020915 60%, #01030a 100%);
        color: #f5f5f5;
        margin: 0;
        padding: 0;
    }
    .container {
        max-width: 900px;
        margin: 30px auto;
        background: rgba(10, 19, 40, 0.96);
        border-radius: 14px;
        padding: 20px 25px 30px;
        box-shadow: 0 0 20px rgba(0,0,0,0.7);
        border: 1px solid rgba(255,255,255,0.05);
    }
    h1 {
        text-align: center;
        margin-top: 0;
        margin-bottom: 5px;
        color: #ffd700;
        text-shadow: 0 0 8px rgba(255,215,0,0.5);
    }
    .subtitle {
        text-align: center;
        margin-bottom: 20px;
        color: #d0e0ff;
    }
    .panel {
        background: linear-gradient(135deg, rgba(179,0,0,0.9), rgba(0,102,0,0.9));
        border-radius: 10px;
        padding: 15px 18px;
        margin-top: 10px;
    }
    .panel-inner {
        background: rgba(0,0,0,0.3);
        border-radius: 8px;
        padding: 14px 16px 16px;
    }
    label {
        display: block;
        margin: 8px 0 4px;
        font-size: 14px;
    }
    input[type="file"] {
        margin-top: 6px;
        font-size: 14px;
    }
    button {
        margin-top: 12px;
        background: linear-gradient(135deg, #ffd700, #ff6600);
        border: none;
        color: #202020;
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: bold;
    }
    button:hover {
        opacity: 0.92;
    }
    .note {
        font-size: 12px;
        color: #f0f0f0;
        margin-top: 8px;
    }
    .summary {
        margin-top: 10px;
        font-size: 13px;
        color: #e9ffe9;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
        font-size: 13px;
    }
    th, td {
        padding: 6px 8px;
        border: 1px solid rgba(255,255,255,0.1);
    }
    th {
        background: rgba(0,0,0,0.6);
        color: #ffd;
    }
    tr:nth-child(even) td {
        background: rgba(0,0,0,0.3);
    }
    .status-inserted {
        color: #a6ffb2;
        font-weight: bold;
    }
    .status-skipped {
        color: #ffe9a4;
        font-weight: bold;
    }
    .status-error {
        color: #ffb2b2;
        font-weight: bold;
    }
</style>
</head>
<body>
<div class="container">
    <h1>🎄 Import Secret Santa Participants 🎄</h1>
    <div class="subtitle">
        Upload a CSV of your elves, and we'll tuck them neatly into the database.
    </div>

    <div class="panel">
        <div class="panel-inner">
            <h2 style="margin-top:0;color:#ffe9a4;font-size:18px;">CSV Upload</h2>
            <form method="post" enctype="multipart/form-data">
                <label for="csv_file">Choose CSV file:</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>

                <div class="note">
                    Expected columns (header row recommended):<br>
                    <code>first_name,last_name,email,family_unit</code><br>
                    Example:<br>
                    <code>Alice,Wonderland,alice@example.com,1</code>
                </div>

                <button type="submit">Import Participants 🎁</button>
            </form>
        </div>
    </div>

    <?php if (!empty($results)): ?>
        <div class="panel" style="margin-top:20px;">
            <div class="panel-inner">
                <h2 style="margin-top:0;color:#ffe9a4;font-size:18px;">Import Results</h2>
                <div class="summary">
                    Processed: <?php echo (int)$summary['processed']; ?>,
                    Inserted: <?php echo (int)$summary['inserted']; ?>,
                    Skipped: <?php echo (int)$summary['skipped']; ?>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>CSV Line</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $r): ?>
                        <?php
                            $statusClass = 'status-' . $r['status'];
                        ?>
                        <tr>
                            <td><?php echo h($r['row']); ?></td>
                            <td><?php echo h($r['name']); ?></td>
                            <td><?php echo h($r['email']); ?></td>
                            <td class="<?php echo h($statusClass); ?>">
                                <?php echo h(ucfirst($r['status'])); ?>
                            </td>
                            <td><?php echo h($r['note']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

