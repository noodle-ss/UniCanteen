<?php
// Ensure no output before JSON header
header('Content-Type: application/json');

// Include configuration files
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/auth_check.php';

try {
    // Get POST data
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : null;
    $status = isset($_POST['status']) ? $_POST['status'] : null;

    // Validate inputs
    if (!$order_id || !$status) {
        throw new Exception("Missing required parameters");
    }

    // Validate status is one of the allowed values
    $valid_statuses = ['P', 'PR', 'R', 'C'];
    if (!in_array($status, $valid_statuses)) {
        throw new Exception("Invalid status value");
    }

    // Get database connection
    $dbConn = Database::getInstance()->getConnection();

    // Verify order exists and belongs to current vendor's restaurant
    $verify_query = "SELECT o.status, r.owner_ID FROM Orders o 
                    JOIN Restaurants r ON o.restaurant_ID = r.ID 
                    WHERE o.ID = ?";
    $verify_stmt = $dbConn->prepare($verify_query);
    if (!$verify_stmt) {
        throw new Exception("Database prepare error: " . $dbConn->error);
    }

    $verify_stmt->bind_param("i", $order_id);
    $verify_stmt->execute();
    $result = $verify_stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("Order not found");
    }

    $order_data = $result->fetch_assoc();

    // Verify the current user is the restaurant owner (vendor)
    $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    if ($order_data['owner_ID'] != $current_user_id) {
        throw new Exception("Unauthorized: You don't own this restaurant");
    }

    // Update the order status
    $update_query = "UPDATE Orders SET status = ? WHERE ID = ?";
    $update_stmt = $dbConn->prepare($update_query);
    if (!$update_stmt) {
        throw new Exception("Database prepare error: " . $dbConn->error);
    }

    $update_stmt->bind_param("si", $status, $order_id);
    $execute_result = $update_stmt->execute();

    if ($execute_result) {
        // Status mapping for response
        $status_names = [
            'P' => 'Pending',
            'PR' => 'Preparing',
            'R' => 'Ready',
            'C' => 'Completed'
        ];

        echo json_encode([
            "success" => true,
            "message" => "Order status updated to " . $status_names[$status],
            "order_id" => $order_id,
            "new_status" => $status,
            "status_name" => $status_names[$status]
        ]);
    } else {
        throw new Exception("Failed to update order status: " . $dbConn->error);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>