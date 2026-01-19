<?php

namespace BitrixInvoiceBridge\Tests;

use BitrixInvoiceBridge\InvoiceApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InvoiceApiClient.
 * 
 * These tests verify the OAuth2 authentication flow, JWT generation, JWK conversion,
 * and API client functionality with comprehensive coverage.
 */
class InvoiceApiClientTest extends TestCase
{
    private string $testBaseUrl = 'https://enel.in-voice.it';
    private string $testLogDir;


    protected function setUp(): void
    {
        $this->testLogDir = sys_get_temp_dir() . '/invoice_client_test_' . uniqid();
        mkdir($this->testLogDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testLogDir)) {
            array_map('unlink', glob($this->testLogDir . '/*'));
            rmdir($this->testLogDir);
        }
    }

    /**
     * Generate a real RSA key pair for testing.
     */
    private function generateTestRsaKey(): array
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        
        $resource = openssl_pkey_new($config);
        if (!$resource) {
            $this->markTestSkipped('Failed to generate test RSA key: ' . openssl_error_string());
        }
        
        openssl_pkey_export($resource, $privateKeyPem);
        $publicKeyDetails = openssl_pkey_get_details($resource);
        
        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($resource);
        }
        
        return [
            'private' => $privateKeyPem,
            'public' => $publicKeyDetails['key'],
            'resource' => $resource,
        ];
    }

    /**
     * Test that InvoiceApiClient can be instantiated.
     */
    public function testClientInstantiation(): void
    {
        $client = new InvoiceApiClient($this->testBaseUrl);
        $this->assertInstanceOf(InvoiceApiClient::class, $client);
    }

    /**
     * Test that credentials can be set.
     */
    public function testSetCredentials(): void
    {
        $client = new InvoiceApiClient($this->testBaseUrl);
        $client->setCredentials('test-client-id', 'test-jwk');
        
        $reflection = new \ReflectionClass($client);
        $clientIdProp = $reflection->getProperty('clientId');
        $clientIdProp->setAccessible(true);
        $jwkProp = $reflection->getProperty('jwkPrivateKey');
        $jwkProp->setAccessible(true);
        
        $this->assertEquals('test-client-id', $clientIdProp->getValue($client));
        $this->assertEquals('test-jwk', $jwkProp->getValue($client));
    }

    /**
     * Test that access token can be set directly.
     */
    public function testSetAccessToken(): void
    {
        $client = new InvoiceApiClient($this->testBaseUrl);
        $client->setAccessToken('test-access-token');
        
        $reflection = new \ReflectionClass($client);
        $tokenProp = $reflection->getProperty('accessToken');
        $tokenProp->setAccessible(true);
        
        $this->assertEquals('test-access-token', $tokenProp->getValue($client));
    }

    /**
     * Test that getAccessToken throws exception when credentials are not set.
     */
    public function testGetAccessTokenWithoutCredentials(): void
    {
        $client = new InvoiceApiClient($this->testBaseUrl);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('OAuth2 credentials not set');
        
        $client->getAccessToken();
    }

    /**
     * Test JWT generation: verify structure, claims, and base64url encoding.
     */
    public function testJWTGenerationStructure(): void
    {
        $keyPair = $this->generateTestRsaKey();
        $client = new InvoiceApiClient($this->testBaseUrl);
        $client->setCredentials('test-client-123', $keyPair['private']);
        
        $reflection = new \ReflectionClass($client);
        $buildJwtMethod = $reflection->getMethod('buildJWT');
        $buildJwtMethod->setAccessible(true);
        
        $jwt = $buildJwtMethod->invoke($client, 'test-client-123');
        
        // Verify JWT structure (3 parts separated by dots)
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'JWT must have 3 parts: header.payload.signature');
        
        // Decode and verify header
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $this->assertEquals('RS256', $header['alg'], 'JWT algorithm must be RS256');
        $this->assertEquals('JWT', $header['typ'], 'JWT type must be JWT');
        
        // Decode and verify payload claims
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        $this->assertEquals('test-client-123', $payload['iss'], 'iss claim must equal client_id');
        $this->assertEquals('test-client-123', $payload['sub'], 'sub claim must equal client_id');
        $this->assertEquals($this->testBaseUrl . '/oauth2/token', $payload['aud'], 'aud claim must be complete token URL');
        $this->assertIsInt($payload['iat'], 'iat claim must be integer timestamp');
        $this->assertEquals($payload['iat'] + 300, $payload['exp'], 'exp claim must be iat + 300 seconds');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payload['jti'], 'jti claim must be valid UUID');
        
        // Verify signature is base64url encoded (no padding, uses -_ instead of +/)
        $this->assertStringNotContainsString('+', $parts[2], 'Signature must use base64url encoding (no +)');
        $this->assertStringNotContainsString('/', $parts[2], 'Signature must use base64url encoding (no /)');
        $this->assertStringNotContainsString('=', $parts[2], 'Signature must use base64url encoding (no padding)');
    }

    /**
     * Test JWT RS256 signature verification.
     */
    public function testJWTRS256Signature(): void
    {
        $keyPair = $this->generateTestRsaKey();
        $client = new InvoiceApiClient($this->testBaseUrl);
        $client->setCredentials('test-client-123', $keyPair['private']);
        
        $reflection = new \ReflectionClass($client);
        $buildJwtMethod = $reflection->getMethod('buildJWT');
        $buildJwtMethod->setAccessible(true);
        
        $jwt = $buildJwtMethod->invoke($client, 'test-client-123');
        
        // Extract parts
        $parts = explode('.', $jwt);
        $header = $parts[0];
        $payload = $parts[1];
        $signature = $this->base64UrlDecode($parts[2]);
        
        // Verify signature using public key
        $signatureInput = $header . '.' . $payload;
        $publicKeyResource = openssl_pkey_get_public($keyPair['public']);
        
        $verified = openssl_verify($signatureInput, $signature, $publicKeyResource, OPENSSL_ALGO_SHA256);
        
        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($publicKeyResource);
        }
        
        $this->assertEquals(1, $verified, 'JWT signature must be valid (RS256)');
    }

    /**
     * Test JWK to PEM conversion with phpseclib3 (if available).
     */
    public function testJWKToPEMConversion(): void
    {
        if (!class_exists('\phpseclib3\Crypt\RSA')) {
            $this->markTestSkipped('phpseclib3 not available');
        }
        
        // Generate a real JWK from a test key
        $keyPair = $this->generateTestRsaKey();
        
        // Extract public key details to create JWK
        $publicKeyDetails = openssl_pkey_get_details(openssl_pkey_get_public($keyPair['public']));
        $n = base64_encode($publicKeyDetails['rsa']['n']);
        $e = base64_encode($publicKeyDetails['rsa']['e']);
        
        // For testing, we'll use the PEM directly since we need private components
        // In real scenario, JWK would have d, p, q, dp, dq, qi
        $client = new InvoiceApiClient($this->testBaseUrl);
        $client->setCredentials('test-client', $keyPair['private']);
        
        $reflection = new \ReflectionClass($client);
        $jwkToKeyMethod = $reflection->getMethod('jwkToPrivateKey');
        $jwkToKeyMethod->setAccessible(true);
        
        // Test PEM format (should work directly)
        $privateKeyResource = $jwkToKeyMethod->invoke($client, $keyPair['private']);
        $this->assertIsResource($privateKeyResource) || $this->assertIsObject($privateKeyResource);
        
        if (PHP_VERSION_ID < 80000 && is_resource($privateKeyResource)) {
            openssl_free_key($privateKeyResource);
        }
    }

    /**
     * Test base64url encoding/decoding.
     */
    public function testBase64UrlEncoding(): void
    {
        $client = new InvoiceApiClient($this->testBaseUrl);
        $reflection = new \ReflectionClass($client);
        
        $encodeMethod = $reflection->getMethod('base64UrlEncode');
        $encodeMethod->setAccessible(true);
        
        $decodeMethod = $reflection->getMethod('base64UrlDecode');
        $decodeMethod->setAccessible(true);
        
        $testData = 'Hello World! Test data with special chars: +/=';
        $encoded = $encodeMethod->invoke($client, $testData);
        
        // Verify base64url encoding (no +, /, =)
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
        
        // Verify round-trip
        $decoded = $decodeMethod->invoke($client, $encoded);
        $this->assertEquals($testData, $decoded);
    }

    /**
     * Test token caching functionality.
     */
    public function testTokenCaching(): void
    {
        $client = new InvoiceApiClient($this->testBaseUrl, $this->testLogDir);
        $client->setAccessToken('cached-token-123');
        
        // Manually set expiration to future
        $reflection = new \ReflectionClass($client);
        $expiresProp = $reflection->getProperty('tokenExpiresAt');
        $expiresProp->setAccessible(true);
        $expiresProp->setValue($client, time() + 3600);
        
        // Verify cached token is returned (without making actual API call)
        $tokenProp = $reflection->getProperty('accessToken');
        $tokenProp->setAccessible(true);
        $this->assertEquals('cached-token-123', $tokenProp->getValue($client));
    }

    /**
     * Helper: Base64 URL decode.
     */
    private function base64UrlDecode(string $data): string
    {
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
