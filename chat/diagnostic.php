<?php
/**
 * API Connection Diagnostic Tool
 * 
 * This script tests connectivity to the Google Generative AI API
 * and identifies common issues that might be affecting your chat application.
 */

// Load configuration
$config = require_once __DIR__ . '/config/config.php';

// Create log directory if it doesn't exist
$logPath = $config['chat']['log_path'];
if (!is_dir($logPath)) {
    mkdir($logPath, 0755, true);
}

// Log function for this diagnostic
function logDiagnostic($message) {
    global $logPath;
    $logFile = $logPath . 'diagnostic_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "$message<br>";
}

logDiagnostic("Starting API connection diagnostic test");

// Check PHP version
logDiagnostic("PHP Version: " . phpversion());
if (version_compare(PHP_VERSION, '7.4.0') < 0) {
    logDiagnostic("WARNING: PHP version 7.4 or higher recommended. Current version may have issues with the API.");
}

// Check required extensions
$requiredExtensions = ['curl', 'json', 'mbstring', 'mysqli'];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        logDiagnostic("ERROR: Required PHP extension '$ext' is not loaded");
    } else {
        logDiagnostic("Extension check: $ext is loaded");
    }
}

// Test Internet connectivity
logDiagnostic("Testing general internet connectivity...");
$testUrls = [
    'https://www.google.com',
    'https://generativelanguage.googleapis.com',
    'https://www.googleapis.com'
];

foreach ($testUrls as $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($response === false) {
        logDiagnostic("ERROR: Failed to connect to $url - " . $error);
    } else {
        logDiagnostic("Successfully connected to $url (HTTP $httpCode)");
    }
    
    curl_close($ch);
}

// Test API connectivity with the actual key
logDiagnostic("Testing API connectivity with your API key...");
$apiKey = $config['gemini']['api_key'];
$testUrl = "https://generativelanguage.googleapis.com/v1beta/models?key=$apiKey";

$ch = curl_init($testUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

if ($response === false) {
    logDiagnostic("ERROR: API connection failed - " . $error);
} else {
    logDiagnostic("API responded with HTTP code $httpCode");
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            logDiagnostic("API key is valid. Available models:");
            if (isset($data['models']) && is_array($data['models'])) {
                foreach ($data['models'] as $model) {
                    logDiagnostic("- " . $model['name']);
                }
                
                // Check if our configured model exists
                $ourModel = $config['gemini']['model'];
                $modelFound = false;
                foreach ($data['models'] as $model) {
                    if (strpos($model['name'], $ourModel) !== false) {
                        $modelFound = true;
                        break;
                    }
                }
                
                if (!$modelFound) {
                    logDiagnostic("ERROR: Your configured model '$ourModel' was not found in the list of available models");
                    logDiagnostic("Please update your configuration to use one of the models listed above");
                }
            } else {
                logDiagnostic("WARNING: No models found in API response");
            }
        } else {
            logDiagnostic("WARNING: Could not parse JSON response from API");
        }
    } else {
        logDiagnostic("ERROR: API returned non-200 status code. Your API key may be invalid.");
        logDiagnostic("Response: " . $response);
    }
}

curl_close($ch);

// Try to send a simple message to the API
logDiagnostic("Testing API with a simple chat request...");
$model = $config['gemini']['model'];
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey";

$data = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                [
                    'text' => 'Say hello in 5 words or less'
                ]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.5,
        'maxOutputTokens' => 100
    ]
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

if ($response === false) {
    logDiagnostic("ERROR: Test chat request failed - " . $error);
} else {
    logDiagnostic("Test chat API responded with HTTP code $httpCode");
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $responseText = $data['candidates'][0]['content']['parts'][0]['text'];
                logDiagnostic("Success! API response: " . $responseText);
                logDiagnostic("Your API setup is working correctly!");
            } else {
                logDiagnostic("WARNING: Unexpected response structure from API");
                logDiagnostic("Response: " . json_encode($data));
            }
        } else {
            logDiagnostic("WARNING: Could not parse JSON response from API");
        }
    } else {
        logDiagnostic("ERROR: API returned non-200 status code for test chat");
        logDiagnostic("Response: " . $response);
        
        // Attempt to extract and display the error message
        $responseData = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($responseData['error']['message'])) {
            logDiagnostic("Error message: " . $responseData['error']['message']);
            
            // Check for common errors and provide guidance
            $errorMsg = $responseData['error']['message'];
            
            if (strpos($errorMsg, 'API key not valid') !== false) {
                logDiagnostic("SOLUTION: Your API key appears to be invalid. Please check your key in the config file.");
            } 
            else if (strpos($errorMsg, 'not found') !== false) {
                logDiagnostic("SOLUTION: The model specified doesn't exist or isn't available. Try using 'gemini-pro' instead of '$model'.");
            }
            else if (strpos($errorMsg, 'quota') !== false) {
                logDiagnostic("SOLUTION: You've exceeded your API quota. Either wait until your quota resets or upgrade your API plan.");
            }
        }
    }
}

curl_close($ch);

// Check database connection
logDiagnostic("Testing database connection...");
try {
    $conn = new mysqli(
        $config['db']['host'],
        $config['db']['user'],
        $config['db']['pass'],
        $config['db']['name']
    );
    
    if ($conn->connect_error) {
        logDiagnostic("ERROR: Database connection failed - " . $conn->connect_error);
    } else {
        logDiagnostic("Database connection successful");
        
        // Check if required tables exist
        $requiredTables = ['chat_users', 'chat_conversations', 'chat_messages', 'chat_analytics', 'chat_product_recommendations'];
        $tablesExist = true;
        
        foreach ($requiredTables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            if ($result->num_rows == 0) {
                logDiagnostic("ERROR: Required table '$table' does not exist");
                $tablesExist = false;
            }
        }
        
        if ($tablesExist) {
            logDiagnostic("All required database tables exist");
        } else {
            logDiagnostic("SOLUTION: Run the application once with ?init=true appended to the URL to create missing tables");
        }
        
        $conn->close();
    }
} catch (Exception $e) {
    logDiagnostic("ERROR: Database exception - " . $e->getMessage());
}

logDiagnostic("Diagnostic complete. Please check the results above for any errors.");
?>

<style>
    body { 
        font-family: monospace; 
        line-height: 1.5; 
        max-width: 1000px; 
        margin: 20px auto; 
        padding: 20px; 
        background: #f5f5f5; 
    }
    br + br { display: none; }
</style>
