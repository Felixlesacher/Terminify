<?php
/**
 * Plugin Name: konstanth – Einrichtungen, Termine & Rundmails
 * Description: Veranstaltungen, Stellen, Einrichtungen und Nachrichten als Gutenberg-Blöcke und Shortcodes für deine eigenen Seiten. Mit Institutionszugängen, Kategorien und Farben und optionaler Brevo-Rundmail.
 * Version: 1.2.1
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Author: konstanth
 * License: GPL-2.0-or-later
 * Text Domain: konstanth
 * Update URI: https://github.com/Felixlesacher/Terminify
 */

defined( 'ABSPATH' ) || exit;

define( 'KN_VERSION', '1.2.1' );
define( 'KN_FILE', __FILE__ );
define( 'KN_PATH', plugin_dir_path( __FILE__ ) );
define( 'KN_URL', plugin_dir_url( __FILE__ ) );

require_once KN_PATH . 'includes/class-kn-core.php';
require_once KN_PATH . 'includes/class-kn-categories.php';
require_once KN_PATH . 'includes/class-kn-mail.php';
require_once KN_PATH . 'includes/class-kn-frontend.php';
require_once KN_PATH . 'includes/class-kn-updates.php';

// Registered early so WordPress sees available updates on every admin and cron request.
KN_Updates::boot();

add_action( 'plugins_loaded', function () {
    new KN_Core();
    new KN_Categories();
    new KN_Mail();
    new KN_Frontend();
} );

register_activation_hook( __FILE__, function () {
    KN_Core::activate();
    KN_Mail::activate();
    update_option( 'kn_version', KN_VERSION, false );
} );
register_deactivation_hook( __FILE__, function () {
    KN_Mail::deactivate();
    flush_rewrite_rules();
} );
