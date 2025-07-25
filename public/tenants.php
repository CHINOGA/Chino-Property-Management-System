<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once '../config/db.php';
include '../templates/header.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        // Check user role before allowing tenant or lease management
        $user_role = $_SESSION['user']['role'] ?? 'tenant'; // default to tenant if not set
        if (!in_array($user_role, ['admin', 'manager'])) {
            $message = "Access denied: You do not have permission to manage tenants or leases.";
            echo "<div class='alert alert-danger'>$message</div>";
            exit;
        } else {
            try {
                if ($action === 'add_tenant') {
                    $full_name = $_POST['name'] ?? '';
                    $email = $_POST['email'] ?? '';
                    $phone = $_POST['phone'] ?? '';
                    $user_id = $_SESSION['user']['id'] ?? 0;
                    $stmt = $pdo->prepare("INSERT INTO tenants (full_name, email, phone, user_id) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$full_name, $email, $phone, $user_id]);
                    $message = "Tenant added successfully.";
                } elseif ($action === 'edit_tenant') {
                    $id = $_POST['tenant_id'] ?? 0;
                    $full_name = $_POST['name'] ?? '';
                    $email = $_POST['email'] ?? '';
                    $phone = $_POST['phone'] ?? '';
                    $stmt = $pdo->prepare("UPDATE tenants SET full_name = ?, email = ?, phone = ? WHERE id = ?");
                    $stmt->execute([$full_name, $email, $phone, $id]);
                    $message = "Tenant updated successfully.";
                } elseif ($action === 'delete_tenant') {
                    $id = $_POST['tenant_id'] ?? 0;
                    $stmt = $pdo->prepare("DELETE FROM tenants WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = "Tenant deleted successfully.";
                } elseif ($action === 'add_lease') {
                    $tenant_id = $_POST['tenant_id'] ?? 0;
                    $unit_id = $_POST['unit_id'] ?? 0;
                    $start_date = $_POST['start_date'] ?? '';
                    $end_date = $_POST['end_date'] ?? '';
                    $rent_amount = $_POST['rent_amount'] ?? 0;
                    $stmt = $pdo->prepare("INSERT INTO leases (tenant_id, unit_id, lease_start, lease_end, rent) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$tenant_id, $unit_id, $start_date, $end_date, $rent_amount]);
                    $message = "Lease added successfully.";
                } elseif ($action === 'edit_lease') {
                    $lease_id = $_POST['lease_id'] ?? 0;
                    $tenant_id = $_POST['tenant_id'] ?? 0;
                    $unit_id = $_POST['unit_id'] ?? 0;
                    $start_date = $_POST['start_date'] ?? '';
                    $end_date = $_POST['end_date'] ?? '';
                    $rent_amount = $_POST['rent_amount'] ?? 0;
                    $stmt = $pdo->prepare("UPDATE leases SET tenant_id = ?, unit_id = ?, lease_start = ?, lease_end = ?, rent = ? WHERE id = ?");
                    $stmt->execute([$tenant_id, $unit_id, $start_date, $end_date, $rent_amount, $lease_id]);
                    $message = "Lease updated successfully.";
                } elseif ($action === 'delete_lease') {
                    $lease_id = $_POST['lease_id'] ?? 0;
                    $stmt = $pdo->prepare("DELETE FROM leases WHERE id = ?");
                    $stmt->execute([$lease_id]);
                    $message = "Lease deleted successfully.";
                }
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
            }
        }
    }
}

// Fetch tenants and leases
try {
    $tenantsStmt = $pdo->query("SELECT * FROM tenants ORDER BY created_at DESC");
    $tenants = $tenantsStmt->fetchAll(PDO::FETCH_ASSOC);

    $leasesStmt = $pdo->query("SELECT leases.*, tenants.full_name AS tenant_name, units.unit_name FROM leases 
        JOIN tenants ON leases.tenant_id = tenants.id 
        JOIN units ON leases.unit_id = units.id ORDER BY leases.created_at DESC");
    $leases = $leasesStmt->fetchAll(PDO::FETCH_ASSOC);

    $unitsStmt = $pdo->query("SELECT * FROM units ORDER BY unit_name ASC");
    $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tenants = [];
    $leases = [];
    $units = [];
    $message = "Error fetching tenants or leases: " . $e->getMessage();
}
?>

<h1>Tenant & Lease Management</h1>

<?php if ($message): ?>
    <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- Add Tenant Form -->
<h2>Add New Tenant</h2>
<form method="post" class="mb-4">
    <input type="hidden" name="action" value="add_tenant">
    <div class="mb-3">
        <label for="tenant_name" class="form-label">Name</label>
        <input type="text" class="form-control" id="tenant_name" name="name" required>
    </div>
    <div class="mb-3">
        <label for="tenant_email" class="form-label">Email</label>
        <input type="email" class="form-control" id="tenant_email" name="email" required>
    </div>
    <div class="mb-3">
        <label for="tenant_phone" class="form-label">Phone</label>
        <input type="text" class="form-control" id="tenant_phone" name="phone" required>
    </div>
    <button type="submit" class="btn btn-primary">Add Tenant</button>
</form>

<!-- List Tenants -->
<h2>Tenants</h2>
<table class="table table-bordered">
    <thead>
        <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($tenants as $tenant): ?>
            <tr>
                <td><?= htmlspecialchars($tenant['full_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($tenant['email'] ?? '') ?></td>
                <td><?= htmlspecialchars($tenant['phone'] ?? '') ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="delete_tenant">
                        <input type="hidden" name="tenant_id" value="<?= $tenant['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this tenant?');">Delete</button>
                    </form>
                    <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#editTenant<?= $tenant['id'] ?>" aria-expanded="false" aria-controls="editTenant<?= $tenant['id'] ?>">
                        Edit
                    </button>
                    <div class="collapse mt-2" id="editTenant<?= $tenant['id'] ?>">
                        <form method="post" class="mb-3">
                            <input type="hidden" name="action" value="edit_tenant">
                            <input type="hidden" name="tenant_id" value="<?= $tenant['id'] ?>">
                            <div class="mb-3">
                                <label for="name<?= $tenant['id'] ?>" class="form-label">Name</label>
                                <input type="text" class="form-control" id="name<?= $tenant['id'] ?>" name="name" value="<?= htmlspecialchars($tenant['full_name']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="email<?= $tenant['id'] ?>" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email<?= $tenant['id'] ?>" name="email" value="<?= htmlspecialchars($tenant['email']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="phone<?= $tenant['id'] ?>" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="phone<?= $tenant['id'] ?>" name="phone" value="<?= htmlspecialchars($tenant['phone']) ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Update Tenant</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Add Lease Form -->
<h2>Add New Lease</h2>
<form method="post" class="mb-4">
    <input type="hidden" name="action" value="add_lease">
    <div class="mb-3">
        <label for="lease_tenant" class="form-label">Tenant</label>
        <select class="form-select" id="lease_tenant" name="tenant_id" required style="color: black;">
            <option value="">Select Tenant</option>
            <?php foreach ($tenants as $tenant): ?>
                <option value="<?= $tenant['id'] ?>"><?= htmlspecialchars($tenant['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label for="lease_unit" class="form-label">Unit</label>
        <select class="form-select" id="lease_unit" name="unit_id" required>
            <option value="">Select Unit</option>
            <?php foreach ($units as $unit): ?>
                <option value="<?= $unit['id'] ?>"><?= htmlspecialchars($unit['unit_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label for="lease_start" class="form-label">Start Date</label>
        <input type="date" class="form-control" id="lease_start" name="start_date" required>
    </div>
    <div class="mb-3">
        <label for="lease_end" class="form-label">End Date</label>
        <input type="date" class="form-control" id="lease_end" name="end_date" required>
    </div>
    <div class="mb-3">
        <label for="lease_rent" class="form-label">Rent Amount (TZS)</label>
        <input type="number" step="0.01" class="form-control" id="lease_rent" name="rent_amount" required>
    </div>
    <button type="submit" class="btn btn-primary">Add Lease</button>
</form>

<!-- List Leases -->
<h2>Leases</h2>
<table class="table table-bordered">
    <thead>
        <tr>
            <th>Tenant</th>
            <th>Unit</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Rent Amount (TZS)</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($leases as $lease): ?>
            <tr>
                <td><?= htmlspecialchars($lease['tenant_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($lease['unit_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($lease['lease_start'] ?? '') ?></td>
                <td><?= htmlspecialchars($lease['lease_end'] ?? '') ?></td>
                <td><?= number_format($lease['rent'] ?? 0, 2) ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="delete_lease">
                        <input type="hidden" name="lease_id" value="<?= $lease['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this lease?');">Delete</button>
                    </form>
                    <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#editLease<?= $lease['id'] ?>" aria-expanded="false" aria-controls="editLease<?= $lease['id'] ?>">
                        Edit
                    </button>
                    <div class="collapse mt-2" id="editLease<?= $lease['id'] ?>">
                        <form method="post" class="mb-3">
                            <input type="hidden" name="action" value="edit_lease">
                            <input type="hidden" name="lease_id" value="<?= $lease['id'] ?>">
                            <div class="mb-3">
                                <label for="tenant_id<?= $lease['id'] ?>" class="form-label">Tenant</label>
                                <select class="form-select" id="tenant_id<?= $lease['id'] ?>" name="tenant_id" required>
                                    <?php foreach ($tenants as $tenant): ?>
                                        <option value="<?= $tenant['id'] ?>" <?= $tenant['id'] == $lease['tenant_id'] ? 'selected' : '' ?>><?= htmlspecialchars($tenant['full_name'] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="unit_id<?= $lease['id'] ?>" class="form-label">Unit</label>
                                <select class="form-select" id="unit_id<?= $lease['id'] ?>" name="unit_id" required>
                                    <?php foreach ($units as $unit): ?>
                                        <option value="<?= $unit['id'] ?>" <?= $unit['id'] == $lease['unit_id'] ? 'selected' : '' ?>><?= htmlspecialchars($unit['unit_name'] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="start_date<?= $lease['id'] ?>" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date<?= $lease['id'] ?>" name="start_date" value="<?= htmlspecialchars($lease['lease_start'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="end_date<?= $lease['id'] ?>" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date<?= $lease['id'] ?>" name="end_date" value="<?= htmlspecialchars($lease['lease_end'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="rent_amount<?= $lease['id'] ?>" class="form-label">Rent Amount (TZS)</label>
                                <input type="number" step="0.01" class="form-control" id="rent_amount<?= $lease['id'] ?>" name="rent_amount" value="<?= htmlspecialchars($lease['rent'] ?? 0) ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Update Lease</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include '../templates/footer.php'; ?>
