<?php
// buffer output so we can detect stray text or notices
ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/database.php';

// ensure vendor is logged in
requireVendor();

$user_id = $_SESSION['user_id'];
$db = Database::getInstance()->getConnection();

// look up the restaurant ID owned by this vendor
$stmt = $db->prepare("SELECT ID FROM Restaurants WHERE owner_ID = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$restaurant = $stmt->get_result()->fetch_assoc();
$restaurant_id = $restaurant['ID'] ?? 0;

if (!$restaurant_id) {
    echo json_encode(['success' => false, 'error' => 'no_restaurant']);
    exit;
}

// read input
$name  = $_POST['name'] ?? '';
$price = floatval($_POST['price'] ?? 0);
$isAvailable = (isset($_POST['status']) && $_POST['status'] === 'available') ? 1 : 0;

// insert using correct column names
$stmt = $db->prepare(
    "INSERT INTO Items (name, price, isAvailable, restaurant_ID) VALUES (?, ?, ?, ?)"
);
$stmt->bind_param("sdii", $name, $price, $isAvailable, $restaurant_id);

$success = $stmt->execute();
$error = $success ? null : $stmt->error;

// capture any earlier output (not expected)
$early = ob_get_clean();
$debug = null;
if (trim($early) !== '') {
    // log for server-side introspection
    error_log('add_item unexpected output: ' . $early);
    $debug = $early;
}

// send JSON header and diagnostic info (including stray output)
header('Content-Type: application/json');
echo json_encode([
    'success'       => $success,
    'error'         => $error,
    'restaurant_id' => $restaurant_id,
    'debug'         => $debug,
]);
?>