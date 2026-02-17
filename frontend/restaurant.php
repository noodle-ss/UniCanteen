<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php'; 

if (!isset($_GET['id'])) {
    header("Location: index.php?page=customer");
    exit();
}

$restaurant_id = intval($_GET['id']);
$db = Database::getInstance()->getConnection();

if (isset($_GET['add_to_cart'])) {
    $item_id = intval($_GET['add_to_cart']);
    header("Location: index.php?page=cart&add=$item_id&restaurant_id=$restaurant_id");
    exit();
}

$restaurantQuery = "SELECT 
    r.*,
    COALESCE(AVG(rt.rating), 0) as avg_rating,
    COUNT(DISTINCT rt.ID) as rating_count
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
    redirect('index.php');
}

$itemsQuery = "SELECT * FROM Items WHERE restaurant_ID = ? ORDER BY isAvailable DESC, name";
$stmt = $db->prepare($itemsQuery);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$itemsResult = $stmt->get_result();

$reviewsQuery = "SELECT 
    rt.*,
    u.full_name as reviewer_name
    FROM Ratings rt
    LEFT JOIN Orders o ON rt.order_ID = o.ID
    LEFT JOIN Users u ON o.customer_ID = u.ID
    WHERE rt.restaurant_ID = ?
    ORDER BY rt.timestamp DESC
    LIMIT 5";
$stmt = $db->prepare($reviewsQuery);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$reviewsResult = $stmt->get_result();

if (isset($_GET['add_to_cart'])) {
    $item_id = intval($_GET['add_to_cart']);
    header("Location: cart.php?add=$item_id&restaurant_id=$restaurant_id");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($restaurant['name']); ?> - UniCanteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
        .menu-item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e0f0e8;
        }
        .menu-item-row:last-child {
            border-bottom: none;
        }
        .menu-item-info {
            flex: 2;
        }
        .menu-item-name {
            font-weight: 600;
            font-size: 1.1rem;
            color: #1a4d31;
        }
        .menu-item-desc {
            color: #5f8b74;
            font-size: 0.9rem;
            margin-top: 4px;
        }
        .menu-item-price {
            font-weight: 600;
            color: #1c6e3c;
            font-size: 1.2rem;
            margin: 0 20px;
        }
        .add-to-cart-btn {
            background: #e3f4ea;
            color: #007a3e;
            border: none;
            padding: 10px 20px;
            border-radius: 30px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .add-to-cart-btn:hover {
            background: #007a3e;
            color: white;
        }
        .add-to-cart-btn.sold-out {
            background: #fee9e9;
            color: #b13e3e;
            cursor: not-allowed;
            pointer-events: none;
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
        <a href="<?php echo url('frontend/cart.php'); ?>" class="btn-primary"><i class="fas fa-bag-shopping"></i> Cart</a>
    </div>
</nav>
            
            <div style="margin: 40px 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                <div>
                    <h1 style="font-size: 2.5rem; color: #0f4a2f;"><?php echo htmlspecialchars($restaurant['name']); ?></h1>
                    <p style="color: #3b7455; margin-top: 10px;"><?php echo htmlspecialchars($restaurant['description']); ?></p>
                    <div style="display: flex; gap: 20px; margin-top: 15px; flex-wrap: wrap;">
                        <span class="rating"><i class="fas fa-star"></i> <?php echo number_format($restaurant['avg_rating'], 1); ?> (<?php echo $restaurant['rating_count']; ?> reviews)</span>
                        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($restaurant['address']); ?></span>
                        <span style="background: <?php echo $restaurant['is_open'] ? '#daf1e2' : '#fee9e9'; ?>; color: <?php echo $restaurant['is_open'] ? '#0c6e3a' : '#b13e3e'; ?>; padding: 6px 16px; border-radius: 30px;">
                            <?php echo $restaurant['is_open'] ? 'Open Now' : 'Closed'; ?>
                        </span>
                    </div>
                </div>
                <a href="<?php echo url('frontend/cart.php'); ?>" class="btn-primary" style="font-size: 1.1rem; padding: 16px 32px;">
                    <i class="fas fa-shopping-cart"></i> View Cart
                </a>
            </div>
            
            <div class="section-header">
                <i class="fas fa-utensils"></i>
                <h2>Full Menu</h2>
            </div>
            
            <div style="background: white; border-radius: 30px; padding: 20px; margin-bottom: 40px;">
                <?php 
                $itemsResult->data_seek(0);
                while($item = $itemsResult->fetch_assoc()): 
                    $isAvailable = ($item['isAvailable'] == 1 || $item['isAvailable'] === '1' || $item['isAvailable'] === true);
                ?>
                <div class="menu-item-row">
                    <div class="menu-item-info">
                        <div class="menu-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                        <?php if($item['description']): ?>
                        <div class="menu-item-desc"><?php echo htmlspecialchars($item['description']); ?></div>
                        <?php endif; ?>
                    </div>
                    <span class="menu-item-price">₱<?php echo number_format($item['price'], 2); ?></span>
                    
                    <?php if($isAvailable): ?>
                        <a href="<?php echo url('frontend/restaurant.php?id=' . $restaurant_id . '&add_to_cart=' . $item['ID']); ?>" 
                           class="add-to-cart-btn">
                            <i class="fas fa-cart-plus"></i> Add to Cart
                        </a>
                    <?php else: ?>
                        <span class="add-to-cart-btn sold-out">
                            <i class="fas fa-times-circle"></i> Sold Out
                        </span>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
            
            <div class="section-header">
                <i class="fas fa-star"></i>
                <h2>Customer Reviews</h2>
                <a href="<?php echo url('frontend/reviews.php?restaurant=' . $restaurant_id); ?>" style="margin-left: auto; color: #007a3e;">
                    See all <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="reviews-grid">
                <?php if($reviewsResult->num_rows > 0): ?>
                    <?php while($review = $reviewsResult->fetch_assoc()): ?>
                    <div class="review-card">
                        <div class="reviewer-avatar">
                            <i class="fas fa-user-circle"></i>
                            <div>
                                <span class="name"><?php echo htmlspecialchars($review['reviewer_name'] ?? 'Anonymous'); ?></span>
                                <div style="font-size: 0.7rem; color: #5f8b74;">
                                    <?php echo getTimeAgo($review['timestamp']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="stars-container">
                            <?php 
                            $rating = $review['rating'];
                            for($i = 1; $i <= 5; $i++):
                                if($i <= floor($rating)):
                            ?>
                                <i class="fas fa-star"></i>
                            <?php elseif($i - 0.5 <= $rating): ?>
                                <i class="fas fa-star-half-alt"></i>
                            <?php else: ?>
                                <i class="far fa-star"></i>
                            <?php endif; endfor; ?>
                            <?php echo number_format($rating, 1); ?>
                        </div>
                        <div class="review-text">
                            <i class="fas fa-quote-left"></i> 
                            <?php echo htmlspecialchars($review['review']); ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; background: white; border-radius: 30px;">
                        <i class="fas fa-star" style="font-size: 3rem; color: #cae3d6; margin-bottom: 15px;"></i>
                        <p style="color: #3b7455;">No reviews yet. Be the first to review!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <footer class="footer-note">
            <i class="fas fa-mobile-alt"></i> UniCanteen · <?php echo htmlspecialchars($restaurant['name']); ?>
        </footer>
    </div>

    <script>
    document.querySelectorAll('.add-to-cart-btn:not(.sold-out)').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="fas fa-check"></i> Added!';
            setTimeout(() => {
                this.innerHTML = originalText;
            }, 1000);
        });
    });
    </script>
</body>
</html>