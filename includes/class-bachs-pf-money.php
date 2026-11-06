<?php
/**
 * Amounts and currencies.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_PF_Money {

	/**
	 * Fiat currencies Bachs can price a checkout in.
	 * https://docs.bachs.io/for-you/supported-currencies
	 */
	const CURRENCIES = array( 'USD', 'NGN', 'GHS', 'KES', 'MWK', 'RWF', 'TZS', 'UGX', 'XAF', 'XOF', 'ZMW' );

	/**
	 * Bachs subscriptions currently bill USD cards only.
	 * https://docs.bachs.io/guides/subscriptions/overview
	 */
	const RECURRING_CURRENCIES = array( 'USD' );

	/**
	 * Every amount is sent to Bachs with two decimals ("25000.00").
	 */
	const DECIMALS = 2;

	/**
	 * Billing cadences offered on forms, mapped to a Bachs billing_cycle.
	 *
	 * @return array key => array( interval, frequency, label )
	 */
	public static function cadences() {
		return array(
			'week'    => array( 'week', 1, __( 'Weekly', 'bachs-payment-forms' ) ),
			'month'   => array( 'month', 1, __( 'Monthly', 'bachs-payment-forms' ) ),
			'quarter' => array( 'month', 3, __( 'Every 3 months', 'bachs-payment-forms' ) ),
			'year'    => array( 'year', 1, __( 'Yearly', 'bachs-payment-forms' ) ),
		);
	}

	/**
	 * @param string $key
	 * @return string
	 */
	public static function cadence_label( $key ) {
		$cadences = self::cadences();
		return isset( $cadences[ $key ] ) ? $cadences[ $key ][2] : __( 'One-time', 'bachs-payment-forms' );
	}

	/**
	 * @return array
	 */
	public static function currencies() {
		return array_values( array_unique( (array) apply_filters( 'bachs_pf_currencies', self::CURRENCIES ) ) );
	}

	/**
	 * @param string $currency
	 * @return bool
	 */
	public static function is_supported( $currency ) {
		return in_array( strtoupper( (string) $currency ), self::currencies(), true );
	}

	/**
	 * @param string $currency
	 * @return bool
	 */
	public static function supports_recurring( $currency ) {
		return in_array( strtoupper( (string) $currency ), (array) apply_filters( 'bachs_pf_recurring_currencies', self::RECURRING_CURRENCIES ), true );
	}

	/**
	 * Parse a user-entered amount ("1,500.50", " 20 ") into a float, or null if invalid.
	 *
	 * @param mixed $value
	 * @return float|null
	 */
	public static function parse( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value >= 0 ? (float) $value : null;
		}
		$value = str_replace( array( ',', ' ' ), '', trim( (string) $value ) );
		if ( '' === $value || ! preg_match( '/^\d+(\.\d{1,2})?$/', $value ) ) {
			return null;
		}
		return (float) $value;
	}

	/**
	 * Decimal string for the Bachs API.
	 *
	 * @param mixed $amount
	 * @return string
	 */
	public static function format( $amount ) {
		return number_format( (float) $amount, self::DECIMALS, '.', '' );
	}

	/**
	 * Human display: "NGN 1,500.00".
	 *
	 * @param mixed  $amount
	 * @param string $currency
	 * @return string
	 */
	public static function display( $amount, $currency ) {
		return trim( strtoupper( (string) $currency ) . ' ' . number_format_i18n( (float) $amount, self::DECIMALS ) );
	}

	/**
	 * @param mixed $amount
	 * @return int
	 */
	public static function to_minor( $amount ) {
		return (int) round( (float) $amount * pow( 10, self::DECIMALS ) );
	}

	/**
	 * Compare two amounts: -1, 0 or 1.
	 *
	 * @param mixed $a
	 * @param mixed $b
	 * @return int
	 */
	public static function compare( $a, $b ) {
		return self::to_minor( $a ) <=> self::to_minor( $b );
	}
}
