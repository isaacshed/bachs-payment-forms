<?php
/**
 * Front end: the [bachs_payment_form id="123"] shortcode and the payer's status view.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_PF_Renderer {

	const SHORTCODE = 'bachs_payment_form';

	/**
	 * Field definitions of the form currently being rendered (null outside a form).
	 *
	 * @var array|null
	 */
	protected static $current_fields = null;

	/**
	 * @var string
	 */
	protected static $current_prefix = '';

	/**
	 * @var int
	 */
	protected static $instance = 0;

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_shortcode( Bachs_PF_Fields::SHORTCODE, array( __CLASS__, 'field_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'bachs-pf', BACHS_PF_URL . '/assets/css/bachs-pf.css', array(), bachs_pf_asset_version( 'assets/css/bachs-pf.css' ) );
		wp_register_script( 'bachs-pf', BACHS_PF_URL . '/assets/js/bachs-pf.js', array(), bachs_pf_asset_version( 'assets/js/bachs-pf.js' ), true );
		wp_localize_script(
			'bachs-pf',
			'bachsPf',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'working' => __( 'Taking you to the secure checkout…', 'bachs-payment-forms' ),
					'error'   => __( 'Something went wrong. Please try again.', 'bachs-payment-forms' ),
					/* translators: %s: currency code */
					'amount'  => __( 'Amount (%s)', 'bachs-payment-forms' ),
					/* translators: %s: amount with currency */
					'minimum' => __( 'Minimum %s', 'bachs-payment-forms' ),
					/* translators: %s: amount with currency */
					'maximum' => __( 'maximum %s', 'bachs-payment-forms' ),
				),
			)
		);
	}

	/**
	 * @param array|string $atts
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'         => 0,
				'show_title' => 'no',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$form = Bachs_PF_Forms::get_form( absint( $atts['id'] ) );
		if ( ! $form ) {
			return current_user_can( 'manage_options' ) ? '<p class="bachs-pf-notice">' . esc_html__( 'Bachs payment form not found. Check the form ID in the shortcode.', 'bachs-payment-forms' ) . '</p>' : '';
		}

		self::enqueue();

		$settings = Bachs_PF_Forms::get_settings( $form->ID );

		// The payer coming back from Bachs, or following their status link.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view, authorised by the payment's secret access key.
		if ( isset( $_GET['bachs_pf_payment'], $_GET['bachs_pf_key'] ) ) {
			$payment = Bachs_PF_Checkout::find_for_payer(
				sanitize_text_field( wp_unslash( $_GET['bachs_pf_payment'] ) ),
				sanitize_text_field( wp_unslash( $_GET['bachs_pf_key'] ) )
			);
			if ( $payment && (int) $payment->form_id === (int) $form->ID ) {
				return self::render_status( $payment, $settings, isset( $_GET['bachs_pf_cancelled'] ) );
			}
		}
		// phpcs:enable

		return self::render_form( $form, $settings, 'yes' === $atts['show_title'] );
	}

	/**
	 * [bachs_field] only renders inside a payment form.
	 *
	 * @param array|string $atts
	 * @return string
	 */
	public static function field_shortcode( $atts ) {
		if ( null === self::$current_fields ) {
			return '';
		}
		$field = Bachs_PF_Fields::normalise( $atts );
		if ( ! $field || ! isset( self::$current_fields[ $field['key'] ] ) ) {
			return '';
		}
		return Bachs_PF_Fields::render( self::$current_fields[ $field['key'] ], self::$current_prefix );
	}

	protected static function enqueue() {
		if ( ! wp_style_is( 'bachs-pf', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'bachs-pf' );
		wp_enqueue_script( 'bachs-pf' );
	}

	/**
	 * @param WP_Post $form
	 * @param array   $settings
	 * @param bool    $show_title
	 * @return string
	 */
	protected static function render_form( $form, $settings, $show_title ) {
		++self::$instance;
		$prefix   = 'bachs-pf-' . $form->ID . '-' . self::$instance;
		$currency = $settings['currency'];

		if ( 'yes' === $settings['require_login'] && ! is_user_logged_in() ) {
			return '<div class="bachs-pf"><p class="bachs-pf-notice">' . sprintf(
				/* translators: %s: login URL */
				wp_kses_post( __( 'Please <a href="%s">log in</a> to use this form.', 'bachs-payment-forms' ) ),
				esc_url( wp_login_url( get_permalink() ) )
			) . '</p></div>';
		}

		$remaining = Bachs_PF_Forms::remaining_stock( $form->ID, $settings );
		if ( 0 === $remaining ) {
			return '<div class="bachs-pf"><p class="bachs-pf-notice">' . esc_html__( 'Sorry, this is sold out.', 'bachs-payment-forms' ) . '</p></div>';
		}

		$user  = wp_get_current_user();
		$html  = '<div class="bachs-pf" id="' . esc_attr( $prefix ) . '">';
		$html .= '<form class="bachs-pf-form" method="post" novalidate data-currency="' . esc_attr( $currency ) . '"' . self::pricing_attribute( $settings ) . '>';

		if ( $show_title ) {
			$html .= '<h3 class="bachs-pf-title">' . esc_html( get_the_title( $form ) ) . '</h3>';
		}

		// Description and custom fields from the form content.
		self::$current_fields = Bachs_PF_Fields::definitions( $form->post_content );
		self::$current_prefix = $prefix;
		$content              = do_shortcode( wpautop( wp_kses_post( $form->post_content ) ) );
		self::$current_fields = null;

		$html .= '<div class="bachs-pf-intro">' . $content . '</div>';

		$html .= self::input( $prefix . '-name', 'bachs_pf_name', __( 'Full name', 'bachs-payment-forms' ), 'text', $user->exists() ? $user->display_name : '', 'autocomplete="name"' );
		$html .= self::input( $prefix . '-email', 'bachs_pf_email', __( 'Email', 'bachs-payment-forms' ), 'email', $user->exists() ? $user->user_email : '', 'autocomplete="email"' );

		$html .= self::render_currency_choice( $prefix, $settings );
		$html .= self::render_amount( $prefix, $settings );

		if ( 'yes' === $settings['quantity'] ) {
			$max   = null !== $remaining ? min( (int) $settings['max_quantity'], $remaining ) : (int) $settings['max_quantity'];
			$html .= '<div class="bachs-pf-field"><label for="' . esc_attr( $prefix . '-qty' ) . '">' . esc_html( '' !== $settings['quantity_label'] ? $settings['quantity_label'] : __( 'Quantity', 'bachs-payment-forms' ) ) . '</label>';
			$html .= '<input type="number" id="' . esc_attr( $prefix . '-qty' ) . '" name="bachs_pf_quantity" class="bachs-pf-qty" min="1" max="' . (int) $max . '" step="1" value="1" required></div>';
		}

		$html .= self::render_schedule( $prefix, $settings );

		if ( '' !== $settings['terms_url'] ) {
			$html .= '<div class="bachs-pf-field bachs-pf-terms"><label><input type="checkbox" name="bachs_pf_terms" value="1" required> ' . sprintf(
				/* translators: %s: terms URL */
				wp_kses_post( __( 'I accept the <a href="%s" target="_blank" rel="noopener">terms</a>.', 'bachs-payment-forms' ) ),
				esc_url( $settings['terms_url'] )
			) . '</label></div>';
		}

		$html .= '<p class="bachs-pf-total" aria-live="polite"></p>';
		$html .= '<div class="bachs-pf-message" role="alert" aria-live="assertive"></div>';

		$html .= '<input type="hidden" name="action" value="bachs_pf_submit">';
		$html .= '<input type="hidden" name="bachs_pf_form_id" value="' . (int) $form->ID . '">';
		$html .= '<input type="hidden" name="bachs_pf_page" value="">';
		$html .= '<input type="hidden" name="bachs_pf_nonce" value="' . esc_attr( wp_create_nonce( Bachs_PF_Checkout::NONCE_ACTION ) ) . '">';

		$html .= '<p class="bachs-pf-actions"><button type="submit" class="bachs-pf-button">' . esc_html( $settings['button_label'] ) . '</button></p>';
		$html .= '<p class="bachs-pf-secure">' . esc_html__( 'You will complete your payment on the secure Bachs checkout.', 'bachs-payment-forms' ) . '</p>';

		$html .= '</form></div>';

		return $html;
	}

	/**
	 * Currency dropdown, only when the form lets payers choose.
	 *
	 * @param string $prefix
	 * @param array  $settings
	 * @return string
	 */
	protected static function render_currency_choice( $prefix, $settings ) {
		if ( 'yes' !== $settings['multi_currency'] ) {
			return '';
		}
		$codes = Bachs_PF_Forms::form_currencies( $settings );
		if ( count( $codes ) < 2 ) {
			return '';
		}

		$html  = '<div class="bachs-pf-field bachs-pf-currency-field"><label for="' . esc_attr( $prefix . '-currency' ) . '">' . esc_html__( 'Currency', 'bachs-payment-forms' ) . '</label>';
		$html .= '<select id="' . esc_attr( $prefix . '-currency' ) . '" name="bachs_pf_currency" class="bachs-pf-currency">';
		foreach ( $codes as $code ) {
			$html .= '<option value="' . esc_attr( $code ) . '"' . selected( $code, $settings['currency'], false ) . '>' . esc_html( $code ) . '</option>';
		}
		return $html . '</select></div>';
	}

	/**
	 * Per-currency prices for the script, as a data attribute (empty for single-currency forms).
	 * Display only: the server prices every payment itself.
	 *
	 * @param array $settings
	 * @return string
	 */
	protected static function pricing_attribute( $settings ) {
		if ( 'yes' !== $settings['multi_currency'] ) {
			return '';
		}

		$data = array();
		foreach ( Bachs_PF_Forms::form_currencies( $settings ) as $code ) {
			$price = Bachs_PF_Forms::pricing_for( $settings, $code );
			if ( null === $price ) {
				continue;
			}
			$data[ $code ] = array(
				'amount'    => $price['amount'],
				'min'       => $price['min_amount'],
				'max'       => $price['max_amount'],
				'options'   => wp_list_pluck( $price['options'], 'amount' ),
				'recurring' => Bachs_PF_Money::supports_recurring( $code ),
			);
		}

		return ' data-pricing="' . esc_attr( wp_json_encode( $data ) ) . '"';
	}

	/**
	 * @param string $prefix
	 * @param array  $settings
	 * @return string
	 */
	protected static function render_amount( $prefix, $settings ) {
		$currency = $settings['currency'];

		switch ( $settings['amount_type'] ) {
			case 'custom':
				$hint = array();
				if ( '' !== $settings['min_amount'] ) {
					/* translators: %s: amount with currency */
					$hint[] = sprintf( __( 'Minimum %s', 'bachs-payment-forms' ), Bachs_PF_Money::display( $settings['min_amount'], $currency ) );
				}
				if ( '' !== $settings['max_amount'] ) {
					/* translators: %s: amount with currency */
					$hint[] = sprintf( __( 'maximum %s', 'bachs-payment-forms' ), Bachs_PF_Money::display( $settings['max_amount'], $currency ) );
				}
				$html  = '<div class="bachs-pf-field bachs-pf-amount"><label for="' . esc_attr( $prefix . '-amount' ) . '" class="bachs-pf-amount-label">';
				/* translators: %s: currency code */
				$html .= esc_html( sprintf( __( 'Amount (%s)', 'bachs-payment-forms' ), $currency ) ) . '</label>';
				$html .= '<input type="number" inputmode="decimal" id="' . esc_attr( $prefix . '-amount' ) . '" name="bachs_pf_amount" class="bachs-pf-unit" step="0.01" min="' . esc_attr( '' !== $settings['min_amount'] ? $settings['min_amount'] : '0.01' ) . '"' . ( '' !== $settings['max_amount'] ? ' max="' . esc_attr( $settings['max_amount'] ) . '"' : '' ) . ' required>';
				$html .= '<small class="bachs-pf-hint">' . esc_html( implode( ', ', $hint ) ) . '</small>';
				return $html . '</div>';

			case 'options':
				$options = Bachs_PF_Forms::parse_options( $settings['options'] )['options'];
				$html    = '<fieldset class="bachs-pf-field bachs-pf-options"><legend>' . esc_html__( 'Choose an amount', 'bachs-payment-forms' ) . '</legend>';
				foreach ( $options as $i => $option ) {
					$html .= '<label><input type="radio" name="bachs_pf_option" class="bachs-pf-unit" value="' . (int) $i . '" data-amount="' . esc_attr( $option['amount'] ) . '"' . ( 0 === $i ? ' checked' : '' ) . ' required> ' . esc_html( $option['label'] ) . ' <span class="bachs-pf-price" data-index="' . (int) $i . '">' . esc_html( Bachs_PF_Money::display( $option['amount'], $currency ) ) . '</span></label>';
				}
				return $html . '</fieldset>';

			default:
				return '<p class="bachs-pf-field bachs-pf-fixed"><span>' . esc_html__( 'Amount', 'bachs-payment-forms' ) . '</span> <strong class="bachs-pf-unit" data-amount="' . esc_attr( $settings['amount'] ) . '">' . esc_html( Bachs_PF_Money::display( $settings['amount'], $currency ) ) . '</strong>'
					. ( 'fixed' === $settings['recurring'] ? ' <span class="bachs-pf-cadence">' . esc_html( Bachs_PF_Money::cadence_label( $settings['cadence'] ) ) . '</span>' : '' ) . '</p>';
		}
	}

	/**
	 * @param string $prefix
	 * @param array  $settings
	 * @return string
	 */
	protected static function render_schedule( $prefix, $settings ) {
		if ( 'optional' !== $settings['recurring'] ) {
			if ( 'fixed' === $settings['recurring'] && 'fixed' !== $settings['amount_type'] ) {
				return '<p class="bachs-pf-field bachs-pf-cadence">' . esc_html(
					sprintf(
						/* translators: %s: billing cadence, e.g. Monthly */
						__( 'Billed %s until you cancel.', 'bachs-payment-forms' ),
						strtolower( Bachs_PF_Money::cadence_label( $settings['cadence'] ) )
					)
				) . '</p>';
			}
			return '';
		}

		$html  = '<div class="bachs-pf-field"><label for="' . esc_attr( $prefix . '-cadence' ) . '">' . esc_html__( 'How often?', 'bachs-payment-forms' ) . '</label>';
		$html .= '<select id="' . esc_attr( $prefix . '-cadence' ) . '" name="bachs_pf_cadence">';
		$html .= '<option value="once">' . esc_html__( 'One-time', 'bachs-payment-forms' ) . '</option>';
		foreach ( $settings['cadences'] as $cadence ) {
			$html .= '<option value="' . esc_attr( $cadence ) . '">' . esc_html( Bachs_PF_Money::cadence_label( $cadence ) ) . '</option>';
		}
		$html .= '</select>';
		if ( 'yes' === $settings['multi_currency'] ) {
			$html .= '<small class="bachs-pf-recurring-note" hidden>' . esc_html__( 'Recurring payments are only available in USD, so this payment will be one-time.', 'bachs-payment-forms' ) . '</small>';
		}
		return $html . '</div>';
	}

	/**
	 * The payer's view of their payment after returning from Bachs.
	 *
	 * @param object $payment
	 * @param array  $settings
	 * @param bool   $cancelled
	 * @return string
	 */
	public static function render_status( $payment, $settings, $cancelled ) {
		// Confirm with Bachs directly, so a paid payment shows as paid even if the webhook
		// hasn't arrived (or isn't set up).
		$session = array( 'status' => 'cancelled' );
		if ( ! $cancelled ) {
			$check   = Bachs_PF_Checkout::reconcile( $payment );
			$payment = $check['payment'];
			$session = $check['session'];
		}

		$amount = Bachs_PF_Money::display( $payment->amount, $payment->currency );
		$html   = '<div class="bachs-pf bachs-pf-status">';

		if ( Bachs_PF_Payments::STATUS_PAID === $payment->status || Bachs_PF_Payments::STATUS_REVIEW === $payment->status ) {
			$html .= '<p class="bachs-pf-success">' . nl2br( esc_html( $settings['success_message'] ) ) . '</p>';
			$html .= '<p>' . esc_html(
				sprintf(
					/* translators: 1: amount, 2: payment reference */
					__( 'Amount: %1$s. Reference: %2$s.', 'bachs-payment-forms' ),
					$amount,
					$payment->reference
				)
			) . '</p>';
			return $html . '</div>';
		}

		if ( 'completed' === $session['status'] ) {
			$html .= '<p class="bachs-pf-success">' . esc_html__( 'Thank you! We have received your payment and are confirming it. You will get an email receipt shortly.', 'bachs-payment-forms' ) . '</p>';
			/* translators: %s: payment reference */
			$html .= '<p>' . esc_html( sprintf( __( 'Reference: %s.', 'bachs-payment-forms' ), $payment->reference ) ) . '</p>';
			return $html . '</div>';
		}

		if ( 'error' === $session['status'] ) {
			$html .= '<p>' . esc_html__( 'We could not check your payment just now. Please refresh this page in a moment.', 'bachs-payment-forms' ) . '</p>';
			return $html . '</div>';
		}

		$html .= '<p class="bachs-pf-notice">' . esc_html(
			'cancelled' === $session['status']
				? __( 'Your payment was not completed.', 'bachs-payment-forms' )
				/* translators: %s: amount */
				: sprintf( __( 'Your payment of %s has not been completed yet.', 'bachs-payment-forms' ), $amount )
		) . '</p>';

		$html .= '<form class="bachs-pf-retry" method="post" novalidate>';
		$html .= '<input type="hidden" name="action" value="bachs_pf_retry">';
		$html .= '<input type="hidden" name="reference" value="' . esc_attr( $payment->reference ) . '">';
		$html .= '<input type="hidden" name="key" value="' . esc_attr( $payment->access_key ) . '">';
		$html .= '<input type="hidden" name="bachs_pf_nonce" value="' . esc_attr( wp_create_nonce( Bachs_PF_Checkout::NONCE_ACTION ) ) . '">';
		$html .= '<div class="bachs-pf-message" role="alert" aria-live="assertive"></div>';
		/* translators: %s: amount */
		$html .= '<p class="bachs-pf-actions"><button type="submit" class="bachs-pf-button">' . esc_html( sprintf( __( 'Pay %s', 'bachs-payment-forms' ), $amount ) ) . '</button></p>';
		$html .= '</form>';

		return $html . '</div>';
	}

	/**
	 * @param string $id
	 * @param string $name
	 * @param string $label
	 * @param string $type
	 * @param string $value
	 * @param string $extra Pre-built attribute string (static, trusted).
	 * @return string
	 */
	protected static function input( $id, $name, $label, $type, $value, $extra = '' ) {
		return '<div class="bachs-pf-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ' <span class="bachs-pf-required" aria-hidden="true">*</span></label>'
			. '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" required ' . $extra . '></div>';
	}
}
