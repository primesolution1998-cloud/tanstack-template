<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Contact_Service {
	public static function normalize_indian_mobile( $mobile ) {
		$digits = preg_replace( '/\D+/', '', (string) $mobile );
		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '91' ) ) { $digits = substr( $digits, 2 ); }
		if ( 11 === strlen( $digits ) && '0' === $digits[0] ) { $digits = substr( $digits, 1 ); }
		return preg_match( '/^[6-9][0-9]{9}$/', $digits ) ? $digits : new WP_Error( 'invalid_mobile', 'Enter a valid 10-digit Indian mobile number.' );
	}
}
