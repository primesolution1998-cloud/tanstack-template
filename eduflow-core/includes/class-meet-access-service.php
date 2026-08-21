<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Meet_Access_Service {
	public static function get_url( $lecture_id, $user_id = 0, $institute_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'authentication_required', 'Authentication is required.', array( 'status'=>401 ) );
		}
		if ( ! $institute_id && ! user_can( $user_id, 'eduflow_manage_classes' ) ) {
			$student_link = EduFlow_Account_Link_Service::linked_entity_any_institute( 'student', $user_id );
			$teacher_link = EduFlow_Account_Link_Service::linked_entity_any_institute( 'teacher', $user_id );
			$link = $student_link ?: $teacher_link;
			$institute_id = $link ? (int) $link['institute_id'] : 0;
		}
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
		$lecture = EduFlow_Class_Service::get( $lecture_id, $institute_id );
		if ( ! $lecture || ! in_array( $lecture['class_status'], array( 'scheduled', 'rescheduled' ), true ) ) {
			return new WP_Error( 'lecture_unavailable', 'Lecture is unavailable.', array( 'status'=>404 ) );
		}
		$allowed = user_can( $user_id, 'eduflow_manage_classes' );
		if ( ! $allowed ) {
			$teachers = EduFlow_DB::table( 'teachers' );
			$allowed = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $teachers WHERE id=%d AND institute_id=%d AND wp_user_id=%d AND teacher_status='active'", $lecture['teacher_id'], $institute_id, $user_id ) );
		}
		if ( ! $allowed ) {
			$allowed = self::student_is_entitled( $lecture, $user_id, $institute_id );
		}
		if ( ! $allowed ) {
			return new WP_Error( 'meet_forbidden', 'You are not entitled to this lecture.', array( 'status'=>403 ) );
		}
		$events = EduFlow_DB::table( 'google_events' );
		$url = $wpdb->get_var( $wpdb->prepare( "SELECT google_meet_url FROM $events WHERE institute_id=%d AND lecture_id=%d AND sync_status='synced'", $institute_id, $lecture_id ) );
		return $url ? esc_url_raw( $url ) : new WP_Error( 'meet_not_ready', 'Meet is not available yet.', array( 'status'=>404 ) );
	}

	private static function student_is_entitled( $lecture, $user_id, $institute_id ) {
		global $wpdb;
		$students = EduFlow_DB::table( 'students' );
		$access = EduFlow_DB::table( 'access' );
		$assignments = EduFlow_DB::table( 'batch_students' );
		$today = current_time( 'Y-m-d' );
		$sql = "SELECT s.id FROM $students s INNER JOIN $access x ON x.student_id=s.id AND x.institute_id=s.institute_id AND x.access_status='active' AND x.status='active' AND x.access_start<=%s AND x.access_end>=%s";
		$params = array( $today, $today );
		if ( $lecture['student_id'] ) {
			$sql .= ' WHERE s.id=%d AND s.institute_id=%d AND s.wp_user_id=%d AND s.student_status=%s';
			array_push( $params, $lecture['student_id'], $institute_id, $user_id, 'active' );
		} else {
			$sql .= " INNER JOIN $assignments a ON a.student_id=s.id AND a.institute_id=s.institute_id AND a.assignment_status='active' WHERE a.batch_id=%d AND s.institute_id=%d AND s.wp_user_id=%d AND s.student_status=%s";
			array_push( $params, $lecture['batch_id'], $institute_id, $user_id, 'active' );
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', ...$params ) );
	}
}
