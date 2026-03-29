<?php
/**
 * add_item.php — Vendor endpoint to add a new menu item.
 *
 * Expects a multipart/form-data POST with:
 *   - name        (string, required)
 *   - description (string, optional)
 *   - price       (numeric, required, > 0)
 *   - status      ("available" | "unavailable")
 *   - image       (file, optional — JPEG/PNG/GIF/WebP, max 5 MB)
 *
 * Returns JSON: { success: bool, error?: string }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

// Only vendors may add items
requireVendor();

$user_id = $_SESSION['user_id'];
$db      = Database::getInstance()->getConnection();

/* --------------------------------------------------
   1. Look up the restaurant owned by this vendor
   -------------------------------------------------- */
$stmt = $db->prepare("SELECT ID FROM Restaurants WHERE owner_ID = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$restaurant = $stmt->get_result()->fetch_assoc();
$stmt->close();

$restaurant_id = $restaurant['ID'] ?? 0;

if (!$restaurant_id) {
    echo json_encode(['success' => false, 'error' => 'No restaurant found for this vendor.']);
    exit;
}

/* --------------------------------------------------
   2. Read and validate form inputs
   -------------------------------------------------- */
$name        = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$price       = floatval($_POST['price'] ?? 0);
$isAvailable = (isset($_POST['status']) && $_POST['status'] === 'available') ? 1 : 0;

if ($name === '') {
    echo json_encode(['success' => false, 'error' => 'Item name is required.']);
    exit;
}

if ($price <= 0) {
    echo json_encode(['success' => false, 'error' => 'Price must be greater than zero.']);
    exit;
}

// Check for duplicate item name
$checkStmt = $db->prepare("SELECT ID FROM Items WHERE restaurant_ID = ? AND name = ?");
$checkStmt->bind_param("is", $restaurant_id, $name);
$checkStmt->execute();
if ($checkStmt->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'error' => 'An item with this name already exists.']);
    exit;
}
$checkStmt->close();

/* --------------------------------------------------
   3. Handle optional image upload
   -------------------------------------------------- */
$image_url = null;

if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/../assets/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileTmp  = $_FILES['image']['tmp_name'];
    $fileSize = $_FILES['image']['size'];
    $fileType = $_FILES['image']['type'];
    $fileExt  = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);

    // Validate File type
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

    // Generate a unique filename and move the upload
    $uniqueName = uniqid('item_', true) . '.' . $fileExt;
    $uploadPath = $uploadDir . $uniqueName;

    if (move_uploaded_file($fileTmp, $uploadPath)) {
        $image_url = 'assets/uploads/' . $uniqueName;
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to upload image.']);
        exit;
    }
}

/* --------------------------------------------------
   4. Insert the new item into the database
   -------------------------------------------------- */
$stmt = $db->prepare(
    "INSERT INTO Items (name, description, price, isAvailable, restaurant_ID, image_url)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param("ssdiis", $name, $description, $price, $isAvailable, $restaurant_id, $image_url);

$success = $stmt->execute();
$error   = $success ? null : $stmt->error;
$stmt->close();

echo json_encode([
    'success'       => $success,
    'error'         => $error,
    'restaurant_id' => $restaurant_id,
]);