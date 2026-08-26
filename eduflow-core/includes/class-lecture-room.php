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

    private static function display_name( $profile ) {
        if ( 'student' === $profile['type'] || 'teacher' === $profile['type'] ) {
            $name = trim( (string) ( $profile['data']['name'] ?? '' ) );
            if ( $name ) {
                return $name;
            }
        }

        $user = wp_get_current_user();
        return $user && $user->exists() ? $user->display_name : 'YTC User';
    }

    private static function display_email( $profile ) {
        if ( 'student' === $profile['type'] || 'teacher' === $profile['type'] ) {
            $email = sanitize_email( $profile['data']['email'] ?? '' );
            if ( $email ) {
                return $email;
            }
        }

        $user = wp_get_current_user();
        return $user && $user->exists() ? sanitize_email( $user->user_email ) : '';
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
                    <span class="eflr-eyebrow">YTC EDUCATION · LIVE ENGLISH CLASSES</span>
                    <h2>My Live Classrooms</h2>
                    <p>Select your batch and enter the permanent YTC classroom.</p>
                </div>
                <span class="eflr-role"><?php echo esc_html( ucfirst( $profile['type'] ) ); ?></span>
            </div>

            <?php if ( ! $batches ) : ?>
                <div class="eflr-empty">
                    <h3>No Active Classroom</h3>
                    <p>Your assigned batch classroom will appear here automatically.</p>
                </div>
            <?php else : ?>
                <div class="eflr-grid">
                    <?php foreach ( $batches as $batch ) : ?>
                        <article class="eflr-card">
                            <div class="eflr-card-top">
                                <span class="eflr-status">● LIVE ROOM</span>
                                <span><?php echo esc_html( $batch['canonical_id'] ); ?></span>
                            </div>

                            <h3><?php echo esc_html( $batch['batch_name'] ); ?></h3>

                            <div class="eflr-meta-row"><span>Class Time</span><strong><?php echo esc_html( self::time_label( $batch['start_time'] ) . ' – ' . self::time_label( $batch['end_time'] ) ); ?></strong></div>
                            <div class="eflr-meta-row"><span>Days</span><strong><?php echo esc_html( str_replace( ',', ', ', $batch['days_of_week'] ) ); ?></strong></div>

                            <a class="eflr-join" href="<?php echo esc_url( add_query_arg( 'batch', (int) $batch['id'] ) ); ?>">
                                <?php echo 'teacher' === $profile['type'] ? 'Open YTC Classroom' : 'Join YTC Classroom'; ?>
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

        $roster       = self::roster( $batch_id );
        $room_name    = self::room_name( $batch );
        $display_name = self::display_name( $profile );
        $display_email= self::display_email( $profile );
        $container_id = 'ytc-jitsi-room-' . (int) $batch_id;

        ob_start();
        ?>
        <div class="eduflow-lecture-room eflr-room-mode">
            <div class="eflr-room-head">
                <a class="eflr-back" href="<?php echo esc_url( remove_query_arg( 'batch' ) ); ?>">← Back to Classrooms</a>
                <div class="eflr-room-badges">
                    <span class="eflr-secure">🔒 Batch Access</span>
                    <span class="eflr-status">● LIVE CLASSROOM</span>
                </div>
            </div>

            <div class="eflr-live-panel">
                <div class="eflr-live-title">
                    <div>
                        <span class="eflr-eyebrow">YTC EDUCATION · LIVE CLASSROOM</span>
                        <h2><?php echo esc_html( $batch['batch_name'] ); ?></h2>
                        <p><?php echo esc_html( self::time_label( $batch['start_time'] ) . ' – ' . self::time_label( $batch['end_time'] ) ); ?> · <?php echo esc_html( str_replace( ',', ', ', $batch['days_of_week'] ) ); ?></p>
                    </div>
                    <div class="eflr-user-chip">
                        <span>Signed in as</span>
                        <strong><?php echo esc_html( $display_name ); ?></strong>
                    </div>
                </div>

                <div class="eflr-video-shell">
                    <div class="eflr-video-topbar">
                        <div><strong>YTC Live Class</strong><span> · Secure batch room</span></div>
                        <div class="eflr-live-dot">LIVE</div>
                    </div>
                    <div id="<?php echo esc_attr( $container_id ); ?>" class="eflr-video-wrap"></div>
                    <div id="<?php echo esc_attr( $container_id ); ?>-loading" class="eflr-video-loading">Connecting to YTC classroom…</div>
                </div>
            </div>

            <div class="eflr-bottom-grid">
                <div class="eflr-roster">
                    <h3>Batch Students <span class="eflr-count"><?php echo esc_html( count( $roster ) ); ?></span></h3>
                    <?php if ( ! $roster ) : ?>
                        <p>No active students assigned.</p>
                    <?php else : ?>
                        <div class="eflr-roster-grid">
                            <?php foreach ( $roster as $student ) : ?>
                                <div class="eflr-student">
                                    <span class="eflr-avatar"><?php echo esc_html( strtoupper( substr( $student['name'], 0, 1 ) ) ); ?></span>
                                    <div><strong><?php echo esc_html( $student['name'] ); ?></strong><span><?php echo esc_html( $student['canonical_id'] ); ?></span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="eflr-help-card">
                    <h3>Classroom Controls</h3>
                    <p>Use the controls inside the video room for camera, microphone, screen sharing, chat and participants.</p>
                    <small>Only users authorized for this batch can open this YTC room from EduFlow.</small>
                </div>
            </div>
        </div>

        <script src="https://meet.jit.si/external_api.js"></script>
        <script>
        (function(){
            var target = document.getElementById(<?php echo wp_json_encode( $container_id ); ?>);
            var loading = document.getElementById(<?php echo wp_json_encode( $container_id . '-loading' ); ?>);
            if (!target || typeof JitsiMeetExternalAPI === 'undefined') {
                if (loading) loading.textContent = 'Live classroom could not load. Please refresh the page.';
                return;
            }

            var api = new JitsiMeetExternalAPI('meet.jit.si', {
                roomName: <?php echo wp_json_encode( $room_name ); ?>,
                parentNode: target,
                width: '100%',
                height: '100%',
                userInfo: {
                    displayName: <?php echo wp_json_encode( $display_name ); ?>,
                    email: <?php echo wp_json_encode( $display_email ); ?>
                },
                configOverwrite: {
                    prejoinPageEnabled: false,
                    disableDeepLinking: true,
                    startWithAudioMuted: false,
                    startWithVideoMuted: false,
                    subject: <?php echo wp_json_encode( 'YTC Live Classroom · ' . $batch['batch_name'] ); ?>
                },
                interfaceConfigOverwrite: {
                    MOBILE_APP_PROMO: false,
                    HIDE_INVITE_MORE_HEADER: true,
                    SHOW_JITSI_WATERMARK: false,
                    SHOW_WATERMARK_FOR_GUESTS: false,
                    DEFAULT_REMOTE_DISPLAY_NAME: 'YTC Student'
                }
            });

            api.addEventListener('videoConferenceJoined', function(){
                if (loading) loading.style.display = 'none';
            });
            api.addEventListener('readyToClose', function(){
                window.location.href = <?php echo wp_json_encode( remove_query_arg( 'batch' ) ); ?>;
            });

            setTimeout(function(){
                if (loading) loading.style.display = 'none';
            }, 7000);
        })();
        </script>
        <?php
        self::styles();
        return ob_get_clean();
    }

    private static function styles() {
        ?>
        <style>
            .eduflow-lecture-room{max-width:1220px;margin:26px auto;font-family:Inter,Arial,sans-serif;color:#10213d}
            .eflr-hero,.eflr-live-panel,.eflr-roster,.eflr-help-card{background:#fff;border:1px solid #e5eaf2;border-radius:20px;padding:24px;box-shadow:0 10px 35px rgba(15,23,42,.06)}
            .eflr-hero{display:flex;align-items:center;justify-content:space-between;gap:20px;background:linear-gradient(135deg,#f8fbff 0%,#fff 55%,#f6f2ff 100%)}
            .eflr-hero h2,.eflr-live-panel h2{margin:8px 0 6px;font-size:30px;line-height:1.15;color:#10213d}
            .eflr-hero p,.eflr-live-panel p{margin:0;color:#64748b}
            .eflr-eyebrow{font-size:11px;font-weight:800;letter-spacing:.14em;color:#6d4aff}
            .eflr-role,.eflr-status,.eflr-count,.eflr-secure{display:inline-flex;align-items:center;padding:7px 11px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}
            .eflr-role,.eflr-count{background:#eef2ff;color:#573ee6}
            .eflr-status{background:#ecfdf3;color:#057a55}
            .eflr-secure{background:#eff6ff;color:#1d4ed8}
            .eflr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:16px;margin-top:18px}
            .eflr-card{background:#fff;border:1px solid #e5eaf2;border-radius:18px;padding:20px;box-shadow:0 7px 22px rgba(15,23,42,.045);transition:.2s ease}
            .eflr-card:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(15,23,42,.09)}
            .eflr-card-top{display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#64748b;gap:10px}
            .eflr-card h3{margin:18px 0 14px;font-size:18px;color:#13213c}
            .eflr-meta-row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed #e5eaf2;font-size:13px}
            .eflr-meta-row span{color:#64748b}.eflr-meta-row strong{color:#172554;text-align:right}
            .eflr-join{display:block;margin-top:17px;background:linear-gradient(135deg,#6545e8,#4f46e5);color:#fff!important;text-decoration:none;padding:12px 16px;border-radius:10px;font-weight:800;text-align:center;box-shadow:0 8px 18px rgba(99,70,230,.2)}
            .eflr-room-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px}
            .eflr-room-badges{display:flex;gap:8px;flex-wrap:wrap}
            .eflr-back{text-decoration:none;font-weight:800;color:#4f46e5}
            .eflr-live-panel{padding:20px}
            .eflr-live-title{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:16px}
            .eflr-user-chip{background:#f8fafc;border:1px solid #e5eaf2;border-radius:12px;padding:10px 14px;min-width:180px}
            .eflr-user-chip span{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;font-weight:700}.eflr-user-chip strong{display:block;margin-top:3px;font-size:13px}
            .eflr-video-shell{position:relative;border-radius:18px;overflow:hidden;background:#0b1220;border:1px solid #182235;box-shadow:0 16px 34px rgba(2,6,23,.22)}
            .eflr-video-topbar{height:46px;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:space-between;padding:0 16px;font-size:12px}
            .eflr-video-topbar span{color:#94a3b8}.eflr-live-dot{font-size:10px;font-weight:900;background:#dc2626;color:#fff;padding:5px 8px;border-radius:999px;letter-spacing:.08em}
            .eflr-video-wrap{width:100%;height:690px;background:#0b1220}
            .eflr-video-wrap iframe{width:100%!important;height:100%!important;border:0!important;display:block}
            .eflr-video-loading{position:absolute;inset:46px 0 0;display:flex;align-items:center;justify-content:center;color:#cbd5e1;background:#0b1220;z-index:2;font-weight:700}
            .eflr-bottom-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:16px;margin-top:16px}
            .eflr-roster,.eflr-help-card{margin:0}
            .eflr-roster h3,.eflr-help-card h3{margin:0 0 12px;font-size:17px}
            .eflr-roster-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin-top:12px}
            .eflr-student{border:1px solid #e5eaf2;border-radius:12px;padding:11px;display:flex;align-items:center;gap:10px;background:#fbfdff}
            .eflr-avatar{width:34px;height:34px;border-radius:50%;background:#ede9fe;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-weight:900}
            .eflr-student div{display:flex;flex-direction:column;gap:3px}.eflr-student div span{font-size:11px;color:#64748b}
            .eflr-help-card p{color:#475569;line-height:1.6}.eflr-help-card small{color:#64748b;line-height:1.5;display:block}
            .eflr-empty,.eduflow-lecture-message{max-width:900px;margin:20px auto;padding:22px;border:1px solid #e2e8f0;border-radius:14px;background:#fff}
            .eduflow-lecture-error{border-color:#fecaca;background:#fff7f7;color:#991b1b}
            @media(max-width:820px){.eflr-hero,.eflr-room-head,.eflr-live-title{align-items:flex-start;flex-direction:column}.eflr-bottom-grid{grid-template-columns:1fr}.eflr-user-chip{width:100%}.eflr-video-wrap{height:560px}}
            @media(max-width:520px){.eduflow-lecture-room{margin:14px auto}.eflr-hero,.eflr-live-panel,.eflr-roster,.eflr-help-card{padding:16px;border-radius:15px}.eflr-hero h2,.eflr-live-panel h2{font-size:23px}.eflr-video-wrap{height:500px}.eflr-video-topbar{font-size:11px}}
        </style>
        <?php
    }
}

EduFlow_Lecture_Room::register();
