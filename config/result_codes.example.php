<?php
/**
 * Result codes mapping configuration.
 * 
 * Maps Bitrix24 activity outcomes/statuses to InVoice workedCode and resultCode.
 * This mapping is required for the reverse flow (Bitrix -> InVoice).
 * 
 * IMPORTANT: Copy this file to config/result_codes.php and update with your actual mappings.
 * The result_codes.php file is NOT committed to the repository for security and customization reasons.
 * 
 * Structure:
 * - Each campaign configuration ID (id_config_campagna) has its own mapping
 * - Each mapping contains:
 *   - 'bitrix_outcome': The Bitrix activity outcome/status value (string or integer)
 *   - 'workedCode': InVoice worked code (e.g., 'W01', 'W02')
 *   - 'resultCode': InVoice result code (e.g., 'RC01', 'RC02')
 *   - 'workedType': InVoice worked type (e.g., 'CALL', 'EMAIL', 'SMS')
 *   - 'description': Optional description for reference
 * 
 * To find available InVoice result codes for a campaign:
 *   php scripts/fetch_result_codes.php <id_config_campagna>
 * 
 * Example Bitrix outcomes (common values):
 * - 'SUCCESS', 'FAILED', 'COMPLETED', 'CANCELLED'
 * - Activity status IDs: 1, 2, 3, etc.
 * - Custom field values from your Bitrix setup
 */
return [
    // Campaign configuration ID: 65704
    65704 => [
        [
            'bitrix_outcome' => 'SUCCESS',
            'workedCode' => 'W01',
            'resultCode' => 'RC01',
            'workedType' => 'CALL',
            'description' => 'Chiamata completata con successo',
        ],
        [
            'bitrix_outcome' => 'FAILED',
            'workedCode' => 'W02',
            'resultCode' => 'RC02',
            'workedType' => 'CALL',
            'description' => 'Chiamata fallita',
        ],
        [
            'bitrix_outcome' => 'NOT_INTERESTED',
            'workedCode' => 'W03',
            'resultCode' => 'RC03',
            'workedType' => 'CALL',
            'description' => 'Cliente non interessato',
        ],
        // Add more mappings as needed
    ],
    
    // Campaign configuration ID: 138663
    138663 => [
        [
            'bitrix_outcome' => 'SUCCESS',
            'workedCode' => 'W01',
            'resultCode' => 'RC01',
            'workedType' => 'CALL',
            'description' => 'Chiamata completata con successo',
        ],
        // Add more mappings as needed
    ],
    
    // Default mapping (used when no campaign-specific mapping exists)
    'default' => [
        [
            'bitrix_outcome' => 'SUCCESS',
            'workedCode' => 'W01',
            'resultCode' => 'RC01',
            'workedType' => 'CALL',
            'description' => 'Default: Success',
        ],
        [
            'bitrix_outcome' => 'FAILED',
            'workedCode' => 'W02',
            'resultCode' => 'RC02',
            'workedType' => 'CALL',
            'description' => 'Default: Failed',
        ],
    ],
];
