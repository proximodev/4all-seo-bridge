<?php
/**
 * Stub-based tests for 4all-seo-bridge.php. No WordPress needed.
 *
 *   php tests/run.php            # SEO plugin: yoast (default)
 *   SEO=rankmath php tests/run.php
 *   SEO=none php tests/run.php
 *
 * Stubs a fake site (home https://example.com, a few posts, an attachment,
 * a static front page) and the WordPress functions the plugin calls, then
 * loads the plugin and drives its handlers and filters directly. Exit 1
 * on any failure.
 */

declare(strict_types=1);

$seo_mode = getenv( 'SEO' ) ?: 'yoast';
if ( 'yoast' === $seo_mode ) {
	define( 'WPSEO_VERSION', '22.0' );
} elseif ( 'rankmath' === $seo_mode ) {
	class RankMath {}
}

// ---------- fake site state ----------
$GLOBALS['site'] = array(
	'home'     => 'https://example.com',
	'options'  => array( 'show_on_front' => 'page', 'page_on_front' => 2 ),
	'posts'    => array(
		1  => array( 'post_title' => 'Hello', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'hello', 'url' => 'https://example.com/hello/' ),
		2  => array( 'post_title' => 'Home', 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'home', 'url' => 'https://example.com/' ),
		3  => array( 'post_title' => 'About', 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'about', 'url' => 'https://example.com/company/about/' ),
		7  => array( 'post_title' => 'Draft', 'post_type' => 'post', 'post_status' => 'draft', 'post_name' => '', 'url' => 'https://example.com/?p=7' ),
		9  => array( 'post_title' => 'img', 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_name' => 'img', 'url' => 'https://example.com/img/' ),
	),
	'permalinks' => array(   // url_to_postid table (same-host only)
		'https://example.com/hello/'         => 1,
		'https://example.com/hello'          => 1,
		'https://example.com/company/about/' => 3,
	),
	'attachments' => array( 'https://example.com/wp-content/uploads/2024/01/photo.jpg' => 9 ),
	'meta'        => array(),
	'transients'  => array(),
	'caps'        => array( 'edit_posts' => true, 'update_plugins' => true, 'edit_post' => array() ), // edit_post: id => bool (default true)
	'http'        => null, // callable(url) => array|WP_Error
	'http_calls'  => 0,
);

// ---------- minimal WP stubs ----------
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

class WP_REST_Request {
	private $params;
	public function __construct( array $params = array() ) { $this->params = $params; }
	public function get_param( $k ) { return array_key_exists( $k, $this->params ) ? $this->params[ $k ] : null; }
}

$GLOBALS['hooks'] = array();
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = $cb; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = $cb; }
function register_activation_hook( $file, $cb ) { $GLOBALS['hooks']['activate'][] = $cb; }
$GLOBALS['routes'] = array();
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][] = array( $ns, $route, $args ); }

function current_user_can( $cap, $id = null ) {
	$caps = $GLOBALS['site']['caps'];
	if ( 'edit_posts' === $cap ) { return $caps['edit_posts']; }
	if ( 'update_plugins' === $cap ) { return $caps['update_plugins']; }
	if ( 'edit_post' === $cap ) { return array_key_exists( $id, $caps['edit_post'] ) ? $caps['edit_post'][ $id ] : true; }
	return false;
}
function get_post( $id ) {
	$p = $GLOBALS['site']['posts'][ (int) $id ] ?? null;
	return $p ? (object) $p : null;
}
function get_permalink( $id ) { return $GLOBALS['site']['posts'][ (int) $id ]['url'] ?? false; }
function get_option( $k ) { return $GLOBALS['site']['options'][ $k ] ?? false; }
function home_url( $path = '' ) { return $GLOBALS['site']['home'] . ( $path ? '/' . ltrim( $path, '/' ) : '' ); }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }
function url_to_postid( $url ) {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	if ( $host !== strtolower( (string) parse_url( $GLOBALS['site']['home'], PHP_URL_HOST ) ) ) { return 0; }
	return $GLOBALS['site']['permalinks'][ $url ] ?? 0;
}
function attachment_url_to_postid( $url ) { return $GLOBALS['site']['attachments'][ $url ] ?? 0; }
function wp_check_invalid_utf8( $s ) { return preg_match( '//u', $s ) ? $s : ''; }
function wp_strip_all_tags( $s, $remove_breaks = false ) {
	$s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $s );
	$s = strip_tags( $s );
	if ( $remove_breaks ) { $s = preg_replace( '/[\r\n\t ]+/', ' ', $s ); }
	return trim( $s );
}
function esc_url_raw( $u ) { return trim( (string) $u ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['site']['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $val ) {
	$val = wp_unslash( $val );
	if ( ! empty( $GLOBALS['site']['meta_sanitizer'] ) ) { $val = call_user_func( $GLOBALS['site']['meta_sanitizer'], $key, $val ); }
	$GLOBALS['site']['meta'][ $id ][ $key ] = $val;
	return true;
}
function wp_slash( $v ) { return addslashes( $v ); }
function wp_unslash( $v ) { return stripslashes( $v ); }
function wp_get_current_user() { return (object) array( 'user_login' => 'tester' ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_site_transient( $k ) { $t = $GLOBALS['site']['transients'][ $k ] ?? null; return null === $t ? false : $t['v']; }
function set_site_transient( $k, $v, $ttl = 0 ) { $GLOBALS['site']['transients'][ $k ] = array( 'v' => $v, 'ttl' => $ttl ); return true; }
function delete_site_transient( $k ) { unset( $GLOBALS['site']['transients'][ $k ] ); return true; }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['site']['http_calls']++; return call_user_func( $GLOBALS['site']['http'], $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? ''; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function plugin_basename( $file ) { return '4all-seo-bridge/' . basename( $file ); }

// capture error_log
$GLOBALS['logfile'] = tempnam( sys_get_temp_dir(), 'bridgelog' );
ini_set( 'error_log', $GLOBALS['logfile'] );
function log_lines() { return array_values( array_filter( array_map( 'trim', file( $GLOBALS['logfile'] ) ) ) ); }

// ---------- load plugin ----------
require dirname( __DIR__ ) . '/4all-seo-bridge.php';
foreach ( $GLOBALS['hooks']['rest_api_init'] as $cb ) { $cb(); }

// ---------- tiny assert ----------
$fails = 0; $count = 0;
function ok( $cond, $msg ) {
	global $fails, $count; $count++;
	if ( $cond ) { echo "  ok   $msg\n"; } else { $fails++; echo "  FAIL $msg\n"; }
}
function same( $exp, $got, $msg ) {
	ok( $exp === $got, $msg . ( $exp === $got ? '' : "\n         expected " . json_encode( $exp ) . "\n         got      " . json_encode( $got ) ) );
}
function err_code( $x ) { return is_wp_error( $x ) ? $x->get_error_code() : null; }
function status( $x ) { return is_wp_error( $x ) ? ( $x->data['status'] ?? null ) : null; }

echo "SEO mode: $seo_mode\n";

// ---------- routes ----------
echo "\nroutes\n";
same( 4, count( $GLOBALS['routes'] ), 'four endpoints registered' );
foreach ( $GLOBALS['routes'] as $r ) {
	ok( '4all/v1' === $r[0] && 'fourall_seo_bridge_can_edit' === $r[2]['permission_callback'] && is_callable( $r[2]['callback'] ), "route {$r[2]['methods']} {$r[1]} gated by can_edit, callback exists" );
}

// ---------- seo plugin detection ----------
echo "\nseo plugin detection\n";
$seo = fourall_seo_bridge_seo_plugin();
if ( 'yoast' === $seo_mode ) { same( array( 'name' => 'yoast', 'title' => '_yoast_wpseo_title', 'metadesc' => '_yoast_wpseo_metadesc' ), $seo, 'yoast keys' ); }
elseif ( 'rankmath' === $seo_mode ) { same( array( 'name' => 'rankmath', 'title' => 'rank_math_title', 'metadesc' => 'rank_math_description' ), $seo, 'rankmath keys' ); }
else { same( null, $seo, 'no seo plugin → null' ); }

// ---------- resolve ----------
echo "\nresolve\n";
same( array( 1, 'permalink' ), fourall_seo_bridge_resolve( 'https://example.com/hello/' ), 'published permalink' );
same( array( 1, 'permalink' ), fourall_seo_bridge_resolve( 'https://example.com/hello/#section' ), 'fragment stripped' );
same( array( 3, 'permalink' ), fourall_seo_bridge_resolve( 'https://example.com/company/about/' ), 'nested page' );
same( array( 7, 'query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?p=7' ), '?p= draft' );
same( array( 3, 'query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?page_id=3' ), '?page_id=' );
same( array( 9, 'query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?attachment_id=9' ), '?attachment_id=' );
same( array( 7, 'query_var' ), fourall_seo_bridge_resolve( 'https://staging.example.com/?p=7&preview=true' ), '?p= on another host still wins' );
same( array( 0, 'not_found' ), fourall_seo_bridge_resolve( 'https://example.com/?p=404' ), '?p= unknown id → not_found' );
same( array( 0, 'bad_query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?p=abc' ), '?p= non-numeric → bad_query_var, never the front page' );
same( array( 0, 'bad_query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?p=' ), '?p= empty → bad_query_var' );
same( array( 0, 'bad_query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?p=0' ), '?p=0 → bad_query_var' );
same( array( 0, 'bad_query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?p=-7' ), '?p=-7 → bad_query_var' );
same( array( 0, 'bad_query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?p=7abc' ), '?p=7abc → bad_query_var' );
same( array( 0, 'bad_query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?p[]=7' ), '?p[]=7 (array) → bad_query_var' );
same( array( 0, 'bad_query_var' ), fourall_seo_bridge_resolve( 'https://example.com/hello/?p=abc' ), '?p= bad on a real permalink still errors (query var is authoritative)' );
same( array( 0, 'bad_query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?page_id=abc' ), '?page_id= non-numeric → bad_query_var' );
same( array( 7, 'query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?p=%207%20' ), '?p= with stray spaces still resolves' );
same( array( 1, 'query_var' ), fourall_seo_bridge_resolve( 'https://example.com/?p=1' ), '?p= on a published post resolves too' );
same( array( 2, 'front_page' ), fourall_seo_bridge_resolve( 'https://example.com/?utm_source=x' ), 'root with unrelated params → front page' );
same( array( 2, 'front_page' ), fourall_seo_bridge_resolve( 'https://example.com/' ), 'empty path → static front page' );
same( array( 2, 'front_page' ), fourall_seo_bridge_resolve( 'https://example.com' ), 'no path at all → static front page' );
same( array( 3, 'path_match' ), fourall_seo_bridge_resolve( 'https://www.production.com/company/about/' ), 'other host → path rebuilt on home_url' );
same( array( 0, 'not_found' ), fourall_seo_bridge_resolve( 'https://www.production.com/nope/' ), 'other host, path miss' );
same( array( 0, 'not_found' ), fourall_seo_bridge_resolve( 'https://example.com/nope/' ), 'same host miss stays a miss' );
$GLOBALS['site']['options'] = array( 'show_on_front' => 'posts' );
same( array( 0, 'blog_index_not_supported' ), fourall_seo_bridge_resolve( 'https://example.com/' ), 'posts-index front → blog_index_not_supported' );
$GLOBALS['site']['options'] = array( 'show_on_front' => 'page', 'page_on_front' => 2 );

// ---------- clean ----------
echo "\nclean\n";
same( 'Hello World', fourall_seo_bridge_clean( "  Hello \n\t World  " ), 'collapses whitespace, trims' );
same( 'Hello World', fourall_seo_bridge_clean( "Hello\xC2\xA0World" ), 'nbsp collapsed to space' );
same( 'bold text', fourall_seo_bridge_clean( '<b>bold</b> text' ), 'tags stripped' );
same( 'text', fourall_seo_bridge_clean( '<script>alert(1)</script>text' ), 'script block removed with content' );
same( 'a < b', fourall_seo_bridge_clean( 'a < b' ), 'literal "<" followed by space survives' );
same( '50% off', fourall_seo_bridge_clean( '50% off' ), 'percent survives' );
same( 'Tom & Jerry', fourall_seo_bridge_clean( 'Tom & Jerry' ), 'ampersand survives' );
same( 'Café – naïve', fourall_seo_bridge_clean( 'Café – naïve' ), 'unicode kept' );
same( 'ab', fourall_seo_bridge_clean( "a\x00\x07b" ), 'control chars removed' );
same( '', fourall_seo_bridge_clean( "\xff\xfe bad" ), 'invalid utf-8 → empty' );
same( '', fourall_seo_bridge_clean( null ), 'null → empty' );
// Documented behaviour to know about (strip_tags): "<" directly followed by
// a non-space char is treated as a tag start and eats to the end.
same( '5', fourall_seo_bridge_clean( '5<10' ), 'KNOWN: "5<10" loses "<10" (strip_tags)' );

// ---------- target ----------
echo "\ntarget\n";
same( 1, fourall_seo_bridge_target( new WP_REST_Request( array( 'id' => 1 ) ) ), 'id wins' );
same( 1, fourall_seo_bridge_target( new WP_REST_Request( array( 'id' => 1, 'url' => 'https://example.com/company/about/' ) ) ), 'id wins over url' );
same( 'not_found', err_code( fourall_seo_bridge_target( new WP_REST_Request( array( 'id' => 999 ) ) ) ), 'unknown id → not_found' );
same( 404, status( fourall_seo_bridge_target( new WP_REST_Request( array( 'id' => 999 ) ) ) ), '… with 404' );
same( 'missing_target', err_code( fourall_seo_bridge_target( new WP_REST_Request( array() ) ) ), 'neither → missing_target' );
same( 400, status( fourall_seo_bridge_target( new WP_REST_Request( array() ) ) ), '… with 400' );
same( 'missing_target', err_code( fourall_seo_bridge_target( new WP_REST_Request( array( 'id' => 0, 'url' => '' ) ) ) ), 'id=0 and empty url → missing_target' );
same( 3, fourall_seo_bridge_target( new WP_REST_Request( array( 'url' => 'https://example.com/company/about/' ) ) ), 'url resolves' );
same( 'not_found', err_code( fourall_seo_bridge_target( new WP_REST_Request( array( 'url' => 'https://example.com/nope/' ) ) ) ), 'url miss → not_found' );
$e = fourall_seo_bridge_target( new WP_REST_Request( array( 'url' => 'https://example.com/?p=abc' ) ) );
same( 'bad_query_var', err_code( $e ), '?p=abc → bad_query_var' );
same( 400, status( $e ), '… with 400' );
$e = fourall_seo_bridge_target( new WP_REST_Request( array( 'url' => 'https://example.com/?p=404' ) ) );
same( 'not_found', err_code( $e ), '?p=404 → not_found' );
same( 404, status( $e ), '… with 404' );
$GLOBALS['site']['options'] = array( 'show_on_front' => 'posts' );
$e = fourall_seo_bridge_target( new WP_REST_Request( array( 'url' => 'https://example.com/' ) ) );
same( 'blog_index_not_supported', err_code( $e ), 'posts-index front → blog_index_not_supported' );
same( 422, status( $e ), '… with 422' );
$GLOBALS['site']['options'] = array( 'show_on_front' => 'page', 'page_on_front' => 2 );
$GLOBALS['site']['caps']['edit_post'][3] = false;
$e = fourall_seo_bridge_target( new WP_REST_Request( array( 'id' => 3 ) ) );
same( 'cannot_edit', err_code( $e ), 'per-post cap denied → cannot_edit' );
same( 403, status( $e ), '… with 403' );
$GLOBALS['site']['caps']['edit_post'] = array();

// ---------- ping ----------
echo "\nping\n";
$p = fourall_seo_bridge_ping();
same( FOURALL_SEO_BRIDGE_VERSION, $p['version'], 'version reported' );
same( 'none' === $seo_mode ? null : $seo_mode, $p['seo_plugin'], 'seo_plugin reported' );
same( array( 'seo_read', 'resolve_query_vars', 'resolve_path_match', 'post_facts' ), $p['capabilities'], 'capabilities list' );
same( 'tester', $p['user'], 'user login' );
ok( true === $p['ok'] && true === $p['can_edit'], 'ok / can_edit' );

// ---------- GET /seo ----------
echo "\nGET /seo\n";
if ( $seo ) { $GLOBALS['site']['meta'][1] = array( $seo['title'] => 'Old title', $seo['metadesc'] => 'Old desc' ); }
$r = fourall_seo_bridge_seo_read( new WP_REST_Request( array( 'url' => 'https://example.com/hello/' ) ) );
same( 1, $r['id'], 'id' );
same( $seo ? $seo['name'] : null, $r['seo'], 'seo name' );
same( $seo ? array( 'title' => 'Old title', 'metadesc' => 'Old desc' ) : array( 'title' => '', 'metadesc' => '' ), $r['current'], 'current values' );
same( 'Hello', $r['post_title'], 'post facts: post_title' );
same( 'post', $r['post_type'], 'post facts: post_type' );
same( 'publish', $r['post_status'], 'post facts: post_status' );
same( 'hello', $r['slug'], 'post facts: slug' );
same( 'https://example.com/hello/', $r['url'], 'post facts: url' );
same( 'not_found', err_code( fourall_seo_bridge_seo_read( new WP_REST_Request( array( 'url' => 'https://example.com/nope/' ) ) ) ), 'miss → not_found' );
$r = fourall_seo_bridge_seo_read( new WP_REST_Request( array( 'id' => 7 ) ) );
same( 'draft', $r['post_status'], 'draft readable by id' );
same( array(), $GLOBALS['site']['meta'][7] ?? array(), 'GET never writes' );

// ---------- POST /seo ----------
echo "\nPOST /seo\n";
$before_log = count( log_lines() );
if ( ! $seo ) {
	$e = fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'title' => 'x' ) ) );
	same( 'no_seo_plugin', err_code( $e ), 'no seo plugin → no_seo_plugin' );
	same( 400, status( $e ), '… with 400' );
} else {
	// dry run
	$r = fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'title' => '  New <b>title</b> ', 'dry_run' => true ) ) );
	same( array( 'title' => 'Old title', 'metadesc' => 'Old desc' ), $r['before'], 'dry: before' );
	same( array( 'title' => 'New title', 'metadesc' => 'Old desc' ), $r['after'], 'dry: after (cleaned, metadesc untouched)' );
	same( array( 'title' => true, 'metadesc' => false ), $r['changed'], 'dry: changed flags' );
	same( true, $r['dry_run'], 'dry: flag echoed' );
	same( 'Old title', $GLOBALS['site']['meta'][1][ $seo['title'] ], 'dry: meta NOT written' );
	same( $before_log, count( log_lines() ), 'dry: nothing logged' );
	same( 'Hello', $r['post_title'], 'dry: post facts present' );

	// apply
	$r = fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'title' => 'New title', 'metadesc' => "Desc with \"quotes\" and O'Neil" ) ) );
	same( array( 'title' => true, 'metadesc' => true ), $r['changed'], 'apply: both changed' );
	same( 'New title', $GLOBALS['site']['meta'][1][ $seo['title'] ], 'apply: title written' );
	same( "Desc with \"quotes\" and O'Neil", $GLOBALS['site']['meta'][1][ $seo['metadesc'] ], 'apply: quotes survive slash/unslash round trip' );
	same( false, $r['dry_run'], 'apply: dry_run false' );
	$lines = log_lines();
	same( $before_log + 1, count( $lines ), 'apply: one audit line' );
	ok( false !== strpos( end( $lines ), '[4all-seo-bridge] tester seo #1 (' . $seo['name'] . ') {"title":true,"metadesc":true}' ), 'apply: audit line content' );

	// idempotent
	$r = fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'title' => 'New title' ) ) );
	same( array( 'title' => false, 'metadesc' => false ), $r['changed'], 'same value again → unchanged' );
	same( $before_log + 1, count( log_lines() ), 'unchanged → no new log line' );

	// omitted vs empty
	$r = fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'metadesc' => '' ) ) );
	same( array( 'title' => false, 'metadesc' => true ), $r['changed'], 'empty string clears metadesc; omitted title untouched' );
	same( '', $GLOBALS['site']['meta'][1][ $seo['metadesc'] ], 'metadesc cleared' );
	same( 'New title', $GLOBALS['site']['meta'][1][ $seo['title'] ], 'title still there' );

	// SEO plugin sanitizer escapes on its keys (Yoast does this): after = stored, repeat push = unchanged
	$GLOBALS['site']['meta_sanitizer'] = function ( $key, $val ) { return 0 === strpos( $key, '_yoast' ) || 0 === strpos( $key, 'rank_math' ) ? htmlspecialchars( $val, ENT_NOQUOTES ) : $val; };
	$r = fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'metadesc' => 'a < b & c' ) ) );
	same( true, $r['changed']['metadesc'], 'escaping sanitizer: first push changed' );
	same( 'a &lt; b &amp; c', $r['after']['metadesc'], 'escaping sanitizer: after reports the stored (escaped) value' );
	$r = fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'metadesc' => 'a < b & c' ) ) );
	same( false, $r['changed']['metadesc'], 'escaping sanitizer: same value again -> unchanged (entity-decoded compare)' );
	same( 'a &lt; b &amp; c', $r['after']['metadesc'], '... after still the stored value' );
	$r = fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'metadesc' => 'a < b & c', 'dry_run' => true ) ) );
	same( false, $r['changed']['metadesc'], 'escaping sanitizer: dry run agrees it is unchanged' );
	$r = fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'metadesc' => 'plain' ) ) );
	same( 'plain', $r['after']['metadesc'], 'escaping sanitizer: unescaped value round-trips' );
	$GLOBALS['site']['meta_sanitizer'] = null;
	$r = fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'metadesc' => '' ) ) );

	// draft via ?p=
	$r = fourall_seo_bridge_seo( new WP_REST_Request( array( 'url' => 'https://example.com/?p=7', 'title' => 'Draft title' ) ) );
	same( 7, $r['id'], 'draft by ?p= targeted' );
	same( 'Draft title', $GLOBALS['site']['meta'][7][ $seo['title'] ], 'draft title written' );

	// errors pass through
	same( 'not_found', err_code( fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 999, 'title' => 'x' ) ) ) ), 'unknown id → not_found' );
	$GLOBALS['site']['caps']['edit_post'][1] = false;
	same( 'cannot_edit', err_code( fourall_seo_bridge_seo( new WP_REST_Request( array( 'id' => 1, 'title' => 'x' ) ) ) ), 'cap denied → cannot_edit' );
	$GLOBALS['site']['caps']['edit_post'] = array();
}

// ---------- POST /alt ----------
echo "\nPOST /alt\n";
$img = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';
$r = fourall_seo_bridge_alt( new WP_REST_Request( array( 'url' => $img, 'alt' => ' A  photo ', 'dry_run' => true ) ) );
same( 9, $r['id'], 'attachment resolved' );
same( array( 'alt' => 'A photo' ), $r['after'], 'dry: cleaned' );
same( '', $GLOBALS['site']['meta'][9]['_wp_attachment_image_alt'] ?? '', 'dry: not written' );
foreach ( array( '-300x200', '-scaled', '-rotated', '-1024x768' ) as $suf ) {
	$u = str_replace( '.jpg', $suf . '.jpg', $img );
	$r = fourall_seo_bridge_alt( new WP_REST_Request( array( 'url' => $u, 'alt' => 'x', 'dry_run' => true ) ) );
	same( 9, $r['id'] ?? null, "suffix $suf stripped" );
}
$r = fourall_seo_bridge_alt( new WP_REST_Request( array( 'url' => str_replace( '.jpg', '-300X200.JPG', $img ), 'alt' => 'x', 'dry_run' => true ) ) );
same( 'not_found', err_code( $r ), 'case: "-300X200.JPG" strips but ".JPG" attachment does not exist (expected miss)' );
$r = fourall_seo_bridge_alt( new WP_REST_Request( array( 'url' => $img, 'alt' => 'A photo' ) ) );
same( array( 'alt' => true ), $r['changed'], 'apply: changed' );
same( 'A photo', $GLOBALS['site']['meta'][9]['_wp_attachment_image_alt'], 'apply: written' );
$lines_alt = log_lines();
ok( false !== strpos( end( $lines_alt ), 'tester alt #9' ), 'apply: audit line' );
$r = fourall_seo_bridge_alt( new WP_REST_Request( array( 'url' => $img, 'alt' => 'A photo' ) ) );
same( array( 'alt' => false ), $r['changed'], 'same alt → unchanged' );
same( 'not_found', err_code( fourall_seo_bridge_alt( new WP_REST_Request( array( 'url' => 'https://example.com/wp-content/uploads/nope.jpg', 'alt' => 'x' ) ) ) ), 'unknown → not_found' );
$GLOBALS['site']['caps']['edit_post'][9] = false;
same( 'cannot_edit', err_code( fourall_seo_bridge_alt( new WP_REST_Request( array( 'url' => $img, 'alt' => 'x' ) ) ) ), 'cap denied → cannot_edit' );
$GLOBALS['site']['caps']['edit_post'] = array();

// ---------- manifest / update ----------
echo "\nmanifest + update check\n";
$good = array( 'name' => '4All SEO Bridge', 'slug' => '4all-seo-bridge', 'version' => '9.9.9', 'download_url' => 'https://github.com/proximodev/4all-seo-bridge/releases/download/v9.9.9/4all-seo-bridge.zip', 'requires' => '6.0', 'requires_php' => '7.4', 'tested' => '6.6', 'last_updated' => '2026-09-12', 'homepage' => 'https://github.com/proximodev/4all-seo-bridge', 'changelog_url' => 'https://github.com/proximodev/4all-seo-bridge/blob/main/CHANGELOG.md' );
$GLOBALS['site']['http'] = function ( $url ) use ( $good ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $good ) ); };
$GLOBALS['site']['http_calls'] = 0;
$m = fourall_seo_bridge_manifest();
same( $good, $m, 'manifest fetched and decoded' );
same( 1, $GLOBALS['site']['http_calls'], 'one HTTP call' );
same( 12 * 3600, $GLOBALS['site']['transients']['fourall_seo_bridge_manifest']['ttl'], 'cached 12h' );
fourall_seo_bridge_manifest();
same( 1, $GLOBALS['site']['http_calls'], 'second call served from cache' );
fourall_seo_bridge_manifest( true );
same( 2, $GLOBALS['site']['http_calls'], 'force refetches' );

$plugin_file = '4all-seo-bridge/4all-seo-bridge.php';
$plugin_data = array( 'Version' => FOURALL_SEO_BRIDGE_VERSION, 'UpdateURI' => 'https://github.com/proximodev/4all-seo-bridge' );
same( false, fourall_seo_bridge_update_check( false, $plugin_data, 'other/other.php', array() ), 'other plugin untouched' );
$u = fourall_seo_bridge_update_check( false, $plugin_data, $plugin_file, array() );
same( '9.9.9', $u['version'], 'update: version from manifest' );
same( '4all-seo-bridge', $u['slug'], 'update: slug' );
same( $plugin_file, $u['plugin'], 'update: plugin file' );
same( $good['download_url'], $u['package'], 'update: package' );
// emulate core's wp_update_plugins() consumer (wp-includes/update.php)
same( 'github.com', wp_parse_url( $plugin_data['UpdateURI'], PHP_URL_HOST ), 'core would ask update_plugins_github.com' );
ok( isset( $GLOBALS['hooks']['update_plugins_github.com'] ), 'filter registered on that hostname' );
$obj = (object) $u; $obj->new_version = $obj->version;
ok( version_compare( $plugin_data['Version'], $obj->version, '<' ), 'core would list it as an available update' );

// manifest failure paths
$GLOBALS['site']['http'] = function () { return new WP_Error( 'http_request_failed', 'nope' ); };
delete_site_transient( 'fourall_seo_bridge_manifest' );
same( null, fourall_seo_bridge_manifest(), 'WP_Error → null' );
same( array(), get_site_transient( 'fourall_seo_bridge_manifest' ), '… backoff sentinel cached' );
same( 3600, $GLOBALS['site']['transients']['fourall_seo_bridge_manifest']['ttl'], '… for 1h' );
$calls = $GLOBALS['site']['http_calls'];
same( array(), fourall_seo_bridge_manifest(), 'during backoff returns cached empty array (falsy)' );
same( $calls, $GLOBALS['site']['http_calls'], '… without an HTTP call' );
same( false, fourall_seo_bridge_update_check( false, $plugin_data, $plugin_file, array() ), 'update check passes through during backoff' );
foreach ( array( array( 404, '{}' ), array( 200, 'not json' ), array( 200, '{"version":"1.0"}' ), array( 200, '{"download_url":"x"}' ), array( 200, '[]' ) ) as $case ) {
	$GLOBALS['site']['http'] = function () use ( $case ) { return array( 'response' => array( 'code' => $case[0] ), 'body' => $case[1] ); };
	delete_site_transient( 'fourall_seo_bridge_manifest' );
	same( null, fourall_seo_bridge_manifest(), "bad manifest ({$case[0]}, {$case[1]}) → null" );
}

// plugin_information panel
$GLOBALS['site']['http'] = function () use ( $good ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $good ) ); };
delete_site_transient( 'fourall_seo_bridge_manifest' );
same( false, fourall_seo_bridge_plugin_info( false, 'plugin_information', (object) array( 'slug' => 'other' ) ), 'info: other slug untouched' );
same( false, fourall_seo_bridge_plugin_info( false, 'query_plugins', (object) array( 'slug' => '4all-seo-bridge' ) ), 'info: other action untouched' );
same( false, fourall_seo_bridge_plugin_info( false, 'plugin_information', (object) array() ), 'info: no slug untouched' );
$i = fourall_seo_bridge_plugin_info( false, 'plugin_information', (object) array( 'slug' => '4all-seo-bridge' ) );
ok( is_object( $i ) && '9.9.9' === $i->version && $good['download_url'] === $i->download_link, 'info: panel built from manifest' );
ok( false !== strpos( $i->sections['changelog'], 'href="https://github.com/proximodev/4all-seo-bridge/blob/main/CHANGELOG.md"' ), 'info: changelog link' );

// auto-update + cache invalidation
same( true, fourall_seo_bridge_auto_update( false, (object) array( 'plugin' => $plugin_file ) ), 'auto-update on for this plugin (default)' );
same( false, fourall_seo_bridge_auto_update( false, (object) array( 'plugin' => 'other/other.php' ) ), 'other plugin: passthrough false' );
same( true, fourall_seo_bridge_auto_update( true, (object) array( 'plugin' => 'other/other.php' ) ), 'other plugin: passthrough true' );
same( false, fourall_seo_bridge_auto_update( false, (object) array() ), 'item without plugin: passthrough' );
ok( isset( $GLOBALS['site']['transients']['fourall_seo_bridge_manifest'] ), 'manifest cached before activation' );
foreach ( $GLOBALS['hooks']['activate'] as $cb ) { $cb(); }
ok( ! isset( $GLOBALS['site']['transients']['fourall_seo_bridge_manifest'] ), 'activation clears manifest cache' );
fourall_seo_bridge_manifest();
foreach ( $GLOBALS['hooks']['upgrader_process_complete'] as $cb ) { $cb( null, array( 'type' => 'theme' ) ); }
ok( isset( $GLOBALS['site']['transients']['fourall_seo_bridge_manifest'] ), 'theme upgrade leaves cache' );
foreach ( $GLOBALS['hooks']['upgrader_process_complete'] as $cb ) { $cb( null, array( 'type' => 'plugin' ) ); }
ok( ! isset( $GLOBALS['site']['transients']['fourall_seo_bridge_manifest'] ), 'plugin upgrade clears cache' );

// Dashboard → Updates → Check again
echo "
force check
";
ok( isset( $GLOBALS['hooks']['load-update-core.php'] ), 'hooked on load-update-core.php' );
$run_update_core = function () { foreach ( $GLOBALS['hooks']['load-update-core.php'] as $cb ) { $cb(); } };
$calls = $GLOBALS['site']['http_calls'];
fourall_seo_bridge_manifest();
$_GET = array();
$run_update_core();
ok( isset( $GLOBALS['site']['transients']['fourall_seo_bridge_manifest'] ), 'plain Updates screen visit leaves cache' );
$_GET = array( 'force-check' => '1' );
$GLOBALS['site']['caps']['update_plugins'] = false;
$run_update_core();
ok( isset( $GLOBALS['site']['transients']['fourall_seo_bridge_manifest'] ), 'force-check without update_plugins cap leaves cache' );
$GLOBALS['site']['caps']['update_plugins'] = true;
$run_update_core();
ok( ! isset( $GLOBALS['site']['transients']['fourall_seo_bridge_manifest'] ), 'Check again clears cache' );
same( $calls + 1, $GLOBALS['site']['http_calls'], '… and the clear itself made no HTTP call' );
fourall_seo_bridge_update_check( false, $plugin_data, $plugin_file, array() );
same( $calls + 2, $GLOBALS['site']['http_calls'], '… so the core check on that page load refetches' );
// during back-off, Check again also retries
$GLOBALS['site']['http'] = function () { return new WP_Error( 'http_request_failed', 'nope' ); };
delete_site_transient( 'fourall_seo_bridge_manifest' );
fourall_seo_bridge_manifest();
same( array(), get_site_transient( 'fourall_seo_bridge_manifest' ), 'back-off sentinel in place' );
$GLOBALS['site']['http'] = function () use ( $good ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $good ) ); };
$run_update_core();
same( $good, fourall_seo_bridge_manifest(), 'Check again skips the 1h back-off and refetches' );
$_GET = array();

// ---------- done ----------
@unlink( $GLOBALS['logfile'] );
echo "\n$count assertions, $fails failed\n";
exit( $fails ? 1 : 0 );
