<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_YTC_Teacher_Seed {

    const OPTION_KEY = 'eduflow_ytc_teacher_seed_847_verified';

    public static function register() {
        add_action(
            'admin_init',
            array( __CLASS__, 'run' ),
            20
        );
    }

    public static function run() {

        if (
            ! current_user_can( 'manage_options' ) &&
            ! current_user_can( 'eduflow_manage_teachers' )
        ) {
            return;
        }

        if ( get_option( self::OPTION_KEY ) ) {
            return;
        }

        global $wpdb;

        /*
         * Ensure the latest schema/migrations exist first.
         */
        EduFlow_DB::install();

        $institute_id =
            EduFlow_Settings::institute_db_id();

        if ( ! $institute_id ) {
            return;
        }

        $table =
            EduFlow_DB::table( 'teachers' );

        $now =
            current_time( 'mysql', true );

        $today =
            current_time( 'Y-m-d' );

        $teachers = array(

            array(
                'name'   => 'Subhash Singh',
                'mobile' => '9145597951',
                'email'  => 'subhashkamalsingh@gmail.com',
            ),

            array(
                'name'   => 'Shravani Deore',
                'mobile' => '8080472142',
                'email'  => 'shravanideore77@gmail.com',
            ),

        );

        $verified = 0;

        foreach ( $teachers as $teacher ) {

            $existing =
                $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT *
                         FROM $table
                         WHERE institute_id=%d
                         AND (
                            mobile=%s
                            OR email=%s
                         )
                         ORDER BY id ASC
                         LIMIT 1",
                        $institute_id,
                        $teacher['mobile'],
                        $teacher['email']
                    ),
                    ARRAY_A
                );

            $data = array(
                'name'                  => $teacher['name'],
                'mobile'                => $teacher['mobile'],
                'email'                 => $teacher['email'],
                'teacher_status'        => 'active',
                'available_days'        => 'mon,tue,wed,thu,fri,sat,sun',
                'available_start_time'  => '08:00:00',
                'available_end_time'    => '23:00:00',
                'maximum_daily_classes' => 12,
                'status'                => 'active',
                'updated_at'            => $now,
            );

            if ( $existing ) {

                $result =
                    $wpdb->update(
                        $table,
                        $data,
                        array(
                            'id'           => (int) $existing['id'],
                            'institute_id' => $institute_id,
                        )
                    );

                if ( false === $result ) {
                    continue;
                }

            } else {

                $canonical =
                    EduFlow_ID_Service::generate(
                        'employee',
                        $institute_id
                    );

                if ( is_wp_error( $canonical ) ) {
                    continue;
                }

                $data['canonical_id'] =
                    $canonical;

                $data['institute_id'] =
                    $institute_id;

                $data['joining_date'] =
                    $today;

                $data['created_at'] =
                    $now;

                if (
                    false ===
                    $wpdb->insert(
                        $table,
                        $data
                    )
                ) {
                    continue;
                }
            }

            /*
             * Do not trust INSERT/UPDATE alone.
             * Verify the persisted current-tenant record.
             */
            $check =
                $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT
                            id,
                            name,
                            teacher_status,
                            available_days,
                            available_start_time,
                            available_end_time,
                            status
                         FROM $table
                         WHERE institute_id=%d
                         AND mobile=%s
                         LIMIT 1",
                        $institute_id,
                        $teacher['mobile']
                    ),
                    ARRAY_A
                );

            if (
                $check &&
                'active' === $check['teacher_status'] &&
                'active' === $check['status'] &&
                '08:00:00' === $check['available_start_time'] &&
                '23:00:00' === $check['available_end_time']
            ) {
                $verified++;
            }
        }

        /*
         * Only lock the seed after BOTH teachers really exist.
         */
        if ( 2 === $verified ) {

            update_option(
                self::OPTION_KEY,
                array(
                    'verified_at' =>
                        current_time( 'mysql' ),

                    'institute_id' =>
                        $institute_id,

                    'teachers' =>
                        $verified,
                ),
                false
            );

            EduFlow_Audit_Service::log(
                'ytc_teacher_master_repaired',
                'teacher',
                'YTC-TEACHERS',
                null,
                array(
                    'verified' =>
                        $verified,
                ),
                $institute_id
            );
        }
    }
}

EduFlow_YTC_Teacher_Seed::register();
