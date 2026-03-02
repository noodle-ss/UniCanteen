<?php
require_once '../config/database.php';
require_once '../config/auth_check.php';

requireVendorLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $item_id = intval($_POST['item_id']);
    $status  = $_POST['status'];

    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("UPDATE Items SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $item_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
}
?>