<?php

namespace Modules\FreshdeskImport\Console;

use Illuminate\Console\Command;
use Modules\FreshdeskImport\Services\Importer;

/** One batch of the Freshdesk import; started every minute by the FreeScout scheduler while an import is running. */
class ImportRun extends Command
{
    protected $signature = 'freescout:freshdesk-import-run {--seconds= : seconds of work (default: config batch_seconds)}';

    protected $description = 'Run one batch of the Freshdesk import, if one is running.';

    public function handle()
    {
        $seconds = (int)($this->option('seconds') ?: config('freshdeskimport.batch_seconds', 50));
        (new Importer())->runBatch($seconds);
        $s = Importer::state();
        $this->line('Freshdesk import: '.$s['status'].' | cursor '.$s['cursor'].' | '.json_encode($s['counts']));
    }
}
