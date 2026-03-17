<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
requireVendor();

if (!isset($_POST['id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$item_id = intval($_POST['id']);
$user_id = $_SESSION['user_id'];
$db = Database::getInstance()->getConnection();

// ensure item belongs to this vendor
$stmt = $db->prepare("SELECT r.ID FROM Items i JOIN Restaurants r ON i.restaurant_ID = r.ID WHERE i.ID = ? AND r.owner_ID = ?");
$stmt->bind_param("ii", $item_id, $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if (!$res) {
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $db->prepare("DELETE FROM Items WHERE ID = ? AND restaurant_ID = ?");
$stmt->bind_param("ii", $item_id, $res['ID']);
$success = $stmt->execute();

echo json_encode(['success' => $success]);
?>