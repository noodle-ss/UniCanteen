<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/database.php';

requireVendor();

$item_id = $_POST['item_id'] ?? null;
$name    = $_POST['item_name'] ?? '';
$price   = floatval($_POST['price'] ?? 0);
$avail   = isset($_POST['availability']) ? intval($_POST['availability']) : 1;

if (!$item_id) {
    echo json_encode(['success' => false]);
    exit;
}

// ensure item belongs to this vendor's restaurant
$user_id = $_SESSION['user_id'];
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT r.ID AS rid FROM Items i JOIN Restaurants r ON i.restaurant_ID = r.ID WHERE i.ID = ? AND r.owner_ID = ?");
$stmt->bind_param("ii", $item_id, $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if (!$res) {
    echo json_encode(['success' => false]);
    exit;
}

$restaurant_id = $res['rid'];

$stmt = $db->prepare("UPDATE Items SET name=?, price=?, isAvailable=? WHERE ID=? AND restaurant_ID=?");
$stmt->bind_param("sdiii", $name, $price, $avail, $item_id, $restaurant_id);

$success = $stmt->execute();
echo json_encode(['success' => $success]);
?>