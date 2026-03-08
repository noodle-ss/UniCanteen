<?php
// include mo ung sa index.php — config + session already loaded

$db = Database::getInstance()->getConnection();

// HANDLE POST ACTIONS (create vendor, ban/unban)

$action_message = '';
$action_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {

    // ── Create Vendor Account ──
    if ($_POST['admin_action'] === 'create_vendor') {
        $v_name     = sanitizeInput($_POST['vendor_name'] ?? '');
        $v_email    = sanitizeInput($_POST['vendor_email'] ?? '');
        $v_password = $_POST['vendor_password'] ?? '';
        $r_name     = sanitizeInput($_POST['restaurant_name'] ?? '');
        $r_address  = sanitizeInput($_POST['restaurant_address'] ?? '');
        $r_desc     = sanitizeInput($_POST['restaurant_description'] ?? '');

        $errors = [];
        if (empty($v_name))     $errors[] = 'Vendor name is required.';
        if (empty($v_email))    $errors[] = 'Email is required.';
        if (empty($v_password)) $errors[] = 'Password is required.';
        if (strlen($v_password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if (empty($r_name))     $errors[] = 'Restaurant name is required.';

        // Check duplicate email
        if (empty($errors)) {
            $chk = $db->prepare("SELECT ID FROM Users WHERE email = ?");
            $chk->bind_param("s", $v_email);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $errors[] = 'Email is already registered.';
            }
        }

        if (empty($errors)) {
            $db->begin_transaction();
            try {
                $hashed = password_hash($v_password, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
                $role = 'V';
                $sq = 'What is your favorite book?';
                $sa = password_hash('vendor', PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);

                $ins = $db->prepare(
                    "INSERT INTO Users (email, password, full_name, role, is_active, login_attempts, security_question, security_answer)
                     VALUES (?, ?, ?, ?, TRUE, 0, ?, ?)"
                );
                $ins->bind_param("ssssss", $v_email, $hashed, $v_name, $role, $sq, $sa);
                $ins->execute();
                $vendor_id = $db->insert_id;

                $ins2 = $db->prepare(
                    "INSERT INTO Restaurants (name, address, description, owner_ID, is_open)
                     VALUES (?, ?, ?, ?, TRUE)"
                );
                $ins2->bind_param("sssi", $r_name, $r_address, $r_desc, $vendor_id);
                $ins2->execute();

                // Log the action
                $admin_id = $_SESSION['user_id'];
                $log = $db->prepare("INSERT INTO UserLogs (user_id, action, ip_address, user_agent) VALUES (?, 'ADMIN_CREATE_VENDOR', ?, ?)");
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $log->bind_param("iss", $admin_id, $ip, $ua);
                $log->execute();

                $db->commit();
                $action_message = "Vendor account created successfully! ({$v_name} — {$r_name})";
                $action_type = 'success';
            } catch (Exception $e) {
                $db->rollback();
                $action_message = 'Failed to create vendor: ' . $e->getMessage();
                $action_type = 'error';
            }
        } else {
            $action_message = implode(' ', $errors);
            $action_type = 'error';
        }
    }

    //  Ban User
    if ($_POST['admin_action'] === 'ban_user') {
        $target_id = intval($_POST['user_id'] ?? 0);
        if ($target_id && $target_id !== intval($_SESSION['user_id'])) {
            $stmt = $db->prepare("UPDATE Users SET is_banned = TRUE, is_active = FALSE WHERE ID = ?");
            $stmt->bind_param("i", $target_id);
            if ($stmt->execute()) {
                // Delete active sessions for banned user
                $del = $db->prepare("DELETE FROM Sessions WHERE user_id = ?");
                $del->bind_param("i", $target_id);
                $del->execute();

                $admin_id = $_SESSION['user_id'];
                $log = $db->prepare("INSERT INTO UserLogs (user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
                $action_str = "ADMIN_BAN_USER_" . $target_id;
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $log->bind_param("isss", $admin_id, $action_str, $ip, $ua);
                $log->execute();

                $action_message = 'User has been banned successfully.';
                $action_type = 'success';
            }
        } else {
            $action_message = 'Invalid user or you cannot ban yourself.';
            $action_type = 'error';
        }
    }

    //  Unban User (Whitelist) 
    if ($_POST['admin_action'] === 'unban_user') {
        $target_id = intval($_POST['user_id'] ?? 0);
        if ($target_id) {
            $stmt = $db->prepare("UPDATE Users SET is_banned = FALSE, is_active = TRUE, login_attempts = 0, locked_until = NULL WHERE ID = ?");
            $stmt->bind_param("i", $target_id);
            if ($stmt->execute()) {
                $admin_id = $_SESSION['user_id'];
                $log = $db->prepare("INSERT INTO UserLogs (user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
                $action_str = "ADMIN_UNBAN_USER_" . $target_id;
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $log->bind_param("isss", $admin_id, $action_str, $ip, $ua);
                $log->execute();

                $action_message = 'User has been unbanned and whitelisted.';
                $action_type = 'success';
            }
        }
    }

    //  Deactivate User (Blacklist) 
    if ($_POST['admin_action'] === 'blacklist_user') {
        $target_id = intval($_POST['user_id'] ?? 0);
        if ($target_id && $target_id !== intval($_SESSION['user_id'])) {
            $stmt = $db->prepare("UPDATE Users SET is_active = FALSE WHERE ID = ?");
            $stmt->bind_param("i", $target_id);
            if ($stmt->execute()) {
                $del = $db->prepare("DELETE FROM Sessions WHERE user_id = ?");
                $del->bind_param("i", $target_id);
                $del->execute();

                $admin_id = $_SESSION['user_id'];
                $log = $db->prepare("INSERT INTO UserLogs (user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
                $action_str = "ADMIN_BLACKLIST_USER_" . $target_id;
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $log->bind_param("isss", $admin_id, $action_str, $ip, $ua);
                $log->execute();

                $action_message = 'User has been blacklisted (deactivated).';
                $action_type = 'success';
            }
        }
    }

    //  Delete Review / Rating 
    if ($_POST['admin_action'] === 'delete_review') {
        $rating_id = intval($_POST['rating_id'] ?? 0);
        if ($rating_id) {
            $stmt = $db->prepare("DELETE FROM Ratings WHERE ID = ?");
            $stmt->bind_param("i", $rating_id);
            if ($stmt->execute()) {
                $admin_id = $_SESSION['user_id'];
                $log = $db->prepare("INSERT INTO UserLogs (user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
                $action_str = "ADMIN_DELETE_REVIEW_" . $rating_id;
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $log->bind_param("isss", $admin_id, $action_str, $ip, $ua);
                $log->execute();
                $action_message = 'Review deleted successfully.';
                $action_type = 'success';
            } else {
                $action_message = 'Failed to delete review.';
                $action_type = 'error';
            }
        }
    }

    //  Toggle Restaurant Status 
    if ($_POST['admin_action'] === 'toggle_restaurant') {
        $rest_id = intval($_POST['restaurant_id'] ?? 0);
        $new_status = intval($_POST['new_status'] ?? 0);
        if ($rest_id) {
            $stmt = $db->prepare("UPDATE Restaurants SET is_open = ? WHERE ID = ?");
            $stmt->bind_param("ii", $new_status, $rest_id);
            $stmt->execute();
            $action_message = $new_status ? 'Restaurant enabled.' : 'Restaurant disabled.';
            $action_type = 'success';
        }
    }

    //  Toggle Maintenance Mode 
    if ($_POST['admin_action'] === 'toggle_maintenance') {
        $index_path = dirname(__DIR__) . '/index.php';
        if (file_exists($index_path)) {
            $contents = file_get_contents($index_path);
            if ($contents !== false) {
                // Detect current state and flip it
                if (preg_match("/define\('MAINTENANCE_MODE',\s*(true|false)\)/", $contents, $matches)) {
                    $current = $matches[1];
                    $new_val = ($current === 'true') ? 'false' : 'true';
                    $new_contents = preg_replace(
                        "/define\('MAINTENANCE_MODE',\s*(true|false)\)/",
                        "define('MAINTENANCE_MODE', {$new_val})",
                        $contents
                    );
                    if (file_put_contents($index_path, $new_contents) !== false) {
                        $admin_id = $_SESSION['user_id'];
                        $log = $db->prepare("INSERT INTO UserLogs (user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
                        $action_str = "ADMIN_MAINTENANCE_MODE_" . strtoupper($new_val);
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $log->bind_param("isss", $admin_id, $action_str, $ip, $ua);
                        $log->execute();
                        $action_message = 'Maintenance mode ' . ($new_val === 'true' ? 'ENABLED' : 'DISABLED') . ' successfully.';
                        $action_type = 'success';
                    } else {
                        $action_message = 'Failed to write to index.php. Check file permissions.';
                        $action_type = 'error';
                    }
                } else {
                    $action_message = 'Could not find MAINTENANCE_MODE constant in index.php.';
                    $action_type = 'error';
                }
            } else {
                $action_message = 'Failed to read index.php.';
                $action_type = 'error';
            }
        } else {
            $action_message = 'index.php not found at expected path.';
            $action_type = 'error';
        }
    }
}

// FETCH DATA FOR DASHBOARD

// Dashboard stats
$total_users    = $db->query("SELECT COUNT(*) FROM Users WHERE role = 'U'")->fetch_row()[0];
$total_vendors  = $db->query("SELECT COUNT(*) FROM Users WHERE role = 'V'")->fetch_row()[0];
$total_orders   = $db->query("SELECT COUNT(*) FROM Orders")->fetch_row()[0];
$banned_users   = $db->query("SELECT COUNT(*) FROM Users WHERE is_banned = TRUE")->fetch_row()[0];
$active_stalls  = $db->query("SELECT COUNT(*) FROM Restaurants WHERE is_open = TRUE")->fetch_row()[0];
$today_revenue  = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM Orders WHERE status='C' AND DATE(order_date) = CURDATE()")->fetch_row()[0];
$today_orders   = $db->query("SELECT COUNT(*) FROM Orders WHERE DATE(order_date) = CURDATE()")->fetch_row()[0];

// Vendors + their restaurants
$vendors_query = "SELECT u.ID, u.full_name, u.email, u.is_active, u.is_banned, u.created_at, u.last_login,
                         r.ID as restaurant_id, r.name as restaurant_name, r.address, r.is_open,
                         (SELECT COUNT(*) FROM Items WHERE restaurant_ID = r.ID) as item_count,
                         (SELECT COUNT(*) FROM Orders WHERE restaurant_ID = r.ID) as order_count,
                         (SELECT COALESCE(SUM(total_amount), 0) FROM Orders WHERE restaurant_ID = r.ID AND status='C') as revenue
                  FROM Users u
                  LEFT JOIN Restaurants r ON u.ID = r.owner_ID
                  WHERE u.role = 'V'
                  ORDER BY u.created_at DESC";
$vendors = $db->query($vendors_query)->fetch_all(MYSQLI_ASSOC);

// All customers/staff users
$users_query = "SELECT u.ID, u.full_name, u.email, u.role, u.is_active, u.is_banned, u.created_at, u.last_login, u.login_attempts,
                       (SELECT COUNT(*) FROM Orders WHERE customer_ID = u.ID) as order_count,
                       (SELECT COALESCE(SUM(total_amount), 0) FROM Orders WHERE customer_ID = u.ID) as total_spent
                FROM Users u
                WHERE u.role = 'U'
                ORDER BY u.created_at DESC";
$users = $db->query($users_query)->fetch_all(MYSQLI_ASSOC);

// Banned users list
$banned_query = "SELECT u.ID, u.full_name, u.email, u.role, u.is_active, u.is_banned, u.created_at
                 FROM Users u
                 WHERE u.is_banned = TRUE
                 ORDER BY u.full_name";
$banned_list = $db->query($banned_query)->fetch_all(MYSQLI_ASSOC);

// Blacklisted (inactive but not banned) users
$blacklisted_query = "SELECT u.ID, u.full_name, u.email, u.role, u.is_active, u.is_banned, u.created_at
                      FROM Users u
                      WHERE u.is_active = FALSE AND u.is_banned = FALSE
                      ORDER BY u.full_name";
$blacklisted_list = $db->query($blacklisted_query)->fetch_all(MYSQLI_ASSOC);

// Recent admin activity logs
$logs_query = "SELECT ul.*, u.full_name
               FROM UserLogs ul
               LEFT JOIN Users u ON ul.user_id = u.ID
               WHERE ul.action LIKE 'ADMIN%'
               ORDER BY ul.timestamp DESC
               LIMIT 10";
$admin_logs = $db->query($logs_query)->fetch_all(MYSQLI_ASSOC);

// All restaurants for review filter dropdown
$restaurants_list = $db->query("SELECT ID, name FROM Restaurants ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Read current maintenance mode from index.php
$maintenance_mode_current = false;
$index_path = dirname(__DIR__) . '/index.php';
if (file_exists($index_path)) {
    $index_contents = file_get_contents($index_path);
    if ($index_contents !== false && preg_match("/define\('MAINTENANCE_MODE',\s*(true|false)\)/", $index_contents, $mm_matches)) {
        $maintenance_mode_current = ($mm_matches[1] === 'true');
    }
}

// Reviews — filter by restaurant if selected
$filter_restaurant_id = intval($_GET['review_restaurant'] ?? 0);
$reviews_query = "SELECT r.ID, r.rating, r.review, r.timestamp,
                         res.name AS restaurant_name, res.ID AS restaurant_id,
                         o.ID AS order_id
                  FROM Ratings r
                  LEFT JOIN Restaurants res ON r.restaurant_ID = res.ID
                  LEFT JOIN Orders o ON r.order_ID = o.ID"
                 . ($filter_restaurant_id ? " WHERE r.restaurant_ID = $filter_restaurant_id" : "")
                 . " ORDER BY r.timestamp DESC";
$reviews = $db->query($reviews_query)->fetch_all(MYSQLI_ASSOC);
$total_reviews = count($reviews);
?>
<!DOCTYPE html>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
<style>
    .wrapper { max-width: 1300px; margin: 0 auto; padding: 0 36px; }

    /* Admin Hero */
    .admin-hero {
      background: linear-gradient(135deg, #1a4d31 0%, #0d2e1d 100%);
      padding: 28px 0 32px;
      color: white;
    }
    .admin-hero h1 { font-size: 1.9rem; font-weight: 700; margin: 0 0 6px; }
    .admin-hero p { opacity: 0.85; font-size: 0.95rem; margin: 0; }
    .admin-hero-inner {
      display: flex; justify-content: space-between; align-items: center;
      flex-wrap: wrap; gap: 16px;
    }
    .admin-hero-actions { display: flex; gap: 12px; align-items: center; }
    .btn-admin-action {
      display: inline-flex; align-items: center; gap: 8px;
      background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.4);
      color: white; padding: 10px 22px; border-radius: 40px;
      font-weight: 600; font-size: 0.9rem; text-decoration: none;
      transition: all 0.2s; cursor: pointer; font-family: 'Inter', sans-serif;
      backdrop-filter: blur(4px);
    }
    .btn-admin-action:hover {
      background: rgba(255,255,255,0.28); border-color: white; transform: translateY(-1px);
    }

    /* Stats bar */
    .admin-stats-bar {
      background: white; border-radius: 60px; padding: 16px 28px;
      margin: -22px 0 32px; display: flex; justify-content: space-between;
      align-items: center; border: 1px solid var(--border-soft);
      box-shadow: 0 8px 20px rgba(0,70,30,0.06); flex-wrap: wrap; gap: 10px;
    }
    .astat { display: flex; align-items: center; gap: 8px; }
    .astat-num { font-weight: 700; font-size: 1.3rem; color: var(--dlsu-green); }
    .astat-lbl { color: #3b7455; font-size: 0.85rem; }
    .adivider { width: 1px; height: 28px; background: #d0eadb; }

    /* Tab navigation */
    .admin-tabs {
      display: flex; gap: 8px; margin-bottom: 28px; flex-wrap: wrap;
    }
    .admin-tab {
      padding: 10px 24px; border-radius: 40px; font-size: 0.9rem; font-weight: 600;
      cursor: pointer; transition: all 0.2s; background: white;
      border: 1.5px solid var(--border-soft); color: #3b7455;
      display: inline-flex; align-items: center; gap: 8px;
    }
    .admin-tab:hover { background: #e3f4ea; border-color: var(--dlsu-green); color: var(--dlsu-green); }
    .admin-tab.active {
      background: var(--dlsu-green); color: white; border-color: var(--dlsu-green);
      box-shadow: 0 4px 12px rgba(0,122,62,0.25);
    }
    .admin-tab .tab-count {
      background: rgba(255,255,255,0.25); padding: 2px 8px; border-radius: 20px; font-size: 0.75rem;
    }
    .admin-tab.active .tab-count { background: rgba(255,255,255,0.3); }

    /* Tab panels */
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    /* Data table styles */
    .data-table {
      width: 100%; border-collapse: separate; border-spacing: 0;
      background: white; border-radius: 20px; overflow: hidden;
      border: 1px solid var(--border-soft);
      box-shadow: 0 4px 12px rgba(0,70,30,0.04);
    }
    .data-table thead th {
      background: #f0f7f2; padding: 14px 18px; text-align: left;
      font-size: 0.8rem; font-weight: 700; color: #16623b;
      text-transform: uppercase; letter-spacing: 0.5px;
      border-bottom: 2px solid #cae3d6;
    }
    .data-table tbody td {
      padding: 14px 18px; border-bottom: 1px solid #e8f3ec;
      font-size: 0.9rem; color: #1e3a2f; vertical-align: middle;
    }
    .data-table tbody tr:last-child td { border-bottom: none; }
    .data-table tbody tr:hover { background: #f9fffc; }

    /* Status pills */
    .pill {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 4px 14px; border-radius: 30px; font-size: 0.78rem; font-weight: 700;
    }
    .pill-active { background: #daf1e2; color: #0b6d38; }
    .pill-banned { background: #fee9e9; color: #b13e3e; }
    .pill-inactive { background: #f5e6e6; color: #8c3333; }
    .pill-open { background: #daf1e2; color: #0b6d38; }
    .pill-closed { background: #fff1cf; color: #9e6d0b; }
    .pill-vendor { background: #e8e0ff; color: #5b3fb5; }
    .pill-customer { background: #e2f0e8; color: #1a5e36; }

    /* Action buttons */
    .btn-sm {
      padding: 6px 14px; border-radius: 30px; font-size: 0.78rem; font-weight: 600;
      border: none; cursor: pointer; transition: all 0.18s;
      display: inline-flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;
    }
    .btn-sm:hover { transform: translateY(-1px); }
    .btn-ban { background: #fee9e9; color: #b13e3e; }
    .btn-ban:hover { background: #f5c9c9; }
    .btn-unban { background: #daf1e2; color: #0b6d38; }
    .btn-unban:hover { background: #c2e8ce; }
    .btn-blacklist { background: #fff1cf; color: #9e6d0b; }
    .btn-blacklist:hover { background: #ffe8a8; }
    .btn-toggle { background: #e3f4ea; color: var(--dlsu-green); }
    .btn-toggle:hover { background: #c8e9d4; }

    /* Cards */
    .info-card {
      background: white; border-radius: 24px; padding: 24px;
      border: 1px solid var(--border-soft);
      box-shadow: 0 4px 12px rgba(0,70,30,0.04);
    }
    .info-card h3 {
      font-size: 1.2rem; color: #0f4a2f; margin: 0 0 16px;
      display: flex; align-items: center; gap: 10px;
    }
    .info-card h3 i { color: var(--dlsu-green); }

    /* Log entries */
    .log-entry {
      display: flex; justify-content: space-between; align-items: center;
      padding: 10px 0; border-bottom: 1px solid #e8f3ec;
      font-size: 0.88rem;
    }
    .log-entry:last-child { border-bottom: none; }
    .log-action { font-weight: 600; color: #1a4d31; }
    .log-time { color: #5f8b74; font-size: 0.8rem; }
    .log-user { color: var(--dlsu-green); font-weight: 500; }

    /* Modal styles (reusing from vendor) */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,30,12,0.45); backdrop-filter: blur(3px);
      align-items: center; justify-content: center; z-index: 9999;
    }
    .modal-card {
      background: white; border-radius: 28px; padding: 32px;
      width: 520px; max-width: 92%;
      box-shadow: 0 24px 60px rgba(0,60,20,0.18);
      border: 1px solid var(--border-soft);
      max-height: 90vh; overflow-y: auto;
    }
    .modal-card h3 {
      font-size: 1.3rem; color: #0f4a2f; margin: 0 0 24px;
      display: flex; align-items: center; gap: 10px;
    }
    .modal-card h3 i { color: var(--dlsu-green); }
    .modal-field { margin-bottom: 16px; }
    .modal-field label {
      display: block; font-size: 0.85rem; font-weight: 600;
      color: #1a4d31; margin-bottom: 6px;
    }
    .modal-field input, .modal-field select, .modal-field textarea {
      width: 100%; padding: 11px 16px; border: 1.5px solid #cae3d6;
      border-radius: 14px; font-family: 'Inter', sans-serif;
      font-size: 0.95rem; color: #1e3a2f; background: #f9fffc;
      outline: none; transition: border-color 0.2s, box-shadow 0.2s;
      box-sizing: border-box;
    }
    .modal-field input:focus, .modal-field select:focus, .modal-field textarea:focus {
      border-color: var(--dlsu-green);
      box-shadow: 0 0 0 3px rgba(0,122,62,0.1); background: white;
    }
    .modal-field textarea { resize: vertical; min-height: 80px; }
    .modal-actions {
      display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;
    }
    .btn-modal-cancel {
      background: #f0f7f2; color: #2d6347; border: none;
      padding: 11px 24px; border-radius: 40px; font-weight: 600;
      cursor: pointer; transition: background 0.18s; font-family: 'Inter', sans-serif;
    }
    .btn-modal-cancel:hover { background: #dceee4; }
    .btn-modal-submit {
      background: var(--dlsu-green); color: white; border: none;
      padding: 11px 28px; border-radius: 40px; font-weight: 600;
      cursor: pointer; transition: all 0.18s; font-family: 'Inter', sans-serif;
    }
    .btn-modal-submit:hover { background: var(--dlsu-darkgreen); transform: translateY(-1px); }

    /* Notification toast */
    .toast {
      position: fixed; top: 20px; right: 20px; padding: 16px 24px;
      border-radius: 16px; font-weight: 600; font-size: 0.95rem;
      z-index: 10000; animation: slideIn 0.3s ease-out;
      display: flex; align-items: center; gap: 10px;
    }
    .toast-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .toast-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    @keyframes slideIn {
      from { transform: translateX(400px); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
      from { transform: translateX(0); opacity: 1; }
      to { transform: translateX(400px); opacity: 0; }
    }

    /* Empty state */
    .empty-state {
      text-align: center; padding: 50px 20px; color: #5f8b74;
    }
    .empty-state i { font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 16px; }
    .empty-state p { font-size: 1rem; margin: 0; }

    /* Responsive */
    @media (max-width: 768px) {
      .data-table { display: block; overflow-x: auto; }
      .admin-stats-bar { flex-direction: column; align-items: flex-start; border-radius: 20px; }
      .adivider { display: none; }
      .sys-grid, .admin-grid { grid-template-columns: 1fr !important; }
    }
  </style>
<section id="sysadmin" class="page-section">

    <!-- ── Hero Banner ── -->
    <div class="admin-hero">
      <div class="wrapper">
        <div class="admin-hero-inner">
          <div>
            <h1><i class="fas fa-user-shield" style="font-size:1.5rem; opacity:0.85; margin-right:10px;"></i> System Administration</h1>
            <p>Manage vendors, oversee users, and maintain platform compliance.</p>
          </div>
          <div class="admin-hero-actions">
            <a href="index.php?page=customer" class="btn-admin-action">
              <i class="fas fa-arrow-left"></i> Back to Home
            </a>
            <a href="index.php?page=logout" class="btn-admin-action">
              <i class="fas fa-sign-out-alt"></i> Logout
            </a>
            <button onclick="openCreateVendorModal()" class="btn-admin-action">
              <i class="fas fa-plus"></i> Create Vendor
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="wrapper">

      <!-- ── Action Messages ── -->
      <?php if ($action_message): ?>
        <div class="<?php echo $action_type === 'success' ? 'success-message' : 'error-message'; ?>" style="margin-top:20px;">
          <i class="fas <?php echo $action_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
          <?php echo htmlspecialchars($action_message); ?>
        </div>
      <?php endif; ?>

      <!-- ── Stats Bar ── -->
      <div class="admin-stats-bar">
        <div class="astat">
          <span class="astat-num"><?php echo $total_users; ?></span>
          <span class="astat-lbl">customers</span>
        </div>
        <div class="adivider"></div>
        <div class="astat">
          <span class="astat-num"><?php echo $total_vendors; ?></span>
          <span class="astat-lbl">vendors</span>
        </div>
        <div class="adivider"></div>
        <div class="astat">
          <span class="astat-num"><?php echo $active_stalls; ?></span>
          <span class="astat-lbl">active stalls</span>
        </div>
        <div class="adivider"></div>
        <div class="astat">
          <span class="astat-num"><?php echo $today_orders; ?></span>
          <span class="astat-lbl">orders today</span>
        </div>
        <div class="adivider"></div>
        <div class="astat">
          <span class="astat-num" style="color:#b13e3e;"><?php echo $banned_users; ?></span>
          <span class="astat-lbl">banned</span>
        </div>
        <div class="adivider"></div>
        <div class="astat">
          <span class="astat-num"><?php echo formatPrice($today_revenue); ?></span>
          <span class="astat-lbl">revenue today</span>
        </div>
      </div>

      <!-- ── Tab Navigation ── -->
      <div class="admin-tabs">
        <div class="admin-tab active" onclick="switchTab('vendors', this)">
          <i class="fas fa-store"></i> Vendors / Stalls
          <span class="tab-count"><?php echo count($vendors); ?></span>
        </div>
        <div class="admin-tab" onclick="switchTab('users', this)">
          <i class="fas fa-users"></i> Customers
          <span class="tab-count"><?php echo count($users); ?></span>
        </div>
        <div class="admin-tab" onclick="switchTab('banned', this)">
          <i class="fas fa-ban"></i> Banned
          <span class="tab-count"><?php echo count($banned_list); ?></span>
        </div>
        <div class="admin-tab" onclick="switchTab('blacklist', this)">
          <i class="fas fa-user-slash"></i> Blacklisted
          <span class="tab-count"><?php echo count($blacklisted_list); ?></span>
        </div>
        <div class="admin-tab" onclick="switchTab('logs', this)">
          <i class="fas fa-file-invoice"></i> Activity Logs
        </div>
        <div class="admin-tab" onclick="switchTab('reviews', this)">
          <i class="fas fa-comments"></i> Comment Moderation
          <span class="tab-count"><?php echo $total_reviews; ?></span>
        </div>
        <div class="admin-tab" onclick="switchTab('maintenance', this)" style="<?php echo $maintenance_mode_current ? 'border-color:#b13e3e; color:#b13e3e;' : ''; ?>">
          <i class="fas fa-tools"></i> Maintenance
          <?php if ($maintenance_mode_current): ?>
            <span class="tab-count" style="background:#b13e3e; color:#fff;">ON</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- TAB 1: VENDORS / STALLS                     -->

      <div id="tab-vendors" class="tab-panel active">
        <div class="info-card" style="margin-bottom:30px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
            <h3 style="margin:0;"><i class="fas fa-truck"></i> Food Vendors / Stalls</h3>
            <button onclick="openCreateVendorModal()" class="btn-sm btn-toggle" style="padding:8px 20px;">
              <i class="fas fa-plus-circle"></i> Create Vendor Account
            </button>
          </div>

          <?php if (empty($vendors)): ?>
            <div class="empty-state">
              <i class="fas fa-store"></i>
              <p>No vendor accounts yet. Create the first one!</p>
            </div>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Vendor</th>
                  <th>Restaurant</th>
                  <th>Status</th>
                  <th>Items</th>
                  <th>Orders</th>
                  <th>Revenue</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($vendors as $v): ?>
                <tr>
                  <td>
                    <div style="font-weight:600; color:#0f4a2f;"><?php echo htmlspecialchars($v['full_name']); ?></div>
                    <div style="font-size:0.8rem; color:#5f8b74;"><?php echo htmlspecialchars($v['email']); ?></div>
                  </td>
                  <td>
                    <div style="font-weight:500;"><?php echo htmlspecialchars($v['restaurant_name'] ?? '—'); ?></div>
                    <div style="font-size:0.78rem; color:#5f8b74;"><?php echo htmlspecialchars($v['address'] ?? ''); ?></div>
                  </td>
                  <td>
                    <?php if ($v['is_banned']): ?>
                      <span class="pill pill-banned"><i class="fas fa-ban"></i> Banned</span>
                    <?php elseif (!$v['is_active']): ?>
                      <span class="pill pill-inactive"><i class="fas fa-times-circle"></i> Inactive</span>
                    <?php else: ?>
                      <span class="pill pill-active"><i class="fas fa-check-circle"></i> Active</span>
                    <?php endif; ?>
                    <?php if ($v['restaurant_id']): ?>
                      <br><span class="pill <?php echo $v['is_open'] ? 'pill-open' : 'pill-closed'; ?>" style="margin-top:4px;">
                        <i class="fas <?php echo $v['is_open'] ? 'fa-door-open' : 'fa-door-closed'; ?>"></i>
                        <?php echo $v['is_open'] ? 'Open' : 'Closed'; ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td style="font-weight:600; color:var(--dlsu-green);"><?php echo $v['item_count']; ?></td>
                  <td><?php echo $v['order_count']; ?></td>
                  <td style="font-weight:600; color:var(--dlsu-green);"><?php echo formatPrice($v['revenue']); ?></td>
                  <td>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                      <?php if ($v['is_banned']): ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="admin_action" value="unban_user">
                          <input type="hidden" name="user_id" value="<?php echo $v['ID']; ?>">
                          <button type="submit" class="btn-sm btn-unban" onclick="return confirm('Unban this vendor?')">
                            <i class="fas fa-unlock"></i> Unban
                          </button>
                        </form>
                      <?php else: ?>
                        <?php if ($v['restaurant_id']): ?>
                          <form method="POST" style="display:inline;">
                            <input type="hidden" name="admin_action" value="toggle_restaurant">
                            <input type="hidden" name="restaurant_id" value="<?php echo $v['restaurant_id']; ?>">
                            <input type="hidden" name="new_status" value="<?php echo $v['is_open'] ? 0 : 1; ?>">
                            <button type="submit" class="btn-sm btn-toggle">
                              <i class="fas <?php echo $v['is_open'] ? 'fa-pause' : 'fa-play'; ?>"></i>
                              <?php echo $v['is_open'] ? 'Disable' : 'Enable'; ?>
                            </button>
                          </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="admin_action" value="ban_user">
                          <input type="hidden" name="user_id" value="<?php echo $v['ID']; ?>">
                          <button type="submit" class="btn-sm btn-ban" onclick="return confirm('Ban this vendor? They will lose access immediately.')">
                            <i class="fas fa-ban"></i> Ban
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- TAB 2: CUSTOMERS                            -->

      <div id="tab-users" class="tab-panel">
        <div class="info-card" style="margin-bottom:30px;">
          <h3><i class="fas fa-users"></i> Customer Accounts (Students / Staff)</h3>

          <?php if (empty($users)): ?>
            <div class="empty-state">
              <i class="fas fa-user-graduate"></i>
              <p>No customer accounts registered yet.</p>
            </div>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Status</th>
                  <th>Orders</th>
                  <th>Total Spent</th>
                  <th>Joined</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                  <td>
                    <div style="display:flex; align-items:center; gap:10px;">
                      <div style="background:#e1f3e9; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                        <i class="fas fa-user-graduate" style="color:var(--dlsu-green); margin:0;"></i>
                      </div>
                      <span style="font-weight:600;"><?php echo htmlspecialchars($u['full_name']); ?></span>
                    </div>
                  </td>
                  <td style="font-size:0.85rem; color:#5f8b74;"><?php echo htmlspecialchars($u['email']); ?></td>
                  <td>
                    <?php if ($u['is_banned']): ?>
                      <span class="pill pill-banned"><i class="fas fa-ban"></i> Banned</span>
                    <?php elseif (!$u['is_active']): ?>
                      <span class="pill pill-inactive"><i class="fas fa-times-circle"></i> Inactive</span>
                    <?php else: ?>
                      <span class="pill pill-active"><i class="fas fa-check-circle"></i> Active</span>
                    <?php endif; ?>
                  </td>
                  <td><?php echo $u['order_count']; ?></td>
                  <td style="font-weight:600; color:var(--dlsu-green);"><?php echo formatPrice($u['total_spent']); ?></td>
                  <td style="font-size:0.82rem; color:#5f8b74;"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                  <td>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                      <?php if ($u['is_banned']): ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="admin_action" value="unban_user">
                          <input type="hidden" name="user_id" value="<?php echo $u['ID']; ?>">
                          <button type="submit" class="btn-sm btn-unban" onclick="return confirm('Unban this user?')">
                            <i class="fas fa-unlock"></i> Unban
                          </button>
                        </form>
                      <?php elseif (!$u['is_active']): ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="admin_action" value="unban_user">
                          <input type="hidden" name="user_id" value="<?php echo $u['ID']; ?>">
                          <button type="submit" class="btn-sm btn-unban" onclick="return confirm('Reactivate this user?')">
                            <i class="fas fa-user-check"></i> Whitelist
                          </button>
                        </form>
                      <?php else: ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="admin_action" value="blacklist_user">
                          <input type="hidden" name="user_id" value="<?php echo $u['ID']; ?>">
                          <button type="submit" class="btn-sm btn-blacklist" onclick="return confirm('Blacklist (deactivate) this user?')">
                            <i class="fas fa-user-slash"></i> Blacklist
                          </button>
                        </form>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="admin_action" value="ban_user">
                          <input type="hidden" name="user_id" value="<?php echo $u['ID']; ?>">
                          <button type="submit" class="btn-sm btn-ban" onclick="return confirm('Ban this user? This is more severe than blacklisting.')">
                            <i class="fas fa-ban"></i> Ban
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- TAB 3: BANNED LIST                          -->

      <div id="tab-banned" class="tab-panel">
        <div class="info-card" style="margin-bottom:30px;">
          <h3><i class="fas fa-ban" style="color:#b13e3e;"></i> Banned Users</h3>
          <p style="color:#5f8b74; margin-bottom:20px; font-size:0.9rem;">
            Banned users are completely locked out of the platform. They cannot log in or place orders.
          </p>

          <?php if (empty($banned_list)): ?>
            <div class="empty-state">
              <i class="fas fa-shield-alt" style="color:#0b6d38;"></i>
              <p>No banned users. The platform is in good standing!</p>
            </div>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Joined</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($banned_list as $b): ?>
                <tr>
                  <td style="font-weight:600; color:#b13e3e;">
                    <i class="fas fa-ban" style="color:#b13e3e;"></i>
                    <?php echo htmlspecialchars($b['full_name']); ?>
                  </td>
                  <td style="font-size:0.85rem;"><?php echo htmlspecialchars($b['email']); ?></td>
                  <td>
                    <span class="pill <?php echo $b['role'] === 'V' ? 'pill-vendor' : 'pill-customer'; ?>">
                      <?php echo $b['role'] === 'V' ? 'Vendor' : ($b['role'] === 'A' ? 'Admin' : 'Customer'); ?>
                    </span>
                  </td>
                  <td style="font-size:0.82rem; color:#5f8b74;"><?php echo date('M d, Y', strtotime($b['created_at'])); ?></td>
                  <td>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="admin_action" value="unban_user">
                      <input type="hidden" name="user_id" value="<?php echo $b['ID']; ?>">
                      <button type="submit" class="btn-sm btn-unban" onclick="return confirm('Unban and whitelist this user?')">
                        <i class="fas fa-unlock"></i> Unban & Whitelist
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- TAB 4: BLACKLISTED (Inactive)               -->

      <div id="tab-blacklist" class="tab-panel">
        <div class="info-card" style="margin-bottom:30px;">
          <h3><i class="fas fa-user-slash" style="color:#9e6d0b;"></i> Blacklisted Users</h3>
          <p style="color:#5f8b74; margin-bottom:20px; font-size:0.9rem;">
            Blacklisted users have deactivated accounts. They can be restored (whitelisted) at any time.
          </p>

          <?php if (empty($blacklisted_list)): ?>
            <div class="empty-state">
              <i class="fas fa-user-check" style="color:#0b6d38;"></i>
              <p>No blacklisted users.</p>
            </div>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Joined</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($blacklisted_list as $bl): ?>
                <tr>
                  <td style="font-weight:600; color:#9e6d0b;">
                    <i class="fas fa-user-slash" style="color:#9e6d0b;"></i>
                    <?php echo htmlspecialchars($bl['full_name']); ?>
                  </td>
                  <td style="font-size:0.85rem;"><?php echo htmlspecialchars($bl['email']); ?></td>
                  <td>
                    <span class="pill <?php echo $bl['role'] === 'V' ? 'pill-vendor' : 'pill-customer'; ?>">
                      <?php echo $bl['role'] === 'V' ? 'Vendor' : ($bl['role'] === 'A' ? 'Admin' : 'Customer'); ?>
                    </span>
                  </td>
                  <td style="font-size:0.82rem; color:#5f8b74;"><?php echo date('M d, Y', strtotime($bl['created_at'])); ?></td>
                  <td>
                    <div style="display:flex; gap:6px;">
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="admin_action" value="unban_user">
                        <input type="hidden" name="user_id" value="<?php echo $bl['ID']; ?>">
                        <button type="submit" class="btn-sm btn-unban" onclick="return confirm('Whitelist (reactivate) this user?')">
                          <i class="fas fa-user-check"></i> Whitelist
                        </button>
                      </form>
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="admin_action" value="ban_user">
                        <input type="hidden" name="user_id" value="<?php echo $bl['ID']; ?>">
                        <button type="submit" class="btn-sm btn-ban" onclick="return confirm('Escalate to full ban?')">
                          <i class="fas fa-ban"></i> Ban
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- TAB 5: ACTIVITY LOGS                        -->

      <div id="tab-logs" class="tab-panel">
        <div class="info-card" style="margin-bottom:30px;">
          <h3><i class="fas fa-file-invoice"></i> Admin Activity Logs</h3>

          <?php if (empty($admin_logs)): ?>
            <div class="empty-state">
              <i class="fas fa-clipboard-list"></i>
              <p>No admin activity yet.</p>
            </div>
          <?php else: ?>
            <?php foreach ($admin_logs as $log): ?>
              <div class="log-entry">
                <div>
                  <span class="log-user"><?php echo htmlspecialchars($log['full_name'] ?? 'System'); ?></span>
                  <span class="log-action" style="margin-left:8px;">
                    <?php
                    $action = $log['action'];
                    if (strpos($action, 'CREATE_VENDOR') !== false) {
                        echo '<i class="fas fa-plus-circle" style="color:var(--dlsu-green);"></i> Created a vendor account';
                    } elseif (strpos($action, 'BAN_USER') !== false) {
                        $target = str_replace('ADMIN_BAN_USER_', '', $action);
                        echo '<i class="fas fa-ban" style="color:#b13e3e;"></i> Banned user #' . htmlspecialchars($target);
                    } elseif (strpos($action, 'UNBAN_USER') !== false) {
                        $target = str_replace('ADMIN_UNBAN_USER_', '', $action);
                        echo '<i class="fas fa-unlock" style="color:#0b6d38;"></i> Unbanned user #' . htmlspecialchars($target);
                    } elseif (strpos($action, 'DELETE_REVIEW') !== false) {
                        $target = str_replace('ADMIN_DELETE_REVIEW_', '', $action);
                        echo '<i class="fas fa-trash-alt" style="color:#b13e3e;"></i> Deleted review #' . htmlspecialchars($target);
                    } elseif (strpos($action, 'BLACKLIST_USER') !== false) {
                        $target = str_replace('ADMIN_BLACKLIST_USER_', '', $action);
                        echo '<i class="fas fa-user-slash" style="color:#9e6d0b;"></i> Blacklisted user #' . htmlspecialchars($target);
                    } else {
                        echo htmlspecialchars($action);
                    }
                    ?>
                  </span>
                </div>
                <div>
                  <span class="log-time">
                    <i class="far fa-clock"></i>
                    <?php echo getTimeAgo($log['timestamp']); ?>
                    · <?php echo date('M d, g:i A', strtotime($log['timestamp'])); ?>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      </div>

      <!-- TAB 6: COMMENT MODERATION                   -->

      <div id="tab-reviews" class="tab-panel">
        <div class="info-card" style="margin-bottom:30px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
            <h3 style="margin:0;"><i class="fas fa-comments"></i> Comment Moderation</h3>
            <span style="font-size:0.85rem; color:#5f8b74;">
              <i class="fas fa-info-circle"></i> Deleted reviews are permanently removed and cannot be recovered.
            </span>
          </div>

          <!-- Restaurant Filter -->
          <form method="GET" action="index.php" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="sysadmin">
            <input type="hidden" name="tab" value="reviews">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
              <label style="font-size:0.85rem; font-weight:700; color:#16623b; text-transform:uppercase; letter-spacing:0.5px;">
                <i class="fas fa-store"></i> Restaurant:
              </label>
              <select name="review_restaurant" onchange="this.form.submit()"
                style="padding:8px 16px; border:1.5px solid #cae3d6; border-radius:30px;
                       font-family:'Inter',sans-serif; font-size:0.88rem; color:#1e3a2f;
                       background:#f9fffc; outline:none; cursor:pointer; min-width:200px;">
                <option value="0">All Restaurants</option>
                <?php foreach ($restaurants_list as $rest): ?>
                  <option value="<?php echo $rest['ID']; ?>"
                    <?php echo $filter_restaurant_id === intval($rest['ID']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($rest['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if ($filter_restaurant_id): ?>
                <a href="index.php?page=sysadmin&tab=reviews"
                   class="btn-sm btn-ban" style="text-decoration:none;">
                  <i class="fas fa-times"></i> Clear
                </a>
              <?php endif; ?>
              <span style="font-size:0.85rem; color:#5f8b74; margin-left:auto;">
                <?php echo $total_reviews; ?> review<?php echo $total_reviews !== 1 ? 's' : ''; ?>
              </span>
            </div>
          </form>

          <?php if (empty($reviews)): ?>
            <div class="empty-state">
              <i class="fas fa-comment-slash"></i>
              <p>No reviews found<?php echo $filter_restaurant_id ? ' for this restaurant' : ''; ?>.</p>
            </div>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Restaurant</th>
                  <th>Rating</th>
                  <th>Review</th>
                  <th>Order</th>
                  <th>Date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($reviews as $rev): ?>
                <tr>
                  <td>
                    <span style="font-weight:600; color:#0f4a2f;">
                      <?php echo htmlspecialchars($rev['restaurant_name'] ?? '—'); ?>
                    </span>
                  </td>
                  <td>
                    <div style="display:flex; align-items:center; gap:5px;">
                      <?php
                        $stars = round(floatval($rev['rating']) * 2) / 2;
                        for ($s = 1; $s <= 5; $s++) {
                          if ($s <= $stars) echo '<i class="fas fa-star" style="color:#f5a623; font-size:0.75rem;"></i>';
                          elseif ($s - 0.5 === $stars) echo '<i class="fas fa-star-half-alt" style="color:#f5a623; font-size:0.75rem;"></i>';
                          else echo '<i class="far fa-star" style="color:#ccc; font-size:0.75rem;"></i>';
                        }
                      ?>
                      <strong style="font-size:0.82rem; color:#1a4d31;">
                        <?php echo number_format(floatval($rev['rating']), 1); ?>
                      </strong>
                    </div>
                  </td>
                  <td style="max-width:340px;">
                    <?php if ($rev['review']): ?>
                      <span style="color:#2c4f3b; font-style:italic; font-size:0.88rem;">
                        "<?php echo htmlspecialchars($rev['review']); ?>"
                      </span>
                    <?php else: ?>
                      <span style="color:#9ab8a7; font-size:0.82rem;">
                        <i class="fas fa-minus"></i> Rating only
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($rev['order_id']): ?>
                      <span class="pill pill-customer">#<?php echo $rev['order_id']; ?></span>
                    <?php else: ?>
                      <span class="pill" style="background:#f0f0f0; color:#999;">—</span>
                    <?php endif; ?>
                  </td>
                  <td style="color:#5f8b74; font-size:0.85rem; white-space:nowrap;">
                    <?php echo date('M d, Y', strtotime($rev['timestamp'])); ?><br>
                    <span style="font-size:0.78rem;"><?php echo date('g:i A', strtotime($rev['timestamp'])); ?></span>
                  </td>
                  <td>
                    <form method="POST" action="index.php?page=sysadmin&tab=reviews<?php echo $filter_restaurant_id ? '&review_restaurant='.$filter_restaurant_id : ''; ?>">
                      <input type="hidden" name="admin_action" value="delete_review">
                      <input type="hidden" name="rating_id" value="<?php echo $rev['ID']; ?>">
                      <button type="submit" class="btn-sm btn-ban"
                        onclick="return confirm('Permanently delete this review?')">
                        <i class="fas fa-trash-alt"></i> Delete
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- TAB 7: MAINTENANCE                          -->

      <div id="tab-maintenance" class="tab-panel">
        <div class="info-card" style="max-width:68%; margin:0 auto 30px; padding:20px 24px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
            <h3 style="margin:0;"><i class="fas fa-tools"></i> Maintenance Mode</h3>
          </div>

          <!-- Status indicator card -->
          <div style="
            background: <?php echo $maintenance_mode_current ? '#fff5f5' : '#f0f9f4'; ?>;
            border: 2px solid <?php echo $maintenance_mode_current ? '#f5c6cb' : '#b7dfc8'; ?>;
            border-radius: 14px;
            padding: 18px 20px;
            margin: 0 0 18px;
            text-align: center;
          ">
            <div style="margin-bottom: 16px;">
              <span style="
                display: inline-flex; align-items: center; gap: 10px;
                background: <?php echo $maintenance_mode_current ? '#b13e3e' : '#007a3e'; ?>;
                color: white; border-radius: 40px;
                padding: 6px 18px; font-size: 0.88rem; font-weight: 700;
              ">
                <i class="fas <?php echo $maintenance_mode_current ? 'fa-hard-hat' : 'fa-check-circle'; ?>"></i>
                Maintenance Mode is currently <?php echo $maintenance_mode_current ? 'ENABLED' : 'DISABLED'; ?>
              </span>
            </div>

            <p style="color: #4a6858; font-size: 0.88rem; margin: 0 0 16px; line-height: 1.5;">
              <?php if ($maintenance_mode_current): ?>
                <i class="fas fa-exclamation-triangle" style="color:#b13e3e;"></i>
                The site is currently in maintenance mode. Regular users and vendors <strong>cannot access</strong> the platform.
                Only administrators can log in. Click the button below to bring the site back online.
              <?php else: ?>
                <i class="fas fa-info-circle" style="color:#007a3e;"></i>
                The site is running normally. Enabling maintenance mode will prevent regular users and vendors from accessing the platform.
                Administrators will still be able to log in.
              <?php endif; ?>
            </p>

            <!-- Confirmation toggle slider -->
            <?php if (!$maintenance_mode_current): ?>
            <div style="margin-bottom: 14px;">
              <label id="maintenance-confirm-label" style="
                display: inline-flex; align-items: center; gap: 14px;
                cursor: pointer; user-select: none;
                background: #fff8f8; border: 1.5px solid #f5c6cb;
                border-radius: 40px; padding: 10px 20px 10px 14px;
                font-size: 0.88rem; font-weight: 600; color: #7a2c2c;
                transition: all 0.2s;
              ">
                <!-- Toggle track -->
                <span id="maintenance-toggle-track" style="
                  position: relative; display: inline-block;
                  width: 48px; height: 26px; border-radius: 13px;
                  background: #e0c4c4; transition: background 0.3s;
                  flex-shrink: 0;
                ">
                  <span id="maintenance-toggle-thumb" style="
                    position: absolute; top: 3px; left: 3px;
                    width: 20px; height: 20px; border-radius: 50%;
                    background: white; transition: left 0.3s;
                    box-shadow: 0 1px 4px rgba(0,0,0,0.2);
                  "></span>
                </span>
                <span id="maintenance-confirm-text">
                  <i class="fas fa-exclamation-triangle"></i> I understand this will lock out all users — confirm to proceed
                </span>
                <input type="checkbox" id="maintenance-confirm-check" style="display:none;" onchange="handleMaintenanceToggle(this)">
              </label>
            </div>
            <?php endif; ?>

            <form method="POST" action="index.php?page=sysadmin&tab=maintenance" id="maintenance-form">
              <input type="hidden" name="admin_action" value="toggle_maintenance">
              <button type="button" id="maintenance-btn"
                <?php echo !$maintenance_mode_current ? 'disabled' : ''; ?>
                onclick="submitMaintenanceForm()"
                style="
                display: inline-flex; align-items: center; gap: 10px;
                background: <?php echo $maintenance_mode_current ? '#007a3e' : '#b13e3e'; ?>;
                color: white; border: none; border-radius: 40px;
                padding: 10px 26px; font-size: 0.9rem; font-weight: 700;
                font-family: 'Inter', sans-serif; transition: all 0.3s;
                box-shadow: 0 4px 14px <?php echo $maintenance_mode_current ? 'rgba(0,122,62,0.25)' : 'rgba(177,62,62,0.25)'; ?>;
                <?php echo !$maintenance_mode_current ? 'opacity:0.38; cursor:not-allowed;' : 'cursor:pointer;' ?>
              ">
                <i class="fas <?php echo $maintenance_mode_current ? 'fa-toggle-on' : 'fa-hard-hat'; ?>" id="maintenance-btn-icon"></i>
                <span id="maintenance-btn-text"><?php echo $maintenance_mode_current ? 'Disable Maintenance Mode' : 'Enable Maintenance Mode'; ?></span>
              </button>
            </form>

            <style>
              #maintenance-confirm-label:has(#maintenance-confirm-check:checked) {
                background: #fff0f0; border-color: #e57373;
              }
              #maintenance-btn:not([disabled]):hover {
                transform: translateY(-2px);
              }
              @keyframes countdown-pulse {
                0%, 100% { box-shadow: 0 4px 14px rgba(177,62,62,0.25); }
                50%       { box-shadow: 0 4px 24px rgba(177,62,62,0.55); }
              }
              .counting-down { animation: countdown-pulse 1s ease-in-out infinite; }
            </style>

            <script>
              var maintenanceRAF = null;
              var maintenanceStartTime = null;
              var maintenanceDuration = 5000; // ms
              var maintenanceCancelled = false;

              function handleMaintenanceToggle(checkbox) {
                var track  = document.getElementById('maintenance-toggle-track');
                var thumb  = document.getElementById('maintenance-toggle-thumb');
                var btn    = document.getElementById('maintenance-btn');
                var label  = document.getElementById('maintenance-confirm-label');

                if (checkbox.checked) {
                  // Visually activate the slider
                  track.style.background = '#b13e3e';
                  thumb.style.left = '25px';
                  label.style.borderColor = '#b13e3e';
                  label.style.color = '#b13e3e';

                  // Start accurate countdown using Date.now() + rAF
                  maintenanceCancelled = false;
                  maintenanceStartTime = Date.now();
                  btn.classList.add('counting-down');
                  runCountdown();

                } else {
                  // Reset slider
                  track.style.background = '#e0c4c4';
                  thumb.style.left = '3px';
                  label.style.borderColor = '#f5c6cb';
                  label.style.color = '#7a2c2c';

                  // Cancel the rAF loop and re-disable button
                  maintenanceCancelled = true;
                  if (maintenanceRAF) cancelAnimationFrame(maintenanceRAF);
                  btn.disabled = true;
                  btn.style.opacity = '0.38';
                  btn.style.cursor = 'not-allowed';
                  btn.classList.remove('counting-down');
                  document.getElementById('maintenance-btn-text').textContent = '<?php echo $maintenance_mode_current ? 'Disable Maintenance Mode' : 'Enable Maintenance Mode'; ?>';
                  document.getElementById('maintenance-btn-icon').className = 'fas <?php echo $maintenance_mode_current ? 'fa-toggle-on' : 'fa-hard-hat'; ?>';
                }
              }

              function runCountdown() {
                if (maintenanceCancelled) return;

                var elapsed = Date.now() - maintenanceStartTime;
                var remaining = Math.ceil((maintenanceDuration - elapsed) / 1000);

                if (elapsed >= maintenanceDuration) {
                  // Done — enable button
                  var btn = document.getElementById('maintenance-btn');
                  btn.disabled = false;
                  btn.style.opacity = '1';
                  btn.style.cursor = 'pointer';
                  btn.classList.remove('counting-down');
                  document.getElementById('maintenance-btn-text').textContent = '<?php echo $maintenance_mode_current ? 'Disable Maintenance Mode' : 'Enable Maintenance Mode'; ?>';
                  document.getElementById('maintenance-btn-icon').className = 'fas <?php echo $maintenance_mode_current ? 'fa-toggle-on' : 'fa-hard-hat'; ?>';
                } else {
                  // Still counting — update display and schedule next frame
                  updateCountdownBtn(remaining);
                  maintenanceRAF = requestAnimationFrame(runCountdown);
                }
              }

              // Clicking the label area also toggles checkbox (since input is hidden)
              document.addEventListener('DOMContentLoaded', function() {
                var label = document.getElementById('maintenance-confirm-label');
                if (label) {
                  label.addEventListener('click', function() {
                    var cb = document.getElementById('maintenance-confirm-check');
                    cb.checked = !cb.checked;
                    handleMaintenanceToggle(cb);
                  });
                }
              });

              function updateCountdownBtn(remaining) {
                var btn = document.getElementById('maintenance-btn');
                btn.disabled = true;
                btn.style.opacity = '0.7';
                btn.style.cursor = 'not-allowed';
                document.getElementById('maintenance-btn-icon').className = 'fas fa-clock';
                document.getElementById('maintenance-btn-text').textContent =
                  '<?php echo $maintenance_mode_current ? 'Disabling' : 'Enabling'; ?> in ' + remaining + 's…';
              }

              function submitMaintenanceForm() {
                var btn = document.getElementById('maintenance-btn');
                if (btn.disabled) return;
                var msg = '<?php echo $maintenance_mode_current
                  ? 'Disable maintenance mode? The site will become publicly accessible.'
                  : 'Enable maintenance mode? Regular users will be locked out until you disable it.'; ?>';
                if (confirm(msg)) {
                  document.getElementById('maintenance-form').submit();
                }
              }
            </script>
          </div>

          <!-- What maintenance mode does info box -->
          <div style="background:#f8fbff; border:1px solid #d0e4f7; border-radius:12px; padding:14px 18px; margin-top:4px;">
            <div style="font-weight:700; color:#1a4d7c; margin-bottom:12px; font-size:0.9rem;">
              <i class="fas fa-info-circle" style="color:#3b82f6;"></i> What does maintenance mode do?
            </div>
            <ul style="margin:0; padding-left:20px; color:#3a5a7a; font-size:0.88rem; line-height:1.8;">
              <li>Displays a maintenance page to all non-admin visitors</li>
              <li>Prevents customers and vendors from logging in or placing orders</li>
              <li>Allows administrators to continue accessing the system</li>
              <li>Changes the <code style="background:#e8f0fe; padding:1px 6px; border-radius:4px;">MAINTENANCE_MODE</code> constant in <code style="background:#e8f0fe; padding:1px 6px; border-radius:4px;">index.php</code></li>
            </ul>
          </div>
        </div>
      </div>


      <footer class="footer-note">
      <i class="fas fa-user-shield"></i>
      System Admin · UniCanteen · Vendor creation, user bans, oversight · <?php echo date('g:i A'); ?>
    </footer>
    </div><!-- /wrapper -->

    

  </section>

<!-- CREATE VENDOR MODAL                         -->

<div id="createVendorModal" class="modal-overlay">
  <div class="modal-card">
    <h3><i class="fas fa-store"></i> Create New Vendor Account</h3>
    <form method="POST" action="index.php?page=sysadmin" id="createVendorForm">
      <input type="hidden" name="admin_action" value="create_vendor">

      <p style="background:#e3f4ea; padding:12px 16px; border-radius:14px; font-size:0.85rem; color:#0b6d38; margin-bottom:20px;">
        <i class="fas fa-info-circle"></i>
        This will create a vendor login and automatically set up their restaurant profile.
      </p>

      <div style="margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #e8f3ec;">
        <div style="font-weight:700; color:#0f4a2f; margin-bottom:12px; font-size:0.9rem;">
          <i class="fas fa-user" style="color:var(--dlsu-green);"></i> Vendor Account Details
        </div>
        <div class="modal-field">
          <label>Full Name *</label>
          <input type="text" name="vendor_name" placeholder="e.g. Bloemen Hall Manager" required>
        </div>
        <div class="modal-field">
          <label>Email Address *</label>
          <input type="email" name="vendor_email" placeholder="vendor@dlsu.edu" required>
        </div>
        <div class="modal-field">
          <label>Temporary Password *</label>
          <input type="text" name="vendor_password" placeholder="Min 8 characters" required minlength="8">
          <small style="color:#5f8b74; font-size:0.78rem; display:block; margin-top:4px;">
            <i class="fas fa-info-circle"></i> The vendor should change this after first login.
          </small>
        </div>
      </div>

      <div>
        <div style="font-weight:700; color:#0f4a2f; margin-bottom:12px; font-size:0.9rem;">
          <i class="fas fa-store" style="color:var(--dlsu-green);"></i> Restaurant Information
        </div>
        <div class="modal-field">
          <label>Restaurant / Stall Name *</label>
          <input type="text" name="restaurant_name" placeholder="e.g. Bloemen Hall Cafe" required>
        </div>
        <div class="modal-field">
          <label>Address / Location</label>
          <input type="text" name="restaurant_address" placeholder="e.g. Bloemen Hall, DLSU">
        </div>
        <div class="modal-field">
          <label>Description</label>
          <textarea name="restaurant_description" placeholder="Brief description of the stall..."></textarea>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-modal-cancel" onclick="closeCreateVendorModal()">Cancel</button>
        <button type="submit" class="btn-modal-submit">
          <i class="fas fa-plus-circle"></i> Create Vendor Account
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // Tab switching
  function switchTab(tabName, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + tabName).classList.add('active');
    btn.classList.add('active');
  }

  // Restore active tab from URL param
  (function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');
    if (tab) {
      const panel = document.getElementById('tab-' + tab);
      const btn = [...document.querySelectorAll('.admin-tab')].find(b => b.getAttribute('onclick')?.includes("'" + tab + "'"));
      if (panel && btn) {
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
        panel.classList.add('active');
        btn.classList.add('active');
      }
    }
  })();

  // Create vendor modal
  function openCreateVendorModal() {
    document.getElementById('createVendorModal').style.display = 'flex';
  }
  function closeCreateVendorModal() {
    document.getElementById('createVendorModal').style.display = 'none';
  }

  // Close modals on overlay click
  document.getElementById('createVendorModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
  });

  // Auto-hide success/error messages after 5s
  document.querySelectorAll('.success-message, .error-message').forEach(el => {
    setTimeout(() => {
      el.style.transition = 'opacity 0.5s ease';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 500);
    }, 5000);
  });
</script>