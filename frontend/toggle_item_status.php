<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

header('Content-Type: application/json');

requireVendor();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$item_id = intval($_POST['item_id'] ?? 0);
$isAvailable = intval($_POST['status'] ?? 0); // 1 = available, 0 = sold out
$user_id = $_SESSION['user_id'];

if (!$item_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid item ID']);
    exit;
}

$db = Database::getInstance()->getConnection();

// Verify the item belongs to the requesting vendor's restaurant
$ownerCheck = $db->prepare(
    "SELECT i.ID FROM Items i
     JOIN Restaurants r ON i.restaurant_ID = r.ID
     WHERE i.ID = ? AND r.owner_id = ?"
);
$ownerCheck->bind_param("ii", $item_id, $user_id);
$ownerCheck->execute();
if ($ownerCheck->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$stmt = $db->prepare("UPDATE Items SET isAvailable = ? WHERE ID = ?");
$stmt->bind_param("ii", $isAvailable, $item_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>