<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Admission_Service {
	const STATUSES = array( 'inquiry_demo', 'admission_created', 'payment_pending', 'payment_verification', 'approved', 'active', 'hold', 'rejected' );
	const CLASS_TYPES = array( 'batch', 'group', '1-to-1', 'demo', 'trial' );
	private static function clean( $input ) {
		$mobile = EduFlow_Contact_Service::normalize_indian_mobile( $input['mobile'] ?? '' ); if ( is_wp_error( $mobile ) ) { return $mobile; }
		$email = sanitize_email( $input['email'] ?? '' );
		if ( ! empty( $input['email'] ) && ! is_email( $email ) ) { return new WP_Error( 'invalid_email', 'Enter a valid email or leave it empty.' ); }
		$class = sanitize_key( $input['class_type'] ?? '' ); if ( ! in_array( $class, self::CLASS_TYPES, true ) ) { return new WP_Error( 'invalid_class_type', 'Invalid class type.' ); }
		$total = round( max( 0, (float) ( $input['total_fee'] ?? 0 ) ), 2 ); $paid = round( max( 0, (float) ( $input['amount_paid'] ?? 0 ) ), 2 );
		return array( 'student_name'=>sanitize_text_field( $input['student_name'] ?? '' ), 'mobile'=>$mobile, 'email'=>$email ?: null, 'course'=>sanitize_text_field( $input['course'] ?? '' ), 'level'=>sanitize_text_field( $input['level'] ?? '' ), 'class_type'=>$class, 'preferred_timing'=>sanitize_text_field( $input['preferred_timing'] ?? '' ), 'admission_date'=>self::date( $input['admission_date'] ?? '' ), 'total_fee'=>$total, 'amount_paid'=>$paid, 'pending_amount'=>max( 0, $total - $paid ), 'payment_status'=>$paid >= $total && $total > 0 ? 'paid' : ( $paid > 0 ? 'partial' : 'pending' ), 'payment_method'=>in_array(sanitize_key($input['payment_method']??'upi'),EduFlow_Payment_Service::METHODS,true)?sanitize_key($input['payment_method']??'upi'):'upi', 'transaction_reference'=>sanitize_text_field($input['transaction_reference']??'')?:null, 'access_period'=>in_array(sanitize_key($input['access_period']??'3_months'),array('1_month','3_months','6_months','custom'),true)?sanitize_key($input['access_period']??'3_months'):'3_months', 'custom_access_end'=>sanitize_text_field($input['custom_access_end']??'')?:null, 'notes'=>sanitize_textarea_field( $input['notes'] ?? '' ) );
	}
	private static function date( $date ) { $d = DateTimeImmutable::createFromFormat( 'Y-m-d', sanitize_text_field( $date ) ); return $d && $d->format( 'Y-m-d' ) === $date ? $date : current_time( 'Y-m-d' ); }
	public static function create( $input, $institute_id = 0 ) {
		global $wpdb; $institute_id = $institute_id ?: EduFlow_Settings::institute_db_id(); $data = self::clean( $input ); if ( is_wp_error( $data ) ) { return $data; }
		if ( '' === $data['student_name'] || '' === $data['course'] ) { return new WP_Error( 'missing_fields', 'Name and course are required.' ); }
		$table = EduFlow_DB::table( 'admissions' ); $mode = EduFlow_Settings::get_all()['duplicate_detection'] ?? 'mobile_or_email';
		$duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT canonical_id FROM $table WHERE institute_id=%d AND status='active' AND admission_status NOT IN ('rejected') AND (mobile=%s OR (%s<>'' AND email=%s)) LIMIT 1", $institute_id, $data['mobile'], 'mobile_or_email' === $mode ? (string) $data['email'] : '', (string) $data['email'] ) );
		if ( $duplicate ) { return new WP_Error( 'duplicate_admission', 'Possible duplicate admission: ' . $duplicate ); }
		$id = EduFlow_ID_Service::generate( 'admission', $institute_id ); if ( is_wp_error( $id ) ) { return $id; }
		$now = current_time( 'mysql', true ); $data = array_merge( $data, array( 'canonical_id'=>$id, 'institute_id'=>$institute_id, 'admission_status'=>'admission_created', 'created_by'=>get_current_user_id(), 'status'=>'active', 'created_at'=>$now, 'updated_at'=>$now ) );
		if ( false === $wpdb->insert( $table, $data ) ) {
    return new WP_Error(
        'admission_create_failed',
        'Admission could not be created. DB: ' . $wpdb->last_error
    );
}
		EduFlow_Audit_Service::log( 'admission_created', 'admission', $id, null, $data, $institute_id ); return (int) $wpdb->insert_id;
	}
	public static function get( $id, $institute_id = 0, $lock = false ) { global $wpdb; $table=EduFlow_DB::table('admissions'); $institute_id=$institute_id?:EduFlow_Settings::institute_db_id(); return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND institute_id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id, $institute_id ), ARRAY_A ); }
	public static function update( $id, $input, $institute_id = 0 ) {
		global $wpdb; $institute_id=$institute_id?:EduFlow_Settings::institute_db_id(); $old=self::get($id,$institute_id); if(!$old){return new WP_Error('not_found','Admission not found.');} $data=self::clean($input); if(is_wp_error($data)){return $data;} $data['updated_at']=current_time('mysql',true); $wpdb->update(EduFlow_DB::table('admissions'),$data,array('id'=>$id,'institute_id'=>$institute_id)); EduFlow_Audit_Service::log('admission_updated','admission',$old['canonical_id'],$old,$data,$institute_id); return true;
	}
	public static function set_status( $id, $status, $institute_id = 0 ) { global $wpdb; $status=sanitize_key($status); if(!in_array($status,self::STATUSES,true)){return new WP_Error('invalid_status','Invalid status.');} $institute_id=$institute_id?:EduFlow_Settings::institute_db_id(); $old=self::get($id,$institute_id); if(!$old){return new WP_Error('not_found','Admission not found.');} $wpdb->update(EduFlow_DB::table('admissions'),array('admission_status'=>$status,'updated_at'=>current_time('mysql',true)),array('id'=>$id,'institute_id'=>$institute_id),array('%s','%s'),array('%d','%d')); EduFlow_Audit_Service::log('admission_'.$status,'admission',$old['canonical_id'],$old['admission_status'],$status,$institute_id); return true; }
}
