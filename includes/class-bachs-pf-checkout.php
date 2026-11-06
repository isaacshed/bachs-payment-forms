<?php
/**
 * Form submission: validate, price the payment on the server, store it, and send the payer to
 * the Bachs hosted checkout. Payments are confirmed later by the signed webhook, never by the
 * browser.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_PF_Checkout {

	const NONCE_ACTION = 'bachs_pf_submit';

	public static function init() {
		add_action( 'wp_ajax_bachs_pf_submit', array( __CLASS__, 'ajax_submit' ) );
		add_action( 'wp_ajax_nopriv_bachs_pf_submit', array( __CLASS__, 'ajax_submit' ) );
		add_action( 'wp_ajax_bachs_pf_retry', array( __CLASS__, 'ajax_retry' ) );
		add_action( 'wp_ajax_nopriv_bachs_pf_retry', array( __CLASS__, 'ajax_retry' ) );
	}

	/**
	 * AJAX: submit a form.
	 */
	public static function ajax_submit() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce values are only compared.
		$nonce = isset( $_POST['bachs_pf_nonce'] ) ? wp_unslash( $_POST['bachs_pf_nonce'] ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			self::send_error( __( 'This form has expired. Please refresh the page and try again.', 'bachs-payment-forms' ) );
		}

		$form_id = isset( $_POST['bachs_pf_form_id'] ) ? absint( $_POST['bachs_pf_form_id'] ) : 0;
		$input   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitised by build_payment() / Bachs_PF_Fields.

		$result = self::start_payment( $form_id, is_array( $input ) ? $input : array() );

		if ( is_wp_error( $result ) ) {
			self::send_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'redirect' => $result ) );
	}

	/**
	 * Validate a submission, store the payment and create its checkout session.
	 *
	 * @param int   $form_id
	 * @param array $input Unslashed POST data.
	 * @return string|WP_Error Checkout URL.
	 */
	public static function start_payment( $form_id, $input ) {

		$form = Bachs_PF_Forms::get_form( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'bachs_pf_form', __( 'This payment form is not available.', 'bachs-payment-forms' ) );
		}

		if ( ! Bachs_PF_Settings::has_keys() ) {
			bachs_pf_log( 'Payment attempted but no Bachs API key is set for the current mode.', 'error' );
			return new WP_Error( 'bachs_pf_config', __( 'Payments are not available right now. Please try again later.', 'bachs-payment-forms' ) );
		}

		$settings = Bachs_PF_Forms::get_settings( $form_id );

		if ( 'yes' === $settings['require_login'] && ! is_user_logged_in() ) {
			return new WP_Error( 'bachs_pf_login', __( 'Please log in to use this form.', 'bachs-payment-forms' ) );
		}

		$payment = self::build_payment( $form, $settings, $input );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		$payment_id = Bachs_PF_Payments::insert( $payment );
		if ( ! $payment_id ) {
			bachs_pf_log( sprintf( 'Could not save a payment for form #%d.', $form_id ), 'error' );
			return new WP_Error( 'bachs_pf_db', __( 'Something went wrong. Please try again.', 'bachs-payment-forms' ) );
		}

		return self::create_session( Bachs_PF_Payments::get( $payment_id ), $form, $settings );
	}

	/**
	 * Work out everything about the payment on the server: who, what, how much, how often.
	 * Nothing about the price is taken from the browser except the payer's choices, and each
	 * choice is checked against the form's settings.
	 *
	 * @param WP_Post $form
	 * @param array   $settings
	 * @param array   $input
	 * @return array|WP_Error Row for Bachs_PF_Payments::insert().
	 */
	public static function build_payment( $form, $settings, $input ) {
		$errors = array();

		$name  = isset( $input['bachs_pf_name'] ) && is_scalar( $input['bachs_pf_name'] ) ? trim( sanitize_text_field( (string) $input['bachs_pf_name'] ) ) : '';
		$email = isset( $input['bachs_pf_email'] ) && is_scalar( $input['bachs_pf_email'] ) ? trim( sanitize_email( (string) $input['bachs_pf_email'] ) ) : '';

		if ( '' === $name ) {
			$errors[] = __( 'Please enter your name.', 'bachs-payment-forms' );
		}
		if ( ! is_email( $email ) ) {
			$errors[] = __( 'Please enter a valid email address.', 'bachs-payment-forms' );
		}

		$currency = $settings['currency'];
		if ( ! Bachs_PF_Money::is_supported( $currency ) ) {
			$errors[] = __( 'This form uses a currency Bachs does not support.', 'bachs-payment-forms' );
		}

		// Unit amount.
		$unit = null;
		switch ( $settings['amount_type'] ) {
			case 'custom':
				$unit = isset( $input['bachs_pf_amount'] ) && is_scalar( $input['bachs_pf_amount'] ) ? Bachs_PF_Money::parse( $input['bachs_pf_amount'] ) : null;
				if ( null === $unit || Bachs_PF_Money::compare( $unit, 0 ) <= 0 ) {
					$errors[] = __( 'Please enter a valid amount.', 'bachs-payment-forms' );
					$unit     = null;
				} elseif ( '' !== $settings['min_amount'] && Bachs_PF_Money::compare( $unit, $settings['min_amount'] ) < 0 ) {
					/* translators: %s: minimum amount with currency */
					$errors[] = sprintf( __( 'The minimum amount is %s.', 'bachs-payment-forms' ), Bachs_PF_Money::display( $settings['min_amount'], $currency ) );
				} elseif ( '' !== $settings['max_amount'] && Bachs_PF_Money::compare( $unit, $settings['max_amount'] ) > 0 ) {
					/* translators: %s: maximum amount with currency */
					$errors[] = sprintf( __( 'The maximum amount is %s.', 'bachs-payment-forms' ), Bachs_PF_Money::display( $settings['max_amount'], $currency ) );
				}
				break;

			case 'options':
				$options = Bachs_PF_Forms::parse_options( $settings['options'] )['options'];
				$index   = isset( $input['bachs_pf_option'] ) && is_scalar( $input['bachs_pf_option'] ) && ctype_digit( (string) $input['bachs_pf_option'] ) ? (int) $input['bachs_pf_option'] : -1;
				if ( ! isset( $options[ $index ] ) ) {
					$errors[] = __( 'Please choose an amount.', 'bachs-payment-forms' );
				} else {
					$unit        = (float) $options[ $index ]['amount'];
					$option_name = $options[ $index ]['label'];
				}
				break;

			default:
				$unit = '' !== $settings['amount'] ? (float) $settings['amount'] : null;
				if ( null === $unit || Bachs_PF_Money::compare( $unit, 0 ) <= 0 ) {
					$errors[] = __( 'This form has no amount set.', 'bachs-payment-forms' );
				}
		}

		// Quantity.
		$quantity = 1;
		if ( 'yes' === $settings['quantity'] ) {
			$raw = isset( $input['bachs_pf_quantity'] ) && is_scalar( $input['bachs_pf_quantity'] ) ? (string) $input['bachs_pf_quantity'] : '1';
			if ( ! ctype_digit( $raw ) || (int) $raw < 1 || (int) $raw > (int) $settings['max_quantity'] ) {
				/* translators: %d: maximum quantity */
				$errors[] = sprintf( __( 'Please choose a quantity between 1 and %d.', 'bachs-payment-forms' ), (int) $settings['max_quantity'] );
			} else {
				$quantity = (int) $raw;
			}
		}

		$remaining = Bachs_PF_Forms::remaining_stock( $form->ID, $settings );
		if ( null !== $remaining && $quantity > $remaining ) {
			$errors[] = 0 === $remaining
				? __( 'Sorry, this is sold out.', 'bachs-payment-forms' )
				/* translators: %d: units left */
				: sprintf( _n( 'Sorry, only %d is left.', 'Sorry, only %d are left.', $remaining, 'bachs-payment-forms' ), $remaining );
		}

		// Recurring.
		$cadence = '';
		if ( 'fixed' === $settings['recurring'] ) {
			$cadence = $settings['cadence'];
		} elseif ( 'optional' === $settings['recurring'] ) {
			$chosen = isset( $input['bachs_pf_cadence'] ) && is_scalar( $input['bachs_pf_cadence'] ) ? sanitize_key( (string) $input['bachs_pf_cadence'] ) : '';
			if ( '' !== $chosen && 'once' !== $chosen ) {
				if ( in_array( $chosen, $settings['cadences'], true ) ) {
					$cadence = $chosen;
				} else {
					$errors[] = __( 'Please choose a valid payment schedule.', 'bachs-payment-forms' );
				}
			}
		}
		if ( '' !== $cadence && ! Bachs_PF_Money::supports_recurring( $currency ) ) {
			$errors[] = __( 'Recurring payments are not available in this currency.', 'bachs-payment-forms' );
		}

		// Terms.
		if ( '' !== $settings['terms_url'] && empty( $input['bachs_pf_terms'] ) ) {
			$errors[] = __( 'Please accept the terms to continue.', 'bachs-payment-forms' );
		}

		// Custom fields.
		$fields = Bachs_PF_Fields::validate(
			Bachs_PF_Fields::definitions( $form->post_content ),
			isset( $input['bachs_pf_fields'] ) ? $input['bachs_pf_fields'] : array()
		);
		$errors = array_merge( $errors, $fields['errors'] );

		$errors = apply_filters( 'bachs_pf_validate_submission', $errors, $form, $settings, $input );

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'bachs_pf_invalid', implode( ' ', array_unique( $errors ) ) );
		}

		$values = $fields['values'];
		if ( isset( $option_name ) ) {
			$values = array(
				'_option' => array(
					'label' => __( 'Option', 'bachs-payment-forms' ),
					'value' => $option_name,
				),
			) + $values;
		}

		return array(
			'form_id'    => $form->ID,
			'type'       => '' !== $cadence ? Bachs_PF_Payments::TYPE_SUBSCRIPTION : Bachs_PF_Payments::TYPE_ONE_TIME,
			'status'     => Bachs_PF_Payments::STATUS_PENDING,
			'mode'       => Bachs_PF_Settings::get_mode(),
			'reference'  => Bachs_PF_Payments::new_reference(),
			'access_key' => Bachs_PF_Payments::new_access_key(),
			'user_id'    => get_current_user_id(),
			'email'      => $email,
			'name'       => $name,
			'currency'   => $currency,
			'amount'     => Bachs_PF_Money::format( $unit * $quantity ),
			'quantity'   => $quantity,
			'cadence'    => $cadence,
			'fields'     => $values,
			'page_url'   => self::safe_page_url( isset( $input['bachs_pf_page'] ) && is_scalar( $input['bachs_pf_page'] ) ? (string) $input['bachs_pf_page'] : '' ),
		);
	}

	/**
	 * Create a Bachs checkout session for a stored payment.
	 *
	 * @param object  $payment
	 * @param WP_Post $form
	 * @param array   $settings
	 * @return string|WP_Error Checkout URL.
	 */
	public static function create_session( $payment, $form, $settings ) {

		$attempt   = (int) $payment->attempts + 1;
		$reference = $attempt > 1 ? $payment->reference . '-' . $attempt : $payment->reference;
		$quantity  = max( 1, (int) $payment->quantity );
		$unit      = Bachs_PF_Money::format( (float) $payment->amount / $quantity );

		$args = array(
			'customer'    => array(
				'email' => $payment->email,
				'name'  => $payment->name,
			),
			'success_url' => self::return_url( $payment, $settings ),
			'cancel_url'  => add_query_arg( 'bachs_pf_cancelled', '1', self::status_url( $payment ) ),
			'reference'   => $reference,
			'metadata'    => array(
				'bachs_pf_payment_id' => (string) $payment->id,
				'bachs_pf_form_id'    => (string) $payment->form_id,
				'source'              => 'wordpress-payment-forms',
			),
		);

		try {
			if ( '' !== $payment->cadence ) {
				$args['product_cart'] = array(
					array(
						'product_id' => Bachs_PF_Forms::get_recurring_product( $form, $payment->cadence, $payment->currency, $unit ),
						'quantity'   => $quantity,
						'pricing'    => array(
							'price_type' => 'fixed',
							'currency'   => $payment->currency,
							'amount'     => $unit,
						),
					),
				);
			} else {
				$args['pricing'] = array(
					'currency' => $payment->currency,
					'amount'   => Bachs_PF_Money::format( $payment->amount ),
				);
			}

			$args   = apply_filters( 'bachs_pf_checkout_session_args', $args, $payment, $form );
			$client = Bachs_PF_API::client( $payment->mode );

			try {
				$session = $client->request( 'checkout-sessions', $args, array( 'Idempotency-Key' => 'bachs-pf-' . $reference ) );
			} catch ( Exception $e ) {
				// The cached recurring product may have been archived in Bachs: make a new one once.
				if ( '' === $payment->cadence || $client->last_response_code < 400 || $client->last_response_code >= 500 ) {
					throw $e;
				}
				bachs_pf_log( sprintf( 'Retrying payment #%d with a new recurring product: %s', $payment->id, $e->getMessage() ), 'warning' );
				$args['product_cart'][0]['product_id'] = Bachs_PF_Forms::get_recurring_product( $form, $payment->cadence, $payment->currency, $unit, true );
				$session                               = $client->request( 'checkout-sessions', $args, array( 'Idempotency-Key' => 'bachs-pf-' . $reference . '-p' ) );
			}
		} catch ( Exception $e ) {
			bachs_pf_log( sprintf( 'Could not create a checkout for payment #%d: %s', $payment->id, $e->getMessage() ), 'error' );
			return new WP_Error( 'bachs_pf_api', self::friendly_api_error( Bachs_PF_API::client( $payment->mode ) ) );
		}

		if ( ! isset( $session->checkout_url, $session->checkout_id ) ) {
			bachs_pf_log( sprintf( 'Unexpected checkout response for payment #%d.', $payment->id ), 'error' );
			return new WP_Error( 'bachs_pf_api', __( 'Payments are not available right now. Please try again later.', 'bachs-payment-forms' ) );
		}

		Bachs_PF_Payments::update(
			$payment->id,
			array(
				'checkout_id' => (string) $session->checkout_id,
				'attempts'    => $attempt,
				'status'      => Bachs_PF_Payments::STATUS_PENDING,
			)
		);

		return (string) $session->checkout_url;
	}

	/**
	 * AJAX: pay again for an unpaid payment, from the payer's own status link.
	 */
	public static function ajax_retry() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce values are only compared.
		$nonce = isset( $_POST['bachs_pf_nonce'] ) ? wp_unslash( $_POST['bachs_pf_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			self::send_error( __( 'This page has expired. Please refresh it and try again.', 'bachs-payment-forms' ) );
		}

		$reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
		$key       = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

		$result = self::retry( $reference, $key );

		if ( is_wp_error( $result ) ) {
			self::send_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'redirect' => $result ) );
	}

	/**
	 * @param string $reference
	 * @param string $key
	 * @return string|WP_Error Checkout URL.
	 */
	public static function retry( $reference, $key ) {
		$payment = self::find_for_payer( $reference, $key );

		if ( ! $payment || Bachs_PF_Payments::TYPE_RENEWAL === $payment->type || 0 !== (int) $payment->parent_id ) {
			return new WP_Error( 'bachs_pf_retry', __( 'This payment link is not valid.', 'bachs-payment-forms' ) );
		}

		if ( ! in_array( $payment->status, Bachs_PF_Payments::UNSETTLED, true ) ) {
			return new WP_Error( 'bachs_pf_retry', __( 'This payment has already been made.', 'bachs-payment-forms' ) );
		}

		$form = Bachs_PF_Forms::get_form( $payment->form_id );
		if ( ! $form ) {
			return new WP_Error( 'bachs_pf_retry', __( 'This payment form is no longer available.', 'bachs-payment-forms' ) );
		}

		$status = self::session_status( $payment );

		if ( 'open' === $status['status'] && '' !== $status['url'] ) {
			return $status['url'];
		}
		if ( 'completed' === $status['status'] ) {
			return new WP_Error( 'bachs_pf_retry', __( 'We have received your payment and are confirming it. There is no need to pay again.', 'bachs-payment-forms' ) );
		}
		if ( 'error' === $status['status'] ) {
			return new WP_Error( 'bachs_pf_retry', __( 'We could not check your payment just now. Please try again in a moment.', 'bachs-payment-forms' ) );
		}

		$settings  = Bachs_PF_Forms::get_settings( $form->ID );
		$remaining = Bachs_PF_Forms::remaining_stock( $form->ID, $settings );
		if ( null !== $remaining && (int) $payment->quantity > $remaining ) {
			return new WP_Error( 'bachs_pf_retry', __( 'Sorry, this is sold out.', 'bachs-payment-forms' ) );
		}

		return self::create_session( $payment, $form, $settings );
	}

	/**
	 * The payer's payment, if the reference and access key match.
	 *
	 * @param string $reference
	 * @param string $key
	 * @return object|null
	 */
	public static function find_for_payer( $reference, $key ) {
		if ( '' === $reference || '' === $key ) {
			return null;
		}
		$payment = Bachs_PF_Payments::get_by( 'reference', $reference );
		return ( $payment && '' !== $payment->access_key && hash_equals( $payment->access_key, $key ) ) ? $payment : null;
	}

	/**
	 * Current checkout session status: open, completed, expired, cancelled, none or error.
	 *
	 * @param object $payment
	 * @return array{status: string, url: string}
	 */
	public static function session_status( $payment ) {
		if ( '' === (string) $payment->checkout_id ) {
			return array(
				'status' => 'none',
				'url'    => '',
			);
		}

		try {
			$session = Bachs_PF_API::client( $payment->mode )->request( 'checkout-sessions/' . rawurlencode( $payment->checkout_id ), array(), array(), 'GET' );
		} catch ( Exception $e ) {
			bachs_pf_log( sprintf( 'Could not check checkout %s: %s', $payment->checkout_id, $e->getMessage() ), 'warning' );
			return array(
				'status' => 'error',
				'url'    => '',
			);
		}

		$status = isset( $session->status ) && is_scalar( $session->status ) ? strtolower( (string) $session->status ) : '';

		if ( ! in_array( $status, array( 'open', 'expired', 'cancelled', 'canceled' ), true ) ) {
			// completed, or a status we don't know: never offer a second payment.
			$status = 'completed';
		}

		return array(
			'status' => 'canceled' === $status ? 'cancelled' : $status,
			'url'    => isset( $session->checkout_url ) && is_scalar( $session->checkout_url ) ? (string) $session->checkout_url : '',
		);
	}

	/**
	 * Where the payer lands after paying: the form's redirect URL, or the page the form is on.
	 * Both carry the payment reference and access key so the payer can see their status.
	 *
	 * @param object $payment
	 * @param array  $settings
	 * @return string
	 */
	public static function return_url( $payment, $settings ) {
		if ( '' !== $settings['redirect_url'] ) {
			return add_query_arg(
				array(
					'bachs_pf_payment' => rawurlencode( $payment->reference ),
					'bachs_pf_key'     => rawurlencode( $payment->access_key ),
				),
				$settings['redirect_url']
			);
		}
		return self::status_url( $payment );
	}

	/**
	 * The page the form was submitted from, with the payer's status parameters.
	 *
	 * @param object $payment
	 * @return string
	 */
	public static function status_url( $payment ) {
		$base = '' !== $payment->page_url ? $payment->page_url : home_url( '/' );

		return add_query_arg(
			array(
				'bachs_pf_payment' => rawurlencode( $payment->reference ),
				'bachs_pf_key'     => rawurlencode( $payment->access_key ),
			),
			remove_query_arg( array( 'bachs_pf_payment', 'bachs_pf_key', 'bachs_pf_cancelled', 'checkout_id' ), $base )
		);
	}

	/**
	 * Accept only an http(s) URL on this site, so nobody can use the form to send payers elsewhere.
	 *
	 * @param string $url
	 * @return string
	 */
	public static function safe_page_url( $url ) {
		$url  = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' === $url || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) $host ) ) {
			return home_url( '/' );
		}

		return remove_query_arg( array( 'bachs_pf_payment', 'bachs_pf_key', 'bachs_pf_cancelled', 'checkout_id' ), $url );
	}

	/**
	 * A payer-friendly message for an API error, without leaking details.
	 *
	 * @param Bachs_PF_API $client
	 * @return string
	 */
	protected static function friendly_api_error( $client ) {
		switch ( $client->last_error_code ) {
			case 'DEPOSIT_LIMIT_EXCEEDED':
				return __( 'This amount is above the limit for a single payment. Please choose a smaller amount.', 'bachs-payment-forms' );
			case 'BASE_CURRENCY_NOT_HELD_BY_ORG':
			case 'BILLING_CURRENCY_NOT_AVAILABLE':
			case 'CHECKOUT_RESTRICTION_LEAVES_NO_PAYMENT_METHOD':
				return __( 'Payments in this currency are not available right now.', 'bachs-payment-forms' );
		}
		if ( 400 <= $client->last_response_code && $client->last_response_code < 500 && false !== stripos( $client->last_error_detail, 'minimum' ) ) {
			return __( 'This amount is below the minimum for a payment.', 'bachs-payment-forms' );
		}
		return __( 'Payments are not available right now. Please try again later.', 'bachs-payment-forms' );
	}

	/**
	 * @param string $message
	 */
	protected static function send_error( $message ) {
		wp_send_json_error( array( 'message' => $message ), 400 );
	}
}
