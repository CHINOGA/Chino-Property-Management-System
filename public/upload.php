<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once '../config/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    $id = $_POST['id'] ?? 0;

    if (!empty($_FILES['file']['name'])) {
        $uploadDir = '../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = basename($_FILES['file']['name']);
        $targetFilePath = $uploadDir . $fileName;
        $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));

        // Allow certain file formats
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
        if (in_array($fileType, $allowedTypes)) {
            if (move_uploaded_file($_FILES['file']['tmp_name'], $targetFilePath)) {
                // Save file path in database
                if ($type === 'property') {
                    $stmt = $pdo->prepare("UPDATE properties SET image = ? WHERE id = ?");
                    $stmt->execute([$fileName, $id]);
                } elseif ($type === 'unit') {
                    $stmt = $pdo->prepare("UPDATE units SET document = ? WHERE id = ?");
                    $stmt->execute([$fileName, $id]);
                }
                $message = "File uploaded successfully.";
            } else {
                $message = "Sorry, there was an error uploading your file.";
            }
        } else {
            $message = "Sorry, only JPG, JPEG, PNG, GIF, PDF, DOC, DOCX files are allowed.";
        }
    } else {
        $message = "No file selected.";
    }
} else {
    $message = "Invalid request.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Upload File</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body>
<div class="container mt-4">
    <h1>Upload File</h1>
    <p><?= htmlspecialchars($message) ?></p>
    <a href="properties.php" class="btn btn-primary">Back to Properties</a>
</div>
</body>
</html>
