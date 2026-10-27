<?php

/**
 * Pipeline per campo del lead (landing di provenienza).
 *
 * La landing passa un valore al widget In.Voice con ivGetExtraParams() (manuale ECO
 * §10.3.4); il valore torna nei dati del lead, nel campo del tracciato che Enel abilita.
 * Copiare in lead_field_pipelines.php per attivarlo.
 *
 * - field: nome del campo nel tracciato (come lo comunica Enel). Vuoto = regola spenta.
 * - map:   valore del campo => pipeline (CATEGORY_ID).
 * I lead senza il campo, o con un valore non in mappa, seguono campaign_pipelines.php e
 * poi PIPELINE del .env: la landing esistente non cambia.
 *
 * Enel 26/10/2026: campo SOURCE_TYPE, WIDGET_A = landing originale (resta su PIPELINE del .env),
 * WIDGET_B = agenziaenel.com/offerta-luce-gas → pipeline 29 (Geomad - Nuovo cliente).
 */
return [
    'field' => 'SOURCE_TYPE',
    'map' => [
        'WIDGET_B' => 29, // Geomad - Nuovo cliente
    ],
];
