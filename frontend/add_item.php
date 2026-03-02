<?php
require_once '../config/database.php';
require_once '../config/auth_check.php';

requireVendorLogin();

$user_id = $_SESSION['user_id'];

// Get vendor ID
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT id FROM Restaurants WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();
$vendor_id = $vendor['id'];

// Insert new menu item
$name   = $_POST['name'];
$price  = floatval($_POST['price']);
$status = $_POST['status'];

$stmt = $db->prepare("INSERT INTO Items (vendor_id, name, price, status) VALUES (?, ?, ?, ?)");
$stmt->bind_param("isds", $vendor_id, $name, $price, $status);

echo json_encode(['success' => $stmt->execute()]);
?>