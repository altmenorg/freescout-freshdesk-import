<form class="form-horizontal margin-top margin-bottom" method="POST" action="" autocomplete="off">
    {{ csrf_field() }}

    <div class="form-group">
        <div class="col-sm-8 col-sm-offset-2">
            <p class="text-help">
                {{ __('Imports your Freshdesk tickets (replies, notes, attachments, customers, tags) into a FreeScout mailbox, then imports new and changed tickets on demand until you switch over.') }}
                <a href="https://github.com/altmenorg/freescout-freshdesk-import#readme" target="_blank" rel="noopener">{{ __('Guide') }}</a>
            </p>
        </div>
    </div>

    <h3 class="subheader">{{ __('Connection') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Freshdesk domain') }}</label>
        <div class="col-sm-6">
            <input type="text" name="settings[freshdeskimport.domain]" value="{{ $settings['freshdeskimport.domain'] }}" class="form-control input-sized-lg" placeholder="yourcompany.freshdesk.com" />
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('API key') }}</label>
        <div class="col-sm-6">
            <input type="password" name="settings[freshdeskimport.api_key]" value="{{ $settings['freshdeskimport.api_key'] }}" class="form-control input-sized-lg" autocomplete="new-password" />
            <p class="form-help">{{ __('Freshdesk › your profile picture › Profile settings › View API key. Use an administrator account so that all tickets are visible. Stored encrypted.') }}</p>
        </div>
    </div>

    <h3 class="subheader">{{ __('Import') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Into mailbox') }}</label>
        <div class="col-sm-6">
            <select name="settings[freshdeskimport.mailbox_id]" class="form-control input-sized-lg">
                <option value="">—</option>
                @foreach ($mailboxes as $mb)
                    <option value="{{ $mb->id }}" @if ((string)$settings['freshdeskimport.mailbox_id'] === (string)$mb->id) selected @endif>{{ $mb->name }} ({{ $mb->email }})</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Tickets updated since') }}</label>
        <div class="col-sm-6">
            <input type="date" name="settings[freshdeskimport.since]" value="{{ $settings['freshdeskimport.since'] }}" class="form-control input-sized" />
            <p class="form-help">{{ __('Optional. Leave empty to import all tickets.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Agents') }}</label>
        <div class="col-sm-6">
            <select name="settings[freshdeskimport.unmatched_agents]" class="form-control input-sized-lg">
                <option value="placeholder" @if ($settings['freshdeskimport.unmatched_agents'] == 'placeholder') selected @endif>{{ __('No FreeScout user with the same e-mail: create a disabled user') }}</option>
                <option value="unassigned" @if ($settings['freshdeskimport.unmatched_agents'] == 'unassigned') selected @endif>{{ __('No FreeScout user with the same e-mail: leave unassigned') }}</option>
            </select>
            <p class="form-help">{{ __('Freshdesk agents are matched to FreeScout users by e-mail. Create your users first to keep who answered what.') }}</p>
        </div>
    </div>

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>

<h3 class="subheader">{{ __('Status') }}</h3>
<div class="fdi-panel" data-status-url="{{ route('freshdeskimport.status') }}" data-action-url="{{ route('freshdeskimport.action') }}"
     data-confirm-start="{{ __('Start the import? Tickets are added to the chosen mailbox.') }}"
     data-confirm-forget="{{ __('Clear the import history? The imported conversations stay in FreeScout, but a new import would create them again.') }}"
     data-l-idle="{{ __('Not started') }}" data-l-running="{{ __('Running') }}" data-l-done="{{ __('Up to date') }}"
     data-l-stopped="{{ __('Stopped') }}" data-l-error="{{ __('Stopped on an error') }}"
     data-l-cron="{{ __('No batch has run for several minutes: check that the FreeScout cron job (php artisan schedule:run) is set up.') }}">
    <div class="fdi-status">
        <span class="fdi-badge"></span>
        <span class="fdi-message"></span>
    </div>
    <div class="fdi-counts">
        <div><strong class="fdi-n" data-k="imported">0</strong><span>{{ __('imported') }}</span></div>
        <div><strong class="fdi-n" data-k="updated">0</strong><span>{{ __('updated') }}</span></div>
        <div><strong class="fdi-n" data-k="kept">0</strong><span>{{ __('kept (changed in FreeScout)') }}</span></div>
        <div><strong class="fdi-n" data-k="errors">0</strong><span>{{ __('errors') }}</span></div>
        <div><strong class="fdi-cursor">—</strong><span>{{ __('last Freshdesk update processed') }}</span></div>
    </div>
    <div class="fdi-actions">
        <button type="button" class="btn btn-default" data-fdi="test">{{ __('Test connection') }}</button>
        <button type="button" class="btn btn-primary" data-fdi="start">{{ __('Start import') }}</button>
        <button type="button" class="btn btn-default" data-fdi="sync">{{ __('Import new and changed tickets') }}</button>
        <button type="button" class="btn btn-default" data-fdi="stop">{{ __('Stop') }}</button>
        <button type="button" class="btn btn-link text-danger" data-fdi="forget">{{ __('Clear import history') }}</button>
    </div>
    <p class="form-help">{{ __("Save your settings before starting. The import runs in the background, at the pace allowed by your Freshdesk plan's API limit: you can leave this page.") }}</p>
    <h4 class="fdi-log-title">{{ __('Log') }}</h4>
    <ul class="fdi-log"></ul>
</div>
