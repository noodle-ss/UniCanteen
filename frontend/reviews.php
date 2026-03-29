<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

$db = Database::getInstance()->getConnection();

$page = isset($_GET['page_num']) ? intval($_GET['page_num']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$restaurant_filter = isset($_GET['restaurant']) ? intval($_GET['restaurant']) : 0;

// Build the count query with filter
$countQuery = "SELECT COUNT(*) as total FROM Ratings";
$countParams = [];
$countTypes = "";

if ($restaurant_filter > 0) {
    $countQuery .= " WHERE restaurant_ID = ?";
    $countParams[] = $restaurant_filter;
    $countTypes .= "i";
}

$countStmt = $db->prepare($countQuery);
if (!empty($countParams)) {
    $countStmt->bind_param($countTypes, ...$countParams);
}
$countStmt->execute();
$total_reviews = $countStmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_reviews / $limit);

// Get all restaurants for filter
$restaurantsQuery = "SELECT ID, name FROM Restaurants ORDER BY name";
$restaurants = $db->query($restaurantsQuery);

// Get reviews with pagination
$reviewsQuery = "SELECT 
    rt.*,
    r.name as restaurant_name,
    COALESCE(CASE WHEN rt.is_anonymous = 1 THEN 'Anonymous' ELSE u.full_name END, 'Anonymous') as reviewer_name,
    u.email,
    o.queue_number as order_number
    FROM Ratings rt
    JOIN Restaurants r ON rt.restaurant_ID = r.ID
    LEFT JOIN Orders o ON rt.order_ID = o.ID
    LEFT JOIN Users u ON o.customer_ID = u.ID";

$reviewParams = [];
$reviewTypes = "";

if ($restaurant_filter > 0) {
    $reviewsQuery .= " WHERE rt.restaurant_ID = ?";
    $reviewParams[] = $restaurant_filter;
    $reviewTypes .= "i";
}

$reviewsQuery .= " ORDER BY rt.timestamp DESC LIMIT ? OFFSET ?";
$reviewParams[] = $limit;
$reviewParams[] = $offset;
$reviewTypes .= "ii";

$reviewStmt = $db->prepare($reviewsQuery);
if (!empty($reviewParams)) {
    $reviewStmt->bind_param($reviewTypes, ...$reviewParams);
}
$reviewStmt->execute();
$reviews = $reviewStmt->get_result();

// Get rating statistics
$statsQuery = "SELECT 
    COUNT(*) as total_reviews,
    COALESCE(AVG(rating), 0) as avg_rating,
    SUM(CASE WHEN rating >= 4.5 THEN 1 ELSE 0 END) as five_star,
    SUM(CASE WHEN rating >= 3.5 AND rating < 4.5 THEN 1 ELSE 0 END) as four_star,
    SUM(CASE WHEN rating >= 2.5 AND rating < 3.5 THEN 1 ELSE 0 END) as three_star,
    SUM(CASE WHEN rating >= 1.5 AND rating < 2.5 THEN 1 ELSE 0 END) as two_star,
    SUM(CASE WHEN rating < 1.5 THEN 1 ELSE 0 END) as one_star
    FROM Ratings";

$statsParams = [];
$statsTypes = "";

if ($restaurant_filter > 0) {
    $statsQuery .= " WHERE restaurant_ID = ?";
    $statsParams[] = $restaurant_filter;
    $statsTypes .= "i";
}

$statsStmt = $db->prepare($statsQuery);
if (!empty($statsParams)) {
    $statsStmt->bind_param($statsTypes, ...$statsParams);
}
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

// Handle review submission
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

            $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;
            $insertStmt = $db->prepare("INSERT INTO Ratings (restaurant_ID, order_ID, rating, review, is_anonymous) VALUES (?, ?, ?, ?, ?)");
            $insertStmt->bind_param("iidsi", $restaurant_id, $order_id, $rating, $review, $is_anonymous);
            if ($insertStmt->execute()) {
                header("Location: " . url('index.php?page=reviews&success=1') . ($restaurant_filter ? "&restaurant=$restaurant_filter" : ""));
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
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
        .reviews-header {
            background: linear-gradient(rgba(245, 250, 245, 0.9), rgba(245, 250, 245, 0.95));
            padding: 40px 0;
            margin-bottom: 40px;
            border-bottom: 1px solid #e0f0e8;
        }

        .rating-big {
            background: white;
            border-radius: 30px;
            padding: 30px;
            display: flex;
            align-items: center;
            gap: 40px;
            flex-wrap: wrap;
            box-shadow: 0 10px 30px rgba(0, 70, 30, 0.1);
            margin-top: 30px;
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
            border: 1px solid #e0f0e8;
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
            border: 1px solid #e0f0e8;
        }

        .page-link.active {
            background: #007a3e;
            color: white;
            border-color: #007a3e;
        }

        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin: 30px 0;
        }

        .wrapper {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 36px;
        }

        .back-button {
            margin-bottom: 20px;
        }

        .back-button a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: white;
            border-radius: 30px;
            text-decoration: none;
            color: #007a3e;
            font-weight: 500;
            border: 1px solid #e0f0e8;
        }

        .back-button a:hover {
            background: #f0f7f0;
        }

        .customer-nav {
            background: transparent !important;
            padding: 0 !important;
            margin-bottom: 20px;
        }

        .success-message {
            background: #dcfce7;
            color: #166534;
            padding: 15px 20px;
            border-radius: 16px;
            border: 1px solid #bbf7d0;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }
    </style>
</head>

<body>
    <div class="main-content">
        <section class="reviews-header">
            <div class="wrapper">
                <nav class="customer-nav">
                    <a href="<?php echo url('index.php?page=customer'); ?>" class="logo">UniCanteen <span>DLSU</span></a>
                    <div class="customer-nav-links">
                        <a href="<?php echo url('index.php?page=customer'); ?>#menu">Menu</a>
                        <a href="<?php echo url('index.php?page=customer'); ?>#track">Track</a>
                        <a href="<?php echo url('index.php?page=favorites'); ?>">Favorites</a>
                        <a href="<?php echo url('index.php?page=orders'); ?>">Orders</a>
                        <a href="<?php echo url('index.php?page=reviews'); ?>" style="font-weight: 700; color: #007a3e;">Reviews</a>
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
                        </a>
                    </div>
                </nav>
                <div class="rating-big">
                    <div class="rating-circle">
                        <span class="number"><?php echo number_format($stats['avg_rating'], 1); ?></span>
                        <span class="stars">
                            <?php
                            $avg = $stats['avg_rating'];
                            for ($i = 1; $i <= 5; $i++):
                                if ($i <= floor($avg))
                                    echo '<i class="fas fa-star"></i>';
                                elseif ($i - 0.5 <= $avg)
                                    echo '<i class="fas fa-star-half-alt"></i>';
                                else
                                    echo '<i class="far fa-star"></i>';
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
                            2 => $stats['two_star'],
                            1 => $stats['one_star']
                        ];
                        foreach ($ratings as $stars => $count):
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
                <select onchange="window.location.href='<?php echo url('index.php?page=reviews'); ?>&restaurant=' + this.value"
                    style="padding: 10px 20px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;">
                    <option value="0">All Restaurants</option>
                    <?php
                    $restaurants->data_seek(0);
                    while ($rest = $restaurants->fetch_assoc()):
                        ?>
                        <option value="<?php echo $rest['ID']; ?>" <?php echo $restaurant_filter == $rest['ID'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rest['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <?php if ($restaurant_filter > 0): ?>
                    <a href="<?php echo url('index.php?page=reviews'); ?>" class="btn-secondary" style="padding: 8px 20px;">
                        <i class="fas fa-times"></i> Clear Filter
                    </a>
                <?php endif; ?>
            </div>

            <?php if (isset($_GET['success'])): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> Thank you for your review!
                </div>
            <?php endif; ?>

            <?php if (isLoggedIn()):
                $pendingQuery = "SELECT o.ID, o.queue_number, r.name as restaurant_name
                                FROM Orders o
                                JOIN Restaurants r ON o.restaurant_ID = r.ID
                                LEFT JOIN Ratings rt ON o.ID = rt.order_ID
                                WHERE o.customer_ID = ? AND o.status = 'C' AND rt.ID IS NULL
                                ORDER BY o.order_date DESC";
                $stmt = $db->prepare($pendingQuery);
                $stmt->bind_param("i", $_SESSION['user_id']);
                $stmt->execute();
                $pendingOrders = $stmt->get_result();

                if ($pendingOrders->num_rows > 0):
                    ?>
                    <div class="review-form">
                        <h3 style="margin-bottom: 15px; color: #16623b;">Review Your Completed Orders</h3>
                        <p style="color: #3b7455; margin-bottom: 20px;">
                            You have <?php echo $pendingOrders->num_rows; ?> order<?php echo $pendingOrders->num_rows > 1 ? 's' : ''; ?> waiting for your review.
                        </p>

                        <?php while ($pendingOrder = $pendingOrders->fetch_assoc()): ?>
                        <div style="background: #f0f7f2; border-radius: 16px; padding: 20px; margin-bottom: 16px; border: 1px solid #e0f0e8;">
                            <p style="font-weight: 600; color: #1a4d31; margin-bottom: 12px;">
                                <i class="fas fa-receipt" style="color: #007a3e; margin-right: 6px;"></i>
                                Order #<?php echo $pendingOrder['queue_number']; ?> — <?php echo htmlspecialchars($pendingOrder['restaurant_name']); ?>
                            </p>

                            <form method="POST" action="<?php echo url('index.php?page=reviews'); ?>">
                                <input type="hidden" name="order_id" value="<?php echo $pendingOrder['ID']; ?>">

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Your Rating</label>
                                    <div class="star-rating" data-order-id="<?php echo $pendingOrder['ID']; ?>">
                                        <i class="fas fa-star" data-rating="1"></i>
                                        <i class="fas fa-star" data-rating="2"></i>
                                        <i class="fas fa-star" data-rating="3"></i>
                                        <i class="fas fa-star" data-rating="4"></i>
                                        <i class="fas fa-star" data-rating="5"></i>
                                    </div>
                                    <input type="hidden" name="rating" class="ratingValue" value="5">
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Your Review</label>
                                    <textarea name="review" rows="3" required
                                        style="width: 100%; padding: 12px 15px; border: 2px solid #e0f0e8; border-radius: 16px; font-family: 'Inter', sans-serif; resize: vertical; box-sizing: border-box;"
                                        placeholder="Tell us about your experience..."></textarea>
                                </div>

                                <div style="margin-bottom: 20px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 500; cursor: pointer; color: #3b7455; font-size: 0.95rem;">
                                        <input type="checkbox" name="is_anonymous" value="1" style="width: 16px; height: 16px; accent-color: #007a3e;">
                                        <i class="fas fa-user-secret" style="color: #007a3e;"></i> Post anonymously
                                    </label>
                                </div>

                                <button type="submit" name="submit_review" class="btn-primary" style="padding: 10px 24px;">
                                    <i class="fas fa-paper-plane"></i> Submit Review
                                </button>
                            </form>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; endif; ?>

            <div class="reviews-grid">
                <?php if ($reviews->num_rows > 0): ?>
                    <?php while ($review = $reviews->fetch_assoc()): ?>
                        <div class="review-card">
                            <div class="reviewer-avatar">
                                <i class="fas fa-user-circle" style="font-size: 2.5rem; color: #007a3e;"></i>
                                <div>
                                    <span
                                        class="name"><?php echo htmlspecialchars($review['reviewer_name'] ?? 'Anonymous'); ?></span>
                                    <div style="font-size: 0.7rem; color: #5f8b74;">
                                        <?php echo getTimeAgo($review['timestamp']); ?>
                                        <?php if ($review['order_number']): ?>
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
                    <?php endwhile; ?>
                <?php else: ?>
                    <div
                        style="grid-column: 1 / -1; text-align: center; padding: 60px; background: white; border-radius: 30px;">
                        <i class="fas fa-star" style="font-size: 4rem; color: #cae3d6; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 10px;">No Reviews Yet</h3>
                        <p style="color: #3b7455;">Be the first to leave a review!</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo url('index.php?page=reviews&page_num=' . ($page - 1)) . ($restaurant_filter ? '&restaurant=' . $restaurant_filter : ''); ?>"
                            class="page-link">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="<?php echo url('index.php?page=reviews&page_num=' . $i) . ($restaurant_filter ? '&restaurant=' . $restaurant_filter : ''); ?>"
                            class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="<?php echo url('index.php?page=reviews&page_num=' . ($page + 1)) . ($restaurant_filter ? '&restaurant=' . $restaurant_filter : ''); ?>"
                            class="page-link">
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
        // Star rating functionality for multiple review forms
        document.querySelectorAll('.star-rating').forEach(container => {
            const stars = container.querySelectorAll('i');
            const ratingInput = container.parentElement.querySelector('.ratingValue');

            function highlightStars(rating) {
                stars.forEach(star => {
                    star.className = star.dataset.rating <= rating ? 'fas fa-star' : 'far fa-star';
                });
            }

            stars.forEach(star => {
                star.addEventListener('mouseover', () => highlightStars(star.dataset.rating));
                star.addEventListener('mouseout', () => highlightStars(ratingInput.value));
                star.addEventListener('click', () => {
                    ratingInput.value = star.dataset.rating;
                    highlightStars(star.dataset.rating);
                });
            });

            // Pre-fill stars to match default value
            highlightStars(ratingInput.value);
        });
    </script>
</body>

</html>