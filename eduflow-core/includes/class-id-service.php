<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_ID_Service {

        const PREFIXES = array(
                'student'    => 'STU',
                'admission'  => 'ADM',
                'batch'      => 'BAT',
                'class'      => 'CLS',
                'demo'       => 'DMO',
                'payment'    => 'PAY',
                'employee'   => 'EMP',
                'lead'       => 'LEAD',
                'attendance' => 'ATT',
        );

        public static function generate( $type, $institute_id = 0 ) {
                global $wpdb;

                if ( ! isset( self::PREFIXES[ $type ] ) ) {
                        return new WP_Error(
                                'eduflow_invalid_id_type',
                                'Invalid ID type.'
                        );
                }

                $institute_id = $institute_id
                        ?: EduFlow_Settings::institute_db_id();

                if ( $institute_id < 1 ) {
                        return new WP_Error(
                                'eduflow_invalid_institute',
                                'A valid institute is required.'
                        );
                }

                $settings = EduFlow_Settings::get_for_institute(
                        $institute_id
                );

                $prefix = strtoupper(
                        preg_replace(
                                '/[^A-Z0-9]/',
                                '',
                                (string) ( $settings['id_prefix'] ?? '' )
                        )
                );

                if ( '' === $prefix ) {
                        return new WP_Error(
                                'eduflow_invalid_prefix',
                                'Configure an institute ID prefix.'
                        );
                }

                $sequence_table = EduFlow_DB::table( 'id_sequences' );
                $sequence       = strtoupper( $type );
                $now            = current_time( 'mysql', true );

                /*
                 * STUDENT SEQUENCE SELF-HEALING
                 *
                 * Legacy/imported students can exist ahead of id_sequences.
                 * Before allocating a Student ID, read the highest actual
                 * canonical Student number and advance from whichever is
                 * greater: live data or stored sequence.
                 */
                if ( 'student' === $type ) {

                        $students = EduFlow_DB::table( 'students' );
                        $pattern  = $prefix . '-STU-%';

                        $max_existing = (int) $wpdb->get_var(
                                $wpdb->prepare(
                                        "SELECT COALESCE(
                                                MAX(
                                                        CAST(
                                                                SUBSTRING_INDEX(
                                                                        canonical_id,
                                                                        '-',
                                                                        -1
                                                                ) AS UNSIGNED
                                                        )
                                                ),
                                                0
                                        )
                                        FROM $students
                                        WHERE institute_id=%d
                                        AND canonical_id LIKE %s",
                                        $institute_id,
                                        $pattern
                                )
                        );

                        $first_value = max(
                                1,
                                $max_existing + 1
                        );

                        $sql = $wpdb->prepare(
                                "INSERT INTO $sequence_table
                                (
                                        institute_id,
                                        sequence_type,
                                        current_value,
                                        status,
                                        created_at,
                                        updated_at
                                )
                                VALUES
                                (
                                        %d,
                                        %s,
                                        LAST_INSERT_ID(%d),
                                        'active',
                                        %s,
                                        %s
                                )
                                ON DUPLICATE KEY UPDATE
                                        current_value = LAST_INSERT_ID(
                                                GREATEST(
                                                        current_value,
                                                        %d
                                                ) + 1
                                        ),
                                        updated_at = VALUES(updated_at)",
                                $institute_id,
                                $sequence,
                                $first_value,
                                $now,
                                $now,
                                $max_existing
                        );

                } else {

                        /*
                         * Standard atomic allocator for all other entity IDs.
                         */
                        $sql = $wpdb->prepare(
                                "INSERT INTO $sequence_table
                                (
                                        institute_id,
                                        sequence_type,
                                        current_value,
                                        status,
                                        created_at,
                                        updated_at
                                )
                                VALUES
                                (
                                        %d,
                                        %s,
                                        LAST_INSERT_ID(1),
                                        'active',
                                        %s,
                                        %s
                                )
                                ON DUPLICATE KEY UPDATE
                                        current_value =
                                                LAST_INSERT_ID(
                                                        current_value + 1
                                                ),
                                        updated_at =
                                                VALUES(updated_at)",
                                $institute_id,
                                $sequence,
                                $now,
                                $now
                        );
                }

                if ( false === $wpdb->query( $sql ) ) {
                        return new WP_Error(
                                'eduflow_id_failure',
                                'Unable to allocate ID. DB: ' .
                                $wpdb->last_error
                        );
                }

                $value = (int) $wpdb->get_var(
                        'SELECT LAST_INSERT_ID()'
                );

                if ( $value < 1 ) {
                        return new WP_Error(
                                'eduflow_id_failure',
                                'ID allocator returned an invalid sequence.'
                        );
                }

                return $prefix .
                        '-' .
                        self::PREFIXES[ $type ] .
                        '-' .
                        str_pad(
                                (string) $value,
                                6,
                                '0',
                                STR_PAD_LEFT
                        );
        }
}
