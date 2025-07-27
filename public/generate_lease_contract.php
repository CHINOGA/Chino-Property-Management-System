<?php
require_once '../vendor/autoload.php';
require_once '../config/db.php';

use Dompdf\Dompdf;

if (!isset($_GET['lease_id']) || !filter_var($_GET['lease_id'], FILTER_VALIDATE_INT)) {
    die('Invalid lease ID.');
}

$lease_id = (int)$_GET['lease_id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            l.lease_start, 
            l.lease_end, 
            t.full_name as tenant_name, 
            u.unit_name, 
            p.address as property_address,
            u.rent_amount
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN units u ON l.unit_id = u.id
        JOIN properties p ON u.property_id = p.id
        WHERE l.id = ?
    ");
    $stmt->execute([$lease_id]);
    $lease = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lease) {
        die('Lease not found.');
    }

    extract($lease);

    ob_start();
    include '../templates/lease_contract_sw.php';
    $html = ob_get_clean();

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("mkataba-wa-ukodishaji-{$lease_id}.pdf");

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}
?>
