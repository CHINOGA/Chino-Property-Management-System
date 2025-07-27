<?php
// Script to send rent payment reminders via SMS and notifications

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/nextsms.php';

// Function to send SMS via NextSMS API
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

// Get current date and date 7 days from now for upcoming rent reminders
$today = new DateTime();
$upcomingDate = (new DateTime())->modify('+7 days');

// Fetch leases with rent due within next 7 days or overdue (no payment or last payment older than due date)
try {
    // Get all leases with tenant info and user phone/email
    $stmt = $pdo->query("
        SELECT leases.id AS lease_id, leases.tenant_id, leases.unit_id, units.rent_amount AS rent, leases.lease_start, leases.lease_end,
               tenants.user_id, tenants.full_name, tenants.phone,
               users.username, users.email
        FROM leases
        JOIN tenants ON leases.tenant_id = tenants.id
        JOIN users ON tenants.user_id = users.id
        JOIN units ON leases.unit_id = units.id
    ");
    $leases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get last payment date for each lease
    $lastPayments = [];
    $stmtPayments = $pdo->query("
        SELECT lease_id, MAX(payment_date) AS last_payment_date
        FROM payments
        WHERE status = 'completed'
        GROUP BY lease_id
    ");
    foreach ($stmtPayments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $lastPayments[$payment['lease_id']] = new DateTime($payment['last_payment_date']);
    }

    // Get manager and admin users for notifications
    $stmtUsers = $pdo->prepare("SELECT id, username, email FROM users WHERE role IN ('manager', 'admin')");
    $stmtUsers->execute();
    $managersAdmins = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

    foreach ($leases as $lease) {
        $leaseEnd = new DateTime($lease['lease_end']);
        $tenantPhone = $lease['phone'];
        $tenantUserId = $lease['user_id'];
        $tenantName = $lease['full_name'];
        $leaseId = $lease['lease_id'];

        // Determine if rent is due soon or overdue
        $lastPaymentDate = $lastPayments[$leaseId] ?? null;
        $rentDueSoon = $leaseEnd >= $today && $leaseEnd <= $upcomingDate;
        $rentOverdue = $lastPaymentDate === null || $lastPaymentDate < $leaseEnd;

        if ($rentDueSoon || $rentOverdue) {
            $message = "Dear $tenantName, your rent payment for unit ID {$lease['unit_id']} is " .
                       ($rentOverdue ? "overdue." : "due soon on " . $leaseEnd->format('Y-m-d') . ".") .
                       " Please make the payment promptly.";

            // Insert notification for tenant
            $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, type, message, created_at) VALUES (?, 'rent_reminder', ?, NOW())");
            $stmtNotif->execute([$tenantUserId, $message]);

            // Send SMS to tenant
            if ($tenantPhone) {
                sendSms($tenantPhone, $message);
            }

            // Notify managers and admins
            foreach ($managersAdmins as $user) {
                $adminMessage = "Reminder: Tenant $tenantName has a " .
                                ($rentOverdue ? "overdue" : "due soon") .
                                " rent payment for unit ID {$lease['unit_id']}.";
                $stmtNotif->execute([$user['id'], $adminMessage]);

                // Assuming manager/admin phone numbers are stored in users table or elsewhere
                // For now, send SMS only if phone is available (not shown in schema)
                // You may need to extend users table to include phone numbers for managers/admins
            }
        }
    }
} catch (Exception $e) {
    error_log("Rent payment reminder error: " . $e->getMessage());
    exit(1);
}

echo "Rent payment reminders processed.\n";
?>
