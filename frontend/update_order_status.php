<?php
/**
 * update_order_status.php — Vendor endpoint to update an order's status.
 *
 * Expects a POST with:
 *   - order_id (int, required)
 *   - status   (string, one of: P, PR, R, C)
 *
 * Returns JSON: { success: bool, order_id?: int, new_status?: string,
 *                 status_name?: string, message?: string, error?: string }
 */

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/auth_check.php';

// Only vendors may update order status
requireVendor();

// Status code → human-readable label
$STATUS_NAMES = [
    'P'  => 'Pending',
    'PR' => 'Preparing',
    'R'  => 'Ready',
    'C'  => 'Completed',
];

try {
    $order_id = intval($_POST['order_id'] ?? 0);
    $status   = $_POST['status'] ?? '';

    /* --------------------------------------------------
       1. Validate inputs
       -------------------------------------------------- */
    if (!$order_id || !$status) {
        throw new Exception("Missing required parameters.");
    }

    if (!array_key_exists($status, $STATUS_NAMES)) {
        throw new Exception("Invalid status value.");
    }

    $db = Database::getInstance()->getConnection();

    /* --------------------------------------------------
       2. Verify order belongs to this vendor's restaurant
       -------------------------------------------------- */
    $stmt = $db->prepare(
        "SELECT o.status, r.owner_ID
         FROM Orders o
         JOIN Restaurants r ON o.restaurant_ID = r.ID
         WHERE o.ID = ?"
    );
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order_data) {
        throw new Exception("Order not found.");
    }

    if ((int)$order_data['owner_ID'] !== (int)$_SESSION['user_id']) {
        throw new Exception("Unauthorized: you do not own this restaurant.");
    }

    /* --------------------------------------------------
       3. Update the order status
       -------------------------------------------------- */
    $stmt = $db->prepare("UPDATE Orders SET status = ? WHERE ID = ?");
    $stmt->bind_param("si", $status, $order_id);

    if (!$stmt->execute()) {
        throw new Exception("Failed to update order status: " . $stmt->error);
    }
    $stmt->close();

    echo json_encode([
        'success'     => true,
        'message'     => 'Order status updated to ' . $STATUS_NAMES[$status] . '.',
        'order_id'    => $order_id,
        'new_status'  => $status,
        'status_name' => $STATUS_NAMES[$status],
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}