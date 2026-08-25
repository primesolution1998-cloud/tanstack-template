<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Job_Service {
	const CRON_HOOK = 'eduflow_process_jobs';
	const RUN_LOCK = 'eduflow_job_runner_lock';
	public static function register() { add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) ); add_filter( 'eduflow_run_job_generate_classes', array( __CLASS__, 'generate_classes' ), 10, 2 ); add_filter( 'eduflow_run_job_google_calendar_sync', array( 'EduFlow_Google_Service', 'sync_job' ), 10, 2 ); }
	public static function generate_classes( $unused, $payload ) { return EduFlow_Class_Service::generate_rolling( absint( $payload['institute_id'] ?? 0 ) ); }
	public static function schedule() { if ( ! wp_next_scheduled( self::CRON_HOOK ) ) { wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK ); } }
	public static function enqueue( $type, $payload, $idempotency_key, $institute_id = null ) {
		global $wpdb; $now = current_time( 'mysql', true );
		$result = $wpdb->insert( EduFlow_DB::table( 'jobs' ), array( 'institute_id'=>$institute_id ?: EduFlow_Settings::institute_db_id(), 'job_type'=>sanitize_key( $type ), 'idempotency_key'=>sanitize_text_field( $idempotency_key ), 'payload'=>wp_json_encode( $payload ), 'status'=>'pending', 'attempts'=>0, 'available_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now ), array( '%d','%s','%s','%s','%s','%d','%s','%s','%s' ) );
		return false === $result ? new WP_Error( 'eduflow_duplicate_job', 'Job was not queued; its idempotency key may already exist.' ) : (int) $wpdb->insert_id;
	}
	public static function retry_failed_by_key( $key ) {
		global $wpdb;
		$table = EduFlow_DB::table( 'jobs' );
		$now = current_time( 'mysql', true );
		return $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='pending',attempts=0,started_at=NULL,completed_at=NULL,failed_at=NULL,available_at=%s,last_error=NULL,updated_at=%s WHERE idempotency_key=%s AND status IN ('failed','completed')", $now, $now, sanitize_text_field( $key ) ) );
	}
	public static function retry( $id ) {
		global $wpdb; return $wpdb->update( EduFlow_DB::table( 'jobs' ), array( 'status'=>'pending', 'available_at'=>current_time( 'mysql', true ), 'updated_at'=>current_time( 'mysql', true ) ), array( 'id'=>(int) $id, 'status'=>'failed' ), array( '%s','%s','%s' ), array( '%d','%s' ) );
	}
	private static function recover_pending_google_maps( $institute_id ) {
		global $wpdb;
		$events = EduFlow_DB::table( 'google_events' );
		$classes = EduFlow_DB::table( 'classes' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT g.lecture_id,g.sync_action FROM $events g INNER JOIN $classes c ON c.id=g.lecture_id AND c.institute_id=g.institute_id WHERE g.institute_id=%d AND g.sync_status='pending' AND c.class_status IN ('scheduled','rescheduled') ORDER BY c.start_datetime ASC LIMIT 200", $institute_id ), ARRAY_A );
		foreach ( $rows as $row ) {
			EduFlow_Google_Service::queue( (int) $row['lecture_id'], sanitize_key( $row['sync_action'] ?: 'upsert' ), $institute_id );
		}
	}
	public static function run() {
		global $wpdb; $table = EduFlow_DB::table( 'jobs' ); $now = current_time( 'mysql', true );
		$lock = get_option( self::RUN_LOCK, 0 );
		if ( $lock && ( time() - (int) $lock ) < 900 ) { return; }
		if ( $lock ) { delete_option( self::RUN_LOCK ); }
		if ( ! add_option( self::RUN_LOCK, time(), '', false ) ) { return; }
		$stale = gmdate( 'Y-m-d H:i:s', time() - 900 );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status='pending',started_at=NULL,available_at=%s,last_error=%s,updated_at=%s WHERE status='running' AND started_at<%s", $now, 'Recovered stale job claim.', $now, $stale ) );
		try {
		$institute_id = EduFlow_Settings::institute_db_id();
		self::recover_pending_google_maps( $institute_id );
		self::enqueue( 'generate_classes', array( 'institute_id'=>$institute_id ), 'rolling-classes:' . $institute_id . ':' . current_time( 'Y-m-d' ), $institute_id );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE status=%s AND available_at<=%s ORDER BY CASE WHEN job_type='google_calendar_sync' THEN 0 ELSE 1 END,id ASC LIMIT 20", 'pending', $now ) );
		foreach ( $ids as $id ) {
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='running',attempts=attempts+1,started_at=%s,updated_at=%s WHERE id=%d AND status='pending'", $now, $now, $id ) );
			if ( 1 !== $claimed ) { continue; }
			$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) );
			try {
				$result = apply_filters( 'eduflow_run_job_' . $job->job_type, null, json_decode( $job->payload, true ), $job );
				if ( null === $result ) { throw new RuntimeException( 'No handler is registered for this job type.' ); }
				if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
				$wpdb->update( $table, array( 'status'=>'completed', 'completed_at'=>$now, 'last_error'=>null, 'updated_at'=>$now ), array( 'id'=>$id ), array( '%s','%s','%s','%s' ), array( '%d' ) );
			} catch ( Throwable $error ) {
				$retryable = 0 === strpos( $error->getMessage(), '[retryable]' ) && (int) $job->attempts < 3; if ( $retryable && 'google_calendar_sync' === $job->job_type ) { $payload=json_decode( $job->payload, true ); EduFlow_Audit_Service::log( 'google_sync_retried', 'class', absint( $payload['lecture_id'] ?? 0 ), null, array( 'attempt'=>(int)$job->attempts + 1 ), absint( $payload['institute_id'] ?? 0 ) ); } $wpdb->update( $table, $retryable ? array( 'status'=>'pending', 'available_at'=>gmdate( 'Y-m-d H:i:s', time() + ( 60 * (int) $job->attempts ) ), 'last_error'=>sanitize_textarea_field( $error->getMessage() ), 'updated_at'=>$now ) : array( 'status'=>'failed', 'failed_at'=>$now, 'last_error'=>sanitize_textarea_field( $error->getMessage() ), 'updated_at'=>$now ), array( 'id'=>$id ) );
			}
		}
		update_option( 'eduflow_last_automation_run', $now, false );
		EduFlow_Access_Service::expire_due();
		EduFlow_Notification_Service::generate_due( $institute_id );
		} finally {
			delete_option( self::RUN_LOCK );
		}
	}
}
