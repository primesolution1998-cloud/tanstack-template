<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Attendance_Service {
	const STUDENT_STATUSES = array( 'present', 'absent', 'late', 'excused', 'cancelled', 'not_marked' );
	const DEMO_STATUSES = array( 'present', 'absent', 'late', 'no_show', 'cancelled' );

	public static function can_manage_lecture( $lecture, $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ?: get_current_user_id();
		if ( user_can( $user_id, 'eduflow_manage_classes' ) ) { return true; }
		$table = EduFlow_DB::table( 'teachers' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id=%d AND institute_id=%d AND wp_user_id=%d AND teacher_status='active'", $lecture['teacher_id'], $lecture['institute_id'], $user_id ) );
	}

	public static function roster( $lecture_id, $institute_id = 0, $user_id = 0 ) {
		global $wpdb;
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
		$lecture = EduFlow_Class_Service::get( $lecture_id, $institute_id );
		if ( ! $lecture || ! self::can_manage_lecture( $lecture, $user_id ) ) { return new WP_Error( 'attendance_forbidden', 'The lecture is unavailable or not assigned to you.', array( 'status'=>403 ) ); }
		$students=EduFlow_DB::table('students'); $assignments=EduFlow_DB::table('batch_students'); $attendance=EduFlow_DB::table('attendance');
		if ( $lecture['student_id'] ) {
			$sql="SELECT s.id,s.canonical_id,s.name,a.id AS attendance_id,COALESCE(a.attendance_status,'not_marked') AS attendance_status,a.join_time,a.leave_time,a.minutes_attended,a.notes FROM $students s LEFT JOIN $attendance a ON a.institute_id=s.institute_id AND a.lecture_id=%d AND a.student_id=s.id WHERE s.institute_id=%d AND s.id=%d";
			return $wpdb->get_results($wpdb->prepare($sql,$lecture_id,$institute_id,$lecture['student_id']),ARRAY_A);
		}
		$sql="SELECT s.id,s.canonical_id,s.name,a.id AS attendance_id,COALESCE(a.attendance_status,'not_marked') AS attendance_status,a.join_time,a.leave_time,a.minutes_attended,a.notes FROM $assignments x INNER JOIN $students s ON s.id=x.student_id AND s.institute_id=x.institute_id LEFT JOIN $attendance a ON a.institute_id=s.institute_id AND a.lecture_id=%d AND a.student_id=s.id WHERE x.institute_id=%d AND x.batch_id=%d AND x.assignment_status='active' ORDER BY s.name";
		return $wpdb->get_results($wpdb->prepare($sql,$lecture_id,$institute_id,$lecture['batch_id']),ARRAY_A);
	}

	public static function mark_student( $lecture_id, $student_id, $input, $institute_id = 0, $user_id = 0 ) {
		global $wpdb;
		$institute_id=$institute_id?:EduFlow_Settings::institute_db_id(); $user_id=$user_id?:get_current_user_id();
		$lecture=EduFlow_Class_Service::get($lecture_id,$institute_id); if(!$lecture||!self::can_manage_lecture($lecture,$user_id)){return new WP_Error('attendance_forbidden','You cannot mark this lecture.',array('status'=>403));}
		$roster=self::roster($lecture_id,$institute_id,$user_id); if(is_wp_error($roster)||!in_array($student_id,array_map('intval',wp_list_pluck($roster,'id')),true)){return new WP_Error('student_not_entitled','Student is not on this lecture roster.');}
		$status=sanitize_key($input['attendance_status']??'not_marked'); if(!in_array($status,self::STUDENT_STATUSES,true)){return new WP_Error('invalid_attendance_status','Invalid attendance status.');}
		$table=EduFlow_DB::table('attendance'); $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE institute_id=%d AND lecture_id=%d AND student_id=%d",$institute_id,$lecture_id,$student_id),ARRAY_A); $now=current_time('mysql',true);
		$data=array('attendance_status'=>$status,'join_time'=>self::datetime($input['join_time']??''),'leave_time'=>self::datetime($input['leave_time']??''),'minutes_attended'=>isset($input['minutes_attended'])?absint($input['minutes_attended']):null,'marked_by'=>$user_id,'marked_at'=>$now,'notes'=>sanitize_textarea_field($input['notes']??''),'updated_at'=>$now);
		if($old){$result=$wpdb->update($table,$data,array('id'=>$old['id'],'institute_id'=>$institute_id));$id=(int)$old['id'];$action='attendance_corrected';}
		else{$canonical=EduFlow_ID_Service::generate('attendance',$institute_id);if(is_wp_error($canonical)){return $canonical;}$data+=array('canonical_id'=>$canonical,'institute_id'=>$institute_id,'lecture_id'=>$lecture_id,'student_id'=>$student_id,'batch_id'=>$lecture['batch_id']?:null,'status'=>'active','created_at'=>$now);$result=$wpdb->insert($table,$data);$id=(int)$wpdb->insert_id;$action='attendance_created';}
		if(false===$result){return new WP_Error('attendance_save_failed','Attendance could not be saved.');}
		EduFlow_Audit_Service::log($action,'attendance',$id,$old,$data,$institute_id);
		EduFlow_Notification_Service::attendance($student_id,$lecture_id,$status,$institute_id);
		return $id;
	}

	public static function mark_demo( $booking_id, $status, $notes = '', $institute_id = 0, $user_id = 0 ) {
		global $wpdb; $institute_id=$institute_id?:EduFlow_Settings::institute_db_id();$user_id=$user_id?:get_current_user_id();$status=sanitize_key($status);
		if(!in_array($status,self::DEMO_STATUSES,true)){return new WP_Error('invalid_demo_attendance','Invalid demo attendance status.');}
		$bookings=EduFlow_DB::table('demo_bookings');$sessions=EduFlow_DB::table('demo_sessions');$booking=$wpdb->get_row($wpdb->prepare("SELECT b.*,d.lecture_id,d.teacher_id FROM $bookings b INNER JOIN $sessions d ON d.id=b.session_id AND d.institute_id=b.institute_id WHERE b.id=%d AND b.institute_id=%d",$booking_id,$institute_id),ARRAY_A);
		if(!$booking){return new WP_Error('demo_booking_missing','Demo booking not found.');}$lecture=EduFlow_Class_Service::get($booking['lecture_id'],$institute_id);if(!$lecture||!self::can_manage_lecture($lecture,$user_id)){return new WP_Error('attendance_forbidden','You cannot mark this demo booking.',array('status'=>403));}
		$table=EduFlow_DB::table('attendance');$old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE institute_id=%d AND demo_booking_id=%d",$institute_id,$booking_id),ARRAY_A);$now=current_time('mysql',true);$data=array('attendance_status'=>$status,'marked_by'=>$user_id,'marked_at'=>$now,'notes'=>sanitize_textarea_field($notes),'updated_at'=>$now);
		if($old){$result=$wpdb->update($table,$data,array('id'=>$old['id'],'institute_id'=>$institute_id));$id=(int)$old['id'];}else{$canonical=EduFlow_ID_Service::generate('attendance',$institute_id);if(is_wp_error($canonical)){return $canonical;}$data+=array('canonical_id'=>$canonical,'institute_id'=>$institute_id,'lecture_id'=>$booking['lecture_id'],'demo_session_id'=>$booking['session_id'],'demo_booking_id'=>$booking_id,'status'=>'active','created_at'=>$now);$result=$wpdb->insert($table,$data);$id=(int)$wpdb->insert_id;}
		if(false===$result){return new WP_Error('attendance_save_failed','Demo attendance could not be saved.');}$wpdb->update($bookings,array('attendance_status'=>$status,'updated_at'=>$now),array('id'=>$booking_id,'institute_id'=>$institute_id));EduFlow_Audit_Service::log('demo_attendance_marked','demo_booking',$booking_id,$old,$data,$institute_id);return $id;
	}

	public static function complete_lecture( $lecture_id, $notes = '', $institute_id = 0, $user_id = 0 ) { global $wpdb;$institute_id=$institute_id?:EduFlow_Settings::institute_db_id();$lecture=EduFlow_Class_Service::get($lecture_id,$institute_id);if(!$lecture||!self::can_manage_lecture($lecture,$user_id)){return new WP_Error('lecture_forbidden','You cannot complete this lecture.',array('status'=>403));}if('cancelled'===$lecture['class_status']){return new WP_Error('lecture_cancelled','A cancelled lecture cannot be completed.');}$result=$wpdb->update(EduFlow_DB::table('classes'),array('class_status'=>'completed','original_values'=>wp_json_encode(array('class_notes'=>sanitize_textarea_field($notes))),'updated_at'=>current_time('mysql',true)),array('id'=>$lecture_id,'institute_id'=>$institute_id));if(false!==$result){EduFlow_Audit_Service::log('lecture_completed','class',$lecture['canonical_id'],array('status'=>$lecture['class_status']),array('status'=>'completed','notes'=>sanitize_textarea_field($notes)),$institute_id);return true;}return new WP_Error('lecture_update_failed','Lecture could not be completed.'); }
	private static function datetime($value){$value=sanitize_text_field($value);return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/',$value)?str_replace('T',' ',$value):null;}

	public static function own_summary($user_id=0){global $wpdb;$user_id=$user_id?:get_current_user_id();$link=EduFlow_Account_Link_Service::linked_entity_any_institute('student',$user_id);if(!$link){return new WP_Error('student_link_required','Student account link required.',array('status'=>403));}$table=EduFlow_DB::table('attendance');$rows=$wpdb->get_results($wpdb->prepare("SELECT a.canonical_id,a.lecture_id,a.attendance_status,a.join_time,a.leave_time,a.minutes_attended,a.marked_at,c.class_date,c.canonical_id AS lecture_canonical_id FROM $table a INNER JOIN ".EduFlow_DB::table('classes')." c ON c.id=a.lecture_id AND c.institute_id=a.institute_id WHERE a.institute_id=%d AND a.student_id=%d ORDER BY c.class_date DESC LIMIT 50",$link['institute_id'],$link['entity_id']),ARRAY_A);$counts=array('present'=>0,'absent'=>0,'late'=>0,'excused'=>0);foreach($rows as $row){if(isset($counts[$row['attendance_status']])){$counts[$row['attendance_status']]++;}}$denominator=array_sum($counts);$attended=$counts['present']+$counts['late'];return array('records'=>$rows,'counts'=>$counts,'denominator'=>'present + absent + late + excused; cancelled and not marked excluded','percentage'=>$denominator?round(100*$attended/$denominator,2):null);}
}
