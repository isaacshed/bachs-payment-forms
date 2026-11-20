<?php
/**
 * Admin: form editor settings, forms list, payments, CSV export and plugin settings.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-bachs-pf-payments-table.php';

class Bachs_PF_Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'add_meta_boxes_' . Bachs_PF_Forms::POST_TYPE, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . Bachs_PF_Forms::POST_TYPE, array( __CLASS__, 'save_form' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_bachs_pf_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_bachs_pf_check', array( __CLASS__, 'check_payment' ) );
		add_filter( 'manage_' . Bachs_PF_Forms::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Bachs_PF_Forms::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'default_content', array( __CLASS__, 'default_content' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( BACHS_PF_MAIN_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	public static function menu() {
		$parent = 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE;
		add_submenu_page( $parent, __( 'Payments', 'bachs-payment-forms' ), __( 'Payments', 'bachs-payment-forms' ), self::CAP, 'bachs-pf-payments', array( __CLASS__, 'payments_page' ) );
		add_submenu_page( $parent, __( 'Bachs Settings', 'bachs-payment-forms' ), __( 'Settings', 'bachs-payment-forms' ), self::CAP, 'bachs-pf-settings', array( __CLASS__, 'settings_page' ) );
	}

	/**
	 * @param string $hook
	 */
	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || Bachs_PF_Forms::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'bachs-pf-admin', BACHS_PF_URL . '/assets/css/bachs-pf-admin.css', array(), bachs_pf_asset_version( 'assets/css/bachs-pf-admin.css' ) );
		wp_enqueue_script( 'bachs-pf-admin', BACHS_PF_URL . '/assets/js/bachs-pf-admin.js', array(), bachs_pf_asset_version( 'assets/js/bachs-pf-admin.js' ), true );
	}

	/**
	 * @param array $links
	 * @return array
	 */
	public static function plugin_links( $links ) {
		$url = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-settings' );
		return array_merge( array( 'settings' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bachs-payment-forms' ) . '</a>' ), $links );
	}

	/**
	 * Starter content for a new form.
	 *
	 * @param string  $content
	 * @param WP_Post $post
	 * @return string
	 */
	public static function default_content( $content, $post ) {
		if ( $post && Bachs_PF_Forms::POST_TYPE === $post->post_type && '' === $content ) {
			return __( 'Describe what the payer is paying for.', 'bachs-payment-forms' ) . "\n\n" . '[bachs_field type="tel" label="Phone number"]';
		}
		return $content;
	}

	/*
	|--------------------------------------------------------------------------
	| Notices
	|--------------------------------------------------------------------------
	*/

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( self::CAP ) ) {
			return;
		}

		// Only on the plugin's own screens (forms, payments, settings).
		$ours = Bachs_PF_Forms::POST_TYPE === $screen->post_type;
		if ( ! $ours ) {
			return;
		}

		$settings_url = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-settings' );

		if ( ! Bachs_PF_Settings::has_keys() ) {
			/* translators: %s: settings URL */
			echo '<div class="notice notice-error"><p>' . wp_kses_post( sprintf( __( 'Bachs Payment Forms: add your Bachs API key for the current mode in <a href="%s">Settings</a> before taking payments.', 'bachs-payment-forms' ), esc_url( $settings_url ) ) ) . '</p></div>';
		} elseif ( '' === Bachs_PF_Settings::get_webhook_secret() ) {
			/* translators: %s: settings URL */
			echo '<div class="notice notice-error"><p>' . wp_kses_post( sprintf( __( 'Bachs Payment Forms: add your webhook signing secret in <a href="%s">Settings</a>. Payments are only marked paid when Bachs\'s signed webhook arrives.', 'bachs-payment-forms' ), esc_url( $settings_url ) ) ) . '</p></div>';
		}

		if ( Bachs_PF_Settings::is_test_mode() ) {
			/* translators: %s: settings URL */
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'Bachs Payment Forms is in <strong>test mode</strong>. Switch it off in <a href="%s">Settings</a> to take real payments.', 'bachs-payment-forms' ), esc_url( $settings_url ) ) ) . '</p></div>';
		}

		$errors = get_transient( 'bachs_pf_form_errors_' . get_current_user_id() );
		if ( is_array( $errors ) && $errors ) {
			delete_transient( 'bachs_pf_form_errors_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Please check the payment settings:', 'bachs-payment-forms' ) . '</strong></p><ul>';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Form editor
	|--------------------------------------------------------------------------
	*/

	public static function meta_boxes() {
		add_meta_box( 'bachs-pf-settings', __( 'Payment settings', 'bachs-payment-forms' ), array( __CLASS__, 'settings_box' ), Bachs_PF_Forms::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'bachs-pf-shortcode', __( 'Shortcode', 'bachs-payment-forms' ), array( __CLASS__, 'shortcode_box' ), Bachs_PF_Forms::POST_TYPE, 'side', 'high' );
		add_meta_box( 'bachs-pf-fields-help', __( 'Adding fields', 'bachs-payment-forms' ), array( __CLASS__, 'fields_help_box' ), Bachs_PF_Forms::POST_TYPE, 'side' );
	}

	/**
	 * @param WP_Post $post
	 */
	public static function shortcode_box( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Publish the form to get its shortcode.', 'bachs-payment-forms' ) . '</p>';
			return;
		}
		$code = '[' . Bachs_PF_Renderer::SHORTCODE . ' id="' . (int) $post->ID . '"]';
		echo '<p>' . esc_html__( 'Paste this into any page or post:', 'bachs-payment-forms' ) . '</p>';
		echo '<input type="text" class="widefat code" readonly value="' . esc_attr( $code ) . '" onclick="this.select();">';
		if ( 'publish' === $post->post_status ) {
			$count = Bachs_PF_Payments::paid_count( $post->ID );
			$url   = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-payments&form_id=' . (int) $post->ID );
			/* translators: %d: number of payments */
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html( sprintf( _n( '%d paid payment', '%d paid payments', $count, 'bachs-payment-forms' ), $count ) ) . '</a></p>';
		}
	}

	public static function fields_help_box() {
		echo '<p>' . esc_html__( 'Name and email are always collected. Add more fields anywhere in the form content:', 'bachs-payment-forms' ) . '</p>';
		echo '<p><code>[bachs_field type="text" label="Company"]</code></p>';
		echo '<p><code>[bachs_field type="select" label="Size" options="S|M|L" required="yes"]</code></p>';
		echo '<p>' . esc_html__( 'Types: text, email, tel, number, url, date, textarea, select, radio, checkbox.', 'bachs-payment-forms' ) . '</p>';
	}

	/**
	 * @param WP_Post $post
	 */
	public static function settings_box( $post ) {
		$s        = Bachs_PF_Forms::get_settings( $post->ID );
		$cadences = Bachs_PF_Money::cadences();

		wp_nonce_field( 'bachs_pf_save_form', 'bachs_pf_form_nonce' );

		echo '<table class="form-table bachs-pf-settings" role="presentation"><tbody>';

		// Currency.
		echo '<tr><th scope="row"><label for="bachs-pf-currency">' . esc_html__( 'Currency', 'bachs-payment-forms' ) . '</label></th><td><select id="bachs-pf-currency" name="bachs_pf[currency]">';
		foreach ( Bachs_PF_Money::currencies() as $code ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $s['currency'], $code, false ) . '>' . esc_html( $code ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The main currency. With Adaptive Pricing on in your Bachs dashboard, a USD form also lets payers switch currency on the Bachs checkout page, at the live exchange rate.', 'bachs-payment-forms' ) . '</p>';

		// Optional: payer chooses among exact prices in several currencies.
		echo '<p><label><input type="checkbox" name="bachs_pf[multi_currency]" value="yes" class="bachs-pf-multi-toggle"' . checked( $s['multi_currency'], 'yes', false ) . '> ' . esc_html__( 'Let payers choose a currency on this form, at prices you set', 'bachs-payment-forms' ) . '</label></p>';
		echo '<div class="bachs-pf-show-multi">';
		echo '<table class="widefat striped bachs-pf-currency-table" style="max-width:560px"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Also accept', 'bachs-payment-forms' ) . '</th>';
		echo '<th scope="col" class="bachs-pf-col-fixed">' . esc_html__( 'Price', 'bachs-payment-forms' ) . '</th>';
		echo '<th scope="col" class="bachs-pf-col-custom">' . esc_html__( 'Minimum', 'bachs-payment-forms' ) . '</th>';
		echo '<th scope="col" class="bachs-pf-col-custom">' . esc_html__( 'Maximum', 'bachs-payment-forms' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( Bachs_PF_Money::currencies() as $code ) {
			$row  = isset( $s['currency_prices'][ $code ] ) ? $s['currency_prices'][ $code ] : array();
			$name = 'bachs_pf[currency_prices][' . $code . ']';
			$val  = function ( $key ) use ( $row ) {
				return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
			};
			echo '<tr data-currency="' . esc_attr( $code ) . '">';
			echo '<td><label><input type="checkbox" name="' . esc_attr( $name . '[enabled]' ) . '" value="1"' . checked( ! empty( $row ), true, false ) . '> ' . esc_html( $code ) . '</label></td>';
			echo '<td class="bachs-pf-col-fixed"><input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $name . '[amount]' ) . '" value="' . esc_attr( $val( 'amount' ) ) . '" aria-label="' . esc_attr( $code . ' ' . __( 'price', 'bachs-payment-forms' ) ) . '"></td>';
			echo '<td class="bachs-pf-col-custom"><input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $name . '[min_amount]' ) . '" value="' . esc_attr( $val( 'min_amount' ) ) . '" aria-label="' . esc_attr( $code . ' ' . __( 'minimum', 'bachs-payment-forms' ) ) . '"></td>';
			echo '<td class="bachs-pf-col-custom"><input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $name . '[max_amount]' ) . '" value="' . esc_attr( $val( 'max_amount' ) ) . '" aria-label="' . esc_attr( $code . ' ' . __( 'maximum', 'bachs-payment-forms' ) ) . '"></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description bachs-pf-show-options">' . esc_html__( 'For a list of amounts, add each extra currency\'s price to every option line, for example: Gold | 50 | NGN 75000 | GHS 600', 'bachs-payment-forms' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Payers are charged exactly the price for the currency they choose. Recurring payments are only available in USD; payers who choose another currency pay once.', 'bachs-payment-forms' ) . '</p>';
		echo '</div>';
		echo '</td></tr>';

		// Amount type.
		$types = array(
			'fixed'   => __( 'Fixed amount', 'bachs-payment-forms' ),
			'custom'  => __( 'Payer enters the amount (donations, invoices)', 'bachs-payment-forms' ),
			'options' => __( 'Payer chooses from a list', 'bachs-payment-forms' ),
		);
		echo '<tr><th scope="row">' . esc_html__( 'Amount', 'bachs-payment-forms' ) . '</th><td><fieldset>';
		foreach ( $types as $value => $label ) {
			echo '<label><input type="radio" name="bachs_pf[amount_type]" value="' . esc_attr( $value ) . '"' . checked( $s['amount_type'], $value, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '</fieldset>';
		echo '<p class="bachs-pf-show-fixed"><label>' . esc_html__( 'Amount', 'bachs-payment-forms' ) . ' <input type="text" inputmode="decimal" name="bachs_pf[amount]" value="' . esc_attr( $s['amount'] ) . '" class="small-text"></label></p>';
		echo '<p class="bachs-pf-show-custom"><label>' . esc_html__( 'Minimum', 'bachs-payment-forms' ) . ' <input type="text" inputmode="decimal" name="bachs_pf[min_amount]" value="' . esc_attr( $s['min_amount'] ) . '" class="small-text"></label> ';
		echo '<label>' . esc_html__( 'Maximum', 'bachs-payment-forms' ) . ' <input type="text" inputmode="decimal" name="bachs_pf[max_amount]" value="' . esc_attr( $s['max_amount'] ) . '" class="small-text"></label> <span class="description">' . esc_html__( 'Both optional.', 'bachs-payment-forms' ) . '</span></p>';
		echo '<p class="bachs-pf-show-options"><label for="bachs-pf-options">' . esc_html__( 'One option per line, as "Label | amount":', 'bachs-payment-forms' ) . '</label><br><textarea id="bachs-pf-options" name="bachs_pf[options]" rows="4" class="large-text code" placeholder="Bronze | 10&#10;Silver | 25&#10;Gold | 50">' . esc_textarea( $s['options'] ) . '</textarea></p>';
		echo '</td></tr>';

		// Quantity and stock.
		echo '<tr><th scope="row">' . esc_html__( 'Quantity', 'bachs-payment-forms' ) . '</th><td>';
		echo '<label><input type="checkbox" name="bachs_pf[quantity]" value="yes"' . checked( $s['quantity'], 'yes', false ) . '> ' . esc_html__( 'Let the payer choose a quantity', 'bachs-payment-forms' ) . '</label>';
		echo '<p><label>' . esc_html__( 'Maximum per payment', 'bachs-payment-forms' ) . ' <input type="number" min="1" max="1000" name="bachs_pf[max_quantity]" value="' . esc_attr( (string) $s['max_quantity'] ) . '" class="small-text"></label> ';
		echo '<label>' . esc_html__( 'Label', 'bachs-payment-forms' ) . ' <input type="text" name="bachs_pf[quantity_label]" value="' . esc_attr( $s['quantity_label'] ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Stock', 'bachs-payment-forms' ) . ' <input type="number" min="0" name="bachs_pf[stock]" value="' . esc_attr( (string) $s['stock'] ) . '" class="small-text"></label> <span class="description">';
		if ( '' !== (string) $s['stock'] && 'auto-draft' !== $post->post_status ) {
			/* translators: %d: units sold */
			echo esc_html( sprintf( __( 'Leave empty for unlimited. %d sold so far.', 'bachs-payment-forms' ), Bachs_PF_Payments::sold_quantity( $post->ID ) ) );
		} else {
			echo esc_html__( 'Leave empty for unlimited.', 'bachs-payment-forms' );
		}
		echo '</span></p></td></tr>';

		// Recurring.
		$recurring = array(
			'none'     => __( 'One-time payments only', 'bachs-payment-forms' ),
			'fixed'    => __( 'Always recurring', 'bachs-payment-forms' ),
			'optional' => __( 'Payer chooses one-time or recurring', 'bachs-payment-forms' ),
		);
		echo '<tr><th scope="row">' . esc_html__( 'Recurring', 'bachs-payment-forms' ) . '</th><td><fieldset>';
		foreach ( $recurring as $value => $label ) {
			echo '<label><input type="radio" name="bachs_pf[recurring]" value="' . esc_attr( $value ) . '"' . checked( $s['recurring'], $value, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '</fieldset>';
		echo '<p class="bachs-pf-show-recurring-fixed"><label>' . esc_html__( 'Billing', 'bachs-payment-forms' ) . ' <select name="bachs_pf[cadence]">';
		foreach ( $cadences as $key => $cadence ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $s['cadence'], $key, false ) . '>' . esc_html( $cadence[2] ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p class="bachs-pf-show-recurring-optional">' . esc_html__( 'Schedules offered:', 'bachs-payment-forms' ) . ' ';
		foreach ( $cadences as $key => $cadence ) {
			echo '<label><input type="checkbox" name="bachs_pf[cadences][]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $s['cadences'], true ), true, false ) . '> ' . esc_html( $cadence[2] ) . '</label> ';
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Recurring payments use Bachs subscriptions, which currently bill USD cards only. You can cancel a subscription from your Bachs dashboard.', 'bachs-payment-forms' ) . '</p>';
		echo '</td></tr>';

		// Checkout and after payment.
		echo '<tr><th scope="row"><label for="bachs-pf-button">' . esc_html__( 'Button label', 'bachs-payment-forms' ) . '</label></th><td><input type="text" id="bachs-pf-button" name="bachs_pf[button_label]" value="' . esc_attr( $s['button_label'] ) . '" class="regular-text"></td></tr>';
		echo '<tr><th scope="row"><label for="bachs-pf-success">' . esc_html__( 'Success message', 'bachs-payment-forms' ) . '</label></th><td><textarea id="bachs-pf-success" name="bachs_pf[success_message]" rows="2" class="large-text">' . esc_textarea( $s['success_message'] ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="bachs-pf-redirect">' . esc_html__( 'Redirect after payment', 'bachs-payment-forms' ) . '</label></th><td><input type="url" id="bachs-pf-redirect" name="bachs_pf[redirect_url]" value="' . esc_attr( $s['redirect_url'] ) . '" class="regular-text" placeholder="https://"><p class="description">' . esc_html__( 'Optional. Leave empty to show the success message on the form\'s page. A redirect page should also contain this form\'s shortcode, so the payer can see their payment status.', 'bachs-payment-forms' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="bachs-pf-terms">' . esc_html__( 'Terms page', 'bachs-payment-forms' ) . '</label></th><td><input type="url" id="bachs-pf-terms" name="bachs_pf[terms_url]" value="' . esc_attr( $s['terms_url'] ) . '" class="regular-text" placeholder="https://"><p class="description">' . esc_html__( 'Optional. When set, payers must tick a box accepting these terms.', 'bachs-payment-forms' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Options', 'bachs-payment-forms' ) . '</th><td>';
		echo '<label><input type="checkbox" name="bachs_pf[require_login]" value="yes"' . checked( $s['require_login'], 'yes', false ) . '> ' . esc_html__( 'Only logged-in users can pay', 'bachs-payment-forms' ) . '</label><br>';
		echo '<label><input type="checkbox" name="bachs_pf[send_receipt]" value="yes"' . checked( $s['send_receipt'], 'yes', false ) . '> ' . esc_html__( 'Email the payer a receipt', 'bachs-payment-forms' ) . '</label><br>';
		echo '<label><input type="checkbox" name="bachs_pf[notify_admin]" value="yes"' . checked( $s['notify_admin'], 'yes', false ) . '> ' . esc_html__( 'Email me about each payment', 'bachs-payment-forms' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function save_form( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( ! isset( $_POST['bachs_pf_form_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bachs_pf_form_nonce'] ) ), 'bachs_pf_save_form' ) ) {
			return;
		}

		// Unchecked checkboxes are absent from POST.
		// Sanitised as soon as it is read; sanitize_settings() then validates each setting by its type.
		$input = isset( $_POST['bachs_pf'] ) && is_array( $_POST['bachs_pf'] ) ? map_deep( wp_unslash( $_POST['bachs_pf'] ), array( __CLASS__, 'sanitize_setting_value' ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- map_deep() sanitises every value.
		foreach ( array( 'quantity', 'multi_currency', 'require_login', 'send_receipt', 'notify_admin' ) as $box ) {
			if ( ! isset( $input[ $box ] ) ) {
				$input[ $box ] = 'no';
			}
		}

		$result = Bachs_PF_Forms::sanitize_settings( $input );
		update_post_meta( $post_id, Bachs_PF_Forms::META_SETTINGS, $result['settings'] );

		if ( $result['errors'] ) {
			set_transient( 'bachs_pf_form_errors_' . get_current_user_id(), $result['errors'], 60 );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Forms list
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param array $columns
	 * @return array
	 */
	public static function columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : '';
		unset( $columns['date'] );
		$columns['bachs_pf_shortcode'] = __( 'Shortcode', 'bachs-payment-forms' );
		$columns['bachs_pf_amount']    = __( 'Amount', 'bachs-payment-forms' );
		$columns['bachs_pf_payments']  = __( 'Paid', 'bachs-payment-forms' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	/**
	 * @param string $column
	 * @param int    $post_id
	 */
	public static function column( $column, $post_id ) {
		$s = Bachs_PF_Forms::get_settings( $post_id );

		switch ( $column ) {
			case 'bachs_pf_shortcode':
				echo '<code>' . esc_html( '[' . Bachs_PF_Renderer::SHORTCODE . ' id="' . (int) $post_id . '"]' ) . '</code>';
				break;
			case 'bachs_pf_amount':
				if ( 'fixed' === $s['amount_type'] ) {
					echo esc_html( Bachs_PF_Money::display( $s['amount'], $s['currency'] ) );
				} elseif ( 'options' === $s['amount_type'] ) {
					/* translators: %s: currency code */
					echo esc_html( sprintf( __( 'Choice of amounts (%s)', 'bachs-payment-forms' ), $s['currency'] ) );
				} else {
					/* translators: %s: currency code */
					echo esc_html( sprintf( __( 'Payer enters amount (%s)', 'bachs-payment-forms' ), $s['currency'] ) );
				}
				if ( 'yes' === $s['multi_currency'] && count( Bachs_PF_Forms::form_currencies( $s ) ) > 1 ) {
					/* translators: %s: comma-separated currency codes */
					echo '<br><span class="description">' . esc_html( sprintf( __( 'Also in %s', 'bachs-payment-forms' ), implode( ', ', array_slice( Bachs_PF_Forms::form_currencies( $s ), 1 ) ) ) ) . '</span>';
				}
				if ( 'none' !== $s['recurring'] ) {
					echo '<br><span class="description">' . esc_html( 'fixed' === $s['recurring'] ? Bachs_PF_Money::cadence_label( $s['cadence'] ) : __( 'One-time or recurring', 'bachs-payment-forms' ) ) . '</span>';
				}
				break;
			case 'bachs_pf_payments':
				$url = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-payments&form_id=' . (int) $post_id );
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( number_format_i18n( Bachs_PF_Payments::paid_count( $post_id ) ) ) . '</a>';
				break;
		}
	}

	/**
	 * @param array   $actions
	 * @param WP_Post $post
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( Bachs_PF_Forms::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'], $actions['view'] );
		$url                          = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-payments&form_id=' . (int) $post->ID );
		$actions['bachs_pf_payments'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View payments', 'bachs-payment-forms' ) . '</a>';
		return $actions;
	}

	/*
	|--------------------------------------------------------------------------
	| Payments
	|--------------------------------------------------------------------------
	*/

	public static function payments_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view payments.', 'bachs-payment-forms' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$payment_id = isset( $_GET['payment'] ) ? absint( $_GET['payment'] ) : 0;
		if ( $payment_id ) {
			self::payment_detail( $payment_id );
			return;
		}

		$table = new Bachs_PF_Payments_Table();
		$table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$form_id    = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'bachs_pf_export',
					'form_id' => $form_id,
				),
				admin_url( 'admin-post.php' )
			),
			'bachs_pf_export'
		);

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Payments', 'bachs-payment-forms' );
		if ( $form_id ) {
			echo ': ' . esc_html( get_the_title( $form_id ) );
		}
		echo '</h1> <a href="' . esc_url( $export_url ) . '" class="page-title-action">' . esc_html__( 'Export CSV', 'bachs-payment-forms' ) . '</a><hr class="wp-header-end">';

		$health = self::webhook_health();
		if ( 'ok' !== $health['state'] ) {
			$settings_url = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-settings' );
			echo '<div class="notice notice-warning"><p>' . esc_html( $health['text'] ) . ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Webhook settings', 'bachs-payment-forms' ) . '</a></p></div>';
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( Bachs_PF_Forms::POST_TYPE ) . '">';
		echo '<input type="hidden" name="page" value="bachs-pf-payments">';
		if ( $form_id ) {
			echo '<input type="hidden" name="form_id" value="' . (int) $form_id . '">';
		}
		$table->views();
		$table->search_box( __( 'Search payments', 'bachs-payment-forms' ), 'bachs-pf-search' );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * @param int $payment_id
	 */
	protected static function payment_detail( $payment_id ) {
		$payment = Bachs_PF_Payments::get( $payment_id );
		$back    = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-payments' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Payment', 'bachs-payment-forms' ) . '</h1><p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All payments', 'bachs-payment-forms' ) . '</a></p>';

		if ( ! $payment ) {
			echo '<p>' . esc_html__( 'Payment not found.', 'bachs-payment-forms' ) . '</p></div>';
			return;
		}

		$rows = array(
			__( 'Status', 'bachs-payment-forms' )      => Bachs_PF_Payments_Table::status_label( $payment->status ),
			__( 'Form', 'bachs-payment-forms' )        => get_the_title( (int) $payment->form_id ),
			__( 'Name', 'bachs-payment-forms' )        => $payment->name,
			__( 'Email', 'bachs-payment-forms' )       => $payment->email,
			__( 'Amount', 'bachs-payment-forms' )      => Bachs_PF_Money::display( $payment->amount, $payment->currency ),
			__( 'Quantity', 'bachs-payment-forms' )    => (string) (int) $payment->quantity,
			__( 'Billing', 'bachs-payment-forms' )     => Bachs_PF_Money::cadence_label( $payment->cadence ),
			__( 'Reference', 'bachs-payment-forms' )   => $payment->reference,
			__( 'Environment', 'bachs-payment-forms' ) => 'sandbox' === $payment->mode ? __( 'Test (sandbox)', 'bachs-payment-forms' ) : __( 'Live', 'bachs-payment-forms' ),
			__( 'Created', 'bachs-payment-forms' )     => get_date_from_gmt( $payment->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);
		if ( $payment->paid_at ) {
			$rows[ __( 'Paid', 'bachs-payment-forms' ) ] = get_date_from_gmt( $payment->paid_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		}
		if ( '' !== $payment->paid_amount ) {
			$rows[ __( 'Amount received', 'bachs-payment-forms' ) ] = trim( $payment->paid_amount . ' ' . $payment->paid_currency );
		}
		if ( '' !== $payment->settlement_amount ) {
			$rows[ __( 'Settled', 'bachs-payment-forms' ) ] = trim( $payment->settlement_amount . ' ' . $payment->settlement_currency );
		}
		foreach ( array(
			'checkout_id'         => __( 'Bachs checkout', 'bachs-payment-forms' ),
			'charge_id'           => __( 'Bachs charge', 'bachs-payment-forms' ),
			'customer_id'         => __( 'Bachs customer', 'bachs-payment-forms' ),
			'subscription_id'     => __( 'Bachs subscription', 'bachs-payment-forms' ),
			'subscription_status' => __( 'Subscription status', 'bachs-payment-forms' ),
		) as $column => $label ) {
			if ( '' !== (string) $payment->{$column} ) {
				$rows[ $label ] = $payment->{$column};
			}
		}
		if ( ! empty( $payment->review_reason ) ) {
			$rows[ __( 'Needs review', 'bachs-payment-forms' ) ] = $payment->review_reason;
		}
		foreach ( (array) $payment->fields as $field ) {
			if ( isset( $field['label'], $field['value'] ) ) {
				$rows[ (string) $field['label'] ] = (string) $field['value'];
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only result flag set by check_payment().
		$checked = isset( $_GET['checked'] ) ? sanitize_key( wp_unslash( $_GET['checked'] ) ) : '';
		if ( '' !== $checked ) {
			$messages = array(
				'paid'    => array( 'success', __( 'Bachs confirms this payment was made. It is now marked paid.', 'bachs-payment-forms' ) ),
				'review'  => array( 'warning', __( 'Bachs confirms a payment was made, but it needs review: see "Needs review" below.', 'bachs-payment-forms' ) ),
				'expired' => array( 'info', __( 'Bachs reports that this checkout ended without payment.', 'bachs-payment-forms' ) ),
				'pending' => array( 'info', __( 'Bachs has not received this payment yet.', 'bachs-payment-forms' ) ),
				'failed'  => array( 'info', __( 'Bachs has not received this payment yet.', 'bachs-payment-forms' ) ),
				'error'   => array( 'error', __( 'Could not reach Bachs. Check your API key in Settings and try again.', 'bachs-payment-forms' ) ),
			);
			if ( isset( $messages[ $checked ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $messages[ $checked ][0] ) . '"><p>' . esc_html( $messages[ $checked ][1] ) . '</p></div>';
			}
		}

		echo '<table class="widefat striped" style="max-width:800px"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row" style="width:220px">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( (string) $value ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( in_array( $payment->status, Bachs_PF_Payments::UNSETTLED, true ) && '' !== $payment->checkout_id && 0 === (int) $payment->parent_id ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px">';
			echo '<input type="hidden" name="action" value="bachs_pf_check">';
			echo '<input type="hidden" name="payment" value="' . (int) $payment->id . '">';
			wp_nonce_field( 'bachs_pf_check_' . (int) $payment->id );
			submit_button( __( 'Check status with Bachs', 'bachs-payment-forms' ), 'secondary', 'submit', false );
			echo '<p class="description">' . esc_html__( 'Asks Bachs whether this checkout was paid and updates the payment. Payments are also checked automatically every hour for three days.', 'bachs-payment-forms' ) . '</p>';
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Admin action: check one payment's status with Bachs.
	 */
	public static function check_payment() {
		$payment_id = isset( $_POST['payment'] ) ? absint( $_POST['payment'] ) : 0;

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'bachs-payment-forms' ), 403 );
		}
		check_admin_referer( 'bachs_pf_check_' . $payment_id );

		$payment = Bachs_PF_Payments::get( $payment_id );
		$result  = 'error';
		if ( $payment ) {
			$check  = Bachs_PF_Checkout::reconcile( $payment );
			$result = 'error' === $check['session']['status'] ? 'error' : $check['payment']->status;
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-payments&payment=' . $payment_id . '&checked=' . rawurlencode( $result ) ) );
		exit;
	}

	/**
	 * Webhook health, for the settings page and the payments screen.
	 *
	 * @return array{state: string, text: string} state: ok, error or none.
	 */
	public static function webhook_health() {
		$status = Bachs_PF_Webhook::delivery_status();

		if ( 'error' === $status['last_state'] ) {
			return array(
				'state' => 'error',
				'text'  => sprintf(
					/* translators: 1: time ago, 2: reason */
					__( 'The last webhook from Bachs, %1$s ago, was rejected: %2$s', 'bachs-payment-forms' ),
					human_time_diff( $status['last_error'] ),
					$status['last_error_reason']
				),
			);
		}
		if ( $status['last_ok'] ) {
			return array(
				'state' => 'ok',
				'text'  => sprintf(
					/* translators: 1: time ago, 2: event type */
					__( 'Working. Last webhook received %1$s ago (%2$s).', 'bachs-payment-forms' ),
					human_time_diff( $status['last_ok'] ),
					$status['last_ok_type']
				),
			);
		}
		return array(
			'state' => 'none',
			'text'  => __( 'No webhook received from Bachs yet. Payments are still confirmed when payers return to your site and by an hourly check, but set up the webhook so they are confirmed instantly.', 'bachs-payment-forms' ),
		);
	}

	/**
	 * Stream payments as CSV. Values that a spreadsheet would run as a formula are neutralised.
	 */
	public static function export_csv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to export payments.', 'bachs-payment-forms' ), 403 );
		}
		check_admin_referer( 'bachs_pf_export' );

		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

		$filename = 'bachs-payments' . ( $form_id ? '-form-' . $form_id : '' ) . '-' . gmdate( 'Y-m-d' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 BOM for spreadsheet apps.

		$rows = self::csv_rows( $form_id );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream.
		exit;
	}

	/**
	 * @param int $form_id 0 for all forms.
	 * @return array[] Header row first.
	 */
	public static function csv_rows( $form_id ) {
		$page    = 1;
		$labels  = array();
		$records = array();

		do {
			$result = Bachs_PF_Payments::query(
				array(
					'form_id'  => $form_id,
					'per_page' => 500,
					'page'     => $page,
					'order'    => 'ASC',
				)
			);
			foreach ( $result['items'] as $payment ) {
				foreach ( (array) $payment->fields as $key => $field ) {
					if ( isset( $field['label'] ) ) {
						$labels[ $key ] = (string) $field['label'];
					}
				}
				$records[] = $payment;
			}
			++$page;
		} while ( count( $result['items'] ) === 500 );

		$header = array( 'ID', 'Created (UTC)', 'Paid (UTC)', 'Status', 'Type', 'Form', 'Name', 'Email', 'Amount', 'Currency', 'Quantity', 'Billing', 'Reference', 'Bachs charge', 'Bachs subscription', 'Environment', 'Review reason' );
		$rows   = array( array_merge( $header, array_values( $labels ) ) );

		foreach ( $records as $p ) {
			$row = array(
				$p->id,
				$p->created_at,
				(string) $p->paid_at,
				$p->status,
				$p->type,
				get_the_title( (int) $p->form_id ),
				$p->name,
				$p->email,
				$p->amount,
				$p->currency,
				$p->quantity,
				$p->cadence,
				$p->reference,
				$p->charge_id,
				$p->subscription_id,
				$p->mode,
				(string) $p->review_reason,
			);
			foreach ( array_keys( $labels ) as $key ) {
				$row[] = isset( $p->fields[ $key ]['value'] ) ? (string) $p->fields[ $key ]['value'] : '';
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Prefix values starting with = + - @ tab or CR so spreadsheets don't run them as formulas.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/*
	|--------------------------------------------------------------------------
	| Plugin settings
	|--------------------------------------------------------------------------
	*/

	public static function register_settings() {
		register_setting(
			'bachs_pf_settings_group',
			Bachs_PF_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_plugin_settings' ),
				'default'           => Bachs_PF_Settings::defaults(),
			)
		);
	}

	/**
	 * Secret fields left blank keep their saved value, so they never have to be re-entered or
	 * printed back into the page.
	 *
	 * @param mixed $input
	 * @return array
	 */
	public static function sanitize_plugin_settings( $input ) {
		$current = Bachs_PF_Settings::all();
		$input   = is_array( $input ) ? $input : array();
		$out     = $current;

		$out['testmode']     = isset( $input['testmode'] ) && 'yes' === $input['testmode'] ? 'yes' : 'no';
		$out['debug']        = isset( $input['debug'] ) && 'yes' === $input['debug'] ? 'yes' : 'no';
		$out['delete_data']  = isset( $input['delete_data'] ) && 'yes' === $input['delete_data'] ? 'yes' : 'no';
		$out['notify_email'] = isset( $input['notify_email'] ) ? sanitize_email( (string) $input['notify_email'] ) : '';

		foreach ( array( 'test_api_key', 'test_webhook_secret', 'live_api_key', 'live_webhook_secret' ) as $key ) {
			$value = isset( $input[ $key ] ) ? trim( sanitize_text_field( (string) $input[ $key ] ) ) : '';
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
			if ( ! empty( $input[ 'clear_' . $key ] ) ) {
				$out[ $key ] = '';
			}
		}

		Bachs_PF_API::reset();

		return $out;
	}

	public static function settings_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$s      = Bachs_PF_Settings::all();
		$option = Bachs_PF_Settings::OPTION;

		echo '<div class="wrap"><h1>' . esc_html__( 'Bachs Payment Forms settings', 'bachs-payment-forms' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'bachs_pf_settings_group' );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Test mode', 'bachs-payment-forms' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $option ) . '[testmode]" value="yes"' . checked( $s['testmode'], 'yes', false ) . '> ' . esc_html__( 'Use the Bachs sandbox and sandbox keys', 'bachs-payment-forms' ) . '</label></td></tr>';

		$secrets = array(
			'test_api_key'        => __( 'Sandbox API key', 'bachs-payment-forms' ),
			'test_webhook_secret' => __( 'Sandbox webhook signing secret', 'bachs-payment-forms' ),
			'live_api_key'        => __( 'Live API key', 'bachs-payment-forms' ),
			'live_webhook_secret' => __( 'Live webhook signing secret', 'bachs-payment-forms' ),
		);
		foreach ( $secrets as $key => $label ) {
			$saved = '' !== (string) $s[ $key ];
			echo '<tr><th scope="row"><label for="bachs-pf-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="password" id="bachs-pf-' . esc_attr( $key ) . '" name="' . esc_attr( $option ) . '[' . esc_attr( $key ) . ']" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr( $saved ? __( 'Saved. Leave blank to keep.', 'bachs-payment-forms' ) : '' ) . '">';
			if ( $saved ) {
				echo ' <label><input type="checkbox" name="' . esc_attr( $option ) . '[clear_' . esc_attr( $key ) . ']" value="1"> ' . esc_html__( 'Remove', 'bachs-payment-forms' ) . '</label>';
			}
			echo '</td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'Webhook URL', 'bachs-payment-forms' ) . '</th><td><input type="text" class="large-text code" readonly value="' . esc_attr( Bachs_PF_Settings::webhook_url() ) . '" onclick="this.select();">';
		echo '<p class="description">' . esc_html__( 'Add this URL as a webhook endpoint in your Bachs developer portal (sandbox and live), subscribe it to these events, then paste each signing secret above:', 'bachs-payment-forms' ) . '</p>';
		echo '<p><code>collection.succeeded</code> <code>collection.underpaid</code> <code>collection.failed</code> <code>checkout.expired</code> <code>customer.subscription.created</code> <code>customer.subscription.updated</code> <code>customer.subscription.deleted</code> <code>invoice.paid</code></p></td></tr>';

		$health = self::webhook_health();
		$color  = array(
			'ok'    => '#00a32a',
			'error' => '#d63638',
			'none'  => '#dba617',
		);
		echo '<tr><th scope="row">' . esc_html__( 'Webhook status', 'bachs-payment-forms' ) . '</th><td><p><span aria-hidden="true" style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;background:' . esc_attr( $color[ $health['state'] ] ) . '"></span>' . esc_html( $health['text'] ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="bachs-pf-notify">' . esc_html__( 'Notification email', 'bachs-payment-forms' ) . '</label></th><td><input type="email" id="bachs-pf-notify" name="' . esc_attr( $option ) . '[notify_email]" value="' . esc_attr( $s['notify_email'] ) . '" class="regular-text" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '"><p class="description">' . esc_html__( 'Where payment notifications go. Defaults to the site admin email.', 'bachs-payment-forms' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Debug log', 'bachs-payment-forms' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $option ) . '[debug]" value="yes"' . checked( $s['debug'], 'yes', false ) . '> ' . esc_html__( 'Log informational events too (errors are always logged)', 'bachs-payment-forms' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Uninstall', 'bachs-payment-forms' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $option ) . '[delete_data]" value="yes"' . checked( isset( $s['delete_data'] ) ? $s['delete_data'] : 'no', 'yes', false ) . '> ' . esc_html__( 'Delete all forms and payment records when the plugin is deleted', 'bachs-payment-forms' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Sanitise one posted setting value: plain text with line breaks kept (the success message is
	 * multi-line). URLs keep their percent-encoding, which text sanitising would strip, and are
	 * checked again with esc_url_raw() in Bachs_PF_Forms::sanitize_settings().
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function sanitize_setting_value( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$trimmed = trim( $value );
		if ( preg_match( '#^https?://#i', $trimmed ) ) {
			return esc_url_raw( $trimmed, array( 'http', 'https' ) );
		}
		return sanitize_textarea_field( $value );
	}
}
