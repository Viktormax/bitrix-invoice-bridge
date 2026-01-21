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
     * Get result codes mapping configuration.
     * 
     * Loads result codes mapping from config/result_codes.php (or .example.php as fallback).
     * This mapping translates Bitrix activity outcomes to InVoice workedCode/resultCode.
     * 
     * @return array Result codes mapping (keys: campaign IDs or 'default')
     */
    public static function getResultCodesConfig(): array
    {
        static $resultCodesConfig = null;
        
        if ($resultCodesConfig === null) {
            $configFile = __DIR__ . '/../config/result_codes.php';
            $exampleFile = __DIR__ . '/../config/result_codes.example.php';
            
            // Try actual config first, then example
            if (file_exists($configFile)) {
                $resultCodesConfig = require $configFile;
            } elseif (file_exists($exampleFile)) {
                $resultCodesConfig = require $exampleFile;
            } else {
                $resultCodesConfig = [];
            }
            
            if (!is_array($resultCodesConfig)) {
                $resultCodesConfig = [];
            }
        }
        
        return $resultCodesConfig;
    }

    /**
     * Map Bitrix activity outcome to InVoice workedCode and resultCode.
     * 
     * @param string|int $bitrixOutcome Bitrix activity outcome/status value
     * @param int|null $idConfigCampagna Campaign configuration ID (for campaign-specific mapping)
     * @return array|null Mapping with 'workedCode', 'resultCode', 'workedType', 'description', or null if not found
     */
    public static function mapBitrixOutcomeToInvoice($bitrixOutcome, ?int $idConfigCampagna = null): ?array
    {
        $config = self::getResultCodesConfig();
        
        // Normalize bitrix outcome (convert to string for comparison)
        $bitrixOutcomeStr = (string)$bitrixOutcome;
        
        // Try campaign-specific mapping first
        if ($idConfigCampagna !== null && isset($config[$idConfigCampagna]) && is_array($config[$idConfigCampagna])) {
            foreach ($config[$idConfigCampagna] as $mapping) {
                if (isset($mapping['bitrix_outcome']) && (string)$mapping['bitrix_outcome'] === $bitrixOutcomeStr) {
                    return $mapping;
                }
            }
        }
        
        // Fallback to default mapping
        if (isset($config['default']) && is_array($config['default'])) {
            foreach ($config['default'] as $mapping) {
                if (isset($mapping['bitrix_outcome']) && (string)$mapping['bitrix_outcome'] === $bitrixOutcomeStr) {
                    return $mapping;
                }
            }
        }
        
        return null;
    }

    /**
     * Build the payload for POST /partner-api/v5/worked.
     *
     * Expected inputs (usually read from Bitrix deal/contact custom fields):
     * - contactId: InVoice ID_ANAGRAFICA (string/int)
     * - campaignId: InVoice campaign id_campagna (string/int)
     * - caller: operator identifier / phone (string)
     * - bitrixOutcome: (optional) Bitrix activity outcome - if provided, will be mapped to workedCode/resultCode
     * - idConfigCampagna: (optional) Campaign configuration ID for outcome mapping
     *
     * If bitrixOutcome is provided and mapping exists, it will override workedCode/resultCode/workedType.
     * Otherwise, workedCode, resultCode, and workedType must be provided explicitly.
     *
     * @throws \InvalidArgumentException
     */
    public static function buildWorkedPayload(array $input): array
    {
        // Try to map Bitrix outcome if provided
        $mappedOutcome = null;
        if (isset($input['bitrixOutcome'])) {
            $idConfigCampagna = isset($input['idConfigCampagna']) ? (int)$input['idConfigCampagna'] : null;
            $mappedOutcome = self::mapBitrixOutcomeToInvoice($input['bitrixOutcome'], $idConfigCampagna);
            
            if ($mappedOutcome) {
                error_log("BitrixToInvoiceWorkedMapper: Mapped Bitrix outcome '{$input['bitrixOutcome']}' to workedCode={$mappedOutcome['workedCode']}, resultCode={$mappedOutcome['resultCode']}, workedType={$mappedOutcome['workedType']}");
            } else {
                error_log("BitrixToInvoiceWorkedMapper: WARNING - No mapping found for Bitrix outcome '{$input['bitrixOutcome']}' (id_config_campagna: " . ($idConfigCampagna ?? 'null') . "). Using explicit values if provided.");
            }
        }
        
        // Use mapped values if available, otherwise require explicit values
        $workedCode = $mappedOutcome['workedCode'] ?? $input['workedCode'] ?? null;
        $resultCode = $mappedOutcome['resultCode'] ?? $input['resultCode'] ?? null;
        $workedType = $mappedOutcome['workedType'] ?? $input['workedType'] ?? null;
        
        $required = ['contactId', 'campaignId', 'workedDate', 'workedEndDate', 'caller'];
        foreach ($required as $key) {
            if (!isset($input[$key]) || $input[$key] === '' || $input[$key] === null) {
                throw new \InvalidArgumentException("Missing required field: {$key}");
            }
        }
        
        if ($workedCode === null || $resultCode === null || $workedType === null) {
            throw new \InvalidArgumentException("Missing workedCode, resultCode, or workedType. Either provide bitrixOutcome with valid mapping in result_codes.php, or provide explicit values.");
        }

        return [
            'workedCode' => (string)$workedCode,
            'workedDate' => (string)$input['workedDate'],
            'workedEndDate' => (string)$input['workedEndDate'],
            'resultCode' => (string)$resultCode,
            'caller' => (string)$input['caller'],
            'workedType' => (string)$workedType,
            'campaignId' => (int)$input['campaignId'],
            'contactId' => (int)$input['contactId'],
        ];
    }
}

