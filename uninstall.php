<?php
/**
 * Runs only when the plugin is deleted in WordPress. Data is kept unless an administrator
 * explicitly enabled „Beim Löschen alle Daten entfernen“ under konstanth → Versand-Einstellungen.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
// The update bookkeeping of the bundled Plugin Update Checker is no content and always goes.
delete_site_option( 'external_updates-konstanth' );
wp_clear_scheduled_hook( 'puc_cron_check_updates-konstanth' );
if ( ! get_option( 'kn_delete_on_uninstall' ) ) return;

global $wpdb;
// Taxonomies are not registered while uninstalling; wp_delete_term needs them.
foreach ( array( 'kn_topic' => 'kn_event', 'kn_institution_type' => 'kn_institution' ) as $taxonomy => $type ) {
    if ( ! taxonomy_exists( $taxonomy ) ) register_taxonomy( $taxonomy, $type, array( 'public' => false ) );
}
$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('kn_event','kn_job','kn_institution','kn_news')" );
foreach ( $ids as $id ) wp_delete_post( (int) $id, true );
foreach ( array( 'kn_topic', 'kn_institution_type' ) as $taxonomy ) {
    $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
    if ( ! is_wp_error( $terms ) ) foreach ( $terms as $term_id ) wp_delete_term( (int) $term_id, $taxonomy );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kn_subscribers" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kn_mail_jobs" );
foreach ( array( 'kn_mail_settings', 'kn_seed_installed', 'kn_terms_seeded', 'kn_version', 'kn_last_queue_run', 'kn_mail_errors', 'kn_newsletter_page', 'kn_delete_on_uninstall', 'kn_topic_children', 'kn_institution_type_children', 'kn_banner_default', 'kn_addresses_split' ) as $option ) delete_option( $option );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'kn\\_list\\_lock\\_%' OR option_name LIKE '\\_transient\\_kn\\_%' OR option_name LIKE '\\_transient\\_timeout\\_kn\\_%'" );
delete_metadata( 'user', 0, '_kn_institution_id', '', true );
wp_clear_scheduled_hook( 'kn_mail_tick' );
$caps = array( 'kn_access', 'kn_manage_institutions', 'edit_kn_items', 'edit_others_kn_items', 'publish_kn_items', 'read_private_kn_items', 'delete_kn_items', 'delete_private_kn_items', 'delete_published_kn_items', 'delete_others_kn_items', 'edit_private_kn_items', 'edit_published_kn_items', 'create_kn_items' );
$admin = get_role( 'administrator' );
if ( $admin ) foreach ( $caps as $cap ) $admin->remove_cap( $cap );
remove_role( 'kn_institution' );
