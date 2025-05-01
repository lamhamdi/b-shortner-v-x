<?php
/**
 * API Test Script
 * 
 * This standalone script tests the Gemini API connection
 * and provides diagnostic information
 */

// Load configuration
$config = require_once __DIR__ . '/config/config.php';

// Function to log results
function logResult($message) {
    echo "$message<br>";
}

logResult("<h2>Gemini API Connection Test</h2>");
logResult("<p>Testing connection to Google Generative AI API...</p>");

// Test 1: Basic connectivity to Google
logResult("<h3>Test 1: Basic Internet Connectivity</h3>");
$ch = curl_init('https://www.google.com');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($response !== false) {
    logResult("<p style='color:green'>✓ Basic internet connectivity OK</p>");
} else {
    logResult("<p style='color:red'>✗ Basic internet connectivity FAILED: $error</p>");
    logResult("<p>Possible solutions:</p><ul>
        <li>Check your internet connection</li>
        <li>Verify firewall settings</li>
        <li>Check for proxy configurations</li>
    </ul>");
    die();
}

// Test 2: API endpoint accessibility
logResult("<h3>Test 2: API Endpoint Accessibility</h3>");
$ch = curl_init('https://generativelanguage.googleapis.com/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($response !== false) {
    logResult("<p style='color:green'>✓ API endpoint is accessible</p>");
} else {
    logResult("<p style='color:red'>✗ API endpoint is NOT accessible: $error</p>");
    logResult("<p>Possible solutions:</p><ul>
        <li>Check if your server can access Google's API endpoints</li>
        <li>Verify firewall or proxy settings</li>
        <li>Your hosting provider might be blocking outgoing connections to this endpoint</li>
    </ul>");
}

// Test 3: API key validation
logResult("<h3>Test 3: API Key Validation</h3>");
$apiKey = $config['gemini']['api_key'];
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=$apiKey";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    logResult("<p style='color:red'>✗ API key test FAILED: $error</p>");
} else {
    if ($httpCode == 200) {
        logResult("<p style='color:green'>✓ API key is valid</p>");
        
        // Show available models
        $data = json_decode($response, true);
        if (isset($data['models']) && is_array($data['models'])) {
            logResult("<p>Available models:</p><ul>");
            foreach ($data['models'] as $model) {
                logResult("<li>{$model['name']}</li>");
            }
            logResult("</ul>");
            
            // Check if our configured model exists
            $ourModel = $config['gemini']['model'];
            $modelFound = false;
            foreach ($data['models'] as $model) {
                if (strpos($model['name'], $ourModel) !== false) {
                    $modelFound = true;
                    break;
                }
            }
            
            if ($modelFound) {
                logResult("<p style='color:green'>✓ Your configured model '$ourModel' exists</p>");
            } else {
                logResult("<p style='color:red'>✗ Your configured model '$ourModel' was NOT found</p>");
                logResult("<p>Please update your configuration to use one of the models listed above</p>");
            }
        }
    } else {
        logResult("<p style='color:red'>✗ API key is NOT valid (HTTP $httpCode)</p>");
        
        $data = json_decode($response, true);
        if (isset($data['error']['message'])) {
            logResult("<p>Error message: {$data['error']['message']}</p>");
        } else {
            logResult("<p>Response: $response</p>");
        }
        
        logResult("<p>Possible solutions:</p><ul>
            <li>Check if your API key is correct in the configuration</li>
            <li>Verify that your API key has been activated</li>
            <li>Make sure you have enabled the Gemini API in your Google Cloud project</li>
        </ul>");
    }
}

// Test 4: Simple API request
logResult("<h3>Test 4: Simple API Request</h3>");
$model = $config['gemini']['model'];
$url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey";

$data = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                [
                    'text' => 'Say hello in 10 words or less'
                ]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.5,
        'maxOutputTokens' => 100
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    logResult("<p style='color:red'>✗ Test API request FAILED: $error</p>");
} else {
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $responseText = $data['candidates'][0]['content']['parts'][0]['text'];
            logResult("<p style='color:green'>✓ API request successful!</p>");
            logResult("<p>Response: <strong>$responseText</strong></p>");
        } else {
            logResult("<p style='color:orange'>⚠ API request returned unexpected format</p>");
            logResult("<p>Response structure: " . json_encode($data) . "</p>");
        }
    } else {
        logResult("<p style='color:red'>✗ API request FAILED with HTTP code $httpCode</p>");
        
        $data = json_decode($response, true);
        if (isset($data['error']['message'])) {
            $errorMsg = $data['error']['message'];
            logResult("<p>Error message: $errorMsg</p>");
            
            // Try to give helpful suggestions
            if (strpos($errorMsg, 'not found') !== false) {
                logResult("<p>It looks like the model name '$model' is incorrect or not available.</p>");
                logResult("<p>Try changing your model in config to 'gemini-pro'</p>");
            } elseif (strpos($errorMsg, 'permission denied') !== false) {
                logResult("<p>Your API key doesn't have permission to use this model.</p>");
            }
        } else {
            logResult("<p>Response: $response</p>");
        }
    }
}

logResult("<h3>Conclusion</h3>");
if ($httpCode == 200) {
    logResult("<p style='color:green; font-weight:bold'>All tests passed! Your API connection is working correctly.</p>");
} else {
    logResult("<p style='color:red; font-weight:bold'>Some tests failed. Please fix the issues above.</p>");
}
?>

<style>
    body {
        font-family: Arial, sans-serif;
        line-height: 1.6;
        max-width: 800px;
        margin: 0 auto;
        padding: 20px;
    }
    h2 {
        color: #2c3e50;
    }
    h3 {
        color: #3498db;
        margin-top: 20px;
    }
    p {
        margin: 10px 0;
    }
    ul {
        margin-left: 25px;
    }
</style>
