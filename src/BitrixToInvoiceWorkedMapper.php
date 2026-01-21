<?php

namespace BitrixInvoiceBridge;

/**
 * Build an InVoice "worked contact" payload from Bitrix CRM entities.
 *
 * This class is pure (no network I/O) and is designed for unit testing.
 */
class BitrixToInvoiceWorkedMapper
{
    /**
     * Build the payload for POST /partner-api/v5/worked.
     *
     * Expected inputs (usually read from Bitrix deal/contact custom fields):
     * - contactId: InVoice ID_ANAGRAFICA (string/int)
     * - campaignId: InVoice campaign id_campagna (string/int)
     * - caller: operator identifier / phone (string)
     *
     * @throws \InvalidArgumentException
     */
    public static function buildWorkedPayload(array $input): array
    {
        $required = ['contactId', 'campaignId', 'resultCode', 'workedCode', 'workedType', 'caller', 'workedDate', 'workedEndDate'];
        foreach ($required as $key) {
            if (!isset($input[$key]) || $input[$key] === '' || $input[$key] === null) {
                throw new \InvalidArgumentException("Missing required field: {$key}");
            }
        }

        return [
            'workedCode' => (string)$input['workedCode'],
            'workedDate' => (string)$input['workedDate'],
            'workedEndDate' => (string)$input['workedEndDate'],
            'resultCode' => (string)$input['resultCode'],
            'caller' => (string)$input['caller'],
            'workedType' => (string)$input['workedType'],
            'campaignId' => (int)$input['campaignId'],
            'contactId' => (int)$input['contactId'],
        ];
    }
}

