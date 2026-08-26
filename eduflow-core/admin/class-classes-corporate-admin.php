<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Classes_Corporate_Admin {
    public function register() {
        add_action( 'admin_menu', array( $this, 'replace_menu' ), 999 );
        add_action( 'admin_post_eduflow_classes_bulk_retry', array( $this, 'bulk_retry' ) );
        add_action( 'admin_post_eduflow_class_mark_completed', array( $this, 'mark_completed' ) );
        add_action( 'admin_post_eduflow_class_manual_schedule', array( $this, 'manual_schedule' ) );
    }

    public function replace_menu() {
        remove_submenu_page( 'eduflow', 'eduflow-classes' );
        add_submenu_page(
            'eduflow',
            'Classes & Lectures',
            'Classes',
            'eduflow_manage_classes',
            'eduflow-classes',
            array( __CLASS__, 'page' )
        );
    }

    private static function guard() {
        if ( ! current_user_can( 'eduflow_manage_classes' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage classes.', 'eduflow-core' ) );
        }
    }

    private static function notice() {
        if ( empty( $_GET['eduflow_notice'] ) ) {
            return;
        }
        $type = 'error' === ( $_GET['notice_type'] ?? '' ) ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['eduflow_notice'] ) ) ) . '</p></div>';
    }

    private static function redirect( $message, $error = false ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'           => 'eduflow-classes',
                    'eduflow_notice' => rawurlencode( $message ),
                    'notice_type'    => $error ? 'error' : 'success',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    private static function local_parts( $row ) {
        try {
            $tz = new DateTimeZone( $row['timezone'] ?: 'UTC' );
            $start = ( new DateTimeImmutable( $row['start_datetime'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
            $end   = ( new DateTimeImmutable( $row['end_datetime'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
            return array(
                'date' => $start->format( 'd M Y' ),
                'time' => $start->format( 'g:i A' ) . ' – ' . $end->format( 'g:i A' ),
            );
        } catch ( Throwable $e ) {
            return array( 'date' => $row['class_date'], 'time' => $row['start_datetime'] . ' – ' . $row['end_datetime'] );
        }
    }

    private static function badge( $value, $kind = 'status' ) {
        $value = sanitize_key( (string) $value );
        $label = ucwords( str_replace( '_', ' ', $value ?: 'not_required' ) );
        return '<span class="efc-badge efc-' . esc_attr( $kind ) . '-' . esc_attr( $value ?: 'not_required' ) . '">' . esc_html( $label ) . '</span>';
    }

    public static function page() {
        self::guard();
        global $wpdb;

        $institute = EduFlow_Settings::institute_db_id();
        $classes = EduFlow_DB::table( 'classes' );
        $batches = EduFlow_DB::table( 'batches' );
        $teachers = EduFlow_DB::table( 'teachers' );
        $batch_students = EduFlow_DB::table( 'batch_students' );
        $google = EduFlow_DB::table( 'google_events' );

        $search       = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        $date_filter  = sanitize_text_field( wp_unslash( $_GET['class_date'] ?? '' ) );
        $batch_filter = absint( $_GET['batch_id'] ?? 0 );
        $teacher_filter = absint( $_GET['teacher_id'] ?? 0 );
        $status_filter = sanitize_key( $_GET['status'] ?? '' );
        $sync_filter   = sanitize_key( $_GET['sync_status'] ?? '' );
        $quick         = sanitize_key( $_GET['quick'] ?? 'upcoming' );
        $page          = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per           = absint( $_GET['per_page'] ?? 20 );
        if ( ! in_array( $per, array( 20, 50, 100 ), true ) ) {
            $per = 20;
        }

        $where = array( 'c.institute_id=%d' );
        $params = array( $institute );

        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(c.canonical_id LIKE %s OR b.batch_name LIKE %s OR b.canonical_id LIKE %s OR t.name LIKE %s)';
            array_push( $params, $like, $like, $like, $like );
        }
        if ( $date_filter ) {
            $where[] = 'c.class_date=%s';
            $params[] = $date_filter;
        }
        if ( $batch_filter ) {
            $where[] = 'c.batch_id=%d';
            $params[] = $batch_filter;
        }
        if ( $teacher_filter ) {
            $where[] = 'c.teacher_id=%d';
            $params[] = $teacher_filter;
        }
        if ( in_array( $status_filter, EduFlow_Class_Service::STATUSES, true ) ) {
            $where[] = 'c.class_status=%s';
            $params[] = $status_filter;
        }
        if ( in_array( $sync_filter, EduFlow_Google_Service::STATUSES, true ) ) {
            if ( 'not_required' === $sync_filter ) {
                $where[] = '(g.sync_status IS NULL OR g.sync_status=%s)';
                $params[] = 'not_required';
            } else {
                $where[] = 'g.sync_status=%s';
                $params[] = $sync_filter;
            }
        }

        $today = current_time( 'Y-m-d' );
        $tomorrow = wp_date( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS );
        $week_end = wp_date( 'Y-m-d', current_time( 'timestamp' ) + 6 * DAY_IN_SECONDS );
        $now_utc = current_time( 'mysql', true );

        if ( 'today' === $quick ) {
            $where[] = 'c.class_date=%s';
            $params[] = $today;
        } elseif ( 'tomorrow' === $quick ) {
            $where[] = 'c.class_date=%s';
            $params[] = $tomorrow;
        } elseif ( 'this_week' === $quick ) {
            $where[] = 'c.class_date BETWEEN %s AND %s';
            array_push( $params, $today, $week_end );
        } elseif ( 'failed_sync' === $quick ) {
            $where[] = 'g.sync_status=%s';
            $params[] = 'failed';
        } elseif ( 'live' === $quick ) {
            $where[] = "c.class_status IN ('scheduled','rescheduled') AND c.start_datetime<=%s AND c.end_datetime>=%s";
            array_push( $params, $now_utc, $now_utc );
        } elseif ( 'completed' === $quick ) {
            $where[] = 'c.class_status=%s';
            $params[] = 'completed';
        } elseif ( 'upcoming' === $quick ) {
            $where[] = "c.class_status IN ('scheduled','rescheduled') AND c.end_datetime>=%s";
            $params[] = $now_utc;
        }

        $student_counts = "SELECT institute_id,batch_id,COUNT(*) student_count FROM $batch_students WHERE assignment_status='active' GROUP BY institute_id,batch_id";
        $base = " FROM $classes c LEFT JOIN $batches b ON b.id=c.batch_id AND b.institute_id=c.institute_id LEFT JOIN $teachers t ON t.id=c.teacher_id AND t.institute_id=c.institute_id LEFT JOIN ($student_counts) sc ON sc.institute_id=c.institute_id AND sc.batch_id=c.batch_id LEFT JOIN $google g ON g.lecture_id=c.id AND g.institute_id=c.institute_id WHERE " . implode( ' AND ', $where );

        $total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $base, ...$params ) );
        $query_params = $params;
        $query_params[] = $per;
        $query_params[] = ( $page - 1 ) * $per;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.*,b.batch_name,b.canonical_id batch_code,t.name teacher_name,COALESCE(sc.student_count,0) student_count,g.sync_status google_sync_status,g.google_meet_url,g.google_event_url,g.last_error google_last_error,g.last_synced_at' . $base . ' ORDER BY c.start_datetime ASC LIMIT %d OFFSET %d',
                ...$query_params
            ),
            ARRAY_A
        );

        $summary = array(
            'today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $classes WHERE institute_id=%d AND class_date=%s", $institute, $today ) ),
            'upcoming' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $classes WHERE institute_id=%d AND class_status IN ('scheduled','rescheduled') AND end_datetime>=%s", $institute, $now_utc ) ),
            'live' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $classes WHERE institute_id=%d AND class_status IN ('scheduled','rescheduled') AND start_datetime<=%s AND end_datetime>=%s", $institute, $now_utc, $now_utc ) ),
            'completed' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $classes WHERE institute_id=%d AND class_status='completed'", $institute ) ),
            'failed_sync' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $google WHERE institute_id=%d AND sync_status='failed'", $institute ) ),
        );

        $batch_options = $wpdb->get_results( $wpdb->prepare( "SELECT id,batch_name,canonical_id,teacher_id,start_time,end_time,timezone FROM $batches WHERE institute_id=%d AND batch_status='active' ORDER BY batch_name ASC", $institute ), ARRAY_A );
        $teacher_options = $wpdb->get_results( $wpdb->prepare( "SELECT id,name,canonical_id FROM $teachers WHERE institute_id=%d AND teacher_status='active' ORDER BY name ASC", $institute ), ARRAY_A );

        self::notice();
        ?>
        <div class="wrap eduflow-wrap efc-wrap">
            <div class="efc-header">
                <div>
                    <h1>Classes &amp; Lectures</h1>
                    <p>Manage scheduled, live, completed and Google-synced classes from one place.</p>
                </div>
                <div class="efc-primary-actions">
                    <a class="button button-primary" href="#class-form">+ Schedule Class</a>
                    <a class="button" href="#generate-schedule">Generate Schedule</a>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="eduflow_classes_bulk_retry">
                        <?php wp_nonce_field( 'eduflow_classes_bulk_retry' ); ?>
                        <button class="button" type="submit" <?php disabled( 0 === $summary['failed_sync'] ); ?>>Retry Failed Sync (<?php echo esc_html( $summary['failed_sync'] ); ?>)</button>
                    </form>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=eduflow-classes' ) ); ?>">Refresh</a>
                </div>
            </div>

            <div class="efc-summary">
                <?php
                $cards = array( 'today'=>'Today', 'upcoming'=>'Upcoming', 'live'=>'Live', 'completed'=>'Completed', 'failed_sync'=>'Google Sync Issues' );
                foreach ( $cards as $key => $label ) :
                    $url = add_query_arg( array( 'page'=>'eduflow-classes', 'quick'=>$key ), admin_url( 'admin.php' ) );
                ?>
                    <a class="efc-card <?php echo $quick === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
                        <strong><?php echo esc_html( $summary[ $key ] ); ?></strong>
                        <span><?php echo esc_html( $label ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="get" class="efc-filters">
                <input type="hidden" name="page" value="eduflow-classes">
                <input type="hidden" name="quick" value="<?php echo esc_attr( $quick ); ?>">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Lecture, batch or teacher">
                <input type="date" name="class_date" value="<?php echo esc_attr( $date_filter ); ?>">
                <select name="batch_id"><option value="0">All batches</option><?php foreach ( $batch_options as $b ) : ?><option value="<?php echo esc_attr( $b['id'] ); ?>" <?php selected( $batch_filter, $b['id'] ); ?>><?php echo esc_html( $b['batch_name'] . ' · ' . $b['canonical_id'] ); ?></option><?php endforeach; ?></select>
                <select name="teacher_id"><option value="0">All teachers</option><?php foreach ( $teacher_options as $t ) : ?><option value="<?php echo esc_attr( $t['id'] ); ?>" <?php selected( $teacher_filter, $t['id'] ); ?>><?php echo esc_html( $t['name'] ); ?></option><?php endforeach; ?></select>
                <select name="status"><option value="">All class statuses</option><?php foreach ( EduFlow_Class_Service::STATUSES as $v ) : ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $status_filter, $v ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $v ) ) ); ?></option><?php endforeach; ?></select>
                <select name="sync_status"><option value="">All sync statuses</option><?php foreach ( EduFlow_Google_Service::STATUSES as $v ) : ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $sync_filter, $v ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $v ) ) ); ?></option><?php endforeach; ?></select>
                <select name="per_page"><?php foreach ( array( 20, 50, 100 ) as $v ) : ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $per, $v ); ?>><?php echo esc_html( $v ); ?> / page</option><?php endforeach; ?></select>
                <button class="button button-primary" type="submit">Filter</button>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=eduflow-classes' ) ); ?>">Reset</a>
            </form>

            <div class="efc-quick-links">
                <?php foreach ( array( 'today'=>'Today','tomorrow'=>'Tomorrow','this_week'=>'This Week','upcoming'=>'Upcoming','live'=>'Live','failed_sync'=>'Failed Sync','completed'=>'Completed' ) as $key=>$label ) : ?>
                    <a class="<?php echo $quick === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page'=>'eduflow-classes','quick'=>$key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </div>

            <div class="efc-table-wrap">
                <table class="widefat striped efc-table">
                    <thead><tr><th>Lecture ID</th><th>Batch</th><th>Teacher</th><th>Students</th><th>Date</th><th>Time</th><th>Status</th><th>Google Sync</th><th>Meet</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if ( ! $rows ) : ?>
                        <tr><td colspan="10"><div class="efc-empty"><strong>No classes found.</strong><span>Try another filter or generate the next schedule.</span></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ( $rows as $row ) :
                        $parts = self::local_parts( $row );
                        $display_status = $row['class_status'];
                        if ( in_array( $display_status, array( 'scheduled', 'rescheduled' ), true ) && $row['start_datetime'] <= $now_utc && $row['end_datetime'] >= $now_utc ) {
                            $display_status = 'live';
                        }
                        $sync_status = $row['google_sync_status'] ?: 'not_required';
                        $meet = $row['google_meet_url'] ?: $row['meet_url'];
                        $student_count = (int) $row['student_count'];
                        if ( $row['student_id'] && $student_count < 1 ) { $student_count = 1; }
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html( $row['canonical_id'] ); ?></strong></td>
                            <td><strong><?php echo esc_html( $row['batch_name'] ?: 'Direct class' ); ?></strong><?php if ( $row['batch_code'] ) : ?><small><?php echo esc_html( $row['batch_code'] ); ?></small><?php endif; ?></td>
                            <td><?php echo esc_html( $row['teacher_name'] ?: 'Unassigned' ); ?></td>
                            <td><span class="efc-count"><?php echo esc_html( $student_count ); ?></span></td>
                            <td><?php echo esc_html( $parts['date'] ); ?><small><?php echo esc_html( $row['timezone'] ); ?></small></td>
                            <td><strong><?php echo esc_html( $parts['time'] ); ?></strong></td>
                            <td><?php echo wp_kses_post( self::badge( $display_status, 'status' ) ); ?></td>
                            <td><?php echo wp_kses_post( self::badge( $sync_status, 'sync' ) ); ?><?php if ( 'failed' === $sync_status && $row['google_last_error'] ) : ?><details class="efc-error"><summary>View error</summary><div><?php echo esc_html( $row['google_last_error'] ); ?></div></details><?php elseif ( $row['last_synced_at'] ) : ?><small>Last sync <?php echo esc_html( $row['last_synced_at'] ); ?></small><?php endif; ?></td>
                            <td>
                                <?php if ( $meet ) : ?><a class="button button-small button-primary" href="<?php echo esc_url( $meet ); ?>" target="_blank" rel="noopener noreferrer">Join Meet</a>
                                <?php elseif ( 'pending' === $sync_status ) : ?><span class="efc-muted">Generating…</span>
                                <?php elseif ( 'failed' === $sync_status ) : ?><a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eduflow_google_sync&record=' . $row['id'] ), 'eduflow_google_sync_' . $row['id'] ) ); ?>">Retry</a>
                                <?php else : ?><span class="efc-muted"><?php echo 'cancelled' === $row['class_status'] ? 'Cancelled' : ( 'completed' === $row['class_status'] ? 'Completed' : 'Not generated' ); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <details class="efc-actions"><summary class="button button-small">Actions</summary><div>
                                    <a href="<?php echo esc_url( add_query_arg( array( 'page'=>'eduflow-classes','view'=>$row['id'], 'quick'=>$quick ), admin_url( 'admin.php' ) ) ); ?>#class-details">View Details</a>
                                    <?php if ( 'completed' !== $row['class_status'] && 'cancelled' !== $row['class_status'] ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'page'=>'eduflow-classes','edit'=>$row['id'], 'quick'=>$quick ), admin_url( 'admin.php' ) ) ); ?>#class-form">Reschedule</a><?php endif; ?>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eduflow_google_sync&record=' . $row['id'] ), 'eduflow_google_sync_' . $row['id'] ) ); ?>">Retry Google Sync</a>
                                    <?php if ( 'cancelled' !== $row['class_status'] && 'completed' !== $row['class_status'] ) : ?><a class="efc-danger" onclick="return confirm('Cancel this class? The linked Google event will also be cancelled safely.');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eduflow_class_action&record=' . $row['id'] . '&do=cancel' ), 'eduflow_class_action_' . $row['id'] ) ); ?>">Cancel Class</a><?php endif; ?>
                                    <?php if ( 'completed' !== $row['class_status'] && 'cancelled' !== $row['class_status'] ) : ?><a onclick="return confirm('Mark this class completed?');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eduflow_class_mark_completed&record=' . $row['id'] ), 'eduflow_class_mark_completed_' . $row['id'] ) ); ?>">Mark Completed</a><?php endif; ?>
                                    <a href="<?php echo esc_url( add_query_arg( array( 'page'=>'eduflow-attendance','class_id'=>$row['id'] ), admin_url( 'admin.php' ) ) ); ?>">Attendance</a>
                                    <?php if ( $meet ) : ?><button type="button" class="efc-copy" data-copy="<?php echo esc_attr( $meet ); ?>">Copy Meet Link</button><?php endif; ?>
                                    <?php if ( $row['google_event_url'] ) : ?><a href="<?php echo esc_url( $row['google_event_url'] ); ?>" target="_blank" rel="noopener noreferrer">Open Calendar Event</a><?php endif; ?>
                                </div></details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $pages = max( 1, (int) ceil( $total / $per ) );
            echo '<div class="efc-pagination">' . wp_kses_post( paginate_links( array( 'base'=>add_query_arg( 'paged','%#%' ), 'current'=>$page, 'total'=>$pages ) ) ) . '<span>' . esc_html( $total ) . ' classes</span></div>';
            self::details_card( $institute );
            self::class_form( $institute, $batch_options, $teacher_options );
            self::generate_card( $batch_options );
            ?>
        </div>

        <style>
        .efc-wrap{max-width:1480px}.efc-header{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin:18px 0}.efc-header h1{margin:0 0 6px}.efc-header p{margin:0;color:#646970}.efc-primary-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.efc-primary-actions form{margin:0}.efc-summary{display:grid;grid-template-columns:repeat(5,minmax(140px,1fr));gap:12px;margin:18px 0}.efc-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;text-decoration:none;color:#1d2327;box-shadow:0 1px 2px rgba(0,0,0,.03)}.efc-card:hover,.efc-card.is-active{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}.efc-card strong{display:block;font-size:26px;line-height:1.1}.efc-card span{display:block;margin-top:5px;color:#646970}.efc-filters{display:flex;gap:8px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;padding:12px;border-radius:8px}.efc-filters input,.efc-filters select{max-width:220px}.efc-quick-links{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.efc-quick-links a{background:#fff;border:1px solid #c3c4c7;border-radius:999px;padding:5px 11px;text-decoration:none}.efc-quick-links a.is-active{background:#2271b1;color:#fff;border-color:#2271b1}.efc-table-wrap{overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:8px}.efc-table{border:0}.efc-table th{white-space:nowrap}.efc-table td{vertical-align:middle}.efc-table small{display:block;color:#646970;margin-top:4px}.efc-count{display:inline-flex;min-width:28px;height:28px;align-items:center;justify-content:center;border-radius:50%;background:#f0f0f1;font-weight:600}.efc-badge{display:inline-block;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:600;background:#f0f0f1}.efc-status-live,.efc-sync-synced{background:#dff6dd;color:#135e13}.efc-status-scheduled,.efc-status-rescheduled,.efc-sync-pending{background:#e5f5fa;color:#0a4b78}.efc-status-completed{background:#e2e4e7;color:#2c3338}.efc-status-cancelled,.efc-status-missed,.efc-sync-failed{background:#fcf0f1;color:#8a2424}.efc-error summary{cursor:pointer;color:#b32d2e;font-size:12px;margin-top:5px}.efc-error div{max-width:300px;background:#fff5f5;border:1px solid #f0c2c4;padding:7px;margin-top:5px;border-radius:5px}.efc-actions{position:relative}.efc-actions>summary{list-style:none;cursor:pointer}.efc-actions>div{position:absolute;right:0;z-index:50;min-width:190px;background:#fff;border:1px solid #c3c4c7;box-shadow:0 4px 16px rgba(0,0,0,.16);border-radius:6px;padding:6px}.efc-actions a,.efc-actions button{display:block;width:100%;box-sizing:border-box;text-align:left;padding:7px 9px;border:0;background:none;text-decoration:none;color:#2271b1;cursor:pointer;font:inherit}.efc-actions a:hover,.efc-actions button:hover{background:#f0f0f1}.efc-actions .efc-danger{color:#b32d2e}.efc-muted{color:#646970}.efc-empty{padding:36px;text-align:center}.efc-empty strong,.efc-empty span{display:block}.efc-empty span{margin-top:5px;color:#646970}.efc-pagination{display:flex;gap:12px;align-items:center;justify-content:flex-end;margin:14px 0}.efc-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:18px 0}.efc-form-grid{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:14px}.efc-form-grid label{display:flex;flex-direction:column;gap:5px;font-weight:600}.efc-form-grid input,.efc-form-grid select{width:100%}.efc-details-grid{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:12px}.efc-detail{background:#f6f7f7;border-radius:6px;padding:10px}.efc-detail strong,.efc-detail span{display:block}.efc-detail span{margin-top:4px;color:#50575e;word-break:break-word}
        @media(max-width:1100px){.efc-summary{grid-template-columns:repeat(2,1fr)}.efc-form-grid,.efc-details-grid{grid-template-columns:repeat(2,1fr)}.efc-header{display:block}.efc-primary-actions{margin-top:12px}}
        @media(max-width:680px){.efc-summary,.efc-form-grid,.efc-details-grid{grid-template-columns:1fr}.efc-filters>*{width:100%;max-width:none!important}.efc-table{min-width:1050px}}
        </style>
        <script>
        document.addEventListener('click',function(e){if(e.target.classList.contains('efc-copy')){var v=e.target.getAttribute('data-copy');if(navigator.clipboard){navigator.clipboard.writeText(v).then(function(){e.target.textContent='Copied';setTimeout(function(){e.target.textContent='Copy Meet Link';},1500);});}}});
        </script>
        <?php
    }

    private static function details_card( $institute ) {
        $id = absint( $_GET['view'] ?? 0 );
        if ( ! $id ) { return; }
        global $wpdb;
        $c = EduFlow_Class_Service::get( $id, $institute );
        if ( ! $c ) { return; }
        $batch = $c['batch_id'] ? EduFlow_Batch_Service::get( $c['batch_id'], $institute ) : array();
        $teacher = EduFlow_Teacher_Service::get( $c['teacher_id'], $institute );
        $gtable = EduFlow_DB::table( 'google_events' );
        $g = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $gtable WHERE institute_id=%d AND lecture_id=%d", $institute, $id ), ARRAY_A );
        $parts = self::local_parts( $c );
        $items = array(
            'Lecture ID' => $c['canonical_id'], 'Batch' => $batch ? $batch['batch_name'] : 'Direct class', 'Teacher' => $teacher ? $teacher['name'] : 'Unassigned',
            'Date' => $parts['date'], 'Time' => $parts['time'], 'Timezone' => $c['timezone'], 'Status' => $c['class_status'],
            'Google Sync' => $g ? $g['sync_status'] : 'not_required', 'Last Sync' => $g ? ( $g['last_synced_at'] ?: 'Never' ) : 'Never',
            'Meet Link' => $g && $g['google_meet_url'] ? $g['google_meet_url'] : ( $c['meet_url'] ?: 'Not generated' ),
            'Calendar Event' => $g && $g['google_event_url'] ? $g['google_event_url'] : 'Not available', 'Last Error' => $g && $g['last_error'] ? $g['last_error'] : 'None',
        );
        echo '<div id="class-details" class="efc-panel"><h2>Class Details</h2><div class="efc-details-grid">';
        foreach ( $items as $label=>$value ) { echo '<div class="efc-detail"><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $value ) . '</span></div>'; }
        echo '</div></div>';
    }

    private static function class_form( $institute, $batches, $teachers ) {
        $id = absint( $_GET['edit'] ?? 0 );
        $row = $id ? EduFlow_Class_Service::get( $id, $institute ) : array();
        $batch_id = $row ? (int) $row['batch_id'] : 0;
        $batch = $batch_id ? EduFlow_Batch_Service::get( $batch_id, $institute ) : array();
        $teacher_id = $row ? (int) $row['teacher_id'] : ( $batch ? (int) $batch['teacher_id'] : 0 );
        $date = $row ? $row['class_date'] : current_time( 'Y-m-d' );
        $start = $batch ? substr( $batch['start_time'], 0, 5 ) : '';
        $end = $batch ? substr( $batch['end_time'], 0, 5 ) : '';
        if ( $row ) {
            try {
                $tz = new DateTimeZone( $row['timezone'] );
                $start = ( new DateTimeImmutable( $row['start_datetime'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'H:i' );
                $end = ( new DateTimeImmutable( $row['end_datetime'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'H:i' );
            } catch ( Throwable $e ) {}
        }
        ?>
        <div id="class-form" class="efc-panel">
            <h2><?php echo $id ? 'Reschedule Class' : 'Schedule Class'; ?></h2>
            <p class="description">Use real batch and teacher names. The same batch occurrence creates one lecture, one Calendar event and one shared Meet link.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php if ( $id ) : ?>
                    <input type="hidden" name="action" value="eduflow_class_action"><input type="hidden" name="do" value="reschedule"><input type="hidden" name="record" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'eduflow_class_action_' . $id ); ?>
                <?php else : ?>
                    <input type="hidden" name="action" value="eduflow_class_manual_schedule"><?php wp_nonce_field( 'eduflow_class_manual_schedule' ); ?>
                <?php endif; ?>
                <div class="efc-form-grid">
                    <label>Batch<select name="batch_id" <?php echo $id ? 'disabled' : 'required'; ?>><option value="">Select Batch</option><?php foreach ( $batches as $b ) : ?><option value="<?php echo esc_attr( $b['id'] ); ?>" data-teacher="<?php echo esc_attr( $b['teacher_id'] ); ?>" data-start="<?php echo esc_attr( substr( $b['start_time'],0,5 ) ); ?>" data-end="<?php echo esc_attr( substr( $b['end_time'],0,5 ) ); ?>" <?php selected( $batch_id, $b['id'] ); ?>><?php echo esc_html( $b['batch_name'] . ' · ' . $b['canonical_id'] ); ?></option><?php endforeach; ?></select><?php if ( $id ) : ?><input type="hidden" name="batch_id" value="<?php echo esc_attr( $batch_id ); ?>"><?php endif; ?></label>
                    <label>Teacher<select name="teacher_id" required><option value="">Select Teacher</option><?php foreach ( $teachers as $t ) : ?><option value="<?php echo esc_attr( $t['id'] ); ?>" <?php selected( $teacher_id, $t['id'] ); ?>><?php echo esc_html( $t['name'] ); ?></option><?php endforeach; ?></select></label>
                    <label>Date<input type="date" name="class_date" value="<?php echo esc_attr( $date ); ?>" required></label>
                    <label>Start Time<input type="time" name="start_time" value="<?php echo esc_attr( $start ); ?>" required></label>
                    <label>End Time<input type="time" name="end_time" value="<?php echo esc_attr( $end ); ?>" required></label>
                </div>
                <?php submit_button( $id ? 'Save Reschedule' : 'Schedule Class' ); ?>
            </form>
        </div>
        <script>
        document.addEventListener('change',function(e){if(e.target.name==='batch_id'&&!e.target.disabled){var o=e.target.options[e.target.selectedIndex],f=e.target.form;if(o&&f){if(o.dataset.teacher)f.teacher_id.value=o.dataset.teacher;if(o.dataset.start)f.start_time.value=o.dataset.start;if(o.dataset.end)f.end_time.value=o.dataset.end;}}});
        </script>
        <?php
    }

    private static function generate_card( $batches ) {
        ?>
        <div id="generate-schedule" class="efc-panel"><h2>Generate Rolling Schedule</h2><p class="description">Generate the next 14 days safely. Existing occurrences are skipped; duplicates are not created.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="eduflow_generate_classes"><?php wp_nonce_field( 'eduflow_generate_classes' ); ?>
                <select name="batch_id" required><option value="">Select Batch</option><?php foreach ( $batches as $b ) : ?><option value="<?php echo esc_attr( $b['id'] ); ?>"><?php echo esc_html( $b['batch_name'] . ' · ' . $b['canonical_id'] ); ?></option><?php endforeach; ?></select>
                <?php submit_button( 'Generate Next 14 Days', 'primary', '', false ); ?>
            </form>
        </div>
        <?php
    }

    public function bulk_retry() {
        self::guard();
        check_admin_referer( 'eduflow_classes_bulk_retry' );
        global $wpdb;
        $institute = EduFlow_Settings::institute_db_id();
        $g = EduFlow_DB::table( 'google_events' );
        $c = EduFlow_DB::table( 'classes' );
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT c.id FROM $c c INNER JOIN $g g ON g.institute_id=c.institute_id AND g.lecture_id=c.id WHERE c.institute_id=%d AND g.sync_status='failed' AND c.class_status IN ('scheduled','rescheduled') ORDER BY c.start_datetime ASC LIMIT 200", $institute ) );
        $queued = 0;
        foreach ( $ids as $id ) {
            $result = EduFlow_Google_Service::queue( (int) $id, 'upsert', $institute );
            if ( ! is_wp_error( $result ) ) { $queued++; }
        }
        EduFlow_Audit_Service::log( 'google_bulk_retry_queued', 'class', 'bulk', null, array( 'queued'=>$queued ), $institute );
        self::redirect( sprintf( '%d failed Google sync%s queued safely.', $queued, 1 === $queued ? '' : 's' ) );
    }

    public function mark_completed() {
        self::guard();
        $id = absint( $_GET['record'] ?? 0 );
        check_admin_referer( 'eduflow_class_mark_completed_' . $id );
        global $wpdb;
        $institute = EduFlow_Settings::institute_db_id();
        $old = EduFlow_Class_Service::get( $id, $institute );
        if ( ! $old ) { self::redirect( 'Class not found.', true ); }
        if ( 'cancelled' === $old['class_status'] ) { self::redirect( 'Cancelled classes cannot be marked completed.', true ); }
        if ( 'completed' === $old['class_status'] ) { self::redirect( 'Class is already completed.' ); }
        $updated = $wpdb->update( EduFlow_DB::table( 'classes' ), array( 'class_status'=>'completed', 'updated_at'=>current_time( 'mysql', true ) ), array( 'id'=>$id, 'institute_id'=>$institute ) );
        if ( false === $updated ) { self::redirect( 'Unable to update class status.', true ); }
        EduFlow_Audit_Service::log( 'lecture_completed', 'class', $old['canonical_id'], $old, array( 'class_status'=>'completed' ), $institute );
        self::redirect( 'Class marked completed.' );
    }

    public function manual_schedule() {
        self::guard();
        check_admin_referer( 'eduflow_class_manual_schedule' );
        global $wpdb;
        $institute = EduFlow_Settings::institute_db_id();
        $batch_id = absint( $_POST['batch_id'] ?? 0 );
        $teacher_id = absint( $_POST['teacher_id'] ?? 0 );
        $date = sanitize_text_field( wp_unslash( $_POST['class_date'] ?? '' ) );
        $start_time = sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) );
        $end_time = sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) );
        $batch = EduFlow_Batch_Service::get( $batch_id, $institute );
        if ( ! $batch || 'active' !== $batch['batch_status'] ) { self::redirect( 'Select an active batch.', true ); }
        $teacher = EduFlow_Teacher_Service::get( $teacher_id, $institute );
        if ( ! $teacher || 'active' !== $teacher['teacher_status'] ) { self::redirect( 'Select an active teacher.', true ); }
        if ( ! $date || $date < $batch['start_date'] || ( $batch['end_date'] && $date > $batch['end_date'] ) ) { self::redirect( 'Class date must be within the batch dates.', true ); }
        $start = EduFlow_Class_Service::to_utc( $date, $start_time, $batch['timezone'] );
        $end = EduFlow_Class_Service::to_utc( $date, $end_time, $batch['timezone'] );
        if ( is_wp_error( $start ) || is_wp_error( $end ) || $start >= $end ) { self::redirect( 'End time must be after start time.', true ); }
        $student_id = null;
        if ( in_array( $batch['class_type'], array( '1-to-1','demo','trial' ), true ) ) {
            $assign = EduFlow_DB::table( 'batch_students' );
            $student_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT student_id FROM $assign WHERE institute_id=%d AND batch_id=%d AND assignment_status='active' ORDER BY id LIMIT 1", $institute, $batch_id ) ) ?: null;
        }
        $schedule_key = $institute . ':' . $batch_id . ':' . $date . ':' . $start_time . ':' . ( $student_id ?: 'batch' );
        $ctable = EduFlow_DB::table( 'classes' );
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ctable WHERE schedule_key=%s", $schedule_key ) ) ) { self::redirect( 'This batch occurrence already exists. No duplicate was created.', true ); }
        $conflict = EduFlow_Class_Service::conflict( $institute, $teacher_id, $student_id, $start, $end );
        if ( $conflict ) { self::redirect( 'Schedule conflicts with ' . $conflict . '.', true ); }
        $canonical = EduFlow_ID_Service::generate( 'class', $institute );
        if ( is_wp_error( $canonical ) ) { self::redirect( $canonical->get_error_message(), true ); }
        $now = current_time( 'mysql', true );
        $data = array( 'canonical_id'=>$canonical, 'schedule_key'=>$schedule_key, 'institute_id'=>$institute, 'batch_id'=>$batch_id, 'student_id'=>$student_id, 'teacher_id'=>$teacher_id, 'class_type'=>$batch['class_type'], 'class_date'=>$date, 'start_datetime'=>$start, 'end_datetime'=>$end, 'timezone'=>$batch['timezone'], 'meet_url'=>null, 'external_event_id'=>null, 'class_status'=>'scheduled', 'status'=>'active', 'created_at'=>$now, 'updated_at'=>$now );
        if ( false === $wpdb->insert( $ctable, $data ) ) { self::redirect( 'Unable to schedule class.', true ); }
        $lecture_id = (int) $wpdb->insert_id;
        EduFlow_Audit_Service::log( 'lecture_scheduled_manual', 'class', $canonical, null, $data, $institute );
        EduFlow_Notification_Service::notify_class( $lecture_id, 'class_scheduled', 'Class scheduled', 'A class has been scheduled.', $now, $institute );
        EduFlow_Google_Service::queue( $lecture_id, 'upsert', $institute );
        self::redirect( 'Class scheduled. Google Calendar/Meet sync queued.' );
    }
}
