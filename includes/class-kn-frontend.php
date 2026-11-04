<?php
/** Public, theme-contained shortcodes and detail views. */
if (!defined('ABSPATH')) { exit; }

class KN_Frontend {
    const PLACEHOLDER = '<!--kn-detail-->';
    private $instance = 0;
    private $detail = null;
    private static $colors_added = false;
    private $own_image = false;

    /** Every shortcode name and the method that renders it; also tells assets() which pages need the stylesheet. */
    const SHORTCODES = array('konstanth_events' => 'events', 'konstanth_termine' => 'events', 'konstanth_veranstaltungen' => 'events', 'konstanth_jobs' => 'jobs', 'konstanth_stellen' => 'jobs', 'konstanth_institutions' => 'institutions', 'konstanth_institutionen' => 'institutions', 'konstanth_news' => 'news', 'konstanth_donations' => 'donations', 'konstanth_foerdern' => 'donations', 'konstanth_aktuelle_termine' => 'upcoming', 'konstanth_aktuelle_veranstaltungen' => 'upcoming', 'konstanth_upcoming' => 'upcoming');

    public function __construct() {
        foreach (self::SHORTCODES as $tag => $method) {
            add_shortcode($tag, array($this, $method));
        }
        add_action('wp_enqueue_scripts', array($this, 'assets'));
        // The detail view reserves its place before wpautop and shortcodes run, and is inserted afterwards.
        add_filter('the_content', array($this, 'content'), 8);
        add_filter('the_content', array($this, 'restore_detail'), 999);
        add_filter('wp_robots', array($this, 'robots'));
        add_action('wp_head', array($this, 'print_schema'));
        // Block themes print the featured image in their single template; the detail layout already shows it once.
        add_filter('post_thumbnail_html', array($this, 'single_image'), 10, 2);
        // The event page shows its title in the banner card; the theme's own title line is dropped there (block themes here, classic themes by a body class in the stylesheet).
        add_filter('render_block_core/post-title', array($this, 'theme_title'), 10, 3);
        add_filter('body_class', function ($classes) { if (is_singular('kn_event')) { $classes[] = 'kn-event-page-active'; } return $classes; });
        add_action('init', array($this, 'register_blocks'));
        add_filter('block_categories_all', function ($categories) { $categories[] = array('slug' => 'konstanth', 'title' => 'konstanth'); return $categories; });
        // The block editor shows the same styles as the website, including the category colours.
        add_action('enqueue_block_assets', function () { if (is_admin()) { self::enqueue(); } });
        // Past events stay reachable for shared links but leave sitemaps and the search index.
        add_filter('wp_sitemaps_posts_query_args', function ($args, $post_type) { if ($post_type === 'kn_event') { $args['meta_query'] = array(array('key' => '_kn_end', 'value' => time(), 'compare' => '>=', 'type' => 'NUMERIC')); } return $args; }, 10, 2);
        add_action('admin_post_kn_ics', array($this, 'download_ics'));
        add_action('admin_post_nopriv_kn_ics', array($this, 'download_ics'));
    }

    public function assets() {
        wp_register_style('kn-frontend', KN_URL . 'assets/frontend.css', array(), self::asset_version('frontend.css'));
        wp_register_script('kn-frontend', KN_URL . 'assets/frontend.js', array(), self::asset_version('frontend.js'), true);
        $post = get_queried_object();
        if (is_singular(array('kn_event', 'kn_job', 'kn_news', 'kn_institution'))) { self::enqueue(); return; }
        if ($post instanceof WP_Post) {
            // The newsletter form is registered by KN_Mail but uses the same stylesheet.
            foreach (array_merge(array_keys(self::SHORTCODES), array('konstanth_newsletter', 'kn_subscribe')) as $tag) {
                if (has_shortcode($post->post_content, $tag)) { self::enqueue(); return; }
            }
            foreach (array('events', 'jobs', 'institutions', 'news', 'donations', 'newsletter', 'upcoming') as $block) { if (function_exists('has_block') && has_block('konstanth/' . $block, $post)) { self::enqueue(); return; } }
        }
    }

    /** Version plus file time, so browsers and caches pick up changed assets after an update. */
    private static function asset_version($file) { return KN_VERSION . (defined('KN_PATH') && is_file(KN_PATH . 'assets/' . $file) ? '.' . filemtime(KN_PATH . 'assets/' . $file) : ''); }

    private static function enqueue() {
        if (!wp_style_is('kn-frontend', 'registered')) {
            wp_register_style('kn-frontend', KN_URL . 'assets/frontend.css', array(), self::asset_version('frontend.css'));
            wp_register_script('kn-frontend', KN_URL . 'assets/frontend.js', array(), self::asset_version('frontend.js'), true);
        }
        wp_enqueue_style('kn-frontend');
        wp_enqueue_script('kn-frontend');
        // Category colours are administrator settings and travel with the stylesheet as custom properties.
        if (!self::$colors_added) { wp_add_inline_style('kn-frontend', KN_Categories::css()); self::$colors_added = true; }
    }

    /** Opens a plugin block and makes sure its scoped stylesheet is present (also for the newsletter module). */
    public static function portal($class = '') {
        self::enqueue();
        $prefix = '';
        // Builders may render a shortcode after wp_head; print only the scoped stylesheet once.
        if (did_action('wp_head') && !wp_style_is('kn-frontend', 'done')) {
            ob_start(); wp_print_styles('kn-frontend'); $prefix = ob_get_clean();
        }
        return $prefix . '<section class="kn-portal ' . esc_attr($class) . '">';
    }

    private function open($atts, $class = '') {
        $html = self::portal($class);
        if (!empty($atts['heading'])) { $html .= '<h2 class="kn-section-title">' . esc_html($atts['heading']) . '</h2>'; }
        return $html;
    }

    private function attributes($atts, $default_layout = 'grid') {
        $atts = shortcode_atts(array('institution' => 0, 'limit' => 12, 'layout' => $default_layout, 'heading' => ''), $atts);
        return array('institution' => absint($atts['institution']), 'limit' => max(1, min(100, absint($atts['limit']))), 'layout' => $atts['layout'] === 'list' ? 'list' : 'grid', 'heading' => sanitize_text_field($atts['heading']));
    }

    /** Dynamic blocks share the shortcode renderers; the editor preview uses the same server output. */
    public function register_blocks() {
        if (!function_exists('register_block_type')) { return; }
        wp_register_script('kn-blocks', KN_URL . 'assets/blocks.js', array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render'), self::asset_version('blocks.js'), true);
        if (is_admin()) {
            $institutions = get_posts(array('post_type' => 'kn_institution', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids'));
            wp_add_inline_script('kn-blocks', 'window.knBlocks=' . wp_json_encode(array('institutions' => array_map(function ($id) { return array('id' => (int) $id, 'title' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8')); }, $institutions))) . ';', 'before');
        }
        $list = array('institution' => array('type' => 'number', 'default' => 0), 'limit' => array('type' => 'number', 'default' => 12), 'layout' => array('type' => 'string', 'default' => 'grid'), 'heading' => array('type' => 'string', 'default' => ''));
        foreach (array('events' => 'events', 'jobs' => 'jobs', 'institutions' => 'institutions', 'news' => 'news', 'donations' => 'donations') as $name => $method) {
            $attributes = $list; if ($name === 'events') { $attributes['layout']['default'] = 'list'; }
            register_block_type('konstanth/' . $name, array('api_version' => 3, 'editor_script' => 'kn-blocks', 'attributes' => $attributes, 'render_callback' => function ($a) use ($method) {
                return $this->$method(array('institution' => absint($a['institution'] ?? 0), 'limit' => absint($a['limit'] ?? 12), 'layout' => ($a['layout'] ?? 'grid') === 'list' ? 'list' : 'grid', 'heading' => sanitize_text_field($a['heading'] ?? '')));
            }));
        }
        register_block_type('konstanth/upcoming', array('api_version' => 3, 'editor_script' => 'kn-blocks', 'attributes' => array('institution' => array('type' => 'number', 'default' => 0), 'limit' => array('type' => 'number', 'default' => 3), 'heading' => array('type' => 'string', 'default' => 'Unsere aktuellen Veranstaltungen'), 'button' => array('type' => 'string', 'default' => 'Alle Veranstaltungen'), 'link' => array('type' => 'string', 'default' => '')), 'render_callback' => function ($a) {
            return $this->upcoming(array('institution' => absint($a['institution'] ?? 0), 'limit' => absint($a['limit'] ?? 3), 'heading' => (string) ($a['heading'] ?? ''), 'button' => (string) ($a['button'] ?? ''), 'link' => (string) ($a['link'] ?? '')));
        }));
        register_block_type('konstanth/newsletter', array('api_version' => 3, 'editor_script' => 'kn-blocks', 'attributes' => array('institution' => array('type' => 'number', 'default' => 0)), 'render_callback' => function ($a) { return KN_Mail::subscribe_form(array('institution' => absint($a['institution'] ?? 0))); }));
    }

    private function key() { return 'kn_' . (++$this->instance); }

    private function input($key) {
        return isset($_GET[$key]) && is_array($_GET[$key]) ? wp_unslash($_GET[$key]) : array();
    }

    private function route_fields($current_key) {
        $html = '';
        foreach (array('page_id', 'p') as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key]) && absint($_GET[$key])) { $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(absint($_GET[$key])) . '">'; }
        }
        if (isset($_GET['post_type']) && is_scalar($_GET['post_type'])) { $html .= '<input type="hidden" name="post_type" value="' . esc_attr(sanitize_key(wp_unslash($_GET['post_type']))) . '">'; }
        // A GET form discards query parameters in its action URL, including plain permalinks.
        foreach ($_GET as $key => $value) {
            if ($key === $current_key || !preg_match('/^kn_[1-9][0-9]*$/D', $key) || !is_array($value)) { continue; }
            foreach (array('filter', 'orgs', 'topics', 'q', 'view', 'month', 'page') as $field) {
                if (!isset($value[$field])) { continue; }
                $values = is_array($value[$field]) ? array_slice($value[$field], 0, 1000) : array($value[$field]);
                foreach ($values as $item) {
                    if (!is_scalar($item)) { continue; }
                    $name = $key . '[' . $field . ']' . (is_array($value[$field]) ? '[]' : '');
                    $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($item))) . '">';
                }
            }
        }
        return $html;
    }

    private function organizations($only = 0) {
        $args = array('post_type' => 'kn_institution', 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true);
        if ($only) { $args['post__in'] = array($only); }
        return (new WP_Query($args))->posts;
    }

    private static function public_org($id) {
        $post = get_post($id);
        return $post && $post->post_type === 'kn_institution' && $post->post_status === 'publish' && empty($post->post_password);
    }

    private static function public_post($post) {
        if (!$post || $post->post_status !== 'publish' || !empty($post->post_password)) { return false; }
        return $post->post_type === 'kn_institution' ? self::public_org($post->ID) : self::public_org(KN_Core::owner($post->ID));
    }

    private function organization_query($organizations) {
        return array('key' => '_kn_org', 'value' => array_map('intval', wp_list_pluck($organizations, 'ID')), 'compare' => 'IN', 'type' => 'NUMERIC');
    }

    private function topics() { return KN_Categories::terms('kn_topic'); }

    private function selected_ids($input, $field, $allowed, $submitted) {
        if (!$submitted) { return array_map('intval', $allowed); }
        $values = isset($input[$field]) && is_array($input[$field]) ? array_map('absint', $input[$field]) : array();
        return array_values(array_intersect($values, array_map('intval', $allowed)));
    }

    public function events($atts = array()) {
        $atts = $this->attributes($atts, 'list'); $key = $this->key(); $input = $this->input($key);
        $organizations = $this->organizations($atts['institution']); $terms = $this->topics();
        $submitted = isset($input['filter']);
        $orgs = $this->selected_ids($input, 'orgs', wp_list_pluck($organizations, 'ID'), $submitted);
        $topics = $this->selected_ids($input, 'topics', wp_list_pluck($terms, 'term_id'), $submitted);
        $search = isset($input['q']) && is_scalar($input['q']) ? sanitize_text_field($input['q']) : '';
        $view = isset($input['view']) && $input['view'] === 'calendar' ? 'calendar' : 'list';
        $month = isset($input['month']) && is_scalar($input['month']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $input['month']) ? $input['month'] : wp_date('Y-m');
        $month_date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', wp_timezone());
        $this_month = DateTimeImmutable::createFromFormat('!Y-m-d', wp_date('Y-m') . '-01', wp_timezone());
        // Endless month links trap crawlers; the calendar covers one year back and two years ahead.
        if (!$month_date || $month_date < $this_month->modify('-12 months') || $month_date > $this_month->modify('+24 months')) { $month_date = $this_month; }
        $page = isset($input['page']) ? max(1, absint($input['page'])) : 1;
        $args = array('post_type' => 'kn_event', 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => $view === 'calendar' ? 100 : $atts['limit'], 'paged' => $page, 'meta_key' => '_kn_start', 'orderby' => 'meta_value_num', 'order' => 'ASC', 's' => $search);
        $args['meta_query'] = array('relation' => 'AND', array('key' => '_kn_org', 'value' => $orgs ?: array(0), 'compare' => 'IN', 'type' => 'NUMERIC'), array('relation' => 'OR', array('key' => '_kn_start', 'value' => time(), 'compare' => '>=', 'type' => 'NUMERIC'), array('key' => '_kn_end', 'value' => time(), 'compare' => '>=', 'type' => 'NUMERIC')));
        if (!$orgs || (!$topics && $terms)) { $args['post__in'] = array(0); }
        if ($topics && count($topics) !== count($terms)) { $args['tax_query'] = array(array('taxonomy' => 'kn_topic', 'field' => 'term_id', 'terms' => $topics)); }
        if ($view === 'calendar') {
            $start = $month_date->getTimestamp(); $end = $month_date->modify('+1 month')->getTimestamp();
            $args['meta_query'][] = array('key' => '_kn_start', 'value' => $end, 'compare' => '<', 'type' => 'NUMERIC');
            $args['meta_query'][] = array('relation' => 'OR', array('key' => '_kn_start', 'value' => $start, 'compare' => '>=', 'type' => 'NUMERIC'), array('key' => '_kn_end', 'value' => $start, 'compare' => '>=', 'type' => 'NUMERIC'));
        }
        $query = new WP_Query($args);
        $state = array('filter' => 1, 'orgs' => $orgs, 'topics' => $topics, 'q' => $search, 'view' => $view, 'month' => $month_date->format('Y-m'));
        $html = $this->open($atts, 'kn-events');
        $html .= '<div class="kn-events-shell"><aside class="kn-filter-panel"><form method="get" class="kn-filter-form" action="' . esc_url(remove_query_arg($key)) . '">';
        $html .= $this->route_fields($key) . '<input type="hidden" name="' . esc_attr($key) . '[filter]" value="1"><input type="hidden" name="' . esc_attr($key) . '[view]" value="' . esc_attr($view) . '"><input type="hidden" name="' . esc_attr($key) . '[month]" value="' . esc_attr($state['month']) . '">';
        $html .= '<label class="kn-field-label" for="' . esc_attr($key) . '-search">Veranstaltungen suchen</label><input id="' . esc_attr($key) . '-search" class="kn-search" type="search" name="' . esc_attr($key) . '[q]" value="' . esc_attr($search) . '" placeholder="Titel oder Beschreibung">';
        // On narrow screens the script collapses the choices into this summary; without script everything stays open.
        $html .= '<details class="kn-filter-details" open><summary class="kn-filter-summary">Themen und Einrichtungen wählen</summary><div class="kn-filter-groups">';
        $html .= $this->checkboxes($key, 'topics', 'Themen', $terms, $topics, 'term_id');
        $html .= $this->checkboxes($key, 'orgs', 'Einrichtungen', $organizations, $orgs, 'ID');
        $html .= '</div></details>';
        $html .= '<p class="kn-filter-hint">Mehrfachauswahl möglich. Ohne Auswahl erscheinen keine Veranstaltungen.</p><div class="kn-filter-actions"><button class="kn-button" type="submit">Ergebnisse anzeigen</button><a class="kn-reset" rel="nofollow" href="' . esc_url(remove_query_arg($key)) . '">Filter zurücksetzen</a></div><p class="kn-filter-feedback" role="status" aria-live="polite"></p></form></aside>';
        $html .= '<div class="kn-event-results"><div class="kn-results-toolbar"><p class="kn-result-count">' . esc_html(number_format_i18n($query->found_posts)) . ' ' . ($query->found_posts === 1 ? 'Veranstaltung' : 'Veranstaltungen') . '</p><nav class="kn-view-switch" aria-label="Ansicht der Veranstaltungen">';
        foreach (array('list' => 'Liste', 'calendar' => 'Kalender') as $mode => $label) { $next = $state; $next['view'] = $mode; $next['page'] = 1; $html .= '<a rel="nofollow" href="' . esc_url(add_query_arg($key, $next)) . '"' . ($view === $mode ? ' aria-current="true"' : '') . '>' . esc_html($label) . '</a>'; }
        $html .= '</nav></div>';
        if ($view === 'calendar') { $html .= $this->calendar($query->posts, $month_date, $key, $state); }
        elseif (!$query->posts) { $html .= $this->empty_state('Keine passenden Veranstaltungen', 'Ändern Sie Ihre Auswahl oder sehen Sie später wieder vorbei.'); }
        else {
            // Month headings structure long lists; each month is its own card group.
            $html .= '<div class="kn-event-list">'; $current = '';
            foreach ($query->posts as $post) {
                if (!self::public_post($post)) { continue; }
                $label = wp_date('F Y', (int) get_post_meta($post->ID, '_kn_start', true));
                if ($label !== $current) { $html .= ($current ? '</div>' : '') . '<h3 class="kn-month-heading">' . esc_html($label) . '</h3><div class="kn-cards kn-layout-' . esc_attr($atts['layout']) . '">'; $current = $label; }
                $html .= $this->event_card($post);
            }
            $html .= ($current ? '</div>' : '') . '</div>';
        }
        $html .= $this->pagination($query, $key, $state, $page) . '</div></div></section>';
        return $html;
    }

    private function checkboxes($key, $field, $label, $items, $selected, $id_field) {
        $html = '<fieldset class="kn-filter-group" data-kn-filter-group="' . esc_attr($field) . '"><legend>' . esc_html($label) . '</legend><div class="kn-check-actions" hidden><button type="button" data-kn-check="all">Alle</button><button type="button" data-kn-check="none">Keine</button></div><div class="kn-check-list">';
        foreach ($items as $item) {
            $id = (int) $item->$id_field; $name = $id_field === 'ID' ? $item->post_title : $item->name;
            $html .= '<label class="kn-check' . ($id_field === 'ID' ? '' : ' kn-term-' . $id) . '"><input type="checkbox" name="' . esc_attr($key . '[' . $field . '][]') . '" value="' . esc_attr($id) . '"' . checked(in_array($id, $selected, true), true, false) . '><span>' . esc_html($name) . '</span></label>';
        }
        if (!$items) { $html .= '<p class="kn-muted">Noch keine Einträge.</p>'; }
        return $html . '</div></fieldset>';
    }

    private function calendar($posts, $month, $key, $state) {
        $html = '<div class="kn-calendar"><nav class="kn-month-nav" aria-label="Kalendermonat">';
        foreach (array('-1 month' => 'Vorheriger Monat', '+1 month' => 'Nächster Monat') as $delta => $label) {
            $next = $state; $next['month'] = $month->modify($delta)->format('Y-m'); $next['page'] = 1;
            if ($delta === '+1 month') { $html .= '<h3>' . esc_html(wp_date('F Y', $month->getTimestamp())) . '</h3>'; }
            $html .= '<a class="kn-month-arrow" rel="nofollow" aria-label="' . esc_attr($label) . '" href="' . esc_url(add_query_arg($key, $next)) . '">' . ($delta === '-1 month' ? '←' : '→') . '</a>';
        }
        $html .= '</nav><p class="kn-calendar-note">Kommende und laufende Veranstaltungen. Bei mehr als 100 Treffern weitere Ergebnisseiten beachten.</p><div class="kn-calendar-grid">';
        foreach (array('Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So') as $day) { $html .= '<div class="kn-weekday" aria-hidden="true">' . esc_html($day) . '</div>'; }
        $offset = (int) $month->format('N') - 1; $days = (int) $month->format('t');
        for ($i = 0; $i < $offset; $i++) { $html .= '<div class="kn-calendar-blank" aria-hidden="true"></div>'; }
        for ($day = 1; $day <= $days; $day++) {
            $date = $month->setDate((int) $month->format('Y'), (int) $month->format('m'), $day); $start = $date->getTimestamp(); $end = $date->modify('+1 day')->getTimestamp();
            $today = $date->format('Y-m-d') === wp_date('Y-m-d');
            $html .= '<div class="kn-calendar-day' . ($today ? ' kn-is-today' : '') . '"><time class="kn-day-number" datetime="' . esc_attr($date->format('Y-m-d')) . '"' . ($today ? ' aria-current="date"' : '') . '><span class="kn-day-name">' . esc_html(wp_date('l, ', $start)) . '</span><span class="kn-day-num">' . esc_html($day) . '.</span></time>'; $entries = '';
            foreach ($posts as $post) {
                if (!self::public_post($post)) { continue; }
                $event_start = (int) get_post_meta($post->ID, '_kn_start', true); $event_end = max($event_start, (int) get_post_meta($post->ID, '_kn_end', true));
                if ($event_start >= $end || $event_end < $start) { continue; }
                $cancelled = get_post_meta($post->ID, '_kn_cancelled', true); $terms = $this->post_terms($post);
                $entries .= '<a class="kn-calendar-event' . ($terms ? ' kn-term-' . (int) $terms[0]->term_id : '') . ($cancelled ? ' kn-is-cancelled' : '') . '" href="' . esc_url(get_permalink($post)) . '"><span>' . esc_html($event_start >= $start ? wp_date('H:i', $event_start) : 'mehrtägig') . '</span> ' . ($cancelled ? '<strong>Abgesagt:</strong> ' : '') . esc_html(get_the_title($post)) . '</a>';
            }
            $html .= ($entries ? '<div class="kn-day-events">' . $entries . '</div>' : '') . '</div>';
        }
        $trailing = (7 - (($offset + $days) % 7)) % 7;
        for ($i = 0; $i < $trailing; $i++) { $html .= '<div class="kn-calendar-blank" aria-hidden="true"></div>'; }
        return $html . '</div></div>';
    }

    private function pagination($query, $key, $state, $page) {
        if ($query->max_num_pages < 2) { return ''; }
        $html = '<nav class="kn-pagination" aria-label="Ergebnisseiten"><span class="kn-page-status" aria-current="page">Seite ' . esc_html($page) . ' von ' . esc_html($query->max_num_pages) . '</span>';
        foreach (array(-1 => '← Zurück', 1 => 'Weiter →') as $delta => $label) { $number = $page + $delta; if ($number < 1 || $number > $query->max_num_pages) { continue; } $next = $state; $next['page'] = $number; $html .= '<a class="kn-button kn-button-secondary" rel="nofollow" href="' . esc_url(add_query_arg($key, $next)) . '">' . esc_html($label) . '</a>'; }
        return $html . '</nav>';
    }

    private function empty_state($title, $text) { return '<div class="kn-empty"><span class="kn-empty-mark" aria-hidden="true">✳</span><h3>' . esc_html($title) . '</h3><p>' . esc_html($text) . '</p></div>'; }

    /** Terms of a post in the administrator's order. */
    private function post_terms($post, $taxonomy = 'kn_topic') {
        $assigned = get_the_terms($post, $taxonomy); if (!$assigned || is_wp_error($assigned)) { return array(); }
        $ids = array_map('intval', wp_list_pluck($assigned, 'term_id'));
        return array_values(array_filter(KN_Categories::terms($taxonomy), function ($term) use ($ids) { return in_array((int) $term->term_id, $ids, true); }));
    }

    private function badges($post, $taxonomy = 'kn_topic', $prefix = '') {
        $terms = $this->post_terms($post, $taxonomy); if (!$terms && !$prefix) { return ''; }
        $html = '<div class="kn-badges">' . $prefix; foreach ($terms as $term) { $html .= '<span class="kn-badge kn-term-' . (int) $term->term_id . '">' . esc_html($term->name) . '</span>'; } return $html . '</div>';
    }

    private function image($post) { return has_post_thumbnail($post) ? '<a class="kn-card-image" href="' . esc_url(get_permalink($post)) . '" tabindex="-1" aria-hidden="true">' . get_the_post_thumbnail($post, 'medium_large', array('loading' => 'lazy', 'alt' => '')) . '</a>' : ''; }

    /** Institution logo (featured image) or a monogram in the colour of its first category. */
    private function logo($post) {
        if (has_post_thumbnail($post)) { return '<span class="kn-logo kn-logo-image" aria-hidden="true">' . get_the_post_thumbnail($post, 'thumbnail', array('loading' => 'lazy', 'alt' => '')) . '</span>'; }
        // Monogram from the significant (capitalised) words: „Zweig am See (Beispiel)“ → ZS, „Praxis Anthropos AG“ → PA.
        $title = trim(preg_replace(array('/\([^)]*\)/u', '/\b(AG|GmbH|e\.\s?V\.|eV)\b/u'), '', get_the_title($post)));
        $words = array_values(array_filter(preg_split('/\s+/u', $title), function ($w) { return preg_match('/^\p{Lu}/u', $w); })) ?: preg_split('/\s+/u', $title); $letters = '';
        foreach (array_slice(array_filter($words), 0, 2) as $word) { $letters .= mb_substr($word, 0, 1); }
        return '<span class="kn-logo" aria-hidden="true">' . esc_html(mb_strtoupper($letters ?: '·')) . '</span>';
    }

    private function term_attr($post, $taxonomy = 'kn_topic') { $terms = $this->post_terms($post, $taxonomy); return $terms ? ' data-term="' . (int) $terms[0]->term_id . '"' : ''; }

    private function excerpt($post) {
        $text = $post->post_excerpt ?: ($post->post_content ?: get_post_meta($post->ID, '_kn_public_info', true));
        return esc_html(wp_trim_words(wp_strip_all_tags(strip_shortcodes($text)), 28));
    }

    private function owner_line($post) {
        $id = KN_Core::owner($post->ID); return self::public_org($id) ? '<a class="kn-owner" href="' . esc_url(get_permalink($id)) . '">' . esc_html(get_the_title($id)) . '</a>' : '';
    }

    private function event_card($post) {
        $start = (int) get_post_meta($post->ID, '_kn_start', true); $cancelled = get_post_meta($post->ID, '_kn_cancelled', true);
        $html = '<article class="kn-card kn-event-card' . ($cancelled ? ' kn-is-cancelled' : '') . '"' . $this->term_attr($post) . '><time class="kn-date-stamp" datetime="' . esc_attr(wp_date('c', $start)) . '"><span class="kn-date-weekday">' . esc_html(wp_date('D', $start)) . '</span><span class="kn-date-day">' . esc_html(wp_date('j', $start)) . '</span><span class="kn-date-month">' . esc_html(wp_date('M', $start)) . '</span></time><div class="kn-card-body">' . $this->image($post) . $this->badges($post, 'kn_topic', $cancelled ? '<span class="kn-badge kn-status-cancelled">Abgesagt</span>' : '') . '<h4><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></h4>' . $this->owner_line($post);
        $html .= '<p class="kn-card-meta">' . esc_html(wp_date('H:i', $start) . ' Uhr');
        $location = KN_Core::place($post->ID)['short']; if ($location !== '') { $html .= ' · ' . esc_html($location); }
        return $html . '</p><p class="kn-excerpt">' . $this->excerpt($post) . '</p><a class="kn-text-link" href="' . esc_url(get_permalink($post)) . '">Zur Veranstaltung <span aria-hidden="true">→</span></a></div></article>';
    }

    /** The next events side by side, for example on the home page: heading, a button in the theme's own button style and one card per event. */
    public function upcoming($atts = array()) {
        $atts = shortcode_atts(array('institution' => 0, 'limit' => 3, 'heading' => 'Unsere aktuellen Veranstaltungen', 'button' => 'Alle Veranstaltungen', 'link' => ''), $atts);
        $limit = max(1, min(12, absint($atts['limit'])));
        $heading = sanitize_text_field($atts['heading']); $button = sanitize_text_field($atts['button']);
        $link = $this->target_url($atts['link']) ?: self::events_page_url();
        $organizations = $this->organizations(absint($atts['institution'])); $posts = array();
        if ($organizations) {
            // Running and future events in chronological order; cancelled ones are left out here, the full list still shows them.
            $query = new WP_Query(array('post_type' => 'kn_event', 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => $limit, 'no_found_rows' => true, 'meta_key' => '_kn_start', 'orderby' => 'meta_value_num', 'order' => 'ASC', 'meta_query' => array('relation' => 'AND', $this->organization_query($organizations), array('key' => '_kn_end', 'value' => time(), 'compare' => '>=', 'type' => 'NUMERIC'), array('relation' => 'OR', array('key' => '_kn_cancelled', 'compare' => 'NOT EXISTS'), array('key' => '_kn_cancelled', 'value' => '1', 'compare' => '!=')))));
            $posts = array_values(array_filter($query->posts, array(__CLASS__, 'public_post')));
        }
        // The heading and the button sit outside the plugin's scoped styles, so the theme styles them like its own.
        $html = '<div class="kn-upcoming">';
        if ($heading !== '' || ($button !== '' && $link)) {
            $html .= '<div class="kn-upcoming-head">' . ($heading !== '' ? '<h2 class="kn-upcoming-title">' . esc_html($heading) . '</h2>' : '<span></span>');
            if ($button !== '' && $link) { $html .= '<div class="wp-block-button kn-upcoming-more"><a class="wp-block-button__link wp-element-button" href="' . esc_url($link) . '">' . esc_html($button) . '</a></div>'; }
            $html .= '</div>';
        }
        $html .= self::portal('kn-upcoming-cards');
        if (!$posts) { $html .= '<p class="kn-upcoming-empty">Zurzeit sind keine Veranstaltungen geplant.</p>'; }
        else {
            // Columns follow the chosen number, not the events found: a single event keeps the width of one card in three.
            $html .= '<div class="kn-upcoming-grid" style="--kn-upcoming-cols:' . (int) min(3, $limit) . '">';
            foreach ($posts as $post) { $html .= $this->upcoming_card($post); }
            $html .= '</div>';
        }
        return $html . '</section></div>';
    }

    private function upcoming_card($post) {
        $start = (int) get_post_meta($post->ID, '_kn_start', true); $url = get_permalink($post); $image = has_post_thumbnail($post);
        // Without a picture the area shows the date in the colour of the first topic.
        $media = $image ? get_the_post_thumbnail($post, 'medium_large', array('loading' => 'lazy', 'alt' => '')) : '<span class="kn-upcoming-stamp"><span class="kn-date-day">' . esc_html(wp_date('j', $start)) . '</span><span class="kn-date-month">' . esc_html(wp_date('M', $start)) . '</span></span>';
        $meta = array(); $owner = KN_Core::owner($post->ID);
        if (self::public_org($owner)) { $meta[] = get_the_title($owner); }
        $location = KN_Core::place($post->ID)['short']; if ($location !== '') { $meta[] = $location; }
        $html = '<article class="kn-card kn-upcoming-card"' . $this->term_attr($post) . '><a class="kn-upcoming-media' . ($image ? '' : ' kn-upcoming-placeholder') . '" href="' . esc_url($url) . '" tabindex="-1" aria-hidden="true">' . $media . '</a>';
        $html .= '<div class="kn-card-body"><p class="kn-upcoming-date"><time datetime="' . esc_attr(wp_date('c', $start)) . '"><strong>' . esc_html(rtrim(wp_date('D', $start), '.') . '., ' . wp_date('j. F', $start)) . '</strong> · ' . esc_html(wp_date('H:i', $start)) . ' Uhr</time></p>' . $this->badges($post);
        $html .= '<h3><a href="' . esc_url($url) . '">' . esc_html(get_the_title($post)) . '</a></h3>' . ($meta ? '<p class="kn-card-meta">' . esc_html(implode(' · ', $meta)) . '</p>' : '');
        return $html . '<a class="kn-text-link" href="' . esc_url($url) . '">Zur Veranstaltung <span aria-hidden="true">→</span></a></div></article>';
    }

    /** A link given in the block or shortcode: a full http(s) address or a path on this website such as /veranstaltungen/. */
    private function target_url($value) {
        $value = trim((string) $value);
        if ($value === '') { return ''; }
        if ($value[0] === '/' && strpos($value, '//') !== 0) { return esc_url_raw(home_url($value)); }
        return $this->http_url($value);
    }

    /** The published page that shows the event list (shortcode or block), for buttons that lead to all events. */
    public static function events_page_url() {
        global $wpdb;
        // Cards, the upcoming block and every event page ask for it; one query per request is enough.
        static $cached = null;
        if (null !== $cached) { return $cached; }
        // Block comments always have a space after the name, with or without settings.
        $needles = array('[konstanth_events', '[konstanth_termine', '[konstanth_veranstaltungen', '<!-- wp:konstanth/events ');
        $where = implode(' OR ', array_fill(0, count($needles), 'post_content LIKE %s'));
        $params = array_map(function ($needle) use ($wpdb) { return '%' . $wpdb->esc_like($needle) . '%'; }, $needles);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where only repeats a fixed placeholder; all values are prepared.
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_password = '' AND ($where) ORDER BY menu_order ASC, ID ASC LIMIT 1", $params));
        return $cached = $id ? (string) get_permalink($id) : '';
    }

    public function jobs($atts = array()) { return $this->listing('kn_job', $atts); }
    public function news($atts = array()) { return $this->listing('kn_news', $atts); }
    public function institutions($atts = array()) { return $this->listing('kn_institution', $atts); }
    public function donations($atts = array()) { return $this->listing('kn_donation', $atts); }

    private function listing($type, $atts) {
        $atts = $this->attributes($atts); $key = $this->key(); $input = $this->input($key); $page = isset($input['page']) ? max(1, absint($input['page'])) : 1;
        $donation = $type === 'kn_donation'; $institution = $type === 'kn_institution' || $donation;
        $args = array('post_type' => $institution ? 'kn_institution' : $type, 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => $atts['limit'], 'paged' => $page, 'orderby' => $institution ? 'title' : 'date', 'order' => $institution ? 'ASC' : 'DESC');
        if ($institution) { if ($atts['institution']) { $args['post__in'] = array($atts['institution']); } }
        else { $organizations = $this->organizations($atts['institution']); if (!$organizations) { $args['post__in'] = array(0); } else { $args['meta_query'] = array($this->organization_query($organizations)); } }
        if ($donation) { $args['meta_query'] = array('relation' => 'OR', array('key' => '_kn_donation', 'value' => '', 'compare' => '!='), array('key' => '_kn_donation_url', 'value' => '', 'compare' => '!=')); }
        if ($type === 'kn_job') { $args['meta_query'][] = array('relation' => 'OR', array('key' => '_kn_deadline', 'compare' => 'NOT EXISTS'), array('key' => '_kn_deadline', 'value' => '', 'compare' => '='), array('key' => '_kn_deadline', 'value' => wp_date('Y-m-d'), 'compare' => '>=', 'type' => 'DATE')); }
        $query = new WP_Query($args); $html = $this->open($atts, 'kn-listing'); $cards = '';
        foreach ($query->posts as $post) { if (self::public_post($post)) { $cards .= $institution ? $this->institution_card($post, $donation) : $this->content_card($post, $type); } }
        if (!$cards) {
            $labels = array('kn_job' => 'Noch keine offenen Stellen', 'kn_news' => 'Noch keine Nachrichten', 'kn_institution' => 'Noch keine Einrichtungen', 'kn_donation' => 'Noch keine Fördermöglichkeiten');
            $html .= $this->empty_state($labels[$type], 'Sobald Inhalte veröffentlicht sind, finden Sie sie an dieser Stelle.');
        } else { $html .= '<div class="kn-cards kn-layout-' . esc_attr($atts['layout']) . '">' . $cards . '</div>'; }
        return $html . $this->pagination($query, $key, array(), $page) . '</section>';
    }

    private function content_card($post, $type) {
        $html = '<article class="kn-card"><div class="kn-card-body">' . $this->image($post) . $this->owner_line($post) . '<h3><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></h3>';
        if ($type === 'kn_job') { $deadline = self::date_label(get_post_meta($post->ID, '_kn_deadline', true)); $meta = array_filter(array(get_post_meta($post->ID, '_kn_location', true), get_post_meta($post->ID, '_kn_workload', true), $deadline ? 'Bewerbung bis ' . $deadline : '')); $html .= '<p class="kn-card-meta">' . esc_html(implode(' · ', $meta)) . '</p>'; }
        else { $html .= '<p class="kn-card-meta">' . esc_html(get_the_date('d. F Y', $post)) . '</p>'; }
        return $html . '<p class="kn-excerpt">' . $this->excerpt($post) . '</p><a class="kn-text-link" href="' . esc_url(get_permalink($post)) . '">' . ($type === 'kn_job' ? 'Zur Stelle' : 'Weiterlesen') . ' <span aria-hidden="true">→</span></a></div></article>';
    }

    private function institution_card($post, $donation = false) {
        $html = '<article class="kn-card kn-institution-card"' . $this->term_attr($post, 'kn_institution_type') . '><div class="kn-card-body">' . $this->logo($post) . $this->badges($post, 'kn_institution_type') . '<h3><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></h3><p class="kn-card-meta">' . esc_html(trim(get_post_meta($post->ID, '_kn_zip', true) . ' ' . get_post_meta($post->ID, '_kn_city', true))) . '</p>';
        if ($donation) { $html .= '<div class="kn-rich-text">' . $this->safe_html(get_post_meta($post->ID, '_kn_donation', true)) . '</div>'; $url = $this->http_url(get_post_meta($post->ID, '_kn_donation_url', true)); if ($url) { $html .= '<a class="kn-button kn-button-secondary" href="' . esc_url($url) . '" rel="external noopener">Einrichtung unterstützen ↗</a>'; } }
        else { $html .= '<p class="kn-excerpt">' . $this->excerpt($post) . '</p><a class="kn-text-link" href="' . esc_url(get_permalink($post)) . '">Einrichtung kennenlernen <span aria-hidden="true">→</span></a>'; }
        return $html . '</div></article>';
    }

    private function http_url($value) { $url = esc_url_raw(trim((string) $value), array('http', 'https')); return $url && in_array(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)), array('http', 'https'), true) ? $url : ''; }
    private function safe_html($value) { return wp_kses_post(wpautop(strip_shortcodes((string) $value))); }

    public function content($content) {
        if (!is_singular(array('kn_event', 'kn_job', 'kn_news', 'kn_institution')) || !in_the_loop() || !is_main_query()) { return $content; }
        $post = get_post(); $public = self::public_post($post);
        // Editors preview their own drafts; everyone else sees nothing until it is public.
        if (!$public && !($post && KN_Core::can_manage($post->ID))) { $this->detail = ''; return "\n" . self::PLACEHOLDER . "\n"; }
        if ($post->post_type === 'kn_event') {
            // Encode brackets so no later content filter can execute author text as a shortcode.
            $this->detail = str_replace(array('[', ']'), array('&#91;', '&#93;'), $this->event_page($post, $public));
            return "\n" . self::PLACEHOLDER . "\n";
        }
        $html = $this->open(array(), 'kn-detail');
        if (!$public) { $html .= '<p class="kn-notice" role="status">Vorschau – dieser Inhalt ist noch nicht öffentlich sichtbar.</p>'; }
        $taxonomy = $post->post_type === 'kn_institution' ? 'kn_institution_type' : 'kn_topic';
        $html .= '<article class="kn-detail"><div class="kn-detail-header"' . $this->term_attr($post, $taxonomy) . '>' . $this->badges($post, $taxonomy);
        if ($post->post_type !== 'kn_institution') { $html .= $this->owner_line($post); }
        $html .= '</div>';
        $this->own_image = true;
        if ($post->post_type === 'kn_institution') { $html = str_replace('<div class="kn-detail-header"', '<div class="kn-detail-header kn-has-logo"', $html); $html = preg_replace('/(<div class="kn-detail-header[^>]*>)/', '$1' . str_replace('$', '\\$', $this->logo($post)), $html, 1); }
        elseif (has_post_thumbnail($post) && $post->post_type !== 'kn_event') { $html .= '<figure class="kn-detail-image">' . get_the_post_thumbnail($post, 'large', array('loading' => 'eager')) . '</figure>'; }
        $this->own_image = false;
        if ($post->post_type === 'kn_job') { $html .= '<dl class="kn-facts">' . $this->fact('Arbeitsort', get_post_meta($post->ID, '_kn_location', true)) . $this->fact('Umfang', get_post_meta($post->ID, '_kn_workload', true)) . $this->fact('Bewerbungsfrist', self::date_label(get_post_meta($post->ID, '_kn_deadline', true))) . '</dl>'; }
        $html .= '<div class="kn-rich-text">' . $this->safe_html($post->post_content);
        $public = get_post_meta($post->ID, '_kn_public_info', true); if ($public && trim($public) !== trim($post->post_content)) { $html .= $this->safe_html($public); }
        $html .= '</div>';
        if ($post->post_type === 'kn_job') {
            $application = get_post_meta($post->ID, '_kn_application', true); $url = $this->http_url($application);
            if ($application) { $html .= '<div class="kn-detail-box"><h2>Bewerbung</h2>' . ($url ? '<a class="kn-button" href="' . esc_url($url) . '" rel="external noopener">Zur Bewerbung ↗</a>' : $this->safe_html($application)) . '</div>'; }
        }
        $html .= $this->contact($post);
        if ($post->post_type === 'kn_institution') {
            $donation = get_post_meta($post->ID, '_kn_donation', true); $donation_url = $this->http_url(get_post_meta($post->ID, '_kn_donation_url', true));
            if ($donation || $donation_url) { $html .= '<div class="kn-detail-box"><h2>Diese Einrichtung unterstützen</h2><div class="kn-rich-text">' . $this->safe_html($donation) . '</div>' . ($donation_url ? '<a class="kn-button kn-button-secondary" rel="external noopener" href="' . esc_url($donation_url) . '">Mehr zum Fördern & Spenden ↗</a>' : '') . '</div>'; }
            if (shortcode_exists('konstanth_newsletter')) { $html .= '<div class="kn-detail-box"><h2>In Verbindung bleiben</h2>' . do_shortcode('[konstanth_newsletter institution="' . absint($post->ID) . '"]') . '</div>'; }
        }
        // Encode brackets so no later content filter can execute author text as a shortcode.
        $this->detail = str_replace(array('[', ']'), array('&#91;', '&#93;'), $html . '</article></section>');
        return "\n" . self::PLACEHOLDER . "\n";
    }

    /** Event page: banner with a card for date and title, picture and flyer on the left, text and facts on the right, a link to all events at the end. */
    private function event_page($post, $public) {
        $start = (int) get_post_meta($post->ID, '_kn_start', true); $end = (int) get_post_meta($post->ID, '_kn_end', true);
        $cancelled = get_post_meta($post->ID, '_kn_cancelled', true); $past = max($start, $end) && max($start, $end) < time();
        $same_day = $end > $start && wp_date('Ymd', $end) === wp_date('Ymd', $start);
        $when = $end > $start && !$same_day ? wp_date('l, j. F Y · H:i', $start) . ' Uhr – ' . wp_date('l, j. F Y · H:i', $end) . ' Uhr' : wp_date('l, j. F Y', $start) . ' | ' . wp_date('H:i', $start) . ($same_day ? '–' . wp_date('H:i', $end) : '') . ' Uhr';
        $banner = KN_Core::banner($post->ID);
        $html = self::portal('kn-detail kn-event-page');
        if (!$public) { $html .= '<p class="kn-notice" role="status">Vorschau – dieser Inhalt ist noch nicht öffentlich sichtbar.</p>'; }
        $html .= '<article class="kn-event"' . $this->term_attr($post) . '><header class="kn-event-hero' . ($banner ? ' kn-has-banner' : '') . '">';
        if ($banner) { $this->own_image = true; $html .= '<div class="kn-event-banner">' . wp_get_attachment_image($banner, 'full', false, array('class' => 'kn-event-banner-image', 'alt' => '', 'loading' => 'eager', 'sizes' => '100vw')) . '</div>'; $this->own_image = false; }
        $html .= '<div class="kn-event-panel"><p class="kn-event-when"><time datetime="' . esc_attr(wp_date('c', $start)) . '">' . esc_html($when) . '</time></p><h1 class="kn-event-title">' . esc_html(get_the_title($post)) . '</h1>' . $this->badges($post) . '</div></header>';
        $image = has_post_thumbnail($post); $flyer = $cancelled ? null : KN_Core::flyer($post->ID);
        $html .= '<div class="kn-event-body' . ($image || $flyer ? '' : ' kn-event-single') . '">';
        if ($image || $flyer) {
            $html .= '<div class="kn-event-aside">';
            if ($image) { $this->own_image = true; $html .= '<figure class="kn-event-image">' . get_the_post_thumbnail($post, 'large', array('loading' => 'eager')) . '</figure>'; $this->own_image = false; }
            // The flyer is shown on the page and offered below as the institution uploaded it.
            if ($flyer) { $html .= $this->flyer_view($flyer, $post) . '<a class="kn-event-flyer kn-flyer" href="' . esc_url($flyer['url']) . '" download>Flyer herunterladen <span class="kn-flyer-meta">(' . esc_html($flyer['type'] . ($flyer['size'] ? ', ' . size_format($flyer['size'], 1) : '')) . ')</span></a>'; }
            $html .= '</div>';
        }
        $html .= '<div class="kn-event-main">';
        if ($cancelled) { $html .= '<p class="kn-notice" role="status"><strong>Diese Veranstaltung wurde abgesagt.</strong></p>'; }
        elseif ($past) { $html .= '<p class="kn-notice" role="status">Diese Veranstaltung hat bereits stattgefunden.</p>'; }
        $html .= '<div class="kn-rich-text">' . $this->safe_html($post->post_content);
        $info = get_post_meta($post->ID, '_kn_public_info', true); if ($info && trim($info) !== trim($post->post_content)) { $html .= $this->safe_html($info); }
        $html .= '</div>';
        $owner = KN_Core::owner($post->ID);
        $place = KN_Core::place($post->ID);
        $facts = $this->fact('Ort', implode("\n", array_filter(array_merge(array($place['name']), $place['lines']), 'strlen'))) . $this->fact('Eintritt', KN_Core::price($post->ID)) . (self::public_org($owner) ? '<div><dt>Veranstalter</dt><dd><a href="' . esc_url(get_permalink($owner)) . '">' . esc_html(get_the_title($owner)) . '</a></dd></div>' : '');
        if ($facts !== '') { $html .= '<dl class="kn-facts">' . $facts . '</dl>'; }
        $registration = $this->http_url(get_post_meta($post->ID, '_kn_registration_url', true));
        $html .= '<div class="kn-detail-actions">' . ($registration && !$cancelled && !$past ? '<a class="kn-button" href="' . esc_url($registration) . '" rel="external noopener">Zur Anmeldung <span aria-hidden="true">↗</span></a>' : '');
        // The calendar file opens the visitor's own calendar app (on phones directly, on computers with one click).
        $html .= ($cancelled || $past ? '' : '<a class="kn-button kn-button-secondary" rel="nofollow" href="' . esc_url(add_query_arg(array('action' => 'kn_ics', 'event' => $post->ID), admin_url('admin-post.php'))) . '">In den Kalender <span aria-hidden="true">↓</span></a>') . '</div>';
        $html .= $this->contact($post) . '</div></div>';
        $all = self::events_page_url();
        if ($all) { $html .= '<footer class="kn-event-footer"><a class="kn-event-all" href="' . esc_url($all) . '">Alle Veranstaltungen</a></footer>'; }
        return $html . '</article></section>';
    }

    /** Block themes print the title with a block; on the event page the banner card carries it. */
    public function theme_title($html, $block = array(), $instance = null) {
        if (!is_singular('kn_event')) { return $html; }
        $id = is_object($instance) && isset($instance->context['postId']) ? (int) $instance->context['postId'] : (int) get_the_ID();
        return $id === (int) get_queried_object_id() ? '' : $html;
    }

    public function single_image($html, $post_id) {
        if ($this->own_image || !is_singular(array('kn_event', 'kn_job', 'kn_news', 'kn_institution')) || (int) $post_id !== (int) get_queried_object_id()) { return $html; }
        return '';
    }

    public function restore_detail($content) {
        if ($this->detail === null) { return $content; }
        $html = $this->detail; $this->detail = null;
        return preg_replace_callback('#(?:<p>\s*)?' . preg_quote(self::PLACEHOLDER, '#') . '(?:\s*</p>)?#', function () use ($html) { return $html; }, $content, 1);
    }

    public function robots($robots) {
        // Filter and calendar combinations are endless; only the unfiltered page belongs in search results.
        foreach (array_keys($_GET) as $key) { if (preg_match('/^kn_[1-9][0-9]*$/D', (string) $key)) { $robots['noindex'] = true; $robots['follow'] = true; break; } }
        if (is_singular('kn_event') && ($post = get_post())) {
            $end = max((int) get_post_meta($post->ID, '_kn_start', true), (int) get_post_meta($post->ID, '_kn_end', true));
            if ($end && $end < time()) { $robots['noindex'] = true; $robots['follow'] = true; }
        }
        return $robots;
    }

    /** schema.org Event data so search engines can show date, place and status. */
    public static function event_schema($post) {
        $post = get_post($post);
        if (!$post || $post->post_type !== 'kn_event' || !self::public_post($post)) { return array(); }
        $start = (int) get_post_meta($post->ID, '_kn_start', true); $end = max($start, (int) get_post_meta($post->ID, '_kn_end', true)); $org = KN_Core::owner($post->ID);
        $place = KN_Core::place($post->ID);
        $schema = array('@context' => 'https://schema.org', '@type' => 'Event', 'name' => wp_strip_all_tags(get_the_title($post)), 'startDate' => wp_date('c', $start), 'endDate' => wp_date('c', $end), 'eventStatus' => get_post_meta($post->ID, '_kn_cancelled', true) ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled', 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode', 'url' => get_permalink($post), 'description' => wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_excerpt ?: $post->post_content)), 40));
        // A structured address where the event has one; older events only name the place, then the institution's address stands in.
        $postal = array_filter(array('@type' => 'PostalAddress', 'streetAddress' => get_post_meta($post->ID, '_kn_address', true), 'postalCode' => get_post_meta($post->ID, '_kn_zip', true), 'addressLocality' => get_post_meta($post->ID, '_kn_city', true), 'addressRegion' => get_post_meta($post->ID, '_kn_region', true), 'addressCountry' => get_post_meta($post->ID, '_kn_country', true)), 'strlen');
        $schema['location'] = array('@type' => 'Place', 'name' => $place['name'] ?: get_the_title($org), 'address' => count($postal) > 1 ? $postal : ($place['name'] ?: implode(', ', KN_Core::address_lines($org))));
        if (KN_Core::price($post->ID) === 'kostenlos') { $schema['isAccessibleForFree'] = true; }
        if ($org) { $schema['organizer'] = array('@type' => 'Organization', 'name' => get_the_title($org), 'url' => get_permalink($org)); }
        if (has_post_thumbnail($post)) { $schema['image'] = array(get_the_post_thumbnail_url($post, 'large')); }
        return $schema;
    }

    public function print_schema() {
        if (!is_singular('kn_event')) { return; }
        $schema = self::event_schema(get_post());
        if ($schema) { echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>' . "\n"; }
    }

    private static function date_label($ymd) {
        if (!is_string($ymd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $ymd)) { return (string) $ymd; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $ymd . ' 12:00', wp_timezone());
        return $date ? wp_date('j. F Y', $date->getTimestamp()) : $ymd;
    }

    private function fact($label, $value) { return $value !== '' ? '<div><dt>' . esc_html($label) . '</dt><dd>' . nl2br(esc_html($value)) . '</dd></div>' : ''; }

    /** The flyer itself: a PDF in the browser's own viewer, a picture as picture. Other types keep only the download. */
    private function flyer_view($flyer, $post) {
        $label = 'Flyer: ' . get_the_title($post);
        if ('PDF' === $flyer['type']) {
            // Browsers without a PDF viewer (some phones) show nothing here; the download below always works.
            return '<object class="kn-event-flyer-view kn-event-flyer-pdf" data="' . esc_url($flyer['url'] . '#toolbar=0&navpanes=0&view=FitH') . '" type="application/pdf" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"></object>';
        }
        if (!in_array($flyer['type'], array('JPG', 'PNG', 'WEBP', 'GIF'), true)) { return ''; }
        $image = wp_get_attachment_image((int) $flyer['id'], 'large', false, array('class' => 'kn-event-flyer-image', 'alt' => $label, 'loading' => 'lazy'));
        return $image ? '<figure class="kn-event-flyer-view">' . $image . '</figure>' : '';
    }

    private function contact($post) {
        $id = $post->ID; $email = get_post_meta($id, '_kn_email', true); $phone = get_post_meta($id, '_kn_phone', true); $address = $post->post_type === 'kn_institution' ? implode("\n", KN_Core::address_lines($id)) : ''; $website = $this->http_url(get_post_meta($id, '_kn_website', true));
        if (!$email && !$phone && !$address && !$website) { return ''; }
        $html = '<div class="kn-detail-box"><h2>Kontakt</h2><dl class="kn-facts">' . $this->fact('Adresse', $address);
        if ($email && is_email($email)) { $html .= '<div><dt>E-Mail</dt><dd><a href="' . esc_attr('mailto:' . sanitize_email($email)) . '">' . esc_html($email) . '</a></dd></div>'; }
        if ($phone) { $html .= '<div><dt>Telefon</dt><dd><a href="' . esc_attr('tel:' . preg_replace('/[^0-9+]/', '', $phone)) . '">' . esc_html($phone) . '</a></dd></div>'; }
        if ($website) { $html .= '<div><dt>Website</dt><dd><a href="' . esc_url($website) . '" rel="external noopener">Website besuchen ↗</a></dd></div>'; }
        return $html . '</dl></div>';
    }

    public static function ics_content($post) {
        $post = get_post($post);
        if (!$post || $post->post_type !== 'kn_event' || !self::public_post($post)) { return ''; }
        $start = (int) get_post_meta($post->ID, '_kn_start', true); if ($start <= 0) { return ''; }
        $end = (int) get_post_meta($post->ID, '_kn_end', true); if ($end <= $start) { $end = $start + 3600; }
        $escape = function ($value) { $text = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'); return str_replace(array('\\', "\r\n", "\r", "\n", ';', ','), array('\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'), $text); };
        $description = get_post_meta($post->ID, '_kn_public_info', true) ?: $post->post_content;
        $price = KN_Core::price($post->ID); if ($price !== '') { $description = wp_strip_all_tags($description) . "\n\nEintritt: " . $price; }
        $lines = array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//konstanth//Veranstaltungen//DE', 'CALSCALE:GREGORIAN', 'BEGIN:VEVENT', 'UID:kn-event-' . $post->ID . '-' . md5(home_url('/')) . '@konstanth', 'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART:' . gmdate('Ymd\THis\Z', $start), 'DTEND:' . gmdate('Ymd\THis\Z', $end), 'STATUS:' . (get_post_meta($post->ID, '_kn_cancelled', true) ? 'CANCELLED' : 'CONFIRMED'), 'SUMMARY:' . $escape(get_the_title($post)), 'DESCRIPTION:' . $escape($description), 'LOCATION:' . $escape(KN_Core::place($post->ID)['text']), 'URL:' . get_permalink($post), 'END:VEVENT', 'END:VCALENDAR');
        $folded = array();
        foreach ($lines as $line) {
            while (strlen($line) > 75) { $bytes = 75; while ($bytes > 0 && (ord($line[$bytes]) & 0xC0) === 0x80) { $bytes--; } $folded[] = substr($line, 0, $bytes); $line = ' ' . substr($line, $bytes); }
            $folded[] = $line;
        }
        return implode("\r\n", $folded) . "\r\n";
    }

    public function download_ics() {
        $id = isset($_GET['event']) && is_scalar($_GET['event']) ? absint($_GET['event']) : 0; $ics = self::ics_content($id);
        if (!$ics) { wp_die('Diese Veranstaltung ist nicht öffentlich verfügbar.', 'Veranstaltung nicht verfügbar', array('response' => 404)); }
        nocache_headers(); header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="konstanth-veranstaltung-' . $id . '.ics"'); header('X-Content-Type-Options: nosniff'); echo $ics; exit;
    }
}
