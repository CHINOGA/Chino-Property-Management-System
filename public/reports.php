<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

// AJAX handler for aggregated report data
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');

    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;
    $interval = $_GET['interval'] ?? 'month';

    if (!$start_date || !$end_date || !in_array($interval, ['month', 'year'])) {
        echo json_encode(['error' => 'Invalid parameters']);
        exit;
    }

    try {
        $period_format = ($interval === 'year') ? '%Y' : '%Y-%m';

        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(payment_date, '$period_format') AS period,
                   COALESCE(SUM(amount), 0) AS total_amount
            FROM rent_payments
            WHERE payment_date BETWEEN ? AND ?
            GROUP BY period
            ORDER BY period ASC
        ");
        $stmt->execute([$start_date, $end_date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map period => total_amount
        $totalsMap = [];
        foreach ($rows as $row) {
            $totalsMap[$row['period']] = (float)$row['total_amount'];
        }

        // Build periods array from start to end with zero-fill
        $periods = [];
        $amounts = [];

        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        if ($interval === 'year') {
            $end->modify('+1 year');
            $step = new DateInterval('P1Y');
            $labelFormat = 'Y';
        } else {
            $end->modify('+1 month');
            $step = new DateInterval('P1M');
            $labelFormat = 'M Y';
        }

        $current = clone $start;
        while ($current < $end) {
            $key = $current->format($interval === 'year' ? 'Y' : 'Y-m');
            $label = $current->format($labelFormat);

            $periods[] = $label;
            $amounts[] = $totalsMap[$key] ?? 0;

            $current->add($step);
        }

        $totalCollected = array_sum($amounts);

        echo json_encode([
            'labels' => $periods,
            'amounts' => $amounts,
            'totalCollected' => $totalCollected
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

include '../templates/header.php';

// Fetch all payments for real-time search
try {
    $stmt = $pdo->query("
        SELECT rp.amount, rp.payment_date, rp.collected_by, t.full_name, u.unit_name
        FROM rent_payments rp
        JOIN tenants t ON rp.tenant_id = t.id
        JOIN leases l ON t.id = l.tenant_id
        JOIN units u ON l.unit_id = u.id
        ORDER BY rp.payment_date DESC
        LIMIT 100
    ");
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $payments = [];
}

?>

<div class="container mt-4">
    <h1>Rent Payments Reports</h1>

    <!-- Real-time Search Section -->
    <section>
        <h2>All Payments (Real-Time Search)</h2>
        <input type="text" id="searchInput" placeholder="Search payments..." class="form-control mb-3">

        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="paymentsTable">
                <thead>
                    <tr>
                        <th>Amount (TZS)</th>
                        <th>Payment Date</th>
                        <th>Collected By</th>
                        <th>Tenant</th>
                        <th>Unit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= number_format($p['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($p['payment_date']) ?></td>
                        <td><?= htmlspecialchars($p['collected_by']) ?></td>
                        <td><?= htmlspecialchars($p['full_name']) ?></td>
                        <td><?= htmlspecialchars($p['unit_name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <hr>

    <!-- Custom Date Range Report Section -->
    <section>
        <h2>Custom Date Range Report</h2>

        <div class="row mb-3">
            <div class="col-md-4">
                <label for="startDate" class="form-label">Start Date</label>
                <input type="date" id="startDate" class="form-control" value="<?= date('Y-m-d', strtotime('-1 year')) ?>">
            </div>
            <div class="col-md-4">
                <label for="endDate" class="form-label">End Date</label>
                <input type="date" id="endDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
                <label for="intervalSelect" class="form-label">Interval</label>
                <select id="intervalSelect" class="form-select">
                    <option value="month" selected>Monthly</option>
                    <option value="year">Yearly</option>
                </select>
            </div>
        </div>

        <p><strong>Total Collected:</strong> TZS <span id="totalCollectedDisplay">Loading...</span></p>

        <canvas id="customReportChart" height="150"></canvas>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Real-time search filter for payments table
    const searchInput = document.getElementById('searchInput');
    const paymentsTable = document.getElementById('paymentsTable').getElementsByTagName('tbody')[0];

    searchInput.addEventListener('input', () => {
        const filter = searchInput.value.toLowerCase();
        const rows = paymentsTable.getElementsByTagName('tr');
        for (let row of rows) {
            const rowText = row.textContent.toLowerCase();
            row.style.display = rowText.includes(filter) ? '' : 'none';
        }
    });

    // Chart.js initialization
    const ctx = document.getElementById('customReportChart').getContext('2d');
    let chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Rent Collected (TZS)',
                data: [],
                backgroundColor: '#007bff'
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

    // Fetch and update aggregated report data
    async function fetchReportData() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        const interval = document.getElementById('intervalSelect').value;

        if (!startDate || !endDate) {
            return;
        }

        const params = new URLSearchParams({
            ajax: '1',
            start_date: startDate,
            end_date: endDate,
            interval: interval
        });

        try {
            const response = await fetch('reports.php?' + params.toString());
            const data = await response.json();

            if (data.error) {
                document.getElementById('totalCollectedDisplay').textContent = data.error;
                chart.data.labels = [];
                chart.data.datasets[0].data = [];
                chart.update();
                return;
            }

            document.getElementById('totalCollectedDisplay').textContent = data.totalCollected.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            chart.data.labels = data.labels;
            chart.data.datasets[0].data = data.amounts;
            chart.update();

        } catch (error) {
            document.getElementById('totalCollectedDisplay').textContent = 'Error loading data';
            chart.data.labels = [];
            chart.data.datasets[0].data = [];
            chart.update();
        }
    }

    // Event listeners for inputs to trigger data fetching
    ['startDate', 'endDate', 'intervalSelect'].forEach(id => {
        document.getElementById(id).addEventListener('change', fetchReportData);
    });

    // Initial fetch
    fetchReportData();
});
</script>

<?php include '../templates/footer.php'; ?>
