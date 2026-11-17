<?php
/**
 * Thin wrapper around the Bachs REST API.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_PF_API {

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string
	 */
	private $api_url;

	/**
	 * @var int
	 */
	public $last_response_code = 0;

	/**
	 * Bachs error_code from the last failed request.
	 *
	 * @var string
	 */
	public $last_error_code = '';

	/**
	 * Unescaped error detail from the last failed request.
	 *
	 * @var string
	 */
	public $last_error_detail = '';

	/**
	 * @param string $api_key
	 * @param string $api_url Base URL without the version.
	 */
	public function __construct( $api_key, $api_url ) {
		$this->api_key = (string) $api_key;
		$this->api_url = rtrim( $api_url, '/' ) . '/v1/';
	}

	/**
	 * @param string $endpoint Relative to /v1/.
	 * @param array  $body     JSON body, or query args for GET.
	 * @param array  $headers  Extra headers.
	 * @param string $method
	 * @return mixed Decoded JSON.
	 *
	 * @throws Exception On a missing key, transport error or a >= 400 response.
	 */
	public function request( $endpoint, $body = array(), $headers = array(), $method = 'POST' ) {

		$this->last_response_code = 0;
		$this->last_error_code    = '';
		$this->last_error_detail  = '';

		if ( '' === $this->api_key ) {
			throw new Exception( 'Bachs API key is not configured.' );
		}

		$args = array(
			'method'     => $method,
			'timeout'    => 30,
			'headers'    => array_merge(
				array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				$headers
			),
			'user-agent' => 'BachsPaymentForms/' . BACHS_PF_VERSION . '; ' . home_url(),
		);

		$url = $this->api_url . ltrim( $endpoint, '/' );

		if ( ! empty( $body ) ) {
			if ( 'GET' === $method ) {
				$url = add_query_arg( array_map( 'rawurlencode', $body ), $url );
			} else {
				$args['body'] = wp_json_encode( self::add_integration_metadata( $body ) );
			}
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		$this->last_response_code = (int) wp_remote_retrieve_response_code( $response );
		$decoded                  = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $this->last_response_code >= 400 ) {
			$this->last_error_code   = isset( $decoded->error_code ) && is_scalar( $decoded->error_code ) ? (string) $decoded->error_code : '';
			$this->last_error_detail = isset( $decoded->detail ) && is_scalar( $decoded->detail ) ? (string) $decoded->detail : (string) wp_remote_retrieve_body( $response );
			$request_id              = wp_remote_retrieve_header( $response, 'x-request-id' );

			throw new Exception(
				sprintf(
					'Bachs API error (%d%s): %s%s',
					(int) $this->last_response_code,
					'' !== $this->last_error_code ? ', ' . esc_html( $this->last_error_code ) : '',
					esc_html( $this->last_error_detail ),
					$request_id ? ' [request ' . esc_html( $request_id ) . ']' : ''
				)
			);
		}

		return $decoded;
	}

	/**
	 * Cached clients, keyed by mode.
	 *
	 * @var Bachs_PF_API[]
	 */
	protected static $clients = array();

	/**
	 * Shared client per environment.
	 *
	 * @param string|null $mode 'sandbox' or 'live'; defaults to the active mode.
	 * @return Bachs_PF_API
	 */
	public static function client( $mode = null ) {
		if ( 'sandbox' !== $mode && 'live' !== $mode ) {
			$mode = Bachs_PF_Settings::get_mode();
		}

		if ( ! isset( self::$clients[ $mode ] ) ) {
			self::$clients[ $mode ] = new self( Bachs_PF_Settings::get_api_key( $mode ), Bachs_PF_Settings::api_base_url( $mode ) );
		}

		return self::$clients[ $mode ];
	}

	/**
	 * Forget cached clients, so new keys take effect (after saving settings, and in tests).
	 */
	public static function reset() {
		self::$clients = array();
	}

	/**
	 * Add the integration details to a request's metadata, so Bachs can report on payments by
	 * integration: plugin, plugin version, WordPress version and site address. Keys the request
	 * already has are kept.
	 *
	 * @param mixed $body
	 * @return mixed
	 */
	public static function add_integration_metadata( $body ) {
		if ( ! is_array( $body ) || ! isset( $body['metadata'] ) || ! is_array( $body['metadata'] ) ) {
			return $body;
		}
		$body['metadata'] = $body['metadata'] + array(
			'integration'         => 'bachs-payment-forms',
			'integration_version' => (string) BACHS_PF_VERSION,
			'platform_version'    => (string) ( get_bloginfo( 'version' ) ),
			'site_url'            => (string) home_url(),
		);
		return $body;
	}
}
