<?php
/**
 * FallbackResponses - Provides pre-defined responses when API is unavailable
 */
class FallbackResponses {
    /**
     * Get a response for a specific intent in the specified language
     * 
     * @param string $intent The detected user intent
     * @param string $language The user's language code
     * @return string A pre-defined response
     */
    public static function getResponse($intent, $language = 'ar') {
        $responses = self::getResponsesByLanguage($language);
        
        if (isset($responses[$intent])) {
            $options = $responses[$intent];
            return $options[array_rand($options)];
        }
        
        // Return a general response if intent not found
        return $responses['general'][array_rand($responses['general'])];
    }
    
    /**
     * Get an error response for a specific error type
     * 
     * @param string $errorType The type of error
     * @param string $language The user's language code
     * @return string An appropriate error message
     */
    public static function getErrorResponse($errorType, $language = 'ar') {
        $responses = self::getErrorResponsesByLanguage($language);
        
        if (isset($responses[$errorType])) {
            return $responses[$errorType];
        }
        
        // Return a general error message if type not found
        return $responses['general'];
    }
    
    /**
     * Get all responses for a specific language
     */
    private static function getResponsesByLanguage($language) {
        switch ($language) {
            case 'en':
                return [
                    'greeting' => [
                        'Hello! How can I help you today?',
                        'Hi there! I\'m here to assist you with our products.',
                        'Welcome! What kind of products are you looking for?'
                    ],
                    'product_info' => [
                        'We have a wide range of products. Could you please specify what you\'re looking for?',
                        'I\'d be happy to help you find the right product. What are you interested in?',
                        'Our store offers various quality products. What category are you interested in?'
                    ],
                    'price_inquiry' => [
                        'Our products are competitively priced. Could you specify which product you\'re interested in?',
                        'We offer products at various price points. What\'s your budget range?',
                        'I can help you find products within your budget. What are you looking for?'
                    ],
                    'general' => [
                        'I\'m here to help you with anything you need. What can I assist you with?',
                        'How can I assist you today?',
                        'I\'m your shopping assistant. How can I help you?'
                    ]
                ];
                
            case 'fr':
                return [
                    'greeting' => [
                        'Bonjour! Comment puis-je vous aider aujourd\'hui?',
                        'Salut! Je suis là pour vous aider avec nos produits.',
                        'Bienvenue! Quel type de produits recherchez-vous?'
                    ],
                    'general' => [
                        'Je suis là pour vous aider. Que puis-je faire pour vous?',
                        'Comment puis-je vous aider aujourd\'hui?',
                        'Je suis votre assistant shopping. Comment puis-je vous aider?'
                    ]
                ];
                
            case 'es':
                return [
                    'greeting' => [
                        '¡Hola! ¿Cómo puedo ayudarte hoy?',
                        '¡Hola! Estoy aquí para ayudarte con nuestros productos.',
                        '¡Bienvenido! ¿Qué tipo de productos estás buscando?'
                    ],
                    'general' => [
                        'Estoy aquí para ayudarte. ¿Qué puedo hacer por ti?',
                        '¿Cómo puedo ayudarte hoy?',
                        'Soy tu asistente de compras. ¿Cómo puedo ayudarte?'
                    ]
                ];
                
            case 'ar':
            default:
                return [
                    'greeting' => [
                        'مرحباً! كيف يمكنني مساعدتك اليوم؟',
                        'أهلاً! أنا هنا لمساعدتك في اختيار منتجاتنا.',
                        'أهلاً وسهلاً! ما نوع المنتجات التي تبحث عنها؟'
                    ],
                    'product_info' => [
                        'لدينا مجموعة واسعة من المنتجات. هل يمكنك تحديد ما الذي تبحث عنه؟',
                        'يسعدني مساعدتك في العثور على المنتج المناسب. ما الذي تهتم به؟',
                        'يقدم متجرنا منتجات متنوعة عالية الجودة. ما الفئة التي تهتم بها؟'
                    ],
                    'price_inquiry' => [
                        'أسعار منتجاتنا تنافسية. هل يمكنك تحديد المنتج الذي تهتم به؟',
                        'نقدم منتجات بنقاط أسعار مختلفة. ما هو نطاق ميزانيتك؟',
                        'يمكنني مساعدتك في العثور على منتجات ضمن ميزانيتك. ما الذي تبحث عنه؟'
                    ],
                    'general' => [
                        'أنا هنا لمساعدتك في أي شيء تحتاجه. كيف يمكنني مساعدتك؟',
                        'كيف يمكنني مساعدتك اليوم؟',
                        'أنا مساعدك للتسوق. كيف يمكنني مساعدتك؟'
                    ]
                ];
        }
    }
    
    /**
     * Get error responses for a specific language
     */
    private static function getErrorResponsesByLanguage($language) {
        switch ($language) {
            case 'en':
                return [
                    'timeout' => 'Sorry, the request timed out. Please try again with a simpler message.',
                    'api_unavailable' => 'Sorry, our service is temporarily unavailable. I\'ll do my best to assist you with basic information.',
                    'rate_limit' => 'You\'ve sent too many messages in a short time. Please wait a moment and try again.',
                    'general' => 'Sorry, an error occurred. Please try again.'
                ];
                
            case 'fr':
                return [
                    'timeout' => 'Désolé, la requête a expiré. Veuillez réessayer avec un message plus simple.',
                    'api_unavailable' => 'Désolé, notre service est temporairement indisponible. Je ferai de mon mieux pour vous aider avec des informations de base.',
                    'general' => 'Désolé, une erreur s\'est produite. Veuillez réessayer.'
                ];
                
            case 'es':
                return [
                    'timeout' => 'Lo siento, la solicitud ha expirado. Inténtelo de nuevo con un mensaje más simple.',
                    'api_unavailable' => 'Lo siento, nuestro servicio no está disponible temporalmente. Haré lo posible para ayudarte con información básica.',
                    'general' => 'Lo siento, se ha producido un error. Inténtelo de nuevo.'
                ];
                
            case 'ar':
            default:
                return [
                    'timeout' => 'عذرًا، انتهت مهلة الطلب. يرجى المحاولة مرة أخرى برسالة أبسط.',
                    'api_unavailable' => 'عذرًا، خدمتنا غير متوفرة مؤقتًا. سأبذل قصارى جهدي لمساعدتك بالمعلومات الأساسية.',
                    'rate_limit' => 'لقد أرسلت الكثير من الرسائل في وقت قصير. يرجى الانتظار لحظة والمحاولة مرة أخرى.',
                    'general' => 'عذرًا، حدث خطأ في معالجة طلبك. يرجى المحاولة مرة أخرى.'
                ];
        }
    }
}
