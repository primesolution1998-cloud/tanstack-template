<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Admin {
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		foreach ( array( 'run_jobs', 'save_admission', 'bulk_import_admissions', 'admission_action', 'save_student', 'save_payment', 'payment_action', 'sync_approved_students') as $action ) {
			add_action( 'admin_post_eduflow_' . $action, array( $this, $action ) );
		}
	}
	public function menu() {
		add_menu_page( 'EduFlow', 'EduFlow', 'eduflow_manage_students', 'eduflow', array( 'EduFlow_Control_Center', 'page' ), 'dashicons-welcome-learn-more', 25 );
		add_submenu_page( 'eduflow', 'Dashboard', 'Dashboard', 'eduflow_manage_institute', 'eduflow-dashboard', array( $this, 'dashboard' ) );
		add_submenu_page( 'eduflow', 'Control Center', 'Control Center', 'eduflow_manage_students', 'eduflow', array( 'EduFlow_Control_Center', 'page' ) );
		add_submenu_page( 'eduflow', 'Admissions', 'Admissions', 'eduflow_manage_admissions', 'eduflow-admissions', array( $this, 'admissions' ) );
		add_submenu_page( 'eduflow', 'Demo Sessions', 'Demo Sessions', 'eduflow_manage_classes', 'eduflow-demos', array( 'EduFlow_Demo_Admin', 'page' ) );
		add_submenu_page( 'eduflow', 'Students', 'Students', 'eduflow_manage_students', 'eduflow-students', array( $this, 'students' ) );
		add_submenu_page( 'eduflow', 'Teachers', 'Teachers', 'eduflow_manage_teachers', 'eduflow-teachers', array( 'EduFlow_Corporate_Teacher_Admin', 'page' ) );
		add_submenu_page( 'eduflow', 'Batches', 'Batches', 'eduflow_manage_batches', 'eduflow-batches', array( 'EduFlow_Phase3_Admin', 'batches' ) );
		add_submenu_page( 'eduflow', 'Classes', 'Classes', 'eduflow_manage_classes', 'eduflow-classes', array( 'EduFlow_Phase3_Admin', 'classes' ) );
		add_submenu_page( 'eduflow', 'Attendance', 'Attendance', 'eduflow_manage_classes', 'eduflow-attendance', array( 'EduFlow_Phase6_Admin', 'attendance' ) );
		add_submenu_page( 'eduflow', 'Payments', 'Payments', 'eduflow_manage_payments', 'eduflow-payments', array( $this, 'payments' ) );
		add_submenu_page( 'eduflow', 'Notifications', 'Notifications', 'eduflow_manage_reports', 'eduflow-notifications', array( 'EduFlow_Phase6_Admin', 'notifications' ) );
		add_submenu_page( 'eduflow', 'Reports', 'Reports', 'eduflow_manage_reports', 'eduflow-reports', array( 'EduFlow_Phase6_Admin', 'reports' ) );
		add_submenu_page( 'eduflow', 'Migration Center', 'Migration Center', 'eduflow_manage_reports', 'eduflow-migration', array( 'EduFlow_Migration_Admin', 'page' ) );
		add_submenu_page( 'eduflow', 'Audit Log', 'Audit Log', 'eduflow_view_audit', 'eduflow-audit', array( $this, 'audit_log' ) );
		add_submenu_page( 'eduflow', 'System Health', 'System Health', 'eduflow_manage_institute', 'eduflow-health', array( $this, 'health' ) );
		add_submenu_page( 'eduflow', 'Settings', 'Settings', 'eduflow_manage_settings', 'eduflow-settings', array( $this, 'settings_page' ) );
	}
	public function settings() { register_setting( 'eduflow_core', 'eduflow_core_settings', array( 'type'=>'array', 'sanitize_callback'=>array( 'EduFlow_Settings', 'sanitize' ), 'default'=>EduFlow_Settings::defaults() ) ); }
	public function assets( $hook ) { if ( false !== strpos( $hook, 'eduflow' ) ) { wp_enqueue_style( 'eduflow-admin', plugins_url( 'assets/admin.css', EDUFLOW_CORE_FILE ), array(), EDUFLOW_CORE_VERSION ); } }
	private function guard( $cap ) { if ( ! current_user_can( $cap ) ) { wp_die( esc_html__( 'You do not have permission to access this page.', 'eduflow-core' ) ); } }
	private function redirect( $page, $message, $type='success' ) { wp_safe_redirect( add_query_arg( array( 'page'=>$page, 'eduflow_notice'=>rawurlencode( $message ), 'notice_type'=>$type ), admin_url( 'admin.php' ) ) ); exit; }
	private function notice() { if ( empty( $_GET['eduflow_notice'] ) ) { return; } $type='error'===($_GET['notice_type']??'')?'error':'success'; echo '<div class="notice notice-'.esc_attr($type).' is-dismissible"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['eduflow_notice']))).'</p></div>'; }
	public function dashboard() {
		$this->guard( 'eduflow_manage_institute' );
		$settings = EduFlow_Settings::get_all();
		$metrics = EduFlow_Dashboard_Service::metrics();
		$cards = array(
			'today_classes'=>array("Today's Classes",'eduflow-classes'),'upcoming_classes'=>array('Upcoming Classes','eduflow-classes'),
			'active_students'=>array('Active Students','eduflow-students'),'active_teachers'=>array('Active Teachers','eduflow-teachers'),
			'active_batches'=>array('Active Batches','eduflow-batches'),'demo_sessions_today'=>array('Demo Sessions Today','eduflow-demos'),
			'demo_bookings_today'=>array('Demo Bookings Today','eduflow-demos'),'pending_admissions'=>array('Pending Admissions','eduflow-admissions'),
			'pending_payments'=>array('Pending Payments','eduflow-payments'),'fee_due'=>array('Fee Due','eduflow-students'),
			'expired_access'=>array('Expired Access','eduflow-students'),'failed_google_sync'=>array('Failed Google Sync','eduflow-classes'),
			'completed_today'=>array('Completed Today','eduflow-attendance'),'attendance_today'=>array('Students Attended','eduflow-attendance'),'verified_payments'=>array('Verified Payments','eduflow-reports'),'renewals'=>array('Renewals','eduflow-reports'),'access_expiring'=>array('Access Expiring','eduflow-reports'),'failed_notifications'=>array('Failed Notifications','eduflow-notifications'),
		);
		?><div class="wrap eduflow-wrap"><h1><?php echo esc_html($settings['institute_name']);?> — EduFlow</h1><div class="eduflow-grid"><?php foreach($cards as $key=>$card):?><a class="eduflow-card" href="<?php echo esc_url(admin_url('admin.php?page='.$card[1]));?>"><h2><?php echo esc_html($metrics[$key]);?></h2><p><?php echo esc_html($card[0]);?></p></a><?php endforeach;?><a class="eduflow-card" href="<?php echo esc_url(admin_url('admin.php?page=eduflow-health'));?>"><h2>Health</h2><p>System Health</p></a></div></div><?php
	}
	public function admissions() {
		$this->guard('eduflow_manage_admissions');global $wpdb;$institute=EduFlow_Settings::institute_db_id();$table=EduFlow_DB::table('admissions');$search=sanitize_text_field(wp_unslash($_GET['s']??''));$status=sanitize_key($_GET['admission_status']??'');$page=max(1,absint($_GET['paged']??1));$where=array('institute_id=%d');$params=array($institute);if($search){$like='%'.$wpdb->esc_like($search).'%';$where[]='(canonical_id LIKE %s OR student_name LIKE %s OR mobile LIKE %s OR email LIKE %s)';array_push($params,$like,$like,$like,$like);}if(in_array($status,EduFlow_Admission_Service::STATUSES,true)){$where[]='admission_status=%s';$params[]=$status;}$base=' FROM '.$table.' WHERE '.implode(' AND ',$where);$total=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*)'.$base,...$params));$params[]=20;$params[]=($page-1)*20;$rows=$wpdb->get_results($wpdb->prepare('SELECT *'.$base.' ORDER BY created_at DESC LIMIT %d OFFSET %d',...$params),ARRAY_A);$this->notice();?><div class="wrap eduflow-wrap"><h1 class="wp-heading-inline">Admissions</h1><a href="#eduflow-admission-form" class="page-title-action">Add New</a><hr class="wp-header-end"><form method="get"><input type="hidden" name="page" value="eduflow-admissions"><p class="search-box"><input type="search" name="s" value="<?php echo esc_attr($search);?>"><select name="admission_status"><option value="">All statuses</option><?php foreach(EduFlow_Admission_Service::STATUSES as $v):?><option value="<?php echo esc_attr($v);?>" <?php selected($status,$v);?>><?php echo esc_html(ucwords(str_replace('_',' ',$v)));?></option><?php endforeach;?></select><?php submit_button('Filter','secondary','',false);?></p></form><table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>Student</th><th>Contact</th><th>Course</th><th>Fee</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><?php echo esc_html($row['canonical_id']);?></td><td><?php echo esc_html($row['student_name']);?></td><td><?php echo esc_html($row['mobile']);?><br><?php echo esc_html($row['email']);?></td><td><?php echo esc_html($row['course'].' / '.$row['level']);?></td><td><?php echo esc_html(number_format_i18n($row['amount_paid'],2).' / '.number_format_i18n($row['total_fee'],2));?></td><td><?php
$status_key = sanitize_key($row['admission_status']);
$status_labels = array(
    'approved'          => '✓ Approved',
    'active'            => '✓ Active',
    'hold'              => 'On Hold',
    'rejected'          => '✕ Rejected',
    'admission_created' => 'Admission Created'
);
$status_label = $status_labels[$status_key] ?? ucwords(str_replace('_',' ',$status_key));
?><span class="eduflow-status-badge eduflow-status-<?php echo esc_attr($status_key);?>"><?php echo esc_html($status_label);?></span></td><td><div class="eduflow-action-buttons"><a class="eduflow-btn eduflow-btn-edit" href="<?php echo esc_url(add_query_arg(array('page'=>'eduflow-admissions','edit'=>$row['id']),admin_url('admin.php')));?>#eduflow-admission-form">Edit</a><?php
if(!in_array($status_key,array('approved','active'),true)):
    $url=wp_nonce_url(admin_url('admin-post.php?action=eduflow_admission_action&record='.$row['id'].'&do=approved'),'eduflow_admission_action_'.$row['id']);
?><a class="eduflow-btn eduflow-btn-approve" href="<?php echo esc_url($url);?>">✓ Approve</a><?php
endif;
if('hold' !== $status_key):
    $url=wp_nonce_url(admin_url('admin-post.php?action=eduflow_admission_action&record='.$row['id'].'&do=hold'),'eduflow_admission_action_'.$row['id']);
?><a class="eduflow-btn eduflow-btn-hold" href="<?php echo esc_url($url);?>">Hold</a><?php
endif;
if('rejected' !== $status_key):
    $url=wp_nonce_url(admin_url('admin-post.php?action=eduflow_admission_action&record='.$row['id'].'&do=rejected'),'eduflow_admission_action_'.$row['id']);
?><a class="eduflow-btn eduflow-btn-reject" href="<?php echo esc_url($url);?>">Reject</a><?php endif;?></div></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="7">No admissions found.</td></tr><?php endif;?></tbody></table><?php echo wp_kses_post(paginate_links(array('base'=>add_query_arg('paged','%#%'),'current'=>$page,'total'=>max(1,(int)ceil($total/20)))));?><?php ?>
<div class="eduflow-card eduflow-sync-approved-card" style="border-left:4px solid #198754;">
    <h2>Student Auto-Sync</h2>
    <p>
        Recover approved admissions that have not yet created their Student record.
        Future approvals continue to create Students automatically.
    </p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
        <input type="hidden" name="action" value="eduflow_sync_approved_students">
        <?php wp_nonce_field('eduflow_sync_approved_students');?>
        <?php submit_button('Sync Approved Students','primary');?>
    </form>
</div>
<?php EduFlow_YTC_Master_Import::render_card(); $this->bulk_admission_form(); $this->admission_form();?></div><?php
	}
	private function admission_form(){
		$record=absint($_GET['edit']??0);$values=$record?EduFlow_Admission_Service::get($record):array();$values=is_array($values)?$values:array();?><div id="eduflow-admission-form" class="eduflow-card"><h2><?php echo $record?'Edit Admission':'Create Admission';?></h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="eduflow_save_admission"><input type="hidden" name="record" value="<?php echo esc_attr($record);?>"><?php wp_nonce_field('eduflow_save_admission');?><div class="eduflow-form-grid"><?php $fields=array('student_name'=>'Student Name','mobile'=>'Mobile','email'=>'Email (optional)','course'=>'Course','level'=>'Level','preferred_timing'=>'Preferred Timing','admission_date'=>'Admission Date','total_fee'=>'Total Fee','amount_paid'=>'Amount Paid');foreach($fields as $key=>$label):?><label><?php echo esc_html($label);?><input name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($values[$key]??'');?>" <?php echo 'admission_date'===$key?'type="date"':'';?> <?php echo in_array($key,array('student_name','mobile','course','level'),true)?'required':'';?>></label><?php endforeach;?><label>Class Type<select name="class_type"><?php foreach(EduFlow_Admission_Service::CLASS_TYPES as $type):?><option value="<?php echo esc_attr($type);?>" <?php selected($values['class_type']??'',$type);?>><?php echo esc_html(ucwords($type));?></option><?php endforeach;?></select></label><label>Payment Method<select name="payment_method"><?php foreach(EduFlow_Payment_Service::METHODS as $method):?><option value="<?php echo esc_attr($method);?>" <?php selected($values['payment_method']??'upi',$method);?>><?php echo esc_html(ucwords(str_replace('_',' ',$method)));?></option><?php endforeach;?></select></label><label>Transaction / UTR<input name="transaction_reference" value="<?php echo esc_attr($values['transaction_reference']??'');?>"></label><label>Access Period<select name="access_period"><option value="1_month" <?php selected($values['access_period']??'3_months','1_month');?>>1 Month</option><option value="3_months" <?php selected($values['access_period']??'3_months','3_months');?>>3 Months</option><option value="6_months" <?php selected($values['access_period']??'3_months','6_months');?>>6 Months</option><option value="custom" <?php selected($values['access_period']??'3_months','custom');?>>Custom End Date</option></select></label><label>Custom Access End<input type="date" name="custom_access_end" value="<?php echo esc_attr($values['custom_access_end']??'');?>"></label><label class="wide">Notes<textarea name="notes"><?php echo esc_textarea($values['notes']??'');?></textarea></label></div><?php submit_button($record?'Update Admission':'Create Admission');?></form></div><?php
	}

     private function bulk_admission_form(){
             ?>
             <div class="eduflow-card">
                     <h2>Bulk Admission Import</h2>
                     <p>Upload CSV exported from Google Sheets. Existing mobile/email matches will be skipped.</p>

                     <form method="post"
                           enctype="multipart/form-data"
                           action="<?php echo esc_url(admin_url('admin-post.php'));?>">

                             <input type="hidden"
                                    name="action"
                                    value="eduflow_bulk_import_admissions">

                             <?php wp_nonce_field('eduflow_bulk_import_admissions');?>

                             <input type="file"
                                    name="admission_csv"
                                    accept=".csv,text/csv"
                                    required>

                             <?php submit_button('Import Admissions','secondary');?>
                     </form>
             </div>
             <?php
     }

     public function bulk_import_admissions(){
             $this->guard('eduflow_manage_admissions');
             check_admin_referer('eduflow_bulk_import_admissions');

             if (
                     empty($_FILES['admission_csv']['tmp_name']) ||
                     ! is_uploaded_file($_FILES['admission_csv']['tmp_name'])
             ) {
                     $this->redirect(
                             'eduflow-admissions',
                             'Please select a valid CSV file.',
                             'error'
                     );
             }

             $handle = fopen(
                     $_FILES['admission_csv']['tmp_name'],
                     'r'
             );

             if (!$handle) {
                     $this->redirect(
                             'eduflow-admissions',
                             'CSV file could not be opened.',
                             'error'
                     );
             }

             $headers = fgetcsv($handle);

             if (!$headers) {
                     fclose($handle);

                     $this->redirect(
                             'eduflow-admissions',
                             'CSV header row is missing.',
                             'error'
                     );
             }

             $normalize = static function($value){
                     $value = strtolower(trim((string)$value));
                     $value = preg_replace('/[^a-z0-9]+/','_',$value);
                     return trim($value,'_');
             };

             $headers = array_map($normalize,$headers);

             $aliases = array(

                     'student_name' => array(
                             'student_name',
                             'name',
                             'student',
                             'student_full_name'
                     ),

                     'mobile' => array(
                             'mobile',
                             'mobile_number',
                             'phone',
                             'phone_number',
                             'contact',
                             'contact_number'
                     ),

                     'email' => array(
                             'email',
                             'email_id',
                             'student_email'
                     ),

                     'course' => array(
                             'course',
                             'course_name',
                             'program'
                     ),

                     'level' => array(
                             'level',
                             'course_level'
                     ),

                     'class_type' => array(
                             'class_type',
                             'class',
                             'type'
                     ),

                     'preferred_timing' => array(
                             'preferred_timing',
                             'timing',
                             'class_timing',
                             'preferred_time'
                     ),

                     'admission_date' => array(
                             'admission_date',
                             'joining_date',
                             'date'
                     ),

                     'total_fee' => array(
                             'total_fee',
                             'fee',
                             'fees',
                             'course_fee',
                             'admission_fee',
                             'admission_fe'
                     ),

                     'amount_paid' => array(
                             'amount_paid',
                             'paid',
                             'paid_amount',
                             'payment_received'
                     ),

                     'payment_method' => array(
                             'payment_method',
                             'payment_mode',
                             'payment_mod',
                             'method'
                     ),

                     'transaction_reference' => array(
                             'transaction_reference',
                             'utr',
                             'transaction_id',
                             'reference',
                             'receipt_no'
                     ),

                     'batch' => array('batch','batch_name'),
                     'teacher' => array('teacher','teacher_name'),
                     'counsellor' => array('counsellor','counselor'),
                     'students_status' => array('students_status','student_status','status'),

                     'access_period' => array(
                             'access_period',
                             'duration',
                             'course_duration'
                     ),

                     'custom_access_end' => array(
                             'custom_access_end',
                             'access_end',
                             'end_date'
                     ),

                     'notes' => array(
                             'notes',
                             'remark',
                             'remarks'
                     )
             );

             $map = array();

             foreach ($aliases as $field => $possible_headers) {

                     foreach ($possible_headers as $header) {

                             $index = array_search(
                                     $header,
                                     $headers,
                                     true
                             );

                             if ($index !== false) {
                                     $map[$field] = $index;
                                     break;
                             }
                     }
             }

             if (
                     !isset($map['student_name']) ||
                     !isset($map['mobile']) ||
                     !isset($map['course'])
             ) {
                     fclose($handle);

                     $this->redirect(
                             'eduflow-admissions',
                             'CSV must contain Student Name, Mobile and Course columns.',
                             'error'
                     );
             }

             $imported   = 0;
             $duplicates = 0;
             $skipped_status = 0;
             $empty_skipped = 0;
             $errors     = 0;
             $error_details = array();

             while (($row = fgetcsv($handle)) !== false) {

                     $has_data = false;

                     foreach ($row as $cell) {
                             if (trim((string)$cell) !== '') {
                                     $has_data = true;
                                     break;
                             }
                     }

                     if (!$has_data) {
                             $empty_skipped++;
                             continue;
                     }

                     $input = array();

                     foreach ($map as $field => $index) {
                             $input[$field] =
                                     isset($row[$index])
                                     ? trim((string)$row[$index])
                                     : '';
                     }

                     if (empty($input['class_type'])) {
                             $input['class_type'] = 'group';
                     }

                     if (empty($input['level'])) {
                             $input['level'] = 'basic';
                     }

                     if (empty($input['admission_date'])) {
                             $input['admission_date'] =
                                     current_time('Y-m-d');
                     }

                     if (empty($input['payment_method'])) {
                             $input['payment_method'] = 'upi';
                     }

                     if (empty($input['access_period'])) {
                             $input['access_period'] = '3_months';
                     }

                     $sheet_status = strtolower(trim((string)($input['students_status'] ?? 'active')));

                     if (in_array($sheet_status, array('dropped','cancelled','canceled'), true)) {
                             $skipped_status++;
                             continue;
                     }

                     $meta = array();

                     if (!empty($input['batch'])) {
                             $meta[] = 'Batch: ' . $input['batch'];
                     }

                     if (!empty($input['teacher'])) {
                             $meta[] = 'Teacher: ' . $input['teacher'];
                     }

                     if (!empty($input['counsellor'])) {
                             $meta[] = 'Counsellor: ' . $input['counsellor'];
                     }

                     if (!empty($input['students_status'])) {
                             $meta[] = 'Sheet Status: ' . $input['students_status'];
                     }

                     if ($meta) {
                             $existing_notes = trim((string)($input['notes'] ?? ''));
                             $input['notes'] = trim($existing_notes . ($existing_notes ? ' | ' : '') . implode(' | ', $meta));
                     }

                     unset(
                             $input['batch'],
                             $input['teacher'],
                             $input['counsellor'],
                             $input['students_status']
                     );

                     $result =
                             EduFlow_Admission_Service::create($input);

                     if (is_wp_error($result)) {

                             if (
                                     'duplicate_admission' ===
                                     $result->get_error_code()
                             ) {
                                     $duplicates++;
                             } else {
                                     $errors++;
                                     if (count($error_details) < 10) {
                                             $error_details[] =
                                                     ($input['student_name'] ?? 'Unknown') .
                                                     ': ' .
                                                     $result->get_error_code() .
                                                     ' - ' .
                                                     $result->get_error_message();
                                     }
                             }

                     } else {
                             $imported++;
                     }
             }

             fclose($handle);

             $message = sprintf(
                     'Bulk import finished. Imported: %d | Existing duplicates: %d | Dropped/Cancelled skipped: %d | Empty rows skipped: %d | Errors: %d',
                     $imported,
                     $duplicates,
                     $skipped_status,
                     $empty_skipped,
                     $errors
             );

             if ($error_details) {
                     $message .= ' | Details: ' . implode(' || ', $error_details);
             }

             $this->redirect(
                     'eduflow-admissions',
                     $message,
                     $errors ? 'error' : 'success'
             );
     }


	public function save_admission(){ $this->guard('eduflow_manage_admissions');check_admin_referer('eduflow_save_admission');$input=wp_unslash($_POST);$record=absint($input['record']??0);$result=$record?EduFlow_Admission_Service::update($record,$input):EduFlow_Admission_Service::create($input);$this->redirect('eduflow-admissions',is_wp_error($result)?$result->get_error_message():'Admission created.',is_wp_error($result)?'error':'success'); }
	
    public function sync_approved_students(){
        $this->guard('eduflow_manage_admissions');
        check_admin_referer('eduflow_sync_approved_students');

        global $wpdb;

        EduFlow_DB::install();

        $institute  = EduFlow_Settings::institute_db_id();
        $admissions = EduFlow_DB::table('admissions');
        $students   = EduFlow_DB::table('students');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.id,a.canonical_id,a.student_name,a.student_id,a.admission_status
                 FROM $admissions a
                 LEFT JOIN $students s
                   ON s.institute_id=a.institute_id
                  AND s.admission_id=a.id
                 WHERE a.institute_id=%d
                   AND a.status='active'
                   AND a.admission_status IN ('approved','active')
                   AND (a.student_id IS NULL OR a.student_id=0 OR s.id IS NULL)
                 ORDER BY a.id ASC",
                $institute
            ),
            ARRAY_A
        );

        $created = 0;
        $recovered = 0;
        $errors = array();

        foreach($rows as $row){

            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM $students
                     WHERE institute_id=%d
                       AND admission_id=%d
                     LIMIT 1",
                    $institute,
                    $row['id']
                )
            );

            if($existing){
                $wpdb->update(
                    $admissions,
                    array(
                        'student_id'=>(int)$existing,
                        'admission_status'=>'active',
                        'updated_at'=>current_time('mysql',true)
                    ),
                    array(
                        'id'=>$row['id'],
                        'institute_id'=>$institute
                    )
                );

                $recovered++;
                continue;
            }

            $result = EduFlow_Student_Service::convert_admission(
                (int)$row['id'],
                $institute
            );

            if(is_wp_error($result)){
                $errors[] =
                    $row['student_name'] . ': ' .
                    $result->get_error_message();
                continue;
            }

            $created++;
        }

        $message = sprintf(
            'Student Sync complete. Created: %d | Recovered links: %d | Errors: %d',
            $created,
            $recovered,
            count($errors)
        );

        if($errors){
            $message .= ' | ' . implode(
                ' || ',
                array_slice($errors,0,5)
            );
        }

        $this->redirect(
            'eduflow-admissions',
            $message,
            $errors ? 'error' : 'success'
        );
    }

public function admission_action(){ $this->guard('eduflow_manage_admissions');$id=absint($_GET['record']??0);check_admin_referer('eduflow_admission_action_'.$id);$do=sanitize_key($_GET['do']??'');$result='approved'===$do?EduFlow_Production_Orchestrator::approve_admission($id):EduFlow_Admission_Service::set_status($id,$do);$this->redirect('eduflow-admissions',is_wp_error($result)?$result->get_error_message():('approved'===$do?'Admission approved. Student automation completed.':'Admission updated.'),is_wp_error($result)?'error':'success'); }
        public function students() {
                $this->guard( 'eduflow_manage_students' );

                global $wpdb;

                $institute = EduFlow_Settings::institute_db_id();

                $args = array_map(
                        'sanitize_text_field',
                        wp_unslash( $_GET )
                );

                if ( ! isset( $args['student_status'] ) ) {
                        $args['student_status'] = 'current';
                }

                $result = EduFlow_Student_Service::query( $args );

                $this->notice();

                $status_options = array(
                        'current'   => 'Current Students',
                        'all'       => 'All Students',
                        'active'    => 'Active',
                        'approved'  => 'Approved',
                        'hold'      => 'On Hold',
                        'dropped'   => 'Dropped',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled'
                );

                $fee_options = array(
                        ''        => 'All Fee Status',
                        'paid'    => 'Paid',
                        'partial' => 'Partial',
                        'pending' => 'Pending',
                        'due'     => 'Due'
                );

                ?>
                <div class="wrap eduflow-wrap">

                        <h1 class="wp-heading-inline">Students</h1>

                        <hr class="wp-header-end">

                        <form method="get" class="eduflow-student-filters">

                                <input type="hidden"
                                       name="page"
                                       value="eduflow-students">

                                <input type="search"
                                       name="search"
                                       placeholder="Name, Student ID, Mobile, Email"
                                       value="<?php echo esc_attr(
                                               $args['search'] ?? ''
                                       ); ?>">

                                <select name="student_status">
                                        <?php
                                        foreach (
                                                $status_options
                                                as $value => $label
                                        ) :
                                                ?>
                                                <option
                                                    value="<?php echo esc_attr($value); ?>"
                                                    <?php selected(
                                                            $args['student_status'] ?? 'current',
                                                            $value
                                                    ); ?>>
                                                        <?php echo esc_html($label); ?>
                                                </option>
                                        <?php endforeach; ?>
                                </select>

                                <select name="fee_status">
                                        <?php
                                        foreach (
                                                $fee_options
                                                as $value => $label
                                        ) :
                                                ?>
                                                <option
                                                    value="<?php echo esc_attr($value); ?>"
                                                    <?php selected(
                                                            $args['fee_status'] ?? '',
                                                            $value
                                                    ); ?>>
                                                        <?php echo esc_html($label); ?>
                                                </option>
                                        <?php endforeach; ?>
                                </select>

                                <input type="text"
                                       name="course"
                                       placeholder="Course"
                                       value="<?php echo esc_attr(
                                               $args['course'] ?? ''
                                       ); ?>">

                                <?php
                                submit_button(
                                        'Filter',
                                        'secondary',
                                        '',
                                        false
                                );
                                ?>

                        </form>

                        <table class="wp-list-table widefat striped eduflow-students-table">

                                <thead>
                                        <tr>
                                                <th>Student ID</th>
                                                <th>Name</th>
                                                <th>Mobile</th>
                                                <th>Email</th>
                                                <th>Course</th>
                                                <th>Level</th>
                                                <th>Class Type</th>
                                                <th>Fee</th>
                                                <th>Status</th>
                                                <th>Access End</th>
                                                <th>Action</th>
                                        </tr>
                                </thead>

                                <tbody>

                                <?php foreach ( $result['items'] as $row ) : ?>

                                        <?php
                                        $status_key = sanitize_key(
                                                $row['student_status']
                                        );

                                        $labels = array(
                                                'approved'  => '✓ Approved',
                                                'active'    => '✓ Active',
                                                'hold'      => 'On Hold',
                                                'dropped'   => 'Dropped',
                                                'completed' => 'Completed',
                                                'cancelled' => 'Cancelled'
                                        );

                                        $status_label =
                                                $labels[$status_key]
                                                ?? ucwords(
                                                        str_replace(
                                                                '_',
                                                                ' ',
                                                                $status_key
                                                        )
                                                );
                                        ?>

                                        <tr>
                                                <td>
                                                        <strong>
                                                        <?php echo esc_html(
                                                                $row['canonical_id']
                                                        ); ?>
                                                        </strong>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                $row['name']
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                $row['mobile']
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                $row['email']
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                $row['course']
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                $row['level']
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                ucwords(
                                                                        str_replace(
                                                                                '-',
                                                                                ' ',
                                                                                $row['class_type']
                                                                        )
                                                                )
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <span class="eduflow-fee-badge eduflow-fee-<?php
                                                        echo esc_attr(
                                                                sanitize_key(
                                                                        $row['fee_status']
                                                                )
                                                        );
                                                        ?>">
                                                        <?php echo esc_html(
                                                                ucwords(
                                                                        $row['fee_status']
                                                                )
                                                        ); ?>
                                                        </span>
                                                </td>

                                                <td>
                                                        <span class="eduflow-student-status eduflow-student-status-<?php
                                                        echo esc_attr($status_key);
                                                        ?>">
                                                        <?php echo esc_html(
                                                                $status_label
                                                        ); ?>
                                                        </span>
                                                </td>

                                                <td>
                                                        <?php echo esc_html(
                                                                $row['access_end']
                                                                ?: '—'
                                                        ); ?>
                                                </td>

                                                <td>
                                                        <a class="button button-small"
                                                           href="<?php echo esc_url(
                                                                   add_query_arg(
                                                                           array(
                                                                                   'page' => 'eduflow-students',
                                                                                   'edit' => $row['id']
                                                                           ),
                                                                           admin_url('admin.php')
                                                                   )
                                                           ); ?>#eduflow-student-form">
                                                                Edit
                                                        </a>
                                                </td>
                                        </tr>

                                <?php endforeach; ?>

                                <?php if ( ! $result['items'] ) : ?>

                                        <tr>
                                                <td colspan="11">
                                                        No students found.
                                                </td>
                                        </tr>

                                <?php endif; ?>

                                </tbody>
                        </table>

                        <?php
                        echo wp_kses_post(
                                paginate_links(
                                        array(
                                                'base'    => add_query_arg(
                                                        'paged',
                                                        '%#%'
                                                ),
                                                'current' => $result['page'],
                                                'total'   => max(
                                                        1,
                                                        $result['pages']
                                                )
                                        )
                                )
                        );

                        $this->student_form();
                        ?>

                </div>
                <?php
        }

        private function student_form() {

                global $wpdb;

                $id = absint( $_GET['edit'] ?? 0 );

                if ( ! $id ) {
                        return;
                }

                $row = EduFlow_Student_Service::get( $id );

                if ( ! $row ) {
                        return;
                }

                $institute = EduFlow_Settings::institute_db_id();

                $batches_table = EduFlow_DB::table( 'batches' );
                $teachers_table = EduFlow_DB::table( 'teachers' );
                $students_table = EduFlow_DB::table( 'students' );

                $batches = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id,batch_name
                                 FROM $batches_table
                                 WHERE institute_id=%d
                                 ORDER BY batch_name ASC",
                                $institute
                        ),
                        ARRAY_A
                );

                $teachers = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id,name
                                 FROM $teachers_table
                                 WHERE institute_id=%d
                                 ORDER BY name ASC",
                                $institute
                        ),
                        ARRAY_A
                );

                $courses = $wpdb->get_col(
                        $wpdb->prepare(
                                "SELECT DISTINCT course
                                 FROM $students_table
                                 WHERE institute_id=%d
                                 AND course<>''
                                 ORDER BY course ASC",
                                $institute
                        )
                );

                foreach (
                        array('Basic','Intermediate','Advanced','IM')
                        as $default_course
                ) {
                        if ( ! in_array(
                                $default_course,
                                $courses,
                                true
                        ) ) {
                                $courses[] = $default_course;
                        }
                }

                $statuses = array(
                        'approved'  => 'Approved',
                        'active'    => 'Active',
                        'hold'      => 'On Hold',
                        'dropped'   => 'Dropped',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled'
                );

                $fee_statuses = array(
                        'paid'    => 'Paid',
                        'partial' => 'Partial',
                        'pending' => 'Pending',
                        'due'     => 'Due'
                );

                ?>

                <div id="eduflow-student-form"
                     class="eduflow-card eduflow-student-edit-card">

                        <h2>
                                Edit Student —
                                <?php echo esc_html(
                                        $row['canonical_id']
                                ); ?>
                        </h2>

                        <form method="post"
                              action="<?php echo esc_url(
                                      admin_url('admin-post.php')
                              ); ?>">

                                <input type="hidden"
                                       name="action"
                                       value="eduflow_save_student">

                                <input type="hidden"
                                       name="record"
                                       value="<?php echo esc_attr($id); ?>">

                                <?php
                                wp_nonce_field(
                                        'eduflow_save_student_' . $id
                                );
                                ?>

                                <div class="eduflow-form-grid">

                                        <label>
                                                Name
                                                <input name="name"
                                                       value="<?php echo esc_attr(
                                                               $row['name']
                                                       ); ?>"
                                                       required>
                                        </label>

                                        <label>
                                                Mobile
                                                <input name="mobile"
                                                       value="<?php echo esc_attr(
                                                               $row['mobile']
                                                       ); ?>"
                                                       required>
                                        </label>

                                        <label>
                                                Email
                                                <input type="email"
                                                       name="email"
                                                       value="<?php echo esc_attr(
                                                               $row['email']
                                                       ); ?>">
                                        </label>

                                        <label>
                                                Course
                                                <select name="course">
                                                        <?php
                                                        foreach (
                                                                $courses
                                                                as $course
                                                        ) :
                                                                ?>
                                                                <option
                                                                    value="<?php echo esc_attr($course); ?>"
                                                                    <?php selected(
                                                                            $row['course'],
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
                                                                <option
                                                                    value="<?php echo esc_attr($level); ?>"
                                                                    <?php selected(
                                                                            $row['level'],
                                                                            $level
                                                                    ); ?>>
                                                                        <?php echo esc_html($level); ?>
                                                                </option>
                                                        <?php endforeach; ?>
                                                </select>
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
                                                                <option
                                                                    value="<?php echo esc_attr($type); ?>"
                                                                    <?php selected(
                                                                            $row['class_type'],
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

                                        <label>
                                                Batch
                                                <select name="batch_id">
                                                        <option value="">
                                                                Unassigned
                                                        </option>

                                                        <?php
                                                        foreach (
                                                                $batches
                                                                as $batch
                                                        ) :
                                                                ?>
                                                                <option
                                                                    value="<?php echo esc_attr(
                                                                            $batch['id']
                                                                    ); ?>"
                                                                    <?php selected(
                                                                            (int)$row['batch_id'],
                                                                            (int)$batch['id']
                                                                    ); ?>>
                                                                        <?php echo esc_html(
                                                                                $batch['batch_name']
                                                                        ); ?>
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
                                                                $teachers
                                                                as $teacher
                                                        ) :
                                                                ?>
                                                                <option
                                                                    value="<?php echo esc_attr(
                                                                            $teacher['id']
                                                                    ); ?>"
                                                                    <?php selected(
                                                                            (int)$row['teacher_id'],
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
                                                Student Status
                                                <select name="student_status">
                                                        <?php
                                                        foreach (
                                                                $statuses
                                                                as $value => $label
                                                        ) :
                                                                ?>
                                                                <option
                                                                    value="<?php echo esc_attr($value); ?>"
                                                                    <?php selected(
                                                                            $row['student_status'],
                                                                            $value
                                                                    ); ?>>
                                                                        <?php echo esc_html($label); ?>
                                                                </option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </label>

                                        <label>
                                                Fee Status
                                                <select name="fee_status">
                                                        <?php
                                                        foreach (
                                                                $fee_statuses
                                                                as $value => $label
                                                        ) :
                                                                ?>
                                                                <option
                                                                    value="<?php echo esc_attr($value); ?>"
                                                                    <?php selected(
                                                                            $row['fee_status'],
                                                                            $value
                                                                    ); ?>>
                                                                        <?php echo esc_html($label); ?>
                                                                </option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </label>

                                </div>

                                <?php
                                submit_button(
                                        'Update Student'
                                );
                                ?>

                        </form>

                </div>

                <?php
        }

	public function save_student(){ $this->guard('eduflow_manage_students');$id=absint($_POST['record']??0);check_admin_referer('eduflow_save_student_'.$id);$result=EduFlow_Student_Service::update($id,wp_unslash($_POST));$this->redirect('eduflow-students',is_wp_error($result)?$result->get_error_message():'Student updated.',is_wp_error($result)?'error':'success'); }
	public function payments(){ $this->guard('eduflow_manage_payments');global $wpdb;$institute=EduFlow_Settings::institute_db_id();$table=EduFlow_DB::table('payments');$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE institute_id=%d ORDER BY created_at DESC LIMIT 100",$institute),ARRAY_A);$this->notice();?><div class="wrap eduflow-wrap"><h1>Payments</h1><table class="wp-list-table widefat striped"><thead><tr><th>ID</th><th>Admission</th><th>Amount</th><th>Method</th><th>Reference</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><?php echo esc_html($row['canonical_id']);?></td><td><?php echo esc_html($row['admission_id']);?></td><td><?php echo esc_html(number_format_i18n($row['amount'],2));?></td><td><?php echo esc_html($row['payment_method']);?></td><td><?php echo esc_html($row['transaction_reference']);?></td><td><?php echo esc_html($row['payment_date']);?></td><td><?php echo esc_html($row['verification_status']);?></td><td><?php if('pending'===$row['verification_status']):foreach(array('verified'=>'Verify','rejected'=>'Reject') as $decision=>$label):$url=wp_nonce_url(admin_url('admin-post.php?action=eduflow_payment_action&record='.$row['id'].'&decision='.$decision),'eduflow_payment_action_'.$row['id']);?><a href="<?php echo esc_url($url);?>"><?php echo esc_html($label);?></a> <?php endforeach;endif;?></td></tr><?php endforeach;?></tbody></table><?php $this->payment_form();?></div><?php }
    private function payment_form() {
        global $wpdb;

        $institute = EduFlow_Settings::institute_db_id();
        $admissions_table = EduFlow_DB::table('admissions');

        $admissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    id,
                    canonical_id,
                    student_name,
                    mobile,
                    admission_status,
                    payment_status
                 FROM $admissions_table
                 WHERE institute_id=%d
                 AND status='active'
                 ORDER BY id DESC
                 LIMIT 300",
                $institute
            ),
            ARRAY_A
        );
        ?>

        <div class="eduflow-card">

            <h2>Record Payment</h2>

            <form method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                <input type="hidden"
                       name="action"
                       value="eduflow_save_payment">

                <?php wp_nonce_field('eduflow_save_payment'); ?>

                <div class="eduflow-form-grid">

                    <label>
                        Admission

                        <select name="admission_id" required>

                            <option value="">
                                Select Admission
                            </option>

                            <?php foreach ( $admissions as $admission ) : ?>

                                <option value="<?php echo esc_attr(
                                    $admission['id']
                                ); ?>">
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
                                                $admission['payment_status']
                                            )
                                        )
                                    ); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>
                    </label>

                    <label>
                        Amount

                        <input type="number"
                               min="0.01"
                               step="0.01"
                               name="amount"
                               required>
                    </label>

                    <label>
                        Method

                        <select name="payment_method">

                            <?php foreach (
                                EduFlow_Payment_Service::METHODS
                                as $method
                            ) : ?>

                                <option value="<?php echo esc_attr($method); ?>">
                                    <?php echo esc_html(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $method
                                            )
                                        )
                                    ); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>
                    </label>

                    <label>
                        Transaction / UTR

                        <input name="transaction_reference">
                    </label>

                    <label>
                        Payment Date

                        <input type="date"
                               name="payment_date"
                               value="<?php echo esc_attr(
                                   current_time('Y-m-d')
                               ); ?>"
                               required>
                    </label>

                    <label>
                        Access Period

                        <select name="access_period">
                            <option value="1_month">1 Month</option>
                            <option value="3_months">3 Months</option>
                            <option value="6_months">6 Months</option>
                            <option value="custom">Custom End Date</option>
                        </select>
                    </label>

                    <label>
                        Custom End Date

                        <input type="date"
                               name="custom_access_end">
                    </label>

                    <label class="wide">
                        Notes

                        <textarea name="notes"></textarea>
                    </label>

                </div>

                <?php submit_button('Record Payment'); ?>

            </form>

        </div>

        <?php
    }
	public function save_payment(){ $this->guard('eduflow_manage_payments');check_admin_referer('eduflow_save_payment');$result=EduFlow_Payment_Service::create(wp_unslash($_POST));$this->redirect('eduflow-payments',is_wp_error($result)?$result->get_error_message():'Payment recorded.',is_wp_error($result)?'error':'success'); }
	public function payment_action(){ $this->guard('eduflow_manage_payments');$id=absint($_GET['record']??0);check_admin_referer('eduflow_payment_action_'.$id);$result=EduFlow_Payment_Service::decide($id,sanitize_key($_GET['decision']??''));$this->redirect('eduflow-payments',is_wp_error($result)?$result->get_error_message():'Payment updated.',is_wp_error($result)?'error':'success'); }
	public function settings_page(){ $this->guard('eduflow_manage_settings');if('google'===sanitize_key($_GET['tab']??'')){EduFlow_Google_Admin::page();return;}$values=EduFlow_Settings::get_all();?><div class="wrap eduflow-wrap"><h1>EduFlow Settings</h1><nav class="nav-tab-wrapper"><a class="nav-tab nav-tab-active" href="<?php echo esc_url(admin_url('admin.php?page=eduflow-settings'));?>">Institute</a><a class="nav-tab" href="<?php echo esc_url(admin_url('admin.php?page=eduflow-settings&tab=google'));?>">Google Integration</a></nav><form method="post" action="options.php"><?php settings_fields('eduflow_core');?><table class="form-table"><tbody><?php foreach(EduFlow_Settings::FIELDS as $key):?><tr><th><label for="ef-<?php echo esc_attr($key);?>"><?php echo esc_html(ucwords(str_replace('_',' ',$key)));?></label></th><td><input class="regular-text" id="ef-<?php echo esc_attr($key);?>" name="eduflow_core_settings[<?php echo esc_attr($key);?>]" value="<?php echo esc_attr($values[$key]??'');?>"></td></tr><?php endforeach;?></tbody></table><?php submit_button();?></form></div><?php }
	public function health(){ $this->guard('eduflow_manage_institute');$report=EduFlow_Health_Service::report();?><div class="wrap eduflow-wrap"><h1>System Health</h1><div class="eduflow-card"><table class="widefat striped"><tbody><?php foreach($report as $key=>$value):?><tr><th><?php echo esc_html(ucwords(str_replace('_',' ',$key)));?></th><td><code><?php echo esc_html(is_scalar($value)?(string)$value:wp_json_encode($value,JSON_PRETTY_PRINT));?></code></td></tr><?php endforeach;?></tbody></table><form action="<?php echo esc_url(admin_url('admin-post.php'));?>" method="post"><input type="hidden" name="action" value="eduflow_run_jobs"><?php wp_nonce_field('eduflow_run_jobs');submit_button('Run Jobs Now','secondary');?></form></div></div><?php }
	public function run_jobs(){ $this->guard('eduflow_manage_institute');check_admin_referer('eduflow_run_jobs');EduFlow_Job_Service::run();$this->redirect('eduflow-health','Jobs processed.'); }
	public function audit_log(){ $this->guard('eduflow_view_audit');global$wpdb;$institute=EduFlow_Settings::institute_db_id();$page=max(1,absint($_GET['paged']??1));$table=EduFlow_DB::table('audit_log');$total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE institute_id=%d",$institute));$rows=$wpdb->get_results($wpdb->prepare("SELECT id,user_id,action,entity_type,entity_id,status,created_at FROM $table WHERE institute_id=%d ORDER BY id DESC LIMIT %d OFFSET %d",$institute,50,($page-1)*50),ARRAY_A);?><div class="wrap"><h1>EduFlow Audit Log</h1><table class="widefat striped"><thead><tr><th>ID</th><th>Actor</th><th>Action</th><th>Entity</th><th>Status</th><th>Timestamp</th></tr></thead><tbody><?php foreach($rows as$row):?><tr><td><?php echo esc_html($row['id']);?></td><td><?php echo esc_html($row['user_id']?:'System');?></td><td><?php echo esc_html($row['action']);?></td><td><?php echo esc_html($row['entity_type'].' / '.$row['entity_id']);?></td><td><?php echo esc_html($row['status']);?></td><td><?php echo esc_html($row['created_at']);?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6">No audit records found.</td></tr><?php endif;?></tbody></table><?php echo wp_kses_post(paginate_links(array('base'=>add_query_arg('paged','%#%'),'current'=>$page,'total'=>max(1,(int)ceil($total/50)))));?></div><?php }
}
