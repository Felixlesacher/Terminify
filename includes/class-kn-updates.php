<?php
/**
 * Updates from the public GitHub repository of the plugin. WordPress then offers new releases under
 * Plugins and Dashboard → Updates like any other plugin update. Only the plugin ZIP attached to a release
 * is installed; content, accounts and settings stay in the database.
 */

defined( 'ABSPATH' ) || exit;

final class KN_Updates {
    /** Public repository with the plugin releases. */
    const REPO = 'https://github.com/Felixlesacher/Terminify/';
    const ASSET = '/^konstanth-\d+\.\d+\.\d+\.zip$/D';

    private static $checker = null;

    /** A site can point to another repository with KN_UPDATE_REPO or switch updates off with KN_DISABLE_UPDATES in wp-config.php. */
    public static function repo() {
        return self::normalize( defined( 'KN_UPDATE_REPO' ) ? (string) KN_UPDATE_REPO : self::REPO );
    }
    /** Only plain https://github.com/<owner>/<repository> addresses are accepted. */
    public static function normalize( $repo ) {
        return preg_match( '#^https://github\.com/[A-Za-z0-9-]+/[A-Za-z0-9._-]+/?$#D', (string) $repo ) ? rtrim( $repo, '/' ) . '/' : '';
    }

    public static function boot() {
        if ( null !== self::$checker || ( defined( 'KN_DISABLE_UPDATES' ) && KN_DISABLE_UPDATES ) ) return self::$checker;
        $repo = self::repo();
        if ( '' === $repo ) return null;
        require_once KN_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
        self::$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( $repo, KN_FILE, 'konstanth' );
        // The raw repository archive has another folder layout, so only the built release ZIP may be installed.
        self::$checker->getVcsApi()->enableReleaseAssets( self::ASSET, \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS );
        // Without a matching release the library would fall back to tags and branches and offer their raw source archives.
        add_filter( 'puc_vcs_update_detection_strategies-konstanth', static function ( $strategies ) {
            return array_intersect_key( (array) $strategies, array( \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::STRATEGY_LATEST_RELEASE => true ) );
        } );
        return self::$checker;
    }
}
