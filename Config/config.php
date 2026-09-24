<?php

return [
    'name' => 'FreshdeskImport',
    // Seconds of work per background run (the FreeScout scheduler starts one run per minute).
    'batch_seconds' => (int)env('FRESHDESK_IMPORT_BATCH_SECONDS', 50),
    // Tickets fetched per Freshdesk API page (Freshdesk maximum: 100).
    'per_page' => 100,
    // Log lines kept in the database.
    'log_keep' => 500,
];
