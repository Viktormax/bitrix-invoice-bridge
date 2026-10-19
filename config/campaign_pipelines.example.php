<?php

/**
 * Pipeline per campagna.
 * Maps InVoice id_config_campagna to the Bitrix24 deal pipeline (CATEGORY_ID).
 *
 * Copy this file to campaign_pipelines.php to enable it.
 *
 * Campaigns NOT listed here keep the default pipeline (PIPELINE in .env).
 * If the file is missing, every campaign uses the default: existing behavior.
 *
 * Format: id_config_campagna => pipeline (CATEGORY_ID)
 */
return [
    // Example: leads from the new widget campaign go to a dedicated pipeline
    // 123456 => 25,
];
