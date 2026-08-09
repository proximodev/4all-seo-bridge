<?php
/**
 * Plugin Name: 4All SEO Bridge
 * Description: Lets 4All Digital's tools securely update SEO titles, meta descriptions, and image alt text on this site.
 * Version: 0.1.1
 * Author: 4All Digital
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FOURALL_SEO_BRIDGE_VERSION', '0.1.1' );

add_action( 'rest_api_init', function () {
	register_rest_route( '4all/v1', '/ping', array(
		'methods'             => 'GET',
		'callback'            => 'fourall_seo_bridge_ping',
		'permission_callback' => 'fourall_seo_bridge_can_edit',
	) );

	register_rest_route( '4all/v1', '/seo', array(
		'methods'             => 'POST',
		'callback'            => 'fourall_seo_bridge_seo',
		'permission_callback' => 'fourall_seo_bridge_can_edit',
		'args'                => array(
			'url'      => array( 'required' => true, 'type' => 'string' ),
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

/** Resolve a URL to a post ID, handling the static front page. */
function fourall_seo_bridge_resolve( $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( '' === trim( $path, '/' ) ) {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			return (int) get_option( 'page_on_front' );
		}
		return 0; // blog index — no single editable SEO post
	}
	return (int) url_to_postid( $url );
}

function fourall_seo_bridge_ping() {
	$seo  = fourall_seo_bridge_seo_plugin();
	$user = wp_get_current_user();
	return array(
		'ok'         => true,
		'plugin'     => '4all-seo-bridge',
		'version'    => FOURALL_SEO_BRIDGE_VERSION,
		'seo_plugin' => $seo ? $seo['name'] : null,
		'can_edit'   => current_user_can( 'edit_posts' ),
		'user'       => $user ? $user->user_login : null,
	);
}

function fourall_seo_bridge_seo( WP_REST_Request $req ) {
	$seo = fourall_seo_bridge_seo_plugin();
	if ( ! $seo ) {
		return new WP_Error( 'no_seo_plugin', 'No supported SEO plugin (Yoast or RankMath) is active.', array( 'status' => 400 ) );
	}

	$url = esc_url_raw( (string) $req->get_param( 'url' ) );
	$id  = fourall_seo_bridge_resolve( $url );
	if ( ! $id ) {
		return new WP_Error( 'not_found', 'Could not resolve URL to a post/page.', array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'cannot_edit', 'Not allowed to edit this post.', array( 'status' => 403 ) );
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
		$val = sanitize_text_field( $val );
		$after[ $slot ]   = $val;
		$changed[ $slot ] = ( $val !== $cur );
		if ( $changed[ $slot ] && ! $dry ) {
			update_post_meta( $id, $key, wp_slash( $val ) );
		}
	}

	if ( ! $dry && ( $changed['title'] || $changed['metadesc'] ) ) {
		fourall_seo_bridge_log( sprintf( 'seo #%d (%s) %s', $id, $seo['name'], wp_json_encode( $changed ) ) );
	}

	return array(
		'id'      => $id,
		'url'     => get_permalink( $id ),
		'seo'     => $seo['name'],
		'before'  => $before,
		'after'   => $after,
		'changed' => $changed,
		'dry_run' => $dry,
	);
}

function fourall_seo_bridge_alt( WP_REST_Request $req ) {
	$url = esc_url_raw( (string) $req->get_param( 'url' ) );
	$id  = attachment_url_to_postid( $url );
	if ( ! $id ) {
		// Retry without a size suffix (e.g. -300x200 / -1024x683).
		$stripped = preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
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
	$val     = sanitize_text_field( (string) $req->get_param( 'alt' ) );
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

/** Minimal audit line (only when WP_DEBUG logging is on). */
function fourall_seo_bridge_log( $msg ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$user = wp_get_current_user();
		error_log( '[4all-seo-bridge] ' . ( $user ? $user->user_login : '?' ) . ' ' . $msg );
	}
}
