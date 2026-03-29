<?php
require_once __DIR__ . '/config/config.php';
define('MAINTENANCE_MODE', false);

// Allow sysadmin access during maintenance mode
$page = isset($_GET['page']) ? $_GET['page'] : 'customer';
$is_sysadmin_access = ($page === 'sysadmin');
$is_logged_in_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'A';

if (MAINTENANCE_MODE) {
    // If trying to access sysadmin during maintenance
    if ($is_sysadmin_access) {
        // If not logged in as admin, redirect to login
        if (!$is_logged_in_admin) {
            $_SESSION['redirect_after_login'] = 'index.php?page=sysadmin';
            header('Location: ' . url('index.php?page=login'));
            exit();
        }
        // If logged in as admin, continue to load sysadmin page (skip maintenance page)
    } elseif ($page === 'login') {
        // Allow login page to load during maintenance (needed for sysadmin access)
        // falls through to normal routing below
    } else {
        // For all other pages, show maintenance page
        include __DIR__ . '/frontend/maintenance.php';
        exit();
    }
}

/**
 * index.php — Main Application Router
 *
 * This file serves as the central entry point for the UniCanteen application.
 * It implements a Front Controller pattern, routing traffic to the correct
 * frontend controllers based on the '?page=' query parameter.
 *
 * It also manages global maintenance mode state and renders the
 * persistent left-hand navigation sidebar (Sysadmin / Customer / Vendor).
 */

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniCanteen · Centralized Campus Food Ordering</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
        body {
            display: flex;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        .left-switcher {
            width: 108px;
            background: white;
            border-right: 1px solid #cae3d6;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 40px;
            gap: 24px;
            box-shadow: 4px 0 16px rgba(0, 80, 20, 0.08);
            z-index: 10;
            position: fixed;
            height: 100vh;
        }

        .switch-btn {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            text-orientation: mixed;
            background: transparent;
            border: none;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 2px;
            padding: 16px 6px;
            border-radius: 36px;
            color: #3d7259;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .switch-btn i {
            writing-mode: horizontal-tb;
            transform: rotate(180deg);
            font-size: 1.4rem;
        }

        .switch-btn.active-view {
            background: #007a3e;
            color: white;
        }

        .main-content {
            flex: 1;
            overflow-y: auto;
            margin-left: 108px;
            background: #f0f7f0;
        }

        /* Reset nested .main-content divs created by included pages */
        .main-content .main-content {
            margin-left: 0;
            overflow-y: visible;
        }
    </style>
</head>

<body>
    <div class="left-switcher">
        <a href="<?php echo url('index.php?page=customer'); ?>" class="switch-btn <?php echo ($page == 'customer') ? 'active-view' : ''; ?>">
            <i class="fas fa-user-graduate"></i><span>CUSTOMER</span>
        </a>
        <a href="<?php echo url('index.php?page=vendor'); ?>" class="switch-btn <?php echo ($page == 'vendor') ? 'active-view' : ''; ?>">
            <i class="fas fa-store"></i><span>VENDOR</span>
        </a>
        <a href="<?php echo url('index.php?page=sysadmin'); ?>" class="switch-btn <?php echo ($page == 'sysadmin') ? 'active-view' : ''; ?>">
            <i class="fas fa-user-tie"></i><span>SYSADMIN</span>
        </a>
    </div>

    <div class="main-content">
        <?php
        switch ($page) {
            case 'customer':
                include 'frontend/customer.php';
                break;
            case 'login':
                include 'frontend/login.php';
                break;
            case 'register':
                include 'frontend/register.php';
                break;
            case 'forgot-password':
                include 'frontend/forgot-password.php';
                break;
            case 'favorites':
                include 'frontend/favorites.php';
                break;
            case 'reorder':
                include 'frontend/reorder.php';
                break;                
            case 'cart':
                include 'frontend/cart.php';
                break;
            case 'profile':
                include 'frontend/profile.php';
                break;
            case 'reviews':
                include 'frontend/reviews.php';
                break;
            case 'orders':
                include 'frontend/orders.php';
                break;
            case 'restaurant':
                include 'frontend/restaurant.php';
                break;
            case 'order-details':
                include 'frontend/order-details.php';
                break;
            case 'logout':
                include 'frontend/logout-handler.php';
                break;
            case 'vendor':
                if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'V') {
                    include 'frontend/vendor.php';
                } else {
                    header('Location: ' . url('index.php?page=login&error=unauthorized'));
                    exit();
                }
                break;
            case 'sysadmin':
                if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'A') {
                    include 'frontend/sysadmin.php';
                } else {
                    header('Location: ' . url('index.php?page=login&error=unauthorized'));
                    exit();
                }
                break;
            default:
                include 'frontend/customer.php';
        }
        ?>
    </div>
    <?php
    // Flush output buffer at the end
    ob_end_flush();
    ?>

    <!-- Global Double-Submit Prevention -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        document.addEventListener('submit', function(e) {
            if (e.target && e.target.tagName === 'FORM') {
                const btn = e.submitter || e.target.querySelector('button[type="submit"], input[type="submit"]');
                if (btn && !btn.disabled) {
                    // Slight delay allows HTML5 validation to pass and normal submit to proceed
                    setTimeout(() => {
                        // If another script called e.preventDefault() (e.g. AJAX), don't auto-disable here
                        if (e.defaultPrevented) return;
                        
                        btn.disabled = true;
                        if (btn.tagName === 'BUTTON') {
                            if (!btn.dataset.originalHtml) {
                                btn.dataset.originalHtml = btn.innerHTML;
                            }
                            // Replace text while preserving size roughly, or just simple spinner
                            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                        } else if (btn.tagName === 'INPUT') {
                            btn.value = 'Processing...';
                        }
                    }, 10);
                }
            }
        });
    });
    </script>
</body>

</html>