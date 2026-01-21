<?php

namespace BitrixInvoiceBridge\Tests;

use BitrixInvoiceBridge\BitrixToInvoiceWorkedMapper;
use PHPUnit\Framework\TestCase;

class BitrixToInvoiceWorkedMapperTest extends TestCase
{
    public function testBuildWorkedPayloadSuccess(): void
    {
        $payload = BitrixToInvoiceWorkedMapper::buildWorkedPayload([
            'contactId' => '12345',
            'campaignId' => '67890',
            'workedCode' => 'W01',
            'workedDate' => '2026-01-21 10:00:00',
            'workedEndDate' => '2026-01-21 10:02:00',
            'resultCode' => 'RC01',
            'caller' => '+39000000000',
            'workedType' => 'CALL',
        ]);

        $this->assertSame('W01', $payload['workedCode']);
        $this->assertSame('2026-01-21 10:00:00', $payload['workedDate']);
        $this->assertSame('2026-01-21 10:02:00', $payload['workedEndDate']);
        $this->assertSame('RC01', $payload['resultCode']);
        $this->assertSame('+39000000000', $payload['caller']);
        $this->assertSame('CALL', $payload['workedType']);
        $this->assertSame(67890, $payload['campaignId']);
        $this->assertSame(12345, $payload['contactId']);
    }

    public function testBuildWorkedPayloadMissingField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BitrixToInvoiceWorkedMapper::buildWorkedPayload([
            'contactId' => '12345',
            // missing campaignId
            'workedCode' => 'W01',
            'workedDate' => '2026-01-21 10:00:00',
            'workedEndDate' => '2026-01-21 10:02:00',
            'resultCode' => 'RC01',
            'caller' => '+39000000000',
            'workedType' => 'CALL',
        ]);
    }
}

