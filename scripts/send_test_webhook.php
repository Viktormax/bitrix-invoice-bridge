<?php

/**
 * Test script to send a sample LEAD_AVAILABLE webhook to the local endpoint.
 * 
 * Usage:
 *   php scripts/send_test_webhook.php [webhook-url] [auth-token]
 * 
 * Example:
 *   php scripts/send_test_webhook.php http://localhost/public/invoice-webhook.php your-secret-token
 */

$webhookUrl = $argv[1] ?? 'http://localhost/public/invoice-webhook.php';
$authToken = $argv[2] ?? 'your-secret-token-here';

// Sample LEAD_AVAILABLE payload based on InVoice API documentation v1.4.0
// Format: { "event": "LEAD_AVAILABLE", "eventDate": "YYYY-MM-DD HH:mm:ss", "slice": [ { "id": 123 } ] }
$payload = [
    'event' => 'LEAD_AVAILABLE',
    'eventDate' => date('Y-m-d H:i:s'), // Format: YYYY-MM-DD HH:mm:ss
    'slice' => [
        [
            'id' => 3706919, // Lot ID (integer)
        ]
    ]
];

$jsonPayload = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "Sending test webhook to: {$webhookUrl}\n";
echo "Payload:\n{$jsonPayload}\n\n";

// Initialize cURL
$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'api-auth-token: ' . $authToken,
        'User-Agent: TestScript/1.0',
    ],
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "ERROR: {$error}\n";
    exit(1);
}

echo "HTTP Status: {$httpCode}\n";
echo "Response:\n{$response}\n";

if ($httpCode >= 200 && $httpCode < 300) {
    echo "\n✓ Test webhook sent successfully!\n";
    exit(0);
} else {
    echo "\n✗ Test webhook failed with HTTP {$httpCode}\n";
    exit(1);
}
