<?php
/**
 * Plugin Name: 4All SEO Bridge
 * Plugin URI: https://github.com/proximodev/4all-seo-bridge
 * Update URI: https://github.com/proximodev/4all-seo-bridge
 * Description: Lets 4All Digital's tools securely read and update SEO titles, meta descriptions, and image alt text on this site.
 * Version: 0.2.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.6
 * Author: 4All Digital
 * Author URI: https://4all.digital
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FOURALL_SEO_BRIDGE_VERSION', '0.2.1' );
define( 'FOURALL_SEO_BRIDGE_UPDATE_URI', 'https://github.com/proximodev/4all-seo-bridge' );
define( 'FOURALL_SEO_BRIDGE_MANIFEST_URL', 'https://github.com/proximodev/4all-seo-bridge/releases/latest/download/manifest.json' );
if ( ! defined( 'FOURALL_SEO_BRIDGE_AUTO_UPDATE' ) ) {
	define( 'FOURALL_SEO_BRIDGE_AUTO_UPDATE', true );
}

add_action( 'rest_api_init', function () {
	register_rest_route( '4all/v1', '/ping', array(
		'methods'             => 'GET',
		'callback'            => 'fourall_seo_bridge_ping',
		'permission_callback' => 'fourall_seo_bridge_can_edit',
	) );

	// Read the current SEO title / meta and the post facts for a URL or id.
	register_rest_route( '4all/v1', '/seo', array(
		'methods'             => 'GET',
		'callback'            => 'fourall_seo_bridge_seo_read',
		'permission_callback' => 'fourall_seo_bridge_can_edit',
		'args'                => array(
			'url' => array( 'type' => 'string' ),
			'id'  => array( 'type' => 'integer' ),
		),
	) );

	register_rest_route( '4all/v1', '/seo', array(
		'methods'             => 'POST',
		'callback'            => 'fourall_seo_bridge_seo',
		'permission_callback' => 'fourall_seo_bridge_can_edit',
		'args'                => array(
			'url'      => array( 'type' => 'string' ),
			'id'       => array( 'type' => 'integer' ),
			'title'    => array( 'type' => 'string' ),
			'metadesc' => array( 'type' => 'string' ),
			'dry_run'  => array( 'type' => 'boolean', 'default' => false ),
		),
	) );

	register_rest_route( '4all/v1', '/alt', array(
		'methods'             => 'POST',
		'callback'            => 'fourall_seo_bridge_alt',
		'permission_callback' => 'fourall_seo_bridge_can_edit',
		'args'                => array(
			'url'     => array( 'required' => true, 'type' => 'string' ),
			'alt'     => array( 'required' => true, 'type' => 'string' ),
			'dry_run' => array( 'type' => 'boolean', 'default' => false ),
		),
	) );
} );

/** Route gate: caller must be able to edit posts (per-post checked in handlers). */
function fourall_seo_bridge_can_edit() {
	return current_user_can( 'edit_posts' );
}

/** Detect the active SEO plugin and its meta keys. */
function fourall_seo_bridge_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
		return array( 'name' => 'yoast', 'title' => '_yoast_wpseo_title', 'metadesc' => '_yoast_wpseo_metadesc' );
	}
	if ( class_exists( 'RankMath' ) ) {
		return array( 'name' => 'rankmath', 'title' => 'rank_math_title', 'metadesc' => 'rank_math_description' );
	}
	return null;
}

/**
 * Resolve a URL to a post ID.
 *
 *   1. `?p=`, `?page_id=`, `?attachment_id=` query vars win — this is how
 *      drafts (which have no public permalink) are addressed. Any status.
 *      A present query var is authoritative: a value that is not a
 *      positive integer is `bad_query_var`, an unknown id is `not_found`.
 *      Neither falls through to the path (so `/?p=abc` is never the
 *      front page).
 *   2. Empty path → the static front page, or 0 for a posts-page front.
 *   3. `url_to_postid()` (published permalinks, nested pages, CPTs).
 *   4. When the request host differs from this site's host (a production
 *      sheet pushed to staging), rebuild the path on home_url() and retry.
 *      A same-host miss stays a miss.
 *
 * Returns array( id, reason ) so callers can tell a blog-index front page
 * from a plain miss.
 */
function fourall_seo_bridge_resolve( $url ) {
	$url   = strtok( (string) $url, '#' );
	$parts = wp_parse_url( $url );
	$query = array();
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}
	foreach ( array( 'p', 'page_id', 'attachment_id' ) as $var ) {
		if ( ! array_key_exists( $var, $query ) ) {
			continue;
		}
		$raw = is_scalar( $query[ $var ] ) ? trim( (string) $query[ $var ] ) : '';
		if ( '' === $raw || ! ctype_digit( $raw ) || 0 === (int) $raw ) {
			return array( 0, 'bad_query_var' );
		}
		$id = (int) $raw;
		return get_post( $id ) ? array( $id, 'query_var' ) : array( 0, 'not_found' );
	}

	$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
	if ( '' === trim( $path, '/' ) ) {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			return array( (int) get_option( 'page_on_front' ), 'front_page' );
		}
		return array( 0, 'blog_index_not_supported' );
	}

	$id = (int) url_to_postid( $url );
	if ( $id ) {
		return array( $id, 'permalink' );
	}

	$req_host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( $req_host && $home_host && $req_host !== $home_host ) {
		$rebuilt = home_url( $path );
		$id      = (int) url_to_postid( $rebuilt );
		if ( $id ) {
			return array( $id, 'path_match' );
		}
	}
	return array( 0, 'not_found' );
}

/** Post facts every response carries. */
function fourall_seo_bridge_post_facts( $id ) {
	$post = get_post( $id );
	if ( ! $post ) {
		return array();
	}
	return array(
		'post_title'  => $post->post_title,
		'post_type'   => $post->post_type,
		'post_status' => $post->post_status,
		'slug'        => $post->post_name,
		'url'         => get_permalink( $id ),
	);
}

/**
 * Clean a value for a head tag without silently dropping ordinary text:
 * reject invalid UTF-8, strip tags, remove control characters, collapse
 * whitespace, trim. A literal "<" or a percent-encoded sequence survives
 * where sanitize_text_field() would have eaten it. Length is the caller's.
 */
function fourall_seo_bridge_clean( $val ) {
	$val = wp_check_invalid_utf8( (string) $val );
	$val = wp_strip_all_tags( $val, false );
	$val = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $val );
	$val = preg_replace( '/\s+/u', ' ', $val );
	return trim( (string) $val );
}

/** Resolve the `url` / `id` request params to a post id or a WP_Error. */
function fourall_seo_bridge_target( WP_REST_Request $req ) {
	$id = (int) $req->get_param( 'id' );
	if ( $id > 0 ) {
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'not_found', 'No post with that id.', array( 'status' => 404 ) );
		}
	} else {
		$url = esc_url_raw( (string) $req->get_param( 'url' ) );
		if ( '' === $url ) {
			return new WP_Error( 'missing_target', 'Pass url or id.', array( 'status' => 400 ) );
		}
		list( $id, $reason ) = fourall_seo_bridge_resolve( $url );
		if ( ! $id ) {
			if ( 'blog_index_not_supported' === $reason ) {
				return new WP_Error( 'blog_index_not_supported', 'The front page is the posts index; its SEO title lives in the SEO plugin options, not on a post.', array( 'status' => 422 ) );
			}
			if ( 'bad_query_var' === $reason ) {
				return new WP_Error( 'bad_query_var', 'The ?p= / ?page_id= / ?attachment_id= value must be a positive post id.', array( 'status' => 400 ) );
			}
			return new WP_Error( 'not_found', 'Could not resolve URL to a post/page.', array( 'status' => 404 ) );
		}
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'cannot_edit', 'Not allowed to edit this post.', array( 'status' => 403 ) );
	}
	return $id;
}

function fourall_seo_bridge_ping() {
	$seo  = fourall_seo_bridge_seo_plugin();
	$user = wp_get_current_user();
	return array(
		'ok'           => true,
		'plugin'       => '4all-seo-bridge',
		'version'      => FOURALL_SEO_BRIDGE_VERSION,
		'seo_plugin'   => $seo ? $seo['name'] : null,
		'can_edit'     => current_user_can( 'edit_posts' ),
		'user'         => $user ? $user->user_login : null,
		'capabilities' => array( 'seo_read', 'resolve_query_vars', 'resolve_path_match', 'post_facts' ),
	);
}

/** GET /seo — current SEO title / meta plus post facts. No writes. */
function fourall_seo_bridge_seo_read( WP_REST_Request $req ) {
	$seo = fourall_seo_bridge_seo_plugin();
	$id  = fourall_seo_bridge_target( $req );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$current = array( 'title' => '', 'metadesc' => '' );
	if ( $seo ) {
		$current['title']    = (string) get_post_meta( $id, $seo['title'], true );
		$current['metadesc'] = (string) get_post_meta( $id, $seo['metadesc'], true );
	}
	return array_merge(
		array( 'id' => $id, 'seo' => $seo ? $seo['name'] : null, 'current' => $current ),
		fourall_seo_bridge_post_facts( $id )
	);
}

function fourall_seo_bridge_seo( WP_REST_Request $req ) {
	$seo = fourall_seo_bridge_seo_plugin();
	if ( ! $seo ) {
		return new WP_Error( 'no_seo_plugin', 'No supported SEO plugin (Yoast or RankMath) is active.', array( 'status' => 400 ) );
	}

	$id = fourall_seo_bridge_target( $req );
	if ( is_wp_error( $id ) ) {
		return $id;
	}

	$dry     = (bool) $req->get_param( 'dry_run' );
	$before  = array();
	$after   = array();
	$changed = array();

	foreach ( array( 'title', 'metadesc' ) as $slot ) {
		$key = $seo[ $slot ];
		$cur = (string) get_post_meta( $id, $key, true );
		$before[ $slot ] = $cur;

		$val = $req->get_param( $slot );
		if ( null === $val ) {
			$after[ $slot ]   = $cur;   // not provided → leave as-is
			$changed[ $slot ] = false;
			continue;
		}
		$val = fourall_seo_bridge_clean( $val );
		$after[ $slot ]   = $val;
		$changed[ $slot ] = ( $val !== $cur );
		if ( $changed[ $slot ] && ! $dry ) {
			update_post_meta( $id, $key, wp_slash( $val ) );
		}
	}

	if ( ! $dry && ( $changed['title'] || $changed['metadesc'] ) ) {
		fourall_seo_bridge_log( sprintf( 'seo #%d (%s) %s', $id, $seo['name'], wp_json_encode( $changed ) ) );
	}

	return array_merge(
		array(
			'id'      => $id,
			'seo'     => $seo['name'],
			'before'  => $before,
			'after'   => $after,
			'changed' => $changed,
			'dry_run' => $dry,
		),
		fourall_seo_bridge_post_facts( $id )
	);
}

function fourall_seo_bridge_alt( WP_REST_Request $req ) {
	$url = esc_url_raw( (string) $req->get_param( 'url' ) );
	$id  = attachment_url_to_postid( $url );
	if ( ! $id ) {
		// Retry without WordPress size / scaled / rotated suffixes
		// (e.g. -300x200, -scaled, -rotated) — same rules as the CLI tools.
		$stripped = preg_replace( '/-(\d+x\d+|scaled|rotated)(\.[a-z0-9]+)$/i', '$2', $url );
		if ( $stripped !== $url ) {
			$id = attachment_url_to_postid( $stripped );
		}
	}
	if ( ! $id ) {
		return new WP_Error( 'not_found', 'Could not resolve URL to an attachment.', array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'cannot_edit', 'Not allowed to edit this attachment.', array( 'status' => 403 ) );
	}

	$dry     = (bool) $req->get_param( 'dry_run' );
	$cur     = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	$val     = fourall_seo_bridge_clean( (string) $req->get_param( 'alt' ) );
	$changed = ( $val !== $cur );
	if ( $changed && ! $dry ) {
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $val ) );
		fourall_seo_bridge_log( sprintf( 'alt #%d', $id ) );
	}

	return array(
		'id'      => $id,
		'before'  => array( 'alt' => $cur ),
		'after'   => array( 'alt' => $val ),
		'changed' => array( 'alt' => $changed ),
		'dry_run' => $dry,
	);
}

/** Audit line for every applied write (user, target, fields). */
function fourall_seo_bridge_log( $msg ) {
	$user = wp_get_current_user();
	error_log( '[4all-seo-bridge] ' . ( $user ? $user->user_login : '?' ) . ' ' . $msg );
}

// ------------------------------------------------------------------
// Self-update through WordPress's plugin updater (core, since 5.8).
//
// The plugin header declares `Update URI` on github.com. When WordPress
// checks for plugin updates it asks `update_plugins_github.com` for this
// plugin; we answer with the latest release's manifest. Core compares the
// manifest version with the installed one and shows the update (or
// applies it, when auto-update is on). The manifest and zip are release
// assets built by this repository's Release workflow.
// ------------------------------------------------------------------

/** Fetch and cache (12 h) the latest release manifest. Null on any problem. */
function fourall_seo_bridge_manifest( $force = false ) {
	$key = 'fourall_seo_bridge_manifest';
	if ( ! $force ) {
		$cached = get_site_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}
	$res = wp_remote_get( FOURALL_SEO_BRIDGE_MANIFEST_URL, array(
		'timeout' => 10,
		'headers' => array( 'Accept' => 'application/json' ),
	) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		set_site_transient( $key, array(), HOUR_IN_SECONDS ); // back off for an hour
		return null;
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
		set_site_transient( $key, array(), HOUR_IN_SECONDS );
		return null;
	}
	set_site_transient( $key, $data, 12 * HOUR_IN_SECONDS );
	return $data;
}

/** Answer WordPress's update check for this plugin with the release manifest. */
function fourall_seo_bridge_update_check( $update, $plugin_data, $plugin_file, $locales ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $update;
	}
	$m = fourall_seo_bridge_manifest();
	if ( ! $m ) {
		return $update;
	}
	return array(
		'id'           => FOURALL_SEO_BRIDGE_UPDATE_URI,
		'slug'         => dirname( plugin_basename( __FILE__ ) ),
		'plugin'       => $plugin_file,
		'version'      => (string) $m['version'],
		'url'          => isset( $m['homepage'] ) ? (string) $m['homepage'] : FOURALL_SEO_BRIDGE_UPDATE_URI,
		'package'      => (string) $m['download_url'],
		'requires'     => isset( $m['requires'] ) ? (string) $m['requires'] : '6.0',
		'requires_php' => isset( $m['requires_php'] ) ? (string) $m['requires_php'] : '7.4',
		'tested'       => isset( $m['tested'] ) ? (string) $m['tested'] : '',
		'icons'        => array(),
		'banners'      => array(),
	);
}
add_filter( 'update_plugins_github.com', 'fourall_seo_bridge_update_check', 10, 4 );

/** Minimal "View details" panel so the update row has something to show. */
function fourall_seo_bridge_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( __FILE__ ) ) !== $args->slug ) {
		return $result;
	}
	$m = fourall_seo_bridge_manifest();
	if ( ! $m ) {
		return $result;
	}
	return (object) array(
		'name'          => isset( $m['name'] ) ? $m['name'] : '4All SEO Bridge',
		'slug'          => $args->slug,
		'version'       => (string) $m['version'],
		'author'        => '4All Digital',
		'homepage'      => isset( $m['homepage'] ) ? $m['homepage'] : FOURALL_SEO_BRIDGE_UPDATE_URI,
		'requires'      => isset( $m['requires'] ) ? $m['requires'] : '6.0',
		'requires_php'  => isset( $m['requires_php'] ) ? $m['requires_php'] : '7.4',
		'tested'        => isset( $m['tested'] ) ? $m['tested'] : '',
		'last_updated'  => isset( $m['last_updated'] ) ? $m['last_updated'] : '',
		'download_link' => (string) $m['download_url'],
		'sections'      => array(
			'description' => 'Lets 4All Digital\'s tools securely read and update SEO titles, meta descriptions, and image alt text on this site.',
			'changelog'   => isset( $m['changelog_url'] ) ? '<a href="' . esc_url( $m['changelog_url'] ) . '">Changelog</a>' : '',
		),
	);
}
add_filter( 'plugins_api', 'fourall_seo_bridge_plugin_info', 10, 3 );

/** Auto-update this plugin unless the site opts out in wp-config.php. */
function fourall_seo_bridge_auto_update( $update, $item ) {
	if ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) {
		return (bool) FOURALL_SEO_BRIDGE_AUTO_UPDATE;
	}
	return $update;
}
add_filter( 'auto_update_plugin', 'fourall_seo_bridge_auto_update', 10, 2 );

/** Forget the cached manifest when the plugin is (re)activated or upgraded. */
register_activation_hook( __FILE__, function () {
	delete_site_transient( 'fourall_seo_bridge_manifest' );
} );

/**
 * Dashboard → Updates → "Check again" (update-core.php?force-check=1):
 * drop the cached manifest before core runs its check on that same page
 * load, so a release published in the last 12 hours shows up immediately.
 * Ordinary visits to the Updates screen, the Plugins screen and cron keep
 * using the cache.
 */
function fourall_seo_bridge_force_check() {
	if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache clear on core's own force-check screen.
		delete_site_transient( 'fourall_seo_bridge_manifest' );
	}
}
add_action( 'load-update-core.php', 'fourall_seo_bridge_force_check', 5 );
add_action( 'upgrader_process_complete', function ( $upgrader, $options ) {
	if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
		delete_site_transient( 'fourall_seo_bridge_manifest' );
	}
}, 10, 2 );
