<?php
/**
 * vendor.php — Vendor dashboard page.
 *
 * Displays menu management, order queue, sales analytics, and
 * recent transactions for the currently logged-in vendor.
 */

$dbConn = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'] ?? null;

/* --------------------------------------------------
   1. Fetch restaurant and menu items for this vendor
   -------------------------------------------------- */
$stmt = $dbConn->prepare("SELECT * FROM Restaurants WHERE owner_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$restaurant = $stmt->get_result()->fetch_assoc();
$stmt->close();

$restaurant_id = $restaurant['ID'] ?? 0;

$stmt = $dbConn->prepare("SELECT * FROM Items WHERE restaurant_id = ?");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$menu_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* --------------------------------------------------
   2. Dashboard metrics (all parameterized queries)
   -------------------------------------------------- */

// Helper: run a single-value aggregate query with a bound restaurant ID
function getMetric($db, $sql, $rid) {
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $rid);
    $stmt->execute();
    $val = $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $val ?? 0;
}

$total_items       = $restaurant_id ? getMetric($dbConn, "SELECT COUNT(*) FROM Items WHERE restaurant_ID = ?", $restaurant_id) : 0;
$available_count   = $restaurant_id ? getMetric($dbConn, "SELECT COUNT(*) FROM Items WHERE restaurant_ID = ? AND isAvailable=1", $restaurant_id) : 0;
$sold_out_count    = $restaurant_id ? getMetric($dbConn, "SELECT COUNT(*) FROM Items WHERE restaurant_ID = ? AND isAvailable=0", $restaurant_id) : 0;
$preparing_orders  = $restaurant_id ? getMetric($dbConn, "SELECT COUNT(*) FROM Orders WHERE restaurant_ID = ? AND status='PR'", $restaurant_id) : 0;
$ready_orders      = $restaurant_id ? getMetric($dbConn, "SELECT COUNT(*) FROM Orders WHERE restaurant_ID = ? AND status='R'", $restaurant_id) : 0;
$fulfilled_orders_count = $restaurant_id ? getMetric($dbConn, "SELECT COUNT(*) FROM Orders WHERE restaurant_ID = ? AND status='C'", $restaurant_id) : 0;
$pending_orders    = $restaurant_id ? getMetric($dbConn, "SELECT COUNT(*) FROM Orders WHERE restaurant_ID = ? AND status='P'", $restaurant_id) : 0;
$avg_order_value   = $restaurant_id ? getMetric($dbConn, "SELECT AVG(total_amount) FROM Orders WHERE restaurant_ID = ? AND status='C'", $restaurant_id) : 0;
$last_week_avg_order_value = $restaurant_id
  ? getMetric($dbConn, "SELECT AVG(total_amount) FROM Orders WHERE restaurant_ID = ? AND status='C' AND order_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", $restaurant_id) : 0;
$total_revenue     = $restaurant_id ? getMetric($dbConn, "SELECT COALESCE(SUM(total_amount),0) FROM Orders WHERE restaurant_ID = ? AND status='C'", $restaurant_id) : 0;
$total_orders      = $restaurant_id ? getMetric($dbConn, "SELECT COUNT(*) FROM Orders WHERE restaurant_ID = ?", $restaurant_id) : 0;
$completed_orders  = $fulfilled_orders_count;

/* --------------------------------------------------
   3. Time-filtered analytics (Today / Week / Month / All)
   -------------------------------------------------- */
$analytics = [];
$periods = [
  'today'      => "DATE(order_date) = CURDATE()",
  'this_week'  => "YEARWEEK(order_date, 1) = YEARWEEK(CURDATE(), 1)",
  'this_month' => "YEAR(order_date) = YEAR(CURDATE()) AND MONTH(order_date) = MONTH(CURDATE())",
  'all'        => "1=1",
];
foreach ($periods as $key => $where) {
  // These period clauses contain only SQL constants, no user input
  $base = "FROM Orders WHERE restaurant_ID={$restaurant_id} AND status='C' AND {$where}";
  $analytics[$key] = [
    'revenue' => $restaurant_id ? ($dbConn->query("SELECT COALESCE(SUM(total_amount),0) {$base}")->fetch_row()[0] ?? 0) : 0,
    'orders'  => $restaurant_id ? ($dbConn->query("SELECT COUNT(*) {$base}")->fetch_row()[0] ?? 0) : 0,
    'avg'     => $restaurant_id ? ($dbConn->query("SELECT COALESCE(AVG(total_amount),0) {$base}")->fetch_row()[0] ?? 0) : 0,
  ];
}

// Best-selling items per period (top 6 each)
$bestSellersByPeriod = [];
$default_item = ['item_name' => '—', 'order_count' => 0];

if ($restaurant_id) {
    $bsPeriods = [
        'today'      => "AND DATE(o.order_date) = CURDATE()",
        'this_week'  => "AND YEARWEEK(o.order_date, 1) = YEARWEEK(CURDATE(), 1)",
        'this_month' => "AND YEAR(o.order_date) = YEAR(CURDATE()) AND MONTH(o.order_date) = MONTH(CURDATE())",
        'all'        => "",
    ];
    foreach ($bsPeriods as $bsKey => $bsWhere) {
        $bsQuery = "SELECT i.name AS item_name, SUM(oi.quantity) AS order_count
                    FROM Order_ItemLine oi
                    JOIN Items i ON oi.item_ID = i.ID
                    JOIN Orders o ON oi.order_ID = o.ID
                    WHERE o.restaurant_ID = ? AND o.status='C' {$bsWhere}
                    GROUP BY oi.item_ID
                    ORDER BY order_count DESC
                    LIMIT 6";
        $bsStmt = $dbConn->prepare($bsQuery);
        $bsStmt->bind_param("i", $restaurant_id);
        $bsStmt->execute();
        $bsItems = $bsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $bsStmt->close();
        // Pad to 6 entries so the template never fails
        while (count($bsItems) < 6) {
            $bsItems[] = $default_item;
        }
        $bestSellersByPeriod[$bsKey] = $bsItems;
    }
} else {
    foreach (['today', 'this_week', 'this_month', 'all'] as $bsKey) {
        $bestSellersByPeriod[$bsKey] = array_fill(0, 6, $default_item);
    }
}

// Default view is "today"
$best_selling_items = $bestSellersByPeriod['today'];

/* --------------------------------------------------
   4. Queue: currently-serving and next-up orders
   -------------------------------------------------- */
$currentServingOrder = null;
$nextServingOrder    = null;
if ($restaurant_id) {
    $queueStmt = $dbConn->prepare(
        "SELECT queue_number, status FROM Orders
         WHERE restaurant_ID = ? AND status IN ('P','PR','R')
         AND DATE(order_date) >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
         ORDER BY order_date ASC"
    );
    $queueStmt->bind_param("i", $restaurant_id);
    $queueStmt->execute();
    $queueOrders = $queueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $queueStmt->close();

    if (!empty($queueOrders)) {
        $currentServingOrder = $queueOrders[0];
        $nextServingOrder    = $queueOrders[1] ?? null;
    }
}

/* --------------------------------------------------
   5. Fetch all orders with their line-items for the queue
   -------------------------------------------------- */
$stmt = $dbConn->prepare(
    "SELECT o.*, IFNULL(u.full_name, IFNULL(NULLIF(o.walkin_name, ''), 'Walk-in Customer')) AS display_name
     FROM Orders o
     LEFT JOIN Users u ON o.customer_ID = u.ID
     WHERE o.restaurant_ID = ?
     ORDER BY o.order_date DESC"
);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Attach line-items to each order
$lineStmt = $dbConn->prepare(
    "SELECT i.name, oi.quantity
     FROM Order_ItemLine oi
     JOIN Items i ON oi.item_ID = i.ID
     WHERE oi.order_ID = ?"
);
foreach ($orders as &$order) {
    $lineStmt->bind_param("i", $order['ID']);
    $lineStmt->execute();
    $order['items'] = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
unset($order);
$lineStmt->close();

function getTransactionStatusStyle($status) {
    $styles = [
        'P'  => 'background: #f5e6e6; color: #b13e3e;',
        'PR' => 'background: #fff1cf; color: #9e6d0b;',
        'R'  => 'background: #c9f0d7; color: #0c6e3a;',
        'C'  => 'background: #d0e3ff; color: #1f5090;',
    ];
    return $styles[$status] ?? '';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>UniCanteen · Vendor Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
  <style>
    body {
      display: block;
      min-height: auto;
      margin: 0;
      padding: 0;
    }

    .main-content {
      margin-left: 0;
    }

    .wrapper {
      max-width: 1300px;
      margin: 0 auto;
      padding: 0 36px;
    }

    /* Vendor hero banner */
    .vendor-hero {
      background: linear-gradient(135deg, #007a3e 0%, #005a2e 100%);
      padding: 28px 0 32px;
      color: white;
    }

    .vendor-hero h1 {
      font-size: 1.9rem;
      font-weight: 700;
      margin: 0 0 6px;
    }

    .vendor-hero p {
      opacity: 0.85;
      font-size: 0.95rem;
      margin: 0;
    }

    .vendor-hero-inner {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
    }

    .vendor-hero-actions {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    /* Back to stalls button */
    .btn-back-stalls {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255, 255, 255, 0.15);
      border: 1.5px solid rgba(255, 255, 255, 0.4);
      color: white;
      padding: 10px 22px;
      border-radius: 40px;
      font-weight: 600;
      font-size: 0.9rem;
      text-decoration: none;
      transition: all 0.2s;
      backdrop-filter: blur(4px);
    }

    .btn-back-stalls:hover {
      background: rgba(255, 255, 255, 0.28);
      border-color: white;
      transform: translateY(-1px);
    }

    .btn-back-stalls i {
      margin-right: 0;
    }

    /* Stats bar */
    .vendor-stats-bar {
      background: white;
      border-radius: 60px;
      padding: 16px 28px;
      margin: -22px 0 32px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border: 1px solid var(--border-soft);
      box-shadow: 0 8px 20px rgba(0, 70, 30, 0.06);
      flex-wrap: wrap;
      gap: 10px;
    }

    .vstat {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .vstat-num {
      font-weight: 700;
      font-size: 1.3rem;
      color: var(--dlsu-green);
    }

    .vstat-lbl {
      color: #3b7455;
      font-size: 0.85rem;
    }

    .vdivider {
      width: 1px;
      height: 28px;
      background: #d0eadb;
    }

    /* Section headers */
    .vendor-section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin: 36px 0 18px;
      flex-wrap: wrap;
      gap: 12px;
    }

    .vendor-section-header h2 {
      font-size: 1.5rem;
      color: #0d6337;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .vendor-section-header h2 i {
      background: #e1f3e9;
      color: var(--dlsu-green);
      padding: 10px;
      border-radius: 50%;
      font-size: 1rem;
    }

    /* Menu item action buttons */
    .btn-edit-item {
      background: #e3f4ea;
      color: var(--dlsu-green);
      border: none;
      padding: 7px 14px;
      border-radius: 30px;
      font-weight: 600;
      font-size: 0.8rem;
      cursor: pointer;
      transition: all 0.18s;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    .btn-edit-item:hover {
      background: #c8e9d4;
      transform: translateY(-1px);
    }

    .btn-delete-item {
      background: #fee9e9;
      color: #b13e3e;
      border: none;
      padding: 7px 14px;
      border-radius: 30px;
      font-weight: 600;
      font-size: 0.8rem;
      cursor: pointer;
      transition: all 0.18s;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    .btn-delete-item:hover {
      background: #f5c9c9;
      transform: translateY(-1px);
    }

    /* Modal overlay + card */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 30, 12, 0.45);
      backdrop-filter: blur(3px);
      align-items: center;
      justify-content: center;
      z-index: 9999;
    }

    .modal-card {
      background: white;
      border-radius: 28px;
      padding: 32px;
      width: 420px;
      max-width: 92%;
      box-shadow: 0 24px 60px rgba(0, 60, 20, 0.18);
      border: 1px solid var(--border-soft);
    }

    .modal-card h3 {
      font-size: 1.3rem;
      color: #0f4a2f;
      margin: 0 0 24px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .modal-card h3 i {
      color: var(--dlsu-green);
    }

    .modal-field {
      margin-bottom: 16px;
    }

    .modal-field label {
      display: block;
      font-size: 0.85rem;
      font-weight: 600;
      color: #1a4d31;
      margin-bottom: 6px;
    }

    .modal-field input,
    .modal-field select,
    .modal-field textarea {
      width: 100%;
      padding: 11px 16px;
      border: 1.5px solid #cae3d6;
      border-radius: 14px;
      font-family: 'Inter', sans-serif;
      font-size: 0.95rem;
      color: #1e3a2f;
      background: #f9fffc;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
      box-sizing: border-box;
    }

    .modal-field textarea {
      resize: vertical;
      min-height: 60px;
    }

    .modal-field input:focus,
    .modal-field select:focus,
    .modal-field textarea:focus {
      border-color: var(--dlsu-green);
      box-shadow: 0 0 0 3px rgba(0, 122, 62, 0.1);
      background: white;
    }

    .modal-actions {
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      margin-top: 24px;
    }

    .btn-modal-cancel {
      background: #f0f7f2;
      color: #2d6347;
      border: none;
      padding: 11px 24px;
      border-radius: 40px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.18s;
    }

    .btn-modal-cancel:hover {
      background: #dceee4;
    }

    .btn-modal-submit {
      background: var(--dlsu-green);
      color: white;
      border: none;
      padding: 11px 28px;
      border-radius: 40px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.18s;
    }

    .btn-modal-submit:hover {
      background: var(--dlsu-darkgreen);
      transform: translateY(-1px);
    }

    .btn-modal-danger {
      background: #fee9e9;
      color: #b13e3e;
      border: none;
      padding: 11px 28px;
      border-radius: 40px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.18s;
    }

    /* Order status select */
    .order-status-select {
      background: #f5f9f6;
      border: 2px solid #cae3d6;
      padding: 6px 14px;
      border-radius: 30px;
      font-weight: 600;
      font-size: 0.8rem;
      cursor: pointer;
      outline: none;
      width: fit-content;
      transition: all 0.25s ease;
    }

    .order-status-select:hover {
      border-color: var(--dlsu-green);
      box-shadow: 0 4px 12px rgba(0, 122, 62, 0.15);
    }

    .order-status-select:focus {
      border-color: var(--dlsu-green);
      box-shadow: 0 0 0 4px rgba(0, 122, 62, 0.2);
      outline: none;
    }

    /* Color-coded status options */
    .order-status-select option[value="P"],
    .order-status-select[value="P"] {
      background-color: #fee9e9;
      color: #b13e3e;
    }

    .order-status-select option[value="PR"],
    .order-status-select[value="PR"] {
      background-color: #fff1cf;
      color: #9e6d0b;
    }

    .order-status-select option[value="R"],
    .order-status-select[value="R"] {
      background-color: #c9f0d7;
      color: #0c6e3a;
    }

    .order-status-select option[value="C"],
    .order-status-select[value="C"] {
      background-color: #d0e3ff;
      color: #1f5090;
    }

    /* Items availability badge in menu list */
    .avail-pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 12px;
      border-radius: 30px;
      font-size: 0.75rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.18s;
      border: none;
      font-family: 'Inter', sans-serif;
    }

    .avail-pill:hover {
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .avail-pill.yes {
      background: #d9f0e2;
      color: #0a5e30;
    }

    .avail-pill.no {
      background: #fee9e9;
      color: #b13e3e;
    }

    /* Drag and Drop Zone */
    .file-drop-zone {
      border: 2px dashed #cae3d6;
      border-radius: 14px;
      padding: 30px 20px;
      text-align: center;
      background: #f9fffc;
      cursor: pointer;
      transition: all 0.2s ease;
      position: relative;
    }

    .file-drop-zone:hover,
    .file-drop-zone.dragover {
      background: #f0f7f2;
      border-color: var(--dlsu-green);
    }

    .file-drop-zone i {
      font-size: 2rem;
      color: var(--dlsu-green);
      margin-bottom: 10px;
      opacity: 0.7;
    }

    .file-drop-zone p {
      margin: 0 0 8px 0;
      color: #1a4d31;
      font-weight: 600;
      font-size: 0.95rem;
    }

    .file-drop-zone span {
      font-size: 0.8rem;
      color: #5f8b74;
      display: block;
    }

    .file-drop-zone input[type="file"] {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      cursor: pointer;
    }

    .img-preview-container {
      margin-top: 16px;
      position: relative;
      display: inline-block;
    }

    .img-preview-container img {
      max-width: 100%;
      max-height: 200px;
      border-radius: 8px;
      border: 1px solid #ddd;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    .remove-preview-btn {
      position: absolute;
      top: -10px;
      right: -10px;
      background: #fee9e9;
      color: #b13e3e;
      border: none;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
      transition: transform 0.2s;
    }

    .remove-preview-btn:hover {
      transform: scale(1.1);
      background: #f5c9c9;
    }

    /* Period filter buttons in Analytics */
    .period-btn {
      background: none;
      border: 1.5px solid #cae3d6;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      font-size: 0.8rem;
      font-weight: 600;
      color: #3b7455;
      padding: 5px 14px;
      border-radius: 20px;
      transition: all 0.18s;
    }

    .period-btn:hover {
      background: #e3f4ea;
      border-color: var(--dlsu-green);
      color: var(--dlsu-green);
    }

    .period-btn.active-period {
      background: var(--dlsu-green);
      border-color: var(--dlsu-green);
      color: white;
      box-shadow: 0 2px 8px rgba(0, 122, 62, 0.25);
    }

    .table-responsive {
        max-height: 450px; 
        overflow-y: auto; 
    }
    
    .data-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background-color: #f4f7f6;
        box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
    }

    .kanban-cards {
        max-height: 500px; 
        overflow-y: auto;  
        padding-right: 5px;
    }

    .table-responsive::-webkit-scrollbar,
    .kanban-cards::-webkit-scrollbar {
        width: 6px;
    }
    .table-responsive::-webkit-scrollbar-thumb,
    .kanban-cards::-webkit-scrollbar-thumb {
        background-color: #ccc;
        border-radius: 4px;
    }

    .custom-scroll-area {
        overflow-y: auto; 
        padding-right: 8px;
    }

    .custom-scroll-area::-webkit-scrollbar {
        width: 6px; /* Thin scrollbar */
    }
    
    .custom-scroll-area::-webkit-scrollbar-track {
        background: transparent; 
    }
    
    .custom-scroll-area::-webkit-scrollbar-thumb {
        background-color: #b8e0cc; 
        border-radius: 10px; 
    }
    
    .custom-scroll-area::-webkit-scrollbar-thumb:hover {
        background-color: #007a3e;
    }
  </style>
</head>

<body>
  <div class="main-content">
    <section id="vendor" class="page-section">

      <!-- ── Vendor Nav (matches customer-nav style) ── -->
      <div class="wrapper">
        <nav class="customer-nav">
          <a href="<?php echo url('index.php?page=customer'); ?>" class="logo">UniCanteen <span>DLSU</span></a>
          <div class="customer-nav-links">
            <span style="font-weight:600; color:#0f4a2f;">
              <i class="fas fa-store" style="color:var(--dlsu-green);"></i>
              <?php echo htmlspecialchars($restaurant['name'] ?? 'Vendor Portal'); ?>
            </span>
              <form method="POST" action="<?php echo url('frontend/toggle_stall.php'); ?>" style="display:inline;">
                <input type="hidden" name="restaurant_id" value="<?php echo $restaurant['ID']; ?>">
                  <button type="submit" style="
                      background: <?php echo $restaurant['is_open'] ? '#e3f4ea' : '#fee9e9'; ?>;
                      color: <?php echo $restaurant['is_open'] ? '#007a3e' : '#b13e3e'; ?>;
                      border: none;
                      padding: 6px 14px;
                      border-radius: 20px;
                      cursor: pointer;
                      font-size: 0.8rem;
                      margin-left: 10px;
                      font-weight: 600;
                  ">
                      <?php echo $restaurant['is_open'] ? 'Open' : 'Close'; ?>
                  </button>
              </form>
            <span class="sync-badge"><i class="fas fa-circle" style="color:#28a745; font-size:0.6rem;"></i> Live</span>
            <a href="<?php echo url('index.php?page=customer'); ?>" class="btn-outline">
              <i class="fas fa-arrow-left"></i> Back to Stalls
            </a>
            <a href="<?php echo url('index.php?page=logout'); ?>" class="btn-primary" style="padding:10px 20px;">
              <i class="fas fa-sign-out-alt"></i> Logout
            </a>
          </div>
        </nav>
      </div>

      <!-- ── Vendor Hero Banner ── -->
      <div class="vendor-hero">
        <div class="wrapper">
          <div class="vendor-hero-inner">
            <div>
              <h1><i class="fas fa-store" style="font-size:1.5rem; opacity:0.85; margin-right:10px;"></i>
                <?php echo htmlspecialchars($restaurant['name'] ?? 'Vendor Portal'); ?></h1>
              <p>Manage your menu, track orders, and monitor your stall's performance.</p>
            </div>
            <div class="vendor-hero-actions">
              <a href="<?php echo url('index.php?page=customer'); ?>" class="btn-back-stalls">
                <i class="fas fa-arrow-left"></i> Back to Stalls
              </a>
              <button onclick="openAddModal()" class="btn-back-stalls" style="cursor:pointer;">
                <i class="fas fa-plus"></i> Add Item
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="wrapper">

        <!-- ── Stats Bar ── -->
        <div class="vendor-stats-bar">
          <div class="vstat">
            <span class="vstat-num"><?php echo formatPrice($total_revenue); ?></span>
            <span class="vstat-lbl">total revenue</span>
          </div>
          <div class="vdivider"></div>
          <div class="vstat">
            <span class="vstat-num"><?php echo $total_orders; ?></span>
            <span class="vstat-lbl">orders</span>
          </div>
          <div class="vdivider"></div>
          <div class="vstat">
            <span class="vstat-num"><?php echo $pending_orders; ?></span>
            <span class="vstat-lbl">pending</span>
          </div>
          <div class="vdivider"></div>
          <div class="vstat">
            <span class="vstat-num"><?php echo $available_count; ?>/<?php echo $total_items; ?></span>
            <span class="vstat-lbl">items available</span>
          </div>
          <div class="vdivider"></div>
          <div class="vstat">
            <i class="fas fa-clock" style="color:var(--warning); margin-right:0;"></i>
            <span class="vstat-lbl"><?php echo $preparing_orders; ?> preparing · <?php echo $ready_orders; ?>
              ready</span>
          </div>
        </div>

        <!-- ── Main Two-Column Grid ── -->
        <div class="admin-grid">

          <!-- ── LEFT: Menu Management ── -->
          <div class="admin-card">
            <div class="vendor-section-header" style="margin-top:0;">
              <h2><i class="fas fa-pen-to-square"></i> Menu Management</h2>
              <span class="sync-badge"><i class="fas fa-rotate"></i> Live Sync</span>
            </div>

            <!-- Inventory summary mini-cards -->
            <div class="inventory-grid" style="margin-bottom:20px; grid-template-columns: repeat(3, 1fr);">
              <div class="inventory-stat total">
                <div class="stat-label total">Total Items</div>
                <div class="stat-number total"><?php echo $total_items; ?></div>
              </div>
              <div class="inventory-stat available">
                <div class="stat-label available">Available</div>
                <div class="stat-number available"><?php echo $available_count; ?></div>
              </div>
              <div class="inventory-stat soldout">
                <div class="stat-label soldout">Sold Out</div>
                <div class="stat-number soldout"><?php echo $sold_out_count; ?></div>
              </div>
            </div>

            <!-- Menu items table header -->
            <div class="menu-header" style="grid-template-columns: 1fr 90px 90px 80px 80px;">
              <span>Item Name</span>
              <span>Price</span>
              <span>Status</span>
              <span style="text-align:center;">Edit</span>
              <span style="text-align:center;">Delete</span>
            </div>

            <!-- Menu items list -->
            <div class="custom-scroll-area" style="height: 350px;">
              <?php if (empty($menu_items)): ?>
                <div style="text-align:center; padding:40px; color:#5f8b74;">
                  <i class="fas fa-utensils" style="font-size:2.5rem; opacity:0.3; display:block; margin-bottom:12px;"></i>
                <p>No menu items yet. Add your first item!</p>
              </div>
            <?php else: ?>
              <?php foreach ($menu_items as $item): ?>
                <div class="menu-edit-row" style="grid-template-columns: 1fr 90px 90px 80px 80px;">
                  <div class="item-info">
                    <?php if (!empty($item['image_url'])): ?>
                      <img src="<?= htmlspecialchars(url($item['image_url'])) ?>" alt="<?= htmlspecialchars($item['name']) ?>"
                        style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px; margin-right: 12px;">
                    <?php else: ?>
                      <i class="fas fa-burger" style="margin-right: 12px;"></i>
                    <?php endif; ?>
                    <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                  </div>
                  <span class="item-price">₱<?= number_format($item['price'], 2) ?></span>
                  <button class="avail-pill <?= $item['isAvailable'] ? 'yes' : 'no' ?>"
                    data-item-id="<?= $item['ID'] ?>"
                    data-available="<?= $item['isAvailable'] ? '1' : '0' ?>"
                    onclick="toggleItemStatus(this)"
                    title="Click to toggle availability">
                    <i class="fas <?= $item['isAvailable'] ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                    <?= $item['isAvailable'] ? 'Available' : 'Sold Out' ?>
                  </button>
                  <div style="display:flex; justify-content:center;">
                    <button class="btn-edit-item" onclick="openEditModal(
                      <?= $item['ID'] ?>,
                      '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>',
                      <?= $item['price'] ?>,
                      <?= $item['isAvailable'] ? 'true' : 'false' ?>,
                      '<?= htmlspecialchars($item['description'] ?? '', ENT_QUOTES) ?>'
                    )"><i class="fas fa-pen"></i> Edit</button>
                  </div>
                  <div style="display:flex; justify-content:center;">
                    <button class="btn-delete-item delete-btn" data-id="<?= $item['ID'] ?>">
                      <i class="fas fa-trash"></i>
                    </button>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

            <!-- Add item button -->
            <button onclick="openAddModal()" class="add-item-btn" style="margin-top:16px;">
              <i class="fas fa-plus-circle"></i> Add New Item
            </button>
          </div>

          <!-- ── RIGHT: Order Queue (with integrated Queue Management) ── -->
          <div class="admin-card">
            <div class="vendor-section-header" style="margin-top:0;">
              <h2><i class="fas fa-clock"></i> Order Queue</h2>
              <div style="display:flex; gap:8px; align-items:center;">
                <button class="btn-primary" style="background:#ff9800; padding:6px 8px; font-size:0.75rem; white-space:nowrap;" onclick="openWalkInModal()">
                  <i class="fas fa-cash-register"></i> Walk-in Sale
                </button>
                <button class="btn-primary" style="font-size:0.75rem; padding:6px 8px; white-space:nowrap;" onclick="nextCustomer()">
                  <i class="fas fa-forward"></i> Next Customer
                </button>
                <button class="btn-secondary" style="font-size:0.75rem; padding:6px 8px; white-space:nowrap;" onclick="resetCounter()">
                  <i class="fas fa-rotate-right"></i> Reset
                </button>
              </div>
            </div>

            <!-- Now Serving Bar -->
            <div style="display:flex; gap:14px; align-items:center; background: linear-gradient(135deg, #e3f4ea, #f0faf5); border: 1.5px solid #b8e0cc; border-radius: 16px; padding: 14px 20px; margin-bottom: 18px;">
              <div style="background: linear-gradient(135deg, #007a3e, #005c2e); color:white; width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.4rem; font-weight:800; flex-shrink:0; box-shadow: 0 4px 12px rgba(0,122,62,0.25);">
                <?php echo $currentServingOrder ? $currentServingOrder['queue_number'] : '—'; ?>
              </div>
              <div style="flex:1;">
                <div style="font-weight:700; font-size:0.9rem; color:#0f4a2f; display:flex; align-items:center; gap:6px;">
                  <span style="background:#007a3e; color:white; font-size:0.6rem; padding:2px 8px; border-radius:10px; font-weight:700; letter-spacing:0.5px;">NOW SERVING</span>
                  <?php if ($currentServingOrder): ?>
                    <span style="font-size:0.75rem; color:#5f8b74; font-weight:500;">
                      <?php 
                        $sLabels = ['P'=>'Pending','PR'=>'Preparing','R'=>'Ready'];
                        echo $sLabels[$currentServingOrder['status']] ?? $currentServingOrder['status'];
                      ?>
                    </span>
                  <?php endif; ?>
                </div>
                <div style="font-size:0.8rem; color:#5f8b74; margin-top:4px;">
                  Next: <?php echo $nextServingOrder ? '#' . $nextServingOrder['queue_number'] : 'N/A'; ?>
                  · Waiting: <?php echo ($pending_orders + $preparing_orders); ?> orders
                </div>
              </div>
            </div>

            <!-- Status filter tabs -->
            <div class="status-filters">
              <span class="filter-tab active" onclick="filterOrdersByStatus('')">All
                <strong><?php echo $total_orders; ?></strong></span>
              <span class="filter-tab" style="background:#f5e6e6; color:#b13e3e; cursor:pointer;"
                onclick="filterOrdersByStatus('P')">Pending
                <strong><?php echo $pending_orders; ?></strong></span>
              <span class="filter-tab" style="background:#fff1cf; color:#9e6d0b; cursor:pointer;"
                onclick="filterOrdersByStatus('PR')">Preparing
                <strong><?php echo $preparing_orders; ?></strong></span>
              <span class="filter-tab" style="background:#c9f0d7; color:#0c6e3a; cursor:pointer;"
                onclick="filterOrdersByStatus('R')">Ready
                <strong><?php echo $ready_orders; ?></strong></span>
              <span class="filter-tab" style="background:#d0e3ff; color:#1f5090; cursor:pointer;"
                onclick="filterOrdersByStatus('C')">Completed
                <strong><?php echo $completed_orders; ?></strong></span>
            </div>

            <!-- Queue header -->
            <div class="queue-item header" style="grid-template-columns: 50px 1.6fr 1fr 0.9fr 1fr 1.4fr;">
              <span>#</span>
              <span>Order Items</span>
              <span>Customer</span>
              <span>Time</span>
              <span>Total</span>
              <span>Status</span>
            </div>

            <!-- Orders -->
            <div class="custom-scroll-area" style="height: 300px;">
              <?php if (empty($orders)): ?>
                <div style="text-align:center; padding:40px; color:#5f8b74;">
                  <i class="fas fa-clipboard-list" style="font-size:2.5rem; opacity:0.3; display:block; margin-bottom:12px;"></i>
                  <p>No orders yet for your stall.</p>
                </div>
              <?php else: ?>
                <?php foreach ($orders as $order): ?>
                  <div class="queue-item" style="grid-template-columns: 50px 1.6fr 1fr 0.9fr 1fr 1.4fr;">
                    <div style="font-weight:800; color:#007a3e; font-size:1.05rem;"><?= $order['queue_number'] ?></div>
                    <div>
                      <?php foreach ($order['items'] as $oi): ?>
                        <div style="font-size:0.85rem; color:#1a4d31;">
                          <span style="font-weight:700;"><?= $oi['quantity'] ?>×</span>
                          <?= htmlspecialchars($oi['name']) ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <div style="font-size:0.9rem;"><?= htmlspecialchars($order['display_name']) ?></div>
                    <div style="font-size:0.85rem; color:#5f8b74;"><?= date('g:i A', strtotime($order['order_date'])) ?></div>
                    <div>
                      <div style="font-weight:600; color:var(--dlsu-green);">₱<?= number_format($order['total_amount'], 2) ?></div>
                      <div style="font-size:0.8rem; color:#4a755e; margin-top:4px;">
                        <?php 
                            $pay_method = strtolower($order['payment_method'] ?? 'cash'); 
                            if ($pay_method === 'cash'): 
                        ?>
                            <i class="fas fa-money-bill-wave"></i> Cash
                        <?php elseif ($pay_method === 'card'): ?>
                            <i class="fas fa-credit-card"></i> Card
                        <?php else: ?>
                            <i class="fas fa-mobile-screen-button"></i> GCash
                        <?php endif; ?>
                      </div>
                    </div>
                    <div>
                      <select class="order-status-select" data-order-id="<?= $order['ID'] ?>" onchange="updateOrderStatus(<?= $order['ID'] ?>, this.value)" style="<?php
                        $status_styles = [
                          'P' => 'background: #fee9e9; color: #b13e3e; border-color: #f5c6cb;',
                          'PR' => 'background: #fff1cf; color: #9e6d0b; border-color: #ffeeba;',
                          'R' => 'background: #c9f0d7; color: #0c6e3a; border-color: #a3dfc9;',
                          'C' => 'background: #d0e3ff; color: #1f5090; border-color: #a8d4f0;'
                        ];
                        echo $status_styles[$order['status']] ?? '';
                        ?>">
                        <option value="P" <?= $order['status'] == 'P' ? 'selected' : '' ?>>Pending</option>
                        <option value="PR" <?= $order['status'] == 'PR' ? 'selected' : '' ?>>Preparing</option>
                        <option value="R" <?= $order['status'] == 'R' ? 'selected' : '' ?>>Ready</option>
                        <option value="C" <?= $order['status'] == 'C' ? 'selected' : '' ?>>Completed</option>
                      </select>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>               
        </div><!-- /admin-grid -->

        <!-- ── Sales Monitoring ── -->
        <div class="admin-card" style="margin-bottom:30px;">
          <div class="vendor-section-header" style="margin-top:0;">
            <h2><i class="fas fa-chart-simple"></i> Sales · Performance Analytics</h2>
            <div style="display:flex; gap:8px;">
              <button class="sync-badge period-btn active-period"
                onclick="setAnalyticsPeriod('today', this)">Today</button>
              <button class="sync-badge period-btn" onclick="setAnalyticsPeriod('this_week', this)">This Week</button>
              <button class="sync-badge period-btn" onclick="setAnalyticsPeriod('this_month', this)">This Month</button>
              <button class="sync-badge period-btn" onclick="setAnalyticsPeriod('all', this)">All Time</button>
            </div>
          </div>

          <!-- Analytics data embedded for JS -->
          <script id="analytics-data" type="application/json">
            <?php echo json_encode($analytics); ?>
          </script>
          <script id="bestsellers-data" type="application/json">
            <?php echo json_encode($bestSellersByPeriod); ?>
          </script>

          <!-- Sales cards -->
          <div class="sales-grid">
            <div class="sales-card">
              <div class="sales-label">Total Revenue</div>
              <div class="sales-number" id="analytics-revenue">
                ₱<?php echo number_format($analytics['today']['revenue'], 2); ?></div>
              <div class="sales-change" id="analytics-revenue-sub"><i class="fas fa-calendar-day"></i> Today</div>
            </div>
            <div class="sales-card">
              <div class="sales-label">Fulfilled Orders</div>
              <div class="sales-number" id="analytics-orders"><?php echo $analytics['today']['orders']; ?></div>
              <div class="sales-change" style="color:#5f8b74;" id="analytics-orders-sub">
                <?php echo $fulfilled_orders_count; ?> total completed · <?php echo $pending_orders; ?> pending
              </div>
            </div>
            <div class="sales-card">
              <div class="sales-label">Avg. Order Value</div>
              <div class="sales-number" id="analytics-avg">₱<?php echo number_format($analytics['today']['avg'], 2); ?>
              </div>
              <div class="sales-change" style="color:#5f8b74;" id="analytics-avg-sub">
                <?php
                $diff = $avg_order_value - $last_week_avg_order_value;
                $sign = $diff >= 0 ? '+' : '';
                echo $sign . number_format($diff, 2);
                ?> vs last week
              </div>
            </div>
          </div>

          <!-- Best sellers -->
          <div class="best-sellers">
            <div class="best-sellers-header">
              <i class="fas fa-crown"></i>
              <span class="best-sellers-title">Best-Selling Items</span>
              <span class="best-badge">Top performers</span>
            </div>
            <div class="items-grid">
              <div class="item-rank">
                <div class="rank-number rank-1">1</div>
                <div class="item-details">
                  <div style="font-weight:600;" id="bs-name-0"><?php echo htmlspecialchars($best_selling_items[0]['item_name']); ?></div>
                  <div class="item-count" id="bs-count-0"><?php echo $best_selling_items[0]['order_count']; ?> orders</div>
                </div>
              </div>
              <div class="item-rank">
                <div class="rank-number rank-2">2</div>
                <div class="item-details">
                  <div style="font-weight:600;" id="bs-name-1"><?php echo htmlspecialchars($best_selling_items[1]['item_name']); ?></div>
                  <div class="item-count" id="bs-count-1"><?php echo $best_selling_items[1]['order_count']; ?> orders</div>
                </div>
              </div>
              <div class="item-rank">
                <div class="rank-number rank-3">3</div>
                <div class="item-details">
                  <div style="font-weight:600;" id="bs-name-2"><?php echo htmlspecialchars($best_selling_items[2]['item_name']); ?></div>
                  <div class="item-count" id="bs-count-2"><?php echo $best_selling_items[2]['order_count']; ?> orders</div>
                </div>
              </div>
            </div>
            <div class="other-items">
              <?php for ($r = 3; $r < 6; $r++): ?>
                <div id="bs-other-<?php echo $r; ?>">
                  <i class="fas fa-fire" style="color:#ff7b7b;"></i>
                  <span id="bs-other-name-<?php echo $r; ?>"><?php echo htmlspecialchars($best_selling_items[$r]['item_name']); ?></span>
                  (<span id="bs-other-count-<?php echo $r; ?>"><?php echo $best_selling_items[$r]['order_count']; ?></span>)
                </div>
              <?php endfor; ?>
            </div>
          </div>
        </div>

        <!-- ── Recent Transactions ── -->
        <div class="queue-management" style="grid-template-columns: 1fr;">
          <!-- Recent Transactions -->
          <div class="queue-card">
            <h3 style="font-size:1.2rem; color:#0f4a2f; margin-bottom:20px;">
              <i class="fas fa-file-invoice" style="color:var(--dlsu-green);"></i> Recent Transactions
            </h3>
            <div class="transactions-list">
              <?php if (!empty($orders)): ?>
                <?php foreach (array_slice($orders, 0, 4) as $txn):
                  $sc = ['C' => 'status-completed', 'PR' => 'status-preparing', 'P' => 'status-pending', 'R' => 'status-ready'];
                  $st = ['C' => 'Completed', 'PR' => 'Preparing', 'P' => 'Pending', 'R' => 'Ready'];
                  $itemNames = array_map(fn($i) => $i['name'], $txn['items']);
                  ?>
                  <div class="transaction-row">
                    <div>
                      <span class="transaction-id">#<?php echo $txn['queue_number']; ?></span>
                      <span class="transaction-items" style="font-size:0.85rem; color:#5f8b74; margin-left:6px;">
                        <?php echo htmlspecialchars(implode(', ', $itemNames)); ?>
                      </span>
                    </div>
                    <span class="transaction-amount"><?php echo formatPrice($txn['total_amount']); ?></span>
                    <span class="transaction-status <?php echo $sc[$txn['status']] ?? ''; ?>" style="<?php echo getTransactionStatusStyle($txn['status']); ?>">
                      <?php echo $st[$txn['status']] ?? $txn['status']; ?>
                    </span>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div style="text-align:center; padding:30px; color:#999;">No transactions yet</div>
              <?php endif; ?>
            </div>
            <button class="btn-outline" style="width:100%; margin-top:8px; font-size:0.9rem;"
              onclick="openAllTransactionsModal()">
              <i class="fas fa-receipt"></i> View All Transactions
            </button>
          </div>
        </div>

      </div><!-- /wrapper -->

      <footer class="footer-note">
        <i class="fas fa-store-alt"></i>
        <?php echo htmlspecialchars($restaurant['name'] ?? 'Vendor Portal'); ?> ·
        UniCanteen Vendor Dashboard · <?php echo date('g:i A'); ?>
      </footer>

    </section>
  </div>

  <!-- ── Add Item Modal ── -->
  <div id="addItemModal" class="modal-overlay">
    <div class="modal-card">
      <h3><i class="fas fa-plus-circle"></i> Add New Menu Item</h3>
      <form id="addItemForm">
        <div class="modal-field">
          <label>Item Name</label>
          <input type="text" name="name" placeholder="e.g. Chicken Bowl" required>
        </div>
        <div class="modal-field">
          <label>Description</label>
          <textarea name="description" placeholder="e.g. Grilled chicken with rice and vegetables" rows="2"></textarea>
        </div>
        <div class="modal-field">
          <label>Price (₱)</label>
          <input type="number" name="price" placeholder="0.00" required min="0" step="0.01">
        </div>
        <div class="modal-field">
          <label>Status</label>
          <select name="status">
            <option value="available">Available</option>
            <option value="sold_out">Sold Out</option>
          </select>
        </div>
        <div class="modal-field">
          <label>Item Image</label>
          <div class="file-drop-zone" id="addDropZone">
            <i class="fas fa-cloud-upload-alt"></i>
            <p>Drag & Drop your image here</p>
            <span>or click to browse files</span>
            <input type="file" name="image" id="addFileInput" accept="image/*"
              onchange="previewImage(this, 'addDropZone', 'preview-img', 'image-preview')">
          </div>
          <div id="image-preview" class="img-preview-container" style="display: none;">
            <button type="button" class="remove-preview-btn"
              onclick="removePreview('addFileInput', 'preview-img', 'image-preview', 'addDropZone')"
              title="Remove Image">
              <i class="fas fa-times"></i>
            </button>
            <img id="preview-img" src="" alt="Preview">
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-modal-cancel" onclick="closeAddModal()">Cancel</button>
          <button type="submit" class="btn-modal-submit"><i class="fas fa-plus"></i> Add Item</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Edit Item Modal ── -->
  <div id="editItemModal" class="modal-overlay">
    <div class="modal-card">
      <h3><i class="fas fa-pen"></i> Edit Menu Item</h3>
      <form id="editItemForm">
        <input type="hidden" name="item_id" id="edit_item_id">
        <div class="modal-field">
          <label>Item Name</label>
          <input type="text" name="item_name" id="edit_item_name" required>
        </div>
        <div class="modal-field">
          <label>Description</label>
          <textarea name="description" id="edit_item_description" placeholder="e.g. Grilled chicken with rice and vegetables" rows="2"></textarea>
        </div>
        <div class="modal-field">
          <label>Price (₱)</label>
          <input type="number" name="price" id="edit_item_price" required min="0" step="0.01">
        </div>
        <div class="modal-field">
          <label>Availability</label>
          <select name="availability" id="edit_item_availability">
            <option value="1">Available</option>
            <option value="0">Sold Out</option>
          </select>
        </div>
        <div class="modal-field">
          <label>Item Image (Optional)</label>
          <div class="file-drop-zone" id="editDropZone">
            <i class="fas fa-cloud-upload-alt"></i>
            <p>Drag & Drop a new image here</p>
            <span>or click to browse files</span>
            <input type="file" name="image" id="editFileInput" accept="image/*"
              onchange="previewImage(this, 'editDropZone', 'edit-preview-img', 'edit-image-preview')">
          </div>
          <div id="edit-image-preview" class="img-preview-container" style="display: none;">
            <button type="button" class="remove-preview-btn"
              onclick="removePreview('editFileInput', 'edit-preview-img', 'edit-image-preview', 'editDropZone')"
              title="Remove Image">
              <i class="fas fa-times"></i>
            </button>
            <img id="edit-preview-img" src="" alt="Preview">
          </div>
          <small style="color: #666; font-size: 0.85rem; display: block; margin-top: 8px;">Leave empty to keep current
            image</small>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-modal-cancel" onclick="closeEditModal()">Cancel</button>
          <button type="submit" class="btn-modal-submit"><i class="fas fa-check"></i> Update Item</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── All Transactions Modal ── -->
  <div id="allTransactionsModal" class="modal-overlay">
    <div class="modal-card" style="max-width: 700px; max-height: 80vh; overflow-y: auto;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3><i class="fas fa-receipt"></i> All Transactions</h3>
        <button type="button" class="btn-modal-cancel" onclick="closeAllTransactionsModal()"
          style="padding: 5px 10px; font-size: 0.9rem;">Close</button>
      </div>
      <div class="all-transactions-list" style="border: 1px solid #e0e0e0; border-radius: 8px;">
        <?php if (!empty($orders)): ?>
          <?php
          $statusClasses = ['C' => 'status-completed', 'PR' => 'status-preparing', 'P' => 'status-pending', 'R' => 'status-ready'];
          $statusNames = ['C' => 'Completed', 'PR' => 'Preparing', 'P' => 'Pending', 'R' => 'Ready'];
          ?>
          <?php foreach ($orders as $txn):
            $itemNames = array_map(fn($i) => $i['name'], $txn['items']);
            ?>
            <div
              style="display: grid; grid-template-columns: 1fr 1.5fr 1fr 1fr; gap: 12px; padding: 12px; border-bottom: 1px solid #f0f0f0; align-items: center;">
              <div>
                <span
                  style="font-weight: 600; color: #0f4a2f; font-size: 0.95rem;">#<?php echo $txn['queue_number']; ?></span>
                <div style="font-size: 0.8rem; color: #5f8b74; margin-top: 4px;">
                  <?php echo date('M d, g:i A', strtotime($txn['order_date'])); ?>
                </div>
              </div>
              <div style="font-size: 0.85rem; color: #333;">
                <?php echo htmlspecialchars(implode(', ', $itemNames)); ?>
              </div>
              <div style="font-weight: 600; color: var(--dlsu-green); text-align: right;">
                <?php echo formatPrice($txn['total_amount']); ?>
              </div>
              <div style="text-align: right;">
                <span class="transaction-status <?php echo $statusClasses[$txn['status']] ?? ''; ?>"
                  style="padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500; <?php echo getTransactionStatusStyle($txn['status']); ?>">
                  <?php echo $statusNames[$txn['status']] ?? $txn['status']; ?>
                </span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="text-align: center; padding: 40px; color: #999;">
            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
            No transactions yet
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Walk-in Sale Modal -->
  <div id="walkin-modal" class="modal-overlay">
    <div class="modal-card" style="width: 500px;">
      <h3><i class="fas fa-cash-register"></i> Walk-in Sale</h3>
      
      <form id="walkin-form">
        <input type="hidden" name="restaurant_id" value="<?= $restaurant_id ?>">

        <div class="modal-field">
            <label>Customer Name (Optional)</label>
            <input type="text" name="walkin_name" placeholder="Leave blank for 'Walk-in Customer'" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px; font-family: 'Inter', sans-serif;">
        </div>
        
        <div class="modal-field">
          <label>Select Items</label>
          <div class="custom-scroll-area" style="border: 1.5px solid #cae3d6; border-radius: 14px; padding: 16px; background: #f9fffc; max-height: 380px; overflow-y: auto;">
              <?php foreach ($menu_items as $item): ?>
                <?php if ($item['isAvailable']): ?>
                  <div class="walkin-item-row" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #e1e8e4;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                      <?php if (!empty($item['image_url'])): ?>
                        <img src="<?= htmlspecialchars(url($item['image_url'])) ?>" alt="<?= htmlspecialchars($item['name']) ?>" style="width: 48px; height: 48px; object-fit: cover; border-radius: 8px;">
                      <?php else: ?>
                        <div style="width: 48px; height: 48px; border-radius: 8px; background: #e3f4ea; display: flex; align-items: center; justify-content: center; color: var(--dlsu-green);">
                          <i class="fas fa-utensils"></i>
                        </div>
                      <?php endif; ?>
                      <div>
                        <div style="font-weight: 600; color: #1a4d31;"><?= htmlspecialchars($item['name']) ?></div>
                        <div style="font-size: 0.85rem; color: var(--dlsu-green); font-weight: 700;">₱<span class="walkin-item-price"><?= number_format($item['price'], 2, '.', '') ?></span></div>
                      </div>
                    </div>
                    
                    <div style="display: flex; align-items: center; gap: 8px; background: white; border: 1.5px solid #cae3d6; border-radius: 30px; padding: 4px;">
                      <button type="button" class="qty-btn minus" onclick="updateWalkInQty('<?= $item['ID'] ?>', -1)" style="width: 28px; height: 28px; border-radius: 50%; border: none; background: #f0f7f2; color: #2d6347; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;"><i class="fas fa-minus"></i></button>
                      <input type="number" name="items[<?= $item['ID'] ?>]" id="walkin_qty_<?= $item['ID'] ?>" class="walkin-qty-input" value="0" min="0" style="width: 32px; border: none; text-align: center; font-weight: 700; color: #1a4d31; padding: 0; background: transparent;" readonly>
                      <button type="button" class="qty-btn plus" onclick="updateWalkInQty('<?= $item['ID'] ?>', 1)" style="width: 28px; height: 28px; border-radius: 50%; border: none; background: #e3f4ea; color: var(--dlsu-green); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;"><i class="fas fa-plus"></i></button>
                    </div>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if (!$available_count): ?>
                <div style="text-align: center; color: #888; padding: 10px;">No available items.</div>
              <?php endif; ?>
          </div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding-top: 16px; border-top: 2px dashed #cae3d6;">
            <span style="font-weight: 600; color: #1a4d31; font-size: 1.1rem;">Total Amount:</span>
            <span style="font-weight: 800; color: var(--dlsu-green); font-size: 1.3rem;">₱<span id="walkin-total">0.00</span></span>
          </div>
        </div>

        <div class="modal-field">
            <label>Payment Method</label>
            <select name="payment_method" id="payment_method">
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
                <option value="card">Card</option>
            </select>
        </div>

        <div class="modal-actions">
          <button type="button" onclick="closeWalkInModal()" class="btn-modal-cancel">Cancel</button>
          <button type="submit" class="btn-modal-submit">Create Order</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Filter orders by status
    function filterOrdersByStatus(status) {
      const rows = document.querySelectorAll('.queue-item:not(.header)');
      const filterTabs = document.querySelectorAll('.filter-tab');

      // Update active tab styling
      filterTabs.forEach(tab => tab.classList.remove('active'));
      event.target.closest('.filter-tab')?.classList.add('active');

      // Filter and display rows
      rows.forEach(row => {
        if (!status) {
          row.style.display = '';
        } else {
          const selectElement = row.querySelector('.order-status-select');
          row.style.display = selectElement?.value === status ? '' : 'none';
        }
      });
    }

    // Handles AJAX request to update the status of an existing order
    function updateOrderStatus(orderId, status) {
      // Disable the select temporarily
      const selectElement = event.target;
      selectElement.disabled = true;
      const originalStatus = selectElement.dataset.originalStatus || selectElement.value;

      fetch('<?php echo url('frontend/update_order_status.php'); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'order_id=' + orderId + '&status=' + status
      })
        .then(res => res.json())
        .then(data => {
          selectElement.disabled = false;

          if (data.success) {
            // Update the dropdown styling based on new status
            updateStatusDisplay(selectElement, status);

            // Show success message
            showStatusNotification(`Order #${orderId} status changed to ${data.status_name}`, 'success');

            // Store the original status for potential rollback
            selectElement.dataset.originalStatus = status;

            // Optionally refresh the page after 1 second to reflect all changes
            setTimeout(() => {
              location.reload();
            }, 1000);
          } else {
            // Revert to original status on error
            selectElement.value = originalStatus;
            showStatusNotification(`Error: ${data.error || 'Failed to update order status'}`, 'error');
          }
        })
        .catch(error => {
          selectElement.disabled = false;
          selectElement.value = originalStatus;
          console.error('Update error:', error);
          showStatusNotification('Network error. Please try again.', 'error');
        });
    }

    // Updates the visual styling (colors) of the order status dropdown based on its value
    function updateStatusDisplay(selectElement, status) {
      // Update the styling of the select based on status
      const statusColors = {
        'P': '#fee9e9',    // light red for pending
        'PR': '#fff1cf',   // light yellow for preparing
        'R': '#c9f0d7',    // light green for ready
        'C': '#d0e3ff'     // light blue for completed
      };

      const statusTextColors = {
        'P': '#b13e3e',
        'PR': '#9e6d0b',
        'R': '#0c6e3a',
        'C': '#1f5090'
      };

      const statusBorderColors = {
        'P': '#f5c6cb',
        'PR': '#ffeeba',
        'R': '#a3dfc9',
        'C': '#a8d4f0'
      };

      if (statusColors[status]) {
        selectElement.style.background = statusColors[status];
        selectElement.style.color = statusTextColors[status];
        selectElement.style.borderColor = statusBorderColors[status];
      }
    }

    // Displays a temporary floating notification on the screen (success/error)
    function showStatusNotification(message, type) {
      // Create a temporary notification element
      const notification = document.createElement('div');
      notification.textContent = message;
      notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 16px 24px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 0.95rem;
        z-index: 10000;
        animation: slideIn 0.3s ease-out;
        ${type === 'success' ? 'background: #d4edda; color: #155724; border: 1px solid #c3e6cb;' : 'background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;'}
      `;

      document.body.appendChild(notification);

      // Auto-remove after 3 seconds
      setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => notification.remove(), 300);
      }, 3000);
    }

    // Add CSS animations if not already in stylesheet
    if (!document.querySelector('style[data-notification-styles]')) {
      const style = document.createElement('style');
      style.setAttribute('data-notification-styles', '');
      style.textContent = `
        @keyframes slideIn {
          from {
            transform: translateX(400px);
            opacity: 0;
          }
          to {
            transform: translateX(0);
            opacity: 1;
          }
        }
        @keyframes slideOut {
          from {
            transform: translateX(0);
            opacity: 1;
          }
          to {
            transform: translateX(400px);
            opacity: 0;
          }
        }

        .filter-tab {
          cursor: pointer;
          transition: all 0.25s ease;
        }

        .filter-tab:hover {
          transform: translateY(-2px);
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .filter-tab.active {
          box-shadow: 0 4px 16px rgba(0, 122, 62, 0.25);
          border-bottom: 3px solid var(--dlsu-green);
        }
      `;
      document.head.appendChild(style);
    }

    // File Drag and Drop & Preview Functions
    function previewImage(input, dropZoneId, previewImgId, previewContainerId) {
      const previewCont = document.getElementById(previewContainerId);
      const previewImg = document.getElementById(previewImgId);
      const dropZone = document.getElementById(dropZoneId);

      if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function (e) {
          previewImg.src = e.target.result;
          previewCont.style.display = 'inline-block';
          if (dropZone) dropZone.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
      } else {
        previewCont.style.display = 'none';
        if (dropZone) dropZone.style.display = 'block';
      }
    }

    function removePreview(inputId, previewImgId, previewContainerId, dropZoneId) {
      document.getElementById(inputId).value = '';
      document.getElementById(previewImgId).src = '';
      document.getElementById(previewContainerId).style.display = 'none';
      document.getElementById(dropZoneId).style.display = 'block';
    }

    // Setup drag and drop for a specific zone
    function setupDragAndDrop(dropZoneId, inputId) {
      const dropZone = document.getElementById(dropZoneId);
      const input = document.getElementById(inputId);

      if (!dropZone || !input) return;

      ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
      });

      function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
      }

      ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
      });

      ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
      });

      dropZone.addEventListener('drop', handleDrop, false);

      function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;

        if (files && files.length > 0) {
          input.files = files;
          // Trigger change event to load preview
          const event = new Event('change');
          input.dispatchEvent(event);
        }
      }
    }

    // Initialize drag and drop on load
    document.addEventListener('DOMContentLoaded', () => {
      setupDragAndDrop('addDropZone', 'addFileInput');
      setupDragAndDrop('editDropZone', 'editFileInput');
    });

    // Toggle item availability via AJAX
    function toggleItemStatus(btn) {
      const itemId = btn.dataset.itemId;
      const currentlyAvailable = btn.dataset.available === '1';
      const newStatus = currentlyAvailable ? 0 : 1;

      // Optimistic UI update
      const icon = btn.querySelector('i');
      if (newStatus) {
        btn.className = 'avail-pill yes';
        icon.className = 'fas fa-check-circle';
        btn.lastChild.textContent = ' Available';
        btn.dataset.available = '1';
      } else {
        btn.className = 'avail-pill no';
        icon.className = 'fas fa-times-circle';
        btn.lastChild.textContent = ' Sold Out';
        btn.dataset.available = '0';
      }

      fetch('<?php echo url('frontend/toggle_item_status.php'); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'item_id=' + itemId + '&status=' + newStatus
      })
      .then(r => r.json())
      .then(d => {
        if (d.success) {
          showStatusNotification(
            newStatus ? 'Item marked as Available' : 'Item marked as Sold Out',
            'success'
          );
          setTimeout(() => location.reload(), 1000);
        } else {
          // Revert on failure
          if (currentlyAvailable) {
            btn.className = 'avail-pill yes';
            icon.className = 'fas fa-check-circle';
            btn.lastChild.textContent = ' Available';
            btn.dataset.available = '1';
          } else {
            btn.className = 'avail-pill no';
            icon.className = 'fas fa-times-circle';
            btn.lastChild.textContent = ' Sold Out';
            btn.dataset.available = '0';
          }
          showStatusNotification('Failed to update availability', 'error');
        }
      })
      .catch(() => {
        // Revert on error
        if (currentlyAvailable) {
          btn.className = 'avail-pill yes';
          icon.className = 'fas fa-check-circle';
          btn.lastChild.textContent = ' Available';
          btn.dataset.available = '1';
        } else {
          btn.className = 'avail-pill no';
          icon.className = 'fas fa-times-circle';
          btn.lastChild.textContent = ' Sold Out';
          btn.dataset.available = '0';
        }
        showStatusNotification('Network error. Please try again.', 'error');
      });
    }


    // Modal Management Functions - For Add Item, Edit Item, and Transactions modals
    function openAddModal() {
      document.getElementById('addItemModal').style.display = 'flex';
      removePreview('addFileInput', 'preview-img', 'image-preview', 'addDropZone');
      document.getElementById('addItemForm').reset();
    }
    function closeAddModal() { document.getElementById('addItemModal').style.display = 'none'; }

    function openEditModal(id, name, price, isAvailable, description) {
      document.getElementById('editItemModal').style.display = 'flex';
      document.getElementById('edit_item_id').value = id;
      document.getElementById('edit_item_name').value = name;
      document.getElementById('edit_item_price').value = price;
      document.getElementById('edit_item_availability').value = isAvailable ? '1' : '0';
      document.getElementById('edit_item_description').value = description || '';
      removePreview('editFileInput', 'edit-preview-img', 'edit-image-preview', 'editDropZone');
    }
    function closeEditModal() { document.getElementById('editItemModal').style.display = 'none'; }
    function openAllTransactionsModal() { document.getElementById('allTransactionsModal').style.display = 'flex'; }
    function closeAllTransactionsModal() { document.getElementById('allTransactionsModal').style.display = 'none'; }

    // Walk-in order modal and cart logic
    function openWalkInModal() {
        document.getElementById('walkin-modal').style.display = 'flex';
        // Reset quantities when opening
        document.querySelectorAll('.walkin-qty-input').forEach(input => input.value = 0);
        calculateWalkInTotal();
    }

    function closeWalkInModal() {
        document.getElementById('walkin-modal').style.display = 'none';
    }

    function updateWalkInQty(id, change) {
      const input = document.getElementById('walkin_qty_' + id);
      let newQty = parseInt(input.value) + change;
      if (newQty < 0) newQty = 0;
      input.value = newQty;
      calculateWalkInTotal();
    }

    function calculateWalkInTotal() {
      let total = 0;
      const rows = document.querySelectorAll('.walkin-item-row');
      rows.forEach(row => {
        const price = parseFloat(row.querySelector('.walkin-item-price').textContent);
        const qty = parseInt(row.querySelector('.walkin-qty-input').value) || 0;
        total += price * qty;
      });
      document.getElementById('walkin-total').textContent = total.toFixed(2);
    }

    // Queue Management Functions
    // Automatically marks the next 'Pending' or 'Preparing' order as 'Completed'
    function nextCustomer() {
      if (confirm("Mark current customer as completed and move to next customer?")) {
        // Find the oldest pending, preparing, or ready order
        const orderSelects = document.querySelectorAll('.order-status-select');
        let foundSelect = null;
        let currentOrderId = null;

        // Iterate backwards because the DOM list is ordered DESC (newest first)
        for (let i = orderSelects.length - 1; i >= 0; i--) {
          let select = orderSelects[i];
          if (select.value === 'P' || select.value === 'PR' || select.value === 'R') {
            currentOrderId = select.dataset.orderId;
            foundSelect = select;
            break;
          }
        }

        if (currentOrderId && foundSelect) {
          // Directly call the status update without relying on event.target
          foundSelect.disabled = true;
          fetch('<?php echo url('frontend/update_order_status.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'order_id=' + currentOrderId + '&status=C'
          })
            .then(res => res.json())
            .then(data => {
              foundSelect.disabled = false;
              if (data.success) {
                updateStatusDisplay(foundSelect, 'C');
                foundSelect.value = 'C';
                showStatusNotification('Order #' + currentOrderId + ' marked as completed', 'success');
                setTimeout(() => location.reload(), 1000);
              } else {
                showStatusNotification('Error: ' + (data.error || 'Failed to update order'), 'error');
              }
            })
            .catch(() => {
              foundSelect.disabled = false;
              showStatusNotification('Network error. Please try again.', 'error');
            });
        } else {
          showStatusNotification('No pending orders in queue', 'error');
        }
      }
    }

    // Resets the order queue numbering back to 1 for a new business day
    function resetCounter() {
      if (confirm("Reset the queue counter? This will restart queue numbering from 1.")) {
        fetch('<?php echo url('frontend/reset_queue.php'); ?>', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=reset_counter'
        })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              showStatusNotification('Queue counter reset successfully', 'success');
              setTimeout(() => location.reload(), 1000);
            } else {
              showStatusNotification(`Error: ${data.error || 'Failed to reset counter'}`, 'error');
            }
          })
          .catch(error => {
            console.error('Reset error:', error);
            showStatusNotification('Network error. Please try again.', 'error');
          });
      }
    }

    // Close modals on overlay click
    // Close modals when clicking outside the modal content (on the overlay)
    ['addItemModal', 'editItemModal', 'allTransactionsModal'].forEach(id => {
      document.getElementById(id).addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
      });
    });

    // Handle Edit Item form submission via AJAX
    document.getElementById('editItemForm').addEventListener('submit', function (e) {
      e.preventDefault();
      fetch('<?php echo url('frontend/edit_item.php'); ?>', { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(d => {
          if (d.success) { alert("Item updated!"); location.reload(); }
          else alert("Failed to update item.");
        });
    });

    // Add Item Form handling
    document.getElementById('addItemForm').addEventListener('submit', function (e) {
      e.preventDefault();
      
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';

      fetch('<?php echo url('frontend/add_item.php'); ?>', { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(d => {
          if (d.success) {
            showStatusNotification("Item added successfully!", "success");
            setTimeout(() => location.reload(), 1000);
          } else {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            showStatusNotification("Failed to add item." + (d.error ? ' ' + d.error : ''), "error");
          }
        })
        .catch(err => {
          console.error('Add item error:', err);
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalText;
          showStatusNotification('Network or server error.', 'error');
        });
    });

    // Handle item deletion with confirmation
    document.querySelectorAll('.delete-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        if (confirm("Delete this item?")) {
          fetch('<?php echo url('frontend/delete_item.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${this.dataset.id}`
          })
            .then(r => r.json())
            .then(d => {
              if (d.success) { alert("Item deleted!"); location.reload(); }
              else alert("Failed to delete item.");
            });
        }
      });
    });

    // ── Analytics Period Filter ──
    const analyticsData = JSON.parse(document.getElementById('analytics-data').textContent);
    const bestsellersData = JSON.parse(document.getElementById('bestsellers-data').textContent);
    const periodLabels = { today: 'Today', this_week: 'This Week', this_month: 'This Month', all: 'All Time' };

    function setAnalyticsPeriod(period, btn) {
      // Update active button
      document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active-period'));
      btn.classList.add('active-period');

      const d = analyticsData[period];
      document.getElementById('analytics-revenue').textContent = '₱' + parseFloat(d.revenue).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      document.getElementById('analytics-orders').textContent = d.orders;
      document.getElementById('analytics-avg').textContent = '₱' + parseFloat(d.avg).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      document.getElementById('analytics-revenue-sub').innerHTML = '<i class="fas fa-calendar-day"></i> ' + periodLabels[period];

      // Update best-sellers for the selected period
      const bs = bestsellersData[period];
      if (bs) {
        for (let i = 0; i < 3; i++) {
          const nameEl = document.getElementById('bs-name-' + i);
          const countEl = document.getElementById('bs-count-' + i);
          if (nameEl) nameEl.textContent = bs[i].item_name;
          if (countEl) countEl.textContent = bs[i].order_count + ' orders';
        }
        for (let i = 3; i < 6; i++) {
          const nameEl = document.getElementById('bs-other-name-' + i);
          const countEl = document.getElementById('bs-other-count-' + i);
          if (nameEl) nameEl.textContent = bs[i].item_name;
          if (countEl) countEl.textContent = bs[i].order_count;
        }
      }
    }

    // Walk-in Form handling
    document.getElementById('walkin-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);

        fetch('<?php echo url("frontend/create_walkin_order.php"); ?>', { 
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.redirected && response.url.includes('error=')) {
                throw new Error("Please select at least one item with a quantity greater than 0.");
            }
            if (!response.ok) {
                throw new Error('Server error.');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
               showStatusNotification(`Order Created! Queue #${data.queue_number}`, 'success');
               setTimeout(() => location.reload(), 1500);
            } else {
               showStatusNotification(data.error || 'Failed to create order', 'error');
            }
        })
        .catch(error => {
            console.error('Walk-in Error:', error);
            showStatusNotification(error.message, 'error');
        });
    });
  </script>
</body>

</html>