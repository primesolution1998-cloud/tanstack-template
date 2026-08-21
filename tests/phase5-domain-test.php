<?php
// Local domain/source checks. This does not claim a live WordPress/MySQL/browser test.
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
class WP_Error {private $code;public function __construct($code='',$message=''){$this->code=$code;}public function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}function sanitize_text_field($v){return trim((string)$v);}function sanitize_textarea_field($v){return trim((string)$v);}function absint($v){return abs((int)$v);}function current_time($type,$gmt=false){return '2026-08-20 12:00:00';}function get_current_user_id(){return 99;}
class EduFlow_Settings {public static function institute_db_id(){return 7;}}
class EduFlow_DB {public static function table($name){return 'test_'.$name;}}
class FakeWPDB {public $keys=array();public $insert_id=0;public function prepare($sql,...$args){return $sql;}public function get_row($sql,$mode=null){return null;}public function insert($table,$data){if(isset($this->keys[$data['idempotency_key']])){return false;}$this->keys[$data['idempotency_key']]=true;$this->insert_id++;return 1;}}
$GLOBALS['wpdb']=new FakeWPDB();
require dirname(__DIR__).'/eduflow-core/includes/class-notification-service.php';
$first=EduFlow_Notification_Service::create('class_scheduled','student',10,'Class','Scheduled','class:1');
$second=EduFlow_Notification_Service::create('class_scheduled','student',10,'Class','Scheduled','class:1');
$checks=array(is_int($first),is_wp_error($second)&&$second->get_error_code()==='duplicate_notification');
$db=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-db.php');
$checks[]=strpos($db,'CREATE TABLE $user_links')!==false&&strpos($db,'CREATE TABLE $notifications')!==false;
$checks[]=strpos($db,'UNIQUE KEY institute_entity (institute_id,entity_type,entity_id)')!==false;
$checks[]=strpos($db,'UNIQUE KEY idempotency_key (idempotency_key)')!==false;
$meet=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-meet-access-service.php');
$checks[]=strpos($meet,"x.access_status='active'")!==false&&strpos($meet,'x.access_start<=%s')!==false&&strpos($meet,'x.access_end>=%s')!==false;
$checks[]=strpos($meet,"a.assignment_status='active'")!==false&&strpos($meet,'s.wp_user_id=%d')!==false;
$portal=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-portal-service.php');
$checks[]=strpos($portal,"c.teacher_id=%d")!==false&&strpos($portal,"c.student_id=%d")!==false;
$account=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-account-link-service.php');
$checks[]=strpos($account,'wp_generate_password( 32, true, true )')!==false&&strpos($account,'retrieve_password')!==false;
$checks[]=stripos($account,'@example.')===false;
$demo=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-demo-service.php');
$checks[]=strpos($demo,"'admission_id'=>\$admission")!==false&&strpos($demo,"demo_booking_moved")!==false;
$rest=file_get_contents(dirname(__DIR__).'/eduflow-core/includes/class-rest.php');
$checks[]=strpos($rest,"'/portal/student'")!==false&&strpos($rest,"permission_callback'=>array(__CLASS__,'logged_in')")!==false;
$checks[]=strpos($rest,'demo_token_permission')!==false;
foreach($checks as $i=>$ok){if(!$ok){fwrite(STDERR,'Failed Phase 5 assertion '.($i+1).PHP_EOL);exit(1);}}
echo count($checks)." Phase 5 assertions passed\n";
