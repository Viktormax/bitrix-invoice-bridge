<?php

/**
 * InVoice webhook endpoint for receiving LEAD_AVAILABLE and other events.
 * This endpoint must respond quickly (within 3-5 seconds) as per InVoice timeout requirements.
 */

// Enable error reporting for debugging (disable in production if needed)
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't display errors to client, but log them
ini_set('log_errors', '1');

// Start output buffering early to catch any errors
ob_start();

// Check if autoloader exists
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloader)) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Server configuration error: Composer dependencies not installed',
        'error_type' => 'MissingDependencies',
        'message' => 'Please run: composer install --no-dev --optimize-autoloader'
    ], JSON_UNESCAPED_SLASHES);
    error_log("Webhook error: vendor/autoload.php not found. Run composer install.");
    ob_end_flush();
    exit;
}

require_once $autoloader;

// Check if required classes exist
if (!class_exists('BitrixInvoiceBridge\WebhookLogger')) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Server configuration error: WebhookLogger class not found',
        'error_type' => 'MissingClass',
        'message' => 'Check that src/WebhookLogger.php exists and autoloader is working'
    ], JSON_UNESCAPED_SLASHES);
    error_log("Webhook error: WebhookLogger class not found");
    ob_end_flush();
    exit;
}

if (!class_exists('BitrixInvoiceBridge\InvoiceApiClient')) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Server configuration error: InvoiceApiClient class not found',
        'error_type' => 'MissingClass',
        'message' => 'Check that src/InvoiceApiClient.php exists and autoloader is working'
    ], JSON_UNESCAPED_SLASHES);
    error_log("Webhook error: InvoiceApiClient class not found");
    ob_end_flush();
    exit;
}

if (!class_exists('BitrixInvoiceBridge\Bitrix24ApiClient')) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Server configuration error: Bitrix24ApiClient class not found',
        'error_type' => 'MissingClass',
        'message' => 'Check that src/Bitrix24ApiClient.php exists and autoloader is working'
    ], JSON_UNESCAPED_SLASHES);
    error_log("Webhook error: Bitrix24ApiClient class not found");
    ob_end_flush();
    exit;
}

if (!class_exists('Ramsey\Uuid\Uuid')) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Server configuration error: Uuid class not found',
        'error_type' => 'MissingDependency',
        'message' => 'Run: composer install --no-dev --optimize-autoloader'
    ], JSON_UNESCAPED_SLASHES);
    error_log("Webhook error: Ramsey\Uuid\Uuid class not found");
    ob_end_flush();
    exit;
}

use BitrixInvoiceBridge\WebhookLogger;
use BitrixInvoiceBridge\InvoiceApiClient;
use BitrixInvoiceBridge\Bitrix24ApiClient;
use Dotenv\Dotenv;
use Ramsey\Uuid\Uuid;

/**
 * Get all HTTP headers (fallback for environments where getallheaders() is not available).
 */
if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
        // Also check for Content-Type and Content-Length which may not have HTTP_ prefix
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }
}

// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    try {
        // Try to load with dotenv (for simple key=value pairs)
        $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
    } catch (\Exception $e) {
        // If dotenv fails (e.g., due to multi-line JSON values), load manually
        error_log("Webhook warning: Dotenv failed, loading .env manually: " . $e->getMessage());
        
        // Read .env file and parse manually (handles multi-line JSON values)
        $envContent = file_get_contents($envFile);
        $lines = explode("\n", $envContent);
        $currentKey = null;
        $currentValue = '';
        $inMultiLine = false;
        
        foreach ($lines as $lineNum => $line) {
            $originalLine = $line;
            $line = rtrim($line);
            
            // Skip empty lines and comments
            if (empty($line) || (isset($line[0]) && $line[0] === '#')) {
                if ($inMultiLine) {
                    $currentValue .= "\n" . $originalLine;
                }
                continue;
            }
            
            // Check if this is a new key=value line
            if (preg_match('/^([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
                // Save previous key if exists
                if ($currentKey !== null) {
                    $finalValue = trim($currentValue);
                    // Remove surrounding quotes if present
                    $finalValue = trim($finalValue, '"\'');
                    $_ENV[$currentKey] = $finalValue;
                    putenv("{$currentKey}={$finalValue}");
                }
                
                // Start new key
                $currentKey = $matches[1];
                $valuePart = $matches[2];
                
                // Check if value starts with { or [ (JSON) - might be multi-line
                $trimmedValue = trim($valuePart);
                if (!empty($trimmedValue) && 
                    (($trimmedValue[0] === '{' || $trimmedValue[0] === '[')) && 
                    (substr($trimmedValue, -1) !== '}' && substr($trimmedValue, -1) !== ']')) {
                    // Multi-line JSON
                    $currentValue = $valuePart;
                    $inMultiLine = true;
                } else {
                    // Single line value - remove surrounding quotes
                    $currentValue = trim($valuePart, '"\'');
                    // Handle empty values
                    if ($currentValue === '') {
                        $currentValue = '';
                    }
                    $_ENV[$currentKey] = $currentValue;
                    putenv("{$currentKey}={$currentValue}");
                    $currentKey = null;
                    $currentValue = '';
                    $inMultiLine = false;
                }
            } else if ($inMultiLine && $currentKey !== null) {
                // Continuation of multi-line value
                $currentValue .= "\n" . $originalLine;
                
                // Check if JSON is complete (balanced braces)
                $trimmed = trim($currentValue);
                if (($trimmed[0] === '{' && substr_count($trimmed, '{') === substr_count($trimmed, '}')) ||
                    ($trimmed[0] === '[' && substr_count($trimmed, '[') === substr_count($trimmed, ']'))) {
                    // JSON is complete
                    $finalValue = trim($currentValue);
                    $_ENV[$currentKey] = $finalValue;
                    putenv("{$currentKey}={$finalValue}");
                    $currentKey = null;
                    $currentValue = '';
                    $inMultiLine = false;
                }
            }
        }
        
        // Save last key if exists
        if ($currentKey !== null) {
            $finalValue = trim($currentValue);
            // Remove surrounding quotes if present
            $finalValue = trim($finalValue, '"\'');
            $_ENV[$currentKey] = $finalValue;
            putenv("{$currentKey}={$finalValue}");
        }
    }
    
    // Debug: Log loaded environment variables (mask sensitive ones)
    $loadedVars = [];
    foreach (['INVOICE_WEBHOOK_TOKEN', 'INVOICE_CLIENT_ID', 'INVOICE_JWK_JSON', 'BITRIX24_WEBHOOK_URL', 
              'ALLOW_DUPLICATE', 'ENTITY_TYPE', 'PIPELINE', 'INVOICE_API_BASE_URL'] as $var) {
        if (isset($_ENV[$var])) {
            if (in_array($var, ['INVOICE_WEBHOOK_TOKEN', 'INVOICE_JWK_JSON', 'BITRIX24_WEBHOOK_URL'])) {
                $loadedVars[$var] = strlen($_ENV[$var]) > 6 ? substr($_ENV[$var], 0, 6) . '***' : '***';
            } else {
                $loadedVars[$var] = $_ENV[$var];
            }
        }
    }
    error_log("Webhook: Loaded environment variables: " . json_encode($loadedVars, JSON_UNESCAPED_SLASHES));
} else {
    error_log("Webhook warning: .env file not found at {$envFile}. Using environment variables from server.");
}

// Generate unique request ID
$requestId = Uuid::uuid4()->toString();

// Start response time tracking
$startTime = microtime(true);

// Initialize response
$response = [
    'ok' => false,
    'request_id' => $requestId,
    'received_at' => date('c'),
    'error' => null
];

try {
    // Ensure we're on HTTPS (InVoice requirement)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
               || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
               || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        $response['error'] = 'Method not allowed. Only POST is accepted.';
        header('Content-Type: application/json');
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Verify authentication token (case-insensitive header check)
    $headers = getallheaders();
    $headersLower = array_change_key_case($headers ?? [], CASE_LOWER);
    
    // Check for api-auth-token header (case-insensitive)
    $authToken = $headersLower['api-auth-token'] ?? null;
    $expectedToken = $_ENV['INVOICE_WEBHOOK_TOKEN'] ?? getenv('INVOICE_WEBHOOK_TOKEN');

    if (empty($expectedToken)) {
        http_response_code(500);
        $response['error'] = 'Server configuration error: webhook token not configured';
        error_log("Webhook error: INVOICE_WEBHOOK_TOKEN not set in environment");
        header('Content-Type: application/json');
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (empty($authToken) || !hash_equals($expectedToken, $authToken)) {
        http_response_code(401);
        $response['error'] = 'Unauthorized: invalid or missing api-auth-token header';
        header('Content-Type: application/json');
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Read raw body (with size limit to prevent memory issues)
    $maxBodySize = 10 * 1024 * 1024; // 10MB max
    $rawBody = '';
    $inputStream = fopen('php://input', 'r');
    if ($inputStream) {
        $rawBody = stream_get_contents($inputStream, $maxBodySize);
        fclose($inputStream);
    }
    
    // Collect all request data for logging (prepare quickly)
    $requestData = [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'path' => $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '',
        'query_string' => $_SERVER['QUERY_STRING'] ?? '',
        'headers' => $headers ?? [],
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? strlen($rawBody),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'not set',
        'raw_body' => $rawBody,
        'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'is_https' => $isHttps,
    ];

    // Initialize logger and log the request (fast, non-blocking write)
    $logDir = $_ENV['LOG_DIR'] ?? getenv('LOG_DIR') ?: __DIR__ . '/../storage/logs/invoice-webhook';
    
    // Ensure log directory exists and is writable
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0755, true)) {
            throw new \Exception("Cannot create log directory: {$logDir}. Check permissions.");
        }
    }
    if (!is_writable($logDir)) {
        throw new \Exception("Log directory is not writable: {$logDir}. Check permissions.");
    }
    
    // Try to log the request, but don't fail if logging fails
    $logger = null;
    try {
        $logger = new WebhookLogger($logDir);
        $logger->logRequest($requestId, $requestData);
    } catch (\Throwable $logError) {
        // Log the logging error but continue execution
        error_log("Webhook [{$requestId}]: Failed to write log: " . $logError->getMessage());
        error_log("Webhook [{$requestId}]: Log error file: " . $logError->getFile() . ":" . $logError->getLine());
        error_log("Webhook [{$requestId}]: Log error trace: " . $logError->getTraceAsString());
        
        // Also try to write to webhook_errors.log as fallback
        $errorLogFile = __DIR__ . '/webhook_errors.log';
        $errorLogEntry = date('Y-m-d H:i:s') . " [{$requestId}] LOGGING ERROR\n";
        $errorLogEntry .= "Error: " . $logError->getMessage() . "\n";
        $errorLogEntry .= "File: " . $logError->getFile() . ":" . $logError->getLine() . "\n";
        $errorLogEntry .= "Trace:\n" . $logError->getTraceAsString() . "\n";
        $errorLogEntry .= str_repeat('-', 80) . "\n\n";
        @file_put_contents($errorLogFile, $errorLogEntry, FILE_APPEND | LOCK_EX);
    }

    // Process event if it's LEAD_AVAILABLE
    $eventProcessed = false;
    $eventProcessingError = null;
    
    if (!empty($rawBody)) {
        $eventData = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($eventData['event'])) {
            // Log that we detected an event
            error_log("Webhook [{$requestId}]: Detected event: " . $eventData['event']);
            
            if ($eventData['event'] === 'LEAD_AVAILABLE' && isset($eventData['slice']) && is_array($eventData['slice'])) {
                error_log("Webhook [{$requestId}]: Processing LEAD_AVAILABLE event with " . count($eventData['slice']) . " slice(s)");
                
                // Process each slice in the event
                foreach ($eventData['slice'] as $slice) {
                    if (isset($slice['id']) && is_numeric($slice['id'])) {
                        $lotId = (int)$slice['id'];
                        error_log("Webhook [{$requestId}]: Processing lot ID: {$lotId}");
                        
                        try {
                            // Initialize InvoiceApiClient
                            $apiBaseUrl = $_ENV['INVOICE_API_BASE_URL'] ?? getenv('INVOICE_API_BASE_URL') ?: 'https://enel.in-voice.it';
                            $clientId = $_ENV['INVOICE_CLIENT_ID'] ?? getenv('INVOICE_CLIENT_ID');
                            $jwkJson = $_ENV['INVOICE_JWK_JSON'] ?? getenv('INVOICE_JWK_JSON');
                            
                            error_log("Webhook [{$requestId}]: API Base URL: {$apiBaseUrl}");
                            error_log("Webhook [{$requestId}]: Client ID configured: " . (!empty($clientId) ? 'YES (length: ' . strlen($clientId) . ')' : 'NO'));
                            error_log("Webhook [{$requestId}]: JWK JSON configured: " . (!empty($jwkJson) ? 'YES (length: ' . strlen($jwkJson) . ')' : 'NO'));
                            
                            if (empty($clientId) || empty($jwkJson)) {
                                throw new \Exception('OAuth2 credentials not configured (INVOICE_CLIENT_ID or INVOICE_JWK_JSON missing)');
                            }
                            
                            // Set cache directory for token caching
                            $tokenCacheDir = $logDir . '/../invoice-token-cache';
                            if (!is_dir($tokenCacheDir)) {
                                @mkdir($tokenCacheDir, 0755, true);
                            }
                            
                            error_log("Webhook [{$requestId}]: Initializing InvoiceApiClient...");
                            $apiClient = new InvoiceApiClient($apiBaseUrl, $tokenCacheDir);
                            $apiClient->setCredentials($clientId, $jwkJson);
                            
                            error_log("Webhook [{$requestId}]: Fetching lot data for lot ID {$lotId}...");
                            // Fetch lot data from InVoice API
                            $lotData = $apiClient->getLotData($lotId);
                            
                            error_log("Webhook [{$requestId}]: Successfully retrieved lot data for lot ID {$lotId}");
                            
                            // Send data to Bitrix24
                            $bitrixWebhookUrl = $_ENV['BITRIX24_WEBHOOK_URL'] ?? getenv('BITRIX24_WEBHOOK_URL');
                            $bitrixEntityId = null;
                            $bitrixDealId = null;
                            $bitrixError = null;
                            
                            // Read Bitrix24 configuration
                            $allowDuplicateRaw = $_ENV['ALLOW_DUPLICATE'] ?? getenv('ALLOW_DUPLICATE');
                            $entityTypeRaw = $_ENV['ENTITY_TYPE'] ?? getenv('ENTITY_TYPE');
                            $pipelineRaw = $_ENV['PIPELINE'] ?? getenv('PIPELINE');
                            
                            error_log("Webhook [{$requestId}]: Raw config values - ALLOW_DUPLICATE: " . ($allowDuplicateRaw ?? 'NULL') . 
                                     ", ENTITY_TYPE: " . ($entityTypeRaw ?? 'NULL') . 
                                     ", PIPELINE: " . ($pipelineRaw ?? 'NULL'));
                            
                            // Defaults: ALLOW_DUPLICATE=true, ENTITY_TYPE=contact
                            $allowDuplicate = strtolower($allowDuplicateRaw ?: 'true') === 'true';
                            $entityType = strtolower($entityTypeRaw ?: 'contact');
                            $pipelineId = !empty($pipelineRaw) ? (int)$pipelineRaw : null;
                            
                            error_log("Webhook [{$requestId}]: Parsed config - ALLOW_DUPLICATE: " . ($allowDuplicate ? 'true' : 'false') . 
                                     ", ENTITY_TYPE: {$entityType}" . 
                                     ", PIPELINE: " . ($pipelineId ?? 'NULL'));
                            
                            // Extract id_config_campagna from slice
                            $idConfigCampagna = isset($slice['id_config_campagna']) ? (int)$slice['id_config_campagna'] : null;
                            if ($idConfigCampagna !== null) {
                                $campaignName = Bitrix24ApiClient::getCampaignName($idConfigCampagna);
                                error_log("Webhook [{$requestId}]: Campaign ID: {$idConfigCampagna}, Campaign Name: {$campaignName}");
                            } else {
                                error_log("Webhook [{$requestId}]: No id_config_campagna found in slice");
                            }
                            
                            if (!empty($bitrixWebhookUrl)) {
                                try {
                                    error_log("Webhook [{$requestId}]: Sending data to Bitrix24...");
                                    error_log("Webhook [{$requestId}]: Entity Type: {$entityType}, Allow Duplicate: " . ($allowDuplicate ? 'true' : 'false'));
                                    if ($pipelineId !== null) {
                                        error_log("Webhook [{$requestId}]: Pipeline ID: {$pipelineId}");
                                    }
                                    
                                    $bitrixClient = new Bitrix24ApiClient($bitrixWebhookUrl);
                                    
                                    $phone = $lotData[0]['TELEFONO'] ?? null;
                                    $existingEntity = null;
                                    
                                    // Check for duplicates only if ALLOW_DUPLICATE=false
                                    if (!$allowDuplicate && !empty($phone)) {
                                        try {
                                            if ($entityType === 'contact') {
                                                $existingEntity = $bitrixClient->findContactByPhone($phone);
                                                if ($existingEntity) {
                                                    error_log("Webhook [{$requestId}]: Found existing contact in Bitrix24: ID " . $existingEntity['ID']);
                                                }
                                            } else {
                                                $existingEntity = $bitrixClient->findLeadByPhone($phone);
                                                if ($existingEntity) {
                                                    error_log("Webhook [{$requestId}]: Found existing lead in Bitrix24: ID " . $existingEntity['ID']);
                                                }
                                            }
                                        } catch (\Exception $e) {
                                            error_log("Webhook [{$requestId}]: Error searching for existing entity: " . $e->getMessage());
                                            // Continue with creation if search fails
                                        }
                                    }
                                    
                                    if ($entityType === 'contact') {
                                        // Create or update contact
                                        $contactFields = Bitrix24ApiClient::mapInvoiceDataToBitrixFields($lotData, $lotId, $idConfigCampagna, 'contact');
                                        error_log("Webhook [{$requestId}]: Contact fields mapped: " . json_encode(array_keys($contactFields)));
                                        
                                        if ($existingEntity) {
                                            // Update existing contact
                                            error_log("Webhook [{$requestId}]: Updating existing contact ID " . $existingEntity['ID']);
                                            $bitrixClient->updateContact((int)$existingEntity['ID'], $contactFields);
                                            $bitrixEntityId = $existingEntity['ID'];
                                            error_log("Webhook [{$requestId}]: Successfully updated contact in Bitrix24");
                                        } else {
                                            // Create new contact
                                            error_log("Webhook [{$requestId}]: Creating new contact in Bitrix24");
                                            $contactResult = $bitrixClient->createContact($contactFields);
                                            if (isset($contactResult['result'])) {
                                                $bitrixEntityId = $contactResult['result'];
                                                error_log("Webhook [{$requestId}]: Successfully created contact in Bitrix24: ID " . $bitrixEntityId);
                                            } else {
                                                throw new \Exception("Bitrix24 API did not return contact ID: " . json_encode($contactResult));
                                            }
                                        }
                                        
                                        // Create deal and link to contact
                                        $dealFields = Bitrix24ApiClient::mapInvoiceDataToDealFields($lotData, $lotId, $idConfigCampagna, $pipelineId);
                                        error_log("Webhook [{$requestId}]: Deal fields mapped: " . json_encode(array_keys($dealFields)));
                                        if (isset($dealFields['CATEGORY_ID'])) {
                                            error_log("Webhook [{$requestId}]: Deal CATEGORY_ID (pipeline): " . $dealFields['CATEGORY_ID']);
                                        }
                                        if (isset($dealFields['SOURCE_DESCRIPTION'])) {
                                            error_log("Webhook [{$requestId}]: Deal SOURCE_DESCRIPTION: " . $dealFields['SOURCE_DESCRIPTION']);
                                        }
                                        error_log("Webhook [{$requestId}]: Creating deal linked to contact ID " . $bitrixEntityId);
                                        $dealResult = $bitrixClient->createDeal($dealFields);
                                        if (isset($dealResult['result'])) {
                                            $bitrixDealId = $dealResult['result'];
                                            error_log("Webhook [{$requestId}]: Successfully created deal: ID " . $bitrixDealId);
                                            
                                            // Link deal to contact
                                            $bitrixClient->linkDealToContact($bitrixDealId, (int)$bitrixEntityId);
                                            error_log("Webhook [{$requestId}]: Successfully linked deal to contact");
                                        } else {
                                            throw new \Exception("Bitrix24 API did not return deal ID: " . json_encode($dealResult));
                                        }
                                        
                                        $response['bitrix_contact_id'] = $bitrixEntityId;
                                        $response['bitrix_deal_id'] = $bitrixDealId;
                                        $response['bitrix_status'] = $existingEntity ? 'contact_updated_deal_created' : 'contact_created_deal_created';
                                        
                                    } else {
                                        // Create or update lead (default behavior)
                                        $leadFields = Bitrix24ApiClient::mapInvoiceDataToBitrixFields($lotData, $lotId, $idConfigCampagna, 'lead');
                                        error_log("Webhook [{$requestId}]: Lead fields mapped: " . json_encode(array_keys($leadFields)));
                                        if (isset($leadFields['SOURCE_DESCRIPTION'])) {
                                            error_log("Webhook [{$requestId}]: Lead SOURCE_DESCRIPTION: " . $leadFields['SOURCE_DESCRIPTION']);
                                        }
                                        
                                        if ($existingEntity) {
                                            // Update existing lead
                                            error_log("Webhook [{$requestId}]: Updating existing lead ID " . $existingEntity['ID']);
                                            $bitrixClient->updateLead((int)$existingEntity['ID'], $leadFields);
                                            $bitrixEntityId = $existingEntity['ID'];
                                            error_log("Webhook [{$requestId}]: Successfully updated lead in Bitrix24");
                                        } else {
                                            // Create new lead
                                            error_log("Webhook [{$requestId}]: Creating new lead in Bitrix24");
                                            $leadResult = $bitrixClient->createLead($leadFields);
                                            if (isset($leadResult['result'])) {
                                                $bitrixEntityId = $leadResult['result'];
                                                error_log("Webhook [{$requestId}]: Successfully created lead in Bitrix24: ID " . $bitrixEntityId);
                                            } else {
                                                throw new \Exception("Bitrix24 API did not return lead ID: " . json_encode($leadResult));
                                            }
                                        }
                                        
                                        $response['bitrix_lead_id'] = $bitrixEntityId;
                                        $response['bitrix_status'] = $existingEntity ? 'updated' : 'created';
                                    }
                                    
                                } catch (\Exception $bitrixEx) {
                                    // Log Bitrix24 error but don't fail the webhook
                                    $bitrixError = $bitrixEx->getMessage();
                                    error_log("Webhook [{$requestId}]: ERROR sending to Bitrix24 - " . $bitrixError);
                                    error_log("Webhook [{$requestId}]: Bitrix24 error type: " . get_class($bitrixEx));
                                    
                                    $response['bitrix_error'] = $bitrixError;
                                    $response['bitrix_status'] = 'failed';
                                }
                            } else {
                                error_log("Webhook [{$requestId}]: BITRIX24_WEBHOOK_URL not configured, skipping Bitrix24 integration");
                            }
                            
                            // Also write success to webhook_errors.log for debugging
                            $errorLogFile = __DIR__ . '/webhook_errors.log';
                            $successLogEntry = date('Y-m-d H:i:s') . " [{$requestId}] EVENT PROCESSING SUCCESS\n";
                            $successLogEntry .= "Lot ID: {$lotId}\n";
                            $successLogEntry .= "Lot data keys: " . implode(', ', array_keys($lotData)) . "\n";
                            if ($idConfigCampagna !== null) {
                                $campaignName = Bitrix24ApiClient::getCampaignName($idConfigCampagna);
                                $successLogEntry .= "Campaign ID: {$idConfigCampagna}, Campaign Name: {$campaignName}\n";
                            }
                            $successLogEntry .= "Entity Type: {$entityType}\n";
                            $successLogEntry .= "Allow Duplicate: " . ($allowDuplicate ? 'true' : 'false') . "\n";
                            if ($pipelineId !== null) {
                                $successLogEntry .= "Pipeline ID: {$pipelineId}\n";
                            }
                            if ($bitrixEntityId) {
                                if ($entityType === 'contact') {
                                    $successLogEntry .= "Bitrix24 Contact ID: {$bitrixEntityId}\n";
                                    if ($bitrixDealId) {
                                        $successLogEntry .= "Bitrix24 Deal ID: {$bitrixDealId}\n";
                                    }
                                } else {
                                    $successLogEntry .= "Bitrix24 Lead ID: {$bitrixEntityId}\n";
                                }
                                $successLogEntry .= "Bitrix24 Status: " . ($response['bitrix_status'] ?? 'unknown') . "\n";
                            }
                            if ($bitrixError) {
                                $successLogEntry .= "Bitrix24 Error: {$bitrixError}\n";
                            }
                            $successLogEntry .= str_repeat('-', 80) . "\n\n";
                            @file_put_contents($errorLogFile, $successLogEntry, FILE_APPEND | LOCK_EX);
                            
                            // Log the lot data retrieval (check if method exists for backward compatibility)
                            if ($logger && method_exists($logger, 'logEventProcessing')) {
                                try {
                                    $logData = [
                                        'event' => 'LEAD_AVAILABLE',
                                        'lot_id' => $lotId,
                                        'lot_data' => $lotData,
                                        'status' => 'success'
                                    ];
                                    if ($bitrixEntityId) {
                                        if ($entityType === 'contact') {
                                            $logData['bitrix_contact_id'] = $bitrixEntityId;
                                            if ($bitrixDealId) {
                                                $logData['bitrix_deal_id'] = $bitrixDealId;
                                            }
                                        } else {
                                            $logData['bitrix_lead_id'] = $bitrixEntityId;
                                        }
                                        $logData['bitrix_status'] = $response['bitrix_status'] ?? 'unknown';
                                    }
                                    if ($bitrixError) {
                                        $logData['bitrix_error'] = $bitrixError;
                                    }
                                    $logger->logEventProcessing($requestId, $logData);
                                } catch (\Throwable $e) {
                                    error_log("Webhook [{$requestId}]: Failed to log event processing: " . $e->getMessage());
                                }
                            } else {
                                if (!$logger) {
                                    error_log("Webhook [{$requestId}]: WARNING - Logger not initialized");
                                } else {
                                    error_log("Webhook [{$requestId}]: WARNING - logEventProcessing() method not found. Please update src/WebhookLogger.php");
                                }
                            }
                            
                            $eventProcessed = true;
                            $response['event_processed'] = true;
                            $response['lot_id'] = $lotId;
                            
                        } catch (\Exception $e) {
                            // Log error but don't fail the webhook (respond 200 to InVoice)
                            $eventProcessingError = $e->getMessage();
                            
                            error_log("Webhook [{$requestId}]: ERROR processing lot {$lotId} - " . $e->getMessage());
                            error_log("Webhook [{$requestId}]: Error type: " . get_class($e));
                            error_log("Webhook [{$requestId}]: Error file: " . $e->getFile() . ":" . $e->getLine());
                            
                            // Also write to webhook_errors.log for easy debugging
                            $errorLogFile = __DIR__ . '/webhook_errors.log';
                            $errorLogEntry = date('Y-m-d H:i:s') . " [{$requestId}] EVENT PROCESSING ERROR\n";
                            $errorLogEntry .= "Lot ID: {$lotId}\n";
                            $errorLogEntry .= "Error: " . $e->getMessage() . "\n";
                            $errorLogEntry .= "Type: " . get_class($e) . "\n";
                            $errorLogEntry .= "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
                            $errorLogEntry .= "Trace:\n" . $e->getTraceAsString() . "\n";
                            $errorLogEntry .= str_repeat('-', 80) . "\n\n";
                            @file_put_contents($errorLogFile, $errorLogEntry, FILE_APPEND | LOCK_EX);
                            
                            // Log event processing error (check if method exists for backward compatibility)
                            if ($logger && method_exists($logger, 'logEventProcessing')) {
                                try {
                                    $logger->logEventProcessing($requestId, [
                                        'event' => 'LEAD_AVAILABLE',
                                        'lot_id' => $lotId,
                                        'status' => 'error',
                                        'error' => $e->getMessage(),
                                        'error_type' => get_class($e),
                                        'error_file' => $e->getFile(),
                                        'error_line' => $e->getLine()
                                    ]);
                                } catch (\Throwable $logErr) {
                                    error_log("Webhook [{$requestId}]: Failed to log event processing error: " . $logErr->getMessage());
                                }
                            } else {
                                if (!$logger) {
                                    error_log("Webhook [{$requestId}]: WARNING - Logger not initialized");
                                } else {
                                    error_log("Webhook [{$requestId}]: WARNING - logEventProcessing() method not found. Please update src/WebhookLogger.php");
                                }
                            }
                        }
                    } else {
                        error_log("Webhook [{$requestId}]: Slice missing or invalid ID: " . json_encode($slice));
                    }
                }
            } else {
                error_log("Webhook [{$requestId}]: Event is not LEAD_AVAILABLE or slice array missing. Event: " . ($eventData['event'] ?? 'unknown'));
            }
        } else {
            error_log("Webhook [{$requestId}]: Failed to decode JSON or event field missing. JSON error: " . json_last_error_msg());
        }
    } else {
        error_log("Webhook [{$requestId}]: Raw body is empty");
    }
    
    // Success response - respond quickly to meet InVoice timeout requirements
    $response['ok'] = true;
    if ($eventProcessed) {
        $response['message'] = 'Event processed successfully';
    } elseif ($eventProcessingError) {
        $response['message'] = 'Event received but processing failed (check logs)';
        $response['processing_error'] = $eventProcessingError;
    }
    http_response_code(200);
    
} catch (Throwable $e) {
    // Log detailed error information
    $errorDetails = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    error_log("Webhook error [{$requestId}]: " . json_encode($errorDetails, JSON_UNESCAPED_SLASHES));
    
    // Also write to a separate error log file (accessible via web for debugging)
    $errorLogFile = __DIR__ . '/webhook_errors.log';
    $errorLogEntry = date('Y-m-d H:i:s') . " [{$requestId}]\n";
    $errorLogEntry .= "Error: " . $e->getMessage() . "\n";
    $errorLogEntry .= "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    $errorLogEntry .= "Type: " . get_class($e) . "\n";
    $errorLogEntry .= "Trace:\n" . $e->getTraceAsString() . "\n";
    $errorLogEntry .= str_repeat('-', 80) . "\n\n";
    @file_put_contents($errorLogFile, $errorLogEntry, FILE_APPEND | LOCK_EX);
    
    http_response_code(500);
    $response['error'] = 'Internal server error';
    $response['error_type'] = get_class($e);
    // Include detailed error information for debugging
    $response['error_message'] = $e->getMessage();
    $response['error_file'] = $e->getFile();
    $response['error_line'] = $e->getLine();
    // Include first few lines of trace for debugging
    $traceLines = explode("\n", $e->getTraceAsString());
    $response['error_trace'] = array_slice($traceLines, 0, 5); // First 5 lines of trace
}

// Ensure response is sent quickly (critical for InVoice timeout: 3s connection, 5s read)
$response['processing_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

// Clear any output buffer content (warnings, notices, etc.)
ob_clean();

// Set headers and send response immediately
header('Content-Type: application/json; charset=utf-8');
header('X-Request-ID: ' . $requestId);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Ensure JSON encoding doesn't fail
$jsonResponse = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($jsonResponse === false) {
    // Fallback if JSON encoding fails
    http_response_code(500);
    $jsonResponse = json_encode([
        'ok' => false,
        'error' => 'JSON encoding error',
        'request_id' => $requestId
    ], JSON_UNESCAPED_SLASHES);
    error_log("Webhook error [{$requestId}]: JSON encoding failed: " . json_last_error_msg());
}

// Send response
echo $jsonResponse;

// Flush output immediately to ensure InVoice receives response quickly
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}

// End output buffering
ob_end_flush();
