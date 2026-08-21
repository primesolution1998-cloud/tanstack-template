<?php
$root=dirname(__DIR__).'/eduflow-core/';$files=array('db'=>file_get_contents($root.'includes/class-db.php'),'attendance'=>file_get_contents($root.'includes/class-attendance-service.php'),'notifications'=>file_get_contents($root.'includes/class-notification-service.php'),'delivery'=>file_get_contents($root.'includes/class-notification-delivery-service.php'),'reports'=>file_get_contents($root.'includes/class-report-service.php'),'rest'=>file_get_contents($root.'includes/class-rest.php'),'admin'=>file_get_contents($root.'admin/class-phase6-admin.php'));
$assertions=array(
	'attendance schema'=>str_contains($files['db'],'CREATE TABLE $attendance'),
	'duplicate student lecture constraint'=>str_contains($files['db'],'UNIQUE KEY lecture_student (institute_id,lecture_id,student_id)'),
	'duplicate demo constraint'=>str_contains($files['db'],'UNIQUE KEY demo_booking (institute_id,demo_booking_id)'),
	'batch roster scoped'=>str_contains($files['attendance'],"assignment_status='active'")&&str_contains($files['attendance'],'x.institute_id=%d'),
	'one-to-one roster'=>str_contains($files['attendance'],"s.id=%d"),
	'teacher assignment check'=>str_contains($files['attendance'],'wp_user_id=%d'),
	'student isolation'=>str_contains($files['attendance'],"linked_entity_any_institute('student'"),
	'correction audited'=>str_contains($files['attendance'],"\$action='attendance_corrected'"),
	'cancelled denominator documented'=>str_contains($files['attendance'],'cancelled and not marked excluded'),
	'notification deterministic key'=>str_contains($files['notifications'],"hash('sha256',\$institute_id.'|'"),
	'missing email safe'=>str_contains($files['delivery'],"elseif(\$address)"),
	'whatsapp neutral hook'=>str_contains($files['delivery'],'eduflow_whatsapp_queued'),
	'notification ownership'=>str_contains($files['notifications'],'wp_user_id=%d'),
	'report institute isolation'=>substr_count($files['reports'],'institute_id=%d')>=10,
	'csv permission'=>str_contains($files['admin'],"guard('eduflow_manage_reports')")&&str_contains($files['admin'],"check_admin_referer('eduflow_export_report')"),
	'private rest callbacks'=>substr_count($files['rest'],'permission_callback')>=10,
	'no sheets'=>!str_contains(strtolower(implode('',$files)),'sheets.googleapis'),
);
foreach($assertions as$name=>$passed){if(!$passed){fwrite(STDERR,"Failed: $name\n");exit(1);}}echo count($assertions)." Phase 6 assertions passed\n";
