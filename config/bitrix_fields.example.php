<?php
/**
 * Bitrix24 custom field mapping configuration.
 * 
 * Maps InVoice data to Bitrix24 custom field IDs.
 * These custom fields are required for the reverse flow (Bitrix -> InVoice).
 * 
 * IMPORTANT: Copy this file to config/bitrix_fields.php and update with your actual field IDs.
 * The bitrix_fields.php file is NOT committed to the repository for security reasons.
 * 
 * To find your custom field IDs in Bitrix24:
 * 1. Go to Settings > CRM > Custom Fields
 * 2. Find the field and check its "CODE" (e.g., UF_CRM_1762455213)
 * 
 * Field Types:
 * - Double: Numeric field (for ID_ANAGRAFICA)
 * - String: Text field (for id_campagna)
 * - DateTime: Date/time field (for creation_date and DATA_SCADENZA)
 */
return [
    // InVoice ID Anagrafica (Double field)
    // Maps to: ID_ANAGRAFICA from InVoice lot data
    'id_anagrafica' => 'UF_CRM_1762455213',
    
    // InVoice Campaign ID (String field)
    // Maps to: id_campagna from InVoice slice event
    'id_campagna' => 'UF_CRM_1768978874430',
    
    // InVoice Start Date (DateTime field)
    // Maps to: creation_date from InVoice slice event (format: YYYY-MM-DD HH:mm:ss)
    'data_inizio' => 'UF_CRM_1762868578',
    
    // InVoice End Date (DateTime field)
    // Maps to: DATA_SCADENZA from InVoice lot data (converted from DD/MM/YYYY to YYYY-MM-DD 00:00:00)
    'data_fine' => 'UF_CRM_1762868603',
];
