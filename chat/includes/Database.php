<?php

class Database {
    private static $instance = null;
    private $conn;
    private $config;
    
    private function __construct($config) {
        $this->config = $config;
        $this->connect();
    }
    
    // Singleton pattern to get database instance
    public static function getInstance($config = null) {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }
    
    // Connect to database
    private function connect() {
        $this->conn = new mysqli(
            $this->config['db']['host'],
            $this->config['db']['user'],
            $this->config['db']['pass'],
            $this->config['db']['name']
        );
        
        if ($this->conn->connect_error) {
            error_log("Database connection failed: " . $this->conn->connect_error);
            throw new Exception("Database connection failed: " . $this->conn->connect_error);
        }
        
        // Set charset to support multilingual content
        $this->conn->set_charset("utf8mb4");
    }
    
    // Get database connection
    public function getConnection() {
        return $this->conn;
    }
    
    // Initialize and update database tables with proper order
    public function initializeTables() {
        try {
            // Start transaction
            $this->conn->begin_transaction();

            // Create users table first
            $this->conn->query("CREATE TABLE IF NOT EXISTS `chat_users` (
                `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` varchar(50) NOT NULL UNIQUE,
                `name` varchar(100),
                `email` varchar(100),
                `phone` varchar(20),
                `language` varchar(10) DEFAULT 'ar',
                `preferences` text,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `last_visit` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Create conversations table next
            $this->conn->query("CREATE TABLE IF NOT EXISTS `chat_conversations` (
                `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `conversation_id` varchar(50) NOT NULL UNIQUE,
                `user_id` varchar(50) NOT NULL,
                `title` varchar(255),
                `language` varchar(10) DEFAULT 'ar',
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                FOREIGN KEY (`user_id`) REFERENCES `chat_users`(`user_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Drop and recreate messages table
            $this->conn->query("DROP TABLE IF EXISTS `chat_messages`");
            
            // Create messages table with updated structure
            $this->conn->query("CREATE TABLE IF NOT EXISTS `chat_messages` (
                `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `conversation_id` varchar(50) NOT NULL,
                `role` enum('user', 'model') NOT NULL,
                `content` text NOT NULL,
                `detected_intent` varchar(100),
                `sentiment` enum('positive', 'neutral', 'negative'),
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations`(`conversation_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Commit transaction
            $this->conn->commit();
            
        } catch (Exception $e) {
            // Rollback on error
            $this->conn->rollback();
            throw new Exception("Failed to initialize tables: " . $e->getMessage());
        }
    }
        // Users table
        $this->conn->query("CREATE TABLE IF NOT EXISTS chat_users (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(20),
            language VARCHAR(10) DEFAULT 'ar',
            preferences TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_visit TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Conversations table
        $this->conn->query("CREATE TABLE IF NOT EXISTS chat_conversations (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            conversation_id VARCHAR(50) NOT NULL UNIQUE,
            user_id VARCHAR(50) NOT NULL,
            title VARCHAR(255),
            language VARCHAR(10) DEFAULT 'ar',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES chat_users(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Messages table
        $this->conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            conversation_id VARCHAR(50) NOT NULL,
            role ENUM('user', 'model') NOT NULL,
            content TEXT NOT NULL,
            detected_intent VARCHAR(100),
            sentiment ENUM('positive', 'neutral', 'negative'),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(conversation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Analytics table
        $this->conn->query("CREATE TABLE IF NOT EXISTS chat_analytics (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) NOT NULL,
            conversation_id VARCHAR(50) NOT NULL,
            session_duration INT,
            messages_count INT,
            feature_used VARCHAR(50),
            user_rating INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES chat_users(user_id),
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(conversation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Product Recommendations table
        $this->conn->query("CREATE TABLE IF NOT EXISTS chat_product_recommendations (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) NOT NULL,
            product_id INT NOT NULL,
            score FLOAT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES chat_users(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    
    // Helper method for database queries
    public function query($sql, $params = []) {
        if (empty($params)) {
            return $this->conn->query($sql);
        }
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Query preparation failed: " . $this->conn->error);
            return false;
        }
        
        if (!empty($params)) {
            $types = '';
            $bindParams = [];
            
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } elseif (is_string($param)) {
                    $types .= 's';
                } else {
                    $types .= 'b';
                }
                $bindParams[] = $param;
            }
            
            array_unshift($bindParams, $types);
            call_user_func_array([$stmt, 'bind_param'], $this->refValues($bindParams));
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        return $result;
    }
    
    // Helper method for binding parameters by reference
    private function refValues($arr) {
        $refs = [];
        foreach ($arr as $key => $value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    }
    
    // Close connection
    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}
