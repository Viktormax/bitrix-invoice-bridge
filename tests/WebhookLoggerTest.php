<?php

namespace BitrixInvoiceBridge\Tests;

use BitrixInvoiceBridge\WebhookLogger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WebhookLogger.
 * 
 * These tests verify the logging functionality, sensitive data masking,
 * body truncation, and large body file handling.
 */
class WebhookLoggerTest extends TestCase
{
    private string $testLogDir;

    protected function setUp(): void
    {
        $this->testLogDir = sys_get_temp_dir() . '/webhook_logger_test_' . uniqid();
        mkdir($this->testLogDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up test log directory and subdirectories
        if (is_dir($this->testLogDir)) {
            // Remove large-bodies subdirectory
            $largeBodiesDir = $this->testLogDir . '/large-bodies';
            if (is_dir($largeBodiesDir)) {
                array_map('unlink', glob($largeBodiesDir . '/*'));
                rmdir($largeBodiesDir);
            }
            // Remove log files
            array_map('unlink', glob($this->testLogDir . '/*'));
            rmdir($this->testLogDir);
        }
    }

    /**
     * Test that WebhookLogger can be instantiated.
     */
    public function testLoggerInstantiation(): void
    {
        $logger = new WebhookLogger($this->testLogDir);
        $this->assertInstanceOf(WebhookLogger::class, $logger);
    }

    /**
     * Test that logRequest creates a log file.
     */
    public function testLogRequestCreatesFile(): void
    {
        $logger = new WebhookLogger($this->testLogDir);
        $requestId = 'test-request-' . uniqid();
        
        $requestData = [
            'method' => 'POST',
            'path' => '/test',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => '{"test": "data"}',
        ];
        
        $logFile = $logger->logRequest($requestId, $requestData);
        
        $this->assertFileExists($logFile);
        $this->assertStringContainsString($requestId, file_get_contents($logFile));
    }

    /**
     * Test that sensitive headers are masked in logs.
     */
    public function testSensitiveHeaderMasking(): void
    {
        $logger = new WebhookLogger($this->testLogDir);
        $requestId = 'test-request-' . uniqid();
        
        $requestData = [
            'method' => 'POST',
            'path' => '/test',
            'headers' => [
                'Authorization' => 'Bearer secret-token-12345',
                'Cookie' => 'session=abc123',
                'api-auth-token' => 'my-secret-token-67890',
            ],
            'raw_body' => '',
        ];
        
        $logFile = $logger->logRequest($requestId, $requestData);
        $logContent = file_get_contents($logFile);
        
        // Verify sensitive headers are masked
        $this->assertStringContainsString('[MASKED]', $logContent);
        // Verify api-auth-token is partially masked (first 6 chars + ***)
        $this->assertStringNotContainsString('my-secret-token-67890', $logContent);
        $this->assertStringContainsString('my-sec***', $logContent);
    }

    /**
     * Test that log files are created with daily rotation (date-based naming).
     */
    public function testDailyLogRotation(): void
    {
        $logger = new WebhookLogger($this->testLogDir);
        $requestId = 'test-request-' . uniqid();
        
        $requestData = [
            'method' => 'POST',
            'path' => '/test',
            'headers' => [],
            'raw_body' => '',
        ];
        
        $logFile = $logger->logRequest($requestId, $requestData);
        
        // Verify log file name contains today's date
        $expectedDate = date('Y-m-d');
        $this->assertStringContainsString($expectedDate, basename($logFile));
    }

    /**
     * Test body truncation when body exceeds maxBodySize.
     */
    public function testBodyTruncation(): void
    {
        // Create logger with small maxBodySize (1KB)
        $maxBodySize = 1024;
        $logger = new WebhookLogger($this->testLogDir, $maxBodySize);
        $requestId = 'test-request-' . uniqid();
        
        // Create body larger than maxBodySize
        $largeBody = str_repeat('A', $maxBodySize + 500);
        
        $requestData = [
            'method' => 'POST',
            'path' => '/test',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => $largeBody,
        ];
        
        $logFile = $logger->logRequest($requestId, $requestData);
        $logContent = file_get_contents($logFile);
        
        // Verify truncation message in log
        $this->assertStringContainsString('TRUNCATED', $logContent);
        $this->assertStringContainsString('full body saved separately', $logContent);
        
        // Verify only first maxBodySize bytes are in main log
        $bodyInLog = $this->extractBodyFromLog($logContent);
        $this->assertLessThanOrEqual($maxBodySize, strlen($bodyInLog));
    }

    /**
     * Test that large bodies are saved to separate file in large-bodies/ directory.
     */
    public function testLargeBodyFileSaving(): void
    {
        $maxBodySize = 1024;
        $logger = new WebhookLogger($this->testLogDir, $maxBodySize);
        $requestId = 'test-request-' . uniqid();
        
        // Create body larger than maxBodySize
        $largeBody = str_repeat('B', $maxBodySize + 1000);
        $expectedFullSize = strlen($largeBody);
        
        $requestData = [
            'method' => 'POST',
            'path' => '/test',
            'headers' => [],
            'raw_body' => $largeBody,
        ];
        
        $logger->logRequest($requestId, $requestData);
        
        // Verify large body file exists
        $largeBodyFile = $this->testLogDir . '/large-bodies/' . $requestId . '.body';
        $this->assertFileExists($largeBodyFile, 'Large body file should be created in large-bodies/ directory');
        
        // Verify full body is saved (not truncated)
        $savedBody = file_get_contents($largeBodyFile);
        $this->assertEquals($expectedFullSize, strlen($savedBody), 'Full body should be saved without truncation');
        $this->assertEquals($largeBody, $savedBody, 'Saved body should match original');
    }

    /**
     * Test JSON decoding and pretty-printing in logs.
     */
    public function testJSONDecodingInLogs(): void
    {
        $logger = new WebhookLogger($this->testLogDir);
        $requestId = 'test-request-' . uniqid();
        
        $jsonPayload = [
            'event' => 'LEAD_AVAILABLE',
            'eventDate' => '2024-01-15 10:30:00',
            'slice' => [
                ['id' => 12345],
                ['id' => 67890],
            ],
        ];
        
        $requestData = [
            'method' => 'POST',
            'path' => '/webhook',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode($jsonPayload),
        ];
        
        $logFile = $logger->logRequest($requestId, $requestData);
        $logContent = file_get_contents($logFile);
        
        // Verify JSON is decoded and pretty-printed
        $this->assertStringContainsString('JSON_DECODED:', $logContent);
        $this->assertStringContainsString('LEAD_AVAILABLE', $logContent);
        $this->assertStringContainsString('"id": 12345', $logContent);
    }

    /**
     * Test JSON decode error handling for invalid JSON.
     */
    public function testInvalidJSONHandling(): void
    {
        $logger = new WebhookLogger($this->testLogDir);
        $requestId = 'test-request-' . uniqid();
        
        $requestData = [
            'method' => 'POST',
            'path' => '/webhook',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => '{"invalid": json}', // Invalid JSON
        ];
        
        $logFile = $logger->logRequest($requestId, $requestData);
        $logContent = file_get_contents($logFile);
        
        // Verify JSON decode error is logged
        $this->assertStringContainsString('JSON_DECODE_ERROR:', $logContent);
    }

    /**
     * Test remote IP detection with X-Forwarded-For header.
     */
    public function testRemoteIPDetection(): void
    {
        $logger = new WebhookLogger($this->testLogDir);
        $requestId = 'test-request-' . uniqid();
        
        $requestData = [
            'method' => 'POST',
            'path' => '/webhook',
            'headers' => [
                'X-Forwarded-For' => '192.168.1.100, 10.0.0.1',
                'X-Real-IP' => '10.0.0.2',
            ],
            'raw_body' => '',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        
        $logFile = $logger->logRequest($requestId, $requestData);
        $logContent = file_get_contents($logFile);
        
        // Verify X-Forwarded-For IP is used (first IP in chain)
        $this->assertStringContainsString('192.168.1.100', $logContent);
        $this->assertStringContainsString('via X-Forwarded-For', $logContent);
    }

    /**
     * Test remote IP detection with X-Real-IP header (fallback).
     */
    public function testRemoteIPWithXRealIP(): void
    {
        $logger = new WebhookLogger($this->testLogDir);
        $requestId = 'test-request-' . uniqid();
        
        $requestData = [
            'method' => 'POST',
            'path' => '/webhook',
            'headers' => [
                'X-Real-IP' => '10.0.0.50',
            ],
            'raw_body' => '',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        
        $logFile = $logger->logRequest($requestId, $requestData);
        $logContent = file_get_contents($logFile);
        
        // Verify X-Real-IP is used
        $this->assertStringContainsString('10.0.0.50', $logContent);
        $this->assertStringContainsString('via X-Real-IP', $logContent);
    }

    /**
     * Test that log entries include all required forensic information.
     */
    public function testForensicLoggingCompleteness(): void
    {
        $logger = new WebhookLogger($this->testLogDir);
        $requestId = 'test-request-' . uniqid();
        $timestamp = date('c');
        
        $requestData = [
            'method' => 'POST',
            'path' => '/invoice-webhook.php',
            'query_string' => 'test=1',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'TestAgent/1.0',
            ],
            'content_type' => 'application/json',
            'content_length' => '100',
            'user_agent' => 'TestAgent/1.0',
            'raw_body' => '{"test": "data"}',
            'REMOTE_ADDR' => '192.168.1.1',
        ];
        
        $logFile = $logger->logRequest($requestId, $requestData);
        $logContent = file_get_contents($logFile);
        
        // Verify all forensic fields are present
        $this->assertStringContainsString("REQUEST_ID: {$requestId}", $logContent);
        $this->assertStringContainsString('TIMESTAMP:', $logContent);
        $this->assertStringContainsString('METHOD: POST', $logContent);
        $this->assertStringContainsString('PATH: /invoice-webhook.php', $logContent);
        $this->assertStringContainsString('QUERY_STRING: test=1', $logContent);
        $this->assertStringContainsString('REMOTE_IP:', $logContent);
        $this->assertStringContainsString('HEADERS:', $logContent);
        $this->assertStringContainsString('CONTENT_TYPE:', $logContent);
        $this->assertStringContainsString('CONTENT_LENGTH:', $logContent);
        $this->assertStringContainsString('USER_AGENT:', $logContent);
        $this->assertStringContainsString('RAW_BODY:', $logContent);
    }

    /**
     * Helper: Extract body content from log file.
     */
    private function extractBodyFromLog(string $logContent): string
    {
        // Find RAW_BODY section
        if (preg_match('/RAW_BODY(?: \(first \d+ bytes\))?:\s*\n(.*?)(?=\n[A-Z_]+:|$)/s', $logContent, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
}
