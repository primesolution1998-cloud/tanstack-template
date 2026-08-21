<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_ID_Service {
	const PREFIXES = array( 'student'=>'STU', 'admission'=>'ADM', 'batch'=>'BAT', 'class'=>'CLS', 'demo'=>'DMO', 'payment'=>'PAY', 'employee'=>'EMP', 'lead'=>'LEAD', 'attendance'=>'ATT' );
	public static function generate( $type, $institute_id = 0 ) {
		global $wpdb;
		if ( ! isset( self::PREFIXES[$type] ) ) { return new WP_Error( 'eduflow_invalid_id_type', 'Invalid ID type.' ); }
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
		if ( $institute_id < 1 ) { return new WP_Error( 'eduflow_invalid_institute', 'A valid institute is required.' ); }
		$table = EduFlow_DB::table( 'id_sequences' ); $now = current_time( 'mysql', true ); $sequence = strtoupper( $type );
		// LAST_INSERT_ID(expr) makes the allocated value connection-local and the upsert atomic.
		$sql = $wpdb->prepare( "INSERT INTO $table (institute_id,sequence_type,current_value,status,created_at,updated_at) VALUES (%d,%s,LAST_INSERT_ID(1),'active',%s,%s) ON DUPLICATE KEY UPDATE current_value=LAST_INSERT_ID(current_value+1),updated_at=VALUES(updated_at)", $institute_id, $sequence, $now, $now );
		if ( false === $wpdb->query( $sql ) ) { return new WP_Error( 'eduflow_id_failure', 'Unable to allocate ID.' ); }
		$value = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		$settings = EduFlow_Settings::get_for_institute( $institute_id );
		$prefix = strtoupper( preg_replace( '/[^A-Z0-9]/', '', (string) ( $settings['id_prefix'] ?? '' ) ) );
		if ( '' === $prefix ) { return new WP_Error( 'eduflow_invalid_prefix', 'Configure an institute ID prefix.' ); }
		return $prefix . '-' . self::PREFIXES[$type] . '-' . str_pad( (string) $value, 6, '0', STR_PAD_LEFT );
	}
}
