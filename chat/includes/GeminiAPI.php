<?php
require_once __DIR__ . '/Utils.php';
require_once __DIR__ . '/FallbackResponses.php';

class GeminiAPI {
    private $config;
    private $logPath;
    private $retriesLeft;
    private $offlineMode = false;
    
    public function __construct($config, $logPath = null) {
        $this->config = $config;
        $this->logPath = $logPath ?? dirname(__DIR__) . '/logs/';
        $this->retriesLeft = $config['retries'] ?? 0;
        
        // Create log directory if it doesn't exist
        // Create log directory if it doesn't exist
        if (!is_dir($this->logPath)) {
            if (!mkdir($this->logPath, 0755, true)) {
                throw new Exception("Failed to create log directory: " . $this->logPath);
            }
        }
        
        // Ensure log directory is writable
        if (!is_writable($this->logPath)) {
            throw new Exception("Log directory is not writable: " . $this->logPath);
        }
        
        // Check API connectivity on initialization
        $this->checkApiConnectivity();
    }
    
    // Check if API is reachable
    private function checkApiConnectivity() {
        Utils::logDebug($this->logPath, "Checking API connectivity...");
        
        $testUrl = "https://generativelanguage.googleapis.com/v1beta/models?key={$this->config['api_key']}";
        $ch = curl_init($testUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || $httpCode != 200) {
            Utils::logError($this->logPath, "API connectivity check failed: " . ($error ?: "HTTP Code $httpCode"));
            Utils::logError($this->logPath, "Switching to offline mode");
            $this->offlineMode = true;
        } else {
            Utils::logDebug($this->logPath, "API connectivity check successful");
            
            // Verify model availability
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['models'])) {
                $modelFound = false;
                foreach ($data['models'] as $model) {
                    if (strpos($model['name'], $this->config['model']) !== false) {
                        $modelFound = true;
                        break;
                    }
                }
                
                if (!$modelFound) {
                    Utils::logError($this->logPath, "Model {$this->config['model']} not found, checking for alternatives");
                    
                    // Try to find an alternative model
                    foreach ($data['models'] as $model) {
                        if (strpos($model['name'], 'gemini-pro') !== false) {
                            Utils::logError($this->logPath, "Switching to alternative model: gemini-pro");
                            $this->config['model'] = 'gemini-pro';
                            $modelFound = true;
                            break;
                        }
                    }
                    
                    if (!$modelFound) {
                        Utils::logError($this->logPath, "No suitable alternative models found, switching to offline mode");
                        $this->offlineMode = true;
                    }
                }
            }
        }
    }
    
    // Generate response from Gemini API with retry logic
    public function generateResponse($prompt, $conversationHistory = []) {
        // Log the request attempt
        Utils::logDebug($this->logPath, "Generating response for prompt: " . substr($prompt, 0, 100) . "...");
        
        // If in offline mode, use fallback responses
        if ($this->offlineMode) {
            Utils::logDebug($this->logPath, "Using offline mode response");
            $intent = Utils::detectIntent($prompt);
            $language = $_SESSION['language'] ?? $this->config['default_language'] ?? 'ar';
            
            return [
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => FallbackResponses::getResponse($intent, $language)
                                ]
                            ]
                        ]
                    ]
                ],
                'offline_mode' => true
            ];
        }
        
        // Construct the API URL with proper model name
        $url = sprintf(
            "https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s",
            trim($this->config['model']),
            trim($this->config['api_key'])
        );
        
        // Log the API URL for debugging
        Utils::logDebug($this->logPath, "API URL: $url");
        
        // Create a simple content request for history management
        $contents = [];
        
        // Add conversation history if available
        if (!empty($conversationHistory)) {
            foreach ($conversationHistory as $message) {
                $role = $message['role'];
                $content = $message['content'];
                
                // For Gemini API, we need to map roles correctly
                $apiRole = ($role === 'user') ? 'user' : 'model';
                
                $contents[] = [
                    'role' => $apiRole,
                    'parts' => [
                        ['text' => $content]
                    ]
                ];
            }
        }
        
        // Add the current prompt if not already included in history
        if (empty($conversationHistory) || end($conversationHistory)['content'] !== $prompt) {
            $contents[] = [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt]
                ]
            ];
        }
        
        $data = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $this->config['temperature'],
                'maxTokens' => $this->config['max_tokens'],
                'topP' => 0.8,
                'topK' => 40
            ]
        ];
        
        try {
            // Attempt API request
            $response = $this->makeApiRequest($url, $data, $prompt);
            
            // If there's an error and we have retries left, try with the fallback model
            if (isset($response['error']) && $this->retriesLeft > 0 && isset($this->config['fallback_model'])) {
                $this->retriesLeft--;
                
                Utils::logError($this->logPath, "Primary model request failed. Retrying with fallback model: {$this->config['fallback_model']}");
                
                // Update URL to use fallback model
                $fallbackUrl = "https://generativelanguage.googleapis.com/{$this->config['api_version']}/models/{$this->config['fallback_model']}:generateContent?key={$this->config['api_key']}";
                
                // Wait before retry
                if (isset($this->config['retry_delay']) && $this->config['retry_delay'] > 0) {
                    sleep($this->config['retry_delay']);
                }
                
                // Try again with fallback model
                return $this->makeApiRequest($fallbackUrl, $data, $prompt);
            }
            
            return $response;
        } catch (Exception $e) {
            Utils::logError($this->logPath, "Exception in generateResponse: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    // Translate text using Gemini API
    public function translateText($text, $targetLanguage) {
        $prompt = "Translate the following text to $targetLanguage. Return only the translation without any additional text: \"$text\"";
        
        $response = $this->generateResponse($prompt, []);
        
        if (isset($response['error'])) {
            Utils::logError($this->logPath, "Translation error: " . $response['error']);
            return $text; // Return original text if translation fails
        }
        
        try {
            if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
                $translatedText = $response['candidates'][0]['content']['parts'][0]['text'];
                // Clean up the response (remove quotes if present)
                return trim($translatedText, " \t\n\r\0\x0B\"'");
            }
        } catch (Exception $e) {
            Utils::logError($this->logPath, "Translation parsing error: " . $e->getMessage());
        }
        
        return $text;
    }
    
    // Make API request to Gemini with improved error handling
    private function makeApiRequest($url, $data, $prompt) {
        // Add request counter to prevent rate limit issues
        static $requestCount = 0;
        static $requestTime = 0;
        
        // Simple rate limiting
        if ($requestCount > 0) {
            $timeSinceLastRequest = microtime(true) - $requestTime;
            // Add a delay if requests are happening too quickly
            if ($timeSinceLastRequest < 0.5) {
                $delay = 0.5 - $timeSinceLastRequest;
                Utils::logDebug($this->logPath, "Rate limiting: Adding delay of {$delay}s");
                usleep($delay * 1000000); // Convert to microseconds
            }
        }
        
        $requestCount++;
        $requestTime = microtime(true);
        
        // Set up cURL for more control over the request
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            Utils::logError($this->logPath, "JSON encode error: " . json_last_error_msg());
            return ['error' => 'Failed to encode request data', 'error_type' => 'invalid_request'];
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        
        // Set timeout from config
        $timeout = isset($this->config['timeout']) ? (int)$this->config['timeout'] : 30;
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout / 2));
        
        // Add more detailed error handling
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, $this->config['debug'] ?? false);
        
        // Create a file handle for the verbose information if debug is enabled
        if ($this->config['debug'] ?? false) {
            $verbose = fopen($this->logPath . 'curl_verbose_' . date('Y-m-d_H-i-s') . '.log', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
        }
        
        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        
        // Get more detailed info about the request
        $info = curl_getinfo($ch);
        
        curl_close($ch);
        
        // Close verbose log if it was opened
        if (isset($verbose) && is_resource($verbose)) {
            fclose($verbose);
        }
        
        // Log the request and response for debugging with more details
        $this->logDetailedRequest($prompt, $data, $response, $httpCode, $info, $curlError, $curlErrno);
        
        // Handle errors
        if ($curlError) {
            Utils::logError($this->logPath, "cURL Error ($curlErrno): $curlError");
            
            // Switch to offline mode for persistent network issues
            if (in_array($curlErrno, [
                CURLE_COULDNT_RESOLVE_HOST,
                CURLE_COULDNT_CONNECT,
                CURLE_OPERATION_TIMEOUTED,
                CURLE_SSL_CONNECT_ERROR
            ])) {
                Utils::logError($this->logPath, "Network connectivity issue detected, switching to offline mode");
                $this->offlineMode = true;
                
                // Return a fallback response
                $intent = Utils::detectIntent($prompt);
                $language = $_SESSION['language'] ?? $this->config['default_language'] ?? 'ar';
                
                return [
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'text' => FallbackResponses::getErrorResponse('api_unavailable', $language)
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'offline_mode' => true
                ];
            }
            
            // Provide specific error type
            $errorType = 'general';
            if ($curlErrno == CURLE_OPERATION_TIMEDOUT) {
                $errorType = 'timeout';
            } elseif (in_array($curlErrno, [CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST])) {
                $errorType = 'api_unavailable';
            }
            
            return [
                'error' => "Connection error ($curlErrno): $curlError",
                'error_type' => $errorType
            ];
        }
        
        if ($httpCode == 404) {
            Utils::logError($this->logPath, "Model not found error, trying fallback model");
            
            // Auto-switch to a fallback model if available
            if (!empty($this->config['fallback_model']) && $this->config['model'] != $this->config['fallback_model']) {
                $this->config['model'] = $this->config['fallback_model'];
                $fallbackUrl = "https://generativelanguage.googleapis.com/{$this->config['api_version']}/models/{$this->config['model']}:generateContent?key={$this->config['api_key']}";
                
                Utils::logDebug($this->logPath, "Retrying with fallback model: {$this->config['model']}");
                return $this->makeApiRequest($fallbackUrl, $data, $prompt);
            }
        }
        
        if ($httpCode != 200) {
            Utils::logError($this->logPath, "API Error: HTTP Code $httpCode, Response: $response");
            
            // Try to extract more meaningful error message from the response
            $errorInfo = $this->extractErrorInfo($response);
            
            return [
                'error' => "API error (HTTP $httpCode): " . $errorInfo['message'],
                'error_type' => $errorInfo['type']
            ];
        }
        
        // Parse JSON response
        $responseData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Utils::logError($this->logPath, "JSON Decode Error: " . json_last_error_msg() . ", Response: $response");
            return [
                'error' => "Failed to parse API response: " . json_last_error_msg(),
                'error_type' => 'invalid_response'
            ];
        }
        
        // Validate response structure
        if (!isset($responseData['candidates']) || !is_array($responseData['candidates']) || empty($responseData['candidates'])) {
            Utils::logError($this->logPath, "Invalid response structure: Missing or empty 'candidates' array. Response: " . json_encode($responseData));
            
            // Try to extract any error message from the response
            if (isset($responseData['error']['message'])) {
                return [
                    'error' => $responseData['error']['message'],
                    'error_type' => 'api_error'
                ];
            }
            
            return [
                'error' => "Invalid API response: Missing candidates",
                'error_type' => 'invalid_response'
            ];
        }
        
        // Enhanced validation for response content
        if (isset($responseData['candidates']) && is_array($responseData['candidates']) && !empty($responseData['candidates'])) {
            // Verify the response has valid content
            $candidate = $responseData['candidates'][0];
            if (!isset($candidate['content']) || 
                !isset($candidate['content']['parts']) || 
                !is_array($candidate['content']['parts']) || 
                empty($candidate['content']['parts']) ||
                !isset($candidate['content']['parts'][0]['text'])) {
                
                Utils::logError($this->logPath, "Malformed response: Missing expected content structure");
                return [
                    'error' => "Invalid API response: Malformed content structure",
                    'error_type' => 'invalid_response'
                ];
            }
        }
        
        return $responseData;
    }
    
    // Extract detailed error information from the API response
    private function extractErrorInfo($response) {
        $responseData = json_decode($response, true);
        $errorInfo = [
            'message' => $response,
            'type' => 'general'
        ];
        
        if (json_last_error() === JSON_ERROR_NONE && isset($responseData['error'])) {
            // Try to get the detailed message
            if (isset($responseData['error']['message'])) {
                $errorInfo['message'] = $responseData['error']['message'];
                
                // Determine error type based on message content
                $lowerMessage = strtolower($errorInfo['message']);
                
                if (strpos($lowerMessage, 'rate limit') !== false || strpos($lowerMessage, 'quota') !== false) {
                    $errorInfo['type'] = 'rate_limit';
                } elseif (strpos($lowerMessage, 'timeout') !== false) {
                    $errorInfo['type'] = 'timeout';
                } elseif (strpos($lowerMessage, 'not available') !== false || strpos($lowerMessage, 'not found') !== false) {
                    $errorInfo['type'] = 'api_unavailable';
                } elseif (strpos($lowerMessage, 'invalid') !== false) {
                    $errorInfo['type'] = 'invalid_request';
                }
            }
            
            // If there's a status message
            if (isset($responseData['error']['status'])) {
                $errorInfo['status'] = $responseData['error']['status'];
                
                // Use status for error type if not already determined
                if ($errorInfo['type'] === 'general') {
                    $lowerStatus = strtolower($errorInfo['status']);
                    
                    if (strpos($lowerStatus, 'unavailable') !== false) {
                        $errorInfo['type'] = 'api_unavailable';
                    } elseif (strpos($lowerStatus, 'resource_exhausted') !== false) {
                        $errorInfo['type'] = 'rate_limit';
                    } elseif (strpos($lowerStatus, 'invalid') !== false) {
                        $errorInfo['type'] = 'invalid_request';
                    }
                }
            }
        }
        
        return $errorInfo;
    }
    
    // Log API requests for debugging with more details
    private function logDetailedRequest($prompt, $data, $response, $httpCode, $info, $curlError, $curlErrno) {
        $logFile = $this->logPath . 'detailed_requests_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        
        $logData = [
            'timestamp' => $timestamp,
            'prompt' => $prompt,
            'request_data' => $data,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'curl_errno' => $curlErrno,
            'request_info' => [
                'total_time' => $info['total_time'],
                'connect_time' => $info['connect_time'],
                'size_upload' => $info['size_upload'],
                'size_download' => $info['size_download'],
                'speed_upload' => $info['speed_upload'],
                'speed_download' => $info['speed_download'],
                'content_type' => $info['content_type'] ?? 'unknown'
            ],
            'response_preview' => substr($response, 0, 500) . (strlen($response) > 500 ? '...' : '')
        ];
        
        file_put_contents(
            $logFile, 
            "[REQUEST $timestamp]\n" . json_encode($logData, JSON_PRETTY_PRINT) . "\n\n", 
            FILE_APPEND
        );
        
        // Also log the full response separately if it's large
        if (strlen($response) > 500) {
            $fullResponseFile = $this->logPath . 'full_responses_' . date('Y-m-d') . '.log';
            file_put_contents(
                $fullResponseFile,
                "[RESPONSE $timestamp]\n$response\n\n",
                FILE_APPEND
            );
        }
    }
}
