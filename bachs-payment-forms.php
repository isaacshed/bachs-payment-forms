<?php
/**
 * Plugin Name: Bachs Payment Forms
 * Plugin URI: https://bachs.io/wordpress/
 * Description: Create payment forms that collect one-time or recurring payments through Bachs: card, bank transfer, mobile money and crypto, in different currencies.
 * Version: 1.0.0
 * Author: Bachs
 * Author URI: https://bachs.io
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: bachs-payment-forms
 * Domain Path: /languages
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BACHS_PF_VERSION', '1.0.0' );
define( 'BACHS_PF_DB_VERSION', '1' );
define( 'BACHS_PF_MAIN_FILE', __FILE__ );
define( 'BACHS_PF_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'BACHS_PF_URL', untrailingslashit( plugins_url( '/', __FILE__ ) ) );

require_once BACHS_PF_PATH . '/includes/class-bachs-pf-settings.php';
require_once BACHS_PF_PATH . '/includes/class-bachs-pf-api.php';
require_once BACHS_PF_PATH . '/includes/class-bachs-pf-money.php';
require_once BACHS_PF_PATH . '/includes/class-bachs-pf-payments.php';
require_once BACHS_PF_PATH . '/includes/class-bachs-pf-forms.php';
require_once BACHS_PF_PATH . '/includes/class-bachs-pf-fields.php';
require_once BACHS_PF_PATH . '/includes/class-bachs-pf-checkout.php';
require_once BACHS_PF_PATH . '/includes/class-bachs-pf-renderer.php';
require_once BACHS_PF_PATH . '/includes/class-bachs-pf-webhook.php';
require_once BACHS_PF_PATH . '/includes/class-bachs-pf-emails.php';

if ( is_admin() ) {
	require_once BACHS_PF_PATH . '/includes/admin/class-bachs-pf-admin.php';
}

register_activation_hook( __FILE__, array( 'Bachs_PF_Payments', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Bachs_PF_Checkout', 'unschedule' ) );

/**
 * Wire up the plugin.
 */
function bachs_pf_init() {
	Bachs_PF_Payments::maybe_upgrade();
	Bachs_PF_Forms::init();
	Bachs_PF_Renderer::init();
	Bachs_PF_Checkout::init();
	Bachs_PF_Webhook::init();
	Bachs_PF_Emails::init();

	if ( is_admin() ) {
		Bachs_PF_Admin::init();
	}
}
add_action( 'plugins_loaded', 'bachs_pf_init' );

/**
 * Version string for a bundled asset: the plugin version plus the file's modification time, so
 * browsers and caches fetch a new copy whenever the file changes.
 *
 * @param string $relative Path inside the plugin, e.g. 'assets/js/bachs-pf.js'.
 * @return string
 */
function bachs_pf_asset_version( $relative ) {
	$file = BACHS_PF_PATH . '/' . ltrim( $relative, '/' );
	return file_exists( $file ) ? BACHS_PF_VERSION . '.' . filemtime( $file ) : BACHS_PF_VERSION;
}

/**
 * Log to the PHP error log only when WP_DEBUG_LOG is on, through a filterable hook so sites can
 * route it elsewhere.
 *
 * @param string $message
 * @param string $level
 */
function bachs_pf_log( $message, $level = 'info' ) {
	do_action( 'bachs_pf_log', $message, $level );

	if ( 'info' === $level && 'yes' !== Bachs_PF_Settings::get( 'debug', 'no' ) ) {
		return;
	}

	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array( 'source' => 'bachs-payment-forms' ) );
		return;
	}

	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in debug logging, only when WP_DEBUG_LOG is enabled.
		error_log( '[bachs-payment-forms] [' . $level . '] ' . $message );
	}
}
