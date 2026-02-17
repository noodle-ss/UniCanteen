<?php
require_once __DIR__ . '/../config/config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';
$full_name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request token';
    } else {
        $full_name = sanitizeInput($_POST['full_name']);
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $agree_terms = isset($_POST['agree_terms']);
        
        $errors = [];
        
        if (empty($full_name)) {
            $errors[] = 'Full name is required';
        } elseif (strlen($full_name) < 2 || strlen($full_name) > 100) {
            $errors[] = 'Full name must be between 2 and 100 characters';
        }
        
        if (empty($email)) {
            $errors[] = 'Email is required';
        } elseif (!isValidDLSUEmail($email)) {
            $errors[] = 'Please enter a valid email address';
        }
        
        if (empty($password)) {
            $errors[] = 'Password is required';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        
        if ($password !== $confirm_password) {
            $errors[] = 'Passwords do not match';
        }
        
        if (!$agree_terms) {
            $errors[] = 'You must agree to the Terms and Conditions';
        }
        
        if (empty($errors)) {
            $checkStmt = $db->prepare("SELECT ID FROM Users WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult && $checkResult->num_rows > 0) {
                $errors[] = 'Email already registered. Please use a different email or <a href="login.php">login</a>.';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
                
                $db->begin_transaction();
                
                try {
                    $insertStmt = $db->prepare(
                        "INSERT INTO Users (email, password, full_name, role, is_active, login_attempts) 
                         VALUES (?, ?, ?, 'U', TRUE, 0)"
                    );
                    $insertStmt->bind_param("sss", $email, $hashed_password, $full_name);
                    
                    if ($insertStmt->execute()) {
                        $user_id = $db->insert_id;
                        
                        $logStmt = $db->prepare(
                            "INSERT INTO UserLogs (user_id, action, ip_address, user_agent) 
                             VALUES (?, 'REGISTER', ?, ?)"
                        );
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $logStmt->bind_param("iss", $user_id, $ip, $ua);
                        $logStmt->execute();
                        
                        $db->commit();
                        
                        $success = 'Registration successful! You can now login with your credentials.';
                        
                        $full_name = '';
                        $email = '';
                        
                        header("refresh:3;url=login.php");
                    } else {
                        throw new Exception("Registration failed");
                    }
                } catch (Exception $e) {
                    $db->rollback();
                    error_log("Registration error: " . $e->getMessage());
                    $errors[] = 'Registration failed. Please try again later.';
                }
            }
        }
        
        if (!empty($errors)) {
            $error = implode('<br>', $errors);
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
    <title>Register - UniCanteen Customer</title>
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
        .password-strength {
            margin-top: 5px;
            font-size: 0.8rem;
        }
        .strength-meter {
            height: 4px;
            background: #e0f0e8;
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }
        .strength-meter-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
        }
        .strength-weak { background: #b13e3e; width: 25%; }
        .strength-fair { background: #f5b342; width: 50%; }
        .strength-good { background: #2ecc71; width: 75%; }
        .strength-strong { background: #007a3e; width: 100%; }
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
                
                <h2 style="text-align: center; margin-bottom: 10px;">Create Customer Account</h2>
                <p style="text-align: center; color: #3b7455; margin-bottom: 30px;">Join UniCanteen to order food from campus stalls</p>
                
                <?php if($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
                <?php endif; ?>
                
                <?php if($success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <p style="margin-top: 10px; font-size: 0.9rem;">Redirecting to login page...</p>
                </div>
                <?php else: ?>
                
                <form method="POST" action="" id="registerForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">
                            <i class="fas fa-user"></i> Full Name
                        </label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required 
                               style="width: 100%; padding: 15px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;"
                               placeholder="Juan Dela Cruz"
                               minlength="2" maxlength="100">
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">
                            <i class="fas fa-envelope"></i> Email Address
                        </label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required 
                               style="width: 100%; padding: 15px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;"
                               placeholder="your@email.com">
                        <small style="color: #8faa9a; display: block; margin-top: 5px;">
                            <i class="fas fa-info-circle"></i> Use your valid email address
                        </small>
                    </div>
                    
                    <div style="margin-bottom: 15px; position: relative;" class="password-toggle">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">
                            <i class="fas fa-lock"></i> Password
                        </label>
                        <input type="password" name="password" id="password" required 
                               style="width: 100%; padding: 15px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;"
                               placeholder="Create a strong password"
                               minlength="8">
                        <i class="fas fa-eye toggle-password" id="togglePassword" style="right: 20px;"></i>
                        
                        <div class="password-strength">
                            <div class="strength-meter">
                                <div class="strength-meter-fill" id="strengthMeter"></div>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-top: 5px; flex-wrap: wrap; gap: 5px;">
                                <small id="strengthText" style="color: #8faa9a;">Enter password</small>
                                <small id="requirements" style="color: #8faa9a;">
                                    <i class="fas fa-info-circle"></i> 8+ chars, upper & lowercase, number
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 20px; position: relative;" class="password-toggle">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">
                            <i class="fas fa-lock"></i> Confirm Password
                        </label>
                        <input type="password" name="confirm_password" id="confirm_password" required 
                               style="width: 100%; padding: 15px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;"
                               placeholder="Re-enter your password">
                        <i class="fas fa-eye toggle-password" id="toggleConfirmPassword" style="right: 20px;"></i>
                        <small id="passwordMatch" style="display: block; margin-top: 5px;"></small>
                    </div>
                    
                    <div style="margin-bottom: 25px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="agree_terms" required style="width: 18px; height: 18px;">
                            <span style="color: #1e3a2f;">
                                I agree to the <a href="#" style="color: #007a3e;">Terms and Conditions</a> and 
                                <a href="#" style="color: #007a3e;">Privacy Policy</a>
                            </span>
                        </label>
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width: 100%; padding: 16px; font-size: 1.1rem;">
                        <i class="fas fa-user-plus"></i> Create Customer Account
                    </button>
                </form>
                
                <div style="text-align: center; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e0f0e8;">
                    <p style="color: #3b7455;">
                        Already have an account? <a href="<?php echo url('frontend/login.php'); ?>" style="color: #007a3e; font-weight: 600;">Sign In</a>
                    </p>
                </div>
                
                <div style="text-align: center; margin-top: 15px; font-size: 0.85rem; color: #8faa9a;">
                    <i class="fas fa-shield-alt"></i> Your information is secure and encrypted
                </div>
                
                <?php endif; ?>
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
    
    document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
        const password = document.getElementById('confirm_password');
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        this.classList.toggle('fa-eye');
        this.classList.toggle('fa-eye-slash');
    });
    
    document.getElementById('password').addEventListener('input', function() {
        const password = this.value;
        const strengthMeter = document.getElementById('strengthMeter');
        const strengthText = document.getElementById('strengthText');
        
        const hasUpperCase = /[A-Z]/.test(password);
        const hasLowerCase = /[a-z]/.test(password);
        const hasNumber = /[0-9]/.test(password);
        const isLongEnough = password.length >= 8;
        
        let strength = 0;
        if (isLongEnough) strength++;
        if (hasUpperCase) strength++;
        if (hasLowerCase) strength++;
        if (hasNumber) strength++;
        
        strengthMeter.className = 'strength-meter-fill';
        if (password.length === 0) {
            strengthMeter.style.width = '0%';
            strengthText.textContent = 'Enter password';
            strengthText.style.color = '#8faa9a';
        } else if (strength <= 2) {
            strengthMeter.classList.add('strength-weak');
            strengthText.textContent = 'Weak password';
            strengthText.style.color = '#b13e3e';
        } else if (strength === 3) {
            strengthMeter.classList.add('strength-fair');
            strengthText.textContent = 'Fair password';
            strengthText.style.color = '#f5b342';
        } else if (strength === 4) {
            strengthMeter.classList.add('strength-good');
            strengthText.textContent = 'Good password';
            strengthText.style.color = '#2ecc71';
        }
    });
    
    document.getElementById('confirm_password').addEventListener('input', function() {
        const password = document.getElementById('password').value;
        const confirm = this.value;
        const matchMsg = document.getElementById('passwordMatch');
        
        if (confirm.length === 0) {
            matchMsg.innerHTML = '';
        } else if (password === confirm) {
            matchMsg.innerHTML = '<i class="fas fa-check-circle" style="color: #0c6e3a;"></i> Passwords match';
            matchMsg.style.color = '#0c6e3a';
        } else {
            matchMsg.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #b13e3e;"></i> Passwords do not match';
            matchMsg.style.color = '#b13e3e';
        }
    });
    </script>
</body>
</html>