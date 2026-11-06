<?php
/**
 * Plugin settings: environment, API keys and webhook secrets.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_PF_Settings {

	const OPTION = 'bachs_pf_settings';

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'testmode'            => 'yes',
			'test_api_key'        => '',
			'test_webhook_secret' => '',
			'live_api_key'        => '',
			'live_webhook_secret' => '',
			'notify_email'        => '',
			'debug'               => 'no',
			'delete_data'         => 'no',
		);
	}

	/**
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * 'sandbox' or 'live'.
	 *
	 * @return string
	 */
	public static function get_mode() {
		return 'no' === self::get( 'testmode', 'yes' ) ? 'live' : 'sandbox';
	}

	/**
	 * @return bool
	 */
	public static function is_test_mode() {
		return 'sandbox' === self::get_mode();
	}

	/**
	 * @param string|null $mode
	 * @return string
	 */
	public static function get_api_key( $mode = null ) {
		$mode = self::normalise_mode( $mode );
		return trim( (string) self::get( 'sandbox' === $mode ? 'test_api_key' : 'live_api_key' ) );
	}

	/**
	 * @param string|null $mode
	 * @return string
	 */
	public static function get_webhook_secret( $mode = null ) {
		$mode = self::normalise_mode( $mode );
		return trim( (string) self::get( 'sandbox' === $mode ? 'test_webhook_secret' : 'live_webhook_secret' ) );
	}

	/**
	 * @return bool
	 */
	public static function has_keys() {
		return '' !== self::get_api_key();
	}

	/**
	 * @param string|null $mode
	 * @return string
	 */
	public static function api_base_url( $mode = null ) {
		return 'sandbox' === self::normalise_mode( $mode ) ? 'https://sandbox-api.bachs.io' : 'https://api.bachs.io';
	}

	/**
	 * Where payment notifications go: the configured address, or the site admin email.
	 *
	 * @return string
	 */
	public static function notify_email() {
		$email = sanitize_email( (string) self::get( 'notify_email' ) );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * The REST URL Bachs delivers webhooks to.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( 'bachs-payment-forms/v1/webhook' );
	}

	/**
	 * @param string|null $mode
	 * @return string
	 */
	protected static function normalise_mode( $mode ) {
		return in_array( $mode, array( 'sandbox', 'live' ), true ) ? $mode : self::get_mode();
	}
}
