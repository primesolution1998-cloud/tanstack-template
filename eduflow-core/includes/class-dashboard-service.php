<?php
defined( 'ABSPATH' ) || exit;
final class EduFlow_Dashboard_Service {
	public static function metrics( $institute_id = 0 ) {
		global $wpdb;
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
		$today = current_time( 'Y-m-d' );
		$tables = array(
			'classes'=>EduFlow_DB::table('classes'), 'students'=>EduFlow_DB::table('students'), 'teachers'=>EduFlow_DB::table('teachers'),
			'batches'=>EduFlow_DB::table('batches'), 'demo_sessions'=>EduFlow_DB::table('demo_sessions'), 'demo_bookings'=>EduFlow_DB::table('demo_bookings'),
			'admissions'=>EduFlow_DB::table('admissions'), 'payments'=>EduFlow_DB::table('payments'), 'access'=>EduFlow_DB::table('access'), 'google'=>EduFlow_DB::table('google_events'), 'attendance'=>EduFlow_DB::table('attendance'), 'notifications'=>EduFlow_DB::table('notifications'), 'history'=>EduFlow_DB::table('access_history'),
		);
		return array(
			'today_classes'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['classes']} WHERE institute_id=%d AND class_date=%s AND class_status IN ('scheduled','rescheduled')",$institute_id,$today)),
			'upcoming_classes'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['classes']} WHERE institute_id=%d AND class_date>%s AND class_status IN ('scheduled','rescheduled')",$institute_id,$today)),
			'active_students'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['students']} WHERE institute_id=%d AND student_status=%s",$institute_id,'active')),
			'active_teachers'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['teachers']} WHERE institute_id=%d AND teacher_status=%s",$institute_id,'active')),
			'active_batches'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['batches']} WHERE institute_id=%d AND batch_status=%s",$institute_id,'active')),
			'demo_sessions_today'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['demo_sessions']} WHERE institute_id=%d AND demo_date=%s AND session_status=%s",$institute_id,$today,'scheduled')),
			'demo_bookings_today'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['demo_bookings']} b INNER JOIN {$tables['demo_sessions']} d ON d.id=b.session_id AND d.institute_id=b.institute_id WHERE b.institute_id=%d AND d.demo_date=%s AND b.booking_status=%s",$institute_id,$today,'booked')),
			'pending_admissions'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['admissions']} WHERE institute_id=%d AND admission_status IN ('admission_created','payment_pending','payment_verification','hold')",$institute_id)),
			'pending_payments'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['payments']} WHERE institute_id=%d AND verification_status=%s",$institute_id,'pending')),
			'fee_due'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['students']} WHERE institute_id=%d AND fee_status<>%s",$institute_id,'paid')),
			'expired_access'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['access']} WHERE institute_id=%d AND access_status=%s",$institute_id,'expired')),
			'failed_google_sync'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['google']} WHERE institute_id=%d AND sync_status=%s",$institute_id,'failed')),
			'completed_today'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['classes']} WHERE institute_id=%d AND class_date=%s AND class_status='completed'",$institute_id,$today)),
			'pending_today'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['classes']} WHERE institute_id=%d AND class_date=%s AND class_status IN ('scheduled','rescheduled')",$institute_id,$today)),
			'attendance_today'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['attendance']} a INNER JOIN {$tables['classes']} c ON c.id=a.lecture_id AND c.institute_id=a.institute_id WHERE a.institute_id=%d AND c.class_date=%s AND a.attendance_status IN ('present','late')",$institute_id,$today)),
			'verified_payments'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['payments']} WHERE institute_id=%d AND verification_status='verified'",$institute_id)),
			'renewals'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['history']} WHERE institute_id=%d AND change_type='extension'",$institute_id)),
			'access_expiring'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['access']} WHERE institute_id=%d AND access_status='active' AND access_end BETWEEN %s AND %s",$institute_id,$today,gmdate('Y-m-d',strtotime($today.' +7 days')))),
			'failed_notifications'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['notifications']} WHERE institute_id=%d AND notification_status='failed'",$institute_id)),
		);
	}
	public static function search( $term, $institute_id = 0 ) {
		global $wpdb;
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
		$term = sanitize_text_field( $term );
		if ( '' === $term ) { return array(); }
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$definitions = array(
			'students'=>array('students','canonical_id','name','mobile'), 'teachers'=>array('teachers','canonical_id','name','mobile'),
			'batches'=>array('batches','canonical_id','batch_name','course'), 'admissions'=>array('admissions','canonical_id','student_name','mobile'),
			'classes'=>array('classes','canonical_id','class_date','class_status'), 'payments'=>array('payments','canonical_id','transaction_reference','verification_status'),
			'demo_sessions'=>array('demo_sessions','canonical_id','demo_date','session_status'),
		);
		$results = array();
		foreach ( $definitions as $label=>$definition ) {
			$table=EduFlow_DB::table($definition[0]); $a=$definition[1]; $b=$definition[2]; $c=$definition[3];
			$results[$label]=$wpdb->get_results($wpdb->prepare("SELECT id,$a AS reference,$b AS label,$c AS detail FROM $table WHERE institute_id=%d AND ($a LIKE %s OR $b LIKE %s OR $c LIKE %s) ORDER BY id DESC LIMIT 10",$institute_id,$like,$like,$like),ARRAY_A);
		}
		return $results;
	}

}
