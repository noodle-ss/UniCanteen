<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>UniCanteen · System Admin</title>
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
    <section id="sysadmin" class="page-section">
      <div class="admin-header">
        <div class="admin-logo"><i class="fas fa-user-shield"></i> UniCanteen · System Admin</div>
        <div class="role-badge"><i class="fas fa-university"></i> Oversight & Compliance</div>
      </div>
      <div class="server-container">
        <div class="sys-grid">
          <!-- vendor accounts (full list) -->
          <div class="sys-card">
            <h3><i class="fas fa-truck"></i> Food vendors / stalls</h3>
            <div class="vendor-row"><span><i class="fas fa-store"></i> Bloemen Hall</span><div class="vendor-meta"><span class="status-active">Active</span><span class="reset-btn">Reset</span></div></div>
            <div class="vendor-row"><span><i class="fas fa-store"></i> Agno Eatery</span><div class="vendor-meta"><span class="status-active">Active</span><span class="reset-btn">Reset</span></div></div>
            <div class="vendor-row"><span><i class="fas fa-store"></i> Kitchen SJ</span><div class="vendor-meta"><span class="status-inactive">Inactive</span><span class="ban-btn">Disable</span></div></div>
            <div class="vendor-row"><span><i class="fas fa-store"></i> St. La Salle Deli</span><div class="vendor-meta"><span class="status-active">Active</span><span class="reset-btn">Reset</span></div></div>
            <div class="vendor-row"><span><i class="fas fa-store"></i> Agno Food Court</span><div class="vendor-meta"><span class="status-active">Active</span><span class="reset-btn">Reset</span></div></div>
            <p class="text-small" style="margin-top:16px;"><i class="fas fa-plus-circle"></i> Create new vendor account</p>
          </div>
          <!-- user oversight + ban capabilities -->
          <div class="sys-card">
            <h3><i class="fas fa-users"></i> User oversight (students/staff)</h3>
            <div class="user-oversight-row"><span><i class="fas fa-user-graduate"></i> Charles B.</span><span class="user-role-tag">student</span><span class="ban-action"><i class="fas fa-ban"></i> restrict</span></div>
            <div class="user-oversight-row"><span><i class="fas fa-user-tie"></i> Prof. Reyes</span><span class="user-role-tag">faculty</span><span class="ban-action">warn</span></div>
            <div class="user-oversight-row"><span><i class="fas fa-user"></i> Adriane M.</span><span class="user-role-tag">staff</span><span class="ban-action">manage</span></div>
            <div class="user-oversight-row"><span><i class="fas fa-user"></i> Justin S.</span><span class="user-role-tag admin-badge">flagged</span><span class="ban-action" style="background:#fceaea;"> ban</span></div>
            <div class="user-oversight-row"><span><i class="fas fa-user"></i> Terrence P.</span><span class="user-role-tag">student</span><span class="ban-action">···</span></div>
            <p class="text-small" style="margin-top:16px;"><i class="fas fa-shield-alt"></i> Ban users violating policy (fake orders)</p>
          </div>
        </div>
        <!-- platform settings & compliance log -->
        <div style="display: flex; gap: 30px; margin-top: 30px; flex-wrap: wrap;">
          <div class="admin-card" style="flex:1;">
            <h3><i class="fas fa-gear"></i> Platform settings</h3>
            <div class="vendor-row"><span>Default user role</span><span class="reset-btn">student/staff</span></div>
            <div class="vendor-row"><span>Session timeout</span><span>30 min</span></div>
            <div class="vendor-row"><span>Maintenance mode</span><span class="status-inactive">off</span></div>
          </div>
          <div class="admin-card" style="flex:1;">
            <h3><i class="fas fa-file-invoice"></i> Compliance log</h3>
            <div><i class="fas fa-circle-check" style="color:green;"></i> 3 stalls audited today</div>
            <div><i class="fas fa-clock"></i> last violation: none</div>
            <div class="mt-4"><span class="status-badge completed">fake order report resolved</span></div>
          </div>
        </div>
        <!-- queue monitoring (admin overview) -->
        <div class="queue-number-panel" style="margin-top:40px; background:#1e5f3e;">
          <span><i class="fas fa-queue"></i> System queue status</span>
          <span style="font-size:2rem; font-weight:700;">A‑012 · B‑042 · C‑008</span>
        </div>
      </div>
      <footer class="footer-note"><i class="fas fa-user-tie"></i> System Admin – vendor creation, user bans, oversight</footer>
    </section>
  </div>
</body>
</html>