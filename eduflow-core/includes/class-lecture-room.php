<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Lecture_Room {

    public static function register() {
        add_shortcode( 'eduflow_lecture_room', array( __CLASS__, 'shortcode' ) );
    }

    private static function institute_id() {
        return EduFlow_Settings::institute_db_id();
    }

    private static function current_profile() {
        global $wpdb;

        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'login_required', 'Please login to access your Lecture Room.' );
        }

        $institute_id = self::institute_id();
        $wp_user_id   = get_current_user_id();
        $students     = EduFlow_DB::table( 'students' );
        $teachers     = EduFlow_DB::table( 'teachers' );

        $student = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $students WHERE institute_id=%d AND wp_user_id=%d LIMIT 1",
                $institute_id,
                $wp_user_id
            ),
            ARRAY_A
        );

        if ( $student ) {
            return array( 'type' => 'student', 'data' => $student );
        }

        $teacher = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $teachers WHERE institute_id=%d AND wp_user_id=%d LIMIT 1",
                $institute_id,
                $wp_user_id
            ),
            ARRAY_A
        );

        if ( $teacher ) {
            return array( 'type' => 'teacher', 'data' => $teacher );
        }

        if ( current_user_can( 'manage_options' ) || current_user_can( 'eduflow_manage_institute' ) ) {
            return array( 'type' => 'admin', 'data' => array() );
        }

        return new WP_Error( 'profile_not_linked', 'Your EduFlow profile is not linked to this WordPress account.' );
    }

    private static function student_has_access( $student ) {
        if ( 'active' !== (string) ( $student['status'] ?? '' ) ) {
            return false;
        }

        if ( in_array( sanitize_key( $student['student_status'] ?? '' ), array( 'dropped', 'cancelled', 'completed' ), true ) ) {
            return false;
        }

        if ( ! empty( $student['access_end'] ) && current_time( 'Y-m-d' ) > $student['access_end'] ) {
            return false;
        }

        return true;
    }

    private static function allowed_batches( $profile ) {
        global $wpdb;

        $institute_id = self::institute_id();
        $batches      = EduFlow_DB::table( 'batches' );

        if ( 'student' === $profile['type'] ) {
            $batch_id = absint( $profile['data']['batch_id'] ?? 0 );
            if ( ! $batch_id ) {
                return array();
            }

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $batches WHERE institute_id=%d AND id=%d AND batch_status='active' AND status='active' LIMIT 1",
                    $institute_id,
                    $batch_id
                ),
                ARRAY_A
            );
        }

        if ( 'teacher' === $profile['type'] ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $batches WHERE institute_id=%d AND teacher_id=%d AND batch_status='active' AND status='active' ORDER BY start_time ASC",
                    $institute_id,
                    (int) $profile['data']['id']
                ),
                ARRAY_A
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $batches WHERE institute_id=%d AND batch_status='active' AND status='active' ORDER BY start_time ASC",
                $institute_id
            ),
            ARRAY_A
        );
    }

    private static function batch_for_profile( $batch_id, $profile ) {
        $batch_id = absint( $batch_id );
        if ( ! $batch_id ) {
            return new WP_Error( 'batch_required', 'Batch room not found.' );
        }

        foreach ( self::allowed_batches( $profile ) as $batch ) {
            if ( (int) $batch['id'] === $batch_id ) {
                return $batch;
            }
        }

        return new WP_Error( 'batch_access_denied', 'You are not authorized for this batch room.' );
    }

    private static function time_label( $time ) {
        $time = trim( (string) $time );
        if ( ! $time ) {
            return '—';
        }

        $dt = DateTime::createFromFormat( 'H:i:s', $time, new DateTimeZone( 'Asia/Kolkata' ) );
        if ( ! $dt ) {
            $dt = DateTime::createFromFormat( 'H:i', $time, new DateTimeZone( 'Asia/Kolkata' ) );
        }

        return $dt ? $dt->format( 'g:i A' ) : $time;
    }

    private static function room_name( $batch ) {
        $canonical = ! empty( $batch['canonical_id'] ) ? $batch['canonical_id'] : 'BATCH-' . (int) $batch['id'];
        $seed      = self::institute_id() . '|' . $canonical . '|' . wp_salt( 'auth' );
        return 'YTC-' . preg_replace( '/[^A-Za-z0-9]/', '', $canonical ) . '-' . substr( hash( 'sha256', $seed ), 0, 20 );
    }

    private static function roster( $batch_id ) {
        global $wpdb;

        $institute_id = self::institute_id();
        $assignments  = EduFlow_DB::table( 'batch_students' );
        $students     = EduFlow_DB::table( 'students' );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.canonical_id,s.name,s.student_status
                 FROM $assignments a
                 INNER JOIN $students s ON s.id=a.student_id AND s.institute_id=a.institute_id
                 WHERE a.institute_id=%d
                   AND a.batch_id=%d
                   AND a.assignment_status='active'
                   AND s.status='active'
                   AND s.student_status NOT IN ('dropped','cancelled','completed')
                 ORDER BY s.name ASC",
                $institute_id,
                absint( $batch_id )
            ),
            ARRAY_A
        );
    }

    private static function message( $text, $type = 'info' ) {
        return '<div class="eduflow-lecture-message eduflow-lecture-' . esc_attr( $type ) . '">' . esc_html( $text ) . '</div>';
    }

    public static function shortcode() {
        $profile = self::current_profile();

        if ( is_wp_error( $profile ) ) {
            return self::message( $profile->get_error_message(), 'error' );
        }

        if ( 'student' === $profile['type'] && ! self::student_has_access( $profile['data'] ) ) {
            return self::message( 'Your Lecture Room access is currently inactive.', 'error' );
        }

        $batch_id = absint( $_GET['batch'] ?? 0 );
        if ( $batch_id ) {
            return self::room_view( $batch_id, $profile );
        }

        return self::dashboard_view( $profile );
    }

    private static function dashboard_view( $profile ) {
        $batches = self::allowed_batches( $profile );

        ob_start();
        ?>
        <div class="eduflow-lecture-room">
            <div class="eflr-hero">
                <div>
                    <span class="eflr-eyebrow">YTC ENGLISH SPEAKING</span>
                    <h2>My Batch Rooms</h2>
                    <p>One permanent live classroom for each active batch.</p>
                </div>
                <span class="eflr-role"><?php echo esc_html( ucfirst( $profile['type'] ) ); ?></span>
            </div>

            <?php if ( ! $batches ) : ?>
                <div class="eflr-empty">
                    <h3>No Active Batch Room</h3>
                    <p>Your assigned batch room will appear here automatically.</p>
                </div>
            <?php else : ?>
                <div class="eflr-grid">
                    <?php foreach ( $batches as $batch ) : ?>
                        <article class="eflr-card">
                            <div class="eflr-card-top">
                                <span class="eflr-status">Active</span>
                                <span><?php echo esc_html( $batch['canonical_id'] ); ?></span>
                            </div>

                            <h3><?php echo esc_html( $batch['batch_name'] ); ?></h3>

                            <p><strong>Time:</strong> <?php echo esc_html( self::time_label( $batch['start_time'] ) . ' – ' . self::time_label( $batch['end_time'] ) ); ?></p>
                            <p><strong>Days:</strong> <?php echo esc_html( str_replace( ',', ', ', $batch['days_of_week'] ) ); ?></p>

                            <a class="eflr-join" href="<?php echo esc_url( add_query_arg( 'batch', (int) $batch['id'] ) ); ?>">
                                <?php echo 'teacher' === $profile['type'] ? 'Open Batch Room' : 'Join Batch Room'; ?>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        self::styles();
        return ob_get_clean();
    }

    private static function room_view( $batch_id, $profile ) {
        $batch = self::batch_for_profile( $batch_id, $profile );
        if ( is_wp_error( $batch ) ) {
            return self::message( $batch->get_error_message(), 'error' );
        }

        $roster    = self::roster( $batch_id );
        $room_name = self::room_name( $batch );
        $room_url  = 'https://meet.jit.si/' . rawurlencode( $room_name ) . '#config.prejoinPageEnabled=false&config.disableDeepLinking=true';

        ob_start();
        ?>
        <div class="eduflow-lecture-room">
            <div class="eflr-room-head">
                <a class="eflr-back" href="<?php echo esc_url( remove_query_arg( 'batch' ) ); ?>">← Back to Batch Rooms</a>
                <span class="eflr-status">Live Classroom</span>
            </div>

            <div class="eflr-live-panel">
                <span class="eflr-eyebrow">YTC LIVE CLASSROOM</span>
                <h2><?php echo esc_html( $batch['batch_name'] ); ?></h2>
                <p><?php echo esc_html( self::time_label( $batch['start_time'] ) . ' – ' . self::time_label( $batch['end_time'] ) ); ?> · <?php echo esc_html( str_replace( ',', ', ', $batch['days_of_week'] ) ); ?></p>

                <div class="eflr-video-wrap">
                    <iframe
                        src="<?php echo esc_url( $room_url ); ?>"
                        allow="camera; microphone; fullscreen; display-capture; autoplay"
                        referrerpolicy="no-referrer"
                        title="YTC Live Classroom"
                    ></iframe>
                </div>
            </div>

            <div class="eflr-roster">
                <h3>Batch Students <span class="eflr-count"><?php echo esc_html( count( $roster ) ); ?></span></h3>
                <?php if ( ! $roster ) : ?>
                    <p>No active students assigned.</p>
                <?php else : ?>
                    <div class="eflr-roster-grid">
                        <?php foreach ( $roster as $student ) : ?>
                            <div class="eflr-student">
                                <strong><?php echo esc_html( $student['name'] ); ?></strong>
                                <span><?php echo esc_html( $student['canonical_id'] ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        self::styles();
        return ob_get_clean();
    }

    private static function styles() {
        ?>
        <style>
            .eduflow-lecture-room{max-width:1180px;margin:24px auto;font-family:Arial,sans-serif;color:#13213c}
            .eflr-hero,.eflr-live-panel,.eflr-roster{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:22px;box-shadow:0 8px 24px rgba(15,23,42,.05);margin-bottom:18px}
            .eflr-hero,.eflr-room-head{display:flex;align-items:center;justify-content:space-between;gap:16px}
            .eflr-eyebrow{font-size:12px;font-weight:700;letter-spacing:.12em;color:#6d4aff}
            .eflr-role,.eflr-status,.eflr-count{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#5b3df5;font-size:12px;font-weight:700}
            .eflr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(235px,1fr));gap:14px;margin-top:16px}
            .eflr-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;box-shadow:0 5px 18px rgba(15,23,42,.04)}
            .eflr-card-top{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#64748b}
            .eflr-card h3{margin:16px 0 10px;font-size:18px}
            .eflr-card p{margin:7px 0;color:#475569}
            .eflr-join{display:inline-block;margin-top:14px;background:#6545e8;color:#fff!important;text-decoration:none;padding:11px 16px;border-radius:8px;font-weight:700}
            .eflr-room-head{margin-bottom:14px}
            .eflr-back{text-decoration:none;font-weight:700;color:#4f46e5}
            .eflr-video-wrap{margin-top:18px;width:100%;height:680px;background:#0f172a;border-radius:16px;overflow:hidden}
            .eflr-video-wrap iframe{width:100%;height:100%;border:0;display:block}
            .eflr-roster-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:14px}
            .eflr-student{border:1px solid #e2e8f0;border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:4px}
            .eflr-student span{font-size:12px;color:#64748b}
            .eflr-empty,.eduflow-lecture-message{max-width:900px;margin:20px auto;padding:22px;border:1px solid #e2e8f0;border-radius:14px;background:#fff}
            .eduflow-lecture-error{border-color:#fecaca;background:#fff7f7;color:#991b1b}
            @media(max-width:700px){.eflr-hero,.eflr-room-head{align-items:flex-start;flex-direction:column}.eflr-video-wrap{height:520px}}
        </style>
        <?php
    }
}

EduFlow_Lecture_Room::register();
