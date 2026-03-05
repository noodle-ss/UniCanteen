<?php
// require_once '../config/auth_check.php';
require_once '../config/database.php';

// requireVendorLogin();

// $user_id = $_SESSION['user_id'] ?? null;

// if (!$user_id) {
//     header("Location: login.php");
//     exit();
// }

$query = "SELECT full_name FROM Users WHERE ID = ?";
$stmt = Database::getInstance()->getConnection()->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Get vendor info (restaurant name, etc.)
$query = "SELECT * FROM Restaurants WHERE user_id = ?";
$stmt = Database::getInstance()->getConnection()->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$restaurant = $stmt->get_result()->fetch_assoc();

$query = "SELECT * FROM Items WHERE vendor_id = ?";
$stmt = Database::getInstance()->getConnection()->prepare($query);
$stmt->bind_param("i", $restaurant['id']);
$stmt->execute();
$menu_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>



<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>UniCanteen · Vendor Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css">
  <style>
    body {
      display: block;
      min-height: auto;
    }
    .main-content {
      margin-left: 0;
    }
  </style>
</head>
<body>
  <div class="main-content">
    <section id="vendor" class="page-section">
      <div class="admin-header">
        <div class="admin-logo"><i class="fas fa-store"></i> UniCanteen · Vendor Portal</div>
        <div class="role-badge"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($restaurant['name'] ?? 'Restaurant'); ?></div>
      </div>
      <div class="server-container">
        <!-- Dashboard Overview Cards -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px;">
          <div style="background: white; border-radius: 20px; padding: 20px; border: 1px solid var(--border-soft);">
            <div style="color: #5f8b74; font-size: 0.9rem; margin-bottom: 8px;">Today's Revenue</div>
            <div style="font-size: 2rem; font-weight: 700; color: var(--dlsu-green);"><?php echo formatPrice($total_revenue); ?></div>
            <div style="color: #3b7455; font-size: 0.8rem; margin-top: 8px;"><i class="fas fa-arrow-up" style="color: #28a745;"></i> Updated live</div>
          </div>
          <div style="background: white; border-radius: 20px; padding: 20px; border: 1px solid var(--border-soft);">
            <div style="color: #5f8b74; font-size: 0.9rem; margin-bottom: 8px;">Total Orders</div>
            <div style="font-size: 2rem; font-weight: 700; color: var(--dlsu-green);"><?php echo $total_orders; ?></div>
            <div style="color: #3b7455; font-size: 0.8rem; margin-top: 8px;"><?php echo $pending_orders; ?> pending · <?php echo $completed_orders; ?> completed</div>
          </div>
          <div style="background: white; border-radius: 20px; padding: 20px; border: 1px solid var(--border-soft);">
            <div style="color: #5f8b74; font-size: 0.9rem; margin-bottom: 8px;">Queue Status</div>
            <div style="font-size: 2rem; font-weight: 700; color: var(--dlsu-green);">Active</div>
            <div style="color: #3b7455; font-size: 0.8rem; margin-top: 8px;">Serving customers · <?php echo $pending_orders; ?> pending</div>
          </div>
          <div style="background: white; border-radius: 20px; padding: 20px; border: 1px solid var(--border-soft);">
            <div style="color: #5f8b74; font-size: 0.9rem; margin-bottom: 8px;">Inventory Health</div>
            <div style="font-size: 2rem; font-weight: 700; color: var(--dlsu-green);">9/12</div>
            <div style="color: #3b7455; font-size: 0.8rem; margin-top: 8px;"><?php echo $sold_out_count; ?> sold out · <?php echo $low_stock_count; ?> low stock</div>
          </div>
        </div>

        <!-- Main Grid: Menu Management & Orders Dashboard -->
        <div class="admin-grid">
          <!-- menu management + inventory summary -->
          <div class="admin-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
              <h3 style="margin: 0;"><i class="fas fa-pen-to-square"></i> Menu Management</h3>
              <span style="background: var(--dlsu-lightgreen); padding: 6px 14px; border-radius: 30px; font-size: 0.8rem; font-weight: 600; color: var(--dlsu-green);">
                <i class="fas fa-rotate"></i> Live Sync
              </span>
            </div>
            
            <!-- Inventory Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 24px;">
              <div style="background: #e3f4ea; border-radius: 16px; padding: 12px; text-align: center;">
                <div style="font-size: 0.7rem; color: #1a633a; text-transform: uppercase;">Total</div>
                <div style="font-weight: 700; font-size: 1.5rem; color: #0b4d2c;"><?php echo $total_items; ?></div>
              </div>
              <div style="background: #d4f0e0; border-radius: 16px; padding: 12px; text-align: center;">
                <div style="font-size: 0.7rem; color: #1a633a; text-transform: uppercase;">Available</div>
                <div style="font-weight: 700; font-size: 1.5rem; color: #0b4d2c;"><?php echo $available_count; ?></div>
              </div>
              <div style="background: #fee9e9; border-radius: 16px; padding: 12px; text-align: center;">
                <div style="font-size: 0.7rem; color: #b13e3e; text-transform: uppercase;">Sold Out</div>
                <div style="font-weight: 700; font-size: 1.5rem; color: #b13e3e;"><?php echo $sold_out_count; ?></div>
              </div>
              <div style="background: #fff1cf; border-radius: 16px; padding: 12px; text-align: center;">
                <div style="font-size: 0.7rem; color: #9e6d0b; text-transform: uppercase;">Low Stock</div>
                <div style="font-weight: 700; font-size: 1.5rem; color: #9e6d0b;"><?php echo $low_stock_count; ?></div>
              </div>
            </div>

            <!-- Menu Items with Toggle -->
            <div style="margin-bottom: 16px;">
              <div style="display: grid; grid-template-columns: 2fr 80px 120px; padding: 10px 0; border-bottom: 2px solid #b1d9c4; font-weight: 600; color: #16623b; font-size: 0.9rem;">
                <span>Item Name</span><span>Price</span><span style="text-align: center;">Status</span>
              </div>
              
              <div class="menu-edit-row">
                <div class="item-info"><i class="fas fa-burger"></i><span class="item-name">Cheeseburger</span></div>
                <span class="item-price">₱85</span>
                <div style="display: flex; justify-content: center;">
                  <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" checked style="accent-color: var(--dlsu-green); width: 18px; height: 18px;">
                    <span class="toggle-avail" style="padding: 4px 12px;">Available</span>
                  </label>
                </div>
              </div>
              
              <div class="menu-edit-row">
                <div class="item-info"><i class="fas fa-mug-hot"></i><span class="item-name">Brewed Coffee</span></div>
                <span class="item-price">₱45</span>
                <div style="display: flex; justify-content: center;">
                  <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" checked style="accent-color: var(--dlsu-green); width: 18px; height: 18px;">
                    <span class="toggle-avail" style="padding: 4px 12px;">Available</span>
                  </label>
                </div>
              </div>
              
              <div class="menu-edit-row">
                <div class="item-info"><i class="fas fa-fish"></i><span class="item-name">Tuna Pandesal</span></div>
                <span class="item-price">₱50</span>
                <div style="display: flex; justify-content: center;">
                  <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" style="accent-color: var(--dlsu-green); width: 18px; height: 18px;">
                    <span class="toggle-sold" style="padding: 4px 12px;">Not Available</span>
                  </label>
                </div>
              </div>
              
              <div class="menu-edit-row">
                <div class="item-info"><i class="fas fa-cake"></i><span class="item-name">Brownie</span></div>
                <span class="item-price">₱35</span>
                <div style="display: flex; justify-content: center;">
                  <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" checked style="accent-color: var(--dlsu-green); width: 18px; height: 18px;">
                    <span class="toggle-avail" style="padding: 4px 12px;">Available</span>
                  </label>
                </div>
              </div>
              
              <div class="menu-edit-row">
                <div class="item-info"><i class="fas fa-mug-saucer"></i><span class="item-name">Matcha Latte</span></div>
                <span class="item-price">₱65</span>
                <div style="display: flex; justify-content: center;">
                  <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" style="accent-color: var(--dlsu-green); width: 18px; height: 18px;">
                    <span class="toggle-sold" style="padding: 4px 12px;">Not Available</span>
                  </label>
                </div>
              </div>
            </div>

            <!-- Add New Item -->
            <div style="margin-top: 20px; padding-top: 16px; border-top: 1px dashed #b8d5c4;">
              <button style="background: transparent; border: 1px dashed var(--dlsu-green); color: var(--dlsu-green); padding: 10px 20px; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer;">
                <i class="fas fa-plus-circle"></i> Add New Menu Item
              </button>
            </div>
          </div>

          <!-- orders dashboard with status badges -->
          <div class="admin-card">
            <h3><i class="fas fa-clock"></i> Order Manager · Real-time Queue</h3>
            
            <!-- Status filter tabs -->
            <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
              <span style="background: var(--dlsu-green); color: white; padding: 6px 16px; border-radius: 30px; font-size: 0.8rem; font-weight: 600;">All <?php echo $total_orders; ?></span>
              <span style="background: #f5e6e6; color: #b13e3e; padding: 6px 16px; border-radius: 30px; font-size: 0.8rem; font-weight: 600;">Pending <?php echo $pending_orders; ?></span>
              <span style="background: #fff1cf; color: #9e6d0b; padding: 6px 16px; border-radius: 30px; font-size: 0.8rem; font-weight: 600;">Preparing <?php echo $preparing_orders; ?></span>
              <span style="background: #c9f0d7; color: #0c6e3a; padding: 6px 16px; border-radius: 30px; font-size: 0.8rem; font-weight: 600;">Ready <?php echo $ready_orders; ?></span>
              <span style="background: #d0e3ff; color: #1f5090; padding: 6px 16px; border-radius: 30px; font-size: 0.8rem; font-weight: 600;">Completed <?php echo $completed_orders; ?></span>
            </div>

            <!-- Order Queue Header -->
            <div class="queue-item header" style="grid-template-columns: 2fr 1.2fr 1.2fr 0.8fr 0.5fr;">
              <span>Order Items</span><span>Customer</span><span>Time</span><span>Status</span><span></span>
            </div>
            
            <div class="queue-item" style="grid-template-columns: 2fr 1.2fr 1.2fr 0.8fr 0.5fr;">
              <span><?php echo $order_items; ?></span><span><?php echo $customer_name; ?></span><span><?php echo date("g:i A"); ?></span>
              <span><select style="background: #fff1cf; border: none; padding: 6px 12px; border-radius: 30px; font-weight: 600; color: #9e6d0b;">
                <option>Preparing</option>
                <option>Ready</option>
                <option>Completed</option>
              </select></span>
              <span><i class="fas fa-chevron-circle-right" style="color:#007a3e; cursor: pointer;"></i></span>
            </div>
            
            <div class="queue-item" style="grid-template-columns: 2fr 1.2fr 1.2fr 0.8fr 0.5fr;">
              <span>Siomai Rice, 2x Gulaman</span><span>Justin S.</span><span>10:45 AM</span>
              <span><span class="status-badge ready" style="background: #c9f0d7; color: #0c6e3a;">Ready</span></span>
              <span><i class="fas fa-check-circle" style="color:#007a3e; cursor: pointer;"></i></span>
            </div>
            
            <div class="queue-item" style="grid-template-columns: 2fr 1.2fr 1.2fr 0.8fr 0.5fr;">
              <span>Iced Coffee, 1x Brownie</span><span>Adriane C.</span><span>10:58 AM</span>
              <span><span class="status-badge pending" style="background: #f5e6e6; color: #b13e3e;">Pending</span></span>
              <span><i class="fas fa-hourglass" style="color:#b13e3e;"></i></span>
            </div>
            
            <div class="queue-item" style="grid-template-columns: 2fr 1.2fr 1.2fr 0.8fr 0.5fr;">
              <span>Club Sandwich, Fries</span><span>Mikaela L.</span><span>11:15 AM</span>
              <span><span class="status-badge completed" style="background: #d0e3ff; color: #1f5090;">Completed</span></span>
              <span><i class="fas fa-check-double" style="color:#16623b;"></i></span>
            </div>
            
            <div class="queue-item" style="grid-template-columns: 2fr 1.2fr 1.2fr 0.8fr 0.5fr;">
              <span>Beef Tapa, 2x Rice</span><span>Charles B.</span><span>11:22 AM</span>
              <span><span class="status-badge preparing" style="background: #fff1cf; color: #9e6d0b;">Preparing</span></span>
              <span><i class="fas fa-chevron-circle-right" style="color:#007a3e; cursor: pointer;"></i></span>
            </div>
          </div>
        </div>

        <!-- Sales Monitoring Section -->
        <div class="admin-card" style="margin-bottom:30px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h3 style="margin: 0;"><i class="fas fa-chart-simple"></i> Sales Monitoring · Performance Analytics</h3>
            <div style="display: flex; gap: 10px;">
              <span style="background: #e3f4ea; padding: 6px 16px; border-radius: 30px; font-size: 0.8rem; font-weight: 500;">Today</span>
              <span style="background: #e3f4ea; padding: 6px 16px; border-radius: 30px; font-size: 0.8rem; font-weight: 500;">This Week</span>
              <span style="background: #e3f4ea; padding: 6px 16px; border-radius: 30px; font-size: 0.8rem; font-weight: 500;">This Month</span>
            </div>
          </div>
          
          <!-- Sales Summary Cards -->
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px;">
            <div style="background: linear-gradient(135deg, #f0faf4, #ffffff); border-radius: 20px; padding: 20px; border: 1px solid var(--border-soft);">
              <div style="color: #5f8b74; font-size: 0.9rem; margin-bottom: 8px;">Total Revenue</div>
              <div style="font-size: 2.2rem; font-weight: 700; color: var(--dlsu-green);"><?php echo "₱" . number_format($total_revenue, 2); ?></div>
              <div style="color: #28a745; font-size: 0.8rem; margin-top: 8px;"><i class="fas fa-arrow-up"></i> 8.2% from yesterday</div>
            </div>
            <div style="background: linear-gradient(135deg, #f0faf4, #ffffff); border-radius: 20px; padding: 20px; border: 1px solid var(--border-soft);">
              <div style="color: #5f8b74; font-size: 0.9rem; margin-bottom: 8px;">Fulfilled Orders</div>
              <div style="font-size: 2.2rem; font-weight: 700; color: var(--dlsu-green);"><?php echo $fulfilled_orders_count; ?></div>
              <div style="color: #3b7455; font-size: 0.8rem; margin-top: 8px;"><?php echo $completed_orders_count; ?> completed · <?php echo $pending_orders_count; ?> pending</div>
            </div>
            <div style="background: linear-gradient(135deg, #f0faf4, #ffffff); border-radius: 20px; padding: 20px; border: 1px solid var(--border-soft);">
              <div style="color: #5f8b74; font-size: 0.9rem; margin-bottom: 8px;">Avg. Order Value</div>
              <div style="font-size: 2.2rem; font-weight: 700; color: var(--dlsu-green);"><?php echo "₱" . number_format($avg_order_value, 2); ?></div>
              <div style="color: #3b7455; font-size: 0.8rem; margin-top: 8px;">+<?php echo number_format($avg_order_value - $last_week_avg_order_value, 2); ?> from last week</div>
            </div>
          </div>

          <!-- Best Selling Items -->
          <div style="background: #eef6f1; border-radius: 24px; padding: 24px;">
            <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px;">
              <i class="fas fa-crown" style="color: #d4a017; font-size: 1.8rem;"></i>
              <span style="font-weight: 700; font-size: 1.2rem; color: #0f4a2f;">Best-Selling Items</span>
              <span style="background: #c9f0d7; padding: 4px 16px; border-radius: 30px; font-size: 0.8rem; color: #0c6e3a;">Top performers today</span>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
              <div style="background: white; border-radius: 18px; padding: 16px; display: flex; align-items: center; gap: 12px;">
                <div style="background: #ffd966; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #9e6d0b;">1</div>
                <div><div style="font-weight: 600;"><?php echo $best_selling_items[0]['item_name']; ?></div><div style="color: #5f8b74; font-size: 0.8rem;"><?php echo $best_selling_items[0]['order_count']; ?> orders</div></div>
              </div>
              <div style="background: white; border-radius: 18px; padding: 16px; display: flex; align-items: center; gap: 12px;">
                <div style="background: #e0e0e0; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #5f5f5f;">2</div>
                <div><div style="font-weight: 600;"><?php echo $best_selling_items[1]['item_name']; ?></div><div style="color: #5f8b74; font-size: 0.8rem;"><?php echo $best_selling_items[1]['order_count']; ?> orders</div></div>
              </div>
              <div style="background: white; border-radius: 18px; padding: 16px; display: flex; align-items: center; gap: 12px;">
                <div style="background: #e0b8a8; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #8b5a4c;">3</div>
                <div><div style="font-weight: 600;"><?php echo $best_selling_items[2]['item_name']; ?></div><div style="color: #5f8b74; font-size: 0.8rem;"><?php echo $best_selling_items[2]['order_count']; ?> orders</div></div>
              </div>
            </div>

            <!-- Additional items -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 12px;">
              <div style="color: #3b7455; font-size: 0.9rem;"><i class="fas fa-fire" style="color: #ff7b7b;"></i><?php echo $best_selling_items[3]['item_name']; ?> (<?php echo $best_selling_items[3]['order_count']; ?>)</div>
              <div style="color: #3b7455; font-size: 0.9rem;"><i class="fas fa-fire" style="color: #ff7b7b;"></i><?php echo $best_selling_items[4]['item_name']; ?> (<?php echo $best_selling_items[4]['order_count']; ?>)</div>
              <div style="color: #3b7455; font-size: 0.9rem;"><i class="fas fa-fire" style="color: #ff7b7b;"></i><?php echo $best_selling_items[5]['item_name']; ?> (<?php echo $best_selling_items[5]['order_count']; ?>)</div>
            </div>
          </div>
        </div>

        <!-- Quick Actions & Queue Management -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;">
          <div class="admin-card">
            <h3><i class="fas fa-people-arrows"></i> Queue Management</h3>
            <div style="display: flex; gap: 16px; align-items: center; margin-bottom: 20px;">
              <div style="background: var(--dlsu-green); width: 80px; height: 80px; border-radius: 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: white;">
                <span style="font-size: 0.7rem;">NOW</span>
                <span style="font-size: 2rem; font-weight: 700;"><?php echo !empty($orders) ? $orders[0]['queue_number'] : 'N/A'; ?></span>
              </div>
              <div>
                <div style="font-weight: 600; font-size: 1.2rem;">Current Queue Number</div>
                <div style="color: #3b7455;">Next: <?php echo !empty($orders) && isset($orders[1]) ? $orders[1]['queue_number'] : 'N/A'; ?> · Waiting: <?php echo $pending_orders; ?> orders</div>
              </div>
            </div>
            <div style="display: flex; gap: 12px;">
              <button style="background: var(--dlsu-green); color: white; border: none; padding: 12px 24px; border-radius: 40px; font-weight: 600; flex: 1;"><i class="fas fa-forward"></i> Next Customer</button>
              <button style="background: #e3f4ea; color: var(--dlsu-green); border: none; padding: 12px 24px; border-radius: 40px; font-weight: 600; flex: 1;"><i class="fas fa-rotate-right"></i> Reset Counter</button>
            </div>
          </div>

          <div class="admin-card">
            <h3><i class="fas fa-file-invoice"></i> Recent Transactions</h3>
            <div style="display: flex; flex-direction: column; gap: 12px;">
              <?php if (!empty($orders)): ?>
                <?php foreach (array_slice($orders, 0, 3) as $txn): ?>
                  <?php 
                    $status_color = '';
                    switch($txn['status']) {
                      case 'C':
                        $status_color = '#0c6e3a';
                        $status_text = 'Completed';
                        break;
                      case 'PR':
                        $status_color = '#9e6d0b';
                        $status_text = 'Preparing';
                        break;
                      case 'P':
                        $status_color = '#b13e3e';
                        $status_text = 'Pending';
                        break;
                      case 'R':
                        $status_color = '#0c6e3a';
                        $status_text = 'Ready';
                        break;
                    }
                  ?>
                  <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e0f0e8;">
                    <span><span style="font-weight: 600;">#<?php echo $txn['queue_number']; ?></span> · <?php echo htmlspecialchars(substr($txn['items'], 0, 25)); ?>...</span>
                    <span><?php echo formatPrice($txn['total_amount']); ?></span>
                    <span style="color: <?php echo $status_color; ?>;"><?php echo $status_text; ?></span>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div style="padding: 20px; text-align: center; color: #999;">
                  No transactions yet
                </div>
              <?php endif; ?>
            </div>
            <button style="background: transparent; border: 1px solid var(--border-soft); color: var(--dlsu-green); padding: 12px; border-radius: 40px; width: 100%; margin-top: 16px; font-weight: 600;">
              <i class="fas fa-receipt"></i> View All Transactions
            </button>
          </div>
        </div>

        <footer class="footer-note" style="margin-top: 40px;">
          <i class="fas fa-store-alt"></i> Vendor Dashboard · Inventory Controller · Order Manager · Sales Reviewer
        </footer>
      </div>
    </section>
  </div>
</body>
</html>