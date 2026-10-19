<?php

namespace BitrixInvoiceBridge\Tests;

use BitrixInvoiceBridge\Bitrix24ApiClient;
use PHPUnit\Framework\TestCase;

class CampaignPipelineTest extends TestCase
{
    public function testUnmappedCampaignKeepsDefaultPipeline(): void
    {
        $this->assertSame(17, Bitrix24ApiClient::resolvePipelineId(65704, 17, [999999 => 25]));
    }

    public function testMappedCampaignUsesItsPipeline(): void
    {
        $this->assertSame(25, Bitrix24ApiClient::resolvePipelineId(999999, 17, [999999 => 25]));
    }

    public function testEmptyMapKeepsDefaultPipeline(): void
    {
        $this->assertSame(17, Bitrix24ApiClient::resolvePipelineId(65704, 17, []));
    }

    public function testMissingCampaignIdKeepsDefaultPipeline(): void
    {
        $this->assertSame(17, Bitrix24ApiClient::resolvePipelineId(null, 17, [999999 => 25]));
    }

    public function testInvalidPipelineValueKeepsDefault(): void
    {
        $this->assertSame(17, Bitrix24ApiClient::resolvePipelineId(999999, 17, [999999 => 0]));
    }

    public function testNoDefaultAndUnmappedCampaignReturnsNull(): void
    {
        $this->assertNull(Bitrix24ApiClient::resolvePipelineId(65704, null, [999999 => 25]));
    }
}
