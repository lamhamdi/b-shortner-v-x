<?php
class Utils {
    // Detect user intent from message
    public static function detectIntent($message) {
        $intents = [
            'greeting' => ['hello', 'hi', 'hey', 'greetings', 'السلام عليكم', 'مرحبا', 'اهلا', 'صباح الخير', 'مساء الخير'],
            'farewell' => ['goodbye', 'bye', 'see you', 'مع السلامة', 'الى اللقاء', 'وداعا'],
            'help' => ['help', 'assist', 'support', 'مساعدة', 'دعم', 'المساعدة'],
            'product_info' => ['product', 'item', 'buy', 'purchase', 'منتج', 'شراء', 'بضاعة', 'المنتجات', 'العناصر'],
            'price_inquiry' => ['price', 'cost', 'how much', 'سعر', 'تكلفة', 'كم يكلف', 'كم سعر', 'بكم'],
            'complaint' => ['complaint', 'issue', 'problem', 'not working', 'شكوى', 'مشكلة', 'عطل', 'لا يعمل'],
            'contact_request' => ['contact', 'call', 'phone', 'email', 'اتصال', 'هاتف', 'بريد', 'تواصل']
        ];
        
        $message = strtolower($message);
        $detectedIntent = 'general';
        $highestScore = 0;
        
        foreach ($intents as $intent => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (strpos($message, $keyword) !== false) {
                    $score++;
                }
            }
            
            if ($score > $highestScore) {
                $highestScore = $score;
                $detectedIntent = $intent;
            }
        }
        
        return $detectedIntent;
    }
    
    // Basic sentiment analysis
    public static function detectSentiment($text) {
        $positiveWords = ['good', 'great', 'excellent', 'awesome', 'love', 'like', 'جيد', 'ممتاز', 'رائع', 'احب', 'اعجبني'];
        $negativeWords = ['bad', 'poor', 'terrible', 'hate', 'dislike', 'سيء', 'رديء', 'كريه', 'اكره', 'لا اعجبني'];
        
        $text = strtolower($text);
        
        $positiveScore = 0;
        $negativeScore = 0;
        
        foreach ($positiveWords as $word) {
            if (strpos($text, $word) !== false) {
                $positiveScore++;
            }
        }
        
        foreach ($negativeWords as $word) {
            if (strpos($text, $word) !== false) {
                $negativeScore++;
            }
        }
        
        if ($positiveScore > $negativeScore) {
            return 'positive';
        } elseif ($negativeScore > $positiveScore) {
            return 'negative';
        } else {
            return 'neutral';
        }
    }
    
    // Extract user info from message
    public static function extractUserInfo($message) {
        $userInfo = [];
        
        // Email pattern
        if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $message, $matches)) {
            $userInfo['email'] = $matches[0];
        }
        
        // Phone pattern (simple pattern, can be expanded)
        if (preg_match('/\b(?:\+?(\d{1,3}))?[-. (]*(\d{3})[-. )]*(\d{3})[-. ]*(\d{4})\b/', $message, $matches)) {
            $userInfo['phone'] = $matches[0];
        }
        
        // Name pattern (very basic - look for "my name is" or "I am" followed by words)
        if (preg_match('/(?:my name is|I am|أنا|اسمي) ([A-Za-zأ-ي]+(?:\s[A-Za-zأ-ي]+){0,3})/i', $message, $matches)) {
            $userInfo['name'] = trim($matches[1]);
        }
        
        return $userInfo;
    }
    
    // Enhance prompt based on intent and user history
    public static function enhancePrompt($prompt, $intent, $userId) {
        $enhancedPrompt = $prompt;
        
        // Add context based on intent
        switch ($intent) {
            case 'greeting':
                // Get user's name if available
                $conn = new mysqli(
                    DB_HOST,
                    DB_USER,
                    DB_PASS,
                    DB_NAME
                );
                
                if (!$conn->connect_error) {
                    $userId = $conn->real_escape_string($userId);
                    $result = $conn->query("SELECT name FROM chat_users WHERE user_id = '$userId'");
                    if ($result && $result->num_rows > 0) {
                        $user = $result->fetch_assoc();
                        if (!empty($user['name'])) {
                            $enhancedPrompt = "The user's name is {$user['name']}. " . $enhancedPrompt;
                        }
                    }
                    $conn->close();
                }
                break;
                
            case 'product_info':
                // Add product context if we can detect product interest
                if (preg_match('/(mobile|phone|laptop|computer|جوال|هاتف|حاسوب|كمبيوتر)/i', $prompt, $matches)) {
                    $product = strtolower($matches[1]);
                    $enhancedPrompt = "The user is asking about $product products. " . $enhancedPrompt;
                }
                break;
                
            case 'price_inquiry':
                $enhancedPrompt = "The user is asking about pricing. Be specific and helpful about our products' prices. " . $enhancedPrompt;
                break;
                
            case 'complaint':
                $enhancedPrompt = "The user seems to have a complaint. Show empathy and offer solutions. " . $enhancedPrompt;
                break;
        }
        
        return $enhancedPrompt;
    }
    
    // Log errors to file with better formatting and details
    public static function logError($logPath, $message) {
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }
        
        $logFile = $logPath . 'error_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]) ? 
            basename($backtrace[1]['file']) . ':' . $backtrace[1]['line'] : 
            'unknown';
        
        $logMessage = "[$timestamp] [$caller] $message\n";
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
    // Log debug information
    public static function logDebug($logPath, $message, $data = null) {
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }
        
        $logFile = $logPath . 'debug_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]) ? 
            basename($backtrace[1]['file']) . ':' . $backtrace[1]['line'] : 
            'unknown';
        
        $logMessage = "[$timestamp] [$caller] $message\n";
        
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $logMessage .= json_encode($data, JSON_PRETTY_PRINT) . "\n";
            } else {
                $logMessage .= $data . "\n";
            }
        }
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}
