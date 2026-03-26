<?php
require_once __DIR__ . '/../config/config.php';

$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $restaurant_id = $_POST['restaurant_id'];

    // Toggle is_open
    $query = "UPDATE Restaurants 
              SET is_open = NOT is_open 
              WHERE ID = ?";

    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();

    // Redirect back
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}