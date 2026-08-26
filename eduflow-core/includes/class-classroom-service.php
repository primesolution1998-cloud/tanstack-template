<?php
defined( 'ABSPATH' ) || exit;

/**
 * EduFlow Live Classroom (MVP)
 *
 * Provides a batch-scoped virtual classroom inside the EduFlow portal.
 * The video provider is Jitsi-compatible and can be self-hosted later by
 * defining EDUFLOW_CLASSROOM_DOMAIN in wp-config.php or using the filter.
 */
final class EduFlow_Classroom_Service {
	public static function domain() {
		$domain = defined( 'EDUFLOW_CLASSROOM_DOMAIN' ) ? EDUFLOW_CLASSROOM_DOMAIN : 'meet.jit.si';
		$domain = preg_replace( '#^https?://#i', '', trim( (string) $domain ) );
		$domain = trim( $domain, '/' );
		return apply_filters( 'eduflow_classroom_domain', $domain );
	}

	public static function room_name( $batch, $institute_id ) {
		$canonical = ! empty( $batch['canonical_id'] ) ? $batch['canonical_id'] : 'BATCH-' . (int) $batch['id'];
		$seed = $institute_id . '|' . $canonical . '|' . wp_salt( 'auth' );
		return 'YTC-' . preg_replace( '/[^A-Za-z0-9]/', '', $canonical ) . '-' . substr( hash( 'sha256', $seed ), 0, 12 );
	}

	public static function access( $batch_id, $user_id = 0, $institute_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'classroom_login_required', 'Please sign in to join the live classroom.', array( 'status' => 401 ) );
		}

		$batch_id = absint( $batch_id );
		$student_link = EduFlow_Account_Link_Service::linked_entity_any_institute( 'student', $user_id );
		$teacher_link = EduFlow_Account_Link_Service::linked_entity_any_institute( 'teacher', $user_id );
		if ( ! $institute_id ) {
			if ( $student_link ) {
				$institute_id = (int) $student_link['institute_id'];
			} elseif ( $teacher_link ) {
				$institute_id = (int) $teacher_link['institute_id'];
			} else {
				$institute_id = EduFlow_Settings::institute_db_id();
			}
		}

		$batch = EduFlow_Batch_Service::get( $batch_id, $institute_id );
		if ( ! $batch || 'closed' === $batch['batch_status'] ) {
			return new WP_Error( 'classroom_batch_unavailable', 'This batch classroom is unavailable.', array( 'status' => 404 ) );
		}

		if ( user_can( $user_id, 'eduflow_manage_institute' ) || user_can( $user_id, 'eduflow_manage_classes' ) ) {
			return self::payload( $batch, $institute_id, $user_id, 'admin', true );
		}

		if ( $student_link && (int) $student_link['institute_id'] === (int) $institute_id ) {
			$assignments = EduFlow_DB::table( 'batch_students' );
			$active = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $assignments WHERE institute_id=%d AND batch_id=%d AND student_id=%d AND assignment_status='active' LIMIT 1",
				$institute_id,
				$batch_id,
				(int) $student_link['entity_id']
			) );
			if ( $active ) {
				return self::payload( $batch, $institute_id, $user_id, 'student', false );
			}
		}

		if ( $teacher_link && (int) $teacher_link['institute_id'] === (int) $institute_id && (int) $batch['teacher_id'] === (int) $teacher_link['entity_id'] ) {
			return self::payload( $batch, $institute_id, $user_id, 'teacher', true );
		}

		return new WP_Error( 'classroom_forbidden', 'You are not assigned to this batch.', array( 'status' => 403 ) );
	}

	private static function payload( $batch, $institute_id, $user_id, $role, $moderator ) {
		$user = get_userdata( $user_id );
		return array(
			'batch_id'      => (int) $batch['id'],
			'institute_id'  => (int) $institute_id,
			'batch_name'    => $batch['batch_name'],
			'room_name'     => self::room_name( $batch, $institute_id ),
			'domain'        => self::domain(),
			'role'          => $role,
			'is_moderator'  => (bool) $moderator,
			'display_name'  => $user ? $user->display_name : 'EduFlow User',
			'email'         => $user ? $user->user_email : '',
		);
	}

	public static function join_url( $batch_id ) {
		return add_query_arg(
			array(
				'eduflow_portal' => 'classroom',
				'batch_id'       => absint( $batch_id ),
			),
			home_url( '/' )
		);
	}
}
