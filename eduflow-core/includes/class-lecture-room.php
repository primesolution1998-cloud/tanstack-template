<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Lecture_Room {

    public static function register() {
        add_shortcode(
            'eduflow_lecture_room',
            array( __CLASS__, 'shortcode' )
        );
    }

    private static function institute_id() {
        return EduFlow_Settings::institute_db_id();
    }

    private static function current_profile() {
        global $wpdb;

        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'login_required',
                'Please login to access your Lecture Room.'
            );
        }

        $institute_id = self::institute_id();
        $wp_user_id   = get_current_user_id();

        $students = EduFlow_DB::table( 'students' );
        $teachers = EduFlow_DB::table( 'teachers' );

        $student = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM $students
                 WHERE institute_id=%d
                 AND wp_user_id=%d
                 LIMIT 1",
                $institute_id,
                $wp_user_id
            ),
            ARRAY_A
        );

        if ( $student ) {
            return array(
                'type' => 'student',
                'data' => $student,
            );
        }

        $teacher = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM $teachers
                 WHERE institute_id=%d
                 AND wp_user_id=%d
                 LIMIT 1",
                $institute_id,
                $wp_user_id
            ),
            ARRAY_A
        );

        if ( $teacher ) {
            return array(
                'type' => 'teacher',
                'data' => $teacher,
            );
        }

        if ( current_user_can( 'manage_options' ) ) {
            return array(
                'type' => 'admin',
                'data' => array(),
            );
        }

        return new WP_Error(
            'profile_not_linked',
            'Your EduFlow profile is not linked to this WordPress account.'
        );
    }

    private static function student_has_access( $student ) {

        if ( 'active' !== (string) ( $student['status'] ?? '' ) ) {
            return false;
        }

        if (
            in_array(
                sanitize_key( $student['student_status'] ?? '' ),
                array(
                    'dropped',
                    'cancelled',
                    'completed',
                ),
                true
            )
        ) {
            return false;
        }

        if (
            ! empty( $student['access_end'] ) &&
            current_time( 'Y-m-d' ) > $student['access_end']
        ) {
            return false;
        }

        return true;
    }

    private static function upcoming_lectures( $profile ) {
        global $wpdb;

        $institute_id = self::institute_id();
        $classes      = EduFlow_DB::table( 'classes' );
        $batches      = EduFlow_DB::table( 'batches' );
        $today        = current_time( 'Y-m-d' );

        if ( 'student' === $profile['type'] ) {

            $student = $profile['data'];

            if ( empty( $student['batch_id'] ) ) {
                return array();
            }

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        c.*,
                        b.batch_name
                     FROM $classes c
                     LEFT JOIN $batches b
                        ON b.id=c.batch_id
                        AND b.institute_id=c.institute_id
                     WHERE c.institute_id=%d
                     AND c.batch_id=%d
                     AND c.class_date>=%s
                     AND c.class_status IN ('scheduled','rescheduled')
                     ORDER BY c.class_date ASC,c.start_datetime ASC
                     LIMIT 10",
                    $institute_id,
                    (int) $student['batch_id'],
                    $today
                ),
                ARRAY_A
            );
        }

        if ( 'teacher' === $profile['type'] ) {

            $teacher = $profile['data'];

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        c.*,
                        b.batch_name
                     FROM $classes c
                     LEFT JOIN $batches b
                        ON b.id=c.batch_id
                        AND b.institute_id=c.institute_id
                     WHERE c.institute_id=%d
                     AND c.teacher_id=%d
                     AND c.class_date>=%s
                     AND c.class_status IN ('scheduled','rescheduled')
                     ORDER BY c.class_date ASC,c.start_datetime ASC
                     LIMIT 15",
                    $institute_id,
                    (int) $teacher['id'],
                    $today
                ),
                ARRAY_A
            );
        }

        if ( 'admin' === $profile['type'] ) {

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        c.*,
                        b.batch_name
                     FROM $classes c
                     LEFT JOIN $batches b
                        ON b.id=c.batch_id
                        AND b.institute_id=c.institute_id
                     WHERE c.institute_id=%d
                     AND c.class_date>=%s
                     AND c.class_status IN ('scheduled','rescheduled')
                     ORDER BY c.class_date ASC,c.start_datetime ASC
                     LIMIT 25",
                    $institute_id,
                    $today
                ),
                ARRAY_A
            );
        }

        return array();
    }

    private static function lecture_for_profile( $lecture_id, $profile ) {
        global $wpdb;

        $institute_id = self::institute_id();
        $classes      = EduFlow_DB::table( 'classes' );
        $batches      = EduFlow_DB::table( 'batches' );

        $lecture = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    c.*,
                    b.batch_name,
                    b.teacher_id AS batch_teacher_id
                 FROM $classes c
                 LEFT JOIN $batches b
                    ON b.id=c.batch_id
                    AND b.institute_id=c.institute_id
                 WHERE c.id=%d
                 AND c.institute_id=%d
                 LIMIT 1",
                $lecture_id,
                $institute_id
            ),
            ARRAY_A
        );

        if ( ! $lecture ) {
            return new WP_Error(
                'lecture_not_found',
                'Lecture not found.'
            );
        }

        if ( 'cancelled' === $lecture['class_status'] ) {
            return new WP_Error(
                'lecture_cancelled',
                'This lecture has been cancelled.'
            );
        }

        if ( 'admin' === $profile['type'] ) {
            return $lecture;
        }

        if ( 'student' === $profile['type'] ) {

            $student = $profile['data'];

            if (
                (int) $student['batch_id'] !==
                (int) $lecture['batch_id']
            ) {
                return new WP_Error(
                    'lecture_access_denied',
                    'This lecture does not belong to your batch.'
                );
            }

            return $lecture;
        }

        if ( 'teacher' === $profile['type'] ) {

            $teacher = $profile['data'];

            if (
                (int) $teacher['id'] !== (int) $lecture['teacher_id'] &&
                (int) $teacher['id'] !== (int) $lecture['batch_teacher_id']
            ) {
                return new WP_Error(
                    'lecture_access_denied',
                    'This lecture is not assigned to you.'
                );
            }

            return $lecture;
        }

        return new WP_Error(
            'lecture_access_denied',
            'Lecture access denied.'
        );
    }

    private static function message( $text, $type = 'info' ) {
        return sprintf(
            '<div class="eduflow-lecture-message eduflow-lecture-%1$s">%2$s</div>',
            esc_attr( $type ),
            esc_html( $text )
        );
    }

    public static function shortcode() {

        $profile = self::current_profile();

        if ( is_wp_error( $profile ) ) {
            return self::message(
                $profile->get_error_message(),
                'error'
            );
        }

        if (
            'student' === $profile['type'] &&
            ! self::student_has_access( $profile['data'] )
        ) {
            return self::message(
                'Your Lecture Room access is currently inactive.',
                'error'
            );
        }

        $lecture_id = absint(
            $_GET['lecture'] ?? 0
        );

        if ( $lecture_id ) {
            return self::room_view(
                $lecture_id,
                $profile
            );
        }

        return self::dashboard_view(
            $profile
        );
    }

    private static function dashboard_view( $profile ) {

        $lectures = self::upcoming_lectures(
            $profile
        );

        ob_start();
        ?>

        <div class="eduflow-lecture-room">

            <div class="eflr-hero">

                <div>
                    <span class="eflr-eyebrow">
                        YTC ENGLISH SPEAKING
                    </span>

                    <h2>My Lecture Room</h2>

                    <p>
                        Your authorized live classes and upcoming lectures.
                    </p>
                </div>

                <span class="eflr-role">
                    <?php echo esc_html(
                        ucfirst(
                            $profile['type']
                        )
                    ); ?>
                </span>

            </div>

            <?php if ( ! $lectures ) : ?>

                <div class="eflr-empty">
                    <h3>No Upcoming Lecture</h3>

                    <p>
                        Your next scheduled class will appear here automatically.
                    </p>
                </div>

            <?php else : ?>

                <div class="eflr-grid">

                    <?php foreach ( $lectures as $lecture ) : ?>

                        <article class="eflr-card">

                            <div class="eflr-card-top">

                                <span class="eflr-status">
                                    <?php echo esc_html(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $lecture['class_status']
                                            )
                                        )
                                    ); ?>
                                </span>

                                <span>
                                    <?php echo esc_html(
                                        $lecture['class_date']
                                    ); ?>
                                </span>

                            </div>

                            <h3>
                                <?php echo esc_html(
                                    $lecture['batch_name']
                                    ?: $lecture['canonical_id']
                                ); ?>
                            </h3>

                            <p>
                                <strong>Time:</strong>

                                <?php echo esc_html(
                                    date_i18n(
                                        'g:i A',
                                        strtotime($lecture['class_date'].' '.$lecture['start_datetime'].' UTC')
                                    )
                                    .
                                    ' – ' .
                                    date_i18n(
                                        'g:i A',
                                        strtotime($lecture['class_date'].' '.$lecture['end_datetime'].' UTC')
                                    )
                                ); ?>
                            </p>

                            <a class="eflr-join"
                               href="<?php echo esc_url(
                                   add_query_arg(
                                       'lecture',
                                       (int) $lecture['id']
                                   )
                               ); ?>">

                                <?php
                                echo 'teacher' === $profile['type']
                                    ? 'Open Lecture Room'
                                    : 'Join Lecture Room';
                                ?>

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

    private static function roster( $batch_id ) {
        global $wpdb;

        $institute_id = self::institute_id();

        $assignments =
            EduFlow_DB::table(
                'batch_students'
            );

        $students =
            EduFlow_DB::table(
                'students'
            );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    s.canonical_id,
                    s.name,
                    s.student_status
                 FROM $assignments a
                 INNER JOIN $students s
                    ON s.id=a.student_id
                    AND s.institute_id=a.institute_id
                 WHERE a.institute_id=%d
                 AND a.batch_id=%d
                 AND a.assignment_status='active'
                 AND s.status='active'
                 AND s.student_status NOT IN ('dropped','cancelled','completed')
                 ORDER BY s.name ASC",
                $institute_id,
                $batch_id
            ),
            ARRAY_A
        );
    }

    private static function room_view(
        $lecture_id,
        $profile
    ) {

        $lecture =
            self::lecture_for_profile(
                $lecture_id,
                $profile
            );

        if ( is_wp_error( $lecture ) ) {
            return self::message(
                $lecture->get_error_message(),
                'error'
            );
        }

        $roster = self::roster(
            (int) $lecture['batch_id']
        );

        ob_start();
        ?>

        <div class="eduflow-lecture-room">

            <div class="eflr-room-head">

                <a class="eflr-back"
                   href="<?php echo esc_url(
                       remove_query_arg(
                           'lecture'
                       )
                   ); ?>">
                    ← Back to My Classes
                </a>

                <span class="eflr-status">
                    <?php echo esc_html(
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $lecture['class_status']
                            )
                        )
                    ); ?>
                </span>

            </div>

            <div class="eflr-live-panel">

                <span class="eflr-eyebrow">
                    YTC LIVE CLASSROOM
                </span>

                <h2>
                    <?php echo esc_html(
                        $lecture['batch_name']
                        ?: 'Lecture Room'
                    ); ?>
                </h2>

                <p>
                    <?php echo esc_html(
                        $lecture['class_date']
                    ); ?>
                    ·
                    <?php echo esc_html(
                        date_i18n(
                            'g:i A',
                            strtotime(
                                $lecture['start_datetime']
                            )
                        )
                    ); ?>
                    –
                    <?php echo esc_html(
                        date_i18n(
                            'g:i A',
                            strtotime(
                                $lecture['end_datetime']
                            )
                        )
                    ); ?>
                </p>

                <div class="eflr-video-placeholder">

                    <div>

                        <h3>
                            Secure Lecture Room Ready
                        </h3>

                        <p>
                            Student/Teacher access is verified.
                        </p>

                        <?php
                        $room = class_exists('EduFlow_Batch_Room_Service')
                            ? EduFlow_Batch_Room_Service::get_room((int)$lecture['batch_id'])
                            : null;

                        $meet = $room
                            ? ($room['google_meet_url'] ?: $room['meet_url'])
                            : ($lecture['meet_url'] ?? '');
                        ?>

                        <?php if ($meet): ?>
                            <a class="eflr-join"
                               target="_blank"
                               rel="noopener noreferrer"
                               href="<?php echo esc_url($meet); ?>">
                                Join Google Meet
                            </a>
                        <?php else: ?>
                            <strong>
                                Google Meet is being prepared for this batch.
                            </strong>
                        <?php endif; ?>

                    </div>

                </div>

            </div>

            <?php if (
                in_array(
                    $profile['type'],
                    array(
                        'teacher',
                        'admin'
                    ),
                    true
                )
            ) : ?>

                <div class="eflr-roster">

                    <h3>
                        Batch Students
                        <span>
                            <?php echo esc_html(
                                count(
                                    $roster
                                )
                            ); ?>
                        </span>
                    </h3>

                    <div class="eflr-students">

                        <?php foreach (
                            $roster
                            as $student
                        ) : ?>

                            <div>

                                <strong>
                                    <?php echo esc_html(
                                        $student['name']
                                    ); ?>
                                </strong>

                                <small>
                                    <?php echo esc_html(
                                        $student['canonical_id']
                                    ); ?>
                                </small>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endif; ?>

        </div>

        <?php

        self::styles();

        return ob_get_clean();
    }

    private static function styles() {
        static $done = false;

        if ( $done ) {
            return;
        }

        $done = true;
        ?>

        <style>

        .eduflow-lecture-room{
            max-width:1180px;
            margin:30px auto;
            padding:0 15px;
            font-family:Arial,sans-serif;
            color:#172033;
        }

        .eflr-hero,
        .eflr-live-panel,
        .eflr-roster,
        .eflr-empty,
        .eduflow-lecture-message{
            background:#fff;
            border:1px solid #e1e7ef;
            border-radius:18px;
            padding:24px;
            box-shadow:0 10px 35px rgba(15,23,42,.06);
        }

        .eflr-hero{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
            margin-bottom:20px;
        }

        .eflr-hero h2,
        .eflr-live-panel h2{
            margin:8px 0;
            font-size:30px;
        }

        .eflr-eyebrow{
            font-size:12px;
            font-weight:700;
            letter-spacing:.12em;
            color:#5b5bd6;
        }

        .eflr-role,
        .eflr-status{
            display:inline-flex;
            align-items:center;
            padding:7px 12px;
            border-radius:999px;
            background:#eef2ff;
            color:#3730a3;
            font-size:12px;
            font-weight:700;
        }

        .eflr-grid{
            display:grid;
            grid-template-columns:repeat(
                auto-fit,
                minmax(260px,1fr)
            );
            gap:16px;
        }

        .eflr-card{
            background:#fff;
            border:1px solid #e1e7ef;
            border-radius:16px;
            padding:20px;
            box-shadow:0 8px 25px rgba(15,23,42,.04);
        }

        .eflr-card-top,
        .eflr-room-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
        }

        .eflr-card h3{
            margin:16px 0 8px;
            font-size:20px;
        }

        .eflr-join{
            display:inline-flex;
            margin-top:12px;
            padding:11px 16px;
            border-radius:9px;
            background:#5b5bd6;
            color:#fff !important;
            text-decoration:none !important;
            font-weight:700;
        }

        .eflr-room-head{
            margin-bottom:15px;
        }

        .eflr-back{
            text-decoration:none !important;
            font-weight:700;
        }

        .eflr-live-panel{
            margin-top:14px;
        }

        .eflr-video-placeholder{
            min-height:360px;
            margin-top:20px;
            padding:30px;
            border-radius:16px;
            background:#101522;
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            text-align:center;
        }

        .eflr-video-placeholder h3{
            font-size:26px;
            margin-bottom:8px;
        }

        .eflr-roster{
            margin-top:18px;
        }

        .eflr-roster h3{
            display:flex;
            align-items:center;
            justify-content:space-between;
        }

        .eflr-students{
            display:grid;
            grid-template-columns:repeat(
                auto-fit,
                minmax(180px,1fr)
            );
            gap:10px;
            margin-top:15px;
        }

        .eflr-students div{
            padding:12px;
            border-radius:10px;
            background:#f7f9fc;
            border:1px solid #edf0f5;
        }

        .eflr-students small{
            display:block;
            margin-top:4px;
            color:#667085;
        }

        .eduflow-lecture-message{
            max-width:900px;
            margin:30px auto;
        }

        .eduflow-lecture-error{
            background:#fff5f5;
            border-color:#efb5b5;
            color:#991b1b;
        }

        .eduflow-lecture-success{
            background:#f0fdf4;
            border-color:#bbf7d0;
            color:#166534;
        }

        @media(max-width:700px){

            .eflr-hero{
                align-items:flex-start;
                flex-direction:column;
            }

            .eflr-hero h2,
            .eflr-live-panel h2{
                font-size:24px;
            }

            .eflr-video-placeholder{
                min-height:280px;
            }
        }

        </style>

        <?php
    }
}

EduFlow_Lecture_Room::register();
