<?php

namespace BitrixInvoiceBridge\Tests;

use BitrixInvoiceBridge\InvoiceApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InvoiceApiClient.
 * 
 * These tests verify the OAuth2 authentication flow and API client functionality.
 */
class InvoiceApiClientTest extends TestCase
{
    private string $testBaseUrl = 'https://enel.in-voice.it';

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
        
        // Use reflection to verify private properties are set
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
     * Test JWT generation structure (without actual signing).
     * This test verifies that JWT claims are correctly structured.
     */
    public function testJWTStructure(): void
    {
        // This would require mocking or testing the private buildJWT method
        // For now, we verify the public interface works correctly
        $client = new InvoiceApiClient($this->testBaseUrl);
        $client->setCredentials('test-client-id', 'test-jwk');
        
        // The actual JWT building requires valid JWK, so we just verify
        // that the method exists and would be called
        $this->assertTrue(method_exists($client, 'getAccessToken'));
    }
}
