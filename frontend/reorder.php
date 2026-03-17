<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . url('index.php?page=orders'));
    exit();
}

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];
$order_id = intval($_POST['order_id']);

// 1. Verify Ownership & Existence (IDOR Protection)
$checkQuery = "SELECT ID, restaurant_ID FROM Orders WHERE ID = ? AND customer_ID = ?";
$stmt = $db->prepare($checkQuery);
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$orderResult = $stmt->get_result();

if ($orderResult->num_rows === 0) {
    // Unauthorized or non-existent order
    header("Location: " . url('index.php?page=orders&error=' . urlencode("Invalid order or unauthorized access.")));
    exit();
}

$orderData = $orderResult->fetch_assoc();
$restaurant_id_to_add = $orderData['restaurant_ID'];

// 2. Fetch original items
$itemsQuery = "SELECT oi.item_ID, oi.quantity, i.name, i.price, i.isAvailable, i.restaurant_ID
               FROM Order_ItemLine oi
               JOIN Items i ON oi.item_ID = i.ID
               WHERE oi.order_ID = ?";
$stmt = $db->prepare($itemsQuery);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$itemsResult = $stmt->get_result();

$itemsToAdd = [];
$unavailableCount = 0;

while ($row = $itemsResult->fetch_assoc()) {
    if ($row['isAvailable']) {
        $itemsToAdd[] = $row;
    } else {
        $unavailableCount++;
    }
}

// If ALL items from that order are unavailable
if (empty($itemsToAdd)) {
    header("Location: " . url('index.php?page=orders&error=' . urlencode("All items from this order are currently unavailable.")));
    exit();
}

// 3. Initialize/Check Cart boundaries (Multi-Restaurant rule)
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cart_restaurant_id = null;
if (!empty($_SESSION['cart'])) {
    $first_item = reset($_SESSION['cart']);
    $cart_restaurant_id = $first_item['restaurant_id'];
}

// (Clear check has effectively been approved by the JS prompt before reaching this endpoint)
if ($cart_restaurant_id !== null && $cart_restaurant_id !== $restaurant_id_to_add) {
    $_SESSION['cart'] = [];
    $_SESSION['flash_warning'] = "Cart was cleared to order from a different stall.";
}

// 4. Add items to cart
// Get restaurant name
$restStmt = $db->prepare("SELECT name FROM Restaurants WHERE ID = ?");
$restStmt->bind_param("i", $restaurant_id_to_add);
$restStmt->execute();
$restName = $restStmt->get_result()->fetch_assoc()['name'];

foreach ($itemsToAdd as $item) {
    $item_id = $item['item_ID'];
    if (isset($_SESSION['cart'][$item_id])) {
        // Increment quantity if it already exists in the new cart setup
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

// 5. Handle success & warnings
// If items were skipped, flash a warning
if ($unavailableCount > 0) {
    $s = $unavailableCount > 1 ? 's' : '';
    $_SESSION['flash_warning'] = ($unavailableCount) . " item{$s} from your previous order were no longer available and were skipped.";
}

// Redirect to cart
header("Location: " . url('index.php?page=cart&success=reordered'));
exit();
