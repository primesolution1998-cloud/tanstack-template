<?php
defined('ABSPATH') || exit;

final class EduFlow_Batch_Room_Service {

    public static function get_room($batch_id,$institute_id=0){
        global $wpdb;

        $institute_id=$institute_id?:EduFlow_Settings::institute_db_id();
        $batch_id=absint($batch_id);

        if(!$batch_id) return null;

        $classes=EduFlow_DB::table('classes');
        $events=EduFlow_DB::table('google_events');

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    c.id AS lecture_id,
                    c.meet_url,
                    g.google_meet_url,
                    g.google_event_id,
                    g.google_event_url
                 FROM $classes c
                 LEFT JOIN $events g
                   ON g.lecture_id=c.id
                  AND g.institute_id=c.institute_id
                 WHERE c.institute_id=%d
                   AND c.batch_id=%d
                   AND (
                       c.meet_url IS NOT NULL
                       OR g.google_meet_url IS NOT NULL
                   )
                 ORDER BY c.id ASC
                 LIMIT 1",
                $institute_id,
                $batch_id
            ),
            ARRAY_A
        );
    }

    public static function sync_batch($batch_id,$institute_id=0){
        global $wpdb;

        $institute_id=$institute_id?:EduFlow_Settings::institute_db_id();
        $batch_id=absint($batch_id);

        $room=self::get_room($batch_id,$institute_id);

        if(!$room){
            return new WP_Error(
                'batch_room_missing',
                'Batch Google Meet room is not ready.'
            );
        }

        $meet=$room['google_meet_url']?:$room['meet_url'];

        if(!$meet){
            return new WP_Error(
                'batch_room_missing',
                'Batch Google Meet URL is missing.'
            );
        }

        $now=current_time('mysql',true);

        /*
         * Same batch = same Meet URL.
         * Do NOT duplicate Google event IDs across lectures.
         */
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE ".EduFlow_DB::table('classes')."
                 SET meet_url=%s,
                     updated_at=%s
                 WHERE institute_id=%d
                   AND batch_id=%d
                   AND class_status IN ('scheduled','rescheduled')",
                esc_url_raw($meet),
                $now,
                $institute_id,
                $batch_id
            )
        );

        /*
         * Existing mappings can display the shared Meet without
         * pretending every lecture owns the master Google event.
         */
        $classes=EduFlow_DB::table('classes');
        $events=EduFlow_DB::table('google_events');

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $events g
                 INNER JOIN $classes c
                   ON c.id=g.lecture_id
                  AND c.institute_id=g.institute_id
                 SET g.google_meet_url=%s,
                     g.sync_status='synced',
                     g.last_error=NULL,
                     g.updated_at=%s
                 WHERE c.institute_id=%d
                   AND c.batch_id=%d",
                esc_url_raw($meet),
                $now,
                $institute_id,
                $batch_id
            )
        );

        return esc_url_raw($meet);
    }
}
