<?php

namespace Modules\FreshdeskImport\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\FreshdeskImport\Services\Importer;

define('FRESHDESKIMPORT_MODULE', 'freshdeskimport');

/**
 * Freshdesk Import: settings page (Manage > Settings > Freshdesk Import) with start / sync / stop buttons and a live
 * status; the work itself runs in batches from the FreeScout scheduler (Console\ImportRun, every minute).
 */
class FreshdeskImportServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'freshdeskimport');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'freshdeskimport');
        $this->loadJsonTranslationsFrom(__DIR__.'/../Resources/lang');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->commands([\Modules\FreshdeskImport\Console\ImportRun::class]);
        $this->registerSettings();

        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('freescout:freshdesk-import-run')
                ->cron('* * * * *')
                ->withoutOverlapping($expires_at = 30 /* minutes */);
            return $schedule;
        });

        // settings page script (a file: FreeScout's CSP blocks inline scripts)
        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(FRESHDESKIMPORT_MODULE).'/js/module.js';
            return $javascripts;
        });
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(FRESHDESKIMPORT_MODULE).'/css/module.css';
            return $styles;
        });
    }

    protected function registerSettings()
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[FRESHDESKIMPORT_MODULE] = ['title' => __('Freshdesk Import'), 'icon' => 'import', 'order' => 700];
            return $sections;
        }, 40);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section != FRESHDESKIMPORT_MODULE) {
                return $settings;
            }
            $settings['freshdeskimport.domain'] = Importer::setting('domain', '');
            $settings['freshdeskimport.api_key'] = Importer::apiKey() !== '' ? '********' : '';
            $settings['freshdeskimport.mailbox_id'] = Importer::setting('mailbox_id', '');
            $settings['freshdeskimport.since'] = Importer::setting('since', '');
            $settings['freshdeskimport.unmatched_agents'] = Importer::setting('unmatched_agents', 'placeholder');
            return $settings;
        }, 20, 2);

        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section != FRESHDESKIMPORT_MODULE) {
                return $params;
            }
            return [
                'template_vars' => [
                    'mailboxes' => \App\Mailbox::orderBy('name')->get(),
                    'state'     => Importer::state(),
                ],
                'settings' => [
                    'freshdeskimport.api_key' => ['safe_password' => true, 'encrypt' => true],
                ],
            ];
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section == FRESHDESKIMPORT_MODULE ? 'freshdeskimport::settings' : $view;
        }, 20, 2);
    }

    public function register()
    {
    }

    public function provides()
    {
        return [];
    }
}
