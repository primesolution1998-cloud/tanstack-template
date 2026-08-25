<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Auto_Assignment_Service {

    private static function normalize_time_text(
        $value
    ) {
        return strtolower(
            preg_replace(
                '/[^0-9apm:]+/',
                '',
                (string) $value
            )
        );
    }

    public static function assign_student_from_admission(
        $student_id,
        $admission_id,
        $institute_id = 0
    ) {
        global $wpdb;

        $institute_id =
            $institute_id
            ?: EduFlow_Settings::institute_db_id();

        $student =
            EduFlow_Student_Service::get(
                $student_id,
                $institute_id
            );

        $admission =
            EduFlow_Admission_Service::get(
                $admission_id,
                $institute_id
            );

        if (
            ! $student ||
            ! $admission
        ) {
            return new WP_Error(
                'auto_assignment_source_missing',
                'Student or admission not found.'
            );
        }

        if (
            in_array(
                $student['student_status'],
                array(
                    'dropped',
                    'cancelled',
                    'completed'
                ),
                true
            )
        ) {
            return true;
        }

        if (
            ! empty(
                $student['batch_id']
            )
        ) {
            return true;
        }

        $batches =
            EduFlow_DB::table(
                'batches'
            );

        $candidates =
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT *
                     FROM $batches
                     WHERE institute_id=%d
                     AND batch_status='active'
                     AND status='active'
                     AND course=%s
                     ORDER BY start_time ASC",
                    $institute_id,
                    $student['course']
                ),
                ARRAY_A
            );

        if ( ! $candidates ) {
            return true;
        }

        $preferred =
            self::normalize_time_text(
                $admission[
                    'preferred_timing'
                ] ?? ''
            );

        $selected = null;

        foreach (
            $candidates
            as $batch
        ) {
            if (
                $student['class_type'] &&
                $batch['class_type'] &&
                $student['class_type']
                    !==
                $batch['class_type']
            ) {
                continue;
            }

            if (
                $student['level'] &&
                $batch['level'] &&
                strcasecmp(
                    $student['level'],
                    $batch['level']
                ) !== 0
            ) {
                continue;
            }

            if ( $preferred ) {

                $batch_text =
                    self::normalize_time_text(
                        $batch['start_time'] .
                        $batch['end_time'] .
                        $batch['batch_name']
                    );

                if (
                    false ===
                    strpos(
                        $batch_text,
                        $preferred
                    )
                    &&
                    false ===
                    strpos(
                        $preferred,
                        self::normalize_time_text(
                            substr(
                                $batch['start_time'],
                                0,
                                5
                            )
                        )
                    )
                ) {
                    continue;
                }
            }

            $selected = $batch;
            break;
        }

        if ( ! $selected ) {
            return true;
        }

        $result =
            EduFlow_Batch_Service::assign_student(
                (int) $selected['id'],
                $student_id,
                $institute_id
            );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if (
            ! empty(
                $selected['teacher_id']
            )
        ) {
            $wpdb->update(
                EduFlow_DB::table(
                    'students'
                ),
                array(
                    'teacher_id' =>
                        (int)
                        $selected[
                            'teacher_id'
                        ],
                    'updated_at' =>
                        current_time(
                            'mysql',
                            true
                        ),
                ),
                array(
                    'id' =>
                        $student_id,
                    'institute_id' =>
                        $institute_id,
                )
            );
        }

        EduFlow_Audit_Service::log(
            'student_auto_assigned',
            'student',
            $student['canonical_id'],
            null,
            array(
                'batch_id' =>
                    (int)
                    $selected['id'],
                'teacher_id' =>
                    (int)
                    (
                        $selected[
                            'teacher_id'
                        ] ?? 0
                    ),
            ),
            $institute_id
        );

        return true;
    }

    public static function assign_teacher_to_batch(
        $teacher_id,
        $batch_id,
        $institute_id = 0
    ) {
        global $wpdb;

        $institute_id =
            $institute_id
            ?: EduFlow_Settings::institute_db_id();

        $teacher_id = absint( $teacher_id );
        $batch_id   = absint( $batch_id );

        if ( ! $teacher_id || ! $batch_id ) {
            return new WP_Error(
                'teacher_batch_required',
                'Select both a valid Batch and Teacher.'
            );
        }

        $teacher =
            EduFlow_Teacher_Service::get(
                $teacher_id,
                $institute_id
            );

        if ( ! $teacher ) {
            return new WP_Error(
                'teacher_not_found',
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
                'Selected teacher must be Active before assignment.'
            );
        }

        $batch =
            EduFlow_Batch_Service::get(
                $batch_id,
                $institute_id
            );

        if ( ! $batch ) {
            return new WP_Error(
                'batch_not_found',
                'Selected batch was not found.'
            );
        }

        if (
            'active' !==
            sanitize_key(
                $batch['batch_status']
            )
        ) {
            return new WP_Error(
                'batch_not_active',
                'Teacher can only be assigned to an Active batch.'
            );
        }

        /*
         * Corporate schedule validation:
         * availability days, time window and overlapping batches.
         */
        $check =
            EduFlow_Teacher_Service::can_take_batch(
                $teacher_id,
                $batch_id,
                $institute_id
            );

        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $old_teacher =
            (int) (
                $batch['teacher_id']
                ?? 0
            );

        /*
         * Same teacher already owns this batch.
         * Do not create fake reassignment history.
         */
        if ( $old_teacher === $teacher_id ) {

            $lecture_result =
                EduFlow_Class_Service::generate_batch(
                    $batch_id,
                    current_time( 'Y-m-d' ),
                    14,
                    $institute_id
                );

            return array(
                'assigned'        => true,
                'already_assigned'=> true,
                'batch_id'        => $batch_id,
                'batch_name'      => $batch['batch_name'],
                'teacher_id'      => $teacher_id,
                'teacher_name'    => $teacher['name'],
                'old_teacher_id'  => $old_teacher,
                'students_synced' => 0,
                'lectures'        => $lecture_result,
            );
        }

        $batches_table =
            EduFlow_DB::table(
                'batches'
            );

        $assignments_table =
            EduFlow_DB::table(
                'batch_students'
            );

        $students_table =
            EduFlow_DB::table(
                'students'
            );

        $now =
            current_time(
                'mysql',
                true
            );

        $wpdb->query(
            'START TRANSACTION'
        );

        try {

            $updated =
                $wpdb->update(
                    $batches_table,
                    array(
                        'teacher_id' =>
                            $teacher_id,

                        'updated_at' =>
                            $now,
                    ),
                    array(
                        'id' =>
                            $batch_id,

                        'institute_id' =>
                            $institute_id,
                    )
                );

            if ( false === $updated ) {
                throw new RuntimeException(
                    'Teacher assignment failed. DB: ' .
                    $wpdb->last_error
                );
            }

            /*
             * Re-read the batch.
             * Never show success until persistence is verified.
             */
            $verified_batch =
                EduFlow_Batch_Service::get(
                    $batch_id,
                    $institute_id
                );

            if (
                ! $verified_batch ||
                (int) $verified_batch['teacher_id']
                    !== $teacher_id
            ) {
                throw new RuntimeException(
                    'Teacher assignment verification failed.'
                );
            }

            $student_ids =
                $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT student_id
                         FROM $assignments_table
                         WHERE institute_id=%d
                         AND batch_id=%d
                         AND assignment_status='active'",
                        $institute_id,
                        $batch_id
                    )
                );

            $students_synced = 0;

            foreach (
                $student_ids
                as $student_id
            ) {

                $student_updated =
                    $wpdb->update(
                        $students_table,
                        array(
                            'teacher_id' =>
                                $teacher_id,

                            'updated_at' =>
                                $now,
                        ),
                        array(
                            'id' =>
                                (int) $student_id,

                            'institute_id' =>
                                $institute_id,
                        )
                    );

                if (
                    false ===
                    $student_updated
                ) {
                    throw new RuntimeException(
                        'Student teacher synchronization failed for Student #' .
                        (int) $student_id .
                        '. DB: ' .
                        $wpdb->last_error
                    );
                }

                $students_synced++;
            }

            $wpdb->query(
                'COMMIT'
            );

        } catch ( Throwable $e ) {

            $wpdb->query(
                'ROLLBACK'
            );

            return new WP_Error(
                'teacher_batch_assignment_failed',
                $e->getMessage()
            );
        }

        EduFlow_Audit_Service::log(
            'batch_teacher_reassigned',
            'batch',
            $batch['canonical_id'],
            array(
                'teacher_id' =>
                    $old_teacher,
            ),
            array(
                'teacher_id' =>
                    $teacher_id,

                'students_synced' =>
                    $students_synced,
            ),
            $institute_id
        );

        /*
         * Lecture generation runs only AFTER
         * verified teacher assignment is committed.
         */
        $lecture_result =
            EduFlow_Class_Service::generate_batch(
                $batch_id,
                current_time( 'Y-m-d' ),
                14,
                $institute_id
            );

        return array(
            'assigned' =>
                true,

            'already_assigned' =>
                false,

            'batch_id' =>
                $batch_id,

            'batch_name' =>
                $batch['batch_name'],

            'teacher_id' =>
                $teacher_id,

            'teacher_name' =>
                $teacher['name'],

            'old_teacher_id' =>
                $old_teacher,

            'students_synced' =>
                $students_synced,

            'lectures' =>
                $lecture_result,
        );
    }
}
