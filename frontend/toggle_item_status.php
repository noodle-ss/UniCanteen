<?php
/**
 * toggle_item_status.php — Vendor endpoint to toggle item availability.
 *
 * Expects a POST with:
 *   - item_id (int, required)
 *   - status  (int, 1 = available, 0 = sold out)
 *
 * Returns JSON: { success: bool, error?: string }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

// Only vendors may toggle item status
requireVendor();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$item_id     = intval($_POST['item_id'] ?? 0);
$isAvailable = intval($_POST['status'] ?? 0);
$user_id     = $_SESSION['user_id'];

if (!$item_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid item ID.']);
    exit;
}

$db = Database::getInstance()->getConnection();

/* --------------------------------------------------
   1. Verify item belongs to this vendor's restaurant
   -------------------------------------------------- */
$ownerCheck = $db->prepare(
    "SELECT i.ID FROM Items i
     JOIN Restaurants r ON i.restaurant_ID = r.ID
     WHERE i.ID = ? AND r.owner_ID = ?"
);
$ownerCheck->bind_param("ii", $item_id, $user_id);
$ownerCheck->execute();

if ($ownerCheck->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    $ownerCheck->close();
    exit;
}
$ownerCheck->close();

/* --------------------------------------------------
   2. Update availability status
   -------------------------------------------------- */
$stmt = $db->prepare("UPDATE Items SET isAvailable = ? WHERE ID = ?");
$stmt->bind_param("ii", $isAvailable, $item_id);
$success = $stmt->execute();
$stmt->close();

echo json_encode(['success' => $success, 'error' => $success ? null : 'Database error.']);