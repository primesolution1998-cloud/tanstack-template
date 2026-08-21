<?php
// Lightweight domain tests that do not claim a WordPress/database integration environment.
define( 'ABSPATH', __DIR__ );
class WP_Error { private $code; private $message; public function __construct($code,$message){$this->code=$code;$this->message=$message;} public function get_error_message(){return $this->message;} }
function is_wp_error($value){return $value instanceof WP_Error;}
require dirname(__DIR__).'/eduflow-core/includes/class-contact-service.php';
require dirname(__DIR__).'/eduflow-core/includes/class-access-service.php';
$cases = array(
	EduFlow_Contact_Service::normalize_indian_mobile('+91 98765-43210') === '9876543210',
	EduFlow_Contact_Service::normalize_indian_mobile('09876543210') === '9876543210',
	is_wp_error(EduFlow_Contact_Service::normalize_indian_mobile('123')),
	EduFlow_Access_Service::calculate_end('2026-08-20','1_month') === '2026-09-20',
	EduFlow_Access_Service::calculate_end('2026-09-20','3_months') === '2026-12-20',
	EduFlow_Access_Service::calculate_end('2026-08-20','custom','2027-01-15') === '2027-01-15',
	is_wp_error(EduFlow_Access_Service::calculate_end('2026-08-20','custom','2026-01-01')),
);
foreach($cases as $index=>$passed){if(!$passed){fwrite(STDERR,'Failed case '.($index+1).PHP_EOL);exit(1);}}
echo "7 domain assertions passed\n";
