<?php
/**
 * Test bootstrap: a small WordPress stand-in. The database is real (SQLite in memory) so the
 * payment queries, including the atomic status transitions, run as SQL.
 *
 * @package Bachs_Payment_Forms
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
define( 'BACHS_PF_VERSION', 'test' );
define( 'BACHS_PF_DB_VERSION', '1' );
define( 'BACHS_PF_MAIN_FILE', dirname( __DIR__ ) . '/bachs-payment-forms.php' );
define( 'BACHS_PF_PATH', dirname( __DIR__ ) );
define( 'BACHS_PF_URL', 'https://site.example/wp-content/plugins/bachs-payment-forms' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/* ---------------------------------------------------------------- wpdb on SQLite */

class Test_WPDB {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $last_error = '';
	/** @var PDO */
	public $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec(
			"CREATE TABLE wp_bachs_pf_payments (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				form_id INTEGER NOT NULL DEFAULT 0,
				parent_id INTEGER NOT NULL DEFAULT 0,
				type TEXT NOT NULL DEFAULT 'one_time',
				status TEXT NOT NULL DEFAULT 'pending',
				mode TEXT NOT NULL DEFAULT 'sandbox',
				reference TEXT NOT NULL UNIQUE,
				access_key TEXT NOT NULL DEFAULT '',
				checkout_id TEXT NOT NULL DEFAULT '',
				attempts INTEGER NOT NULL DEFAULT 0,
				charge_id TEXT NOT NULL DEFAULT '',
				subscription_id TEXT NOT NULL DEFAULT '',
				subscription_status TEXT NOT NULL DEFAULT '',
				customer_id TEXT NOT NULL DEFAULT '',
				user_id INTEGER NOT NULL DEFAULT 0,
				email TEXT NOT NULL DEFAULT '',
				name TEXT NOT NULL DEFAULT '',
				currency TEXT NOT NULL DEFAULT '',
				amount NUMERIC NOT NULL DEFAULT 0,
				quantity INTEGER NOT NULL DEFAULT 1,
				cadence TEXT NOT NULL DEFAULT '',
				fields TEXT NULL,
				page_url TEXT NOT NULL DEFAULT '',
				paid_amount TEXT NOT NULL DEFAULT '',
				paid_currency TEXT NOT NULL DEFAULT '',
				settlement_amount TEXT NOT NULL DEFAULT '',
				settlement_currency TEXT NOT NULL DEFAULT '',
				review_reason TEXT NULL,
				event_ids TEXT NULL,
				invoice_ids TEXT NULL,
				created_at TEXT NOT NULL,
				updated_at TEXT NOT NULL,
				paid_at TEXT NULL
			)"
		);
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$pdo = $this->pdo;
		return preg_replace_callback(
			'/%[isdf]/',
			function ( $m ) use ( &$i, $args, $pdo ) {
				$value = $args[ $i++ ];
				switch ( $m[0] ) {
					case '%i':
						return '`' . str_replace( '`', '``', $value ) . '`';
					case '%d':
						return (string) (int) $value;
					case '%f':
						return (string) (float) $value;
					default:
						return $pdo->quote( (string) $value );
				}
			},
			$query
		);
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate() {
		return '';
	}

	public function insert( $table, $data ) {
		$cols = array_keys( $data );
		$sql  = 'INSERT INTO `' . $table . '` (`' . implode( '`,`', $cols ) . '`) VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')';
		try {
			$stmt = $this->pdo->prepare( $sql );
			$stmt->execute( array_values( $data ) );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function update( $table, $data, $where ) {
		$sets = array();
		foreach ( array_keys( $data ) as $col ) {
			$sets[] = '`' . $col . '` = ?';
		}
		$conds = array();
		foreach ( array_keys( $where ) as $col ) {
			$conds[] = '`' . $col . '` = ?';
		}
		$stmt = $this->pdo->prepare( 'UPDATE `' . $table . '` SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $conds ) );
		$stmt->execute( array_merge( array_values( $data ), array_values( $where ) ) );
		return $stmt->rowCount();
	}

	public function query( $sql ) {
		return $this->pdo->exec( $sql );
	}

	public function get_results( $sql ) {
		return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_OBJ );
	}

	public function get_row( $sql ) {
		$row = $this->pdo->query( $sql )->fetch( PDO::FETCH_OBJ );
		return $row ? $row : null;
	}

	public function get_var( $sql ) {
		$value = $this->pdo->query( $sql )->fetchColumn();
		return false === $value ? null : $value;
	}
}

/* ---------------------------------------------------------------- test state */

$GLOBALS['T'] = array();

function t_reset() {
	global $wpdb;
	$wpdb         = new Test_WPDB();
	$GLOBALS['T'] = array(
		'options'   => array(),
		'posts'     => array(),
		'post_meta' => array(),
		'responses' => array(),
		'requests'  => array(),
		'mail'      => array(),
		'actions'   => array(),
		'user_id'   => 0,
		'logs'      => array(),
	);
	if ( class_exists( 'Bachs_PF_API' ) ) {
		Bachs_PF_API::reset();
	}
}

function t_queue( $code, $body ) {
	$GLOBALS['T']['responses'][] = array(
		'response' => array( 'code' => $code ),
		'body'     => is_string( $body ) ? $body : json_encode( $body ),
		'headers'  => array(),
	);
}

/* ---------------------------------------------------------------- WordPress functions */

function __( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html_e( $t, $d = null ) { echo $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return (string) $u; }
function esc_url_raw( $u, $protocols = null ) {
	$u = trim( (string) $u );
	if ( '' === $u ) { return ''; }
	$scheme = strtolower( (string) parse_url( $u, PHP_URL_SCHEME ) );
	return in_array( $scheme, (array) ( $protocols ?: array( 'http', 'https' ) ), true ) ? $u : '';
}
function wp_kses_post( $t ) { return preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $t ); } // Like core: strips script/style.
function sanitize_text_field( $t ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $t ) ) ); }
function sanitize_textarea_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_email( $e ) { return preg_replace( '/[^a-zA-Z0-9.@_+\-]/', '', (string) $e ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://site.example' . $p; }
function admin_url( $p = '' ) { return 'https://site.example/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://site.example/wp-json/' . $p; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
function wp_specialchars_decode( $t, $q = 0 ) { return htmlspecialchars_decode( (string) $t, ENT_QUOTES ); }
function get_bloginfo( $k = '' ) { return 'version' === $k ? '7.1' : 'Test Site'; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_generate_password( $len = 12, $special = true, $extra = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$out   = '';
	for ( $i = 0; $i < $len; $i++ ) { $out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ]; }
	return $out;
}
function apply_filters( $hook, $value ) { return $value; }
function do_action( $hook, ...$args ) { $GLOBALS['T']['actions'][] = array( $hook, $args ); }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function register_rest_route( ...$a ) {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_current_user_id() { return $GLOBALS['T']['user_id']; }
function is_user_logged_in() { return $GLOBALS['T']['user_id'] > 0; }
function wp_doing_ajax() { return false; }
function wpautop( $t ) { return (string) $t; }
function do_shortcode( $t ) { return (string) $t; }

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['T']['options'] ) ? $GLOBALS['T']['options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['T']['options'][ $k ] = $v; return true; }
function get_post( $id ) { return isset( $GLOBALS['T']['posts'][ (int) $id ] ) ? $GLOBALS['T']['posts'][ (int) $id ] : null; }
function get_the_title( $post ) { $p = is_object( $post ) ? $post : get_post( $post ); return $p ? $p->post_title : ''; }
function get_post_meta( $id, $k, $single = false ) { return isset( $GLOBALS['T']['post_meta'][ $id ][ $k ] ) ? $GLOBALS['T']['post_meta'][ $id ][ $k ] : ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['T']['post_meta'][ $id ][ $k ] = $v; return true; }

function add_query_arg( $args, $url, $url3 = null ) {
	if ( ! is_array( $args ) ) {
		$args = array( $args => $url );
		$url  = $url3;
	}
	$parts = parse_url( $url );
	$query = array();
	if ( isset( $parts['query'] ) ) { parse_str( $parts['query'], $query ); }
	foreach ( $args as $k => $v ) { $query[ $k ] = $v; }
	$base = strtok( $url, '?' );
	return $base . ( $query ? '?' . http_build_query( $query ) : '' );
}
function remove_query_arg( $keys, $url ) {
	$parts = parse_url( $url );
	$query = array();
	if ( isset( $parts['query'] ) ) { parse_str( $parts['query'], $query ); }
	foreach ( (array) $keys as $k ) { unset( $query[ $k ] ); }
	$base = strtok( $url, '?' );
	return $base . ( $query ? '?' . http_build_query( $query ) : '' );
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['T']['requests'][] = array(
		'url'     => $url,
		'method'  => $args['method'],
		'headers' => $args['headers'],
		'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
	);
	if ( empty( $GLOBALS['T']['responses'] ) ) {
		throw new RuntimeException( 'Unexpected HTTP request: ' . $args['method'] . ' ' . $url );
	}
	return array_shift( $GLOBALS['T']['responses'] );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }
function wp_mail( $to, $subject, $body, $headers = array() ) {
	$GLOBALS['T']['mail'][] = compact( 'to', 'subject', 'body' );
	return true;
}

class WP_List_Table {}

function wp_get_current_user() { return new class() { public $display_name = ''; public $user_email = ''; public function exists() { return false; } }; }
function wp_create_nonce( $a ) { return 'nonce123'; }
function get_permalink() { return 'https://site.example/donate/'; }
function wp_login_url( $r = '' ) { return 'https://site.example/wp-login.php'; }
function current_user_can( $c ) { return false; }
function wp_style_is( ...$a ) { return true; }
function wp_register_style( ...$a ) {}
function wp_register_script( ...$a ) {}
function wp_enqueue_style( ...$a ) {}
function wp_enqueue_script( ...$a ) {}
function wp_localize_script( ...$a ) {}
function wp_list_pluck( $list, $field ) { return array_map( function ( $i ) use ( $field ) { return is_array( $i ) ? $i[ $field ] : $i->$field; }, $list ); }
function selected( $a, $b, $echo = true ) { return (string) $a === (string) $b ? ' selected="selected"' : ''; }
function checked( $a, $b = true, $echo = true ) { return (string) $a === (string) $b ? ' checked="checked"' : ''; }

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

/* Shortcode parsing, equivalent to WordPress core's for the cases the plugin uses. */
function get_shortcode_regex( $tagnames = null ) {
	$tagregexp = implode( '|', array_map( 'preg_quote', $tagnames ) );
	return '\\[(\\[?)(' . $tagregexp . ')(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
}
function shortcode_parse_atts( $text ) {
	$atts    = array();
	$pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
	$text    = preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', $text );
	if ( preg_match_all( $pattern, $text, $match, PREG_SET_ORDER ) ) {
		foreach ( $match as $m ) {
			if ( ! empty( $m[1] ) ) { $atts[ strtolower( $m[1] ) ] = stripcslashes( $m[2] ); }
			elseif ( ! empty( $m[3] ) ) { $atts[ strtolower( $m[3] ) ] = stripcslashes( $m[4] ); }
			elseif ( ! empty( $m[5] ) ) { $atts[ strtolower( $m[5] ) ] = stripcslashes( $m[6] ); }
		}
	}
	return $atts;
}
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$out = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}

function bachs_pf_asset_version( $r ) { return 'test'; }
function human_time_diff( $from, $to = 0 ) { return ( ( $to ?: time() ) - $from ) . ' secs'; }
function bachs_pf_log( $message, $level = 'info' ) { $GLOBALS['T']['logs'][] = array( $level, $message ); }

t_reset();

require BACHS_PF_PATH . '/includes/class-bachs-pf-settings.php';
require BACHS_PF_PATH . '/includes/class-bachs-pf-api.php';
require BACHS_PF_PATH . '/includes/class-bachs-pf-money.php';
require BACHS_PF_PATH . '/includes/class-bachs-pf-payments.php';
require BACHS_PF_PATH . '/includes/class-bachs-pf-forms.php';
require BACHS_PF_PATH . '/includes/class-bachs-pf-fields.php';
require BACHS_PF_PATH . '/includes/class-bachs-pf-checkout.php';
require BACHS_PF_PATH . '/includes/class-bachs-pf-webhook.php';
require BACHS_PF_PATH . '/includes/class-bachs-pf-emails.php';
require BACHS_PF_PATH . '/includes/class-bachs-pf-renderer.php';
