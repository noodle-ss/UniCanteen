<?php
require_once __DIR__ . '/../config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: index.php?page=customer");
    exit();
}

$db = Database::getInstance()->getConnection();
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request token';
    } else {
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        
        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password';
        } else {
            $lockCheckStmt = $db->prepare(
                "SELECT locked_until FROM Users WHERE email = ? AND locked_until > NOW()"
            );
            $lockCheckStmt->bind_param("s", $email);
            $lockCheckStmt->execute();
            $lockResult = $lockCheckStmt->get_result();
            
            if ($lockResult->num_rows > 0) {
                $error = 'Account is locked. Please try again later.';
            } else {
                $stmt = $db->prepare(
                    "SELECT ID, email, password, full_name, role, is_active, is_banned, login_attempts 
                     FROM Users WHERE email = ?"
                );
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    
                    if (!$user['is_active'] || $user['is_banned']) {
                        $error = 'Account is disabled. Please contact administrator.';
                    } else {
                        if (password_verify($password, $user['password'])) {
                            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT, ['cost' => BCRYPT_COST])) {
                                $newHash = password_hash($password, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
                                $rehashStmt = $db->prepare("UPDATE Users SET password = ? WHERE ID = ?");
                                $rehashStmt->bind_param("si", $newHash, $user['ID']);
                                $rehashStmt->execute();
                            }
                            
                            $resetStmt = $db->prepare("UPDATE Users SET login_attempts = 0, last_login = NOW() WHERE ID = ?");
                            $resetStmt->bind_param("i", $user['ID']);
                            $resetStmt->execute();
                            
                            $database = Database::getInstance();
                            $session_token = $database->createSession($user['ID']);
                            
                            $_SESSION['user_id'] = $user['ID'];
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['user_name'] = $user['full_name'];
                            $_SESSION['user_role'] = $user['role'];
                            $_SESSION['session_token'] = $session_token;
                            
                            secureSessionRegenerate();
                            
                            $redirect = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : 'index.php';
                            unset($_SESSION['redirect_after_login']);
                            redirect($redirect);
                        } else {
                            $attempts = $user['login_attempts'] + 1;
                            
                            if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                                $locked_until = date('Y-m-d H:i:s', time() + LOCKOUT_TIME);
                                $lockStmt = $db->prepare("UPDATE Users SET login_attempts = ?, locked_until = ? WHERE ID = ?");
                                $lockStmt->bind_param("isi", $attempts, $locked_until, $user['ID']);
                                $lockStmt->execute();
                                $error = 'Too many failed attempts. Account locked for 15 minutes.';
                            } else {
                                $updateStmt = $db->prepare("UPDATE Users SET login_attempts = ? WHERE ID = ?");
                                $updateStmt->bind_param("ii", $attempts, $user['ID']);
                                $updateStmt->execute();
                                $error = 'Invalid password';
                            }
                        }
                    }
                } else {
                    $error = 'Invalid email or password';
                }
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
    <title>Login - UniCanteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo url('assets/styles.css'); ?>">
    <style>
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
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #8faa9a;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="wrapper">
            <div class="auth-container">
                <a href="<?php echo url('index.php'); ?>" class="logo" style="display: block; text-align: center; margin-bottom: 30px;">UniCanteen</a>
                
                <h2 style="text-align: center; margin-bottom: 30px;">Welcome Back</h2>
                
                <?php if($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required 
                               style="width: 100%; padding: 15px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;"
                               placeholder="your@email.com">
                    </div>
                    
                    <div style="margin-bottom: 10px; position: relative;" class="password-toggle">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">Password</label>
                        <input type="password" name="password" id="password" required 
                               style="width: 100%; padding: 15px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;"
                               placeholder="••••••••">
                        <i class="fas fa-eye toggle-password" id="togglePassword" style="right: 20px;"></i>
                    </div>
                    
                    <div style="text-align: right; margin-bottom: 20px;">
                        <a href="<?php echo url('frontend/forgot-password.php'); ?>" style="color: #007a3e; font-size: 0.9rem;">Forgot Password?</a>
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width: 100%; padding: 16px; font-size: 1.1rem;">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                </form>
                
                <div style="text-align: center; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e0f0e8;">
                    <p style="color: #3b7455; margin-bottom: 10px;">New to UniCanteen?</p>
                    <a href="<?php echo url('frontend/register.php'); ?>" class="btn-secondary" style="text-decoration: none; display: inline-block; padding: 12px 30px;">
                        <i class="fas fa-user-plus"></i> Create Customer Account
                    </a>
                </div>
                
                <div style="text-align: center; margin-top: 15px; font-size: 0.85rem; color: #8faa9a;">
                    <i class="fas fa-shield-alt"></i> Your information is secure and encrypted
                </div>
            </div>
        </div>
    </div>
    
    <script>
    document.getElementById('togglePassword').addEventListener('click', function() {
        const password = document.getElementById('password');
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        this.classList.toggle('fa-eye');
        this.classList.toggle('fa-eye-slash');
    });
    </script>
</body>
</html>