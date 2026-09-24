<?php

namespace Modules\FreshdeskImport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\FreshdeskImport\Services\Importer;

/** Buttons of the settings page (test / start / sync / stop / reset) and live status (polled by Public/js/module.js). */
class FreshdeskImportController extends Controller
{
    public function action(Request $request)
    {
        $user_id = auth()->user()->id;
        try {
            switch ($request->input('action')) {
                case 'test':
                    return $this->ok(__('Connected to Freshdesk as :name', ['name' => Importer::testConnection()]));
                case 'start':
                    $this->checkReady();
                    Importer::start($user_id);
                    return $this->ok(__('Import started: the first batch runs within a minute.'));
                case 'sync':
                    $this->checkReady();
                    Importer::sync($user_id);
                    return $this->ok(__('Sync started: new and changed tickets are imported within a minute.'));
                case 'stop':
                    Importer::stop();
                    return $this->ok(__('Import stopped.'));
                case 'forget':
                    // forget the import history (not the imported conversations): a new import would duplicate them
                    \DB::table('freshdesk_import_tickets')->delete();
                    \DB::table('freshdesk_import_agents')->delete();
                    \DB::table('freshdesk_import_logs')->delete();
                    \Option::remove(Importer::OPTION_STATE);
                    return $this->ok(__('Import history cleared.'));
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        return response()->json(['status' => 'error', 'msg' => 'Unknown action']);
    }

    public function status()
    {
        $state = Importer::state();
        $logs = \DB::table('freshdesk_import_logs')->orderBy('id', 'desc')->limit(40)->get()->map(function ($l) {
            return ['level' => $l->level, 'message' => $l->message, 'at' => $l->created_at];
        });
        return response()->json([
            'state'    => $state,
            'tickets'  => \DB::table('freshdesk_import_tickets')->count(),
            'logs'     => $logs,
            'cron_ok'  => $this->cronLooksAlive($state),
        ]);
    }

    protected function checkReady()
    {
        if (!Importer::setting('domain') || Importer::apiKey() === '') {
            throw new \Exception(__('Fill in the Freshdesk domain and API key, then save.'));
        }
        if (!\App\Mailbox::find((int)Importer::setting('mailbox_id'))) {
            throw new \Exception(__('Choose the mailbox that will receive the tickets, then save.'));
        }
        if (Importer::state()['status'] == Importer::STATUS_RUNNING) {
            throw new \Exception(__('An import is already running.'));
        }
    }

    /** Running for more than 3 minutes without a batch: the FreeScout cron job is probably not set up. */
    protected function cronLooksAlive(array $state)
    {
        if ($state['status'] != Importer::STATUS_RUNNING) {
            return true;
        }
        $ref = $state['last_run_at'] ?: $state['started_at'];
        return !$ref || (time() - strtotime($ref)) < 180 || $state['paused_until'] > time();
    }

    protected function ok($msg)
    {
        return response()->json(['status' => 'success', 'msg' => $msg]);
    }
}
