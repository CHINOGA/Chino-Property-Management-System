<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
include '../templates/header.php';

$user = $_SESSION['user']['username'];
$role = $_SESSION['user']['role'];
$user_id = $_SESSION['user']['id'] ?? null;

try {
    // Tenant: Submit new maintenance request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'tenant') {
        $unit_id = $_POST['unit_id'] ?? null;
        $description = trim($_POST['description'] ?? '');

        if ($unit_id && $description !== '') {
            $stmt = $pdo->prepare("INSERT INTO maintenance_requests (tenant_id, unit_id, description) VALUES (?, ?, ?)");
            if ($stmt->execute([$user_id, $unit_id, $description])) {
                echo "<div class='alert alert-success'>Maintenance request submitted successfully.</div>";
            } else {
                echo "<div class='alert alert-danger'>Error submitting request.</div>";
            }
        } else {
            echo "<div class='alert alert-warning'>Please provide all required fields.</div>";
        }
    }

    // Admin/Manager: Update maintenance request status and cost
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($role === 'admin' || $role === 'manager')) {
        $request_id = $_POST['request_id'] ?? null;
        $status = $_POST['status'] ?? null;
        $cost = $_POST['cost'] ?? 0;

        if ($request_id && $status) {
            $stmt = $pdo->prepare("UPDATE maintenance_requests SET status = ?, cost = ? WHERE id = ?");
            if ($stmt->execute([$status, $cost, $request_id])) {
                echo "<div class='alert alert-success'>Maintenance request updated successfully.</div>";
            } else {
                echo "<div class='alert alert-danger'>Error updating request.</div>";
            }
        }
    }

    // Fetch maintenance requests
    if ($role === 'tenant') {
        $stmt = $pdo->prepare("SELECT mr.id, mr.description, mr.status, mr.cost, u.unit_name FROM maintenance_requests mr JOIN units u ON mr.unit_id = u.id JOIN tenants t ON mr.tenant_id = t.id WHERE t.user_id = ? ORDER BY mr.created_at DESC");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->query("SELECT mr.id, mr.description, mr.status, mr.cost, u.unit_name, t.full_name FROM maintenance_requests mr JOIN units u ON mr.unit_id = u.id JOIN tenants t ON mr.tenant_id = t.id ORDER BY mr.created_at DESC");
    }
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch units for tenant to submit requests
    $units = [];
    if ($role === 'tenant') {
        $stmt = $pdo->prepare("SELECT u.id, u.unit_name FROM units u JOIN leases l ON u.id = l.unit_id JOIN tenants t ON l.tenant_id = t.id WHERE t.user_id = ?");
        $stmt->execute([$user_id]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>

<h1>Maintenance Requests</h1>

<?php if ($role === 'tenant'): ?>
<h2>Submit New Request</h2>
<form method="POST" action="maintenance.php" class="mb-4">
    <div class="mb-3">
        <label for="unit_id" class="form-label">Unit</label>
        <select id="unit_id" name="unit_id" class="form-select" required>
            <option value="">Select Unit</option>
            <?php foreach ($units as $unit): ?>
                <option value="<?= htmlspecialchars($unit['id']) ?>"><?= htmlspecialchars($unit['unit_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">Description</label>
        <textarea id="description" name="description" class="form-control" rows="3" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Submit Request</button>
</form>
<?php endif; ?>

<h2>Existing Requests</h2>
<table class="table table-striped">
    <thead>
        <tr>
            <th>Unit</th>
            <?php if ($role !== 'tenant'): ?>
            <th>Tenant</th>
            <?php endif; ?>
            <th>Description</th>
            <th>Status</th>
            <th>Cost</th>
            <?php if ($role === 'admin' || $role === 'manager'): ?>
            <th>Actions</th>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($requests as $req): ?>
        <tr>
            <td><?= htmlspecialchars($req['unit_name']) ?></td>
            <?php if ($role !== 'tenant'): ?>
            <td><?= htmlspecialchars($req['full_name']) ?></td>
            <?php endif; ?>
            <td><?= htmlspecialchars($req['description']) ?></td>
            <td><?= htmlspecialchars($req['status']) ?></td>
            <td><?= htmlspecialchars($req['cost']) ?></td>
            <?php if ($role === 'admin' || $role === 'manager'): ?>
            <td>
                <form method="POST" action="maintenance.php" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="request_id" value="<?= htmlspecialchars($req['id']) ?>">
                    <select name="status" class="form-select" required>
                        <option value="pending" <?= $req['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="in_progress" <?= $req['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="completed" <?= $req['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                    <input type="number" step="0.01" name="cost" class="form-control" style="max-width:100px;" value="<?= htmlspecialchars($req['cost']) ?>" placeholder="Cost" required>
                    <button type="submit" class="btn btn-sm btn-primary">Update</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include '../templates/footer.php'; ?>
