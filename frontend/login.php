<?php
/**
 * login.php — User login page interface.
 *
 * Provides the aesthetic HTML shell for the authentication process.
 * The actual form logic and markup is included via 'login-content.php'
 * which is managed by the main 'index.php' router.
 */
require_once __DIR__ . '/../config/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - UniCanteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
        body {
            display: block;
            min-height: auto;
            margin: 0;
            padding: 0;
            background: #f0f7f0;
        }
        .main-content {
            margin-left: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .wrapper {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 36px;
            width: 100%;
        }
        .auth-container {
            max-width: 400px;
            margin: 50px auto;
            background: white;
            border-radius: 30px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,70,30,0.1);
        }
        .password-toggle {
            position: relative;
        }
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 70%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #8faa9a;
        }
        .btn-secondary {
            background: #e3f4ea;
            color: var(--dlsu-green);
            border: none;
            padding: 12px 30px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-secondary:hover {
            background: #d0e8db;
            transform: translateY(-2px);
        }
        .btn-primary {
            background: var(--dlsu-green);
            color: white;
            border: none;
            padding: 16px 24px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-primary:hover {
            background: var(--dlsu-darkgreen);
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 122, 62, 0.2);
        }
    </style>
</head>
<body>
    <div class="main-content">
        <?php include 'login-content.php'; ?>
    </div>
</body>
</html>