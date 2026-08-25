<?php
defined( 'ABSPATH' ) || exit;
final class EduFlow_Control_Center {
	public function register() {
		add_action( 'admin_post_eduflow_link_account', array( $this, 'link_account' ) );
		add_action( 'admin_post_eduflow_invite_student', array( $this, 'invite_student' ) );
		add_action( 'admin_post_eduflow_renew_access', array( $this, 'renew_access' ) );
	}
	private static function guard() { if ( ! current_user_can( 'eduflow_manage_students' ) ) { wp_die( esc_html__( 'You cannot access the Control Center.', 'eduflow-core' ) ); } }
	public static function page() {
		self::guard();
		$metrics = EduFlow_Dashboard_Service::metrics();
		$search=sanitize_text_field(wp_unslash($_GET['s']??'')); $results=EduFlow_Dashboard_Service::search($search);
		$cards = array(
			'today_classes'=>array("Today's Classes",'eduflow-classes'),'upcoming_classes'=>array('Upcoming Classes','eduflow-classes'),
			'active_students'=>array('Active Students','eduflow-students'),'active_teachers'=>array('Active Teachers','eduflow-teachers'),
			'active_batches'=>array('Active Batches','eduflow-batches'),'demo_sessions_today'=>array('Demo Sessions Today','eduflow-demos'),
			'demo_bookings_today'=>array('Demo Bookings Today','eduflow-demos'),'pending_admissions'=>array('Pending Admissions','eduflow-admissions'),
			'pending_payments'=>array('Pending Payments','eduflow-payments'),'fee_due'=>array('Fee Due','eduflow-students'),
			'expired_access'=>array('Expired Access','eduflow-students'),'failed_google_sync'=>array('Failed Google Sync','eduflow-classes'),
		);
		?><div class="wrap eduflow-wrap"><h1>EduFlow Control Center</h1><?php if(!empty($_GET['eduflow_notice'])):?><div class="notice notice-<?php echo esc_attr('error'===($_GET['notice_type']??'')?'error':'success');?>"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['eduflow_notice'])));?></p></div><?php endif;?><form method="get"><input type="hidden" name="page" value="eduflow"><input class="regular-text" type="search" name="s" value="<?php echo esc_attr($search);?>" placeholder="Search IDs, names, mobile, course, status"><?php submit_button('Search All','secondary','',false);?></form><?php self::search_results($results);?><div class="eduflow-grid"><?php foreach($cards as $key=>$card):?><a class="eduflow-card" href="<?php echo esc_url(admin_url('admin.php?page='.$card[1]));?>"><h2><?php echo esc_html($metrics[$key]);?></h2><p><?php echo esc_html($card[0]);?></p></a><?php endforeach;?><a class="eduflow-card" href="<?php echo esc_url(admin_url('admin.php?page=eduflow-health'));?>"><h2>Health</h2><p>System Health</p></a></div><?php self::operations();?></div><?php
	}
	private static function search_results($results) { if(!$results){return;} ?><div class="eduflow-card"><h2>Search Results</h2><?php foreach($results as $type=>$rows):if(!$rows){continue;}?><h3><?php echo esc_html(ucwords(str_replace('_',' ',$type)));?></h3><table class="widefat striped"><tbody><?php foreach($rows as $row):?><tr><td><?php echo esc_html($row['reference']);?></td><td><?php echo esc_html($row['label']);?></td><td><?php echo esc_html($row['detail']);?></td></tr><?php endforeach;?></tbody></table><?php endforeach;?></div><?php }

    private static function operations() {
        global $wpdb;

        $institute = EduFlow_Settings::institute_db_id();

        $students_table   = EduFlow_DB::table('students');
        $teachers_table   = EduFlow_DB::table('teachers');
        $admissions_table = EduFlow_DB::table('admissions');

        $students = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,canonical_id,name,mobile
                 FROM $students_table
                 WHERE institute_id=%d
                 AND status='active'
                 AND student_status NOT IN ('dropped','cancelled','completed')
                 ORDER BY name ASC",
                $institute
            ),
            ARRAY_A
        );

        $teachers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,canonical_id,name,mobile
                 FROM $teachers_table
                 WHERE institute_id=%d
                 AND status='active'
                 AND teacher_status<>'inactive'
                 ORDER BY name ASC",
                $institute
            ),
            ARRAY_A
        );

        $admissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,canonical_id,student_name,mobile,admission_status
                 FROM $admissions_table
                 WHERE institute_id=%d
                 AND status='active'
                 ORDER BY id DESC
                 LIMIT 300",
                $institute
            ),
            ARRAY_A
        );

        $wp_users = get_users(
            array(
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'fields'  => array('ID','display_name','user_email'),
            )
        );

        $links = array(
            'Admissions'               => 'eduflow-admissions',
            'Students'                 => 'eduflow-students',
            'Teachers'                 => 'eduflow-teachers',
            'Batches'                  => 'eduflow-batches',
            'Classes'                  => 'eduflow-classes',
            'Demo Sessions & Bookings' => 'eduflow-demos',
            'Payments & Renewals'       => 'eduflow-payments',
            'Google Sync'               => 'eduflow-classes',
        );
        ?>

        <div class="eduflow-card">

            <h2>Operations</h2>

            <p>
                <?php foreach ( $links as $label => $page ) : ?>
                    <a class="button"
                       href="<?php echo esc_url(
                           admin_url('admin.php?page=' . $page)
                       ); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </p>

            <hr>

            <h3>Secure Account Linking</h3>

            <form method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('Confirm this account link?');">

                <input type="hidden"
                       name="action"
                       value="eduflow_link_account">

                <?php wp_nonce_field('eduflow_link_account'); ?>

                <select name="entity_ref" required>

                    <option value="">
                        Select Student or Teacher
                    </option>

                    <optgroup label="Students">

                        <?php foreach ( $students as $student ) : ?>

                            <option value="<?php echo esc_attr(
                                'student:' . $student['id']
                            ); ?>">
                                <?php echo esc_html(
                                    $student['name'] .
                                    ' — ' .
                                    $student['canonical_id'] .
                                    ' — ' .
                                    $student['mobile']
                                ); ?>
                            </option>

                        <?php endforeach; ?>

                    </optgroup>

                    <optgroup label="Teachers">

                        <?php foreach ( $teachers as $teacher ) : ?>

                            <option value="<?php echo esc_attr(
                                'teacher:' . $teacher['id']
                            ); ?>">
                                <?php echo esc_html(
                                    $teacher['name'] .
                                    ' — ' .
                                    $teacher['canonical_id'] .
                                    ' — ' .
                                    $teacher['mobile']
                                ); ?>
                            </option>

                        <?php endforeach; ?>

                    </optgroup>

                </select>

                <select name="wp_user_id" required>

                    <option value="">
                        Select WordPress Account
                    </option>

                    <?php foreach ( $wp_users as $user ) : ?>

                        <option value="<?php echo esc_attr($user->ID); ?>">
                            <?php echo esc_html(
                                $user->display_name .
                                ' — ' .
                                $user->user_email
                            ); ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <button class="button button-primary">
                    Link Existing Account
                </button>

            </form>

            <hr>

            <h3>Student Activation</h3>

            <form method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('Send activation to selected student?');">

                <input type="hidden"
                       name="action"
                       value="eduflow_invite_student">

                <?php wp_nonce_field('eduflow_invite_student'); ?>

                <select name="student_id" required>

                    <option value="">
                        Select Student
                    </option>

                    <?php foreach ( $students as $student ) : ?>

                        <option value="<?php echo esc_attr($student['id']); ?>">
                            <?php echo esc_html(
                                $student['name'] .
                                ' — ' .
                                $student['canonical_id'] .
                                ' — ' .
                                $student['mobile']
                            ); ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <button class="button">
                    Send Student Activation
                </button>

            </form>

            <hr>

            <h3>Verify Payment & Renew Access</h3>

            <form method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('Record, verify and renew access?');">

                <input type="hidden"
                       name="action"
                       value="eduflow_renew_access">

                <?php wp_nonce_field('eduflow_renew_access'); ?>

                <select name="admission_id" required>

                    <option value="">
                        Select Admission
                    </option>

                    <?php foreach ( $admissions as $admission ) : ?>

                        <option value="<?php echo esc_attr($admission['id']); ?>">
                            <?php echo esc_html(
                                $admission['student_name'] .
                                ' — ' .
                                $admission['canonical_id'] .
                                ' — ' .
                                $admission['mobile'] .
                                ' — ' .
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $admission['admission_status']
                                    )
                                )
                            ); ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <input type="number"
                       min="0.01"
                       step="0.01"
                       name="amount"
                       placeholder="Amount"
                       required>

                <select name="payment_method">
                    <option value="upi">UPI</option>
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="card">Card</option>
                    <option value="other">Other</option>
                </select>

                <input name="transaction_reference"
                       placeholder="Transaction / UTR">

                <select name="access_period">
                    <option value="1_month">1 Month</option>
                    <option value="3_months">3 Months</option>
                    <option value="6_months">6 Months</option>
                </select>

                <button class="button button-primary">
                    Record, Verify & Renew
                </button>

            </form>

        </div>

        <?php
    }
	private function done($result,$message){$error=is_wp_error($result);wp_safe_redirect(add_query_arg(array('page'=>'eduflow','eduflow_notice'=>rawurlencode($error?$result->get_error_message():$message),'notice_type'=>$error?'error':'success'),admin_url('admin.php')));exit;}
    public function link_account() {
        self::guard();

        check_admin_referer('eduflow_link_account');

        $entity_ref = sanitize_text_field(
            wp_unslash($_POST['entity_ref'] ?? '')
        );

        if (
            ! preg_match(
                '/^(student|teacher):([0-9]+)$/',
                $entity_ref,
                $matches
            )
        ) {
            $this->done(
                new WP_Error(
                    'invalid_entity',
                    'Select a valid Student or Teacher.'
                ),
                ''
            );
        }

        $entity_type = $matches[1];
        $entity_id   = absint($matches[2]);
        $wp_user_id  = absint($_POST['wp_user_id'] ?? 0);

        if ( ! get_user_by('id', $wp_user_id) ) {
            $this->done(
                new WP_Error(
                    'invalid_wp_user',
                    'Selected WordPress account was not found.'
                ),
                ''
            );
        }

        $institute = EduFlow_Settings::institute_db_id();

        if ( 'student' === $entity_type ) {
            $entity = EduFlow_Student_Service::get(
                $entity_id,
                $institute
            );
        } else {
            $entity = EduFlow_Teacher_Service::get(
                $entity_id,
                $institute
            );
        }

        if ( ! $entity ) {
            $this->done(
                new WP_Error(
                    'entity_not_found',
                    'Selected EduFlow profile was not found.'
                ),
                ''
            );
        }

        $this->done(
            EduFlow_Account_Link_Service::link(
                $entity_type,
                $entity_id,
                $wp_user_id
            ),
            'Account linked.'
        );
    }
	public function invite_student(){self::guard();check_admin_referer('eduflow_invite_student');$this->done(EduFlow_Account_Link_Service::create_student_invitation(absint($_POST['student_id']??0)),'Activation email requested.');}
	public function renew_access(){self::guard();if(!current_user_can('eduflow_manage_payments')){wp_die(esc_html__('You cannot renew access.','eduflow-core'));}check_admin_referer('eduflow_renew_access');$input=wp_unslash($_POST);$input['payment_date']=current_time('Y-m-d');$payment=EduFlow_Payment_Service::create($input);if(is_wp_error($payment)){$this->done($payment,'');}$result=EduFlow_Payment_Service::decide($payment,'verified');if(!is_wp_error($result)){EduFlow_Audit_Service::log('access_renewed','payment',$payment,null,array('period'=>sanitize_key($input['access_period']??'')),EduFlow_Settings::institute_db_id());}$this->done($result,'Payment verified and access renewed.');}

}
