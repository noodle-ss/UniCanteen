<?php
require_once '../config/auth_check.php';
require_once '../config/database.php';

requireVendorLogin();

if(!isset($_POST['id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$item_id = intval($_POST['id']);

$conn = Database::getInstance()->getConnection();
$stmt = $conn->prepare("DELETE FROM Items WHERE id = ? AND vendor_id = ?");
$stmt->bind_param("ii", $item_id, $_SESSION['user_id']);
$success = $stmt->execute();

echo json_encode(['success' => $success]);
?>