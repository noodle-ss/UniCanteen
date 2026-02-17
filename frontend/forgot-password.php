<?php
require_once __DIR__ . '/../config/config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request token';
    } else {
        $email = sanitizeInput($_POST['email']);
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } else {
            $stmt = $db->prepare("SELECT ID, full_name FROM Users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600);
                
                $logStmt = $db->prepare("INSERT INTO UserLogs (user_id, action, ip_address) VALUES (?, 'PASSWORD_RESET_REQUEST', ?)");
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $logStmt->bind_param("is", $user['ID'], $ip);
                $logStmt->execute();
                
                $success = "Password reset instructions have been sent to your email.";
            } else {
                $success = "If your email is registered, you will receive reset instructions.";
            }
        }
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - UniCanteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
        .auth-container {
            max-width: 450px;
            margin: 50px auto;
            background: white;
            border-radius: 30px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,70,30,0.1);
        }
        .reset-steps {
            margin: 30px 0;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f9fffc;
            border-radius: 20px;
            border: 1px solid #e0f0e8;
        }
        .step-number {
            width: 40px;
            height: 40px;
            background: #007a3e;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        .security-note {
            background: #e3f4ea;
            border-radius: 20px;
            padding: 20px;
            margin-top: 30px;
            color: #1e3a2f;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="wrapper">
            <div class="auth-container">
                <a href="<?php echo url('index.php'); ?>" class="logo" style="display: block; text-align: center; margin-bottom: 20px;">UniCanteen</a>
                
                <h2 style="text-align: center; margin-bottom: 10px;">Reset Password</h2>
                <p style="text-align: center; color: #3b7455; margin-bottom: 30px;">Enter your email to receive reset instructions</p>
                
                <?php if($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>
                
                <?php if($success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="<?php echo url('frontend/login.php'); ?>" class="btn-primary" style="display: inline-block;">Return to Login</a>
                </div>
                <?php else: ?>
                
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div style="margin-bottom: 25px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">
                            <i class="fas fa-envelope"></i> Email Address
                        </label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required 
                               style="width: 100%; padding: 15px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;"
                               placeholder="your@email.com">
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width: 100%; padding: 16px; font-size: 1.1rem;">
                        <i class="fas fa-paper-plane"></i> Send Reset Instructions
                    </button>
                </form>
                
                <div class="reset-steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div>Enter your registered email address</div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div>Check your inbox for reset link</div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div>Create a new strong password</div>
                    </div>
                </div>
                
                <div class="security-note">
                    <i class="fas fa-shield-alt" style="color: #007a3e;"></i>
                    <strong>Security Note:</strong> The reset link will expire in 1 hour for your security. If you don't receive an email, check your spam folder.
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <p style="color: #3b7455;">
                        Remember your password? <a href="<?php echo url('frontend/login.php'); ?>" style="color: #007a3e; font-weight: 600;">Sign In</a>
                    </p>
                </div>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>