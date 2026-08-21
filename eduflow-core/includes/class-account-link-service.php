<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Account_Link_Service {
	public static function register() {
		add_filter( 'authenticate', array( __CLASS__, 'authenticate_identifier' ), 15, 3 );
	}

	private static function hash_identifier( $value ) {
		return hash_hmac( 'sha256', strtolower( trim( (string) $value ) ), wp_salt( 'auth' ) );
	}

	private static function entity( $type, $id, $institute_id ) {
		if ( 'student' === $type ) {
			return EduFlow_Student_Service::get( $id, $institute_id );
		}
		if ( 'teacher' === $type ) {
			return EduFlow_Teacher_Service::get( $id, $institute_id );
		}
		return null;
	}

	public static function link( $type, $entity_id, $wp_user_id, $institute_id = 0 ) {
		global $wpdb;
		$type = sanitize_key( $type );
		if ( ! in_array( $type, array( 'student', 'teacher' ), true ) ) {
			return new WP_Error( 'invalid_link_type', 'Invalid account link type.' );
		}
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
		$entity = self::entity( $type, $entity_id, $institute_id );
		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $entity || ! $user ) {
			return new WP_Error( 'link_record_missing', 'The EduFlow record or WordPress user was not found.' );
		}
		$mobile = EduFlow_Contact_Service::normalize_indian_mobile( $entity['mobile'] ?? '' );
		if ( is_wp_error( $mobile ) ) {
			return $mobile;
		}
		$email = sanitize_email( $entity['email'] ?? '' );
		$table = EduFlow_DB::table( 'user_links' );
		$now = current_time( 'mysql', true );
		$data = array(
			'institute_id'  => $institute_id,
			'entity_type'   => $type,
			'entity_id'     => $entity_id,
			'wp_user_id'    => $wp_user_id,
			'canonical_hash'=> self::hash_identifier( $entity['canonical_id'] ),
			'email_hash'    => $email ? self::hash_identifier( $email ) : null,
			'mobile_hash'   => self::hash_identifier( $mobile ),
			'link_status'   => 'active',
			'linked_by'     => get_current_user_id(),
			'linked_at'     => $now,
			'status'        => 'active',
			'created_at'    => $now,
			'updated_at'    => $now,
		);
		$wpdb->query( 'START TRANSACTION' );
		$duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE (institute_id=%d AND entity_type=%s AND entity_id=%d) OR (wp_user_id=%d AND entity_type=%s) FOR UPDATE", $institute_id, $type, $entity_id, $wp_user_id, $type ) );
		if ( $duplicate ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'duplicate_account_link', 'This record or WordPress user is already linked.' );
		}
		if ( false === $wpdb->insert( $table, $data ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'account_link_failed', 'The account could not be linked.' );
		}
		$entity_table = EduFlow_DB::table( 'student' === $type ? 'students' : 'teachers' );
		$wpdb->update( $entity_table, array( 'wp_user_id'=>$wp_user_id, 'updated_at'=>$now ), array( 'id'=>$entity_id, 'institute_id'=>$institute_id ) );
		$wpdb->update( EduFlow_DB::table( 'notifications' ), array( 'wp_user_id'=>$wp_user_id, 'updated_at'=>$now ), array( 'institute_id'=>$institute_id, 'recipient_type'=>$type, 'recipient_id'=>$entity_id ) );
		$wpdb->query( 'COMMIT' );
		if ( ! user_can( $user, 'manage_options' ) ) {
			$role='student' === $type ? 'eduflow_student' : 'eduflow_teacher'; $user->add_role( $role ); EduFlow_Audit_Service::log( 'role_capability_changed', 'user', $wp_user_id, null, array( 'role'=>$role ), $institute_id );
		}
		EduFlow_Audit_Service::log( 'account_linked', $type, $entity['canonical_id'], null, array( 'wp_user_id'=>$wp_user_id ), $institute_id );
		return true;
	}

	public static function create_student_invitation( $student_id, $institute_id = 0 ) {
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
		$student = EduFlow_Student_Service::get( $student_id, $institute_id );
		if ( ! $student ) {
			return new WP_Error( 'student_not_found', 'Student not found.' );
		}
		$email = sanitize_email( $student['email'] ?? '' );
		if ( ! $email || ! is_email( $email ) ) {
			return new WP_Error( 'email_required_for_invitation', 'A valid email is required for a WordPress activation invitation.' );
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			$login = sanitize_user( strtolower( $student['canonical_id'] ), true );
			$user_id = wp_insert_user( array( 'user_login'=>$login, 'user_email'=>$email, 'display_name'=>$student['name'], 'user_pass'=>wp_generate_password( 32, true, true ), 'role'=>'eduflow_student' ) );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			$user = get_user_by( 'id', $user_id );
		}
		$result = self::link( 'student', $student_id, $user->ID, $institute_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		retrieve_password( $user->user_login );
		return true;
	}

	public static function linked_entity( $type, $user_id = 0, $institute_id = 0 ) {
		global $wpdb;
		$type = sanitize_key( $type );
		$user_id = $user_id ?: get_current_user_id();
		$institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
		$table = EduFlow_DB::table( 'user_links' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE institute_id=%d AND entity_type=%s AND wp_user_id=%d AND link_status='active'", $institute_id, $type, $user_id ), ARRAY_A );
	}

	public static function linked_entity_any_institute( $type, $user_id = 0 ) {
		global $wpdb;
		$type = sanitize_key( $type );
		$user_id = $user_id ?: get_current_user_id();
		$table = EduFlow_DB::table( 'user_links' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE entity_type=%s AND wp_user_id=%d AND link_status='active'", $type, $user_id ), ARRAY_A );
	}

	public static function authenticate_identifier( $user, $username, $password ) {
		if ( $user instanceof WP_User || '' === (string) $username || '' === (string) $password || is_email( $username ) ) {
			return $user;
		}
		global $wpdb;
		$normalized = EduFlow_Contact_Service::normalize_indian_mobile( $username );
		$value = is_wp_error( $normalized ) ? sanitize_text_field( $username ) : $normalized;
		$hash = self::hash_identifier( $value );
		$table = EduFlow_DB::table( 'user_links' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT wp_user_id FROM $table WHERE link_status='active' AND (canonical_hash=%s OR mobile_hash=%s) LIMIT 2", $hash, $hash ) );
		if ( 1 !== count( $ids ) ) {
			return $user;
		}
		$linked = get_user_by( 'id', $ids[0] );
		return $linked ? wp_authenticate_username_password( null, $linked->user_login, $password ) : $user;
	}
}
