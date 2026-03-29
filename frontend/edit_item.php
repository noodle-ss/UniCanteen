<?php
/**
 * edit_item.php — Vendor endpoint to update an existing menu item.
 *
 * Expects a multipart/form-data POST with:
 *   - item_id      (int, required)
 *   - item_name    (string, required)
 *   - description  (string, optional)
 *   - price        (numeric, required)
 *   - availability (int, 1 = available, 0 = sold out)
 *   - image        (file, optional — JPEG/PNG/GIF/WebP, max 5 MB)
 *
 * Returns JSON: { success: bool, error?: string }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

// Only vendors may edit items
requireVendor();

/* --------------------------------------------------
   1. Read and validate inputs
   -------------------------------------------------- */
$item_id     = intval($_POST['item_id'] ?? 0);
$name        = trim($_POST['item_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$price       = floatval($_POST['price'] ?? 0);
$avail       = isset($_POST['availability']) ? intval($_POST['availability']) : 1;

if (!$item_id) {
    echo json_encode(['success' => false, 'error' => 'Missing item ID.']);
    exit;
}

/* --------------------------------------------------
   2. Verify item belongs to this vendor's restaurant
   -------------------------------------------------- */
$user_id = $_SESSION['user_id'];
$db      = Database::getInstance()->getConnection();

$stmt = $db->prepare(
    "SELECT r.ID AS rid
     FROM Items i
     JOIN Restaurants r ON i.restaurant_ID = r.ID
     WHERE i.ID = ? AND r.owner_ID = ?"
);
$stmt->bind_param("ii", $item_id, $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
    echo json_encode(['success' => false, 'error' => 'Item not found or unauthorized.']);
    exit;
}

$restaurant_id = $res['rid'];

/* --------------------------------------------------
   3. Handle optional image upload
   -------------------------------------------------- */
$image_url    = null;
$update_image = false;

if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/../assets/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileTmp  = $_FILES['image']['tmp_name'];
    $fileSize = $_FILES['image']['size'];
    $fileType = $_FILES['image']['type'];
    $fileExt  = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);

    // Validate MIME type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($fileType, $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'Invalid image type. Only JPEG, PNG, GIF, and WebP are allowed.']);
        exit;
    }

    // Validate file size (max 5 MB)
    if ($fileSize > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Image file is too large. Maximum size is 5 MB.']);
        exit;
    }

    // Generate unique filename and move the upload
    $uniqueName = uniqid('item_', true) . '.' . $fileExt;
    $uploadPath = $uploadDir . $uniqueName;

    if (move_uploaded_file($fileTmp, $uploadPath)) {
        $image_url    = 'assets/uploads/' . $uniqueName;
        $update_image = true;
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to upload image.']);
        exit;
    }
}

/* --------------------------------------------------
   4. Update the item record
   -------------------------------------------------- */
if ($update_image) {
    $stmt = $db->prepare(
        "UPDATE Items SET name = ?, description = ?, price = ?, isAvailable = ?, image_url = ?
         WHERE ID = ? AND restaurant_ID = ?"
    );
    $stmt->bind_param("ssdisii", $name, $description, $price, $avail, $image_url, $item_id, $restaurant_id);
} else {
    $stmt = $db->prepare(
        "UPDATE Items SET name = ?, description = ?, price = ?, isAvailable = ?
         WHERE ID = ? AND restaurant_ID = ?"
    );
    $stmt->bind_param("ssdiii", $name, $description, $price, $avail, $item_id, $restaurant_id);
}

$success = $stmt->execute();
$error   = $success ? null : $stmt->error;
$stmt->close();

echo json_encode(['success' => $success, 'error' => $error]);