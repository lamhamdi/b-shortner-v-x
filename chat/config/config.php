<?php
// Include the main site configuration
require_once __DIR__ . '/../../includes/config.php';

// Configuration settings for the chat system
return [
    'gemini' => [
        'api_key' => 'API KEY',
        'model' => 'gemini-1.5-pro-latest',
        'fallback_model' => 'gemini-1.5-pro-latest', // Fallback model if primary fails
        'max_tokens' => 1024,
        'temperature' => 0.7,
        'api_version' => 'v1beta',
        'timeout' => 30, // Request timeout in seconds
        'retries' => 2, // Number of retries for failed requests
        'retry_delay' => 1, // Delay between retries in seconds
        'debug' => true // Enable detailed debug logging
    ],
    'db' => [
        'host' => DB_HOST,
        'user' => DB_USER,
        'pass' => DB_PASS,
        'name' => DB_NAME
    ],
    'chat' => [
        'log_path' => __DIR__ . '/../logs/',
        'default_language' => 'ar',
        'supported_languages' => ['ar', 'en', 'fr', 'es'],
        'max_history' => 20, // Maximum number of messages to keep in history
        'error_responses' => [
            'ar' => 'عذرًا، حدث خطأ في معالجة طلبك. يرجى المحاولة مرة أخرى.',
            'en' => 'Sorry, an error occurred processing your request. Please try again.',
            'fr' => 'Désolé, une erreur s\'est produite lors du traitement de votre demande. Veuillez réessayer.',
            'es' => 'Lo sentimos, se produjo un error al procesar su solicitud. Por favor, inténtelo de nuevo.'
        ],
        'offline_mode' => false, // Enable to use local responses when API is down
        'rate_limit' => [
            'enabled' => true,
            'requests_per_minute' => 10,
            'requests_per_day' => 1000
        ]
    ],
    'site' => [
        'url' => SITE_URL,
        'name' => SITE_NAME,
        'currency' => DEFAULT_CURRENCY
    ],
    'product_recommendations' => [
        'enabled' => true,
        'max_items' => 5,
        'min_score' => 0.3,
        'default_categories' => ['featured', 'new_arrivals']
    ]
];
