<?php
// Prevent multiple inclusions
if (!function_exists('requireLogin')) {
    require_once __DIR__ . '/config.php';

    function requireLogin()
    {
        if (!isLoggedIn()) {
            // Strip BASE_PATH so redirect() won't double-prefix it
            $uri = $_SERVER['REQUEST_URI'];
            if (strpos($uri, BASE_PATH) === 0) {
                $uri = substr($uri, strlen(BASE_PATH));
            }
            $_SESSION['redirect_after_login'] = $uri;
            redirect('index.php?page=login');
        }
    }

    function requireCustomer()
    {
        requireLogin();
        if (!isCustomer()) {
            redirect('index.php?error=unauthorized');
        }
    }

    function requireVendor()
    {
        requireLogin();
        if (getUserRole() !== 'V') {
            redirect('index.php?error=unauthorized');
        }
    }

    function requireAdmin()
    {
        requireLogin();
        if (getUserRole() !== 'A') {
            redirect('index.php?error=unauthorized');
        }
    }

    // Validate session from database
    function validateSession()
    {
        if (isset($_SESSION['session_token']) && isset($_SESSION['user_id'])) {
            $database = Database::getInstance();
            $session = $database->validateSession($_SESSION['session_token']);

            if (!$session) {
                // Invalid or expired session
                session_destroy();
                redirect('index.php?page=login&expired=1');
            }

            // Refresh session data
            $_SESSION['user_id'] = $session['user_id'];
            $_SESSION['user_role'] = $session['role'];
            $_SESSION['user_name'] = $session['full_name'];
            $_SESSION['user_email'] = $session['email'];
        }
    }

    // Call this on every page
    validateSession();
}
?>