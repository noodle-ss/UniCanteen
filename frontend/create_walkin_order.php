<?php
/**
 * create_walkin_order.php — Vendor endpoint for creating walk-in orders.
 *
 * Walk-in orders are placed on behalf of customers who order directly at the
 * stall counter.  They are attributed to a dedicated system "Walk-In" account
 * (customer_ID = NULL) and automatically set to "Preparing" status.
 *
 * Expects a POST with:
 *   - restaurant_id     (int, required)
 *   - items[item_id]    (int qty, at least one > 0)
 *   - payment_method    (string, optional — defaults to "cash")
 *
 * Returns JSON: { success: bool, order_id?: int, queue_number?: int, error?: string }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

// Only vendors may create walk-in orders
requireVendor();

$db = Database::getInstance()->getConnection();

/* --------------------------------------------------
   1. Validate required POST parameters
   -------------------------------------------------- */
if (empty($_POST['restaurant_id']) || empty($_POST['items']) || !is_array($_POST['items'])) {
    echo json_encode(['success' => false, 'error' => 'Missing restaurant ID or items.']);
    exit;
}

$restaurant_id  = intval($_POST['restaurant_id']);
$payment_method = $_POST['payment_method'] ?? 'cash';
$walkin_name    = isset($_POST['walkin_name']) && trim($_POST['walkin_name']) !== '' ? trim($_POST['walkin_name']) : null;

/* --------------------------------------------------
   2. Verify the vendor owns this restaurant
   -------------------------------------------------- */
$ownerStmt = $db->prepare("SELECT ID FROM Restaurants WHERE ID = ? AND owner_ID = ?");
$ownerStmt->bind_param("ii", $restaurant_id, $_SESSION['user_id']);
$ownerStmt->execute();
$ownerResult = $ownerStmt->get_result()->fetch_assoc();
$ownerStmt->close();

if (!$ownerResult) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized: you do not own this restaurant.']);
    exit;
}

/* --------------------------------------------------
   3. Build a validated list of items and compute total
   -------------------------------------------------- */
$priceStmt  = $db->prepare("SELECT price FROM Items WHERE ID = ? AND restaurant_ID = ?");
$validItems = [];
$total      = 0.0;

foreach ($_POST['items'] as $item_id => $qty) {
    $item_id = intval($item_id);
    $qty     = intval($qty);

    if ($qty <= 0) {
        continue; // skip items with zero or negative quantity
    }

    // Fetch the item's price and confirm it belongs to this restaurant
    $priceStmt->bind_param("ii", $item_id, $restaurant_id);
    $priceStmt->execute();
    $row = $priceStmt->get_result()->fetch_assoc();

    if (!$row) {
        continue; // item not found or doesn't belong to this restaurant
    }

    $price = floatval($row['price']);
    $total += $price * $qty;

    $validItems[] = [
        'item_id' => $item_id,
        'qty'     => $qty,
        'price'   => $price,
    ];
}

$priceStmt->close();

if (empty($validItems) || $total <= 0) {
    echo json_encode(['success' => false, 'error' => 'No valid items selected.']);
    exit;
}

/* --------------------------------------------------
   4. Insert order and items inside a transaction
   -------------------------------------------------- */
$db->begin_transaction();

try {
    // 4a. Insert the order header (customer_ID = NULL for walk-in)
    $orderStmt = $db->prepare(
        "INSERT INTO Orders (restaurant_ID, customer_ID, total_amount, status, payment_method, walkin_name, order_date)
         VALUES (?, NULL, ?, 'PR', ?, ?, NOW())"
    );
    $orderStmt->bind_param("idss", $restaurant_id, $total, $payment_method, $walkin_name);
    $orderStmt->execute();
    $order_id = $orderStmt->insert_id;
    $orderStmt->close();

    // 4b. Insert each order line item
    $lineStmt = $db->prepare(
        "INSERT INTO Order_ItemLine (order_ID, item_ID, quantity, price_at_time)
         VALUES (?, ?, ?, ?)"
    );

    foreach ($validItems as $item) {
        $lineStmt->bind_param("iiid", $order_id, $item['item_id'], $item['qty'], $item['price']);
        $lineStmt->execute();
    }

    $lineStmt->close();

    // 4c. Assign the next queue number for today
    $queueStmt = $db->prepare(
        "SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_queue
         FROM Orders
         WHERE restaurant_ID = ? AND DATE(order_date) = CURDATE()"
    );
    $queueStmt->bind_param("i", $restaurant_id);
    $queueStmt->execute();
    $next_queue = $queueStmt->get_result()->fetch_assoc()['next_queue'];
    $queueStmt->close();

    $updateStmt = $db->prepare("UPDATE Orders SET queue_number = ? WHERE ID = ?");
    $updateStmt->bind_param("ii", $next_queue, $order_id);
    $updateStmt->execute();
    $updateStmt->close();

    $db->commit();

    echo json_encode([
        'success'      => true,
        'order_id'     => $order_id,
        'queue_number' => $next_queue,
    ]);

} catch (Exception $e) {
    $db->rollback();
    echo json_encode(['success' => false, 'error' => 'Failed to create order: ' . $e->getMessage()]);
}