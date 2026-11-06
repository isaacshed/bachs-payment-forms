<?php
/**
 * Emails: the payer's receipt and the site owner's notifications.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_PF_Emails {

	public static function init() {
		add_action( 'bachs_pf_payment_paid', array( __CLASS__, 'on_paid' ) );
		add_action( 'bachs_pf_payment_review', array( __CLASS__, 'on_review' ), 10, 2 );
	}

	/**
	 * @param object $payment
	 */
	public static function on_paid( $payment ) {
		if ( ! $payment ) {
			return;
		}
		$settings = Bachs_PF_Forms::get_settings( $payment->form_id );

		if ( 'yes' === $settings['send_receipt'] && is_email( $payment->email ) ) {
			self::send_receipt( $payment );
		}
		if ( 'yes' === $settings['notify_admin'] ) {
			self::send_owner_notice( $payment, '' );
		}
	}

	/**
	 * Payments held for review always notify the owner: they need a decision.
	 *
	 * @param object $payment
	 * @param string $reason
	 */
	public static function on_review( $payment, $reason ) {
		if ( $payment ) {
			self::send_owner_notice( $payment, (string) $reason );
		}
	}

	/**
	 * @param object $payment
	 * @return bool
	 */
	public static function send_receipt( $payment ) {
		$form_title = self::form_title( $payment );

		/* translators: 1: site name, 2: form title */
		$subject = sprintf( __( '[%1$s] Payment receipt: %2$s', 'bachs-payment-forms' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $form_title );

		$intro = Bachs_PF_Payments::TYPE_RENEWAL === $payment->type
			/* translators: %s: payer name */
			? sprintf( __( 'Hi %s, your recurring payment was successful. Thank you!', 'bachs-payment-forms' ), $payment->name )
			/* translators: %s: payer name */
			: sprintf( __( 'Hi %s, thank you for your payment. Here is your receipt.', 'bachs-payment-forms' ), $payment->name );

		$body = self::layout( $intro, self::summary_rows( $payment, false ) );

		return self::send( $payment->email, $subject, $body );
	}

	/**
	 * @param object $payment
	 * @param string $reason Non-empty when the payment needs review.
	 * @return bool
	 */
	public static function send_owner_notice( $payment, $reason ) {
		$form_title = self::form_title( $payment );
		$site       = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		if ( '' !== $reason ) {
			/* translators: 1: site name, 2: form title */
			$subject = sprintf( __( '[%1$s] Payment needs review: %2$s', 'bachs-payment-forms' ), $site, $form_title );
			/* translators: %s: reason */
			$intro = sprintf( __( 'A payment was received but needs your review before you deliver anything: %s Check it in your Bachs dashboard.', 'bachs-payment-forms' ), $reason );
		} else {
			/* translators: 1: site name, 2: form title */
			$subject = sprintf( __( '[%1$s] New payment: %2$s', 'bachs-payment-forms' ), $site, $form_title );
			$intro   = __( 'You received a new payment.', 'bachs-payment-forms' );
		}

		$link = admin_url( 'edit.php?post_type=' . Bachs_PF_Forms::POST_TYPE . '&page=bachs-pf-payments&payment=' . (int) $payment->id );
		$body = self::layout( $intro, self::summary_rows( $payment, true ), $link, __( 'View payment', 'bachs-payment-forms' ) );

		return self::send( Bachs_PF_Settings::notify_email(), $subject, $body );
	}

	/**
	 * @param object $payment
	 * @param bool   $for_owner Include payer contact details and Bachs IDs.
	 * @return array label => value
	 */
	public static function summary_rows( $payment, $for_owner ) {
		$rows = array(
			__( 'Form', 'bachs-payment-forms' )      => self::form_title( $payment ),
			__( 'Amount', 'bachs-payment-forms' )    => Bachs_PF_Money::display( $payment->amount, $payment->currency ),
			__( 'Reference', 'bachs-payment-forms' ) => $payment->reference,
		);

		if ( (int) $payment->quantity > 1 ) {
			$rows[ __( 'Quantity', 'bachs-payment-forms' ) ] = (string) (int) $payment->quantity;
		}
		if ( '' !== $payment->cadence ) {
			$rows[ __( 'Billing', 'bachs-payment-forms' ) ] = Bachs_PF_Money::cadence_label( $payment->cadence );
		}
		if ( $for_owner ) {
			$rows[ __( 'Name', 'bachs-payment-forms' ) ]  = $payment->name;
			$rows[ __( 'Email', 'bachs-payment-forms' ) ] = $payment->email;
			if ( '' !== $payment->charge_id ) {
				$rows[ __( 'Bachs charge', 'bachs-payment-forms' ) ] = $payment->charge_id;
			}
		}

		$fields = is_array( $payment->fields ) ? $payment->fields : array();
		if ( 0 !== (int) $payment->parent_id && Bachs_PF_Payments::TYPE_RENEWAL === $payment->type ) {
			$parent = Bachs_PF_Payments::get( $payment->parent_id );
			$fields = $parent && is_array( $parent->fields ) ? $parent->fields : array();
		}
		foreach ( $fields as $field ) {
			if ( isset( $field['label'], $field['value'] ) && '' !== (string) $field['value'] ) {
				$rows[ (string) $field['label'] ] = (string) $field['value'];
			}
		}

		return $rows;
	}

	/**
	 * Simple, inline-styled HTML email. Every value is escaped here.
	 *
	 * @param string $intro
	 * @param array  $rows
	 * @param string $link
	 * @param string $link_text
	 * @return string
	 */
	public static function layout( $intro, $rows, $link = '', $link_text = '' ) {
		$html  = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#18181b;">';
		$html .= '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">';
		$html .= '<p style="font-size:16px;line-height:1.5;margin:0 0 16px;">' . esc_html( $intro ) . '</p>';
		$html .= '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><td style="padding:8px 0;border-top:1px solid #e4e4e7;color:#52525b;width:40%;vertical-align:top;">' . esc_html( $label ) . '</td>';
			$html .= '<td style="padding:8px 0;border-top:1px solid #e4e4e7;vertical-align:top;">' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		$html .= '</table>';
		if ( '' !== $link ) {
			$html .= '<p style="margin:24px 0 0;"><a href="' . esc_url( $link ) . '" style="display:inline-block;background:#18181b;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:6px;">' . esc_html( $link_text ) . '</a></p>';
		}
		$html .= '</div>';
		$html .= '<p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#71717a;text-align:center;">' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</p>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * @param string $to
	 * @param string $subject
	 * @param string $body
	 * @return bool
	 */
	protected static function send( $to, $subject, $body ) {
		$sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( ! $sent ) {
			bachs_pf_log( sprintf( 'Email "%s" to %s could not be sent.', $subject, $to ), 'warning' );
		}
		return (bool) $sent;
	}

	/**
	 * @param object $payment
	 * @return string
	 */
	protected static function form_title( $payment ) {
		$title = get_the_title( (int) $payment->form_id );
		return '' !== $title ? wp_specialchars_decode( wp_strip_all_tags( $title ), ENT_QUOTES ) : __( 'Payment form', 'bachs-payment-forms' );
	}
}
