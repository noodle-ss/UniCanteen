<?php
/**
 * reorder.php — Customer endpoint to re-add items from a previous order to the cart.
 *
 * Copies all available items from a completed order into the session cart.
 * If the previous order was from a different restaurant, the current cart
 * is cleared first (single-restaurant cart rule).
 *
 * Expects a POST with:
 *   - order_id (int, required)
 *
 * On success redirects to the cart page.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

requireLogin();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . url('index.php?page=orders'));
    exit;
}

$db       = Database::getInstance()->getConnection();
$user_id  = $_SESSION['user_id'];
$order_id = intval($_POST['order_id'] ?? 0);

/* --------------------------------------------------
   1. Verify the order belongs to this customer (IDOR protection)
   -------------------------------------------------- */
$stmt = $db->prepare("SELECT ID, restaurant_ID FROM Orders WHERE ID = ? AND customer_ID = ?");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$orderResult = $stmt->get_result();

if ($orderResult->num_rows === 0) {
    $stmt->close();
    header("Location: " . url('index.php?page=orders&error=' . urlencode("Invalid order or unauthorized access.")));
    exit;
}

$orderData = $orderResult->fetch_assoc();
$stmt->close();
$restaurant_id_to_add = $orderData['restaurant_ID'];

/* --------------------------------------------------
   2. Fetch the original order's items and check availability
   -------------------------------------------------- */
$itemsStmt = $db->prepare(
    "SELECT oi.item_ID, oi.quantity, i.name, i.price, i.isAvailable, i.restaurant_ID
     FROM Order_ItemLine oi
     JOIN Items i ON oi.item_ID = i.ID
     WHERE oi.order_ID = ?"
);
$itemsStmt->bind_param("i", $order_id);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();

$itemsToAdd       = [];
$unavailableCount  = 0;

while ($row = $itemsResult->fetch_assoc()) {
    if ($row['isAvailable']) {
        $itemsToAdd[] = $row;
    } else {
        $unavailableCount++;
    }
}
$itemsStmt->close();

// Bail if every item in the order is now unavailable
if (empty($itemsToAdd)) {
    header("Location: " . url('index.php?page=orders&error=' . urlencode("All items from this order are currently unavailable.")));
    exit;
}

/* --------------------------------------------------
   3. Enforce single-restaurant cart rule
   -------------------------------------------------- */
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cart_restaurant_id = null;
if (!empty($_SESSION['cart'])) {
    $first_item = reset($_SESSION['cart']);
    $cart_restaurant_id = $first_item['restaurant_id'];
}

// Clear the cart if switching to a different restaurant
if ($cart_restaurant_id !== null && $cart_restaurant_id !== $restaurant_id_to_add) {
    $_SESSION['cart'] = [];
    $_SESSION['flash_warning'] = "Cart was cleared to order from a different stall.";
}

/* --------------------------------------------------
   4. Add available items to the session cart
   -------------------------------------------------- */
$restStmt = $db->prepare("SELECT name FROM Restaurants WHERE ID = ?");
$restStmt->bind_param("i", $restaurant_id_to_add);
$restStmt->execute();
$restName = $restStmt->get_result()->fetch_assoc()['name'];
$restStmt->close();

foreach ($itemsToAdd as $item) {
    $item_id = $item['item_ID'];
    if (isset($_SESSION['cart'][$item_id])) {
        // Increment quantity if the item is already in the cart
        $_SESSION['cart'][$item_id]['quantity'] += $item['quantity'];
    } else {
        $_SESSION['cart'][$item_id] = [
            'id'              => $item_id,
            'name'            => $item['name'],
            'price'           => $item['price'],
            'quantity'        => $item['quantity'],
            'restaurant_id'   => $restaurant_id_to_add,
            'restaurant_name' => $restName,
        ];
    }
}

/* --------------------------------------------------
   5. Flash a warning if some items were skipped
   -------------------------------------------------- */
if ($unavailableCount > 0) {
    $s = $unavailableCount > 1 ? 's' : '';
    $_SESSION['flash_warning'] = "{$unavailableCount} item{$s} from your previous order were no longer available and were skipped.";
}

// Redirect to the cart page
header("Location: " . url('index.php?page=cart&success=reordered'));
exit;
