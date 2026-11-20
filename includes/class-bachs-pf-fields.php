<?php
/**
 * Custom form fields, defined in the form content with one prefixed shortcode:
 *
 *   [bachs_field type="text" label="Phone number" required="yes"]
 *   [bachs_field type="select" label="T-shirt size" options="S|M|L"]
 *
 * The server reads the field list from the form itself, so it only accepts and stores fields
 * the form actually has, and validates each one by type.
 *
 * @package Bachs_Payment_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_PF_Fields {

	const SHORTCODE = 'bachs_field';

	const TYPES = array( 'text', 'email', 'tel', 'number', 'url', 'date', 'textarea', 'select', 'radio', 'checkbox' );

	const MAX_LENGTH = 2000;

	/**
	 * Parse the field definitions from a form's content.
	 *
	 * @param string $content
	 * @return array key => array( key, type, label, required, options, placeholder )
	 */
	public static function definitions( $content ) {
		$fields = array();

		if ( false === strpos( (string) $content, '[' . self::SHORTCODE ) ) {
			return $fields;
		}

		preg_match_all( '/' . get_shortcode_regex( array( self::SHORTCODE ) ) . '/s', (string) $content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			// Escaped shortcode: [[bachs_field ...]].
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}
			$field = self::normalise( shortcode_parse_atts( $match[3] ) );
			if ( $field && ! isset( $fields[ $field['key'] ] ) ) {
				$fields[ $field['key'] ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * @param array|string $atts
	 * @return array|null
	 */
	public static function normalise( $atts ) {
		$atts = shortcode_atts(
			array(
				'type'        => 'text',
				'label'       => '',
				'name'        => '',
				'required'    => 'no',
				'options'     => '',
				'placeholder' => '',
			),
			is_array( $atts ) ? $atts : array()
		);

		$label = sanitize_text_field( '' !== $atts['label'] ? $atts['label'] : $atts['name'] );
		if ( '' === $label ) {
			return null;
		}

		$type = sanitize_key( $atts['type'] );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = 'text';
		}

		$key = sanitize_key( str_replace( array( ' ', '-' ), '_', '' !== $atts['name'] ? $atts['name'] : $label ) );
		if ( '' === $key || in_array( $key, array( 'name', 'email', 'amount', 'quantity' ), true ) ) {
			$key = 'field_' . substr( md5( $label ), 0, 8 );
		}

		$options = array();
		if ( '' !== $atts['options'] ) {
			$options = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( '|', $atts['options'] ) ) ), 'strlen' ) );
		}
		if ( in_array( $type, array( 'select', 'radio' ), true ) && empty( $options ) ) {
			$type = 'text';
		}

		return array(
			'key'         => $key,
			'type'        => $type,
			'label'       => $label,
			'required'    => in_array( strtolower( (string) $atts['required'] ), array( 'yes', 'true', '1', 'required' ), true ),
			'options'     => $options,
			'placeholder' => sanitize_text_field( $atts['placeholder'] ),
		);
	}

	/**
	 * Sanitise submitted values as soon as they are read: each defined field with the sanitiser
	 * for its type (sanitize_email, esc_url_raw, sanitize_textarea_field...). Values for fields the
	 * form doesn't define are dropped.
	 *
	 * @param array $definitions
	 * @param mixed $input Unslashed $_POST['bachs_pf_fields'].
	 * @return array key => sanitised value.
	 */
	public static function sanitize( $definitions, $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = array();
		foreach ( $definitions as $key => $field ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = self::clean( $field, $input[ $key ] );
			}
		}
		return $clean;
	}

	/**
	 * Validate submitted values against the definitions.
	 *
	 * @param array $definitions
	 * @param array $input Values from sanitize().
	 * @return array{values: array, errors: string[]} values: key => array( label, value ).
	 */
	public static function validate( $definitions, $input ) {
		$values = array();
		$errors = array();
		$input  = is_array( $input ) ? $input : array();

		foreach ( $definitions as $key => $field ) {
			$raw   = isset( $input[ $key ] ) ? $input[ $key ] : '';
			$value = self::clean( $field, $raw );

			$empty = is_array( $value ) ? empty( $value ) : '' === $value;

			if ( $empty ) {
				if ( $field['required'] ) {
					/* translators: %s: field label */
					$errors[] = sprintf( __( '%s is required.', 'bachs-payment-forms' ), $field['label'] );
				}
				$values[ $key ] = array(
					'label' => $field['label'],
					'value' => '',
				);
				continue;
			}

			$error = self::check( $field, $value );
			if ( '' !== $error ) {
				$errors[] = $error;
				continue;
			}

			$values[ $key ] = array(
				'label' => $field['label'],
				'value' => is_array( $value ) ? implode( ', ', $value ) : $value,
			);
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * @param array $field
	 * @param mixed $raw
	 * @return string|array
	 */
	protected static function clean( $field, $raw ) {
		if ( 'checkbox' === $field['type'] ) {
			if ( empty( $field['options'] ) ) {
				return ( '' !== (string) ( is_array( $raw ) ? '' : $raw ) ) ? __( 'Yes', 'bachs-payment-forms' ) : '';
			}
			$picked = array_map( 'sanitize_text_field', array_map( 'strval', array_filter( (array) $raw, 'is_scalar' ) ) );
			return array_values( array_filter( array_map( 'trim', $picked ), 'strlen' ) );
		}

		if ( is_array( $raw ) ) {
			return '';
		}

		$raw = (string) $raw;

		switch ( $field['type'] ) {
			case 'textarea':
				return trim( sanitize_textarea_field( $raw ) );
			case 'email':
				return trim( sanitize_email( $raw ) );
			case 'url':
				return esc_url_raw( trim( $raw ), array( 'http', 'https' ) );
			default:
				return trim( sanitize_text_field( $raw ) );
		}
	}

	/**
	 * @param array        $field
	 * @param string|array $value
	 * @return string Error message, or '' when valid.
	 */
	protected static function check( $field, $value ) {
		$label = $field['label'];

		if ( is_string( $value ) && strlen( $value ) > self::MAX_LENGTH ) {
			/* translators: %s: field label */
			return sprintf( __( '%s is too long.', 'bachs-payment-forms' ), $label );
		}

		switch ( $field['type'] ) {
			case 'email':
				/* translators: %s: field label */
				return is_email( $value ) ? '' : sprintf( __( '%s must be a valid email address.', 'bachs-payment-forms' ), $label );
			case 'number':
				/* translators: %s: field label */
				return is_numeric( $value ) ? '' : sprintf( __( '%s must be a number.', 'bachs-payment-forms' ), $label );
			case 'date':
				$date = DateTime::createFromFormat( 'Y-m-d', $value );
				/* translators: %s: field label */
				return ( $date && $date->format( 'Y-m-d' ) === $value ) ? '' : sprintf( __( '%s must be a valid date.', 'bachs-payment-forms' ), $label );
			case 'url':
				/* translators: %s: field label */
				return '' !== $value ? '' : sprintf( __( '%s must be a valid web address.', 'bachs-payment-forms' ), $label );
			case 'select':
			case 'radio':
				/* translators: %s: field label */
				return in_array( $value, $field['options'], true ) ? '' : sprintf( __( 'Choose a valid option for %s.', 'bachs-payment-forms' ), $label );
			case 'checkbox':
				if ( is_array( $value ) && array_diff( $value, $field['options'] ) ) {
					/* translators: %s: field label */
					return sprintf( __( 'Choose valid options for %s.', 'bachs-payment-forms' ), $label );
				}
				return '';
		}

		return '';
	}

	/**
	 * Render one field (used by the shortcode while a form is being rendered).
	 *
	 * @param array  $field
	 * @param string $id_prefix Unique per form instance.
	 * @return string
	 */
	public static function render( $field, $id_prefix ) {
		$name     = 'bachs_pf_fields[' . $field['key'] . ']';
		$id       = $id_prefix . '-' . $field['key'];
		$required = $field['required'] ? ' required' : '';
		$label    = esc_html( $field['label'] ) . ( $field['required'] ? ' <span class="bachs-pf-required" aria-hidden="true">*</span>' : '' );
		$ph       = '' !== $field['placeholder'] ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';

		$html = '<div class="bachs-pf-field bachs-pf-field-' . esc_attr( $field['type'] ) . '">';

		switch ( $field['type'] ) {
			case 'textarea':
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label>';
				$html .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" maxlength="' . (int) self::MAX_LENGTH . '"' . $ph . $required . '></textarea>';
				break;

			case 'select':
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label>';
				$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $required . '>';
				$html .= '<option value="">' . esc_html__( 'Choose…', 'bachs-payment-forms' ) . '</option>';
				foreach ( $field['options'] as $option ) {
					$html .= '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>';
				}
				$html .= '</select>';
				break;

			case 'radio':
			case 'checkbox':
				if ( 'checkbox' === $field['type'] && empty( $field['options'] ) ) {
					$html .= '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . $required . '> ' . $label . '</label>';
					break;
				}
				$html .= '<fieldset><legend>' . $label . '</legend>';
				foreach ( $field['options'] as $i => $option ) {
					$input_name = 'checkbox' === $field['type'] ? $name . '[]' : $name;
					$html      .= '<label><input type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $option ) . '"' . ( 'radio' === $field['type'] && $field['required'] && 0 === $i ? ' required' : '' ) . '> ' . esc_html( $option ) . '</label>';
				}
				$html .= '</fieldset>';
				break;

			default:
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label>';
				$html .= '<input type="' . esc_attr( $field['type'] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( 'number' === $field['type'] ? ' step="any"' : ' maxlength="' . (int) self::MAX_LENGTH . '"' ) . $ph . $required . '>';
		}

		return $html . '</div>';
	}
}
