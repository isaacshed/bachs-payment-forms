<?php
/**
 * Uninstall. Settings are always removed. Forms and payment records are financial data, so
 * they are only deleted when the site owner opted in under Settings → Uninstall.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$bachs_pf_settings = get_option( 'bachs_pf_settings', array() );

if ( is_array( $bachs_pf_settings ) && isset( $bachs_pf_settings['delete_data'] ) && 'yes' === $bachs_pf_settings['delete_data'] ) {
	global $wpdb;

	$bachs_pf_forms = get_posts(
		array(
			'post_type'      => 'bachs_form',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $bachs_pf_forms as $bachs_pf_form_id ) {
		wp_delete_post( $bachs_pf_form_id, true );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on opted-in uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'bachs_pf_payments' ) );
	delete_option( 'bachs_pf_db_version' );
}

delete_option( 'bachs_pf_settings' );
