<?php

/**
 * Bitrix -> InVoice webhook endpoint.
 *
 * This endpoint is designed to be called by Bitrix24 automation / outgoing webhooks
 * when a call/activity is performed on a contact/deal, filtered by a specific pipeline.
 *
 * SECURITY:
 * - Accepts POST only
 * - Requires shared secret header: x-api-auth-token (BITRIX_WEBHOOK_TOKEN)
 * - Forensic logging via WebhookLogger (same as InVoice webhook)
 *
 * NOTE: The exact Bitrix event payload can vary (CRM activity hooks, robots, etc.).
 * This implementation focuses on robustness + logging and provides a minimal, safe processing flow.
 */

declare(strict_types=1);

use BitrixInvoiceBridge\Bitrix24ApiClient;
use BitrixInvoiceBridge\InvoiceApiClient;
use BitrixInvoiceBridge\WebhookLogger;
use BitrixInvoiceBridge\BitrixToInvoiceWorkedMapper;
use Dotenv\Dotenv;
use Ramsey\Uuid\Uuid;

// ---------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => 'Server configuration error: Composer dependencies not installed',
        'message' => 'Please run: composer install --no-dev --optimize-autoloader',
    ]);
    exit;
}
require $autoload;

// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    try {
        // Try to load with dotenv (for simple key=value pairs)
        $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
    } catch (\Exception $e) {
        // If dotenv fails (e.g., due to multi-line JSON values), load manually
        error_log("BitrixWebhook warning: Dotenv failed, loading .env manually: " . $e->getMessage());
        
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
    foreach (['BITRIX_WEBHOOK_TOKEN', 'BITRIX24_WEBHOOK_URL', 'BITRIX_OUT_PIPELINE', 
              'INVOICE_API_BASE_URL', 'INVOICE_CLIENT_ID'] as $var) {
        if (isset($_ENV[$var])) {
            if (in_array($var, ['BITRIX_WEBHOOK_TOKEN', 'BITRIX24_WEBHOOK_URL', 'INVOICE_CLIENT_ID'])) {
                $loadedVars[$var] = strlen($_ENV[$var]) > 6 ? substr($_ENV[$var], 0, 6) . '***' : '***';
            } else {
                $loadedVars[$var] = $_ENV[$var];
            }
        }
    }
    error_log("BitrixWebhook: Loaded environment variables: " . json_encode($loadedVars, JSON_UNESCAPED_SLASHES));
} else {
    error_log("BitrixWebhook warning: .env file not found at {$envFile}. Using environment variables from server.");
}

$requestId = Uuid::uuid4()->toString();
header('X-Request-ID: ' . $requestId);

// Read headers (case-insensitive)
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = [];
foreach ($headers as $k => $v) {
    $headersLower[strtolower((string)$k)] = $v;
}

$rawBody = file_get_contents('php://input') ?: '';
$contentType = strtolower($headersLower['content-type'] ?? '');

// Parse body based on content type
$decoded = null;
$parsedForm = null;
$jsonError = null;

if ($rawBody !== '') {
    if (strpos($contentType, 'application/json') !== false) {
        // JSON payload
        $decoded = json_decode($rawBody, true);
        $jsonError = (json_last_error() === JSON_ERROR_NONE) ? null : json_last_error_msg();
    } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false || strpos($contentType, 'multipart/form-data') !== false) {
        // Form-urlencoded payload (Bitrix webhook format)
        parse_str($rawBody, $parsedForm);
        $decoded = $parsedForm; // Use same variable for consistency
    } else {
        // Try JSON as fallback
        $decoded = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonError = json_last_error_msg();
            // If JSON fails, try form-urlencoded as fallback
            parse_str($rawBody, $parsedForm);
            $decoded = $parsedForm;
        }
    }
}

// ---------------------------------------------------------------------
// LOG ALL REQUESTS (before authentication) - for debugging
// ---------------------------------------------------------------------

$bitrixWebhookLogFile = __DIR__ . '/../storage/logs/bitrix-webhook-calls.log';
$logDir = dirname($bitrixWebhookLogFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'request_id' => $requestId,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'headers' => $headers,
    'content_type' => $contentType,
    'raw_body' => $rawBody,
    'parsed_body' => $decoded,
    'parsed_form' => $parsedForm,
    'json_error' => $jsonError,
    'query_string' => $_SERVER['QUERY_STRING'] ?? '',
    'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'x_real_ip' => $headersLower['x-real-ip'] ?? null,
    'x_forwarded_for' => $headersLower['x-forwarded-for'] ?? null,
    'server_vars' => [
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
        'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? null,
        'HTTPS' => $_SERVER['HTTPS'] ?? null,
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
    ],
    'auth_status' => 'not_checked_yet',
    // Extract Bitrix-specific fields for easier analysis
    'bitrix_event' => $decoded['event'] ?? null,
    'bitrix_activity_id' => $decoded['data']['FIELDS']['ID'] ?? null,
    'bitrix_auth_domain' => $decoded['auth']['domain'] ?? null,
    'bitrix_auth_token_preview' => isset($decoded['auth']['application_token']) ? (substr($decoded['auth']['application_token'], 0, 6) . '***') : null,
];

$logLine = json_encode($logEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('=', 100) . "\n\n";

// Write to dedicated log file (append mode, with locking)
@file_put_contents($bitrixWebhookLogFile, $logLine, FILE_APPEND | LOCK_EX);
error_log("BitrixWebhook [{$requestId}]: ALL requests logged to bitrix-webhook-calls.log (before auth check)");

// Quick reject non-POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    // Log rejection
    $rejectLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'request_id' => $requestId,
        'rejection_reason' => 'Method Not Allowed (not POST)',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    ];
    @file_put_contents($bitrixWebhookLogFile, "REJECTED: " . json_encode($rejectLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
    
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed', 'request_id' => $requestId]);
    exit;
}

// Shared secret auth
// Bitrix can send auth token either:
// 1. Via header: x-api-auth-token (custom header)
// 2. Via body: auth[application_token] (Bitrix native format)
$expected = $_ENV['BITRIX_WEBHOOK_TOKEN'] ?? getenv('BITRIX_WEBHOOK_TOKEN');
$provided = $headersLower['x-api-auth-token'] ?? null;

// If not in header, try to get from parsed body (Bitrix format)
if (empty($provided) && is_array($decoded) && isset($decoded['auth']['application_token'])) {
    $provided = $decoded['auth']['application_token'];
}

if (empty($expected)) {
    // Log configuration error
    $errorLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'request_id' => $requestId,
        'error' => 'Server misconfigured: BITRIX_WEBHOOK_TOKEN not set',
    ];
    @file_put_contents($bitrixWebhookLogFile, "ERROR: " . json_encode($errorLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'request_id' => $requestId, 'error' => 'Server misconfigured: BITRIX_WEBHOOK_TOKEN not set']);
    exit;
}

if (!is_string($provided) || !hash_equals((string)$expected, (string)$provided)) {
    // Log authentication failure
    $authFailLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'request_id' => $requestId,
        'auth_status' => 'failed',
        'provided_token_preview' => $provided ? (substr($provided, 0, 6) . '***') : 'null',
        'expected_token_preview' => substr($expected, 0, 6) . '***',
    ];
    @file_put_contents($bitrixWebhookLogFile, "AUTH_FAILED: " . json_encode($authFailLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
    
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'request_id' => $requestId, 'error' => 'Unauthorized']);
    exit;
}

// Update log entry with auth success
$authSuccessLog = [
    'timestamp' => date('Y-m-d H:i:s'),
    'request_id' => $requestId,
    'auth_status' => 'success',
];
@file_put_contents($bitrixWebhookLogFile, "AUTH_SUCCESS: " . json_encode($authSuccessLog, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);

// Logging (forensic) - only for authenticated requests
$logDir = $_ENV['LOG_DIR'] ?? getenv('LOG_DIR') ?: (__DIR__ . '/../storage/logs/invoice-webhook');
try {
    $logger = new WebhookLogger($logDir);
} catch (\Throwable $e) {
    $logger = null;
    error_log("BitrixWebhook [{$requestId}]: Failed to init logger: " . $e->getMessage());
}

if ($logger) {
    try {
        $logger->logRequest($requestId, [
            'request_id' => $requestId,
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'headers' => $headers,
            'raw_body' => $rawBody,
            'json_decoded' => $decoded,
            'json_error' => $jsonError,
            'parsed_form' => $parsedForm,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'content_type' => $contentType,
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log("BitrixWebhook [{$requestId}]: Failed to write forensic log: " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// TEMPORARY: Log only, no processing (for studying Bitrix payload format)
// ---------------------------------------------------------------------

$response = [
    'ok' => true,
    'request_id' => $requestId,
    'received_at' => date('c'),
    'note' => 'Call logged for analysis. Processing temporarily disabled.',
];

http_response_code(200);
header('Content-Type: application/json');
echo json_encode($response);
exit;

// ---------------------------------------------------------------------
// Processing (minimal + safe) - DISABLED FOR NOW
// ---------------------------------------------------------------------

/**
 * Expected processing strategy (to be enabled after studying logs):
 * - Determine deal ID (and activity ID) from payload
 * - Fetch deal via Bitrix API
 * - Gate by pipeline (CATEGORY_ID)
 * - Read InVoice IDs from custom fields (contactId/campaignId)
 * - Build worked payload and submit to InVoice
 *
 * Because Bitrix payload formats vary, this endpoint logs everything and returns 200 quickly.
 */

/*
$bitrixWebhookUrl = $_ENV['BITRIX24_WEBHOOK_URL'] ?? getenv('BITRIX24_WEBHOOK_URL');
$pipelineTargetRaw = $_ENV['BITRIX_OUT_PIPELINE'] ?? getenv('BITRIX_OUT_PIPELINE');
$pipelineTarget = ($pipelineTargetRaw !== null && $pipelineTargetRaw !== '') ? (int)$pipelineTargetRaw : null;

$response = [
    'ok' => true,
    'request_id' => $requestId,
    'received_at' => date('c'),
    'processed' => false,
];

try {
    if (!is_array($decoded)) {
        $response['note'] = 'No JSON payload (or invalid JSON). Logged only.';
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if (empty($bitrixWebhookUrl)) {
        $response['note'] = 'BITRIX24_WEBHOOK_URL not configured; cannot fetch deal/activity. Logged only.';
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // Best-effort extraction (supports robots/custom webhooks):
    $dealId = null;
    if (isset($decoded['deal_id'])) {
        $dealId = (int)$decoded['deal_id'];
    } elseif (isset($decoded['data']['FIELDS']['OWNER_TYPE_ID'], $decoded['data']['FIELDS']['OWNER_ID'])) {
        // crm.activity.* event (common): OWNER_TYPE_ID 2 = DEAL
        if ((int)$decoded['data']['FIELDS']['OWNER_TYPE_ID'] === 2) {
            $dealId = (int)$decoded['data']['FIELDS']['OWNER_ID'];
        }
    } elseif (isset($decoded['data']['OWNER_TYPE_ID'], $decoded['data']['OWNER_ID'])) {
        if ((int)$decoded['data']['OWNER_TYPE_ID'] === 2) {
            $dealId = (int)$decoded['data']['OWNER_ID'];
        }
    }

    if (!$dealId) {
        $response['note'] = 'No deal_id found in payload; logged only.';
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    $bitrix = new Bitrix24ApiClient((string)$bitrixWebhookUrl);
    $deal = $bitrix->getDeal($dealId);
    $dealFields = $deal['result'] ?? null;
    if (!is_array($dealFields)) {
        throw new \RuntimeException('Bitrix: crm.deal.get did not return result');
    }

    $categoryId = isset($dealFields['CATEGORY_ID']) ? (int)$dealFields['CATEGORY_ID'] : null;
    if ($pipelineTarget !== null && $categoryId !== null && $categoryId !== $pipelineTarget) {
        $response['note'] = 'Deal not in target pipeline; skipped.';
        $response['deal_id'] = $dealId;
        $response['deal_category_id'] = $categoryId;
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // Read InVoice references from custom fields (using config)
    $fieldConfig = Bitrix24ApiClient::getBitrixFieldConfig();
    $fieldIdAnagrafica = $fieldConfig['id_anagrafica'] ?? 'UF_CRM_INVOICE_ID_ANAGRAFICA'; // Fallback to legacy
    $fieldIdCampagna = $fieldConfig['id_campagna'] ?? 'UF_CRM_INVOICE_CAMPAIGN_ID'; // Fallback to legacy
    
    // Read custom fields (try configured field first, then legacy)
    $invoiceContactId = null;
    if (isset($dealFields[$fieldIdAnagrafica])) {
        $invoiceContactId = $dealFields[$fieldIdAnagrafica];
    } elseif (isset($dealFields['UF_CRM_INVOICE_ID_ANAGRAFICA'])) {
        $invoiceContactId = $dealFields['UF_CRM_INVOICE_ID_ANAGRAFICA'];
    }
    
    $invoiceCampaignId = null;
    if (isset($dealFields[$fieldIdCampagna])) {
        $invoiceCampaignId = $dealFields[$fieldIdCampagna];
    } elseif (isset($dealFields['UF_CRM_INVOICE_CAMPAIGN_ID'])) {
        $invoiceCampaignId = $dealFields['UF_CRM_INVOICE_CAMPAIGN_ID'];
    }

    if (empty($invoiceContactId) || empty($invoiceCampaignId)) {
        $response['note'] = 'Missing InVoice IDs on deal (custom fields: ' . $fieldIdAnagrafica . ' / ' . $fieldIdCampagna . '); skipped.';
        $response['deal_id'] = $dealId;
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Convert to appropriate types (Double field may come as float, String as string)
    $invoiceContactId = (string)$invoiceContactId;
    $invoiceCampaignId = (string)$invoiceCampaignId;
    
    // Read id_config_campagna from deal (needed for result codes mapping)
    $idConfigCampagna = null;
    if (isset($dealFields['UF_CRM_INVOICE_CAMPAIGN_CONFIG_ID'])) {
        $idConfigCampagna = (int)$dealFields['UF_CRM_INVOICE_CAMPAIGN_CONFIG_ID'];
        error_log("BitrixWebhook [{$requestId}]: Found id_config_campagna on deal: {$idConfigCampagna}");
    } else {
        error_log("BitrixWebhook [{$requestId}]: WARNING - id_config_campagna not found on deal, result codes mapping may use default");
    }

    // Extract Bitrix activity outcome from payload (supports multiple formats)
    $bitrixOutcome = null;
    if (isset($decoded['outcome'])) {
        $bitrixOutcome = $decoded['outcome'];
    } elseif (isset($decoded['status'])) {
        $bitrixOutcome = $decoded['status'];
    } elseif (isset($decoded['result'])) {
        $bitrixOutcome = $decoded['result'];
    } elseif (isset($decoded['data']['FIELDS']['RESULT'])) {
        $bitrixOutcome = $decoded['data']['FIELDS']['RESULT'];
    } elseif (isset($decoded['data']['FIELDS']['STATUS'])) {
        $bitrixOutcome = $decoded['data']['FIELDS']['STATUS'];
    }
    
    if ($bitrixOutcome !== null) {
        error_log("BitrixWebhook [{$requestId}]: Extracted Bitrix outcome: {$bitrixOutcome}");
    } else {
        error_log("BitrixWebhook [{$requestId}]: No Bitrix outcome found in payload, will use explicit workedCode/resultCode if provided");
    }

    // Build worked input - use outcome mapping if available, otherwise use explicit values
    $workedInput = [
        'contactId' => $invoiceContactId,
        'campaignId' => $invoiceCampaignId,
        'workedDate' => $decoded['workedDate'] ?? date('Y-m-d H:i:s'),
        'workedEndDate' => $decoded['workedEndDate'] ?? date('Y-m-d H:i:s'),
        'caller' => $decoded['caller'] ?? 'unknown',
    ];
    
    // Add outcome mapping if available
    if ($bitrixOutcome !== null) {
        $workedInput['bitrixOutcome'] = $bitrixOutcome;
        if ($idConfigCampagna !== null) {
            $workedInput['idConfigCampagna'] = $idConfigCampagna;
        }
    }
    
    // Explicit values override mapping if provided
    if (isset($decoded['workedCode'])) {
        $workedInput['workedCode'] = $decoded['workedCode'];
    }
    if (isset($decoded['resultCode'])) {
        $workedInput['resultCode'] = $decoded['resultCode'];
    }
    if (isset($decoded['workedType'])) {
        $workedInput['workedType'] = $decoded['workedType'];
    }

    $workedPayload = BitrixToInvoiceWorkedMapper::buildWorkedPayload($workedInput);

    // InVoice client
    $invoiceBaseUrl = $_ENV['INVOICE_API_BASE_URL'] ?? getenv('INVOICE_API_BASE_URL') ?: 'https://enel.in-voice.it';
    $invoiceClientId = $_ENV['INVOICE_CLIENT_ID'] ?? getenv('INVOICE_CLIENT_ID');
    $invoiceJwk = $_ENV['INVOICE_JWK_JSON'] ?? getenv('INVOICE_JWK_JSON');

    $invoice = new InvoiceApiClient($invoiceBaseUrl, __DIR__ . '/../storage/logs/invoice-token-cache');
    if (!empty($invoiceClientId) && !empty($invoiceJwk)) {
        $invoice->setCredentials((string)$invoiceClientId, (string)$invoiceJwk);
    }

    $workedResult = $invoice->submitWorkedContact($workedPayload);

    $response['processed'] = true;
    $response['deal_id'] = $dealId;
    $response['invoice_worked_response'] = $workedResult;

} catch (\Throwable $e) {
    // Never fail Bitrix retries with 500 unless absolutely necessary
    $response['processed'] = false;
    $response['error'] = $e->getMessage();
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode($response);
*/
