<?php
// frontend/login-content.php
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

                            $redirect = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : 'index.php?page=customer';
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
<div class="wrapper">
    <div class="auth-container">
        <div style="text-align: left; margin-bottom: 20px;">
            <a href="<?php echo url('index.php?page=customer'); ?>" class="btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
        </div>
        <a href="<?php echo url('index.php'); ?>" class="logo"
            style="display: block; text-align: center; margin-bottom: 30px;">UniCanteen</a>

        <h2 style="text-align: center; margin-bottom: 30px;">Welcome Back</h2>

        <?php if ($error): ?>
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
                <a href="<?php echo url('index.php?page=forgot-password'); ?>"
                    style="color: #007a3e; font-size: 0.9rem;">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-primary" style="width: 100%; padding: 16px; font-size: 1.1rem;">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>

        <div style="text-align: center; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e0f0e8;">
            <p style="color: #3b7455; margin-bottom: 10px;">New to UniCanteen?</p>
            <a href="<?php echo url('index.php?page=register'); ?>" class="btn-secondary"
                style="text-decoration: none; display: inline-block; padding: 12px 30px;">
                <i class="fas fa-user-plus"></i> Create Customer Account
            </a>
        </div>

        <div style="text-align: center; margin-top: 15px; font-size: 0.85rem; color: #8faa9a;">
            <i class="fas fa-shield-alt"></i> Your information is secure and encrypted
        </div>
    </div>
</div>

<script>
    document.getElementById('togglePassword')?.addEventListener('click', function () {
        const password = document.getElementById('password');
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        this.classList.toggle('fa-eye');
        this.classList.toggle('fa-eye-slash');
    });
</script>