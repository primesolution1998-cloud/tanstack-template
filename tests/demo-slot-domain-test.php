<?php
// Pure and source-invariant checks; no live WordPress/MySQL/Google environment is claimed.
define('ABSPATH',__DIR__);
class WP_Error {public function __construct($code='',$message=''){} }
function absint($v){return abs((int)$v);}function sanitize_text_field($v){return trim((string)$v);}
require dirname(__DIR__).'/eduflow-core/includes/class-demo-service.php';
$key=EduFlow_Demo_Service::session_key(9,'2026-08-20','11:00','11:30',21);
$checks=array(
	$key===EduFlow_Demo_Service::session_key(9,'2026-08-20','11:00:00','11:30:00',21),
	$key!==EduFlow_Demo_Service::session_key(9,'2026-08-20','12:00','12:30',21),
	$key!==EduFlow_Demo_Service::session_key(9,'2026-08-20','11:00','11:30',22),
	$key!==EduFlow_Demo_Service::session_key(10,'2026-08-20','11:00','11:30',21),
);
$db=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-db.php');
$checks[]=strpos($db,'UNIQUE KEY session_key (session_key)')!==false;
$checks[]=strpos($db,'UNIQUE KEY canonical_slot (institute_id,demo_date,start_time,end_time,teacher_id)')!==false;
$checks[]=strpos($db,'UNIQUE KEY booking_key (booking_key)')!==false;
$checks[]=strpos($db,'previous_session_id bigint unsigned NULL')!==false;$checks[]=substr_count($db,"'demo_sessions'")>=2&&substr_count($db,"'demo_bookings'")>=2;
$service=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-demo-service.php');
$checks[]=strpos($service,"return \$existing;")!==false;
$checks[]=strpos($service,"demo_slot_full")!==false;
$checks[]=strpos($service,"g.lecture_id=d.lecture_id")!==false;
$checks[]=strpos($service,"EduFlow_Google_Service::queue(\$session['lecture_id']")!==false;
$checks[]=strpos($service,"EduFlow_Google_Service::queue(\$old['lecture_id']")!==false;
$google=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-google-service.php');
$checks[]=strpos($google,"d.lecture_id=%d AND b.booking_status='booked'")!==false;
foreach($checks as $index=>$passed){if(!$passed){fwrite(STDERR,'Failed demo-slot assertion '.($index+1).PHP_EOL);exit(1);}}
echo count($checks)." demo-slot assertions passed\n";
