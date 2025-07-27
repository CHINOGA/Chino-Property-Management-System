<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Security: Check for authenticated admin/manager
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? 'tenant', ['admin', 'manager'])) {
    header('Location: login.php');
    exit;
}

require_once '../config/db.php';
include '../templates/header.php';

$output = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_script'])) {
    ob_start();
    include '../scripts/run_rent_payment_reminder.php';
    $output = ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms'])) {
    require_once '../config/nextsms.php';

    function sendSms($phone, $message) {
        $postData = [
            'token' => NEXTSMS_API_TOKEN,
            'from' => NEXTSMS_SENDER_ID,
            'to' => $phone,
            'message' => $message,
        ];

        $ch = curl_init(NEXTSMS_API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("NextSMS API error: " . $error);
            return false;
        }

        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status'] === 'success') {
            return true;
        } else {
            error_log("NextSMS API response error: " . $response);
            return false;
        }
    }

    $recipients = $_POST['recipients'] ?? [];
    $message = $_POST['message'] ?? '';

    if (!empty($recipients) && !empty($message)) {
        $successCount = 0;
        $failCount = 0;
        foreach ($recipients as $recipient) {
            if (sendSms($recipient, $message)) {
                $successCount++;
            } else {
                $failCount++;
            }
        }
        $output = "SMS sent to $successCount recipients. Failed to send to $failCount recipients.";
    } else {
        $output = "Please select recipients and enter a message.";
    }
}
?>

<h1>Rent Payment Reminders</h1>

<p>Click the button below to manually trigger the rent payment reminder script. This will send SMS and in-app notifications to tenants with upcoming or overdue rent payments.</p>

<form method="post" class="mb-4">
    <button type="submit" name="run_script" class="btn btn-primary">Run Reminder Script</button>
</form>

<?php if ($output): ?>
<div class="alert alert-info">
    <pre><?= htmlspecialchars($output) ?></pre>
</div>
<?php endif; ?>

<hr>

<h2>Send Bulk SMS</h2>
<form method="post" class="mb-4">
    <div class="mb-3">
        <label for="recipients" class="form-label">Recipients</label>
        <select name="recipients[]" id="recipients" class="form-select" multiple required>
            <?php
            $tenantsStmt = $pdo->query("SELECT t.phone, u.username, t.full_name FROM tenants t JOIN users u ON t.user_id = u.id WHERE u.role = 'tenant' AND t.phone IS NOT NULL AND t.phone != ''");
            while ($tenant = $tenantsStmt->fetch(PDO::FETCH_ASSOC)) {
                echo "<option value='{$tenant['phone']}'>{$tenant['full_name']} ({$tenant['username']}) - {$tenant['phone']}</option>";
            }
            ?>
        </select>
    </div>
    <div class="mb-3">
        <label for="message" class="form-label">Message</label>
        <textarea name="message" id="message" class="form-control" rows="5" required></textarea>
    </div>
    <button type="submit" name="send_sms" class="btn btn-success">Send SMS</button>
</form>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#recipients').select2({
        placeholder: 'Select recipients',
        allowClear: true
    });
});
</script>

<?php include '../templates/footer.php'; ?>
