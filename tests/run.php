<?php
/**
 * Plain-PHP tests. Run: php tests/run.php
 *
 * @package Bachs_Payment_Forms
 */

// phpcs:ignoreFile

require __DIR__ . '/bootstrap.php';

$passes   = 0;
$failures = 0;

function check( $label, $condition ) {
	global $passes, $failures;
	if ( $condition ) {
		++$passes;
		return;
	}
	++$failures;
	echo "FAIL: {$label}\n";
}

function setup( $settings = array() ) {
	t_reset();
	update_option(
		'bachs_pf_settings',
		array_merge(
			array(
				'testmode'            => 'yes',
				'test_api_key'        => 'sk_sandbox_1',
				'test_webhook_secret' => 'whsec_sandbox',
				'live_api_key'        => 'sk_live_1',
				'live_webhook_secret' => 'whsec_live',
			),
			$settings
		)
	);
}

function make_form( $settings = array(), $content = '', $id = 10 ) {
	$post = (object) array(
		'ID'           => $id,
		'post_type'    => 'bachs_form',
		'post_status'  => 'publish',
		'post_title'   => 'Donate',
		'post_content' => $content,
	);
	$GLOBALS['T']['posts'][ $id ] = $post;
	update_post_meta( $id, Bachs_PF_Forms::META_SETTINGS, array_merge( Bachs_PF_Forms::default_settings(), $settings ) );
	return $post;
}

function base_input( $extra = array() ) {
	return array_merge(
		array(
			'bachs_pf_name'  => 'Ada Obi',
			'bachs_pf_email' => 'ada@example.com',
			'bachs_pf_page'  => 'https://site.example/donate/',
		),
		$extra
	);
}

function session_ok( $id = 'chk_1' ) {
	t_queue( 201, array( 'checkout_id' => $id, 'checkout_url' => 'https://checkout.bachs.io/c/' . $id, 'status' => 'open' ) );
}

function last_request() {
	return end( $GLOBALS['T']['requests'] );
}

function actions( $hook ) {
	return array_values( array_filter( $GLOBALS['T']['actions'], function ( $a ) use ( $hook ) { return $a[0] === $hook; } ) );
}

function event( $id, $type, $data ) {
	return (object) array( 'id' => $id, 'type' => $type, 'data' => json_decode( json_encode( $data ) ) );
}

// --- Money -----------------------------------------------------------------------------

check( 'parse plain', 1500.5 === Bachs_PF_Money::parse( '1,500.50' ) );
check( 'parse rejects text', null === Bachs_PF_Money::parse( '12abc' ) );
check( 'parse rejects negatives', null === Bachs_PF_Money::parse( '-5' ) );
check( 'parse rejects 3 decimals', null === Bachs_PF_Money::parse( '1.005' ) );
check( 'format 2 decimals', '25000.00' === Bachs_PF_Money::format( 25000 ) );
check( 'recurring only USD', Bachs_PF_Money::supports_recurring( 'USD' ) && ! Bachs_PF_Money::supports_recurring( 'NGN' ) );

// --- Form settings -----------------------------------------------------------------------

setup();
$r = Bachs_PF_Forms::sanitize_settings( array( 'currency' => 'NGN', 'amount_type' => 'fixed', 'amount' => '5000', 'recurring' => 'fixed' ) );
check( 'recurring in NGN falls back to one-time', 'none' === $r['settings']['recurring'] && count( $r['errors'] ) === 1 );
$r = Bachs_PF_Forms::sanitize_settings( array( 'amount_type' => 'fixed', 'amount' => '' ) );
check( 'fixed form needs an amount', ! empty( $r['errors'] ) );
$r = Bachs_PF_Forms::sanitize_settings( array( 'amount_type' => 'options', 'options' => "Bronze | 10\nbad line\nGold|50" ) );
check( 'options parsed and normalised', "Bronze | 10.00\nGold | 50.00" === $r['settings']['options'] && 1 === count( $r['errors'] ) );
$r = Bachs_PF_Forms::sanitize_settings( array( 'currency' => 'EUR', 'amount' => '5' ) );
check( 'unsupported currency falls back to USD', 'USD' === $r['settings']['currency'] );
$r = Bachs_PF_Forms::sanitize_settings( array( 'amount' => '5', 'redirect_url' => 'javascript:alert(1)' ) );
check( 'redirect URL must be http(s)', '' === $r['settings']['redirect_url'] );

// --- Fields ------------------------------------------------------------------------------

$content = 'Intro [bachs_field type="tel" label="Phone" required="yes"] [bachs_field type="select" label="Size" options="S|M|L"] [bachs_field type="checkbox" label="Extras" options="Gift wrap|Card"] [[bachs_field label="Escaped"]]';
$defs    = Bachs_PF_Fields::definitions( $content );
check( 'three fields parsed, escaped one ignored', array( 'phone', 'size', 'extras' ) === array_keys( $defs ) );
$v = Bachs_PF_Fields::validate( $defs, array( 'phone' => '0803', 'size' => 'XL', 'extras' => array( 'Card' ), 'injected' => 'x' ) );
check( 'invalid select option rejected', 1 === count( $v['errors'] ) );
check( 'unknown submitted field dropped', ! isset( $v['values']['injected'] ) );
$v = Bachs_PF_Fields::validate( $defs, array( 'size' => 'M' ) );
check( 'required field enforced', 1 === count( $v['errors'] ) );
$v = Bachs_PF_Fields::validate( $defs, array( 'phone' => '0803', 'size' => 'M', 'extras' => array( 'Card', 'Gift wrap' ) ) );
check( 'valid values accepted', empty( $v['errors'] ) && 'Card, Gift wrap' === $v['values']['extras']['value'] );
$html = Bachs_PF_Fields::render( Bachs_PF_Fields::normalise( array( 'type' => 'text', 'label' => '"><script>x</script>' ) ), 'p' );
check( 'field label escaped', false === strpos( $html, '<script>' ) );

// --- Pricing on the server -------------------------------------------------------------------

setup();
$form = make_form( array( 'amount_type' => 'fixed', 'amount' => '20.00' ) );
$p    = Bachs_PF_Checkout::build_payment( $form, Bachs_PF_Forms::get_settings( 10 ), base_input( array( 'bachs_pf_amount' => '0.01' ) ) );
check( 'fixed form ignores a posted amount', '20.00' === $p['amount'] );

$form = make_form( array( 'amount_type' => 'custom', 'min_amount' => '5.00', 'max_amount' => '100.00' ) );
$s    = Bachs_PF_Forms::get_settings( 10 );
check( 'custom amount below minimum rejected', is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_amount' => '4.99' ) ) ) ) );
check( 'custom amount above maximum rejected', is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_amount' => '100.01' ) ) ) ) );
check( 'custom amount accepted', '50.00' === Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_amount' => '50' ) ) )['amount'] );

$form = make_form( array( 'amount_type' => 'options', 'options' => "Bronze | 10.00\nGold | 50.00" ) );
$s    = Bachs_PF_Forms::get_settings( 10 );
$p    = Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_option' => '1', 'bachs_pf_amount' => '1' ) ) );
check( 'option priced from settings', '50.00' === $p['amount'] && 'Gold' === $p['fields']['_option']['value'] );
check( 'unknown option rejected', is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_option' => '7' ) ) ) ) );

$form = make_form( array( 'amount' => '10.00', 'quantity' => 'yes', 'max_quantity' => 5, 'stock' => '3' ) );
$s    = Bachs_PF_Forms::get_settings( 10 );
check( 'quantity multiplies the price', '30.00' === Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_quantity' => '3' ) ) )['amount'] );
check( 'quantity above stock rejected', is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_quantity' => '4' ) ) ) ) );
check( 'quantity above max rejected', is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_quantity' => '9' ) ) ) ) );
check( 'quantity must be a whole number', is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_quantity' => '1.5' ) ) ) ) );

$form = make_form( array( 'amount' => '10.00', 'recurring' => 'optional', 'cadences' => array( 'month', 'year' ) ) );
$s    = Bachs_PF_Forms::get_settings( 10 );
check( 'optional recurring: one-time', '' === Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_cadence' => 'once' ) ) )['cadence'] );
check( 'optional recurring: allowed cadence', 'year' === Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_cadence' => 'year' ) ) )['cadence'] );
check( 'optional recurring: disallowed cadence rejected', is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_cadence' => 'week' ) ) ) ) );

$form = make_form( array( 'amount' => '10.00', 'terms_url' => 'https://site.example/terms' ) );
check( 'terms must be accepted', is_wp_error( Bachs_PF_Checkout::build_payment( $form, Bachs_PF_Forms::get_settings( 10 ), base_input() ) ) );

$form = make_form( array( 'amount' => '10.00' ) );
$p    = Bachs_PF_Checkout::build_payment( $form, Bachs_PF_Forms::get_settings( 10 ), base_input( array( 'bachs_pf_page' => 'https://evil.example/phish' ) ) );
check( 'off-site return page replaced by home', 'https://site.example/' === $p['page_url'] );
$GLOBALS['T']['user_id'] = 7;
$p = Bachs_PF_Checkout::build_payment( $form, Bachs_PF_Forms::get_settings( 10 ), base_input( array( 'pf-user_id' => '1', 'user_id' => '1' ) ) );
check( 'user ID comes from the session, not the form', 7 === $p['user_id'] );
$GLOBALS['T']['user_id'] = 0;

// --- Multi-currency (optional) ----------------------------------------------------------------

setup();
$r = Bachs_PF_Forms::sanitize_settings( array( 'currency' => 'USD', 'amount' => '10', 'multi_currency' => 'yes' ) );
check( 'multi-currency without extra currencies is switched off', 'no' === $r['settings']['multi_currency'] && ! empty( $r['errors'] ) );

$r = Bachs_PF_Forms::sanitize_settings(
	array(
		'currency'        => 'USD',
		'amount'          => '10',
		'multi_currency'  => 'yes',
		'currency_prices' => array(
			'NGN' => array( 'enabled' => '1', 'amount' => '15,000' ),
			'GHS' => array( 'enabled' => '1', 'amount' => '' ),
			'USD' => array( 'enabled' => '1', 'amount' => '99' ),
			'KES' => array( 'amount' => '1300' ),
			'EUR' => array( 'enabled' => '1', 'amount' => '9' ),
		),
	)
);
check( 'fixed: priced currencies kept, unpriced/unticked/main/unsupported dropped', array( 'NGN' ) === array_keys( $r['settings']['currency_prices'] ) && '15000.00' === $r['settings']['currency_prices']['NGN']['amount'] );
check( 'fixed: missing price reported', 1 === count( $r['errors'] ) && false !== strpos( $r['errors'][0], 'GHS' ) );
check( 'form currencies: main first', array( 'USD', 'NGN' ) === Bachs_PF_Forms::form_currencies( $r['settings'] ) );

$r = Bachs_PF_Forms::sanitize_settings(
	array(
		'currency'        => 'USD',
		'amount_type'     => 'options',
		'options'         => "Bronze | 10 | NGN 15000 | GHS 150\nGold | 50 | NGN 75000",
		'multi_currency'  => 'yes',
		'currency_prices' => array( 'NGN' => array( 'enabled' => '1' ), 'GHS' => array( 'enabled' => '1' ) ),
	)
);
check( 'options: currency missing on an option is not offered', array( 'NGN' ) === array_keys( $r['settings']['currency_prices'] ) && false !== strpos( implode( ' ', $r['errors'] ), 'Gold' ) );
check( 'options: text normalised to offered currencies', "Bronze | 10.00 | NGN 15000.00\nGold | 50.00 | NGN 75000.00" === $r['settings']['options'] );
$bad = Bachs_PF_Forms::parse_options( 'Gold | 50 | Naira 75000' );
check( 'options: unreadable extra price reported', 1 === count( $bad['errors'] ) );

$r = Bachs_PF_Forms::sanitize_settings( array( 'currency' => 'USD', 'amount' => '10', 'recurring' => 'fixed', 'multi_currency' => 'yes', 'currency_prices' => array( 'NGN' => array( 'enabled' => '1', 'amount' => '15000' ) ) ) );
check( 'always-recurring form drops non-USD choices', 'no' === $r['settings']['multi_currency'] && array() === $r['settings']['currency_prices'] );

$r = Bachs_PF_Forms::sanitize_settings( array( 'currency' => 'USD', 'amount_type' => 'custom', 'multi_currency' => 'yes', 'currency_prices' => array( 'NGN' => array( 'enabled' => '1', 'min_amount' => '5000', 'max_amount' => '100' ) ) ) );
check( 'custom: per-currency min above max rejected', 'no' === $r['settings']['multi_currency'] );

function multi_form( $settings, $id = 10 ) {
	return make_form( array_merge( array( 'currency' => 'USD', 'multi_currency' => 'yes' ), $settings ), '', $id );
}

setup();
$form = multi_form( array( 'amount' => '10.00', 'currency_prices' => array( 'NGN' => array( 'amount' => '15000.00', 'min_amount' => '', 'max_amount' => '' ) ) ) );
$s    = Bachs_PF_Forms::get_settings( 10 );
$p    = Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_currency' => 'NGN', 'bachs_pf_amount' => '1' ) ) );
check( 'payer picks NGN: priced in NGN at the set price', 'NGN' === $p['currency'] && '15000.00' === $p['amount'] );
check( 'payer picks the main currency', 'USD' === Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_currency' => 'USD' ) ) )['currency'] );
check( 'no choice posted: main currency', '10.00' === Bachs_PF_Checkout::build_payment( $form, $s, base_input() )['amount'] );
check( 'currency not offered is refused', is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_currency' => 'GHS' ) ) ) ) );
check( 'unsupported currency is refused', is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_currency' => 'EUR' ) ) ) ) );

$single = make_form( array( 'currency' => 'USD', 'amount' => '10.00', 'currency_prices' => array( 'NGN' => array( 'amount' => '15000.00' ) ) ), '', 12 );
$p      = Bachs_PF_Checkout::build_payment( $single, Bachs_PF_Forms::get_settings( 12 ), base_input( array( 'bachs_pf_currency' => 'NGN' ) ) );
check( 'option off: posted currency ignored, form unchanged', 'USD' === $p['currency'] && '10.00' === $p['amount'] );

$form = multi_form( array( 'amount_type' => 'custom', 'min_amount' => '5.00', 'currency_prices' => array( 'NGN' => array( 'amount' => '', 'min_amount' => '5000.00', 'max_amount' => '' ) ) ) );
$s    = Bachs_PF_Forms::get_settings( 10 );
check( 'custom: NGN minimum enforced', is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_currency' => 'NGN', 'bachs_pf_amount' => '4999' ) ) ) ) );
check( 'custom: NGN amount accepted', '6000.00' === Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_currency' => 'NGN', 'bachs_pf_amount' => '6000' ) ) )['amount'] );
check( 'custom: USD keeps its own minimum', ! is_wp_error( Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_currency' => 'USD', 'bachs_pf_amount' => '6' ) ) ) ) );

$form = multi_form( array( 'amount_type' => 'options', 'options' => "Bronze | 10.00 | NGN 15000.00\nGold | 50.00 | NGN 75000.00", 'currency_prices' => array( 'NGN' => array() ), 'quantity' => 'yes' ) );
$p    = Bachs_PF_Checkout::build_payment( $form, Bachs_PF_Forms::get_settings( 10 ), base_input( array( 'bachs_pf_currency' => 'NGN', 'bachs_pf_option' => '1', 'bachs_pf_quantity' => '2' ) ) );
check( 'options: NGN option price times quantity', 'NGN' === $p['currency'] && '150000.00' === $p['amount'] && 'Gold' === $p['fields']['_option']['value'] );

$form = multi_form( array( 'amount' => '10.00', 'recurring' => 'optional', 'cadences' => array( 'month' ), 'currency_prices' => array( 'NGN' => array( 'amount' => '15000.00' ) ) ) );
$s    = Bachs_PF_Forms::get_settings( 10 );
check( 'recurring in NGN refused with a clear message', is_wp_error( $e = Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_currency' => 'NGN', 'bachs_pf_cadence' => 'month' ) ) ) ) && false !== strpos( $e->get_error_message(), 'one-time' ) );
check( 'one-time in NGN allowed on a recurring-optional form', '' === Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_currency' => 'NGN', 'bachs_pf_cadence' => 'once' ) ) )['cadence'] );
check( 'recurring in USD still allowed', 'month' === Bachs_PF_Checkout::build_payment( $form, $s, base_input( array( 'bachs_pf_currency' => 'USD', 'bachs_pf_cadence' => 'month' ) ) )['cadence'] );

// The checkout is created in the chosen currency, and the webhook checks that currency.
setup();
multi_form( array( 'amount' => '10.00', 'currency_prices' => array( 'NGN' => array( 'amount' => '15000.00' ) ) ) );
session_ok();
Bachs_PF_Checkout::start_payment( 10, base_input( array( 'bachs_pf_currency' => 'NGN' ) ) );
$req = last_request();
check( 'checkout priced directly in NGN', 'NGN' === $req['body']['pricing']['currency'] && '15000.00' === $req['body']['pricing']['amount'] && ! isset( $req['body']['billing_currency'] ) );
$pay = Bachs_PF_Payments::get( 1 );
Bachs_PF_Webhook::dispatch( event( 'evt_n1', 'collection.succeeded', array( 'checkout_id' => 'chk_1', 'charge_id' => 'ch_1', 'status' => 'SUCCEEDED', 'amount' => '150.00', 'currency' => 'NGN', 'reference' => $pay->reference, 'metadata' => array( 'bachs_pf_payment_id' => '1' ) ) ) );
check( 'short NGN payment held for review', 'review' === Bachs_PF_Payments::get( 1 )->status );

// --- Creating the checkout -------------------------------------------------------------------

setup();
make_form( array( 'amount' => '20.00', 'quantity' => 'yes' ) );
session_ok();
$url = Bachs_PF_Checkout::start_payment( 10, base_input( array( 'bachs_pf_quantity' => '2' ) ) );
$req = last_request();
$pay = Bachs_PF_Payments::get( 1 );
check( 'one-time: redirect to checkout', 'https://checkout.bachs.io/c/chk_1' === $url );
check( 'one-time: ad-hoc pricing at the total', '40.00' === $req['body']['pricing']['amount'] && 'USD' === $req['body']['pricing']['currency'] && ! isset( $req['body']['product_cart'] ) );
check( 'one-time: metadata links back to the payment', '1' === $req['body']['metadata']['bachs_pf_payment_id'] );
check( 'one-time: reference + idempotency key', $pay->reference === $req['body']['reference'] && 'bachs-pf-' . $pay->reference === $req['headers']['Idempotency-Key'] );
check( 'one-time: return URL carries the access key', false !== strpos( $req['body']['success_url'], 'bachs_pf_key=' . $pay->access_key ) );
check( 'one-time: stored pending with checkout id', 'pending' === $pay->status && 'chk_1' === $pay->checkout_id && 1 === (int) $pay->attempts );

setup();
make_form( array( 'amount' => '15.00', 'recurring' => 'fixed', 'cadence' => 'quarter' ) );
t_queue( 201, array( 'product_id' => 'prod_1' ) );
session_ok();
Bachs_PF_Checkout::start_payment( 10, base_input() );
$product = $GLOBALS['T']['requests'][0];
$req     = last_request();
check( 'recurring: product created with billing cycle', 'products' === basename( $product['url'] ) && array( 'interval' => 'month', 'frequency' => 3 ) === $product['body']['billing_cycle'] );
check( 'recurring: product_cart with fixed price override', 'prod_1' === $req['body']['product_cart'][0]['product_id'] && '15.00' === $req['body']['product_cart'][0]['pricing']['amount'] && ! isset( $req['body']['pricing'] ) );
check( 'recurring: customer sent', 'ada@example.com' === $req['body']['customer']['email'] );
session_ok( 'chk_2' );
Bachs_PF_Checkout::start_payment( 10, base_input() );
check( 'recurring: product reused for the next payer', 3 === count( $GLOBALS['T']['requests'] ) );

setup();
make_form( array( 'amount' => '20.00' ) );
t_queue( 422, array( 'error_code' => 'DEPOSIT_LIMIT_EXCEEDED', 'detail' => 'too much' ) );
$err = Bachs_PF_Checkout::start_payment( 10, base_input() );
check( 'API error gives a friendly message', is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'limit' ) );

setup();
make_form( array( 'amount' => '20.00' ) );
update_option( 'bachs_pf_settings', array( 'testmode' => 'yes' ) );
check( 'no keys: refused without API call', is_wp_error( Bachs_PF_Checkout::start_payment( 10, base_input() ) ) && 0 === count( $GLOBALS['T']['requests'] ) );

// --- Payer status link and retry ----------------------------------------------------------------

setup();
make_form( array( 'amount' => '20.00' ) );
session_ok();
Bachs_PF_Checkout::start_payment( 10, base_input() );
$pay = Bachs_PF_Payments::get( 1 );
check( 'payer lookup needs the right key', null === Bachs_PF_Checkout::find_for_payer( $pay->reference, 'wrong' ) && null !== Bachs_PF_Checkout::find_for_payer( $pay->reference, $pay->access_key ) );

t_queue( 200, array( 'status' => 'completed' ) );
check( 'retry refused when the checkout is completed', is_wp_error( Bachs_PF_Checkout::retry( $pay->reference, $pay->access_key ) ) );
t_queue( 200, array( 'status' => 'open', 'checkout_url' => 'https://checkout.bachs.io/c/chk_1' ) );
check( 'retry reuses an open checkout', 'https://checkout.bachs.io/c/chk_1' === Bachs_PF_Checkout::retry( $pay->reference, $pay->access_key ) );
t_queue( 200, array( 'status' => 'expired' ) );
session_ok( 'chk_2' );
$url = Bachs_PF_Checkout::retry( $pay->reference, $pay->access_key );
check( 'retry after expiry opens a new checkout with the next reference', 'https://checkout.bachs.io/c/chk_2' === $url && $pay->reference . '-2' === last_request()['body']['reference'] );

// --- Webhooks ----------------------------------------------------------------------------------

function paid_setup( $settings = array() ) {
	setup();
	make_form( array_merge( array( 'amount' => '20.00' ), $settings ) );
	session_ok();
	Bachs_PF_Checkout::start_payment( 10, base_input() );
	return Bachs_PF_Payments::get( 1 );
}

function succeeded( $pay, $extra = array() ) {
	return array_merge(
		array(
			'checkout_id'         => 'chk_1',
			'charge_id'           => 'ch_1',
			'status'              => 'SUCCEEDED',
			'amount'              => '20.00',
			'currency'            => 'USD',
			'settlement_amount'   => '19.30',
			'settlement_currency' => 'USD',
			'reference'           => $pay->reference,
			'metadata'            => array( 'bachs_pf_payment_id' => (string) $pay->id ),
		),
		$extra
	);
}

$pay = paid_setup();
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'collection.succeeded', succeeded( $pay ) ) );
$after = Bachs_PF_Payments::get( 1 );
check( 'collection.succeeded marks paid', 'paid' === $after->status && 'ch_1' === $after->charge_id && 'USD' === $after->settlement_currency );
check( 'paid action fired once', 1 === count( actions( 'bachs_pf_payment_paid' ) ) );
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'collection.succeeded', succeeded( $pay ) ) );
Bachs_PF_Webhook::dispatch( event( 'evt_9', 'collection.succeeded', succeeded( $pay ) ) );
check( 'redelivery (same or new event id) does not repeat side effects', 1 === count( actions( 'bachs_pf_payment_paid' ) ) );

$pay = paid_setup();
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'collection.succeeded', succeeded( $pay, array( 'amount' => '2.00' ) ) ) );
check( 'short payment in the same currency held for review', 'review' === Bachs_PF_Payments::get( 1 )->status && 1 === count( actions( 'bachs_pf_payment_review' ) ) );

$pay = paid_setup();
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'collection.succeeded', succeeded( $pay, array( 'amount' => '26000.00', 'currency' => 'NGN' ) ) ) );
check( 'payment in another currency completes', 'paid' === Bachs_PF_Payments::get( 1 )->status );

$pay = paid_setup();
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'collection.succeeded', succeeded( $pay, array( 'status' => 'ACCEPTED' ) ) ) );
check( 'ACCEPTED held for review', 'review' === Bachs_PF_Payments::get( 1 )->status );

$pay = paid_setup();
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'collection.succeeded', succeeded( $pay, array( 'reference' => 'bpf_someoneelse' ) ) ) );
check( 'foreign reference held for review', 'review' === Bachs_PF_Payments::get( 1 )->status );

$pay = paid_setup();
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'collection.succeeded', succeeded( $pay ) ) );
Bachs_PF_Webhook::dispatch( event( 'evt_2', 'collection.succeeded', succeeded( $pay, array( 'charge_id' => 'ch_2', 'checkout_id' => 'chk_x' ) ) ) );
$dups = Bachs_PF_Payments::query( array( 'status' => 'review' ) );
check( 'duplicate charge recorded as its own review row', 1 === $dups['total'] && 'ch_2' === $dups['items'][0]->charge_id && 1 === (int) $dups['items'][0]->parent_id );
check( 'duplicate does not count against stock', 0 === (int) $dups['items'][0]->quantity );

$pay = paid_setup( array( 'quantity' => 'yes', 'stock' => '5' ) );
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'collection.succeeded', succeeded( $pay ) ) );
check( 'sold quantity counts paid payments', 1 === Bachs_PF_Payments::sold_quantity( 10 ) );

$pay = paid_setup();
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'checkout.expired', array( 'checkout_id' => 'chk_old', 'metadata' => array( 'bachs_pf_payment_id' => '1' ) ) ) );
check( 'expiry of a replaced checkout ignored', 'pending' === Bachs_PF_Payments::get( 1 )->status );
Bachs_PF_Webhook::dispatch( event( 'evt_2', 'checkout.expired', array( 'checkout_id' => 'chk_1', 'metadata' => array( 'bachs_pf_payment_id' => '1' ) ) ) );
check( 'expiry of the current checkout recorded', 'expired' === Bachs_PF_Payments::get( 1 )->status );
Bachs_PF_Webhook::dispatch( event( 'evt_3', 'collection.succeeded', succeeded( $pay ) ) );
check( 'late payment after expiry still marks paid', 'paid' === Bachs_PF_Payments::get( 1 )->status );
Bachs_PF_Webhook::dispatch( event( 'evt_4', 'collection.failed', array( 'checkout_id' => 'chk_1', 'metadata' => array( 'bachs_pf_payment_id' => '1' ) ) ) );
check( 'failure after payment ignored', 'paid' === Bachs_PF_Payments::get( 1 )->status );

$pay = paid_setup();
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'collection.underpaid', array( 'checkout_id' => 'chk_1', 'charge_id' => 'ch_1', 'amount_paid' => '5.00', 'amount_expected' => '20.00', 'currency' => 'USD', 'metadata' => array( 'bachs_pf_payment_id' => '1' ) ) ) );
check( 'underpaid held for review', 'review' === Bachs_PF_Payments::get( 1 )->status );

// Subscriptions.
setup();
make_form( array( 'amount' => '15.00', 'recurring' => 'fixed', 'cadence' => 'month' ) );
t_queue( 201, array( 'product_id' => 'prod_1' ) );
session_ok();
Bachs_PF_Checkout::start_payment( 10, base_input() );
$pay = Bachs_PF_Payments::get( 1 );
$meta = array( 'bachs_pf_payment_id' => '1', 'checkout_id' => 'chk_1' );

Bachs_PF_Webhook::dispatch( event( 'evt_i1', 'invoice.paid', array( 'invoice_id' => 'inv_1', 'charge' => 'ch_1', 'amount_paid' => '15.00', 'currency' => 'USD', 'subscription' => array( 'subscription_id' => 'sub_1' ) ) ) );
Bachs_PF_Webhook::dispatch( event( 'evt_s1', 'customer.subscription.created', array( 'subscription_id' => 'sub_1', 'status' => 'active', 'customer' => array( 'customer_id' => 'cus_1' ), 'metadata' => $meta ) ) );
Bachs_PF_Webhook::dispatch( event( 'evt_c1', 'collection.succeeded', succeeded( $pay, array( 'amount' => '15.00' ) ) ) );
$pay = Bachs_PF_Payments::get( 1 );
check( 'subscription linked to the payment', 'sub_1' === $pay->subscription_id && 'active' === $pay->subscription_status && 'cus_1' === $pay->customer_id );
check( 'first payment paid', 'paid' === $pay->status );

Bachs_PF_Webhook::dispatch( event( 'evt_i1b', 'invoice.paid', array( 'invoice_id' => 'inv_1b', 'charge' => array( 'charge_id' => 'ch_1' ), 'amount_paid' => '15.00', 'currency' => 'USD', 'subscription' => array( 'subscription_id' => 'sub_1' ) ) ) );
check( 'first invoice (same charge) is not a renewal', 1 === Bachs_PF_Payments::query()['total'] );

Bachs_PF_Webhook::dispatch( event( 'evt_i2', 'invoice.paid', array( 'invoice_id' => 'inv_2', 'charge' => 'ch_2', 'amount_paid' => '15.00', 'currency' => 'USD', 'subscription' => array( 'subscription_id' => 'sub_1' ) ) ) );
Bachs_PF_Webhook::dispatch( event( 'evt_i2', 'invoice.paid', array( 'invoice_id' => 'inv_2', 'charge' => 'ch_2', 'amount_paid' => '15.00', 'currency' => 'USD', 'subscription' => array( 'subscription_id' => 'sub_1' ) ) ) );
$all = Bachs_PF_Payments::query( array( 'order' => 'ASC' ) );
check( 'renewal recorded once', 2 === $all['total'] && 'renewal' === $all['items'][1]->type && 'paid' === $all['items'][1]->status && 1 === (int) $all['items'][1]->parent_id );
check( 'renewal does not count against stock', 1 === Bachs_PF_Payments::sold_quantity( 10 ) );

Bachs_PF_Webhook::dispatch( event( 'evt_s2', 'customer.subscription.deleted', array( 'subscription_id' => 'sub_1', 'status' => 'canceled' ) ) );
check( 'cancellation tracked', 'canceled' === Bachs_PF_Payments::get( 1 )->subscription_status );

// --- Signatures -------------------------------------------------------------------------------

setup();
$body = '{"id":"evt_1","type":"collection.succeeded","data":{}}';
$ts   = (string) time();
$sig  = hash_hmac( 'sha256', $ts . '.' . $body, 'whsec_sandbox' );
check( 'v1 signature verifies', 'sandbox' === Bachs_PF_Webhook::verify( $body, $ts, $sig, '' ) );
check( 'v2 signature with rotation verifies', 'sandbox' === Bachs_PF_Webhook::verify( $body, '', '', "t={$ts},v1=bad,v1={$sig}" ) );
check( 'live secret verifies in test mode', 'live' === Bachs_PF_Webhook::verify( $body, $ts, hash_hmac( 'sha256', $ts . '.' . $body, 'whsec_live' ), '' ) );
check( 'tampered body rejected', false === Bachs_PF_Webhook::verify( $body . ' ', $ts, $sig, '' ) );
$old = (string) ( time() - 1000 );
check( 'stale timestamp rejected', false === Bachs_PF_Webhook::verify( $body, $old, hash_hmac( 'sha256', $old . '.' . $body, 'whsec_sandbox' ), '' ) );

// --- Emails -----------------------------------------------------------------------------------

$pay = paid_setup();
Bachs_PF_Payments::update( 1, array( 'fields' => array( 'note' => array( 'label' => 'Note', 'value' => '<b>hi</b>' ) ) ) );
Bachs_PF_Emails::on_paid( Bachs_PF_Payments::get( 1 ) );
check( 'receipt and owner notice sent', 2 === count( $GLOBALS['T']['mail'] ) && 'ada@example.com' === $GLOBALS['T']['mail'][0]['to'] );
check( 'email values escaped', false === strpos( $GLOBALS['T']['mail'][0]['body'], '<b>hi</b>' ) );

// --- Rendering (escaping) -----------------------------------------------------------------------

setup();
$evil = '"><script>alert(1)</script>';
make_form(
	array(
		'currency'       => 'USD',
		'amount_type'    => 'options',
		'options'        => 'Gold' . $evil . ' | 10.00',
		'quantity'       => 'yes',
		'quantity_label' => $evil,
		'button_label'   => $evil,
		'terms_url'      => 'https://site.example/terms',
		'recurring'      => 'optional',
		'cadences'       => array( 'month' ),
	),
	'[bachs_field type="text" label="Name' . $evil . '"]'
);
$_GET = array();
$html = Bachs_PF_Renderer::shortcode( array( 'id' => 10 ) );
check( 'form renders', false !== strpos( $html, 'bachs-pf-form' ) && false !== strpos( $html, 'name="bachs_pf_option"' ) );
check( 'no unescaped script in the form', false === strpos( $html, '<script>' ) );
check( 'form carries a nonce and the form ID', false !== strpos( $html, 'value="nonce123"' ) && false !== strpos( $html, 'name="bachs_pf_form_id" value="10"' ) );

$GLOBALS['T']['posts'][10]->post_status = 'draft';
check( 'draft forms do not render', '' === Bachs_PF_Renderer::shortcode( array( 'id' => 10 ) ) );

// Payer status view.
$pay = paid_setup();
$_GET = array( 'bachs_pf_payment' => $pay->reference, 'bachs_pf_key' => 'wrong' );
$html = Bachs_PF_Renderer::shortcode( array( 'id' => 10 ) );
check( 'wrong key shows the form, not the payment', false !== strpos( $html, 'bachs-pf-form' ) && false === strpos( $html, $pay->reference ) );
$_GET = array( 'bachs_pf_payment' => $pay->reference, 'bachs_pf_key' => $pay->access_key );
t_queue( 200, array( 'status' => 'completed' ) );
$html = Bachs_PF_Renderer::shortcode( array( 'id' => 10 ) );
check( 'completed checkout awaiting webhook shows confirming message', false !== strpos( $html, 'confirming' ) && false === strpos( $html, 'bachs-pf-retry' ) );
t_queue( 200, array( 'status' => 'expired' ) );
$html = Bachs_PF_Renderer::shortcode( array( 'id' => 10 ) );
check( 'expired checkout offers to pay again', false !== strpos( $html, 'bachs-pf-retry' ) );
Bachs_PF_Webhook::dispatch( event( 'evt_1', 'collection.succeeded', succeeded( $pay ) ) );
$html = Bachs_PF_Renderer::shortcode( array( 'id' => 10 ) );
check( 'paid payment shows the success message without an API call', false !== strpos( $html, 'bachs-pf-success' ) );
$_GET = array();

make_form( array( 'amount' => '10.00', 'stock' => '1' ), '', 11 );
Bachs_PF_Payments::insert( array( 'form_id' => 11, 'status' => 'paid', 'reference' => 'bpf_sold', 'quantity' => 1 ) );
check( 'sold-out form shows a notice instead of the form', false !== strpos( Bachs_PF_Renderer::shortcode( array( 'id' => 11 ) ), 'sold out' ) );

// Rendering the currency choice.
setup();
$_GET = array();
multi_form( array( 'amount' => '10.00', 'recurring' => 'optional', 'cadences' => array( 'month' ), 'currency_prices' => array( 'NGN' => array( 'amount' => '15000.00' ) ) ) );
$html = Bachs_PF_Renderer::shortcode( array( 'id' => 10 ) );
check( 'multi-currency form shows a currency dropdown', false !== strpos( $html, 'name="bachs_pf_currency"' ) && false !== strpos( $html, '<option value="NGN"' ) );
preg_match( '/data-pricing="([^"]+)"/', $html, $m );
$data = isset( $m[1] ) ? json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true ) : null;
check( 'pricing data for every currency', isset( $data['USD'], $data['NGN'] ) && '15000.00' === $data['NGN']['amount'] && false === $data['NGN']['recurring'] && true === $data['USD']['recurring'] );
check( 'recurring note rendered for multi-currency', false !== strpos( $html, 'bachs-pf-recurring-note' ) );
make_form( array( 'amount' => '10.00' ), '', 13 );
$html = Bachs_PF_Renderer::shortcode( array( 'id' => 13 ) );
check( 'single-currency form has no dropdown or pricing data', false === strpos( $html, 'bachs_pf_currency' ) && false === strpos( $html, 'data-pricing' ) );

// --- CSV -------------------------------------------------------------------------------------

require BACHS_PF_PATH . '/includes/admin/class-bachs-pf-admin.php';
check( 'CSV formula neutralised', "'=HYPERLINK(1)" === Bachs_PF_Admin::csv_safe( '=HYPERLINK(1)' ) );
check( 'CSV negative number kept', '-5' === Bachs_PF_Admin::csv_safe( '-5' ) );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
