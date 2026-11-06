<?php
/**
 * The payment form post type and its settings.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_PF_Forms {

	const POST_TYPE = 'bachs_form';

	const META_SETTINGS = '_bachs_pf_settings';
	const META_PRODUCTS = '_bachs_pf_products';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Forms are private admin objects: no public URL, no archive, no search, and only
	 * administrators (manage_options) can create or edit them.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Payment Forms', 'bachs-payment-forms' ),
					'singular_name'      => __( 'Payment Form', 'bachs-payment-forms' ),
					'menu_name'          => __( 'Bachs Forms', 'bachs-payment-forms' ),
					'all_items'          => __( 'All Forms', 'bachs-payment-forms' ),
					'add_new'            => __( 'Add New Form', 'bachs-payment-forms' ),
					'add_new_item'       => __( 'Add New Payment Form', 'bachs-payment-forms' ),
					'edit_item'          => __( 'Edit Payment Form', 'bachs-payment-forms' ),
					'new_item'           => __( 'New Payment Form', 'bachs-payment-forms' ),
					'search_items'       => __( 'Search Forms', 'bachs-payment-forms' ),
					'not_found'          => __( 'No payment forms found.', 'bachs-payment-forms' ),
					'not_found_in_trash' => __( 'No payment forms found in Trash.', 'bachs-payment-forms' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'menu_icon'           => 'dashicons-money-alt',
				'supports'            => array( 'title', 'editor' ),
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'              => 'manage_options',
					'read_post'              => 'manage_options',
					'delete_post'            => 'manage_options',
					'edit_posts'             => 'manage_options',
					'edit_others_posts'      => 'manage_options',
					'publish_posts'          => 'manage_options',
					'read_private_posts'     => 'manage_options',
					'delete_posts'           => 'manage_options',
					'delete_private_posts'   => 'manage_options',
					'delete_published_posts' => 'manage_options',
					'delete_others_posts'    => 'manage_options',
					'edit_private_posts'     => 'manage_options',
					'edit_published_posts'   => 'manage_options',
					'create_posts'           => 'manage_options',
				),
			)
		);
	}

	/**
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'currency'        => 'USD',
			'amount_type'     => 'fixed',
			'amount'          => '',
			'min_amount'      => '',
			'max_amount'      => '',
			'options'         => '',
			'quantity'        => 'no',
			'max_quantity'    => 10,
			'quantity_label'  => __( 'Quantity', 'bachs-payment-forms' ),
			'stock'           => '',
			'recurring'       => 'none',
			'cadence'         => 'month',
			'cadences'        => array( 'month' ),
			'button_label'    => __( 'Pay now', 'bachs-payment-forms' ),
			'success_message' => __( 'Thank you! Your payment was received.', 'bachs-payment-forms' ),
			'redirect_url'    => '',
			'require_login'   => 'no',
			'terms_url'       => '',
			'send_receipt'    => 'yes',
			'notify_admin'    => 'yes',
		);
	}

	/**
	 * @param int $form_id
	 * @return array
	 */
	public static function get_settings( $form_id ) {
		$saved = get_post_meta( (int) $form_id, self::META_SETTINGS, true );
		return array_merge( self::default_settings(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * A published payment form, or null.
	 *
	 * @param int $form_id
	 * @return WP_Post|null
	 */
	public static function get_form( $form_id ) {
		$post = get_post( (int) $form_id );
		return ( $post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) ? $post : null;
	}

	/**
	 * Validate and normalise settings from the form editor.
	 *
	 * @param array $input Raw (unslashed) input.
	 * @return array{settings: array, errors: string[]}
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$out      = array();
		$errors   = array();
		$input    = is_array( $input ) ? $input : array();

		$get = function ( $key ) use ( $input, $defaults ) {
			return isset( $input[ $key ] ) ? $input[ $key ] : $defaults[ $key ];
		};

		$currency        = strtoupper( sanitize_text_field( (string) $get( 'currency' ) ) );
		$out['currency'] = Bachs_PF_Money::is_supported( $currency ) ? $currency : 'USD';

		$amount_type        = sanitize_key( (string) $get( 'amount_type' ) );
		$out['amount_type'] = in_array( $amount_type, array( 'fixed', 'custom', 'options' ), true ) ? $amount_type : 'fixed';

		foreach ( array( 'amount', 'min_amount', 'max_amount' ) as $key ) {
			$raw = trim( (string) $get( $key ) );
			if ( '' === $raw ) {
				$out[ $key ] = '';
				continue;
			}
			$parsed = Bachs_PF_Money::parse( $raw );
			if ( null === $parsed ) {
				/* translators: %s: field value */
				$errors[]    = sprintf( __( '"%s" is not a valid amount.', 'bachs-payment-forms' ), $raw );
				$out[ $key ] = '';
			} else {
				$out[ $key ] = Bachs_PF_Money::format( $parsed );
			}
		}

		$out['options'] = '';
		$options        = self::parse_options( (string) $get( 'options' ) );
		if ( ! empty( $options['errors'] ) ) {
			$errors = array_merge( $errors, $options['errors'] );
		}
		$out['options'] = implode(
			"\n",
			array_map(
				function ( $option ) {
					return $option['label'] . ' | ' . $option['amount'];
				},
				$options['options']
			)
		);

		if ( 'fixed' === $out['amount_type'] && ( '' === $out['amount'] || Bachs_PF_Money::compare( $out['amount'], 0 ) <= 0 ) ) {
			$errors[] = __( 'A fixed-amount form needs an amount greater than zero.', 'bachs-payment-forms' );
		}
		if ( 'options' === $out['amount_type'] && empty( $options['options'] ) ) {
			$errors[] = __( 'Add at least one amount option, one per line, as "Label | amount".', 'bachs-payment-forms' );
		}
		if ( 'custom' === $out['amount_type'] && '' !== $out['min_amount'] && '' !== $out['max_amount'] && Bachs_PF_Money::compare( $out['min_amount'], $out['max_amount'] ) > 0 ) {
			$errors[] = __( 'The minimum amount is higher than the maximum.', 'bachs-payment-forms' );
		}

		$out['quantity']       = 'yes' === $get( 'quantity' ) ? 'yes' : 'no';
		$out['max_quantity']   = max( 1, min( 1000, absint( $get( 'max_quantity' ) ) ) );
		$out['quantity_label'] = sanitize_text_field( (string) $get( 'quantity_label' ) );
		$stock                 = trim( (string) $get( 'stock' ) );
		$out['stock']          = '' === $stock ? '' : (string) absint( $stock );

		$recurring        = sanitize_key( (string) $get( 'recurring' ) );
		$out['recurring'] = in_array( $recurring, array( 'none', 'fixed', 'optional' ), true ) ? $recurring : 'none';
		$cadences         = Bachs_PF_Money::cadences();
		$cadence          = sanitize_key( (string) $get( 'cadence' ) );
		$out['cadence']   = isset( $cadences[ $cadence ] ) ? $cadence : 'month';
		$allowed          = array_values( array_intersect( array_map( 'sanitize_key', (array) $get( 'cadences' ) ), array_keys( $cadences ) ) );
		$out['cadences']  = $allowed ? $allowed : array( 'month' );

		if ( 'none' !== $out['recurring'] && ! Bachs_PF_Money::supports_recurring( $out['currency'] ) ) {
			$errors[] = sprintf(
				/* translators: %s: comma-separated currency codes */
				__( 'Recurring payments are only available in %s, because Bachs subscriptions currently bill USD cards only. The form was saved as one-time.', 'bachs-payment-forms' ),
				implode( ', ', (array) apply_filters( 'bachs_pf_recurring_currencies', Bachs_PF_Money::RECURRING_CURRENCIES ) )
			);
			$out['recurring'] = 'none';
		}

		$out['button_label']    = sanitize_text_field( (string) $get( 'button_label' ) );
		$out['success_message'] = sanitize_textarea_field( (string) $get( 'success_message' ) );
		$out['redirect_url']    = esc_url_raw( trim( (string) $get( 'redirect_url' ) ), array( 'http', 'https' ) );
		$out['terms_url']       = esc_url_raw( trim( (string) $get( 'terms_url' ) ), array( 'http', 'https' ) );

		foreach ( array( 'require_login', 'send_receipt', 'notify_admin' ) as $key ) {
			$out[ $key ] = 'yes' === $get( $key ) ? 'yes' : 'no';
		}

		if ( '' === $out['button_label'] ) {
			$out['button_label'] = $defaults['button_label'];
		}

		return array(
			'settings' => $out,
			'errors'   => $errors,
		);
	}

	/**
	 * Parse amount options, one per line: "Label | amount".
	 *
	 * @param string $raw
	 * @return array{options: array, errors: string[]}
	 */
	public static function parse_options( $raw ) {
		$options = array();
		$errors  = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$pos = strrpos( $line, '|' );
			if ( false === $pos ) {
				/* translators: %s: option line */
				$errors[] = sprintf( __( 'Amount option "%s" needs the format "Label | amount".', 'bachs-payment-forms' ), $line );
				continue;
			}
			$label  = sanitize_text_field( trim( substr( $line, 0, $pos ) ) );
			$amount = Bachs_PF_Money::parse( trim( substr( $line, $pos + 1 ) ) );
			if ( '' === $label || null === $amount || Bachs_PF_Money::compare( $amount, 0 ) <= 0 ) {
				/* translators: %s: option line */
				$errors[] = sprintf( __( 'Amount option "%s" needs a label and an amount greater than zero.', 'bachs-payment-forms' ), $line );
				continue;
			}
			$options[] = array(
				'label'  => $label,
				'amount' => Bachs_PF_Money::format( $amount ),
			);
		}

		return array(
			'options' => $options,
			'errors'  => $errors,
		);
	}

	/**
	 * Units left, or null when stock isn't tracked.
	 *
	 * @param int   $form_id
	 * @param array $settings
	 * @return int|null
	 */
	public static function remaining_stock( $form_id, $settings ) {
		if ( '' === (string) $settings['stock'] ) {
			return null;
		}
		return max( 0, (int) $settings['stock'] - Bachs_PF_Payments::sold_quantity( $form_id ) );
	}

	/**
	 * The Bachs recurring product for a form, cadence and currency, created on first use.
	 * Bachs fixes a product's billing interval at creation, so there is one product per cadence;
	 * each checkout then sets the price with product_cart[].pricing.
	 *
	 * @param WP_Post $form
	 * @param string  $cadence
	 * @param string  $currency
	 * @param string  $unit_amount
	 * @param bool    $force_new Ignore a cached product (e.g. after it was archived in Bachs).
	 * @return string Product ID.
	 *
	 * @throws Exception On an API error.
	 */
	public static function get_recurring_product( $form, $cadence, $currency, $unit_amount, $force_new = false ) {
		$mode     = Bachs_PF_Settings::get_mode();
		$products = get_post_meta( $form->ID, self::META_PRODUCTS, true );
		$products = is_array( $products ) ? $products : array();

		if ( ! $force_new && ! empty( $products[ $mode ][ $currency ][ $cadence ] ) ) {
			return (string) $products[ $mode ][ $currency ][ $cadence ];
		}

		$cadences = Bachs_PF_Money::cadences();
		if ( ! isset( $cadences[ $cadence ] ) ) {
			throw new Exception( 'Unknown billing cadence.' );
		}

		$name = sprintf( '%s (%s)', wp_strip_all_tags( get_the_title( $form ) ), $cadences[ $cadence ][2] );

		$response = Bachs_PF_API::client()->request(
			'products',
			array(
				'name'          => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 255 ) : substr( $name, 0, 255 ),
				'price'         => array(
					'currency' => $currency,
					'amount'   => Bachs_PF_Money::format( $unit_amount ),
				),
				'billing_cycle' => array(
					'interval'  => $cadences[ $cadence ][0],
					'frequency' => $cadences[ $cadence ][1],
				),
				'metadata'      => array(
					'bachs_pf_form_id' => (string) $form->ID,
					'source'           => 'wordpress-payment-forms',
				),
			),
			array( 'Idempotency-Key' => sprintf( 'bachs-pf-product-%d-%s-%s-%s-%d', $form->ID, $mode, $currency, $cadence, $force_new ? time() : 0 ) )
		);

		$product_id = isset( $response->product_id ) ? (string) $response->product_id : ( isset( $response->id ) ? (string) $response->id : '' );

		if ( '' === $product_id ) {
			throw new Exception( 'Unexpected response when creating the Bachs product.' );
		}

		$products[ $mode ][ $currency ][ $cadence ] = $product_id;
		update_post_meta( $form->ID, self::META_PRODUCTS, $products );

		return $product_id;
	}
}
