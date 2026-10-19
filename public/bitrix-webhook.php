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

/**
 * Read a required env var from $_ENV / getenv.
 *
 * @throws \RuntimeException
 */
function requireEnv(string $key): string
{
    $val = $_ENV[$key] ?? getenv($key);
    if (!is_string($val) || trim($val) === '') {
        throw new \RuntimeException("Missing required env var: {$key}");
    }
    return trim($val);
}

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
// LOG ALL REQUESTS (before authentication) - for debugging (daily rotated)
// ---------------------------------------------------------------------

$bitrixWebhookLogDir = __DIR__ . '/../storage/logs/bitrix-webhook-calls';
if (!is_dir($bitrixWebhookLogDir)) {
    mkdir($bitrixWebhookLogDir, 0755, true);
}
$bitrixWebhookLogFile = $bitrixWebhookLogDir . '/' . date('Y-m-d') . '.log';
$logDir = $bitrixWebhookLogDir;

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

// Compact JSON to keep file size under control
$logLine = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

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
    @file_put_contents($bitrixWebhookLogFile, "REJECTED: " . json_encode($rejectLog) . "\n", FILE_APPEND | LOCK_EX);
    
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
    // Log authentication failure with full tokens for debugging
    $authFailLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'request_id' => $requestId,
        'auth_status' => 'failed',
        'provided_token' => $provided ?: 'null',
        'expected_token' => $expected,
        'provided_token_length' => $provided ? strlen($provided) : 0,
        'expected_token_length' => strlen($expected),
        'provided_from' => isset($headersLower['x-api-auth-token']) ? 'header' : (isset($decoded['auth']['application_token']) ? 'body' : 'none'),
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
// Helper functions for outcome mapping and polling
// ---------------------------------------------------------------------

/**
 * Read outcome mapping configuration.
 * 
 * @return array Outcome mapping (Bitrix ID => InVoice code)
 */
function readOutcomeMapping(): array
{
    static $outcomeMapping = null;
    
    if ($outcomeMapping === null) {
        $configFile = __DIR__ . '/../config/outcome_mapping.php';
        $exampleFile = __DIR__ . '/../config/outcome_mapping.example.php';
        
        // Try actual config first, then example
        if (file_exists($configFile)) {
            $outcomeMapping = require $configFile;
        } elseif (file_exists($exampleFile)) {
            $outcomeMapping = require $exampleFile;
        } else {
            $outcomeMapping = [];
        }
        
        if (!is_array($outcomeMapping)) {
            $outcomeMapping = [];
        }
    }
    
    return $outcomeMapping;
}

/**
 * Translate Bitrix outcome ID to InVoice result code.
 * 
 * @param int|string $bitrixOutcomeId Bitrix outcome ID
 * @return string|null InVoice result code, or null if not found
 */
function translateOutcome($bitrixOutcomeId): ?string
{
    $mapping = readOutcomeMapping();
    $outcomeId = (int)$bitrixOutcomeId;
    
    return $mapping[$outcomeId] ?? null;
}

/**
 * Normalize caller phone for outbound submissions to InVoice.
 *
 * This must be applied only to the calling number (`caller`), not to workedCode.
 */
function normalizeOutboundCallerPhone(string $phone): string
{
    // Keep digits only.
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    // Convert 0039xxxxxxxxxx -> 39xxxxxxxxxx for unified handling.
    if (strpos($digits, '0039') === 0) {
        $digits = substr($digits, 2);
    }

    // Convert 39xxxxxxxxxx -> xxxxxxxxxx (local format without country code).
    if (strpos($digits, '39') === 0 && strlen($digits) > 10) {
        $digits = substr($digits, 2);
    }

    return $digits;
}

/**
 * Normalize date format for InVoice API (PRIMARY FORMAT).
 * Converts to format: yyyy/mm/dd HH:mm:ss (no timezone).
 * IMPORTANT: InVoice expects Italian time (CET/CEST), NOT UTC.
 * This function removes timezone offset but keeps the original time WITHOUT any conversion.
 * 
 * @param string $dateInput Date string in various formats
 * @return string Formatted date as yyyy/mm/dd HH:mm:ss (Italian time, no timezone)
 */
function normalizeDateForInvoice(string $dateInput): string
{
    if (empty($dateInput)) {
        // Use current time without timezone conversion
        return date('Y/m/d H:i:s');
    }
    
    // CRITICAL: Extract date and time EXACTLY as provided, NO timezone conversion
    // Remove timezone offset if present (e.g., "+03:00", "-05:00", or "Z")
    // but keep the exact time values as they appear in the string
    $dateInputCleaned = preg_replace('/[+-]\d{2}:\d{2}$/', '', $dateInput);
    $dateInputCleaned = preg_replace('/Z$/', '', $dateInputCleaned);
    
    // Extract date and time parts directly (NO DateTime parsing to avoid any timezone conversion)
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/', $dateInputCleaned, $matches)) {
        // Direct extraction: YYYY-MM-DD HH:mm:ss -> yyyy/mm/dd HH:mm:ss
        // Keep the exact hour, minute, second as they appear in the original string
        $year = $matches[1];
        $month = $matches[2];
        $day = $matches[3];
        $hour = $matches[4];
        $minute = $matches[5];
        $second = $matches[6];
        
        // Return in format yyyy/mm/dd HH:mm:ss (exact time, no conversion)
        return "{$year}/{$month}/{$day} {$hour}:{$minute}:{$second}";
    }
    
    // Fallback: try other formats
    if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/', $dateInputCleaned, $matches)) {
        // Already in yyyy/mm/dd format
        return "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}:{$matches[6]}";
    }
    
    // Last resort: use current time without timezone conversion
    error_log("BitrixWebhook: Failed to parse date '{$dateInput}', using current time");
    return date('Y/m/d H:i:s');
}

/**
 * Convert date to alternative format for InVoice API retry (FALLBACK FORMAT).
 * Converts to format: yyyy/MM/dd HH:mm:ss (no timezone).
 * IMPORTANT: Extracts time directly without any timezone conversion.
 * 
 * @param string $dateInput Date string in various formats
 * @return string Formatted date as yyyy/MM/dd HH:mm:ss (Italian time, no timezone)
 */
function normalizeDateForInvoiceAlternative(string $dateInput): string
{
    if (empty($dateInput)) {
        // Use current time without timezone conversion
        return date('Y/m/d H:i:s');
    }
    
    // CRITICAL: Extract date and time EXACTLY as provided, NO timezone conversion
    // Remove timezone offset if present (e.g., "+03:00", "-05:00", or "Z")
    // but keep the exact time values as they appear in the string
    $dateInputCleaned = preg_replace('/[+-]\d{2}:\d{2}$/', '', $dateInput);
    $dateInputCleaned = preg_replace('/Z$/', '', $dateInputCleaned);
    
    // Extract date and time parts directly (NO DateTime parsing to avoid any timezone conversion)
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/', $dateInputCleaned, $matches)) {
        // Direct extraction: YYYY-MM-DD HH:mm:ss -> yyyy/MM/dd HH:mm:ss
        // Keep the exact hour, minute, second as they appear in the original string
        $year = $matches[1];
        $month = $matches[2];
        $day = $matches[3];
        $hour = $matches[4];
        $minute = $matches[5];
        $second = $matches[6];
        
        // Return in format yyyy/MM/dd HH:mm:ss (exact time, no conversion)
        return "{$year}/{$month}/{$day} {$hour}:{$minute}:{$second}";
    }
    
    // Fallback: try other formats
    if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/', $dateInputCleaned, $matches)) {
        // Already in yyyy/MM/dd format
        return "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}:{$matches[6]}";
    }
    
    // Last resort: use current time without timezone conversion
    error_log("BitrixWebhook: Failed to parse date (alternative format) '{$dateInput}', using current time");
    return date('Y/m/d H:i:s');
}

/**
 * Valida e corregge END_TIME per chiamate outbound
 * 
 * @param string $startTime Orario di inizio chiamata (formato: 'Y-m-d H:i:s' o DateTime)
 * @param string|null $endTime Orario di fine chiamata da Bitrix (END_TIME) - può essere null
 * @param string $timezone Fuso orario (default: 'Europe/Rome')
 * @return array ['start_time' => DateTime, 'end_time' => DateTime|null, 'corrected' => bool, 'diff_minutes' => float]
 */
function validateAndCorrectCallTimes($startTime, $endTime = null, $timezone = 'Europe/Rome') {
    try {
        $tz = new \DateTimeZone($timezone);
        
        // Converti START_TIME in DateTime
        if ($startTime instanceof \DateTime) {
            $start = clone $startTime;
            $start->setTimezone($tz);
        } else {
            $start = new \DateTime($startTime, $tz);
        }
        
        // CORREZIONE: Bitrix invia orari con 2 ore in avanti, sottrai 2 ore
        $start->modify('-2 hours');
        
        // Se END_TIME è null o vuoto, usa START_TIME
        if (empty($endTime)) {
            return [
                'start_time' => $start,
                'end_time' => clone $start,
                'corrected' => false,
                'diff_minutes' => 0.0
            ];
        }
        
        // Converti END_TIME in DateTime
        if ($endTime instanceof \DateTime) {
            $end = clone $endTime;
            $end->setTimezone($tz);
        } else {
            $end = new \DateTime($endTime, $tz);
        }
        
        // CORREZIONE: Bitrix invia orari con 2 ore in avanti, sottrai 2 ore
        $end->modify('-2 hours');
        
        // Calcola la differenza in minuti
        $diff = $end->getTimestamp() - $start->getTimestamp();
        $diffMinutes = $diff / 60.0;
        
        // Se la differenza è maggiore di 60 minuti, correggi mantenendo i minuti originali
        if ($diffMinutes > 60.0) {
            // Mantieni i minuti e secondi di END_TIME, ma imposta l'ora a START_TIME + 1 ora
            $correctedEnd = clone $start;
            $correctedEnd->modify('+1 hour');
            
            // Copia minuti e secondi da END_TIME originale
            $originalMinutes = (int)$end->format('i');
            $originalSeconds = (int)$end->format('s');
            $correctedEnd->setTime((int)$correctedEnd->format('H'), $originalMinutes, $originalSeconds);
            
            error_log("BitrixWebhook: END_TIME corretto - differenza originale: {$diffMinutes} minuti, ora impostato a START_TIME + 1 ora con minuti originali ({$originalMinutes}:{$originalSeconds})");
            
            return [
                'start_time' => $start,
                'end_time' => $correctedEnd,
                'corrected' => true,
                'diff_minutes' => $diffMinutes
            ];
        }
        
        // Se la differenza è negativa (END_TIME prima di START_TIME), usa START_TIME
        if ($diffMinutes < 0) {
            error_log("BitrixWebhook: END_TIME è precedente a START_TIME, uso START_TIME come END_TIME");
            return [
                'start_time' => $start,
                'end_time' => clone $start,
                'corrected' => true,
                'diff_minutes' => $diffMinutes
            ];
        }
        
        // Tutto ok, ritorna i tempi originali
        return [
            'start_time' => $start,
            'end_time' => $end,
            'corrected' => false,
            'diff_minutes' => $diffMinutes
        ];
        
    } catch (\Exception $e) {
        error_log("BitrixWebhook: Errore nella validazione dei tempi: " . $e->getMessage());
        // In caso di errore, usa il timestamp corrente come fallback
        $tz = new \DateTimeZone($timezone);
        $now = new \DateTime('now', $tz);
        return [
            'start_time' => $now,
            'end_time' => clone $now,
            'corrected' => false,
            'diff_minutes' => 0.0
        ];
    }
}

/**
 * Calculate next value for custom field UF_CRM_1762188692.
 * 
 * Mapping logic:
 * null → 773
 * 773 → 775
 * 775 → 777
 * 777 → 779
 * 779 → 781
 * 781 → 783
 * 783 → 785
 * 785 → 787
 * 787 → stop (return null, don't update)
 * 
 * @param mixed $currentValue Current value of the field (can be null, empty, or integer)
 * @return int|null Next value to set, or null if should stop (787 or unknown value)
 */
function calculateNextCustomFieldValue($currentValue): ?int
{
    // Normalize current value: convert to integer or null
    $current = null;
    if ($currentValue !== null && $currentValue !== '' && $currentValue !== '0') {
        $current = (int)$currentValue;
    }
    
    // Mapping logic
    $mapping = [
        null => 773,
        773 => 775,
        775 => 777,
        777 => 779,
        779 => 781,
        781 => 783,
        783 => 785,
        785 => 787,
        // 787 → stop (return null)
    ];
    
    return $mapping[$current] ?? null;
}

/**
 * Send alert email when outcome is not found after all polling attempts.
 * 
 * @param string $requestId Request ID
 * @param int $dealId Deal ID
 * @param string $activityId Activity ID
 * @param string|null $invoiceIdAnagrafica InVoice anagrafica ID
 * @param string|null $invoiceIdCampagna InVoice campaign ID
 * @param array $pollResult Polling result
 * @return void
 * @throws \Exception If email sending fails
 */
function sendAlertEmail(string $requestId, int $dealId, string $activityId, ?string $invoiceIdAnagrafica, ?string $invoiceIdCampagna, array $pollResult): void
{
    $alertMail = $_ENV['ALERT_MAIL'] ?? getenv('ALERT_MAIL');
    
    if (empty($alertMail)) {
        error_log("BitrixWebhook [{$requestId}]: ALERT_MAIL not configured, cannot send alert email");
        throw new \Exception('ALERT_MAIL not configured in .env');
    }
    
    $subject = "[Bitrix-InVoice Bridge] Outcome not found after polling - Deal #{$dealId}";
    
    $body = "ALERT: Outcome not found after all polling attempts\n\n";
    $body .= "Request ID: {$requestId}\n";
    $body .= "Deal ID: {$dealId}\n";
    $body .= "Activity ID: {$activityId}\n";
    $body .= "InVoice Anagrafica ID: " . ($invoiceIdAnagrafica ?? 'N/A') . "\n";
    $body .= "InVoice Campaign ID: " . ($invoiceIdCampagna ?? 'N/A') . "\n";
    $body .= "Initial Outcome: " . ($pollResult['initial_outcome'] ?? 'empty') . "\n";
    $body .= "Final Outcome: " . ($pollResult['final_outcome'] ?? 'empty') . "\n";
    $body .= "Polling Attempts: {$pollResult['attempts']}\n";
    $body .= "Outcome Changed: " . ($pollResult['changed'] ? 'Yes' : 'No') . "\n";
    $body .= "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
    $body .= "The system attempted to poll the deal outcome 6 times with increasing intervals (60s, 120s, 240s, 480s, 960s, 1920s),\n";
    $body .= "but no valid outcome was found. Manual intervention may be required.\n\n";
    $body .= "Please check the deal in Bitrix24 and verify the outcome field (UF_CRM_1761843804).\n";
    
    $headers = [
        'From: Bitrix-InVoice Bridge <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
        'Reply-To: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: text/plain; charset=UTF-8',
    ];
    
    $success = mail($alertMail, $subject, $body, implode("\r\n", $headers));
    
    if (!$success) {
        throw new \Exception('Failed to send alert email (mail() returned false)');
    }
    
    error_log("BitrixWebhook [{$requestId}]: Alert email sent to {$alertMail}");
}

/**
 * Update deal custom field UF_CRM_1762188692 in background.
 * 
 * @param Bitrix24ApiClient $bitrix Bitrix API client
 * @param int $dealId Deal ID
 * @param string $requestId Request ID for logging
 * @return void
 */
function updateDealCustomField(Bitrix24ApiClient $bitrix, int $dealId, string $requestId): void
{
    $customFieldId = 'UF_CRM_1762188692';
    $logDir = __DIR__ . '/../storage/logs/bitrix-deal-update';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/' . date('Y-m-d') . '.log';
    
    $updateLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'request_id' => $requestId,
        'deal_id' => $dealId,
        'custom_field_id' => $customFieldId,
        'step' => 'start',
    ];
    
    try {
        // Step 1: Get current deal to read current value
        $updateLog['step'] = 'fetching_current_value';
        $dealResponse = $bitrix->getDeal($dealId);
        $dealData = $dealResponse['result'] ?? null;
        
        if (!is_array($dealData)) {
            $updateLog['error'] = 'Failed to fetch deal data';
            $updateLog['success'] = false;
            @file_put_contents($logFile, json_encode($updateLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
            error_log("BitrixWebhook [{$requestId}]: Failed to fetch deal {$dealId} for custom field update");
            return;
        }
        
        $currentValue = $dealData[$customFieldId] ?? null;
        $updateLog['current_value'] = $currentValue;
        $updateLog['current_value_type'] = gettype($currentValue);
        
        // Step 2: Calculate next value
        $updateLog['step'] = 'calculating_next_value';
        $nextValue = calculateNextCustomFieldValue($currentValue);
        $updateLog['next_value'] = $nextValue;
        
        // Step 3: Update if next value is not null (stop condition)
        if ($nextValue === null) {
            $updateLog['step'] = 'skipped';
            $updateLog['reason'] = 'Next value is null (stop condition: value is 787 or unknown)';
            $updateLog['success'] = true;
            @file_put_contents($logFile, json_encode($updateLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
            error_log("BitrixWebhook [{$requestId}]: Skipping custom field update - next value is null (current: {$currentValue})");
            return;
        }
        
        // Step 4: Update deal with new value
        $updateLog['step'] = 'updating_deal';
        $updateResponse = $bitrix->updateDeal($dealId, [
            $customFieldId => $nextValue
        ]);
        
        $updateLog['update_response'] = $updateResponse;
        $updateLog['step'] = 'completed';
        $updateLog['success'] = true;
        
        error_log("BitrixWebhook [{$requestId}]: Successfully updated deal {$dealId} custom field {$customFieldId}: {$currentValue} → {$nextValue}");
        
    } catch (\Exception $e) {
        $updateLog['step'] = 'error';
        $updateLog['error'] = $e->getMessage();
        $updateLog['trace'] = $e->getTraceAsString();
        $updateLog['success'] = false;
        error_log("BitrixWebhook [{$requestId}]: Error updating deal {$dealId} custom field: " . $e->getMessage());
    }
    
    // Write log
    @file_put_contents($logFile, json_encode($updateLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
}

/**
 * Poll deal outcome field for changes.
 * 
 * @param Bitrix24ApiClient $bitrix Bitrix API client
 * @param int $dealId Deal ID
 * @param string $esitoFieldId Custom field ID for outcome
 * @param mixed $initialOutcome Initial outcome value
 * @param array $delays Array of delays in seconds between attempts (e.g., [60, 120, 240, 480, 960, 1920])
 * @return array ['outcome' => mixed, 'changed' => bool, 'attempts' => int, 'final_outcome' => mixed]
 */
function pollDealOutcome(Bitrix24ApiClient $bitrix, int $dealId, string $esitoFieldId, $initialOutcome, array $delays): array
{
    $maxAttempts = count($delays);
    $pollLog = [
        'initial_outcome' => $initialOutcome,
        'attempts' => 0,
        'changed' => false,
        'final_outcome' => $initialOutcome,
    ];
    
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $pollLog['attempts'] = $attempt;
        $delay = $delays[$attempt - 1] ?? 60; // Default to 60 if delay not specified
        
        $pollLog['attempt_' . $attempt] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'sleep_before' => $delay,
        ];
        
        // Sleep before checking (except first attempt which happens immediately after initial read)
        if ($attempt > 1) {
            sleep($delay);
        }
        
        try {
            // Fetch updated deal
            $dealResponse = $bitrix->getDeal($dealId);
            $dealData = $dealResponse['result'] ?? null;
            
            if (!is_array($dealData)) {
                $pollLog['attempt_' . $attempt]['error'] = 'Failed to fetch deal data';
                error_log("BitrixWebhook: Poll attempt {$attempt} failed - no deal data");
                continue;
            }
            
            $currentOutcome = $dealData[$esitoFieldId] ?? null;
            $pollLog['attempt_' . $attempt]['outcome'] = $currentOutcome;
            $pollLog['attempt_' . $attempt]['outcome_changed'] = ($currentOutcome !== $initialOutcome);
            
            // Check if outcome changed (and is not empty)
            if ($currentOutcome !== $initialOutcome && $currentOutcome !== null && $currentOutcome !== '') {
                $pollLog['changed'] = true;
                $pollLog['final_outcome'] = $currentOutcome;
                $pollLog['changed_at_attempt'] = $attempt;
                error_log("BitrixWebhook: Outcome changed at attempt {$attempt}: {$initialOutcome} -> {$currentOutcome}");
                break; // Stop polling if outcome found
            }
            
        } catch (\Exception $e) {
            $pollLog['attempt_' . $attempt]['error'] = $e->getMessage();
            error_log("BitrixWebhook: Poll attempt {$attempt} failed: " . $e->getMessage());
        }
    }
    
    // If no change detected, keep initial outcome
    if (!$pollLog['changed']) {
        $pollLog['final_outcome'] = $initialOutcome;
    }
    
    return $pollLog;
}

// ---------------------------------------------------------------------
// Extract Bitrix API credentials and fetch activity/deal data
// ---------------------------------------------------------------------

$response = [
    'ok' => true,
    'request_id' => $requestId,
    'received_at' => date('c'),
    'processed' => false,
];

// Dedicated log file for Bitrix data retrieval
$dataLogDir = __DIR__ . '/../storage/logs/bitrix-data-retrieval';
if (!is_dir($dataLogDir)) {
    mkdir($dataLogDir, 0755, true);
}
$bitrixDataLogFile = $dataLogDir . '/' . date('Y-m-d') . '.log';

// Capture timestamp at first check (when webhook arrives)
$firstCheckTimestamp = time();
$firstCheckDateTime = date('Y-m-d H:i:s', $firstCheckTimestamp);

$dataLog = [
    'timestamp' => $firstCheckDateTime,
    'request_id' => $requestId,
    'step' => 'start',
    'first_check_timestamp' => $firstCheckTimestamp,
    'first_check_datetime' => $firstCheckDateTime,
];

try {
    // Extract Bitrix event and activity ID
    $bitrixEvent = $decoded['event'] ?? null;
    $activityId = $decoded['data']['FIELDS']['ID'] ?? null;
    
    $dataLog['bitrix_event'] = $bitrixEvent;
    $dataLog['activity_id'] = $activityId;
    
    // Only process ONCRMACTIVITYADD events
    if ($bitrixEvent !== 'ONCRMACTIVITYADD') {
        $dataLog['step'] = 'event_filtered';
        $dataLog['note'] = "Event '{$bitrixEvent}' is not ONCRMACTIVITYADD, skipping";
        $dataLog['success'] = true;
        @file_put_contents($bitrixDataLogFile, json_encode($dataLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
        
        $response['note'] = "Event '{$bitrixEvent}' not processed (only ONCRMACTIVITYADD)";
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    if (empty($activityId)) {
        $dataLog['error'] = 'Missing activity ID in payload';
        $dataLog['decoded_payload'] = $decoded;
        @file_put_contents($bitrixDataLogFile, json_encode($dataLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
        
        $response['note'] = 'Missing activity ID in payload';
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Extract Bitrix API credentials from payload
    $bitrixDomain = $decoded['auth']['domain'] ?? null;
    $bitrixClientEndpoint = $decoded['auth']['client_endpoint'] ?? null;
    $bitrixApplicationToken = $decoded['auth']['application_token'] ?? null;
    $bitrixMemberId = $decoded['auth']['member_id'] ?? null;
    
    $dataLog['bitrix_domain'] = $bitrixDomain;
    $dataLog['bitrix_client_endpoint'] = $bitrixClientEndpoint;
    $dataLog['bitrix_member_id'] = $bitrixMemberId;
    $dataLog['bitrix_token_preview'] = $bitrixApplicationToken ? (substr($bitrixApplicationToken, 0, 6) . '***') : null;
    
    if (empty($bitrixClientEndpoint) || empty($bitrixApplicationToken)) {
        $dataLog['error'] = 'Missing Bitrix API credentials (client_endpoint or application_token)';
        @file_put_contents($bitrixDataLogFile, json_encode($dataLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
        
        $response['note'] = 'Missing Bitrix API credentials';
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Build Bitrix webhook URL
    // Use BITRIX24_WEBHOOK_URL from .env (already configured for Bitrix API calls)
    // This is the webhook URL we use to call Bitrix REST API
    $bitrixWebhookUrl = $_ENV['BITRIX24_WEBHOOK_URL'] ?? getenv('BITRIX24_WEBHOOK_URL');
    
    if (empty($bitrixWebhookUrl)) {
        $dataLog['error'] = 'BITRIX24_WEBHOOK_URL not configured in .env';
        @file_put_contents($bitrixDataLogFile, json_encode($dataLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
        
        $response['note'] = 'BITRIX24_WEBHOOK_URL not configured';
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    $webhookUrl = rtrim($bitrixWebhookUrl, '/');
    
    $dataLog['step'] = 'building_webhook_url';
    $dataLog['webhook_url_source'] = 'from_env';
    $dataLog['webhook_url'] = preg_replace('/\/[^\/]+\/[^\/]+\/$/', '/***/***/', $webhookUrl); // Mask token in log
    
    // Initialize Bitrix API client
    $bitrix = new Bitrix24ApiClient($webhookUrl);
    
    // Step 1: Get activity details using crm.activity.get
    $dataLog['step'] = 'fetching_activity';
    $dataLog['api_call'] = 'crm.activity.get';
    $activityData = null;
    try {
        $activityResponse = $bitrix->getActivity((int)$activityId);
        $activityData = $activityResponse['result'] ?? null;
        $dataLog['activity_fetch_success'] = true;
        $dataLog['activity_response'] = $activityResponse;
        $dataLog['activity_data'] = $activityData;
    } catch (\Exception $e) {
        $dataLog['activity_fetch_error'] = $e->getMessage();
        $dataLog['activity_fetch_success'] = false;
        error_log("BitrixWebhook [{$requestId}]: Failed to fetch activity {$activityId}: " . $e->getMessage());
        
        // Stop processing if we can't fetch activity
        $dataLog['step'] = 'error';
        $dataLog['success'] = false;
        @file_put_contents($bitrixDataLogFile, json_encode($dataLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
        
        $response['note'] = 'Failed to fetch activity from Bitrix';
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Verify OWNER_TYPE_ID: must be 2 (DEAL)
    $dataLog['step'] = 'verifying_owner_type';
    $ownerTypeId = isset($activityData['OWNER_TYPE_ID']) ? (int)$activityData['OWNER_TYPE_ID'] : null;
    $ownerId = isset($activityData['OWNER_ID']) ? (int)$activityData['OWNER_ID'] : null;
    
    $dataLog['activity_owner_type_id'] = $ownerTypeId;
    $dataLog['activity_owner_id'] = $ownerId;
    $dataLog['owner_type_name'] = [
        1 => 'Contact',
        2 => 'Deal',
        3 => 'Company',
        4 => 'Lead',
    ][$ownerTypeId] ?? 'Unknown';
    
    if ($ownerTypeId !== 2) {
        $dataLog['step'] = 'owner_type_mismatch';
        $dataLog['note'] = "OWNER_TYPE_ID is {$ownerTypeId} (not 2=DEAL), skipping";
        $dataLog['success'] = true;
        @file_put_contents($bitrixDataLogFile, json_encode($dataLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
        
        $response['note'] = "Activity is not linked to a Deal (OWNER_TYPE_ID={$ownerTypeId})";
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Extract deal ID (OWNER_ID when OWNER_TYPE_ID = 2)
    $dealId = $ownerId;
    $dataLog['step'] = 'extracted_deal_id';
    $dataLog['deal_id'] = $dealId;
    
    // Step 2: Get deal details using crm.deal.get
    $dataLog['step'] = 'fetching_deal';
    $dataLog['api_call'] = 'crm.deal.get';
    $dealData = null;
    try {
        $dealResponse = $bitrix->getDeal($dealId);
        $dealData = $dealResponse['result'] ?? null;
        $dataLog['deal_fetch_success'] = true;
        $dataLog['deal_response'] = $dealResponse;
    } catch (\Exception $e) {
        $dataLog['deal_fetch_error'] = $e->getMessage();
        $dataLog['deal_fetch_success'] = false;
        error_log("BitrixWebhook [{$requestId}]: Failed to fetch deal {$dealId}: " . $e->getMessage());
        
        // Stop processing if we can't fetch deal
        $dataLog['step'] = 'error';
        $dataLog['success'] = false;
        @file_put_contents($bitrixDataLogFile, json_encode($dataLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
        
        $response['note'] = 'Failed to fetch deal from Bitrix';
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Step 3: Verify pipeline (CATEGORY_ID) against PIPELINE_ACTIVITY
    $dataLog['step'] = 'verifying_pipeline';
    $dealCategoryId = isset($dealData['CATEGORY_ID']) ? (int)$dealData['CATEGORY_ID'] : null;
    
    // Read PIPELINE_ACTIVITY from environment (with debug logging)
    $pipelineActivityRaw = $_ENV['PIPELINE_ACTIVITY'] ?? getenv('PIPELINE_ACTIVITY');
    $dataLog['pipeline_activity_raw'] = $pipelineActivityRaw;
    $dataLog['pipeline_activity_raw_type'] = gettype($pipelineActivityRaw);
    $dataLog['pipeline_activity_in_env'] = isset($_ENV['PIPELINE_ACTIVITY']);
    $dataLog['pipeline_activity_in_getenv'] = getenv('PIPELINE_ACTIVITY') !== false;
    
    // Convert to integer if not empty, otherwise null
    if ($pipelineActivityRaw !== null && $pipelineActivityRaw !== '' && $pipelineActivityRaw !== '0') {
        $pipelineActivity = (int)$pipelineActivityRaw;
    } else {
        $pipelineActivity = null;
    }
    
    $dataLog['deal_category_id'] = $dealCategoryId;
    $dataLog['pipeline_activity_configured'] = $pipelineActivity;
    $dataLog['pipeline_activity_configured_type'] = gettype($pipelineActivity);
    $dataLog['pipeline_match'] = ($pipelineActivity !== null && $dealCategoryId === $pipelineActivity);
    
    if ($pipelineActivity !== null && $dealCategoryId !== $pipelineActivity) {
        $dataLog['step'] = 'pipeline_mismatch';
        $dataLog['note'] = "Deal CATEGORY_ID ({$dealCategoryId}) does not match PIPELINE_ACTIVITY ({$pipelineActivity}), skipping";
        $dataLog['success'] = true;
        @file_put_contents($bitrixDataLogFile, json_encode($dataLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
        
        $response['note'] = "Deal not in target pipeline (CATEGORY_ID={$dealCategoryId}, expected={$pipelineActivity})";
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Pipeline matches: update custom field UF_CRM_1762188692 ONCE (only on first webhook call, not during polling)
    // This is executed immediately when the webhook arrives, before responding to Bitrix
    $dataLog['step'] = 'pipeline_match_confirmed';
    $dataLog['custom_field_update_started'] = true;
    
    try {
        updateDealCustomField($bitrix, $dealId, $requestId);
        $dataLog['custom_field_update_completed'] = true;
    } catch (\Exception $e) {
        $dataLog['custom_field_update_error'] = $e->getMessage();
        error_log("BitrixWebhook [{$requestId}]: Error updating custom field: " . $e->getMessage());
        // Don't stop processing if custom field update fails
    }
    
    // Step 4: Extract InVoice custom fields (ID Anagrafica Invoice and ID Campagna Invoice)
    $dataLog['step'] = 'extracting_invoice_fields';
    $fieldConfig = Bitrix24ApiClient::getBitrixFieldConfig();
    
    // Extract all custom fields for reference
    $customFields = [];
    if (is_array($dealData)) {
        foreach ($dealData as $key => $value) {
            if (strpos($key, 'UF_CRM_') === 0) {
                $customFields[$key] = $value;
            }
        }
    }
    $dataLog['deal_custom_fields'] = $customFields;
    $dataLog['field_config_used'] = $fieldConfig;
    
    // Extract InVoice-specific fields (focus on ID Anagrafica and ID Campagna)
    $invoiceIdAnagrafica = $dealData[$fieldConfig['id_anagrafica'] ?? 'UF_CRM_INVOICE_ID_ANAGRAFICA'] ?? null;
    $invoiceIdCampagna = $dealData[$fieldConfig['id_campagna'] ?? 'UF_CRM_INVOICE_CAMPAIGN_ID'] ?? null;
    
    $invoiceFields = [
        'id_anagrafica' => $invoiceIdAnagrafica,
        'id_campagna' => $invoiceIdCampagna,
        'id_config_campagna' => $dealData[$fieldConfig['id_config_campagna'] ?? 'UF_CRM_INVOICE_CAMPAIGN_CONFIG_ID'] ?? null,
        'data_inizio' => $dealData[$fieldConfig['data_inizio'] ?? 'UF_CRM_INVOICE_DATA_INIZIO'] ?? null,
        'data_fine' => $dealData[$fieldConfig['data_fine'] ?? 'UF_CRM_INVOICE_DATA_FINE'] ?? null,
    ];
    $dataLog['invoice_fields'] = $invoiceFields;
    $dataLog['invoice_id_anagrafica'] = $invoiceIdAnagrafica;
    $dataLog['invoice_id_campagna'] = $invoiceIdCampagna;
    
    // Step 5: Extract activity outcome and deal status
    $dataLog['step'] = 'extracting_outcome_and_status';
    
    // Activity outcome/result (multiple possible fields)
    $activityOutcome = $activityData['RESULT'] ?? null;
    $activityStatus = $activityData['STATUS'] ?? null;
    $activitySubject = $activityData['SUBJECT'] ?? null;
    $activityTypeId = $activityData['TYPE_ID'] ?? null;
    $activityDirection = $activityData['DIRECTION'] ?? null;
    
    // Extract START_TIME and END_TIME from Bitrix activity for call duration validation
    // Bitrix may provide these fields in various formats (START_TIME, END_TIME, CREATED, COMPLETED)
    $bitrixStartTime = $activityData['START_TIME'] ?? $activityData['CREATED'] ?? null;
    $bitrixEndTime = $activityData['END_TIME'] ?? $activityData['COMPLETED'] ?? null;
    
    // Store in globals for use in background processing (polling phase)
    $GLOBALS['_BITRIX_START_TIME'] = $bitrixStartTime;
    $GLOBALS['_BITRIX_END_TIME'] = $bitrixEndTime;
    
    // Deal status
    $dealStageId = $dealData['STAGE_ID'] ?? null;
    $dealTitle = $dealData['TITLE'] ?? null;
    
    $dataLog['activity_outcome'] = [
        'RESULT' => $activityOutcome,
        'STATUS' => $activityStatus,
        'SUBJECT' => $activitySubject,
        'TYPE_ID' => $activityTypeId,
        'DIRECTION' => $activityDirection,
    ];
    $dataLog['deal_status'] = [
        'STAGE_ID' => $dealStageId,
        'TITLE' => $dealTitle,
        'CATEGORY_ID' => $dealCategoryId,
    ];
    
    // Step 6: Read initial outcome from custom field
    $dataLog['step'] = 'reading_initial_outcome';
    $esitoFieldId = $fieldConfig['esito'] ?? 'UF_CRM_1761843804';
    $initialOutcome = $dealData[$esitoFieldId] ?? null;
    
    $dataLog['esito_field_id'] = $esitoFieldId;
    $dataLog['initial_outcome'] = $initialOutcome;
    $dataLog['initial_outcome_type'] = gettype($initialOutcome);
    
    // Summary for easy reading
    $dataLog['summary'] = [
        'activity_id' => $activityId,
        'deal_id' => $dealId,
        'deal_category_id' => $dealCategoryId,
        'pipeline_match' => ($pipelineActivity === null || $dealCategoryId === $pipelineActivity),
        'invoice_id_anagrafica' => $invoiceIdAnagrafica,
        'invoice_id_campagna' => $invoiceIdCampagna,
        'initial_outcome' => $initialOutcome,
        'ready_for_invoice' => !empty($invoiceIdAnagrafica) && !empty($invoiceIdCampagna) && ($pipelineActivity === null || $dealCategoryId === $pipelineActivity),
    ];
    
    // Store activity_id in dataLog for use in background processing
    $dataLog['activity_id'] = $activityId;
    
    $dataLog['step'] = 'ready_for_polling';
    $dataLog['success'] = true;
    
} catch (\Exception $e) {
    $dataLog['step'] = 'error';
    $dataLog['error'] = $e->getMessage();
    $dataLog['trace'] = $e->getTraceAsString();
    $dataLog['success'] = false;
    error_log("BitrixWebhook [{$requestId}]: Error processing: " . $e->getMessage());
}

// Write initial data log
@file_put_contents($bitrixDataLogFile, json_encode($dataLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);

// Respond immediately to webhook (don't wait for polling)
$response['note'] = 'Processing started. Polling outcome in background.';
$response['processed'] = true;
http_response_code(200);
header('Content-Type: application/json');
echo json_encode($response);

// Close connection and continue processing in background
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Fallback: flush output buffers
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

// ---------------------------------------------------------------------
// Background processing: Poll outcome and send to InVoice
// ---------------------------------------------------------------------
// NOTE: Custom field update is done BEFORE this point (when webhook arrives),
// so it's executed only once per activity, not during polling
// ---------------------------------------------------------------------

// Only proceed if we have all required data
if (isset($dataLog['success']) && $dataLog['success'] === true && 
    isset($invoiceIdAnagrafica) && !empty($invoiceIdAnagrafica) &&
    isset($invoiceIdCampagna) && !empty($invoiceIdCampagna) &&
    isset($dealId) && isset($bitrix) && isset($esitoFieldId)) {
    
    $pollLog = [
        'request_id' => $requestId,
        'timestamp' => date('Y-m-d H:i:s'),
        'step' => 'polling_started',
        'deal_id' => $dealId,
        'esito_field_id' => $esitoFieldId,
        'initial_outcome' => $initialOutcome ?? null,
    ];
    
    try {
        // Poll for outcome changes with exponential backoff: 60, 120, 240, 480, 960, 1920 seconds
        $pollDelays = [60, 120, 240, 480, 960, 1920]; // 6 attempts with doubling intervals
        $pollResult = pollDealOutcome($bitrix, $dealId, $esitoFieldId, $initialOutcome, $pollDelays);
        $pollLog['poll_result'] = $pollResult;
        $pollLog['poll_delays_used'] = $pollDelays;
        
        // Determine final outcome to use
        $finalOutcome = $pollResult['final_outcome'];
        $pollLog['final_outcome'] = $finalOutcome;
        $pollLog['outcome_changed'] = $pollResult['changed'];
        
        // Translate outcome to InVoice result code
        $pollLog['step'] = 'translating_outcome';
        $invoiceResultCode = null;
        
        // Skip if outcome is empty or null
        if (empty($finalOutcome) || $finalOutcome === '' || $finalOutcome === null) {
            $pollLog['error'] = 'Outcome is empty, skipping InVoice submission';
            $pollLog['note'] = 'No outcome value to translate. Activity may not have been completed yet.';
            $pollLog['skipped'] = true;
            error_log("BitrixWebhook [{$requestId}]: Skipping InVoice submission - outcome is empty");
            
            // After 6 attempts, if still no outcome, send alert email
            if ($pollResult['attempts'] >= 6) {
                $pollLog['step'] = 'sending_alert_email';
                try {
                    // Get activity_id from dataLog (stored before background processing)
                    $activityIdForAlert = $dataLog['activity_id'] ?? $dataLog['summary']['activity_id'] ?? 'unknown';
                    sendAlertEmail($requestId, $dealId, $activityIdForAlert, $invoiceIdAnagrafica, $invoiceIdCampagna, $pollResult);
                    $pollLog['alert_email_sent'] = true;
                } catch (\Exception $e) {
                    $pollLog['alert_email_error'] = $e->getMessage();
                    error_log("BitrixWebhook [{$requestId}]: Failed to send alert email: " . $e->getMessage());
                }
            }
        } else {
            $invoiceResultCode = translateOutcome($finalOutcome);
            $pollLog['translation'] = [
                'bitrix_outcome' => $finalOutcome,
                'invoice_result_code' => $invoiceResultCode,
                'translation_found' => $invoiceResultCode !== null,
            ];
            
            if ($invoiceResultCode === null) {
                $pollLog['error'] = 'No translation found for outcome: ' . $finalOutcome;
                error_log("BitrixWebhook [{$requestId}]: No translation found for outcome {$finalOutcome}");
            }
        }
        
        if ($invoiceResultCode !== null) {
            // Build worked payload and send to InVoice
            $pollLog['step'] = 'building_invoice_payload';
            
            // Extract and validate call times from Bitrix activity data
            $bitrixStartTime = $GLOBALS['_BITRIX_START_TIME'] ?? null;
            $bitrixEndTime = $GLOBALS['_BITRIX_END_TIME'] ?? null;
            
            // Use validated times if available, otherwise fallback to first check timestamp
            if (!empty($bitrixStartTime)) {
                // Validate and correct call times (ensures END_TIME is not more than 1 hour after START_TIME)
                $validatedTimes = validateAndCorrectCallTimes($bitrixStartTime, $bitrixEndTime, 'Europe/Rome');
                
                $startDateTime = $validatedTimes['start_time'];
                $endDateTime = $validatedTimes['end_time'];
                
                // Format dates for Invoice API: yyyy/mm/dd HH:mm:ss (no timezone conversion, exact time)
                $workedDate = $startDateTime->format('Y/m/d H:i:s');
                $workedEndDate = $endDateTime ? $endDateTime->format('Y/m/d H:i:s') : $startDateTime->format('Y/m/d H:i:s');
                
                $pollLog['date_source'] = 'bitrix_activity';
                $pollLog['bitrix_start_time'] = $bitrixStartTime;
                $pollLog['bitrix_end_time'] = $bitrixEndTime;
                $pollLog['validated_start'] = $startDateTime->format('Y-m-d H:i:s');
                $pollLog['validated_end'] = $endDateTime ? $endDateTime->format('Y-m-d H:i:s') : null;
                $pollLog['time_corrected'] = $validatedTimes['corrected'];
                $pollLog['diff_minutes'] = $validatedTimes['diff_minutes'];
            } else {
                // Fallback: use timestamp from first check (when webhook arrived)
                // Format: yyyy/MM/dd HH:mm:ss (no timezone conversion, exact time from first check)
                $workedDate = date('Y/m/d H:i:s', $firstCheckTimestamp);
                $workedEndDate = date('Y/m/d H:i:s', $firstCheckTimestamp);
                
                $pollLog['date_source'] = 'first_check_timestamp_fallback';
                $pollLog['first_check_timestamp'] = $firstCheckTimestamp;
                $pollLog['first_check_datetime'] = date('Y-m-d H:i:s', $firstCheckTimestamp);
                $pollLog['note'] = 'Bitrix START_TIME/END_TIME not available, using webhook arrival time';
            }
            
            // Caller must always be handled as STRING to preserve leading 0 (e.g. 079...).
            // Fetch the real outbound line the operator used (PORTAL_NUMBER) via voximplant.statistic.get,
            // linked by CRM_ACTIVITY_ID. Falls back to the fixed line only if the lookup fails or is empty
            // (e.g. non-Voximplant calls, or telephony scope not available).
            $callerPortalNumber = null;
            try {
                $callerPortalNumber = $bitrix->getCallerPortalNumber((int)$activityId);
            } catch (\Exception $e) {
                error_log("BitrixWebhook [{$requestId}]: Failed to fetch PORTAL_NUMBER for activity {$activityId}: " . $e->getMessage());
            }

            if ($callerPortalNumber !== null) {
                $caller = (string) normalizeOutboundCallerPhone($callerPortalNumber);
                $pollLog['caller_source'] = 'voximplant_portal_number';
                $pollLog['caller_portal_number_raw'] = $callerPortalNumber;
            } else {
                $caller = (string) normalizeOutboundCallerPhone('0797008058');
                $pollLog['caller_source'] = 'fixed_fallback';
                error_log("BitrixWebhook [{$requestId}]: PORTAL_NUMBER not found for activity {$activityId}, using fixed fallback caller number");
            }
            $pollLog['caller_resolved'] = $caller;

            // IMPORTANT: workedCode must be the contact's phone number (not the result code)
            // According to InVoice API docs: "workedCode: il numero di telefono del contatto lavorato"
            $contactPhone = null;
            $contactId = $dealData['CONTACT_ID'] ?? null;
            
            if (!empty($contactId)) {
                try {
                    $contactResponse = $bitrix->getContact((int)$contactId);
                    $contactData = $contactResponse['result'] ?? [];
                    
                    // Bitrix stores phone in PHONE field (can be array or string)
                    if (isset($contactData['PHONE'])) {
                        if (is_array($contactData['PHONE'])) {
                            // PHONE is array of objects with VALUE field
                            $contactPhone = $contactData['PHONE'][0]['VALUE'] ?? null;
                        } else {
                            $contactPhone = $contactData['PHONE'];
                        }
                    }
                    
                    // Clean phone number preserving original called-number format.
                    if ($contactPhone) {
                        $contactPhone = preg_replace('/[^0-9+]/', '', $contactPhone);
                    }
                    
                    $pollLog['contact_fetch'] = [
                        'contact_id' => $contactId,
                        'phone_found' => !empty($contactPhone),
                        'phone_raw' => $contactData['PHONE'] ?? null,
                        'phone_cleaned' => $contactPhone,
                    ];
                } catch (\Exception $e) {
                    error_log("BitrixWebhook [{$requestId}]: Failed to fetch contact {$contactId}: " . $e->getMessage());
                    $pollLog['contact_fetch_error'] = $e->getMessage();
                }
            }
            
            // Fallback: try to extract phone from caller string or use placeholder
            if (empty($contactPhone)) {
                // Try to extract phone from caller string (e.g., "In uscita a +39 328 011 4601")
                if (preg_match('/(\+?\d{10,15})/', $caller, $matches)) {
                    $contactPhone = $matches[1];
                    $pollLog['phone_extracted_from_caller'] = true;
                } else {
                    // Last resort: use placeholder (InVoice might reject this)
                    $contactPhone = '0000000000';
                    error_log("BitrixWebhook [{$requestId}]: WARNING - No contact phone found, using placeholder");
                    $pollLog['phone_placeholder_used'] = true;
                }
            }
            
            // Get id_config_campagna for mapping
            $idConfigCampagna = $dealData[$fieldConfig['id_config_campagna'] ?? 'UF_CRM_INVOICE_CAMPAIGN_CONFIG_ID'] ?? null;
            
            $workedInput = [
                'contactId' => (int)$invoiceIdAnagrafica,
                'campaignId' => (int)$invoiceIdCampagna,
                'workedDate' => $workedDate,
                'workedEndDate' => $workedEndDate,
                'caller' => (string)$caller,
                'workedCode' => $contactPhone, // PHONE NUMBER (required by InVoice API)
                'resultCode' => $invoiceResultCode, // Outcome code (e.g., "D001")
                'workedType' => 'O', // O = Outbound (must be O, E, V, B, or C)
            ];
            
            $pollLog['worked_input'] = $workedInput;
            
            // Build payload using mapper
            $pollLog['step'] = 'submitting_to_invoice';
            $workedPayload = BitrixToInvoiceWorkedMapper::buildWorkedPayload($workedInput);
            $pollLog['worked_payload'] = $workedPayload;
            
            // Initialize InVoice client
            $invoiceBaseUrl = requireEnv('INVOICE_API_BASE_URL');
            $invoiceClientId = requireEnv('INVOICE_CLIENT_ID');
            $invoiceJwk = requireEnv('INVOICE_JWK_JSON');
            
            $tokenCacheDir = __DIR__ . '/../storage/logs/invoice-token-cache';
            $invoice = new InvoiceApiClient($invoiceBaseUrl, $tokenCacheDir);
            $invoice->setCredentials($invoiceClientId, $invoiceJwk);
            
            // Submit to InVoice (with retry using alternative date format)
            $invoiceResponse = null;
            $invoiceException = null;
            $dateFormatUsed = 'primary'; // Track which format was used
            
            try {
                // First attempt with primary format: yyyy/mm/dd HH:mm:ss
                $invoiceResponse = $invoice->submitWorkedContact($workedPayload);
                $pollLog['invoice_response'] = $invoiceResponse;
                $pollLog['date_format_used'] = 'primary (yyyy/mm/dd HH:mm:ss)';
                $pollLog['step'] = 'completed';
                $pollLog['success'] = true;
                
                error_log("BitrixWebhook [{$requestId}]: Successfully submitted to InVoice with primary date format (yyyy/mm/dd). Outcome: {$finalOutcome} -> {$invoiceResultCode}");
            } catch (\Exception $firstException) {
                // Check if error might be related to date format
                $errorMessage = $firstException->getMessage();
                $isDateFormatError = (
                    stripos($errorMessage, 'date') !== false ||
                    stripos($errorMessage, 'format') !== false ||
                    stripos($errorMessage, 'json non valido') !== false ||
                    stripos($errorMessage, 'invalid') !== false ||
                    stripos($errorMessage, 'data chiamata') !== false ||
                    stripos($errorMessage, 'non valida') !== false ||
                    stripos($errorMessage, 'non valido') !== false ||
                    stripos($errorMessage, 'valida') !== false
                );
                
                $pollLog['first_attempt_error'] = $errorMessage;
                $pollLog['first_attempt_is_date_error'] = $isDateFormatError;
                
                // Retry with alternative date format if it might be a date format issue
                if ($isDateFormatError) {
                    error_log("BitrixWebhook [{$requestId}]: First attempt failed, retrying with alternative date format (yyyy/MM/dd HH:mm:ss)");
                    
                    try {
                        // Convert dates to alternative format: dd-mm-yyyy HH:mm:ss
                        // Use validated times if available, otherwise fallback to first check timestamp
                        if (!empty($bitrixStartTime)) {
                            // Use the same validated times but in alternative format (yyyy/MM/dd HH:mm:ss)
                            $validatedTimes = validateAndCorrectCallTimes($bitrixStartTime, $bitrixEndTime, 'Europe/Rome');
                            $startDateTime = $validatedTimes['start_time'];
                            $endDateTime = $validatedTimes['end_time'];
                            $workedDateAlt = $startDateTime->format('Y/m/d H:i:s');
                            $workedEndDateAlt = $endDateTime ? $endDateTime->format('Y/m/d H:i:s') : $startDateTime->format('Y/m/d H:i:s');
                        } else {
                            // Fallback: use first check timestamp
                            $workedDateAlt = date('d-m-Y H:i:s', $firstCheckTimestamp);
                            $workedEndDateAlt = date('d-m-Y H:i:s', $firstCheckTimestamp);
                        }
                        
                        // Rebuild payload with alternative date format
                        $workedInputAlt = $workedInput;
                        $workedInputAlt['workedDate'] = $workedDateAlt;
                        $workedInputAlt['workedEndDate'] = $workedEndDateAlt;
                        
                        $workedPayloadAlt = BitrixToInvoiceWorkedMapper::buildWorkedPayload($workedInputAlt);
                        $pollLog['worked_payload_retry'] = $workedPayloadAlt;
                        
                        // Retry submission
                        $invoiceResponse = $invoice->submitWorkedContact($workedPayloadAlt);
                        $pollLog['invoice_response'] = $invoiceResponse;
                        $pollLog['date_format_used'] = 'alternative (yyyy/MM/dd HH:mm:ss)';
                        $pollLog['step'] = 'completed';
                        $pollLog['success'] = true;
                        
                        error_log("BitrixWebhook [{$requestId}]: Successfully submitted to InVoice with alternative date format (yyyy/MM/dd). Outcome: {$finalOutcome} -> {$invoiceResultCode}");
                    } catch (\Exception $retryException) {
                        // Both attempts failed
                        $pollLog['retry_attempt_error'] = $retryException->getMessage();
                        $pollLog['invoice_error'] = $retryException->getMessage();
                        $pollLog['invoice_error_trace'] = $retryException->getTraceAsString();
                        $pollLog['invoice_error_file'] = $retryException->getFile();
                        $pollLog['invoice_error_line'] = $retryException->getLine();
                        error_log("BitrixWebhook [{$requestId}]: Both date format attempts failed. First: {$errorMessage}. Retry: " . $retryException->getMessage());
                        throw $retryException; // Re-throw to be caught by outer catch
                    }
                } else {
                    // Not a date format error, don't retry
                    $pollLog['invoice_error'] = $errorMessage;
                    $pollLog['invoice_error_trace'] = $firstException->getTraceAsString();
                    $pollLog['invoice_error_file'] = $firstException->getFile();
                    $pollLog['invoice_error_line'] = $firstException->getLine();
                    error_log("BitrixWebhook [{$requestId}]: InVoice submission failed (not a date format error): " . $errorMessage);
                    throw $firstException; // Re-throw to be caught by outer catch
                }
            }
            
        }
        
    } catch (\Exception $e) {
        $pollLog['step'] = 'error';
        $pollLog['error'] = $e->getMessage();
        $pollLog['trace'] = $e->getTraceAsString();
        $pollLog['success'] = false;
        error_log("BitrixWebhook [{$requestId}]: Error in background processing: " . $e->getMessage());
    }
    
    // Write polling log
    @file_put_contents($bitrixDataLogFile, "POLLING_RESULT: " . json_encode($pollLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('=', 100) . "\n\n", FILE_APPEND | LOCK_EX);
    
} else {
    error_log("BitrixWebhook [{$requestId}]: Skipping background processing - missing required data");
}

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
    // Normalize dates to InVoice format: YYYY/MM/DD HH:mm:ss (no timezone)
    $workedDateRaw = $decoded['workedDate'] ?? date('Y-m-d H:i:s');
    $workedEndDateRaw = $decoded['workedEndDate'] ?? date('Y-m-d H:i:s');
    $workedInput = [
        'contactId' => $invoiceContactId,
        'campaignId' => $invoiceCampaignId,
        'workedDate' => normalizeDateForInvoice($workedDateRaw),
        'workedEndDate' => normalizeDateForInvoice($workedEndDateRaw),
        'caller' => '0797008058', // Always fixed value
    ];
    
    // IMPORTANT: workedCode must be the contact's phone number (required by InVoice API)
    // If not provided in decoded payload, we need to fetch it from Bitrix
    if (isset($decoded['workedCode'])) {
        $workedInput['workedCode'] = $decoded['workedCode'];
    } else {
        // TODO: Fetch contact phone from Bitrix if not provided
        // For now, this will cause an error if workedCode is missing
        error_log("BitrixWebhook [{$requestId}]: WARNING - workedCode (phone number) not provided in payload");
    }
    
    // Add outcome mapping if available
    if ($bitrixOutcome !== null) {
        $workedInput['bitrixOutcome'] = $bitrixOutcome;
        if ($idConfigCampagna !== null) {
            $workedInput['idConfigCampagna'] = $idConfigCampagna;
        }
    }
    
    // Explicit values override mapping if provided
    if (isset($decoded['resultCode'])) {
        $workedInput['resultCode'] = $decoded['resultCode'];
    }
    
    // workedType must be O, E, V, B, or C (default to O for Outbound)
    if (isset($decoded['workedType'])) {
        $workedInput['workedType'] = $decoded['workedType'];
    } else {
        $workedInput['workedType'] = 'O'; // Default to O (Outbound)
    }

    $workedPayload = BitrixToInvoiceWorkedMapper::buildWorkedPayload($workedInput);

    // InVoice client
$invoiceBaseUrl = $_ENV['INVOICE_API_BASE_URL'] ?? getenv('INVOICE_API_BASE_URL');
    $invoiceClientId = $_ENV['INVOICE_CLIENT_ID'] ?? getenv('INVOICE_CLIENT_ID');
    $invoiceJwk = $_ENV['INVOICE_JWK_JSON'] ?? getenv('INVOICE_JWK_JSON');

    $invoice = new InvoiceApiClient($invoiceBaseUrl, __DIR__ . '/../storage/logs/invoice-token-cache');
    if (!empty($invoiceClientId) && !empty($invoiceJwk)) {
        $invoice->setCredentials((string)$invoiceClientId, (string)$invoiceJwk);
    }

    // Submit with retry using alternative date format if needed
    $workedResult = null;
    try {
        // First attempt with primary format: yyyy/MM/dd HH:mm:ss
        $workedResult = $invoice->submitWorkedContact($workedPayload);
        error_log("BitrixWebhook [{$requestId}]: Successfully submitted to InVoice with primary date format");
    } catch (\Exception $firstException) {
        // Check if error might be related to date format
        $errorMessage = $firstException->getMessage();
        $isDateFormatError = (
            stripos($errorMessage, 'date') !== false ||
            stripos($errorMessage, 'format') !== false ||
            stripos($errorMessage, 'json non valido') !== false ||
            stripos($errorMessage, 'invalid') !== false ||
            stripos($errorMessage, 'data chiamata') !== false ||
            stripos($errorMessage, 'non valida') !== false ||
            stripos($errorMessage, 'non valido') !== false ||
            stripos($errorMessage, 'valida') !== false
        );
        
        // Retry with alternative date format if it might be a date format issue
        if ($isDateFormatError) {
            error_log("BitrixWebhook [{$requestId}]: First attempt failed, retrying with alternative date format (dd-mm-yyyy HH:mm:ss)");
            
            // Convert dates to alternative format: dd-mm-yyyy HH:mm:ss (with dashes)
            $workedDateAlt = normalizeDateForInvoiceAlternative($workedDateRaw);
            $workedEndDateAlt = normalizeDateForInvoiceAlternative($workedEndDateRaw);
            
            // Rebuild payload with alternative date format
            $workedInputAlt = $workedInput;
            $workedInputAlt['workedDate'] = $workedDateAlt;
            $workedInputAlt['workedEndDate'] = $workedEndDateAlt;
            
            $workedPayloadAlt = BitrixToInvoiceWorkedMapper::buildWorkedPayload($workedInputAlt);
            
            // Retry submission
            $workedResult = $invoice->submitWorkedContact($workedPayloadAlt);
            error_log("BitrixWebhook [{$requestId}]: Successfully submitted to InVoice with alternative date format");
        } else {
            // Not a date format error, re-throw
            throw $firstException;
        }
    }

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
