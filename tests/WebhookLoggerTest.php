<?php

namespace BitrixInvoiceBridge\Tests;

use BitrixInvoiceBridge\WebhookLogger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WebhookLogger.
 * 
 * These tests verify the logging functionality and sensitive data masking.
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
        // Clean up test log directory
        if (is_dir($this->testLogDir)) {
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
}
