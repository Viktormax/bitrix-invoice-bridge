<?php

/**
 * Pipeline per campo del lead (es. landing di provenienza).
 *
 * La landing passa un valore al widget In.Voice con ivGetExtraParams() (manuale ECO
 * §10.3.4); il valore torna nei dati del lead, nel campo del tracciato che Enel abilita.
 * Copiare in lead_field_pipelines.php per attivarlo.
 *
 * - field: nome del campo nel tracciato (come lo comunica Enel). Vuoto = regola spenta.
 * - map:   valore del campo => pipeline (CATEGORY_ID).
 * I lead senza il campo, o con un valore non in mappa, seguono campaign_pipelines.php e
 * poi PIPELINE del .env: la landing esistente non cambia.
 */
return [
    'field' => '',            // es. 'LANDING' — da Enel
    'map' => [
        'offerta-luce-gas' => 29, // Geomad - Nuovo cliente
    ],
];
