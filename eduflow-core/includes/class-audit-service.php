<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Audit_Service {
	private static function redact( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		foreach ( $value as $key => $item ) {
			$value[$key] = preg_match( '/(?:pass(?:word)?|token|secret|api[_-]?key|authorization|cookie|credential)/i', (string) $key ) ? '[REDACTED]' : self::redact( $item );
		}
		return $value;
	}
	public static function log( $action, $entity_type, $entity_id = null, $old = null, $new = null, $institute_id = null ) {
		global $wpdb;
		$ip = apply_filters( 'eduflow_audit_ip_address', '' ); // Opt-in: avoids trusting spoofable request headers by default.
		return false !== $wpdb->insert( EduFlow_DB::table( 'audit_log' ), array(
			'institute_id'=>$institute_id ?: EduFlow_Settings::institute_db_id(), 'user_id'=>get_current_user_id() ?: null,
			'action'=>sanitize_key( $action ), 'entity_type'=>sanitize_key( $entity_type ), 'entity_id'=>null === $entity_id ? null : sanitize_text_field( (string) $entity_id ),
			'old_value'=>null === $old ? null : wp_json_encode( self::redact( $old ) ), 'new_value'=>null === $new ? null : wp_json_encode( self::redact( $new ) ),
			'ip_address'=>sanitize_text_field( $ip ), 'status'=>'recorded', 'created_at'=>current_time( 'mysql', true ),
		), array( '%d','%d','%s','%s','%s','%s','%s','%s','%s','%s' ) );
	}
}
