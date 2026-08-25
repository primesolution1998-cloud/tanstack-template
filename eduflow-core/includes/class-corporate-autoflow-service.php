<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Corporate_Autoflow_Service {

    private static function institute( $institute_id = 0 ) {
        return $institute_id ?: EduFlow_Settings::institute_db_id();
    }

    private static function active_student( $student ) {
        if ( ! $student ) {
            return false;
        }

        if ( 'active' !== (string) ( $student['status'] ?? '' ) ) {
            return false;
        }

        return ! in_array(
            sanitize_key( $student['student_status'] ?? '' ),
            array( 'dropped', 'cancelled', 'completed' ),
            true
        );
    }

    public static function sync_student_from_admission( $student_id, $admission_id, $institute_id = 0 ) {
        global $wpdb;

        $institute_id = self::institute( $institute_id );

        $student = EduFlow_Student_Service::get( $student_id, $institute_id );
        $admission = EduFlow_Admission_Service::get( $admission_id, $institute_id );

        if ( ! $student || ! $admission ) {
            return new WP_Error(
                'autoflow_source_missing',
                'Student or admission record not found.'
            );
        }

        if ( ! self::active_student( $student ) ) {
            return true;
        }

        $batches = EduFlow_DB::table( 'batches' );

        if ( empty( $student['batch_id'] ) ) {

            $preferred = strtolower(
                trim(
                    (string) ( $admission['preferred_timing'] ?? '' )
                )
            );

            $candidates = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT *
                     FROM $batches
                     WHERE institute_id=%d
                     AND batch_status='active'
                     AND status='active'
                     AND course=%s
                     ORDER BY start_time ASC,id ASC",
                    $institute_id,
                    $student['course']
                ),
                ARRAY_A
            );

            $selected = null;

            foreach ( $candidates as $batch ) {

                if (
                    ! empty( $student['class_type'] ) &&
                    ! empty( $batch['class_type'] ) &&
                    $student['class_type'] !== $batch['class_type']
                ) {
                    continue;
                }

                if (
                    ! empty( $student['level'] ) &&
                    ! empty( $batch['level'] ) &&
                    0 !== strcasecmp( $student['level'], $batch['level'] )
                ) {
                    continue;
                }

                if ( $preferred ) {
                    $haystack = strtolower(
                        $batch['batch_name'] . ' ' .
                        $batch['start_time'] . ' ' .
                        $batch['end_time']
                    );

                    $normalized_preferred = preg_replace(
                        '/[^0-9a-z:]+/',
                        '',
                        $preferred
                    );

                    $normalized_haystack = preg_replace(
                        '/[^0-9a-z:]+/',
                        '',
                        $haystack
                    );

                    if (
                        $normalized_preferred &&
                        false === strpos( $normalized_haystack, $normalized_preferred )
                    ) {
                        continue;
                    }
                }

                $selected = $batch;
                break;
            }

            if ( $selected ) {
                $assigned = EduFlow_Batch_Service::assign_student(
                    (int) $selected['id'],
                    $student_id,
                    $institute_id
                );

                if ( is_wp_error( $assigned ) ) {
                    return $assigned;
                }

                $student['batch_id'] = (int) $selected['id'];
            }
        }

        if ( ! empty( $student['batch_id'] ) ) {

            $batch = EduFlow_Batch_Service::get(
                (int) $student['batch_id'],
                $institute_id
            );

            if ( $batch && ! empty( $batch['teacher_id'] ) ) {

                $wpdb->update(
                    EduFlow_DB::table( 'students' ),
                    array(
                        'teacher_id' => (int) $batch['teacher_id'],
                        'updated_at' => current_time( 'mysql', true ),
                    ),
                    array(
                        'id' => $student_id,
                        'institute_id' => $institute_id,
                    )
                );
            }
        }

        EduFlow_Audit_Service::log(
            'corporate_student_autoflow_synced',
            'student',
            $student['canonical_id'],
            null,
            array(
                'admission_id' => $admission_id,
                'batch_id' => (int) ( $student['batch_id'] ?? 0 ),
            ),
            $institute_id
        );

        return true;
    }

    public static function sync_batch_teacher_to_students( $batch_id, $institute_id = 0 ) {
        global $wpdb;

        $institute_id = self::institute( $institute_id );

        $batch = EduFlow_Batch_Service::get( $batch_id, $institute_id );

        if ( ! $batch ) {
            return new WP_Error( 'batch_not_found', 'Batch not found.' );
        }

        if ( empty( $batch['teacher_id'] ) ) {
            return true;
        }

        $assignments = EduFlow_DB::table( 'batch_students' );
        $students = EduFlow_DB::table( 'students' );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT student_id
                 FROM $assignments
                 WHERE institute_id=%d
                 AND batch_id=%d
                 AND assignment_status='active'",
                $institute_id,
                $batch_id
            )
        );

        foreach ( $ids as $student_id ) {
            $wpdb->update(
                $students,
                array(
                    'teacher_id' => (int) $batch['teacher_id'],
                    'updated_at' => current_time( 'mysql', true ),
                ),
                array(
                    'id' => (int) $student_id,
                    'institute_id' => $institute_id,
                )
            );
        }

        EduFlow_Audit_Service::log(
            'batch_teacher_students_synced',
            'batch',
            $batch['canonical_id'],
            null,
            array(
                'teacher_id' => (int) $batch['teacher_id'],
                'students_synced' => count( $ids ),
            ),
            $institute_id
        );

        return true;
    }

    public static function generate_batch_lectures_if_ready( $batch_id, $institute_id = 0 ) {
        $institute_id = self::institute( $institute_id );

        $batch = EduFlow_Batch_Service::get( $batch_id, $institute_id );

        if ( ! $batch ) {
            return new WP_Error( 'batch_not_found', 'Batch not found.' );
        }

        if (
            'active' !== $batch['batch_status'] ||
            empty( $batch['teacher_id'] )
        ) {
            return true;
        }

        return EduFlow_Class_Service::generate_batch(
            $batch_id,
            current_time( 'Y-m-d' ),
            14,
            $institute_id
        );
    }

    public static function recover_unassigned_students( $institute_id = 0 ) {
        global $wpdb;

        $institute_id = self::institute( $institute_id );

        $students = EduFlow_DB::table( 'students' );
        $admissions = EduFlow_DB::table( 'admissions' );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id student_id,s.admission_id
                 FROM $students s
                 INNER JOIN $admissions a
                    ON a.id=s.admission_id
                    AND a.institute_id=s.institute_id
                 WHERE s.institute_id=%d
                 AND s.status='active'
                 AND s.student_status NOT IN ('dropped','cancelled','completed')
                 AND (s.batch_id IS NULL OR s.batch_id=0)",
                $institute_id
            ),
            ARRAY_A
        );

        $synced = 0;
        $errors = array();

        foreach ( $rows as $row ) {
            $result = self::sync_student_from_admission(
                (int) $row['student_id'],
                (int) $row['admission_id'],
                $institute_id
            );

            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
                continue;
            }

            $synced++;
        }

        return array(
            'synced' => $synced,
            'errors' => $errors,
        );
    }

    public static function operational_exceptions( $institute_id = 0 ) {
        global $wpdb;

        $institute_id = self::institute( $institute_id );

        $students = EduFlow_DB::table( 'students' );
        $batches = EduFlow_DB::table( 'batches' );

        $students_without_batch = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM $students
                 WHERE institute_id=%d
                 AND status='active'
                 AND student_status NOT IN ('dropped','cancelled','completed')
                 AND (batch_id IS NULL OR batch_id=0)",
                $institute_id
            )
        );

        $students_without_teacher = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM $students
                 WHERE institute_id=%d
                 AND status='active'
                 AND student_status NOT IN ('dropped','cancelled','completed')
                 AND (teacher_id IS NULL OR teacher_id=0)",
                $institute_id
            )
        );

        $batches_without_teacher = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM $batches
                 WHERE institute_id=%d
                 AND batch_status='active'
                 AND (teacher_id IS NULL OR teacher_id=0)",
                $institute_id
            )
        );

        return array(
            'students_without_batch' => $students_without_batch,
            'students_without_teacher' => $students_without_teacher,
            'batches_without_teacher' => $batches_without_teacher,
        );
    }
}
