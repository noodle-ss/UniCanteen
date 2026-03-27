<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';
$db = Database::getInstance()->getConnection();

// Initialize cart items count
$cart_items_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_items_count += $item['quantity'];
    }
}

// Get statistics
$stats = [];
$statsQuery = "SELECT 
    (SELECT COUNT(*) FROM Restaurants WHERE is_open = TRUE) as open_stalls,
    (SELECT COUNT(*) FROM Items WHERE isAvailable = TRUE) as available_items,
    (SELECT COUNT(*) FROM Orders WHERE DATE(order_date) = CURDATE()) as today_orders";
$statsResult = $db->query($statsQuery);
$stats = $statsResult->fetch_assoc();

// Get all restaurants with their items and ratings
$restaurantsQuery = "SELECT 
    r.*,
    COALESCE(AVG(rt.rating), 0) as avg_rating,
    COUNT(DISTINCT rt.ID) as rating_count,
    (SELECT COUNT(*) FROM Items WHERE restaurant_ID = r.ID AND isAvailable = TRUE) as available_items_count,
    (SELECT COUNT(*) FROM Items WHERE restaurant_ID = r.ID) as total_items_count
    FROM Restaurants r
    LEFT JOIN Ratings rt ON r.ID = rt.restaurant_ID
    GROUP BY r.ID
    ORDER BY r.is_open DESC, r.name";
$restaurantsResult = $db->query($restaurantsQuery);

// Get recent reviews
$reviewsQuery = "SELECT 
    rt.*,
    r.name as restaurant_name,
    u.full_name as reviewer_name
    FROM Ratings rt
    JOIN Restaurants r ON rt.restaurant_ID = r.ID
    LEFT JOIN Orders o ON rt.order_ID = o.ID
    LEFT JOIN Users u ON o.customer_ID = u.ID
    ORDER BY rt.timestamp DESC
    LIMIT 4";
$reviewsResult = $db->query($reviewsQuery);

// Get overall rating stats
$ratingStatsQuery = "SELECT 
    COALESCE(AVG(rating), 0) as avg_rating,
    COUNT(*) as total_reviews,
    SUM(CASE WHEN rating >= 4.5 THEN 1 ELSE 0 END) as five_star,
    SUM(CASE WHEN rating >= 3.5 AND rating < 4.5 THEN 1 ELSE 0 END) as four_star,
    SUM(CASE WHEN rating >= 2.5 AND rating < 3.5 THEN 1 ELSE 0 END) as three_star,
    SUM(CASE WHEN rating >= 1.5 AND rating < 2.5 THEN 1 ELSE 0 END) as two_star,
    SUM(CASE WHEN rating < 1.5 THEN 1 ELSE 0 END) as one_star
    FROM Ratings";
$ratingStatsResult = $db->query($ratingStatsQuery);
$ratingStats = $ratingStatsResult->fetch_assoc();

// Get current orders if user is logged in
$activeOrders = [];
$userFavorites = [];
if (isLoggedIn()) {
    // Get favorites
    $favQuery = "SELECT item_id FROM Favorites WHERE user_id = ?";
    $stmtFav = $db->prepare($favQuery);
    $stmtFav->bind_param("i", $_SESSION['user_id']);
    $stmtFav->execute();
    $favResult = $stmtFav->get_result();
    while ($row = $favResult->fetch_assoc()) {
        $userFavorites[] = $row['item_id'];
    }

    $orderQuery = "SELECT 
        o.*,
        r.name as restaurant_name
        FROM Orders o
        JOIN Restaurants r ON o.restaurant_ID = r.ID
        WHERE o.customer_ID = ? AND o.status != 'C'
        ORDER BY o.order_date DESC";
    $stmt = $db->prepare($orderQuery);
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $orderResult = $stmt->get_result();
    while ($ord = $orderResult->fetch_assoc()) {
        $itemsQuery = "SELECT 
            oi.*,
            i.name as item_name,
            r.name as restaurant_name
            FROM Order_ItemLine oi
            JOIN Items i ON oi.item_ID = i.ID
            JOIN Restaurants r ON i.restaurant_ID = r.ID
            WHERE oi.order_ID = ?";
        $stmt2 = $db->prepare($itemsQuery);
        $stmt2->bind_param("i", $ord['ID']);
        $stmt2->execute();
        $ord['items'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $activeOrders[] = $ord;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniCanteen · Customer</title>
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

        .user-menu {
            position: relative;
            display: inline-block;
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            min-width: 200px;
            z-index: 1000;
            border: 1px solid #e0f0e8;
            margin-top: 10px;
        }

        .dropdown-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px 20px;
            text-decoration: none;
            color: #1f4d35;
            border-bottom: 1px solid #e0f0e8;
        }

        .dropdown-menu a:last-child {
            border-bottom: none;
        }

        .dropdown-menu a:hover {
            background: #f0f7f0;
        }

        .cart-count {
            background: white;
            color: #007a3e;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.7rem;
            margin-left: 5px;
        }

        .user-dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        /* ── Search & Filter bar ── */
        .search-filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin: 18px 0 22px;
        }

        .search-input-wrap {
            position: relative;
            flex: 1;
            min-width: 220px;
            max-width: 420px;
        }

        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b9a7f;
            font-size: 0.9rem;
            pointer-events: none;
        }

        .stall-search-input {
            width: 100%;
            padding: 12px 42px 12px 40px;
            border: 2px solid #cae3d6;
            border-radius: 40px;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            color: #1a3d28;
            background: #fff;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .stall-search-input:focus {
            border-color: #007a3e;
            box-shadow: 0 0 0 3px rgba(0, 122, 62, 0.12);
        }

        .stall-search-input::placeholder {
            color: #94b8a3;
        }

        .search-clear-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 26px;
            height: 26px;
            border-radius: 50%;
            border: none;
            background: #e0f0e8;
            color: #007a3e;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            transition: background 0.15s;
        }

        .search-clear-btn:hover {
            background: #cae3d6;
        }

        .filter-chips {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-chip {
            padding: 9px 18px;
            border-radius: 40px;
            border: 1.5px solid #cae3d6;
            background: #f4fbf7;
            color: #2d6347;
            font-size: 0.85rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.18s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .filter-chip:hover {
            background: #e0f0e8;
            border-color: #007a3e;
        }

        .filter-chip.active {
            background: #007a3e;
            color: #fff;
            border-color: #007a3e;
        }

        .filter-chip.active i {
            color: #fff !important;
        }

        .search-result-count {
            font-size: 0.85rem;
            color: #5f8b74;
            font-weight: 500;
            white-space: nowrap;
            display: none;
        }

        /* ── Closed stall card styling ── */
        .stall-card-closed {
            position: relative;
            border: 2px solid #e8b4b4 !important;
            background: linear-gradient(145deg, #fff9f9 0%, #fef2f2 100%) !important;
            overflow: hidden;
        }
        .stall-card-closed::before {
            content: 'CLOSED';
            position: absolute;
            top: 14px;
            right: -30px;
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: white;
            font-size: 0.6rem;
            font-weight: 800;
            letter-spacing: 1.5px;
            padding: 4px 40px;
            transform: rotate(35deg);
            z-index: 5;
            box-shadow: 0 2px 8px rgba(185, 28, 28, 0.3);
        }
        .stall-card-closed .stall-header {
            opacity: 0.85;
        }
        .stall-card-closed .menu-row {
            opacity: 0.45;
            filter: grayscale(40%);
        }
        .stall-card-closed .price-tag {
            background: #f3f4f6 !important;
            color: #9ca3af !important;
        }
        .stall-card-closed .avail-tag {
            background: #f3f4f6 !important;
            color: #9ca3af !important;
        }
        .stall-card-closed .avail-tag i {
            color: #9ca3af !important;
        }
        .stall-card-closed .fav-btn {
            opacity: 0.3;
            pointer-events: none;
        }
        .stall-card-closed .availability-summary {
            opacity: 0.5;
        }
        .closed-overlay-bar {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: white;
            font-weight: 700;
            font-size: 0.85rem;
            border-radius: 40px; 
            margin-top: 5px;
            letter-spacing: 0.3px;
        }
        .closed-overlay-bar i {
            font-size: 1rem;
            animation: pulse-icon 2s ease-in-out infinite;
        }
        @keyframes pulse-icon {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .btn-secondary-closed {
            width: 100%;
            margin-top: 15px;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.9rem;
            background: #fff;
            color: #6b7280;
            border: 1.5px solid #d1d5db;
            transition: all 0.18s;
        }
        .btn-secondary-closed:hover {
            background: #f9fafb;
            border-color: #9ca3af;
            color: #4b5563;
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
        <section id="customer" class="page-section">
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
                            <a
                                href="<?php echo url('index.php?page=profile'); ?>"><?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'] ?? 'Profile')[0]); ?></a>
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

            <div class="hero-customer">
                <div class="wrapper">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                        <div>
                            <h1>Fresh from the canteen,<br>skip the line</h1>
                            <div style="display: flex; gap: 24px; margin-top: 20px; flex-wrap: wrap;">
                                <span style="color: #3b7455; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-circle-check" style="color: #007a3e;"></i>
                                    <?php echo $stats['open_stalls']; ?>+ food stalls
                                </span>
                                <span style="color: #3b7455; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-clock" style="color: #007a3e;"></i> Real-time stock
                                </span>
                                <span style="color: #3b7455; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-star" style="color: #007a3e;"></i>
                                    <?php echo number_format($ratingStats['avg_rating'], 1); ?> avg rating
                                </span>
                            </div>
                        </div>
                        <div class="hero-image">
                            <img src="<?php echo url('assets/images/hero-placeholder.jpg'); ?>"
                                style="border-radius: 38px; border: 2px solid #b8e0cc; width: 280px; height: 140px; object-fit: cover; box-shadow: 0 10px 25px rgba(0,80,20,0.1);"
                                alt="UniCanteen"
                                onerror="this.src='https://placehold.co/280x140/ebf9f1/007a3e?text=UniCanteen+App'">
                        </div>
                    </div>
                </div>
            </div>

            <div class="wrapper">
                <!-- Quick Stats Bar -->
                <div class="stats-bar">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $stats['open_stalls']; ?></span>
                        <span class="stat-label">stalls open</span>
                    </div>
                    <div class="divider"></div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $stats['available_items']; ?></span>
                        <span class="stat-label">items available</span>
                    </div>
                    <div class="divider"></div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $stats['today_orders']; ?></span>
                        <span class="stat-label">orders today</span>
                    </div>
                    <div class="divider"></div>
                    <div class="stat-item">
                        <i class="fas fa-clock" style="color: #f5b342;"></i>
                        <span class="stat-label">Peak: 12-1PM</span>
                    </div>
                </div>

                <!-- Today's Stalls section -->
                <div class="section-header" id="menu">
                    <i class="fas fa-store"></i>
                    <h2>Today's Stalls</h2>
                </div>

                <!-- Search & Filter Bar -->
                <div class="search-filter-bar">
                    <div class="search-input-wrap">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="stallSearch" class="stall-search-input"
                            placeholder="Search food or stall name…" oninput="applyStallFilter()" autocomplete="off">
                        <button class="search-clear-btn" id="searchClearBtn" onclick="clearSearch()" title="Clear">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="filter-chips">
                        <button class="filter-chip active" data-filter="all" onclick="setFilter('all', this)">All
                            Stalls</button>
                        <button class="filter-chip" data-filter="open" onclick="setFilter('open', this)">Open
                            Now</button>
                        <button class="filter-chip" data-filter="rated" onclick="setFilter('rated', this)"><i
                                class="fas fa-star" style="color:#eab308; font-size:0.75rem;"></i> Top Rated</button>
                    </div>
                    <span class="search-result-count" id="resultCount"></span>
                </div>

                <div class="stall-grid" id="stallGrid">
                    <?php
                    $restaurantsResult->data_seek(0);
                    while ($restaurant = $restaurantsResult->fetch_assoc()):
                        $itemsQuery = "SELECT * FROM Items WHERE restaurant_ID = ? ORDER BY name LIMIT 4";
                        $stmt = $db->prepare($itemsQuery);
                        $stmt->bind_param("i", $restaurant['ID']);
                        $stmt->execute();
                        $itemsResult = $stmt->get_result();
                        // Collect item names for data attribute
                        $itemNames = [];
                        $itemsForAttr = $itemsResult->fetch_all(MYSQLI_ASSOC);
                        foreach ($itemsForAttr as $i) {
                            $itemNames[] = $i['name'];
                        }
                        $itemsResult->data_seek(0);
                        ?>
                        <div class="stall-card <?php echo !$restaurant['is_open'] ? 'stall-card-closed' : ''; ?>"
                            data-stall-name="<?php echo htmlspecialchars(strtolower($restaurant['name'])); ?>"
                            data-items="<?php echo htmlspecialchars(strtolower(implode(' ', $itemNames))); ?>"
                            data-open="<?php echo $restaurant['is_open'] ? '1' : '0'; ?>"
                            data-rating="<?php echo $restaurant['avg_rating']; ?>">
                            <div class="stall-header">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span class="stall-name"><?php echo htmlspecialchars($restaurant['name']); ?></span>
                                    <span
                                        style="background: <?php echo $restaurant['is_open'] ? '#e3f4ea' : '#fee9e9'; ?>; padding: 4px 10px; border-radius: 40px; font-size: 0.7rem; font-weight: 600; color: <?php echo $restaurant['is_open'] ? '#007a3e' : '#b13e3e'; ?>;">
                                        <?php echo $restaurant['is_open'] ? 'Open' : 'Closed'; ?>
                                    </span>
                                </div>
                                <span class="rating">
                                    <i class="fas fa-star"></i> <?php echo number_format($restaurant['avg_rating'], 1); ?>
                                    <span
                                        style="color: #8faa9a; font-weight: 400; margin-left: 4px;">(<?php echo $restaurant['rating_count']; ?>)</span>
                                </span>
                            </div>
                            <div class="menu-grid">
                                <?php
                                foreach ($itemsForAttr as $item):
                                    $isAvailable = ($item['isAvailable'] == 1 || $item['isAvailable'] === '1' || $item['isAvailable'] === true);
                                    $isFav = in_array($item['ID'], $userFavorites);
                                    ?>
                                    <div class="menu-row" title="<?php echo htmlspecialchars($item['description'] ?? ''); ?>" style="position:relative; padding-right:30px;">
                                        <span class="item-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                        <span class="price-tag">₱<?php echo number_format($item['price'], 0); ?></span>
                                        <?php if ($isAvailable): ?>
                                            <span class="avail-tag"><i class="fas fa-check-circle"></i> Available</span>
                                        <?php else: ?>
                                            <span class="avail-tag not-available"><i class="fas fa-times-circle"></i> Sold
                                                Out</span>
                                        <?php endif; ?>
                                        <button class="fav-btn" data-item-id="<?php echo $item['ID']; ?>" 
                                                style="position:absolute; right:-5px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color: <?php echo $isFav ? '#dc2626' : '#9ca3af'; ?>; transition: color 0.2s; font-size:1.1rem; padding:5px;">
                                            <i class="<?php echo $isFav ? 'fas' : 'far'; ?> fa-heart"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="availability-summary">
                                <span><i class="fas fa-utensils"></i> <?php echo $restaurant['available_items_count']; ?>
                                    items available</span>
                                <span
                                    class="available-count"><?php echo $restaurant['available_items_count']; ?>/<?php echo $restaurant['total_items_count']; ?>
                                    available</span>
                            </div>
                            <?php if ($restaurant['is_open']): ?>
                                <a href="<?php echo url('index.php?page=restaurant&id=' . $restaurant['ID']); ?>" class="btn-secondary"
                                    style="width: 100%; margin-top: 15px; text-decoration: none;">
                                    View Full Menu <i class="fas fa-arrow-right"></i>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo url('index.php?page=restaurant&id=' . $restaurant['ID']); ?>" class="btn-secondary-closed">
                                    <i class="fas fa-eye"></i> View Menu (Closed)
                                </a>
                                <div class="closed-overlay-bar">
                                    <i class="fas fa-store-slash"></i> This store is currently closed
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                </div>

                <!-- Order tracking card -->
                <?php if (!empty($activeOrders)): ?>
                    <div class="track-card" id="track">
                        <!-- Header -->
                        <div
                            style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div
                                    style="background: #e3f4ea; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-clipboard-list" style="font-size: 1.4rem; color: #007a3e;"></i>
                                </div>
                                <div>
                                    <h3 style="margin: 0; font-size: 1.3rem;">Active Orders</h3>
                                    <p style="color: #3b7455; margin: 3px 0 0; font-size: 0.9rem;">
                                        <?php echo count($activeOrders); ?>
                                        order<?php echo count($activeOrders) > 1 ? 's' : ''; ?> in progress
                                    </p>
                                </div>
                            </div>
                            <a href="<?php echo url('index.php?page=orders'); ?>" class="btn-secondary"
                                style="padding: 10px 22px; text-decoration: none; font-size: 0.9rem;">
                                <i class="fas fa-list"></i> View All Orders
                            </a>
                        </div>

                        <?php foreach ($activeOrders as $activeOrder): ?>
                            <!-- Single order card -->
                            <div
                                style="background: #f9fffc; border-radius: 20px; padding: 20px; margin-bottom: 16px; border: 1px solid #e0f0e8;">
                                <!-- Order title + status -->
                                <div
                                    style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 16px;">
                                    <div>
                                        <span style="font-weight: 700; font-size: 1.05rem; color: #0f4a2f;">Order
                                            #<?php echo $activeOrder['queue_number']; ?></span>
                                        <span
                                            style="color: #3b7455; font-size: 0.85rem; margin-left: 10px;"><?php echo htmlspecialchars($activeOrder['restaurant_name']); ?></span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span
                                            style="background: #daf1e2; padding: 6px 18px; border-radius: 40px; font-size: 0.875rem; font-weight: 600; color: #007a3e; display: flex; align-items: center; gap: 6px;">
                                            <span class="status-dot"
                                                style="width: 8px; height: 8px; background: #007a3e; border-radius: 50%; display: inline-block;"></span>
                                            <?php
                                            $statusText = [
                                                'P' => 'Pending',
                                                'PR' => 'Preparing',
                                                'R' => 'Ready for Pickup',
                                                'C' => 'Completed'
                                            ];
                                            echo $statusText[$activeOrder['status']];
                                            ?>
                                        </span>
                                        <a href="<?php echo url('index.php?page=order-details&id=' . $activeOrder['ID']); ?>"
                                            style="color: #007a3e; font-size: 0.85rem; font-weight: 600; text-decoration: none;">Details
                                            <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i></a>
                                    </div>
                                </div>

                                <!-- Items -->
                                <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
                                    <?php foreach ($activeOrder['items'] as $item): ?>
                                        <div class="order-item"
                                            style="background: white; border-radius: 14px; padding: 10px 14px; border: 1px solid #e0f0e8;">
                                            <span class="order-item-name">
                                                <span style="font-weight: 700;"><?php echo $item['quantity']; ?>x</span>
                                                <?php echo htmlspecialchars($item['item_name']); ?>
                                                <span
                                                    style="color: #5f8b74;">(<?php echo htmlspecialchars($item['restaurant_name']); ?>)</span>
                                            </span>
                                            <span class="order-price"><?php echo formatPrice($item['price_at_time']); ?></span>
                                            <span class="avail-tag order-avail status-badge confirmed"
                                                style="width: auto;">Confirmed</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Progress tracker -->
                                <div class="order-progress">
                                    <div class="progress-step">
                                        <div
                                            class="step-circle <?php echo in_array($activeOrder['status'], ['P', 'PR', 'R', 'C']) ? 'active' : 'inactive'; ?>">
                                            <i class="fas fa-check"></i>
                                        </div>
                                        <span class="step-label">Order Placed</span>
                                    </div>
                                    <div class="progress-step">
                                        <div
                                            class="step-circle <?php echo in_array($activeOrder['status'], ['PR', 'R', 'C']) ? 'active' : 'inactive'; ?>">
                                            <i class="fas fa-fire"></i>
                                        </div>
                                        <span class="step-label">Preparing</span>
                                    </div>
                                    <div class="progress-step">
                                        <div
                                            class="step-circle <?php echo in_array($activeOrder['status'], ['R', 'C']) ? 'active' : 'inactive'; ?>">
                                            <i class="fas fa-bag-shopping"></i>
                                        </div>
                                        <span class="step-label">Ready</span>
                                    </div>
                                    <div class="progress-step">
                                        <div
                                            class="step-circle <?php echo $activeOrder['status'] == 'C' ? 'active' : 'inactive'; ?>">
                                            <i class="fas fa-circle-check"></i>
                                        </div>
                                        <span class="step-label">Pick Up</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <!-- No active order message -->
                    <div class="track-card" style="text-align: center; padding: 40px;" id="track">
                        <i class="fas fa-clipboard-list"
                            style="font-size: 3rem; color: #007a3e; opacity: 0.5; margin-bottom: 15px;"></i>
                        <h3 style="margin-bottom: 10px;">No Active Orders</h3>
                        <p style="color: #3b7455; margin-bottom: 20px;">Hungry? Browse our stalls and place your first
                            order!</p>
                        <a href="#menu" class="btn-primary">Browse Menu</a>
                    </div>
                <?php endif; ?>

                <!-- Recent Ratings section -->
                <div class="section-header" id="reviews">
                    <i class="fas fa-star"></i>
                    <h2>Recent Ratings & Reviews</h2>
                    <a href="<?php echo url('index.php?page=reviews'); ?>"
                        style="margin-left: auto; color: #007a3e; font-weight: 500; text-decoration: none;">
                        See all <i class="fas fa-arrow-right"></i>
                    </a>
                </div>

                <div class="reviews-grid">
                    <?php
                    $reviewsResult->data_seek(0);
                    if ($reviewsResult->num_rows > 0):
                        while ($review = $reviewsResult->fetch_assoc()):
                            ?>
                            <div class="review-card">
                                <div class="reviewer-avatar">
                                    <i class="fas fa-user-circle" style="font-size: 2.5rem; color: #007a3e;"></i>
                                    <div>
                                        <span
                                            class="name"><?php echo htmlspecialchars($review['reviewer_name'] ?? 'Anonymous'); ?></span>
                                        <div style="font-size: 0.7rem; color: #5f8b74;">
                                            <?php echo getTimeAgo($review['timestamp']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="stall-badge">
                                    <i class="fas fa-store-alt"></i> <?php echo htmlspecialchars($review['restaurant_name']); ?>
                                </div>
                                <div class="stars-container">
                                    <?php
                                    $rating = $review['rating'];
                                    for ($i = 1; $i <= 5; $i++):
                                        if ($i <= floor($rating)):
                                            ?>
                                            <i class="fas fa-star"></i>
                                        <?php elseif ($i - 0.5 <= $rating): ?>
                                            <i class="fas fa-star-half-alt"></i>
                                        <?php else: ?>
                                            <i class="far fa-star"></i>
                                        <?php endif; endfor; ?>
                                    <?php echo number_format($rating, 1); ?>
                                </div>
                                <div class="review-text">
                                    <i class="fas fa-quote-left"></i>
                                    <?php echo htmlspecialchars(html_entity_decode($review['review'], ENT_QUOTES, 'UTF-8')); ?>
                                </div>
                            </div>
                            <?php
                        endwhile;
                    else:
                        ?>
                        <div style="text-align: center; padding: 40px; background: white; border-radius: 30px;">
                            <i class="fas fa-star" style="font-size: 3rem; color: #cae3d6; margin-bottom: 15px;"></i>
                            <p style="color: #3b7455;">No reviews yet. Be the first to leave a review!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- rating summary -->
                <div class="rating-summary" style="justify-content: space-between; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <i class="fas fa-star" style="color: #eab308; font-size: 2.5rem;"></i>
                        <div>
                            <span
                                style="font-size:2.2rem; font-weight:700;"><?php echo number_format($ratingStats['avg_rating'], 1); ?></span>
                            / 5
                            <span style="color: #3b7455;">· <?php echo $ratingStats['total_reviews']; ?> total
                                reviews</span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <div style="text-align: center;"><span style="font-weight:700;">5★</span> <span
                                style="color:#3b7455;">(<?php echo $ratingStats['five_star']; ?>)</span></div>
                        <div style="text-align: center;"><span style="font-weight:700;">4★</span> <span
                                style="color:#3b7455;">(<?php echo $ratingStats['four_star']; ?>)</span></div>
                        <div style="text-align: center;"><span style="font-weight:700;">3★</span> <span
                                style="color:#3b7455;">(<?php echo $ratingStats['three_star']; ?>)</span></div>
                        <div style="text-align: center;"><span style="font-weight:700;">2★</span> <span
                                style="color:#3b7455;">(<?php echo $ratingStats['two_star']; ?>)</span></div>
                        <div style="text-align: center;"><span style="font-weight:700;">1★</span> <span
                                style="color:#3b7455;">(<?php echo $ratingStats['one_star']; ?>)</span></div>
                    </div>
                </div>

                <!-- Quick Order CTA -->
                <div
                    style="background: linear-gradient(135deg, #007a3e, #1e7547); border-radius: 40px; padding: 40px; margin: 40px 0; color: white; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                    <div>
                        <h3 style="font-size: 1.8rem; margin-bottom: 10px; color: white;">Hungry? Order ahead</h3>
                        <p style="opacity: 0.9;">Skip the line and pick up when it's ready</p>
                    </div>
                    <a href="#menu" class="btn-primary"
                        style="background: white; color: #007a3e; text-decoration: none;">
                        <i class="fas fa-bag-shopping"></i> Order Now
                    </a>
                </div>
            </div>
            <footer class="footer-note">
                <i class="fas fa-mobile-alt"></i> UniCanteen Customer · Real‑time availability ·
                <?php echo $stats['open_stalls']; ?>+ stalls · Cashless payment
            </footer>
        </section>
    </div>
    <script>
        // ── Smooth scroll for anchor links ──────────────────────────────────
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        // ── Search & Filter ─────────────────────────────────────────────────
        let activeFilter = 'all';

        function setFilter(filter, btn) {
            activeFilter = filter;
            document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            applyStallFilter();
        }

        function clearSearch() {
            document.getElementById('stallSearch').value = '';
            applyStallFilter();
            document.getElementById('stallSearch').focus();
        }

        function applyStallFilter() {
            const query = document.getElementById('stallSearch').value.trim().toLowerCase();
            const cards = document.querySelectorAll('#stallGrid .stall-card');
            const clearBtn = document.getElementById('searchClearBtn');
            let visible = 0;

            // Show/hide the clear button
            clearBtn.style.display = query ? 'flex' : 'none';

            cards.forEach(card => {
                const name = card.dataset.stallName || '';
                const items = card.dataset.items || '';
                const isOpen = card.dataset.open === '1';
                const rating = parseFloat(card.dataset.rating || 0);

                // Text match
                const textMatch = !query || name.includes(query) || items.includes(query);

                // Chip filter
                let chipMatch = true;
                if (activeFilter === 'open') chipMatch = isOpen;
                if (activeFilter === 'rated') chipMatch = rating >= 4.0;

                const show = textMatch && chipMatch;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            // Result count
            const countEl = document.getElementById('resultCount');
            if (query || activeFilter !== 'all') {
                countEl.textContent = visible + ' stall' + (visible !== 1 ? 's' : '') + ' found';
                countEl.style.display = 'inline';
            } else {
                countEl.style.display = 'none';
            }

            // No-results placeholder
            let noRes = document.getElementById('noStallResults');
            if (visible === 0) {
                if (!noRes) {
                    noRes = document.createElement('div');
                    noRes.id = 'noStallResults';
                    noRes.style.cssText = 'grid-column:1/-1;text-align:center;padding:60px 20px;color:#3b7455;';
                    noRes.innerHTML = '<i class="fas fa-search" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:14px;"></i>' +
                        '<strong style="font-size:1.1rem;color:#1a4d31;">No stalls found</strong>' +
                        '<p style="margin-top:8px;">Try a different keyword or filter.</p>';
                    document.getElementById('stallGrid').appendChild(noRes);
                }
                noRes.style.display = '';
            } else if (noRes) {
                noRes.style.display = 'none';
            }
        }

        // Sort "Top Rated" — re-order DOM nodes
        function sortByRating() {
            const grid = document.getElementById('stallGrid');
            const cards = [...grid.querySelectorAll('.stall-card')];
            cards.sort((a, b) => parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating));
            cards.forEach(c => grid.appendChild(c));
        }

        // On page load — hide clear btn
        document.getElementById('searchClearBtn').style.display = 'none';

        // ── Favorite Toggle ─────────────────────────────────────────────────
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.fav-btn');
            if (!btn) return;
            
            e.preventDefault();
            e.stopPropagation();

            if (!window.isLoggedIn) {
                alert('Please sign in to save your favorite items.');
                window.location.href = '<?php echo url('index.php?page=login'); ?>';
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

            fetch('<?php echo url('frontend/toggle_favorite.php'); ?>', {
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