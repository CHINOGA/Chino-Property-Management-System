<?php
session_start();

// Security: Check for authenticated user
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
include '../templates/header.php';

$user = $_SESSION['user']['username'];
$role = $_SESSION['user']['role'];
$user_id = $_SESSION['user']['id'] ?? null;

// Logging function (for debugging)
function logError($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, __DIR__ . '/../logs/error.log');
}

// Flash message helper
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

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper function to verify CSRF token
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Function to sanitize input
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// Status badge function
function statusBadge($status) {
    switch ($status) {
        case 'pending':
            return "<span class='badge bg-warning text-dark'>Pending</span>";
        case 'in_progress':
            return "<span class='badge bg-primary'>In Progress</span>";
        case 'completed':
            return "<span class='badge bg-success'>Completed</span>";
        default:
            return "<span class='badge bg-secondary'>Unknown</span>";
    }
}

// Priority badge function
function priorityBadge($priority) {
    switch ($priority) {
        case 'low':
            return "<span class='badge bg-success'>Low</span>";
        case 'medium':
            return "<span class='badge bg-warning'>Medium</span>";
        case 'high':
            return "<span class='badge bg-danger'>High</span>";
        default:
            return "<span class='badge bg-secondary'>Unknown</span>";
    }
}

// Function to validate and handle file uploads
function handleFileUpload($file, $request_id, $pdo) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                flash('error', 'File is too large (max 5MB).');
                break;
            case UPLOAD_ERR_NO_FILE:
                return true; // No file uploaded is not an error
            default:
                flash('error', 'File upload error: ' . $file['error']);
                logError('File upload error: ' . $file['error']);
        }
        return false;
    }

    $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
    $max_size = 5 * 1024 * 1024; // 5MB
    if (!in_array($file['type'], $allowed_types) || $file['size'] > $max_size) {
        flash('error', 'Invalid file type or size. Allowed: JPEG, PNG, PDF (max 5MB).');
        return false;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('maint_') . '.' . $ext;
    $upload_dir = __DIR__ . '/../uploads/maintenance/';
    $upload_path = $upload_dir . $filename;

    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            flash('error', 'Failed to create upload directory.');
            logError('Failed to create upload directory: ' . $upload_dir);
            return false;
        }
    }

    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        $stmt = $pdo->prepare("INSERT INTO maintenance_files (request_id, file_path, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        if ($stmt->execute([$request_id, $filename])) {
            return true;
        } else {
            flash('error', 'Failed to save file metadata to database.');
            logError('Failed to save file metadata for request_id: ' . $request_id);
            return false;
        }
    } else {
        flash('error', 'Failed to move uploaded file.');
        logError('Failed to move uploaded file to: ' . $upload_path);
        return false;
    }
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logError('POST request received for user: ' . $user . ', role: ' . $role . ', action: ' . ($_POST['action'] ?? 'none'));
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        flash('error', 'Invalid CSRF token.');
        logError('Invalid CSRF token for user: ' . $user);
        header("Location: maintenance.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($role === 'tenant' && isset($_POST['action']) && $_POST['action'] === 'create') {
            logError('Processing create request for tenant user_id: ' . $user_id);
            // Get tenant id from tenants table
            $tenantStmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $tenantStmt->execute([$user_id]);
            $tenantRow = $tenantStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tenantRow) {
                flash('error', 'Tenant record not found for your user account.');
                logError('Tenant record not found for user_id: ' . $user_id);
            } else {
                $tenant_id = $tenantRow['id'];
                // Submit new maintenance request
                $unit_id = filter_input(INPUT_POST, 'unit_id', FILTER_VALIDATE_INT);
                $description = sanitizeInput(filter_input(INPUT_POST, 'description', FILTER_UNSAFE_RAW));
                $priority = filter_input(INPUT_POST, 'priority', FILTER_UNSAFE_RAW);

                // Input validation
                if (!$unit_id) {
                    flash('error', 'Please select a valid unit.');
                    logError('Invalid unit_id: ' . $unit_id);
                } elseif (empty($description)) {
                    flash('error', 'Description is required.');
                    logError('Empty description');
                } elseif (strlen($description) > 1000) {
                    flash('error', 'Description exceeds 1000 characters.');
                    logError('Description too long: ' . strlen($description));
                } elseif (!in_array($priority, ['low', 'medium', 'high'])) {
                    flash('error', 'Invalid priority selected.');
                    logError('Invalid priority: ' . $priority);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO maintenance_requests (tenant_id, unit_id, description, priority, status, created_at)
                        VALUES (?, ?, ?, ?, 'pending', CURRENT_TIMESTAMP)
                    ");
                    if ($stmt->execute([$tenant_id, $unit_id, $description, $priority])) {
                        $request_id = $pdo->lastInsertId();
                        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
                            if (!handleFileUpload($_FILES['attachment'], $request_id, $pdo)) {
                                flash('error', 'Error uploading attachment. Request still submitted.');
                            }
                        }
                        flash('success', 'Maintenance request submitted successfully.');
                        logError('Request created successfully, request_id: ' . $request_id);
                    } else {
                        flash('error', 'Error submitting request.');
                        logError('Failed to insert maintenance request for tenant_id: ' . $tenant_id);
                    }
                }
            }
        } elseif (($role === 'admin' || $role === 'manager') && isset($_POST['action'])) {
            if ($_POST['action'] === 'update') {
                $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
                $status = filter_input(INPUT_POST, 'status', FILTER_UNSAFE_RAW);
                $cost = filter_input(INPUT_POST, 'cost', FILTER_VALIDATE_FLOAT);
                $notes = sanitizeInput(filter_input(INPUT_POST, 'notes', FILTER_UNSAFE_RAW));

                if ($cost === false || $cost < 0 || $cost > 99999999.99) {
                    $cost = 0.00;
                } else {
                    $cost = round($cost, 2);
                }

                if (!$request_id || !in_array($status, ['pending', 'in_progress', 'completed'])) {
                    flash('error', 'Invalid update data.');
                    logError('Invalid update data for request_id: ' . $request_id);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE maintenance_requests 
                        SET status = ?, cost = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    if ($stmt->execute([$status, $cost, $notes, $request_id])) {
                        flash('success', 'Maintenance request updated successfully.');
                    } else {
                        flash('error', 'Error updating request.');
                        logError('Failed to update request_id: ' . $request_id);
                    }
                }
            } elseif ($_POST['action'] === 'delete') {
                $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
                if ($request_id) {
                    $stmt = $pdo->prepare("DELETE FROM maintenance_files WHERE request_id = ?");
                    $stmt->execute([$request_id]);

                    $stmt = $pdo->prepare("DELETE FROM maintenance_requests WHERE id = ?");
                    if ($stmt->execute([$request_id])) {
                        flash('success', 'Maintenance request deleted successfully.');
                    } else {
                        flash('error', 'Error deleting request.');
                        logError('Failed to delete request_id: ' . $request_id);
                    }
                } else {
                    flash('error', 'Invalid request ID.');
                    logError('Invalid request_id for deletion');
                }
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        flash('error', 'Database error: ' . htmlspecialchars($e->getMessage()));
        logError('Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'Error: ' . htmlspecialchars($e->getMessage()));
        logError('General error: ' . $e->getMessage());
    }

    header("Location: maintenance.php");
    exit;
}

// Fetch units for tenant
$units = [];
if ($role === 'tenant') {
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.unit_name
            FROM units u
            JOIN leases l ON u.id = l.unit_id
            JOIN tenants t ON l.tenant_id = t.id
            WHERE t.user_id = ?
        ");
        $stmt->execute([$user_id]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($units)) {
            flash('error', 'No units assigned to your account. Contact the property manager.');
            logError('No units found for tenant_id: ' . $user_id);
        }
    } catch (PDOException $e) {
        flash('error', 'Error fetching units: ' . htmlspecialchars($e->getMessage()));
        logError('Error fetching units for tenant_id: ' . $user_id . ': ' . $e->getMessage());
    }
}

// Handle search/filter for admin/manager
$search = isset($_GET['search']) ? sanitizeInput(filter_input(INPUT_GET, 'search', FILTER_UNSAFE_RAW)) : '';
$status_filter = isset($_GET['status']) && in_array($_GET['status'], ['pending', 'in_progress', 'completed', 'all']) ? $_GET['status'] : 'all';

// Fetch maintenance requests with pagination
$perPage = 10;
$page = isset($_GET['page']) && filter_var($_GET['page'], FILTER_VALIDATE_INT) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

try {
    $query = "
        SELECT SQL_CALC_FOUND_ROWS mr.id, mr.description, mr.status, mr.cost, mr.priority, mr.notes,
               mr.created_at, mr.updated_at, u.unit_name, t.full_name
        FROM maintenance_requests mr
        JOIN units u ON mr.unit_id = u.id
        JOIN tenants t ON mr.tenant_id = t.id
    ";
    $params = [];
    $conditions = [];

    if ($role === 'tenant') {
        $conditions[] = "t.user_id = ?";
        $params[] = [$user_id, PDO::PARAM_INT];
    }

    if ($search) {
        $conditions[] = "(mr.description LIKE ? OR t.full_name LIKE ?)";
        $params[] = ['%' . $search . '%', PDO::PARAM_STR];
        $params[] = ['%' . $search . '%', PDO::PARAM_STR];
    }

    if ($status_filter !== 'all') {
        $conditions[] = "mr.status = ?";
        $params[] = [$status_filter, PDO::PARAM_STR];
    }

    if ($conditions) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }

    $query .= " ORDER BY mr.created_at DESC LIMIT ? OFFSET ?";
    $params[] = [$perPage, PDO::PARAM_INT];
    $params[] = [$offset, PDO::PARAM_INT];

    $stmt = $pdo->prepare($query);
    foreach ($params as $index => $param) {
        $stmt->bindValue($index + 1, $param[0], $param[1]);
    }
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStmt = $pdo->query("SELECT FOUND_ROWS()");
    $totalRows = (int)$totalStmt->fetchColumn();
    $totalPages = ceil($totalRows / $perPage);

    $attachments = [];
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'maintenance_files'");
    if ($tableCheck->rowCount() > 0) {
        foreach ($requests as $req) {
            $stmt = $pdo->prepare("SELECT id, file_path, created_at FROM maintenance_files WHERE request_id = ?");
            $stmt->execute([$req['id']]);
            $attachments[$req['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    flash('error', 'Database error: ' . htmlspecialchars($e->getMessage()));
    logError('Database error in fetching requests: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-responsive { max-height: 600px; }
        .form-control-sm, .form-select-sm { min-width: 100px; }
        .badge { font-size: 0.9em; }
        .truncate { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1>Maintenance Requests</h1>

        <!-- Flash messages -->
        <?php if ($msg = flash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($msg = flash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($role === 'tenant'): ?>
        <h2>Submit New Request</h2>
        <?php if (empty($units)): ?>
            <div class="alert alert-warning">
                No units are assigned to your account. Please contact the property manager to assign a lease.
            </div>
        <?php else: ?>
        <form method="POST" action="maintenance.php" id="maintenanceForm" class="mb-4" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="create">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="unit_id" class="form-label">Unit</label>
                    <select id="unit_id" name="unit_id" class="form-select" required>
                        <option value="">Select Unit</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= htmlspecialchars($unit['id']) ?>"><?= htmlspecialchars($unit['unit_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="priority" class="form-label">Priority</label>
                    <select id="priority" name="priority" class="form-select" required>
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Description (max 1000 characters)</label>
                <textarea id="description" name="description" class="form-control" rows="4" required maxlength="1000"></textarea>
            </div>
            <div class="mb-3">
                <label for="attachment" class="form-label">Attachment (JPEG, PNG, PDF, max 5MB)</label>
                <input type="file" id="attachment" name="attachment" class="form-control" accept="image/jpeg,image/png,application/pdf">
            </div>
            <button type="submit" class="btn btn-primary">Submit Request</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'manager'): ?>
        <h2>Filter Requests</h2>
        <form method="GET" action="maintenance.php" class="mb-4">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="search" class="form-label">Search by Description or Tenant</label>
                    <input type="text" id="search" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Enter description or tenant name">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
                <div class="col-md-2 mb-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <h2>Existing Requests</h2>
        <div class="table-responsive">
            <table class="table table-striped table-bordered align-middle">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <?php if ($role !== 'tenant'): ?>
                            <th>Tenant</th>
                        <?php endif; ?>
                        <th>Description</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Cost</th>
                        <th>Notes</th>
                        <th>Attachments</th>
                        <th>Created</th>
                        <?php if ($role === 'admin' || $role === 'manager'): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="<?= $role !== 'tenant' ? 10 : 8 ?>" class="text-center">No maintenance requests found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <td><?= htmlspecialchars($req['unit_name']) ?></td>
                            <?php if ($role !== 'tenant'): ?>
                                <td><?= htmlspecialchars($req['full_name']) ?></td>
                            <?php endif; ?>
                            <td class="truncate" title="<?= htmlspecialchars($req['description']) ?>"><?= htmlspecialchars(substr($req['description'], 0, 100)) . (strlen($req['description']) > 100 ? '...' : '') ?></td>
                            <td><?= priorityBadge($req['priority']) ?></td>
                            <td><?= statusBadge($req['status']) ?></td>
                            <td>TZS <?= htmlspecialchars(number_format((float)$req['cost'], 2)) ?></td>
                            <td class="truncate" title="<?= htmlspecialchars($req['notes'] ?? '') ?>"><?= htmlspecialchars(substr($req['notes'] ?? '', 0, 50)) . (strlen($req['notes'] ?? '') > 50 ? '...' : '') ?></td>
                            <td>
                                <?php if (!empty($attachments[$req['id']])): ?>
                                    <?php foreach ($attachments[$req['id']] as $file): ?>
                                        <a href="../uploads/maintenance/<?= htmlspecialchars($file['file_path']) ?>" target="_blank" class="d-block">View (<?= htmlspecialchars(date('m/d/Y H:i', strtotime($file['created_at']))) ?>)</a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    None
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(date('m/d/Y H:i', strtotime($req['created_at']))) ?></td>
                            <?php if ($role === 'admin' || $role === 'manager'): ?>
                            <td>
                                <form method="POST" action="maintenance.php" class="d-flex align-items-center gap-2 mb-2">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="request_id" value="<?= htmlspecialchars($req['id']) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <select name="status" class="form-select form-select-sm" required>
                                        <option value="pending" <?= $req['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="in_progress" <?= $req['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                        <option value="completed" <?= $req['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    </select>
                                    <input type="number" step="0.01" min="0" max="99999999.99" name="cost" class="form-control form-control-sm" style="max-width:100px;" value="<?= htmlspecialchars(number_format($req['cost'], 2)) ?>" placeholder="Cost">
                                    <input type="text" name="notes" class="form-control form-control-sm" style="max-width:150px;" value="<?= htmlspecialchars($req['notes'] ?? '') ?>" placeholder="Notes" maxlength="255">
                                    <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                </form>
                                <form method="POST" action="maintenance.php">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="request_id" value="<?= htmlspecialchars($req['id']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this request?')">Delete</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>">Previous</a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>">Next</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add tooltip for truncated text
        document.querySelectorAll('.truncate').forEach(el => {
            el.addEventListener('mouseover', function() {
                this.setAttribute('data-bs-toggle', 'tooltip');
                this.setAttribute('data-bs-placement', 'top');
                new bootstrap.Tooltip(this);
            });
        });

        // Log form submission for debugging
        document.getElementById('maintenanceForm')?.addEventListener('submit', function(e) {
            console.log('Form submitted with values:', new FormData(this));
        });
    </script>
    <?php include '../templates/footer.php'; ?>
</body>
</html>