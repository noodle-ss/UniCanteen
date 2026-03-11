<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

// Ensure response is JSON
header('Content-Type: application/json');

// Security: Must be logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];
$item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;

if ($item_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid item ID.']);
    exit();
}

// Check if item exists
$stmt = $db->prepare("SELECT ID FROM Items WHERE ID = ?");
$stmt->bind_param("i", $item_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Item not found.']);
    exit();
}

// Check if already favorited
$stmt = $db->prepare("SELECT ID FROM Favorites WHERE user_id = ? AND item_id = ?");
$stmt->bind_param("ii", $user_id, $item_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Remove favorite
    $delStmt = $db->prepare("DELETE FROM Favorites WHERE user_id = ? AND item_id = ?");
    $delStmt->bind_param("ii", $user_id, $item_id);
    if ($delStmt->execute()) {
        echo json_encode(['success' => true, 'action' => 'removed']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error while removing favorite.']);
    }
} else {
    // Add favorite
    $insStmt = $db->prepare("INSERT INTO Favorites (user_id, item_id) VALUES (?, ?)");
    $insStmt->bind_param("ii", $user_id, $item_id);
    if ($insStmt->execute()) {
        echo json_encode(['success' => true, 'action' => 'added']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error while adding favorite.']);
    }
}
?>
