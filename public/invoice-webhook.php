<?php

/**
 * InVoice webhook endpoint for receiving LEAD_AVAILABLE and other events.
 * This endpoint must respond quickly (within 3-5 seconds) as per InVoice timeout requirements.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixInvoiceBridge\WebhookLogger;
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
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
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

    if (empty($authToken) || $authToken !== $expectedToken) {
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
    $logger = new WebhookLogger($logDir);
    $logger->logRequest($requestId, $requestData);

    // Success response - respond quickly to meet InVoice timeout requirements
    $response['ok'] = true;
    http_response_code(200);
    
} catch (Throwable $e) {
    // Log error but still respond quickly
    error_log("Webhook error [{$requestId}]: " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'Internal server error';
    $response['error_type'] = get_class($e);
}

// Ensure response is sent quickly (critical for InVoice timeout: 3s connection, 5s read)
$response['processing_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

// Set headers and send response immediately
header('Content-Type: application/json; charset=utf-8');
header('X-Request-ID: ' . $requestId);

// Use compact JSON (no pretty print) for faster response
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Flush output immediately to ensure InVoice receives response quickly
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}
