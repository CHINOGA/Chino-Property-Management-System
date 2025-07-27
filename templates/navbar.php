<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$user = $_SESSION['user']['username'] ?? null;
$role = $_SESSION['user']['role'] ?? null;

if (!$user) {
    // User not logged in, do not show navbar
    return;
}

// DEBUG: Output user role and access list for troubleshooting
// Uncomment below lines to debug role and access
echo "<!-- User role: " . htmlspecialchars($role) . " -->\n";
echo "<!-- Access list: " . implode(', ', array_keys(array_flip(hasAccess($role, 'dashboard') ? ['dashboard', 'properties', 'tenants', 'rent_collection', 'maintenance', 'reports', 'notifications', 'rent_payment_reminders'] : []))) . " -->\n";

function hasAccess($role, $menuItem) {
    $access = [
        'admin' => ['dashboard', 'properties', 'tenants', 'rent_collection', 'maintenance', 'reports', 'user_management', 'rent_payment_reminders'],
        'manager' => ['dashboard', 'properties', 'tenants', 'rent_collection', 'maintenance', 'reports', 'user_management', 'rent_payment_reminders'],
        'user' => ['dashboard'],
        'tenant' => ['dashboard']
    ];

    // Restrict tenants from accessing add pages
    if (strtolower($role) === 'tenant') {
        $restricted = ['properties', 'tenants'];
        if (in_array($menuItem, $restricted)) {
            return false;
        }
    }

    return in_array($menuItem, $access[strtolower($role)] ?? []);
}
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">PMS</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
      aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if (hasAccess($role, 'dashboard')): ?>
        <li class="nav-item">
          <a class="nav-link" href="dashboard.php">Dashboard</a>
        </li>
        <?php endif; ?>
        <?php if (hasAccess($role, 'properties')): ?>
        <li class="nav-item">
          <a class="nav-link" href="properties.php">Properties & Units</a>
        </li>
        <?php endif; ?>
        <?php if (hasAccess($role, 'tenants')): ?>
        <li class="nav-item">
          <a class="nav-link" href="tenants.php">Tenants & Leases</a>
        </li>
        <?php endif; ?>
        <?php if (hasAccess($role, 'user_management')): ?>
        <li class="nav-item">
          <a class="nav-link" href="user_management.php">User Management</a>
        </li>
        <?php endif; ?>
        <?php if (hasAccess($role, 'rent_collection')): ?>
        <li class="nav-item">
          <a class="nav-link" href="rent_collection.php">Rent Collection</a>
        </li>
        <?php endif; ?>
        <?php if (hasAccess($role, 'maintenance')): ?>
        <li class="nav-item">
          <a class="nav-link" href="maintenance.php">Maintenance</a>
        </li>
        <?php endif; ?>
        <?php if (hasAccess($role, 'reports')): ?>
        <li class="nav-item">
          <a class="nav-link" href="reports.php">Reports</a>
        </li>
        <?php endif; ?>
        <?php if (hasAccess($role, 'rent_payment_reminders')): ?>
        <li class="nav-item">
          <a class="nav-link" href="rent_payment_reminders.php">Rent Payment Reminders</a>
        </li>
        <?php endif; ?>
      </ul>
      <span class="navbar-text me-3">
        Logged in as: <?= htmlspecialchars($user) ?>
      </span>
      <a class="btn btn-outline-light" href="logout.php">Logout</a>
    </div>
  </div>
</nav>
