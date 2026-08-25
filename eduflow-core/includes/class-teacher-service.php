<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Teacher_Service {

    const STATUSES = array(
        'active',
        'on_leave',
        'paused',
        'inactive',
    );

    public static function days( $days ) {
        $allowed = array(
            'mon','tue','wed','thu','fri','sat','sun'
        );

        $days = is_array( $days )
            ? $days
            : explode( ',', (string) $days );

        return array_values(
            array_intersect(
                $allowed,
                array_map( 'sanitize_key', $days )
            )
        );
    }

    private static function date( $date ) {
        $date = sanitize_text_field( (string) $date );

        $d = DateTimeImmutable::createFromFormat(
            'Y-m-d',
            $date
        );

        return (
            $d &&
            $d->format( 'Y-m-d' ) === $date
        )
            ? $date
            : current_time( 'Y-m-d' );
    }

    private static function clean( $input ) {

        $mobile =
            EduFlow_Contact_Service::normalize_indian_mobile(
                $input['mobile'] ?? ''
            );

        if ( is_wp_error( $mobile ) ) {
            return $mobile;
        }

        $email = sanitize_email(
            $input['email'] ?? ''
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

        $days = self::days(
            $input['available_days'] ?? array()
        );

        $start = sanitize_text_field( $input['available_start_time'] ?? '' );
        $end   = sanitize_text_field( $input['available_end_time'] ?? '' );

        if ( $start || $end ) {

            if ( ! $start || ! $end ) {
                return new WP_Error(
                    'invalid_time',
                    'Enter both availability start and end time.'
                );
            }

            $start_obj = DateTimeImmutable::createFromFormat( 'H:i', substr( $start, 0, 5 ) );
            $end_obj   = DateTimeImmutable::createFromFormat( 'H:i', substr( $end, 0, 5 ) );

            if ( ! $start_obj || ! $end_obj ) {
                return new WP_Error(
                    'invalid_time',
                    'Enter a valid availability time.'
                );
            }

            $start = $start_obj->format( 'H:i:s' );
            $end   = $end_obj->format( 'H:i:s' );

            if ( $start_obj >= $end_obj ) {
                return new WP_Error(
                    'invalid_time',
                    'Availability end must follow start.'
                );
            }
        }

        return array(
            'name' => sanitize_text_field(
                $input['name'] ?? ''
            ),

            'mobile' => $mobile,

            'email' => $email ?: null,

            'joining_date' => self::date(
                $input['joining_date'] ?? ''
            ),

            'available_days' =>
                implode( ',', $days ),

            'available_start_time' =>
                $start ?: null,

            'available_end_time' =>
                $end ?: null,

            'maximum_daily_classes' =>
                max(
                    1,
                    absint(
                        $input['maximum_daily_classes']
                        ?? 8
                    )
                ),

            'notes' =>
                sanitize_textarea_field(
                    $input['notes'] ?? ''
                ),
        );
    }

    public static function create(
        $input,
        $institute_id = 0
    ) {
        global $wpdb;

        $institute_id =
            $institute_id
            ?: EduFlow_Settings::institute_db_id();

        $data = self::clean( $input );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        if ( '' === $data['name'] ) {
            return new WP_Error(
                'name_required',
                'Teacher name is required.'
            );
        }

        $table =
            EduFlow_DB::table( 'teachers' );

        $duplicate =
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT canonical_id
                     FROM $table
                     WHERE institute_id=%d
                     AND status='active'
                     AND (
                        mobile=%s
                        OR (
                            %s<>''
                            AND email=%s
                        )
                     )
                     LIMIT 1",
                    $institute_id,
                    $data['mobile'],
                    (string) $data['email'],
                    (string) $data['email']
                )
            );

        if ( $duplicate ) {
            return new WP_Error(
                'duplicate_teacher',
                'Teacher already exists: ' .
                $duplicate
            );
        }

        $id =
            EduFlow_ID_Service::generate(
                'employee',
                $institute_id
            );

        if ( is_wp_error( $id ) ) {
            return $id;
        }

        $now =
            current_time( 'mysql', true );

        $data = array_merge(
            $data,
            array(
                'canonical_id'  => $id,
                'institute_id'  => $institute_id,
                'teacher_status'=> 'active',
                'status'        => 'active',
                'created_at'    => $now,
                'updated_at'    => $now,
            )
        );

        if (
            false ===
            $wpdb->insert(
                $table,
                $data
            )
        ) {
            return new WP_Error(
                'teacher_create_failed',
                'Teacher could not be created. DB: ' .
                $wpdb->last_error
            );
        }

        EduFlow_Audit_Service::log(
            'teacher_created',
            'teacher',
            $id,
            null,
            $data,
            $institute_id
        );

        return (int) $wpdb->insert_id;
    }

    public static function get(
        $id,
        $institute_id = 0
    ) {
        global $wpdb;

        $table =
            EduFlow_DB::table( 'teachers' );

        $institute_id =
            $institute_id
            ?: EduFlow_Settings::institute_db_id();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM $table
                 WHERE id=%d
                 AND institute_id=%d",
                $id,
                $institute_id
            ),
            ARRAY_A
        );
    }

    public static function update(
        $id,
        $input,
        $institute_id = 0
    ) {
        global $wpdb;

        $institute_id =
            $institute_id
            ?: EduFlow_Settings::institute_db_id();

        $old =
            self::get(
                $id,
                $institute_id
            );

        if ( ! $old ) {
            return new WP_Error(
                'not_found',
                'Teacher not found.'
            );
        }

        $data = self::clean( $input );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $data['updated_at'] =
            current_time(
                'mysql',
                true
            );

        $result =
            $wpdb->update(
                EduFlow_DB::table(
                    'teachers'
                ),
                $data,
                array(
                    'id' => $id,
                    'institute_id' =>
                        $institute_id,
                )
            );

        if ( false === $result ) {
            return new WP_Error(
                'teacher_update_failed',
                'Teacher could not be updated. DB: ' .
                $wpdb->last_error
            );
        }

        EduFlow_Audit_Service::log(
            'teacher_updated',
            'teacher',
            $old['canonical_id'],
            $old,
            $data,
            $institute_id
        );

        return true;
    }

    public static function set_status(
        $id,
        $status,
        $institute_id = 0
    ) {
        global $wpdb;

        $status =
            sanitize_key( $status );

        if (
            ! in_array(
                $status,
                self::STATUSES,
                true
            )
        ) {
            return new WP_Error(
                'invalid_status',
                'Invalid teacher status.'
            );
        }

        $institute_id =
            $institute_id
            ?: EduFlow_Settings::institute_db_id();

        $old =
            self::get(
                $id,
                $institute_id
            );

        if ( ! $old ) {
            return new WP_Error(
                'not_found',
                'Teacher not found.'
            );
        }

        $wpdb->update(
            EduFlow_DB::table(
                'teachers'
            ),
            array(
                'teacher_status' => $status,
                'updated_at' =>
                    current_time(
                        'mysql',
                        true
                    ),
            ),
            array(
                'id' => $id,
                'institute_id' =>
                    $institute_id,
            )
        );

        EduFlow_Audit_Service::log(
            'teacher_status_changed',
            'teacher',
            $old['canonical_id'],
            $old['teacher_status'],
            $status,
            $institute_id
        );

        return true;
    }

    public static function can_take_batch(
        $teacher_id,
        $batch_id,
        $institute_id = 0
    ) {
        global $wpdb;

        $institute_id =
            $institute_id
            ?: EduFlow_Settings::institute_db_id();

        $teacher =
            self::get(
                $teacher_id,
                $institute_id
            );

        if ( ! $teacher ) {
            return new WP_Error(
                'teacher_not_found',
                'Teacher not found.'
            );
        }

        if (
            'active' !==
            $teacher['teacher_status']
        ) {
            return new WP_Error(
                'teacher_not_active',
                'Teacher must be Active before batch assignment.'
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
                'Batch not found.'
            );
        }

        $teacher_days =
            self::days(
                $teacher['available_days']
            );

        $batch_days =
            self::days(
                $batch['days_of_week']
            );

        if (
            $teacher_days &&
            array_diff(
                $batch_days,
                $teacher_days
            )
        ) {
            return new WP_Error(
                'teacher_day_conflict',
                'Teacher is not available on all batch days.'
            );
        }

        if (
            $teacher['available_start_time'] &&
            $batch['start_time'] <
            $teacher['available_start_time']
        ) {
            return new WP_Error(
                'teacher_time_conflict',
                'Batch starts before teacher availability.'
            );
        }

        if (
            $teacher['available_end_time'] &&
            $batch['end_time'] >
            $teacher['available_end_time']
        ) {
            return new WP_Error(
                'teacher_time_conflict',
                'Batch ends after teacher availability.'
            );
        }

        $batches =
            EduFlow_DB::table(
                'batches'
            );

        $others =
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT *
                     FROM $batches
                     WHERE institute_id=%d
                     AND teacher_id=%d
                     AND id<>%d
                     AND batch_status='active'",
                    $institute_id,
                    $teacher_id,
                    $batch_id
                ),
                ARRAY_A
            );

        foreach ( $others as $other ) {

            $overlap_days =
                array_intersect(
                    self::days(
                        $batch['days_of_week']
                    ),
                    self::days(
                        $other['days_of_week']
                    )
                );

            $overlap_time =
                $batch['start_time'] <
                    $other['end_time']
                &&
                $batch['end_time'] >
                    $other['start_time'];

            if (
                $overlap_days &&
                $overlap_time
            ) {
                return new WP_Error(
                    'teacher_batch_conflict',
                    'Teacher already has an overlapping active batch: ' .
                    $other['batch_name']
                );
            }
        }

        return true;
    }
}
