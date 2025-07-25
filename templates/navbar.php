<?php
session_start();
$user = $_SESSION['user']['username'] ?? null;
$role = $_SESSION['user']['role'] ?? null;

if (!$user) {
    // User not logged in, do not show navbar
    return;
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
        <li class="nav-item">
          <a class="nav-link" href="dashboard.php">Dashboard</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="properties.php">Properties & Units</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="tenants.php">Tenants & Leases</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="rent_collection.php">Rent Collection</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="maintenance.php">Maintenance</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="reports.php">Reports</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="notifications.php">Notifications</a>
        </li>
      </ul>
      <span class="navbar-text me-3">
        Logged in as: <?= htmlspecialchars($user) ?>
      </span>
      <a class="btn btn-outline-light" href="logout.php">Logout</a>
    </div>
  </div>
</nav>
