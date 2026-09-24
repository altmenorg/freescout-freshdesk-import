<?php

namespace Modules\FreshdeskImport\Services;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use App\User;

/**
 * Freshdesk -> FreeScout importer, run in small batches by the scheduler (freescout:freshdesk-import-run, every minute).
 *
 * Tickets are read in "updated_at" order from a cursor (last updated_at processed). The first run starts from the
 * beginning (or from the "import tickets updated since" date); a sync simply continues from the cursor. A ticket already
 * imported is re-imported when it changed in Freshdesk, unless it got a reply or note written in FreeScout since: it is
 * then left untouched and reported in the log.
 */
class Importer
{
    const OPTION_STATE = 'freshdeskimport.state';

    const STATUS_IDLE = 'idle';
    const STATUS_RUNNING = 'running';
    const STATUS_DONE = 'done';
    const STATUS_STOPPED = 'stopped';
    const STATUS_ERROR = 'error';

    const MAX_ERRORS_PER_RUN = 50;

    /** Freshdesk "source" of tickets created by an agent (outbound e-mail). */
    const SOURCE_OUTBOUND_EMAIL = 10;

    protected $api;
    protected $mailbox;
    protected $state;
    protected $customers = [];
    protected $agents = null;
    protected $agentsForbidden = false;

    /* ================================================================ settings, state, log */

    public static function setting($key, $default = null)
    {
        return \Option::get('freshdeskimport.'.$key, $default);
    }

    public static function apiKey()
    {
        $stored = self::setting('api_key', '');
        if (!$stored) {
            return '';
        }
        try {
            return trim((string)decrypt($stored));
        } catch (\Exception $e) {
            return '';
        }
    }

    public static function api()
    {
        return new FreshdeskApi(self::setting('domain', ''), self::apiKey());
    }

    public static function state()
    {
        // Option::get() already decodes arrays stored by Option::set(); accept a JSON string too
        $raw = \Option::get(self::OPTION_STATE, null);
        $s = is_array($raw) ? $raw : json_decode((string)$raw, true);
        return array_merge([
            'status'       => self::STATUS_IDLE,
            'cursor'       => null,
            'page'         => 1,
            'paused_until' => 0,
            'started_at'   => null,
            'last_run_at'  => null,
            'user_id'      => null,
            'message'      => '',
            'counts'       => ['imported' => 0, 'updated' => 0, 'kept' => 0, 'skipped' => 0, 'errors' => 0],
        ], is_array($s) ? $s : []);
    }

    public static function saveState(array $state)
    {
        \Option::set(self::OPTION_STATE, $state);
    }

    public static function log($message, $level = 'info')
    {
        \DB::table('freshdesk_import_logs')->insert(['level' => $level, 'message' => mb_substr((string)$message, 0, 2000), 'created_at' => date('Y-m-d H:i:s')]);
        // keep the table small
        $keep = (int)config('freshdeskimport.log_keep', 500);
        $max = \DB::table('freshdesk_import_logs')->max('id');
        if ($max && $max % 100 == 0) {
            \DB::table('freshdesk_import_logs')->where('id', '<=', $max - $keep)->delete();
        }
    }

    /* ================================================================ actions from the settings page */

    /** Check the domain and API key; returns the name of the Freshdesk agent owning the key. */
    public static function testConnection()
    {
        $me = self::api()->get('agents/me');
        return $me['contact']['name'] ?? ($me['contact']['email'] ?? 'OK');
    }

    /** Start a full import (from the beginning, or from the "since" setting). */
    public static function start($user_id)
    {
        $state = self::state();
        $since = self::setting('since', '');
        $state = array_merge($state, [
            'status'       => self::STATUS_RUNNING,
            'cursor'       => $since ? gmdate('Y-m-d\TH:i:s\Z', strtotime($since.' 00:00:00 UTC')) : '2000-01-01T00:00:00Z',
            'page'         => 1,
            'paused_until' => 0,
            'started_at'   => date('Y-m-d H:i:s'),
            'user_id'      => (int)$user_id,
            'message'      => '',
            'counts'       => ['imported' => 0, 'updated' => 0, 'kept' => 0, 'skipped' => 0, 'errors' => 0],
        ]);
        self::saveState($state);
        self::log('Import started');
    }

    /** Continue from where the last run stopped: new and changed tickets only. */
    public static function sync($user_id)
    {
        $state = self::state();
        if (!$state['cursor']) {
            self::start($user_id);
            return;
        }
        $state['status'] = self::STATUS_RUNNING;
        $state['page'] = 1;
        $state['paused_until'] = 0;
        $state['user_id'] = (int)$user_id;
        $state['message'] = '';
        self::saveState($state);
        self::log('Sync started (changes since '.$state['cursor'].')');
    }

    public static function stop()
    {
        $state = self::state();
        if ($state['status'] == self::STATUS_RUNNING) {
            $state['status'] = self::STATUS_STOPPED;
            $state['message'] = '';
            self::saveState($state);
            self::log('Stopped by user');
        }
    }

    /* ================================================================ background batch */

    /** One batch of work, at most $seconds long. Called every minute by the scheduler. */
    public function runBatch($seconds = 50)
    {
        $this->state = self::state();
        if ($this->state['status'] != self::STATUS_RUNNING) {
            return;
        }
        if ($this->state['paused_until'] > time()) {
            return;
        }
        $this->mailbox = Mailbox::find((int)self::setting('mailbox_id'));
        if (!$this->mailbox) {
            $this->fail('Target mailbox not found: choose one in the settings.');
            return;
        }
        $this->api = self::api();
        $deadline = time() + max(10, (int)$seconds);
        $run_errors = 0;
        $this->state['last_run_at'] = date('Y-m-d H:i:s');

        try {
            $this->loadAgents();
            while (time() < $deadline) {
                $cursor_at_start = $this->state['cursor'];
                $page = (int)$this->state['page'];
                $tickets = $this->api->get('tickets?updated_since='.rawurlencode($cursor_at_start)
                    .'&include=requester,description,stats&order_by=updated_at&order_type=asc'
                    .'&per_page='.(int)config('freshdeskimport.per_page', 100).'&page='.$page);
                if (!is_array($tickets) || !count($tickets)) {
                    $this->finish();
                    break;
                }
                $interrupted = false;
                foreach ($tickets as $t) {
                    if (time() >= $deadline) {
                        $interrupted = true;
                        break;
                    }
                    try {
                        $this->processTicket($t);
                    } catch (RateLimited $e) {
                        throw $e;
                    } catch (\Exception $e) {
                        $this->state['counts']['errors']++;
                        $run_errors++;
                        self::log('Ticket #'.($t['id'] ?? '?').': '.$e->getMessage(), 'error');
                        if ($run_errors >= self::MAX_ERRORS_PER_RUN) {
                            $this->fail('Too many errors, import stopped: see the log.');
                            return;
                        }
                    }
                    if (!empty($t['updated_at']) && $t['updated_at'] > $this->state['cursor']) {
                        $this->state['cursor'] = $t['updated_at'];
                    }
                    self::saveState($this->state);
                }
                if ($interrupted) {
                    break;
                }
                if (count($tickets) < (int)config('freshdeskimport.per_page', 100)) {
                    $this->finish();
                    break;
                }
                // Full page: next query restarts from the new cursor (page 1). If the whole page shared the same
                // updated_at, the cursor did not move: take the next page instead, or we would loop forever.
                $this->state['page'] = ($this->state['cursor'] == $cursor_at_start) ? $page + 1 : 1;
                self::saveState($this->state);
            }
        } catch (RateLimited $e) {
            $this->state['paused_until'] = time() + $e->retryAfter;
            $this->state['message'] = 'Freshdesk rate limit: paused until '.date('H:i:s', $this->state['paused_until']);
            self::saveState($this->state);
            self::log($e->getMessage(), 'warning');
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
        $this->mailbox->updateFoldersCounters();
    }

    protected function finish()
    {
        $c = $this->state['counts'];
        $this->state['status'] = self::STATUS_DONE;
        $this->state['page'] = 1;
        $this->state['message'] = '';
        self::saveState($this->state);
        self::log('Up to date: '.$c['imported'].' imported, '.$c['updated'].' updated, '.$c['kept'].' kept (changed in FreeScout), '.$c['errors'].' errors');
    }

    protected function fail($message)
    {
        $this->state['status'] = self::STATUS_ERROR;
        $this->state['message'] = $message;
        self::saveState($this->state);
        self::log($message, 'error');
    }

    /* ================================================================ agents */

    /** Freshdesk agents -> FreeScout users: same e-mail, otherwise a placeholder user or nobody (setting). */
    protected function loadAgents()
    {
        if ($this->agents !== null) {
            return;
        }
        $this->agents = [];
        if (!empty($this->state['agents_forbidden'])) {
            $this->agentsForbidden = true;
            return;
        }
        foreach (\DB::table('freshdesk_import_agents')->get() as $row) {
            $this->agents[(string)$row->fd_agent_id] = $row->user_id ? (int)$row->user_id : null;
        }
        if ($this->agents) {
            return;
        }
        $page = 1;
        try {
            do {
                $list = $this->api->get('agents?per_page=100&page='.$page);
                foreach ($list ?: [] as $a) {
                    $this->mapAgent($a);
                }
                $page++;
            } while (is_array($list) && count($list) == 100);
        } catch (Forbidden $e) {
            // key of a non-administrator agent: tickets can still be read, agents cannot be listed
            $this->agentsForbidden = true;
            $this->state['agents_forbidden'] = true;
            self::saveState($this->state);
            self::log('The API key belongs to a Freshdesk agent who cannot list agents (not an administrator): tickets are imported unassigned and replies are credited to you. Use an administrator\'s API key to keep who answered what.', 'warning');
        }
    }

    protected function mapAgent(array $a)
    {
        $id = (string)$a['id'];
        $name = trim((string)($a['contact']['name'] ?? ''));
        $email = strtolower(trim((string)($a['contact']['email'] ?? '')));
        $user = $email ? User::whereRaw('LOWER(email) = ?', [$email])->first() : null;
        if (!$user && self::setting('unmatched_agents', 'placeholder') == 'placeholder') {
            // disabled user named after the Freshdesk agent: keeps who answered what, cannot log in
            list($first, $last) = $this->splitName($name ?: 'Freshdesk agent '.$id);
            $user = new User();
            $user->first_name = mb_substr($first, 0, 20);
            $user->last_name = mb_substr($last, 0, 30);
            $user->email = 'freshdesk-agent-'.$id.'@import.invalid';
            $user->password = bcrypt(bin2hex(random_bytes(16)));
            $user->role = User::ROLE_USER;
            $user->status = User::STATUS_DISABLED;
            $user->save();
            $this->mailbox->users()->syncWithoutDetaching([$user->id]);
            self::log('Freshdesk agent "'.($name ?: $id).'" has no FreeScout user with the same e-mail: created disabled user #'.$user->id);
        }
        \DB::table('freshdesk_import_agents')->insert(['fd_agent_id' => $a['id'], 'user_id' => $user ? $user->id : null, 'name' => mb_substr($name, 0, 191), 'email' => mb_substr($email, 0, 191)]);
        $this->agents[$id] = $user ? (int)$user->id : null;
    }

    /** FreeScout user for a Freshdesk agent id; unknown agents (deleted since) are fetched and mapped on the fly. */
    protected function agentUserId($fd_agent_id)
    {
        if (!$fd_agent_id) {
            return null;
        }
        $key = (string)$fd_agent_id;
        if ($this->agentsForbidden) {
            return null;
        }
        if (!array_key_exists($key, $this->agents)) {
            try {
                $a = $this->api->get('agents/'.$key);
            } catch (RateLimited $e) {
                throw $e;
            } catch (\Exception $e) {
                $a = ['id' => $fd_agent_id, 'contact' => ['name' => '', 'email' => '']];
            }
            $this->mapAgent($a ?: ['id' => $fd_agent_id, 'contact' => []]);
        }
        return $this->agents[$key];
    }

    /** Author of agent threads when the agent has no FreeScout user: the admin who started the import. */
    protected function fallbackUserId()
    {
        return (int)($this->state['user_id'] ?: (User::where('role', User::ROLE_ADMIN)->value('id') ?: 1));
    }

    /* ================================================================ tickets */

    protected function processTicket(array $t)
    {
        $fd_id = (int)$t['id'];
        $row = \DB::table('freshdesk_import_tickets')->where('fd_ticket_id', $fd_id)->first();
        if ($row && $row->fd_updated_at && $this->sameTime($row->fd_updated_at, $t['updated_at'])) {
            $this->state['counts']['skipped']++;
            return; // unchanged since the last import (happens at page boundaries)
        }
        $reuse = null;
        if ($row) {
            $reuse = Conversation::find($row->conversation_id);
            if ($reuse && Thread::where('conversation_id', $reuse->id)->where('imported', 0)->exists()) {
                // replied to or noted in FreeScout since the import: never overwrite work done here
                \DB::table('freshdesk_import_tickets')->where('fd_ticket_id', $fd_id)->update(['fd_updated_at' => $this->dbTime($t['updated_at'])]);
                $this->state['counts']['kept']++;
                self::log('Ticket #'.$fd_id.' (conversation #'.$reuse->number.') changed in both tools: kept as is in FreeScout', 'warning');
                return;
            }
        }

        // everything from Freshdesk first (API calls, downloads), then a short database transaction
        $conversations = [];
        $page = 1;
        do {
            $chunk = $this->api->get('tickets/'.$fd_id.'/conversations?per_page=100&page='.$page);
            $conversations = array_merge($conversations, $chunk ?: []);
            $page++;
        } while (is_array($chunk) && count($chunk) == 100);
        usort($conversations, function ($a, $b) {
            return strcmp($a['created_at'], $b['created_at']);
        });
        $t['_files'] = $this->downloadAttachments($t['attachments'] ?? []);
        foreach ($conversations as $i => $c) {
            $conversations[$i]['_files'] = $this->downloadAttachments($c['attachments'] ?? []);
        }

        \DB::beginTransaction();
        try {
            $conv = $this->saveTicket($t, $conversations, $reuse);
            \DB::table('freshdesk_import_tickets')->updateOrInsert(
                ['fd_ticket_id' => $fd_id],
                ['conversation_id' => $conv->id, 'fd_updated_at' => $this->dbTime($t['updated_at']), 'imported_at' => date('Y-m-d H:i:s')]
            );
            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
        $this->syncTags($conv, $t['tags'] ?? []);
        $this->state['counts'][$reuse ? 'updated' : 'imported']++;
    }

    protected function saveTicket(array $t, array $conversations, $reuse)
    {
        $req = $t['requester'] ?? [];
        $customer = $this->customer($req['email'] ?? '', $req['name'] ?? '', $req['phone'] ?? '', $req['mobile'] ?? '');
        $status = $this->status($t);
        $assignee = $this->agentUserId($t['responder_id'] ?? null);

        if ($reuse) {
            foreach ($reuse->threads as $old) {
                foreach ($old->attachments as $a) {
                    $a->deleteForever();
                }
                $old->delete();
            }
            $conv = $reuse;
        } else {
            $conv = new Conversation();
        }
        $conv->type = Conversation::TYPE_EMAIL;
        $conv->subject = mb_substr((string)($t['subject'] ?: '(no subject)'), 0, 998);
        $conv->mailbox_id = $this->mailbox->id;
        $conv->customer_id = $customer->id;
        $conv->customer_email = (string)$customer->getMainEmail();
        $conv->status = $status;
        $conv->state = Conversation::STATE_PUBLISHED;
        $conv->imported = true;
        $conv->user_id = $assignee;
        $conv->source_via = ((int)($t['source'] ?? 0) == self::SOURCE_OUTBOUND_EMAIL) ? Conversation::PERSON_USER : Conversation::PERSON_CUSTOMER;
        $conv->source_type = Conversation::SOURCE_TYPE_EMAIL;
        $conv->created_at = $this->localTime($t['created_at']);
        $conv->closed_at = ($status == Conversation::STATUS_CLOSED)
            ? $this->localTime($t['stats']['closed_at'] ?? ($t['stats']['resolved_at'] ?? $t['updated_at']))
            : null;
        $conv->setCc($this->replyCc($t, $conv->customer_email));
        $conv->setMeta('fd_id', (int)$t['id']);
        foreach (['priority' => 'fd_priority', 'type' => 'fd_type'] as $field => $meta) {
            if (!empty($t[$field])) {
                $conv->setMeta($meta, $t[$field]);
            }
        }
        if (!empty($t['tags'])) {
            $conv->setMeta('fd_tags', array_values($t['tags']));
        }
        $custom = array_filter($t['custom_fields'] ?? [], function ($v) {
            return $v !== null && $v !== '' && $v !== false;
        });
        if ($custom) {
            $conv->setMeta('fd_custom_fields', $custom);
        }
        $conv->updateFolder();
        $conv->save();

        // 1) the ticket description: by the customer, or by the agent for an outbound e-mail
        $outbound = ((int)($t['source'] ?? 0) == self::SOURCE_OUTBOUND_EMAIL);
        $body = (string)($t['description'] ?: nl2br(e($t['description_text'] ?? '')));
        if ($outbound) {
            $first = $this->thread($conv, Thread::TYPE_MESSAGE, $body, [
                'to' => [$customer->getMainEmail()], 'customer_id' => $customer->id,
                'created_by_user_id' => $assignee ?: $this->fallbackUserId(),
                'source_via' => Thread::PERSON_USER, 'source_type' => Thread::SOURCE_TYPE_EMAIL, 'user_id' => $assignee,
            ], $t['created_at'], $status);
        } else {
            $first = $this->thread($conv, Thread::TYPE_CUSTOMER, $body, [
                'from' => $customer->getMainEmail(), 'customer_id' => $customer->id, 'created_by_customer_id' => $customer->id,
                'source_via' => Thread::PERSON_CUSTOMER, 'source_type' => Thread::SOURCE_TYPE_EMAIL, 'user_id' => $assignee,
            ], $t['created_at'], $status);
        }
        $has_att = $this->attach($first, $t['_files'], $outbound ? $assignee : null);
        $threads = 1;
        $last_reply_at = $t['created_at'];
        $last_from = $outbound ? Conversation::PERSON_USER : Conversation::PERSON_CUSTOMER;
        $last_customer_at = $outbound ? null : $t['created_at'];
        $preview = (string)($t['description_text'] ?? '');

        // 2) replies, customer messages and private notes
        foreach ($conversations as $c) {
            $body = (string)($c['body'] ?: nl2br(e($c['body_text'] ?? '')));
            $user_id = null;
            if (!empty($c['incoming'])) {
                $from = $this->emailOf($c['from_email'] ?? '');
                $cust = ($from && $from !== strtolower((string)$customer->getMainEmail()))
                    ? $this->customer($from, $this->nameOf($c['from_email'] ?? ''))
                    : $customer;
                $th = $this->thread($conv, Thread::TYPE_CUSTOMER, $body, [
                    'from' => $cust->getMainEmail(), 'to' => array_map([$this, 'emailOf'], $c['to_emails'] ?? []),
                    'customer_id' => $cust->id, 'created_by_customer_id' => $cust->id,
                    'source_via' => Thread::PERSON_CUSTOMER, 'source_type' => Thread::SOURCE_TYPE_EMAIL, 'user_id' => $assignee,
                ], $c['created_at'], $status);
                $last_from = Conversation::PERSON_CUSTOMER;
                $last_customer_at = $c['created_at'];
            } else {
                $user_id = $this->agentUserId($c['user_id'] ?? null) ?: $this->fallbackUserId();
                $note = !empty($c['private']);
                $th = $this->thread($conv, $note ? Thread::TYPE_NOTE : Thread::TYPE_MESSAGE, $body, [
                    'to' => array_map([$this, 'emailOf'], $c['to_emails'] ?? []), 'cc' => array_map([$this, 'emailOf'], $c['cc_emails'] ?? []),
                    'bcc' => array_map([$this, 'emailOf'], $c['bcc_emails'] ?? []),
                    'customer_id' => $customer->id, 'created_by_user_id' => $user_id,
                    'source_via' => Thread::PERSON_USER, 'source_type' => Thread::SOURCE_TYPE_EMAIL, 'user_id' => $assignee,
                ], $c['created_at'], $status);
                if (!$note) {
                    $last_from = Conversation::PERSON_USER;
                }
            }
            if ($this->attach($th, $c['_files'], $user_id)) {
                $has_att = true;
            }
            $threads++;
            if (empty($c['private'])) {
                $last_reply_at = $c['created_at'];
                $preview = (string)($c['body_text'] ?? '');
            }
        }

        $conv->threads_count = $threads;
        $conv->last_reply_at = $this->localTime($last_reply_at);
        $conv->last_reply_from = $last_from;
        $conv->last_customer_reply_at = $last_customer_at ? $this->localTime($last_customer_at) : null;
        $conv->has_attachments = $has_att;
        $conv->setPreview($preview);
        $conv->updated_at = $this->localTime($t['updated_at']);
        $conv->timestamps = false; // keep Freshdesk's updated_at
        $conv->save();
        $conv->timestamps = true;
        return $conv;
    }

    protected function thread($conv, $type, $body, array $data, $created_at, $status)
    {
        $thread = Thread::create($conv, $type, $body !== '' ? $body : '&nbsp;', $data, false);
        $thread->imported = true;
        $thread->status = $status;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->created_at = $this->localTime($created_at);
        $thread->updated_at = $this->localTime($created_at);
        $thread->save();
        $this->embedInlineImages($thread);
        return $thread;
    }

    /* ================================================================ attachments and inline images */

    protected function downloadAttachments(array $list)
    {
        $files = [];
        foreach ($list as $a) {
            if (empty($a['attachment_url'])) {
                continue;
            }
            $content = $this->api->download($a['attachment_url']);
            if ($content === null || $content === '') {
                self::log('Attachment "'.($a['name'] ?? $a['id']).'" could not be downloaded', 'warning');
                continue;
            }
            $files[] = ['name' => (string)($a['name'] ?: 'file'), 'mime' => (string)($a['content_type'] ?: 'application/octet-stream'), 'content' => $content];
        }
        return $files;
    }

    protected function attach($thread, array $files, $user_id = null)
    {
        $added = 0;
        foreach ($files as $f) {
            if (Attachment::create($f['name'], $f['mime'], null, $f['content'], null, false, $thread->id, $user_id)) {
                $added++;
            }
        }
        if ($added) {
            $thread->has_attachments = true;
            $thread->save();
        }
        return $added > 0;
    }

    /**
     * Images pasted in Freshdesk messages point to Freshdesk URLs that expire: copy them into FreeScout as embedded
     * attachments and rewrite the <img src>.
     */
    protected function embedInlineImages($thread)
    {
        $body = (string)$thread->body;
        if (stripos($body, '<img') === false) {
            return;
        }
        $changed = false;
        $body = preg_replace_callback('#(<img\b[^>]*?\bsrc=)(["\'])(https?://[^"\']+)\2#i', function ($m) use ($thread, &$changed) {
            $url = html_entity_decode($m[3]);
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            if (!preg_match('#(^|\.)(freshdesk\.com|freshworks\.com|freshdesk\.io)$|^s3[.-].*amazonaws\.com$#', $host)) {
                return $m[0]; // external image (newsletter, signature…): leave it
            }
            $content = $this->api->download($url);
            if (!$content) {
                return $m[0];
            }
            $mime = 'image/png';
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_buffer($fi, $content) ?: $mime;
                finfo_close($fi);
            }
            $ext = explode('/', $mime)[1] ?? 'png';
            $att = Attachment::create('image-'.substr(md5($url), 0, 8).'.'.$ext, $mime, null, $content, null, true, $thread->id);
            if (!$att) {
                return $m[0];
            }
            $changed = true;
            return $m[1].$m[2].$att->url().$m[2];
        }, $body);
        if ($changed) {
            $thread->body = $body;
            $thread->save();
        }
    }

    /* ================================================================ tags */

    protected function syncTags($conv, array $names)
    {
        if (!class_exists('\Modules\Tags\Entities\Tag') || !\Module::isActive('tags')) {
            return;
        }
        $names = array_values(array_filter(array_map('trim', $names)));
        try {
            $current = \Modules\Tags\Entities\Tag::conversationTags($conv)->pluck('name')->all();
            foreach ($names as $name) {
                if (!in_array($name, $current)) {
                    \Modules\Tags\Entities\Tag::attachByName($name, $conv->id);
                }
            }
            foreach ($current as $name) {
                if (!in_array($name, $names)) {
                    \Modules\Tags\Entities\Tag::detachByName($name, $conv->id);
                }
            }
        } catch (\Exception $e) {
            self::log('Tags of conversation #'.$conv->number.': '.$e->getMessage(), 'warning');
        }
    }

    /* ================================================================ helpers */

    protected function customer($email, $name, $phone = '', $mobile = '')
    {
        $email = strtolower(trim((string)$email));
        $key = $email ?: 'name:'.$name;
        if (isset($this->customers[$key])) {
            return $this->customers[$key];
        }
        list($first, $last) = $this->splitName($name);
        $data = ['first_name' => mb_substr($first, 0, 100), 'last_name' => mb_substr($last, 0, 100)];
        $customer = $email ? Customer::create($email, $data) : null;
        if (!$customer) {
            $customer = Customer::createWithoutEmail(['first_name' => mb_substr($first ?: 'Customer', 0, 100), 'last_name' => mb_substr($last, 0, 100)]);
        }
        $phones = array_column($customer->getPhones(), 'value');
        $added = false;
        foreach ([[$phone, Customer::PHONE_TYPE_WORK], [$mobile, Customer::PHONE_TYPE_MOBILE]] as $p) {
            $value = trim((string)$p[0]);
            if ($value !== '' && !in_array($value, $phones)) {
                $customer->addPhone($value, $p[1]);
                $phones[] = $value;
                $added = true;
            }
        }
        if ($added) {
            $customer->save();
        }
        return $this->customers[$key] = $customer;
    }

    protected function status(array $t)
    {
        if (!empty($t['spam'])) {
            return Conversation::STATUS_SPAM;
        }
        switch ((int)$t['status']) {
            case 2:
                return Conversation::STATUS_ACTIVE;  // Open
            case 4: // Resolved
            case 5: // Closed
                return Conversation::STATUS_CLOSED;
            default:
                return Conversation::STATUS_PENDING; // Pending and custom "waiting" statuses
        }
    }

    /** Cc pre-filled by Freshdesk when replying (reply_cc_emails, else cc_emails), without the mailbox and customer. */
    protected function replyCc(array $t, $customer_email)
    {
        $src = !empty($t['reply_cc_emails']) ? $t['reply_cc_emails'] : ($t['cc_emails'] ?? []);
        $exclude = array_filter([strtolower((string)$this->mailbox->email), strtolower((string)$customer_email)]);
        $out = [];
        foreach ((array)$src as $s) {
            $e = $this->emailOf($s);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $exclude, true)) {
                $out[$e] = $e;
            }
        }
        return array_values($out);
    }

    public function emailOf($s)
    {
        return strtolower(trim(preg_match('/<([^>]+)>/', (string)$s, $m) ? $m[1] : (string)$s));
    }

    protected function nameOf($s)
    {
        return preg_match('/^\s*"?([^"<]+?)"?\s*<[^>]+>/', (string)$s, $m) ? trim($m[1]) : '';
    }

    protected function splitName($name)
    {
        $name = trim((string)$name);
        if ($name === '' || strpos($name, '@') !== false) {
            return [$name, ''];
        }
        $parts = preg_split('/\s+/', $name, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    /** Freshdesk dates are ISO 8601 UTC; FreeScout stores dates in the application timezone. */
    protected function localTime($iso)
    {
        return $iso ? \Carbon\Carbon::parse($iso, 'UTC')->setTimezone(config('app.timezone')) : null;
    }

    protected function dbTime($iso)
    {
        return $iso ? \Carbon\Carbon::parse($iso, 'UTC')->format('Y-m-d H:i:s') : null;
    }

    protected function sameTime($db, $iso)
    {
        return $db === $this->dbTime($iso);
    }
}
