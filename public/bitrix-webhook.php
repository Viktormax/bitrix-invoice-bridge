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

// Quick reject non-POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$requestId = Uuid::uuid4()->toString();
header('X-Request-ID: ' . $requestId);

// Read headers (case-insensitive)
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = [];
foreach ($headers as $k => $v) {
    $headersLower[strtolower((string)$k)] = $v;
}

// Shared secret auth
$expected = $_ENV['BITRIX_WEBHOOK_TOKEN'] ?? getenv('BITRIX_WEBHOOK_TOKEN');
$provided = $headersLower['x-api-auth-token'] ?? null;
if (empty($expected)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'request_id' => $requestId, 'error' => 'Server misconfigured: BITRIX_WEBHOOK_TOKEN not set']);
    exit;
}
if (!is_string($provided) || !hash_equals((string)$expected, (string)$provided)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'request_id' => $requestId, 'error' => 'Unauthorized']);
    exit;
}

// Logging (forensic)
$logDir = $_ENV['LOG_DIR'] ?? getenv('LOG_DIR') ?: (__DIR__ . '/../storage/logs/invoice-webhook');
try {
    $logger = new WebhookLogger($logDir);
} catch (\Throwable $e) {
    $logger = null;
    error_log("BitrixWebhook [{$requestId}]: Failed to init logger: " . $e->getMessage());
}

$rawBody = file_get_contents('php://input') ?: '';
$decoded = null;
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
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
            'json_decoded' => (json_last_error() === JSON_ERROR_NONE) ? $decoded : null,
            'json_error' => (json_last_error() === JSON_ERROR_NONE) ? null : json_last_error_msg(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log("BitrixWebhook [{$requestId}]: Failed to write forensic log: " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Processing (minimal + safe)
// ---------------------------------------------------------------------

/**
 * Expected processing strategy:
 * - Determine deal ID (and activity ID) from payload
 * - Fetch deal via Bitrix API
 * - Gate by pipeline (CATEGORY_ID)
 * - Read InVoice IDs from custom fields (contactId/campaignId)
 * - Build worked payload and submit to InVoice
 *
 * Because Bitrix payload formats vary, this endpoint logs everything and returns 200 quickly.
 */

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

