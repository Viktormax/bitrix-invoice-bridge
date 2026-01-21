<?php

/**
 * Script to fetch and display result codes mapping from InVoice API for a specific campaign.
 * 
 * This script helps understand which workedCode and resultCode values are available
 * for each campaign, which is needed for the Bitrix -> InVoice reverse flow.
 * 
 * Usage:
 *   php scripts/fetch_result_codes.php [id_config_campagna]
 * 
 * Example:
 *   php scripts/fetch_result_codes.php 65704
 * 
 * The script will:
 * 1. Load OAuth2 credentials from .env file
 * 2. Authenticate with InVoice API
 * 3. Fetch result codes for the specified campaign
 * 4. Display them in a readable format
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BitrixInvoiceBridge\InvoiceApiClient;

// ---------------------------------------------------------------------
// Parse command line arguments
// ---------------------------------------------------------------------

$idConfigCampagna = isset($argv[1]) ? (int)$argv[1] : null;

if ($idConfigCampagna === null || $idConfigCampagna <= 0) {
    echo "ERROR: Please provide a valid id_config_campagna (campaign configuration ID)\n";
    echo "\n";
    echo "Usage:\n";
    echo "  php scripts/fetch_result_codes.php <id_config_campagna>\n";
    echo "\n";
    echo "Example:\n";
    echo "  php scripts/fetch_result_codes.php 65704\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Load environment variables
// ---------------------------------------------------------------------

$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    echo "ERROR: .env file not found at: {$envFile}\n";
    echo "Please create a .env file with InVoice OAuth2 credentials.\n";
    exit(1);
}

// Simple .env parser (handles multi-line JSON values)
$envVars = [];
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$currentKey = null;
$currentValue = '';
$inJson = false;
$braceCount = 0;

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || strpos($line, '#') === 0) {
        continue;
    }
    
    if ($inJson) {
        $currentValue .= $line;
        $braceCount += substr_count($line, '{') - substr_count($line, '}');
        $braceCount += substr_count($line, '[') - substr_count($line, ']');
        if ($braceCount <= 0) {
            $envVars[$currentKey] = $currentValue;
            $currentKey = null;
            $currentValue = '';
            $inJson = false;
            $braceCount = 0;
        }
        continue;
    }
    
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Remove quotes if present
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        
        // Check if it's a JSON value (starts with { or [)
        if (substr($value, 0, 1) === '{' || substr($value, 0, 1) === '[') {
            $currentKey = $key;
            $currentValue = $value;
            $braceCount = substr_count($value, '{') - substr_count($value, '}');
            $braceCount += substr_count($value, '[') - substr_count($value, ']');
            if ($braceCount > 0) {
                $inJson = true;
            } else {
                $envVars[$key] = $value;
            }
        } else {
            $envVars[$key] = $value;
        }
    }
}

// Set environment variables
foreach ($envVars as $key => $value) {
    $_ENV[$key] = $value;
    putenv("{$key}={$value}");
}

// ---------------------------------------------------------------------
// Initialize InVoice API client
// ---------------------------------------------------------------------

$baseUrl = $_ENV['INVOICE_API_BASE_URL'] ?? getenv('INVOICE_API_BASE_URL') ?: 'https://enel.in-voice.it';
$clientId = $_ENV['INVOICE_CLIENT_ID'] ?? getenv('INVOICE_CLIENT_ID');
$jwkJson = $_ENV['INVOICE_JWK_JSON'] ?? getenv('INVOICE_JWK_JSON');

if (empty($clientId) || empty($jwkJson)) {
    echo "ERROR: Missing InVoice OAuth2 credentials in .env file\n";
    echo "Required variables:\n";
    echo "  - INVOICE_API_BASE_URL (optional, default: https://enel.in-voice.it)\n";
    echo "  - INVOICE_CLIENT_ID\n";
    echo "  - INVOICE_JWK_JSON\n";
    exit(1);
}

$tokenCacheDir = __DIR__ . '/../storage/logs/invoice-token-cache';
if (!is_dir($tokenCacheDir)) {
    mkdir($tokenCacheDir, 0755, true);
}

$invoiceClient = new InvoiceApiClient($baseUrl, $tokenCacheDir);
$invoiceClient->setCredentials($clientId, $jwkJson);

// ---------------------------------------------------------------------
// Fetch result codes
// ---------------------------------------------------------------------

echo "Fetching result codes for campaign configuration ID: {$idConfigCampagna}\n";
echo "InVoice API Base URL: {$baseUrl}\n";
echo str_repeat('-', 80) . "\n\n";

try {
    $resultCodes = $invoiceClient->getResultCodes($idConfigCampagna);
    
    if (empty($resultCodes)) {
        echo "No result codes found for campaign configuration ID: {$idConfigCampagna}\n";
        exit(0);
    }
    
    // Display result codes in a readable format
    echo "RESULT CODES MAPPING FOR CAMPAIGN CONFIGURATION ID: {$idConfigCampagna}\n";
    echo str_repeat('=', 80) . "\n\n";
    
    // Check if resultCodes is an array of objects or a single object
    if (isset($resultCodes[0]) && is_array($resultCodes[0])) {
        // Array of result codes
        $codes = $resultCodes;
    } elseif (isset($resultCodes['result_codes']) && is_array($resultCodes['result_codes'])) {
        // Wrapped in result_codes key
        $codes = $resultCodes['result_codes'];
    } elseif (is_array($resultCodes)) {
        // Direct array
        $codes = $resultCodes;
    } else {
        // Single object or unknown format
        $codes = [$resultCodes];
    }
    
    // Display table header
    printf("%-20s %-20s %-40s\n", "WORKED CODE", "RESULT CODE", "DESCRIPTION");
    echo str_repeat('-', 80) . "\n";
    
    foreach ($codes as $code) {
        $workedCode = $code['workedCode'] ?? $code['worked_code'] ?? $code['code'] ?? 'N/A';
        $resultCode = $code['resultCode'] ?? $code['result_code'] ?? $code['result'] ?? 'N/A';
        $description = $code['description'] ?? $code['desc'] ?? $code['name'] ?? 'N/A';
        
        // Truncate description if too long
        if (strlen($description) > 38) {
            $description = substr($description, 0, 35) . '...';
        }
        
        printf("%-20s %-20s %-40s\n", $workedCode, $resultCode, $description);
    }
    
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "Total result codes: " . count($codes) . "\n";
    echo "\n";
    
    // Also output as JSON for programmatic use
    echo "JSON Output (for reference):\n";
    echo json_encode($resultCodes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    
} catch (\Exception $e) {
    echo "ERROR: Failed to fetch result codes\n";
    echo "Error message: " . $e->getMessage() . "\n";
    echo "Error type: " . get_class($e) . "\n";
    if ($e->getFile()) {
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
    exit(1);
}
