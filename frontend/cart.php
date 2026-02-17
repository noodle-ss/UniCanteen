<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

$db = Database::getInstance()->getConnection();

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Handle add to cart
if (isset($_GET['add']) && isset($_GET['restaurant_id'])) {
    $item_id = intval($_GET['add']);
    $restaurant_id = intval($_GET['restaurant_id']);
    
    // Check if item exists and is available
    $checkQuery = "SELECT i.*, r.name as restaurant_name, r.ID as restaurant_id 
                   FROM Items i 
                   JOIN Restaurants r ON i.restaurant_ID = r.ID 
                   WHERE i.ID = ? AND i.isAvailable = TRUE";
    $stmt = $db->prepare($checkQuery);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($item = $result->fetch_assoc()) {
        // Check if cart has items from different restaurant
        if (!empty($_SESSION['cart'])) {
            $first_item = reset($_SESSION['cart']);
            if ($first_item['restaurant_id'] != $restaurant_id) {
                $error = "You can only order from one restaurant at a time. Please clear your cart first.";
            }
        }
        
        if (!isset($error)) {
            if (isset($_SESSION['cart'][$item_id])) {
                $_SESSION['cart'][$item_id]['quantity']++;
            } else {
                $_SESSION['cart'][$item_id] = [
                    'id' => $item['ID'],
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'quantity' => 1,
                    'restaurant_id' => $item['restaurant_id'],
                    'restaurant_name' => $item['restaurant_name']
                ];
            }
            $success = "Item added to cart!";
        }
    }
    header("Location: cart.php?" . (isset($error) ? "error=" . urlencode($error) : "success=added"));
    exit();
}

// Handle update quantity
if (isset($_POST['update_cart'])) {
    foreach ($_POST['quantity'] as $item_id => $quantity) {
        $quantity = intval($quantity);
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$item_id]);
        } else {
            $_SESSION['cart'][$item_id]['quantity'] = $quantity;
        }
    }
    header("Location: cart.php?success=updated");
    exit();
}

// Handle remove item
if (isset($_GET['remove'])) {
    $item_id = intval($_GET['remove']);
    unset($_SESSION['cart'][$item_id]);
    header("Location: cart.php?success=removed");
    exit();
}

// Handle clear cart
if (isset($_GET['clear'])) {
    $_SESSION['cart'] = [];
    header("Location: cart.php?success=cleared");
    exit();
}

// Handle checkout
if (isset($_POST['checkout'])) {
    if (empty($_SESSION['cart'])) {
        $error = "Your cart is empty!";
    } elseif (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = 'cart.php';
        header("Location: login.php");
        exit();
    } else {
        // Calculate total
        $total = 0;
        $restaurant_id = null;
        foreach ($_SESSION['cart'] as $item) {
            $total += $item['price'] * $item['quantity'];
            $restaurant_id = $item['restaurant_id'];
        }
        
        // Get next queue number for this restaurant
        $queueQuery = "SELECT COALESCE(MAX(queue_number), 2400) + 1 as next_queue 
                       FROM Orders WHERE restaurant_ID = ? AND DATE(order_date) = CURDATE()";
        $stmt = $db->prepare($queueQuery);
        $stmt->bind_param("i", $restaurant_id);
        $stmt->execute();
        $queueResult = $stmt->get_result();
        $queueData = $queueResult->fetch_assoc();
        $queue_number = $queueData['next_queue'];
        
        // Create order
        $db->begin_transaction();
        try {
            $orderQuery = "INSERT INTO Orders (customer_ID, restaurant_ID, total_amount, status, queue_number, payment_method) 
                           VALUES (?, ?, ?, 'P', ?, ?)";
            $stmt = $db->prepare($orderQuery);
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $stmt->bind_param("iidss", $_SESSION['user_id'], $restaurant_id, $total, $queue_number, $payment_method);
            $stmt->execute();
            $order_id = $db->insert_id;
            
            // Add order items
            foreach ($_SESSION['cart'] as $item) {
                $itemQuery = "INSERT INTO Order_ItemLine (order_ID, item_ID, quantity, price_at_time) 
                              VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($itemQuery);
                $stmt->bind_param("iiid", $order_id, $item['id'], $item['quantity'], $item['price']);
                $stmt->execute();
            }
            
            $db->commit();
            $_SESSION['cart'] = [];
            header("Location: order-confirmation.php?id=$order_id");
            exit();
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "Checkout failed. Please try again.";
        }
    }
}

// Get success/error messages
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// Calculate cart totals
$cart_total = 0;
$cart_items_count = 0;
$restaurant_name = '';
foreach ($_SESSION['cart'] as $item) {
    $cart_total += $item['price'] * $item['quantity'];
    $cart_items_count += $item['quantity'];
    $restaurant_name = $item['restaurant_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - UniCanteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        .cart-container {
            max-width: 1000px;
            margin: 40px auto;
        }
        .cart-header {
            display: grid;
            grid-template-columns: 3fr 1fr 1fr 1fr;
            padding: 15px 0;
            border-bottom: 2px solid #b1d9c4;
            font-weight: 600;
            color: #16623b;
        }
        .cart-item {
            display: grid;
            grid-template-columns: 3fr 1fr 1fr 1fr;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid #e0f0e8;
        }
        .cart-item:last-child {
            border-bottom: none;
        }
        .cart-item-name {
            font-weight: 500;
            color: #1a4d31;
        }
        .cart-item-price {
            font-weight: 600;
            color: #1c6e3c;
        }
        .cart-item-quantity input {
            width: 70px;
            padding: 8px;
            border: 2px solid #e0f0e8;
            border-radius: 30px;
            text-align: center;
            font-family: 'Inter', sans-serif;
        }
        .cart-item-remove {
            color: #b13e3e;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .cart-summary {
            background: white;
            border-radius: 30px;
            padding: 30px;
            margin-top: 30px;
            border: 1px solid var(--border-soft);
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e0f0e8;
        }
        .summary-row.total {
            font-size: 1.3rem;
            font-weight: 700;
            color: #007a3e;
            border-bottom: none;
        }
        .payment-methods {
            display: flex;
            gap: 20px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        .payment-method {
            flex: 1;
            min-width: 120px;
            padding: 15px;
            border: 2px solid #e0f0e8;
            border-radius: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .payment-method.selected {
            border-color: #007a3e;
            background: #e3f4ea;
        }
        .payment-method input[type="radio"] {
            display: none;
        }
        .payment-method i {
            font-size: 1.5rem;
            color: #007a3e;
            margin-bottom: 8px;
        }
        .restaurant-info {
            background: #e3f4ea;
            padding: 15px 20px;
            border-radius: 40px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
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
        <a href="<?php echo url('frontend/reviews.php'); ?>">Reviews</a>
        <?php if(isset($_SESSION['user_id'])): ?>
            <a href="<?php echo url('frontend/profile.php'); ?>"><?php echo htmlspecialchars($_SESSION['user_name']); ?></a>
            <a href="<?php echo url('frontend/logout.php'); ?>" class="btn-outline">Logout</a>
        <?php else: ?>
            <a href="<?php echo url('frontend/login.php'); ?>">Sign In</a>
            <a href="<?php echo url('frontend/register.php'); ?>" class="btn-outline">Register</a>
        <?php endif; ?>
        <a href="<?php echo url('frontend/cart.php'); ?>" class="btn-primary"><i class="fas fa-bag-shopping"></i> Cart 
            <span class="cart-count"><?php echo $cart_items_count; ?></span>
        </a>
    </div>
</nav>

            <div class="cart-container">
                <h1 style="font-size: 2.5rem; color: #0f4a2f; margin-bottom: 30px;">Shopping Cart</h1>
                
                <?php if($success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> 
                    <?php 
                    if($success == 'added') echo "Item added to cart!";
                    elseif($success == 'updated') echo "Cart updated successfully!";
                    elseif($success == 'removed') echo "Item removed from cart!";
                    elseif($success == 'cleared') echo "Cart cleared!";
                    ?>
                </div>
                <?php endif; ?>
                
                <?php if($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <?php if(empty($_SESSION['cart'])): ?>
                <div class="track-card" style="text-align: center; padding: 60px;">
                    <i class="fas fa-shopping-cart" style="font-size: 4rem; color: #cae3d6; margin-bottom: 20px;"></i>
                    <h3 style="margin-bottom: 15px; color: #1e3a2f;">Your cart is empty</h3>
                    <p style="color: #3b7455; margin-bottom: 25px;">Looks like you haven't added any items yet.</p>
                    <a href="customer.php#menu" class="btn-primary" style="display: inline-block;">
                        <i class="fas fa-utensils"></i> Browse Stalls
                    </a>
                </div>
                <?php else: ?>
                
                <div class="restaurant-info">
                    <i class="fas fa-store" style="color: #007a3e;"></i>
                    <span>Ordering from: <strong><?php echo htmlspecialchars($restaurant_name); ?></strong></span>
                    <span style="margin-left: auto; font-size: 0.9rem; color: #3b7455;">
                        <i class="fas fa-clock"></i> Same restaurant only
                    </span>
                </div>
                
                <form method="POST" action="cart.php">
                    <div class="cart-header">
                        <span>Item</span>
                        <span>Price</span>
                        <span>Quantity</span>
                        <span>Total</span>
                    </div>
                    
                    <div style="background: white; border-radius: 30px; padding: 20px; margin-bottom: 20px;">
                        <?php foreach($_SESSION['cart'] as $item_id => $item): ?>
                        <div class="cart-item">
                            <div class="cart-item-name">
                                <?php echo htmlspecialchars($item['name']); ?>
                            </div>
                            <div class="cart-item-price">
                                ₱<?php echo number_format($item['price'], 2); ?>
                            </div>
                            <div class="cart-item-quantity">
                                <input type="number" name="quantity[<?php echo $item_id; ?>]" 
                                       value="<?php echo $item['quantity']; ?>" min="0" max="10">
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span class="cart-item-price">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                                <a href="cart.php?remove=<?php echo $item_id; ?>" class="cart-item-remove" onclick="return confirm('Remove this item?')">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="submit" name="update_cart" class="btn-secondary">
                            <i class="fas fa-sync-alt"></i> Update Cart
                        </button>
                        <a href="cart.php?clear=1" class="btn-secondary" onclick="return confirm('Clear your entire cart?')">
                            <i class="fas fa-trash"></i> Clear Cart
                        </a>
                        <a href="customer.php#menu" class="btn-secondary">
                            <i class="fas fa-plus-circle"></i> Add More Items
                        </a>
                    </div>
                </form>

                <div class="cart-summary">
                    <h3 style="margin-bottom: 20px; color: #16623b;">Order Summary</h3>
                    
                    <?php 
                    $subtotal = $cart_total;
                    $service_fee = 20;
                    $total = $subtotal + $service_fee;
                    ?>
                    
                    <div class="summary-row">
                        <span>Subtotal (<?php echo $cart_items_count; ?> items)</span>
                        <span>₱<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Service Fee</span>
                        <span>₱<?php echo number_format($service_fee, 2); ?></span>
                    </div>
                    <div class="summary-row total">
                        <span>Total</span>
                        <span>₱<?php echo number_format($total, 2); ?></span>
                    </div>

                    <form method="POST" action="cart.php" id="checkoutForm">
                        <h4 style="margin: 20px 0 10px; color: #1e3a2f;">Payment Method</h4>
                        <div class="payment-methods">
                            <label class="payment-method selected">
                                <input type="radio" name="payment_method" value="cash" checked>
                                <i class="fas fa-money-bill-wave"></i>
                                <div>Cash</div>
                            </label>
                            <label class="payment-method">
                                <input type="radio" name="payment_method" value="gcash">
                                <i class="fas fa-mobile-alt"></i>
                                <div>GCash</div>
                            </label>
                            <label class="payment-method">
                                <input type="radio" name="payment_method" value="card">
                                <i class="fas fa-credit-card"></i>
                                <div>Card</div>
                            </label>
                        </div>

                        <?php if(!isLoggedIn()): ?>
                        <div class="error-message" style="margin: 20px 0;">
                            <i class="fas fa-info-circle"></i> Please <a href="login.php" style="color: #007a3e; font-weight: 600;">sign in</a> to checkout
                        </div>
                        <?php endif; ?>

                        <button type="submit" name="checkout" class="btn-primary" style="width: 100%; padding: 18px; font-size: 1.2rem;"
                                <?php echo !isLoggedIn() ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                            <i class="fas fa-check-circle"></i> Proceed to Checkout
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <footer class="footer-note">
            <i class="fas fa-shopping-cart"></i> Secure Checkout · Multiple Payment Options · Real-time Order Tracking
        </footer>
    </div>

    <script>
    // Payment method selection
    document.querySelectorAll('.payment-method').forEach(method => {
        method.addEventListener('click', function() {
            document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('selected'));
            this.classList.add('selected');
            this.querySelector('input[type="radio"]').checked = true;
        });
    });
    </script>
</body>
</html>