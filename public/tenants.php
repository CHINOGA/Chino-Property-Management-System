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

// Initialize CSRF token
$csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

function flash($key, $message = null) {
    if ($message) {
        $_SESSION['flash'][$key] = $message;
    } elseif (isset($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function logError($message) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, $logDir . '/error.log');
}

// Function to check if username is duplicate excluding a specific user id
function isDuplicateUsername($pdo, $username, $excludeUserId = null) {
    $sql = "SELECT id FROM users WHERE username = ?";
    $params = [$username];
    if ($excludeUserId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeUserId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() !== false;
}

$message = flash('message');

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        flash('message', 'Invalid CSRF token.');
        logError('Invalid CSRF token for user_id: ' . ($_SESSION['user']['id'] ?? 'unknown'));
        header("Location: tenants.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        if (isset($_POST['action'])) {
            $action = $_POST['action'];

            if ($action === 'edit_tenant') {
                $tenant_id = filter_input(INPUT_POST, 'tenant_id', FILTER_VALIDATE_INT);
                $full_name = trim(filter_input(INPUT_POST, 'name', FILTER_UNSAFE_RAW));
                $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
                $username = trim(filter_input(INPUT_POST, 'username', FILTER_UNSAFE_RAW));
                $password = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW) ?: null;

                $username = trim($username);
                if (!$tenant_id || empty($full_name) || !$email || $username === '') {
                    flash('message', 'All required fields must be filled.');
                } elseif (strlen($username) < 3) {
                    flash('message', 'Username must be at least 3 characters.');
                } elseif ($password && strlen($password) < 6) {
                    flash('message', 'Password must be at least 6 characters.');
                } elseif (isDuplicateUsername($pdo, $username, $tenant_id)) {
                    flash('message', 'Username already exists.');
                } else {
                    $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
                    $stmt->execute([$tenant_id]);
                    $tenant = $stmt->fetch();
                    if ($tenant) {
                        $user_id = $tenant['user_id'];
                        $updates = ['username' => $username];
                        $sql = "UPDATE users SET username = ?";
                        $params = [$username];
                        if ($password) {
                            $updates['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                            $sql .= ", password_hash = ?";
                            $params[] = $updates['password_hash'];
                        }
                        $sql .= " WHERE id = ?";
                        $params[] = $user_id;
                        $stmt = $pdo->prepare($sql);
                        if ($stmt->execute($params)) {
                            $stmt = $pdo->prepare("UPDATE tenants SET full_name = ?, email = ? WHERE id = ?");
                            if ($stmt->execute([$full_name, $email, $tenant_id])) {
                                flash('message', 'Tenant updated successfully.');
                            } else {
                                flash('message', 'Failed to update tenant details.');
                                $pdo->rollBack();
                            }
                        } else {
                            flash('message', 'Failed to update user account.');
                            $pdo->rollBack();
                        }
                    } else {
                        flash('message', 'Tenant not found.');
                    }
                }
            } elseif ($action === 'delete_tenant') {
                $tenant_id = filter_input(INPUT_POST, 'tenant_id', FILTER_VALIDATE_INT);
                if (!$tenant_id) {
                    flash('message', 'Invalid tenant ID.');
                } else {
                    // Check for active leases
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leases WHERE tenant_id = ?");
                    $stmt->execute([$tenant_id]);
                    if ($stmt->fetchColumn() > 0) {
                        flash('message', 'Cannot delete tenant with active leases.');
                    } else {
                        // Fetch tenant data for potential restoration
                        $stmt = $pdo->prepare("SELECT user_id, full_name, email FROM tenants WHERE id = ?");
                        $stmt->execute([$tenant_id]);
                        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($tenant) {
                            $user_id = $tenant['user_id'];
                            $stmt = $pdo->prepare("DELETE FROM tenants WHERE id = ?");
                            if ($stmt->execute([$tenant_id])) {
                                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                                if ($stmt->execute([$user_id])) {
                                    flash('message', 'Tenant deleted successfully.');
                                } else {
                                    // Restore tenant with original data
                                    flash('message', 'Failed to delete user account.');
                                    $pdo->rollBack();
                                    $stmt = $pdo->prepare("INSERT INTO tenants (id, user_id, full_name, email) VALUES (?, ?, ?, ?)");
                                    $stmt->execute([$tenant_id, $user_id, $tenant['full_name'], $tenant['email']]);
                                }
                            } else {
                                flash('message', 'Failed to delete tenant.');
                                $pdo->rollBack();
                            }
                        } else {
                            flash('message', 'Tenant not found.');
                        }
                    }
                }
            } elseif ($action === 'add_lease') {
                $tenant_id = filter_input(INPUT_POST, 'tenant_id', FILTER_VALIDATE_INT);
                $unit_id = filter_input(INPUT_POST, 'unit_id', FILTER_VALIDATE_INT);
                $start_date = filter_input(INPUT_POST, 'start_date', FILTER_UNSAFE_RAW);
                $end_date = filter_input(INPUT_POST, 'end_date', FILTER_UNSAFE_RAW);
                $rent_amount = filter_input(INPUT_POST, 'rent_amount', FILTER_VALIDATE_FLOAT);

                if (!$tenant_id || !$unit_id || !$start_date || !$end_date || !$rent_amount) {
                    flash('message', 'All lease fields are required.');
                } elseif (strtotime($start_date) >= strtotime($end_date)) {
                    flash('message', 'Lease start date must be before end date.');
                } elseif ($rent_amount <= 0) {
                    flash('message', 'Rent amount must be greater than 0.');
                } else {
                    // Validate tenant and unit existence
                    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE id = ?");
                    $stmt->execute([$tenant_id]);
                    if (!$stmt->fetch()) {
                        flash('message', 'Invalid tenant ID.');
                    } else {
                        $stmt = $pdo->prepare("SELECT id FROM units WHERE id = ?");
                        $stmt->execute([$unit_id]);
                        if (!$stmt->fetch()) {
                            flash('message', 'Invalid unit ID.');
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO leases (tenant_id, unit_id, lease_start, lease_end) VALUES (?, ?, ?, ?)");
                            if ($stmt->execute([$tenant_id, $unit_id, $start_date, $end_date])) {
                                $updateUnitStmt = $pdo->prepare("UPDATE units SET occupancy_status = 'occupied', rent_amount = ? WHERE id = ?");
                                $updateUnitStmt->execute([$rent_amount, $unit_id]);
                                flash('message', 'Lease added successfully.');
                            } else {
                                flash('message', 'Failed to add lease.');
                            }
                        }
                    }
                }
            } elseif ($action === 'edit_lease') {
                $lease_id = filter_input(INPUT_POST, 'lease_id', FILTER_VALIDATE_INT);
                $tenant_id = filter_input(INPUT_POST, 'tenant_id', FILTER_VALIDATE_INT);
                $unit_id = filter_input(INPUT_POST, 'unit_id', FILTER_VALIDATE_INT);
                $start_date = filter_input(INPUT_POST, 'start_date', FILTER_UNSAFE_RAW);
                $end_date = filter_input(INPUT_POST, 'end_date', FILTER_UNSAFE_RAW);
                $rent_amount = filter_input(INPUT_POST, 'rent_amount', FILTER_VALIDATE_FLOAT);

                if (!$lease_id || !$tenant_id || !$unit_id || !$start_date || !$end_date || !$rent_amount) {
                    flash('message', 'All lease fields are required.');
                } elseif (strtotime($start_date) >= strtotime($end_date)) {
                    flash('message', 'Lease start date must be before end date.');
                } elseif ($rent_amount <= 0) {
                    flash('message', 'Rent amount must be greater than 0.');
                } else {
                    // Validate tenant and unit existence
                    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE id = ?");
                    $stmt->execute([$tenant_id]);
                    if (!$stmt->fetch()) {
                        flash('message', 'Invalid tenant ID.');
                    } else {
                        $stmt = $pdo->prepare("SELECT id FROM units WHERE id = ?");
                        $stmt->execute([$unit_id]);
                        if (!$stmt->fetch()) {
                            flash('message', 'Invalid unit ID.');
                        } else {
                            $stmt = $pdo->prepare("UPDATE leases SET tenant_id = ?, unit_id = ?, lease_start = ?, lease_end = ? WHERE id = ?");
                            if ($stmt->execute([$tenant_id, $unit_id, $start_date, $end_date, $lease_id])) {
                                $updateUnitStmt = $pdo->prepare("UPDATE units SET rent_amount = ? WHERE id = ?");
                                $updateUnitStmt->execute([$rent_amount, $unit_id]);
                                flash('message', 'Lease updated successfully.');
                            } else {
                                flash('message', 'Failed to update lease.');
                            }
                        }
                    }
                }
            } elseif ($action === 'delete_lease') {
                $lease_id = filter_input(INPUT_POST, 'lease_id', FILTER_VALIDATE_INT);
                if (!$lease_id) {
                    flash('message', 'Invalid lease ID.');
                } else {
                    // Check for dependencies (e.g., payments)
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE lease_id = ?");
                    $stmt->execute([$lease_id]);
                    if ($stmt->fetchColumn() > 0) {
                        flash('message', 'Cannot delete lease with associated payments.');
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM leases WHERE id = ?");
                        if ($stmt->execute([$lease_id])) {
                            flash('message', 'Lease deleted successfully.');
                        } else {
                            flash('message', 'Failed to delete lease.');
                        }
                    }
                }
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        flash('message', 'Database error: ' . htmlspecialchars($e->getMessage()));
        logError('Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('message', 'Error: ' . htmlspecialchars($e->getMessage()));
        logError('General error: ' . $e->getMessage());
    }

    header("Location: tenants.php");
    exit;
}

// Fetching data for the page
try {
    // Fetch all users with tenant role for synchronization
    $tenantRoleUsersStmt = $pdo->query("SELECT id, username FROM users WHERE role = 'tenant' ORDER BY username ASC");
    $tenantRoleUsers = $tenantRoleUsersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch tenants from tenants table using LEFT JOIN to include all users with tenant role
    $tenantsStmt = $pdo->query("SELECT u.id, t.full_name, u.username, u.email FROM users u LEFT JOIN tenants t ON u.id = t.user_id WHERE u.role = 'tenant' ORDER BY u.username ASC");
    $tenants = $tenantsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Sync tenant users from users table into tenants table if missing
    $tenantUserIds = array_column($tenants, 'user_id');
    foreach ($tenantRoleUsers as $user) {
        if (!in_array($user['id'], $tenantUserIds)) {
            try {
                // Skip users with empty, null, or whitespace-only usernames
                if (!isset($user['username']) || $user['username'] === null || trim($user['username']) === '') {
                    logError("Skipping user with empty username: user_id={$user['id']}");
                    continue;
                }
                
                // Get user email
                $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                $stmt->execute([$user['id']]);
                $user_email = $stmt->fetchColumn();

                // Generate unique full_name
                $baseFullName = trim($user['username']);
                $fullName = $baseFullName;
                $suffix = 1;
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE full_name = ?");
                while (true) {
                    $checkStmt->execute([$fullName]);
                    if ($checkStmt->fetchColumn() == 0) {
                        break;
                    }
                    $fullName = $baseFullName . '_' . $suffix++;
                }
                // Verify user_id doesn't exist in tenants
                $checkUserIdStmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE user_id = ?");
                $checkUserIdStmt->execute([$user['id']]);
                if ($checkUserIdStmt->fetchColumn() == 0) {
                    $insertStmt = $pdo->prepare("INSERT INTO tenants (user_id, full_name, email) VALUES (?, ?, ?)");
                    if ($insertStmt->execute([$user['id'], $fullName, $user_email])) {
                        logError("Inserted tenant user_id={$user['id']} full_name={$fullName}");
                    } else {
                        logError("Failed to insert tenant user_id={$user['id']} full_name={$fullName}");
                    }
                }
            } catch (PDOException $e) {
                logError("Error syncing tenant user_id={$user['id']}: " . $e->getMessage());
            }
        }
    }

    // Re-fetch tenants after sync
    $tenantsStmt = $pdo->query("SELECT t.*, u.username, u.email as user_email FROM tenants t JOIN users u ON t.user_id = u.id WHERE u.role = 'tenant' ORDER BY t.created_at DESC");
    $tenants = $tenantsStmt->fetchAll(PDO::FETCH_ASSOC);

    $leasesStmt = $pdo->query("SELECT l.*, t.full_name AS tenant_name, u.unit_name, u.rent_amount FROM leases l 
        JOIN tenants t ON l.tenant_id = t.id 
        JOIN units u ON l.unit_id = u.id 
        ORDER BY l.created_at DESC");
    $leases = $leasesStmt->fetchAll(PDO::FETCH_ASSOC);

    $unitsStmt = $pdo->query("SELECT id, unit_name FROM units WHERE occupancy_status = 'vacant' ORDER BY unit_name ASC");
    $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tenants = [];
    $leases = [];
    $units = [];
    flash('message', 'Error fetching data: ' . htmlspecialchars($e->getMessage()));
    logError('Error fetching data: ' . $e->getMessage());
}
?>

<h1>Tenant & Lease Management</h1>

<?php if ($message = flash('message')): ?>
    <div class="alert alert-<?= strpos($message, 'Error') !== false || strpos($message, 'Invalid') !== false ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert" style="position: fixed; top: 70px; right: 20px; z-index: 1050; min-width: 300px;">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>


<!-- Edit Tenant Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Edit Tenant</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" action="tenants.php" id="editTenantForm" onsubmit="return validateEditForm()">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="edit_tenant">
                    <input type="hidden" id="edit_tenant_id" name="tenant_id">
                    <div class="mb-3">
                        <label for="edit_full_name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="edit_full_name" name="name" required minlength="3">
                    </div>
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="edit_username" name="username" required minlength="3">
                    </div>
                    <div class="mb-3">
                        <label for="edit_password" class="form-label">New Password (leave blank to keep current)</label>
                        <input type="password" class="form-control" id="edit_password" name="password" minlength="6">
                    </div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Add Lease Form -->
<h2>Add New Lease</h2>
<form method="post" class="mb-4" id="addLeaseForm" onsubmit="return validateAddLeaseForm()">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="action" value="add_lease">
    <div class="mb-3">
        <label for="lease_tenant" class="form-label">Tenant</label>
        <select class="form-select" id="lease_tenant" name="tenant_id" required>
            <option value="">Select Tenant</option>
            <?php
            $stmt = $pdo->query("SELECT t.id, u.username FROM tenants t JOIN users u ON t.user_id = u.id WHERE u.role = 'tenant' ORDER BY u.username ASC");
            $all_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($all_tenants as $tenant):
            ?>
                <option value="<?= htmlspecialchars($tenant['id']) ?>"><?= htmlspecialchars($tenant['username']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label for="lease_unit" class="form-label">Unit</label>
        <select class="form-select" id="lease_unit" name="unit_id" required>
            <option value="">Select Unit</option>
            <?php foreach ($units as $unit): ?>
                <option value="<?= htmlspecialchars($unit['id']) ?>"><?= htmlspecialchars($unit['unit_name']) ?></option>
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
        <input type="number" step="0.01" class="form-control" id="lease_rent" name="rent_amount" required min="0.01">
    </div>
    <button type="submit" class="btn btn-primary">Add Lease</button>
</form>

<!-- List Leases -->
<h2>Leases</h2>
<div class="table-responsive">
    <table class="table table-striped table-bordered">
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
            <?php if (empty($leases)): ?>
                <tr><td colspan="6" class="text-center">No leases found.</td></tr>
            <?php else: ?>
                <?php foreach ($leases as $lease): ?>
                    <tr>
                        <td><?= htmlspecialchars($lease['tenant_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($lease['unit_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($lease['lease_start'] ?? '') ?></td>
                        <td><?= htmlspecialchars($lease['lease_end'] ?? '') ?></td>
                        <td><?= number_format($lease['rent_amount'] ?? 0, 2) ?></td>
                        <td>
                            <a href="generate_lease_contract.php?lease_id=<?= htmlspecialchars($lease['id']) ?>" class="btn btn-sm btn-info" target="_blank">Generate Contract</a>
                            <form method="post" action="tenants.php" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="action" value="delete_lease">
                                <input type="hidden" name="lease_id" value="<?= htmlspecialchars($lease['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this lease?')">Delete</button>
                            </form>
                            <button class="btn btn-sm btn-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#editLease<?= htmlspecialchars($lease['id']) ?>" aria-expanded="false" aria-controls="editLease<?= htmlspecialchars($lease['id']) ?>">
                                Edit
                            </button>
                            <div class="collapse mt-2" id="editLease<?= htmlspecialchars($lease['id']) ?>">
                                <form method="post" action="tenants.php" class="mb-3" onsubmit="return validateEditLeaseForm(<?= htmlspecialchars($lease['id']) ?>)">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="edit_lease">
                                    <input type="hidden" name="lease_id" value="<?= htmlspecialchars($lease['id']) ?>">
                                    <div class="mb-3">
                                        <label for="tenant_id_<?= htmlspecialchars($lease['id']) ?>" class="form-label">Tenant</label>
                                        <select class="form-select" id="tenant_id_<?= htmlspecialchars($lease['id']) ?>" name="tenant_id" required>
                                            <?php foreach ($tenants as $tenant): ?>
                                                <option value="<?= htmlspecialchars($tenant['id']) ?>" <?= $tenant['id'] == $lease['tenant_id'] ? 'selected' : '' ?>><?= htmlspecialchars($tenant['full_name'] ?? '') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="unit_id_<?= htmlspecialchars($lease['id']) ?>" class="form-label">Unit</label>
                                        <select class="form-select" id="unit_id_<?= htmlspecialchars($lease['id']) ?>" name="unit_id" required>
                                            <?php foreach ($units as $unit): ?>
                                                <option value="<?= htmlspecialchars($unit['id']) ?>" <?= $unit['id'] == $lease['unit_id'] ? 'selected' : '' ?>><?= htmlspecialchars($unit['unit_name'] ?? '') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="start_date_<?= htmlspecialchars($lease['id']) ?>" class="form-label">Start Date</label>
                                        <input type="date" class="form-control" id="start_date_<?= htmlspecialchars($lease['id']) ?>" name="start_date" value="<?= htmlspecialchars($lease['lease_start'] ?? '') ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="end_date_<?= htmlspecialchars($lease['id']) ?>" class="form-label">End Date</label>
                                        <input type="date" class="form-control" id="end_date_<?= htmlspecialchars($lease['id']) ?>" name="end_date" value="<?= htmlspecialchars($lease['lease_end'] ?? '') ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="rent_amount_<?= htmlspecialchars($lease['id']) ?>" class="form-label">Rent Amount (TZS)</label>
                                        <input type="number" step="0.01" class="form-control" id="rent_amount_<?= htmlspecialchars($lease['id']) ?>" name="rent_amount" value="<?= htmlspecialchars($lease['rent_amount'] ?? 0) ?>" required min="0.01">
                                    </div>
                                    <button type="submit" class="btn btn-primary">Update Lease</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
<script>
function validateEditForm() {
    const password = document.getElementById('edit_password').value;
    if (password && password.length < 6) {
        alert('Password must be at least 6 characters.');
        return false;
    }
    const username = document.getElementById('edit_username').value.trim();
    if (username.length < 3) {
        alert('Username must be at least 3 characters.');
        return false;
    }
    return true;
}

function validateAddLeaseForm() {
    const startDate = new Date(document.getElementById('lease_start').value);
    const endDate = new Date(document.getElementById('lease_end').value);
    const rentAmount = parseFloat(document.getElementById('lease_rent').value);
    if (startDate >= endDate) {
        alert('Lease start date must be before end date.');
        return false;
    }
    if (rentAmount <= 0) {
        alert('Rent amount must be greater than 0.');
        return false;
    }
    return true;
}

function validateEditLeaseForm(leaseId) {
    const startDate = new Date(document.getElementById(`start_date_${leaseId}`).value);
    const endDate = new Date(document.getElementById(`end_date_${leaseId}`).value);
    const rentAmount = parseFloat(document.getElementById(`rent_amount_${leaseId}`).value);
    if (startDate >= endDate) {
        alert('Lease start date must be before end date.');
        return false;
    }
    if (rentAmount <= 0) {
        alert('Rent amount must be greater than 0.');
        return false;
    }
    return true;
}

document.querySelectorAll('.edit-btn').forEach(button => {
    button.addEventListener('click', () => {
        const id = button.getAttribute('data-id');
        const fullName = button.getAttribute('data-fullname');
        const email = button.getAttribute('data-email');
        const userId = button.getAttribute('data-userid');
        document.getElementById('edit_tenant_id').value = id;
        document.getElementById('edit_full_name').value = fullName;
        document.getElementById('edit_email').value = email;
        fetch(`../api/get_username.php?id=${userId}`, {
            headers: {
                'X-CSRF-Token': '<?= htmlspecialchars($csrf_token) ?>'
            }
        })
            .then(response => {
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                return response.json();
            })
            .then(data => {
                document.getElementById('edit_username').value = data.username || '';
            })
            .catch(error => {
                console.error('Error fetching username:', error);
                document.getElementById('edit_username').value = '';
                alert('Failed to load username: ' + error.message);
            });
    });
});
</script>
<?php include '../templates/footer.php'; ?>
