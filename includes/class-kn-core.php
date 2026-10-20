<?php
defined( 'ABSPATH' ) || exit;

final class KN_Core {
    private static $trusted = false;
    private static $term_guard = false;
    private static $saving = false;
    const TOPICS = array( 'Vortrag', 'Gesundheit', 'Bildung', 'Kunst und Kultur', 'Anthroposophie', 'Religion', 'Esoterik und Spiritualität' );
    const ORG_TYPES = array( 'Schule', 'Gesundheit', 'Kunst und Kultur', 'Religion', 'Esoterik und Spiritualität' );
    const FIELDS = array( 'location', 'public_info', 'internal_info', 'workload', 'deadline', 'application', 'email', 'phone', 'address', 'city', 'website', 'donation', 'donation_url', 'mail_mode', 'source', 'registration_url', 'cancelled' );

    public function __construct() {
        add_action( 'init', array( __CLASS__, 'register' ) );
        add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 20 );
        add_filter( 'wp_sitemaps_users_query_args', function ( $args ) { $args['role__not_in'] = array_merge( (array) ( $args['role__not_in'] ?? array() ), array( 'kn_institution' ) ); return $args; } );
        add_filter( 'the_author', array( __CLASS__, 'author_name' ) );
        add_filter( 'author_link', array( __CLASS__, 'author_link' ), 10, 2 );
        // Block themes render the author with blocks that bypass the_author.
        add_filter( 'render_block_core/post-author-name', array( __CLASS__, 'author_block' ), 10, 3 );
        add_filter( 'render_block_core/post-author', array( __CLASS__, 'author_block' ), 10, 3 );
        add_action( 'template_redirect', array( __CLASS__, 'hide_institution_authors' ) );
        // WordPress would redirect /?author=ID to /author/<login>/ before the 404 above; that redirect is dropped for institution accounts.
        add_filter( 'redirect_canonical', function ( $redirect ) { $user = is_author() ? get_queried_object() : null; return $user instanceof WP_User && in_array( 'kn_institution', (array) $user->roles, true ) ? false : $redirect; } );
        // Scheduled events are published by WP-Cron without the editor form; invalid ones fall back to draft.
        add_action( 'transition_post_status', array( __CLASS__, 'check_scheduled' ), 5, 3 );
        add_filter( 'display_post_states', array( __CLASS__, 'schedule_state' ), 10, 2 );
        add_filter( 'map_meta_cap', array( __CLASS__, 'map_caps' ), 20, 4 );
        add_filter( 'user_has_cap', array( __CLASS__, 'user_caps' ), 20, 4 );
        add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'guard_insert' ), 10, 2 );
        add_filter( 'wp_insert_post_data', array( __CLASS__, 'prepare_post' ), 20, 4 );
        add_action( 'wp_after_insert_post', array( __CLASS__, 'save_post' ), 20, 4 );
        foreach ( array( 'add', 'update', 'delete' ) as $operation ) {
            add_filter( $operation . '_post_metadata', array( __CLASS__, 'guard_meta' ), 10, 5 );
            add_filter( $operation . '_user_metadata', array( __CLASS__, 'guard_user_meta' ), 10, 5 );
        }
        foreach ( array( 'post', 'user' ) as $meta_type ) {
            add_filter( 'update_' . $meta_type . '_metadata_by_mid', function ( $check, $mid, $value, $new_key ) use ( $meta_type ) { return self::guard_mid( $check, $meta_type, $mid, $value, $new_key, false ); }, 10, 4 );
            add_filter( 'delete_' . $meta_type . '_metadata_by_mid', function ( $check, $mid ) use ( $meta_type ) { return self::guard_mid( $check, $meta_type, $mid, null, false, true ); }, 10, 2 );
        }
        add_action( 'set_object_terms', array( __CLASS__, 'guard_topics' ), 20, 6 );
        add_action( 'pre_get_posts', array( __CLASS__, 'scope_query' ) );
        add_filter( 'posts_where', array( __CLASS__, 'scope_read_where' ), 20, 2 );
        add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'scope_media' ) );
        add_filter( 'upload_mimes', array( __CLASS__, 'upload_types' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menus' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
        add_action( 'show_user_profile', array( __CLASS__, 'user_field' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'user_field' ) );
        add_action( 'user_new_form', array( __CLASS__, 'new_user_field' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_user' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user' ) );
        add_action( 'user_register', array( __CLASS__, 'save_user' ) );
        add_action( 'admin_post_kn_import', array( __CLASS__, 'import' ) );
        add_action( 'admin_post_kn_duplicate', array( __CLASS__, 'duplicate_request' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( KN_FILE ), function ( $links ) { array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=konstanth' ) ) . '">Anleitung & Shortcodes</a>' ); return $links; } );
        add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
        foreach ( self::types() as $type ) {
            add_filter( 'manage_' . $type . '_posts_columns', array( __CLASS__, 'columns' ) );
            add_action( 'manage_' . $type . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
            add_filter( 'use_block_editor_for_post_type', function ( $use, $post_type ) { return in_array( $post_type, self::types(), true ) ? false : $use; }, 10, 2 );
        }
    }

    public static function types() { return array( 'kn_event', 'kn_job', 'kn_institution', 'kn_news' ); }
    public static function is_admin( $user_id = 0 ) { return user_can( $user_id ?: get_current_user_id(), 'manage_options' ); }
    public static function org_id( $user_id = 0 ) {
        $id = (int) get_user_meta( $user_id ?: get_current_user_id(), '_kn_institution_id', true );
        $p = $id ? get_post( $id ) : null;
        return $p && 'kn_institution' === $p->post_type && ! in_array( $p->post_status, array( 'trash', 'auto-draft' ), true ) ? $id : 0;
    }
    public static function owner( $post_id ) {
        return 'kn_institution' === get_post_type( $post_id ) ? (int) $post_id : (int) get_post_meta( $post_id, '_kn_org', true );
    }
    public static function can_manage( $post_id, $user_id = 0 ) {
        $user_id = $user_id ?: get_current_user_id();
        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, self::types(), true ) || ! $user_id ) return false;
        if ( self::is_admin( $user_id ) ) return true;
        if ( ! user_can( $user_id, 'kn_access' ) ) return false;
        $org = self::org_id( $user_id );
        if ( ! $org ) return false;
        if ( 'auto-draft' === $post->post_status && ! self::owner( $post_id ) && (int) $post->post_author === $user_id && 'kn_institution' !== $post->post_type ) return true;
        return self::owner( $post_id ) === $org;
    }
    public static function system_write( $callback ) {
        $previous = self::$trusted; self::$trusted = true;
        try { return $callback(); } finally { self::$trusted = $previous; }
    }
    public static function parse_datetime( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value ) ) return 0;
        $d = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
        return $d && $d->format( 'Y-m-d\TH:i' ) === $value ? $d->getTimestamp() : 0;
    }
    public static function validate_event( $data ) {
        if ( empty( $data['start'] ) || empty( $data['end'] ) || (int) $data['end'] <= (int) $data['start'] ) return new WP_Error( 'kn_dates', 'Bitte einen gültigen Beginn und ein späteres Ende eingeben.' );
        $categories = isset( $data['categories'] ) && is_array( $data['categories'] ) ? $data['categories'] : array();
        if ( count( $categories ) < 1 || count( $categories ) > 3 || count( array_unique( $categories ) ) !== count( $categories ) ) return new WP_Error( 'kn_categories', 'Bitte eine bis maximal drei unterschiedliche Themenkategorien wählen.' );
        return true;
    }
    public static function register() {
        $thumbnail_support = get_theme_support( 'post-thumbnails' );
        if ( ! $thumbnail_support ) add_theme_support( 'post-thumbnails', self::types() );
        elseif ( is_array( $thumbnail_support ) && isset( $thumbnail_support[0] ) && is_array( $thumbnail_support[0] ) ) add_theme_support( 'post-thumbnails', array_unique( array_merge( $thumbnail_support[0], self::types() ) ) );
        $labels = array( 'kn_event' => array( 'Veranstaltungen', 'Veranstaltung' ), 'kn_job' => array( 'Stellen', 'Stelle' ), 'kn_institution' => array( 'Institutionen', 'Institution' ), 'kn_news' => array( 'Nachrichten', 'Nachricht' ) );
        foreach ( $labels as $type => $label ) {
            $caps = array( 'edit_post' => 'edit_kn_item', 'read_post' => 'read_kn_item', 'delete_post' => 'delete_kn_item', 'edit_posts' => 'edit_kn_items', 'edit_others_posts' => 'edit_others_kn_items', 'publish_posts' => 'publish_kn_items', 'read_private_posts' => 'read_private_kn_items', 'delete_posts' => 'delete_kn_items', 'delete_private_posts' => 'delete_private_kn_items', 'delete_published_posts' => 'delete_published_kn_items', 'delete_others_posts' => 'delete_others_kn_items', 'edit_private_posts' => 'edit_private_kn_items', 'edit_published_posts' => 'edit_published_kn_items', 'create_posts' => 'kn_institution' === $type ? 'kn_manage_institutions' : 'create_kn_items' );
            register_post_type( $type, array(
                'labels' => array( 'name' => $label[0], 'singular_name' => $label[1], 'add_new' => 'Neu hinzufügen', 'add_new_item' => $label[1] . ' erstellen', 'edit_item' => $label[1] . ' bearbeiten', 'search_items' => $label[0] . ' durchsuchen', 'not_found' => 'Keine Einträge gefunden.' ),
                'public' => true, 'show_ui' => true, 'show_in_menu' => 'konstanth', 'show_in_rest' => false, 'exclude_from_search' => false,
                'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ), 'capability_type' => array( 'kn_item', 'kn_items' ), 'capabilities' => $caps, 'map_meta_cap' => true,
                'has_archive' => false, 'rewrite' => array( 'slug' => array( 'kn_event' => 'termin', 'kn_job' => 'stelle', 'kn_institution' => 'institution', 'kn_news' => 'nachricht' )[ $type ] ),
                'delete_with_user' => false,
            ) );
            foreach ( array_merge( self::FIELDS, array( 'org', 'start', 'end' ) ) as $key ) {
                register_post_meta( $type, '_kn_' . $key, array( 'single' => true, 'type' => in_array( $key, array( 'org', 'start', 'end' ), true ) ? 'integer' : 'string', 'show_in_rest' => false, 'auth_callback' => function ( $allowed, $meta_key, $post_id ) { return self::can_manage( $post_id ); } ) );
            }
        }
        // These are intentionally absent from public REST schemas.
        register_taxonomy( 'kn_topic', 'kn_event', array( 'label' => 'Themenkategorien', 'public' => true, 'show_ui' => false, 'show_in_rest' => false, 'meta_box_cb' => false, 'hierarchical' => true, 'capabilities' => array( 'manage_terms' => 'manage_options', 'edit_terms' => 'manage_options', 'delete_terms' => 'manage_options', 'assign_terms' => 'edit_kn_items' ) ) );
        register_taxonomy( 'kn_institution_type', 'kn_institution', array( 'label' => 'Institutionskategorien', 'public' => true, 'show_ui' => false, 'show_in_rest' => false, 'meta_box_cb' => false, 'hierarchical' => true, 'capabilities' => array( 'manage_terms' => 'manage_options', 'edit_terms' => 'manage_options', 'delete_terms' => 'manage_options', 'assign_terms' => 'edit_kn_items' ) ) );
    }
    public static function activate() {
        self::register();
        $caps = array( 'read', 'kn_access', 'upload_files', 'edit_kn_items', 'edit_others_kn_items', 'publish_kn_items', 'read_private_kn_items', 'delete_kn_items', 'delete_private_kn_items', 'delete_published_kn_items', 'delete_others_kn_items', 'edit_private_kn_items', 'edit_published_kn_items', 'create_kn_items' );
        add_role( 'kn_institution', 'Institution', array_fill_keys( $caps, true ) );
        $role = get_role( 'kn_institution' ); foreach ( $caps as $cap ) if ( $role ) $role->add_cap( $cap );
        $admin = get_role( 'administrator' ); foreach ( array_merge( $caps, array( 'kn_manage_institutions' ) ) as $cap ) if ( $admin ) $admin->add_cap( $cap );
        self::seed_terms();
        KN_Categories::ensure_defaults();
        self::seed();
        flush_rewrite_rules();
    }
    /** Default categories are created once; categories an administrator later removes stay removed. */
    public static function seed_terms( $sets = null ) {
        if ( get_option( 'kn_terms_seeded' ) ) return;
        foreach ( $sets ?: array( 'kn_topic' => self::TOPICS, 'kn_institution_type' => self::ORG_TYPES ) as $taxonomy => $terms ) foreach ( $terms as $term ) if ( ! term_exists( $term, $taxonomy ) ) wp_insert_term( $term, $taxonomy );
        update_option( 'kn_terms_seeded', 1, false );
    }
    /** Replacing the plugin ZIP does not run the activation hook; a version change repairs tables, roles, schedule and permalinks. */
    public static function maybe_upgrade() {
        if ( get_option( 'kn_version' ) === KN_VERSION ) return;
        self::activate();
        KN_Mail::activate();
        KN_Mail::detect_page();
        update_option( 'kn_version', KN_VERSION, false );
    }
    /** Themes print the account name as author; the public sees the responsible institution instead. */
    public static function author_name( $name ) {
        $post = get_post();
        if ( $post && in_array( $post->post_type, self::types(), true ) ) { $org = self::owner( $post->ID ); if ( $org ) return get_the_title( $org ); }
        return $name;
    }
    public static function author_block( $content, $block, $instance = null ) {
        $id = $instance && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : (int) get_the_ID();
        if ( ! $id || ! in_array( get_post_type( $id ), self::types(), true ) ) return $content;
        $org = self::owner( $id );
        return $org ? '<div class="wp-block-post-author-name">' . esc_html( get_the_title( $org ) ) . '</div>' : '';
    }
    public static function author_link( $link, $author_id ) {
        $post = get_post();
        if ( $post && in_array( $post->post_type, self::types(), true ) && self::owner( $post->ID ) ) return get_permalink( self::owner( $post->ID ) );
        return $link;
    }
    public static function hide_institution_authors() {
        if ( ! is_author() ) return;
        $user = get_queried_object();
        if ( $user instanceof WP_User && in_array( 'kn_institution', (array) $user->roles, true ) ) { global $wp_query; $wp_query->set_404(); status_header( 404 ); nocache_headers(); }
    }
    private static function seed() {
        if ( get_option( 'kn_seed_installed' ) ) return;
        self::system_write( function () {
            $existing = get_posts( array( 'post_type' => 'kn_institution', 'post_status' => 'any', 'meta_key' => '_kn_seed', 'meta_value' => 'praxis-anthropos', 'numberposts' => 1 ) );
            if ( $existing ) { update_option( 'kn_seed_installed', (int) $existing[0]->ID, false ); return; }
            $id = wp_insert_post( array( 'post_type' => 'kn_institution', 'post_status' => 'draft', 'post_title' => 'Praxis Anthropos AG', 'post_content' => '<p>Praxis für anthroposophische Medizin und Naturheilverfahren in Kreuzlingen. Das Profil dient als erste Testeinrichtung im Netzwerk. Aktuelle Angebote und Terminmöglichkeiten finden Sie auf der Website der Praxis.</p>', 'post_excerpt' => 'Anthroposophische Medizin und Naturheilverfahren in Kreuzlingen.' ), true );
            if ( is_wp_error( $id ) ) return;
            foreach ( array( 'address' => 'Löwenstrasse 16 (Rondo)', 'city' => 'CH-8280 Kreuzlingen', 'email' => 'praxis.engesser@hin.ch', 'phone' => '+41 71 672 39 89', 'website' => 'https://praxis-anthropos.ch/', 'source' => 'https://praxis-anthropos.ch/kontakt/' ) as $key => $value ) update_post_meta( $id, '_kn_' . $key, $value );
            update_post_meta( $id, '_kn_seed', 'praxis-anthropos' );
            wp_set_object_terms( $id, 'Gesundheit', 'kn_institution_type' );
            update_option( 'kn_seed_installed', (int) $id, false );
        } );
    }
    public static function map_caps( $caps, $cap, $user_id, $args ) {
        if ( ! in_array( $cap, array( 'edit_post', 'read_post', 'delete_post', 'edit_kn_item', 'read_kn_item', 'delete_kn_item' ), true ) || empty( $args[0] ) ) return $caps;
        $post = get_post( (int) $args[0] );
        if ( $post && 'revision' === $post->post_type ) $post = get_post( $post->post_parent );
        if ( ! $post || ! in_array( $post->post_type, self::types(), true ) ) return $caps;
        if ( self::is_admin( $user_id ) ) return array( 'manage_options' );
        if ( in_array( $cap, array( 'read_post', 'read_kn_item' ), true ) && 'publish' === $post->post_status ) {
            $owner = 'kn_institution' === $post->post_type ? $post : get_post( self::owner( $post->ID ) );
            if ( $owner && 'kn_institution' === $owner->post_type && 'publish' === $owner->post_status && ! $owner->post_password ) return array( 'read' );
        }
        return self::can_manage( $post->ID, $user_id ) ? array( 'kn_access' ) : array( 'do_not_allow' );
    }
    public static function user_caps( $allcaps, $caps, $args, $user ) {
        if ( ! empty( $allcaps['kn_access'] ) && empty( $allcaps['manage_options'] ) && ! self::org_id( $user->ID ) ) foreach ( array( 'create_kn_items', 'publish_kn_items', 'upload_files', 'edit_kn_items', 'edit_others_kn_items', 'delete_kn_items', 'read_private_kn_items' ) as $cap ) $allcaps[ $cap ] = false;
        return $allcaps;
    }
    public static function guard_insert( $empty, $data ) {
        if ( self::$trusted ) return $empty;
        $stored = ! empty( $data['ID'] ) ? get_post( (int) $data['ID'] ) : null;
        if ( $stored && in_array( $stored->post_type, self::types(), true ) && ! self::is_admin() && ( ! self::can_manage( $stored->ID ) || $stored->post_type !== ( $data['post_type'] ?? $stored->post_type ) ) ) return true;
        if ( empty( $data['post_type'] ) || ! in_array( $data['post_type'], self::types(), true ) ) return $empty;
        if ( self::is_admin() ) return $empty;
        $id = isset( $data['ID'] ) ? (int) $data['ID'] : 0;
        if ( $id ) return self::can_manage( $id ) ? $empty : true;
        return user_can( get_current_user_id(), 'kn_access' ) && self::org_id() && 'kn_institution' !== $data['post_type'] ? $empty : true;
    }
    // The editor always sends post_ID; then the form values belong to that post only.
    private static function submitted( $id = 0 ) { return ! empty( $_POST['kn_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kn_nonce'] ) ), 'kn_save_content' ) && ( ! $id || ! isset( $_POST['post_ID'] ) || absint( $_POST['post_ID'] ) === (int) $id ); }
    private static function form_errors( $type ) {
        $f = isset( $_POST['kn_fields'] ) && is_array( $_POST['kn_fields'] ) ? wp_unslash( $_POST['kn_fields'] ) : array();
        if ( 'kn_event' !== $type ) return true;
        $topics = isset( $_POST['kn_topics'] ) && is_array( $_POST['kn_topics'] ) ? array_map( 'absint', $_POST['kn_topics'] ) : array();
        $known = get_terms( array( 'taxonomy' => 'kn_topic', 'hide_empty' => false, 'fields' => 'ids' ) );
        if ( is_wp_error( $known ) || count( array_intersect( $topics, $known ) ) !== count( $topics ) ) return new WP_Error( 'kn_categories', 'Bitte ausschließlich die vorgegebenen Kategorien auswählen.' );
        return self::validate_event( array( 'start' => self::parse_datetime( $f['start'] ?? '' ), 'end' => self::parse_datetime( $f['end'] ?? '' ), 'categories' => $topics ) );
    }
    public static function prepare_post( $data, $postarr, $raw, $update ) {
        if ( ! in_array( $data['post_type'], self::types(), true ) || self::$trusted ) return $data;
        if ( ! self::is_admin() ) {
            $data['post_author'] = $update && ! empty( $postarr['ID'] ) ? get_post_field( 'post_author', (int) $postarr['ID'] ) : get_current_user_id();
            $data['post_content'] = wp_slash( wp_kses_post( wp_unslash( $data['post_content'] ) ) );
        }
        if ( 'kn_event' === $data['post_type'] && in_array( $data['post_status'], array( 'publish', 'future' ), true ) && self::submitted( (int) ( $postarr['ID'] ?? 0 ) ) ) {
            $valid = self::form_errors( 'kn_event' );
            if ( is_wp_error( $valid ) ) { $data['post_status'] = 'draft'; self::error( $valid->get_error_message() . ' Der Eintrag bleibt als Entwurf gespeichert.' ); }
        }
        if ( in_array( $data['post_status'], array( 'publish', 'future' ), true ) && 'kn_institution' !== $data['post_type'] ) {
            $existing = ! empty( $postarr['ID'] ) ? self::owner( (int) $postarr['ID'] ) : 0;
            $selected = self::is_admin() && isset( $_POST['kn_org'] ) ? absint( $_POST['kn_org'] ) : ( $existing ?: self::org_id() );
            if ( ! $selected || 'kn_institution' !== get_post_type( $selected ) ) { $data['post_status'] = 'draft'; self::error( 'Bitte zuerst eine verantwortliche Institution zuordnen. Der Inhalt bleibt ein Entwurf.' ); }
        }
        return $data;
    }
    public static function check_scheduled( $new, $old, $post ) {
        if ( ! in_array( $post->post_type, self::types(), true ) || ! in_array( $new, array( 'publish', 'future' ), true ) ) return;
        if ( get_post_meta( $post->ID, '_kn_schedule_error', true ) ) self::system_write( function () use ( $post ) { delete_post_meta( $post->ID, '_kn_schedule_error' ); } );
        if ( 'publish' !== $new || 'future' !== $old || 'kn_event' !== $post->post_type ) return;
        // When this post's own editor form triggers the publication, prepare_post already checked the submitted values.
        if ( self::submitted() && isset( $_POST['post_ID'] ) && absint( $_POST['post_ID'] ) === (int) $post->ID ) return;
        $topics = wp_get_object_terms( $post->ID, 'kn_topic', array( 'fields' => 'ids' ) );
        $valid = self::validate_event( array( 'start' => (int) get_post_meta( $post->ID, '_kn_start', true ), 'end' => (int) get_post_meta( $post->ID, '_kn_end', true ), 'categories' => is_wp_error( $topics ) ? array() : $topics ) );
        $reason = is_wp_error( $valid ) ? $valid->get_error_message() : ( self::owner( $post->ID ) ? '' : 'Es ist keine verantwortliche Institution zugeordnet.' );
        if ( '' === $reason ) return;
        // Nobody is logged in during WP-Cron, so the reason is kept on the entry and shown in the list and the editor.
        self::system_write( function () use ( $post, $reason ) { wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) ); update_post_meta( $post->ID, '_kn_schedule_error', $reason ); } );
    }
    public static function schedule_state( $states, $post ) {
        if ( in_array( $post->post_type, self::types(), true ) && get_post_meta( $post->ID, '_kn_schedule_error', true ) ) $states['kn_schedule_error'] = 'Geplante Veröffentlichung abgebrochen';
        return $states;
    }
    public static function save_post( $id, $post, $update, $before ) {
        if ( self::$saving || ! in_array( $post->post_type, self::types(), true ) || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) return;
        self::$saving = true;
        try {
            if ( 'kn_institution' !== $post->post_type && ! get_post_meta( $id, '_kn_org', true ) ) {
                $owner = self::is_admin() && isset( $_POST['kn_org'] ) ? absint( $_POST['kn_org'] ) : self::org_id();
                if ( $owner && 'kn_institution' === get_post_type( $owner ) ) self::system_write( function () use ( $id, $owner ) { update_post_meta( $id, '_kn_org', $owner ); } );
            }
            if ( ! self::submitted( $id ) || ! self::can_manage( $id ) ) {
                if ( ( self::$trusted || self::can_manage( $id ) ) && 'kn_event' === $post->post_type && 'publish' === $post->post_status ) {
                    $topics = wp_get_object_terms( $id, 'kn_topic', array( 'fields' => 'ids' ) );
                    $valid = self::validate_event( array( 'start' => (int) get_post_meta( $id, '_kn_start', true ), 'end' => (int) get_post_meta( $id, '_kn_end', true ), 'categories' => is_wp_error( $topics ) ? array() : $topics ) );
                    if ( is_wp_error( $valid ) ) self::system_write( function () use ( $id ) { wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) ); } );
                }
                if ( ! self::$trusted && self::can_manage( $id ) ) do_action( 'kn_content_saved', $id );
                return;
            }
            $fields = isset( $_POST['kn_fields'] ) && is_array( $_POST['kn_fields'] ) ? wp_unslash( $_POST['kn_fields'] ) : array();
            if ( 'kn_institution' !== $post->post_type && self::is_admin() && isset( $_POST['kn_org'] ) ) {
                $selected = absint( $_POST['kn_org'] );
                if ( $selected && 'kn_institution' === get_post_type( $selected ) ) update_post_meta( $id, '_kn_org', $selected );
            }
            foreach ( self::FIELDS as $key ) {
                if ( in_array( $key, array( 'mail_mode', 'source', 'cancelled' ), true ) || ! array_key_exists( $key, $fields ) ) continue;
                $value = $fields[ $key ];
                if ( ! is_scalar( $value ) ) continue;
                if ( in_array( $key, array( 'website', 'donation_url', 'registration_url' ), true ) ) $value = esc_url_raw( $value, array( 'http', 'https' ) );
                elseif ( 'email' === $key ) $value = sanitize_email( $value );
                elseif ( in_array( $key, array( 'public_info', 'internal_info', 'donation' ), true ) ) $value = sanitize_textarea_field( $value );
                else $value = sanitize_text_field( $value );
                update_post_meta( $id, '_kn_' . $key, $value );
            }
            $mail_mode = ! empty( $_POST['kn_send_mail'] ) && isset( $fields['mail_mode'] ) && in_array( $fields['mail_mode'], array( 'immediate', 'delayed' ), true ) ? $fields['mail_mode'] : 'none';
            update_post_meta( $id, '_kn_mail_mode', $mail_mode );
            // An unticked checkbox is absent from the form, so cancellation is always written explicitly.
            if ( 'kn_event' === $post->post_type ) update_post_meta( $id, '_kn_cancelled', ! empty( $fields['cancelled'] ) ? '1' : '' );
            if ( 'kn_institution' === $post->post_type && self::is_admin() ) self::system_write( function () use ( $id ) { update_post_meta( $id, '_kn_mail_blocked', empty( $_POST['kn_mail_blocked'] ) ? '' : '1' ); } );
            if ( 'kn_event' === $post->post_type ) {
                $valid = self::form_errors( 'kn_event' );
                if ( is_wp_error( $valid ) ) { self::error( $valid->get_error_message() ); do_action( 'kn_content_saved', $id ); return; }
                update_post_meta( $id, '_kn_start', self::parse_datetime( $fields['start'] ?? '' ) );
                update_post_meta( $id, '_kn_end', self::parse_datetime( $fields['end'] ?? '' ) );
                $topics = isset( $_POST['kn_topics'] ) && is_array( $_POST['kn_topics'] ) ? array_map( 'absint', $_POST['kn_topics'] ) : array();
                $known = get_terms( array( 'taxonomy' => 'kn_topic', 'hide_empty' => false, 'fields' => 'ids' ) );
                if ( ! is_wp_error( $known ) ) wp_set_object_terms( $id, array_values( array_intersect( $topics, $known ) ), 'kn_topic' );
            }
            if ( 'kn_institution' === $post->post_type ) {
                $terms = isset( $_POST['kn_org_types'] ) && is_array( $_POST['kn_org_types'] ) ? array_map( 'absint', $_POST['kn_org_types'] ) : array();
                wp_set_object_terms( $id, $terms, 'kn_institution_type' );
            }
            do_action( 'kn_content_saved', $id );
        } finally { self::$saving = false; }
    }
    public static function guard_meta( $check, $id, $key, $value, $extra ) {
        // delete_metadata() without an object, or with delete_all, deletes a key on all posts; plugin keys stay with administrators.
        $bulk = ! $id || ( ! empty( $extra ) && 'delete_post_metadata' === current_filter() );
        if ( $bulk && ! self::$trusted && ! self::is_admin() && 0 === strpos( (string) $key, '_kn_' ) ) return false;
        if ( self::$trusted || self::is_admin() || ! in_array( get_post_type( $id ), self::types(), true ) ) return $check;
        $guarded = 0 === strpos( $key, '_kn_' ) || '_thumbnail_id' === $key;
        if ( ! $guarded ) return $check;
        if ( ! self::can_manage( $id ) ) return false;
        if ( '_kn_org' === $key && (int) $value !== self::org_id() ) return false;
        if ( in_array( $key, array( '_kn_brevo_lists', '_kn_seed', '_kn_schedule_error' ), true ) || 0 === strpos( $key, '_kn_mail_' ) && '_kn_mail_mode' !== $key ) return false;
        if ( '_thumbnail_id' === $key && (int) $value > 0 ) {
            $image = get_post( (int) $value );
            $image_org = $image && $image->post_parent ? self::owner( $image->post_parent ) : 0;
            if ( ! $image || 'attachment' !== $image->post_type || ( self::org_id( (int) $image->post_author ) !== self::org_id() && $image_org !== self::org_id() ) ) return false;
        }
        // The editor swaps the featured image immediately via Ajax, without saving; that change counts like a save for mail approval.
        if ( '_thumbnail_id' === $key && ! self::$saving && ! self::submitted( $id ) ) do_action( 'kn_content_saved', $id );
        return $check;
    }
    private static function guard_mid( $check, $type, $mid, $value, $new_key, $delete ) {
        if ( self::$trusted || self::is_admin() ) return $check;
        $row = get_metadata_by_mid( $type, (int) $mid );
        if ( ! $row ) return $check;
        $id_key = $type . '_id'; $id = (int) $row->$id_key; $old_key = $row->meta_key;
        $next_key = false === $new_key ? $old_key : $new_key;
        if ( ! is_string( $next_key ) ) return false;
        if ( 'user' === $type ) {
            if ( '_kn_institution_id' === $old_key || '_kn_institution_id' === $next_key ) return false;
            return $check;
        }
        if ( ! in_array( get_post_type( $id ), self::types(), true ) ) return $check;
        $protected = 0 === strpos( $old_key, '_kn_' ) || 0 === strpos( $next_key, '_kn_' ) || '_thumbnail_id' === $old_key || '_thumbnail_id' === $next_key;
        if ( ! $protected ) return $check;
        if ( $old_key !== $next_key || ( $delete && '_kn_org' === $old_key ) ) return false;
        return self::guard_meta( $check, $id, $old_key, $delete ? $row->meta_value : $value, null );
    }
    public static function guard_user_meta( $check, $id, $key, $value, $extra ) { return '_kn_institution_id' === $key && ! self::$trusted && ! self::is_admin() ? false : $check; }
    public static function guard_topics( $id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
        if ( self::$term_guard || 'kn_topic' !== $taxonomy || 'kn_event' !== get_post_type( $id ) ) return;
        $current = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
        if ( is_wp_error( $current ) ) return;
        $unauthorized = ! self::$trusted && ! self::can_manage( $id );
        if ( count( $current ) <= 3 && ! $unauthorized ) return;
        $old = array(); foreach ( $old_tt_ids as $tt_id ) { $term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy ); if ( $term ) $old[] = (int) $term->term_id; }
        self::$term_guard = true;
        wp_set_object_terms( $id, array_slice( $old, 0, 3 ), $taxonomy );
        self::$term_guard = false;
        self::error( $unauthorized ? 'Keine Berechtigung für die Kategorien dieses Eintrags.' : 'Maximal drei Kategorien sind möglich. Die vorherige Auswahl wurde beibehalten.' );
    }
    public static function scope_query( $query ) {
        $requested_types = (array) $query->get( 'post_type' );
        if ( array_intersect( $requested_types, self::types() ) || in_array( 'any', $requested_types, true ) || $query->is_search() ) $query->set( 'suppress_filters', false );
        if ( ! is_admin() || ! $query->is_main_query() || self::is_admin() || ! current_user_can( 'kn_access' ) ) return;
        $type = $query->get( 'post_type' );
        if ( 'attachment' === $type ) { $query->set( 'author', get_current_user_id() ); return; }
        if ( ! in_array( $type, self::types(), true ) ) return;
        $org = self::org_id();
        if ( 'kn_institution' === $type ) $query->set( 'post__in', array( $org ?: 0 ) );
        else { $meta = (array) $query->get( 'meta_query' ); $meta[] = array( 'key' => '_kn_org', 'value' => $org ?: -1, 'compare' => '=' ); $query->set( 'meta_query', $meta ); }
        $query->set( 'author', '' );
    }
    public static function scope_read_where( $where, $query ) {
        if ( self::$trusted || self::is_admin() ) return $where;
        global $wpdb;
        $org = get_current_user_id() && current_user_can( 'kn_access' ) ? self::org_id() : 0;
        $p = $wpdb->posts;
        $m = $wpdb->postmeta;
        $public_owner = "EXISTS (SELECT 1 FROM $m kn_owner_meta INNER JOIN $p kn_owner_post ON kn_owner_post.ID=CAST(kn_owner_meta.meta_value AS UNSIGNED) WHERE kn_owner_meta.post_id=$p.ID AND kn_owner_meta.meta_key='_kn_org' AND kn_owner_post.post_type='kn_institution' AND kn_owner_post.post_status='publish' AND kn_owner_post.post_password='')";
        $own_content = $org ? $wpdb->prepare( "EXISTS (SELECT 1 FROM $m kn_own_meta WHERE kn_own_meta.post_id=$p.ID AND kn_own_meta.meta_key='_kn_org' AND kn_own_meta.meta_value=%d)", $org ) : '0=1';
        $own_institution = $org ? $wpdb->prepare( "$p.ID=%d", $org ) : '0=1';
        $where .= " AND ($p.post_type NOT IN ('kn_event','kn_job','kn_news') OR (($p.post_status='publish' AND $public_owner) OR $own_content))";
        $where .= " AND ($p.post_type<>'kn_institution' OR $p.post_status='publish' OR $own_institution)";
        return $where;
    }
    public static function scope_media( $args ) {
        if ( ! self::is_admin() && current_user_can( 'kn_access' ) ) {
            unset( $args['author'] );
            $args['author__in'] = self::org_id() ? get_users( array( 'meta_key' => '_kn_institution_id', 'meta_value' => self::org_id(), 'fields' => 'ID' ) ) : array( 0 );
            if ( ! $args['author__in'] ) $args['author__in'] = array( 0 );
        }
        return $args;
    }
    public static function upload_types( $mimes ) { return ! self::is_admin() && current_user_can( 'kn_access' ) ? array_intersect_key( $mimes, array_flip( array( 'jpg|jpeg|jpe', 'png', 'webp', 'gif' ) ) ) : $mimes; }
    public static function menus() {
        add_menu_page( 'konstanth', 'konstanth', 'kn_access', 'konstanth', array( __CLASS__, 'dashboard' ), 'dashicons-calendar-alt', 26 );
        add_submenu_page( 'konstanth', 'Anleitung & Shortcodes', 'Übersicht & Shortcodes', 'kn_access', 'konstanth', array( __CLASS__, 'dashboard' ) );
        add_submenu_page( 'konstanth', 'Rundmails', 'Rundmails', 'kn_access', 'kn-mail', array( 'KN_Mail', 'render_admin' ) );
        add_submenu_page( 'konstanth', 'Alte Mailtexte importieren', 'Mailtexte importieren', 'kn_access', 'kn-import', array( __CLASS__, 'import_page' ) );
        add_submenu_page( 'konstanth', 'Einstellungen', 'Versand-Einstellungen', 'manage_options', 'kn-settings', array( 'KN_Mail', 'render_settings' ) );
    }
    public static function admin_assets() {
        $screen = get_current_screen();
        if ( ! $screen || ( ! in_array( $screen->post_type, self::types(), true ) && false === strpos( $screen->id, 'kn-' ) && false === strpos( $screen->id, 'konstanth' ) ) ) return;
        wp_enqueue_style( 'kn-admin', KN_URL . 'assets/admin.css', array(), KN_VERSION );
        wp_enqueue_script( 'kn-admin', KN_URL . 'assets/admin.js', array(), KN_VERSION, true );
    }
    public static function meta_boxes() {
        foreach ( self::types() as $type ) add_meta_box( 'kn-details', 'kn_institution' === $type ? 'Institutionsprofil' : 'Angaben für die Veröffentlichung', array( __CLASS__, 'details_box' ), $type, 'normal', 'high' );
        foreach ( array( 'kn_event', 'kn_job', 'kn_news' ) as $type ) add_meta_box( 'kn-mail-box', 'Website & Rundmail', array( __CLASS__, 'mail_box' ), $type, 'side', 'high' );
        add_meta_box( 'kn-internal', 'Interne Hinweise · nur deine Einrichtung', array( __CLASS__, 'internal_box' ), 'kn_event', 'normal', 'default' );
    }
    private static function input( $post, $key, $label, $type = 'text', $hint = '' ) {
        $value = get_post_meta( $post->ID, '_kn_' . $key, true );
        if ( in_array( $key, array( 'start', 'end' ), true ) && $value ) $value = wp_date( 'Y-m-d\TH:i', (int) $value );
        echo '<p class="kn-field"><label for="kn-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
        if ( 'textarea' === $type ) echo '<textarea rows="4" id="kn-' . esc_attr( $key ) . '" name="kn_fields[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
        else echo '<input type="' . esc_attr( $type ) . '" id="kn-' . esc_attr( $key ) . '" name="kn_fields[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
        if ( $hint ) echo '<span class="description">' . esc_html( $hint ) . '</span>';
        echo '</p>';
    }
    public static function details_box( $post ) {
        wp_nonce_field( 'kn_save_content', 'kn_nonce' );
        if ( 'kn_institution' !== $post->post_type ) {
            if ( self::is_admin() ) { echo '<p class="kn-field"><label>Verantwortliche Institution</label>'; self::org_select( 'kn_org', self::owner( $post->ID ) ); echo '</p>'; }
            else echo '<div class="kn-admin-note">Deine Institution: <strong>' . esc_html( get_the_title( self::org_id() ) ) . '</strong>. Die Zuordnung wird beim Speichern automatisch geprüft.</div>';
        }
        if ( 'kn_event' === $post->post_type ) {
            echo '<div class="kn-field-grid">'; self::input( $post, 'start', 'Beginn *', 'datetime-local', 'Zeitzone: ' . wp_timezone_string() ); self::input( $post, 'end', 'Ende *', 'datetime-local' ); echo '</div>';
            self::input( $post, 'location', 'Ort / Adresse' );
            self::input( $post, 'registration_url', 'Link zur Anmeldung (optional)', 'url', 'Erscheint als Knopf „Zur Anmeldung“, solange der Termin nicht abgesagt ist.' );
            echo '<p class="kn-field"><label><input type="checkbox" name="kn_fields[cancelled]" value="1" ' . checked( '1' === get_post_meta( $post->ID, '_kn_cancelled', true ), true, false ) . '> Termin ist abgesagt</label><span class="description">Der Termin bleibt sichtbar, wird aber deutlich als abgesagt markiert. Geplante Rundmails dazu werden nicht versendet.</span></p>';
            echo '<fieldset class="kn-topic-choices"><legend>Themenkategorien * · maximal 3</legend>';
            $chosen = wp_get_object_terms( $post->ID, 'kn_topic', array( 'fields' => 'ids' ) );
            foreach ( KN_Categories::terms( 'kn_topic' ) as $term ) echo '<label><input type="checkbox" name="kn_topics[]" value="' . (int) $term->term_id . '" ' . checked( ! is_wp_error( $chosen ) && in_array( (int) $term->term_id, $chosen, true ), true, false ) . '> <span class="kn-swatch" style="background:' . esc_attr( $term->kn_bg ) . ';color:' . esc_attr( $term->kn_ink ) . '">' . esc_html( $term->name ) . '</span></label>';
            echo '<p class="description" id="kn-category-feedback" aria-live="polite">Eine bis drei Kategorien auswählen.</p></fieldset>';
            self::input( $post, 'public_info', 'Informationen für Besucher', 'textarea', 'Öffentlich auf der Website und in der Rundmail sichtbar.' );
        } elseif ( 'kn_job' === $post->post_type ) {
            echo '<div class="kn-field-grid">'; self::input( $post, 'location', 'Arbeitsort' ); self::input( $post, 'workload', 'Stellenumfang, z. B. 60–80 %' ); echo '</div>';
            self::input( $post, 'deadline', 'Bewerbungsfrist', 'date' ); self::input( $post, 'application', 'Bewerbungsweg / Kontakt / Link' );
        } elseif ( 'kn_institution' === $post->post_type ) {
            echo '<fieldset class="kn-topic-choices"><legend>Institutionskategorien</legend>';
            $chosen = wp_get_object_terms( $post->ID, 'kn_institution_type', array( 'fields' => 'ids' ) );
            foreach ( KN_Categories::terms( 'kn_institution_type' ) as $term ) echo '<label><input type="checkbox" name="kn_org_types[]" value="' . (int) $term->term_id . '" ' . checked( ! is_wp_error( $chosen ) && in_array( (int) $term->term_id, $chosen, true ), true, false ) . '> <span class="kn-swatch" style="background:' . esc_attr( $term->kn_bg ) . ';color:' . esc_attr( $term->kn_ink ) . '">' . esc_html( $term->name ) . '</span></label>';
            echo '</fieldset><div class="kn-field-grid">'; self::input( $post, 'address', 'Straße / Hausnummer' ); self::input( $post, 'city', 'Postleitzahl / Ort' ); self::input( $post, 'email', 'Kontakt-E-Mail', 'email' ); self::input( $post, 'phone', 'Telefon', 'tel' ); echo '</div>';
            self::input( $post, 'website', 'Website', 'url' ); self::input( $post, 'donation', 'Eigene Förderinformationen / Spendenkonto', 'textarea' ); self::input( $post, 'donation_url', 'Förder- oder Spendenlink', 'url' );
            $source = get_post_meta( $post->ID, '_kn_source', true ); if ( $source ) echo '<p class="description">Testprofil aus öffentlichen Angaben: <a target="_blank" rel="noopener noreferrer" href="' . esc_url( $source ) . '">Originalquelle prüfen</a>. Keine medizinischen Leistungsversprechen oder Öffnungszeiten übernommen.</p>';
            echo '<p class="description">Das Beitragsbild dient als Logo. Für Bilder und Texte deiner Einrichtung verwendest du eigene oder freigegebene Inhalte.</p>';
            if ( self::is_admin() ) echo '<p class="kn-field"><label><input type="checkbox" name="kn_mail_blocked" value="1" ' . checked( '1' === get_post_meta( $post->ID, '_kn_mail_blocked', true ), true, false ) . '> Rundmails dieser Einrichtung sperren</label><span class="description">Nur für Administratoren sichtbar. Gesperrte Rundmails werden nicht versendet, zum Beispiel nach einem Missbrauch des Zugangs.</span></p>';
        }
    }
    public static function internal_box( $post ) { self::input( $post, 'internal_info', 'Nur intern', 'textarea', 'Wird weder auf der Website noch in Rundmails oder öffentlichen Schnittstellen ausgegeben.' ); }
    public static function mail_box( $post ) {
        $mode = get_post_meta( $post->ID, '_kn_mail_mode', true ) ?: 'none';
        echo '<p>Text und Beitragsbild werden automatisch für die Website und die Mail verwendet.</p><label><input id="kn-send-mail" type="checkbox" name="kn_send_mail" value="1" ' . checked( 'none' !== $mode, true, false ) . '> Als Rundmail versenden</label><p><select id="kn-mail-mode" name="kn_fields[mail_mode]"><option value="delayed" ' . selected( $mode, 'delayed', false ) . '>24 Stunden nach Veröffentlichung</option><option value="immediate" ' . selected( $mode, 'immediate', false ) . '>Sofort nach Veröffentlichung</option></select></p><p class="description">Empfänger: bestätigte Abonnements deiner Einrichtung und – bei Terminen – der gewählten Themen. Interne Hinweise werden ausgelassen.</p>';
        $published = 'publish' === $post->post_status ? (int) get_post_time( 'U', true, $post ) : 0; $start = (int) get_post_meta( $post->ID, '_kn_start', true );
        if ( 'delayed' === $mode && $published && $published < time() - DAY_IN_SECONDS ) echo '<p class="kn-admin-note">Hinweis: Die Veröffentlichung liegt mehr als 24 Stunden zurück. Die Rundmail geht deshalb beim nächsten Lauf raus.</p>';
        if ( 'kn_event' === $post->post_type && 'delayed' === $mode && $start && $start < ( $published ?: time() ) + DAY_IN_SECONDS ) echo '<p class="kn-admin-note">Hinweis: Der Termin beginnt vor Ablauf der 24 Stunden. Dann würde die Rundmail nicht mehr versendet – wähle besser „Sofort nach Veröffentlichung“.</p>';
        if ( 'auto-draft' !== $post->post_status ) echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kn_mail_preview&post_id=' . $post->ID ), 'kn_mail_preview_' . $post->ID ) ) . '">Gespeicherte Mailvorschau ↗</a></p>';
        echo '<p class="description">Zuerst speichern, dann Vorschau prüfen. Testmodus und Versandstatus stehen unter Rundmails.</p>';
        if ( 'auto-draft' !== $post->post_status && self::can_manage( $post->ID ) ) echo '<p><a class="button" href="' . esc_url( self::duplicate_url( $post->ID ) ) . '">' . ( 'kn_event' === $post->post_type ? 'Termin duplizieren' : 'Als Kopie neu anlegen' ) . '</a></p>';
    }
    private static function org_select( $name, $selected = 0, $include_empty = true ) {
        echo '<select name="' . esc_attr( $name ) . '">';
        if ( $include_empty ) echo '<option value="0">Bitte Institution wählen</option>';
        foreach ( get_posts( array( 'post_type' => 'kn_institution', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) echo '<option value="' . (int) $p->ID . '" ' . selected( (int) $selected, (int) $p->ID, false ) . '>' . esc_html( $p->post_title ) . ( 'publish' === $p->post_status ? '' : ' (Entwurf)' ) . '</option>';
        echo '</select>';
    }
    public static function user_field( $user ) {
        if ( ! current_user_can( 'manage_options' ) ) return;
        echo '<h2>konstanth · Institutionszugang</h2><table class="form-table"><tr><th>Feste Institution</th><td>'; wp_nonce_field( 'kn_assign_org', 'kn_user_nonce' ); self::org_select( 'kn_user_org', (int) get_user_meta( $user->ID, '_kn_institution_id', true ) ); echo '<p class="description">Zusätzlich die Rolle „Institution“ wählen. Ohne Zuordnung kann das Konto keine Inhalte anlegen. Mehrere Konten können derselben Institution zugeordnet sein.</p></td></tr></table>';
    }
    public static function new_user_field( $operation ) { if ( 'add-new-user' === $operation ) self::user_field( (object) array( 'ID' => 0 ) ); }
    public static function save_user( $user_id ) {
        if ( ! current_user_can( 'manage_options' ) || empty( $_POST['kn_user_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kn_user_nonce'] ) ), 'kn_assign_org' ) || ! isset( $_POST['kn_user_org'] ) ) return;
        $org = absint( $_POST['kn_user_org'] );
        if ( $org && 'kn_institution' !== get_post_type( $org ) ) return;
        if ( $org ) update_user_meta( $user_id, '_kn_institution_id', $org ); else delete_user_meta( $user_id, '_kn_institution_id' );
    }
    private static function error( $text ) { if ( get_current_user_id() ) set_transient( 'kn_error_' . get_current_user_id(), $text, 120 ); }
    public static function notices() {
        $error = get_transient( 'kn_error_' . get_current_user_id() );
        if ( $error ) { echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>'; delete_transient( 'kn_error_' . get_current_user_id() ); }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $edited = $screen && 'post' === $screen->base && isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        $reason = $edited && self::can_manage( $edited ) ? (string) get_post_meta( $edited, '_kn_schedule_error', true ) : '';
        if ( '' !== $reason ) echo '<div class="notice notice-warning"><p><strong>Die geplante Veröffentlichung wurde abgebrochen.</strong> ' . esc_html( $reason ) . ' Der Eintrag ist jetzt ein Entwurf. Bitte die Angaben ergänzen und erneut veröffentlichen oder planen.</p></div>';
        $zone = get_option( 'timezone_string' );
        if ( self::is_admin() && ( ! $zone || 'UTC' === $zone ) ) echo '<div class="notice notice-warning"><p><strong>konstanth:</strong> Die Zeitzone der Website ist nicht auf eine Stadt eingestellt. Termine und Kalenderdateien können dadurch um eine bis zwei Stunden verschoben sein. Bitte unter <a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">Einstellungen → Allgemein</a> zum Beispiel „Berlin“ oder „Zürich“ wählen.</p></div>';
        if ( current_user_can( 'kn_access' ) && ! self::is_admin() && ! self::org_id() ) echo '<div class="notice notice-warning"><p>Deinem Konto ist keine aktive Institution zugeordnet. Bitte den Administrator um Zuordnung unter Benutzer bitten.</p></div>';
    }
    public static function dashboard() {
        if ( ! current_user_can( 'kn_access' ) ) wp_die( 'Keine Berechtigung.', '', array( 'response' => 403 ) );
        echo '<div class="wrap kn-admin"><h1>konstanth</h1><p class="kn-admin-intro">Zusammenarbeit anthroposophischer Einrichtungen. Deine Website, deine Gestaltung – unsere Inhaltsbausteine.</p><div class="kn-admin-panels"><section class="kn-admin-panel"><h2>Inhalte einfügen</h2><p>Erstelle deine Seite wie gewohnt. Im WordPress-Editor fügst du einen konstanth-Block ein, zum Beispiel „konstanth: Termine“ – mit Live-Vorschau und Einstellungen in der Seitenleiste. Alternativ funktionieren die Kurzzeilen unten in einem Shortcode-Block oder Page Builder. Kopfbereich, Navigation, Seitentitel und Footer bleiben bei deinem Theme.</p>';
        foreach ( array( 'konstanth_events' => 'Chronologischer Veranstaltungskalender', 'konstanth_jobs' => 'Stellenbörse', 'konstanth_institutions' => 'Institutionsverzeichnis', 'konstanth_news' => 'Nachrichten', 'konstanth_donations' => 'Förderinformationen', 'konstanth_newsletter' => 'Rundmail-Anmeldung' ) as $code => $label ) echo '<div class="kn-shortcode"><div><strong>' . esc_html( $label ) . '</strong><code>[' . esc_html( $code ) . ']</code></div><button class="button" type="button" data-kn-copy="[' . esc_attr( $code ) . ']">Kopieren</button></div>';
        echo '<p class="description">Optional: <code>[konstanth_events institution="123" limit="12"]</code> zeigt nur die Einrichtung mit dieser ID. Die ID steht in der Institutionstabelle. Die Farben der Themen und Einrichtungsarten stellst du unter „Kategorien & Farben“ ein; die CSS-Variable <code>--kn-accent</code> passt zusätzlich die Akzentfarbe an.</p></section><section class="kn-admin-panel"><h2>Dein Ablauf</h2><ol><li>Veranstaltung, Stelle oder Nachricht anlegen.</li><li>Text, Beitragsbild und Zusatzangaben ergänzen.</li><li>Mailhäkchen optional setzen und Versandzeitpunkt wählen.</li><li>Als Entwurf speichern und Vorschau prüfen.</li><li>Veröffentlichen – der Inhalt erscheint im passenden Baustein.</li></ol><p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=kn_event' ) ) . '">Veranstaltung erstellen</a></p>';
        if ( self::is_admin() ) echo '<h3>Institutionszugänge einrichten</h3><ol><li>Institution erstellen oder den Testentwurf „Praxis Anthropos AG“ prüfen und veröffentlichen.</li><li>Unter Benutzer ein Konto anlegen, Rolle „Institution“ wählen und die Institution zuordnen.</li><li>Mit diesem Konto testen: Nur Inhalte der zugewiesenen Einrichtung sind bearbeitbar.</li></ol><p>Administratoren verwalten als Plattformverantwortliche alle Einrichtungen und Einstellungen. Institutionskonten erhalten weder diese Verwaltung noch Zugriff auf Brevo-Schlüssel.</p><p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=kn_institution' ) ) . '">Institutionen verwalten</a> <a class="button" href="' . esc_url( admin_url( 'user-new.php' ) ) . '">Benutzer anlegen</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=kn-categories' ) ) . '">Kategorien & Farben</a></p>';
        else { $org_id = self::org_id(); if ( $org_id ) echo '<p>Deine Institution: <strong>' . esc_html( get_the_title( $org_id ) ) . '</strong></p><p><a class="button" href="' . esc_url( get_edit_post_link( $org_id, 'raw' ) ) . '">Institutionsprofil bearbeiten</a></p>'; }
        echo '<h3>Versand vorbereiten</h3><p>Nach der Installation ist der Testmodus aktiv. Die einmalige Brevo-Konfiguration erfolgt unter Versand-Einstellungen. Bis zur bewussten Freigabe wird keine Rundmail versandt.</p></section></div><p id="kn-copy-feedback" aria-live="polite"></p></div>';
    }
    public static function row_actions( $actions, $post ) {
        if ( in_array( $post->post_type, self::types(), true ) ) {
            unset( $actions['inline hide-if-no-js'] );
            if ( ! self::can_manage( $post->ID ) ) { unset( $actions['edit'], $actions['trash'], $actions['untrash'], $actions['delete'] ); }
            elseif ( 'kn_institution' !== $post->post_type ) { $actions['kn_duplicate'] = '<a href="' . esc_url( self::duplicate_url( $post->ID ) ) . '">Duplizieren</a>'; }
        }
        return $actions;
    }
    private static function duplicate_url( $id ) { return wp_nonce_url( admin_url( 'admin-post.php?action=kn_duplicate&post=' . (int) $id ), 'kn_duplicate_' . (int) $id ); }
    /** Recurring courses: a copy keeps text, details, themes and image as a new draft without a mail order. */
    public static function duplicate( $id ) {
        $source = get_post( $id );
        if ( ! $source || ! in_array( $source->post_type, array( 'kn_event', 'kn_job', 'kn_news' ), true ) || ! self::can_manage( $source->ID ) ) return new WP_Error( 'kn_forbidden', 'Dieser Eintrag kann nicht dupliziert werden.' );
        $owner = self::owner( $source->ID );
        return self::system_write( function () use ( $source, $owner ) {
            $copy = wp_insert_post( wp_slash( array( 'post_type' => $source->post_type, 'post_status' => 'draft', 'post_title' => $source->post_title . ' (Kopie)', 'post_content' => $source->post_content, 'post_excerpt' => $source->post_excerpt, 'post_author' => get_current_user_id() ) ), true );
            if ( is_wp_error( $copy ) ) return $copy;
            foreach ( array_merge( array_diff( self::FIELDS, array( 'cancelled', 'mail_mode', 'source' ) ), array( 'start', 'end' ) ) as $key ) { $value = get_post_meta( $source->ID, '_kn_' . $key, true ); if ( '' !== $value ) update_post_meta( $copy, '_kn_' . $key, $value ); }
            update_post_meta( $copy, '_kn_org', $owner ); update_post_meta( $copy, '_kn_mail_mode', 'none' );
            $thumb = get_post_thumbnail_id( $source->ID ); if ( $thumb ) set_post_thumbnail( $copy, $thumb );
            $topics = wp_get_object_terms( $source->ID, 'kn_topic', array( 'fields' => 'ids' ) ); if ( ! is_wp_error( $topics ) && $topics ) wp_set_object_terms( $copy, array_map( 'intval', $topics ), 'kn_topic' );
            return $copy;
        } );
    }
    public static function duplicate_request() {
        $id = absint( $_GET['post'] ?? 0 );
        check_admin_referer( 'kn_duplicate_' . $id );
        $copy = self::duplicate( $id );
        if ( is_wp_error( $copy ) ) wp_die( esc_html( $copy->get_error_message() ), '', array( 'response' => 403 ) );
        self::error( 'Kopie angelegt. Bitte Datum, Uhrzeit und Text prüfen, dann veröffentlichen.' );
        wp_safe_redirect( get_edit_post_link( $copy, 'raw' ) ); exit;
    }
    public static function columns( $columns ) { $columns['kn_org'] = 'Institution / ID'; return $columns; }
    public static function column( $column, $id ) { if ( 'kn_org' === $column ) { $owner = self::owner( $id ); echo esc_html( $owner ? get_the_title( $owner ) . ' · ID ' . $owner : 'Noch nicht zugeordnet' ); } }
    public static function import_page() {
        if ( ! current_user_can( 'kn_access' ) ) wp_die( 'Keine Berechtigung.' );
        echo '<div class="wrap kn-admin"><h1>Alte Mailtexte importieren</h1><div class="kn-admin-panel"><p>CSV-Datei mit den Spalten <code>title,content,date</code> hochladen. UTF-8, Komma oder Semikolon, maximal 1 MB und 100 Nachrichten. Alle Texte werden als <strong>Entwürfe</strong> deiner Institution gespeichert. Empfängeradressen werden nicht übernommen und keine Rundmails ausgelöst.</p><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="kn_import">'; wp_nonce_field( 'kn_import' );
        if ( self::is_admin() ) { echo '<p>Ziel-Institution '; self::org_select( 'kn_import_org', 0 ); echo '</p>'; }
        echo '<p><input type="file" name="kn_csv" accept=".csv,text/csv" required></p>'; submit_button( 'Mailtexte als Entwürfe importieren' ); echo '</form><p class="description">Vorher eine kleine Testdatei importieren. HTML wird auf erlaubte WordPress-Inhalte begrenzt. Ein Empfängerimport ist hier bewusst ausgeschlossen; bestätigte Abonnements entstehen über die Anmeldung.</p></div></div>';
    }
    /** Imports up to 100 rows (title, content, date) as drafts of one institution; identical texts are skipped. */
    public static function import_rows( $handle, $org ) {
        $first = fgets( $handle ); rewind( $handle ); $delimiter = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',';
        $header = fgetcsv( $handle, 0, $delimiter, '"', '' );
        $header = array_map( function ( $v ) { return strtolower( trim( ltrim( (string) $v, "\xEF\xBB\xBF" ) ) ); }, $header ?: array() );
        if ( ! in_array( 'title', $header, true ) || ! in_array( 'content', $header, true ) ) return new WP_Error( 'kn_import_columns', 'Die Spalten title und content fehlen.' );
        $count = 0;
        while ( $count < 100 && ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) !== false ) {
            if ( count( $row ) !== count( $header ) ) continue; $values = array_combine( $header, $row ); if ( ! trim( $values['title'] ) ) continue;
            $fingerprint = hash( 'sha256', $org . '|' . $values['title'] . '|' . $values['content'] );
            if ( get_posts( array( 'post_type' => 'kn_news', 'post_status' => 'any', 'meta_key' => '_kn_import_hash', 'meta_value' => $fingerprint, 'fields' => 'ids', 'numberposts' => 1, 'suppress_filters' => false ) ) ) continue;
            $args = array( 'post_type' => 'kn_news', 'post_status' => 'draft', 'post_author' => get_current_user_id(), 'post_title' => sanitize_text_field( $values['title'] ), 'post_content' => wp_kses_post( $values['content'] ), 'meta_input' => array( '_kn_org' => $org, '_kn_mail_mode' => 'none', '_kn_import_hash' => $fingerprint ) );
            if ( ! empty( $values['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $values['date'] ) && self::parse_datetime( $values['date'] . 'T12:00' ) ) $args['post_date'] = $values['date'] . ' 12:00:00';
            // The institution was verified by the caller; the trusted context lets the duplicate marker and owner be stored for institution accounts too.
            $id = self::system_write( function () use ( $args ) { return wp_insert_post( wp_slash( $args ), true ); } );
            if ( ! is_wp_error( $id ) ) $count++;
        }
        return $count;
    }
    public static function import() {
        if ( ! current_user_can( 'kn_access' ) ) wp_die( 'Keine Berechtigung.', '', array( 'response' => 403 ) ); check_admin_referer( 'kn_import' );
        $org = self::is_admin() ? absint( $_POST['kn_import_org'] ?? 0 ) : self::org_id();
        if ( ! $org || 'kn_institution' !== get_post_type( $org ) ) wp_die( 'Bitte eine gültige Institution wählen.' );
        $file = $_FILES['kn_csv'] ?? array();
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || ! empty( $file['error'] ) || $file['size'] > 1048576 || 'csv' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) wp_die( 'Bitte eine CSV-Datei bis 1 MB hochladen.' );
        $handle = fopen( $file['tmp_name'], 'r' ); if ( ! $handle ) wp_die( 'Datei kann nicht gelesen werden.' );
        $count = self::import_rows( $handle, $org );
        if ( is_wp_error( $count ) ) { fclose( $handle ); wp_die( esc_html( $count->get_error_message() ) ); }
        fclose( $handle );
        self::error( $count . ' Mailtexte als Entwürfe importiert. Doppelte Einträge wurden ausgelassen. Kein Versand ausgelöst.' );
        wp_safe_redirect( admin_url( 'edit.php?post_type=kn_news' ) ); exit;
    }
}
