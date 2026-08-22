<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_YTC_Meet_Bridge {
    const PROVIDER = 'YTC Google Meet & Calendar Automation v1.3.1';

    public static function available() {
        return class_exists( 'YTC_Meet_Google' ) && is_callable( array( 'YTC_Meet_Google', 'is_connected' ) );
    }

    public static function connected() {
        return self::available() && (bool) YTC_Meet_Google::is_connected();
    }

    public static function merge_status( $status ) {
        if ( ! self::connected() ) return $status;
        $status['connected'] = true;
        $status['calendar_id'] = sanitize_text_field( get_option( 'ytc_meet_calendar_id', 'primary' ) ?: 'primary' );
        $status['connection_status'] = 'connected';
        $status['provider'] = self::PROVIDER;
        $status['bridge'] = true;
        $status['last_error'] = null;
        return $status;
    }

    public static function sync( $lecture_id, $action = 'upsert', $institute_id = 0 ) {
        global $wpdb;
        $institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
        $lecture = EduFlow_Class_Service::get( $lecture_id, $institute_id );
        if ( ! $lecture ) return new WP_Error( 'permanent_google_sync', 'Lecture not found.' );
        if ( ! self::connected() ) return new WP_Error( 'google_auth_required', 'YTC Google Meet is not connected.' );

        $map_table = EduFlow_DB::table( 'google_events' );
        $map = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $map_table WHERE institute_id=%d AND lecture_id=%d", $institute_id, $lecture_id ), ARRAY_A );
        if ( ! $map ) {
            EduFlow_Google_Service::queue( $lecture_id, $action, $institute_id );
            $map = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $map_table WHERE institute_id=%d AND lecture_id=%d", $institute_id, $lecture_id ), ARRAY_A );
        }
        if ( ! $map ) return new WP_Error( 'permanent_google_sync', 'Google event mapping could not be created.' );

        if ( 'cancel' === $action || 'cancelled' === $lecture['class_status'] ) {
            if ( ! empty( $map['google_event_id'] ) ) {
                $result = YTC_Meet_Google::cancel_event( $map['google_event_id'] );
                if ( is_wp_error( $result ) ) return self::fail( $map, $result );
            }
            return self::mark_cancelled( $map, $lecture, $institute_id );
        }

        if ( 'synced' === $map['sync_status'] && ! empty( $map['google_event_id'] ) && ! empty( $map['google_meet_url'] ) ) return true;

        // Reschedule/update fallback for YTC v1.3.1: cancel previous event, then create one replacement.
        if ( ! empty( $map['google_event_id'] ) ) {
            $cancel = YTC_Meet_Google::cancel_event( $map['google_event_id'] );
            if ( is_wp_error( $cancel ) ) return self::fail( $map, $cancel );
        }

        $args = self::event_args( $lecture, $institute_id );
        $result = YTC_Meet_Google::create_meet_event( $args );
        if ( is_wp_error( $result ) ) return self::fail( $map, $result );

        $event_id = sanitize_text_field( $result['id'] ?? '' );
        $meet = esc_url_raw( $result['hangoutLink'] ?? '' );
        if ( ! $meet && ! empty( $result['conferenceData']['entryPoints'] ) ) {
            foreach ( $result['conferenceData']['entryPoints'] as $point ) {
                if ( 'video' === ( $point['entryPointType'] ?? '' ) && ! empty( $point['uri'] ) ) { $meet = esc_url_raw( $point['uri'] ); break; }
            }
        }
        if ( ! $event_id ) return self::fail( $map, new WP_Error( 'google_invalid_response', 'Google returned no event ID.' ) );

        $now = current_time( 'mysql', true );
        $wpdb->update( $map_table, array(
            'google_event_id'=>$event_id,
            'google_calendar_id'=>sanitize_text_field( get_option( 'ytc_meet_calendar_id', 'primary' ) ?: 'primary' ),
            'google_meet_url'=>$meet ?: null,
            'google_event_url'=>esc_url_raw( $result['htmlLink'] ?? '' ),
            'sync_status'=>$meet ? 'synced' : 'pending',
            'sync_action'=>'upsert',
            'sync_attempts'=>(int)$map['sync_attempts'] + 1,
            'last_synced_at'=>$now,
            'last_error'=>null,
            'updated_at'=>$now,
        ), array( 'id'=>$map['id'], 'institute_id'=>$institute_id ) );
        $wpdb->update( EduFlow_DB::table( 'classes' ), array( 'meet_url'=>$meet ?: null, 'external_event_id'=>$event_id, 'updated_at'=>$now ), array( 'id'=>$lecture_id, 'institute_id'=>$institute_id ) );
        update_option( 'eduflow_google_last_successful_sync', $now, false );
        EduFlow_Audit_Service::log( empty( $map['google_event_id'] ) ? 'google_event_created' : 'google_event_updated', 'class', $lecture['canonical_id'], null, array( 'provider'=>self::PROVIDER, 'meet_created'=>(bool)$meet ), $institute_id );
        if ( $meet ) EduFlow_Notification_Service::notify_class( $lecture_id, 'meet_ready', 'Meet ready', 'Google Meet is ready for your class.', $event_id, $institute_id );
        return $meet ? true : new WP_Error( 'retryable_google_sync', '[retryable] Google event exists but Meet URL is still pending.' );
    }

    private static function event_args( $lecture, $institute_id ) {
        global $wpdb;
        $emails = array();
        $teacher = EduFlow_Teacher_Service::get( $lecture['teacher_id'], $institute_id );
        if ( $teacher && is_email( $teacher['email'] ?? '' ) ) $emails[] = strtolower( $teacher['email'] );
        $students = EduFlow_DB::table( 'students' );
        if ( 'demo' === $lecture['class_type'] && ! $lecture['batch_id'] ) {
            $sessions=EduFlow_DB::table('demo_sessions'); $bookings=EduFlow_DB::table('demo_bookings');
            $rows=$wpdb->get_col($wpdb->prepare("SELECT b.email FROM $sessions d INNER JOIN $bookings b ON b.session_id=d.id AND b.institute_id=d.institute_id WHERE d.institute_id=%d AND d.lecture_id=%d AND b.booking_status='booked'",$institute_id,$lecture['id']));
            foreach($rows as $email) if(is_email($email)) $emails[]=strtolower($email);
        } elseif ( $lecture['student_id'] ) {
            $email=$wpdb->get_var($wpdb->prepare("SELECT email FROM $students WHERE id=%d AND institute_id=%d",$lecture['student_id'],$institute_id)); if(is_email($email)) $emails[]=strtolower($email);
        } else {
            $assign=EduFlow_DB::table('batch_students');
            $rows=$wpdb->get_col($wpdb->prepare("SELECT s.email FROM $assign a INNER JOIN $students s ON s.id=a.student_id AND s.institute_id=a.institute_id WHERE a.institute_id=%d AND a.batch_id=%d AND a.assignment_status='active'",$institute_id,$lecture['batch_id'])); foreach($rows as $email) if(is_email($email)) $emails[]=strtolower($email);
        }
        $emails=array_values(array_unique(array_filter(array_map('sanitize_email',$emails))));
        $batch = $lecture['batch_id'] ? EduFlow_Batch_Service::get( $lecture['batch_id'], $institute_id ) : null;
        $start=(new DateTimeImmutable($lecture['start_datetime'],new DateTimeZone('UTC')))->format(DateTimeInterface::RFC3339);
        $end=(new DateTimeImmutable($lecture['end_datetime'],new DateTimeZone('UTC')))->format(DateTimeInterface::RFC3339);
        return array('title'=>'EduFlow: '.($batch['batch_name']??$lecture['canonical_id']),'description'=>'EduFlow Lecture '.$lecture['canonical_id'],'start'=>$start,'end'=>$end,'timezone'=>'UTC','attendees'=>$emails);
    }

    private static function fail( $map, $error ) {
        global $wpdb; $message=sanitize_text_field($error->get_error_message());
        $wpdb->update(EduFlow_DB::table('google_events'),array('sync_status'=>'failed','sync_attempts'=>(int)$map['sync_attempts']+1,'last_error'=>$message,'updated_at'=>current_time('mysql',true)),array('id'=>$map['id'],'institute_id'=>$map['institute_id']));
        EduFlow_Audit_Service::log('google_sync_failed','class',$map['lecture_id'],null,array('provider'=>self::PROVIDER,'error_code'=>$error->get_error_code()),$map['institute_id']);
        return new WP_Error('retryable_google_sync','[retryable] '.$message);
    }

    private static function mark_cancelled( $map, $lecture, $institute_id ) {
        global $wpdb; $now=current_time('mysql',true);
        $wpdb->update(EduFlow_DB::table('google_events'),array('sync_status'=>'cancelled','last_synced_at'=>$now,'last_error'=>null,'updated_at'=>$now),array('id'=>$map['id'],'institute_id'=>$institute_id));
        EduFlow_Audit_Service::log('google_event_cancelled','class',$lecture['canonical_id'],null,array('provider'=>self::PROVIDER),$institute_id); return true;
    }
}
