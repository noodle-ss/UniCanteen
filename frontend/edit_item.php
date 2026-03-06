<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/database.php';

requireVendor();

$item_id = $_POST['item_id'] ?? null;
$name    = $_POST['item_name'] ?? '';
$price   = floatval($_POST['price'] ?? 0);
$avail   = isset($_POST['availability']) ? intval($_POST['availability']) : 1;

// Handle image upload
$image_url = null;
$update_image = false;
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
        $update_image = true;
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to upload image.']);
        exit;
    }
}

if (!$item_id) {
    echo json_encode(['success' => false]);
    exit;
}

// ensure item belongs to this vendor's restaurant
$user_id = $_SESSION['user_id'];
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT r.ID AS rid FROM Items i JOIN Restaurants r ON i.restaurant_ID = r.ID WHERE i.ID = ? AND r.owner_ID = ?");
$stmt->bind_param("ii", $item_id, $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if (!$res) {
    echo json_encode(['success' => false]);
    exit;
}

$restaurant_id = $res['rid'];

if ($update_image) {
    $stmt = $db->prepare("UPDATE Items SET name=?, price=?, isAvailable=?, image_url=? WHERE ID=? AND restaurant_ID=?");
    $stmt->bind_param("sdisii", $name, $price, $avail, $image_url, $item_id, $restaurant_id);
} else {
    $stmt = $db->prepare("UPDATE Items SET name=?, price=?, isAvailable=? WHERE ID=? AND restaurant_ID=?");
    $stmt->bind_param("sdiii", $name, $price, $avail, $item_id, $restaurant_id);
}

$success = $stmt->execute();
echo json_encode(['success' => $success]);
?>