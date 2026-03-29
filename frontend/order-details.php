<?php
/**
 * order-details.php — Customer order summary page.
 *
 * Displays the complete details for a specific order, including
 * ordered items, quantities, total amount, order status, and payment method.
 * Allows the customer to easily reorder the exact same meal.
 *
 * Expects GET with 'id' parameter defining the order ID.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

requireLogin();

if (!isset($_GET['id'])) {
    header('Location: ' . url('index.php?page=orders'));
    exit();
}

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];
$order_id = intval($_GET['id']);

$orderQuery = "SELECT o.*, r.name as restaurant_name 
               FROM Orders o 
               JOIN Restaurants r ON o.restaurant_ID = r.ID 
               WHERE o.ID = ? AND o.customer_ID = ?";
$stmt = $db->prepare($orderQuery);
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$currentOrder = $stmt->get_result()->fetch_assoc();

if (!$currentOrder) {
    header('Location: ' . url('index.php?page=orders'));
    exit();
}

$itemsQuery = "SELECT oi.*, i.name as item_name, r.name as restaurant_name 
               FROM Order_ItemLine oi 
               JOIN Items i ON oi.item_ID = i.ID 
               JOIN Restaurants r ON i.restaurant_ID = r.ID 
               WHERE oi.order_ID = ?";
$stmt = $db->prepare($itemsQuery);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$orderItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Check if order has been reviewed
$hasReview = false;
if ($currentOrder['status'] === 'C') {
    $reviewCheckStmt = $db->prepare("SELECT ID FROM Ratings WHERE order_ID = ?");
    $reviewCheckStmt->bind_param("i", $order_id);
    $reviewCheckStmt->execute();
    $hasReview = $reviewCheckStmt->get_result()->num_rows > 0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details - UniCanteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
        .wrapper {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 36px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            border: 1.5px solid #cae3d6;
            color: #007a3e;
            padding: 10px 24px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            margin-bottom: 30px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(0, 80, 20, 0.05);
        }

        .btn-back:hover {
            background: #f0f7f2;
            border-color: #007a3e;
            transform: translateY(-2px);
        }

        .order-details-container {
            max-width: 800px;
            margin: 40px auto;
            width: 100%;
            padding: 0 20px;
            box-sizing: border-box;
        }

        .order-header-info {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #e0f0e8;
            padding-bottom: 25px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .order-title-group {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .order-icon-wrapper {
            background: #e3f4ea;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 0 0 4px #d1edd9;
        }

        .order-summary-box {
            background: #f9fffc;
            border-radius: 20px;
            padding: 25px;
            border: 1px solid #e0f0e8;
        }

        .order-item-card {
            background: white;
            border-radius: 14px;
            padding: 15px 20px;
            border: 1px solid #e0f0e8;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.2s;
        }

        .order-item-card:hover {
            transform: scale(1.01);
            border-color: #cae3d6;
            box-shadow: 0 4px 12px rgba(0, 80, 20, 0.04);
        }

        .status-badge {
            background: #daf1e2;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 600;
            color: #007a3e;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #c3e6d1;
        }

        .status-badge-container {
            margin-left: auto;
        }

        @media (max-width: 600px) {
            .order-header-info {
                flex-direction: column;
            }

            .status-badge-container {
                margin-left: 0;
            }
        }
    </style>
</head>

<body>
    <div class="main-content">
        <div class="wrapper">
            <nav class="customer-nav">
                <a href="<?php echo url('index.php'); ?>" class="logo">UniCanteen <span>DLSU</span></a>
                <div class="customer-nav-links">
                    <a href="<?php echo url('index.php?page=customer'); ?>#menu">Menu</a>
                    <a href="<?php echo url('index.php?page=customer'); ?>#track">Track</a>
                    <a href="<?php echo url('index.php?page=favorites'); ?>">Favorites</a>
                    <a href="<?php echo url('index.php?page=orders'); ?>">Orders</a>
                    <a href="<?php echo url('index.php?page=reviews'); ?>">Reviews</a>
                    <a href="<?php echo url('index.php?page=profile'); ?>">
                        <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Profile'); ?>
                    </a>
                    <a href="<?php echo url('index.php?page=logout'); ?>" class="btn-outline">Logout</a>
                    <a href="<?php echo url('index.php?page=cart'); ?>" class="btn-primary">
                        <i class="fas fa-bag-shopping"></i> Cart
                    </a>
                </div>
            </nav>

            <div class="order-details-container">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 10px;">
                    <a href="<?php echo url('index.php?page=orders'); ?>" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Orders
                    </a>
                    <div style="display: flex; gap: 10px; margin-bottom: 30px;">
                        <?php if ($currentOrder['status'] === 'C'): ?>
                            <?php if ($hasReview): ?>
                                <span style="display:inline-flex; align-items:center; gap:8px; background:#dcfce7; color:#166534; padding:10px 24px; border-radius:40px; font-weight:600; font-size:0.95rem; border:1px solid #bbf7d0;">
                                    <i class="fas fa-check-circle"></i> Reviewed
                                </span>
                            <?php else: ?>
                                <a href="<?php echo url('index.php?page=reviews'); ?>" style="display:inline-flex; align-items:center; gap:8px; background:#fef9c3; color:#854d0e; padding:10px 24px; border-radius:40px; font-weight:600; font-size:0.95rem; text-decoration:none; border:1px solid #fde68a; transition:all 0.2s ease;">
                                    <i class="fas fa-star"></i> Leave Review
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <form method="POST" action="<?php echo url('index.php?page=reorder'); ?>" style="margin-bottom:0;" onsubmit="return confirmReorder(this, <?php echo $currentOrder['restaurant_ID']; ?>);">
                            <input type="hidden" name="order_id" value="<?php echo $currentOrder['ID']; ?>">
                            <button type="submit" style="display:inline-flex; align-items:center; gap:8px; background:#007a3e; border:none; color:#fff; padding:10px 24px; border-radius:40px; font-weight:600; font-size:0.95rem; cursor:pointer; box-shadow:0 4px 12px rgba(0, 122, 62, 0.2); transition:all 0.2s ease;">
                                <i class="fas fa-redo-alt"></i> Reorder Entire Meal
                            </button>
                        </form>
                    </div>
                </div>

                <div class="track-card" style="padding: 30px;">
                    <div class="order-header-info">
                        <div class="order-title-group">
                            <div class="order-icon-wrapper">
                                <i class="fas fa-receipt" style="font-size: 2rem; color: #007a3e;"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0 0 8px 0; font-size: 1.8rem; color: #0f4a2f;">Order
                                    #<?php echo $currentOrder['queue_number']; ?></h3>
                                <p style="color: #4a755e; margin: 0; font-size: 0.95rem;">
                                    From <strong
                                        style="color: #1a4d31;"><?php echo htmlspecialchars($currentOrder['restaurant_name']); ?></strong><br>
                                    <span style="font-size: 0.85rem; opacity: 0.8;"><i class="far fa-clock"></i>
                                        <?php echo date('F d, Y · g:i A', strtotime($currentOrder['order_date'])); ?></span>
                                </p>
                            </div>
                        </div>
                        <div class="status-badge-container">
                            <div class="status-badge">
                                <span class="status-dot"
                                    style="width: 10px; height: 10px; background: #007a3e; border-radius: 50%; display: inline-block; box-shadow: 0 0 0 3px rgba(0, 122, 62, 0.2);"></span>
                                <?php
                                $statusText = [
                                    'P' => 'Pending Approval',
                                    'PR' => 'Preparing Now',
                                    'R' => 'Ready for Pickup',
                                    'C' => 'Completed'
                                ];
                                echo $statusText[$currentOrder['status']];
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Progress tracker -->
                    <div class="order-progress"
                        style="margin-bottom: 35px; background: white; padding: 25px 20px; border-radius: 20px; border: 1px solid #e0f0e8;">
                        <div class="progress-step">
                            <div
                                class="step-circle <?php echo in_array($currentOrder['status'], ['P', 'PR', 'R', 'C']) ? 'active' : 'inactive'; ?>">
                                <i class="fas fa-check"></i>
                            </div>
                            <span class="step-label">Order Placed</span>
                        </div>
                        <div class="progress-step">
                            <div
                                class="step-circle <?php echo in_array($currentOrder['status'], ['PR', 'R', 'C']) ? 'active' : 'inactive'; ?>">
                                <i class="fas fa-fire"></i>
                            </div>
                            <span class="step-label">Preparing</span>
                        </div>
                        <div class="progress-step">
                            <div
                                class="step-circle <?php echo in_array($currentOrder['status'], ['R', 'C']) ? 'active' : 'inactive'; ?>">
                                <i class="fas fa-bag-shopping"></i>
                            </div>
                            <span class="step-label">Ready</span>
                        </div>
                        <div class="progress-step">
                            <div
                                class="step-circle <?php echo $currentOrder['status'] == 'C' ? 'active' : 'inactive'; ?>">
                                <i class="fas fa-circle-check"></i>
                            </div>
                            <span class="step-label">Picked Up</span>
                        </div>
                    </div>

                    <div class="order-summary-box">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h4 style="margin: 0; font-size: 1.2rem; color: #1a4d31;"><i class="fas fa-shopping-basket"
                                    style="color: #007a3e; margin-right: 8px;"></i>Order Summary</h4>
                            <span
                                style="background: #e3f4ea; color: #007a3e; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600;"><?php echo count($orderItems); ?>
                                Items</span>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 25px;">
                            <?php foreach ($orderItems as $item): ?>
                                <div class="order-item-card">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <div
                                            style="background: #f0f7f2; color: #007a3e; font-weight: 700; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; border: 1px solid #cae3d6;">
                                            <?php echo $item['quantity']; ?>x
                                        </div>
                                        <span style="font-weight: 600; color: #1a4d31; font-size: 1.05rem;">
                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                        </span>
                                    </div>
                                    <span
                                        style="font-weight: 700; color: #007a3e; font-size: 1.1rem;">₱<?php echo number_format($item['price_at_time'], 2); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="border-top: 2px dashed #cae3d6; margin: 25px 0;"></div>

                        <div
                            style="display: flex; justify-content: space-between; align-items: center; padding: 0 10px;">
                            <span style="font-size: 1.1rem; color: #4a755e; font-weight: 600;">Total Amount</span>
                            <span
                                style="font-size: 1.8rem; font-weight: 800; color: #0f4a2f;">₱<?php echo number_format($currentOrder['total_amount'], 2); ?></span>
                        </div>

                        <div
                            style="display: flex; justify-content: space-between; align-items: center; padding: 15px 10px 0; margin-top: 15px; border-top: 1px solid #e0f0e8;">
                            <span style="font-size: 0.95rem; color: #4a755e; font-weight: 500;">Payment Method</span>
                            <span
                                style="font-size: 1.05rem; font-weight: 700; color: #1a4d31; display: flex; align-items: center; gap: 8px;">
                                <?php if ($currentOrder['payment_method'] === 'card'): ?>
                                    <i class="fas fa-credit-card" style="color: #1f5090;"></i> Card
                                <?php else: ?>
                                    <i class="fas fa-mobile-screen-button" style="color: #007a3e;"></i> GCash
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div> <!-- closes track-card -->
            </div> <!-- closes order-details-container -->

            <?php
            // Pass cart info to JS for validation
            $current_cart_restaurant_id = null;
            if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
                $first_item = reset($_SESSION['cart']);
                $current_cart_restaurant_id = $first_item['restaurant_id'];
            }
            ?>
            <script>
                const currentCartRestaurantId = <?php echo $current_cart_restaurant_id ? $current_cart_restaurant_id : 'null'; ?>;
                function confirmReorder(form, targetRestaurantId) {
                    if (currentCartRestaurantId !== null && currentCartRestaurantId !== targetRestaurantId) {
                        return confirm("Your cart currently contains items from a different stall. Reordering this meal will clear your current cart. Proceed?");
                    }
                    return true;
                }
            </script>

            <footer class="footer-note" style="margin-top: 50px; padding-bottom: 40px; color: #4a755e;">
                <i class="fas fa-clipboard-list"></i> Order History · Track Orders · Reorder Favorites
            </footer>
        </div> <!-- closes main-content -->
</body>

</html>