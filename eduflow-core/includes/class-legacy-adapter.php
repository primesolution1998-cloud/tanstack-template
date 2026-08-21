<?php
defined( 'ABSPATH' ) || exit;

interface EduFlow_Legacy_Adapter_Interface {
	public function key(); public function entity_type(); public function detect(); public function count();
	public function preview( $offset, $limit ); public function normalize( array $row ); public function validate( array $row );
	public function reconcile( array $row, $institute_id ); public function import( array $row, $institute_id );
}

class EduFlow_Legacy_Table_Adapter implements EduFlow_Legacy_Adapter_Interface {
	protected $definition;
	public function __construct( array $definition ) { $this->definition=$definition; }
	public function key(){return sanitize_key($this->definition['key']??'');}
	public function entity_type(){return sanitize_key($this->definition['entity_type']??'unsupported');}
	protected function table(){global $wpdb;$table=(string)($this->definition['table']??'');return preg_match('/^'.preg_quote($wpdb->prefix,'/').'[A-Za-z0-9_]+$/',$table)?$table:'';}
	public function detect(){global $wpdb;$table=$this->table();return $table&&$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table;}
	public function count(){global $wpdb;return $this->detect()?(int)$wpdb->get_var('SELECT COUNT(*) FROM `'.esc_sql($this->table()).'`'):0;}
	protected function columns(){global $wpdb;$table=$this->table();if(!$table){return array();}return array_map('sanitize_key',(array)$wpdb->get_col('SHOW COLUMNS FROM `'.esc_sql($table).'`'));}
	public function preview($offset,$limit){global $wpdb;if(!$this->detect()){return array();}$columns=$this->columns();$requested=array_values(array_intersect($columns,array_map('sanitize_key',(array)($this->definition['columns']??array()))));if(!$requested){return array();}$select=implode(',',array_map(static function($c){return '`'.esc_sql($c).'`';},$requested));return $wpdb->get_results($wpdb->prepare('SELECT '.$select.' FROM `'.esc_sql($this->table()).'` ORDER BY `'.esc_sql($this->definition['primary_key']).'` ASC LIMIT %d OFFSET %d',min(200,max(1,absint($limit))),absint($offset)),ARRAY_A);}
	public function normalize(array $row){$map=(array)($this->definition['field_map']??array());$out=array();foreach($map as$target=>$source){if(array_key_exists($source,$row)){$out[sanitize_key($target)]=$row[$source];}}if(isset($out['mobile'])){$mobile=EduFlow_Contact_Service::normalize_indian_mobile($out['mobile']);$out['mobile']=is_wp_error($mobile)?'':$mobile;}if(isset($out['email'])){$out['email']=sanitize_email($out['email']);}return apply_filters('eduflow_legacy_normalized_'.$this->key(),$out,$row);}
	public function validate(array $row){$required=(array)($this->definition['required']??array());$errors=array();foreach($required as$field){if(!isset($row[$field])||''===$row[$field]){$errors[]='Missing '.sanitize_key($field).'.';}}if(isset($row['email'])&&$row['email']&&!is_email($row['email'])){$errors[]='Invalid email.';}if(isset($row['mobile'])&&''===$row['mobile']){$errors[]='Invalid mobile.';}return $errors;}
	public function fingerprint(array $row){ksort($row);return hash('sha256',wp_json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
	public function source_id(array $row){$key=sanitize_key($this->definition['primary_key']);return sanitize_text_field((string)($row[$key]??''));}
	public function reconcile(array $row,$institute_id){return EduFlow_Migration_Service::reconcile($this,$row,$institute_id);}
	public function import(array $row,$institute_id){$callback=$this->definition['import_callback']??null;return is_callable($callback)?call_user_func($callback,$this->normalize($row),$institute_id,$this):new WP_Error('manual_mapping_required','This detected schema requires a reviewed import callback.');}
	public function source_table(){return $this->table();}
}

class EduFlow_Legacy_Admission_Adapter extends EduFlow_Legacy_Table_Adapter {}
class EduFlow_Legacy_Student_Adapter extends EduFlow_Legacy_Table_Adapter {}
class EduFlow_Legacy_Teacher_Adapter extends EduFlow_Legacy_Table_Adapter {}
class EduFlow_Legacy_Payment_Adapter extends EduFlow_Legacy_Table_Adapter {}
class EduFlow_Legacy_Batch_Adapter extends EduFlow_Legacy_Table_Adapter {}
class EduFlow_Legacy_Class_Adapter extends EduFlow_Legacy_Table_Adapter {}
class EduFlow_Legacy_Demo_Adapter extends EduFlow_Legacy_Table_Adapter {}
class EduFlow_Legacy_Meet_Adapter extends EduFlow_Legacy_Table_Adapter {}
