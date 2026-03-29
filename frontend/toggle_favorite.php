<?php
/**
 * toggle_favorite.php — Toggle a menu item as a user favorite.
 *
 * If the item is already favorited, it will be removed.
 * If it is not yet favorited, it will be added.
 *
 * Expects a POST with:
 *   - item_id (int, required)
 *
 * Returns JSON: { success: bool, action?: "added"|"removed", message?: string }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

// Must be logged in to manage favorites
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$db      = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];
$item_id = intval($_POST['item_id'] ?? 0);

if ($item_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid item ID.']);
    exit;
}

/* --------------------------------------------------
   1. Verify the item exists
   -------------------------------------------------- */
$stmt = $db->prepare("SELECT ID FROM Items WHERE ID = ?");
$stmt->bind_param("i", $item_id);
$stmt->execute();
$exists = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$exists) {
    echo json_encode(['success' => false, 'message' => 'Item not found.']);
    exit;
}

/* --------------------------------------------------
   2. Check if already favorited, then toggle
   -------------------------------------------------- */
$stmt = $db->prepare("SELECT ID FROM Favorites WHERE user_id = ? AND item_id = ?");
$stmt->bind_param("ii", $user_id, $item_id);
$stmt->execute();
$isFavorited = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($isFavorited) {
    // Remove the favorite
    $delStmt = $db->prepare("DELETE FROM Favorites WHERE user_id = ? AND item_id = ?");
    $delStmt->bind_param("ii", $user_id, $item_id);
    $ok = $delStmt->execute();
    $delStmt->close();

    echo json_encode($ok
        ? ['success' => true, 'action' => 'removed']
        : ['success' => false, 'message' => 'Database error while removing favorite.']
    );
} else {
    // Add the favorite
    $insStmt = $db->prepare("INSERT INTO Favorites (user_id, item_id) VALUES (?, ?)");
    $insStmt->bind_param("ii", $user_id, $item_id);
    $ok = $insStmt->execute();
    $insStmt->close();

    echo json_encode($ok
        ? ['success' => true, 'action' => 'added']
        : ['success' => false, 'message' => 'Database error while adding favorite.']
    );
}
