<?php
session_start();
require_once __DIR__ . '/config/config.php';

//this is a simple router/controller pattern where we include different frontend PHP files based on the 'page' query parameter. Each frontend file is responsible for rendering a specific view (customer, vendor, sysadmin) and handling its own logic. The left sidebar allows switching between these views, and the main content area displays the selected view's content.
// DEV HACK – always act as vendor #3
if (isset($_GET['dev']) && $_GET['dev'] === 'vendor') {
    $_SESSION['user_id']   = 3;
    $_SESSION['user_role'] = 'V';        // matches the checks below
    $_SESSION['user_name'] = 'Test Vendor';
} // this is temporary to allow quick access during development, but should be protected by proper authentication checks in production

// Route logic
$page = isset($_GET['page']) ? $_GET['page'] : 'customer';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniCanteen · Centralized Campus Food Ordering</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/styles.css">
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
    </style>
</head>

<body>
    <div class="left-switcher">
        <a href="index.php?page=customer" class="switch-btn <?php echo ($page == 'customer') ? 'active-view' : ''; ?>">
            <i class="fas fa-user-graduate"></i><span>CUSTOMER</span>
        </a>
        <a href="index.php?page=vendor" class="switch-btn <?php echo ($page == 'vendor') ? 'active-view' : ''; ?>">
            <i class="fas fa-store"></i><span>VENDOR</span>
        </a>
        <a href="index.php?page=sysadmin" class="switch-btn <?php echo ($page == 'sysadmin') ? 'active-view' : ''; ?>">
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
                break; // Add this line
            case 'vendor':
                // if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'V') {
                //     include 'frontend/vendor.php';
                // } else {
                //     header('Location: index.php?page=login&error=unauthorized');
                //     exit();
                // }
                include 'frontend/vendor.php'; // this is temporary to allow quick access during development, but should be protected by the above check in production
                break;
            case 'sysadmin':
                if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'A') {
                    include 'frontend/sysadmin.php';
                } else {
                    header('Location: index.php?page=login&error=unauthorized');
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
</body>

</html>