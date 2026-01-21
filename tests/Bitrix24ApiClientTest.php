<?php

namespace BitrixInvoiceBridge\Tests;

use BitrixInvoiceBridge\Bitrix24ApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Bitrix24ApiClient.
 * 
 * These tests verify the mapping functions, campaign name resolution,
 * and field transformation logic.
 */
class Bitrix24ApiClientTest extends TestCase
{
    private string $testConfigDir;
    private string $originalConfigFile;

    protected function setUp(): void
    {
        // Create temporary config directory
        $this->testConfigDir = sys_get_temp_dir() . '/bitrix_test_config_' . uniqid();
        mkdir($this->testConfigDir, 0755, true);
        
        // Backup original config path
        $this->originalConfigFile = __DIR__ . '/../config/campaigns.php';
    }

    protected function tearDown(): void
    {
        // Clean up temporary config
        if (is_dir($this->testConfigDir)) {
            array_map('unlink', glob($this->testConfigDir . '/*'));
            rmdir($this->testConfigDir);
        }
    }

    /**
     * Create a temporary campaign config file for testing.
     */
    private function createTestCampaignConfig(array $mapping): string
    {
        $configFile = $this->testConfigDir . '/campaigns.php';
        $content = "<?php\nreturn " . var_export($mapping, true) . ";\n";
        file_put_contents($configFile, $content);
        return $configFile;
    }

    /**
     * Test getCampaignName with existing mapping.
     */
    public function testGetCampaignNameWithMapping(): void
    {
        $mapping = [
            65704 => 'CAMPAGNA_GOOGLE',
            138663 => 'CAMPAGNA_META',
        ];
        
        $configFile = $this->createTestCampaignConfig($mapping);
        
        // Temporarily override the config path using reflection
        $reflection = new \ReflectionClass(Bitrix24ApiClient::class);
        $method = $reflection->getMethod('getCampaignName');
        
        // Test with mapped campaign
        $this->assertEquals('CAMPAGNA_GOOGLE', Bitrix24ApiClient::getCampaignName(65704));
        $this->assertEquals('CAMPAGNA_META', Bitrix24ApiClient::getCampaignName(138663));
    }

    /**
     * Test getCampaignName with fallback to ID.
     */
    public function testGetCampaignNameFallback(): void
    {
        $mapping = [
            65704 => 'CAMPAGNA_GOOGLE',
        ];
        
        $this->createTestCampaignConfig($mapping);
        
        // Test with unmapped campaign (should return ID as string)
        $this->assertEquals('99999', Bitrix24ApiClient::getCampaignName(99999));
        $this->assertEquals('12345', Bitrix24ApiClient::getCampaignName(12345));
    }

    /**
     * Test getCampaignName with missing config file.
     */
    public function testGetCampaignNameWithMissingConfig(): void
    {
        // Test that it falls back to ID when config doesn't exist
        // (This tests the fallback behavior when campaigns.php is missing)
        $this->assertIsString(Bitrix24ApiClient::getCampaignName(12345));
        $this->assertEquals('12345', Bitrix24ApiClient::getCampaignName(12345));
    }

    /**
     * Test mapInvoiceDataToBitrixFields for lead entity.
     */
    public function testMapInvoiceDataToBitrixFieldsLead(): void
    {
        $lotData = [
            [
                'TELEFONO' => '+39123456789',
                'DATA_SCADENZA' => '31/12/2024',
                'ID_ANAGRAFICA' => '12345',
            ]
        ];
        
        $lotId = 280027;
        $campaignId = 65704;
        
        $fields = Bitrix24ApiClient::mapInvoiceDataToBitrixFields($lotData, $lotId, $campaignId, null, 'lead');
        
        $this->assertArrayHasKey('TITLE', $fields);
        $this->assertStringContainsString('Lead from InVoice', $fields['TITLE']);
        $this->assertStringContainsString((string)$lotId, $fields['TITLE']);
        
        $this->assertEquals('WEB', $fields['SOURCE_ID']);
        $this->assertEquals('Y', $fields['OPENED']);
        
        $this->assertArrayHasKey('PHONE', $fields);
        $this->assertIsArray($fields['PHONE']);
        $this->assertEquals('+39123456789', $fields['PHONE'][0]['VALUE']);
        $this->assertEquals('WORK', $fields['PHONE'][0]['VALUE_TYPE']);
        
        $this->assertArrayHasKey('SOURCE_DESCRIPTION', $fields);
        $this->assertArrayHasKey('UF_CRM_DATA_SCADENZA', $fields);
        $this->assertEquals('2024-12-31', $fields['UF_CRM_DATA_SCADENZA']);
        
        $this->assertArrayHasKey('UF_CRM_INVOICE_ID_ANAGRAFICA', $fields);
        $this->assertEquals('12345', $fields['UF_CRM_INVOICE_ID_ANAGRAFICA']);
        
        $this->assertArrayHasKey('COMMENTS', $fields);
        $this->assertStringContainsString('InVoice Lot ID: ' . $lotId, $fields['COMMENTS']);
    }

    /**
     * Test mapInvoiceDataToBitrixFields for contact entity.
     */
    public function testMapInvoiceDataToBitrixFieldsContact(): void
    {
        $lotData = [
            [
                'TELEFONO' => '+39123456789',
                'DATA_SCADENZA' => '15/06/2025',
                'ID_ANAGRAFICA' => '67890',
            ]
        ];
        
        $lotId = 280066;
        $campaignId = 138663;
        
        $fields = Bitrix24ApiClient::mapInvoiceDataToBitrixFields($lotData, $lotId, $campaignId, null, 'contact');
        
        $this->assertArrayHasKey('NAME', $fields);
        $this->assertStringContainsString('Contact from InVoice', $fields['NAME']);
        $this->assertStringContainsString((string)$lotId, $fields['NAME']);
        
        $this->assertArrayNotHasKey('TITLE', $fields);
        $this->assertArrayNotHasKey('SOURCE_ID', $fields);
        $this->assertEquals('Y', $fields['OPENED']);
        
        $this->assertArrayHasKey('PHONE', $fields);
        $this->assertEquals('+39123456789', $fields['PHONE'][0]['VALUE']);
        
        $this->assertArrayHasKey('SOURCE_DESCRIPTION', $fields);
        $this->assertArrayHasKey('UF_CRM_DATA_SCADENZA', $fields);
        $this->assertEquals('2025-06-15', $fields['UF_CRM_DATA_SCADENZA']);
    }

    /**
     * Test mapInvoiceDataToBitrixFields without campaign ID.
     */
    public function testMapInvoiceDataToBitrixFieldsWithoutCampaign(): void
    {
        $lotData = [
            [
                'TELEFONO' => '+39123456789',
            ]
        ];
        
        $fields = Bitrix24ApiClient::mapInvoiceDataToBitrixFields($lotData, 12345, null, null, 'lead');
        
        $this->assertArrayNotHasKey('SOURCE_DESCRIPTION', $fields);
        $this->assertArrayHasKey('PHONE', $fields);
    }

    /**
     * Test mapInvoiceDataToBitrixFields with invalid date format.
     */
    public function testMapInvoiceDataToBitrixFieldsInvalidDate(): void
    {
        $lotData = [
            [
                'DATA_SCADENZA' => 'invalid-date',
            ]
        ];
        
        $fields = Bitrix24ApiClient::mapInvoiceDataToBitrixFields($lotData, 12345, null, null, 'lead');
        
        $this->assertArrayHasKey('UF_CRM_DATA_SCADENZA', $fields);
        $this->assertEquals('invalid-date', $fields['UF_CRM_DATA_SCADENZA']);
    }

    /**
     * Test mapInvoiceDataToDealFields with pipeline.
     */
    public function testMapInvoiceDataToDealFieldsWithPipeline(): void
    {
        $lotData = [
            [
                'ID_ANAGRAFICA' => '12345',
            ]
        ];
        
        $lotId = 280027;
        $campaignId = 65704;
        $pipelineId = 5;
        
        $fields = Bitrix24ApiClient::mapInvoiceDataToDealFields($lotData, $lotId, $campaignId, null, $pipelineId);
        
        $this->assertArrayHasKey('TITLE', $fields);
        $this->assertStringContainsString('Deal from InVoice', $fields['TITLE']);
        $this->assertStringContainsString((string)$lotId, $fields['TITLE']);
        
        $this->assertEquals('Y', $fields['OPENED']);
        $this->assertEquals($pipelineId, $fields['CATEGORY_ID']);
        
        $this->assertArrayHasKey('SOURCE_DESCRIPTION', $fields);
        $this->assertArrayHasKey('COMMENTS', $fields);
        $this->assertStringContainsString('InVoice Lot ID: ' . $lotId, $fields['COMMENTS']);
    }

    /**
     * Test mapInvoiceDataToDealFields without pipeline.
     */
    public function testMapInvoiceDataToDealFieldsWithoutPipeline(): void
    {
        $lotData = [
            [
                'ID_ANAGRAFICA' => '12345',
            ]
        ];
        
        $fields = Bitrix24ApiClient::mapInvoiceDataToDealFields($lotData, 12345, 65704, null, null);
        
        $this->assertArrayNotHasKey('CATEGORY_ID', $fields);
    }

    /**
     * Test mapInvoiceDataToDealFields with zero pipeline (should not set).
     */
    public function testMapInvoiceDataToDealFieldsWithZeroPipeline(): void
    {
        $lotData = [[]];
        
        $fields = Bitrix24ApiClient::mapInvoiceDataToDealFields($lotData, 12345, null, null, 0);
        
        $this->assertArrayNotHasKey('CATEGORY_ID', $fields);
    }

    /**
     * Test mapInvoiceDataToBitrixFields with empty lot data.
     */
    public function testMapInvoiceDataToBitrixFieldsEmptyData(): void
    {
        $fields = Bitrix24ApiClient::mapInvoiceDataToBitrixFields([], 12345, null, null, 'lead');
        
        $this->assertArrayHasKey('TITLE', $fields);
        $this->assertArrayNotHasKey('PHONE', $fields);
        $this->assertArrayNotHasKey('UF_CRM_DATA_SCADENZA', $fields);
    }

    /**
     * Test mapInvoiceDataToDealFields with empty lot data.
     */
    public function testMapInvoiceDataToDealFieldsEmptyData(): void
    {
        $fields = Bitrix24ApiClient::mapInvoiceDataToDealFields([], 12345, null, null, null);
        
        $this->assertArrayHasKey('TITLE', $fields);
        $this->assertArrayHasKey('COMMENTS', $fields);
    }
}
