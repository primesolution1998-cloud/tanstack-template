<?php
defined( 'ABSPATH' ) || exit;
final class EduFlow_Phase3_Admin {
	public function register(){foreach(array('save_teacher','teacher_status','save_batch','batch_status','batch_student','generate_classes','class_action') as $action){add_action('admin_post_eduflow_'.$action,array($this,$action));}}
	private static function guard($cap){if(!current_user_can($cap)){wp_die(esc_html__('You do not have permission to perform this action.','eduflow-core'));}}
	private function redirect($page,$result,$success){$error=is_wp_error($result);wp_safe_redirect(add_query_arg(array('page'=>$page,'eduflow_notice'=>rawurlencode($error?$result->get_error_message():$success),'notice_type'=>$error?'error':'success'),admin_url('admin.php')));exit;}
	private static function notice(){if(!empty($_GET['eduflow_notice'])){$type='error'===($_GET['notice_type']??'')?'error':'success';echo '<div class="notice notice-'.esc_attr($type).' is-dismissible"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['eduflow_notice']))).'</p></div>';}}
	private static function pages($total,$page,$per=20){echo wp_kses_post(paginate_links(array('base'=>add_query_arg('paged','%#%'),'current'=>$page,'total'=>max(1,(int)ceil($total/$per)))));}
        public static function teachers() {
                self::guard( 'eduflow_manage_teachers' );

                global $wpdb;

                $institute = EduFlow_Settings::institute_db_id();
                $teachers  = EduFlow_DB::table( 'teachers' );
                $batches   = EduFlow_DB::table( 'batches' );
                $classes   = EduFlow_DB::table( 'classes' );

                $search = sanitize_text_field(
                        wp_unslash( $_GET['s'] ?? '' )
                );

                $status_filter = sanitize_key(
                        $_GET['teacher_status'] ?? ''
                );

                $where  = array( 't.institute_id=%d' );
                $params = array( $institute );

                if ( $search ) {
                        $like = '%' . $wpdb->esc_like( $search ) . '%';

                        $where[] =
                                '(t.name LIKE %s
                                  OR t.canonical_id LIKE %s
                                  OR t.mobile LIKE %s
                                  OR t.email LIKE %s)';

                        array_push(
                                $params,
                                $like,
                                $like,
                                $like,
                                $like
                        );
                }

                if ( $status_filter ) {
                        $where[]  = 't.teacher_status=%s';
                        $params[] = $status_filter;
                }

                $sql =
                        "SELECT
                                t.*,
                                (
                                    SELECT COUNT(*)
                                    FROM $batches b
                                    WHERE b.institute_id=t.institute_id
                                    AND b.teacher_id=t.id
                                    AND b.batch_status='active'
                                ) AS active_batches,
                                (
                                    SELECT COUNT(*)
                                    FROM $classes c
                                    WHERE c.institute_id=t.institute_id
                                    AND c.teacher_id=t.id
                                    AND c.class_date=CURDATE()
                                    AND c.class_status IN ('scheduled','rescheduled')
                                ) AS today_classes
                         FROM $teachers t
                         WHERE " .
                         implode( ' AND ', $where ) .
                         " ORDER BY t.name ASC";

                $rows = $wpdb->get_results(
                        $wpdb->prepare( $sql, ...$params ),
                        ARRAY_A
                );

                $view_teacher = absint(
                        $_GET['view_teacher'] ?? 0
                );

                $teacher_batches = array();
                $view_row = array();

                if ( $view_teacher ) {
                        $view_row = EduFlow_Teacher_Service::get(
                                $view_teacher,
                                $institute
                        );

                        if ( $view_row ) {
                                $teacher_batches = $wpdb->get_results(
                                        $wpdb->prepare(
                                                "SELECT
                                                        id,
                                                        canonical_id,
                                                        batch_name,
                                                        start_time,
                                                        end_time,
                                                        days_of_week,
                                                        batch_status
                                                 FROM $batches
                                                 WHERE institute_id=%d
                                                 AND teacher_id=%d
                                                 ORDER BY start_time ASC",
                                                $institute,
                                                $view_teacher
                                        ),
                                        ARRAY_A
                                );
                        }
                }

                ?>
                <div class="wrap eduflow-wrap eduflow-teacher-control">

                        <h1 class="wp-heading-inline">
                                Teacher Control Center
                        </h1>

                        <p class="description">
                                Manage teacher profiles, availability, workload,
                                assigned batches and lifecycle safely.
                        </p>

                        <hr class="wp-header-end">

                        <form method="get"
                              class="eduflow-teacher-toolbar">

                                <input type="hidden"
                                       name="page"
                                       value="eduflow-teachers">

                                <input type="search"
                                       name="s"
                                       placeholder="Teacher ID, name, mobile or email"
                                       value="<?php echo esc_attr($search); ?>">

                                <select name="teacher_status">
                                        <option value="">
                                                All Statuses
                                        </option>

                                        <?php
                                        foreach (
                                                EduFlow_Teacher_Service::STATUSES
                                                as $status
                                        ) :
                                        ?>
                                        <option value="<?php echo esc_attr($status); ?>"
                                                <?php selected(
                                                        $status_filter,
                                                        $status
                                                ); ?>>
                                                <?php echo esc_html(
                                                        ucwords(
                                                                str_replace(
                                                                        '_',
                                                                        ' ',
                                                                        $status
                                                                )
                                                        )
                                                ); ?>
                                        </option>
                                        <?php endforeach; ?>
                                </select>

                                <?php
                                submit_button(
                                        'Filter',
                                        'secondary',
                                        '',
                                        false
                                );
                                ?>

                                <a class="button"
                                   href="<?php echo esc_url(
                                           admin_url(
                                                   'admin.php?page=eduflow-teachers'
                                           )
                                   ); ?>">
                                        Reset
                                </a>
                        </form>

                        <div class="eduflow-table-wrap">

                        <table class="widefat striped eduflow-teacher-table">

                                <thead>
                                <tr>
                                        <th>Teacher</th>
                                        <th>Contact</th>
                                        <th>Availability</th>
                                        <th>Workload</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                </tr>
                                </thead>

                                <tbody>

                                <?php foreach ( $rows as $row ) : ?>

                                <?php
                                $status = sanitize_key(
                                        $row['teacher_status']
                                );
                                ?>

                                <tr>

                                        <td>
                                                <strong>
                                                <?php echo esc_html(
                                                        $row['name']
                                                ); ?>
                                                </strong>

                                                <div class="eduflow-muted">
                                                <?php echo esc_html(
                                                        $row['canonical_id']
                                                ); ?>
                                                </div>
                                        </td>

                                        <td>
                                                <?php echo esc_html(
                                                        $row['mobile']
                                                ); ?>

                                                <div class="eduflow-muted">
                                                <?php echo esc_html(
                                                        $row['email']
                                                        ?: 'No email'
                                                ); ?>
                                                </div>
                                        </td>

                                        <td>
                                                <strong>
                                                <?php echo esc_html(
                                                        $row['available_start_time']
                                                        && $row['available_end_time']
                                                        ? substr(
                                                                $row['available_start_time'],
                                                                0,
                                                                5
                                                          ) .
                                                          ' – ' .
                                                          substr(
                                                                $row['available_end_time'],
                                                                0,
                                                                5
                                                          )
                                                        : 'Not Set'
                                                ); ?>
                                                </strong>

                                                <div class="eduflow-muted">
                                                <?php echo esc_html(
                                                        $row['available_days']
                                                        ?: 'No days selected'
                                                ); ?>
                                                </div>
                                        </td>

                                        <td>
                                                <span class="eduflow-workload-badge">
                                                        <?php echo esc_html(
                                                                (int)$row['active_batches']
                                                        ); ?>
                                                        Batches
                                                </span>

                                                <span class="eduflow-workload-badge">
                                                        <?php echo esc_html(
                                                                (int)$row['today_classes']
                                                        ); ?>
                                                        Today
                                                </span>
                                        </td>

                                        <td>
                                                <span class="eduflow-teacher-status eduflow-teacher-status-<?php
                                                echo esc_attr($status);
                                                ?>">
                                                <?php echo esc_html(
                                                        ucwords(
                                                                str_replace(
                                                                        '_',
                                                                        ' ',
                                                                        $status
                                                                )
                                                        )
                                                ); ?>
                                                </span>
                                        </td>

                                        <td>
                                                <div class="eduflow-action-grid">

                                                        <a class="button button-small"
                                                           href="<?php echo esc_url(
                                                                   add_query_arg(
                                                                           array(
                                                                                   'page' => 'eduflow-teachers',
                                                                                   'view_teacher' => $row['id']
                                                                           ),
                                                                           admin_url('admin.php')
                                                                   )
                                                           ); ?>#eduflow-teacher-batches">
                                                                View Batches
                                                        </a>

                                                        <a class="button button-small"
                                                           href="<?php echo esc_url(
                                                                   add_query_arg(
                                                                           array(
                                                                                   'page' => 'eduflow-teachers',
                                                                                   'edit' => $row['id']
                                                                           ),
                                                                           admin_url('admin.php')
                                                                   )
                                                           ); ?>#teacher-form">
                                                                Edit
                                                        </a>

                                                        <?php if ( 'active' === $status ) : ?>

                                                        <a class="button button-small eduflow-warning-action"
                                                           onclick="return confirm('Put this teacher on leave? Existing batch history will remain safe.');"
                                                           href="<?php echo esc_url(
                                                                   wp_nonce_url(
                                                                           admin_url(
                                                                                   'admin-post.php?action=eduflow_teacher_status&record=' .
                                                                                   $row['id'] .
                                                                                   '&status=on_leave'
                                                                           ),
                                                                           'eduflow_teacher_status_' .
                                                                           $row['id']
                                                                   )
                                                           ); ?>">
                                                                On Leave
                                                        </a>

                                                        <?php else : ?>

                                                        <a class="button button-small button-primary"
                                                           onclick="return confirm('Return this teacher to Active status?');"
                                                           href="<?php echo esc_url(
                                                                   wp_nonce_url(
                                                                           admin_url(
                                                                                   'admin-post.php?action=eduflow_teacher_status&record=' .
                                                                                   $row['id'] .
                                                                                   '&status=active'
                                                                           ),
                                                                           'eduflow_teacher_status_' .
                                                                           $row['id']
                                                                   )
                                                           ); ?>">
                                                                Set Active
                                                        </a>

                                                        <?php endif; ?>

                                                </div>
                                        </td>

                                </tr>

                                <?php endforeach; ?>

                                <?php if ( ! $rows ) : ?>

                                <tr>
                                        <td colspan="6">
                                                No teachers found.
                                        </td>
                                </tr>

                                <?php endif; ?>

                                </tbody>
                        </table>

                        </div>

                        <?php if ( $view_row ) : ?>

                        <div id="eduflow-teacher-batches"
                             class="eduflow-card eduflow-teacher-batches-card">

                                <div class="eduflow-roster-heading">
                                        <div>
                                                <h2>
                                                        Assigned Batches —
                                                        <?php echo esc_html(
                                                                $view_row['name']
                                                        ); ?>
                                                </h2>

                                                <p>
                                                        <?php echo esc_html(
                                                                count(
                                                                        $teacher_batches
                                                                )
                                                        ); ?>
                                                        linked batches
                                                </p>
                                        </div>

                                        <a class="button"
                                           href="<?php echo esc_url(
                                                   admin_url(
                                                           'admin.php?page=eduflow-teachers'
                                                   )
                                           ); ?>">
                                                Close
                                        </a>
                                </div>

                                <table class="widefat striped">
                                        <thead>
                                        <tr>
                                                <th>Batch</th>
                                                <th>Schedule</th>
                                                <th>Days</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                        </tr>
                                        </thead>

                                        <tbody>

                                        <?php foreach (
                                                $teacher_batches
                                                as $batch
                                        ) : ?>

                                        <tr>
                                                <td>
                                                        <strong>
                                                        <?php echo esc_html(
                                                                $batch['batch_name']
                                                        ); ?>
                                                        </strong>

                                                        <div class="eduflow-muted">
                                                        <?php echo esc_html(
                                                                $batch['canonical_id']
                                                        ); ?>
                                                        </div>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                substr(
                                                                        $batch['start_time'],
                                                                        0,
                                                                        5
                                                                ) .
                                                                ' – ' .
                                                                substr(
                                                                        $batch['end_time'],
                                                                        0,
                                                                        5
                                                                )
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                $batch['days_of_week']
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                ucwords(
                                                                        $batch['batch_status']
                                                                )
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <a class="button button-small"
                                                           href="<?php echo esc_url(
                                                                   add_query_arg(
                                                                           array(
                                                                                   'page' => 'eduflow-batches',
                                                                                   'edit' => $batch['id']
                                                                           ),
                                                                           admin_url('admin.php')
                                                                   )
                                                           ); ?>#batch-form">
                                                                Change Teacher
                                                        </a>
                                                </td>
                                        </tr>

                                        <?php endforeach; ?>

                                        <?php if ( ! $teacher_batches ) : ?>

                                        <tr>
                                                <td colspan="5">
                                                        No batches assigned.
                                                </td>
                                        </tr>

                                        <?php endif; ?>

                                        </tbody>
                                </table>

                        </div>

                        <?php endif; ?>

                        <?php self::teacher_form(); ?>

                </div>
                <?php
        }
	private static function teacher_form(){$id=absint($_GET['edit']??0);$row=$id?EduFlow_Teacher_Service::get($id):array();$row=is_array($row)?$row:array();$days=explode(',',$row['available_days']??'');?><div class="eduflow-card" id="teacher-form"><h2><?php echo $id?'Edit Teacher':'Create Teacher';?></h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="eduflow_save_teacher"><input type="hidden" name="record" value="<?php echo esc_attr($id);?>"><?php wp_nonce_field('eduflow_save_teacher');?><div class="eduflow-form-grid"><?php foreach(array('name','mobile','email','joining_date','available_start_time','available_end_time','maximum_daily_classes') as $key):?><label><?php echo esc_html(ucwords(str_replace('_',' ',$key)));?><input name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($row[$key]??'');?>" <?php echo false!==strpos($key,'time')?'type="time"':'';?> <?php echo 'joining_date'===$key?'type="date"':'';?>></label><?php endforeach;?><fieldset><legend>Available Days</legend><?php foreach(array('mon','tue','wed','thu','fri','sat','sun') as $day):?><label><input type="checkbox" name="available_days[]" value="<?php echo esc_attr($day);?>" <?php checked(in_array($day,$days,true));?>><?php echo esc_html(ucfirst($day));?></label> <?php endforeach;?></fieldset><label class="wide">Notes<textarea name="notes"><?php echo esc_textarea($row['notes']??'');?></textarea></label></div><?php submit_button($id?'Update Teacher':'Create Teacher');?></form></div><?php }
	public function save_teacher(){self::guard('eduflow_manage_teachers');check_admin_referer('eduflow_save_teacher');$input=wp_unslash($_POST);$id=absint($input['record']??0);$result=$id?EduFlow_Teacher_Service::update($id,$input):EduFlow_Teacher_Service::create($input);$this->redirect('eduflow-teachers',$result,$id?'Teacher updated.':'Teacher created.');}
	public function teacher_status(){self::guard('eduflow_manage_teachers');$id=absint($_GET['record']??0);check_admin_referer('eduflow_teacher_status_'.$id);$this->redirect('eduflow-teachers',EduFlow_Teacher_Service::set_status($id,sanitize_key($_GET['status']??'')),'Teacher status updated.');}
        public static function batches() {
                self::guard( 'eduflow_manage_batches' );

                global $wpdb;

                $institute = EduFlow_Settings::institute_db_id();
                $batches   = EduFlow_DB::table( 'batches' );
                $teachers  = EduFlow_DB::table( 'teachers' );
                $assign    = EduFlow_DB::table( 'batch_students' );
                $students  = EduFlow_DB::table( 'students' );

                $search = sanitize_text_field(
                        wp_unslash( $_GET['s'] ?? '' )
                );

                $status_filter = sanitize_key(
                        $_GET['status'] ?? ''
                );

                $where  = array( 'b.institute_id=%d' );
                $params = array( $institute );

                if ( $status_filter ) {
                        $where[]  = 'b.batch_status=%s';
                        $params[] = $status_filter;
                }

                if ( $search ) {
                        $like = '%' . $wpdb->esc_like( $search ) . '%';

                        $where[] =
                                '(b.batch_name LIKE %s
                                  OR b.canonical_id LIKE %s
                                  OR b.course LIKE %s)';

                        array_push(
                                $params,
                                $like,
                                $like,
                                $like
                        );
                }

                $sql =
                        "SELECT
                                b.*,
                                t.name AS teacher_name,
                                (
                                        SELECT COUNT(*)
                                        FROM $assign ba
                                        WHERE ba.institute_id=b.institute_id
                                        AND ba.batch_id=b.id
                                        AND ba.assignment_status='active'
                                ) AS assigned_students
                         FROM $batches b
                         LEFT JOIN $teachers t
                                ON t.id=b.teacher_id
                                AND t.institute_id=b.institute_id
                         WHERE " .
                         implode( ' AND ', $where ) .
                         " ORDER BY b.start_time ASC,b.id ASC";

                $rows = $wpdb->get_results(
                        $wpdb->prepare( $sql, ...$params ),
                        ARRAY_A
                );

                $view_batch = absint(
                        $_GET['view_batch'] ?? 0
                );

                $view_row = array();
                $roster   = array();

                if ( $view_batch ) {

                        $view_row = EduFlow_Batch_Service::get(
                                $view_batch,
                                $institute
                        );

                        if ( $view_row ) {

                                $roster = $wpdb->get_results(
                                        $wpdb->prepare(
                                                "SELECT
                                                        s.id,
                                                        s.canonical_id,
                                                        s.name,
                                                        s.mobile,
                                                        s.email,
                                                        s.student_status,
                                                        s.fee_status
                                                 FROM $assign ba
                                                 INNER JOIN $students s
                                                        ON s.id=ba.student_id
                                                        AND s.institute_id=ba.institute_id
                                                 WHERE ba.institute_id=%d
                                                 AND ba.batch_id=%d
                                                 AND ba.assignment_status='active'
                                                 ORDER BY s.name ASC",
                                                $institute,
                                                $view_batch
                                        ),
                                        ARRAY_A
                                );
                        }
                }

                $target_batches = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id,canonical_id,batch_name,start_time,end_time
                                 FROM $batches
                                 WHERE institute_id=%d
                                 AND batch_status='active'
                                 ORDER BY start_time ASC,batch_name ASC",
                                $institute
                        ),
                        ARRAY_A
                );

                $available_students = array();

                if ( $view_row ) {
                        $available_students = $wpdb->get_results(
                                $wpdb->prepare(
                                        "SELECT id,canonical_id,name,mobile
                                         FROM $students
                                         WHERE institute_id=%d
                                         AND status='active'
                                         AND student_status NOT IN ('dropped','cancelled','completed')
                                         AND (batch_id IS NULL OR batch_id<>%d)
                                         ORDER BY name ASC",
                                        $institute,
                                        $view_batch
                                ),
                                ARRAY_A
                        );
                }

                ?>
                <div class="wrap eduflow-wrap eduflow-pro-batches">

                        <h1 class="wp-heading-inline">
                                Batch Control Center
                        </h1>

                        <p class="description">
                                Manage running batches, rosters, teachers,
                                schedules and lifecycle safely.
                        </p>

                        <hr class="wp-header-end">

                        <form method="get"
                              class="eduflow-batch-toolbar">

                                <input type="hidden"
                                       name="page"
                                       value="eduflow-batches">

                                <input type="search"
                                       name="s"
                                       placeholder="Batch ID, name or course"
                                       value="<?php echo esc_attr($search); ?>">

                                <select name="status">
                                        <option value="">
                                                All statuses
                                        </option>
                                        <option value="active"
                                                <?php selected($status_filter,'active'); ?>>
                                                Active
                                        </option>
                                        <option value="paused"
                                                <?php selected($status_filter,'paused'); ?>>
                                                Paused
                                        </option>
                                        <option value="closed"
                                                <?php selected($status_filter,'closed'); ?>>
                                                Closed
                                        </option>
                                </select>

                                <?php
                                submit_button(
                                        'Filter',
                                        'secondary',
                                        '',
                                        false
                                );
                                ?>

                                <a class="button"
                                   href="<?php echo esc_url(
                                           admin_url(
                                                   'admin.php?page=eduflow-batches'
                                           )
                                   ); ?>">
                                        Reset
                                </a>
                        </form>

                        <div class="eduflow-table-wrap">
                        <table class="widefat striped eduflow-pro-batch-table">

                                <thead>
                                <tr>
                                        <th>Batch</th>
                                        <th>Course</th>
                                        <th>Teacher</th>
                                        <th>Schedule</th>
                                        <th>Students</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                </tr>
                                </thead>

                                <tbody>

                                <?php foreach ( $rows as $row ) : ?>

                                <?php
                                $status = sanitize_key(
                                        $row['batch_status']
                                );

                                $assigned = (int)
                                        $row['assigned_students'];

                                $capacity = (int)
                                        $row['capacity'];
                                ?>

                                <tr>

                                        <td>
                                                <strong>
                                                <?php echo esc_html(
                                                        $row['batch_name']
                                                ); ?>
                                                </strong>

                                                <div class="eduflow-muted">
                                                <?php echo esc_html(
                                                        $row['canonical_id']
                                                ); ?>
                                                </div>
                                        </td>

                                        <td>
                                                <?php echo esc_html(
                                                        $row['course']
                                                ); ?>

                                                <div class="eduflow-muted">
                                                <?php echo esc_html(
                                                        $row['level'] .
                                                        ' / ' .
                                                        $row['class_type']
                                                ); ?>
                                                </div>
                                        </td>

                                        <td>
                                                <?php
                                                echo esc_html(
                                                        $row['teacher_name']
                                                        ?: 'Unassigned'
                                                );
                                                ?>
                                        </td>

                                        <td>
                                                <strong>
                                                <?php echo esc_html(
                                                        substr(
                                                                $row['start_time'],
                                                                0,
                                                                5
                                                        ) .
                                                        ' – ' .
                                                        substr(
                                                                $row['end_time'],
                                                                0,
                                                                5
                                                        )
                                                ); ?>
                                                </strong>

                                                <div class="eduflow-muted">
                                                <?php echo esc_html(
                                                        $row['days_of_week']
                                                ); ?>
                                                </div>
                                        </td>

                                        <td>
                                                <span class="eduflow-capacity-badge">
                                                        <?php
                                                        echo esc_html(
                                                                $assigned .
                                                                ' / ' .
                                                                $capacity
                                                        );
                                                        ?>
                                                </span>
                                        </td>

                                        <td>
                                                <span class="eduflow-batch-status eduflow-batch-status-<?php
                                                echo esc_attr($status);
                                                ?>">
                                                <?php
                                                echo esc_html(
                                                        ucwords($status)
                                                );
                                                ?>
                                                </span>
                                        </td>

                                        <td>
                                                <div class="eduflow-action-grid">

                                                        <a class="button button-small"
                                                           href="<?php echo esc_url(
                                                                   add_query_arg(
                                                                           array(
                                                                                   'page' => 'eduflow-batches',
                                                                                   'view_batch' => $row['id']
                                                                           ),
                                                                           admin_url('admin.php')
                                                                   )
                                                           ); ?>#eduflow-batch-roster">
                                                                View Students
                                                        </a>

                                                        <a class="button button-small"
                                                           href="<?php echo esc_url(
                                                                   add_query_arg(
                                                                           array(
                                                                                   'page' => 'eduflow-batches',
                                                                                   'edit' => $row['id']
                                                                           ),
                                                                           admin_url('admin.php')
                                                                   )
                                                           ); ?>#batch-form">
                                                                Edit
                                                        </a>

                                                        <?php if ( 'active' === $status ) : ?>

                                                        <a class="button button-small eduflow-warning-action"
                                                           onclick="return confirm('Pause this batch? Students and history will remain safe.');"
                                                           href="<?php echo esc_url(
                                                                   wp_nonce_url(
                                                                           admin_url(
                                                                                   'admin-post.php?action=eduflow_batch_status&record=' .
                                                                                   $row['id'] .
                                                                                   '&status=paused'
                                                                           ),
                                                                           'eduflow_batch_status_' .
                                                                           $row['id']
                                                                   )
                                                           ); ?>">
                                                                Pause
                                                        </a>

                                                        <?php endif; ?>

                                                        <?php if ( 'paused' === $status ) : ?>

                                                        <a class="button button-small button-primary"
                                                           onclick="return confirm('Resume this batch?');"
                                                           href="<?php echo esc_url(
                                                                   wp_nonce_url(
                                                                           admin_url(
                                                                                   'admin-post.php?action=eduflow_batch_status&record=' .
                                                                                   $row['id'] .
                                                                                   '&status=active'
                                                                           ),
                                                                           'eduflow_batch_status_' .
                                                                           $row['id']
                                                                   )
                                                           ); ?>">
                                                                Resume
                                                        </a>

                                                        <?php endif; ?>

                                                        <?php if ( 'closed' !== $status ) : ?>

                                                        <a class="button button-small eduflow-danger-action"
                                                           onclick="return confirm('Close this batch? You can reopen it later from the Closed filter.');"
                                                           href="<?php echo esc_url(
                                                                   wp_nonce_url(
                                                                           admin_url(
                                                                                   'admin-post.php?action=eduflow_batch_status&record=' .
                                                                                   $row['id'] .
                                                                                   '&status=closed'
                                                                           ),
                                                                           'eduflow_batch_status_' .
                                                                           $row['id']
                                                                   )
                                                           ); ?>">
                                                                Close
                                                        </a>

                                                        <?php else : ?>

                                                        <a class="button button-small"
                                                           onclick="return confirm('Reopen this closed batch?');"
                                                           href="<?php echo esc_url(
                                                                   wp_nonce_url(
                                                                           admin_url(
                                                                                   'admin-post.php?action=eduflow_batch_status&record=' .
                                                                                   $row['id'] .
                                                                                   '&status=active'
                                                                           ),
                                                                           'eduflow_batch_status_' .
                                                                           $row['id']
                                                                   )
                                                           ); ?>">
                                                                Reopen
                                                        </a>

                                                        <?php endif; ?>

                                                </div>
                                        </td>

                                </tr>

                                <?php endforeach; ?>

                                <?php if ( ! $rows ) : ?>

                                <tr>
                                        <td colspan="7">
                                                No batches found.
                                        </td>
                                </tr>

                                <?php endif; ?>

                                </tbody>
                        </table>
                        </div>

                        <?php if ( $view_row ) : ?>

                        <div id="eduflow-batch-roster"
                             class="eduflow-card eduflow-roster-card">

                                <div class="eduflow-roster-heading">

                                        <div>
                                                <h2>
                                                        Batch Roster Manager
                                                </h2>

                                                <p>
                                                        <strong>
                                                        <?php echo esc_html(
                                                                $view_row['batch_name']
                                                        ); ?>
                                                        </strong>
                                                        ·
                                                        <?php echo esc_html(
                                                                substr($view_row['start_time'],0,5)
                                                                . ' – ' .
                                                                substr($view_row['end_time'],0,5)
                                                        ); ?>
                                                        ·
                                                        <?php echo esc_html(
                                                                count($roster)
                                                        ); ?>
                                                        Students
                                                </p>
                                        </div>

                                        <a class="button"
                                           href="<?php echo esc_url(
                                                   admin_url(
                                                           'admin.php?page=eduflow-batches'
                                                   )
                                           ); ?>">
                                                Close Roster
                                        </a>
                                </div>

                                <div class="eduflow-roster-summary">

                                        <div>
                                                <span>Teacher</span>
                                                <strong>
                                                <?php
                                                $teacher_name = 'Unassigned';

                                                if ( ! empty($view_row['teacher_id']) ) {
                                                        $teacher = EduFlow_Teacher_Service::get(
                                                                (int)$view_row['teacher_id'],
                                                                $institute
                                                        );

                                                        if ( $teacher ) {
                                                                $teacher_name = $teacher['name'];
                                                        }
                                                }

                                                echo esc_html($teacher_name);
                                                ?>
                                                </strong>
                                        </div>

                                        <div>
                                                <span>Schedule</span>
                                                <strong>
                                                <?php echo esc_html(
                                                        $view_row['days_of_week']
                                                ); ?>
                                                </strong>
                                        </div>

                                        <div>
                                                <span>Occupancy</span>
                                                <strong>
                                                <?php echo esc_html(
                                                        count($roster) .
                                                        ' / ' .
                                                        (int)$view_row['capacity']
                                                ); ?>
                                                </strong>
                                        </div>

                                        <div>
                                                <span>Status</span>
                                                <strong>
                                                <?php echo esc_html(
                                                        ucwords(
                                                                $view_row['batch_status']
                                                        )
                                                ); ?>
                                                </strong>
                                        </div>

                                </div>

                                <div class="eduflow-table-wrap">

                                <table class="widefat striped eduflow-roster-table">

                                        <thead>
                                        <tr>
                                                <th>Student</th>
                                                <th>Mobile</th>
                                                <th>Fee</th>
                                                <th>Status</th>
                                                <th>Transfer Batch</th>
                                                <th>Actions</th>
                                        </tr>
                                        </thead>

                                        <tbody>

                                        <?php foreach ( $roster as $student ) : ?>

                                        <tr>

                                                <td>
                                                        <strong>
                                                        <?php echo esc_html(
                                                                $student['name']
                                                        ); ?>
                                                        </strong>

                                                        <div class="eduflow-muted">
                                                        <?php echo esc_html(
                                                                $student['canonical_id']
                                                        ); ?>
                                                        </div>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                $student['mobile']
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <span class="eduflow-mini-badge">
                                                        <?php echo esc_html(
                                                                ucwords(
                                                                        $student['fee_status']
                                                                )
                                                        ); ?>
                                                        </span>
                                                </td>

                                                <td>
                                                        <span class="eduflow-mini-badge">
                                                        <?php echo esc_html(
                                                                ucwords(
                                                                        $student['student_status']
                                                                )
                                                        ); ?>
                                                        </span>
                                                </td>

                                                <td>

                                                        <form method="post"
                                                              action="<?php echo esc_url(
                                                                      admin_url('admin-post.php')
                                                              ); ?>"
                                                              class="eduflow-inline-transfer"
                                                              onsubmit="return confirm('Transfer <?php echo esc_js($student['name']); ?> to the selected batch?');">

                                                                <?php
                                                                wp_nonce_field(
                                                                        'eduflow_batch_student'
                                                                );
                                                                ?>

                                                                <input type="hidden"
                                                                       name="action"
                                                                       value="eduflow_batch_student">

                                                                <input type="hidden"
                                                                       name="operation"
                                                                       value="transfer">

                                                                <input type="hidden"
                                                                       name="student_id"
                                                                       value="<?php echo esc_attr(
                                                                               $student['id']
                                                                       ); ?>">

                                                                <input type="hidden"
                                                                       name="batch_id"
                                                                       value="<?php echo esc_attr(
                                                                               $view_batch
                                                                       ); ?>">

                                                                <select name="target_batch_id"
                                                                        required>

                                                                        <option value="">
                                                                                Select new batch
                                                                        </option>

                                                                        <?php
                                                                        foreach (
                                                                                $target_batches
                                                                                as $target
                                                                        ) :

                                                                                if (
                                                                                        (int)$target['id']
                                                                                        ===
                                                                                        (int)$view_batch
                                                                                ) {
                                                                                        continue;
                                                                                }
                                                                        ?>

                                                                        <option value="<?php echo esc_attr(
                                                                                $target['id']
                                                                        ); ?>">
                                                                                <?php echo esc_html(
                                                                                        $target['batch_name'] .
                                                                                        ' (' .
                                                                                        substr($target['start_time'],0,5) .
                                                                                        '–' .
                                                                                        substr($target['end_time'],0,5) .
                                                                                        ')'
                                                                                ); ?>
                                                                        </option>

                                                                        <?php endforeach; ?>

                                                                </select>

                                                                <button type="submit"
                                                                        class="button button-small button-primary">
                                                                        Transfer
                                                                </button>

                                                        </form>

                                                </td>

                                                <td>

                                                        <div class="eduflow-action-grid">

                                                                <a class="button button-small"
                                                                   href="<?php echo esc_url(
                                                                           add_query_arg(
                                                                                   array(
                                                                                           'page' => 'eduflow-students',
                                                                                           'edit' => $student['id']
                                                                                   ),
                                                                                   admin_url('admin.php')
                                                                           )
                                                                   ); ?>#eduflow-student-form">
                                                                        Edit Student
                                                                </a>

                                                                <form method="post"
                                                                      action="<?php echo esc_url(
                                                                              admin_url('admin-post.php')
                                                                      ); ?>"
                                                                      onsubmit="return confirm('Remove <?php echo esc_js($student['name']); ?> from this batch? Student record will remain safe.');">

                                                                        <?php
                                                                        wp_nonce_field(
                                                                                'eduflow_batch_student'
                                                                        );
                                                                        ?>

                                                                        <input type="hidden"
                                                                               name="action"
                                                                               value="eduflow_batch_student">

                                                                        <input type="hidden"
                                                                               name="operation"
                                                                               value="remove">

                                                                        <input type="hidden"
                                                                               name="student_id"
                                                                               value="<?php echo esc_attr(
                                                                                       $student['id']
                                                                               ); ?>">

                                                                        <input type="hidden"
                                                                               name="batch_id"
                                                                               value="<?php echo esc_attr(
                                                                                       $view_batch
                                                                               ); ?>">

                                                                        <button type="submit"
                                                                                class="button button-small eduflow-danger-action">
                                                                                Remove
                                                                        </button>

                                                                </form>

                                                        </div>

                                                </td>

                                        </tr>

                                        <?php endforeach; ?>

                                        <?php if ( ! $roster ) : ?>

                                        <tr>
                                                <td colspan="6">
                                                        No active students assigned.
                                                </td>
                                        </tr>

                                        <?php endif; ?>

                                        </tbody>

                                </table>

                                </div>

                                <div class="eduflow-roster-add">

                                        <h3>Add Student to This Batch</h3>

                                        <form method="post"
                                              action="<?php echo esc_url(
                                                      admin_url('admin-post.php')
                                              ); ?>"
                                              onsubmit="return confirm('Assign selected student to this batch?');">

                                                <?php
                                                wp_nonce_field(
                                                        'eduflow_batch_student'
                                                );
                                                ?>

                                                <input type="hidden"
                                                       name="action"
                                                       value="eduflow_batch_student">

                                                <input type="hidden"
                                                       name="operation"
                                                       value="assign">

                                                <input type="hidden"
                                                       name="batch_id"
                                                       value="<?php echo esc_attr(
                                                               $view_batch
                                                       ); ?>">

                                                <select name="student_id"
                                                        required>

                                                        <option value="">
                                                                Select Student
                                                        </option>

                                                        <?php
                                                        foreach (
                                                                $available_students
                                                                as $available
                                                        ) :
                                                        ?>

                                                        <option value="<?php echo esc_attr(
                                                                $available['id']
                                                        ); ?>">
                                                                <?php echo esc_html(
                                                                        $available['name'] .
                                                                        ' — ' .
                                                                        $available['canonical_id'] .
                                                                        ' — ' .
                                                                        $available['mobile']
                                                                ); ?>
                                                        </option>

                                                        <?php endforeach; ?>

                                                </select>

                                                <button type="submit"
                                                        class="button button-primary">
                                                        Add Student
                                                </button>

                                        </form>

                                </div>

                        </div>

                        <?php endif; ?>

                        <?php
                        if (
                                class_exists(
                                        'EduFlow_YTC_Batch_Setup'
                                )
                        ) {
                                EduFlow_YTC_Batch_Setup::render_card();
                        }

                        self::batch_form();
                        self::assignment_form();
                        ?>

                </div>
                <?php
        }
        private static function batch_form() {

                global $wpdb;

                $id = absint(
                        $_GET['edit'] ?? 0
                );

                $row = $id
                        ? EduFlow_Batch_Service::get($id)
                        : array();

                $row = is_array($row)
                        ? $row
                        : array();

                $institute = EduFlow_Settings::institute_db_id();
                $teachers  = EduFlow_DB::table('teachers');

                $teacher_rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id,name
                                 FROM $teachers
                                 WHERE institute_id=%d
                                 AND status='active'
                                 ORDER BY name ASC",
                                $institute
                        ),
                        ARRAY_A
                );

                $days = array_filter(
                        array_map(
                                'strtolower',
                                explode(
                                        ',',
                                        $row['days_of_week'] ?? ''
                                )
                        )
                );

                ?>
                <div class="eduflow-card"
                     id="batch-form">

                        <h2>
                                <?php echo $id
                                        ? 'Edit Batch'
                                        : 'Create Batch'; ?>
                        </h2>

                        <form method="post"
                              action="<?php echo esc_url(
                                      admin_url('admin-post.php')
                              ); ?>">

                                <input type="hidden"
                                       name="action"
                                       value="eduflow_save_batch">

                                <input type="hidden"
                                       name="record"
                                       value="<?php echo esc_attr($id); ?>">

                                <?php
                                wp_nonce_field(
                                        'eduflow_save_batch'
                                );
                                ?>

                                <div class="eduflow-form-grid">

                                        <label>
                                                Batch Name
                                                <input name="batch_name"
                                                       required
                                                       value="<?php echo esc_attr(
                                                               $row['batch_name'] ?? ''
                                                       ); ?>">
                                        </label>

                                        <label>
                                                Course
                                                <select name="course">
                                                        <?php
                                                        foreach (
                                                                array(
                                                                        'Basic',
                                                                        'Intermediate',
                                                                        'Advanced'
                                                                )
                                                                as $course
                                                        ) :
                                                        ?>
                                                        <option value="<?php echo esc_attr($course); ?>"
                                                                <?php selected(
                                                                        $row['course'] ?? 'Basic',
                                                                        $course
                                                                ); ?>>
                                                                <?php echo esc_html($course); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </label>

                                        <label>
                                                Level
                                                <select name="level">
                                                        <?php
                                                        foreach (
                                                                array(
                                                                        'Basic',
                                                                        'Intermediate',
                                                                        'Advanced'
                                                                )
                                                                as $level
                                                        ) :
                                                        ?>
                                                        <option value="<?php echo esc_attr($level); ?>"
                                                                <?php selected(
                                                                        $row['level'] ?? 'Basic',
                                                                        $level
                                                                ); ?>>
                                                                <?php echo esc_html($level); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </label>

                                        <label>
                                                Teacher
                                                <select name="teacher_id">
                                                        <option value="">
                                                                Unassigned
                                                        </option>

                                                        <?php
                                                        foreach (
                                                                $teacher_rows
                                                                as $teacher
                                                        ) :
                                                        ?>
                                                        <option value="<?php echo esc_attr(
                                                                $teacher['id']
                                                        ); ?>"
                                                                <?php selected(
                                                                        (int)($row['teacher_id'] ?? 0),
                                                                        (int)$teacher['id']
                                                                ); ?>>
                                                                <?php echo esc_html(
                                                                        $teacher['name']
                                                                ); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </label>

                                        <label>
                                                Capacity
                                                <input type="number"
                                                       min="1"
                                                       name="capacity"
                                                       required
                                                       value="<?php echo esc_attr(
                                                               $row['capacity'] ?? 1
                                                       ); ?>">
                                        </label>

                                        <label>
                                                Start Time
                                                <input type="time"
                                                       name="start_time"
                                                       required
                                                       value="<?php echo esc_attr(
                                                               $row['start_time'] ?? ''
                                                       ); ?>">
                                        </label>

                                        <label>
                                                End Time
                                                <input type="time"
                                                       name="end_time"
                                                       required
                                                       value="<?php echo esc_attr(
                                                               $row['end_time'] ?? ''
                                                       ); ?>">
                                        </label>

                                        <label>
                                                Start Date
                                                <input type="date"
                                                       name="start_date"
                                                       required
                                                       value="<?php echo esc_attr(
                                                               $row['start_date'] ?? ''
                                                       ); ?>">
                                        </label>

                                        <label>
                                                End Date
                                                <input type="date"
                                                       name="end_date"
                                                       value="<?php echo esc_attr(
                                                               $row['end_date'] ?? ''
                                                       ); ?>">
                                        </label>

                                        <label>
                                                Timezone
                                                <input name="timezone"
                                                       value="<?php echo esc_attr(
                                                               $row['timezone']
                                                               ?? 'Asia/Kolkata'
                                                       ); ?>">
                                        </label>

                                        <label>
                                                Class Type
                                                <select name="class_type">
                                                        <?php
                                                        foreach (
                                                                EduFlow_Admission_Service::CLASS_TYPES
                                                                as $type
                                                        ) :
                                                        ?>
                                                        <option value="<?php echo esc_attr($type); ?>"
                                                                <?php selected(
                                                                        $row['class_type'] ?? 'group',
                                                                        $type
                                                                ); ?>>
                                                                <?php echo esc_html(
                                                                        ucwords(
                                                                                str_replace(
                                                                                        '-',
                                                                                        ' ',
                                                                                        $type
                                                                                )
                                                                        )
                                                                ); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </label>

                                        <fieldset class="eduflow-days-field">
                                                <legend>Running Days</legend>

                                                <div class="eduflow-day-grid">

                                                <?php
                                                foreach (
                                                        array(
                                                                'mon',
                                                                'tue',
                                                                'wed',
                                                                'thu',
                                                                'fri',
                                                                'sat',
                                                                'sun'
                                                        )
                                                        as $day
                                                ) :
                                                ?>
                                                        <label>
                                                                <input type="checkbox"
                                                                       name="days_of_week[]"
                                                                       value="<?php echo esc_attr($day); ?>"
                                                                       <?php checked(
                                                                               in_array(
                                                                                       $day,
                                                                                       $days,
                                                                                       true
                                                                               )
                                                                       ); ?>>
                                                                <?php echo esc_html(
                                                                        ucfirst($day)
                                                                ); ?>
                                                        </label>
                                                <?php endforeach; ?>

                                                </div>
                                        </fieldset>

                                </div>

                                <?php
                                submit_button(
                                        $id
                                                ? 'Update Batch'
                                                : 'Create Batch'
                                );
                                ?>

                        </form>
                </div>
                <?php
        }
        private static function assignment_form() {

                global $wpdb;

                $institute = EduFlow_Settings::institute_db_id();
                $students  = EduFlow_DB::table('students');
                $batches   = EduFlow_DB::table('batches');

                $student_rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id,canonical_id,name,mobile
                                 FROM $students
                                 WHERE institute_id=%d
                                 AND status='active'
                                 AND student_status NOT IN
                                     ('dropped','cancelled','completed')
                                 ORDER BY name ASC",
                                $institute
                        ),
                        ARRAY_A
                );

                $batch_rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id,canonical_id,batch_name,batch_status
                                 FROM $batches
                                 WHERE institute_id=%d
                                 AND batch_status<>'closed'
                                 ORDER BY start_time ASC,batch_name ASC",
                                $institute
                        ),
                        ARRAY_A
                );

                ?>

                <div class="eduflow-card">

                        <h2>Student Batch Assignment</h2>

                        <p class="description">
                                Assign, remove or transfer a student without
                                entering raw database IDs.
                        </p>

                        <form method="post"
                              action="<?php echo esc_url(
                                      admin_url('admin-post.php')
                              ); ?>"
                              onsubmit="return confirm('Confirm this student batch operation?');">

                                <?php
                                wp_nonce_field(
                                        'eduflow_batch_student'
                                );
                                ?>

                                <input type="hidden"
                                       name="action"
                                       value="eduflow_batch_student">

                                <div class="eduflow-form-grid">

                                        <label>
                                                Operation

                                                <select name="operation"
                                                        id="eduflow-operation">

                                                        <option value="assign">
                                                                Assign
                                                        </option>

                                                        <option value="remove">
                                                                Remove
                                                        </option>

                                                        <option value="transfer">
                                                                Transfer
                                                        </option>

                                                </select>
                                        </label>

                                        <label>
                                                Student

                                                <select name="student_id"
                                                        required>

                                                        <option value="">
                                                                Select Student
                                                        </option>

                                                        <?php
                                                        foreach (
                                                                $student_rows
                                                                as $student
                                                        ) :
                                                        ?>

                                                        <option value="<?php echo esc_attr(
                                                                $student['id']
                                                        ); ?>">
                                                                <?php
                                                                echo esc_html(
                                                                        $student['name'] .
                                                                        ' — ' .
                                                                        $student['canonical_id'] .
                                                                        ' — ' .
                                                                        $student['mobile']
                                                                );
                                                                ?>
                                                        </option>

                                                        <?php endforeach; ?>

                                                </select>
                                        </label>

                                        <label>
                                                Current / Target Batch

                                                <select name="batch_id"
                                                        required>

                                                        <option value="">
                                                                Select Batch
                                                        </option>

                                                        <?php
                                                        foreach (
                                                                $batch_rows
                                                                as $batch
                                                        ) :
                                                        ?>

                                                        <option value="<?php echo esc_attr(
                                                                $batch['id']
                                                        ); ?>">
                                                                <?php
                                                                echo esc_html(
                                                                        $batch['batch_name'] .
                                                                        ' — ' .
                                                                        $batch['canonical_id']
                                                                );
                                                                ?>
                                                        </option>

                                                        <?php endforeach; ?>

                                                </select>
                                        </label>

                                        <label>
                                                Transfer To Batch

                                                <select name="target_batch_id">

                                                        <option value="">
                                                                Not Required
                                                        </option>

                                                        <?php
                                                        foreach (
                                                                $batch_rows
                                                                as $batch
                                                        ) :
                                                        ?>

                                                        <option value="<?php echo esc_attr(
                                                                $batch['id']
                                                        ); ?>">
                                                                <?php echo esc_html(
                                                                        $batch['batch_name']
                                                                ); ?>
                                                        </option>

                                                        <?php endforeach; ?>

                                                </select>
                                        </label>

                                </div>

                                <?php
                                submit_button(
                                        'Apply Student Assignment',
                                        'primary'
                                );
                                ?>

                        </form>

                </div>

                <?php
        }
	public function save_batch(){self::guard('eduflow_manage_batches');check_admin_referer('eduflow_save_batch');$input=wp_unslash($_POST);$id=absint($input['record']??0);$result=$id?EduFlow_Batch_Service::update($id,$input):EduFlow_Batch_Service::create($input);$this->redirect('eduflow-batches',$result,$id?'Batch updated.':'Batch created.');}
        public function batch_status() {

                self::guard(
                        'eduflow_manage_batches'
                );

                $id = absint(
                        $_REQUEST['record'] ?? 0
                );

                check_admin_referer(
                        'eduflow_batch_status_' . $id
                );

                $status = sanitize_key(
                        $_REQUEST['status'] ?? ''
                );

                $allowed = array(
                        'active',
                        'paused',
                        'closed'
                );

                if (
                        ! in_array(
                                $status,
                                $allowed,
                                true
                        )
                ) {
                        $result = new WP_Error(
                                'invalid_batch_status',
                                'Invalid batch status.'
                        );

                } else {

                        $result =
                                EduFlow_Batch_Service::set_status(
                                        $id,
                                        $status
                                );
                }

                $message = is_wp_error($result)
                        ? $result->get_error_message()
                        : 'Batch status updated to ' .
                          ucwords($status) .
                          '.';

                $this->redirect(
                        'eduflow-batches',
                        $result,
                        $message
                );
        }
	public function batch_student(){self::guard('eduflow_manage_batches');check_admin_referer('eduflow_batch_student');$operation=sanitize_key($_POST['operation']??'');$batch=absint($_POST['batch_id']??0);$student=absint($_POST['student_id']??0);if('assign'===$operation){$result=EduFlow_Batch_Service::assign_student($batch,$student);}elseif('remove'===$operation){$result=EduFlow_Batch_Service::remove_student($batch,$student);}elseif('transfer'===$operation){$result=EduFlow_Batch_Service::transfer_student($batch,absint($_POST['target_batch_id']??0),$student);}else{$result=new WP_Error('invalid_operation','Invalid assignment operation.');}$this->redirect('eduflow-batches',$result,'Student assignment updated.');}
	public static function classes(){self::guard('eduflow_manage_classes');global $wpdb;$institute=EduFlow_Settings::institute_db_id();$table=EduFlow_DB::table('classes');$google=EduFlow_DB::table('google_events');$search=sanitize_text_field(wp_unslash($_GET['s']??''));$status=sanitize_key($_GET['status']??'');$page=max(1,absint($_GET['paged']??1));$where=array('c.institute_id=%d');$params=array($institute);if($search){$like='%'.$wpdb->esc_like($search).'%';$where[]='(c.canonical_id LIKE %s OR c.schedule_key LIKE %s)';array_push($params,$like,$like);}if(in_array($status,EduFlow_Class_Service::STATUSES,true)){$where[]='c.class_status=%s';$params[]=$status;}$base=' FROM '.$table.' c LEFT JOIN '.$google.' g ON g.lecture_id=c.id AND g.institute_id=c.institute_id WHERE '.implode(' AND ',$where);$total=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*)'.$base,...$params));$params[]=20;$params[]=($page-1)*20;$rows=$wpdb->get_results($wpdb->prepare('SELECT c.*,g.sync_status AS google_sync_status,g.google_meet_url,g.google_event_url,g.last_error AS google_last_error'.$base.' ORDER BY c.start_datetime ASC LIMIT %d OFFSET %d',...$params),ARRAY_A);self::notice();?><div class="wrap eduflow-wrap"><h1>Classes</h1><form method="get"><input type="hidden" name="page" value="eduflow-classes"><input type="search" name="s" value="<?php echo esc_attr($search);?>" placeholder="Lecture ID"><select name="status"><option value="">All statuses</option><?php foreach(EduFlow_Class_Service::STATUSES as $v):?><option value="<?php echo esc_attr($v);?>" <?php selected($status,$v);?>><?php echo esc_html(ucfirst($v));?></option><?php endforeach;?></select><?php submit_button('Filter','secondary','',false);?></form><table class="widefat striped"><thead><tr><th>ID</th><th>Batch</th><th>Teacher</th><th>Student</th><th>Date</th><th>UTC Time</th><th>Status</th><th>Google Sync</th><th>Meet</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><?php echo esc_html($row['canonical_id']);?></td><td><?php echo esc_html($row['batch_id']);?></td><td><?php echo esc_html($row['teacher_id']);?></td><td><?php echo esc_html($row['student_id']);?></td><td><?php echo esc_html($row['class_date'].' '.$row['timezone']);?></td><td><?php echo esc_html($row['start_datetime'].'–'.$row['end_datetime']);?></td><td><?php echo esc_html($row['class_status']);?></td><td><?php echo esc_html($row['google_sync_status']?:'not_required');?>
<?php if(!empty($row['google_last_error'])):?><div style="color:#b32d2e;font-size:11px;max-width:260px"><?php echo esc_html($row['google_last_error']);?></div><?php endif;?></td><td><?php if($row['google_meet_url']):?><a href="<?php echo esc_url($row['google_meet_url']);?>" target="_blank" rel="noopener noreferrer">Open Meet</a><?php else:?>Unavailable<?php endif;?></td><td><a href="<?php echo esc_url(add_query_arg(array('page'=>'eduflow-classes','edit'=>$row['id']),admin_url('admin.php')));?>#class-form">Reschedule</a> <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=eduflow_class_action&record='.$row['id'].'&do=cancel'),'eduflow_class_action_'.$row['id']));?>">Cancel</a> <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=eduflow_google_sync&record='.$row['id']),'eduflow_google_sync_'.$row['id']));?>"><?php echo 'failed'===$row['google_sync_status']?'Retry Sync':'Sync Google';?></a> <?php if($row['google_event_url']):?><a href="<?php echo esc_url($row['google_event_url']);?>" target="_blank" rel="noopener noreferrer">Open Event</a><?php endif;?></td></tr><?php endforeach;?></tbody></table><?php self::pages($total,$page);self::class_forms();?></div><?php }
        private static function class_forms() {
                global $wpdb;

                $institute = EduFlow_Settings::institute_db_id();

                $id = absint( $_GET['edit'] ?? 0 );
                $row = $id
                        ? EduFlow_Class_Service::get( $id )
                        : array();

                $row = is_array( $row ) ? $row : array();

                $batches_table  = EduFlow_DB::table( 'batches' );
                $teachers_table = EduFlow_DB::table( 'teachers' );

                $batches = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT
                                        id,
                                        canonical_id,
                                        batch_name,
                                        start_time,
                                        end_time,
                                        batch_status
                                 FROM $batches_table
                                 WHERE institute_id=%d
                                 AND batch_status='active'
                                 ORDER BY start_time ASC,batch_name ASC",
                                $institute
                        ),
                        ARRAY_A
                );

                $teachers = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT
                                        id,
                                        canonical_id,
                                        name,
                                        teacher_status
                                 FROM $teachers_table
                                 WHERE institute_id=%d
                                 AND teacher_status='active'
                                 AND status='active'
                                 ORDER BY name ASC",
                                $institute
                        ),
                        ARRAY_A
                );
                ?>

                <div class="eduflow-card">
                        <h2>Generate Rolling Schedule</h2>

                        <p class="description">
                                Select a running batch. EduFlow will generate
                                the next 14 days without creating duplicate lectures.
                        </p>

                        <form method="post"
                              action="<?php echo esc_url(
                                      admin_url( 'admin-post.php' )
                              ); ?>"
                              onsubmit="return confirm('Generate the next 14 days of lectures for this batch?');">

                                <input type="hidden"
                                       name="action"
                                       value="eduflow_generate_classes">

                                <?php wp_nonce_field( 'eduflow_generate_classes' ); ?>

                                <label>
                                        Batch

                                        <select name="batch_id" required>
                                                <option value="">
                                                        Select Batch
                                                </option>

                                                <?php foreach ( $batches as $batch ) : ?>
                                                        <option value="<?php echo esc_attr(
                                                                $batch['id']
                                                        ); ?>">
                                                                <?php echo esc_html(
                                                                        $batch['batch_name'] .
                                                                        ' — ' .
                                                                        $batch['canonical_id'] .
                                                                        ' (' .
                                                                        substr(
                                                                                $batch['start_time'],
                                                                                0,
                                                                                5
                                                                        ) .
                                                                        '–' .
                                                                        substr(
                                                                                $batch['end_time'],
                                                                                0,
                                                                                5
                                                                        ) .
                                                                        ')'
                                                                ); ?>
                                                        </option>
                                                <?php endforeach; ?>
                                        </select>
                                </label>

                                <?php
                                submit_button(
                                        'Generate Next 14 Days',
                                        'secondary',
                                        '',
                                        false
                                );
                                ?>

                        </form>
                </div>

                <?php if ( $row ) : ?>

                <div class="eduflow-card"
                     id="class-form">

                        <h2>
                                Reschedule
                                <?php echo esc_html(
                                        $row['canonical_id']
                                ); ?>
                        </h2>

                        <form method="post"
                              action="<?php echo esc_url(
                                      admin_url( 'admin-post.php' )
                              ); ?>"
                              onsubmit="return confirm('Confirm this lecture reschedule?');">

                                <input type="hidden"
                                       name="action"
                                       value="eduflow_class_action">

                                <input type="hidden"
                                       name="do"
                                       value="reschedule">

                                <input type="hidden"
                                       name="record"
                                       value="<?php echo esc_attr($id); ?>">

                                <?php
                                wp_nonce_field(
                                        'eduflow_class_action_' . $id
                                );
                                ?>

                                <div class="eduflow-form-grid">

                                        <label>
                                                Date
                                                <input type="date"
                                                       name="class_date"
                                                       required
                                                       value="<?php echo esc_attr(
                                                               $row['class_date']
                                                       ); ?>">
                                        </label>

                                        <label>
                                                Teacher

                                                <select name="teacher_id" required>
                                                        <option value="">
                                                                Select Teacher
                                                        </option>

                                                        <?php foreach ( $teachers as $teacher ) : ?>

                                                        <option
                                                            value="<?php echo esc_attr(
                                                                    $teacher['id']
                                                            ); ?>"
                                                            <?php selected(
                                                                    (int)($row['teacher_id'] ?? 0),
                                                                    (int)$teacher['id']
                                                            ); ?>>

                                                                <?php echo esc_html(
                                                                        $teacher['name'] .
                                                                        ' — ' .
                                                                        $teacher['canonical_id']
                                                                ); ?>

                                                        </option>

                                                        <?php endforeach; ?>

                                                </select>
                                        </label>

                                        <label>
                                                Start Time
                                                <input type="time"
                                                       name="start_time"
                                                       required
                                                       value="<?php
                                                       if ( ! empty($row['start_datetime']) ) {
                                                               echo esc_attr(
                                                                       gmdate(
                                                                               'H:i',
                                                                               strtotime(
                                                                                       $row['start_datetime']
                                                                               )
                                                                       )
                                                               );
                                                       }
                                                       ?>">
                                        </label>

                                        <label>
                                                End Time
                                                <input type="time"
                                                       name="end_time"
                                                       required
                                                       value="<?php
                                                       if ( ! empty($row['end_datetime']) ) {
                                                               echo esc_attr(
                                                                       gmdate(
                                                                               'H:i',
                                                                               strtotime(
                                                                                       $row['end_datetime']
                                                                               )
                                                                       )
                                                               );
                                                       }
                                                       ?>">
                                        </label>

                                </div>

                                <?php
                                submit_button(
                                        'Reschedule Lecture',
                                        'primary'
                                );
                                ?>

                        </form>
                </div>

                <?php endif;
        }
	public function generate_classes(){self::guard('eduflow_manage_classes');check_admin_referer('eduflow_generate_classes');$result=EduFlow_Class_Service::generate_batch(absint($_POST['batch_id']??0));$message=is_wp_error($result)?'':sprintf('%d created, %d already present, %d conflicts.', $result['created'],$result['skipped'],count($result['conflicts']));$this->redirect('eduflow-classes',$result,$message);}
	public function class_action(){self::guard('eduflow_manage_classes');$id=absint($_REQUEST['record']??0);check_admin_referer('eduflow_class_action_'.$id);$do=sanitize_key($_REQUEST['do']??'');$result='cancel'===$do?EduFlow_Class_Service::cancel($id):('reschedule'===$do?EduFlow_Class_Service::reschedule($id,wp_unslash($_POST)):new WP_Error('invalid_action','Invalid class action.'));$this->redirect('eduflow-classes',$result,'Lecture updated.');}
}
