<?php
/**
 * Payments list table.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Bachs_PF_Payments_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'payment',
				'plural'   => 'payments',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @param string $status
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'pending' => __( 'Awaiting payment', 'bachs-payment-forms' ),
			'paid'    => __( 'Paid', 'bachs-payment-forms' ),
			'review'  => __( 'Needs review', 'bachs-payment-forms' ),
			'failed'  => __( 'Failed', 'bachs-payment-forms' ),
			'expired' => __( 'Expired', 'bachs-payment-forms' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'payer'   => __( 'Payer', 'bachs-payment-forms' ),
			'amount'  => __( 'Amount', 'bachs-payment-forms' ),
			'status'  => __( 'Status', 'bachs-payment-forms' ),
			'form'    => __( 'Form', 'bachs-payment-forms' ),
			'ref'     => __( 'Reference', 'bachs-payment-forms' ),
			'created' => __( 'Date', 'bachs-payment-forms' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		if ( ! empty( $_GET['form_id'] ) ) {
			unset( $columns['form'] );
		}
		return $columns;
	}

	/**
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'amount'  => array( 'amount', false ),
			'status'  => array( 'status', false ),
			'created' => array( 'created_at', true ),
		);
	}

	/**
	 * @return array
	 */
	protected function get_views() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		// phpcs:enable

		$base  = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-payments' . ( $form_id ? '&form_id=' . $form_id : '' ) );
		$views = array( 'all' => '<a href="' . esc_url( $base ) . '"' . ( '' === $current ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'bachs-payment-forms' ) . '</a>' );

		foreach ( array( 'paid', 'review', 'pending', 'failed', 'expired' ) as $status ) {
			$views[ $status ] = '<a href="' . esc_url( add_query_arg( 'status', $status, $base ) ) . '"' . ( $current === $status ? ' class="current"' : '' ) . '>' . esc_html( self::status_label( $status ) ) . '</a>';
		}

		return $views;
	}

	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list.
		$args = array(
			'form_id'  => isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0,
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at',
			'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
			'per_page' => 20,
			'page'     => $this->get_pagenum(),
		);
		// phpcs:enable

		$result = Bachs_PF_Payments::query( $args );

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => 20,
			)
		);
	}

	/**
	 * @param object $item
	 * @return string
	 */
	protected function column_payer( $item ) {
		$url = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-payments&payment=' . (int) $item->id );
		return '<strong><a href="' . esc_url( $url ) . '">' . esc_html( '' !== $item->name ? $item->name : $item->email ) . '</a></strong><br>' . esc_html( $item->email );
	}

	/**
	 * @param object $item
	 * @return string
	 */
	protected function column_amount( $item ) {
		$html = esc_html( Bachs_PF_Money::display( $item->amount, $item->currency ) );
		if ( Bachs_PF_Payments::TYPE_RENEWAL === $item->type ) {
			$html .= '<br><span class="description">' . esc_html__( 'Renewal', 'bachs-payment-forms' ) . '</span>';
		} elseif ( '' !== $item->cadence ) {
			$html .= '<br><span class="description">' . esc_html( Bachs_PF_Money::cadence_label( $item->cadence ) ) . '</span>';
		}
		return $html;
	}

	/**
	 * @param object $item
	 * @return string
	 */
	protected function column_status( $item ) {
		$html = '<span class="bachs-pf-status bachs-pf-status-' . esc_attr( $item->status ) . '">' . esc_html( self::status_label( $item->status ) ) . '</span>';
		if ( 'sandbox' === $item->mode ) {
			$html .= ' <span class="description">' . esc_html__( '(test)', 'bachs-payment-forms' ) . '</span>';
		}
		return $html;
	}

	/**
	 * @param object $item
	 * @return string
	 */
	protected function column_form( $item ) {
		$url = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-payments&form_id=' . (int) $item->form_id );
		return '<a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( (int) $item->form_id ) ) . '</a>';
	}

	/**
	 * @param object $item
	 * @return string
	 */
	protected function column_ref( $item ) {
		return '<code>' . esc_html( $item->reference ) . '</code>';
	}

	/**
	 * @param object $item
	 * @return string
	 */
	protected function column_created( $item ) {
		return esc_html( get_date_from_gmt( $item->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
	}

	public function no_items() {
		esc_html_e( 'No payments yet.', 'bachs-payment-forms' );
	}
}
