<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_YTC_Master_Import {

    public static function register() {
        add_action(
            'admin_post_eduflow_seed_ytc_master',
            array( __CLASS__, 'handle' )
        );

        add_action(
            'admin_init',
            array( __CLASS__, 'maybe_auto_seed' )
        );
    }

    public static function maybe_auto_seed() {
        if (
            ! is_admin() ||
            ! current_user_can( 'eduflow_manage_admissions' ) ||
            '1' !== (string) ( $_GET['eduflow_auto_seed'] ?? '' )
        ) {
            return;
        }

        $_REQUEST['_wpnonce'] = wp_create_nonce( 'eduflow_seed_ytc_master' );
        self::handle();
    }

    public static function render_card() {
        if ( ! current_user_can( 'eduflow_manage_admissions' ) ) {
            return;
        }
        ?>
        <div class="eduflow-card">
            <h2>YTC Master Admissions</h2>
            <p>Direct one-click import from the verified YTC admission master.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="eduflow_seed_ytc_master">
                <?php wp_nonce_field( 'eduflow_seed_ytc_master' ); ?>
                <?php submit_button( 'Import YTC Master Admissions', 'primary' ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle() {
        if ( ! current_user_can( 'eduflow_manage_admissions' ) ) {
            wp_die( 'Permission denied.' );
        }

        check_admin_referer( 'eduflow_seed_ytc_master' );

        global $wpdb;

        // Repair live EduFlow schema first.
        EduFlow_DB::install();

        $table = EduFlow_DB::table( 'admissions' );
        $institute = EduFlow_Settings::institute_db_id();
        $now = current_time( 'mysql', true );

        $records = array(
            array('2026-07-19','Rakesh Kumar','9931756520','Basic','1 to 1','6 to 7 pm','90 Days','Subhash',8400,'Subhash','Active','mahardkumar123@gmail.com'),
            array('','Samiksha Rawat','8851140343','Basic','1 to 1','7 to 8','30 Days','Anchal',1000,'','Active','openarvindrawat@gmail.com'),
            array('2026-07-23','Govind Sharma','9759142343','Basic','Group','8:30 to 9:30','30 Days','Anchal',500,'','Dropped',''),
            array('2026-07-23','Kajal Tiwari','7080062574','Basic','Group','9:30 to 10:30','30 Days','Anchal',1000,'','Dropped','kt2398402@gmail.com'),
            array('2026-07-23','Avinash Chaurasiya','7800232162','Basic','1 to 1','9:30 to 10:30','30 Days','Anchal',2000,'','Active','avinash95chaurasiya@gmail.com'),
            array('2026-07-23','Trilochan Kisan','9937098528','Basic','1 to 1','8.30 to 9.30','30 Days','Anchal',3000,'','Active','trilochankisan1141@gmail.com'),
            array('2026-07-24','Rohit Bisht','7409521558','Basic','Group','9:30 to 10:30','30 Days','Anchal',500,'','Active','rohitbst558@gmail.com'),
            array('2026-07-25','Dushyant Yadav','7217783409','Basic','Group','9:30 to 10:30','90 Days','Anchal',1500,'','Active','dusyantyadav2412@gmail.com'),
            array('2026-07-26','Suhas Yadav','7400449714','Basic','Group','9:30 to 10:30','30 Days','Anchal',500,'','Active','suhasyadav.007@gmail.com'),
            array('2026-07-27','Shashi Ranjan','8677047708','Basic','Group','9:30 to 10:30','30 Days','Anchal',500,'','Active','kgolu771@gmail.com'),
            array('2026-08-03','Lovepreet','61433027772','Basic','1 to 1','10.30 to 11.30','90 Days','Anchal',4500,'','Active','satvinderkang7@gmail.com'),
            array('2026-08-03','Ariyansh Sekhar','9439399759','Basic','Group','6 to 7','90 Days','Anchal',1500,'','Active','himanshusekharmahalik@gmail.com'),
            array('2026-08-03','Chandra Prakash Jaiswal','9335652309','Basic','Group','7 to 8','30 Days','Anchal',1000,'','Active','cpjaiswal420@gmail.com'),
            array('2026-08-04','Ambika Sharma','8894494500','Basic','Group','7 to 8','30 Days','Anchal',1000,'','Active','ambusharma31@gmail.com'),
            array('2026-08-04','Sahil Naik','7058898895','Basic','Group','7 to 8','30 Days','Anchal',1000,'','Active','sahilnaik63032@gmail.com'),
            array('2026-08-05','Jugal Khatri','9426173183','Basic','Group','7 to 8','30 Days','Anchal',1000,'','Active','khatrij314@gmail.com'),
            array('2026-08-06','Gopal Kumar','7004106679','Basic','Group','9 to 10','30 Days','Subhash',500,'Subhash','Dropped',''),
            array('2026-08-09','Pradumn Yadav','9129561747','Basic','Group','8 to 9 pm','30 Days','Subhash',1000,'Subhash','Active',''),
            array('2026-08-10','Farman Ali','9906078268','Basic','Group','8 to 9 pm','30 Days','Anchal',1000,'','Active','alifarman70527@gmail.com'),
            array('2026-08-10','Brijbhushan Singh','8140010900','Basic','Group','8 to 9 pm','30 Days','Subhash',1800,'','Active','mahendrasinhjikhant@gmail.com'),
            array('2026-08-11','Durga Bharti','7339896413','Basic','Group','8 to 9 pm','30 Days','Subhash',1000,'','Active',''),
            array('2026-08-12','Akshay Davang','7602741529','Basic','Group','8 to 9 pm','30 Days','Anchal',1000,'','Active',''),
            array('2026-08-16','Muskan Praveen','9703591202','Basic','Group','8 to 9 pm','30 Days','Anchal',1000,'Anchal','Active','muskanarbaz24@gmail.com'),
            array('2026-08-17','Rajeshwar Shivastav','9893213494','Basic','Group','9:30 to 10:30','30 Days','Anchal',1000,'','Active','rajeshshrivastava0123@gmail.com'),
            array('2026-08-17','Harshvardhan Shinde','8928490303','IM','Group','8 to 9 pm','30 Days','Anchal',1500,'','Active','shindeharshvardhan1988@gmail.com')
        );

        // Use only columns that actually exist in LIVE database.
        $live_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        $live_columns = array_flip( $live_columns );

        $inserted = 0;
        $existing = 0;
        $dropped = 0;
        $errors = 0;
        $details = array();

        foreach ( $records as $r ) {

            list(
                $date,$name,$mobile,$course,$level,$timing,$duration,
                $staff,$fee,$counsellor,$student_status,$email
            ) = $r;

            $email = is_email( $email ) ? strtolower( $email ) : null;

            $duplicate = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table}
                     WHERE institute_id=%d
                     AND (mobile=%s OR (%s<>'' AND email=%s))
                     LIMIT 1",
                    $institute,
                    $mobile,
                    (string) $email,
                    (string) $email
                )
            );

            if ( $duplicate ) {
                $existing++;
                continue;
            }

            $canonical = EduFlow_ID_Service::generate( 'admission', $institute );

            if ( is_wp_error( $canonical ) ) {
                $errors++;
                $details[] = $name . ': ID generation failed';
                continue;
            }

            $missing_date = empty( $date );
            if ( $missing_date ) {
                $date = current_time( 'Y-m-d' );
            }

            $is_dropped = strtolower( $student_status ) === 'dropped';

            $notes =
                'YTC Master Import'
                . ' | Batch: ' . $timing
                . ' | Duration: ' . $duration
                . ' | Staff: ' . $staff
                . ' | Counsellor: ' . $counsellor
                . ' | Source Status: ' . $student_status;

            if ( $missing_date ) {
                $notes .= ' | Original admission date missing';
            }

            if ( strlen( preg_replace('/\D/','',$mobile) ) != 10 ) {
                $notes .= ' | Mobile requires review: ' . $mobile;
            }

            $data = array(
                'canonical_id'          => $canonical,
                'institute_id'          => $institute,
                'student_name'          => $name,
                'mobile'                => $mobile,
                'email'                 => $email,
                'course'                => $course,
                'level'                 => $level,
                'class_type'            => strtolower($level)==='1 to 1' ? '1-to-1' : 'group',
                'preferred_timing'      => $timing,
                'admission_date'        => $date,
                'total_fee'             => $fee,
                'amount_paid'           => $fee,
                'pending_amount'        => 0,
                'payment_status'        => 'paid',
                'payment_method'        => 'upi',
                'transaction_reference' => null,
                'access_period'         => strpos($duration,'90') !== false ? '3_months' : '1_month',
                'custom_access_end'     => null,
                'admission_status'      => $is_dropped ? 'rejected' : 'admission_created',
                'notes'                 => $notes,
                'student_id'            => null,
                'created_by'            => get_current_user_id(),
                'status'                => $is_dropped ? 'inactive' : 'active',
                'created_at'            => $now,
                'updated_at'            => $now
            );

            // Extra safety: remove any column absent from live DB.
            $data = array_intersect_key( $data, $live_columns );

            if ( false === $wpdb->insert( $table, $data ) ) {
                $errors++;
                $details[] = $name . ': ' . $wpdb->last_error;
                continue;
            }

            EduFlow_Audit_Service::log(
                'ytc_master_import',
                'admission',
                $canonical,
                null,
                $data,
                $institute
            );

            if ( $is_dropped ) {
                $dropped++;
            } else {
                $inserted++;
            }
        }

        $message = sprintf(
            'YTC Master Import complete. Active inserted: %d | Existing skipped: %d | Dropped archived: %d | Errors: %d',
            $inserted,
            $existing,
            $dropped,
            $errors
        );

        if ( $details ) {
            $message .= ' | ' . implode( ' || ', array_slice( $details, 0, 5 ) );
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'eduflow-admissions',
                    'eduflow_notice' => rawurlencode( $message ),
                    'notice_type' => $errors ? 'error' : 'success'
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
}

EduFlow_YTC_Master_Import::register();
