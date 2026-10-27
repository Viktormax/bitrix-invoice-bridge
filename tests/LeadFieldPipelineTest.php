<?php

namespace BitrixInvoiceBridge\Tests;

use BitrixInvoiceBridge\Bitrix24ApiClient;
use PHPUnit\Framework\TestCase;

class LeadFieldPipelineTest extends TestCase
{
    private const RULE = ['field' => 'LANDING', 'map' => ['offerta-luce-gas' => 29]];

    public function testLeadFromNewLandingGoesToItsPipeline(): void
    {
        $lead = ['TELEFONO' => '3331234567', 'LANDING' => 'offerta-luce-gas'];
        $this->assertSame(29, Bitrix24ApiClient::resolvePipelineForLead($lead, 65704, 17, self::RULE, []));
    }

    public function testLeadWithoutFieldKeepsDefault(): void
    {
        $this->assertSame(17, Bitrix24ApiClient::resolvePipelineForLead(['TELEFONO' => '3331234567'], 65704, 17, self::RULE, []));
    }

    public function testUnknownValueKeepsDefault(): void
    {
        $this->assertSame(17, Bitrix24ApiClient::resolvePipelineForLead(['LANDING' => 'home'], 65704, 17, self::RULE, []));
    }

    public function testEmptyValueKeepsDefault(): void
    {
        $this->assertSame(17, Bitrix24ApiClient::resolvePipelineForLead(['LANDING' => ''], 65704, 17, self::RULE, []));
    }

    public function testFieldAndValueAreCaseInsensitive(): void
    {
        $lead = ['landing' => ' Offerta-Luce-Gas '];
        $this->assertSame(29, Bitrix24ApiClient::resolvePipelineForLead($lead, 65704, 17, self::RULE, []));
    }

    public function testDisabledRuleKeepsDefault(): void
    {
        $lead = ['LANDING' => 'offerta-luce-gas'];
        $this->assertSame(17, Bitrix24ApiClient::resolvePipelineForLead($lead, 65704, 17, ['field' => '', 'map' => []], []));
    }

    public function testLeadFieldWinsOverCampaignMap(): void
    {
        $lead = ['LANDING' => 'offerta-luce-gas'];
        $this->assertSame(29, Bitrix24ApiClient::resolvePipelineForLead($lead, 999999, 17, self::RULE, [999999 => 25]));
    }

    public function testFallsBackToCampaignMap(): void
    {
        $this->assertSame(25, Bitrix24ApiClient::resolvePipelineForLead(['LANDING' => 'home'], 999999, 17, self::RULE, [999999 => 25]));
    }

    private const RULES = ['rules' => [
        ['field' => 'SOURCE_TYPE', 'map' => ['WIDGET_B' => 29]],
        ['field' => 'EXTERNAL_ID', 'map' => ['WIDGET_B' => 29]],
    ]];

    public function testExternalIdWithPrefixGoesToPipeline(): void
    {
        $lead = ['TELEFONO' => '3331234567', 'EXTERNAL_ID' => 'WIDGET_B-1793103684107-k3x9'];
        $this->assertSame(29, Bitrix24ApiClient::resolvePipelineForLead($lead, 65704, 17, self::RULES, []));
    }

    public function testSourceTypeStillRoutes(): void
    {
        $lead = ['SOURCE_TYPE' => 'WIDGET_B', 'EXTERNAL_ID' => null];
        $this->assertSame(29, Bitrix24ApiClient::resolvePipelineForLead($lead, 65704, 17, self::RULES, []));
    }

    public function testWidgetTransactionIdInExternalIdKeepsDefault(): void
    {
        $lead = ['SOURCE_TYPE' => 'WIDGET_A', 'EXTERNAL_ID' => '8d615d2d-33f9-450a-a558-32c435872a63'];
        $this->assertSame(17, Bitrix24ApiClient::resolvePipelineForLead($lead, 65704, 17, self::RULES, []));
    }

    public function testPrefixNeedsSeparator(): void
    {
        $this->assertSame(17, Bitrix24ApiClient::resolvePipelineForLead(['EXTERNAL_ID' => 'WIDGET_BX'], 65704, 17, self::RULES, []));
    }
}
