<?php

/**
 * Pipeline per campo del lead (landing di provenienza).
 *
 * La landing passa un valore al widget In.Voice con ivGetExtraParams() (manuale ECO
 * §10.3.4); il valore torna nei dati del lead, nel campo del tracciato che Enel abilita.
 * Copiare in lead_field_pipelines.php per attivarlo.
 *
 * - rules: elenco di regole, provate in ordine; la prima che trova un valore in mappa vince.
 *   - field: nome del campo nel tracciato. Vuoto = regola spenta.
 *   - map:   valore del campo => pipeline (CATEGORY_ID). Vale anche "valore-qualcosa"
 *            (es. EXTERNAL_ID "WIDGET_B-1793…" → "WIDGET_B").
 * I lead senza il campo, o con un valore non in mappa, seguono campaign_pipelines.php e
 * poi PIPELINE del .env: la landing esistente non cambia.
 *
 * Enel 26/10/2026: campo SOURCE_TYPE, WIDGET_A = landing originale (resta su PIPELINE del .env),
 * WIDGET_B = agenziaenel.com/offerta-luce-gas → pipeline 29 (Geomad - Nuovo cliente).
 * 27/10/2026: SOURCE_TYPE non torna ancora nei lead; la landing B passa anche EXTERNAL_ID
 * (campo già nel tracciato, vuoto da marzo) come "WIDGET_B-<univoco>".
 */
return [
    'rules' => [
        ['field' => 'SOURCE_TYPE', 'map' => ['WIDGET_B' => 29]], // Geomad - Nuovo cliente
        ['field' => 'EXTERNAL_ID', 'map' => ['WIDGET_B' => 29]],
    ],
];
