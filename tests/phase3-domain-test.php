<?php
// Domain and source-invariant checks; this is not a live WordPress/MySQL integration test.
define('ABSPATH',__DIR__);
class WP_Error { private $message; public function __construct($code,$message){$this->message=$message;} public function get_error_message(){return $this->message;} }
function is_wp_error($value){return $value instanceof WP_Error;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
require dirname(__DIR__).'/eduflow-core/includes/class-teacher-service.php';
require dirname(__DIR__).'/eduflow-core/includes/class-class-service.php';
$checks=array();
$checks[]=EduFlow_Teacher_Service::days(array('mon','WED','bad','sun'))===array('mon','wed','sun');
$checks[]=EduFlow_Class_Service::to_utc('2026-08-20','10:00','Asia/Kolkata')==='2026-08-20 04:30:00';
$checks[]=is_wp_error(EduFlow_Class_Service::to_utc('2026-08-20','10:00','Invalid/Zone'));
$db=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-db.php');
$checks[]=strpos($db,'UNIQUE KEY schedule_key (schedule_key)')!==false;
$checks[]=strpos($db,'UNIQUE KEY assignment_key (assignment_key)')!==false;
$checks[]=strpos($db,'KEY teacher_time (institute_id,teacher_id,start_datetime,end_datetime)')!==false;
$batch=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-batch-service.php');
$checks[]=strpos($batch,"assignment_status='active'")!==false&&strpos($batch,"batch_full")!==false;
$checks[]=strpos($batch,'student_schedule_conflict')!==false&&strpos($batch,'institute_id=%d')!==false;
$classes=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-class-service.php');
$checks[]=strpos($classes,"id<>%d")!==false&&strpos($classes,'schedule_conflict')!==false;
$checks[]=strpos($classes,"'completed'===\$old['class_status']")!==false;
$checks[]=strpos($classes,"modify('+'.max(0,absint(\$days)-1).' days')")!==false;
$checks[]=strpos($classes,"if('cancelled'===\$old['class_status']){return true;}")!==false;
foreach($checks as $i=>$passed){if(!$passed){fwrite(STDERR,'Failed Phase 3 check '.($i+1).PHP_EOL);exit(1);}}
echo count($checks)." Phase 3 assertions passed\n";
