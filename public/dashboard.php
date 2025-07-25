<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once '../config/db.php';
include '../templates/header.php';

// Initialize
$totalProperties = $totalTenants = $monthlyIncome = $vacantUnits = 0;

try {
    // Total properties
    $stmt = $pdo->query("SELECT COUNT(*) FROM properties");
    $totalProperties = (int)$stmt->fetchColumn();

    // Total tenants
    $stmt = $pdo->query("SELECT COUNT(*) FROM tenants");
    $totalTenants = (int)$stmt->fetchColumn();

    // Monthly income
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM payments 
        WHERE status = 'completed' 
        AND MONTH(payment_date) = MONTH(CURRENT_DATE()) 
        AND YEAR(payment_date) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute();
    $monthlyIncome = (float)$stmt->fetchColumn();

    // Vacant units
    $stmt = $pdo->query("SELECT COUNT(*) FROM units WHERE occupancy_status = 'vacant'");
    $vacantUnits = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error fetching dashboard data: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>

<h1>Dashboard</h1>
<p>Welcome, <?= htmlspecialchars($_SESSION['user']['username'] ?? '') ?>!</p>

<div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3">
            <div class="card-header">Properties</div>
            <div class="card-body">
                <h5 class="card-title"><?= $totalProperties ?></h5>
                <p class="card-text">Total Properties</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-success mb-3">
            <div class="card-header">Tenants</div>
            <div class="card-body">
                <h5 class="card-title"><?= $totalTenants ?></h5>
                <p class="card-text">Total Tenants</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-warning mb-3">
            <div class="card-header">Monthly Income</div>
            <div class="card-body">
                <h5 class="card-title">TZS <?= number_format($monthlyIncome, 2) ?></h5>
                <p class="card-text">Rent Collected</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-danger mb-3">
            <div class="card-header">Vacancies</div>
            <div class="card-body">
                <h5 class="card-title"><?= $vacantUnits ?></h5>
                <p class="card-text">Vacant Units</p>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="row mt-4">
    <div class="col-md-6">
        <canvas id="rentCollectedChart"></canvas>
    </div>
    <div class="col-md-6">
        <canvas id="occupancyChart"></canvas>
    </div>
</div>

<?php
// Chart data initialization
$monthLabels = [];
$rentTotals = [];
$occupiedCount = $vacantCount = 0;

try {
    // Get rent collected by month (last 12 months)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount) AS total
        FROM payments
        WHERE status = 'completed' AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month
        ORDER BY month ASC
    ");
    $stmt->execute();
    $rentData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['2025-06' => 400000, ...]

    // Fill in any missing months
    $now = new DateTime();
    for ($i = 11; $i >= 0; $i--) {
        $month = (clone $now)->modify("-$i months");
        $key = $month->format('Y-m');
        $label = $month->format('M Y');
        $monthLabels[] = $label;
        $rentTotals[] = isset($rentData[$key]) ? (float)$rentData[$key] : 0;
    }

    // Occupied and vacant units
    $stmt = $pdo->query("SELECT COUNT(*) FROM units WHERE occupancy_status = 'occupied'");
    $occupiedCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM units WHERE occupancy_status = 'vacant'");
    $vacantCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error loading chart data: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>

<script>
const rentCtx = document.getElementById('rentCollectedChart').getContext('2d');
new Chart(rentCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($monthLabels) ?>,
        datasets: [{
            label: 'Rent Collected (TZS)',
            data: <?= json_encode($rentTotals) ?>,
            backgroundColor: 'rgba(54, 162, 235, 0.2)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

const occCtx = document.getElementById('occupancyChart').getContext('2d');
new Chart(occCtx, {
    type: 'pie',
    data: {
        labels: ['Occupied Units', 'Vacant Units'],
        datasets: [{
            data: [<?= $occupiedCount ?>, <?= $vacantCount ?>],
            backgroundColor: [
                'rgba(40, 167, 69, 0.7)',
                'rgba(220, 53, 69, 0.7)'
            ],
            borderColor: [
                'rgba(40, 167, 69, 1)',
                'rgba(220, 53, 69, 1)'
            ],
            borderWidth: 1
        }]
    },
    options: {
        responsive: true
    }
});
</script>

<?php include '../templates/footer.php'; ?>
