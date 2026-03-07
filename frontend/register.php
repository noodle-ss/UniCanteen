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
        $security_question = sanitizeInput($_POST['security_question'] ?? '');
        $security_answer = sanitizeInput($_POST['security_answer'] ?? '');

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

        if (empty($security_question)) {
            $errors[] = 'Please select a security question';
        }
        if (empty($security_answer)) {
            $errors[] = 'Please provide an answer to your security question';
        } elseif (strlen($security_answer) < 2) {
            $errors[] = 'Security answer must be at least 2 characters';
        }

        if (empty($errors)) {
            $checkStmt = $db->prepare("SELECT ID FROM Users WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult && $checkResult->num_rows > 0) {
                $errors[] = 'Email already registered. Please use a different email or <a href="' . url('index.php?page=login') . '" style="color:#007a3e;font-weight:600;">login here</a>.';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
                $hashed_answer = password_hash(strtolower(trim($security_answer)), PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);

                $db->begin_transaction();

                try {
                    $insertStmt = $db->prepare(
                        "INSERT INTO Users (email, password, full_name, role, is_active, login_attempts, security_question, security_answer)
                         VALUES (?, ?, ?, 'U', TRUE, 0, ?, ?)"
                    );
                    $insertStmt->bind_param("sssss", $email, $hashed_password, $full_name, $security_question, $hashed_answer);

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

                        // FIX: Use the url() helper function to generate the correct path
                        header("refresh:3;url=" . url('index.php?page=login'));
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
            max-width: 450px;
            margin: 50px auto;
            background: white;
            border-radius: 30px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0, 70, 30, 0.1);
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

        .strength-weak {
            background: #b13e3e;
            width: 25%;
        }

        .strength-fair {
            background: #f5b342;
            width: 50%;
        }

        .strength-good {
            background: #2ecc71;
            width: 75%;
        }

        .strength-strong {
            background: #007a3e;
            width: 100%;
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: white;
            margin: 50px auto;
            padding: 0;
            border-radius: 30px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 70, 30, 0.2);
            animation: slideIn 0.3s ease;
            border: 1px solid var(--border-soft);
        }

        .modal-header {
            padding: 25px 30px;
            border-bottom: 2px solid var(--border-soft);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(to right, #f0f7f0, white);
            border-radius: 30px 30px 0 0;
        }

        .modal-header h2 {
            margin: 0;
            color: var(--dlsu-green);
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h2 i {
            font-size: 1.8rem;
            color: var(--dlsu-green);
            background: #e1f3e9;
            padding: 10px;
            border-radius: 50%;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: #8faa9a;
            transition: all 0.2s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .modal-close:hover {
            background: #fee9e9;
            color: #b13e3e;
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
            line-height: 1.6;
            color: #1e3a2f;
        }

        .modal-body h3 {
            color: var(--dlsu-green);
            margin: 25px 0 15px;
            font-size: 1.3rem;
            border-left: 4px solid var(--dlsu-green);
            padding-left: 15px;
        }

        .modal-body h3:first-of-type {
            margin-top: 0;
        }

        .modal-body p {
            margin-bottom: 15px;
        }

        .modal-body ul {
            margin-bottom: 20px;
            padding-left: 20px;
        }

        .modal-body li {
            margin-bottom: 8px;
        }

        .modal-body .highlight {
            background: #f0f7f0;
            padding: 15px 20px;
            border-radius: 16px;
            border-left: 4px solid var(--dlsu-green);
            margin: 20px 0;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 2px solid var(--border-soft);
            text-align: right;
            background: #f9fffc;
            border-radius: 0 0 30px 30px;
        }

        .modal-footer button {
            padding: 12px 30px;
            border-radius: 40px;
            border: none;
            background: var(--dlsu-green);
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .modal-footer button:hover {
            background: var(--dlsu-darkgreen);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 122, 62, 0.2);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .terms-link,
        .privacy-link {
            color: #007a3e;
            font-weight: 600;
            cursor: pointer;
            text-decoration: underline;
            text-decoration-color: #b8e0cc;
            text-underline-offset: 3px;
            transition: all 0.2s ease;
        }

        .terms-link:hover,
        .privacy-link:hover {
            color: #004f29;
            text-decoration-color: #007a3e;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 10px 0;
        }

        .checkbox-container input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #007a3e;
        }

        .last-updated {
            font-size: 0.85rem;
            color: #8faa9a;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--border-soft);
            text-align: right;
        }
    </style>
</head>

<body>
    <div class="main-content">
        <div class="wrapper">
            <div class="auth-container">
                <div style="text-align: left; margin-bottom: 20px;">
                    <a href="<?php echo url('index.php?page=customer'); ?>" class="btn-secondary"
                        style="display: inline-block; padding: 10px 20px; text-decoration: none;">
                        <i class="fas fa-arrow-left"></i> Back to Home
                    </a>
                </div>
                <a href="<?php echo url('index.php'); ?>" class="logo"
                    style="display: block; text-align: center; margin-bottom: 30px;">UniCanteen</a>

                <h2 style="text-align: center; margin-bottom: 10px;">Create Customer Account</h2>
                <p style="text-align: center; color: #3b7455; margin-bottom: 30px;">Join UniCanteen to order food from
                    campus stalls</p>

                <?php if ($error): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
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
                                placeholder="Juan Dela Cruz" minlength="2" maxlength="100">
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
                                placeholder="Create a strong password" minlength="8">
                            <i class="fas fa-eye toggle-password" id="togglePassword" style="right: 20px;"></i>

                            <div class="password-strength">
                                <div class="strength-meter">
                                    <div class="strength-meter-fill" id="strengthMeter"></div>
                                </div>
                                <div
                                    style="display: flex; justify-content: space-between; margin-top: 5px; flex-wrap: wrap; gap: 5px;">
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
                            <div style="position: relative;">
                                <input type="password" name="confirm_password" id="confirm_password" required
                                    style="width: 100%; padding: 15px; border: 2px solid #e0f0e8; border-radius: 30px; font-family: 'Inter', sans-serif;"
                                    placeholder="Re-enter your password">
                                <i class="fas fa-eye toggle-password" id="toggleConfirmPassword"
                                    style="position: absolute; right: 20px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #8faa9a; z-index: 10;"></i>
                            </div>
                            <small id="passwordMatch" style="display: block; margin-top: 5px;"></small>
                        </div>

                        <div style="margin-bottom: 25px;">
                            <label class="checkbox-container">
                                <input type="checkbox" name="agree_terms" required>
                                <span style="color: #1e3a2f;">
                                    I agree to the
                                    <span class="terms-link" onclick="openModal('terms')">Terms and Conditions</span>
                                    and
                                    <span class="privacy-link" onclick="openModal('privacy')">Privacy Policy</span>
                                </span>
                            </label>
                        </div>

                        <!-- Security Question -->
                        <div style="margin-bottom: 20px; border-top: 1px solid #e0f0e8; padding-top: 20px;">
                            <p style="font-size: 0.85rem; font-weight: 600; color: #1a4d31; margin: 0 0 14px;">
                                <i class="fas fa-shield-alt" style="color: var(--dlsu-green);"></i>
                                Account Recovery — Security Question
                            </p>
                            <div style="margin-bottom: 14px;">
                                <label
                                    style="display: block; margin-bottom: 7px; font-size: 0.85rem; font-weight: 600; color: #1a4d31;">
                                    <i class="fas fa-question-circle"></i> Select a Security Question
                                </label>
                                <select name="security_question" required
                                    style="width: 100%; padding: 13px 16px; border: 1.5px solid #cae3d6; border-radius: 14px; font-family: 'Inter', sans-serif; font-size: 0.92rem; color: #1e3a2f; background: #f9fffc; outline: none; box-sizing: border-box;">
                                    <option value="" disabled selected>Choose a question…</option>
                                    <option value="What is the name of your childhood pet?">What is the name of your
                                        childhood pet?</option>
                                    <option value="What city were you born in?">What city were you born in?</option>
                                    <option value="What is your mother's maiden name?">What is your mother's maiden name?
                                    </option>
                                    <option value="What was the name of your first school?">What was the name of your first
                                        school?</option>
                                    <option value="What is your favorite book?">What is your favorite book?</option>
                                    <option value="What is the name of your oldest sibling?">What is the name of your oldest
                                        sibling?</option>
                                    <option value="What street did you grow up on?">What street did you grow up on?</option>
                                    <option value="What was your childhood nickname?">What was your childhood nickname?
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label
                                    style="display: block; margin-bottom: 7px; font-size: 0.85rem; font-weight: 600; color: #1a4d31;">
                                    <i class="fas fa-key"></i> Your Answer
                                </label>
                                <input type="text" name="security_answer" required
                                    style="width: 100%; padding: 13px 16px; border: 1.5px solid #cae3d6; border-radius: 14px; font-family: 'Inter', sans-serif; font-size: 0.92rem; color: #1e3a2f; background: #f9fffc; outline: none; box-sizing: border-box;"
                                    placeholder="Your answer (not case-sensitive)" autocomplete="off">
                                <small style="color:#8faa9a; font-size:0.78rem; margin-top:4px; display:block;">
                                    <i class="fas fa-info-circle"></i> Used to recover your account if you forget your
                                    password.
                                </small>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary" style="width: 100%; padding: 16px; font-size: 1.1rem;">
                            <i class="fas fa-user-plus"></i> Create Customer Account
                        </button>
                    </form>

                    <div style="text-align: center; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e0f0e8;">
                        <p style="color: #3b7455;">
                            Already have an account? <a href="<?php echo url('index.php?page=login'); ?>"
                                style="color: #007a3e; font-weight: 600;">Sign In</a>
                        </p>
                    </div>

                    <div style="text-align: center; margin-top: 15px; font-size: 0.85rem; color: #8faa9a;">
                        <i class="fas fa-shield-alt"></i> Your information is secure and encrypted
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Terms and Conditions Modal -->
    <div id="termsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-file-contract"></i> Terms and Conditions</h2>
                <button class="modal-close" onclick="closeModal('terms')">&times;</button>
            </div>
            <div class="modal-body">
                <h3>1. Acceptance of Terms</h3>
                <p>By accessing and using UniCanteen ("the Platform"), you agree to be bound by these Terms and
                    Conditions. If you do not agree to these terms, please do not use the Platform.</p>

                <h3>2. User Accounts</h3>
                <p>2.1. You must be a current student, faculty member, or staff of De La Salle University to create an
                    account.<br>
                    2.2. You are responsible for maintaining the confidentiality of your account credentials.<br>
                    2.3. You must provide accurate and complete information during registration.<br>
                    2.4. You are solely responsible for all activities that occur under your account.</p>

                <h3>3. Ordering and Payments</h3>
                <p>3.1. All orders placed through UniCanteen are final and cannot be canceled once confirmed.<br>
                    3.2. Payments are processed directly at the food stall upon pickup. No online payments are processed
                    through the Platform.<br>
                    3.3. Prices displayed are in Philippine Peso (PHP) and are subject to change without prior
                    notice.<br>
                    3.4. Food stalls reserve the right to modify menu items and prices.</p>

                <h3>4. Order Pickup</h3>
                <p>4.1. Orders must be picked up within the designated time window.<br>
                    4.2. A valid queue number or order confirmation must be presented upon pickup.<br>
                    4.3. UniCanteen and participating stalls are not responsible for orders not picked up within the
                    specified time.</p>

                <h3>5. Prohibited Conduct</h3>
                <p>5.1. You agree not to:<br>
                    - Create fake or fraudulent orders<br>
                    - Attempt to manipulate the queue system<br>
                    - Use the Platform for any illegal purpose<br>
                    - Interfere with the proper functioning of the Platform<br>
                    - Impersonate another user or vendor</p>

                <h3>6. Intellectual Property</h3>
                <p>All content on UniCanteen, including logos, designs, text, and software, is the property of
                    UniCanteen and its licensors and is protected by intellectual property laws.</p>

                <h3>7. Limitation of Liability</h3>
                <p>UniCanteen serves as a platform connecting users with food stalls. We are not liable for:<br>
                    - The quality of food or services provided by stalls<br>
                    - Delays in order preparation<br>
                    - Any disputes between users and vendors<br>
                    - Technical issues beyond our reasonable control</p>

                <h3>8. Termination</h3>
                <p>We reserve the right to suspend or terminate accounts that violate these terms, including but not
                    limited to creating fake orders or engaging in fraudulent activity.</p>

                <h3>9. Modifications</h3>
                <p>UniCanteen may modify these terms at any time. Continued use of the Platform constitutes acceptance
                    of modified terms.</p>

                <div class="last-updated">
                    <i class="fas fa-calendar-alt"></i> Last Updated: January 15, 2024
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('terms')">I Understand</button>
            </div>
        </div>
    </div>

    <!-- Privacy Policy Modal -->
    <div id="privacyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-shield-alt"></i> Privacy Policy</h2>
                <button class="modal-close" onclick="closeModal('privacy')">&times;</button>
            </div>
            <div class="modal-body">
                <h3>1. Information We Collect</h3>
                <p><strong>Personal Information:</strong><br>
                    - Full name<br>
                    - Email address (DLSU email)<br>
                    - User role (student, faculty, staff)<br>
                    - Order history<br>
                    - Ratings and reviews</p>

                <p><strong>Automatically Collected Information:</strong><br>
                    - IP address<br>
                    - Browser type and version<br>
                    - Device information<br>
                    - Usage patterns and preferences</p>

                <h3>2. How We Use Your Information</h3>
                <p>We use your information to:<br>
                    - Create and manage your account<br>
                    - Process and track your orders<br>
                    - Improve our services and user experience<br>
                    - Communicate important updates<br>
                    - Prevent fraudulent activities<br>
                    - Comply with legal obligations</p>

                <h3>3. Data Sharing and Disclosure</h3>
                <p>3.1. <strong>With Food Vendors:</strong> Your name and order details are shared with vendors to
                    fulfill your orders.<br>
                    3.2. <strong>With University Administration:</strong> Account information may be shared to verify
                    university affiliation.<br>
                    3.3. <strong>Legal Compliance:</strong> We may disclose information if required by law or to protect
                    our rights.<br>
                    3.4. We do not sell your personal information to third parties.</p>

                <h3>4. Data Security</h3>
                <p>We implement appropriate technical and organizational measures to protect your information,
                    including:<br>
                    - Encryption of sensitive data<br>
                    - Regular security assessments<br>
                    - Access controls and authentication<br>
                    - Secure session management</p>

                <h3>5. Your Rights</h3>
                <p>You have the right to:<br>
                    - Access your personal information<br>
                    - Correct inaccurate data<br>
                    - Request deletion of your account<br>
                    - Opt-out of non-essential communications<br>
                    - Export your data</p>

                <h3>6. Cookies and Tracking</h3>
                <p>We use cookies and similar technologies to:<br>
                    - Maintain your session<br>
                    - Remember your preferences<br>
                    - Analyze platform usage<br>
                    - Improve functionality</p>

                <h3>7. Data Retention</h3>
                <p>We retain your information for as long as your account is active and for a reasonable period
                    thereafter to comply with legal obligations and resolve disputes.</p>

                <h3>8. Third-Party Links</h3>
                <p>The Platform may contain links to third-party websites. We are not responsible for their privacy
                    practices.</p>

                <h3>9. Children's Privacy</h3>
                <p>UniCanteen is intended for university students, faculty, and staff. We do not knowingly collect
                    information from individuals under 18 without parental consent.</p>

                <h3>10. Changes to Privacy Policy</h3>
                <p>We may update this Privacy Policy periodically. Material changes will be communicated through the
                    Platform.</p>

                <h3>11. Contact Information</h3>
                <div class="highlight">
                    <p><i class="fas fa-envelope"></i> <strong>Email:</strong> privacy@unicanteen.dlsu.edu.ph<br>
                        <i class="fas fa-phone"></i> <strong>Phone:</strong> (02) 1234-5678<br>
                        <i class="fas fa-map-marker-alt"></i> <strong>Address:</strong> De La Salle University, 2401
                        Taft Avenue, Manila, Philippines
                    </p>
                </div>

                <div class="last-updated">
                    <i class="fas fa-calendar-alt"></i> Last Updated: January 15, 2024
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('privacy')">I Understand</button>
            </div>
        </div>
    </div>

    <script>
        // Password toggle functionality
        document.getElementById('togglePassword').addEventListener('click', function () {
            const password = document.getElementById('password');
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        document.getElementById('toggleConfirmPassword').addEventListener('click', function () {
            const password = document.getElementById('confirm_password');
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        // Password strength checker
        document.getElementById('password').addEventListener('input', function () {
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

        // Password match checker
        document.getElementById('confirm_password').addEventListener('input', function () {
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

        // Modal functions
        function openModal(type) {
            const modal = document.getElementById(type + 'Modal');
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden'; // Prevent scrolling
        }

        function closeModal(type) {
            const modal = document.getElementById(type + 'Modal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto'; // Restore scrolling
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        // Prevent checkbox from being checked unless user has viewed documents
        const termsCheckbox = document.querySelector('input[name="agree_terms"]');

        termsCheckbox.addEventListener('change', function (e) {
            if (this.checked) {
                const termsViewed = sessionStorage.getItem('termsViewed') === 'true';
                const privacyViewed = sessionStorage.getItem('privacyViewed') === 'true';
                if (!termsViewed || !privacyViewed) {
                    e.preventDefault();
                    this.checked = false;
                    alert('Please read both the Terms and Conditions and Privacy Policy before agreeing.');
                }
            }
        });

        // Mark as viewed when modals are closed
        function markAsViewed(type) {
            sessionStorage.setItem(type + 'Viewed', 'true');
        }

        // Override closeModal to mark as viewed
        const originalCloseModal = closeModal;
        closeModal = function (type) {
            originalCloseModal(type);
            markAsViewed(type);
        }
    </script>
</body>

</html>