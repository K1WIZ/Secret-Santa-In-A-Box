<?php
// admin_secret_santa.php
// Admin/testing page for Secret Santa pairing.
// - Can run in TEST mode (no DB writes) or COMMIT mode (writes to DB for selected year).
// - Can send a test email using the configured SMTP/Gmail settings.
// - Shows a table of all participants, their wish lists, and email open stats.

require __DIR__ . '/vendor/autoload.php'; // PHPMailer via Composer
$config = require __DIR__ . '/config.php';

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
    die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
}

// -----------------------------------------------------------------
// Minimal "auth" – change this to something safer or integrate with real auth
// Example usage: /admin_secret_santa.php?key=changeme
// -----------------------------------------------------------------
$ADMIN_KEY = $config['app']['admin_key'] ?? 'changeme';
if (!isset($_GET['key']) || $_GET['key'] !== $ADMIN_KEY) {
    http_response_code(403);
    echo "Forbidden. Admin key missing or incorrect.";
    exit;
}

// ------------------------
// Helper: recursive pairing
// ------------------------
/**
 * Recursive backtracking assignment.
 *
 * @param array $givers           List of participants (array of assoc arrays)
 * @param array $receivers        Remaining receivers (id => participant array)
 * @param array $lastYearMap      giver_id => receiver_id from last year
 * @param array $result           giver_id => receiver participant
 * @param int   $index            index into $givers
 * @return bool
 */
function assignPairsRecursive(array $givers, array &$receivers, array $lastYearMap, array &$result, int $index = 0): bool
{
    if ($index >= count($givers)) {
        return true; // all done
    }

    $giver = $givers[$index];

    $receiverKeys = array_keys($receivers);
    shuffle($receiverKeys);

    foreach ($receiverKeys as $rKey) {
        $receiver = $receivers[$rKey];

        // 1) Cannot give to self
        if ($receiver['id'] === $giver['id']) {
            continue;
        }

        // 2) Cannot give to same family unit
        if ($receiver['family_unit'] === $giver['family_unit']) {
            continue;
        }

        // 3) Cannot give to same person as last year (if present in map)
        if (isset($lastYearMap[$giver['id']]) && $lastYearMap[$giver['id']] === $receiver['id']) {
            continue;
        }

        // Choose this receiver
        $result[$giver['id']] = $receiver;
        $chosen = $receiver;
        unset($receivers[$rKey]);

        if (assignPairsRecursive($givers, $receivers, $lastYearMap, $result, $index + 1)) {
            return true;
        }

        // Backtrack
        $receivers[$rKey] = $chosen;
        unset($result[$giver['id']]);
    }

    return false; // no valid choice for this giver
}

// ------------------------
// Helper: create PHPMailer instance
// ------------------------
function createMailer(array $smtpConfig): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $smtpConfig['host'];
    $mail->Port = $smtpConfig['port'];

    if (!empty($smtpConfig['username'])) {
        // Real SMTP (e.g., Gmail) – use auth + STARTTLS
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpConfig['username'];
        $mail->Password   = $smtpConfig['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
    } else {
        // No username configured (e.g., MailHog dev) – no auth, no TLS
        $mail->SMTPAuth    = false;
        $mail->SMTPSecure  = false;
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    return $mail;
}

// ------------------------
// Fetch participants (include wishlist fields)
// ------------------------
$stmt = $pdo->query("
    SELECT
        id, first_name, last_name, email, family_unit,
        wish_item1, wish_item2, wish_item3, wish_key
    FROM participants
    ORDER BY id ASC
");
$participants = $stmt->fetchAll();

if (count($participants) < 2) {
    $error = 'Not enough participants in the database.';
}

// ------------------------
// Handle form submit
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed.');
    }
}

$generatedPairs = [];
$debugMessage   = '';
$error          = $error ?? null;

$defaultYear = (int)date('Y');
$year        = isset($_POST['year']) ? (int)$_POST['year'] : $defaultYear;
if ($year < 2000 || $year > 2100) {
    $year = $defaultYear;
}
$avoidSameAsLastYear = !empty($_POST['avoid_last_year']);
$commitToDb          = !empty($_POST['commit_to_db']);
$action              = $_POST['action'] ?? 'generate';

// Default test email address: SMTP from_email if set
$defaultTestEmail = $config['smtp']['from_email'] ?? '';
$testEmail        = $_POST['test_email'] ?? $defaultTestEmail;

// Utility for HTML escaping
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ------------------------
// ACTION: Add participant
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_participant') {
    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $familyUnit = isset($_POST['family_unit']) ? (int)$_POST['family_unit'] : 0;

    if ($firstName === '' || $email === '') {
        $error = 'First name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        try {
            $wishKey = bin2hex(random_bytes(16));
            $ins = $pdo->prepare("
                INSERT INTO participants (first_name, last_name, email, family_unit, wish_key)
                VALUES (:first_name, :last_name, :email, :family_unit, :wish_key)
            ");
            $ins->execute([
                ':first_name'  => mb_substr($firstName, 0, 100),
                ':last_name'   => mb_substr($lastName, 0, 100),
                ':email'       => mb_substr($email, 0, 255),
                ':family_unit' => $familyUnit,
                ':wish_key'    => $wishKey,
            ]);
            $debugMessage = 'Participant added successfully.';

            // Refresh participants list
            $stmt = $pdo->query("SELECT id, first_name, last_name, email, family_unit, wish_item1, wish_item2, wish_item3, wish_key FROM participants ORDER BY id ASC");
            $participants = $stmt->fetchAll();
        } catch (PDOException $e) {
            $error = 'Failed to add participant: ' . h($e->getMessage());
        }
    }
}

// ------------------------
// ACTION: Resend email
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'resend_email') {
    $pidToResend = isset($_POST['participant_id']) ? (int)$_POST['participant_id'] : 0;
    if ($pidToResend > 0) {
        try {
            // Find assignment for current year
            $pairStmt = $pdo->prepare("SELECT receiver_id FROM secret_santa_pairs WHERE year = :year AND giver_id = :giver_id");
            $pairStmt->execute([':year' => $year, ':giver_id' => $pidToResend]);
            $pair = $pairStmt->fetch();

            if ($pair) {
                // Load giver and receiver details
                $giverStmt = $pdo->prepare("SELECT * FROM participants WHERE id = :id");
                $giverStmt->execute([':id' => $pidToResend]);
                $giver = $giverStmt->fetch();

                $receiverStmt = $pdo->prepare("SELECT * FROM participants WHERE id = :id");
                $receiverStmt->execute([':id' => $pair['receiver_id']]);
                $receiver = $receiverStmt->fetch();

                if ($giver && $receiver && !empty($giver['email'])) {
                    $mailer = createMailer($config['smtp']);
                    $baseUrl = $config['app']['base_url'] ?? '';

                    // We need a way to build the email HTML.
                    // For now, I'll copy the helper from secret_santa.php or implement a shared one.
                    // Let's implement it here as a helper for now.

                    $giverName    = h($giver['first_name'] . ' ' . $giver['last_name']);
                    $receiverName = h($receiver['first_name'] . ' ' . $receiver['last_name']);
                    $wishKey      = urlencode($giver['wish_key']);
                    $wishlistUrl  = rtrim($baseUrl, '/') . '/wishes.php?key=' . $wishKey;
                    $pixelUrl     = rtrim($baseUrl, '/') . '/open.php?pid=' . urlencode($giver['id']) . '&year=' . urlencode((string)$year);
                    $budget       = h($config['app']['budget_limit'] ?? '$50');

                    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Secret Santa $year</title></head>
<body style="margin:0;padding:0;background:#0b1b33;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#b30000,#006600);border-radius:12px;padding:20px;text-align:center;color:#ffffff;">
      <h1 style="font-size:32px;margin:0 0 10px;font-weight:bold;letter-spacing:1px;">🎄 Secret Santa $year 🎄</h1>
      <p style="font-size:16px;margin:10px 0 20px;">Hi <strong>{$giverName}</strong>!<br>The elves have spoken...</p>
      <div style="background:#ffffff;border-radius:10px;padding:30px;margin:0 auto;max-width:480px;">
        <p style="font-size:14px;color:#444;margin:0 0 10px;">Your Secret Santa person is:</p>
        <div style="font-size:30px;font-weight:bold;color:#b30000;margin:10px 0 10px;">{$receiverName}</div>
        <p style="font-size:14px;color:#333;margin:15px 0 5px;font-weight:bold;">💰 Budget Limit: {$budget}</p>
        <p style="font-size:13px;color:#444;margin:10px 0;">✅ View <strong>{$receiverName}</strong>'s wish list:<br>
          <a href="{$wishlistUrl}#recipient" style="color:#006600;font-weight:bold;">View Their Wishes</a></p>
        <p style="font-size:13px;color:#444;margin:10px 0;">✏️ Enter or update <strong>your own</strong> wish list:<br>
          <a href="{$wishlistUrl}#mine" style="color:#b30000;font-weight:bold;">Enter My Wishes</a></p>
      </div>
      <img src="{$pixelUrl}" alt="" width="1" height="1" style="display:none;opacity:0;">
    </div>
  </div>
</body>
</html>
HTML;

                    $mail = clone $mailer;
                    $mail->clearAllRecipients();
                    $mail->addAddress($giver['email'], $giverName);
                    $mail->Subject = "Your Secret Santa Person for $year 🎄 (Resend)";
                    $mail->Body    = $htmlBody;
                    $mail->AltBody = "Hi {$giver['first_name']},\n\nYour Secret Santa person for $year is {$receiver['first_name']} {$receiver['last_name']}.\n\nBudget Limit: {$budget}\n\nView wishes here: $wishlistUrl";

                    $mail->send();
                    $debugMessage = 'Assignment email resent to <strong>' . h($giver['email']) . '</strong>.';
                } else {
                    $error = 'Could not find giver/receiver or email is missing.';
                }
            } else {
                $error = 'No assignment found for this participant in ' . h($year) . '. Generate pairings first!';
            }
        } catch (Exception $e) {
            $error = 'Failed to resend email: ' . h($e->getMessage());
        }
    }
}

// ------------------------
// ACTION: Delete participant
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_participant') {
    $pidToDelete = isset($_POST['participant_id']) ? (int)$_POST['participant_id'] : 0;
    if ($pidToDelete > 0) {
        try {
            $pdo->beginTransaction();

            // Delete pairings where this participant is giver or receiver
            $delPairs = $pdo->prepare("DELETE FROM secret_santa_pairs WHERE giver_id = :id OR receiver_id = :id");
            $delPairs->execute([':id' => $pidToDelete]);

            // Delete open stats
            $delOpens = $pdo->prepare("DELETE FROM email_opens WHERE participant_id = :id");
            $delOpens->execute([':id' => $pidToDelete]);

            // Delete participant
            $delPart = $pdo->prepare("DELETE FROM participants WHERE id = :id");
            $delPart->execute([':id' => $pidToDelete]);

            $pdo->commit();
            $debugMessage = 'Participant and their associated data deleted.';

            // Refresh participants list
            $stmt = $pdo->query("SELECT id, first_name, last_name, email, family_unit, wish_item1, wish_item2, wish_item3, wish_key FROM participants ORDER BY id ASC");
            $participants = $stmt->fetchAll();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Failed to delete participant: ' . h($e->getMessage());
        }
    }
}

// ------------------------
// ACTION: Send test email
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_email') {
    if (empty($testEmail)) {
        $error = 'Please specify a test email address.';
    } else {
        try {
            $mailer = createMailer($GLOBALS['config']['smtp']);

            $mail = clone $mailer;
            $mail->clearAllRecipients();
            $mail->addAddress($testEmail);

            $mail->Subject = 'Secret Santa SMTP Test 🎄';
            $yearNow = date('Y');

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Secret Santa SMTP Test</title>
</head>
<body style="margin:0;padding:0;background:#0b1b33;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#b30000,#006600);border-radius:12px;padding:20px;text-align:center;color:#ffffff;">
      <h1 style="font-size:32px;margin:0 0 10px;font-weight:bold;letter-spacing:1px;">
        🎄 Secret Santa SMTP Test 🎄
      </h1>
      <p style="font-size:16px;margin:10px 0 20px;">
        This is a <strong>test email</strong> from your Secret Santa application for {$yearNow}.
      </p>
      <div style="background:#ffffff;border-radius:10px;padding:20px;margin:0 auto;max-width:480px;">
        <p style="font-size:14px;color:#444;margin:0 0 10px;">
          If you can read this, your Gmail/SMTP configuration is working!
        </p>
        <p style="font-size:13px;color:#666;margin:10px 0 0;">
          When the real drawing runs, participants will receive similarly styled emails
          with the name of their Secret Santa recipient in a big festive font.
        </p>
      </div>
      <p style="font-size:11px;color:#f0f0f0;margin-top:15px;">
        (You triggered this test from the Secret Santa admin page.)
      </p>
    </div>
  </div>
</body>
</html>
HTML;

            $mail->Body    = $htmlBody;
            $mail->AltBody = "Secret Santa SMTP Test.\n\nIf you received this, SMTP is working.";

            $mail->send();
            $debugMessage = 'Test email sent successfully to <strong>' . h($testEmail) . '</strong>.';
        } catch (Exception $e) {
            $error = 'Failed to send test email: ' . h($e->getMessage());
        }
    }
}

// ------------------------
// ACTION: Generate (and optionally commit) pairings
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate' && empty($error)) {

    // Build giver/receiver arrays
    $givers    = $participants;
    $receivers = [];
    foreach ($participants as $p) {
        $receivers[] = $p;
    }

    // Load last year's pairs if requested
    $lastYearMap = [];
    if ($avoidSameAsLastYear && $year > 0) {
        $lastYear = $year - 1;
        $stmtLY = $pdo->prepare("SELECT giver_id, receiver_id FROM secret_santa_pairs WHERE year = :year");
        $stmtLY->execute([':year' => $lastYear]);

        while ($row = $stmtLY->fetch()) {
            $lastYearMap[$row['giver_id']] = $row['receiver_id'];
        }
    }

    $resultPairs = []; // giver_id => receiver participant
    $success = assignPairsRecursive($givers, $receivers, $lastYearMap, $resultPairs);

    if ($success) {
        // Build display-friendly structure
        $generatedPairs = [];
        foreach ($participants as $giver) {
            $giverId  = $giver['id'];
            $receiver = $resultPairs[$giverId];
            $generatedPairs[] = [
                'giver_id'        => $giverId,
                'giver_name'      => $giver['first_name'] . ' ' . $giver['last_name'],
                'giver_family'    => $giver['family_unit'],
                'receiver_id'     => $receiver['id'],
                'receiver_name'   => $receiver['first_name'] . ' ' . $receiver['last_name'],
                'receiver_family' => $receiver['family_unit'],
                'same_as_last'    => (isset($lastYearMap[$giverId]) && $lastYearMap[$giverId] === $receiver['id']),
            ];
        }

        // If commit option is ON, write to DB for the selected year
        if ($commitToDb) {
            try {
                $pdo->beginTransaction();

                // Remove any existing pairings for that year (so you can re-test)
                $del = $pdo->prepare("DELETE FROM secret_santa_pairs WHERE year = :year");
                $del->execute([':year' => $year]);

                $ins = $pdo->prepare("
                    INSERT INTO secret_santa_pairs (year, giver_id, receiver_id)
                    VALUES (:year, :giver_id, :receiver_id)
                ");

                foreach ($resultPairs as $giverId => $receiver) {
                    $ins->execute([
                        ':year'       => $year,
                        ':giver_id'   => $giverId,
                        ':receiver_id'=> $receiver['id'],
                    ]);
                }

                $pdo->commit();
                $debugMessage = "Pairing generated and <strong>saved to DB</strong> for year {$year}. "
                    . "You can now test 'avoid last year' using year " . ($year + 1) . ".";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Failed to save pairing to DB: ' . h($e->getMessage());
            }
        } else {
            $debugMessage = 'Pairing generated in <strong>TEST MODE</strong>. '
                . 'No DB writes or emails were performed.';
        }
    } else {
        $error = 'Failed to find a valid pairing with the current constraints. '
               . 'Try relaxing the "avoid last year" option or double-check family units.';
    }
}

// ------------------------
// Fetch email open stats for selected year
// ------------------------
$openStats = []; // participant_id => ['first_opened_at'=>..., 'last_opened_at'=>..., 'open_count'=>...]
try {
    $stmtOpen = $pdo->prepare("
        SELECT participant_id, first_opened_at, last_opened_at, open_count
        FROM email_opens
        WHERE year = :year
    ");
    $stmtOpen->execute([':year' => $year]);
    while ($row = $stmtOpen->fetch()) {
        $openStats[$row['participant_id']] = $row;
    }
} catch (PDOException $e) {
    // If this fails, we just won't show open stats (no fatal error)
    // You could log this if you want:
    // error_log('Failed to fetch email open stats: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Secret Santa Admin</title>
<style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: #0b1b33;
        color: #f5f5f5;
        margin: 0;
        padding: 0;
    }
    .container {
        max-width: 1100px;
        margin: 30px auto;
        background: #1b2b4d;
        border-radius: 12px;
        padding: 20px 25px 30px;
        box-shadow: 0 0 12px rgba(0,0,0,0.5);
    }
    h1 {
        text-align: center;
        margin-top: 0;
        margin-bottom: 10px;
        color: #ffd700;
    }
    .subtitle {
        text-align: center;
        margin-bottom: 25px;
        color: #d0e0ff;
    }
    form {
        margin-bottom: 25px;
        background: #12203b;
        border-radius: 10px;
        padding: 15px 20px;
    }
    label {
        display: inline-block;
        margin: 5px 0;
    }
    input[type="number"],
    input[type="email"],
    input[type="text"] {
        padding: 5px 8px;
        border-radius: 4px;
        border: 1px solid #4a6bb8;
        background: #0b1427;
        color: #f5f5f5;
    }
    input[type="number"] {
        width: 100px;
    }
    input[type="email"] {
        width: 260px;
    }
    input[type="checkbox"] {
        transform: scale(1.1);
        margin-right: 5px;
    }
    button {
        background: linear-gradient(135deg, #b30000, #006600);
        border: none;
        color: #fff;
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        margin-top: 10px;
        margin-right: 8px;
    }
    button:hover {
        opacity: 0.9;
    }
    .msg {
        margin-bottom: 15px;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 14px;
    }
    .msg.error {
        background: #661212;
        color: #ffd0d0;
    }
    .msg.ok {
        background: #124b2a;
        color: #d0ffd8;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    th, td {
        padding: 6px 8px;
        border: 1px solid #32456f;
        font-size: 13px;
        vertical-align: top;
    }
    th {
        background: #243a6f;
    }
    tr:nth-child(even) {
        background: #19274a;
    }
    tr:nth-child(odd) {
        background: #111b34;
    }
    .badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 11px;
    }
    .badge-ok {
        background: #185c2f;
        color: #c8ffd5;
    }
    .badge-warn {
        background: #7a261e;
        color: #ffe0d0;
    }
    .footer-note {
        margin-top: 15px;
        font-size: 11px;
        color: #9fb5ff;
    }
    .warning-text {
        font-size: 12px;
        color: #ffdddd;
        margin-top: 5px;
    }
    .section-title {
        font-weight: bold;
        margin-top: 5px;
        margin-bottom: 5px;
        color: #ffe9a4;
    }
    .section-divider {
        border-top: 1px solid #32456f;
        margin: 15px 0;
    }
    h2 {
        margin-top: 25px;
        margin-bottom: 8px;
        color: #ffd700;
    }
    .small-note {
        font-size: 11px;
        color: #cbd5ff;
        margin-top: 6px;
    }
</style>
</head>
<body>
<div class="container">
    <h1>🎄 Secret Santa Admin 🎄</h1>
    <div class="subtitle">
        Generate Secret Santa pairings, commit them to the database, test SMTP email delivery,<br>
        and view everyone’s wish lists and open stats for the selected year.
    </div>

    <?php if (!empty($error)): ?>
        <div class="msg error"><?php echo $error; ?></div>
    <?php elseif (!empty($debugMessage)): ?>
        <div class="msg ok"><?php echo $debugMessage; ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <div class="section-title">Pairing Options</div>

        <div>
            <label for="year">Target year:&nbsp;</label>
            <input type="number" name="year" id="year" value="<?php echo h($year); ?>">
        </div>
        <div style="margin-top:10px;">
            <label>Budget Limit: <strong><?php echo h($config['app']['budget_limit'] ?? '$50'); ?></strong></label>
            <span class="small-note">(Change this via <code>BUDGET_LIMIT</code> environment variable)</span>
        </div>
        <div style="margin-top:10px;">
            <label>
                <input type="checkbox" name="avoid_last_year" <?php echo $avoidSameAsLastYear ? 'checked' : ''; ?>>
                Avoid assigning the same recipient as last year (if last year&rsquo;s data exists)
            </label>
        </div>
        <div style="margin-top:10px;">
            <label>
                <input type="checkbox" name="commit_to_db" <?php echo $commitToDb ? 'checked' : ''; ?>>
                <strong>Commit this pairing to the database for the selected year</strong>
            </label>
            <div class="warning-text">
                Warning: This will <strong>delete any existing pairings</strong> for that year
                from <code>secret_santa_pairs</code> and replace them with this result.
            </div>
        </div>

        <div class="section-divider"></div>

        <div class="section-title">Email Test</div>
        <div>
            <label for="test_email">Send a test email to:&nbsp;</label>
            <input type="email" name="test_email" id="test_email"
                   value="<?php echo h($testEmail); ?>">
        </div>
        <div class="warning-text">
            This sends a <strong>single test email</strong> using your Gmail/SMTP config.
            It does not send any Secret Santa assignments.
        </div>

        <div style="margin-top:15px;">
            <button type="submit" name="action" value="generate">Generate Pairings</button>
            <button type="submit" name="action" value="test_email">Send Test Email</button>
        </div>
    </form>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <div class="section-title">Add Single Participant</div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items: flex-end;">
            <div>
                <label for="first_name">First Name</label><br>
                <input type="text" name="first_name" id="first_name" required style="width:140px;">
            </div>
            <div>
                <label for="last_name">Last Name</label><br>
                <input type="text" name="last_name" id="last_name" style="width:140px;">
            </div>
            <div>
                <label for="email">Email</label><br>
                <input type="email" name="email" id="email" required style="width:200px;">
            </div>
            <div>
                <label for="family_unit">Family Unit</label><br>
                <input type="number" name="family_unit" id="family_unit" value="1" required style="width:80px;">
            </div>
            <div>
                <button type="submit" name="action" value="add_participant" style="margin:0;">Add Elf 🎁</button>
            </div>
        </div>
        <div class="small-note">
            Need to add many? Use the <a href="import_users.php?key=<?php echo h($ADMIN_KEY); ?>" style="color:#ffd700;">CSV Import</a>.
        </div>
    </form>

    <?php if (!empty($generatedPairs)): ?>
        <h2>Generated Pairings for <?php echo h($year); ?></h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Giver</th>
                    <th>Giver Family</th>
                    <th>Receiver</th>
                    <th>Receiver Family</th>
                    <th>Same as Last Year?</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($generatedPairs as $idx => $row): ?>
                <tr>
                    <td><?php echo h($idx + 1); ?></td>
                    <td><?php echo h($row['giver_name']); ?></td>
                    <td><?php echo h($row['giver_family']); ?></td>
                    <td><?php echo h($row['receiver_name']); ?></td>
                    <td><?php echo h($row['receiver_family']); ?></td>
                    <td>
                        <?php if ($row['same_as_last']): ?>
                            <span class="badge badge-warn">YES</span>
                        <?php else: ?>
                            <span class="badge badge-ok">No</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="footer-note">
            To test the "avoid last year" logic end-to-end, you can:
            <ol>
                <li>Generate &amp; <strong>commit</strong> pairings for year <code>X</code>.</li>
                <li>Then, generate pairings for year <code>X+1</code> with
                    "avoid last year" checked and verify no giver has the same recipient twice.</li>
            </ol>
        </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate' && empty($error)): ?>
        <div class="footer-note">No pairings were generated.</div>
    <?php endif; ?>

    <!-- Wishes & open stats table -->
    <h2>All Participants, Wish Lists & Open Stats (Year <?php echo h($year); ?>)</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Email</th>
                <th>Family Unit</th>
                <th>Wishlist Filled?</th>
                <th>First Open</th>
                <th>Last Open</th>
                <th>Opens</th>
                <th>Manage</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($participants as $idx => $p): ?>
            <?php
                $stats = $openStats[$p['id']] ?? null;
                $firstOpen = $stats['first_opened_at'] ?? '';
                $lastOpen  = $stats['last_opened_at'] ?? '';
                $openCount = $stats['open_count'] ?? 0;
            ?>
            <tr>
                <td><?php echo h($idx + 1); ?></td>
                <td><?php echo h($p['first_name'] . ' ' . $p['last_name']); ?></td>
                <td><?php echo h($p['email']); ?></td>
                <td><?php echo h($p['family_unit']); ?></td>
                <td>
                    <?php if ($p['wish_item1'] || $p['wish_item2'] || $p['wish_item3']): ?>
                        <span class="badge badge-ok">Yes</span>
                    <?php else: ?>
                        <span class="badge badge-warn">No</span>
                    <?php endif; ?>
                </td>
                <td><?php echo h($firstOpen); ?></td>
                <td><?php echo h($lastOpen); ?></td>
                <td><?php echo h($openCount); ?></td>
                <td style="white-space:nowrap;">
                    <form method="post" style="padding:0;background:none;margin:0;display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="participant_id" value="<?php echo $p['id']; ?>">
                        <button type="submit" name="action" value="resend_email"
                                style="background:#006600;padding:4px 8px;font-size:11px;margin:0;">
                            Resend
                        </button>
                    </form>
                    <form method="post" onsubmit="return confirm('Really delete this participant? This will also clear their pairings.');" style="padding:0;background:none;margin:0;display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="participant_id" value="<?php echo $p['id']; ?>">
                        <button type="submit" name="action" value="delete_participant"
                                style="background:#b30000;padding:4px 8px;font-size:11px;margin:0;">
                            Delete
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="small-note">
        Open stats are based on the tracking pixel in this year's Secret Santa emails and may be affected by
        whether recipients’ mail clients load remote images.
    </div>
</div>
</body>
</html>

