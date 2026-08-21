<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Settings {
	const FIELDS = array( 'institute_name', 'institute_id', 'id_prefix', 'duplicate_detection', 'default_demo_slot_capacity', 'logo', 'support_email', 'support_mobile', 'whatsapp_number', 'website_url', 'currency', 'timezone', 'date_format', 'default_batch_capacity', 'default_course_duration', 'brand_primary_color', 'brand_secondary_color' );
	public static function defaults() { return array( 'institute_name'=>'YTC Education', 'institute_id'=>'YTC001', 'id_prefix'=>'YTC', 'duplicate_detection'=>'mobile_or_email', 'default_demo_slot_capacity'=>'6', 'timezone'=>'Asia/Kolkata', 'currency'=>'INR', 'date_format'=>'Y-m-d', 'default_batch_capacity'=>'30', 'default_course_duration'=>'3 months', 'brand_primary_color'=>'#2563eb', 'brand_secondary_color'=>'#0f172a' ); }
	public static function institute_db_id() { return (int) get_option( 'eduflow_default_institute_db_id', 0 ); }
	public static function get_all() { return wp_parse_args( get_option( 'eduflow_core_settings', array() ), self::defaults() ); }
	public static function get_for_institute( $institute_id ) {
		global $wpdb;$table=self::institute_db_id()===(int)$institute_id?self::get_all():array();$settings=EduFlow_DB::table('settings');$rows=$wpdb->get_results($wpdb->prepare("SELECT setting_key,setting_value FROM $settings WHERE institute_id=%d AND status=%s",$institute_id,'active'),ARRAY_A);foreach($rows as $row){$table[$row['setting_key']]=$row['setting_value'];}return $table;
	}
	public static function sanitize( $input ) {
		$out = array(); $input = is_array( $input ) ? $input : array();
		foreach ( self::FIELDS as $key ) { $value = $input[$key] ?? ''; $out[$key] = sanitize_text_field( $value ); }
		$out['support_email'] = sanitize_email( $input['support_email'] ?? '' );
		$out['website_url'] = esc_url_raw( $input['website_url'] ?? '' );
		$out['logo'] = esc_url_raw( $input['logo'] ?? '' );
		$out['brand_primary_color'] = sanitize_hex_color( $input['brand_primary_color'] ?? '' ) ?: '';
		$out['brand_secondary_color'] = sanitize_hex_color( $input['brand_secondary_color'] ?? '' ) ?: '';
		$out['default_batch_capacity'] = (string) max( 1, absint( $input['default_batch_capacity'] ?? 1 ) );
		$out['default_demo_slot_capacity'] = (string) max( 1, absint( $input['default_demo_slot_capacity'] ?? 6 ) );
		$out['id_prefix'] = substr( strtoupper( preg_replace( '/[^A-Z0-9]/', '', $out['id_prefix'] ) ), 0, 10 );
		$out['duplicate_detection'] = in_array( $out['duplicate_detection'], array( 'mobile', 'mobile_or_email' ), true ) ? $out['duplicate_detection'] : 'mobile_or_email';
		if ( ! in_array( $out['timezone'], timezone_identifiers_list(), true ) ) { $out['timezone'] = 'UTC'; }
		return $out;
	}
	public static function seed() {
		global $wpdb; $now = current_time( 'mysql', true ); $table = EduFlow_DB::table( 'institutes' );
		$id = self::institute_db_id();
		if ( ! $id ) {
			$wpdb->insert( $table, array( 'institute_key'=>'YTC001', 'name'=>'YTC Education', 'status'=>'active', 'created_at'=>$now, 'updated_at'=>$now ), array( '%s','%s','%s','%s','%s' ) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) { $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE institute_key = %s", 'YTC001' ) ); }
			update_option( 'eduflow_default_institute_db_id', $id, false );
		}
		if ( false === get_option( 'eduflow_core_settings', false ) ) { add_option( 'eduflow_core_settings', self::defaults(), '', false ); }
		self::sync( array(), self::get_all() );
	}
	public static function sync( $old, $settings ) {
		global $wpdb; $id = self::institute_db_id(); if ( ! $id || ! is_array( $settings ) ) { return; }
		$now = current_time( 'mysql', true ); $institutes = EduFlow_DB::table( 'institutes' ); $table = EduFlow_DB::table( 'settings' );
		$wpdb->update( $institutes, array( 'institute_key'=>$settings['institute_id'], 'name'=>$settings['institute_name'], 'updated_at'=>$now ), array( 'id'=>$id ), array( '%s','%s','%s' ), array( '%d' ) );
		foreach ( self::FIELDS as $key ) {
			$value = (string) ( $settings[$key] ?? '' );
			$wpdb->query( $wpdb->prepare( "INSERT INTO $table (institute_id,setting_key,setting_value,status,created_at,updated_at) VALUES (%d,%s,%s,'active',%s,%s) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),status='active',updated_at=VALUES(updated_at)", $id, $key, $value, $now, $now ) );
		}
	}
}
