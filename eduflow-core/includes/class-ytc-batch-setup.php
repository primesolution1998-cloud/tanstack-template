<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_YTC_Batch_Setup {

    public static function register() {
        add_action(
            'admin_post_eduflow_setup_ytc_batches',
            array( __CLASS__, 'handle' )
        );
    }

    public static function render_card() {
        if ( ! current_user_can( 'eduflow_manage_batches' ) ) {
            return;
        }
        ?>
        <div class="eduflow-card">
            <h2>YTC Running Batch Setup</h2>
            <p>Automatically create/update running batches and assign active students from the verified roster.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="eduflow_setup_ytc_batches">
                <?php wp_nonce_field( 'eduflow_setup_ytc_batches' ); ?>
                <?php submit_button( 'Setup YTC Running Batches', 'primary', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle() {
        if ( ! current_user_can( 'eduflow_manage_batches' ) ) {
            wp_die( 'Permission denied.' );
        }

        check_admin_referer( 'eduflow_setup_ytc_batches' );

        global $wpdb;

        EduFlow_DB::install();

        $institute = EduFlow_Settings::institute_db_id();
        $batches   = EduFlow_DB::table( 'batches' );
        $students  = EduFlow_DB::table( 'students' );
        $today     = current_time( 'Y-m-d' );
        $now       = current_time( 'mysql', true );

        $definitions = array(
            array(
                'name'     => 'YTC 09:30 PM - 10:30 PM',
                'start'    => '21:30:00',
                'end'      => '22:30:00',
                'students' => array(
                    'Avinash Chaurasiya',
                    'Harshvardhan Shinde',
                    'Rajeshwar Shivastav',
                    'Dushyant Yadav',
                    'Suhas Yadav',
                    'Rohit Bisht',
                    'Shashi Ranjan'
                )
            ),
            array(
                'name'     => 'YTC 08:00 PM - 09:00 PM',
                'start'    => '20:00:00',
                'end'      => '21:00:00',
                'students' => array(
                    'Durga Bharti',
                    'Farman Ali',
                    'Brijbhushan Singh',
                    'Akshay Davang',
                    'Pradumn Yadav',
                )
            ),
            array(
                'name'     => 'YTC 07:00 PM - 08:00 PM',
                'start'    => '19:00:00',
                'end'      => '20:00:00',
                'students' => array(
                    'Trilochan Kisan',
                    'Chandra Prakash Jaiswal',
                    'Ambika Sharma',
                    'Sahil Naik',
                    'Jugal Khatri'
                )
            ),
            array(
                'name'     => 'YTC 10:30 AM - 11:30 AM',
                'start'    => '10:30:00',
                'end'      => '11:30:00',
                'students' => array(
                    'Lovepreet'
                )
            ),
            array(
                'name'     => 'YTC 06:00 PM - 07:00 PM',
                'start'    => '18:00:00',
                'end'      => '19:00:00',
                'students' => array(
                    'Rakesh Kumar',
                    'Muskan Praveen',
                    'Samiksha Rawat',
                    'Ariyansh Sekhar'
                )
            )
        );

        $created  = 0;
        $updated  = 0;
        $assigned = 0;
        $skipped  = 0;
        $missing  = array();
        $errors   = array();

        foreach ( $definitions as $def ) {

            $batch = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $batches
                     WHERE institute_id=%d
                     AND batch_name=%s
                     LIMIT 1",
                    $institute,
                    $def['name']
                ),
                ARRAY_A
            );

            $capacity = max( 1, count( $def['students'] ) );

            if ( ! $batch ) {

                $canonical = EduFlow_ID_Service::generate(
                    'batch',
                    $institute
                );

                if ( is_wp_error( $canonical ) ) {
                    $errors[] = $def['name'] . ': ' . $canonical->get_error_message();
                    continue;
                }

                $data = array(
                    'canonical_id' => $canonical,
                    'institute_id' => $institute,
                    'batch_name' => $def['name'],
                    'course' => 'Basic',
                    'level' => 'Basic',
                    'class_type' => 'group',
                    'teacher_id' => null,
                    'capacity' => $capacity,
                    'days_of_week' => 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
                    'start_time' => $def['start'],
                    'end_time' => $def['end'],
                    'start_date' => $today,
                    'end_date' => null,
                    'timezone' => 'Asia/Kolkata',
                    'batch_status' => 'active',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now
                );

                if ( false === $wpdb->insert( $batches, $data ) ) {
                    $errors[] = $def['name'] . ': ' . $wpdb->last_error;
                    continue;
                }

                $batch_id = (int) $wpdb->insert_id;
                $created++;

            } else {

                $batch_id = (int) $batch['id'];

                $wpdb->update(
                    $batches,
                    array(
                        'capacity' => $capacity,
                        'days_of_week' => 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
                        'start_time' => $def['start'],
                        'end_time' => $def['end'],
                        'timezone' => 'Asia/Kolkata',
                        'batch_status' => 'active',
                        'status' => 'active',
                        'updated_at' => $now
                    ),
                    array(
                        'id' => $batch_id,
                        'institute_id' => $institute
                    )
                );

                $updated++;
            }

            foreach ( $def['students'] as $name ) {

                $student = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM $students
                         WHERE institute_id=%d
                         AND LOWER(name)=LOWER(%s)
                         LIMIT 1",
                        $institute,
                        $name
                    ),
                    ARRAY_A
                );

                if ( ! $student ) {
                    $missing[] = $name;
                    continue;
                }

                if (
                    'active' !== $student['status'] ||
                    in_array(
                        strtolower( $student['student_status'] ),
                        array( 'dropped', 'cancelled', 'completed' ),
                        true
                    )
                ) {
                    $skipped++;
                    continue;
                }
                $assignment_table = EduFlow_DB::table( 'batch_students' );

                $current_assignments = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT *
                         FROM $assignment_table
                         WHERE institute_id=%d
                         AND student_id=%d
                         AND assignment_status='active'
                         ORDER BY id ASC",
                        $institute,
                        (int) $student['id']
                    ),
                    ARRAY_A
                );

                $already_target = false;

                foreach ( $current_assignments as $current_assignment ) {
                    if ( (int) $current_assignment['batch_id'] === (int) $batch_id ) {
                        $already_target = true;
                        break;
                    }
                }

                if ( $already_target ) {

                    $wpdb->update(
                        $students,
                        array(
                            'batch_id'   => $batch_id,
                            'updated_at' => $now
                        ),
                        array(
                            'id'           => (int) $student['id'],
                            'institute_id' => $institute
                        )
                    );

                    $skipped++;
                    continue;
                }

                $wpdb->query( 'START TRANSACTION' );

                $migration_failed = false;

                foreach ( $current_assignments as $old_assignment ) {

                    $history_key = 'history:' . (int) $old_assignment['id'];

                    $changed = $wpdb->update(
                        $assignment_table,
                        array(
                            'assignment_key'          => $history_key,
                            'assignment_status'       => 'transferred',
                            'removed_at'              => $now,
                            'transferred_to_batch_id' => $batch_id,
                            'status'                  => 'history',
                            'updated_at'              => $now
                        ),
                        array(
                            'id'           => (int) $old_assignment['id'],
                            'institute_id' => $institute
                        )
                    );

                    if ( false === $changed ) {
                        $errors[] =
                            $name . ': old assignment migration failed - ' .
                            $wpdb->last_error;

                        $migration_failed = true;
                        break;
                    }
                }

                if ( $migration_failed ) {
                    $wpdb->query( 'ROLLBACK' );
                    continue;
                }

                $assignment_key =
                    $institute . ':' .
                    $batch_id . ':' .
                    (int) $student['id'];

                $inserted = $wpdb->insert(
                    $assignment_table,
                    array(
                        'institute_id'      => $institute,
                        'batch_id'          => $batch_id,
                        'student_id'        => (int) $student['id'],
                        'assignment_key'    => $assignment_key,
                        'assignment_status' => 'active',
                        'assigned_at'       => $now,
                        'created_by'        => get_current_user_id(),
                        'status'            => 'active',
                        'created_at'        => $now,
                        'updated_at'        => $now
                    )
                );

                if ( false === $inserted ) {
                    $wpdb->query( 'ROLLBACK' );

                    $errors[] =
                        $name . ': target assignment insert failed - ' .
                        $wpdb->last_error;

                    continue;
                }

                $student_updated = $wpdb->update(
                    $students,
                    array(
                        'batch_id'   => $batch_id,
                        'updated_at' => $now
                    ),
                    array(
                        'id'           => (int) $student['id'],
                        'institute_id' => $institute
                    )
                );

                if ( false === $student_updated ) {
                    $wpdb->query( 'ROLLBACK' );

                    $errors[] =
                        $name . ': Student batch link failed - ' .
                        $wpdb->last_error;

                    continue;
                }

                $wpdb->query( 'COMMIT' );

                EduFlow_Audit_Service::log(
                    'ytc_batch_roster_migrated',
                    'batch_assignment',
                    $wpdb->insert_id,
                    null,
                    array(
                        'batch_id'   => $batch_id,
                        'student_id' => (int) $student['id']
                    ),
                    $institute
                );

                $assigned++;
            }
        }

        $message = sprintf(
            'YTC Batch Setup complete. Created: %d | Updated: %d | Assigned: %d | Skipped: %d | Missing: %d | Errors: %d',
            $created,
            $updated,
            $assigned,
            $skipped,
            count( $missing ),
            count( $errors )
        );

        if ( $missing ) {
            $message .= ' | Missing: ' . implode(
                ', ',
                array_slice( $missing, 0, 10 )
            );
        }

        if ( $errors ) {
            $message .= ' | Errors: ' . implode(
                ' || ',
                array_slice( $errors, 0, 5 )
            );
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'eduflow-batches',
                    'eduflow_notice' => rawurlencode( $message ),
                    'notice_type' => $errors ? 'error' : 'success'
                ),
                admin_url( 'admin.php' )
            )
        );

        exit;
    }
}

EduFlow_YTC_Batch_Setup::register();
