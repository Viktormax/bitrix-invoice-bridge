<?php

namespace BitrixInvoiceBridge;

use Ramsey\Uuid\Uuid;

/**
 * Forensic webhook logger with sensitive data masking and daily log rotation.
 */
class WebhookLogger
{
    private string $logDir;
    private int $maxBodySize;
    private array $sensitiveHeaders;

    public function __construct(string $logDir, int $maxBodySize = 1048576) // 1MB default
    {
        $this->logDir = rtrim($logDir, '/');
        $this->maxBodySize = $maxBodySize;
        $this->sensitiveHeaders = ['authorization', 'cookie', 'set-cookie', 'x-api-key'];
        
        // Ensure log directory exists
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Log a complete webhook request with forensic details.
     *
     * @param string $requestId Unique request identifier
     * @param array $requestData Complete request information
     * @return string The log file path where entry was written
     */
    public function logRequest(string $requestId, array $requestData): string
    {
        $logFile = $this->getLogFile();
        $logEntry = $this->buildLogEntry($requestId, $requestData);
        
        // Use file locking to prevent corruption
        $fp = fopen($logFile, 'a');
        if ($fp && flock($fp, LOCK_EX)) {
            fwrite($fp, $logEntry . "\n");
            flock($fp, LOCK_UN);
            fclose($fp);
        } else {
            error_log("Failed to acquire lock or open log file: {$logFile}");
        }

        // If body is too large, save it to a separate file
        if (isset($requestData['raw_body']) && strlen($requestData['raw_body']) > $this->maxBodySize) {
            $this->saveLargeBody($requestId, $requestData['raw_body']);
        }

        return $logFile;
    }

    /**
     * Get the log file path for today.
     */
    private function getLogFile(): string
    {
        $date = date('Y-m-d');
        return $this->logDir . '/' . $date . '.log';
    }

    /**
     * Build a complete log entry with all request details.
     */
    private function buildLogEntry(string $requestId, array $data): string
    {
        $lines = [];
        $lines[] = str_repeat('=', 80);
        $lines[] = "REQUEST_ID: {$requestId}";
        $lines[] = "TIMESTAMP: " . date('c');
        $lines[] = "METHOD: " . ($data['method'] ?? 'UNKNOWN');
        $lines[] = "PATH: " . ($data['path'] ?? '');
        $lines[] = "QUERY_STRING: " . ($data['query_string'] ?? '');
        
        // Remote IP (considering proxies)
        $remoteIp = $this->getRemoteIp($data);
        $lines[] = "REMOTE_IP: {$remoteIp}";
        if (isset($data['REMOTE_ADDR'])) {
            $lines[] = "REMOTE_ADDR: {$data['REMOTE_ADDR']}";
        }
        
        // Headers (masked if sensitive)
        $lines[] = "HEADERS:";
        foreach ($data['headers'] ?? [] as $name => $value) {
            $maskedValue = $this->maskSensitiveValue($name, $value);
            $lines[] = "  {$name}: {$maskedValue}";
        }
        
        // Content info
        $lines[] = "CONTENT_TYPE: " . ($data['content_type'] ?? 'not set');
        $lines[] = "CONTENT_LENGTH: " . ($data['content_length'] ?? 'not set');
        $lines[] = "USER_AGENT: " . ($data['user_agent'] ?? 'not set');
        
        // Raw body (truncated if too large)
        $rawBody = $data['raw_body'] ?? '';
        $bodySize = strlen($rawBody);
        $isTruncated = $bodySize > $this->maxBodySize;
        
        $lines[] = "RAW_BODY_SIZE: {$bodySize} bytes" . ($isTruncated ? " (TRUNCATED - full body saved separately)" : '');
        if (!$isTruncated) {
            $lines[] = "RAW_BODY:";
            $lines[] = $this->indent($rawBody);
        } else {
            $lines[] = "RAW_BODY (first {$this->maxBodySize} bytes):";
            $lines[] = $this->indent(substr($rawBody, 0, $this->maxBodySize));
        }
        
        // JSON decoded (if valid)
        if (!empty($rawBody)) {
            $jsonData = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $lines[] = "JSON_DECODED:";
                $lines[] = $this->indent(json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $lines[] = "JSON_DECODE_ERROR: " . json_last_error_msg();
            }
        }
        
        $lines[] = str_repeat('=', 80);
        
        return implode("\n", $lines);
    }

    /**
     * Get remote IP considering proxy headers.
     */
    private function getRemoteIp(array $data): string
    {
        $headers = array_change_key_case($data['headers'] ?? [], CASE_LOWER);
        
        // Check X-Forwarded-For (can contain multiple IPs)
        if (isset($headers['x-forwarded-for'])) {
            $ips = explode(',', $headers['x-forwarded-for']);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip . ' (via X-Forwarded-For)';
            }
        }
        
        // Check X-Real-IP
        if (isset($headers['x-real-ip'])) {
            $ip = trim($headers['x-real-ip']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip . ' (via X-Real-IP)';
            }
        }
        
        // Fallback to REMOTE_ADDR
        return $data['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Mask sensitive header values and token-like strings.
     */
    private function maskSensitiveValue(string $headerName, string $value): string
    {
        $headerLower = strtolower($headerName);
        
        // Mask known sensitive headers
        if (in_array($headerLower, $this->sensitiveHeaders)) {
            return '[MASKED]';
        }
        
        // For api-auth-token, show first 6 chars + mask
        if ($headerLower === 'api-auth-token') {
            if (strlen($value) > 6) {
                return substr($value, 0, 6) . '***';
            }
            return '***';
        }
        
        // Mask token-like strings (long alphanumeric strings)
        if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value)) {
            return substr($value, 0, 6) . '***';
        }
        
        return $value;
    }

    /**
     * Save large body to a separate file.
     */
    private function saveLargeBody(string $requestId, string $body): void
    {
        $largeBodyFile = $this->logDir . '/large-bodies/' . $requestId . '.body';
        $largeBodyDir = dirname($largeBodyFile);
        
        if (!is_dir($largeBodyDir)) {
            mkdir($largeBodyDir, 0755, true);
        }
        
        file_put_contents($largeBodyFile, $body, LOCK_EX);
    }

    /**
     * Log event processing (API calls, results, errors).
     *
     * @param string $requestId Original webhook request ID
     * @param array $processingData Event processing information
     * @return string The log file path where entry was written
     */
    public function logEventProcessing(string $requestId, array $processingData): string
    {
        $logFile = $this->getLogFile();
        
        $lines = [];
        $lines[] = str_repeat('-', 80);
        $lines[] = "EVENT_PROCESSING [REQUEST_ID: {$requestId}]";
        $lines[] = "TIMESTAMP: " . date('c');
        $lines[] = "EVENT: " . ($processingData['event'] ?? 'UNKNOWN');
        
        if (isset($processingData['lot_id'])) {
            $lines[] = "LOT_ID: {$processingData['lot_id']}";
        }
        
        $lines[] = "STATUS: " . ($processingData['status'] ?? 'unknown');
        
        if (isset($processingData['error'])) {
            $lines[] = "ERROR: {$processingData['error']}";
            if (isset($processingData['error_type'])) {
                $lines[] = "ERROR_TYPE: {$processingData['error_type']}";
            }
        }
        
        if (isset($processingData['lot_data']) && $processingData['status'] === 'success') {
            $lines[] = "LOT_DATA:";
            $lotDataJson = json_encode($processingData['lot_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $lines[] = $this->indent($lotDataJson);
        }
        
        $lines[] = str_repeat('-', 80);
        
        $logEntry = implode("\n", $lines) . "\n";
        
        // Use file locking to prevent corruption
        $fp = fopen($logFile, 'a');
        if ($fp && flock($fp, LOCK_EX)) {
            fwrite($fp, $logEntry);
            flock($fp, LOCK_UN);
            fclose($fp);
        } else {
            error_log("Failed to acquire lock or open log file: {$logFile}");
        }
        
        return $logFile;
    }

    /**
     * Indent a multi-line string.
     */
    private function indent(string $text, int $spaces = 2): string
    {
        $indent = str_repeat(' ', $spaces);
        return $indent . str_replace("\n", "\n" . $indent, $text);
    }
}
