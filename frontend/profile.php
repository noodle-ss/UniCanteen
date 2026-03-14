<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

// Only logged in users can access profile
requireLogin();

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = sanitizeInput($_POST['full_name']);
        $email = sanitizeInput($_POST['email']);

        // Validate
        $errors = [];
        if (empty($full_name)) {
            $errors[] = 'Full name is required';
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }

        if (empty($errors)) {
            // Check if email already exists for another user
            $checkStmt = $db->prepare("SELECT ID FROM Users WHERE email = ? AND ID != ?");
            $checkStmt->bind_param("si", $email, $user_id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $error = 'Email already in use by another account';
            } else {
                $updateStmt = $db->prepare("UPDATE Users SET full_name = ?, email = ? WHERE ID = ?");
                $updateStmt->bind_param("ssi", $full_name, $email, $user_id);
                if ($updateStmt->execute()) {
                    $_SESSION['user_name'] = $full_name;
                    $_SESSION['user_email'] = $email;
                    $success = 'Profile updated successfully!';
                } else {
                    $error = 'Failed to update profile';
                }
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }

    if (isset($_POST['change_password'])) {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        $errors = [];

        // Verify current password
        $stmt = $db->prepare("SELECT password FROM Users WHERE ID = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!password_verify($current, $user['password'])) {
            $errors[] = 'Current password is incorrect';
        }

        if (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters';
        }
        if (!preg_match('/[A-Z]/', $new)) {
            $errors[] = 'New password must contain an uppercase letter';
        }
        if (!preg_match('/[a-z]/', $new)) {
            $errors[] = 'New password must contain a lowercase letter';
        }
        if (!preg_match('/[0-9]/', $new)) {
            $errors[] = 'New password must contain a number';
        }
        if ($new !== $confirm) {
            $errors[] = 'New passwords do not match';
        }

        if (empty($errors)) {
            $hashed = password_hash($new, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
            $updateStmt = $db->prepare("UPDATE Users SET password = ? WHERE ID = ?");
            $updateStmt->bind_param("si", $hashed, $user_id);
            if ($updateStmt->execute()) {
                $success = 'Password changed successfully!';
            } else {
                $error = 'Failed to change password';
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
}

// Get user data
$stmt = $db->prepare("SELECT * FROM Users WHERE ID = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Get user's orders
$ordersQuery = "SELECT o.*, r.name as restaurant_name,
                COUNT(oi.ID) as item_count
                FROM Orders o
                JOIN Restaurants r ON o.restaurant_ID = r.ID
                LEFT JOIN Order_ItemLine oi ON o.ID = oi.order_ID
                WHERE o.customer_ID = ?
                GROUP BY o.ID
                ORDER BY o.order_date DESC
                LIMIT 5";
$stmt = $db->prepare($ordersQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$orders = $stmt->get_result();

// Get user's reviews
$reviewsQuery = "SELECT r.*, res.name as restaurant_name
                FROM Ratings r
                JOIN Restaurants res ON r.restaurant_ID = res.ID
                WHERE r.order_ID IN (SELECT ID FROM Orders WHERE customer_ID = ?)
                ORDER BY r.timestamp DESC";
$stmt = $db->prepare($reviewsQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$reviews = $stmt->get_result();

// Get count of completed orders that haven't been reviewed
$pendingReviewsQuery = "SELECT COUNT(*) as pending_count
                        FROM Orders o
                        LEFT JOIN Ratings rt ON o.ID = rt.order_ID
                        WHERE o.customer_ID = ? AND o.status = 'C' AND rt.ID IS NULL";
$stmt = $db->prepare($pendingReviewsQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$pendingReviews = $stmt->get_result()->fetch_assoc()['pending_count'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - UniCanteen</title>
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

        .profile-container {
            max-width: 1000px;
            margin: 40px auto;
        }

        .profile-header {
            background: linear-gradient(135deg, #007a3e, #1e7547);
            color: white;
            border-radius: 30px;
            padding: 40px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: #007a3e;
        }

        .profile-info h1 {
            font-size: 2rem;
            margin-bottom: 5px;
        }

        .profile-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #e0f0e8;
            padding-bottom: 10px;
        }

        .tab-btn {
            padding: 12px 25px;
            border: none;
            background: none;
            font-weight: 600;
            color: #3b7455;
            cursor: pointer;
            border-radius: 40px;
            transition: all 0.2s;
        }

        .tab-btn.active {
            background: #007a3e;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .profile-card {
            background: white;
            border-radius: 30px;
            padding: 30px;
            border: 1px solid var(--border-soft);
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #1e3a2f;
        }

        .form-group input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0f0e8;
            border-radius: 30px;
            font-family: 'Inter', sans-serif;
        }

        .order-history-item {
            padding: 20px;
            border-bottom: 1px solid #e0f0e8;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .order-history-item:last-child {
            border-bottom: none;
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
                    <a href="<?php echo url('index.php?page=orders'); ?>">Orders</a>
                    <a href="<?php echo url('index.php?page=reviews'); ?>">Reviews</a>
                    <a href="<?php echo url('index.php?page=profile'); ?>"
                        style="color: #007a3e;"><?php echo htmlspecialchars(explode(' ', $user['full_name'])[0]); ?></a>
                    <a href="<?php echo url('index.php?page=logout'); ?>" class="btn-outline">Logout</a>
                    <a href="<?php echo url('index.php?page=cart'); ?>" class="btn-primary"><i
                            class="fas fa-bag-shopping"></i> Cart</a>
                </div>
            </nav>
            <div class="profile-container">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="profile-info">
                        <h1><?php echo htmlspecialchars($user['full_name']); ?></h1>
                        <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></p>
                        <p><i class="fas fa-calendar"></i> Member since
                            <?php echo date('F Y', strtotime($user['created_at'])); ?>
                        </p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="success-message">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <div class="profile-tabs">
                    <button class="tab-btn active" onclick="showTab('profile', this)"><i class="fas fa-user"></i>
                        Profile</button>
                    <button class="tab-btn" onclick="showTab('orders', this)"><i class="fas fa-clipboard-list"></i>
                        Orders</button>
                    <button class="tab-btn" onclick="showTab('reviews', this)"><i class="fas fa-star"></i>
                        Reviews</button>
                    <button class="tab-btn" onclick="showTab('security', this)"><i class="fas fa-lock"></i>
                        Security</button>
                </div>

                <!-- Profile Tab -->
                <div id="profile-tab" class="tab-content active">
                    <div class="profile-card">
                        <h3 style="margin-bottom: 20px; color: #16623b;">Edit Profile</h3>
                        <form method="POST" action="index.php?page=profile">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="full_name"
                                    value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>"
                                    required>
                            </div>
                            <button type="submit" name="update_profile" class="btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Orders Tab -->
                <div id="orders-tab" class="tab-content">
                    <div class="profile-card">
                        <h3 style="margin-bottom: 20px; color: #16623b;">Recent Orders</h3>
                        <?php if ($orders->num_rows > 0): ?>
                            <?php while ($order = $orders->fetch_assoc()): ?>
                                <div class="order-history-item">
                                    <div>
                                        <div style="font-weight: 600;">Order #<?php echo $order['queue_number']; ?></div>
                                        <div style="color: #5f8b74; font-size: 0.9rem;"><?php echo $order['restaurant_name']; ?>
                                        </div>
                                        <div style="color: #5f8b74; font-size: 0.8rem;">
                                            <?php echo date('M d, Y g:i A', strtotime($order['order_date'])); ?>
                                        </div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div class="order-status status-<?php echo $order['status']; ?>">
                                            <?php
                                            $statuses = ['P' => 'Pending', 'PR' => 'Preparing', 'R' => 'Ready', 'C' => 'Completed'];
                                            echo $statuses[$order['status']];
                                            ?>
                                        </div>
                                        <div style="font-size: 0.8rem; color: #8faa9a;"><?php echo $order['item_count']; ?>
                                            items</div>
                                    </div>
                                    <div style="font-weight: 700; color: #007a3e;">
                                        ₱<?php echo number_format($order['total_amount'], 2); ?>
                                    </div>
                                    <a href="index.php?page=orders" class="btn-secondary" style="padding: 8px 20px;">
                                        View Orders <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            <?php endwhile; ?>
                            <a href="<?php echo url('index.php?page=orders'); ?>" class="btn-secondary"
                                style="width: 100%; margin-top: 20px;">
                                View All Orders <i class="fas fa-arrow-right"></i>
                            </a>
                        <?php else: ?>
                            <p style="text-align: center; color: #3b7455; padding: 30px;">
                                No orders yet. <a href="index.php?page=customer#menu" style="color: #007a3e;">Start
                                    ordering!</a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Reviews Tab -->
                <div id="reviews-tab" class="tab-content">
                    <div class="profile-card">
                        <h3 style="margin-bottom: 20px; color: #16623b;">My Reviews</h3>
                        <?php if ($reviews->num_rows > 0): ?>
                            <?php while ($review = $reviews->fetch_assoc()): ?>
                                <div class="order-history-item">
                                    <div>
                                        <div style="font-weight: 600;">
                                            <?php echo htmlspecialchars($review['restaurant_name']); ?>
                                        </div>
                                        <div style="color: #5f8b74; font-size: 0.9rem;">
                                            <?php echo date('M d, Y', strtotime($review['timestamp'])); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star"
                                                style="color: <?php echo $i <= $review['rating'] ? '#eab308' : '#d0eadb'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <div style="flex: 1; min-width: 200px;">
                                        <?php echo htmlspecialchars($review['review']); ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: #3b7455; padding: 30px;">
                                You haven't written any reviews yet.
                            </p>
                        <?php endif; ?>

                        <?php if ($pendingReviews > 0): ?>
                            <a href="<?php echo url('index.php?page=reviews'); ?>" class="btn-primary"
                                style="width: 100%; margin-top: 20px; display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none;">
                                <i class="fas fa-star"></i>
                                Write a Review (<?php echo $pendingReviews; ?> pending)
                            </a>
                        <?php else: ?>
                            <a href="<?php echo url('index.php?page=reviews'); ?>" class="btn-secondary"
                                style="width: 100%; margin-top: 20px; text-decoration: none; display: block; text-align: center;">
                                View All Reviews <i class="fas fa-arrow-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Security Tab -->
                <div id="security-tab" class="tab-content">
                    <div class="profile-card">
                        <h3 style="margin-bottom: 20px; color: #16623b;">Change Password</h3>
                        <form method="POST" action="index.php?page=profile">
                            <div class="form-group">
                                <label>Current Password</label>
                                <input type="password" name="current_password" required>
                            </div>
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" name="new_password" required>
                                <small style="color: #8faa9a;">Min. 8 chars with uppercase, lowercase, and
                                    number</small>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" required>
                            </div>
                            <button type="submit" name="change_password" class="btn-primary">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <footer class="footer-note">
            <i class="fas fa-user-circle"></i> My Profile · Manage Account · View Orders
        </footer>
    </div>

    <script>
        function showTab(tab, el) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tab + '-tab').classList.add('active');

            // Update active button
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            el.classList.add('active');
        }
    </script>
</body>

</html>