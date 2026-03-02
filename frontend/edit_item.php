<?php
require_once '../config/auth_check.php';
require_once '../config/database.php';

requireVendorLogin();

$item_id = $_POST['item_id'] ?? null;
$name = $_POST['item_name'] ?? '';
$price = $_POST['price'] ?? 0;
$availability = $_POST['availability'] ?? 1;

if(!$item_id) {
    echo json_encode(['success'=>false]);
    exit;
}

$stmt = Database::getInstance()->getConnection()->prepare("UPDATE Items SET name=?, price=?, availability=? WHERE id=?");
$stmt->bind_param("sdii", $name, $price, $availability, $item_id);

if($stmt->execute()){
    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false]);
}
?>