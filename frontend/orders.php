<?php
/**
 * orders.php — Customer order history page.
 *
 * Displays a chronological list of all orders placed by the current customer.
 * Shows order status and totals.
 * Provides quick actions to reorder meals or leave reviews for completed orders.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

requireLogin();

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];

// Get all user orders with review status
$ordersQuery = "SELECT o.*, r.name as restaurant_name,
                COUNT(oi.ID) as item_count,
                SUM(oi.quantity) as total_items,
                rt.ID as review_id
                FROM Orders o
                JOIN Restaurants r ON o.restaurant_ID = r.ID
                LEFT JOIN Order_ItemLine oi ON o.ID = oi.order_ID
                LEFT JOIN Ratings rt ON o.ID = rt.order_ID
                WHERE o.customer_ID = ?
                GROUP BY o.ID
                ORDER BY o.order_date DESC";
$stmt = $db->prepare($ordersQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$orders = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - UniCanteen</title>
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

        .orders-container {
            max-width: 1000px;
            margin: 40px auto;
        }

        .order-card {
            background: white;
            border-radius: 30px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid var(--border-soft);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0f0e8;
        }

        .order-status {
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-P {
            background: #f5e6e6;
            color: #b13e3e;
        }

        .status-PR {
            background: #fff1cf;
            color: #9e6d0b;
        }

        .status-R {
            background: #c9f0d7;
            color: #0c6e3a;
        }

        .status-C {
            background: #d0e3ff;
            color: #1f5090;
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
                    <a href="<?php echo url('index.php?page=orders'); ?>" style="font-weight: 700; color: #007a3e;">Orders</a>
                    <a href="<?php echo url('index.php?page=reviews'); ?>">Reviews</a>
                    <a
                        href="<?php echo url('index.php?page=profile'); ?>"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Profile'); ?></a>
                    <a href="<?php echo url('index.php?page=logout'); ?>" class="btn-outline">Logout</a>
                    <a href="<?php echo url('index.php?page=cart'); ?>" class="btn-primary">
                        <i class="fas fa-bag-shopping"></i> Cart
                        <?php
                        $cart_items_count = 0;
                        $current_cart_restaurant_id = null;
                        if (isset($_SESSION['cart'])) {
                            foreach ($_SESSION['cart'] as $ci) {
                                $cart_items_count += $ci['quantity'];
                                if ($current_cart_restaurant_id === null) {
                                    $current_cart_restaurant_id = $ci['restaurant_id'];
                                }
                            }
                        }
                        ?>
                        <span
                            style="background:white;color:#007a3e;border-radius:50%;padding:2px 6px;font-size:0.7rem;margin-left:5px;"><?php echo $cart_items_count; ?></span>
                    </a>
                </div>
            </nav>
            <div class="orders-container">
                <h1 style="font-size: 2.5rem; color: #0f4a2f; margin-bottom: 30px;">My Orders</h1>

                <?php if ($orders->num_rows > 0): ?>
                    <?php while ($order = $orders->fetch_assoc()): ?>
                        <div class="order-card">
                            <div class="order-header">
                                <div>
                                    <h3 style="margin-bottom: 5px;">Order #<?php echo $order['queue_number']; ?></h3>
                                    <p style="color: #3b7455;"><?php echo $order['restaurant_name']; ?> ·
                                        <?php echo date('F d, Y g:i A', strtotime($order['order_date'])); ?>
                                    </p>
                                </div>
                                <div class="order-status status-<?php echo $order['status']; ?>">
                                    <?php
                                    $statuses = ['P' => 'Pending', 'PR' => 'Preparing', 'R' => 'Ready for Pickup', 'C' => 'Completed'];
                                    echo $statuses[$order['status']];
                                    ?>
                                </div>
                            </div>

                            <div
                                style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                                <div>
                                    <p><i class="fas fa-box"></i> <?php echo $order['total_items']; ?> items</p>
                                    <p><i class="fas fa-money-bill"></i> Total:
                                        <strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong>
                                    </p>
                                    <p style="margin-top: 5px; font-size: 0.9rem; color: #4a755e;">
                                        <?php if ($order['payment_method'] === 'card'): ?>
                                            <i class="fas fa-credit-card"
                                                style="color: #1f5090; width: 16px; text-align: center;"></i> Card
                                        <?php else: ?>
                                            <i class="fas fa-mobile-screen-button"
                                                style="color: #007a3e; width: 16px; text-align: center;"></i> GCash
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div style="display:flex; gap:10px; flex-wrap: wrap;">
                                    <?php if ($order['status'] === 'C'): ?>
                                        <?php if ($order['review_id']): ?>
                                            <span class="btn-primary" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; cursor:default; flex:none; padding:0 18px; font-size:0.85rem; display:inline-flex; align-items:center; height:40px; border-radius:30px; box-sizing:border-box;">
                                                <i class="fas fa-check-circle" style="margin-right:5px;"></i> Reviewed
                                            </span>
                                        <?php else: ?>
                                            <a href="<?php echo url('index.php?page=reviews'); ?>" class="btn-primary" style="background:#fef9c3; color:#854d0e; border:1px solid #fde68a; text-decoration:none; flex:none; padding:0 18px; font-size:0.85rem; display:inline-flex; align-items:center; height:40px; border-radius:30px; box-sizing:border-box;">
                                                <i class="fas fa-star" style="margin-right:5px;"></i> Leave Review
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <form method="POST" action="<?php echo url('index.php?page=reorder'); ?>" style="margin:0;" onsubmit="return confirmReorder(this, <?php echo $order['restaurant_ID']; ?>);">
                                        <input type="hidden" name="order_id" value="<?php echo $order['ID']; ?>">
                                        <button type="submit" class="btn-primary" style="background:#e3f4ea; color:#007a3e; border:1px solid #b8e0cc; flex:none; padding:0 18px; font-size:0.85rem; display:inline-flex; align-items:center; height:40px; border-radius:30px; box-sizing:border-box; cursor:pointer;">
                                            <i class="fas fa-redo-alt" style="margin-right:5px;"></i> Reorder
                                        </button>
                                    </form>
                                    
                                    <a href="<?php echo url('index.php?page=order-details&id=' . $order['ID']); ?>" class="btn-primary" style="flex:none; padding:0 18px; font-size:0.85rem; display:inline-flex; align-items:center; height:40px; border-radius:30px; border:1px solid transparent; box-sizing:border-box; text-decoration:none;">
                                        View Details <i class="fas fa-arrow-right" style="margin-left:5px;"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="track-card" style="text-align: center; padding: 60px;">
                        <i class="fas fa-clipboard-list" style="font-size: 4rem; color: #cae3d6; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 15px;">No orders yet</h3>
                        <p style="color: #3b7455; margin-bottom: 25px;">Start ordering from your favorite campus stalls!</p>
                        <a href="<?php echo url('index.php?page=customer'); ?>#menu" class="btn-primary"
                            style="display: inline-block;">
                            <i class="fas fa-utensils"></i> Browse Stalls
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <footer class="footer-note">
            <i class="fas fa-clipboard-list"></i> Order History · Track Orders · Reorder Favorites
        </footer>
    </div>

    <script>
        const currentCartRestaurantId = <?php echo $current_cart_restaurant_id ? $current_cart_restaurant_id : 'null'; ?>;
        
        function confirmReorder(form, targetRestaurantId) {
            // If cart has items from a different restaurant
            if (currentCartRestaurantId !== null && currentCartRestaurantId !== targetRestaurantId) {
                return confirm("Your cart currently contains items from a different stall. Reordering this meal will clear your current cart. Proceed?");
            }
            return true;
        }
    </script>
</body>

</html>