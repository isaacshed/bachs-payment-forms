<?php
/**
 * Webhook endpoint: POST /wp-json/bachs-payment-forms/v1/webhook
 *
 * Payments are marked paid here, from Bachs' signed events, never from the browser. Each state
 * change is an atomic UPDATE ... WHERE status IN (...), so a payment is paid, emailed and counted
 * against stock exactly once, however many times Bachs delivers the event.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_PF_Webhook {

	const MAX_TIMESTAMP_DRIFT = 300;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			'bachs-payment-forms/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_request' ),
				// Public endpoint; every delivery is authenticated by its HMAC signature instead.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_request( $request ) {
		$body = (string) $request->get_body();
		$mode = self::verify(
			$body,
			(string) $request->get_header( 'x_bachs_timestamp' ),
			(string) $request->get_header( 'x_bachs_signature' ),
			(string) $request->get_header( 'x_bachs_signature_v2' )
		);

		if ( ! $mode ) {
			$reason = self::has_any_secret()
				? __( 'The signature did not match. Check that the signing secret in Settings is the one for this webhook endpoint, in the same mode (sandbox or live).', 'bachs-payment-forms' )
				: __( 'No webhook signing secret is saved in Settings.', 'bachs-payment-forms' );
			bachs_pf_log( 'Webhook rejected: signature did not verify.', 'warning' );
			self::record_delivery( false, $reason );
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$event = json_decode( $body );
		if ( ! is_object( $event ) || empty( $event->type ) || ! isset( $event->data ) || ! is_object( $event->data ) ) {
			self::record_delivery( false, __( 'The webhook body was not a valid Bachs event.', 'bachs-payment-forms' ) );
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		try {
			self::dispatch( $event );
		} catch ( Throwable $e ) {
			bachs_pf_log( sprintf( 'Webhook %s failed: %s', $event->type, $e->getMessage() ), 'error' );
			/* translators: %s: event type */
			self::record_delivery( false, sprintf( __( 'Processing %s failed; Bachs will retry. See the debug log for details.', 'bachs-payment-forms' ), (string) $event->type ) );
			// 5xx makes Bachs retry.
			return new WP_REST_Response( array( 'ok' => false ), 500 );
		}

		self::record_delivery( true, (string) $event->type );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	const DELIVERY_OPTION = 'bachs_pf_webhook_status';

	/**
	 * Remember the last accepted and the last rejected delivery, for the settings page.
	 *
	 * @param bool   $ok
	 * @param string $detail Event type when accepted, reason when rejected.
	 */
	public static function record_delivery( $ok, $detail ) {
		$status = self::delivery_status();
		$status['last_state'] = $ok ? 'ok' : 'error';
		if ( $ok ) {
			$status['last_ok']      = time();
			$status['last_ok_type'] = $detail;
		} else {
			$status['last_error']        = time();
			$status['last_error_reason'] = $detail;
		}
		update_option( self::DELIVERY_OPTION, $status, false );
	}

	/**
	 * @return array last_ok, last_ok_type, last_error, last_error_reason.
	 */
	public static function delivery_status() {
		$status = get_option( self::DELIVERY_OPTION, array() );
		return array_merge(
			array(
				'last_ok'           => 0,
				'last_ok_type'      => '',
				'last_error'        => 0,
				'last_error_reason' => '',
				'last_state'        => '',
			),
			is_array( $status ) ? $status : array()
		);
	}

	/**
	 * @return bool
	 */
	protected static function has_any_secret() {
		return '' !== Bachs_PF_Settings::get_webhook_secret( 'sandbox' ) || '' !== Bachs_PF_Settings::get_webhook_secret( 'live' );
	}

	/**
	 * @param object $event
	 */
	public static function dispatch( $event ) {
		$handlers = array(
			'collection.succeeded'          => 'collection_succeeded',
			'collection.underpaid'          => 'collection_underpaid',
			'collection.failed'             => 'collection_failed',
			'checkout.expired'              => 'checkout_expired',
			'customer.subscription.created' => 'subscription_changed',
			'customer.subscription.updated' => 'subscription_changed',
			'customer.subscription.deleted' => 'subscription_changed',
			'invoice.paid'                  => 'invoice_paid',
		);

		$type = (string) $event->type;
		if ( isset( $handlers[ $type ] ) ) {
			call_user_func( array( __CLASS__, $handlers[ $type ] ), $event->data, $event );
		}

		do_action( 'bachs_pf_webhook_event', $type, $event );
	}

	/**
	 * @param object $data
	 * @param object $event
	 */
	protected static function collection_succeeded( $data, $event ) {
		$payment = self::resolve_payment( $data );
		if ( ! $payment || Bachs_PF_Payments::has_event( $payment, self::event_id( $event ) ) ) {
			return;
		}
		self::settle( $payment, $data, self::event_id( $event ) );
	}

	/**
	 * Record a successful payment: paid when it matches the form, otherwise held for review.
	 * Shared by the webhook and by status checks against the Bachs API, and safe to call any
	 * number of times: the status transition is atomic, so emails and stock happen once.
	 *
	 * @param object $payment
	 * @param object $data     collection.succeeded-shaped data: charge_id, status, amount, currency, reference, ...
	 * @param string $event_id Webhook event ID, '' for API checks.
	 * @return string The payment's status afterwards.
	 */
	public static function settle( $payment, $data, $event_id = '' ) {
		$charge_id   = self::str( $data, 'charge_id' );
		$checkout_id = self::str( $data, 'checkout_id' );

		// Already settled: a second payment for the same submission is one from a different
		// checkout. (The same checkout seen again, from the webhook and the status API, is not:
		// the two can name the charge differently.)
		if ( ! in_array( $payment->status, Bachs_PF_Payments::UNSETTLED, true ) ) {
			$other_checkout = '' !== $checkout_id && '' !== $payment->checkout_id && $checkout_id !== $payment->checkout_id;
			$other_charge   = '' === $checkout_id && '' !== $charge_id && '' !== $payment->charge_id && $charge_id !== $payment->charge_id;
			if ( $other_checkout || $other_charge ) {
				self::record_duplicate( $payment, $data );
			}
			Bachs_PF_Payments::remember_event( $payment, $event_id );
			return $payment->status;
		}

		$problem = self::payment_problem( $payment, $data );
		$columns = array(
			'charge_id'           => $charge_id,
			'paid_amount'         => self::str( $data, 'amount' ),
			'paid_currency'       => self::str( $data, 'currency' ),
			'settlement_amount'   => self::str( $data, 'settlement_amount' ),
			'settlement_currency' => self::str( $data, 'settlement_currency' ),
			'paid_at'             => current_time( 'mysql', true ),
			'review_reason'       => '' !== $problem ? $problem : null,
		);
		if ( isset( $data->customer->id ) && is_scalar( $data->customer->id ) ) {
			$columns['customer_id'] = (string) $data->customer->id;
		}
		// Remember which checkout paid, so a later payment on another checkout is spotted.
		if ( '' !== $checkout_id ) {
			$columns['checkout_id'] = $checkout_id;
		}

		$to = '' === $problem ? Bachs_PF_Payments::STATUS_PAID : Bachs_PF_Payments::STATUS_REVIEW;

		if ( Bachs_PF_Payments::transition( $payment->id, Bachs_PF_Payments::UNSETTLED, $to, $columns ) ) {
			$payment = Bachs_PF_Payments::get( $payment->id );
			Bachs_PF_Payments::remember_event( $payment, $event_id );

			if ( Bachs_PF_Payments::STATUS_PAID === $to ) {
				do_action( 'bachs_pf_payment_paid', $payment );
			} else {
				bachs_pf_log( sprintf( 'Payment #%d held for review: %s', $payment->id, $problem ), 'warning' );
				do_action( 'bachs_pf_payment_review', $payment, $problem );
			}
			return $to;
		}

		$fresh = Bachs_PF_Payments::get( $payment->id );
		return $fresh ? $fresh->status : $payment->status;
	}

	/**
	 * Bring a payment up to date from its checkout session, as returned by
	 * GET /v1/checkout-sessions/{id}. Used when the webhook is late or never arrives.
	 *
	 * @param object $payment
	 * @param object $session
	 * @return string The payment's status afterwards.
	 */
	public static function settle_from_session( $payment, $session ) {
		$status         = strtolower( self::str( $session, 'status' ) );
		$payment_status = strtolower( self::str( $session, 'payment_status' ) );
		$charge         = isset( $session->charge ) && is_object( $session->charge ) ? $session->charge : null;
		$charge_status  = $charge ? strtolower( self::str( $charge, 'status' ) ) : '';

		$succeeded = 'completed' === $status && ( 'succeeded' === $payment_status || 'succeeded' === $charge_status );

		if ( $succeeded ) {
			$data = (object) array(
				'checkout_id' => self::str( $session, 'checkout_id' ),
				'charge_id'   => $charge ? ( '' !== self::str( $charge, 'payment_id' ) ? self::str( $charge, 'payment_id' ) : self::str( $charge, 'charge_id' ) ) : '',
				'status'      => 'SUCCEEDED',
				'amount'      => $charge && '' !== self::str( $charge, 'amount' ) ? self::str( $charge, 'amount' ) : self::str( $session, 'amount' ),
				'currency'    => $charge && '' !== self::str( $charge, 'currency' ) ? self::str( $charge, 'currency' ) : self::str( $session, 'currency' ),
				'reference'   => self::str( $session, 'reference' ),
			);
			if ( isset( $session->customer->id ) && is_scalar( $session->customer->id ) ) {
				$data->customer = (object) array( 'id' => (string) $session->customer->id );
			}
			return self::settle( $payment, $data );
		}

		// The checkout ended without payment.
		if ( in_array( $status, array( 'expired', 'cancelled', 'canceled' ), true ) && self::is_current_checkout( $payment, $session ) ) {
			Bachs_PF_Payments::transition( $payment->id, array( Bachs_PF_Payments::STATUS_PENDING, Bachs_PF_Payments::STATUS_FAILED ), Bachs_PF_Payments::STATUS_EXPIRED );
		}

		$fresh = Bachs_PF_Payments::get( $payment->id );
		return $fresh ? $fresh->status : $payment->status;
	}

	/**
	 * Check a successful collection against what the form asked for.
	 *
	 * @param object $payment
	 * @param object $data
	 * @return string Empty when it matches, otherwise why it needs review.
	 */
	public static function payment_problem( $payment, $data ) {
		$status = strtoupper( self::str( $data, 'status' ) );

		if ( 'ACCEPTED' === $status ) {
			return __( 'Bachs accepted this payment on terms other than the exact amount requested.', 'bachs-payment-forms' );
		}

		$reference = self::str( $data, 'reference' );
		if ( '' !== $reference && ! self::reference_matches( $payment, $reference ) ) {
			/* translators: %s: Bachs payment reference */
			return sprintf( __( 'The payment reference %s does not belong to this submission.', 'bachs-payment-forms' ), $reference );
		}

		$currency = strtoupper( self::str( $data, 'currency' ) );
		$amount   = self::str( $data, 'amount' );

		// Payers may pay in another currency (Adaptive Pricing); compare only like with like.
		if ( $currency === strtoupper( $payment->currency ) && is_numeric( $amount ) && Bachs_PF_Money::compare( $amount, $payment->amount ) < 0 ) {
			return sprintf(
				/* translators: 1: amount paid, 2: amount due */
				__( 'The payer paid %1$s but the form asked for %2$s.', 'bachs-payment-forms' ),
				Bachs_PF_Money::display( $amount, $currency ),
				Bachs_PF_Money::display( $payment->amount, $payment->currency )
			);
		}

		return '';
	}

	/**
	 * @param object $data
	 * @param object $event
	 */
	protected static function collection_underpaid( $data, $event ) {
		$payment = self::resolve_payment( $data );
		if ( ! $payment || Bachs_PF_Payments::has_event( $payment, self::event_id( $event ) ) ) {
			return;
		}

		$problem = sprintf(
			/* translators: 1: amount paid, 2: amount expected, 3: currency */
			__( 'The payment was short: %1$s paid of %2$s %3$s.', 'bachs-payment-forms' ),
			self::str( $data, 'amount_paid' ),
			self::str( $data, 'amount_expected' ),
			self::str( $data, 'currency' )
		);

		if ( Bachs_PF_Payments::transition(
			$payment->id,
			Bachs_PF_Payments::UNSETTLED,
			Bachs_PF_Payments::STATUS_REVIEW,
			array(
				'charge_id'     => self::str( $data, 'charge_id' ),
				'paid_amount'   => self::str( $data, 'amount_paid' ),
				'paid_currency' => self::str( $data, 'currency' ),
				'paid_at'       => current_time( 'mysql', true ),
				'review_reason' => $problem,
			)
		) ) {
			$payment = Bachs_PF_Payments::get( $payment->id );
			Bachs_PF_Payments::remember_event( $payment, self::event_id( $event ) );
			do_action( 'bachs_pf_payment_review', $payment, $problem );
		}
	}

	/**
	 * @param object $data
	 * @param object $event
	 */
	protected static function collection_failed( $data, $event ) {
		$payment = self::resolve_payment( $data );
		if ( $payment && self::is_current_checkout( $payment, $data ) ) {
			Bachs_PF_Payments::transition( $payment->id, array( Bachs_PF_Payments::STATUS_PENDING ), Bachs_PF_Payments::STATUS_FAILED );
		}
	}

	/**
	 * @param object $data
	 * @param object $event
	 */
	protected static function checkout_expired( $data, $event ) {
		$payment = self::resolve_payment( $data );
		if ( $payment && self::is_current_checkout( $payment, $data ) ) {
			Bachs_PF_Payments::transition( $payment->id, array( Bachs_PF_Payments::STATUS_PENDING, Bachs_PF_Payments::STATUS_FAILED ), Bachs_PF_Payments::STATUS_EXPIRED );
		}
	}

	/**
	 * customer.subscription.created / updated / deleted: link and track the subscription.
	 *
	 * @param object $data
	 * @param object $event
	 */
	protected static function subscription_changed( $data, $event ) {
		$subscription_id = self::str( $data, 'subscription_id' );
		if ( '' === $subscription_id ) {
			return;
		}

		$payment = Bachs_PF_Payments::get_by( 'subscription_id', $subscription_id, true );

		// First event for this subscription: Bachs copies the checkout metadata onto it.
		if ( ! $payment ) {
			$payment = self::resolve_payment( $data );
			if ( ! $payment || Bachs_PF_Payments::TYPE_SUBSCRIPTION !== $payment->type ) {
				return;
			}
		}

		$columns = array(
			'subscription_id'     => $subscription_id,
			'subscription_status' => sanitize_key( self::str( $data, 'status' ) ),
		);
		if ( isset( $data->customer->customer_id ) && is_scalar( $data->customer->customer_id ) ) {
			$columns['customer_id'] = (string) $data->customer->customer_id;
		}
		if ( 'customer.subscription.deleted' === $event->type && '' === $columns['subscription_status'] ) {
			$columns['subscription_status'] = 'canceled';
		}

		Bachs_PF_Payments::update( $payment->id, $columns );
		do_action( 'bachs_pf_subscription_changed', Bachs_PF_Payments::get( $payment->id ), $event->type, $data );
	}

	/**
	 * invoice.paid: record each renewal as its own paid row under the original payment.
	 *
	 * Bachs sends invoice.paid for the first charge too (alongside collection.succeeded); that one
	 * is recognised by its charge or by being the subscription's first invoice, and skipped.
	 *
	 * @param object $data
	 * @param object $event
	 */
	protected static function invoice_paid( $data, $event ) {
		$invoice_id      = self::str( $data, 'invoice_id' );
		$subscription_id = isset( $data->subscription->subscription_id ) && is_scalar( $data->subscription->subscription_id ) ? (string) $data->subscription->subscription_id : '';

		if ( '' === $invoice_id || '' === $subscription_id ) {
			return;
		}

		$parent = Bachs_PF_Payments::get_by( 'subscription_id', $subscription_id, true );
		if ( ! $parent ) {
			// Usually the first invoice arriving before customer.subscription.created: nothing to record.
			return;
		}

		$invoices = is_array( $parent->invoice_ids ) ? $parent->invoice_ids : array();
		if ( in_array( $invoice_id, $invoices, true ) ) {
			return;
		}

		$charge     = self::invoice_charge_id( $data );
		$is_initial = ( '' !== $charge && $charge === $parent->charge_id ) || empty( $invoices ) && in_array( $parent->status, Bachs_PF_Payments::UNSETTLED, true );

		if ( ! $is_initial && empty( $invoices ) && '' === $charge ) {
			// No charge to compare: the first invoice we see is the initial one.
			$is_initial = true;
		}

		$invoices[] = $invoice_id;
		Bachs_PF_Payments::update( $parent->id, array( 'invoice_ids' => array_slice( $invoices, -200 ) ) );

		if ( $is_initial ) {
			return;
		}

		$amount   = self::str( $data, 'amount_paid' );
		$currency = self::str( $data, 'currency' );

		$renewal_id = Bachs_PF_Payments::insert(
			array(
				'form_id'       => $parent->form_id,
				'parent_id'     => $parent->id,
				'type'          => Bachs_PF_Payments::TYPE_RENEWAL,
				'status'        => Bachs_PF_Payments::STATUS_PAID,
				'mode'          => $parent->mode,
				'reference'     => substr( 'bpf_inv_' . $invoice_id, 0, 128 ),
				'user_id'       => $parent->user_id,
				'email'         => $parent->email,
				'name'          => $parent->name,
				'currency'      => '' !== $currency ? $currency : $parent->currency,
				'amount'        => is_numeric( $amount ) ? Bachs_PF_Money::format( $amount ) : $parent->amount,
				'quantity'      => $parent->quantity,
				'cadence'       => $parent->cadence,
				'charge_id'     => $charge,
				'paid_amount'   => $amount,
				'paid_currency' => $currency,
				'subscription_id' => $subscription_id,
				'paid_at'       => current_time( 'mysql', true ),
			)
		);

		// The unique reference makes a duplicate delivery fail to insert, so this runs once.
		if ( $renewal_id ) {
			do_action( 'bachs_pf_payment_paid', Bachs_PF_Payments::get( $renewal_id ) );
		}
	}

	/**
	 * A second payment for an already-settled submission: keep a visible record for refund.
	 *
	 * @param object $payment
	 * @param object $data
	 */
	protected static function record_duplicate( $payment, $data ) {
		$charge_id = self::str( $data, 'charge_id' );

		$id = Bachs_PF_Payments::insert(
			array(
				'form_id'       => $payment->form_id,
				'parent_id'     => $payment->id,
				'type'          => Bachs_PF_Payments::TYPE_ONE_TIME,
				'status'        => Bachs_PF_Payments::STATUS_REVIEW,
				'mode'          => $payment->mode,
				'reference'     => substr( 'bpf_dup_' . $charge_id, 0, 128 ),
				'email'         => $payment->email,
				'name'          => $payment->name,
				'currency'      => $payment->currency,
				'amount'        => $payment->amount,
				'quantity'      => 0,
				'charge_id'     => $charge_id,
				'paid_amount'   => self::str( $data, 'amount' ),
				'paid_currency' => self::str( $data, 'currency' ),
				'paid_at'       => current_time( 'mysql', true ),
				'review_reason' => sprintf(
					/* translators: %s: original payment reference */
					__( 'Duplicate payment for %s, which was already paid. Refund it from your Bachs dashboard.', 'bachs-payment-forms' ),
					$payment->reference
				),
			)
		);

		if ( $id ) {
			bachs_pf_log( sprintf( 'Duplicate charge %s for payment #%d.', $charge_id, $payment->id ), 'error' );
			do_action( 'bachs_pf_payment_review', Bachs_PF_Payments::get( $id ), __( 'Duplicate payment', 'bachs-payment-forms' ) );
		}
	}

	/**
	 * Find the payment behind a collection, checkout or subscription event.
	 *
	 * @param object $data
	 * @return object|null
	 */
	public static function resolve_payment( $data ) {
		$payment = null;

		if ( isset( $data->metadata->bachs_pf_payment_id ) && is_scalar( $data->metadata->bachs_pf_payment_id ) ) {
			$payment = Bachs_PF_Payments::get( absint( $data->metadata->bachs_pf_payment_id ) );
		}

		if ( ! $payment ) {
			$checkout_id = self::str( $data, 'checkout_id' );
			if ( '' === $checkout_id && isset( $data->metadata->checkout_id ) && is_scalar( $data->metadata->checkout_id ) ) {
				$checkout_id = (string) $data->metadata->checkout_id;
			}
			$payment = Bachs_PF_Payments::get_by( 'checkout_id', $checkout_id );
		}

		if ( ! $payment ) {
			$reference = preg_replace( '/-\d+$/', '', self::str( $data, 'reference' ) );
			$payment   = Bachs_PF_Payments::get_by( 'reference', $reference );
		}

		return ( $payment && 0 === (int) $payment->parent_id ) ? $payment : null;
	}

	/**
	 * @param object $payment
	 * @param string $reference
	 * @return bool
	 */
	protected static function reference_matches( $payment, $reference ) {
		return $reference === $payment->reference || 1 === preg_match( '/^' . preg_quote( $payment->reference, '/' ) . '-\d+$/', $reference );
	}

	/**
	 * Ignore failures and expiries of a checkout the payer has since replaced.
	 *
	 * @param object $payment
	 * @param object $data
	 * @return bool
	 */
	protected static function is_current_checkout( $payment, $data ) {
		$checkout_id = self::str( $data, 'checkout_id' );
		return '' === $checkout_id || $checkout_id === $payment->checkout_id;
	}

	/**
	 * @param object $data invoice.* data.
	 * @return string
	 */
	protected static function invoice_charge_id( $data ) {
		if ( ! isset( $data->charge ) ) {
			return '';
		}
		if ( is_scalar( $data->charge ) ) {
			return (string) $data->charge;
		}
		foreach ( array( 'charge_id', 'id' ) as $key ) {
			if ( isset( $data->charge->{$key} ) && is_scalar( $data->charge->{$key} ) ) {
				return (string) $data->charge->{$key};
			}
		}
		return '';
	}

	/**
	 * @param object $event
	 * @return string
	 */
	protected static function event_id( $event ) {
		return isset( $event->id ) && is_scalar( $event->id ) ? (string) $event->id : '';
	}

	/**
	 * @param object $data
	 * @param string $key
	 * @return string
	 */
	protected static function str( $data, $key ) {
		return isset( $data->{$key} ) && is_scalar( $data->{$key} ) ? (string) $data->{$key} : '';
	}

	/*
	|--------------------------------------------------------------------------
	| Signatures
	|--------------------------------------------------------------------------
	*/

	/**
	 * Verify against the sandbox and live secrets.
	 *
	 * @param string $body
	 * @param string $timestamp X-Bachs-Timestamp.
	 * @param string $signature X-Bachs-Signature.
	 * @param string $sig_v2    X-Bachs-Signature-V2.
	 * @return string|false Matching mode.
	 */
	public static function verify( $body, $timestamp, $signature, $sig_v2 ) {
		if ( '' === $body ) {
			return false;
		}

		foreach ( array_unique( array( Bachs_PF_Settings::get_mode(), 'sandbox', 'live' ) ) as $mode ) {
			$secret = Bachs_PF_Settings::get_webhook_secret( $mode );
			if ( '' === $secret ) {
				continue;
			}
			if ( self::verify_v2( $body, $sig_v2, $secret ) || self::verify_v1( $body, $timestamp, $signature, $secret ) ) {
				return $mode;
			}
		}

		return false;
	}

	/**
	 * X-Bachs-Signature: hex HMAC-SHA256 of "{timestamp}.{body}".
	 *
	 * @param string $body
	 * @param string $timestamp
	 * @param string $signature
	 * @param string $secret
	 * @return bool
	 */
	public static function verify_v1( $body, $timestamp, $signature, $secret ) {
		if ( '' === $secret || '' === $signature || ! ctype_digit( (string) $timestamp ) ) {
			return false;
		}
		if ( abs( time() - (int) $timestamp ) > self::MAX_TIMESTAMP_DRIFT ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ), strtolower( trim( $signature ) ) );
	}

	/**
	 * X-Bachs-Signature-V2: "t={timestamp},v1={sig}[,v1={sig}...]" (one v1 per valid secret).
	 *
	 * @param string $body
	 * @param string $header
	 * @param string $secret
	 * @return bool
	 */
	public static function verify_v2( $body, $header, $secret ) {
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$timestamp  = '';
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		foreach ( $signatures as $signature ) {
			if ( self::verify_v1( $body, $timestamp, $signature, $secret ) ) {
				return true;
			}
		}
		return false;
	}
}
