<?php
// Meet authorization branch tests with a deterministic fake DB; no live WordPress/MySQL is claimed.
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
class WP_Error {private $code;public function __construct($code='',$message='',$data=null){$this->code=$code;}public function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}function get_current_user_id(){return 0;}function current_time($type){return '2026-08-20';}function esc_url_raw($v){return $v;}
$GLOBALS['scenario']='none';
function user_can($user,$cap){return 'manager'===$GLOBALS['scenario'];}
class EduFlow_Settings {public static function institute_db_id(){return 7;}}
class EduFlow_DB {public static function table($name){return 'ef_'.$name;}}
class EduFlow_Account_Link_Service {public static function linked_entity_any_institute($type,$user){return null;}}
class EduFlow_Class_Service {public static $lecture;public static function get($id,$inst){return self::$lecture;}}
class FakeWPDB {
 public function prepare($sql,...$args){return $sql;}
 public function get_var($sql){
  if(strpos($sql,'FROM ef_teachers')!==false){return 'teacher'===$GLOBALS['scenario']?44:null;}
  if(strpos($sql,'FROM ef_students')!==false){return in_array($GLOBALS['scenario'],array('student_batch','student_one'),true)?55:null;}
  if(strpos($sql,'FROM ef_google_events')!==false){return 'https://meet.google.com/shared-slot';}
  return null;
 }
}
$GLOBALS['wpdb']=new FakeWPDB();
require dirname(__DIR__).'/eduflow-core/includes/class-meet-access-service.php';
$batch=array('id'=>10,'teacher_id'=>44,'student_id'=>null,'batch_id'=>5,'class_status'=>'scheduled');
$one=array('id'=>11,'teacher_id'=>44,'student_id'=>55,'batch_id'=>null,'class_status'=>'scheduled');
$checks=array();
EduFlow_Class_Service::$lecture=$batch;$GLOBALS['scenario']='manager';$checks[]=EduFlow_Meet_Access_Service::get_url(10,1,7)==='https://meet.google.com/shared-slot';
$GLOBALS['scenario']='teacher';$checks[]=EduFlow_Meet_Access_Service::get_url(10,2,7)==='https://meet.google.com/shared-slot';
$GLOBALS['scenario']='student_batch';$checks[]=EduFlow_Meet_Access_Service::get_url(10,3,7)==='https://meet.google.com/shared-slot';
EduFlow_Class_Service::$lecture=$one;$GLOBALS['scenario']='student_one';$checks[]=EduFlow_Meet_Access_Service::get_url(11,3,7)==='https://meet.google.com/shared-slot';
$GLOBALS['scenario']='expired';$checks[]=is_wp_error(EduFlow_Meet_Access_Service::get_url(11,3,7));
$GLOBALS['scenario']='other';$checks[]=is_wp_error(EduFlow_Meet_Access_Service::get_url(11,9,7));
EduFlow_Class_Service::$lecture=array_merge($batch,array('class_status'=>'cancelled'));$GLOBALS['scenario']='manager';$checks[]=is_wp_error(EduFlow_Meet_Access_Service::get_url(10,1,7));
foreach($checks as $i=>$ok){if(!$ok){fwrite(STDERR,'Failed Meet auth assertion '.($i+1).PHP_EOL);exit(1);}}
echo count($checks)." Meet authorization assertions passed\n";
