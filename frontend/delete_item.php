<?php
/**
 * delete_item.php — Vendor endpoint to delete a menu item.
 *
 * Expects a POST with:
 *   - id (int, required — the item ID to delete)
 *
 * Returns JSON: { success: bool, error?: string }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

// Only vendors may delete items
requireVendor();

if (!isset($_POST['id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing item ID.']);
    exit;
}

$item_id = intval($_POST['id']);
$user_id = $_SESSION['user_id'];
$db      = Database::getInstance()->getConnection();

/* --------------------------------------------------
   1. Verify item belongs to this vendor's restaurant
   -------------------------------------------------- */
$stmt = $db->prepare(
    "SELECT r.ID
     FROM Items i
     JOIN Restaurants r ON i.restaurant_ID = r.ID
     WHERE i.ID = ? AND r.owner_ID = ?"
);
$stmt->bind_param("ii", $item_id, $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
    echo json_encode(['success' => false, 'error' => 'Item not found or unauthorized.']);
    exit;
}

/* --------------------------------------------------
   2. Fetch image path before deletion, then delete the item
   -------------------------------------------------- */
$imgStmt = $db->prepare("SELECT image_url FROM Items WHERE ID = ? AND restaurant_ID = ?");
$imgStmt->bind_param("ii", $item_id, $res['ID']);
$imgStmt->execute();
$imgRow = $imgStmt->get_result()->fetch_assoc();
$imgStmt->close();

$stmt = $db->prepare("DELETE FROM Items WHERE ID = ? AND restaurant_ID = ?");
$stmt->bind_param("ii", $item_id, $res['ID']);
$success = $stmt->execute();
$stmt->close();

// Clean up the uploaded image file if it exists
if ($success && $imgRow && !empty($imgRow['image_url'])) {
    $imagePath = __DIR__ . '/../' . $imgRow['image_url'];
    if (file_exists($imagePath)) {
        unlink($imagePath);
    }
}

echo json_encode(['success' => $success]);