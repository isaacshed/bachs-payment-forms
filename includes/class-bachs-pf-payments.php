<?php
/**
 * Payment records: the custom table and all queries against it.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_PF_Payments {

	const STATUS_PENDING = 'pending';
	const STATUS_PAID    = 'paid';
	const STATUS_REVIEW  = 'review';
	const STATUS_FAILED  = 'failed';
	const STATUS_EXPIRED = 'expired';

	const TYPE_ONE_TIME     = 'one_time';
	const TYPE_SUBSCRIPTION = 'subscription';
	const TYPE_RENEWAL      = 'renewal';

	/**
	 * Statuses a payment can still move to paid (or review) from.
	 */
	const UNSETTLED = array( 'pending', 'failed', 'expired' );

	/**
	 * Columns stored as JSON.
	 */
	const JSON_COLUMNS = array( 'fields', 'event_ids', 'invoice_ids' );

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bachs_pf_payments';
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL DEFAULT 0,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(20) NOT NULL DEFAULT 'one_time',
				status varchar(20) NOT NULL DEFAULT 'pending',
				mode varchar(10) NOT NULL DEFAULT 'sandbox',
				reference varchar(128) NOT NULL,
				access_key varchar(64) NOT NULL DEFAULT '',
				checkout_id varchar(100) NOT NULL DEFAULT '',
				attempts int(10) unsigned NOT NULL DEFAULT 0,
				charge_id varchar(100) NOT NULL DEFAULT '',
				subscription_id varchar(100) NOT NULL DEFAULT '',
				subscription_status varchar(20) NOT NULL DEFAULT '',
				customer_id varchar(100) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(255) NOT NULL DEFAULT '',
				name varchar(255) NOT NULL DEFAULT '',
				currency varchar(10) NOT NULL DEFAULT '',
				amount decimal(20,2) NOT NULL DEFAULT 0,
				quantity int(10) unsigned NOT NULL DEFAULT 1,
				cadence varchar(20) NOT NULL DEFAULT '',
				fields longtext NULL,
				page_url varchar(2048) NOT NULL DEFAULT '',
				paid_amount varchar(32) NOT NULL DEFAULT '',
				paid_currency varchar(20) NOT NULL DEFAULT '',
				settlement_amount varchar(32) NOT NULL DEFAULT '',
				settlement_currency varchar(20) NOT NULL DEFAULT '',
				review_reason text NULL,
				event_ids text NULL,
				invoice_ids text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				paid_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY reference (reference),
				KEY form_status (form_id,status),
				KEY checkout_id (checkout_id),
				KEY subscription_id (subscription_id),
				KEY parent_id (parent_id)
			) {$charset};"
		);

		update_option( 'bachs_pf_db_version', BACHS_PF_DB_VERSION );
	}

	/**
	 * Run install() when the schema version changes (plugin updated without re-activation).
	 */
	public static function maybe_upgrade() {
		if ( BACHS_PF_DB_VERSION !== get_option( 'bachs_pf_db_version' ) ) {
			self::install();
		}
	}

	/**
	 * @param array $data Column => value.
	 * @return int Inserted ID, 0 on failure.
	 */
	public static function insert( $data ) {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = array_merge(
			array(
				'created_at' => $now,
				'updated_at' => $now,
			),
			self::encode( $data )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$ok = $wpdb->insert( self::table(), $data );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, must not be cached.
		return false !== $wpdb->update( self::table(), self::encode( $data ), array( 'id' => (int) $id ) );
	}

	/**
	 * Atomically move a payment from one of $from to $to. Only one request can win, so side
	 * effects (emails, stock) run exactly once even when webhooks are delivered twice.
	 *
	 * Each UPDATE matches on the current status, so it only changes the row while it is still
	 * in that status; a payment is in one status at a time, so at most one of them succeeds.
	 *
	 * @param int      $id
	 * @param string[] $from
	 * @param string   $to
	 * @param array    $data Extra columns to set (null writes NULL).
	 * @return bool Whether this call made the change.
	 */
	public static function transition( $id, $from, $to, $data = array() ) {
		global $wpdb;

		$data               = self::encode( $data );
		$data['status']     = $to;
		$data['updated_at'] = current_time( 'mysql', true );

		foreach ( array_values( $from ) as $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; a status change must hit the database.
			$rows = $wpdb->update(
				self::table(),
				$data,
				array(
					'id'     => (int) $id,
					'status' => (string) $status,
				)
			);
			if ( 1 === (int) $rows ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function get( $id ) {
		return self::get_by( 'id', (int) $id );
	}

	/**
	 * Find one payment by a unique-ish column.
	 *
	 * @param string $column          id|reference|checkout_id|subscription_id|charge_id
	 * @param mixed  $value
	 * @param bool   $top_level_only  Only first payments (not renewals or duplicates).
	 * @return object|null
	 */
	public static function get_by( $column, $value, $top_level_only = false ) {
		global $wpdb;

		$allowed = array( 'id', 'reference', 'checkout_id', 'subscription_id', 'charge_id' );
		if ( ! in_array( $column, $allowed, true ) || '' === (string) $value ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, live payment state.
		if ( $top_level_only ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE %i = %s AND parent_id = 0 ORDER BY id ASC LIMIT 1',
					self::table(),
					$column,
					(string) $value
				)
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE %i = %s ORDER BY id ASC LIMIT 1',
					self::table(),
					$column,
					(string) $value
				)
			);
		}
		// phpcs:enable

		return $row ? self::decode( $row ) : null;
	}

	/**
	 * List payments for the admin.
	 *
	 * The SQL is fixed: each filter is written so it switches itself off when empty (form 0,
	 * status '', search ''), so only placeholder values change between calls.
	 *
	 * @param array $args form_id, status, search, orderby, order, per_page, page.
	 * @return array{items: object[], total: int}
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'form_id'  => 0,
				'status'   => '',
				'search'   => '',
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$table   = self::table();
		$form_id = (int) $args['form_id'];
		$status  = (string) $args['status'];
		$search  = (string) $args['search'];
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$orderby = in_array( $args['orderby'], array( 'created_at', 'paid_at', 'amount', 'email', 'status' ), true ) ? $args['orderby'] : 'created_at';
		$limit   = max( 1, (int) $args['per_page'] );
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * $limit );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin list of live payments.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %d = 0 OR form_id = %d ) AND ( %s = '' OR status = %s ) AND ( %s = '' OR email LIKE %s OR name LIKE %s OR reference LIKE %s )",
				$table,
				$form_id,
				$form_id,
				$status,
				$status,
				$search,
				$like,
				$like,
				$like
			)
		);

		if ( 'ASC' === strtoupper( (string) $args['order'] ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE ( %d = 0 OR form_id = %d ) AND ( %s = '' OR status = %s ) AND ( %s = '' OR email LIKE %s OR name LIKE %s OR reference LIKE %s ) ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d",
					$table,
					$form_id,
					$form_id,
					$status,
					$status,
					$search,
					$like,
					$like,
					$like,
					$orderby,
					$limit,
					$offset
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE ( %d = 0 OR form_id = %d ) AND ( %s = '' OR status = %s ) AND ( %s = '' OR email LIKE %s OR name LIKE %s OR reference LIKE %s ) ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d",
					$table,
					$form_id,
					$form_id,
					$status,
					$status,
					$search,
					$like,
					$like,
					$like,
					$orderby,
					$limit,
					$offset
				)
			);
		}
		// phpcs:enable

		return array(
			'items' => array_map( array( __CLASS__, 'decode' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Units sold on a form: the quantity of its paid (or held for review) first payments.
	 *
	 * @param int $form_id
	 * @return int
	 */
	public static function sold_quantity( $form_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live stock count.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(quantity), 0) FROM %i WHERE form_id = %d AND parent_id = 0 AND status IN ('paid','review')",
				self::table(),
				(int) $form_id
			)
		);
	}

	/**
	 * Count of paid payments per form, for the forms list.
	 *
	 * @param int $form_id
	 * @return int
	 */
	public static function paid_count( $form_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live count.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE form_id = %d AND status = 'paid'", self::table(), (int) $form_id ) );
	}

	/**
	 * Remember a webhook event ID on a payment; returns false if it was already seen.
	 *
	 * @param object $payment
	 * @param string $event_id
	 * @return bool
	 */
	public static function remember_event( $payment, $event_id ) {
		if ( '' === (string) $event_id ) {
			return true;
		}
		$ids = is_array( $payment->event_ids ) ? $payment->event_ids : array();
		if ( in_array( $event_id, $ids, true ) ) {
			return false;
		}
		$ids[] = (string) $event_id;
		$ids   = array_slice( $ids, -50 );
		self::update( $payment->id, array( 'event_ids' => $ids ) );
		$payment->event_ids = $ids;
		return true;
	}

	/**
	 * @param object $payment
	 * @param string $event_id
	 * @return bool
	 */
	public static function has_event( $payment, $event_id ) {
		return '' !== (string) $event_id && is_array( $payment->event_ids ) && in_array( (string) $event_id, $payment->event_ids, true );
	}

	/**
	 * Random, unguessable token for the payer's return link.
	 *
	 * @return string
	 */
	public static function new_access_key() {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Unique payment reference, e.g. "bpf_7k2m9x4q8w1z".
	 *
	 * @return string
	 */
	public static function new_reference() {
		return 'bpf_' . strtolower( wp_generate_password( 16, false, false ) );
	}

	/**
	 * @param array $data
	 * @return array
	 */
	protected static function encode( $data ) {
		foreach ( self::JSON_COLUMNS as $column ) {
			if ( array_key_exists( $column, $data ) && ! is_string( $data[ $column ] ) && null !== $data[ $column ] ) {
				$data[ $column ] = wp_json_encode( $data[ $column ] );
			}
		}
		return $data;
	}

	/**
	 * @param object $row
	 * @return object
	 */
	public static function decode( $row ) {
		foreach ( self::JSON_COLUMNS as $column ) {
			if ( isset( $row->{$column} ) && is_string( $row->{$column} ) && '' !== $row->{$column} ) {
				$decoded          = json_decode( $row->{$column}, true );
				$row->{$column} = is_array( $decoded ) ? $decoded : array();
			} else {
				$row->{$column} = array();
			}
		}
		return $row;
	}
}
