<?php
/** Consent, institution-scoped audiences and an at-most-once campaign queue. */
defined('ABSPATH') || exit;

class KN_Mail {
    const CRON = 'kn_mail_tick';
    // Shared hosts stop PHP after 30–60 seconds; the audience sync resumes in the next run instead.
    const TIME_BUDGET = 20;
    private static $run_started = 0;

    public function __construct() {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action(self::CRON, [__CLASS__, 'run_queue']);
        add_action('kn_content_saved', [__CLASS__, 'maybe_update']);
        // Scheduled entries are published by WP-Cron without a user; the mail job is created at that moment.
        add_action('transition_post_status', static function($new, $old, $post) { if ($new === 'publish' && $old === 'future' && isset(self::topics()[$post->post_type])) { self::maybe_update($post->ID); } }, 10, 3);
        add_shortcode('konstanth_newsletter', [__CLASS__, 'subscribe_form']);
        add_shortcode('kn_subscribe', [__CLASS__, 'subscribe_form']);
        foreach (['kn_subscribe'=>'handle_subscribe', 'kn_confirm'=>'handle_confirm', 'kn_manage_request'=>'handle_manage_request', 'kn_preferences'=>'handle_preferences'] as $action=>$method) {
            add_action('admin_post_' . $action, [__CLASS__, $method]);
            add_action('admin_post_nopriv_' . $action, [__CLASS__, $method]);
        }
        add_action('admin_post_kn_mail_settings', [__CLASS__, 'save_settings']);
        add_action('admin_post_kn_mail_action', [__CLASS__, 'admin_action']);
        add_action('admin_post_kn_mail_preview', [__CLASS__, 'preview']);
        add_action('template_redirect', [__CLASS__, 'manage_landing']);
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'privacy_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'privacy_erasers']);
        add_action('init', [__CLASS__, 'ensure_schedule']);
        add_action('save_post_page', [__CLASS__, 'page_saved'], 10, 2);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
    }

    /** The queue schedule is recreated if a migration, update or cron cleanup removed it. */
    public static function ensure_schedule() {
        if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 60, 'kn_five_minutes', self::CRON); }
    }

    public static function admin_notices() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings();
        if (!empty($s['enabled']) && empty($s['test_mode']) && !self::address_confirmed($s)) {
            echo '<div class="notice notice-warning"><p><strong>konstanth:</strong> Der Rundmail-Versand ist angehalten, weil sich die Website-Adresse geändert hat (bestätigt: ' . esc_html($s['live_url'] ?: '–') . ', jetzt: ' . esc_html(home_url('/')) . '). Das schützt Test- und Kopierseiten vor echtem Versand. Ist dies die echte Website, speichern Sie die <a href="' . esc_url(admin_url('admin.php?page=kn-settings')) . '">Versand-Einstellungen</a> hier erneut.</p></div>';
        }
        $recent = array_filter((array)get_option('kn_mail_errors', []), static function($e) { return (int)($e['time'] ?? 0) > time() - DAY_IN_SECONDS; });
        if ($recent) { echo '<div class="notice notice-error"><p><strong>konstanth:</strong> ' . count($recent) . ' Bestätigungs- oder Verwaltungsmail(s) konnten in den letzten 24 Stunden nicht versendet werden. Details unter <a href="' . esc_url(admin_url('admin.php?page=kn-mail')) . '">Rundmails</a>.</p></div>'; }
        $last = (int)get_option('kn_last_queue_run', 0);
        if (self::is_live() && $last && $last < time() - HOUR_IN_SECONDS) { echo '<div class="notice notice-warning"><p><strong>konstanth:</strong> Die Rundmail-Warteschlange lief zuletzt am ' . esc_html(wp_date('d.m.Y H:i', $last)) . '. Bitte beim Hoster einen Server-Cron einrichten (siehe Anleitung).</p></div>'; }
    }

    public static function topics() {
        return ['kn_event'=>'Veranstaltungen', 'kn_job'=>'Stellenangebote', 'kn_news'=>'Nachrichten'];
    }

    public static function settings() {
        $s = array_merge(['enabled'=>0, 'test_mode'=>1, 'api_key'=>'', 'sender_email'=>'', 'sender_name'=>'Konstanth', 'postal_address'=>'', 'folder_id'=>0, 'live_url'=>'', 'require_approval'=>1], (array)get_option('kn_mail_settings', []));
        if (defined('KN_BREVO_API_KEY')) { $s['api_key'] = KN_BREVO_API_KEY; }
        return $s;
    }

    public static function is_live() {
        $s = self::settings();
        return !empty($s['enabled']) && empty($s['test_mode']) && !empty($s['api_key']) && is_email($s['sender_email']) && trim($s['sender_name']) !== '' && trim($s['postal_address']) !== '' && (int)$s['folder_id'] > 0 && self::address_confirmed($s);
    }

    /** A copied site (staging, move) keeps the stored key, so live transport is bound to the confirmed site address. */
    public static function address_confirmed($s = null) {
        $s = $s ?: self::settings();
        return !empty($s['live_url']) && rtrim((string)$s['live_url'], '/') === rtrim(home_url('/'), '/');
    }

    /** Test-phase jobs must never turn into live campaigns just because the mode changes. */
    public static function go_live_transition() {
        global $wpdb;
        $t = self::table('jobs');
        $wpdb->query($wpdb->prepare("UPDATE $t SET status='dry_run',error_code='before_go_live',updated_at=%d WHERE status IN ('pending','approval') AND campaign_id=0", time()));
    }

    public static function error_text($code) {
        $texts = ['kn_brevo_401'=>'Brevo hat die Anfrage abgelehnt (HTTP 401): API-Schlüssel ungültig oder die IP-Adresse des Webservers ist in Brevo nicht freigegeben (Brevo → Sicherheit → Autorisierte IP-Adressen).', 'rate_limited'=>'Brevo meldet zu viele Anfragen. Der Auftrag wird automatisch später fortgesetzt.', 'time_budget'=>'Der Listenabgleich wird im nächsten Lauf fortgesetzt.', 'no_active_subscribers'=>'Keine bestätigten Abonnements für diesen Inhalt.', 'before_go_live'=>'Im Testbetrieb angelegt; beim Wechsel in den Livebetrieb nicht automatisch versendet.', 'kn_transport'=>'Antwort des Versanddienstes unklar.', 'worker_interrupted'=>'Verarbeitung unterbrochen; bitte in Brevo prüfen.', 'campaign_id_missing'=>'Brevo hat keine Kampagnen-ID geliefert; bitte in Brevo prüfen.', 'campaign_not_persisted'=>'Kampagnen-ID konnte nicht gespeichert werden; bitte in Brevo prüfen.', 'unpublished_before_dispatch'=>'Der Inhalt war vor dem Versand nicht mehr öffentlich.', 'daily_limit'=>'Diese Einrichtung hat heute schon eine Rundmail versendet; der Auftrag ist auf den nächsten Morgen verschoben.', 'admin_cancelled'=>'Von einem Administrator abgebrochen.', 'kn_list_provisioning'=>'Die Brevo-Liste wird gerade angelegt oder muss nach einer unklaren Antwort in Brevo geprüft und unter Versand-Einstellungen zugeordnet werden.', 'kn_list_unknown'=>'Brevo hat keine Listen-ID geliefert; bitte in Brevo prüfen.', 'kn_audience_limit'=>'Die Brevo-Liste ist für einen Lauf zu groß.', 'kn_topic'=>'Ungültige Abo-Zuordnung.', 'kn_mail_disabled'=>'Der Livebetrieb ist nicht freigegeben.'];
        return $texts[$code] ?? $code;
    }

    /** Administrators see failed service mails; the log never stores recipient addresses. */
    public static function log_error($context, $code) {
        $log = (array)get_option('kn_mail_errors', []);
        array_unshift($log, ['time'=>time(), 'context'=>sanitize_key($context), 'code'=>substr(sanitize_text_field((string)$code), 0, 100)]);
        update_option('kn_mail_errors', array_slice($log, 0, 20), false);
    }

    /** Anonymous forms live on cached pages; a signed timestamp replaces nonces that would expire there. */
    public static function form_stamp($time = null) {
        $time = (int)($time ?? time());
        return $time . '.' . substr(hash_hmac('sha256', 'kn_form|' . $time, wp_salt('nonce')), 0, 24);
    }

    private static function stamp_ok($stamp) {
        if (!is_string($stamp) || !preg_match('/^(\d{9,11})\.([a-f0-9]{24})$/D', $stamp, $m)) { return false; }
        return hash_equals(substr(hash_hmac('sha256', 'kn_form|' . $m[1], wp_salt('nonce')), 0, 24), $m[2]) && time() - (int)$m[1] >= 3;
    }

    public static function due_at($mode, $published) {
        return $mode === 'immediate' ? (int)$published : ($mode === 'delayed' ? (int)$published + 86400 : 0);
    }

    public static function eligible($id) {
        $p = get_post($id);
        if (!$p || !isset(self::topics()[$p->post_type]) || $p->post_status !== 'publish' || !empty($p->post_password)) { return false; }
        if (!in_array(get_post_meta($id, '_kn_mail_mode', true), ['immediate','delayed'], true)) { return false; }
        $org = get_post((int)get_post_meta($id, '_kn_org', true));
        if (!$org || $org->post_type !== 'kn_institution' || $org->post_status !== 'publish' || !empty($org->post_password)) { return false; }
        // Administrators can stop all mail of one institution, e.g. after a compromised account.
        if (get_post_meta($org->ID, '_kn_mail_blocked', true)) { return false; }
        if ($p->post_type === 'kn_event' && ((int)get_post_meta($id, '_kn_start', true) <= time() || get_post_meta($id, '_kn_cancelled', true))) { return false; }
        if ($p->post_type === 'kn_job') {
            $deadline = get_post_meta($id, '_kn_deadline', true);
            if ($deadline && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $deadline) || $deadline < wp_date('Y-m-d'))) { return false; }
        }
        return true;
    }

    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . ($name === 'jobs' ? 'kn_mail_jobs' : 'kn_subscribers');
    }

    private static function write_meta($id, $key, $value) {
        return KN_Core::system_write(static function() use ($id,$key,$value) { return update_post_meta($id,$key,$value); });
    }

    public static function cron_schedules($s) {
        $s['kn_five_minutes'] = ['interval'=>300, 'display'=>'Konstanth alle fünf Minuten'];
        return $s;
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sub = self::table('subscribers'); $jobs = self::table('jobs');
        dbDelta("CREATE TABLE $sub (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(190) NOT NULL,
            org_id bigint(20) unsigned NOT NULL,
            topic varchar(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            token_hash varchar(64) NOT NULL DEFAULT '',
            manage_hash varchar(64) NOT NULL DEFAULT '',
            manage_expires bigint(20) unsigned NOT NULL DEFAULT 0,
            token_expires bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at bigint(20) unsigned NOT NULL,
            confirmed_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY subscription (email,org_id,topic),
            KEY token_hash (token_hash),
            KEY manage_hash (manage_hash),
            KEY audience (org_id,topic,status)
        ) $charset;");
        dbDelta("CREATE TABLE $jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            org_id bigint(20) unsigned NOT NULL,
            topic varchar(20) NOT NULL,
            due_at bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
            error_code varchar(100) NOT NULL DEFAULT '',
            created_at bigint(20) unsigned NOT NULL,
            updated_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id),
            KEY ready (status,due_at)
        ) $charset;");
        if (get_option('kn_mail_settings', false) === false) { add_option('kn_mail_settings', self::settings(), '', false); }
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time()+60, 'kn_five_minutes', self::CRON); }
    }

    public static function deactivate() { wp_clear_scheduled_hook(self::CRON); }

    public static function maybe_update($id) {
        global $wpdb;
        $id = absint($id); $table = self::table('jobs');
        // WP-Cron counts as nobody, also with ALTERNATE_WP_CRON, where it runs inside a visitor's request.
        $user = defined('DOING_CRON') && DOING_CRON ? 0 : get_current_user_id();
        // The last person who saved the entry decides about approval, also for a later publication by WP-Cron.
        if ($user && isset(self::topics()[get_post_type($id)])) { self::write_meta($id, '_kn_mail_by_admin', current_user_can('manage_options') ? '1' : '0'); }
        if (!self::eligible($id)) {
            // Closed test runs stay closed; re-planning them is an explicit administrator action.
            $wpdb->query($wpdb->prepare("UPDATE $table SET status='cancelled', error_code='', updated_at=%d WHERE post_id=%d AND status IN ('pending','approval','failed') AND campaign_id=0", time(), $id));
            return;
        }
        $p = get_post($id);
        $published = (int)get_post_meta($id, '_kn_mail_first_published', true);
        if (!$published) {
            $published = get_post_time('U', true, $p);
            if (!$published) { $published = time(); }
            self::write_meta($id, '_kn_mail_first_published', $published);
        }
        $due = self::due_at(get_post_meta($id, '_kn_mail_mode', true), $published);
        $org = (int)get_post_meta($id, '_kn_org', true);
        // Institution mail waits for the administrator while the approval switch is on.
        // Scheduled entries are published by WP-Cron without a user; then the last saving person counts, not the author.
        $by_admin = $user ? current_user_can('manage_options') : get_post_meta($id, '_kn_mail_by_admin', true) === '1';
        $status = !empty(self::settings()['require_approval']) && !$by_admin ? 'approval' : 'pending';
        // A unique post_id protects edits, parallel saves and republishing from duplicate mail.
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (post_id,org_id,topic,due_at,status,created_at,updated_at) VALUES (%d,%d,%s,%d,%s,%d,%d)", $id,$org,$p->post_type,$due,$status,time(),time()));
        $wpdb->query($wpdb->prepare("UPDATE $table SET org_id=%d, topic=%s, due_at=%d, updated_at=%d WHERE post_id=%d AND status IN ('pending','approval') AND campaign_id=0", $org,$p->post_type,$due,time(),$id));
        // An approval covers the content as it was; a later change by an institution needs a new approval.
        if ($status === 'approval') { $wpdb->query($wpdb->prepare("UPDATE $table SET status='approval', updated_at=%d WHERE post_id=%d AND status='pending' AND campaign_id=0", time(), $id)); }
        // Ticking the box again re-plans a mail that was stopped but never handed to Brevo; sent mail and administrator cancellations stay final.
        $wpdb->query($wpdb->prepare("UPDATE $table SET status=%s, org_id=%d, topic=%s, due_at=%d, updated_at=%d WHERE post_id=%d AND status='cancelled' AND campaign_id=0 AND error_code=''", $status,$org,$p->post_type,$due,time(),$id));
    }

    public static function approve_job($id) {
        global $wpdb;
        $t = self::table('jobs');
        $wpdb->query($wpdb->prepare("UPDATE $t SET status='pending',updated_at=%d WHERE id=%d AND status='approval'", time(), (int)$id));
    }

    /** Theme subscriptions use org 0 and the topic key "t{term_id}"; institution subscriptions use a content type. */
    public static function topic_key($term_id) { return 't' . (int)$term_id; }
    public static function topic_term($topic) { return preg_match('/^t([1-9][0-9]*)$/D', (string)$topic, $m) ? (int)$m[1] : 0; }
    public static function topic_label($org, $topic) {
        if ($term_id = self::topic_term($topic)) { $term = get_term($term_id); return 'Thema „' . ($term && !is_wp_error($term) ? $term->name : 'entfernt') . '“ – Termine aller Einrichtungen'; }
        $institution = get_post((int)$org);
        return ($institution ? $institution->post_title : 'Einrichtung') . ' – ' . (self::topics()[$topic] ?? $topic);
    }
    private static function valid_audience($org, $topic) {
        if ($org) { return isset(self::topics()[$topic]); }
        $term = get_term(self::topic_term($topic));
        return $term && !is_wp_error($term) && $term->taxonomy === 'kn_topic';
    }
    private static function stored_list($org, $topic) {
        if ($org) { $lists = (array)get_post_meta($org, '_kn_brevo_lists', true); return (int)($lists[$topic] ?? 0); }
        return (int)get_term_meta(self::topic_term($topic), 'kn_brevo_list', true);
    }
    private static function store_list($org, $topic, $list) {
        if (!$org) { return update_term_meta(self::topic_term($topic), 'kn_brevo_list', (int)$list); }
        $lists = (array)get_post_meta($org, '_kn_brevo_lists', true); $lists[$topic] = (int)$list;
        return self::write_meta($org, '_kn_brevo_lists', $lists);
    }
    private static function event_topics($post_id) {
        $ids = wp_get_object_terms($post_id, 'kn_topic', ['fields'=>'ids']);
        return is_wp_error($ids) ? [] : array_map('intval', (array)$ids);
    }

    private static function api($method, $path, $body = null) {
        if (!self::is_live()) { return new WP_Error('kn_mail_disabled', 'Live-Versand ist nicht freigegeben.'); }
        $s = self::settings();
        $args = ['method'=>$method, 'timeout'=>25, 'redirection'=>0, 'headers'=>['api-key'=>$s['api_key'], 'Accept'=>'application/json', 'Content-Type'=>'application/json']];
        if ($body !== null) { $args['body'] = wp_json_encode($body); }
        $r = wp_remote_request('https://api.brevo.com/v3/' . ltrim($path, '/'), $args);
        if (is_wp_error($r)) { return new WP_Error('kn_transport', 'Antwort des Versanddienstes unklar.', ['ambiguous'=>$method !== 'GET']); }
        $code = wp_remote_retrieve_response_code($r);
        if ((int)$code === 429) { return new WP_Error('rate_limited', self::error_text('rate_limited'), ['http'=>429, 'ambiguous'=>false]); }
        if ($code < 200 || $code >= 300) { return new WP_Error('kn_brevo_' . $code, (int)$code === 401 ? self::error_text('kn_brevo_401') : 'Brevo hat die Anfrage abgelehnt (HTTP ' . (int)$code . ').', ['http'=>$code, 'ambiguous'=>$method !== 'GET' && $code >= 500]); }
        $data = json_decode(wp_remote_retrieve_body($r), true);
        return is_array($data) ? $data : [];
    }

    private static function list_id($org, $topic) {
        if (!self::valid_audience($org, $topic)) { return new WP_Error('kn_topic', 'Ungültiges Thema.'); }
        if ($stored = self::stored_list($org, $topic)) { return $stored; }
        // Provisioning is serialized and a timed-out create is never repeated automatically.
        $lock = 'kn_list_lock_' . (int)$org . '_' . $topic;
        if (get_option($lock)) { return new WP_Error('kn_list_provisioning', 'Listenanlage muss in Brevo geprüft werden.'); }
        if (!add_option($lock, time(), '', false)) { return new WP_Error('kn_list_provisioning', 'Listenanlage läuft bereits.'); }
        $s = self::settings();
        $r = self::api('POST', 'contacts/lists', ['name'=>$org ? 'Konstanth ' . (int)$org . ' ' . $topic : 'Konstanth Thema ' . self::topic_term($topic), 'folderId'=>(int)$s['folder_id']]);
        if (is_wp_error($r)) {
            if (empty($r->get_error_data()['ambiguous'])) { delete_option($lock); }
            return $r;
        }
        if (empty($r['id'])) { return new WP_Error('kn_list_unknown', 'Listen-ID fehlt; bitte in Brevo prüfen.'); }
        self::store_list($org, $topic, (int)$r['id']); delete_option($lock);
        return (int)$r['id'];
    }

    private static function remove_from_list($org, $topic, $emails) {
        $list = self::stored_list($org, $topic);
        if (!$list || !$emails || !self::is_live()) { return true; }
        foreach (array_chunk(array_values($emails), 150) as $chunk) {
            $r = self::api('POST', 'contacts/lists/' . $list . '/contacts/remove', ['emails'=>$chunk]);
            if (is_wp_error($r)) { return $r; }
        }
        return true;
    }

    private static function sync_audience($org, $topic) {
        global $wpdb;
        $sub = self::table('subscribers');
        $emails = $wpdb->get_col($wpdb->prepare("SELECT email FROM $sub WHERE org_id=%d AND topic=%s AND status='active'", $org, $topic));
        // No subscribers and no list yet: nothing to reconcile, and no empty provider list is created.
        if (!$emails && !self::stored_list($org, $topic)) { return ['id'=>0, 'count'=>0]; }
        $list = self::list_id($org, $topic);
        if (is_wp_error($list)) { return $list; }
        $active = array_fill_keys($emails, true); $remote = []; $known = []; $offset = 0;
        do {
            $r = self::api('GET', 'contacts/lists/' . $list . '/contacts?limit=500&offset=' . $offset);
            if (is_wp_error($r)) { return $r; }
            foreach (($r['contacts'] ?? []) as $contact) {
                if (empty($contact['email'])) { continue; }
                $remote[] = strtolower($contact['email']);
                // List payloads that already carry opt-out flags spare one request per subscriber.
                if (array_key_exists('emailBlacklisted', $contact)) { $known[strtolower($contact['email'])] = $contact; }
            }
            $count = count($r['contacts'] ?? []); $offset += $count;
            if ($offset > 100000) { return new WP_Error('kn_audience_limit', 'Liste ist für diesen Lauf zu groß.'); }
        } while ($count === 500);
        // Never mail stale provider memberships after a local unsubscription.
        $remove = array_values(array_filter($remote, static function($e) use ($active) { return !isset($active[$e]); }));
        $r = self::remove_from_list($org, $topic, $remove);
        if (is_wp_error($r)) { return $r; }
        $allowed = 0; $in_list = array_fill_keys($remote, true);
        foreach ($emails as $email) {
            if (self::$run_started && microtime(true) - self::$run_started > self::TIME_BUDGET) { return new WP_Error('time_budget', self::error_text('time_budget')); }
            $contact = isset($known[$email]) ? $known[$email] : self::api('GET', 'contacts/' . rawurlencode($email));
            if (is_wp_error($contact)) {
                if (($contact->get_error_data()['http'] ?? 0) !== 404) { return $contact; }
                $contact = self::api('POST', 'contacts', ['email'=>$email, 'listIds'=>[$list]]);
                if (is_wp_error($contact)) { return $contact; }
                $allowed++; continue;
            }
            // Omit emailBlacklisted entirely: a provider opt-out is never undone by this plugin.
            if (!empty($contact['emailBlacklisted'])) {
                $r = self::remove_from_list($org, $topic, [$email]);
                if (is_wp_error($r)) { return $r; }
                // A provider-wide opt-out ends every local subscription of this address.
                $wpdb->query($wpdb->prepare("UPDATE $sub SET status='unsubscribed',updated_at=%d WHERE email=%s AND status='active'", time(), $email));
                continue;
            }
            // Respect scoped provider opt-outs even if Brevo still reports list membership.
            if (in_array($list, array_map('intval', (array)($contact['listUnsubscribed'] ?? [])), true)) {
                $r = self::remove_from_list($org, $topic, [$email]);
                if (is_wp_error($r)) { return $r; }
                $wpdb->query($wpdb->prepare("UPDATE $sub SET status='unsubscribed',updated_at=%d WHERE email=%s AND org_id=%d AND topic=%s AND status='active'", time(), $email, $org, $topic));
                continue;
            }
            if (!isset($in_list[$email])) {
                $r = self::api('POST', 'contacts/lists/' . $list . '/contacts/add', ['emails'=>[$email]]);
                if (is_wp_error($r)) { return $r; }
            }
            $allowed++;
        }
        return ['id'=>$list, 'count'=>$allowed];
    }

    public static function run_queue() {
        global $wpdb;
        $t = self::table('jobs'); $now = time(); self::$run_started = microtime(true);
        update_option('kn_last_queue_run', $now, false);
        // A process crash after an API request must never turn into an automatic resend.
        $wpdb->query($wpdb->prepare("UPDATE $t SET status='unknown',error_code='worker_interrupted',updated_at=%d WHERE status='sending' AND updated_at<%d", $now, $now-900));
        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status='pending' AND due_at<=%d ORDER BY due_at,id LIMIT 5", $now));
        foreach ($jobs as $job) {
            $locked = $wpdb->query($wpdb->prepare("UPDATE $t SET status='sending',updated_at=%d WHERE id=%d AND status='pending'", time(), $job->id));
            if ($locked !== 1) { continue; }
            if (!self::eligible($job->post_id) || (int)get_post_meta($job->post_id, '_kn_org', true) !== (int)$job->org_id) { self::finish($job->id, 'cancelled'); continue; }
            if (!self::is_live()) { self::finish($job->id, 'dry_run'); continue; }
            // At most one campaign per institution and calendar day; later ones move to the next morning.
            $day = (new DateTimeImmutable('today', wp_timezone()))->getTimestamp();
            if ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE org_id=%d AND status='sent' AND updated_at>=%d AND id<>%d", (int)$job->org_id, $day, (int)$job->id))) {
                $wpdb->update($t, ['status'=>'pending', 'error_code'=>'daily_limit', 'due_at'=>(new DateTimeImmutable('tomorrow 08:00', wp_timezone()))->getTimestamp(), 'updated_at'=>time()], ['id'=>(int)$job->id], ['%s','%s','%d','%d'], ['%d']);
                continue;
            }
            // The institution's own list plus one list per theme of an event; Brevo delivers each address once.
            $audiences = [[(int)$job->org_id, $job->topic]];
            if ($job->topic === 'kn_event') { foreach (self::event_topics($job->post_id) as $term_id) { $audiences[] = [0, self::topic_key($term_id)]; } }
            $list_ids = []; $paused = false; $failed = '';
            foreach ($audiences as $audience_key) {
                $audience = self::sync_audience($audience_key[0], $audience_key[1]);
                if (is_wp_error($audience) && in_array($audience->get_error_code(), ['rate_limited', 'time_budget'], true)) {
                    // No campaign exists yet, so resuming later can never duplicate a mail.
                    $wpdb->update($t, ['status'=>'pending', 'error_code'=>$audience->get_error_code(), 'due_at'=>time() + ($audience->get_error_code() === 'rate_limited' ? 300 : 60), 'updated_at'=>time()], ['id'=>(int)$job->id], ['%s','%s','%d','%d'], ['%d']);
                    $paused = true; break;
                }
                if (is_wp_error($audience)) { $failed = $audience->get_error_code(); break; }
                if ($audience['count']) { $list_ids[] = (int)$audience['id']; }
            }
            if ($paused) { break; }
            if ($failed) { self::finish($job->id, 'failed', $failed); continue; }
            if (!$list_ids) { self::finish($job->id, 'cancelled', 'no_active_subscribers'); continue; }
            // Recheck publication immediately before producing a campaign.
            if (!self::eligible($job->post_id)) { self::finish($job->id, 'cancelled'); continue; }
            $s = self::settings(); $p = get_post($job->post_id); $institution = get_post((int)$job->org_id);
            $reply = sanitize_email(get_post_meta($job->org_id, '_kn_email', true));
            // Readers consented to mail "of the selected institutions": the sender names the institution.
            $sender = $institution ? trim(substr(wp_strip_all_tags($institution->post_title), 0, 60)) . ' über konstanth' : $s['sender_name'];
            $payload = ['name'=>'Konstanth #' . (int)$job->post_id . ' Auftrag #' . (int)$job->id, 'subject'=>wp_strip_all_tags($p->post_title), 'sender'=>['email'=>$s['sender_email'], 'name'=>$sender], 'htmlContent'=>self::email_html($job->post_id), 'recipients'=>['listIds'=>array_values(array_unique($list_ids))], 'tag'=>'kn-' . (int)$job->id];
            if (is_email($reply)) { $payload['replyTo'] = $reply; }
            $r = self::api('POST', 'emailCampaigns', $payload);
            if (is_wp_error($r)) { self::finish($job->id, !empty($r->get_error_data()['ambiguous']) ? 'unknown' : 'failed', $r->get_error_code()); continue; }
            if (empty($r['id'])) { self::finish($job->id, 'unknown', 'campaign_id_missing'); continue; }
            $campaign = (int)$r['id'];
            $saved = $wpdb->update($t, ['campaign_id'=>$campaign, 'updated_at'=>time()], ['id'=>(int)$job->id, 'status'=>'sending'], ['%d','%d'], ['%d','%s']);
            if ($saved !== 1) { self::finish($job->id, 'unknown', 'campaign_not_persisted'); continue; }
            if (!self::eligible($job->post_id)) { self::finish($job->id, 'cancelled', 'unpublished_before_dispatch'); continue; }
            $r = self::api('POST', 'emailCampaigns/' . $campaign . '/sendNow');
            if (is_wp_error($r)) { self::finish($job->id, !empty($r->get_error_data()['ambiguous']) ? 'unknown' : 'failed', $r->get_error_code()); continue; }
            self::finish($job->id, 'sent');
        }
        // Expired unconfirmed requests contain no established subscription and are discarded.
        $sub = self::table('subscribers');
        $wpdb->query($wpdb->prepare("DELETE FROM $sub WHERE status='pending' AND token_expires<%d AND token_expires>0", time()-604800));
    }

    private static function finish($id, $status, $error='') {
        global $wpdb;
        $wpdb->update(self::table('jobs'), ['status'=>$status,'error_code'=>substr(sanitize_text_field($error),0,100),'updated_at'=>time()], ['id'=>(int)$id], ['%s','%s','%d'], ['%d']);
    }

    /** Design „Farbstimmungen“ as an Outlook-safe table layout with inline styles; never includes internal notes. */
    public static function email_html($id) {
        $p = get_post($id); if (!$p) { return ''; }
        $s = self::settings(); $org = get_post((int)get_post_meta($id, '_kn_org', true));
        $font = 'font-family:Arial,Helvetica,sans-serif'; $ink = '#26233a'; $muted = '#585470'; $accent = '#5442a8';
        $image = self::mail_image_url($id);
        $public = get_post_meta($id, '_kn_public_info', true);
        $content = preg_replace('#<(script|style|iframe|form)\b[^>]*>.*?</\1>#is', '', $p->post_content);
        $content = wp_kses_post(wpautop($content));
        // HTML email accepts only public content, never internal notes or editor scripts.
        $content = preg_replace('#<(script|style|iframe|form)\b[^>]*>.*?</\1>#is', '', $content);
        $terms = [];
        if ($p->post_type === 'kn_event') { foreach (self::event_topics($id) as $term_id) { $term = get_term($term_id); if ($term && !is_wp_error($term)) { $terms[] = [$term->name, class_exists('KN_Categories') ? KN_Categories::term_colors($term_id) : ['#eeeafa', $ink]]; } } }
        $band = $terms ? $terms[0][1][0] : '#eeeafa';
        $details = ''; $preheader = '';
        if ($p->post_type === 'kn_event') {
            $start = (int)get_post_meta($id, '_kn_start', true); $end = (int)get_post_meta($id, '_kn_end', true);
            if ($start) { $when = wp_date('l, j. F Y, H:i', $start) . ($end > $start ? '–' . wp_date(wp_date('Ymd', $end) === wp_date('Ymd', $start) ? 'H:i' : 'j. F Y, H:i', $end) : '') . ' Uhr'; $details .= '<strong>Wann:</strong> ' . esc_html($when) . '<br>'; $preheader = $when; }
        }
        if ($p->post_type === 'kn_job') {
            foreach (['workload'=>'Stellenumfang','deadline'=>'Bewerbungsfrist','application'=>'Bewerbung'] as $key=>$label) {
                $value = get_post_meta($id,'_kn_' . $key,true);
                if ($key === 'deadline' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)$value)) { $value = wp_date('j. F Y', strtotime($value . ' 12:00:00')); }
                if ($value) { $details .= '<strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '<br>'; }
            }
        }
        $location = get_post_meta($id, '_kn_location', true);
        if ($location) { $details .= '<strong>Wo:</strong> ' . esc_html($location) . '<br>'; }
        $org_name = $org ? $org->post_title : 'konstanth';
        $intro = ['kn_event'=>' lädt ein', 'kn_job'=>' sucht', 'kn_news'=>' berichtet'][$p->post_type] ?? '';
        $cta = ['kn_event'=>'Termin ansehen', 'kn_job'=>'Stelle ansehen', 'kn_news'=>'Weiterlesen'][$p->post_type] ?? 'Ansehen';
        $registration = $p->post_type === 'kn_event' ? esc_url_raw((string)get_post_meta($id, '_kn_registration_url', true), ['http','https']) : '';
        $button = function ($url, $label, $filled) use ($font, $accent) { return '<td bgcolor="' . ($filled ? $accent : '#ffffff') . '" style="background:' . ($filled ? $accent : '#ffffff') . ';border:2px solid ' . $accent . ';border-radius:10px;padding:12px 22px"><a href="' . esc_url($url) . '" style="' . $font . ';font-size:16px;font-weight:bold;color:' . ($filled ? '#ffffff' : $accent) . ';text-decoration:none;display:inline-block">' . esc_html($label) . '</a></td>'; };
        $badges = '';
        foreach ($terms as $term) { $badges .= '<td bgcolor="' . esc_attr($term[1][0]) . '" style="background:' . esc_attr($term[1][0]) . ';color:' . esc_attr($term[1][1]) . ';' . $font . ';font-size:13px;font-weight:bold;padding:4px 10px;border-radius:999px">' . esc_html($term[0]) . '</td><td width="6" style="font-size:0;line-height:0">&nbsp;</td>'; }
        $row = function ($html, $style = 'padding:0 32px') { return '<tr><td style="' . $style . '">' . $html . '</td></tr>'; };
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($p->post_title) . '</title></head><body style="margin:0;padding:0;background:#f8f6fd">';
        $html .= '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all">' . esc_html(trim($preheader . ' · ' . $org_name, ' ·')) . '</div>';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" bgcolor="#f8f6fd" style="background:#f8f6fd"><tr><td align="center" style="padding:24px 12px"><!--[if mso]><table role="presentation" width="600" align="center" cellspacing="0" cellpadding="0"><tr><td><![endif]--><table role="presentation" width="600" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px">';
        $html .= '<tr><td bgcolor="' . $accent . '" style="background:' . $accent . ';padding:18px 32px;' . $font . ';font-size:20px;font-weight:bold;color:#ffffff;border-radius:14px 14px 0 0">konstanth</td></tr>';
        $html .= '<tr><td height="8" bgcolor="' . esc_attr($band) . '" style="font-size:0;line-height:0;mso-line-height-rule:exactly;background:' . esc_attr($band) . '">&nbsp;</td></tr>';
        $html .= $row('<p style="margin:0;' . $font . ';font-size:14px;color:' . $muted . '">' . esc_html($org_name . $intro) . '</p>', 'padding:24px 32px 0 32px');
        if ($badges) { $html .= $row('<table role="presentation" cellspacing="0" cellpadding="0"><tr>' . $badges . '</tr></table>', 'padding:10px 32px 0 32px'); }
        $html .= $row('<h1 style="margin:0;' . $font . ';font-size:26px;line-height:1.25;font-weight:bold;color:' . $ink . '">' . esc_html($p->post_title) . '</h1>', 'padding:14px 32px 0 32px');
        if ($details) { $html .= $row('<p style="margin:0;' . $font . ';font-size:16px;line-height:1.6;color:' . $ink . '">' . $details . '</p>', 'padding:14px 32px 0 32px'); }
        if ($image) { $html .= $row('<img src="' . esc_url($image) . '" alt="' . esc_attr(get_post_meta(get_post_thumbnail_id($id), '_wp_attachment_image_alt', true)) . '" width="536" style="width:100%;max-width:536px;height:auto;display:block;border:0;border-radius:10px">', 'padding:18px 32px 0 32px'); }
        $html .= $row('<div style="' . $font . ';font-size:16px;line-height:1.65;color:' . $ink . '">' . $content . ($public ? wp_kses_post(wpautop($public)) : '') . '</div>', 'padding:14px 32px 0 32px');
        $html .= $row('<table role="presentation" cellspacing="0" cellpadding="0"><tr>' . $button(get_permalink($id), $cta, true) . ($registration ? '<td width="10" style="font-size:0">&nbsp;</td>' . $button($registration, 'Zur Anmeldung', false) : '') . '</tr></table>', 'padding:22px 32px 8px 32px');
        if (is_email(get_post_meta((int)get_post_meta($id, '_kn_org', true), '_kn_email', true))) { $html .= $row('<p style="margin:0;' . $font . ';font-size:14px;color:' . $muted . '">Antworten auf diese E-Mail gehen direkt an ' . esc_html($org_name) . '.</p>', 'padding:14px 32px 0 32px'); }
        $html .= '<tr><td style="padding:24px 0 0 0"></td></tr><tr><td bgcolor="#eeeafa" style="background:#eeeafa;padding:20px 32px;' . $font . ';font-size:13px;line-height:1.55;color:' . $muted . ';border-radius:0 0 14px 14px">Absender: ' . esc_html($org_name) . ' über konstanth<br>' . nl2br(esc_html($s['postal_address'])) . '<br>Sie erhalten diese E-Mail, weil Sie Neuigkeiten dieser Einrichtung oder dieses Themas abonniert und bestätigt haben.<br><a href="' . esc_url(self::link('manage')) . '" style="color:' . $ink . '">Abonnements verwalten</a> · <a href="{{ unsubscribe }}" style="color:' . $ink . '">Alle E-Mails abbestellen</a></td></tr>';
        return $html . '</table><!--[if mso]></td></tr></table><![endif]--></td></tr></table></body></html>';
    }

    /** Outlook for Windows cannot display WebP/AVIF; mails receive a JPEG copy created once per image. */
    public static function mail_image_url($id) {
        $thumb = (int)get_post_thumbnail_id($id);
        if (!$thumb) { return ''; }
        $mime = function_exists('get_post_mime_type') ? (string)get_post_mime_type($thumb) : '';
        if (!in_array($mime, ['image/webp', 'image/avif'], true)) { return get_the_post_thumbnail_url($id, 'large'); }
        $stored = (string)get_post_meta($thumb, '_kn_mail_jpeg_file', true);
        if (!$stored || !is_file($stored)) {
            $source = get_attached_file($thumb); $editor = $source ? wp_get_image_editor($source) : null;
            if (!$editor || is_wp_error($editor)) { return get_the_post_thumbnail_url($id, 'large'); }
            $editor->resize(1088, null, false);
            $target = preg_replace('/\.(webp|avif)$/i', '', $source) . '-kn-mail.jpg';
            $saved = $editor->save($target, 'image/jpeg');
            if (is_wp_error($saved) || empty($saved['path'])) { return get_the_post_thumbnail_url($id, 'large'); }
            $stored = $saved['path']; update_post_meta($thumb, '_kn_mail_jpeg_file', $stored);
        }
        $uploads = wp_get_upload_dir();
        return strpos($stored, $uploads['basedir']) === 0 ? $uploads['baseurl'] . substr($stored, strlen($uploads['basedir'])) : get_the_post_thumbnail_url($id, 'large');
    }

    /** Page that hosts [konstanth_newsletter]; confirmations and management then appear inside the theme. */
    public static function page_url() {
        // Detected when pages are saved and on plugin updates (detect_page), never during a visitor request.
        $id = (int)get_option('kn_newsletter_page', 0);
        return $id && get_post_status($id) === 'publish' ? get_permalink($id) : '';
    }

    public static function detect_page() {
        global $wpdb;
        $id = (int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND (post_content LIKE '%[konstanth_newsletter%' OR post_content LIKE '%wp:konstanth/newsletter%') ORDER BY ID LIMIT 1");
        if ($id) { update_option('kn_newsletter_page', $id, false); } else { delete_option('kn_newsletter_page'); }
        return $id;
    }

    public static function page_saved($id, $post) {
        if ($post->post_type === 'page' && ($post->post_status === 'publish' || (int)get_option('kn_newsletter_page') === (int)$id)) { self::detect_page(); }
    }

    private static function link($state, $token = '') {
        $page = self::page_url();
        if ($state === 'confirm') { return $page ? add_query_arg('kn_confirm', $token, $page) : add_query_arg(['action'=>'kn_confirm','token'=>$token], admin_url('admin-post.php')); }
        if ($state === 'preferences') { return $page ? add_query_arg('kn_manage', $token, $page) : add_query_arg(['action'=>'kn_preferences','token'=>$token], admin_url('admin-post.php')); }
        return add_query_arg('kn_newsletter', 'manage', $page ?: home_url('/'));
    }

    private static function chip($name, $value, $label, $class = '', $checked = false) {
        return '<label class="kn-check' . ($class ? ' ' . esc_attr($class) : '') . '"><input type="checkbox" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($checked ? ' checked' : '') . '><span>' . esc_html($label) . '</span></label>';
    }

    public static function subscribe_form($atts=[]) {
        $atts = shortcode_atts(['org'=>0, 'institution'=>0], $atts, 'konstanth_newsletter');
        $org = absint($atts['org'] ?: $atts['institution']);
        $open = class_exists('KN_Frontend') ? KN_Frontend::portal('kn-newsletter') : '<section class="kn-portal kn-newsletter">';
        // Messages, confirmation and management render here, inside the theme, instead of on a bare WordPress screen.
        if (!$org && ($state = self::page_state())) { return $open . $state . '</section>'; }
        $preview = !self::is_live() && current_user_can('manage_options');
        if (!self::is_live() && !$preview) { return $open . '<p class="kn-notice" role="status">Der Newsletter ist noch im Testbetrieb. Es werden keine Anmeldungen oder E-Mails versendet.</p></section>'; }
        $institution = $org ? get_post($org) : null;
        if ($org && (!$institution || $institution->post_type !== 'kn_institution' || $institution->post_status !== 'publish')) { return $open . '<p>Diese Einrichtung bietet derzeit kein öffentliches Abonnement an.</p></section>'; }
        $institutions = $org ? [$institution] : get_posts(['post_type'=>'kn_institution','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        $themes = !$org && class_exists('KN_Categories') ? KN_Categories::terms('kn_topic') : [];
        if (!$institutions && !$themes) { return $open . '<p>Derzeit sind keine Abonnements verfügbar.</p></section>'; }
        static $instance = 0; $instance++; $email_id = 'kn-nl-email-' . $instance;
        $html = $open;
        if ($preview) { $html .= '<p class="kn-notice" role="status">Vorschau für Administratoren: Der Newsletter ist im Testbetrieb. Besucher sehen nur einen Hinweis; Anmeldungen sind noch nicht möglich.</p>'; }
        $html .= '<form class="kn-newsletter-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kn_subscribe"><input type="hidden" name="kn_ts" value="' . esc_attr(self::form_stamp()) . '">';
        $html .= '<div><h2 class="kn-section-title">Neuigkeiten per E-Mail</h2><p class="kn-form-note">Die Anmeldung wird erst aktiv, wenn Sie den Link in der Bestätigungsmail anklicken.</p></div>';
        $html .= '<div><label class="kn-field-label" for="' . esc_attr($email_id) . '">E-Mail-Adresse</label><input id="' . esc_attr($email_id) . '" class="kn-input" type="email" name="email" maxlength="190" autocomplete="email" required></div>';
        if ($themes) {
            $html .= '<fieldset class="kn-filter-group" data-kn-filter-group="topics"><legend>Termine zu Themen – aus allen Einrichtungen</legend><div class="kn-check-list">';
            foreach ($themes as $term) { $html .= self::chip('topic_ids[]', (int)$term->term_id, $term->name, 'kn-term-' . (int)$term->term_id); }
            $html .= '</div></fieldset>';
        }
        if ($org) { $html .= '<input type="hidden" name="org_ids[]" value="' . (int)$org . '"><p><strong>' . esc_html($institution->post_title) . '</strong></p>'; }
        elseif ($institutions) {
            $html .= '<fieldset class="kn-filter-group" data-kn-filter-group="orgs"><legend>Alles von bestimmten Einrichtungen (höchstens 20)</legend><div class="kn-check-list">';
            foreach ($institutions as $i) { $html .= self::chip('org_ids[]', (int)$i->ID, $i->post_title); }
            $html .= '</div></fieldset>';
        }
        if ($institutions) {
            $html .= '<fieldset class="kn-filter-group" data-kn-filter-group="types"><legend>' . ($org ? 'Was möchten Sie erhalten?' : 'Was möchten Sie von diesen Einrichtungen erhalten?') . '</legend><div class="kn-check-list">';
            foreach (self::topics() as $topic=>$label) { $html .= self::chip('topics[]', $topic, ['kn_event'=>'Termine','kn_job'=>'Stellenangebote','kn_news'=>'Nachrichten'][$topic] ?? $label, '', $org && $topic === 'kn_event'); }
            $html .= '</div></fieldset>';
        }
        $privacy = get_privacy_policy_url();
        $html .= '<div class="kn-consent"><label class="kn-check"><input type="checkbox" name="consent" value="1" required><span>Ich möchte zu meiner Auswahl E-Mails erhalten und kann jedes Abonnement jederzeit beenden.' . ($privacy ? ' <a href="' . esc_url($privacy) . '">Datenschutzhinweise</a>' : '') . '</span></label></div>';
        $html .= '<p class="kn-honeypot" aria-hidden="true" style="position:absolute;left:-10000px"><label>Bitte leer lassen <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>';
        $html .= '<div class="kn-filter-actions"><button class="kn-button" type="submit"' . ($preview ? ' disabled' : '') . '>Bestätigungslink anfordern</button><a class="kn-reset" href="' . esc_url(self::link('manage')) . '">Bestehende Abonnements verwalten</a></div></form>';
        return $html . '</section>';
    }

    /** Renders the current newsletter step on the hosting page (stored message, confirmation or management). */
    private static function page_state() {
        if (isset($_GET['kn_nl']) && is_scalar($_GET['kn_nl'])) {
            $state = get_transient('kn_nl_' . preg_replace('/[^a-f0-9]/', '', (string)$_GET['kn_nl']));
            if (is_array($state)) { return '<div class="kn-confirm"><h2>' . esc_html($state['title']) . '</h2>' . wp_kses_post($state['html']) . '<p><a href="' . esc_url(remove_query_arg('kn_nl')) . '">Zurück zur Anmeldung</a></p></div>'; }
        }
        if (isset($_GET['kn_confirm']) && is_scalar($_GET['kn_confirm'])) { return '<div class="kn-confirm">' . self::confirm_view(sanitize_text_field(wp_unslash($_GET['kn_confirm']))) . '</div>'; }
        if (isset($_GET['kn_manage']) && is_scalar($_GET['kn_manage'])) { return '<div class="kn-confirm">' . self::preferences_view(sanitize_text_field(wp_unslash($_GET['kn_manage']))) . '</div>'; }
        if (isset($_GET['kn_newsletter']) && sanitize_key(wp_unslash($_GET['kn_newsletter'])) === 'manage') { return '<div class="kn-confirm">' . (self::is_live() ? self::manage_request_form() : '<p>Der Newsletter ist derzeit nicht aktiv.</p>') . '</div>'; }
        return '';
    }

    private static function manage_request_form() {
        return '<form class="kn-newsletter-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kn_manage_request"><input type="hidden" name="kn_ts" value="' . esc_attr(self::form_stamp()) . '"><h2 class="kn-section-title">Abonnements verwalten</h2><p class="kn-form-note">Sie erhalten einen persönlichen Link, mit dem Sie einzelne Abonnements beenden können.</p><div><label class="kn-field-label" for="kn-manage-email">E-Mail-Adresse</label><input id="kn-manage-email" class="kn-input" type="email" name="email" maxlength="190" required autocomplete="email"></div><p class="kn-honeypot" aria-hidden="true" style="position:absolute;left:-10000px"><label>Bitte leer lassen <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p><div class="kn-filter-actions"><button class="kn-button" type="submit">Verwaltungslink anfordern</button></div></form>';
    }

    public static function manage_landing() {
        $keys = ['kn_nl', 'kn_confirm', 'kn_manage', 'kn_newsletter'];
        if (!array_intersect($keys, array_keys($_GET))) { return; }
        // Personal links must never be cached or leak through the referrer.
        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        add_action('wp_head', static function() { echo '<meta name="referrer" content="no-referrer">' . "\n"; });
        $page = self::page_url();
        if (isset($_GET['kn_newsletter']) && sanitize_key(wp_unslash($_GET['kn_newsletter'])) === 'manage' && !$page) {
            self::screen('Abonnements verwalten', self::is_live() ? self::manage_request_form() : '<p>Der Newsletter ist derzeit nicht aktiv.</p>');
        }
        if ($page && isset($_GET['kn_newsletter']) && !is_page((int)get_option('kn_newsletter_page'))) { wp_safe_redirect(add_query_arg('kn_newsletter', 'manage', $page)); exit; }
    }

    private static function screen($title, $html, $status=200) {
        $page = self::page_url();
        if ($page) {
            $key = bin2hex(random_bytes(12));
            set_transient('kn_nl_' . $key, ['title'=>$title, 'html'=>$html], 10 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg('kn_nl', $key, $page)); exit;
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        wp_die($html . '<p><a href="' . esc_url(home_url('/')) . '">Zur Website</a></p>', esc_html($title), ['response'=>$status]);
    }

    private static function request_ok($action) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !empty($_POST['website'])) { return false; }
        // Signup and management requests come from cached public pages; see form_stamp().
        if (in_array($action, ['kn_subscribe', 'kn_manage_request'], true)) { return self::stamp_ok(sanitize_text_field(wp_unslash($_POST['kn_ts'] ?? ''))); }
        return isset($_POST['_kn_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_kn_nonce'])), $action);
    }

    private static function within_limit($email) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        foreach (['ip'=>[$ip,8], 'email'=>[strtolower($email),3], 'site'=>['all',200]] as $kind=>$item) {
            $key = 'kn_mail_rate_' . hash_hmac('sha256', $kind . ':' . $item[0], wp_salt('nonce'));
            $count = (int)get_transient($key);
            if ($count >= $item[1]) { return false; }
            set_transient($key, $count+1, HOUR_IN_SECONDS);
        }
        return true;
    }

    private static function token() { return bin2hex(random_bytes(32)); }
    private static function token_hash($token) { return hash('sha256', $token); }
    private static function valid_token($token) { return is_string($token) && (bool)preg_match('/^[a-f0-9]{64}$/D', $token); }

    private static function service_mail($email, $subject, $html) {
        if (!self::is_live()) { return new WP_Error('kn_mail_disabled','Newsletter nicht aktiv.'); }
        $s = self::settings();
        return self::api('POST','smtp/email',['sender'=>['email'=>$s['sender_email'],'name'=>$s['sender_name']],'to'=>[['email'=>$email]],'subject'=>$subject,'htmlContent'=>$html,'textContent'=>wp_strip_all_tags(str_replace(['<br>','</p>'],"\n",$html))]);
    }

    public static function handle_subscribe() {
        global $wpdb;
        if (!self::request_ok('kn_subscribe')) { self::screen('Anmeldung','<p>Das Formular konnte nicht geprüft werden. Bitte laden Sie die Seite neu, warten Sie einen Moment und senden Sie es erneut.</p>',400); return; }
        if (!self::is_live()) { self::screen('Newsletter','<p>Der Newsletter ist im Testbetrieb. Es wurde nichts versendet.</p>'); return; }
        $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        $orgs = array_values(array_unique(array_filter(array_map('absint',(array)($_POST['org_ids'] ?? [$_POST['org_id'] ?? 0])))));
        $types = array_values(array_intersect(array_keys(self::topics()), array_map('sanitize_key', (array)wp_unslash($_POST['topics'] ?? []))));
        $themes = [];
        foreach (array_unique(array_map('absint', (array)($_POST['topic_ids'] ?? []))) as $term_id) { if ($term_id && self::valid_audience(0, self::topic_key($term_id))) { $themes[] = $term_id; } }
        $scoped = $orgs && $types;
        if (!is_email($email) || strlen($email)>190 || count($orgs)>20 || (!$scoped && !$themes) || ($orgs && !$types) || empty($_POST['consent'])) { self::screen('Anmeldung','<p>Bitte prüfen Sie E-Mail-Adresse, Ihre Auswahl (Themen oder Einrichtungen mit Inhaltsart, höchstens 20 Einrichtungen) und die Einwilligung.</p>',400); return; }
        $names = []; $audiences = [];
        foreach ($orgs as $org) {
            $institution = get_post($org);
            if (!$institution || $institution->post_type !== 'kn_institution' || $institution->post_status !== 'publish' || !empty($institution->post_password)) { self::screen('Anmeldung','<p>Eine ausgewählte Einrichtung ist derzeit nicht öffentlich verfügbar.</p>',400); return; }
            $names[] = $institution->post_title;
            foreach ($types as $type) { $audiences[] = [$org, $type]; }
        }
        foreach ($themes as $term_id) { $term = get_term($term_id); $names[] = 'Thema „' . $term->name . '“'; $audiences[] = [0, self::topic_key($term_id)]; }
        $generic = '<p>Wenn die Anmeldung möglich ist, erhalten Sie eine E-Mail mit einem Bestätigungslink. Bitte prüfen Sie auch den Spamordner.</p>';
        if (!self::within_limit($email)) { self::screen('Anmeldung','<p>Zu viele Anfragen in kurzer Zeit. Bitte versuchen Sie es in einer Stunde erneut.</p>',429); return; }
        $token = self::token(); $hash = self::token_hash($token); $sub = self::table('subscribers'); $pending = false;
        foreach ($audiences as $audience) {
            list($org, $topic) = $audience;
            $existing = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $sub WHERE email=%s AND org_id=%d AND topic=%s",$email,$org,$topic));
            if ($existing && $existing->status === 'active') { continue; }
            $data = ['email'=>$email,'org_id'=>$org,'topic'=>$topic,'status'=>'pending','token_hash'=>$hash,'token_expires'=>time()+172800,'updated_at'=>time()];
            if ($existing) { $written = $wpdb->update($sub,$data,['id'=>(int)$existing->id]); }
            else { $data['created_at']=time(); $written = $wpdb->insert($sub,$data); }
            if ($written !== false) { $pending = true; }
        }
        if ($pending) {
            $sent = self::service_mail($email,'Bitte bestätigen Sie Ihr Abonnement – Konstanth','<p>Sie haben Neuigkeiten angefordert: ' . esc_html(implode(', ', $names)) . '.</p><p><a href="' . esc_url(self::link('confirm', $token)) . '">Ausgewählte Abonnements bestätigen</a></p><p>Dieser Link ist 48 Stunden gültig. Ohne Bestätigung werden Sie nicht angemeldet. Falls Sie dies nicht angefordert haben, ignorieren Sie diese Nachricht.</p>');
            if (is_wp_error($sent)) { self::log_error('confirmation', $sent->get_error_code()); }
        }
        self::screen('Anmeldung',$generic);
    }

    /** First step of a confirmation link: shows the button (mail scanners open links automatically). */
    private static function confirm_view($token) {
        global $wpdb;
        if (!self::valid_token($token)) { return '<p>Dieser Bestätigungslink ist ungültig oder abgelaufen.</p>'; }
        $sub = self::table('subscribers');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sub WHERE token_hash=%s AND status='pending' AND token_expires>=%d",self::token_hash($token),time()));
        if (!$rows) { return '<p>Dieser Link wurde bereits verwendet oder ist abgelaufen. Bei Bedarf können Sie sich erneut anmelden.</p>'; }
        if (!self::is_live()) { return '<p>Der Newsletter ist derzeit pausiert. Bitte versuchen Sie es später erneut.</p>'; }
        $items = ''; foreach ($rows as $row) { $items .= '<li>' . esc_html(self::topic_label($row->org_id, $row->topic)) . '</li>'; }
        return self::confirm_form($token, $items);
    }

    public static function handle_confirm() {
        global $wpdb;
        $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $token = sanitize_text_field(wp_unslash(($post ? $_POST['token'] : $_GET['token']) ?? ''));
        if (!$post) {
            if (self::page_url() && self::valid_token($token)) { wp_safe_redirect(self::link('confirm', $token)); exit; }
            self::screen('Abonnement bestätigen', self::confirm_view($token), self::valid_token($token) ? 200 : 400); return;
        }
        if (!self::valid_token($token)) { self::screen('Bestätigung','<p>Dieser Bestätigungslink ist ungültig oder abgelaufen.</p>',400); return; }
        $sub = self::table('subscribers'); $hash = self::token_hash($token);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sub WHERE token_hash=%s AND status='pending' AND token_expires>=%d",$hash,time()));
        if (!$rows) { self::screen('Bestätigung','<p>Dieser Link wurde bereits verwendet oder ist abgelaufen. Bei Bedarf können Sie sich erneut anmelden.</p>'); return; }
        if (!self::is_live()) { self::screen('Bestätigung','<p>Der Newsletter ist derzeit pausiert. Bitte versuchen Sie es später erneut.</p>'); return; }
        $wpdb->query($wpdb->prepare("UPDATE $sub SET status='active',confirmed_at=%d,updated_at=%d,token_hash='',token_expires=0 WHERE token_hash=%s AND status='pending' AND token_expires>=%d",time(),time(),$hash,time()));
        // Lists are reconciled at dispatch; confirmation never causes campaign delivery.
        $manage = self::new_manage_token($rows[0]->email);
        self::screen('Abonnement bestätigt','<p>Vielen Dank – Ihr Abonnement ist jetzt aktiv.</p><p><a href="' . esc_url(self::link('preferences', $manage)) . '">Abonnements verwalten</a></p>');
    }

    private static function confirm_form($token, $items = '') {
        return '<span class="kn-confirm-mark" aria-hidden="true">✓</span><h2>Ein Klick fehlt noch</h2><p>Bitte bestätigen Sie diese Abonnements mit dem Knopf:</p>' . ($items ? '<ul>' . $items . '</ul>' : '') . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kn_confirm"><input type="hidden" name="token" value="' . esc_attr($token) . '"><button type="submit" class="kn-button">Abonnement jetzt bestätigen</button></form><p class="kn-form-note">Haben Sie sich nicht angemeldet? Dann ignorieren Sie diese Seite einfach.</p>';
    }

    private static function new_manage_token($email) {
        global $wpdb;
        $token = self::token();
        $wpdb->update(self::table('subscribers'),['manage_hash'=>self::token_hash($token),'manage_expires'=>time()+3600],['email'=>$email]);
        return $token;
    }

    public static function handle_manage_request() {
        global $wpdb;
        if (!self::request_ok('kn_manage_request')) { self::screen('Abonnements','<p>Bitte laden Sie das Formular neu, warten Sie einen Moment und versuchen Sie es erneut.</p>',400); return; }
        $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        $generic = '<p>Wenn für diese Adresse Abonnements vorhanden sind, erhalten Sie einen persönlichen Verwaltungslink.</p>';
        if (is_email($email) && self::is_live()) {
            if (!self::within_limit($email)) { self::screen('Abonnements','<p>Zu viele Anfragen in kurzer Zeit. Bitte versuchen Sie es in einer Stunde erneut.</p>',429); return; }
            $sub = self::table('subscribers');
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $sub WHERE email=%s LIMIT 1",$email))) {
                $token = self::new_manage_token($email);
                $sent = self::service_mail($email,'Ihre Abonnements verwalten – Konstanth','<p><a href="' . esc_url(self::link('preferences', $token)) . '">Abonnements verwalten</a></p><p>Der persönliche Link ist eine Stunde gültig. Geben Sie ihn nicht weiter. Falls Sie ihn nicht angefordert haben, ignorieren Sie diese Nachricht.</p>');
                if (is_wp_error($sent)) { self::log_error('manage', $sent->get_error_code()); }
            }
        }
        self::screen('Abonnements',$generic);
    }

    private static function preferences_view($token) {
        global $wpdb;
        if (!self::valid_token($token)) { return '<p>Dieser Verwaltungslink ist ungültig oder abgelaufen.</p>'; }
        $hash = self::token_hash($token);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('subscribers') . " WHERE manage_hash=%s AND manage_expires>=%d ORDER BY org_id,topic",$hash,time()));
        if (!$rows) { return '<p>Dieser Verwaltungslink ist abgelaufen. Fordern Sie auf der Website einen neuen Link an.</p>'; }
        $html = '<form class="kn-newsletter-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kn_preferences"><input type="hidden" name="token" value="' . esc_attr($token) . '">' . wp_nonce_field('kn_preferences_' . $hash,'_kn_nonce',true,false);
        $html .= '<h2 class="kn-section-title">Ihre Abonnements</h2><fieldset class="kn-filter-group"><legend>Markieren Sie, was Sie beenden möchten</legend><div class="kn-manage-list">';
        $any = false;
        foreach ($rows as $row) {
            if ($row->status === 'unsubscribed') { continue; }
            $any = true;
            $html .= '<label class="kn-check"><input type="checkbox" name="cancel[]" value="' . (int)$row->id . '"><span>' . esc_html(self::topic_label($row->org_id, $row->topic) . ($row->status === 'pending' ? ' (noch nicht bestätigt)' : '')) . '</span></label>';
        }
        if (!$any) { $html .= '<p>Es bestehen keine aktiven Abonnements mehr.</p>'; }
        return $html . '</div></fieldset><p class="kn-form-note">Für neue Themen ist eine erneute Anmeldung mit Bestätigung nötig.</p><div class="kn-filter-actions"><button class="kn-button" type="submit">Auswahl speichern</button></div></form>';
    }

    public static function handle_preferences() {
        global $wpdb;
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? $_GET['token'] ?? ''));
        if (!self::valid_token($token)) { self::screen('Abonnements','<p>Dieser Verwaltungslink ist ungültig oder abgelaufen.</p>',400); return; }
        $sub = self::table('subscribers'); $hash = self::token_hash($token);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sub WHERE manage_hash=%s AND manage_expires>=%d ORDER BY org_id,topic",$hash,time()));
        if (!$rows) { self::screen('Abonnements','<p>Dieser Verwaltungslink ist abgelaufen. Fordern Sie auf der Website einen neuen Link an.</p>',400); return; }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if (!self::request_ok('kn_preferences_' . $hash)) { self::screen('Abonnements','<p>Bitte laden Sie die Seite neu.</p>',400); return; }
            $cancel = array_map('absint',(array)($_POST['cancel'] ?? []));
            foreach ($rows as $row) {
                if (!in_array((int)$row->id,$cancel,true)) { continue; }
                $wpdb->update($sub,['status'=>'unsubscribed','updated_at'=>time(),'token_hash'=>'','token_expires'=>0],['id'=>(int)$row->id]);
                self::remove_from_list((int)$row->org_id,$row->topic,[$row->email]);
            }
            // Invalidate the bearer token after a successful mutation.
            $wpdb->query($wpdb->prepare("UPDATE $sub SET manage_hash='',manage_expires=0 WHERE manage_hash=%s",$hash));
            self::screen('Abonnements','<p>Ihre Auswahl wurde gespeichert. Beendete Abonnements erhalten keine neuen Rundmails. Bereits an Brevo übergebene E-Mails können schon unterwegs sein.</p>'); return;
        }
        if (self::page_url()) { wp_safe_redirect(self::link('preferences', $token)); exit; }
        self::screen('Abonnements verwalten', self::preferences_view($token));
    }

    public static function render_settings() {
        if (!current_user_can('manage_options')) { wp_die('Keine Berechtigung.', '', ['response'=>403]); }
        $s = self::settings();
        echo '<div class="wrap"><h1>Rundmail-Einstellungen</h1><p>Nach der Installation bleibt der Versand aus. Im Testmodus werden weder Bestätigungs-E-Mails noch Kampagnen gesendet. Bereits im Test verarbeitete Aufträge werden beim Einschalten nicht automatisch verschickt.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kn_mail_settings">';
        wp_nonce_field('kn_mail_settings');
        echo '<table class="form-table"><tr><th><label for="kn-api">Brevo API-Schlüssel</label></th><td><input id="kn-api" type="password" name="api_key" autocomplete="new-password" value="" class="regular-text"' . (defined('KN_BREVO_API_KEY') ? ' disabled' : '') . '><p class="description">' . (defined('KN_BREVO_API_KEY') ? 'Über KN_BREVO_API_KEY in der Serverkonfiguration gesetzt.' : ($s['api_key'] ? 'Schlüssel vorhanden. Leer lassen, um ihn beizubehalten.' : 'Noch kein Schlüssel gespeichert.')) . '</p></td></tr>';
        foreach (['sender_email'=>'Verifizierte Absenderadresse','sender_name'=>'Absendername','folder_id'=>'Brevo Ordner-ID für neue Listen'] as $key=>$label) {
            echo '<tr><th><label for="kn-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="kn-' . esc_attr($key) . '" name="' . esc_attr($key) . '" type="' . ($key === 'folder_id' ? 'number" min="1' : ($key === 'sender_email' ? 'email' : 'text')) . '" value="' . esc_attr($s[$key]) . '"></td></tr>';
        }
        echo '<tr><th><label for="kn-address">Postanschrift für E-Mail-Fußzeilen</label></th><td><textarea id="kn-address" name="postal_address" rows="4" class="large-text">' . esc_textarea($s['postal_address']) . '</textarea></td></tr><tr><th>Freigabe</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked(!empty($s['enabled']), true, false) . '> Newsletter grundsätzlich aktivieren</label><br><label><input type="checkbox" name="test_mode" value="1" ' . checked(!empty($s['test_mode']),true,false) . '> Testmodus: keine externen E-Mails oder API-Aufrufe</label></td></tr><tr><th>Rundmails von Einrichtungen</th><td><label><input type="checkbox" name="require_approval" value="1" ' . checked(!empty($s['require_approval']),true,false) . '> brauchen meine Freigabe, bevor sie versendet werden</label><p class="description">Unabhängig davon geht pro Einrichtung höchstens eine Rundmail pro Tag hinaus. Einzelne Einrichtungen sperren Sie im jeweiligen Institutionsprofil.</p></td></tr><tr><th>Beim Löschen des Plugins</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1" ' . checked((bool)get_option('kn_delete_on_uninstall'), true, false) . '> alle konstanth-Daten entfernen</label><p class="description">Standardmäßig bleiben Inhalte, Kategorien und Abonnements erhalten, wenn das Plugin gelöscht wird. Mit diesem Häkchen werden beim Löschen (nicht beim Deaktivieren) alle Termine, Stellen, Nachrichten, Einrichtungen, Kategorien, Abonnements und Einstellungen endgültig entfernt. Bilder in der Mediathek, Benutzerkonten und Daten bei Brevo bleiben bestehen.</p></td></tr><tr><th>Seite für Anmeldung und Bestätigung</th><td>' . (self::page_url() ? '<a href="' . esc_url(self::page_url()) . '">' . esc_html(self::page_url()) . '</a><p class="description">Bestätigungs- und Verwaltungsschritte erscheinen auf dieser Seite in Ihrem Theme.</p>' : '<p class="description">Noch keine veröffentlichte Seite mit dem Baustein „Rundmail-Anmeldung“ gefunden. Bis dahin erscheinen Bestätigungen als schlichte WordPress-Seite.</p>') . '</td></tr></table>';
        submit_button('Einstellungen speichern');
        echo '</form><h2>Einrichtung und Betrieb</h2><ol><li>Brevo-Konto, Absenderdomain und Adresse verifizieren; einen eigenen Ordner anlegen und dessen ID oben eintragen.</li><li>Testmodus verwenden und die E-Mail-Vorschau an eigenen Inhalten prüfen.</li><li>Live-Versand bewusst freigeben; danach echte Anmeldung und Abmeldung an einer eigenen Adresse testen.</li><li>Für verlässliche Zeitfenster einen Server-Cron alle fünf Minuten einrichten. WordPress-Cron hängt sonst von Seitenaufrufen ab; Zustellung ist keine minutengenaue Garantie.</li></ol><p>Pro Einrichtung und Thema erstellt das Plugin eine eigene Liste. Nutzen Sie diese Listen ausschließlich für Konstanth; sie werden vor Kampagnen mit den lokalen, bestätigten Abonnements abgeglichen. Globale Brevo-Sperren werden nicht aufgehoben. Die allgemeine Brevo-Abmeldung im E-Mail-Fuß beendet E-Mails des gesamten Brevo-Kontos; der Verwaltungslink erlaubt einzelne lokale Abonnements.</p><p>Verzögerung: „24 Stunden nach Veröffentlichung“ zählt ab dem Veröffentlichungszeitpunkt des Eintrags, auch wenn die Rundmail-Option erst später gesetzt wird. Bearbeiten löst keinen zweiten Versand aus. Ein Testlauf kann nur im Verwaltungsbereich ausdrücklich erneut eingeplant werden.</p><h2>Listen nach unklarer API-Antwort zuordnen</h2><p>Falls eine Listenanlage unterbrochen wurde, zuerst in Brevo nach „Konstanth ID Thema“ suchen. Erst dann die vorhandene Listen-ID eintragen. Dadurch wird keine neue Kampagne gesendet.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kn_mail_action"><input type="hidden" name="operation" value="map_list">';
        wp_nonce_field('kn_mail_action');
        echo '<p><label>Einrichtungs-ID (0 für Themenlisten) <input name="org_id" type="number" min="0" required></label> <label>Thema <select name="topic">';
        foreach(self::topics() as $k=>$v) { echo '<option value="' . esc_attr($k) . '">' . esc_html($v) . '</option>'; }
        if (class_exists('KN_Categories')) { foreach (KN_Categories::terms('kn_topic') as $term) { echo '<option value="' . esc_attr(self::topic_key($term->term_id)) . '">Themenliste: ' . esc_html($term->name) . '</option>'; } }
        echo '</select></label> <label>Brevo Listen-ID <input name="list_id" type="number" min="1" required></label></p>';
        submit_button('Vorhandene Liste prüfen und zuordnen','secondary'); echo '</form></div>';
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) { wp_die('Keine Berechtigung.', '', ['response'=>403]); }
        check_admin_referer('kn_mail_settings');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Ungültige Anfrage.', '', ['response'=>405]); }
        $s = self::settings(); $was_live = self::is_live();
        if (!defined('KN_BREVO_API_KEY') && !empty($_POST['api_key'])) { $s['api_key'] = trim(wp_unslash($_POST['api_key'])); }
        $s['sender_email'] = sanitize_email(wp_unslash($_POST['sender_email'] ?? ''));
        $s['sender_name'] = sanitize_text_field(wp_unslash($_POST['sender_name'] ?? ''));
        $s['postal_address'] = sanitize_textarea_field(wp_unslash($_POST['postal_address'] ?? ''));
        $s['folder_id'] = absint($_POST['folder_id'] ?? 0);
        $s['enabled'] = empty($_POST['enabled']) ? 0 : 1;
        $s['test_mode'] = empty($_POST['test_mode']) ? 0 : 1;
        $s['require_approval'] = empty($_POST['require_approval']) ? 0 : 1;
        update_option('kn_delete_on_uninstall', empty($_POST['delete_on_uninstall']) ? 0 : 1, false);
        if ($s['enabled'] && !$s['test_mode'] && (!$s['api_key'] || !is_email($s['sender_email']) || !$s['sender_name'] || !$s['postal_address'] || !$s['folder_id'])) { wp_die('Für den Live-Versand fehlen API-Schlüssel, gültiger Absender, Name, Anschrift oder Ordner-ID.', '', ['response'=>400]); }
        // Saving live settings deliberately confirms this site address (see address_confirmed()).
        if ($s['enabled'] && !$s['test_mode']) { $s['live_url'] = home_url('/'); }
        if (defined('KN_BREVO_API_KEY')) { unset($s['api_key']); }
        update_option('kn_mail_settings',$s,false);
        if (!$was_live && self::is_live()) { self::go_live_transition(); }
        wp_safe_redirect(admin_url('admin.php?page=kn-settings&saved=1')); exit;
    }

    public static function render_admin() {
        global $wpdb;
        if (!current_user_can('manage_options') && !current_user_can('kn_access')) { wp_die('Keine Berechtigung.', '', ['response'=>403]); }
        $admin = current_user_can('manage_options'); $org = $admin ? 0 : KN_Core::org_id();
        if (!$admin && !$org) { echo '<div class="wrap"><h1>Rundmails</h1><p>Ihr Zugang ist keiner Einrichtung zugeordnet.</p></div>'; return; }
        $jobs = self::table('jobs'); $sub = self::table('subscribers');
        $where = $admin ? '' : $wpdb->prepare(' WHERE org_id=%d', $org);
        $rows = $wpdb->get_results("SELECT * FROM $jobs" . $where . ' ORDER BY id DESC LIMIT 100');
        $counts = $wpdb->get_results("SELECT topic,status,COUNT(*) AS n FROM $sub" . $where . ' GROUP BY topic,status');
        echo '<div class="wrap"><h1>Rundmails</h1><p><strong>' . (self::is_live() ? 'Live-Versand freigegeben' : 'Testbetrieb – kein externer Versand') . '</strong></p><p>„An Brevo übergeben“ bestätigt die Annahme durch den Versanddienst, nicht die Zustellung im Postfach. Unklare API-Antworten werden nie automatisch wiederholt.</p><h2>Abonnements</h2><ul>';
        foreach ($counts as $c) { echo '<li>' . esc_html((self::topic_term($c->topic) ? self::topic_label(0, $c->topic) : (self::topics()[$c->topic] ?? $c->topic)) . ' / ' . (['active'=>'aktiv','pending'=>'unbestätigt','unsubscribed'=>'beendet'][$c->status] ?? $c->status)) . ': ' . (int)$c->n . '</li>'; }
        echo '</ul><p>Einrichtungszugänge sehen ausschließlich zusammengefasste Zahlen ihrer eigenen Einrichtung.</p><h2>Letzte Versandaufträge</h2><table class="widefat striped"><thead><tr><th>Inhalt</th><th>Geplant</th><th>Status</th><th>Aktion</th></tr></thead><tbody>';
        $labels = ['approval'=>'Wartet auf Freigabe','pending'=>'Geplant','sending'=>'In Verarbeitung','sent'=>'An Brevo übergeben','dry_run'=>'Testlauf beendet','failed'=>'Fehlgeschlagen','cancelled'=>'Beendet / abgebrochen','unknown'=>'Unklar – manuell in Brevo prüfen'];
        foreach ($rows as $row) {
            $p = get_post($row->post_id);
            echo '<tr><td>' . esc_html($p ? $p->post_title : 'Gelöschter Inhalt') . '<br><small>Auftrag #' . (int)$row->id . ($admin && $row->campaign_id ? ' · Brevo #' . (int)$row->campaign_id : '') . '</small></td><td>' . esc_html(wp_date('d.m.Y H:i',(int)$row->due_at)) . '</td><td>' . esc_html($labels[$row->status] ?? $row->status) . ($row->error_code ? '<br><small>' . esc_html(self::error_text($row->error_code)) . '</small>' : '') . '</td><td>';
            if ($p && ($admin || KN_Core::can_manage($p->ID))) {
                $url = wp_nonce_url(add_query_arg(['action'=>'kn_mail_preview','post_id'=>$p->ID],admin_url('admin-post.php')),'kn_mail_preview_' . $p->ID);
                echo '<a target="_blank" rel="noopener" href="' . esc_url($url) . '">Vorschau</a> ';
            }
            if ($admin && in_array($row->status,['approval','pending','dry_run','failed'],true) && !(int)$row->campaign_id) {
                echo '<form method="post" style="display:inline" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kn_mail_action"><input type="hidden" name="job_id" value="' . (int)$row->id . '">' . wp_nonce_field('kn_mail_action','_wpnonce',true,false);
                if ($row->status === 'approval') { echo '<button type="submit" name="operation" value="approve" class="button button-primary">Freigeben</button> '; }
                elseif ($row->status !== 'pending') { echo '<button type="submit" name="operation" value="requeue" class="button">Ausdrücklich erneut einplanen</button> '; }
                echo '<button type="submit" name="operation" value="cancel" class="button">Abbrechen</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="4">Noch keine Versandaufträge vorhanden.</td></tr>'; }
        echo '</tbody></table>';
        if ($admin) {
            $last = (int)get_option('kn_last_queue_run', 0);
            echo '<p>Letzter Lauf der Warteschlange: ' . esc_html($last ? wp_date('d.m.Y H:i', $last) : 'noch keiner') . '</p>';
            $errors = (array)get_option('kn_mail_errors', []);
            if ($errors) {
                echo '<h2>Fehlgeschlagene Service-Mails</h2><ul>';
                foreach ($errors as $e) { echo '<li>' . esc_html(wp_date('d.m.Y H:i', (int)$e['time']) . ' · ' . ($e['context'] === 'confirmation' ? 'Bestätigungsmail' : 'Verwaltungslink') . ' · ' . self::error_text($e['code'])) . '</li>'; }
                echo '</ul><p>Empfängeradressen werden hier bewusst nicht gespeichert.</p>';
            }
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=kn-settings')) . '">Versand konfigurieren</a></p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="kn_mail_action"><input type="hidden" name="operation" value="run">' . wp_nonce_field('kn_mail_action','_wpnonce',true,false) . '<p><button class="button" type="submit">Fällige Warteschlange jetzt verarbeiten</button></p></form><p>Bei „Unklar“ oder vorhandener Brevo-Kampagnen-ID erfolgt keine Wiederholung. Suchen Sie den Auftrag in Brevo; dort kann eine bereits erzeugte Kampagne manuell geprüft und freigegeben werden.</p>';
        }
        echo '</div>';
    }

    public static function admin_action() {
        global $wpdb;
        if (!current_user_can('manage_options')) { wp_die('Keine Berechtigung.', '', ['response'=>403]); }
        check_admin_referer('kn_mail_action');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Ungültige Anfrage.', '', ['response'=>405]); }
        $op = sanitize_key(wp_unslash($_POST['operation'] ?? '')); $t = self::table('jobs'); $id = absint($_POST['job_id'] ?? 0);
        if ($op === 'run') { self::run_queue(); }
        elseif ($op === 'cancel') { $wpdb->query($wpdb->prepare("UPDATE $t SET status='cancelled',error_code='admin_cancelled',updated_at=%d WHERE id=%d AND status IN ('approval','pending','dry_run','failed') AND campaign_id=0",time(),$id)); }
        elseif ($op === 'approve') { self::approve_job($id); }
        elseif ($op === 'requeue') {
            $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$id));
            if (!$job || !self::eligible($job->post_id) || !in_array($job->status,['dry_run','failed'],true) || $job->campaign_id) { wp_die('Dieser Auftrag darf nicht erneut eingeplant werden.', '', ['response'=>400]); }
            $wpdb->query($wpdb->prepare("UPDATE $t SET status='pending',error_code='',due_at=%d,updated_at=%d WHERE id=%d AND status IN ('dry_run','failed') AND campaign_id=0",time(),time(),$id));
        }
        elseif ($op === 'map_list') {
            $org = absint($_POST['org_id'] ?? 0); $topic = sanitize_key(wp_unslash($_POST['topic'] ?? '')); $list = absint($_POST['list_id'] ?? 0); $p = get_post($org);
            if ((!$org && !self::valid_audience(0, $topic)) || ($org && (!$p || $p->post_type !== 'kn_institution' || !isset(self::topics()[$topic]))) || !$list || !self::is_live()) { wp_die('Einrichtung, Thema, Listen-ID und Live-Verbindung prüfen.', '', ['response'=>400]); }
            $r = self::api('GET','contacts/lists/' . $list);
            if (is_wp_error($r) || (int)($r['folderId'] ?? 0) !== (int)self::settings()['folder_id']) { wp_die('Die Liste konnte im eingestellten Brevo-Ordner nicht geprüft werden.', '', ['response'=>400]); }
            self::store_list($org, $topic, $list); delete_option('kn_list_lock_' . $org . '_' . $topic);
        }
        else { wp_die('Unbekannte Aktion.', '', ['response'=>400]); }
        wp_safe_redirect(admin_url('admin.php?page=kn-mail')); exit;
    }

    public static function preview() {
        $id = absint($_GET['post_id'] ?? 0);
        if (!current_user_can('manage_options') && !KN_Core::can_manage($id)) { wp_die('Keine Berechtigung.', '', ['response'=>403]); }
        check_admin_referer('kn_mail_preview_' . $id);
        $p = get_post($id);
        if (!$p || !isset(self::topics()[$p->post_type])) { wp_die('Inhalt nicht gefunden.', '', ['response'=>404]); }
        nocache_headers(); header('Referrer-Policy: no-referrer'); header('Content-Type: text/html; charset=UTF-8');
        header("Content-Security-Policy: default-src 'none'; img-src https: http:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
        echo self::email_html($id); exit;
    }

    public static function privacy_exporters($items) {
        $items['konstanth-newsletter']=['exporter_friendly_name'=>'Konstanth Newsletter','callback'=>[__CLASS__,'privacy_export']]; return $items;
    }

    public static function privacy_export($email, $page=1) {
        global $wpdb;
        $t=self::table('subscribers');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE email=%s",strtolower(sanitize_email($email)))); $data=[];
        foreach($rows as $r) { $data[]=['group_id'=>'konstanth-newsletter','group_label'=>'Konstanth Newsletter','item_id'=>'kn-sub-' . $r->id,'data'=>[['name'=>'E-Mail','value'=>$r->email],['name'=>'Abonnement','value'=>self::topic_label($r->org_id, $r->topic)],['name'=>'Status','value'=>$r->status],['name'=>'Bestätigt am (UTC)','value'=>$r->confirmed_at ? gmdate('c',(int)$r->confirmed_at) : 'Nicht bestätigt']]]; }
        return ['data'=>$data,'done'=>true];
    }

    public static function privacy_erasers($items) {
        $items['konstanth-newsletter']=['eraser_friendly_name'=>'Konstanth Newsletter','callback'=>[__CLASS__,'privacy_erase']]; return $items;
    }

    public static function privacy_erase($email, $page=1) {
        global $wpdb;
        $t=self::table('subscribers'); $email=strtolower(sanitize_email($email));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE email=%s",$email)); $retained=false; $removed=false;
        foreach($rows as $r) {
            $wpdb->update($t,['status'=>'unsubscribed','updated_at'=>time()],['id'=>(int)$r->id]);
            // Institution lists live in post meta, theme lists in term meta; both must be cleaned at the provider.
            if (self::stored_list((int)$r->org_id, $r->topic) && !self::is_live()) { $retained=true; continue; }
            $result=self::remove_from_list($r->org_id,$r->topic,[$email]);
            if (is_wp_error($result)) { $retained=true; continue; }
            $wpdb->delete($t,['id'=>(int)$r->id],['%d']); $removed=true;
        }
        $messages=$retained ? ['Abonnements wurden lokal beendet. Für die Entfernung aus Brevo-Listen muss die Verbindung wieder verfügbar sein. Brevo-Kontaktdaten sind zusätzlich im Brevo-Konto zu prüfen.'] : ($rows ? ['Lokale Abonnements wurden entfernt. Brevo-Kontaktdaten und Versandprotokolle sind zusätzlich im Brevo-Konto zu prüfen.'] : []);
        return ['items_removed'=>$removed,'items_retained'=>$retained,'messages'=>$messages,'done'=>true];
    }
}
