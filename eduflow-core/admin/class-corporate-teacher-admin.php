<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Corporate_Teacher_Admin {

    public static function register() {

        add_action(
            'admin_post_eduflow_corp_save_teacher',
            array(
                __CLASS__,
                'save_teacher'
            )
        );

        add_action(
            'admin_post_eduflow_corp_teacher_status',
            array(
                __CLASS__,
                'teacher_status'
            )
        );

        add_action(
            'admin_post_eduflow_corp_teacher_batch',
            array(
                __CLASS__,
                'teacher_batch'
            )
        );
    }

    private static function guard() {
        if (
            ! current_user_can(
                'eduflow_manage_teachers'
            )
        ) {
            wp_die(
                esc_html__(
                    'Permission denied.',
                    'eduflow-core'
                )
            );
        }
    }

    private static function redirect(
        $message,
        $error = false
    ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' =>
                        'eduflow-teachers',

                    'eduflow_notice' =>
                        rawurlencode(
                            $message
                        ),

                    'notice_type' =>
                        $error
                        ? 'error'
                        : 'success',
                ),
                admin_url(
                    'admin.php'
                )
            )
        );

        exit;
    }

    public static function page() {

        self::guard();

        global $wpdb;

        $institute =
            EduFlow_Settings::institute_db_id();

        $teachers =
            EduFlow_DB::table(
                'teachers'
            );

        $batches =
            EduFlow_DB::table(
                'batches'
            );

        $search =
            sanitize_text_field(
                wp_unslash(
                    $_GET['s'] ?? ''
                )
            );

        $status =
            sanitize_key(
                $_GET[
                    'teacher_status'
                ] ?? ''
            );

        $where = array(
            't.institute_id=%d'
        );

        $params = array(
            $institute
        );

        if ( $search ) {

            $like =
                '%' .
                $wpdb->esc_like(
                    $search
                ) .
                '%';

            $where[] =
                '(t.canonical_id LIKE %s
                  OR t.name LIKE %s
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

        if ( $status ) {
            $where[] =
                't.teacher_status=%s';

            $params[] =
                $status;
        }

        $rows =
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        t.*,
                        (
                            SELECT COUNT(*)
                            FROM $batches b
                            WHERE b.institute_id=t.institute_id
                            AND b.teacher_id=t.id
                            AND b.batch_status='active'
                        ) active_batches,
                        (
                            SELECT GROUP_CONCAT(
                                CONCAT(
                                    b.batch_name,
                                    ' | ',
                                    TIME_FORMAT(b.start_time,'%h:%i %p'),
                                    '–',
                                    TIME_FORMAT(b.end_time,'%h:%i %p')
                                )
                                ORDER BY b.start_time
                                SEPARATOR ' || '
                            )
                            FROM $batches b
                            WHERE b.institute_id=t.institute_id
                            AND b.teacher_id=t.id
                            AND b.batch_status='active'
                        ) assigned_batch_names
                     FROM $teachers t
                     WHERE " .
                     implode(
                        ' AND ',
                        $where
                     ) .
                     " ORDER BY t.name",
                    ...$params
                ),
                ARRAY_A
            );

        $active_batches =
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        id,
                        batch_name,
                        start_time,
                        end_time,
                        teacher_id
                     FROM $batches
                     WHERE institute_id=%d
                     AND batch_status='active'
                     ORDER BY start_time",
                    $institute
                ),
                ARRAY_A
            );

        ?>
        <div class="wrap eduflow-wrap">

            <h1>
                Teacher Control Center
            </h1>

            <form method="get"
                  class="eduflow-teacher-toolbar">

                <input type="hidden"
                       name="page"
                       value="eduflow-teachers">

                <input type="search"
                       name="s"
                       placeholder="Search teacher"
                       value="<?php echo esc_attr(
                           $search
                       ); ?>">

                <select name="teacher_status">

                    <option value="">
                        All Status
                    </option>

                    <?php foreach (
                        EduFlow_Teacher_Service::STATUSES
                        as $teacher_status
                    ) : ?>

                    <option
                        value="<?php echo esc_attr(
                            $teacher_status
                        ); ?>"
                        <?php selected(
                            $status,
                            $teacher_status
                        ); ?>>

                        <?php echo esc_html(
                            ucwords(
                                str_replace(
                                    '_',
                                    ' ',
                                    $teacher_status
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

            </form>

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>Teacher</th>
                        <th>Contact</th>
                        <th>Availability</th>
                        <th>Batches</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach (
                    $rows
                    as $row
                ) : ?>

                <tr>

                    <td>
                        <strong>
                        <?php echo esc_html(
                            $row['name']
                        ); ?>
                        </strong>

                        <div class="eduflow-muted">
                        <?php echo esc_html(
                            $row[
                                'canonical_id'
                            ]
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
                        <?php echo esc_html(
                            (
                                $row[
                                    'available_start_time'
                                ]
                                &&
                                $row[
                                    'available_end_time'
                                ]
                            )
                            ?
                            substr(
                                $row[
                                    'available_start_time'
                                ],
                                0,
                                5
                            )
                            .
                            ' – ' .
                            substr(
                                $row[
                                    'available_end_time'
                                ],
                                0,
                                5
                            )
                            :
                            'Not Set'
                        ); ?>

                        <div class="eduflow-muted">
                        <?php echo esc_html(
                            $row[
                                'available_days'
                            ]
                            ?: 'No days'
                        ); ?>
                        </div>
                    </td>

                    <td>
                        <strong>
                            <?php echo esc_html(
                                (int) $row['active_batches']
                            ); ?>
                        </strong>

                        <?php if ( ! empty( $row['assigned_batch_names'] ) ) : ?>

                            <div style="margin-top:6px;line-height:1.5;">
                                <?php
                                $assigned_batches = explode(
                                    ' || ',
                                    $row['assigned_batch_names']
                                );

                                foreach ( $assigned_batches as $assigned_batch ) :
                                ?>
                                    <div>
                                        <?php echo esc_html( $assigned_batch ); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                        <?php else : ?>

                            <div class="eduflow-muted">
                                No batch assigned
                            </div>

                        <?php endif; ?>
                    </td>

                    <td>
                        <strong>
                        <?php echo esc_html(
                            ucwords(
                                str_replace(
                                    '_',
                                    ' ',
                                    $row[
                                        'teacher_status'
                                    ]
                                )
                            )
                        ); ?>
                        </strong>
                    </td>

                    <td>

                        <a class="button button-small"
                           href="<?php echo esc_url(
                               add_query_arg(
                                   array(
                                       'page' =>
                                           'eduflow-teachers',

                                       'edit' =>
                                           $row['id'],
                                   ),
                                   admin_url(
                                       'admin.php'
                                   )
                               )
                           ); ?>#teacher-form">
                            Edit
                        </a>

                        <?php if (
                            'active' ===
                            $row[
                                'teacher_status'
                            ]
                        ) : ?>

                        <a class="button button-small"
                           onclick="return confirm('Put teacher on leave?');"
                           href="<?php echo esc_url(
                               wp_nonce_url(
                                   admin_url(
                                       'admin-post.php?action=eduflow_corp_teacher_status&record=' .
                                       $row['id'] .
                                       '&status=on_leave'
                                   ),
                                   'eduflow_corp_teacher_status_' .
                                   $row['id']
                               )
                           ); ?>">
                            On Leave
                        </a>

                        <?php else : ?>

                        <a class="button button-small button-primary"
                           onclick="return confirm('Set teacher Active?');"
                           href="<?php echo esc_url(
                               wp_nonce_url(
                                   admin_url(
                                       'admin-post.php?action=eduflow_corp_teacher_status&record=' .
                                       $row['id'] .
                                       '&status=active'
                                   ),
                                   'eduflow_corp_teacher_status_' .
                                   $row['id']
                               )
                           ); ?>">
                            Set Active
                        </a>

                        <?php endif; ?>

                    </td>

                </tr>

                <?php endforeach; ?>

                </tbody>
            </table>

            <div class="eduflow-card">

                <h2>
                    Assign / Change Batch Teacher
                </h2>

                <form method="post"
                      action="<?php echo esc_url(
                          admin_url(
                              'admin-post.php'
                          )
                      ); ?>"
                      onsubmit="return confirm('Confirm teacher assignment?');">

                    <?php
                    wp_nonce_field(
                        'eduflow_corp_teacher_batch'
                    );
                    ?>

                    <input type="hidden"
                           name="action"
                           value="eduflow_corp_teacher_batch">

                    <select name="batch_id"
                            required>

                        <option value="">
                            Select Batch
                        </option>

                        <?php foreach (
                            $active_batches
                            as $batch
                        ) : ?>

                        <option
                            value="<?php echo esc_attr(
                                $batch['id']
                            ); ?>">

                            <?php echo esc_html(
                                $batch[
                                    'batch_name'
                                ] .
                                ' (' .
                                substr(
                                    $batch[
                                        'start_time'
                                    ],
                                    0,
                                    5
                                ) .
                                '–' .
                                substr(
                                    $batch[
                                        'end_time'
                                    ],
                                    0,
                                    5
                                ) .
                                ')'
                            ); ?>

                        </option>

                        <?php endforeach; ?>

                    </select>

                    <select name="teacher_id"
                            required>

                        <option value="">
                            Select Teacher
                        </option>

                        <?php foreach (
                            $rows
                            as $teacher
                        ) :

                            if (
                                'active' !==
                                $teacher[
                                    'teacher_status'
                                ]
                            ) {
                                continue;
                            }
                        ?>

                        <option
                            value="<?php echo esc_attr(
                                $teacher['id']
                            ); ?>">

                            <?php echo esc_html(
                                $teacher['name'] .
                                ' — ' .
                                $teacher[
                                    'canonical_id'
                                ]
                            ); ?>

                        </option>

                        <?php endforeach; ?>

                    </select>

                    <?php
                    submit_button(
                        'Assign Teacher',
                        'primary',
                        '',
                        false
                    );
                    ?>

                </form>

            </div>

            <?php self::teacher_form(); ?>

        </div>
        <?php
    }

    private static function teacher_form() {

        $id =
            absint(
                $_GET['edit'] ?? 0
            );

        $row =
            $id
            ?
            EduFlow_Teacher_Service::get(
                $id
            )
            :
            array();

        $row =
            is_array( $row )
            ? $row
            : array();

        $days =
            EduFlow_Teacher_Service::days(
                $row[
                    'available_days'
                ] ?? ''
            );

        ?>

        <div class="eduflow-card"
             id="teacher-form">

            <h2>
                <?php echo $id
                    ? 'Edit Teacher'
                    : 'Create Teacher'; ?>
            </h2>

            <form method="post"
                  action="<?php echo esc_url(
                      admin_url(
                          'admin-post.php'
                      )
                  ); ?>">

                <?php
                wp_nonce_field(
                    'eduflow_corp_save_teacher'
                );
                ?>

                <input type="hidden"
                       name="action"
                       value="eduflow_corp_save_teacher">

                <input type="hidden"
                       name="record"
                       value="<?php echo esc_attr(
                           $id
                       ); ?>">

                <div class="eduflow-form-grid">

                    <label>
                        Name
                        <input name="name"
                               required
                               value="<?php echo esc_attr(
                                   $row['name']
                                   ?? ''
                               ); ?>">
                    </label>

                    <label>
                        Mobile
                        <input name="mobile"
                               required
                               value="<?php echo esc_attr(
                                   $row['mobile']
                                   ?? ''
                               ); ?>">
                    </label>

                    <label>
                        Email
                        <input type="email"
                               name="email"
                               value="<?php echo esc_attr(
                                   $row['email']
                                   ?? ''
                               ); ?>">
                    </label>

                    <label>
                        Joining Date
                        <input type="date"
                               name="joining_date"
                               value="<?php echo esc_attr(
                                   $row[
                                       'joining_date'
                                   ]
                                   ?? current_time(
                                       'Y-m-d'
                                   )
                               ); ?>">
                    </label>

                    <label>
                        Available Start
                        <input type="time"
                               name="available_start_time"
                               value="<?php echo esc_attr(
                                   $row[
                                       'available_start_time'
                                   ]
                                   ?? ''
                               ); ?>">
                    </label>

                    <label>
                        Available End
                        <input type="time"
                               name="available_end_time"
                               value="<?php echo esc_attr(
                                   $row[
                                       'available_end_time'
                                   ]
                                   ?? ''
                               ); ?>">
                    </label>

                    <label>
                        Maximum Daily Classes
                        <input type="number"
                               min="1"
                               name="maximum_daily_classes"
                               value="<?php echo esc_attr(
                                   $row[
                                       'maximum_daily_classes'
                                   ]
                                   ?? 8
                               ); ?>">
                    </label>

                    <fieldset class="wide">
                        <legend>
                            Available Days
                        </legend>

                        <?php foreach (
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
                        ) : ?>

                        <label>
                            <input
                                type="checkbox"
                                name="available_days[]"
                                value="<?php echo esc_attr(
                                    $day
                                ); ?>"
                                <?php checked(
                                    in_array(
                                        $day,
                                        $days,
                                        true
                                    )
                                ); ?>>

                            <?php echo esc_html(
                                ucfirst(
                                    $day
                                )
                            ); ?>
                        </label>

                        <?php endforeach; ?>

                    </fieldset>

                    <label class="wide">
                        Notes
                        <textarea
                            name="notes"><?php echo esc_textarea(
                                $row['notes']
                                ?? ''
                            ); ?></textarea>
                    </label>

                </div>

                <?php
                submit_button(
                    $id
                    ? 'Update Teacher'
                    : 'Create Teacher'
                );
                ?>

            </form>

        </div>

        <?php
    }

    public static function save_teacher() {

        self::guard();

        check_admin_referer(
            'eduflow_corp_save_teacher'
        );

        $input =
            wp_unslash(
                $_POST
            );

        $id =
            absint(
                $input['record']
                ?? 0
            );

        $result =
            $id
            ?
            EduFlow_Teacher_Service::update(
                $id,
                $input
            )
            :
            EduFlow_Teacher_Service::create(
                $input
            );

        self::redirect(
            is_wp_error(
                $result
            )
            ?
            $result->get_error_message()
            :
            (
                $id
                ? 'Teacher updated successfully.'
                : 'Teacher created successfully.'
            ),
            is_wp_error(
                $result
            )
        );
    }

    public static function teacher_status() {

        self::guard();

        $id =
            absint(
                $_GET['record']
                ?? 0
            );

        check_admin_referer(
            'eduflow_corp_teacher_status_' .
            $id
        );

        $status =
            sanitize_key(
                $_GET['status']
                ?? ''
            );

        $result =
            EduFlow_Teacher_Service::set_status(
                $id,
                $status
            );

        self::redirect(
            is_wp_error(
                $result
            )
            ?
            $result->get_error_message()
            :
            'Teacher status updated.',
            is_wp_error(
                $result
            )
        );
    }

    public static function teacher_batch() {

        self::guard();

        check_admin_referer(
            'eduflow_corp_teacher_batch'
        );

        $teacher_id =
            absint(
                $_POST['teacher_id']
                ?? 0
            );

        $batch_id =
            absint(
                $_POST['batch_id']
                ?? 0
            );

        $result =
            EduFlow_Auto_Assignment_Service::assign_teacher_to_batch(
                $teacher_id,
                $batch_id
            );

        if ( is_wp_error( $result ) ) {

            self::redirect(
                $result->get_error_message(),
                true
            );
        }

        /*
         * Defensive second sync.
         * This is idempotent and ensures student profiles
         * reflect the verified batch teacher.
         */
        EduFlow_Corporate_Autoflow_Service::sync_batch_teacher_to_students(
            $batch_id
        );

        $message =
            sprintf(
                '%s assigned to %s. Students synchronized: %d.',
                $result['teacher_name'],
                $result['batch_name'],
                (int) $result['students_synced']
            );

        if (
            ! empty(
                $result['already_assigned']
            )
        ) {
            $message =
                sprintf(
                    '%s is already assigned to %s.',
                    $result['teacher_name'],
                    $result['batch_name']
                );
        }

        $lectures =
            $result['lectures']
            ?? array();

        if (
            is_wp_error(
                $lectures
            )
        ) {

            $message .=
                ' Lecture generation warning: ' .
                $lectures->get_error_message();

        } elseif (
            is_array(
                $lectures
            )
        ) {

            $created =
                (int) (
                    $lectures['created']
                    ?? 0
                );

            $skipped =
                (int) (
                    $lectures['skipped']
                    ?? 0
                );

            $conflicts =
                count(
                    $lectures['conflicts']
                    ?? array()
                );

            $message .=
                sprintf(
                    ' Lectures: %d created, %d existing, %d conflicts.',
                    $created,
                    $skipped,
                    $conflicts
                );

            if (
                $conflicts
                &&
                ! empty(
                    $lectures['conflicts'][0]
                )
            ) {
                $message .=
                    ' First conflict: ' .
                    sanitize_text_field(
                        $lectures['conflicts'][0]
                    );
            }
        }

        self::redirect(
            $message,
            false
        );
    }
}

EduFlow_Corporate_Teacher_Admin::register();
