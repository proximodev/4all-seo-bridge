<?php
/**
 * Updater probe — runs INSIDE WordPress via wp-cli, against the real core
 * updater, without publishing a release:
 *
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/4all-seo-bridge/tests/wp-updater-probe.php
 *
 * Checks: the real manifest fetch from GitHub; core's update_plugins
 * transient with the real manifest (no update when installed >= latest);
 * a seeded manifest makes core list an update; the plugins_api details
 * panel; the auto_update_plugin filter; Check again (force-check) clears the
 * cache only for a user who can update plugins. Leaves no transients behind.
 * Does NOT install anything.
 */

if ( ! defined( 'WP_CLI' ) ) { echo "Run with wp eval-file.\n"; exit( 1 ); }

$file  = '4all-seo-bridge/4all-seo-bridge.php';
$fails = 0;
$ok    = function ( $cond, $msg, $extra = null ) use ( &$fails ) {
	echo '  ', $cond ? 'ok  ' : 'FAIL', ' ', $msg, ( ! $cond && null !== $extra ? '  got ' . wp_json_encode( $extra ) : '' ), "\n";
	if ( ! $cond ) { $fails++; }
};
$plugins_update = function () use ( $file ) {
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$t = get_site_transient( 'update_plugins' );
	return array(
		'response'  => isset( $t->response[ $file ] ) ? $t->response[ $file ] : null,
		'no_update' => isset( $t->no_update[ $file ] ) ? $t->no_update[ $file ] : null,
	);
};

echo "installed: ", FOURALL_SEO_BRIDGE_VERSION, "  manifest url: ", FOURALL_SEO_BRIDGE_MANIFEST_URL, "\n";

echo "\nreal manifest from GitHub\n";
delete_site_transient( 'fourall_seo_bridge_manifest' );
$m = fourall_seo_bridge_manifest();
$ok( is_array( $m ) && ! empty( $m['version'] ), 'fetched (' . ( $m ? $m['version'] : 'null' ) . ')', $m );
$ok( $m && 0 === strpos( $m['download_url'], 'https://github.com/proximodev/4all-seo-bridge/releases/download/' ), 'download_url points at a release asset', $m ? $m['download_url'] : null );
$cached = get_site_transient( 'fourall_seo_bridge_manifest' );
$ok( is_array( $cached ) && $cached === $m, 'cached in the site transient' );
$real_version = $m ? $m['version'] : '0';

echo "\ncore's check with the real manifest\n";
$r = $plugins_update();
if ( version_compare( FOURALL_SEO_BRIDGE_VERSION, $real_version, '<' ) ) {
	$ok( $r['response'] && $r['response']->new_version === $real_version, "core lists $real_version as an update (installed is older)", $r );
} else {
	$ok( $r['no_update'] && $r['no_update']->new_version === $real_version, "core lists it under no_update ($real_version <= installed)", $r );
}

echo "\nseeded manifest (no release needed)\n";
$seed = array( 'name' => '4All SEO Bridge', 'slug' => '4all-seo-bridge', 'version' => '9.9.9', 'download_url' => home_url( '/wp-content/uploads/bridge/4all-seo-bridge.zip' ), 'requires' => '6.0', 'requires_php' => '7.4', 'tested' => '6.6', 'homepage' => 'https://github.com/proximodev/4all-seo-bridge', 'changelog_url' => 'https://github.com/proximodev/4all-seo-bridge/blob/main/CHANGELOG.md' );
set_site_transient( 'fourall_seo_bridge_manifest', $seed, 12 * HOUR_IN_SECONDS );
$r = $plugins_update();
$ok( $r['response'] && '9.9.9' === $r['response']->new_version, 'core lists 9.9.9 as available', $r );
$ok( $r['response'] && $seed['download_url'] === $r['response']->package, 'package = manifest download_url' );
$ok( $r['response'] && '4all-seo-bridge' === $r['response']->slug && $file === $r['response']->plugin, 'slug + plugin file as core expects' );
$ok( $r['response'] && 'https://github.com/proximodev/4all-seo-bridge' === $r['response']->id, 'id = Update URI (core overrides with the header)' );

echo "\nplugins_api details panel\n";
if ( ! function_exists( 'plugins_api' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin-install.php'; }
$info = plugins_api( 'plugin_information', array( 'slug' => '4all-seo-bridge' ) );
$ok( is_object( $info ) && ! is_wp_error( $info ) && '9.9.9' === $info->version, 'panel built from the (seeded) manifest', is_wp_error( $info ) ? $info->get_error_message() : $info );
$ok( is_object( $info ) && ! is_wp_error( $info ) && $seed['download_url'] === $info->download_link, 'download_link' );
$ok( is_object( $info ) && ! is_wp_error( $info ) && false !== strpos( $info->sections['changelog'], 'CHANGELOG.md' ), 'changelog link' );

echo "\nauto-update filter\n";
$ok( true === apply_filters( 'auto_update_plugin', false, (object) array( 'plugin' => $file ) ), 'auto_update_plugin → true for this plugin (constant default)' );
$ok( false === apply_filters( 'auto_update_plugin', false, (object) array( 'plugin' => 'akismet/akismet.php' ) ), 'other plugins untouched' );

echo "\nCheck again (force-check) on Dashboard → Updates\n";
$_GET['force-check'] = '1';
wp_set_current_user( 0 );
set_site_transient( 'fourall_seo_bridge_manifest', $seed, 12 * HOUR_IN_SECONDS );
do_action( 'load-update-core.php' );
$after = get_site_transient( 'fourall_seo_bridge_manifest' );
$ok( is_array( $after ) && '9.9.9' === $after['version'], 'logged-out request leaves the cache alone', $after );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );
$ok( current_user_can( 'update_plugins' ), 'admin can update_plugins' );
set_site_transient( 'fourall_seo_bridge_manifest', $seed, 12 * HOUR_IN_SECONDS );
delete_site_transient( 'update_plugins' ); // core skips a re-check within 60 s of the last one on this screen
do_action( 'load-update-core.php' ); // ours at 5 clears; core's wp_update_plugins at 10 refetches
$after = get_site_transient( 'fourall_seo_bridge_manifest' );
$ok( is_array( $after ) && $real_version === $after['version'], "admin Check again drops the seeded 9.9.9 and core refetches the real $real_version", $after );
unset( $_GET['force-check'] );
set_site_transient( 'fourall_seo_bridge_manifest', $seed, 12 * HOUR_IN_SECONDS );
do_action( 'load-update-core.php' );
$after = get_site_transient( 'fourall_seo_bridge_manifest' );
$ok( is_array( $after ) && '9.9.9' === $after['version'], 'plain Updates screen visit (no force-check) leaves the cache alone', $after );

echo "\ncleanup\n";
delete_site_transient( 'fourall_seo_bridge_manifest' );
delete_site_transient( 'update_plugins' );
wp_update_plugins();
$r = $plugins_update();
$ok( ! $r['response'], 'no phantom update left behind' );

echo "\n", $fails ? "$fails FAILED" : 'all ok', "\n";
exit( $fails ? 1 : 0 );
