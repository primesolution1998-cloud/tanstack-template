<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Activator {
	public static function activate() {
		EduFlow_DB::install(); EduFlow_Roles::install(); EduFlow_Settings::seed(); EduFlow_Job_Service::schedule();
		update_option( 'eduflow_core_db_version', EDUFLOW_CORE_VERSION, false ); flush_rewrite_rules();
	}
	public static function maybe_upgrade() { if ( EDUFLOW_CORE_VERSION !== get_option( 'eduflow_core_db_version' ) ) { self::activate(); } }
	public static function deactivate() { wp_clear_scheduled_hook( EduFlow_Job_Service::CRON_HOOK ); flush_rewrite_rules(); }
}
