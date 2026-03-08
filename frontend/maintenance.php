<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniCanteen · Under Maintenance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,700;1,400&display=swap');

        :root {
            --green-dark:   #007a3e;
            --green-mid:    #3d7259;
            --green-light:  #cae3d6;
            --green-bg:     #f0f7f0;
            --green-pale:   #e6f3ec;
            --sidebar-w:    108px;
            --white:        #ffffff;
            --text-dark:    #1a3328;
            --text-mid:     #3d5c4a;
            --border:       #cae3d6;
            --shadow:       rgba(0, 80, 20, 0.08);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            display: flex;
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background: var(--green-bg);
            color: var(--text-dark);
        }

        /* ── Left sidebar – identical to index.php ── */
        .left-switcher {
            width: var(--sidebar-w);
            background: var(--white);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 40px;
            gap: 24px;
            box-shadow: 4px 0 16px var(--shadow);
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
            font-family: 'DM Sans', sans-serif;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 2px;
            padding: 16px 6px;
            border-radius: 36px;
            color: var(--green-mid);
            cursor: default;
            display: flex;
            align-items: center;
            gap: 8px;
            opacity: 0.45;
            pointer-events: none;
            text-decoration: none;
        }

        .switch-btn i {
            writing-mode: horizontal-tb;
            transform: rotate(180deg);
            font-size: 1.4rem;
        }

        /* ── Main content area ── */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-w);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 24px;
        }

        /* ── Card ── */
        .card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: 0 8px 40px var(--shadow);
            max-width: 560px;
            width: 100%;
            padding: 56px 48px 48px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        /* Decorative top stripe */
        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--green-dark), #00c46a, var(--green-dark));
            background-size: 200% 100%;
            animation: stripe-slide 3s linear infinite;
        }

        @keyframes stripe-slide {
            0%   { background-position: 0% 0%; }
            100% { background-position: 200% 0%; }
        }

        /* ── Icon badge ── */
        .icon-wrap {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: var(--green-pale);
            border: 2px solid var(--green-light);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 28px;
            animation: pulse-ring 2.4s ease-in-out infinite;
        }

        

        .icon-wrap i {
            font-size: 2.4rem;
            color: var(--green-dark);
        }

        /* ── Typography ── */
        h1 {
            font-family: 'DM Serif Display', serif;
            font-size: 2rem;
            font-weight: 400;
            color: var(--text-dark);
            line-height: 1.15;
            margin-bottom: 12px;
        }

        h1 em {
            font-style: italic;
            color: var(--green-dark);
        }

        .subtitle {
            font-size: 1rem;
            color: var(--text-mid);
            line-height: 1.6;
            margin-bottom: 36px;
        }

        /* ── Info pills ── */
        .info-row {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 36px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--green-pale);
            border: 1px solid var(--green-light);
            border-radius: 999px;
            padding: 8px 18px;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--green-mid);
        }

        .pill i { font-size: 0.85rem; color: var(--green-dark); }

        /* ── Divider ── */
        .divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 0 0 28px;
        }

        /* ── Footer note ── */
        .footer-note {
            font-size: 0.82rem;
            color: #7aaa90;
        }

        .footer-note a {
            color: var(--green-dark);
            font-weight: 600;
            text-decoration: none;
        }

        .footer-note a:hover { text-decoration: underline; }

        /* ── Branding wordmark ── */
        .wordmark {
            position: absolute;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            font-family: 'DM Serif Display', serif;
            font-size: 0.95rem;
            letter-spacing: 1px;
            color: var(--green-light);
            white-space: nowrap;
        }


    </style>
</head>
<body>

    <!-- Sidebar (greyed out — not interactive during maintenance) -->
    <div class="left-switcher">
        <a class="switch-btn"><i class="fas fa-user-graduate"></i><span>CUSTOMER</span></a>
        <a class="switch-btn"><i class="fas fa-store"></i><span>VENDOR</span></a>
        <a class="switch-btn"><i class="fas fa-user-tie"></i><span>SYSADMIN</span></a>
    </div>

    <div class="main-content">
        <div class="card">
            <span class="wordmark">UniCanteen</span>

            <div class="icon-wrap">
                <i class="fas fa-wrench"></i>
            </div>

            <h1>We're doing a bit of<br><em>kitchen work</em></h1>

            <p class="subtitle">
                UniCanteen is temporarily offline for scheduled maintenance.<br>
                We're cooking up improvements — won't be long!
            </p>

            <div class="info-row">
                <span class="pill"><i class="fas fa-calendar-check"></i> Back soon <span class="dots"><span>.</span><span>.</span><span>.</span></span></span>
            </div>
        </div>
    </div>

</body>
</html>