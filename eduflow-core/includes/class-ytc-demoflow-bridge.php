<?php
defined('ABSPATH') || exit;

final class EduFlow_YTC_DemoFlow_Bridge {

    public static function register() {
        add_action('ytc_demoflow_booking_created', array(__CLASS__, 'import_booking'), 20, 2);
    }

    public static function import_booking($legacy_booking_id, $data) {
        global $wpdb;

        $institute_id = EduFlow_Settings::institute_db_id();
        if (!$institute_id) return;

        $legacy_table = $wpdb->prefix . 'ytc_demoflow_bookings';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $legacy_table WHERE id=%d", absint($legacy_booking_id)),
            ARRAY_A
        );

        if (!$row && is_array($data)) {
            $row = $data;
        }
        if (!$row) return;

        $name = $row['student_name'] ?? $row['name'] ?? $row['prospect_name'] ?? '';
        $mobile = $row['mobile'] ?? $row['phone'] ?? '';
        $email = $row['email'] ?? '';

        $date = $row['demo_date'] ?? $row['date'] ?? '';
        $start = $row['start_time'] ?? $row['slot_start'] ?? $row['time_from'] ?? '';
        $end = $row['end_time'] ?? $row['slot_end'] ?? $row['time_to'] ?? '';

        if (!$date || !$start || !$end || !$name || !$mobile) return;

        $teachers = EduFlow_DB::table('teachers');
        $teacher_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $teachers WHERE institute_id=%d AND teacher_status='active' ORDER BY id ASC LIMIT 1",
                $institute_id
            )
        );

        if (!$teacher_id) return;

        $session_input = array(
            'demo_date'  => $date,
            'start_time' => $start,
            'end_time'   => $end,
            'teacher_id' => $teacher_id,
            'capacity'   => 10,
        );

        $booking_input = array(
            'prospect_name' => $name,
            'mobile'        => $mobile,
            'email'         => $email,
        );

        $result = EduFlow_Demo_Service::add_booking(
            $session_input,
            $booking_input,
            $institute_id
        );

        if (!is_wp_error($result)) {
            EduFlow_Audit_Service::log(
                'legacy_demoflow_imported',
                'demo_booking',
                $result,
                null,
                array('legacy_booking_id' => $legacy_booking_id),
                $institute_id
            );
        }
    }
}
