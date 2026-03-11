<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth_check.php';

requireLogin();

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];

// Get Cart Items Count
$cart_items_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_items_count += $item['quantity'];
    }
}

// Fetch Favorites with Item and Restaurant info
$favQuery = "SELECT 
    f.item_id,
    i.*,
    r.name as restaurant_name,
    r.is_open as restaurant_open,
    r.ID as restaurant_ID
FROM Favorites f
JOIN Items i ON f.item_id = i.ID
JOIN Restaurants r ON i.restaurant_ID = r.ID
WHERE f.user_id = ?
ORDER BY f.created_at DESC";

$stmt = $db->prepare($favQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$favoritesResult = $stmt->get_result();
$favorites = $favoritesResult->fetch_all(MYSQLI_ASSOC);

// Populate user favorites array for the JS check
$userFavorites = array_column($favorites, 'item_id');

// Flash messages from add-to-cart redirect
$flash_added = isset($_GET['added']) ? urldecode($_GET['added']) : '';
$flash_error = isset($_GET['error']) ? urldecode($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Favorites · UniCanteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
        body { background: #f0f7f2; }
        .wrapper { max-width: 1200px; margin: 0 auto; padding: 0 36px; }
        .cart-count { background: white; color: #007a3e; border-radius: 50%; padding: 2px 6px; font-size: 0.7rem; margin-left: 5px; }
        
        /* ── Toasts ── */
        .toast-fixed {
            position: fixed; bottom: 28px; right: 28px; z-index: 9999;
            min-width: 260px; max-width: 380px; padding: 14px 20px;
            border-radius: 16px; font-weight: 600; font-size: 0.9rem;
            display: flex; align-items: center; gap: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
            animation: toastSlide 0.3s ease;
        }
        .toast-fixed.success { background: #dcfce7; color: #166534; border: 1.5px solid #bbf7d0; }
        .toast-fixed.error { background: #fef2f2; color: #991b1b; border: 1.5px solid #fecaca; }
        @keyframes toastSlide { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        /* ── Page Header ── */
        .page-header { background: #fff; padding: 40px 0; border-bottom: 1px solid #e0f0e8; margin-bottom: 40px; }
        .page-title { font-size: 2.2rem; font-weight: 700; color: #0f3d24; margin: 0 0 8px; display: flex; align-items: center; gap: 12px; }
        .page-subtitle { color: #5f8b74; font-size: 1.05rem; margin: 0; }
        
        /* ── Grid ── */
        .menu-grid-2col {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 18px;
            margin-bottom: 48px;
        }

        .menu-card {
            background: #fff; border-radius: 20px; display: flex; flex-direction: column;
            overflow: hidden; border: 1.5px solid #e8f4ee; transition: all 0.2s; position: relative;
        }
        .menu-card:hover { box-shadow: 0 8px 24px rgba(0, 80, 20, 0.08); transform: translateY(-3px); }
        .menu-card.sold-out-card { opacity: 0.75; }

        .menu-card-img { width: 100%; height: 200px; object-fit: cover; border-bottom: 1px solid #e8f4ee; }
        .menu-card-icon { width: 100%; height: 200px; background: #f0faf4; display: flex; align-items: center; justify-content: center; color: #007a3e; font-size: 3.5rem; border-bottom: 1px solid #e8f4ee; }
        .menu-card.sold-out-card .menu-card-icon { background: #fef2f2; color: #b91c1c; }

        .menu-card-info-row { padding: 20px 22px; display: flex; align-items: center; gap: 16px; }
        .menu-card-body { flex: 1; min-width: 0; }
        
        .menu-card-stall { font-size: 0.75rem; color: #007a3e; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; display: flex; align-items: center; gap: 4px; }
        .menu-card-name { font-weight: 600; font-size: 1.05rem; color: #1a3d28; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .menu-card-desc { font-size: 0.85rem; color: #6b8f7a; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .menu-card-price { font-size: 1.1rem; font-weight: 700; color: #007a3e; margin-top: 8px; }

        .menu-card-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0; }
        
        .btn-add { display: inline-flex; align-items: center; gap: 7px; background: #007a3e; color: #fff; padding: 8px 18px; border-radius: 30px; font-weight: 600; font-size: 0.85rem; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0,122,62,0.2); }
        .btn-add:hover { background: #005a2c; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,122,62,0.3); }
        .btn-sold { display: inline-flex; align-items: center; gap: 7px; background: #fef2f2; color: #b91c1c; padding: 8px 16px; border-radius: 30px; font-weight: 600; font-size: 0.85rem; border: 1.5px solid #fecaca; }

        .avail-tag-small { font-size: 0.75rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; }
        .avail-tag-small.yes { background: #dcfce7; color: #15803d; }
        .avail-tag-small.no { background: #fef2f2; color: #b91c1c; }

        /* ── Empty State ── */
        .empty-favorites {
            background: #fff;
            border-radius: 24px;
            padding: 80px 40px;
            text-align: center;
            border: 1.5px dashed #c8e6d4;
            max-width: 600px;
            margin: 40px auto;
        }
        .empty-favorites i { font-size: 4rem; color: #cae3d6; margin-bottom: 24px; display: block; }
        .empty-favorites h3 { font-size: 1.6rem; color: #0f3d24; margin: 0 0 12px; }
        .empty-favorites p { color: #5f8b74; font-size: 1.05rem; margin: 0 0 32px; line-height: 1.6; }
        .btn-browse { display: inline-flex; align-items: center; gap: 10px; background: #007a3e; color: white; padding: 14px 28px; border-radius: 40px; font-weight: 600; text-decoration: none; font-size: 1rem; transition: all 0.2s; }
        .btn-browse:hover { background: #005a2c; transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,122,62,0.25); }

    </style>
</head>

<body>
    <script>
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
                        <a href="index.php?page=favorites" style="font-weight: 700; color: #007a3e;">Favorites</a>
                        <a href="index.php?page=reviews">Reviews</a>
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <a href="index.php?page=profile"><?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'] ?? 'Profile')[0]); ?></a>
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
                    <a href="index.php?page=cart" style="margin-left:auto; color:inherit; font-weight:700; text-decoration:underline;">View Cart</a>
                </div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="toast-fixed error" id="errToast">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($flash_error); ?>
                </div>
            <?php endif; ?>

            <div class="page-header">
                <div class="wrapper">
                    <h1 class="page-title"><i class="fas fa-heart" style="color:#ef4444;"></i> My Favorites</h1>
                    <p class="page-subtitle">Your saved items from all stalls.</p>
                </div>
            </div>

            <div class="wrapper">
                <?php if (empty($favorites)): ?>
                    <div class="empty-favorites">
                        <i class="fas fa-heart-crack"></i>
                        <h3>No favorites yet</h3>
                        <p>You haven't saved any items yet. Browse stall menus and tap the heart icon to save your go-to meals for quick reordering.</p>
                        <a href="index.php?page=customer#menu" class="btn-browse">
                            <i class="fas fa-utensils"></i> Browse Menus
                        </a>
                    </div>
                <?php else: ?>
                    <div class="menu-grid-2col">
                        <?php foreach ($favorites as $item): 
                            $isAvailable = ($item['isAvailable'] == 1 && $item['restaurant_open'] == 1);
                            $isFav = in_array($item['ID'], $userFavorites);
                        ?>
                            <div class="menu-card <?php echo !$isAvailable ? 'sold-out-card' : ''; ?>" id="fav-card-<?php echo $item['ID']; ?>">
                                <!-- Favorite Heart Toggle -->
                                <button class="fav-btn" data-item-id="<?php echo $item['ID']; ?>" 
                                        style="position:absolute; top:12px; right:12px; z-index:10; background:rgba(255,255,255,0.95); border:none; border-radius:50%; width:40px; height:40px; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 4px 12px rgba(0,0,0,0.15); color: <?php echo $isFav ? '#dc2626' : '#9ca3af'; ?>; transition: transform 0.2s, color 0.2s; font-size:1.3rem;">
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
                                        <div class="menu-card-stall">
                                            <i class="fas fa-store"></i> <?php echo htmlspecialchars($item['restaurant_name']); ?>
                                        </div>
                                        <div class="menu-card-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                        <?php if ($item['description']): ?>
                                            <div class="menu-card-desc"><?php echo htmlspecialchars($item['description']); ?></div>
                                        <?php endif; ?>
                                        <div class="menu-card-price">₱<?php echo number_format($item['price'], 0); ?></div>
                                    </div>
                                    <div class="menu-card-actions">
                                        <span class="avail-tag-small <?php echo $isAvailable ? 'yes' : 'no'; ?>">
                                            <?php echo $isAvailable ? 'Available' : ($item['restaurant_open'] ? 'Sold Out' : 'Stall Closed'); ?>
                                        </span>
                                        <?php if ($isAvailable): ?>
                                            <a href="index.php?page=cart&add=<?php echo $item['ID']; ?>&restaurant_id=<?php echo $item['restaurant_ID']; ?>&return_to=favorites"
                                                class="btn-add">
                                                <i class="fas fa-plus"></i> Add
                                            </a>
                                        <?php else: ?>
                                            <span class="btn-sold">
                                                <i class="fas fa-times"></i> Unavail.
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <footer class="footer-note">
                <i class="fas fa-heart"></i> UniCanteen · Manage your favorite meals
            </footer>
        </section>
    </div>

    <script>
        // Auto-dismiss toasts
        ['addToast', 'errToast'].forEach(id => {
            const el = document.getElementById(id);
            if (el) setTimeout(() => { el.style.transition = 'opacity 0.5s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 3000);
        });

        // Toggle Favorite (Remove from list animation)
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.fav-btn');
            if (!btn) return;
            
            e.preventDefault();
            e.stopPropagation();

            const itemId = btn.dataset.itemId;
            const card = document.getElementById('fav-card-' + itemId);
            const icon = btn.querySelector('i');
            
            // Optimistically "remove" state
            icon.classList.remove('fas');
            icon.classList.add('far');
            btn.style.color = '#9ca3af';

            const formData = new FormData();
            formData.append('item_id', itemId);

            fetch('frontend/toggle_favorite.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.action === 'removed') {
                    // Animate disappearance
                    card.style.transition = 'all 0.3s ease';
                    card.style.transform = 'scale(0.9)';
                    card.style.opacity = '0';
                    setTimeout(() => {
                        card.remove();
                        // Show empty state if everything is gone
                        if(document.querySelectorAll('.menu-card').length === 0) {
                            window.location.reload();
                        }
                    }, 300);
                } else if (!data.success) {
                    console.error('Failed to toggle favorite:', data.message);
                    // Revert 
                    icon.classList.add('fas');
                    icon.classList.remove('far');
                    btn.style.color = '#dc2626';
                }
            })
            .catch(error => console.error('Error:', error));
        });
    </script>
</body>
</html>
