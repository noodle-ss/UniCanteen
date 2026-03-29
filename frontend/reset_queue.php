<?php
/**
 * reset_queue.php — Vendor endpoint to re-number the active order queue.
 *
 * Re-sequences queue numbers (1, 2, 3, …) for all non-completed orders
 * belonging to the current vendor's restaurant, ordered by original date.
 *
 * Expects a POST with:
 *   - action = "reset_counter"
 *
 * Returns JSON: { success: bool, message?: string, error?: string }
 */

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/auth_check.php';

// Only vendors may reset the queue
requireVendor();

try {
    if (($_POST['action'] ?? '') !== 'reset_counter') {
        throw new Exception("Invalid action.");
    }

    $user_id = $_SESSION['user_id'];
    $db      = Database::getInstance()->getConnection();

    /* --------------------------------------------------
       1. Look up this vendor's restaurant
       -------------------------------------------------- */
    $stmt = $db->prepare("SELECT ID FROM Restaurants WHERE owner_ID = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $restaurant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$restaurant) {
        throw new Exception("Restaurant not found for current user.");
    }

    $restaurant_id = $restaurant['ID'];

    /* --------------------------------------------------
       2. Fetch active orders and re-assign queue numbers
       -------------------------------------------------- */
    $fetchStmt = $db->prepare(
        "SELECT ID FROM Orders
         WHERE restaurant_ID = ? AND status IN ('P', 'PR', 'R')
         ORDER BY order_date ASC"
    );
    $fetchStmt->bind_param("i", $restaurant_id);
    $fetchStmt->execute();
    $orders = $fetchStmt->get_result();

    // Reuse a single prepared statement for all updates
    $updateStmt  = $db->prepare("UPDATE Orders SET queue_number = ? WHERE ID = ?");
    $queue_number = 1;

    while ($order = $orders->fetch_assoc()) {
        $updateStmt->bind_param("ii", $queue_number, $order['ID']);
        $updateStmt->execute();
        $queue_number++;
    }

    $updateStmt->close();
    $fetchStmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Queue counter reset successfully.',
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}