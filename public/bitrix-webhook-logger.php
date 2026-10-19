<?php

/**
 * Bitrix Webhook Logger Endpoint
 * 
 * Questo endpoint è dedicato esclusivamente al logging di tutte le chiamate webhook ricevute da Bitrix.
 * Non esegue alcuna elaborazione dei dati, registra solo tutte le informazioni della chiamata.
 * 
 * Può essere configurato come webhook separato in Bitrix per monitorare tutte le chiamate.
 * 
 * SECURITY:
 * - Accepts POST only
 * - Opzionalmente può richiedere autenticazione (configurabile via .env)
 */

declare(strict_types=1);

// Generate unique request ID
$requestId = uniqid('log_', true);
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

// Load WebhookCallListener
require_once __DIR__ . '/../alerts/WebhookCallListener.php';

$webhookListener = new WebhookCallListener(__DIR__ . '/../alerts/webhook-calls.log');

// Quick reject non-POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $webhookListener->logEvent($requestId, 'rejected', 'Method Not Allowed (not POST)', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    ]);
    
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed', 'request_id' => $requestId]);
    exit;
}

// Optional authentication check (if BITRIX_WEBHOOK_LOGGER_TOKEN is set in .env)
$expectedToken = $_ENV['BITRIX_WEBHOOK_LOGGER_TOKEN'] ?? getenv('BITRIX_WEBHOOK_LOGGER_TOKEN');
if (!empty($expectedToken)) {
    $providedToken = $headersLower['x-api-auth-token'] ?? null;
    
    // If not in header, try to get from parsed body (Bitrix format)
    if (empty($providedToken) && is_array($decoded) && isset($decoded['auth']['application_token'])) {
        $providedToken = $decoded['auth']['application_token'];
    }
    
    if (empty($providedToken) || !hash_equals((string)$expectedToken, (string)$providedToken)) {
        $webhookListener->logEvent($requestId, 'auth_failed', 'Authentication failed', [
            'provided_token' => $providedToken ?: 'null',
            'expected_token_length' => strlen($expectedToken),
            'provided_from' => isset($headersLower['x-api-auth-token']) ? 'header' : (isset($decoded['auth']['application_token']) ? 'body' : 'none'),
        ]);
        
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'request_id' => $requestId, 'error' => 'Unauthorized']);
        exit;
    }
    
    $webhookListener->logEvent($requestId, 'auth_success', 'Authentication successful');
}

// Log all call information
$webhookListener->logFullCall(
    $requestId,
    $headers,
    $rawBody,
    $decoded,
    $contentType,
    !empty($expectedToken) ? 'authenticated' : 'no_auth_required'
);

// Respond immediately (this is a logging-only endpoint)
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'request_id' => $requestId,
    'message' => 'Call logged successfully',
    'logged_at' => date('Y-m-d H:i:s'),
]);
