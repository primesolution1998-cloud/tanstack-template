<?php
defined( 'ABSPATH' ) || exit;
final class EduFlow_Demo_Service {
	const SESSION_STATUSES=array('scheduled','completed','cancelled');
	const ATTENDANCE_STATUSES=array('pending','attended','absent');
	public static function session_key($institute_id,$date,$start,$end,$teacher_id){return hash('sha256',absint($institute_id).'|'.$date.'|'.self::time($start).'|'.self::time($end).'|'.absint($teacher_id));}
	private static function time($time){$time=sanitize_text_field((string)$time);if(preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/',$time)){return strlen($time)===5?$time.':00':$time;}return '';}
	private static function date($date){$date=sanitize_text_field((string)$date);$parsed=DateTimeImmutable::createFromFormat('Y-m-d',$date);return $parsed&&$parsed->format('Y-m-d')===$date?$date:'';}
	public static function get_session($id,$institute_id=0,$lock=false){global $wpdb;$table=EduFlow_DB::table('demo_sessions');$institute_id=$institute_id?:EduFlow_Settings::institute_db_id();return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND institute_id=%d".($lock?' FOR UPDATE':''),$id,$institute_id),ARRAY_A);}
	public static function ensure_session($input,$institute_id=0){global $wpdb;$institute_id=$institute_id?:EduFlow_Settings::institute_db_id();$date=self::date($input['demo_date']??'');$start=self::time($input['start_time']??'');$end=self::time($input['end_time']??'');$teacher_id=absint($input['teacher_id']??0);$capacity=max(1,absint($input['capacity']??(EduFlow_Settings::get_for_institute($institute_id)['default_demo_slot_capacity']??6)));if(!$date||!$start||!$end||$start>=$end){return new WP_Error('invalid_demo_slot','A valid demo date and start/end time are required.');}$teacher=EduFlow_Teacher_Service::get($teacher_id,$institute_id);if(!$teacher||'active'!==$teacher['teacher_status']){return new WP_Error('invalid_demo_teacher','Select an active teacher from this institute.');}$key=self::session_key($institute_id,$date,$start,$end,$teacher_id);$sessions=EduFlow_DB::table('demo_sessions');$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions WHERE session_key=%s AND institute_id=%d",$key,$institute_id),ARRAY_A);if($existing){return $existing;}$timezone=EduFlow_Settings::get_for_institute($institute_id)['timezone']??'Asia/Kolkata';$begin=EduFlow_Class_Service::to_utc($date,$start,$timezone);$finish=EduFlow_Class_Service::to_utc($date,$end,$timezone);if(is_wp_error($begin)||is_wp_error($finish)){return new WP_Error('invalid_demo_datetime','Demo slot date/time is invalid.');}$conflict=EduFlow_Class_Service::conflict($institute_id,$teacher_id,null,$begin,$finish);if($conflict){return new WP_Error('demo_teacher_conflict','Teacher conflicts with '.$conflict);}$wpdb->query('START TRANSACTION');try{$canonical=EduFlow_ID_Service::generate('demo',$institute_id);$lecture_canonical=EduFlow_ID_Service::generate('class',$institute_id);if(is_wp_error($canonical)||is_wp_error($lecture_canonical)){throw new RuntimeException('Canonical ID allocation failed.');}$now=current_time('mysql',true);$lecture=array('canonical_id'=>$lecture_canonical,'schedule_key'=>'demo-slot:'.$key,'institute_id'=>$institute_id,'batch_id'=>null,'student_id'=>null,'teacher_id'=>$teacher_id,'class_type'=>'demo','class_date'=>$date,'start_datetime'=>$begin,'end_datetime'=>$finish,'timezone'=>$timezone,'meet_url'=>null,'external_event_id'=>null,'class_status'=>'scheduled','status'=>'active','created_at'=>$now,'updated_at'=>$now);if(false===$wpdb->insert(EduFlow_DB::table('classes'),$lecture)){throw new RuntimeException('Demo lecture could not be created.');}$lecture_id=(int)$wpdb->insert_id;$session=array('canonical_id'=>$canonical,'session_key'=>$key,'institute_id'=>$institute_id,'demo_date'=>$date,'start_time'=>$start,'end_time'=>$end,'teacher_id'=>$teacher_id,'capacity'=>$capacity,'lecture_id'=>$lecture_id,'session_status'=>'scheduled','status'=>'active','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now);if(false===$wpdb->insert($sessions,$session)){throw new RuntimeException('Demo session could not be created.');}$session['id']=(int)$wpdb->insert_id;$wpdb->query('COMMIT');EduFlow_Audit_Service::log('demo_session_created','demo_session',$canonical,null,$session,$institute_id);EduFlow_Notification_Service::create('demo_scheduled','teacher',$teacher_id,'Demo scheduled','A demo session has been assigned to you.','demo-session:'.$session['id'],'demo_session',$session['id'],array('portal','email_ready'),$institute_id);EduFlow_Google_Service::queue($lecture_id,'upsert',$institute_id);return $session;}catch(Throwable $e){$wpdb->query('ROLLBACK');$winner=$wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions WHERE session_key=%s AND institute_id=%d",$key,$institute_id),ARRAY_A);return $winner?:new WP_Error('demo_session_failed',$e->getMessage());}}
	public static function add_booking($session_input,$booking_input,$institute_id=0){global $wpdb;$institute_id=$institute_id?:EduFlow_Settings::institute_db_id();$session=self::ensure_session($session_input,$institute_id);if(is_wp_error($session)){return $session;}$mobile=EduFlow_Contact_Service::normalize_indian_mobile($booking_input['mobile']??'');if(is_wp_error($mobile)){return $mobile;}$email=sanitize_email($booking_input['email']??'');if(!empty($booking_input['email'])&&!is_email($email)){return new WP_Error('invalid_email','Enter a valid email or leave it empty.');}$name=sanitize_text_field($booking_input['prospect_name']??'');if(!$name){return new WP_Error('prospect_name_required','Prospect name is required.');}$table=EduFlow_DB::table('demo_bookings');$wpdb->query('START TRANSACTION');$session=self::get_session($session['id'],$institute_id,true);$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE institute_id=%d AND session_id=%d AND booking_status='booked'",$institute_id,$session['id']));if($count>=(int)$session['capacity']){$wpdb->query('ROLLBACK');return new WP_Error('demo_slot_full','Demo slot capacity has been reached.');}$key=hash('sha256',$institute_id.'|'.$session['id'].'|'.$mobile);$now=current_time('mysql',true);$data=array('institute_id'=>$institute_id,'session_id'=>$session['id'],'booking_key'=>$key,'prospect_name'=>$name,'mobile'=>$mobile,'email'=>$email?:null,'attendance_status'=>'pending','feedback'=>'','follow_up_status'=>'pending','conversion_status'=>'not_converted','booking_status'=>'booked','status'=>'active','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now);if(false===$wpdb->insert($table,$data)){$wpdb->query('ROLLBACK');return new WP_Error('duplicate_demo_booking','This mobile is already booked in the demo slot.');}$booking_id=(int)$wpdb->insert_id;self::touch_lecture($session['lecture_id'],$institute_id);$wpdb->query('COMMIT');EduFlow_Audit_Service::log('demo_booking_created','demo_booking',$booking_id,null,$data,$institute_id);EduFlow_Notification_Service::create('demo_scheduled','demo_booking',$booking_id,'Demo scheduled','Your demo session is scheduled.','demo-scheduled:'.$booking_id,'demo_session',$session['id'],array('portal','email_ready','whatsapp_ready'),$institute_id);EduFlow_Google_Service::queue($session['lecture_id'],'upsert',$institute_id);return $booking_id;}
	private static function touch_lecture($lecture_id,$institute_id){global $wpdb;$wpdb->update(EduFlow_DB::table('classes'),array('updated_at'=>current_time('mysql',true)),array('id'=>$lecture_id,'institute_id'=>$institute_id));}
	public static function move_booking($booking_id,$target_input,$institute_id=0){global $wpdb;$institute_id=$institute_id?:EduFlow_Settings::institute_db_id();$target=self::ensure_session($target_input,$institute_id);if(is_wp_error($target)){return $target;}$table=EduFlow_DB::table('demo_bookings');$wpdb->query('START TRANSACTION');$booking=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND institute_id=%d FOR UPDATE",$booking_id,$institute_id),ARRAY_A);if(!$booking){$wpdb->query('ROLLBACK');return new WP_Error('booking_not_found','Demo booking not found.');}if((int)$booking['session_id']===(int)$target['id']){$wpdb->query('COMMIT');return true;}$target=self::get_session($target['id'],$institute_id,true);$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE institute_id=%d AND session_id=%d AND booking_status='booked'",$institute_id,$target['id']));if($count>=(int)$target['capacity']){$wpdb->query('ROLLBACK');return new WP_Error('demo_slot_full','Target demo slot is full.');}$old=self::get_session($booking['session_id'],$institute_id);$key=hash('sha256',$institute_id.'|'.$target['id'].'|'.$booking['mobile']);$now=current_time('mysql',true);$result=$wpdb->update($table,array('session_id'=>$target['id'],'booking_key'=>$key,'previous_session_id'=>$booking['session_id'],'moved_at'=>$now,'updated_at'=>$now),array('id'=>$booking_id,'institute_id'=>$institute_id));if(false===$result){$wpdb->query('ROLLBACK');return new WP_Error('duplicate_demo_booking','Prospect is already booked in the target slot.');}self::touch_lecture($old['lecture_id'],$institute_id);self::touch_lecture($target['lecture_id'],$institute_id);$wpdb->query('COMMIT');EduFlow_Audit_Service::log('demo_booking_moved','demo_booking',$booking_id,$booking,array('session_id'=>$target['id']),$institute_id);EduFlow_Notification_Service::create('demo_rescheduled','demo_booking',$booking_id,'Demo moved','Your demo booking was moved to another slot.','demo-moved:'.$booking_id.':'.$target['id'],'demo_session',$target['id'],array('portal','email_ready','whatsapp_ready'),$institute_id);EduFlow_Google_Service::queue($old['lecture_id'],'upsert',$institute_id);EduFlow_Google_Service::queue($target['lecture_id'],'upsert',$institute_id);return true;}
	public static function update_booking($booking_id,$input,$institute_id=0){global $wpdb;$institute_id=$institute_id?:EduFlow_Settings::institute_db_id();$table=EduFlow_DB::table('demo_bookings');$old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND institute_id=%d",$booking_id,$institute_id),ARRAY_A);if(!$old){return new WP_Error('booking_not_found','Demo booking not found.');}$attendance=sanitize_key($input['attendance_status']??$old['attendance_status']);if(!in_array($attendance,self::ATTENDANCE_STATUSES,true)){return new WP_Error('invalid_attendance','Invalid attendance status.');}$data=array('attendance_status'=>$attendance,'feedback'=>sanitize_textarea_field($input['feedback']??$old['feedback']),'follow_up_status'=>sanitize_key($input['follow_up_status']??$old['follow_up_status']),'conversion_status'=>sanitize_key($input['conversion_status']??$old['conversion_status']),'updated_at'=>current_time('mysql',true));$wpdb->update($table,$data,array('id'=>$booking_id,'institute_id'=>$institute_id));EduFlow_Audit_Service::log('demo_booking_updated','demo_booking',$booking_id,$old,$data,$institute_id);return true;}
	public static function update_capacity($session_id,$capacity,$institute_id=0){global $wpdb;$institute_id=$institute_id?:EduFlow_Settings::institute_db_id();$session=self::get_session($session_id,$institute_id);if(!$session){return new WP_Error('session_not_found','Demo session not found.');}$capacity=max(1,absint($capacity));$bookings=EduFlow_DB::table('demo_bookings');$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $bookings WHERE institute_id=%d AND session_id=%d AND booking_status='booked'",$institute_id,$session_id));if($capacity<$count){return new WP_Error('capacity_below_bookings','Capacity cannot be below current bookings.');}$wpdb->update(EduFlow_DB::table('demo_sessions'),array('capacity'=>$capacity,'updated_at'=>current_time('mysql',true)),array('id'=>$session_id,'institute_id'=>$institute_id));EduFlow_Audit_Service::log('demo_session_updated','demo_session',$session['canonical_id'],$session,array('capacity'=>$capacity),$institute_id);return true;}
	public static function get_booking_meet_url( $booking_id, $institute_id = 0 ) {
		global $wpdb;
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
		$bookings = EduFlow_DB::table( 'demo_bookings' );
		$sessions = EduFlow_DB::table( 'demo_sessions' );
		$events = EduFlow_DB::table( 'google_events' );
		$url = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT g.google_meet_url FROM $bookings b INNER JOIN $sessions d ON d.id=b.session_id AND d.institute_id=b.institute_id INNER JOIN $events g ON g.lecture_id=d.lecture_id AND g.institute_id=d.institute_id WHERE b.id=%d AND b.institute_id=%d AND b.booking_status='booked' AND g.sync_status='synced'",
				$booking_id,
				$institute_id
			)
		);
		return $url ? esc_url_raw( $url ) : new WP_Error( 'demo_meet_not_ready', 'The demo Meet link is not ready.' );
	}

	private static function booking( $booking_id, $institute_id ) {
		global $wpdb;
		$table = EduFlow_DB::table( 'demo_bookings' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND institute_id=%d", $booking_id, $institute_id ), ARRAY_A );
	}

	public static function reschedule_session( $session_id, $input, $institute_id = 0 ) {
		global $wpdb;
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
		$old = self::get_session( $session_id, $institute_id, true );
		if ( ! $old ) { return new WP_Error( 'session_not_found', 'Demo session not found.' ); }
		$date = self::date( $input['demo_date'] ?? '' ); $start = self::time( $input['start_time'] ?? '' ); $end = self::time( $input['end_time'] ?? '' ); $teacher_id = absint( $input['teacher_id'] ?? 0 );
		if ( ! $date || ! $start || ! $end || $start >= $end ) { return new WP_Error( 'invalid_demo_slot', 'A valid target slot is required.' ); }
		$teacher = EduFlow_Teacher_Service::get( $teacher_id, $institute_id );
		if ( ! $teacher || 'active' !== $teacher['teacher_status'] ) { return new WP_Error( 'invalid_demo_teacher', 'Select an active institute teacher.' ); }
		$key = self::session_key( $institute_id, $date, $start, $end, $teacher_id );
		$table = EduFlow_DB::table( 'demo_sessions' );
		$duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE session_key=%s AND institute_id=%d AND id<>%d", $key, $institute_id, $session_id ) );
		if ( $duplicate ) { return new WP_Error( 'demo_slot_exists', 'The target slot already exists. Move bookings to that canonical session instead.' ); }
		$timezone = EduFlow_Settings::get_for_institute( $institute_id )['timezone'] ?? 'Asia/Kolkata';
		$begin = EduFlow_Class_Service::to_utc( $date, $start, $timezone ); $finish = EduFlow_Class_Service::to_utc( $date, $end, $timezone );
		$conflict = EduFlow_Class_Service::conflict( $institute_id, $teacher_id, null, $begin, $finish, $old['lecture_id'] );
		if ( $conflict ) { return new WP_Error( 'demo_teacher_conflict', 'Teacher conflicts with ' . $conflict ); }
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$locked = self::get_session( $session_id, $institute_id, true );
		if ( ! $locked ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'session_not_found', 'Demo session not found.' ); }
		$duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE session_key=%s AND institute_id=%d AND id<>%d FOR UPDATE", $key, $institute_id, $session_id ) );
		if ( $duplicate ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'demo_slot_exists', 'The target canonical slot already exists.' ); }
		$updated = $wpdb->update( $table, array( 'session_key'=>$key, 'demo_date'=>$date, 'start_time'=>$start, 'end_time'=>$end, 'teacher_id'=>$teacher_id, 'updated_at'=>$now ), array( 'id'=>$session_id, 'institute_id'=>$institute_id ) );
		if ( false === $updated ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'demo_reschedule_failed', 'Demo session could not be rescheduled.' ); }
		$class_updated = $wpdb->update( EduFlow_DB::table( 'classes' ), array( 'schedule_key'=>'demo-slot:'.$key, 'teacher_id'=>$teacher_id, 'class_date'=>$date, 'start_datetime'=>$begin, 'end_datetime'=>$finish, 'timezone'=>$timezone, 'class_status'=>'rescheduled', 'updated_at'=>$now ), array( 'id'=>$old['lecture_id'], 'institute_id'=>$institute_id ) );
		if ( false === $class_updated ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'demo_reschedule_failed', 'Linked lecture could not be rescheduled.' ); }
		$wpdb->query( 'COMMIT' );
		$action = (int)$old['teacher_id'] !== $teacher_id ? 'demo_mentor_changed' : 'demo_rescheduled';
		EduFlow_Audit_Service::log( $action, 'demo_session', $old['canonical_id'], $old, array( 'date'=>$date, 'start'=>$start, 'end'=>$end, 'teacher_id'=>$teacher_id ), $institute_id );
		self::notify_session_bookings( $session_id, 'demo_rescheduled', 'Demo rescheduled', 'Your demo session has been rescheduled.', $institute_id );
		EduFlow_Google_Service::queue( $old['lecture_id'], 'upsert', $institute_id );
		return true;
	}

	public static function cancel_booking( $booking_id, $institute_id = 0 ) {
		global $wpdb; $institute_id = $institute_id ?: EduFlow_Settings::institute_db_id(); $old = self::booking( $booking_id, $institute_id );
		if ( ! $old ) { return new WP_Error( 'booking_not_found', 'Demo booking not found.' ); }
		if ( 'cancelled' === $old['booking_status'] ) { return true; }
		$wpdb->update( EduFlow_DB::table( 'demo_bookings' ), array( 'booking_status'=>'cancelled', 'status'=>'history', 'updated_at'=>current_time('mysql',true) ), array( 'id'=>$booking_id, 'institute_id'=>$institute_id ) );
		$session = self::get_session( $old['session_id'], $institute_id ); self::touch_lecture( $session['lecture_id'], $institute_id ); EduFlow_Google_Service::queue( $session['lecture_id'], 'upsert', $institute_id );
		EduFlow_Audit_Service::log( 'demo_booking_cancelled', 'demo_booking', $booking_id, $old, array( 'booking_status'=>'cancelled' ), $institute_id );
		EduFlow_Notification_Service::create( 'demo_cancelled', 'demo_booking', $booking_id, 'Demo cancelled', 'Your demo booking was cancelled.', 'demo-cancelled:'.$booking_id, 'demo_session', $old['session_id'], array('portal','email_ready','whatsapp_ready'), $institute_id );
		return true;
	}

	public static function convert_to_admission( $booking_id, $input, $institute_id = 0 ) {
		global $wpdb; $institute_id = $institute_id ?: EduFlow_Settings::institute_db_id(); $booking = self::booking( $booking_id, $institute_id );
		if ( ! $booking ) { return new WP_Error( 'booking_not_found', 'Demo booking not found.' ); }
		if ( $booking['admission_id'] ) { return (int)$booking['admission_id']; }
		$admission = EduFlow_Admission_Service::create( array( 'student_name'=>$booking['prospect_name'], 'mobile'=>$booking['mobile'], 'email'=>$booking['email'], 'course'=>$input['course']??'', 'level'=>$input['level']??'', 'class_type'=>$input['class_type']??'demo', 'preferred_timing'=>$input['preferred_timing']??'', 'admission_date'=>current_time('Y-m-d'), 'total_fee'=>$input['total_fee']??0, 'amount_paid'=>0, 'notes'=>'Converted from demo booking #'.$booking_id ), $institute_id );
		if ( is_wp_error( $admission ) ) { return $admission; }
		$wpdb->update( EduFlow_DB::table( 'demo_bookings' ), array( 'admission_id'=>$admission, 'conversion_status'=>'converted', 'updated_at'=>current_time('mysql',true) ), array( 'id'=>$booking_id, 'institute_id'=>$institute_id ) );
		EduFlow_Audit_Service::log( 'demo_converted', 'demo_booking', $booking_id, $booking, array( 'admission_id'=>$admission ), $institute_id );
		return $admission;
	}

	private static function notify_session_bookings( $session_id, $type, $title, $message, $institute_id ) {
		global $wpdb; $table = EduFlow_DB::table( 'demo_bookings' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE institute_id=%d AND session_id=%d AND booking_status='booked'", $institute_id, $session_id ) );
		foreach ( $ids as $id ) { EduFlow_Notification_Service::create( $type, 'demo_booking', $id, $title, $message, $type.':'.$session_id.':'.$id.':'.current_time('mysql',true), 'demo_session', $session_id, array('portal','email_ready','whatsapp_ready'), $institute_id ); }
	}

	public static function prospect_token( $booking_id, $institute_id = 0 ) {
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id(); $booking = self::booking( $booking_id, $institute_id );
		return $booking ? hash_hmac( 'sha256', $booking['booking_key'].'|'.$booking_id.'|'.$institute_id, wp_salt('nonce') ) : '';
	}

	public static function prospect_meet_url( $booking_id, $token, $institute_id = 0 ) {
		if ( ! $institute_id ) { global $wpdb; $table=EduFlow_DB::table('demo_bookings'); $institute_id=(int)$wpdb->get_var($wpdb->prepare("SELECT institute_id FROM $table WHERE id=%d AND booking_status='booked'",$booking_id)); }
		$expected = self::prospect_token( $booking_id, $institute_id );
		if ( ! $expected || ! hash_equals( $expected, (string)$token ) ) { return new WP_Error( 'invalid_demo_access', 'Demo access token is invalid.', array('status'=>403) ); }
		return self::get_booking_meet_url( $booking_id, $institute_id );
	}

}
