<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

if (!isset($_GET['id'])) {
    header("Location: index.php?page=customer");
    exit();
}

$restaurant_id = intval($_GET['id']);
$db = Database::getInstance()->getConnection();

// Handle add-to-cart redirect
if (isset($_GET['add_to_cart'])) {
    $item_id = intval($_GET['add_to_cart']);
    header("Location: index.php?page=cart&add=$item_id&restaurant_id=$restaurant_id");
    exit();
}

// Fetch restaurant
$restaurantQuery = "SELECT
    r.*,
    COALESCE(AVG(rt.rating), 0) as avg_rating,
    COUNT(DISTINCT rt.ID) as rating_count,
    (SELECT COUNT(*) FROM Items WHERE restaurant_ID = r.ID AND isAvailable = TRUE) as available_items_count,
    (SELECT COUNT(*) FROM Items WHERE restaurant_ID = r.ID) as total_items_count
    FROM Restaurants r
    LEFT JOIN Ratings rt ON r.ID = rt.restaurant_ID
    WHERE r.ID = ?
    GROUP BY r.ID";
$stmt = $db->prepare($restaurantQuery);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$restaurantResult = $stmt->get_result();
$restaurant = $restaurantResult->fetch_assoc();

if (!$restaurant) {
    header("Location: index.php?page=customer");
    exit();
}

// Fetch items
$itemsQuery = "SELECT * FROM Items WHERE restaurant_ID = ? ORDER BY isAvailable DESC, name";
$stmt = $db->prepare($itemsQuery);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$itemsResult = $stmt->get_result();

// Fetch recent reviews
$reviewsQuery = "SELECT
    rt.*,
    u.full_name as reviewer_name
    FROM Ratings rt
    LEFT JOIN Orders o ON rt.order_ID = o.ID
    LEFT JOIN Users u ON o.customer_ID = u.ID
    WHERE rt.restaurant_ID = ?
    ORDER BY rt.timestamp DESC
    LIMIT 6";
$stmt = $db->prepare($reviewsQuery);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$reviewsResult = $stmt->get_result();

// Cart count
$cart_items_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_items_count += $item['quantity'];
    }
}

// User favorites
$userFavorites = [];
if (isLoggedIn()) {
    $favQuery = "SELECT item_id FROM Favorites WHERE user_id = ?";
    $stmtFav = $db->prepare($favQuery);
    $stmtFav->bind_param("i", $_SESSION['user_id']);
    $stmtFav->execute();
    $favResult = $stmtFav->get_result();
    while ($row = $favResult->fetch_assoc()) {
        $userFavorites[] = $row['item_id'];
    }
}

// Flash messages from add-to-cart redirect
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_items_count += $item['quantity'];
    }
}
// Flash messages from add-to-cart redirect
$flash_added = isset($_GET['added']) ? urldecode($_GET['added']) : '';
$flash_error = isset($_GET['error']) ? urldecode($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($restaurant['name']); ?> · UniCanteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
        /* ── Reset for full-width layout ── */
        body {
            display: block;
            min-height: auto;
            margin: 0;
            padding: 0;
            background: #f0f7f2;
        }

        .main-content {
            margin-left: 0;
        }

        .wrapper {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 36px;
        }

        /* ── NAV ── */
        .cart-count {
            background: white;
            color: #007a3e;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        /* ── Toast notification ── */
        .toast-fixed {
            position: fixed;
            bottom: 28px;
            right: 28px;
            z-index: 9999;
            min-width: 260px;
            max-width: 380px;
            padding: 14px 20px;
            border-radius: 16px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
            animation: toastSlide 0.3s ease;
        }

        .toast-fixed.success {
            background: #dcfce7;
            color: #166534;
            border: 1.5px solid #bbf7d0;
        }

        .toast-fixed.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1.5px solid #fecaca;
        }

        @keyframes toastSlide {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* ── Hero ── */
        .r-hero {
            background: linear-gradient(135deg, #005c2e 0%, #007a3e 50%, #1a8c4a 100%);
            padding: 0;
            position: relative;
            overflow: hidden;
        }

        .r-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse at 80% 50%, rgba(255, 255, 255, 0.06) 0%, transparent 60%),
                radial-gradient(ellipse at 20% 80%, rgba(0, 0, 0, 0.12) 0%, transparent 50%);
            pointer-events: none;
        }

        .r-hero-inner {
            position: relative;
            z-index: 1;
            padding: 36px 0 40px;
        }

        /* Back button — solid white pill */
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.18);
            border: 1.5px solid rgba(255, 255, 255, 0.40);
            color: #fff;
            padding: 9px 20px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            margin-bottom: 24px;
            transition: background 0.2s, border-color 0.2s;
            backdrop-filter: blur(4px);
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.30);
            border-color: rgba(255, 255, 255, 0.65);
        }

        .r-hero h1 {
            font-size: 2.6rem;
            font-weight: 700;
            color: #fff;
            margin: 0 0 10px;
            line-height: 1.2;
        }

        .r-hero-desc {
            color: rgba(255, 255, 255, 0.85);
            font-size: 1rem;
            margin: 0 0 22px;
            max-width: 580px;
            line-height: 1.6;
        }

        /* Hero meta pills */
        .hero-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .h-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 16px;
            border-radius: 40px;
            font-size: 0.85rem;
            font-weight: 500;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #fff;
        }

        .h-pill.pill-open {
            background: rgba(74, 222, 128, 0.22);
            border-color: rgba(74, 222, 128, 0.45);
        }

        .h-pill.pill-closed {
            background: rgba(239, 68, 68, 0.22);
            border-color: rgba(239, 68, 68, 0.45);
        }

        .h-pill .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }

        .pill-open .dot {
            background: #4ade80;
        }

        .pill-closed .dot {
            background: #f87171;
        }

        /* Hero right — View Cart button */
        .r-hero-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 24px;
        }

        .btn-hero-cart {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            color: #007a3e;
            padding: 13px 26px;
            border-radius: 40px;
            font-weight: 700;
            font-size: 0.95rem;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.18);
            flex-shrink: 0;
        }

        .btn-hero-cart:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.22);
        }

        .btn-hero-cart .badge {
            background: #007a3e;
            color: #fff;
            border-radius: 40px;
            padding: 2px 9px;
            font-size: 0.78rem;
        }

        /* ── Content area ── */
        .r-body {
            padding: 40px 0 60px;
        }

        /* ── Section header ── */
        .sec-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .sec-header-icon {
            width: 40px;
            height: 40px;
            background: #e3f4ea;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #007a3e;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .sec-header h2 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f3d24;
            margin: 0;
        }

        .sec-header .sec-subtitle {
            font-size: 0.85rem;
            color: #5f8b74;
            margin: 0;
            font-weight: 400;
        }

        .sec-header-end {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .availability-pill {
            background: #e3f4ea;
            border: 1px solid #b8e0cc;
            color: #007a3e;
            padding: 5px 14px;
            border-radius: 40px;
            font-size: 0.825rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ── Menu grid ── */
        .menu-grid-2col {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 14px;
            margin-bottom: 48px;
        }

        .menu-card {
            background: #fff;
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1.5px solid #e8f4ee;
            transition: box-shadow 0.18s, border-color 0.18s, transform 0.18s;
            position: relative;
        }

        .menu-card:hover {
            box-shadow: 0 6px 24px rgba(0, 80, 20, 0.10);
            border-color: #b8d9c8;
            transform: translateY(-2px);
        }

        .menu-card.sold-out-card {
            opacity: 0.72;
        }

        .menu-card-img {
            width: 100%;
            height: 220px;
            object-fit: cover;
            display: block;
            border-bottom: 1.5px solid #e8f4ee;
        }

        .menu-card-icon {
            width: 100%;
            height: 220px;
            background: #f0faf4;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #007a3e;
            font-size: 3.5rem;
            border-bottom: 1.5px solid #e8f4ee;
        }

        .menu-card.sold-out-card .menu-card-icon {
            background: #fef2f2;
            color: #b91c1c;
        }

        .menu-card-info-row {
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .menu-card-body {
            flex: 1;
            min-width: 0;
        }

        .menu-card-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1a3d28;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .menu-card-desc {
            font-size: 0.8rem;
            color: #6b8f7a;
            margin-top: 3px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .menu-card-price {
            font-size: 1.05rem;
            font-weight: 700;
            color: #007a3e;
            white-space: nowrap;
        }

        .menu-card-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            flex-shrink: 0;
        }

        .btn-add {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #007a3e;
            color: #fff;
            padding: 8px 16px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.82rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
        }

        .btn-add:hover {
            background: #005a2c;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(0, 90, 44, 0.30);
        }

        .btn-sold {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #fef2f2;
            color: #b91c1c;
            padding: 8px 16px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.82rem;
            white-space: nowrap;
            border: 1.5px solid #fecaca;
        }

        .avail-tag-small {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
        }

        .avail-tag-small.yes {
            background: #dcfce7;
            color: #15803d;
        }

        .avail-tag-small.no {
            background: #fef2f2;
            color: #b91c1c;
        }

        /* Empty state */
        .empty-state {
            background: #fff;
            border-radius: 24px;
            padding: 56px 32px;
            text-align: center;
            border: 1.5px dashed #c8e6d4;
            color: #5f8b74;
            grid-column: 1/-1;
        }

        .empty-state i {
            font-size: 2.8rem;
            opacity: 0.3;
            margin-bottom: 14px;
            display: block;
        }

        /* ── Reviews ── */
        .reviews-grid-local {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 18px;
            margin-bottom: 48px;
        }

        .rev-card {
            background: #fff;
            border-radius: 20px;
            padding: 22px 24px;
            border: 1.5px solid #e8f4ee;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: box-shadow 0.18s;
        }

        .rev-card:hover {
            box-shadow: 0 6px 20px rgba(0, 80, 20, 0.08);
        }

        .rev-top {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .rev-avatar {
            width: 42px;
            height: 42px;
            background: #e3f4ea;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #007a3e;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .rev-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: #1a3d28;
        }

        .rev-time {
            font-size: 0.73rem;
            color: #8faa9a;
            margin-top: 2px;
        }

        .rev-stars {
            display: flex;
            align-items: center;
            gap: 3px;
            color: #f59e0b;
            font-size: 0.85rem;
        }

        .rev-stars span {
            color: #4a7560;
            font-size: 0.8rem;
            margin-left: 5px;
            font-weight: 600;
        }

        .rev-text {
            font-size: 0.875rem;
            color: #4a6655;
            line-height: 1.6;
            border-left: 3px solid #b8e0cc;
            padding-left: 12px;
            font-style: italic;
        }
    </style>
</head>

<body>
    <script>
        // Pass PHP favorites array to JS early
        window.userFavorites = <?php echo json_encode($userFavorites); ?>;
        window.isLoggedIn = <?php echo isLoggedIn() ? 'true' : 'false'; ?>;
    </script>
    <div class="main-content">
        <section class="page-section">

            <!-- Nav -->
            <div class="wrapper">
                <nav class="customer-nav">
                    <a href="index.php?page=customer" class="logo">UniCanteen <span>DLSU</span></a>
                    <div class="customer-nav-links">
                        <a href="index.php?page=customer#menu">Menu</a>
                        <a href="index.php?page=customer#track">Track</a>
                        <a href="index.php?page=reviews">Reviews</a>
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <a
                                href="index.php?page=profile"><?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'] ?? 'Profile')[0]); ?></a>
                            <a href="index.php?page=logout" class="btn-outline">Logout</a>
                        <?php else: ?>
                            <a href="index.php?page=login">Sign In</a>
                            <a href="index.php?page=register" class="btn-outline">Register</a>
                        <?php endif; ?>
                        <a href="index.php?page=cart" class="btn-primary">
                            <i class="fas fa-bag-shopping"></i> Cart
                            <span class="cart-count"><?php echo $cart_items_count; ?></span>
                        </a>
                    </div>
                </nav>
            </div>

            <!-- Flash toasts -->
            <?php if ($flash_added): ?>
                <div class="toast-fixed success" id="addToast">
                    <i class="fas fa-circle-check"></i>
                    <span><strong><?php echo htmlspecialchars($flash_added); ?></strong> added to cart!</span>
                    <a href="index.php?page=cart"
                        style="margin-left:auto; color:inherit; font-weight:700; white-space:nowrap; text-decoration:underline;">View
                        Cart</a>
                </div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="toast-fixed error" id="errToast">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($flash_error); ?>
                </div>
            <?php endif; ?>

            <!-- Hero -->
            <div class="r-hero">
                <div class="r-hero-inner">
                    <div class="wrapper">
                        <!-- Back button -->
                        <div>
                            <a href="index.php?page=customer#menu" class="btn-back">
                                <i class="fas fa-arrow-left"></i> Back to Stalls
                            </a>
                        </div>

                        <div class="r-hero-row">
                            <div>
                                <h1><?php echo htmlspecialchars($restaurant['name']); ?></h1>
                                <?php if ($restaurant['description']): ?>
                                    <p class="r-hero-desc"><?php echo htmlspecialchars($restaurant['description']); ?></p>
                                <?php endif; ?>
                                <div class="hero-pills">
                                    <span
                                        class="h-pill <?php echo $restaurant['is_open'] ? 'pill-open' : 'pill-closed'; ?>">
                                        <span class="dot"></span>
                                        <?php echo $restaurant['is_open'] ? 'Open Now' : 'Closed'; ?>
                                    </span>
                                    <span class="h-pill">
                                        <i class="fas fa-star" style="color:#fbbf24;"></i>
                                        <?php echo number_format($restaurant['avg_rating'], 1); ?>
                                        <span style="opacity:0.65;">(<?php echo $restaurant['rating_count']; ?>
                                            reviews)</span>
                                    </span>
                                    <span class="h-pill">
                                        <i class="fas fa-utensils"></i>
                                        <?php echo $restaurant['available_items_count']; ?>/<?php echo $restaurant['total_items_count']; ?>
                                        items available
                                    </span>
                                    <?php if ($restaurant['address']): ?>
                                        <span class="h-pill">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($restaurant['address']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- View Cart button -->
                            <?php if ($cart_items_count > 0): ?>
                                <a href="index.php?page=cart" class="btn-hero-cart">
                                    <i class="fas fa-bag-shopping"></i>
                                    View Cart
                                    <span class="badge"><?php echo $cart_items_count; ?></span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Body content -->
            <div class="r-body">
                <div class="wrapper">

                    <!-- ── FULL MENU ── -->
                    <div class="sec-header">
                        <div class="sec-header-icon"><i class="fas fa-utensils"></i></div>
                        <div>
                            <h2>Full Menu</h2>
                            <p class="sec-subtitle"><?php echo $restaurant['name']; ?></p>
                        </div>
                        <div class="sec-header-end">
                            <span class="availability-pill">
                                <i class="fas fa-circle-check" style="font-size:0.75rem;"></i>
                                <?php echo $restaurant['available_items_count']; ?> of
                                <?php echo $restaurant['total_items_count']; ?> available
                            </span>
                        </div>
                    </div>

                    <div class="menu-grid-2col">
                        <?php
                        $itemsResult->data_seek(0);
                        $count = 0;
                        while ($item = $itemsResult->fetch_assoc()):
                            $isAvailable = ($item['isAvailable'] == 1 || $item['isAvailable'] === '1' || $item['isAvailable'] === true);
                            $isFav = in_array($item['ID'], $userFavorites);
                            $count++;
                            ?>
                            <div class="menu-card <?php echo !$isAvailable ? 'sold-out-card' : ''; ?>" style="position:relative;">
                                <!-- Favorite Heart Toggle -->
                                <button class="fav-btn" data-item-id="<?php echo $item['ID']; ?>" 
                                        style="position:absolute; top:12px; right:12px; z-index:10; background:rgba(255,255,255,0.9); border:none; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,0.1); color: <?php echo $isFav ? '#dc2626' : '#9ca3af'; ?>; transition: transform 0.2s, color 0.2s; font-size:1.15rem;">
                                    <i class="<?php echo $isFav ? 'fas' : 'far'; ?> fa-heart" style="transform: translate(1px, 1px);"></i>
                                </button>
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?php echo htmlspecialchars(url($item['image_url'])); ?>"
                                        alt="<?php echo htmlspecialchars($item['name']); ?>" class="menu-card-img">
                                <?php else: ?>
                                    <div class="menu-card-icon">
                                        <i class="fas fa-<?php echo $isAvailable ? 'bowl-food' : 'ban'; ?>"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="menu-card-info-row">
                                    <div class="menu-card-body">
                                        <div class="menu-card-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                        <?php if ($item['description']): ?>
                                            <div class="menu-card-desc"><?php echo htmlspecialchars($item['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="menu-card-price" style="margin-top:6px;">
                                            ₱<?php echo number_format($item['price'], 0); ?></div>
                                    </div>
                                    <div class="menu-card-actions">
                                        <span class="avail-tag-small <?php echo $isAvailable ? 'yes' : 'no'; ?>">
                                            <?php echo $isAvailable ? 'Available' : 'Sold Out'; ?>
                                        </span>
                                        <?php if ($isAvailable): ?>
                                            <a href="index.php?page=restaurant&id=<?php echo $restaurant_id; ?>&add_to_cart=<?php echo $item['ID']; ?>"
                                                class="btn-add" id="cart-btn-<?php echo $item['ID']; ?>">
                                                <i class="fas fa-plus"></i> Add
                                            </a>
                                        <?php else: ?>
                                            <span class="btn-sold">
                                                <i class="fas fa-times"></i> Sold Out
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>

                        <?php if ($count === 0): ?>
                            <div class="empty-state">
                                <i class="fas fa-utensils"></i>
                                No items listed for this stall yet.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── REVIEWS ── -->
                    <div class="sec-header" id="reviews">
                        <div class="sec-header-icon"><i class="fas fa-star"></i></div>
                        <div>
                            <h2>Customer Reviews</h2>
                            <p class="sec-subtitle">What others are saying</p>
                        </div>
                        <div class="sec-header-end">
                            <a href="index.php?page=reviews"
                                style="color:#007a3e; font-weight:600; font-size:0.875rem; text-decoration:none; display:flex; align-items:center; gap:6px;">
                                See all <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>

                    <div class="reviews-grid-local">
                        <?php
                        $reviewsResult->data_seek(0);
                        if ($reviewsResult->num_rows > 0):
                            while ($review = $reviewsResult->fetch_assoc()):
                                ?>
                                <div class="rev-card">
                                    <div class="rev-top">
                                        <div class="rev-avatar">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div>
                                            <div class="rev-name">
                                                <?php echo htmlspecialchars($review['reviewer_name'] ?? 'Anonymous'); ?>
                                            </div>
                                            <div class="rev-time"><?php echo getTimeAgo($review['timestamp']); ?></div>
                                        </div>
                                    </div>
                                    <div class="rev-stars">
                                        <?php
                                        $rating = $review['rating'];
                                        for ($i = 1; $i <= 5; $i++):
                                            if ($i <= floor($rating)): ?>
                                                <i class="fas fa-star"></i>
                                            <?php elseif ($i - 0.5 <= $rating): ?>
                                                <i class="fas fa-star-half-alt"></i>
                                            <?php else: ?>
                                                <i class="far fa-star" style="color:#d1d5db;"></i>
                                            <?php endif;
                                        endfor; ?>
                                        <span><?php echo number_format($rating, 1); ?></span>
                                    </div>
                                    <?php if ($review['review']): ?>
                                        <div class="rev-text"><?php echo htmlspecialchars($review['review']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php
                            endwhile;
                        else:
                            ?>
                            <div class="empty-state" style="grid-column:1/-1;">
                                <i class="fas fa-star"></i>
                                No reviews yet — be the first to review this stall!
                            </div>
                        <?php endif; ?>
                    </div>

                </div><!-- /wrapper -->
            </div><!-- /r-body -->

            <footer class="footer-note">
                <i class="fas fa-store"></i>
                <?php echo htmlspecialchars($restaurant['name']); ?> · UniCanteen DLSU · Real-time availability
            </footer>

        </section>
    </div>

    <script>
        // Reset add-buttons when user presses Back (pageshow covers bfcache restore)
        function resetAddButtons() {
            document.querySelectorAll('.btn-add').forEach(btn => {
                btn.innerHTML = '<i class="fas fa-plus"></i> Add';
                btn.style.background = '';
                btn.style.color = '';
            });
        }
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) resetAddButtons();
        });
        // Brief visual feedback on click (will be reset if user comes back)
        document.querySelectorAll('.btn-add').forEach(btn => {
            btn.addEventListener('click', function () {
                this.innerHTML = '<i class="fas fa-check"></i> Added!';
                this.style.background = '#15803d';
                this.style.color = '#fff';
            });
        });
        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        // Auto-dismiss toasts after 3 s
        ['addToast', 'errToast'].forEach(id => {
            const el = document.getElementById(id);
            if (el) setTimeout(() => { el.style.transition = 'opacity 0.5s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 3000);
        });

        // ── Favorite Toggle ─────────────────────────────────────────────────
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.fav-btn');
            if (!btn) return;
            
            e.preventDefault();
            e.stopPropagation();

            if (!window.isLoggedIn) {
                alert('Please sign in to save your favorite items.');
                window.location.href = 'index.php?page=login';
                return;
            }

            const itemId = btn.dataset.itemId;
            const icon = btn.querySelector('i');
            
            // Optimistic UI update
            const isCurrentlyFav = icon.classList.contains('fas');
            if (isCurrentlyFav) {
                icon.classList.remove('fas');
                icon.classList.add('far');
                btn.style.color = '#9ca3af';
            } else {
                icon.classList.remove('far');
                icon.classList.add('fas');
                btn.style.color = '#dc2626';
            }

            // AJAX request
            const formData = new FormData();
            formData.append('item_id', itemId);

            fetch('frontend/toggle_favorite.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    // Revert UI on failure
                    console.error('Failed to toggle favorite:', data.message);
                    if (isCurrentlyFav) {
                        icon.classList.add('fas');
                        icon.classList.remove('far');
                        btn.style.color = '#dc2626';
                    } else {
                        icon.classList.add('far');
                        icon.classList.remove('fas');
                        btn.style.color = '#9ca3af';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Revert UI on error
                if (isCurrentlyFav) {
                    icon.classList.add('fas');
                    icon.classList.remove('far');
                    btn.style.color = '#dc2626';
                } else {
                    icon.classList.add('far');
                    icon.classList.remove('fas');
                    btn.style.color = '#9ca3af';
                }
            });
        });
    </script>
</body>

</html>