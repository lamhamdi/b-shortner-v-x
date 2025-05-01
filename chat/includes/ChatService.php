<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ProductService.php';
require_once __DIR__ . '/GeminiAPI.php';
require_once __DIR__ . '/Utils.php';

class ChatService {
    private $db;
    private $config;
    private $geminiAPI;
    private $productService;
    
    public function __construct($config) {
        $this->config = $config;
        $this->initializeServices();
    }
    
    private function initializeServices() {
        try {
            $this->db = Database::getInstance($this->config)->getConnection();
            if ($this->db->connect_error) {
                throw new Exception("Database connection failed: " . $this->db->connect_error);
            }
            
            $this->geminiAPI = new GeminiAPI($this->config['gemini']);
            $this->productService = new ProductService();
            
        } catch (Exception $e) {
            Utils::logError($this->config['chat']['log_path'], "Service initialization error: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function ensureConnection() {
        if (!$this->db || !$this->db->ping()) {
            $this->initializeServices();
        }
    }
    
    // Get or create a user
    public function getOrCreateUser() {
        if (!isset($_SESSION['user_id'])) {
            $_SESSION['user_id'] = 'user_' . uniqid();
        }
        
        $userId = $this->db->real_escape_string($_SESSION['user_id']);
        
        // Check if user exists
        $result = $this->db->query("SELECT * FROM chat_users WHERE user_id = '$userId'");
        
        if ($result && $result->num_rows == 0) {
            // Create new user
            $language = $this->db->real_escape_string($_SESSION['language'] ?? $this->config['chat']['default_language']);
            $this->db->query("INSERT INTO chat_users (user_id, language) VALUES ('$userId', '$language')");
        }
        
        return $_SESSION['user_id'];
    }
    
    // Get or create a conversation
    public function getOrCreateConversation($userId) {
        if (!isset($_SESSION['conversation_id'])) {
            $_SESSION['conversation_id'] = 'conv_' . uniqid();
        }
        
        $conversationId = $this->db->real_escape_string($_SESSION['conversation_id']);
        $userId = $this->db->real_escape_string($userId);
        $language = $this->db->real_escape_string($_SESSION['language'] ?? $this->config['chat']['default_language']);
        
        // Check if conversation exists
        $result = $this->db->query("SELECT * FROM chat_conversations WHERE conversation_id = '$conversationId'");
        
        if ($result && $result->num_rows == 0) {
            // Create new conversation
            $title = $this->db->real_escape_string("Chat " . date('Y-m-d H:i'));
            $this->db->query("INSERT INTO chat_conversations (conversation_id, user_id, title, language) 
                             VALUES ('$conversationId', '$userId', '$title', '$language')");
        }
        
        return $_SESSION['conversation_id'];
    }
    
    // Save message to database
    public function saveMessage($conversationId, $role, $content, $intent = null) {
        $this->ensureConnection();
        $conversationId = $this->db->real_escape_string($conversationId);
        $role = $this->db->real_escape_string($role);
        $content = $this->db->real_escape_string($content);
        $intent = $intent ? $this->db->real_escape_string($intent) : null;
        
        // Perform basic sentiment analysis
        $sentiment = Utils::detectSentiment($content);
        
        $stmt = $this->db->prepare("INSERT INTO chat_messages (conversation_id, role, content, detected_intent, sentiment) 
                                  VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $conversationId, $role, $content, $intent, $sentiment);
        $result = $stmt->execute();
        
        $stmt->close();
        
        return $result;
    }
    
    // Load conversation history from database
    public function loadConversationHistory($conversationId, $limit = 20) {
        $conversationId = $this->db->real_escape_string($conversationId);
        $limit = (int)$limit;
        
        $result = $this->db->query("SELECT role, content FROM chat_messages 
                                  WHERE conversation_id = '$conversationId' 
                                  ORDER BY created_at DESC LIMIT $limit");
        
        $messages = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $messages[] = [
                    'role' => $row['role'],
                    'content' => $row['content']
                ];
            }
        }
        
        // Reverse to get chronological order
        return array_reverse($messages);
    }
    
    // Update user information
    public function updateUserInfo($userId, $data) {
        $userId = $this->db->real_escape_string($userId);
        
        $updateFields = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['name', 'email', 'phone', 'language', 'preferences'])) {
                $updateFields[] = $key . " = '" . $this->db->real_escape_string($value) . "'";
            }
        }
        
        if (empty($updateFields)) {
            return false;
        }
        
        $updateQuery = "UPDATE chat_users SET " . implode(", ", $updateFields) . " WHERE user_id = '$userId'";
        return $this->db->query($updateQuery);
    }
    
    // Log analytics event
    public function logAnalyticsEvent($userId, $conversationId, $eventType, $data = []) {
        $userId = $this->db->real_escape_string($userId);
        $conversationId = $this->db->real_escape_string($conversationId);
        $eventType = $this->db->real_escape_string($eventType);
        
        // Process specific event types
        if ($eventType === 'session_end') {
            $duration = isset($data['duration']) ? (int)$data['duration'] : 0;
            $messageCount = isset($data['messages_count']) ? (int)$data['messages_count'] : 0;
            
            $stmt = $this->db->prepare("INSERT INTO chat_analytics (user_id, conversation_id, session_duration, messages_count, feature_used) 
                                      VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssis", $userId, $conversationId, $duration, $messageCount, $eventType);
            $result = $stmt->execute();
            $stmt->close();
        } else {
            $feature = $this->db->real_escape_string($eventType);
            
            $stmt = $this->db->prepare("INSERT INTO chat_analytics (user_id, conversation_id, feature_used) 
                                      VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $userId, $conversationId, $feature);
            $result = $stmt->execute();
            $stmt->close();
        }
        
        return true;
    }
    
    // Generate product recommendations based on the conversation
    public function generateProductRecommendations($userId, $conversationId) {
        // Get the most recent messages
        $conversationId = $this->db->real_escape_string($conversationId);
        $result = $this->db->query("SELECT content FROM chat_messages 
                                  WHERE conversation_id = '$conversationId' AND role = 'user'
                                  ORDER BY created_at DESC LIMIT 10");
        
        $userMessages = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $userMessages[] = $row['content'];
            }
        }
        
        return $this->productService->generateRecommendationsFromMessages($userId, $userMessages);
    }
    
    // Get product recommendations for a user
    public function getProductRecommendations($userId, $count = 3) {
        return $this->productService->getRecommendationsForUser($userId, $count);
    }
    
    // Process user message and generate AI response with improved error handling
    public function processUserMessage($userId, $conversationId, $userMessage) {
        if (empty($userMessage)) {
            return [
                'error' => $_SESSION['language'] == 'ar' ?
                    'الرجاء إدخال رسالة' :
                    'Please enter a message'
            ];
        }
        
        if (mb_strlen($userMessage) > 1000) {
            return [
                'error' => $_SESSION['language'] == 'ar' ?
                    'الرسالة طويلة جداً. يرجى تقصيرها.' :
                    'Message is too long. Please shorten it.'
            ];
        }
        // Detect user intent
        $intent = Utils::detectIntent($userMessage);
        
        // Extract user info if any
        $userInfo = Utils::extractUserInfo($userMessage);
        if (!empty($userInfo)) {
            $this->updateUserInfo($userId, $userInfo);
        }
        
        // Enhance prompt based on intent
        $enhancedPrompt = Utils::enhancePrompt($userMessage, $intent, $userId);
        
        // Add user message to conversation history
        $_SESSION['conversation'][] = [
            'role' => 'user',
            'content' => $userMessage
        ];
        
        // Save message to database
        $this->saveMessage($conversationId, 'user', $userMessage, $intent);
        
        // Log the intent for analytics
        $this->logAnalyticsEvent($userId, $conversationId, 'intent_' . $intent);
        
        try {
            // Use offline mode if configured
            if ($this->config['chat']['offline_mode'] ?? false) {
                $aiResponse = FallbackResponses::getResponse($intent, $_SESSION['language']);
            } else {
                // Call Gemini API with error handling
                $response = $this->geminiAPI->generateResponse($enhancedPrompt, $_SESSION['conversation']);
                
                if (isset($response['error'])) {
                    Utils::logError($this->config['chat']['log_path'], "API Error Response: " . json_encode($response));
                    
                    // Get appropriate error response based on error type
                    $errorType = $response['error_type'] ?? 'general';
                    return [
                        'error' => FallbackResponses::getErrorResponse($errorType, $_SESSION['language']),
                        'error_type' => $errorType
                    ];
                } else {
                    try {
                        // Extract response from Gemini with detailed validation
                        if (isset($response['candidates']) && is_array($response['candidates']) && !empty($response['candidates'])) {
                            $candidate = $response['candidates'][0];
                            
                            if (isset($candidate['content']) && isset($candidate['content']['parts']) && 
                                is_array($candidate['content']['parts']) && !empty($candidate['content']['parts'])) {
                                
                                $aiResponse = $candidate['content']['parts'][0]['text'] ?? null;
                                
                                if ($aiResponse === null) {
                                    Utils::logError($this->config['chat']['log_path'], "Response missing text part: " . json_encode($candidate));
                                    $aiResponse = FallbackResponses::getResponse($intent, $_SESSION['language']);
                                }
                                
                                // If user language is not Arabic and the response contains Arabic, translate if needed
                                if ($_SESSION['language'] != 'ar' && preg_match('/[\x{0600}-\x{06FF}]/u', $aiResponse)) {
                                    try {
                                        $aiResponse = $this->geminiAPI->translateText($aiResponse, $_SESSION['language']);
                                    } catch (Exception $e) {
                                        Utils::logError($this->config['chat']['log_path'], "Translation error: " . $e->getMessage());
                                        // Continue with the untranslated response
                                    }
                                }
                            } else {
                                Utils::logError($this->config['chat']['log_path'], "Response format error: Missing content parts. " . json_encode($candidate));
                                $aiResponse = FallbackResponses::getResponse($intent, $_SESSION['language']);
                            }
                        } else {
                            Utils::logError($this->config['chat']['log_path'], "Response format error: No candidates found. " . json_encode($response));
                            $aiResponse = FallbackResponses::getResponse($intent, $_SESSION['language']);
                        }
                    } catch (Exception $e) {
                        Utils::logError($this->config['chat']['log_path'], "Exception while processing response: " . $e->getMessage() . "\n" . json_encode($response));
                        $aiResponse = FallbackResponses::getResponse($intent, $_SESSION['language']);
                    }
                }
            }
            
            // Add AI response to conversation history
            $_SESSION['conversation'][] = [
                'role' => 'model',
                'content' => $aiResponse
            ];
            
            // Save the AI response to database
            $this->saveMessage($conversationId, 'model', $aiResponse);
            
            // Generate product recommendations if intent is product-related
            if (in_array($intent, ['product_info', 'price_inquiry'])) {
                try {
                    $this->generateProductRecommendations($userId, $conversationId);
                } catch (Exception $e) {
                    Utils::logError($this->config['chat']['log_path'], "Error generating recommendations: " . $e->getMessage());
                    // Continue without recommendations
                }
            }
            
            // Limit conversation history length in memory
            $maxHistory = $this->config['chat']['max_history'] ?? 20;
            if (count($_SESSION['conversation']) > $maxHistory) {
                $_SESSION['conversation'] = array_slice($_SESSION['conversation'], -$maxHistory);
            }
        } catch (Exception $e) {
            Utils::logError($this->config['chat']['log_path'], "Fatal error in processUserMessage: " . $e->getMessage());
            return [
                'error' => FallbackResponses::getErrorResponse('general', $_SESSION['language']),
                'error_type' => 'general'
            ];
        }
        
        // Get updated recommendations
        try {
            $recommendations = $this->getProductRecommendations($userId);
        } catch (Exception $e) {
            Utils::logError($this->config['chat']['log_path'], "Error getting recommendations: " . $e->getMessage());
            $recommendations = [];
        }
        
        return [
            'response' => $aiResponse,
            'intent' => $intent,
            'recommendations' => $recommendations
        ];
    }
    
    // Get a fallback response in case of errors
    private function getFallbackResponse($language, $errorDetails = null) {
        if ($language == 'ar') {
            if ($errorDetails) {
                // If the error is rate limiting related
                if (stripos($errorDetails, 'rate') !== false || stripos($errorDetails, 'quota') !== false) {
                    return "عذرًا، لقد تجاوزنا الحد الأقصى لعدد الطلبات في الوقت الحالي. يرجى المحاولة مرة أخرى بعد قليل.";
                }
                // If it's a timeout issue
                if (stripos($errorDetails, 'timeout') !== false || stripos($errorDetails, 'timed out') !== false) {
                    return "عذرًا، استغرقت العملية وقتًا طويلاً. يرجى المحاولة مرة أخرى بصياغة أبسط.";
                }
            }
            return "عذرًا، حدث خطأ في معالجة طلبك. يرجى المحاولة مرة أخرى بصياغة مختلفة.";
        } else {
            if ($errorDetails) {
                // If the error is rate limiting related
                if (stripos($errorDetails, 'rate') !== false || stripos($errorDetails, 'quota') !== false) {
                    return "Sorry, we've exceeded the maximum number of requests at the moment. Please try again shortly.";
                }
                // If it's a timeout issue
                if (stripos($errorDetails, 'timeout') !== false || stripos($errorDetails, 'timed out') !== false) {
                    return "Sorry, the operation took too long. Please try again with a simpler query.";
                }
            }
            return "Sorry, there was an error processing your request. Please try again with a different wording.";
        }
    }
}
