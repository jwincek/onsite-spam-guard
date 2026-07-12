<?php
/**
 * PHPUnit bootstrap — lightweight WordPress stubs + plugin autoloader.
 *
 * These are fast, dependency-free unit tests for the plugin's pure logic
 * (token signing, IP resolution, guard decisions). They do not require a
 * WordPress install or a database; only the handful of WP functions the
 * tested classes call are stubbed below.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

define( 'SIMPLE_SPAM_SHIELD_PLUGIN_ROOT', dirname( __DIR__ ) );

// Satisfies the `if ( ! defined( 'ABSPATH' ) ) exit;` direct-access guards.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/' );
}

// --- In-memory option store ------------------------------------------------
$GLOBALS['simple_spam_shield_test_options'] = [];

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['simple_spam_shield_test_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['simple_spam_shield_test_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
		$GLOBALS['simple_spam_shield_test_options'][ $key ] = $value;
		return true;
	}
}

// --- In-memory transient store ---------------------------------------------
$GLOBALS['simple_spam_shield_test_transients'] = [];

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['simple_spam_shield_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['simple_spam_shield_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['simple_spam_shield_test_transients'][ $key ] );
		return true;
	}
}

// --- Misc WP helpers -------------------------------------------------------
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		return $GLOBALS['simple_spam_shield_test_caps'][ $capability ] ?? false;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['simple_spam_shield_test_user_id'] ?? 0 );
	}
}
if ( ! function_exists( 'wp_check_comment_disallowed_list' ) ) {
	function wp_check_comment_disallowed_list( $author, $email, $url, $comment, $user_ip = '', $user_agent = '' ) {
		$list = trim( (string) get_option( 'disallowed_keys', '' ) );
		if ( '' === $list ) {
			return false;
		}
		$haystack = strtolower( "$author $email $url $comment $user_ip $user_agent" );
		foreach ( array_filter( array_map( 'trim', explode( "\n", $list ) ) ) as $word ) {
			if ( str_contains( $haystack, strtolower( $word ) ) ) {
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return $text;
	}
}

// --- In-memory filter registry ---------------------------------------------
$GLOBALS['simple_spam_shield_test_filters'] = [];

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['simple_spam_shield_test_filters'][ $hook ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['simple_spam_shield_test_filters'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = [] ) {
		// Throw instead of exiting so tests can assert the hard-block path.
		throw new \RuntimeException( is_string( $message ) ? $message : 'wp_die' );
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		return substr( str_repeat( 'aB3$xY7!', (int) ceil( $length / 8 ) ), 0, (int) $length );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string ) {
		return trim( strip_tags( (string) $string ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = is_string( $str ) ? $str : '';
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( $str ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return is_string( $str ) ? wp_strip_all_tags( $str ) : '';
	}
}

// --- WP_Error --------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_code() {
			return $this->code;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// --- Plugin autoloader (mirrors the plugin's own) --------------------------
spl_autoload_register( function ( $class ) {
	if ( ! str_starts_with( $class, 'Simple_Spam_Shield\\' ) ) {
		return;
	}
	$relative = substr( $class, strlen( 'Simple_Spam_Shield\\' ) );
	$parts    = explode( '\\', $relative );
	$name     = array_pop( $parts );
	$dir      = strtolower( implode( '/', $parts ) );
	$file     = SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/includes/' . $dir . '/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Public API functions (not autoloaded — they are plain global functions).
require_once SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/includes/api.php';
