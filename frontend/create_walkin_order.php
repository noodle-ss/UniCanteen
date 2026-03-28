<?php
// ADD THESE 3 LINES:
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

$db = Database::getInstance()->getConnection();

/* =========================
   VALIDATE INPUT
========================= */
if (!isset($_POST['restaurant_id']) || !isset($_POST['items'])) {
    die("Invalid request");
}

$restaurant_id = intval($_POST['restaurant_id']);
$items = $_POST['items'];

$total = 0;
$validItems = [];

/* =========================
   GET PRICE (SAFE VERSION)
========================= */
$stmtPrice = $db->prepare("SELECT price FROM Items WHERE ID = ?");

foreach ($items as $item_id => $qty) {
    $qty = intval($qty);
    $item_id = intval($item_id);

    if ($qty > 0) {

        $stmtPrice->bind_param("i", $item_id);
        $stmtPrice->execute();

        $result = $stmtPrice->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            $price = floatval($row['price']);
            $total += $price * $qty;

            $validItems[] = [
                'item_id' => $item_id,
                'qty' => $qty,
                'price' => $price
            ];
        }
    }
}

$stmtPrice->close();

/* =========================
   VALIDATION CHECK
========================= */
if ($total <= 0 || empty($validItems)) {
    header("Location: index.php?page=vendor&error=empty");
    exit;
}

/* =========================
   INSERT ORDER (NO USER)
========================= */
$stmt = $db->prepare("
    INSERT INTO Orders (restaurant_ID, customer_ID, total_amount, status, order_date)
    VALUES (?, 8, ?, 'P', NOW())
");

$stmt->bind_param("id", $restaurant_id, $total);
$stmt->execute();

$order_id = $stmt->insert_id;

/* =========================
   INSERT ITEMS
========================= */
$stmtItem = $db->prepare("
    INSERT INTO Order_ItemLine (order_ID, item_ID, quantity, price_at_time)
    VALUES (?, ?, ?, ?)
");

foreach ($validItems as $item) {
    $stmtItem->bind_param(
        "iiid",
        $order_id,
        $item['item_id'],
        $item['qty'],
        $item['price']
    );
    $stmtItem->execute();
}

$stmtItem->close();

/* =========================
   SAFE QUEUE NUMBER
========================= */
/* =========================
   SAFE QUEUE NUMBER (FIXED)
========================= */
// Step 1: Kunin muna ang highest queue number
$qStmt = $db->prepare("SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_queue FROM Orders WHERE restaurant_ID = ? AND DATE(order_date) = CURDATE()");
$qStmt->bind_param("i", $restaurant_id);
$qStmt->execute();
$qResult = $qStmt->get_result();
$qRow = $qResult->fetch_assoc();
$next_queue = $qRow['next_queue'];
$qStmt->close();

// Step 2: I-update ang order gamit ang nakuha nating number
$uStmt = $db->prepare("UPDATE Orders SET queue_number = ? WHERE ID = ?");
$uStmt->bind_param("ii", $next_queue, $order_id);
$uStmt->execute();
$uStmt->close();

exit;
?>