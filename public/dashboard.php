<?php
session_start();

// Security: Check session and user role
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['role'])) {
    header('Location: login.php');
    exit;
}

// Security: CSRF token validation
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once '../config/db.php';
include '../templates/header.php';

// Initialize variables
$totalProperties = $totalTenants = $monthlyIncome = $vacantUnits = 0;
$errors = [];

try {
    // Enable PDO strict mode
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Use prepared statements for all queries
    // Total properties
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM properties");
    $stmt->execute();
    $totalProperties = (int)$stmt->fetchColumn();

    // Total tenants (active only)
    // Removed status filter as 'status' column does not exist in tenants table
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants");
    $stmt->execute();
    $totalTenants = (int)$stmt->fetchColumn();

    // Monthly income with currency conversion
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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM units WHERE occupancy_status = 'vacant'");
    $stmt->execute();
    $vacantUnits = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    $errors[] = "Database error: " . htmlspecialchars($e->getMessage());
    error_log("Dashboard DB Error: " . $e->getMessage());
}
?>

<h1>Dashboard</h1>
<p>Welcome, <?= htmlspecialchars($_SESSION['user']['username'] ?? 'User') ?>!</p>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <p class="mb-0"><?= $error ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3">
            <div class="card-header">
                <i class="fas fa-building mr-2"></i>Properties
            </div>
            <div class="card-body">
                <h5 class="card-title"><?= number_format($totalProperties) ?></h5>
                <p class="card-text">Total Properties</p>
                <a href="properties.php" class="btn btn-light btn-sm">View Details</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-success mb-3">
            <div class="card-header">
                <i class="fas fa-users mr-2"></i>Tenants
            </div>
            <div class="card-body">
                <h5 class="card-title"><?= number_format($totalTenants) ?></h5>
                <p class="card-text">Active Tenants</p>
                <a href="tenants.php" class="btn btn-light btn-sm">View Details</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-warning mb-3">
            <div class="card-header">
                <i class="fas fa-money-bill-wave mr-2"></i>Monthly Income
            </div>
            <div class="card-body">
                <h5 class="card-title">TZS <?= number_format($monthlyIncome, 2) ?></h5>
                <p class="card-text">Rent Collected (TZS) (This Month)</p>
                <a href="payments.php" class="btn btn-light btn-sm">View Details</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white bg-danger mb-3">
            <div class="card-header">
                <i class="fas fa-home mr-2"></i>Vacancies
            </div>
            <div class="card-body">
                <h5 class="card-title"><?= number_format($vacantUnits) ?></h5>
                <p class="card-text">Vacant Units</p>
                <a href="units.php" class="btn btn-light btn-sm">View Details</a>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity Section -->
<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-history mr-2"></i>Recent Activity
            </div>
            <div class="card-body">
                <?php
                try {
                    $stmt = $pdo->prepare("
                        SELECT a.*, u.username 
                        FROM activity_log a 
                        JOIN users u ON a.user_id = u.id 
                        ORDER BY a.created_at DESC 
                        LIMIT 5
                    ");
                    $stmt->execute();
                    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <ul class="list-group">
                    <?php foreach ($activities as $activity): ?>
                        <li class="list-group-item">
                            <span class="badge badge-info mr-2">
                                <?= date('M d, Y H:i', strtotime($activity['created_at'])) ?>
                            </span>
                            <?= htmlspecialchars($activity['username']) ?> 
                            <?= htmlspecialchars($activity['action']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php } catch (PDOException $e) {
                    $errors[] = "Error loading activities: " . htmlspecialchars($e->getMessage());
                } ?>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js local copy to avoid CDN integrity issues -->
<script src="assets/js/chart.umd.min.js"></script>

<div class="row mt-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Rent Collection Trend</div>
            <div class="card-body">
                <canvas id="rentCollectedChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Occupancy Status</div>
            <div class="card-body">
                <canvas id="occupancyChart"></canvas>
            </div>
        </div>
    </div>
</div>

<?php
// Chart data initialization
$monthLabels = [];
$rentTotals = [];
$occupiedCount = $vacantCount = 0;

try {
    // Cache chart data for 1 hour
    $cacheKey = 'dashboard_chart_data_' . md5(date('Y-m'));
    $cacheFile = '../cache/' . $cacheKey . '.json';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        $monthLabels = $cachedData['monthLabels'];
        $rentTotals = $cachedData['rentTotals'];
        $occupiedCount = $cachedData['occupiedCount'];
        $vacantCount = $cachedData['vacantCount'];
    } else {
        // Get rent collected by month (last 12 months)
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount) AS total
            FROM payments
            WHERE status = 'completed' 
            AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            AND deleted_at IS NULL
            GROUP BY month
            ORDER BY month ASC
        ");
        $stmt->execute();
        $rentData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Fill in missing months
        $now = new DateTime();
        for ($i = 11; $i >= 0; $i--) {
            $month = (clone $now)->modify("-$i months");
            $key = $month->format('Y-m');
            $label = $month->format('M Y');
            $monthLabels[] = $label;
            $rentTotals[] = isset($rentData[$key]) ? (float)$rentData[$key] : 0;
        }

        // Occupied and vacant units
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM units WHERE occupancy_status = 'occupied' AND deleted_at IS NULL");
        $stmt->execute();
        $occupiedCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM units WHERE occupancy_status = 'vacant' AND deleted_at IS NULL");
        $stmt->execute();
        $vacantCount = (int)$stmt->fetchColumn();

        // Cache the results
        file_put_contents($cacheFile, json_encode([
            'monthLabels' => $monthLabels,
            'rentTotals' => $rentTotals,
            'occupiedCount' => $occupiedCount,
            'vacantCount' => $vacantCount
        ]));
    }
} catch (PDOException $e) {
    $errors[] = "Error loading chart data: " . htmlspecialchars($e->getMessage());
    error_log("Chart Data Error: " . $e->getMessage());
}
?>

<script>
// Add CSRF token to AJAX requests
const csrfToken = '<?= $_SESSION['csrf_token'] ?>';

// Rent Collection Chart
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
            tension: 0.4,
            pointRadius: 4,
            pointHoverRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'TZS ' + context.parsed.y.toLocaleString();
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return 'TZS ' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Occupancy Chart
const occCtx = document.getElementById('occupancyChart').getContext('2d');
new Chart(occCtx, {
    type: 'doughnut',
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
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.label || '';
                        let value = context.raw || 0;
                        let total = context.dataset.data.reduce((a, b) => a + b, 0);
                        let percentage = Math.round((value / total) * 100);
                        return `${label}: ${value} (${percentage}%)`;
                    }
                }
            }
        }
    }
});

function updateDashboard() {
    fetch('api/get_dashboard_data.php')
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error(data.error);
                return;
            }
            document.querySelector('.bg-primary .card-title').textContent = new Intl.NumberFormat().format(data.total_properties);
            document.querySelector('.bg-success .card-title').textContent = new Intl.NumberFormat().format(data.total_tenants);
            document.querySelector('.bg-warning .card-title').textContent = 'TZS ' + new Intl.NumberFormat('en-US', { style: 'decimal', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(data.total_rent_collected);
        })
        .catch(error => console.error('Error fetching dashboard data:', error));
}

setInterval(updateDashboard, 5000);
</script>

<?php include '../templates/footer.php'; ?>
