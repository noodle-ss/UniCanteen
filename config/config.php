<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Secure session configuration
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', 7200); // 2 hours
    ini_set('session.cookie_lifetime', 7200); // 2 hours
    
    session_start();
}

// Start output buffering to prevent header issues
ob_start();

// Site configuration - Check if constants are already defined before defining them
if (!defined('BASE_PATH')) {
    // Always compute BASE_PATH from the project root directory, not the current script.
    // This file lives in /UniCanteen/config/, so the project root is one level up.
    $configDir = str_replace('\\', '/', __DIR__);
    $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    $projectRoot = dirname($configDir); // one level up from /config
    $basePath = str_replace($docRoot, '', $projectRoot);
    define('BASE_PATH', rtrim($basePath, '/') . '/');
}
if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'UniCanteen');
}
if (!defined('SITE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    define('SITE_URL', $protocol . $_SERVER['HTTP_HOST'] . BASE_PATH);
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', $_SERVER['DOCUMENT_ROOT'] . '/mp-itprog/UniCanteen/uploads/');
}
if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', SITE_URL . '/uploads/');
}

// Security constants
if (!defined('BCRYPT_COST')) {
    define('BCRYPT_COST', 12);
}
if (!defined('MAX_LOGIN_ATTEMPTS')) {
    define('MAX_LOGIN_ATTEMPTS', 5);
}
if (!defined('LOCKOUT_TIME')) {
    define('LOCKOUT_TIME', 900); // 15 minutes in seconds
}
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 7200); // 2 hours
}

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('Asia/Manila');

// Include database configuration - only once
require_once __DIR__ . '/database.php';

// Helper functions (functions don't need defined() check, but we can use function_exists)
if (!function_exists('redirect')) {
    function redirect($url) {
        // If it's already an absolute URL (http/https), use as-is
        if (preg_match('/^https?:\/\//', $url)) {
            header("Location: $url");
        } else {
            // For all relative paths, use url() to generate proper absolute path
            header("Location: " . url($url));
        }
        exit();
    }
}

if (!function_exists('url')) {
    function url($path = '') {
        return BASE_PATH . ltrim($path, '/');
    }
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
    }
}

if (!function_exists('getUserRole')) {
    function getUserRole() {
        return $_SESSION['user_role'] ?? null;
    }
}

if (!function_exists('isCustomer')) {
    function isCustomer() {
        return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'U';
    }
}

if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

if (!function_exists('formatPrice')) {
    function formatPrice($price) {
        return '₱' . number_format($price, 2);
    }
}

if (!function_exists('getTimeAgo')) {
    function getTimeAgo($timestamp) {
        $time_ago = strtotime($timestamp);
        $current_time = time();
        $time_difference = $current_time - $time_ago;
        $seconds = $time_difference;
        
        $minutes = round($seconds / 60);
        $hours = round($seconds / 3600);
        $days = round($seconds / 86400);
        
        if ($seconds < 60) {
            return "Just now";
        } else if ($minutes < 60) {
            return $minutes . " minute" . ($minutes > 1 ? "s" : "") . " ago";
        } else if ($hours < 24) {
            return $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
        } else {
            return $days . " day" . ($days > 1 ? "s" : "") . " ago";
        }
    }
}

if (!function_exists('isValidDLSUEmail')) {
    function isValidDLSUEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

if (!function_exists('secureSessionRegenerate')) {
    function secureSessionRegenerate() {
        // Only regenerate if headers haven't been sent
        if (!headers_sent()) {
            session_regenerate_id(true);
            return true;
        }
        return false;
    }
}
?>