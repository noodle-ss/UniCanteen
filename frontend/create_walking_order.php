<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';


$db = Database::getInstance()->getConnection();

// Validate input
if (!isset($_POST['restaurant_id']) || !isset($_POST['items'])) {
    die("Invalid request");
}

$restaurant_id = intval($_POST['restaurant_id']);
$items = $_POST['items'];

$total = 0;
$validItems = [];

// =========================
// COMPUTE TOTAL SAFELY
// =========================
foreach ($items as $item_id => $qty) {
    $qty = intval($qty);

    if ($qty > 0) {
        $stmt = $db->prepare("SELECT price FROM Items WHERE ID = ?");
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $price = floatval($row['price']);
            $total += $price * $qty;

            // store valid item for later insert
            $validItems[] = [
                'item_id' => $item_id,
                'qty' => $qty
            ];
        }
    }
}

// If no items selected
if ($total <= 0 || empty($validItems)) {
    header("Location: index.php?page=vendor&error=empty");
    exit;
}

// =========================
// GET WALK-IN USER ID
// =========================
$stmt = $db->prepare("SELECT ID FROM Users WHERE email = 'walkin@system.local' LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row) {
    die("Walk-in user not found. Please create it in database.");
}

$walkin_id = $row['ID'];

// =========================
// INSERT ORDER (PENDING)
// =========================
$stmt = $db->prepare("
    INSERT INTO Orders (restaurant_ID, customer_ID, total_amount, status, order_date)
    VALUES (?, ?, ?, 'P', NOW())
");
$stmt->bind_param("iid", $restaurant_id, $walkin_id, $total);
$stmt->execute();
$order_id = $stmt->insert_id;

// =========================
// INSERT ORDER ITEMS
// =========================
foreach ($validItems as $item) {
    $stmt = $db->prepare("
        INSERT INTO Order_ItemLine (order_ID, item_ID, quantity)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iii", $order_id, $item['item_id'], $item['qty']);
    $stmt->execute();
}

// =========================
// OPTIONAL: AUTO QUEUE NUMBER
// =========================
$queueQuery = "
    UPDATE Orders 
    SET queue_number = (
        SELECT IFNULL(MAX(queue_number), 0) + 1 
        FROM Orders o2 
        WHERE o2.restaurant_ID = ?
    )
    WHERE ID = ?
";

$stmt = $db->prepare($queueQuery);
$stmt->bind_param("ii", $restaurant_id, $order_id);
$stmt->execute();

// =========================
// REDIRECT BACK
// =========================
header("Location: index.php?page=vendor&success=walkin");
exit;

?>