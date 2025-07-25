<?php
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';  // Adjust path as needed
include '../templates/header.php';

$user = $_SESSION['user']['username'];
$role = $_SESSION['user']['role'];

// Only admin or manager allowed
if (!in_array($role, ['admin', 'manager'])) {
    echo "<div class='alert alert-danger'>Access denied.</div>";
    include '../templates/footer.php';
    exit;
}

// Handle new payment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenant_id = $_POST['tenant_id'] ?? null;
    $amount = $_POST['amount'] ?? null;
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');

    if ($tenant_id && $amount && is_numeric($amount) && $amount > 0) {
        try {
            $stmt = $pdo->prepare("INSERT INTO rent_payments (tenant_id, amount, payment_date, collected_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$tenant_id, $amount, $payment_date, $user]);
            echo "<div class='alert alert-success'>Rent payment recorded successfully.</div>";
        } catch (PDOException $e) {
            echo "<div class='alert alert-danger'>Error recording payment: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>Please select tenant and enter a valid amount.</div>";
    }
}

// Fetch tenants for dropdown
try {
    $tenantsStmt = $pdo->query("SELECT id, full_name FROM tenants ORDER BY full_name");
    $tenants = $tenantsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error fetching tenants: " . htmlspecialchars($e->getMessage()) . "</div>";
    $tenants = [];
}

// Fetch payments with tenant name and unit name joined correctly
try {
    $paymentsStmt = $pdo->query("
        SELECT rp.id, rp.amount, rp.payment_date, rp.collected_by, t.full_name, u.unit_name
        FROM rent_payments rp
        JOIN tenants t ON rp.tenant_id = t.id
        JOIN leases l ON t.id = l.tenant_id
        JOIN units u ON l.unit_id = u.id
        ORDER BY rp.payment_date DESC
        LIMIT 50
    ");
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error fetching payments: " . htmlspecialchars($e->getMessage()) . "</div>";
    $payments = [];
}
?>

<div class="container mt-4">
    <h1>Rent Payments</h1>

    <h2>Record New Payment</h2>
    <form method="POST" action="rent_payments.php" class="mb-4">
        <div class="mb-3">
            <label for="tenant_id" class="form-label">Tenant</label>
            <select id="tenant_id" name="tenant_id" class="form-select" required>
                <option value="">Select Tenant</option>
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= htmlspecialchars($tenant['id']) ?>"><?= htmlspecialchars($tenant['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="amount" class="form-label">Amount (TZS)</label>
            <input type="number" step="0.01" id="amount" name="amount" class="form-control" required>
        </div>

        <div class="mb-3">
            <label for="payment_date" class="form-label">Payment Date</label>
            <input type="date" id="payment_date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>

        <button type="submit" class="btn btn-primary">Record Payment</button>
    </form>

    <h2>Recent Payments</h2>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Tenant</th>
                <th>Unit</th>
                <th>Amount (TZS)</th>
                <th>Payment Date</th>
                <th>Collected By</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($payments)): ?>
                <tr><td colspan="5">No payments found.</td></tr>
            <?php else: ?>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= htmlspecialchars($payment['full_name']) ?></td>
                        <td><?= htmlspecialchars($payment['unit_name']) ?></td>
                        <td><?= number_format($payment['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($payment['payment_date']) ?></td>
                        <td><?= htmlspecialchars($payment['collected_by']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../templates/footer.php'; ?>
