<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Portal_Service {
	public static function student_dashboard( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		$link = EduFlow_Account_Link_Service::linked_entity_any_institute( 'student', $user_id );
		if ( ! $link ) {
			return new WP_Error( 'student_link_required', 'This account is not linked to a Student Master record.', array( 'status'=>403 ) );
		}
		$institute_id = (int) $link['institute_id'];
		$student = EduFlow_Student_Service::get( $link['entity_id'], $institute_id );
		if ( ! $student || (int) $student['wp_user_id'] !== $user_id ) {
			return new WP_Error( 'student_ownership_failed', 'Student ownership could not be verified.', array( 'status'=>403 ) );
		}
		$classes = self::student_classes( $student, $institute_id );
		return array(
			'portal_type'=>'student', 'institute_id'=>$institute_id,
			'profile'=>self::student_profile( $student, $institute_id ),
			'today'=>array_values( array_filter( $classes, static function( $class ){ return $class['class_date'] === current_time( 'Y-m-d' ); } ) ),
			'next'=>isset( $classes[0] ) ? $classes[0] : null,
			'upcoming'=>$classes,
			'notifications'=>EduFlow_Notification_Service::for_user( $user_id, 20, $institute_id ),
			'unread_notifications'=>EduFlow_Notification_Service::unread_count( $user_id, $institute_id ),
			'attendance'=>EduFlow_Attendance_Service::own_summary( $user_id ),
		);
	}

	private static function student_profile( $student, $institute_id ) {
		global $wpdb;
		$batches = EduFlow_DB::table( 'batches' );
		$teachers = EduFlow_DB::table( 'teachers' );
		$batch = $student['batch_id'] ? $wpdb->get_row( $wpdb->prepare( "SELECT id,canonical_id,batch_name,teacher_id FROM $batches WHERE id=%d AND institute_id=%d", $student['batch_id'], $institute_id ), ARRAY_A ) : null;
		$teacher_id = $student['teacher_id'] ?: ( $batch['teacher_id'] ?? 0 );
		$teacher = $teacher_id ? $wpdb->get_row( $wpdb->prepare( "SELECT id,canonical_id,name FROM $teachers WHERE id=%d AND institute_id=%d", $teacher_id, $institute_id ), ARRAY_A ) : null;
		$access = self::access_record( $student['id'], $institute_id );
		if ( $batch ) {
			$classroom = EduFlow_Classroom_Service::access( $batch['id'], get_current_user_id(), $institute_id );
			$batch['classroom_url'] = is_wp_error( $classroom ) ? null : EduFlow_Classroom_Service::join_url( $batch['id'] );
		}
		return array(
			'id'=>(int)$student['id'], 'name'=>$student['name'], 'canonical_id'=>$student['canonical_id'],
			'course'=>$student['course'], 'level'=>$student['level'], 'class_type'=>$student['class_type'],
			'batch'=>$batch, 'teacher'=>$teacher, 'admission_date'=>$student['admission_date'],
			'access_start'=>$access['access_start'] ?? $student['access_start'], 'access_end'=>$access['access_end'] ?? $student['access_end'],
			'access_status'=>$access['access_status'] ?? 'pending', 'fee_status'=>$student['fee_status'], 'student_status'=>$student['student_status'],
		);
	}

	private static function access_record( $student_id, $institute_id ) {
		global $wpdb;
		$table = EduFlow_DB::table( 'access' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT access_start,access_end,access_status FROM $table WHERE institute_id=%d AND student_id=%d AND status='active'", $institute_id, $student_id ), ARRAY_A );
	}

	private static function student_classes( $student, $institute_id ) {
		global $wpdb;
		$classes = EduFlow_DB::table( 'classes' );
		$batches = EduFlow_DB::table( 'batches' );
		$teachers = EduFlow_DB::table( 'teachers' );
		$assignments = EduFlow_DB::table( 'batch_students' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id,c.canonical_id,c.batch_id,c.student_id,c.teacher_id,c.class_type,c.class_date,c.start_datetime,c.end_datetime,c.timezone,c.class_status,b.batch_name,b.course,t.name AS teacher_name FROM $classes c LEFT JOIN $batches b ON b.id=c.batch_id AND b.institute_id=c.institute_id INNER JOIN $teachers t ON t.id=c.teacher_id AND t.institute_id=c.institute_id LEFT JOIN $assignments a ON a.batch_id=c.batch_id AND a.institute_id=c.institute_id AND a.student_id=%d AND a.assignment_status='active' WHERE c.institute_id=%d AND c.class_status IN ('scheduled','rescheduled') AND c.class_date>=%s AND (c.student_id=%d OR (c.student_id IS NULL AND a.id IS NOT NULL)) ORDER BY c.start_datetime ASC LIMIT 50", $student['id'], $institute_id, current_time( 'Y-m-d' ), $student['id'] ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$url = EduFlow_Meet_Access_Service::get_url( $row['id'], get_current_user_id(), $institute_id );
			$row['meet_url'] = is_wp_error( $url ) ? null : $url;
			$row['can_join'] = ! is_wp_error( $url );
			$row['classroom_url'] = null;
			if ( ! empty( $row['batch_id'] ) ) {
				$classroom = EduFlow_Classroom_Service::access( $row['batch_id'], get_current_user_id(), $institute_id );
				if ( ! is_wp_error( $classroom ) ) {
					$row['classroom_url'] = EduFlow_Classroom_Service::join_url( $row['batch_id'] );
				}
			}
		}
		return $rows;
	}

	public static function teacher_dashboard( $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ?: get_current_user_id();
		$link = EduFlow_Account_Link_Service::linked_entity_any_institute( 'teacher', $user_id );
		if ( ! $link ) {
			return new WP_Error( 'teacher_link_required', 'This account is not linked to a Teacher Master record.', array( 'status'=>403 ) );
		}
		$institute_id = (int) $link['institute_id'];
		$teacher = EduFlow_Teacher_Service::get( $link['entity_id'], $institute_id );
		if ( ! $teacher || (int) $teacher['wp_user_id'] !== $user_id ) {
			return new WP_Error( 'teacher_ownership_failed', 'Teacher ownership could not be verified.', array( 'status'=>403 ) );
		}
		$classes = EduFlow_DB::table( 'classes' );
		$batches = EduFlow_DB::table( 'batches' );
		$students = EduFlow_DB::table( 'students' );
		$demos = EduFlow_DB::table( 'demo_sessions' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id,c.canonical_id,c.batch_id,c.student_id,c.class_type,c.class_date,c.start_datetime,c.end_datetime,c.timezone,c.class_status,b.batch_name,b.course,s.name AS student_name,d.canonical_id AS demo_session_id FROM $classes c LEFT JOIN $batches b ON b.id=c.batch_id AND b.institute_id=c.institute_id LEFT JOIN $students s ON s.id=c.student_id AND s.institute_id=c.institute_id LEFT JOIN $demos d ON d.lecture_id=c.id AND d.institute_id=c.institute_id WHERE c.institute_id=%d AND c.teacher_id=%d AND c.class_status IN ('scheduled','rescheduled') AND c.class_date>=%s ORDER BY c.start_datetime ASC LIMIT 50", $institute_id, $teacher['id'], current_time( 'Y-m-d' ) ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$url = EduFlow_Meet_Access_Service::get_url( $row['id'], $user_id, $institute_id );
			$row['meet_url'] = is_wp_error( $url ) ? null : $url;
			$row['can_join'] = ! is_wp_error( $url );
			$row['classroom_url'] = null;
			if ( ! empty( $row['batch_id'] ) ) {
				$classroom = EduFlow_Classroom_Service::access( $row['batch_id'], $user_id, $institute_id );
				if ( ! is_wp_error( $classroom ) ) {
					$row['classroom_url'] = EduFlow_Classroom_Service::join_url( $row['batch_id'] );
				}
			}
		}
		return array(
			'portal_type'=>'teacher', 'institute_id'=>$institute_id,
			'profile'=>array_intersect_key( $teacher, array_flip( array( 'id','canonical_id','name','mobile','email','joining_date','available_days','available_start_time','available_end_time','teacher_status' ) ) ),
			'today'=>array_values( array_filter( $rows, static function( $class ){ return $class['class_date'] === current_time( 'Y-m-d' ); } ) ),
			'next'=>isset( $rows[0] ) ? $rows[0] : null,
			'upcoming'=>$rows,
			'notifications'=>EduFlow_Notification_Service::for_user( $user_id, 20, $institute_id ),
			'unread_notifications'=>EduFlow_Notification_Service::unread_count( $user_id, $institute_id ),
		);
	}
}
