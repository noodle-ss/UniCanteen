<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

requireLogin();

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];

// Get all user orders
$ordersQuery = "SELECT o.*, r.name as restaurant_name,
                COUNT(oi.ID) as item_count,
                SUM(oi.quantity) as total_items
                FROM Orders o
                JOIN Restaurants r ON o.restaurant_ID = r.ID
                LEFT JOIN Order_ItemLine oi ON o.ID = oi.order_ID
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
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
        .status-P { background: #f5e6e6; color: #b13e3e; }
        .status-PR { background: #fff1cf; color: #9e6d0b; }
        .status-R { background: #c9f0d7; color: #0c6e3a; }
        .status-C { background: #d0e3ff; color: #1f5090; }
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
        <a href="<?php echo url('frontend/reviews.php'); ?>">Reviews</a>
        <a href="<?php echo url('frontend/profile.php'); ?>"><?php echo htmlspecialchars($_SESSION['user_name']); ?></a>
        <a href="<?php echo url('frontend/logout.php'); ?>" class="btn-outline">Logout</a>
        <a href="<?php echo url('frontend/cart.php'); ?>" class="btn-primary"><i class="fas fa-bag-shopping"></i> Cart</a>
    </div>
</nav>

            <div class="orders-container">
                <h1 style="font-size: 2.5rem; color: #0f4a2f; margin-bottom: 30px;">My Orders</h1>
                
                <?php if($orders->num_rows > 0): ?>
                    <?php while($order = $orders->fetch_assoc()): ?>
                    <div class="order-card">
                        <div class="order-header">
                            <div>
                                <h3 style="margin-bottom: 5px;">Order #<?php echo $order['queue_number']; ?></h3>
                                <p style="color: #3b7455;"><?php echo $order['restaurant_name']; ?> · <?php echo date('F d, Y g:i A', strtotime($order['order_date'])); ?></p>
                            </div>
                            <div class="order-status status-<?php echo $order['status']; ?>">
                                <?php 
                                $statuses = ['P' => 'Pending', 'PR' => 'Preparing', 'R' => 'Ready for Pickup', 'C' => 'Completed'];
                                echo $statuses[$order['status']];
                                ?>
                            </div>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                            <div>
                                <p><i class="fas fa-box"></i> <?php echo $order['total_items']; ?> items</p>
                                <p><i class="fas fa-money-bill"></i> Total: <strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong></p>
                            </div>
                            <a href="order-details.php?id=<?php echo $order['ID']; ?>" class="btn-primary">
                                View Details <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="track-card" style="text-align: center; padding: 60px;">
                        <i class="fas fa-clipboard-list" style="font-size: 4rem; color: #cae3d6; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 15px;">No orders yet</h3>
                        <p style="color: #3b7455; margin-bottom: 25px;">Start ordering from your favorite campus stalls!</p>
                        <a href="customer.php#menu" class="btn-primary" style="display: inline-block;">
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
</body>
</html>