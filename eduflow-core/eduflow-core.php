<?php
/**
 * Plugin Name: EduFlow Institute Suite
 * Description: Multi-institute foundation, security, audit, jobs, and integrations for EduFlow.
 * Version: 8.6.3
 * Author: EduFlow
 * Text Domain: eduflow-core
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'EDUFLOW_CORE_VERSION', '8.6.3' );
define( 'EDUFLOW_CORE_FILE', __FILE__ );
define( 'EDUFLOW_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once EDUFLOW_CORE_DIR . 'includes/class-db.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-roles.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-settings.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-id-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-audit-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-job-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-migration-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-legacy-adapter.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-contact-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-corporate-autoflow-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-batch-room-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-lecture-room.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-ytc-teacher-seed.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-auto-assignment-service.php';
require_once EDUFLOW_CORE_DIR . 'admin/class-corporate-teacher-admin.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-ytc-batch-setup.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-ytc-master-import.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-admission-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-student-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-production-orchestrator.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-access-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-payment-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-teacher-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-batch-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-class-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-crypto-service.php';
// YTC Meet bridge disabled - native EduFlow Google OAuth.
require_once EDUFLOW_CORE_DIR . 'includes/class-google-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-demo-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-account-link-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-notification-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-notification-delivery-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-attendance-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-report-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-portal-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-dashboard-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-meet-access-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-health-service.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-rest.php';
require_once EDUFLOW_CORE_DIR . 'includes/class-activator.php';
require_once EDUFLOW_CORE_DIR . 'admin/class-admin.php';
require_once EDUFLOW_CORE_DIR . 'admin/class-phase3-admin.php';
require_once EDUFLOW_CORE_DIR . 'admin/class-classes-corporate-admin.php';
require_once EDUFLOW_CORE_DIR . 'admin/class-google-admin.php';
require_once EDUFLOW_CORE_DIR . 'admin/class-demo-admin.php';
require_once EDUFLOW_CORE_DIR . 'admin/class-control-center.php';
require_once EDUFLOW_CORE_DIR . 'admin/class-phase6-admin.php';
require_once EDUFLOW_CORE_DIR . 'admin/class-migration-admin.php';
require_once EDUFLOW_CORE_DIR . 'public/class-portal.php';

register_activation_hook( __FILE__, array( 'EduFlow_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EduFlow_Activator', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
	EduFlow_Activator::maybe_upgrade();
	EduFlow_Job_Service::register();
	EduFlow_Job_Service::schedule();
	EduFlow_REST::register();
	EduFlow_Account_Link_Service::register();
	EduFlow_Notification_Delivery_Service::register();
	EduFlow_Migration_Service::register();
	EduFlow_Portal::register();
	add_action( 'update_option_eduflow_core_settings', array( 'EduFlow_Settings', 'sync' ), 10, 2 );
	if ( is_admin() ) {
		( new EduFlow_Admin() )->register();
		( new EduFlow_Phase3_Admin() )->register();
		( new EduFlow_Classes_Corporate_Admin() )->register();
		( new EduFlow_Google_Admin() )->register();
		( new EduFlow_Demo_Admin() )->register();
		( new EduFlow_Control_Center() )->register();
		( new EduFlow_Phase6_Admin() )->register();
		( new EduFlow_Migration_Admin() )->register();

		// Phase3 registers the legacy Classes renderer first. Remove only that
		// callback before the corporate Classes submenu is rebound at priority 999.
		add_action( 'admin_menu', static function () {
			remove_action( 'eduflow_page_eduflow-classes', array( 'EduFlow_Phase3_Admin', 'classes' ) );
		}, 998 );
	}
} );

require_once __DIR__ . '/includes/class-ytc-demoflow-bridge.php';
EduFlow_YTC_DemoFlow_Bridge::register();
