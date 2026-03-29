<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

$db = Database::getInstance()->getConnection();

// Initialize cart
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ── Handle add to cart ──────────────────────────────────────────────────────
if (isset($_GET['add']) && isset($_GET['restaurant_id'])) {
    $item_id      = intval($_GET['add']);
    $restaurant_id = intval($_GET['restaurant_id']);

    $checkQuery = "SELECT i.*, r.name as restaurant_name, r.ID as restaurant_id, r.is_open
                   FROM Items i
                   JOIN Restaurants r ON i.restaurant_ID = r.ID
                   WHERE i.ID = ? AND i.isAvailable = TRUE";
    $stmt = $db->prepare($checkQuery);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $addError = null;
    if ($item = $result->fetch_assoc()) {
        // Check if the restaurant is open
        if (!$item['is_open']) {
            $addError = "This store is currently closed. You cannot add items from a closed store.";
        }
        if (!$addError && !empty($_SESSION['cart'])) {
            $first = reset($_SESSION['cart']);
            if ($first['restaurant_id'] != $restaurant_id) {
                $addError = "You can only order from one restaurant at a time. Clear your cart first.";
            }
        }
        if (!$addError) {
            if (isset($_SESSION['cart'][$item_id])) {
                $_SESSION['cart'][$item_id]['quantity']++;
            } else {
                $_SESSION['cart'][$item_id] = [
                    'id'              => $item['ID'],
                    'name'            => $item['name'],
                    'price'           => $item['price'],
                    'quantity'        => 1,
                    'restaurant_id'   => $item['restaurant_id'],
                    'restaurant_name' => $item['restaurant_name'],
                ];
            }
            // Stay on previous page with a flash, NOT redirect to cart
            $itemName = urlencode($item['name']);
            $return_to = $_GET['return_to'] ?? null;
            if ($return_to === 'favorites') {
                header("Location: " . url('index.php?page=favorites&added=' . urlencode($item['name'])));
            } else {
                header("Location: " . url("index.php?page=restaurant&id={$restaurant_id}&added=" . $itemName));
            }
            exit();
        }
    }
    $return_to = $_GET['return_to'] ?? null;
    if ($return_to === 'favorites') {
        header("Location: " . url('index.php?page=favorites&error=' . urlencode($addError ?? "Item not available.")));
    } else {
        header("Location: " . url("index.php?page=restaurant&id={$restaurant_id}&error=" . urlencode($addError ?? "Item not available.")));
    }
    exit();
}

// ── Handle update quantity ───────────────────────────────────────────────────
if (isset($_POST['update_cart'])) {
    foreach ($_POST['quantity'] as $item_id => $quantity) {
        $quantity = intval($quantity);
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$item_id]);
        } else {
            $_SESSION['cart'][$item_id]['quantity'] = $quantity;
        }
    }
    header("Location: " . url('index.php?page=cart&success=updated'));
    exit();
}

// ── Handle remove ───────────────────────────────────────────────────────────
if (isset($_GET['remove'])) {
    unset($_SESSION['cart'][intval($_GET['remove'])]);
    header("Location: " . url('index.php?page=cart&success=removed'));
    exit();
}

// ── Handle clear ────────────────────────────────────────────────────────────
if (isset($_GET['clear'])) {
    $_SESSION['cart'] = [];
    header("Location: " . url('index.php?page=cart&success=cleared'));
    exit();
}

// ── Handle checkout ─────────────────────────────────────────────────────────
if (isset($_POST['checkout'])) {
    if (empty($_SESSION['cart'])) {
        $checkoutError = "Your cart is empty!";
    } elseif (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = 'index.php?page=cart';
        header("Location: " . url('index.php?page=login'));
        exit();
    } else {
        // Verify the restaurant is still open before checkout
        $restaurant_id_checkout = null;
        foreach ($_SESSION['cart'] as $item) {
            $restaurant_id_checkout = $item['restaurant_id'];
            break;
        }
        $openCheck = $db->prepare("SELECT is_open FROM Restaurants WHERE ID = ?");
        $openCheck->bind_param("i", $restaurant_id_checkout);
        $openCheck->execute();
        $openRes = $openCheck->get_result()->fetch_assoc();
        if (!$openRes || !$openRes['is_open']) {
            $checkoutError = "Sorry, this store has closed. Your order cannot be placed at this time.";
        } else {
            $subtotal      = 0;
        $restaurant_id = null;
        foreach ($_SESSION['cart'] as $item) {
            $subtotal += $item['price'] * $item['quantity'];
            $restaurant_id = $item['restaurant_id'];
        }
        $service_fee_checkout = 15;
        $total = $subtotal + $service_fee_checkout;

        $queueQuery = "SELECT COALESCE(MAX(queue_number), 0) + 1 as next_queue
                       FROM Orders WHERE restaurant_ID = ? AND DATE(order_date) = CURDATE()";
        $stmt = $db->prepare($queueQuery);
        $stmt->bind_param("i", $restaurant_id);
        $stmt->execute();
        $queueData = $stmt->get_result()->fetch_assoc();
        $queue_number = $queueData['next_queue'];
        $stmt->close();

        $payment_method = isset($_POST['payment_method']) && in_array($_POST['payment_method'], ['gcash', 'card']) 
            ? $_POST['payment_method'] : 'gcash';

        $db->begin_transaction();
        try {
            $orderQuery = "INSERT INTO Orders (customer_ID, restaurant_ID, total_amount, status, queue_number, payment_method)
                           VALUES (?, ?, ?, 'P', ?, ?)";
            $stmt = $db->prepare($orderQuery);
            $stmt->bind_param("iidis", $_SESSION['user_id'], $restaurant_id, $total, $queue_number, $payment_method);
            $stmt->execute();
            $order_id = $db->insert_id;

            foreach ($_SESSION['cart'] as $item) {
                $itemQuery = "INSERT INTO Order_ItemLine (order_ID, item_ID, quantity, price_at_time) VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($itemQuery);
                $stmt->bind_param("iiid", $order_id, $item['id'], $item['quantity'], $item['price']);
                $stmt->execute();
            }

            $db->commit();
            $_SESSION['cart'] = [];
            header("Location: " . url("index.php?page=orders&order_id=$order_id&success=placed"));
            exit();
        } catch (Exception $e) {
            $db->rollback();
            $checkoutError = "Checkout failed. Please try again.";
        }
    }
    }
}

// ── Page vars ────────────────────────────────────────────────────────────────
$successMsg = $_GET['success'] ?? '';
$errorMsg   = $_GET['error']   ?? ($checkoutError ?? '');
$warningMsg = $_SESSION['flash_warning'] ?? '';
unset($_SESSION['flash_warning']);

$cart_total       = 0;
$cart_items_count = 0;
$restaurant_name  = '';
foreach ($_SESSION['cart'] as $item) {
    $cart_total       += $item['price'] * $item['quantity'];
    $cart_items_count += $item['quantity'];
    $restaurant_name   = $item['restaurant_name'];
}
$restaurant_id_for_back = '';
if (!empty($_SESSION['cart'])) {
    $first = reset($_SESSION['cart']);
    $restaurant_id_for_back = $first['restaurant_id'];
}

$subtotal    = $cart_total;
$service_fee = 15;
$grand_total = $subtotal + $service_fee;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart · UniCanteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
        /* ── Layout reset ── */
        .wrapper { max-width: 1300px; margin: 0 auto; padding: 0 36px; }

        .cart-count {
            background: white; color: #007a3e;
            border-radius: 50%; padding: 2px 6px;
            font-size: 0.7rem; margin-left: 5px;
        }

        /* ── Page hero ── */
        .cart-hero {
            background: linear-gradient(135deg, #005c2e 0%, #007a3e 50%, #1a8c4a 100%);
            padding: 0;
        }
        .cart-hero-inner {
            padding: 32px 0 36px;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.17);
            border: 1.5px solid rgba(255,255,255,0.38);
            color: #fff;
            padding: 9px 20px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            margin-bottom: 20px;
            transition: background 0.2s;
        }
        .btn-back:hover { background: rgba(255,255,255,0.28); }
        .cart-hero h1 {
            font-size: 2.2rem; font-weight: 700;
            color: #fff; margin: 0 0 6px;
        }
        .cart-hero-sub { color: rgba(255,255,255,0.8); font-size: 0.95rem; margin: 0; }

        /* ── Content layout ── */
        .cart-body { padding: 40px 0 60px; }
        .cart-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 28px;
            align-items: start;
        }
        @media (max-width: 900px) {
            .cart-layout { grid-template-columns: 1fr; }
        }

        /* ── Toasts ── */
        .toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 20px;
            border-radius: 16px;
            font-weight: 500;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        .toast.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .toast.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .toast.warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

        /* ── Restaurant banner ── */
        .stall-banner {
            display: flex; align-items: center;
            gap: 12px;
            background: #fff;
            border: 1.5px solid #d0eddc;
            border-radius: 18px;
            padding: 14px 20px;
            margin-bottom: 18px;
        }
        .stall-banner-icon {
            width: 40px; height: 40px;
            background: #e3f4ea; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: #007a3e; font-size: 1rem; flex-shrink: 0;
        }
        .stall-banner-name { font-weight: 700; color: #1a3d28; font-size: 0.95rem; }
        .stall-banner-note { font-size: 0.78rem; color: #6b8f7a; margin-top: 2px; }

        /* ── Cart items card ── */
        .cart-card {
            background: #fff;
            border-radius: 24px;
            border: 1.5px solid #e0f0e8;
            overflow: hidden;
        }
        .cart-card-header {
            display: grid;
            grid-template-columns: 1fr 90px 90px 90px 44px;
            gap: 8px;
            padding: 14px 22px;
            background: #f4fbf7;
            border-bottom: 1.5px solid #e0f0e8;
            font-size: 0.8rem;
            font-weight: 700;
            color: #4a7560;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .cart-card-header span:not(:first-child) { text-align: center; }
        .cart-card-header span:nth-child(3) { text-align: right; }

        .cart-row {
            display: grid;
            grid-template-columns: 1fr 90px 90px 90px 44px;
            gap: 8px;
            align-items: center;
            padding: 16px 22px;
            border-bottom: 1px solid #eef7f2;
            transition: background 0.15s;
        }
        .cart-row:last-child { border-bottom: none; }
        .cart-row:hover { background: #fafffe; }

        .item-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1a3d28;
        }
        .item-rest {
            font-size: 0.75rem;
            color: #6b8f7a;
            margin-top: 2px;
        }
        .item-price {
            text-align: center;
            font-weight: 500;
            color: #2d6347;
            font-size: 0.9rem;
        }

        .qty-control {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .qty-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 1.5px solid #c8e6d4;
            background: #f4fbf7;
            color: #007a3e;
            font-size: 1rem;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
            padding: 0;
            flex-shrink: 0;
        }
        .qty-btn:hover { background: #007a3e; color: #fff; border-color: #007a3e; }
        .qty-btn:active { transform: scale(0.92); }
        .qty-control input[type="number"] {
            width: 52px;
            padding: 7px 6px;
            border: 1.5px solid #c8e6d4;
            border-radius: 10px;
            text-align: center;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            color: #1a3d28;
            font-weight: 600;
            background: #f4fbf7;
            outline: none;
            -moz-appearance: textfield;
        }
        .qty-control input:focus { border-color: #007a3e; background: #fff; }
        .qty-control input::-webkit-outer-spin-button,
        .qty-control input::-webkit-inner-spin-button { -webkit-appearance: none; }

        .item-subtotal {
            text-align: right;
            font-weight: 700;
            color: #007a3e;
            font-size: 0.95rem;
        }
        .btn-remove {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.8rem;
            transition: background 0.2s;
            margin: 0 auto;
        }
        .btn-remove:hover { background: #fee2e2; }

        /* action bar */
        .cart-actions {
            display: flex;
            gap: 10px;
            padding: 16px 22px;
            background: #f9fef9;
            border-top: 1.5px solid #e0f0e8;
            flex-wrap: wrap;
        }
        .btn-sm {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.825rem;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.18s;
            font-family: 'Inter', sans-serif;
        }
        .btn-sm.update { background: #e3f4ea; color: #007a3e; }
        .btn-sm.update:hover { background: #007a3e; color: #fff; }
        .btn-sm.clear  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .btn-sm.clear:hover { background: #fee2e2; }
        .btn-sm.add-more { background: #f4fbf7; color: #1a3d28; border: 1px solid #c8e6d4; margin-left: auto; }
        .btn-sm.add-more:hover { background: #e0f0e8; }

        /* ── Empty cart ── */
        .empty-cart {
            background: #fff;
            border-radius: 24px;
            border: 1.5px dashed #c8e6d4;
            padding: 64px 32px;
            text-align: center;
            color: #5f8b74;
        }
        .empty-cart i { font-size: 3rem; opacity: 0.25; display: block; margin-bottom: 16px; }
        .empty-cart h3 { color: #1a3d28; margin-bottom: 8px; }
        .empty-cart p  { margin-bottom: 24px; }

        /* ── Summary sidebar ── */
        .summary-card {
            background: #fff;
            border-radius: 24px;
            border: 1.5px solid #e0f0e8;
            overflow: hidden;
            position: sticky;
            top: 24px;
        }
        .summary-header {
            padding: 18px 24px 14px;
            border-bottom: 1.5px solid #e8f5ee;
        }
        .summary-header h3 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f3d24;
            margin: 0;
        }
        .summary-rows { padding: 16px 24px; }
        .sum-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 9px 0;
            font-size: 0.9rem;
            border-bottom: 1px solid #eef7f2;
            color: #3d675a;
        }
        .sum-row:last-child { border-bottom: none; }
        .sum-row.total-row {
            font-size: 1.1rem;
            font-weight: 700;
            color: #007a3e;
            border-top: 2px solid #e0f0e8;
            border-bottom: none;
            padding-top: 14px;
            margin-top: 6px;
        }
        .sum-label { font-weight: 500; }
        .sum-val   { font-weight: 600; }

        /* ── Payment methods block ── */
        .payment-methods {
            margin: 0 24px 20px;
        }
        .payment-option {
            border: 2px solid #e0f0e8;
            border-radius: 18px;
            padding: 16px 20px;
            background: #fff;
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .payment-option:last-child {
            margin-bottom: 0;
        }
        .payment-option.active {
            border-color: #007a3e;
            background: linear-gradient(135deg, #f0faf5, #e3f4ea);
        }
        .payment-logo {
            width: 48px; height: 48px;
            background: #f4fbf7;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            color: #4a7560;
            font-size: 1.3rem;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        .payment-option.active .payment-logo.gcash-logo { background: #007a3e; color: #fff; }
        .payment-option.active .payment-logo.card-logo { background: #1f5090; color: #fff; }
        
        .payment-title {
            font-weight: 700;
            color: #0a3d22;
            font-size: 0.95rem;
        }
        .payment-sub {
            font-size: 0.78rem;
            color: #3d7455;
            margin-top: 2px;
        }
        .payment-check {
            margin-left: auto;
            color: #e0f0e8;
            font-size: 1.3rem;
            transition: color 0.2s;
        }
        .payment-option.active .payment-check {
            color: #007a3e;
        }
        
        .qr-instruction {
            display: none;
            margin: 10px 24px 20px;
            padding: 15px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            text-align: center;
            font-size: 0.85rem;
            color: #475569;
        }
        .qr-instruction.show { display: block; }
        
        /* ── Checkout button ── */
        .checkout-wrap { padding: 0 24px 24px; }
        .btn-checkout {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 40px;
            background: #007a3e;
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: background 0.2s, transform 0.15s, box-shadow 0.15s;
            text-decoration: none;
        }
        .btn-checkout:hover {
            background: #005c2e;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0,90,44,0.28);
        }
        .btn-checkout:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .login-notice {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 14px;
            padding: 12px 16px;
            font-size: 0.85rem;
            color: #92400e;
            margin: 0 24px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .login-notice a { color: #007a3e; font-weight: 600; }

        /* ── Confirmation Modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(3px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 28px;
            padding: 36px 32px 28px;
            max-width: 440px;
            width: 90%;
            box-shadow: 0 24px 64px rgba(0,80,30,0.18);
            animation: modalIn 0.22s ease;
        }
        @keyframes modalIn {
            from { transform: scale(0.92) translateY(16px); opacity: 0; }
            to   { transform: scale(1) translateY(0);       opacity: 1; }
        }
        .modal-icon {
            width: 64px; height: 64px;
            background: #e3f4ea;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem;
            color: #007a3e;
            margin: 0 auto 20px;
        }
        .modal-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #0f3d24;
            text-align: center;
            margin-bottom: 6px;
        }
        .modal-sub {
            text-align: center;
            color: #4a755e;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        .modal-summary {
            background: #f4fbf7;
            border-radius: 16px;
            padding: 14px 18px;
            margin-bottom: 24px;
            border: 1px solid #d0eddc;
        }
        .modal-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: #2d6347;
            padding: 5px 0;
            border-bottom: 1px solid #e0f0e8;
        }
        .modal-row:last-child { border-bottom: none; }
        .modal-row.total { font-weight: 700; font-size: 1rem; color: #007a3e; }
        .modal-btns {
            display: flex;
            gap: 12px;
        }
        .modal-cancel {
            flex: 1;
            padding: 13px;
            border: 1.5px solid #d0eddc;
            border-radius: 40px;
            background: #f4fbf7;
            color: #2d6347;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all 0.18s;
        }
        .modal-cancel:hover { background: #e0f0e8; }
        .modal-confirm {
            flex: 2;
            padding: 13px;
            border: none;
            border-radius: 40px;
            background: #007a3e;
            color: #fff;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: background 0.18s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .modal-confirm:hover { background: #005c2e; }
    </style>
</head>
<body>
<div class="main-content">
<section class="page-section">

    <!-- Nav -->
    <div class="wrapper">
        <nav class="customer-nav">
            <a href="<?php echo url('index.php?page=customer'); ?>" class="logo">UniCanteen <span>DLSU</span></a>
            <div class="customer-nav-links">
                <a href="<?php echo url('index.php?page=customer'); ?>#menu">Menu</a>
                <a href="<?php echo url('index.php?page=customer'); ?>#track">Track</a>
                <a href="<?php echo url('index.php?page=favorites'); ?>">Favorites</a>
                <a href="<?php echo url('index.php?page=orders'); ?>">Orders</a>
                <a href="<?php echo url('index.php?page=reviews'); ?>">Reviews</a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="<?php echo url('index.php?page=profile'); ?>"><?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'] ?? 'Profile')[0]); ?></a>
                    <a href="<?php echo url('index.php?page=logout'); ?>" class="btn-outline">Logout</a>
                <?php else: ?>
                    <a href="<?php echo url('index.php?page=login'); ?>">Sign In</a>
                    <a href="<?php echo url('index.php?page=register'); ?>" class="btn-outline">Register</a>
                <?php endif; ?>
                <a href="<?php echo url('index.php?page=cart'); ?>" class="btn-primary">
                    <i class="fas fa-bag-shopping"></i> Cart
                    <span class="cart-count"><?php echo $cart_items_count; ?></span>
                </a>
            </div>
        </nav>
    </div>

    <!-- Hero -->
    <div class="cart-hero">
        <div class="cart-hero-inner">
            <div class="wrapper">
                <?php
                $backUrl = $restaurant_id_for_back
                    ? url("index.php?page=restaurant&id={$restaurant_id_for_back}")
                    : url('index.php?page=customer') . '#menu';
                ?>
                <a href="<?php echo $backUrl; ?>" class="btn-back">
                    <i class="fas fa-arrow-left"></i>
                    <?php echo $restaurant_id_for_back ? 'Back to Restaurant' : 'Back to Stalls'; ?>
                </a>
                <h1><i class="fas fa-bag-shopping" style="font-size:1.8rem; margin-right:12px; opacity:0.85;"></i>Your Cart</h1>
                <p class="cart-hero-sub">
                    <?php echo $cart_items_count; ?> item<?php echo $cart_items_count != 1 ? 's' : ''; ?>
                    <?php echo $restaurant_name ? '· ' . htmlspecialchars($restaurant_name) : ''; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Body -->
    <div class="cart-body">
        <div class="wrapper">

            <!-- Toasts -->
            <?php if ($successMsg): ?>
            <div class="toast success">
                <i class="fas fa-circle-check"></i>
                <?php
                    $msgs = ['added'=>'Item added to cart!','updated'=>'Cart updated!','removed'=>'Item removed.','cleared'=>'Cart cleared.','placed'=>'Order placed successfully!','reordered'=>'Meal reordered! Review your cart.'];
                    echo $msgs[$successMsg] ?? 'Done!';
                ?>
            </div>
            <?php endif; ?>
            <?php if ($errorMsg): ?>
            <div class="toast error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($errorMsg); ?>
            </div>
            <?php endif; ?>
            <?php if ($warningMsg): ?>
            <div class="toast warning">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($warningMsg); ?>
            </div>
            <?php endif; ?>

            <?php if (empty($_SESSION['cart'])): ?>
            <!-- Empty state -->
            <div class="empty-cart">
                <i class="fas fa-bag-shopping"></i>
                <h3>Your cart is empty</h3>
                <p>Add some items from our stalls and they'll appear here.</p>
                <a href="<?php echo url('index.php?page=customer'); ?>#menu" class="btn-checkout" style="width:auto; display:inline-flex; padding:13px 28px;">
                    <i class="fas fa-utensils"></i> Browse Stalls
                </a>
            </div>

            <?php else: ?>
            <div class="cart-layout">

                <!-- LEFT: Items -->
                <div>
                    <!-- Stall banner -->
                    <div class="stall-banner">
                        <div class="stall-banner-icon"><i class="fas fa-store"></i></div>
                        <div>
                            <div class="stall-banner-name"><?php echo htmlspecialchars($restaurant_name); ?></div>
                            <div class="stall-banner-note">Items are from one stall only</div>
                        </div>
                    </div>

                    <!-- Items card -->
                    <div class="cart-card">
                        <div class="cart-card-header">
                            <span>Item</span>
                            <span style="text-align:center;">Unit Price</span>
                            <span style="text-align:center;">Qty</span>
                            <span style="text-align:right;">Subtotal</span>
                            <span></span>
                        </div>

                        <form method="POST" action="<?php echo url('index.php?page=cart'); ?>" id="cartForm">
                        <?php foreach ($_SESSION['cart'] as $item_id => $item): ?>
                        <div class="cart-row">
                            <div>
                                <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="item-rest"><?php echo htmlspecialchars($item['restaurant_name']); ?></div>
                            </div>
                            <div class="item-price">₱<?php echo number_format($item['price'], 0); ?></div>
                            <div class="qty-control">
                                <button type="button" class="qty-btn" onclick="changeQty(this, -1)">&#8722;</button>
                                <input type="number"
                                       name="quantity[<?php echo $item_id; ?>]"
                                       value="<?php echo $item['quantity']; ?>"
                                       min="0" max="20">
                                <button type="button" class="qty-btn" onclick="changeQty(this, 1)">&#43;</button>
                            </div>
                            <div class="item-subtotal">₱<?php echo number_format($item['price'] * $item['quantity'], 0); ?></div>
                            <a href="<?php echo url('index.php?page=cart&remove=' . $item_id); ?>"
                               class="btn-remove"
                               onclick="return confirm('Remove this item?')">
                                <i class="fas fa-xmark"></i>
                            </a>
                        </div>
                        <?php endforeach; ?>

                        <div class="cart-actions">
                            <button type="submit" name="update_cart" class="btn-sm update">
                                <i class="fas fa-rotate-right"></i> Update
                            </button>
                            <a href="<?php echo url('index.php?page=cart&clear=1'); ?>"
                               class="btn-sm clear"
                               onclick="return confirm('Clear your entire cart?')">
                                <i class="fas fa-trash"></i> Clear Cart
                            </a>
                            <a href="<?php echo $restaurant_id_for_back ? url('index.php?page=restaurant&id=' . $restaurant_id_for_back) : url('index.php?page=customer') . '#menu'; ?>"
                               class="btn-sm add-more">
                                <i class="fas fa-plus"></i> Add More
                            </a>
                        </div>
                        </form>
                    </div>
                </div>

                <!-- RIGHT: Summary -->
                <div>
                    <div class="summary-card">
                        <div class="summary-header">
                            <h3><i class="fas fa-receipt" style="color:#007a3e; margin-right:8px;"></i>Order Summary</h3>
                        </div>

                        <div class="summary-rows">
                            <div class="sum-row">
                                <span class="sum-label">Subtotal (<?php echo $cart_items_count; ?> items)</span>
                                <span class="sum-val">₱<?php echo number_format($subtotal, 2); ?></span>
                            </div>
                            <div class="sum-row">
                                <span class="sum-label">Service Fee</span>
                                <span class="sum-val">₱<?php echo number_format($service_fee, 2); ?></span>
                            </div>
                            <div class="sum-row total-row">
                                <span>Total</span>
                                <span>₱<?php echo number_format($grand_total, 2); ?></span>
                            </div>
                        </div>

                        <!-- Payment Methods -->
                        <div class="payment-methods">
                            <label class="payment-option active" onclick="selectPayment('gcash', this)">
                                <div class="payment-logo gcash-logo"><i class="fas fa-mobile-screen-button"></i></div>
                                <div>
                                    <div class="payment-title">GCash</div>
                                    <div class="payment-sub">Cashless · Secure · Instant</div>
                                </div>
                                <div class="payment-check"><i class="fas fa-circle-check"></i></div>
                            </label>
                            <label class="payment-option" onclick="selectPayment('card', this)">
                                <div class="payment-logo card-logo"><i class="fas fa-credit-card"></i></div>
                                <div>
                                    <div class="payment-title">Card</div>
                                    <div class="payment-sub">Debit or Credit Card</div>
                                </div>
                                <div class="payment-check"><i class="fas fa-circle-check"></i></div>
                            </label>
                        </div>
                        
                        <div id="gcashInstruction" class="qr-instruction show">
                            <i class="fas fa-qrcode" style="font-size: 2rem; color: #64748b; margin-bottom: 8px; display: block;"></i>
                            <strong>Scan QR or send payment</strong> at the stall.<br>Please show proof of transfer when picking up your food.
                        </div>
                        <div id="cardInstruction" class="qr-instruction">
                            <i class="fas fa-credit-card" style="font-size: 2rem; color: #64748b; margin-bottom: 8px; display: block;"></i>
                            <strong>Present your card</strong> at the stall.<br>Payment will be processed upon pickup.
                        </div>

                        <?php if (!isLoggedIn()): ?>
                        <div class="login-notice">
                            <i class="fas fa-info-circle"></i>
                            Please <a href="<?php echo url('index.php?page=login'); ?>">sign in</a> to checkout.
                        </div>
                        <?php endif; ?>

                        <div class="checkout-wrap">
                            <form method="POST" action="<?php echo url('index.php?page=cart'); ?>" id="checkoutForm">
                                <input type="hidden" name="payment_method" id="paymentMethodInput" value="gcash">
                                <button type="button" id="placeOrderBtn" class="btn-checkout"
                                    <?php echo !isLoggedIn() ? 'disabled' : ''; ?>
                                    onclick="openConfirmModal()">
                                    <i class="fas fa-check-circle"></i>
                                    Place Order · ₱<?php echo number_format($grand_total, 2); ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div><!-- /cart-layout -->
            <?php endif; ?>

        </div><!-- /wrapper -->
    </div><!-- /cart-body -->

    <footer class="footer-note">
        <i class="fas fa-lock"></i> GCash Secure Checkout · UniCanteen DLSU · Real-time Order Tracking
    </footer>
</section>
</div>

<!-- Order Confirmation Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
        <div class="modal-icon"><i class="fas fa-bag-shopping"></i></div>
        <div class="modal-title">Confirm Your Order</div>
        <div class="modal-sub">Please review your order before placing it.</div>
        <div class="modal-summary">
            <?php foreach ($_SESSION['cart'] as $item): ?>
            <div class="modal-row">
                <span><?php echo $item['quantity']; ?>x <?php echo htmlspecialchars($item['name']); ?></span>
                <span>&#8369;<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
            </div>
            <?php endforeach; ?>
            <div class="modal-row">
                <span>Service Fee</span>
                <span>&#8369;<?php echo number_format($service_fee, 2); ?></span>
            </div>
            <div class="modal-row total">
                <span>Total</span>
                <span>&#8369;<?php echo number_format($grand_total, 2); ?></span>
            </div>
            <div class="modal-row" style="margin-top: 10px; border-top: 2px dashed #e0f0e8; padding-top: 10px;">
                <span style="font-size: 0.85rem; color: #6b8f7a;">Payment Method</span>
                <span id="modalPaymentDisplay" style="font-weight: 600; color: #1a3d28;"></span>
            </div>
        </div>
        <div class="modal-btns">
            <button class="modal-cancel" onclick="closeConfirmModal()"><i class="fas fa-times"></i> Cancel</button>
            <button class="modal-confirm" onclick="submitOrder()"><i class="fas fa-check-circle"></i> Confirm Order</button>
        </div>
    </div>
</div>

<script>
// +/- quantity buttons
function changeQty(btn, delta) {
    const input = btn.closest('.qty-control').querySelector('input[type="number"]');
    const newVal = Math.max(0, Math.min(20, parseInt(input.value || 0) + delta));
    input.value = newVal;
    input.dispatchEvent(new Event('input'));
}

// Live subtotal update
document.querySelectorAll('.qty-control input').forEach(input => {
    input.addEventListener('input', function() {
        const row = this.closest('.cart-row');
        if (!row) return;
        const priceText = row.querySelector('.item-price').textContent.replace(/[\u20b1,]/g,'');
        const price = parseFloat(priceText);
        const qty   = parseInt(this.value) || 0;
        const sub   = row.querySelector('.item-subtotal');
        if (sub) sub.textContent = '\u20b1' + (price * qty).toLocaleString('en-PH', {minimumFractionDigits:0});
    });
});

// Confirmation modal
function openConfirmModal() {
    document.getElementById('confirmModal').classList.add('open');
}
function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('open');
}
function submitOrder() {
    const btn = document.getElementById('placeOrderBtn');
    const confirmBtn = document.querySelector('.modal-confirm');
    // Guard against double-submit on both the place-order button and the confirm button
    if (btn.disabled || btn.dataset.submitting === '1') return;
    if (confirmBtn && confirmBtn.dataset.submitting === '1') return;
    btn.dataset.submitting = '1';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Placing Order…';
    if (confirmBtn) {
        confirmBtn.dataset.submitting = '1';
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
    }

    const form = document.getElementById('checkoutForm');
    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = 'checkout';
    inp.value = '1';
    form.appendChild(inp);
    form.submit();
}
// Close modal on overlay click
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirmModal();
});

// Payment method selection
function selectPayment(method, el) {
    document.querySelectorAll('.payment-option').forEach(o => o.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('paymentMethodInput').value = method;
    // Toggle instruction blocks
    document.getElementById('gcashInstruction').classList.toggle('show', method === 'gcash');
    document.getElementById('cardInstruction').classList.toggle('show', method === 'card');
    // Update modal display
    const labels = { gcash: 'GCash', card: 'Card' };
    document.getElementById('modalPaymentDisplay').textContent = labels[method] || method;
}
// Init modal label on load
document.addEventListener('DOMContentLoaded', function() {
    const method = document.getElementById('paymentMethodInput').value;
    const labels = { gcash: 'GCash', card: 'Card' };
    document.getElementById('modalPaymentDisplay').textContent = labels[method] || method;
});
</script>
</body>
</html>