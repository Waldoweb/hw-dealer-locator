<?php
/**
 * Plugin Name:       HW Dealer Locator
 * Plugin URI:        https://github.com/Waldoweb/hw-dealer-locator
 * Description:       Dealer finder: a map and list of dealers with search by city, state or ZIP, "Use my location", and a distance filter. Dealers are their own post type and are geocoded on save.
 * Version:           0.2.1
 * Author:            Hostetler Web
 * Author URI:        https://hostetlerweb.com
 * Update URI:        https://github.com/Waldoweb/hw-dealer-locator
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Text Domain:       hw-dealer-locator
 */

defined( 'ABSPATH' ) || exit;

define( 'HW_DEALER_VERSION', '0.2.1' );

// Updates come from GitHub releases through Plugin Update Checker. Pushing a vX.Y.Z
// tag builds the release zip (.github/workflows/release.yml); sites check every 6
// hours and install it automatically.
if ( is_readable( __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Waldoweb/hw-dealer-locator/',
		__FILE__,
		'hw-dealer-locator',
		6
	)->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );

	add_filter(
		'auto_update_plugin',
		function ( $update, $item ) {
			return ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) ? true : $update;
		},
		10,
		2
	);
}

define( 'HW_DEALER_FILE', __FILE__ );
define( 'HW_DEALER_DIR', __DIR__ );

/** Asset version: changes whenever the file does, so browsers and page caches never serve a stale copy. */
function hw_dealer_asset_ver( $rel ) {
	$mtime = @filemtime( HW_DEALER_DIR . '/' . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return HW_DEALER_VERSION . ( $mtime ? '.' . $mtime : '' );
}

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/post-type.php';
require_once __DIR__ . '/includes/hours.php';
require_once __DIR__ . '/includes/geocode.php';
require_once __DIR__ . '/includes/editor.php';
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/shortcode.php';
require_once __DIR__ . '/includes/delete-guard.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/bricks.php';
require_once __DIR__ . '/includes/importer.php';

register_activation_hook(
	__FILE__,
	function () {
		hw_dealer_register_post_type();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		unregister_post_type( 'hw_dealer' );
		flush_rewrite_rules();
	}
);
