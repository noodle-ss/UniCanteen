<?php
// Ensure no output before JSON header
header('Content-Type: application/json');

// Include configuration files
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/auth_check.php';

try {
    // Get POST data
    $action = isset($_POST['action']) ? $_POST['action'] : null;

    if ($action !== 'reset_counter') {
        throw new Exception("Invalid action");
    }

    // Get database connection
    $dbConn = Database::getInstance()->getConnection();

    // Get current user's restaurant ID
    $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    if (!$current_user_id) {
        throw new Exception("User not authenticated");
    }

    // Get restaurant ID for current vendor
    $restaurant_query = "SELECT ID FROM Restaurants WHERE owner_ID = ?";
    $restaurant_stmt = $dbConn->prepare($restaurant_query);
    $restaurant_stmt->bind_param("i", $current_user_id);
    $restaurant_stmt->execute();
    $restaurant_result = $restaurant_stmt->get_result();

    if ($restaurant_result->num_rows === 0) {
        throw new Exception("Restaurant not found for current user");
    }

    $restaurant = $restaurant_result->fetch_assoc();
    $restaurant_id = $restaurant['ID'];

    // Reset queue numbers for pending orders of this restaurant
    // First, get all pending orders ordered by date
    $get_orders_query = "SELECT ID FROM Orders
                        WHERE restaurant_ID = ?
                        AND status IN ('P', 'PR', 'R')
                        ORDER BY order_date ASC";

    $get_stmt = $dbConn->prepare($get_orders_query);
    $get_stmt->bind_param("i", $restaurant_id);
    $get_stmt->execute();
    $orders_result = $get_stmt->get_result();

    // Update each order with sequential queue numbers
    $queue_number = 1;
    while ($order = $orders_result->fetch_assoc()) {
        $update_query = "UPDATE Orders SET queue_number = ? WHERE ID = ?";
        $update_stmt = $dbConn->prepare($update_query);
        $update_stmt->bind_param("ii", $queue_number, $order['ID']);
        $update_stmt->execute();
        $update_stmt->close();
        $queue_number++;
    }

    $get_stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Queue counter reset successfully'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>