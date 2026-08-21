<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Roles {
	const CAPS = array( 'eduflow_manage_institute', 'eduflow_manage_admissions', 'eduflow_manage_students', 'eduflow_manage_teachers', 'eduflow_manage_batches', 'eduflow_manage_classes', 'eduflow_manage_payments', 'eduflow_manage_crm', 'eduflow_manage_reports', 'eduflow_manage_settings', 'eduflow_view_audit', 'eduflow_view_own_classes', 'eduflow_view_own_profile' );
	public static function install() {
		$all = array_fill_keys( self::CAPS, true );
		$administrator = get_role( 'administrator' );
		if ( $administrator ) { foreach ( self::CAPS as $cap ) { $administrator->add_cap( $cap ); } }
		$manager = $all; unset( $manager['eduflow_manage_institute'], $manager['eduflow_manage_settings'] );
		$definitions = array(
			'eduflow_owner'=>array( 'EduFlow Owner / Super Admin', array_merge( array( 'read'=>true ), $all ) ),
			'eduflow_institute_admin'=>array( 'EduFlow Institute Admin', array_merge( array( 'read'=>true ), $all ) ),
			'eduflow_manager'=>array( 'EduFlow Manager', array_merge( array( 'read'=>true ), $manager ) ),
			'eduflow_teacher'=>array( 'EduFlow Teacher', array( 'read'=>true, 'eduflow_view_own_classes'=>true, 'eduflow_view_own_profile'=>true ) ),
			'eduflow_student'=>array( 'EduFlow Student', array( 'read'=>true, 'eduflow_view_own_classes'=>true, 'eduflow_view_own_profile'=>true ) ),
		);
		foreach ( $definitions as $slug=>$definition ) {
			add_role( $slug, $definition[0], $definition[1] );
			$role = get_role( $slug ); if ( ! $role ) { continue; }
			foreach ( self::CAPS as $cap ) { $role->remove_cap( $cap ); }
			foreach ( $definition[1] as $cap=>$grant ) { $grant ? $role->add_cap( $cap ) : $role->remove_cap( $cap ); }
		}
	}
}
