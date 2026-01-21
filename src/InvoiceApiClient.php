<?php

namespace BitrixInvoiceBridge;

/**
 * InVoice (ECO) API Client for OAuth2 authentication and API calls.
 * 
 * Based on API documentation v1.4.0
 */
class InvoiceApiClient
{
    private string $baseUrl;
    private ?string $accessToken = null;
    private ?string $clientId = null;
    private ?string $jwkPrivateKey = null;
    private ?string $tokenCacheFile = null;
    private int $tokenExpiresAt = 0;

    public function __construct(string $baseUrl = 'https://enel.in-voice.it', ?string $tokenCacheDir = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        
        // Set cache file path if cache directory provided
        if ($tokenCacheDir !== null) {
            $cacheDir = rtrim($tokenCacheDir, '/');
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            $this->tokenCacheFile = $cacheDir . '/invoice_token_cache_' . md5($this->baseUrl) . '.json';
            $this->loadTokenFromCache();
        }
    }

    /**
     * Set OAuth2 credentials for JWT Bearer authentication.
     */
    public function setCredentials(string $clientId, string $jwkPrivateKey): void
    {
        $this->clientId = $clientId;
        $this->jwkPrivateKey = $jwkPrivateKey;
    }

    /**
     * Set access token directly (if already obtained).
     */
    public function setAccessToken(string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Load token from cache if valid.
     */
    private function loadTokenFromCache(): void
    {
        if ($this->tokenCacheFile === null || !file_exists($this->tokenCacheFile)) {
            return;
        }
        
        $cacheData = json_decode(file_get_contents($this->tokenCacheFile), true);
        if (!is_array($cacheData) || !isset($cacheData['token'], $cacheData['expires_at'])) {
            return;
        }
        
        // Check if token is still valid (with 60s buffer as per requirements)
        $now = time();
        if ($cacheData['expires_at'] > ($now + 60)) {
            $this->accessToken = $cacheData['token'];
            $this->tokenExpiresAt = $cacheData['expires_at'];
        } else {
            // Token expired, remove cache file
            @unlink($this->tokenCacheFile);
        }
    }

    /**
     * Save token to cache.
     */
    private function saveTokenToCache(string $token, int $expiresIn): void
    {
        if ($this->tokenCacheFile === null) {
            return;
        }
        
        $this->tokenExpiresAt = time() + $expiresIn;
        $cacheData = [
            'token' => $token,
            'expires_at' => $this->tokenExpiresAt,
            'cached_at' => time(),
        ];
        
        file_put_contents($this->tokenCacheFile, json_encode($cacheData), LOCK_EX);
    }

    /**
     * Get OAuth2 access token using JWT Bearer flow (with caching).
     * 
     * @return string Access token
     * @throws \Exception If authentication fails
     */
    public function getAccessToken(): string
    {
        // Return cached token if still valid
        if ($this->accessToken && $this->tokenExpiresAt > (time() + 60)) {
            return $this->accessToken;
        }

        if (empty($this->clientId) || empty($this->jwkPrivateKey)) {
            throw new \Exception('OAuth2 credentials not set. Call setCredentials() first.');
        }

        // Build JWT client assertion
        $jwt = $this->buildJWT($this->clientId);

        // Request access token
        $tokenUrl = $this->baseUrl . '/oauth2/token';
        
        // Body must be form-url-encoded (as per Java implementation)
        $postData = http_build_query([
            'client_id' => $this->clientId,
            'client_assertion' => $jwt,
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'grant_type' => 'client_credentials',
            'scope' => 'api.partner',
        ]);

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("OAuth2 token request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \Exception("OAuth2 token request failed with HTTP {$httpCode}: {$response}");
        }

        $tokenData = json_decode($response, true);
        if (!isset($tokenData['access_token'])) {
            throw new \Exception("Invalid token response: {$response}");
        }

        $this->accessToken = $tokenData['access_token'];
        
        // Cache token (expires_in is in seconds, subtract 60s buffer)
        $expiresIn = isset($tokenData['expires_in']) ? (int)$tokenData['expires_in'] : 3600;
        $this->saveTokenToCache($this->accessToken, $expiresIn - 60);
        
        return $this->accessToken;
    }

    /**
     * Base64 URL decode (RFC 4648).
     */
    private function base64UrlDecode(string $data): string
    {
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Convert JWK RSA to OpenSSL private key resource.
     * 
     * Supports:
     * 1. PEM format directly (if JWK contains 'private_key' field with PEM)
     * 2. JWK RSA with private components (requires phpseclib for full support)
     * 
     * For JWK RSA parsing without phpseclib, the key should be pre-converted to PEM format.
     * 
     * @param string $jwkData JSON string or already parsed array
     * @return resource OpenSSL private key resource
     * @throws \Exception If JWK is invalid or missing required components
     */
    private function jwkToPrivateKey(string $jwkData)
    {
        // If it's already PEM format, use it directly
        if (strpos($jwkData, 'BEGIN') !== false || strpos($jwkData, 'PRIVATE KEY') !== false) {
            $key = openssl_pkey_get_private($jwkData);
            if (!$key) {
                throw new \Exception('Invalid PEM private key: ' . openssl_error_string());
            }
            return $key;
        }
        
        // Parse JWK JSON
        $jwk = is_string($jwkData) ? json_decode($jwkData, true) : $jwkData;
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($jwk)) {
            $error = json_last_error_msg();
            $preview = is_string($jwkData) ? substr($jwkData, 0, 200) : 'not a string';
            throw new \Exception("Invalid JWK format: {$error}. Preview: {$preview}");
        }
        
        // Check if JWK contains a pre-converted PEM key
        if (isset($jwk['private_key']) && is_string($jwk['private_key'])) {
            if (strpos($jwk['private_key'], 'BEGIN') !== false) {
                // It's already in PEM format
                $key = openssl_pkey_get_private($jwk['private_key']);
                if ($key) {
                    return $key;
                }
            }
        }
        
        // Check if it's an RSA key with private components
        if (!isset($jwk['kty']) || $jwk['kty'] !== 'RSA') {
            throw new \Exception('JWK must be RSA type (kty=RSA)');
        }
        
        // Required components for RSA private key
        $required = ['n', 'e', 'd'];
        foreach ($required as $component) {
            if (!isset($jwk[$component]) || !is_string($jwk[$component])) {
                throw new \Exception("JWK missing required component: {$component}. JWK must include private components (d, p, q) to sign JWT.");
            }
        }
        
        // Check if phpseclib is available (required for JWK RSA parsing)
        if (class_exists('\phpseclib3\Crypt\RSA')) {
            return $this->jwkToPrivateKeyWithPhpseclib($jwk);
        }
        
        // Also check for phpseclib v2 (backward compatibility)
        if (class_exists('\phpseclib\Crypt\RSA')) {
            return $this->jwkToPrivateKeyWithPhpseclibV2($jwk);
        }
        
        // Without phpseclib, we cannot directly parse JWK RSA components in pure PHP
        // (would require complex ASN.1 DER encoding)
        throw new \Exception(
            'JWK RSA parsing requires phpseclib library for full support. ' .
            'Install with: composer require phpseclib/phpseclib ' .
            'OR convert your JWK to PEM format and use INVOICE_JWK_PRIVATE_KEY with PEM content. ' .
            'To convert JWK to PEM, you can use online tools or: ' .
            'python3 -c "from cryptography.hazmat.primitives import serialization; import json, base64, sys; ' .
            'jwk=json.load(sys.stdin); n=base64.urlsafe_b64decode(jwk[\"n\"]+\"==\"); ' .
            'e=base64.urlsafe_b64decode(jwk[\"e\"]+\"==\"); d=base64.urlsafe_b64decode(jwk[\"d\"]+\"==\"); ' .
            'from cryptography.hazmat.primitives.asymmetric import rsa; ' .
            'key=rsa.RSAPrivateNumbers(...).private_key(); print(key.private_bytes(encoding=serialization.Encoding.PEM, format=serialization.PrivateFormat.PKCS8, encryption_algorithm=serialization.NoEncryption()).decode())"'
        );
    }
    
    /**
     * Convert JWK to private key using phpseclib3 (if available).
     */
    private function jwkToPrivateKeyWithPhpseclib(array $jwk)
    {
        // Use phpseclib3 PublicKeyLoader to parse JWK
        // PublicKeyLoader can load JWK format directly
        $jwkJson = json_encode($jwk, JSON_UNESCAPED_SLASHES);
        
        try {
            // Load JWK using PublicKeyLoader
            $privateKey = \phpseclib3\Crypt\PublicKeyLoader::load($jwkJson, false);
            
            if (!$privateKey instanceof \phpseclib3\Crypt\RSA\PrivateKey) {
                throw new \Exception('JWK did not load as RSA private key');
            }
            
            // Get PEM format
            $pem = $privateKey->toString('PKCS8');
            
            // Load into OpenSSL resource
            $opensslKey = openssl_pkey_get_private($pem);
            if (!$opensslKey) {
                throw new \Exception('Failed to create OpenSSL key from PEM: ' . openssl_error_string());
            }
            
            return $opensslKey;
            
        } catch (\Exception $e) {
            throw new \Exception('Failed to parse JWK with phpseclib3: ' . $e->getMessage());
        }
    }
    
    /**
     * Convert JWK to private key using phpseclib v2 (backward compatibility).
     */
    private function jwkToPrivateKeyWithPhpseclibV2(array $jwk)
    {
        // Use phpseclib v2 to parse JWK RSA
        $rsa = new \phpseclib\Crypt\RSA();
        
        // Load JWK into phpseclib v2
        $key = [
            'n' => new \phpseclib\Math\BigInteger($this->base64UrlDecode($jwk['n']), 256),
            'e' => new \phpseclib\Math\BigInteger($this->base64UrlDecode($jwk['e']), 256),
            'd' => new \phpseclib\Math\BigInteger($this->base64UrlDecode($jwk['d']), 256),
        ];
        
        // Add CRT components if available
        if (isset($jwk['p'], $jwk['q'], $jwk['dp'], $jwk['dq'], $jwk['qi'])) {
            $key['p'] = new \phpseclib\Math\BigInteger($this->base64UrlDecode($jwk['p']), 256);
            $key['q'] = new \phpseclib\Math\BigInteger($this->base64UrlDecode($jwk['q']), 256);
            $key['dmp1'] = new \phpseclib\Math\BigInteger($this->base64UrlDecode($jwk['dp']), 256);
            $key['dmq1'] = new \phpseclib\Math\BigInteger($this->base64UrlDecode($jwk['dq']), 256);
            $key['iqmp'] = new \phpseclib\Math\BigInteger($this->base64UrlDecode($jwk['qi']), 256);
        }
        
        $rsa->loadKey($key);
        
        // Get PEM format
        $pem = $rsa->getPrivateKey();
        
        // Load into OpenSSL resource
        $privateKey = openssl_pkey_get_private($pem);
        if (!$privateKey) {
            throw new \Exception('Failed to create private key from JWK: ' . openssl_error_string());
        }
        
        return $privateKey;
    }

    /**
     * Generate UUID v4 for JWT jti claim.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant bits
        
        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }

    /**
     * Build JWT for OAuth2 client assertion (replicating Java logic exactly).
     * 
     * Header: alg=RS256, typ=JWT
     * Claims: iss, sub, aud (token URL), iat, exp (iat+300s), jti (UUID)
     */
    private function buildJWT(string $clientId): string
    {
        // Header (exactly as Java)
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        // Token URL (aud must be the complete token endpoint URL)
        $tokenUrl = $this->baseUrl . '/oauth2/token';
        
        // Payload claims (exactly as Java)
        $now = time();
        $payload = [
            'iss' => $clientId,           // issuer = client_id
            'sub' => $clientId,           // subject = client_id
            'aud' => $tokenUrl,           // audience = complete token URL (CRITICAL!)
            'iat' => $now,                // issued at (seconds)
            'exp' => $now + 300,          // expiration (iat + 300s = 5 minutes, NOT 1h)
            'jti' => $this->generateUuid(), // JWT ID (UUID for uniqueness)
        ];

        // Encode header and payload
        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        // Create signature input
        $signatureInput = $headerEncoded . '.' . $payloadEncoded;
        
        // Get private key from JWK
        $privateKey = $this->jwkToPrivateKey($this->jwkPrivateKey);
        
        // Sign with RS256
        $signature = '';
        if (!openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            if (PHP_VERSION_ID < 80000) {
                openssl_free_key($privateKey);
            }
            throw new \Exception('Failed to sign JWT: ' . openssl_error_string());
        }
        // openssl_free_key() is deprecated in PHP 8.0+ (keys are freed automatically)
        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($privateKey);
        }

        // Encode signature
        $signatureEncoded = $this->base64UrlEncode($signature);

        // Return complete JWT
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Base64 URL encode (RFC 4648).
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Make authenticated API request.
     */
    public function request(string $method, string $endpoint, ?array $data = null): array
    {
        $url = $this->baseUrl . $endpoint;
        $accessToken = $this->getAccessToken();

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET' && $data !== null) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("API request failed: {$error}");
        }

        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response: {$response}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMsg = isset($responseData['message']) ? $responseData['message'] : json_encode($responseData);
            throw new \Exception("API request failed with HTTP {$httpCode}: {$errorMsg}");
        }

        return $responseData;
    }

    /**
     * Get lot status.
     */
    public function getLotStatus(int $lotId): array
    {
        return $this->request('GET', "/partner-api/v5/slices/{$lotId}");
    }

    /**
     * Download lot JSON data.
     */
    public function getLotData(int $lotId): array
    {
        return $this->request('GET', "/partner-api/v5/slices/{$lotId}.json");
    }

    /**
     * List available lots (slices).
     */
    public function listLots(): array
    {
        return $this->request('GET', '/partner-api/v5/slices');
    }

    /**
     * Request a new lot.
     */
    public function requestLot(int $campaignId, int $size): array
    {
        return $this->request('POST', '/partner-api/v5/slices', [
            'id_campagna' => $campaignId,
            'size' => $size,
        ]);
    }

    /**
     * Upload a single worked contact (Bitrix -> InVoice).
     *
     * Endpoint: POST /partner-api/v5/worked
     * Content-Type: application/json
     *
     * Required fields (per API docs):
     * - workedCode
     * - workedDate
     * - workedEndDate
     * - resultCode
     * - caller
     * - workedType
     * - campaignId
     * - contactId
     */
    public function submitWorkedContact(array $workedPayload): array
    {
        return $this->request('POST', '/partner-api/v5/worked', $workedPayload);
    }
}
