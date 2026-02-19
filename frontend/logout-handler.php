<?php
require_once __DIR__ . '/../config/config.php';

if (isset($_SESSION['user_id']) && isset($_SESSION['session_token'])) {
    $db = Database::getInstance()->getConnection();
    
    $deleteStmt = $db->prepare("DELETE FROM Sessions WHERE session_token = ?");
    $deleteStmt->bind_param("s", $_SESSION['session_token']);
    $deleteStmt->execute();
}

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header("Location: ../index.php?page=customer&logout=success");
exit();
?>