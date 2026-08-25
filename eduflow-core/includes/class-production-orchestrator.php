<?php
defined( 'ABSPATH' ) || exit;

final class EduFlow_Production_Orchestrator {
    public static function approve_admission( $admission_id, $institute_id = 0 ) {
        global $wpdb;
        $institute_id = $institute_id ?: EduFlow_Settings::institute_db_id();
        $admission = EduFlow_Admission_Service::get( $admission_id, $institute_id );
        if ( ! $admission ) return new WP_Error( 'admission_not_found', 'Admission not found.' );

        $existing_student = absint( $admission['student_id'] ?? 0 );
        if ( $existing_student ) return $existing_student;

        $status = EduFlow_Admission_Service::set_status( $admission_id, 'approved', $institute_id );
        if ( is_wp_error( $status ) ) return $status;

        if ( (float) $admission['amount_paid'] > 0 ) {
            $source = 'admission_initial:' . $institute_id . ':' . $admission_id;
            $payments = EduFlow_DB::table( 'payments' );
            $payment_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $payments WHERE institute_id=%d AND source_identifier=%s LIMIT 1",
                $institute_id, $source
            ) );
            if ( ! $payment_id ) {
                $payment_id = EduFlow_Payment_Service::create( array(
                    'admission_id'          => $admission_id,
                    'amount'                => (float) $admission['amount_paid'],
                    'payment_method'        => $admission['payment_method'] ?: 'upi',
                    'transaction_reference' => $admission['transaction_reference'] ?: '',
                    'source_identifier'     => $source,
                    'payment_date'          => $admission['admission_date'],
                    'access_period'         => $admission['access_period'] ?: '3_months',
                    'custom_access_end'     => $admission['custom_access_end'] ?: '',
                    'notes'                 => $admission['notes'] ?: 'Initial payment captured from Admission.',
                ), $institute_id );
                if ( is_wp_error( $payment_id ) ) return $payment_id;
            }
            $payment = EduFlow_Payment_Service::get( $payment_id, $institute_id );
            if ( $payment && 'pending' === $payment['verification_status'] ) {
                $verified = EduFlow_Payment_Service::decide( $payment_id, 'verified', $institute_id );
                if ( is_wp_error( $verified ) ) return $verified;
            }
        }

        $student_id = EduFlow_Student_Service::convert_admission( $admission_id, $institute_id );
        if ( is_wp_error( $student_id ) ) return $student_id;

        $corporate_autoflow = EduFlow_Corporate_Autoflow_Service::sync_student_from_admission(
            $student_id,
            $admission_id,
            $institute_id
        );

        if ( is_wp_error( $corporate_autoflow ) ) {
            EduFlow_Audit_Service::log(
                'corporate_autoflow_pending',
                'student',
                $student_id,
                null,
                array(
                    'reason' => $corporate_autoflow->get_error_message()
                ),
                $institute_id
            );
        }

        $student = EduFlow_Student_Service::get( $student_id, $institute_id );
        if ( $student && ! empty( $student['email'] ) && empty( $student['wp_user_id'] ) ) {
            $invite = EduFlow_Account_Link_Service::create_student_invitation( $student_id, $institute_id );
            if ( is_wp_error( $invite ) ) {
                EduFlow_Audit_Service::log( 'student_invitation_pending', 'student', $student['canonical_id'], null, array( 'reason'=>$invite->get_error_code() ), $institute_id );
            }
        }

        EduFlow_Audit_Service::log( 'admission_auto_orchestrated', 'admission', $admission['canonical_id'], null, array( 'student_id'=>$student_id ), $institute_id );
        return $student_id;
    }
}
