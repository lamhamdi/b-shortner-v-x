<?php
// Session management for user persistence
session_start();

// Load configuration
$config = require_once __DIR__ . '/config/config.php';

// Include required classes
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/ChatService.php';
require_once __DIR__ . '/includes/GeminiAPI.php';
require_once __DIR__ . '/includes/ProductService.php';
require_once __DIR__ . '/includes/Utils.php';

// Initialize database and tables
try {
    $db = Database::getInstance($config);
    $db->initializeTables();
    Utils::logDebug($config['chat']['log_path'], "Database tables initialized successfully");
} catch (Exception $e) {
    Utils::logError($config['chat']['log_path'], "Database initialization error: " . $e->getMessage());
    die("Error initializing database. Please try again later.");
}

// Initialize services
$chatService = new ChatService($config);
$productService = new ProductService();

// Set language from URL parameter or use default
if (isset($_GET['lang']) && in_array($_GET['lang'], $config['chat']['supported_languages'])) {
    $_SESSION['language'] = $_GET['lang'];
} elseif (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $config['chat']['default_language'];
}

// Get or create user and conversation
$userId = $chatService->getOrCreateUser();
$conversationId = $chatService->getOrCreateConversation($userId);

// Load conversation history
if (empty($_SESSION['conversation'])) {
    $_SESSION['conversation'] = $chatService->loadConversationHistory($conversationId);
}

// Process user message
$aiResponse = '';
$intent = 'general';
$recommendations = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    try {
        $userMessage = trim(htmlspecialchars($_POST['message']));
        
        if (!empty($userMessage)) {
            $result = $chatService->processUserMessage($userId, $conversationId, $userMessage);
            
            if (isset($result['error'])) {
                throw new Exception($result['error']);
            }
            
            $aiResponse = $result['response'];
            $intent = $result['intent'] ?? 'general';
            $recommendations = $result['recommendations'] ?? [];
            
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                
                header('Content-Type: application/json');
                echo json_encode($result);
                exit;
            }
        }
    } catch (Exception $e) {
        $errorMessage = ($_SESSION['language'] == 'ar') ?
            'عذراً، حدث خطأ في معالجة طلبك. يرجى المحاولة مرة أخرى.' :
            'Sorry, there was an error processing your request. Please try again.';
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            
            header('Content-Type: application/json');
            echo json_encode(['error' => $errorMessage]);
            exit;
        }
        
        $aiResponse = $errorMessage;
    }
}

// Get user recommendations for display
$recommendations = $chatService->getProductRecommendations($userId);
?>

<!DOCTYPE html>
<html lang="<?php echo $_SESSION['language']; ?>" dir="<?php echo ($_SESSION['language'] == 'ar') ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($_SESSION['language'] == 'ar') ? 'نظام الدردشة الذكي - ' . $config['site']['name'] : 'Intelligent Chat System - ' . $config['site']['name']; ?></title>
    <link rel="stylesheet" href="assets/css/chat.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo ($_SESSION['language'] == 'ar') ? 'نظام الدردشة الذكي' : 'Intelligent Chat System'; ?></h1>
            <p><?php echo ($_SESSION['language'] == 'ar') ? 'مدعوم بواسطة Google Gemini API' : 'Powered by Google Gemini API'; ?></p>
        </div>
        
        <div class="content-area">
            <div class="chat-container">
                <div class="chat-messages" id="chatMessages">
                    <?php
                    // Display chat history
                    if (!empty($_SESSION['conversation'])) {
                        foreach ($_SESSION['conversation'] as $message) {
                            $role = $message['role'];
                            $content = $message['content'];
                            $className = ($role === 'user') ? 'user-message' : 'ai-message';
                            echo "<div class='message $className'>";
                            
                            // Detect if the message contains product HTML
                            if (strpos($content, '<div class="product-card"') !== false || 
                                strpos($content, '<div class="product-recommendations"') !== false) {
                                // For messages containing product recommendations, output as HTML
                                echo '<div class="message-content">' . $content . '</div>';
                            } else {
                                // For regular text messages, escape HTML and add line breaks
                                echo '<div class="message-content">' . nl2br(htmlspecialchars($content)) . '</div>';
                            }
                            
                            echo "<div class='message-time'>" . date('h:i A') . "</div>";
                            echo "</div>";
                        }
                    } else {
                        // Initial message that introduces the assistant based on language
                        $initialMessage = '';
                        
                        if ($_SESSION['language'] == 'ar') {
                            $initialMessage = "مرحبًا! أنا مساعد التسوق الذكي الخاص بـ {$config['site']['name']}. يمكنني مساعدتك في:
                            • العثور على المنتجات المناسبة
                            • الإجابة على استفساراتك حول منتجاتنا
                            • تقديم توصيات مخصصة لك
                            • معلومات عن الأسعار والعروض
                            
                            كيف يمكنني مساعدتك اليوم؟";
                        } else {
                            $initialMessage = "Hello! I'm the Smart Shopping Assistant for {$config['site']['name']}. I can help you with:
                            • Finding suitable products
                            • Answering your questions about our products
                            • Providing personalized recommendations
                            • Information about prices and offers
                            
                            How can I assist you today?";
                        }
                        
                        echo "<div class='message ai-message'>";
                        echo '<div class="message-content">' . nl2br(htmlspecialchars($initialMessage)) . '</div>';
                        echo "<div class='message-time'>" . date('h:i A') . "</div>";
                        echo "</div>";
                        
                        // Save this initial message to the database
                        $chatService->saveMessage($conversationId, 'model', $initialMessage);
                    }
                    ?>
                    <div class="typing-indicator" id="typingIndicator">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </div>
                
                <div class="chat-input-container">
                    <form class="chat-form" id="chatForm">
                        <input type="text" class="chat-input" id="messageInput" 
                               placeholder="<?php echo ($_SESSION['language'] == 'ar') ? 'اكتب رسالتك هنا...' : 'Type your message here...'; ?>" 
                               autocomplete="off" required>
                        <button type="submit" class="send-button">
                            <?php echo ($_SESSION['language'] == 'ar') ? 'إرسال' : 'Send'; ?>
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="sidebar">
                <div class="recommendations">
                    <h3><?php echo ($_SESSION['language'] == 'ar') ? 'توصيات لك' : 'Recommendations for you'; ?></h3>
                    <div id="recommendationsContainer">
                        <?php if (empty($recommendations)): ?>
                            <p><?php echo ($_SESSION['language'] == 'ar') ? 'لا توجد توصيات حتى الآن' : 'No recommendations yet'; ?></p>
                        <?php else: ?>
                            <?php foreach ($recommendations as $product): ?>
                                <div class="recommendation-item" data-product-id="<?php echo $product['id']; ?>">
                                    <div class="recommendation-thumb">
                                        <img src="<?php echo $config['site']['url'] . '/' . (!empty($product['images'][0]['image_url']) ? $product['images'][0]['image_url'] : 'images/no-image.jpg'); ?>" 
                                             alt="<?php echo htmlspecialchars($_SESSION['language'] == 'ar' && !empty($product['name_ar']) ? $product['name_ar'] : $product['name']); ?>">
                                    </div>
                                    <div class="recommendation-info">
                                        <h4><?php echo htmlspecialchars($_SESSION['language'] == 'ar' && !empty($product['name_ar']) ? $product['name_ar'] : $product['name']); ?></h4>
                                        <p><?php echo ($_SESSION['language'] == 'ar') ? 'انقر للاستفسار' : 'Click to inquire'; ?></p>
                                        <div class="recommendation-price">
                                            <?php echo number_format($product['price'], 2) . ' ' . $config['site']['currency']; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="language-selector">
                    <h3><?php echo ($_SESSION['language'] == 'ar') ? 'اختر اللغة' : 'Select Language'; ?></h3>
                    <div class="language-options">
                        <div class="language-option <?php echo ($_SESSION['language'] == 'ar') ? 'active' : ''; ?>" data-lang="ar">العربية</div>
                        <div class="language-option <?php echo ($_SESSION['language'] == 'en') ? 'active' : ''; ?>" data-lang="en">English</div>
                        <div class="language-option <?php echo ($_SESSION['language'] == 'fr') ? 'active' : ''; ?>" data-lang="fr">Français</div>
                        <div class="language-option <?php echo ($_SESSION['language'] == 'es') ? 'active' : ''; ?>" data-lang="es">Español</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/js/chat.js"></script>
</body>
</html>
