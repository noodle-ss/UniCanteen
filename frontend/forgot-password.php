<?php
require_once __DIR__ . '/../config/config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$db = Database::getInstance()->getConnection();

// ─── STEP MANAGEMENT ──────────────────────────────────────────────────────────
// step 1: enter email  → step 2: answer security question → step 3: set new password
$step = intval($_POST['step'] ?? $_GET['step'] ?? 1);
$error = '';
$success = '';
$email = '';
$security_question = '';
$user_id = 0;

// ─── STEP 1 HANDLER: look up email ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request token.';
    } else {
        $email = sanitizeInput($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $stmt = $db->prepare("SELECT ID, security_question FROM Users WHERE email = ? AND is_active = 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if ($row && !empty($row['security_question'])) {
                // Store in session so step 2 can use it
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_user_id'] = $row['ID'];
                $_SESSION['reset_step'] = 2;
                $step = 2;
                $security_question = $row['security_question'];
            } else {
                // Vague message intentionally – don't reveal whether email exists or has no question
                $error = 'No account with a security question was found for that email.';
            }
        }
    }
}

// ─── STEP 2 HANDLER: verify security answer ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request token.';
        $step = 1;
    } elseif (empty($_SESSION['reset_email']) || ($_SESSION['reset_step'] ?? 0) < 2) {
        $error = 'Session expired. Please start again.';
        $step = 1;
    } else {
        $email = $_SESSION['reset_email'];
        $user_id = intval($_SESSION['reset_user_id']);
        $answer = sanitizeInput($_POST['security_answer'] ?? '');

        $stmt = $db->prepare("SELECT security_question, security_answer FROM Users WHERE ID = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $security_question = $row['security_question'] ?? '';

        if (empty($answer)) {
            $error = 'Please enter your answer.';
            $step = 2; // stay on step 2
        } elseif (!$row || !password_verify(strtolower(trim($answer)), $row['security_answer'])) {
            $error = 'Incorrect answer. Please try again.';
            $step = 2;
        } else {
            // Answer correct – advance to step 3
            $_SESSION['reset_step'] = 3;
            $_SESSION['reset_verified'] = true;
            $step = 3;
        }
    }
}

// ─── STEP 3 HANDLER: set new password ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request token.';
        $step = 1;
    } elseif (empty($_SESSION['reset_email']) || ($_SESSION['reset_step'] ?? 0) < 3 || empty($_SESSION['reset_verified'])) {
        $error = 'Session expired or unauthorized. Please start again.';
        $step = 1;
    } else {
        $user_id = intval($_SESSION['reset_user_id']);
        $email = $_SESSION['reset_email'];
        $new_pw = $_POST['new_password'] ?? '';
        $conf_pw = $_POST['confirm_password'] ?? '';

        if (strlen($new_pw) < 8) {
            $error = 'Password must be at least 8 characters.';
            $step = 3;
        } elseif (!preg_match('/[A-Z]/', $new_pw)) {
            $error = 'Password must contain at least one uppercase letter.';
            $step = 3;
        } elseif (!preg_match('/[a-z]/', $new_pw)) {
            $error = 'Password must contain at least one lowercase letter.';
            $step = 3;
        } elseif (!preg_match('/[0-9]/', $new_pw)) {
            $error = 'Password must contain at least one number.';
            $step = 3;
        } elseif ($new_pw !== $conf_pw) {
            $error = 'Passwords do not match.';
            $step = 3;
        } else {
            $hashed = password_hash($new_pw, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
            $upd = $db->prepare("UPDATE Users SET password = ?, login_attempts = 0, locked_until = NULL WHERE ID = ?");
            $upd->bind_param("si", $hashed, $user_id);
            if ($upd->execute()) {
                // Log action
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $log = $db->prepare("INSERT INTO UserLogs (user_id, action, ip_address) VALUES (?, 'PASSWORD_RESET', ?)");
                $log->bind_param("is", $user_id, $ip);
                $log->execute();

                // Clear reset session data
                unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['reset_step'], $_SESSION['reset_verified']);
                $success = 'Password reset successfully! You can now log in with your new password.';
                $step = 4; // done state
            } else {
                $error = 'Failed to update password. Please try again.';
                $step = 3;
            }
        }
    }
}

// ─── Restore state for GET (when returning from a POST redirect) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['reset_step'])) {
    $step = intval($_SESSION['reset_step']);
    if ($step >= 2) {
        $email = $_SESSION['reset_email'] ?? '';
        $uid = intval($_SESSION['reset_user_id'] ?? 0);
        if ($uid) {
            $stmt = $db->prepare("SELECT security_question FROM Users WHERE ID = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $security_question = $stmt->get_result()->fetch_assoc()['security_question'] ?? '';
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
    <title>Forgot Password – UniCanteen</title>
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

        .fp-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background: linear-gradient(135deg, #f0f7f2 0%, #e8f5ee 100%);
        }

        .fp-card {
            background: white;
            border-radius: 28px;
            padding: 44px 40px;
            width: 100%;
            max-width: 460px;
            box-shadow: 0 20px 60px rgba(0, 70, 30, 0.12);
            border: 1px solid #d4eede;
        }

        .fp-logo {
            display: block;
            text-align: center;
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--dlsu-green);
            text-decoration: none;
            margin-bottom: 28px;
            letter-spacing: -0.5px;
        }

        .fp-logo span {
            color: #86c49f;
        }

        /* Progress stepper */
        .stepper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 32px;
        }

        .step-dot {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            border: 2px solid #cae3d6;
            color: #aac8b8;
            background: white;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .step-dot.done {
            background: var(--dlsu-green);
            border-color: var(--dlsu-green);
            color: white;
        }

        .step-dot.active {
            background: var(--dlsu-green);
            border-color: var(--dlsu-green);
            color: white;
            box-shadow: 0 0 0 4px rgba(0, 122, 62, 0.15);
        }

        .step-line {
            flex: 1;
            height: 2px;
            background: #d4eede;
            max-width: 60px;
        }

        .step-line.done {
            background: var(--dlsu-green);
        }

        .step-label {
            text-align: center;
            font-size: 0.72rem;
            color: #8faa9a;
            margin-top: 6px;
        }

        .stepper-row {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .fp-title {
            text-align: center;
            font-size: 1.4rem;
            font-weight: 700;
            color: #0f4a2f;
            margin: 0 0 6px;
        }

        .fp-sub {
            text-align: center;
            color: #5f8b74;
            font-size: 0.9rem;
            margin: 0 0 28px;
        }

        .fp-field {
            margin-bottom: 20px;
        }

        .fp-field label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #1a4d31;
            margin-bottom: 7px;
        }

        .fp-field input {
            width: 100%;
            padding: 13px 18px;
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

        .fp-field input:focus {
            border-color: var(--dlsu-green);
            box-shadow: 0 0 0 3px rgba(0, 122, 62, 0.1);
            background: white;
        }

        .question-box {
            background: #f0f7f2;
            border: 1px solid #cae3d6;
            border-radius: 14px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            color: #1a4d31;
        }

        .question-box i {
            color: var(--dlsu-green);
            margin-right: 6px;
        }

        .pw-toggle {
            position: relative;
        }

        .pw-toggle input {
            padding-right: 48px;
        }

        .pw-eye {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #8faa9a;
            font-size: 0.95rem;
        }

        .pw-strength {
            margin-top: 6px;
            height: 4px;
            background: #e0f0e8;
            border-radius: 2px;
            overflow: hidden;
        }

        .pw-strength-fill {
            height: 100%;
            width: 0;
            transition: width 0.3s, background 0.3s;
            border-radius: 2px;
        }

        .fp-btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 40px;
            background: var(--dlsu-green);
            color: white;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 8px;
        }

        .fp-btn:hover {
            background: var(--dlsu-darkgreen);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0, 122, 62, 0.25);
        }

        .fp-back {
            display: block;
            text-align: center;
            margin-top: 18px;
            color: #5f8b74;
            font-size: 0.88rem;
            text-decoration: none;
        }

        .fp-back:hover {
            color: var(--dlsu-green);
        }

        .fp-alert {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.88rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .fp-alert.error {
            background: #fee9e9;
            color: #b13e3e;
            border: 1px solid #f5c6cb;
        }

        .fp-alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .done-icon {
            width: 72px;
            height: 72px;
            background: #d4edda;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #155724;
            margin: 0 auto 20px;
        }
    </style>
</head>

<body>
    <div class="main-content">
        <div class="fp-wrapper">
            <div class="fp-card">

                <a href="<?php echo url('index.php'); ?>" class="fp-logo">UniCanteen <span>DLSU</span></a>

                <!-- ── Stepper ── -->
                <?php $activeStep = min($step, 3); ?>
                <div style="display:flex; justify-content:center; align-items:flex-start; gap:0; margin-bottom:32px;">
                    <?php
                    $labels = ['Email', 'Security Q', 'New Password'];
                    for ($i = 1; $i <= 3; $i++):
                        $cls = $i < $activeStep ? 'done' : ($i === $activeStep ? 'active' : '');
                        ?>
                        <div class="stepper-row">
                            <div class="step-dot <?php echo $cls; ?>">
                                <?php echo $i < $activeStep ? '<i class="fas fa-check"></i>' : $i; ?>
                            </div>
                            <div class="step-label"><?php echo $labels[$i - 1]; ?></div>
                        </div>
                        <?php if ($i < 3): ?>
                            <div class="step-line <?php echo $i < $activeStep ? 'done' : ''; ?>" style="margin-top:17px;"></div>
                        <?php endif; endfor; ?>
                </div>

                <?php if ($error): ?>
                    <div class="fp-alert error"><i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- ═══════════════════════════════════════════════
           STEP 1: Enter Email
      ═══════════════════════════════════════════════ -->
                <?php if ($step === 1): ?>
                    <div class="fp-title">Forgot Password?</div>
                    <div class="fp-sub">Enter your email and we'll show your security question.</div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="step" value="1">
                        <div class="fp-field">
                            <label><i class="fas fa-envelope"></i> Email Address</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>"
                                placeholder="your@email.com" required autofocus>
                        </div>
                        <button type="submit" class="fp-btn"><i class="fas fa-arrow-right"></i> Continue</button>
                    </form>
                    <a href="<?php echo url('index.php?page=login'); ?>" class="fp-back">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>

                    <!-- ═══════════════════════════════════════════════
           STEP 2: Answer Security Question
      ═══════════════════════════════════════════════ -->
                <?php elseif ($step === 2): ?>
                    <div class="fp-title">Security Question</div>
                    <div class="fp-sub">Answer correctly to reset your password.</div>
                    <div class="question-box">
                        <i class="fas fa-shield-alt"></i>
                        <?php echo htmlspecialchars($security_question ?: ($_SESSION['reset_question'] ?? '')); ?>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="step" value="2">
                        <div class="fp-field">
                            <label><i class="fas fa-key"></i> Your Answer</label>
                            <input type="text" name="security_answer" placeholder="Type your answer here…" required
                                autofocus autocomplete="off">
                            <small style="color:#8faa9a; font-size:0.78rem; margin-top:4px; display:block;">
                                <i class="fas fa-info-circle"></i> Not case-sensitive
                            </small>
                        </div>
                        <button type="submit" class="fp-btn"><i class="fas fa-check"></i> Verify Answer</button>
                    </form>
                    <a href="<?php echo url('index.php?page=forgot-password'); ?>" class="fp-back"
                        onclick="<?php echo "fetch(''.concat('', window.location.href.replace(/[^/]+$/, '')), {method:'GET'});"; ?>">
                        <i class="fas fa-arrow-left"></i> Start over
                    </a>

                    <!-- ═══════════════════════════════════════════════
           STEP 3: Set New Password
      ═══════════════════════════════════════════════ -->
                <?php elseif ($step === 3): ?>
                    <div class="fp-title">Set New Password</div>
                    <div class="fp-sub">Choose a strong password for your account.</div>
                    <form method="POST" id="resetForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="step" value="3">
                        <div class="fp-field">
                            <label><i class="fas fa-lock"></i> New Password</label>
                            <div class="pw-toggle">
                                <input type="password" name="new_password" id="newPw" placeholder="At least 8 characters"
                                    required minlength="8" autofocus>
                                <i class="fas fa-eye pw-eye" id="toggleNewPw"></i>
                            </div>
                            <div class="pw-strength">
                                <div class="pw-strength-fill" id="pwFill"></div>
                            </div>
                            <small id="pwStrengthText"
                                style="color:#8faa9a; font-size:0.78rem; margin-top:4px; display:block;">Enter
                                password</small>
                        </div>
                        <div class="fp-field">
                            <label><i class="fas fa-lock"></i> Confirm Password</label>
                            <div class="pw-toggle">
                                <input type="password" name="confirm_password" id="confPw" placeholder="Re-enter password"
                                    required>
                                <i class="fas fa-eye pw-eye" id="toggleConfPw"></i>
                            </div>
                            <small id="matchText" style="font-size:0.78rem; margin-top:4px; display:block;"></small>
                        </div>
                        <div
                            style="background:#f0f7f2; border-radius:12px; padding:12px 16px; margin-bottom:16px; font-size:0.82rem; color:#3b7455;">
                            <i class="fas fa-info-circle" style="color:var(--dlsu-green);"></i>
                            Minimum 8 characters · uppercase · lowercase · number
                        </div>
                        <button type="submit" class="fp-btn"><i class="fas fa-save"></i> Reset Password</button>
                    </form>

                    <!-- ═══════════════════════════════════════════════
           STEP 4: Success
      ═══════════════════════════════════════════════ -->
                <?php elseif ($step === 4): ?>
                    <div class="done-icon"><i class="fas fa-check"></i></div>
                    <div class="fp-title" style="margin-bottom:8px;">Password Reset!</div>
                    <p style="text-align:center; color:#5f8b74; margin:0 0 28px;">
                        Your password has been updated successfully. You can now log in with your new password.
                    </p>
                    <a href="<?php echo url('index.php?page=login'); ?>" class="fp-btn"
                        style="display:block; text-align:center; text-decoration:none;">
                        <i class="fas fa-sign-in-alt"></i> Go to Login
                    </a>
                <?php endif; ?>

            </div><!-- /fp-card -->
        </div><!-- /fp-wrapper -->
    </div>

    <script>
        // Password visibility toggles
        document.getElementById('toggleNewPw')?.addEventListener('click', function () {
            const f = document.getElementById('newPw');
            f.type = f.type === 'password' ? 'text' : 'password';
            this.classList.toggle('fa-eye'); this.classList.toggle('fa-eye-slash');
        });
        document.getElementById('toggleConfPw')?.addEventListener('click', function () {
            const f = document.getElementById('confPw');
            f.type = f.type === 'password' ? 'text' : 'password';
            this.classList.toggle('fa-eye'); this.classList.toggle('fa-eye-slash');
        });

        // Password strength meter
        document.getElementById('newPw')?.addEventListener('input', function () {
            const v = this.value;
            const fill = document.getElementById('pwFill');
            const txt = document.getElementById('pwStrengthText');
            const criteria = [v.length >= 8, /[A-Z]/.test(v), /[a-z]/.test(v), /[0-9]/.test(v), /[^A-Za-z0-9]/.test(v)];
            const score = criteria.filter(Boolean).length;

            if (!v.length) {
                fill.style.width = '0%'; fill.style.background = '';
                txt.textContent = 'Enter password'; txt.style.color = '#8faa9a';
            } else if (score <= 2) {
                fill.style.width = '25%'; fill.style.background = '#b13e3e';
                txt.textContent = 'Weak'; txt.style.color = '#b13e3e';
            } else if (score === 3) {
                fill.style.width = '50%'; fill.style.background = '#f5b342';
                txt.textContent = 'Fair'; txt.style.color = '#f5b342';
            } else if (score === 4) {
                fill.style.width = '75%'; fill.style.background = '#2ecc71';
                txt.textContent = 'Good'; txt.style.color = '#2ecc71';
            } else {
                fill.style.width = '100%'; fill.style.background = '#007a3e';
                txt.textContent = 'Strong ✓'; txt.style.color = '#007a3e';
            }
        });

        // Password match indicator
        document.getElementById('confPw')?.addEventListener('input', function () {
            const match = document.getElementById('matchText');
            if (!this.value) { match.textContent = ''; return; }
            if (this.value === document.getElementById('newPw').value) {
                match.innerHTML = '<i class="fas fa-check-circle" style="color:#0c6e3a"></i> Passwords match';
                match.style.color = '#0c6e3a';
            } else {
                match.innerHTML = '<i class="fas fa-times-circle" style="color:#b13e3e"></i> Passwords do not match';
                match.style.color = '#b13e3e';
            }
        });
    </script>
</body>

</html>