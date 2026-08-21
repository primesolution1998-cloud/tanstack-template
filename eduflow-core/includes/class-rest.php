<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_REST {
	public static function register() { add_action( 'rest_api_init', array( __CLASS__, 'routes' ) ); }
	public static function routes() {
		register_rest_route( 'eduflow/v1', '/health', array( 'methods'=>WP_REST_Server::READABLE, 'callback'=>array( __CLASS__, 'health' ), 'permission_callback'=>array( __CLASS__, 'can_view' ) ) );
		register_rest_route( 'eduflow/v1', '/classes/(?P<id>\d+)/meet', array( 'methods'=>WP_REST_Server::READABLE, 'callback'=>array( __CLASS__, 'meet' ), 'permission_callback'=>static function(){ return is_user_logged_in(); }, 'args'=>array( 'id'=>array( 'sanitize_callback'=>'absint' ) ) ) );
		register_rest_route( 'eduflow/v1', '/portal/student', array( 'methods'=>WP_REST_Server::READABLE, 'callback'=>array(__CLASS__,'student_portal'), 'permission_callback'=>array(__CLASS__,'logged_in') ) );
		register_rest_route( 'eduflow/v1', '/portal/teacher', array( 'methods'=>WP_REST_Server::READABLE, 'callback'=>array(__CLASS__,'teacher_portal'), 'permission_callback'=>array(__CLASS__,'logged_in') ) );
		register_rest_route( 'eduflow/v1', '/portal/notifications', array( 'methods'=>WP_REST_Server::READABLE, 'callback'=>array(__CLASS__,'notifications'), 'permission_callback'=>array(__CLASS__,'logged_in') ) );
		register_rest_route( 'eduflow/v1', '/portal/notifications/(?P<id>\d+)/read', array( 'methods'=>WP_REST_Server::CREATABLE, 'callback'=>array(__CLASS__,'notification_read'), 'permission_callback'=>array(__CLASS__,'logged_in'), 'args'=>array('id'=>array('sanitize_callback'=>'absint')) ) );
			register_rest_route( 'eduflow/v1', '/demo-bookings/(?P<id>\d+)/meet', array( 'methods'=>WP_REST_Server::READABLE, 'callback'=>array(__CLASS__,'demo_meet'), 'permission_callback'=>array(__CLASS__,'demo_token_permission'), 'args'=>array('id'=>array('sanitize_callback'=>'absint'),'token'=>array('sanitize_callback'=>'sanitize_text_field','required'=>true)) ) );
			register_rest_route('eduflow/v1','/portal/student/attendance',array('methods'=>WP_REST_Server::READABLE,'callback'=>array(__CLASS__,'own_attendance'),'permission_callback'=>array(__CLASS__,'logged_in')));
			register_rest_route('eduflow/v1','/teacher/classes/(?P<id>\d+)/attendance',array(array('methods'=>WP_REST_Server::READABLE,'callback'=>array(__CLASS__,'teacher_roster'),'permission_callback'=>array(__CLASS__,'logged_in')),array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array(__CLASS__,'teacher_mark'),'permission_callback'=>array(__CLASS__,'logged_in'))));
			register_rest_route('eduflow/v1','/teacher/classes/(?P<id>\d+)/complete',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array(__CLASS__,'teacher_complete'),'permission_callback'=>array(__CLASS__,'logged_in')));
			register_rest_route('eduflow/v1','/teacher/demo-bookings/(?P<id>\d+)/attendance',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array(__CLASS__,'demo_attendance'),'permission_callback'=>array(__CLASS__,'logged_in')));
			register_rest_route('eduflow/v1','/reports/summary',array('methods'=>WP_REST_Server::READABLE,'callback'=>array(__CLASS__,'report_summary'),'permission_callback'=>static function(){return current_user_can('eduflow_manage_reports');}));
	}
	public static function logged_in() { return is_user_logged_in(); }
	public static function student_portal() { $data=EduFlow_Portal_Service::student_dashboard(); return is_wp_error($data)?$data:rest_ensure_response($data); }
	public static function teacher_portal() { $data=EduFlow_Portal_Service::teacher_dashboard(); return is_wp_error($data)?$data:rest_ensure_response($data); }
	public static function notifications() { return rest_ensure_response(EduFlow_Notification_Service::for_user()); }
	public static function notification_read($request) { return rest_ensure_response(array('updated'=>(bool)EduFlow_Notification_Service::mark_read(absint($request['id'])))); }
	public static function demo_token_permission($request) { return ! is_wp_error(EduFlow_Demo_Service::prospect_meet_url(absint($request['id']),sanitize_text_field($request['token']))); }
	public static function demo_meet($request) { $url=EduFlow_Demo_Service::prospect_meet_url(absint($request['id']),sanitize_text_field($request['token'])); return is_wp_error($url)?$url:rest_ensure_response(array('meet_url'=>$url)); }
	public static function can_view() { return current_user_can( 'eduflow_manage_institute' ); }
	public static function health() { return rest_ensure_response( EduFlow_Health_Service::report() ); }
	public static function meet( $request ) { $url=EduFlow_Meet_Access_Service::get_url( absint( $request['id'] ) ); return is_wp_error( $url ) ? $url : rest_ensure_response( array( 'meet_url'=>$url ) ); }
	public static function own_attendance(){ $data=EduFlow_Attendance_Service::own_summary(); return is_wp_error($data)?$data:rest_ensure_response($data); }
	public static function teacher_roster($request){$data=EduFlow_Attendance_Service::roster(absint($request['id']));return is_wp_error($data)?$data:rest_ensure_response($data);}
	public static function teacher_mark($request){$result=EduFlow_Attendance_Service::mark_student(absint($request['id']),absint($request->get_param('student_id')),(array)$request->get_json_params());return is_wp_error($result)?$result:rest_ensure_response(array('attendance_id'=>$result));}
	public static function teacher_complete($request){$result=EduFlow_Attendance_Service::complete_lecture(absint($request['id']),sanitize_textarea_field($request->get_param('notes')));return is_wp_error($result)?$result:rest_ensure_response(array('completed'=>true));}
	public static function demo_attendance($request){$result=EduFlow_Attendance_Service::mark_demo(absint($request['id']),sanitize_key($request->get_param('attendance_status')),sanitize_textarea_field($request->get_param('notes')));return is_wp_error($result)?$result:rest_ensure_response(array('attendance_id'=>$result));}
	public static function report_summary($request){$range=EduFlow_Report_Service::range(sanitize_key($request->get_param('range')?:'last_7_days'),$request->get_param('from'),$request->get_param('to'));return is_wp_error($range)?$range:rest_ensure_response(EduFlow_Report_Service::summary($range[0],$range[1]));}
}
