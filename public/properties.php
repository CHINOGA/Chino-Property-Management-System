<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once '../config/db.php';
include '../templates/header.php';

// Handle form submissions for add/edit/delete property and units
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        try {
            if ($action === 'add_property') {
                $name = $_POST['name'] ?? '';
                $address = $_POST['address'] ?? '';
                $description = $_POST['description'] ?? '';

                $stmt = $pdo->prepare("INSERT INTO properties (name, address, description) VALUES (?, ?, ?)");
                $stmt->execute([$name, $address, $description]);
                $message = "Property added successfully.";
            } elseif ($action === 'edit_property') {
                $id = $_POST['property_id'] ?? 0;
                $name = $_POST['name'] ?? '';
                $address = $_POST['address'] ?? '';
                $description = $_POST['description'] ?? '';

                $stmt = $pdo->prepare("UPDATE properties SET name = ?, address = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $address, $description, $id]);
                $message = "Property updated successfully.";
            } elseif ($action === 'delete_property') {
                $id = $_POST['property_id'] ?? 0;
                $stmt = $pdo->prepare("DELETE FROM properties WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Property deleted successfully.";
            } elseif ($action === 'add_unit') {
                $property_id = $_POST['property_id'] ?? 0;
                $unit_name = $_POST['unit_name'] ?? '';
                $rent_amount = $_POST['rent_amount'] ?? 0;
                $occupancy_status = $_POST['occupancy_status'] ?? 'vacant';

                $stmt = $pdo->prepare("INSERT INTO units (property_id, unit_name, rent_amount, occupancy_status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$property_id, $unit_name, $rent_amount, $occupancy_status]);
                $message = "Unit added successfully.";
            } elseif ($action === 'edit_unit') {
                $unit_id = $_POST['unit_id'] ?? 0;
                $unit_name = $_POST['unit_name'] ?? '';
                $rent_amount = $_POST['rent_amount'] ?? 0;
                $occupancy_status = $_POST['occupancy_status'] ?? 'vacant';

                $stmt = $pdo->prepare("UPDATE units SET unit_name = ?, rent_amount = ?, occupancy_status = ? WHERE id = ?");
                $stmt->execute([$unit_name, $rent_amount, $occupancy_status, $unit_id]);
                $message = "Unit updated successfully.";
            } elseif ($action === 'delete_unit') {
                $unit_id = $_POST['unit_id'] ?? 0;
                $stmt = $pdo->prepare("DELETE FROM units WHERE id = ?");
                $stmt->execute([$unit_id]);
                $message = "Unit deleted successfully.";
            }
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
}

// Fetch properties and their units
try {
    $stmt = $pdo->query("SELECT * FROM properties ORDER BY created_at DESC");
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unitsStmt = $pdo->prepare("SELECT * FROM units WHERE property_id = ? ORDER BY created_at DESC");
} catch (PDOException $e) {
    $properties = [];
    $message = "Error fetching properties: " . $e->getMessage();
}
?>

<h1>Property & Unit Management</h1>

<?php if ($message): ?>
    <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- Add Property Form -->
<h2>Add New Property</h2>
<form method="post" class="mb-4">
    <input type="hidden" name="action" value="add_property">
    <div class="mb-3">
        <label for="name" class="form-label">Property Name</label>
        <input type="text" class="form-control" id="name" name="name" required>
    </div>
    <div class="mb-3">
        <label for="address" class="form-label">Address</label>
        <textarea class="form-control" id="address" name="address" rows="2" required></textarea>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">Description (optional)</label>
        <textarea class="form-control" id="description" name="description" rows="2"></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Add Property</button>
</form>

<!-- List Properties and Units -->
<?php foreach ($properties as $property): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3><?= htmlspecialchars($property['name']) ?></h3>
            <form method="post" onsubmit="return confirm('Delete this property and all its units?');" style="margin:0;">
                <input type="hidden" name="action" value="delete_property">
                <input type="hidden" name="property_id" value="<?= $property['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete Property</button>
            </form>
        </div>
        <div class="card-body">
            <p><strong>Address:</strong> <?= nl2br(htmlspecialchars($property['address'])) ?></p>
            <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($property['description'])) ?></p>

            <!-- Edit Property Form -->
            <button class="btn btn-secondary mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#editProperty<?= $property['id'] ?>" aria-expanded="false" aria-controls="editProperty<?= $property['id'] ?>">
                Edit Property
            </button>
            <div class="collapse" id="editProperty<?= $property['id'] ?>">
                <form method="post" class="mb-3">
                    <input type="hidden" name="action" value="edit_property">
                    <input type="hidden" name="property_id" value="<?= $property['id'] ?>">
                    <div class="mb-3">
                        <label for="name<?= $property['id'] ?>" class="form-label">Property Name</label>
                        <input type="text" class="form-control" id="name<?= $property['id'] ?>" name="name" value="<?= htmlspecialchars($property['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="address<?= $property['id'] ?>" class="form-label">Address</label>
                        <textarea class="form-control" id="address<?= $property['id'] ?>" name="address" rows="2" required><?= htmlspecialchars($property['address']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="description<?= $property['id'] ?>" class="form-label">Description (optional)</label>
                        <textarea class="form-control" id="description<?= $property['id'] ?>" name="description" rows="2"><?= htmlspecialchars($property['description']) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Property</button>
                </form>
            </div>

            <!-- Units List -->
            <h4>Units</h4>
            <?php
            $unitsStmt->execute([$property['id']]);
            $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Unit Name</th>
                        <th>Rent Amount (TZS)</th>
                        <th>Occupancy Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($units as $unit): ?>
                        <tr>
                            <td><?= htmlspecialchars($unit['unit_name']) ?></td>
                            <td><?= number_format($unit['rent_amount'], 2) ?></td>
                            <td><?= htmlspecialchars(ucfirst($unit['occupancy_status'])) ?></td>
                            <td>
                                <!-- Edit Unit Button triggers collapse -->
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#editUnit<?= $unit['id'] ?>" aria-expanded="false" aria-controls="editUnit<?= $unit['id'] ?>">
                                    Edit
                                </button>
                                <!-- Delete Unit Form -->
                                <form method="post" onsubmit="return confirm('Delete this unit?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_unit">
                                    <input type="hidden" name="unit_id" value="<?= $unit['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                                <!-- Edit Unit Form -->
                                <div class="collapse mt-2" id="editUnit<?= $unit['id'] ?>">
                                    <form method="post" class="mb-3">
                                        <input type="hidden" name="action" value="edit_unit">
                                        <input type="hidden" name="unit_id" value="<?= $unit['id'] ?>">
                                        <div class="mb-3">
                                            <label for="unit_name<?= $unit['id'] ?>" class="form-label">Unit Name</label>
                                            <input type="text" class="form-control" id="unit_name<?= $unit['id'] ?>" name="unit_name" value="<?= htmlspecialchars($unit['unit_name']) ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="rent_amount<?= $unit['id'] ?>" class="form-label">Rent Amount (TZS)</label>
                                            <input type="number" step="0.01" class="form-control" id="rent_amount<?= $unit['id'] ?>" name="rent_amount" value="<?= htmlspecialchars($unit['rent_amount']) ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="occupancy_status<?= $unit['id'] ?>" class="form-label">Occupancy Status</label>
                                            <select class="form-select" id="occupancy_status<?= $unit['id'] ?>" name="occupancy_status" required>
                                                <option value="vacant" <?= $unit['occupancy_status'] === 'vacant' ? 'selected' : '' ?>>Vacant</option>
                                                <option value="occupied" <?= $unit['occupancy_status'] === 'occupied' ? 'selected' : '' ?>>Occupied</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-primary">Update Unit</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Add Unit Form -->
            <button class="btn btn-primary mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#addUnit<?= $property['id'] ?>" aria-expanded="false" aria-controls="addUnit<?= $property['id'] ?>">
                Add Unit
            </button>
            <div class="collapse" id="addUnit<?= $property['id'] ?>">
                <form method="post" class="mb-3" enctype="multipart/form-data" action="upload.php">
                    <input type="hidden" name="action" value="add_unit">
                    <input type="hidden" name="property_id" value="<?= $property['id'] ?>">
                    <div class="mb-3">
                        <label for="unit_name_new<?= $property['id'] ?>" class="form-label">Unit Name</label>
                        <input type="text" class="form-control" id="unit_name_new<?= $property['id'] ?>" name="unit_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="rent_amount_new<?= $property['id'] ?>" class="form-label">Rent Amount (TZS)</label>
                        <input type="number" step="0.01" class="form-control" id="rent_amount_new<?= $property['id'] ?>" name="rent_amount" required>
                    </div>
                    <div class="mb-3">
                        <label for="occupancy_status_new<?= $property['id'] ?>" class="form-label">Occupancy Status</label>
                        <select class="form-select" id="occupancy_status_new<?= $property['id'] ?>" name="occupancy_status" required>
                            <option value="vacant" selected>Vacant</option>
                            <option value="occupied">Occupied</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="unit_document_new<?= $property['id'] ?>" class="form-label">Upload Document</label>
                        <input type="file" class="form-control" id="unit_document_new<?= $property['id'] ?>" name="file" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                        <input type="hidden" name="type" value="unit">
                    </div>
                    <button type="submit" class="btn btn-success">Add Unit</button>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php include '../templates/footer.php'; ?>
