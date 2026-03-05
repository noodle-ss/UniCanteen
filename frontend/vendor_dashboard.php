<?php
$orderItemsQuery = "
SELECT i.name, oi.quantity
FROM Order_ItemLine oi
JOIN Items i ON oi.item_ID = i.ID
WHERE oi.order_ID = ?
";

$stmtItems = $dbConn->prepare($orderItemsQuery);
$stmtItems->bind_param("i", $order['ID']);
$stmtItems->execute();
$order_items = $stmtItems->get_result()->fetch_all(MYSQLI_ASSOC);
?>