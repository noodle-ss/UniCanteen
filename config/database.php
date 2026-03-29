<?php
/**
 * database.php — Database connection and session management.
 *
 * Implements a Singleton pattern for the MySQLi database connection to 
 * ensure only one instance is active. Also handles session creation, 
 * validation, and database transaction helpers.
 */
// config/database.php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'unicanteen');
define('DB_PORT', '3306');

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            
            // Make sure charset is set correctly
            $this->connection->set_charset("utf8mb4");
        
            // Set the MySQL client to return strict integers
            $this->connection->query("SET sql_mode = 'STRICT_ALL_TABLES'");

        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }
    
    public function query($sql) {
        return $this->connection->query($sql);
    }
    
    public function escapeString($string) {
        return $this->connection->real_escape_string($string);
    }
    
    public function getLastInsertId() {
        return $this->connection->insert_id;
    }
    
    public function beginTransaction() {
        $this->connection->begin_transaction();
    }
    
    public function commit() {
        $this->connection->commit();
    }
    
    public function rollback() {
        $this->connection->rollback();
    }
    
    public function createSession($user_id) {
        $token = bin2hex(random_bytes(32));
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $expires_at = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        
        $deleteStmt = $this->connection->prepare("DELETE FROM Sessions WHERE user_id = ?");
        $deleteStmt->bind_param("i", $user_id);
        $deleteStmt->execute();
        
        $insertStmt = $this->connection->prepare(
            "INSERT INTO Sessions (user_id, session_token, ip_address, user_agent, expires_at) 
             VALUES (?, ?, ?, ?, ?)"
        );
        $insertStmt->bind_param("issss", $user_id, $token, $ip_address, $user_agent, $expires_at);
        $insertStmt->execute();
        
        return $token;
    }
    
    public function validateSession($token) {
        $stmt = $this->connection->prepare(
            "SELECT s.*, u.ID as user_id, u.role, u.full_name, u.email 
             FROM Sessions s
             JOIN Users u ON s.user_id = u.ID
             WHERE s.session_token = ? AND s.expires_at > NOW() AND u.is_active = TRUE AND u.is_banned = FALSE"
        );
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $session = $result->fetch_assoc();
            
            // Refresh session expiration time
            $lifetime = SESSION_LIFETIME;
            $updateStmt = $this->connection->prepare(
                "UPDATE Sessions SET expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE session_token = ?"
            );
            $updateStmt->bind_param("is", $lifetime, $token);
            $updateStmt->execute();
            
            return $session;
        }
        
        return null;
    }
}
?>