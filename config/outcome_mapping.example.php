<?php
/**
 * Outcome mapping configuration.
 * 
 * Maps Bitrix24 custom field outcome IDs to InVoice result codes.
 * This mapping is required for the reverse flow (Bitrix -> InVoice) when processing
 * activity outcomes that are manually updated by users.
 * 
 * IMPORTANT: Copy this file to config/outcome_mapping.php and update with your actual mappings.
 * The outcome_mapping.php file is NOT committed to the repository for security and customization reasons.
 * 
 * Structure:
 * - Keys: Bitrix24 custom field outcome ID (integer)
 * - Values: InVoice result code (string, e.g., 'D109', 'D106')
 * 
 * To find your outcome IDs in Bitrix24:
 * 1. Go to the custom field configuration
 * 2. Check the enum values and their IDs
 * 3. Map each ID to the corresponding InVoice result code
 * 
 * Example:
 * - Bitrix outcome ID 717 maps to InVoice result code 'D109'
 * - Bitrix outcome ID 719 maps to InVoice result code 'D106'
 */
return [
    // Bitrix outcome ID => InVoice result code
    717 => 'D109',
    719 => 'D106',
    721 => 'D103',
    723 => 'D102',
    725 => 'D105',
    727 => 'D101',
    729 => 'D107',
    731 => 'D108',
    733 => 'D201',
    735 => 'D003',
    737 => 'D004',
    739 => 'D010',
    741 => 'D001',
    743 => 'D002',
];
