<?php
/**
 * toggle_stall.php — Vendor endpoint to toggle restaurant open/closed status.
 *
 * Expects a POST with:
 *   - restaurant_id (int, required)
 *
 * Redirects back to the referring page on success.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

// Only vendors may toggle stall status
requireVendor();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?page=vendor');
}

$restaurant_id = intval($_POST['restaurant_id'] ?? 0);
$user_id       = $_SESSION['user_id'];

if (!$restaurant_id) {
    redirect('index.php?page=vendor&error=invalid_restaurant');
}

$db = Database::getInstance()->getConnection();

/* --------------------------------------------------
   Verify vendor owns this restaurant before toggling
   -------------------------------------------------- */
$stmt = $db->prepare("UPDATE Restaurants SET is_open = NOT is_open WHERE ID = ? AND owner_ID = ?");
$stmt->bind_param("ii", $restaurant_id, $user_id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    redirect('index.php?page=vendor&error=unauthorized');
}

// Redirect back to the vendor dashboard
redirect('index.php?page=vendor');