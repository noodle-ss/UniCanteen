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
$name = $_POST['name'] ?? '';
$description = $_POST['description'] ?? '';
$price = floatval($_POST['price'] ?? 0);
$isAvailable = (isset($_POST['status']) && $_POST['status'] === 'available') ? 1 : 0;

// Handle image upload
$image_url = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/../assets/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileName = $_FILES['image']['name'];
    $fileTmp = $_FILES['image']['tmp_name'];
    $fileSize = $_FILES['image']['size'];
    $fileType = $_FILES['image']['type'];

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($fileType, $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'Invalid image type. Only JPEG, PNG, GIF, and WebP are allowed.']);
        exit;
    }

    // Validate file size (max 5MB)
    if ($fileSize > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Image file is too large. Maximum size is 5MB.']);
        exit;
    }

    // Generate unique filename
    $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
    $uniqueName = uniqid('item_', true) . '.' . $fileExt;
    $uploadPath = $uploadDir . $uniqueName;

    if (move_uploaded_file($fileTmp, $uploadPath)) {
        $image_url = 'assets/uploads/' . $uniqueName;
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to upload image.']);
        exit;
    }
}

// insert using correct column names
$stmt = $db->prepare(
    "INSERT INTO Items (name, description, price, isAvailable, restaurant_ID, image_url) VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param("ssdiis", $name, $description, $price, $isAvailable, $restaurant_id, $image_url);

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
    'success' => $success,
    'error' => $error,
    'restaurant_id' => $restaurant_id,
    'debug' => $debug,
]);
?>