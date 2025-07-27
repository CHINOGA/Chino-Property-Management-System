<?php
require_once '../../config/db.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("SELECT 
        (SELECT COUNT(*) FROM properties) as total_properties,
        (SELECT COUNT(*) FROM units) as total_units,
        (SELECT COUNT(*) FROM tenants) as total_tenants,
        (SELECT SUM(amount) FROM payments) as total_rent_collected
    ");
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode($data);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>
