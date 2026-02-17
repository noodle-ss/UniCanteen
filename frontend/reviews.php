<?php
require_once __DIR__ . '/../config/config.php';

$db = Database::getInstance()->getConnection();

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$restaurant_filter = isset($_GET['restaurant']) ? intval($_GET['restaurant']) : 0;

$countQuery = "SELECT COUNT(*) as total FROM Ratings";
if ($restaurant_filter > 0) {
    $countQuery .= " WHERE restaurant_ID = $restaurant_filter";
}
$countResult = $db->query($countQuery);
$total_reviews = $countResult->fetch_assoc()['total'];
$total_pages = ceil($total_reviews / $limit);

$restaurantsQuery = "SELECT ID, name FROM Restaurants ORDER BY name";
$restaurants = $db->query($restaurantsQuery);

$reviewsQuery = "SELECT 
    rt.*,
    r.name as restaurant_name,
    u.full_name as reviewer_name,
    u.email,
    o.queue_number as order_number
    FROM Ratings rt
    JOIN Restaurants r ON rt.restaurant_ID = r.ID
    LEFT JOIN Orders o ON rt.order_ID = o.ID
    LEFT JOIN Users u ON o.customer_ID = u.ID
    " . ($restaurant_filter > 0 ? "WHERE rt.restaurant_ID = $restaurant_filter" : "") . "
    ORDER BY rt.timestamp DESC
    LIMIT ? OFFSET ?";

$stmt = $db->prepare($reviewsQuery);
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$reviews = $stmt->get_result();

$statsQuery = "SELECT 
    COUNT(*) as total_reviews,
    COALESCE(AVG(rating), 0) as avg_rating,
    SUM(CASE WHEN rating >= 4.5 THEN 1 ELSE 0 END) as five_star,
    SUM(CASE WHEN rating >= 3.5 AND rating < 4.5 THEN 1 ELSE 0 END) as four_star,
    SUM(CASE WHEN rating >= 2.5 AND rating < 3.5 THEN 1 ELSE 0 END) as three_star,
    SUM(CASE WHEN rating < 2.5 THEN 1 ELSE 0 END) as two_star
    FROM Ratings";
if ($restaurant_filter > 0) {
    $statsQuery .= " WHERE restaurant_ID = $restaurant_filter";
}
$statsResult = $db->query($statsQuery);
$stats = $statsResult->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_review']) && isLoggedIn()) {
    $order_id = intval($_POST['order_id']);
    $rating = floatval($_POST['rating']);
    $review = sanitizeInput($_POST['review']);
    
    $checkStmt = $db->prepare("SELECT ID FROM Orders WHERE ID = ? AND customer_ID = ? AND status = 'C'");
    $checkStmt->bind_param("ii", $order_id, $_SESSION['user_id']);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        $reviewCheck = $db->prepare("SELECT ID FROM Ratings WHERE order_ID = ?");
        $reviewCheck->bind_param("i", $order_id);
        $reviewCheck->execute();
        if ($reviewCheck->get_result()->num_rows == 0) {
            $restStmt = $db->prepare("SELECT restaurant_ID FROM Orders WHERE ID = ?");
            $restStmt->bind_param("i", $order_id);
            $restStmt->execute();
            $restResult = $restStmt->get_result();
            $restaurant_id = $restResult->fetch_assoc()['restaurant_ID'];
            
            $insertStmt = $db->prepare("INSERT INTO Ratings (restaurant_ID, order_ID, rating, review) VALUES (?, ?, ?, ?)");
            $insertStmt->bind_param("iids", $restaurant_id, $order_id, $rating, $review);
            if ($insertStmt->execute()) {
                header("Location: reviews.php?success=1" . ($restaurant_filter ? "&restaurant=$restaurant_filter" : ""));
                exit();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews - UniCanteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
        .reviews-header {
            background: linear-gradient(rgba(245,250,245,0.9), rgba(245,250,245,0.95));
            background-size: cover;
            padding: 60px 0;
            margin-bottom: 40px;
            border-bottom: 1px solid var(--border-soft);
        }
        .rating-big {
            background: white;
            border-radius: 30px;
            padding: 30px;
            display: flex;
            align-items: center;
            gap: 40px;
            flex-wrap: wrap;
            box-shadow: 0 10px 30px rgba(0,70,30,0.1);
        }
        .rating-circle {
            width: 150px;
            height: 150px;
            background: linear-gradient(135deg, #007a3e, #1e7547);
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
        }
        .rating-circle .number {
            font-size: 3.5rem;
            font-weight: 700;
            line-height: 1;
        }
        .rating-circle .stars {
            font-size: 1rem;
            margin-top: 5px;
        }
        .rating-bars {
            flex: 1;
        }
        .rating-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .bar-label {
            width: 50px;
            font-weight: 600;
        }
        .bar-progress {
            flex: 1;
            height: 8px;
            background: #e0f0e8;
            border-radius: 4px;
            overflow: hidden;
        }
        .bar-fill {
            height: 100%;
            background: #f5b342;
            border-radius: 4px;
        }
        .filter-section {
            background: white;
            border-radius: 30px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        .review-form {
            background: white;
            border-radius: 30px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border-soft);
        }
        .star-rating {
            display: flex;
            gap: 10px;
            font-size: 1.5rem;
            color: #eab308;
            cursor: pointer;
        }
        .star-rating i {
            transition: transform 0.2s;
        }
        .star-rating i:hover {
            transform: scale(1.2);
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 40px 0;
        }
        .page-link {
            padding: 10px 16px;
            border-radius: 30px;
            background: white;
            color: #007a3e;
            text-decoration: none;
            border: 1px solid var(--border-soft);
        }
        .page-link.active {
            background: #007a3e;
            color: white;
            border-color: #007a3e;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <section class="reviews-header">
            <div class="wrapper">
                <nav class="customer-nav" style="background: transparent; padding-top: 0;">
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

                <div class="rating-big">
                    <div class="rating-circle">
                        <span class="number"><?php echo number_format($stats['avg_rating'], 1); ?></span>
                        <span class="stars">
                            <?php 
                            $avg = $stats['avg_rating'];
                            for($i=1; $i<=5; $i++):
                                if($i <= floor($avg)) echo '<i class="fas fa-star"></i>';
                                elseif($i - 0.5 <= $avg) echo '<i class="fas fa-star-half-alt"></i>';
                                else echo '<i class="far fa-star"></i>';
                            endfor;
                            ?>
                        </span>
                        <span style="font-size: 0.9rem;"><?php echo $stats['total_reviews']; ?> reviews</span>
                    </div>
                    <div class="rating-bars">
                        <?php 
                        $ratings = [
                            5 => $stats['five_star'],
                            4 => $stats['four_star'],
                            3 => $stats['three_star'],
                            2 => $stats['two_star']
                        ];
                        foreach($ratings as $stars => $count):
                            $percentage = $stats['total_reviews'] > 0 ? ($count / $stats['total_reviews']) * 100 : 0;
                        ?>
                        <div class="rating-bar">
                            <span class="bar-label"><?php echo $stars; ?> ★</span>
                            <div class="bar-progress">
                                <div class="bar-fill" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                            <span style="min-width: 40px;"><?php echo $count; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <div class="wrapper">
            <div class="filter-section">
                <i class="fas fa-filter" style="color: #007a3e;"></i>
                <span style="font-weight: 600;">Filter by:</span>
                <select onchange="window.location.href='<?php echo url('frontend/reviews.php'); ?>?restaurant=' + this.value" 
                        style="padding: 10px 20px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;">
                    <option value="0">All Restaurants</option>
                    <?php 
                    $restaurants->data_seek(0);
                    while($rest = $restaurants->fetch_assoc()): 
                    ?>
                    <option value="<?php echo $rest['ID']; ?>" <?php echo $restaurant_filter == $rest['ID'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($rest['name']); ?>
                    </option>
                    <?php endwhile; ?>
                </select>
                
                <?php if($restaurant_filter > 0): ?>
                <a href="<?php echo url('frontend/reviews.php'); ?>" class="btn-secondary" style="padding: 8px 20px;">
                    <i class="fas fa-times"></i> Clear Filter
                </a>
                <?php endif; ?>
            </div>

            <?php if(isset($_GET['success'])): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> Thank you for your review!
            </div>
            <?php endif; ?>

            <?php if(isLoggedIn()): 
                $pendingQuery = "SELECT o.ID, o.queue_number, r.name as restaurant_name
                                FROM Orders o
                                JOIN Restaurants r ON o.restaurant_ID = r.ID
                                LEFT JOIN Ratings rt ON o.ID = rt.order_ID
                                WHERE o.customer_ID = ? AND o.status = 'C' AND rt.ID IS NULL
                                LIMIT 1";
                $stmt = $db->prepare($pendingQuery);
                $stmt->bind_param("i", $_SESSION['user_id']);
                $stmt->execute();
                $pendingOrder = $stmt->get_result()->fetch_assoc();
                
                if($pendingOrder):
            ?>
            <div class="review-form">
                <h3 style="margin-bottom: 15px; color: #16623b;">Review Your Recent Order</h3>
                <p style="color: #3b7455; margin-bottom: 20px;">
                    Order #<?php echo $pendingOrder['queue_number']; ?> from <?php echo $pendingOrder['restaurant_name']; ?>
                </p>
                
                <form method="POST" action="">
                    <input type="hidden" name="order_id" value="<?php echo $pendingOrder['ID']; ?>">
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 10px; font-weight: 500;">Your Rating</label>
                        <div class="star-rating" id="starRating">
                            <i class="far fa-star" data-rating="1"></i>
                            <i class="far fa-star" data-rating="2"></i>
                            <i class="far fa-star" data-rating="3"></i>
                            <i class="far fa-star" data-rating="4"></i>
                            <i class="far fa-star" data-rating="5"></i>
                        </div>
                        <input type="hidden" name="rating" id="ratingValue" value="5">
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 10px; font-weight: 500;">Your Review</label>
                        <textarea name="review" rows="4" required 
                                  style="width: 100%; padding: 15px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;"
                                  placeholder="Tell us about your experience..."></textarea>
                    </div>
                    
                    <button type="submit" name="submit_review" class="btn-primary">
                        <i class="fas fa-paper-plane"></i> Submit Review
                    </button>
                </form>
            </div>
            <?php endif; endif; ?>

            <div class="reviews-grid">
                <?php if($reviews->num_rows > 0): ?>
                    <?php while($review = $reviews->fetch_assoc()): ?>
                    <div class="review-card">
                        <div class="reviewer-avatar">
                            <i class="fas fa-user-circle"></i>
                            <div>
                                <span class="name"><?php echo htmlspecialchars($review['reviewer_name'] ?? 'Anonymous'); ?></span>
                                <div style="font-size: 0.7rem; color: #5f8b74;">
                                    <?php echo getTimeAgo($review['timestamp']); ?>
                                    <?php if($review['order_number']): ?>
                                    · Order #<?php echo $review['order_number']; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="stall-badge">
                            <i class="fas fa-store-alt"></i> <?php echo htmlspecialchars($review['restaurant_name']); ?>
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
                    <div style="text-align: center; padding: 60px; background: white; border-radius: 30px;">
                        <i class="fas fa-star" style="font-size: 4rem; color: #cae3d6; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 10px;">No Reviews Yet</h3>
                        <p style="color: #3b7455;">Be the first to leave a review!</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php if($page > 1): ?>
                <a href="?page=<?php echo $page-1; ?><?php echo $restaurant_filter ? '&restaurant='.$restaurant_filter : ''; ?>" class="page-link">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php endif; ?>
                
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?><?php echo $restaurant_filter ? '&restaurant='.$restaurant_filter : ''; ?>" 
                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
                
                <?php if($page < $total_pages): ?>
                <a href="?page=<?php echo $page+1; ?><?php echo $restaurant_filter ? '&restaurant='.$restaurant_filter : ''; ?>" class="page-link">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <footer class="footer-note">
            <i class="fas fa-star"></i> Customer Reviews · Real Feedback · Trusted Opinions
        </footer>
    </div>

    <script>
    const stars = document.querySelectorAll('#starRating i');
    const ratingInput = document.getElementById('ratingValue');
    
    if (stars.length > 0) {
        stars.forEach(star => {
            star.addEventListener('mouseover', function() {
                const rating = this.dataset.rating;
                highlightStars(rating);
            });
            
            star.addEventListener('mouseout', function() {
                const currentRating = ratingInput.value;
                highlightStars(currentRating);
            });
            
            star.addEventListener('click', function() {
                const rating = this.dataset.rating;
                ratingInput.value = rating;
                highlightStars(rating);
            });
        });
    }
    
    function highlightStars(rating) {
        stars.forEach(star => {
            const starRating = star.dataset.rating;
            if (starRating <= rating) {
                star.className = 'fas fa-star';
            } else {
                star.className = 'far fa-star';
            }
        });
    }
    </script>
</body>
</html>