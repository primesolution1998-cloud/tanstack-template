<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Student_Service {
        public static function convert_admission( $admission_id, $institute_id = 0 ) {
                global $wpdb;

                // Ensure current live schema before conversion.
                EduFlow_DB::install();

                $institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
                $admissions   = EduFlow_DB::table( 'admissions' );
                $students     = EduFlow_DB::table( 'students' );

                $wpdb->query( 'START TRANSACTION' );

                try {
                        $admission = EduFlow_Admission_Service::get(
                                $admission_id,
                                $institute_id,
                                true
                        );

                        if ( ! $admission ) {
                                throw new RuntimeException( 'Admission not found.' );
                        }

                        // Idempotent recovery: if Student already exists,
                        // reconnect Admission and return existing Student.
                        $existing = $wpdb->get_var(
                                $wpdb->prepare(
                                        "SELECT id
                                         FROM $students
                                         WHERE institute_id=%d
                                         AND admission_id=%d
                                         LIMIT 1",
                                        $institute_id,
                                        $admission_id
                                )
                        );

                        if ( $existing ) {
                                $wpdb->update(
                                        $admissions,
                                        array(
                                                'student_id'       => (int) $existing,
                                                'admission_status' => 'active',
                                                'updated_at'       => current_time( 'mysql', true ),
                                        ),
                                        array(
                                                'id'           => $admission_id,
                                                'institute_id' => $institute_id,
                                        )
                                );

                                $wpdb->query( 'COMMIT' );
                                return (int) $existing;
                        }

                        if ( ! in_array(
                                $admission['admission_status'],
                                array( 'approved', 'active' ),
                                true
                        ) ) {
                                throw new RuntimeException(
                                        'Approve the admission before student conversion.'
                                );
                        }

                        $canonical = EduFlow_ID_Service::generate(
                                'student',
                                $institute_id
                        );

                        if ( is_wp_error( $canonical ) ) {
                                throw new RuntimeException(
                                        $canonical->get_error_message()
                                );
                        }

                        $now = current_time( 'mysql', true );

                        $data = array(
                                'canonical_id'   => $canonical,
                                'institute_id'   => $institute_id,
                                'wp_user_id'     => null,
                                'name'           => $admission['student_name'],
                                'mobile'         => $admission['mobile'],
                                'email'          => $admission['email'] ?: null,
                                'course'         => $admission['course'],
                                'level'          => $admission['level'],
                                'class_type'     => $admission['class_type'],
                                'batch_id'       => null,
                                'teacher_id'     => null,
                                'admission_id'   => $admission_id,
                                'admission_date' => $admission['admission_date'],
                                'access_start'   => $admission['admission_date'],
                                'access_end'     => null,
                                'fee_status'     => $admission['payment_status'],
                                'account_status' => 'not_linked',
                                'student_status' => 'approved',
                                'status'         => 'active',
                                'created_at'     => $now,
                                'updated_at'     => $now,
                        );

                        // Live-schema compatibility.
                        $live_columns = $wpdb->get_col(
                                "SHOW COLUMNS FROM $students",
                                0
                        );

                        if ( ! $live_columns ) {
                                throw new RuntimeException(
                                        'Students table schema could not be read.'
                                );
                        }

                        $allowed = array_flip( $live_columns );
                        $data = array_intersect_key( $data, $allowed );

                        $inserted = $wpdb->insert( $students, $data );

                        if ( false === $inserted ) {
                                throw new RuntimeException(
                                        'Student insert failed. DB: ' .
                                        $wpdb->last_error .
                                        ' | Query: ' .
                                        $wpdb->last_query
                                );
                        }

                        $student_id = (int) $wpdb->insert_id;

                        if ( $student_id <= 0 ) {
                                throw new RuntimeException(
                                        'Student insert returned no Student ID.'
                                );
                        }

                        $updated = $wpdb->update(
                                $admissions,
                                array(
                                        'student_id'       => $student_id,
                                        'admission_status' => 'active',
                                        'updated_at'       => $now,
                                ),
                                array(
                                        'id'           => $admission_id,
                                        'institute_id' => $institute_id,
                                )
                        );

                        if ( false === $updated ) {
                                throw new RuntimeException(
                                        'Admission link update failed. DB: ' .
                                        $wpdb->last_error
                                );
                        }

                        $wpdb->query( 'COMMIT' );

                        EduFlow_Audit_Service::log(
                                'student_created',
                                'student',
                                $canonical,
                                null,
                                array(
                                        'student_id'   => $student_id,
                                        'admission_id' => $admission_id,
                                ),
                                $institute_id
                        );

                        EduFlow_Notification_Service::create(
                                'admission_approved',
                                'student',
                                $student_id,
                                'Admission approved',
                                'Your admission has been approved.',
                                'admission-approved:' . $admission_id,
                                'admission',
                                $admission_id,
                                array(
                                        'portal',
                                        'email_ready',
                                        'whatsapp_ready'
                                ),
                                $institute_id
                        );

                        return $student_id;

                } catch ( Throwable $e ) {
                        $wpdb->query( 'ROLLBACK' );

                        return new WP_Error(
                                'student_conversion_failed',
                                $e->getMessage()
                        );
                }
        }

	public static function get( $id, $institute_id=0, $lock=false ) { global $wpdb;$table=EduFlow_DB::table('students');$institute_id=$institute_id?:EduFlow_Settings::institute_db_id();return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND institute_id=%d".($lock?' FOR UPDATE':''),$id,$institute_id),ARRAY_A); }
        public static function update( $id, $input, $institute_id = 0 ) {
                global $wpdb;

                $institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
                $old = self::get( $id, $institute_id );

                if ( ! $old ) {
                        return new WP_Error(
                                'not_found',
                                'Student not found.'
                        );
                }

                $name = sanitize_text_field(
                        $input['name'] ?? $old['name']
                );

                $course = sanitize_text_field(
                        $input['course'] ?? $old['course']
                );

                if ( '' === $name ) {
                        return new WP_Error(
                                'student_name_required',
                                'Student name is required.'
                        );
                }

                if ( '' === $course ) {
                        return new WP_Error(
                                'student_course_required',
                                'Course is required.'
                        );
                }

                $mobile = EduFlow_Contact_Service::normalize_indian_mobile(
                        $input['mobile'] ?? $old['mobile']
                );

                if ( is_wp_error( $mobile ) ) {
                        return $mobile;
                }

                $email = sanitize_email(
                        $input['email'] ?? $old['email']
                );

                if (
                        ! empty( $input['email'] ) &&
                        ! is_email( $email )
                ) {
                        return new WP_Error(
                                'invalid_email',
                                'Enter a valid email or leave it empty.'
                        );
                }

                $class_type = sanitize_key(
                        $input['class_type'] ?? $old['class_type']
                );

                if (
                        ! in_array(
                                $class_type,
                                EduFlow_Admission_Service::CLASS_TYPES,
                                true
                        )
                ) {
                        return new WP_Error(
                                'invalid_class_type',
                                'Invalid class type.'
                        );
                }

                $allowed_student_statuses = array(
                        'approved',
                        'active',
                        'hold',
                        'dropped',
                        'completed',
                        'cancelled'
                );

                $student_status = sanitize_key(
                        $input['student_status'] ?? $old['student_status']
                );

                if (
                        ! in_array(
                                $student_status,
                                $allowed_student_statuses,
                                true
                        )
                ) {
                        return new WP_Error(
                                'invalid_student_status',
                                'Invalid student status.'
                        );
                }

                $allowed_fee_statuses = array(
                        'paid',
                        'partial',
                        'pending',
                        'due'
                );

                $fee_status = sanitize_key(
                        $input['fee_status'] ?? $old['fee_status']
                );

                if (
                        ! in_array(
                                $fee_status,
                                $allowed_fee_statuses,
                                true
                        )
                ) {
                        return new WP_Error(
                                'invalid_fee_status',
                                'Invalid fee status.'
                        );
                }

                /*
                 * Duplicate protection.
                 * Historical dropped/cancelled/completed records do not block
                 * a valid current Student record.
                 */
                $students_table = EduFlow_DB::table( 'students' );

                $duplicate = $wpdb->get_var(
                        $wpdb->prepare(
                                "SELECT canonical_id
                                 FROM $students_table
                                 WHERE institute_id=%d
                                 AND id<>%d
                                 AND status='active'
                                 AND student_status NOT IN ('dropped','cancelled','completed')
                                 AND (
                                        mobile=%s
                                        OR (
                                                %s<>''
                                                AND email=%s
                                        )
                                 )
                                 LIMIT 1",
                                $institute_id,
                                $id,
                                $mobile,
                                (string) $email,
                                (string) $email
                        )
                );

                if ( $duplicate ) {
                        return new WP_Error(
                                'duplicate_student',
                                'Another active student already uses this mobile/email: ' .
                                $duplicate
                        );
                }

                $requested_batch_id = absint(
                        $input['batch_id'] ?? $old['batch_id']
                );

                $requested_teacher_id = absint(
                        $input['teacher_id'] ?? $old['teacher_id']
                );

                /*
                 * Dropped / Cancelled / Completed students must not remain
                 * attached to an active batch.
                 */
                $inactive_lifecycle = in_array(
                        $student_status,
                        array(
                                'dropped',
                                'cancelled',
                                'completed'
                        ),
                        true
                );

                if ( $inactive_lifecycle ) {
                        $requested_batch_id = 0;
                        $requested_teacher_id = 0;
                }

                $target_batch = null;

                if ( $requested_batch_id ) {
                        $target_batch = EduFlow_Batch_Service::get(
                                $requested_batch_id,
                                $institute_id
                        );

                        if ( ! $target_batch ) {
                                return new WP_Error(
                                        'invalid_batch',
                                        'Selected batch was not found.'
                                );
                        }

                        if (
                                'closed' ===
                                sanitize_key(
                                        $target_batch['batch_status']
                                )
                        ) {
                                return new WP_Error(
                                        'closed_batch',
                                        'A student cannot be assigned to a closed batch.'
                                );
                        }

                        /*
                         * Batch teacher is authoritative.
                         */
                        if ( ! empty( $target_batch['teacher_id'] ) ) {
                                $requested_teacher_id =
                                        (int) $target_batch['teacher_id'];
                        }
                }

                if ( $requested_teacher_id ) {
                        $teacher = EduFlow_Teacher_Service::get(
                                $requested_teacher_id,
                                $institute_id
                        );

                        if ( ! $teacher ) {
                                return new WP_Error(
                                        'invalid_teacher',
                                        'Selected teacher was not found.'
                                );
                        }

                        if (
                                'active' !==
                                sanitize_key(
                                        $teacher['teacher_status']
                                )
                        ) {
                                return new WP_Error(
                                        'teacher_not_active',
                                        'Selected teacher is not Active.'
                                );
                        }
                }

                /*
                 * Batch lifecycle changes go through Batch Service so
                 * assignment history remains intact.
                 */
                $old_batch_id = (int) ( $old['batch_id'] ?? 0 );

                if ( $old_batch_id !== $requested_batch_id ) {

                        if ( $old_batch_id && $requested_batch_id ) {

                                $assignment = EduFlow_Batch_Service::transfer_student(
                                        $old_batch_id,
                                        $requested_batch_id,
                                        $id,
                                        $institute_id
                                );

                        } elseif ( ! $old_batch_id && $requested_batch_id ) {

                                $assignment = EduFlow_Batch_Service::assign_student(
                                        $requested_batch_id,
                                        $id,
                                        $institute_id
                                );

                        } elseif ( $old_batch_id && ! $requested_batch_id ) {

                                $assignment = EduFlow_Batch_Service::remove_student(
                                        $old_batch_id,
                                        $id,
                                        $institute_id
                                );

                        } else {
                                $assignment = true;
                        }

                        if ( is_wp_error( $assignment ) ) {
                                return $assignment;
                        }
                }

                $data = array(
                        'name'           => $name,
                        'mobile'         => $mobile,
                        'email'          => $email ?: null,
                        'course'         => $course,
                        'level'          => sanitize_text_field(
                                $input['level'] ?? $old['level']
                        ),
                        'class_type'     => $class_type,
                        'batch_id'       => $requested_batch_id ?: null,
                        'teacher_id'     => $requested_teacher_id ?: null,
                        'student_status' => $student_status,
                        'fee_status'     => $fee_status,
                        'status'         => $inactive_lifecycle
                                ? 'inactive'
                                : 'active',
                        'updated_at'     => current_time( 'mysql', true ),
                );

                $result = $wpdb->update(
                        $students_table,
                        $data,
                        array(
                                'id'           => $id,
                                'institute_id' => $institute_id
                        )
                );

                if ( false === $result ) {
                        return new WP_Error(
                                'student_update_failed',
                                'Student could not be updated. DB: ' .
                                $wpdb->last_error
                        );
                }

                EduFlow_Audit_Service::log(
                        'student_updated',
                        'student',
                        $old['canonical_id'],
                        $old,
                        $data,
                        $institute_id
                );

                return true;
        }

	public static function query( $args=array(), $institute_id=0 ) {
		global $wpdb;$table=EduFlow_DB::table('students');$institute_id=$institute_id?:EduFlow_Settings::institute_db_id();$page=max(1,absint($args['paged']??1));$per=min(100,max(1,absint($args['per_page']??20)));$where=array('institute_id=%d');$params=array($institute_id);
		$search=sanitize_text_field($args['search']??'');if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where[]='(name LIKE %s OR canonical_id LIKE %s OR mobile LIKE %s OR email LIKE %s)';array_push($params,$like,$like,$like,$like);}
		foreach(array('course','level','class_type','fee_status') as $field){
                        if(!empty($args[$field])){
                                $where[]="$field=%s";
                                $params[]=sanitize_text_field($args[$field]);
                        }
                }

                $student_status_filter = sanitize_key($args['student_status'] ?? 'current');

                if ('all' === $student_status_filter) {
                        // Show complete Student history.
                } elseif ('current' === $student_status_filter || '' === $student_status_filter) {
                        $where[]="student_status NOT IN ('dropped','cancelled','completed')";
                } else {
                        $where[]="student_status=%s";
                        $params[]=$student_status_filter;
                }
		$allowed=array('canonical_id','name','course','level','class_type','student_status','fee_status','access_end','created_at');$order=in_array($args['orderby']??'',$allowed,true)?$args['orderby']:'created_at';$direction='asc'===strtolower($args['order']??'')?'ASC':'DESC';$base=' FROM '.$table.' WHERE '.implode(' AND ',$where);$total=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*)'.$base,...$params));$params[]= $per;$params[]=($page-1)*$per;$items=$wpdb->get_results($wpdb->prepare("SELECT *$base ORDER BY $order $direction LIMIT %d OFFSET %d",...$params),ARRAY_A);return array('items'=>$items,'total'=>$total,'pages'=>(int)ceil($total/$per),'page'=>$page);
	}
}
