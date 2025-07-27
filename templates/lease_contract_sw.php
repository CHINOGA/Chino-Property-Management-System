<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <title>Mkataba wa Ukodishaji</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; }
        h1 { text-align: center; }
        p { line-height: 1.5; }
    </style>
</head>
<body>
    <h1>Mkataba wa Ukodishaji</h1>
    <p>Mkataba huu umefanywa tarehe <?= date('d/m/Y') ?> kati ya:</p>
    <p><strong>Mwenye Nyumba:</strong> Chino Property Management</p>
    <p>na</p>
    <p><strong>Mpangaji:</strong> <?= htmlspecialchars($tenant_name) ?></p>
    <p>Kuhusu mali iliyoko katika:</p>
    <p><strong>Anwani ya Mali:</strong> <?= htmlspecialchars($property_address) ?></p>
    <p><strong>Nambari ya Nyumba/Chumba:</strong> <?= htmlspecialchars($unit_name) ?></p>
    <h2>Masharti ya Ukodishaji:</h2>
    <ol>
        <li>Muda wa ukodishaji ni kuanzia tarehe <?= htmlspecialchars($start_date) ?> hadi tarehe <?= htmlspecialchars($end_date) ?>.</li>
        <li>Kodi ya pango ni TZS <?= number_format($rent_amount, 2) ?> kwa mwezi.</li>
        <li>Malipo ya kodi yatafanywa kila mwezi kabla ya tarehe 5.</li>
        <li>Mpangaji atatumia mali kwa makazi tu.</li>
        <li>Uharibifu wowote utakaosababishwa na mpangaji utarekebishwa kwa gharama za mpangaji.</li>
    </ol>
    <p>Saini:</p>
    <p>_________________________</p>
    <p>Mwenye Nyumba</p>
    <p>_________________________</p>
    <p>Mpangaji</p>
</body>
</html>
