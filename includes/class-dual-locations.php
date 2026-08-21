<?php

if (!defined('ABSPATH')) exit;

class Olama_Transportation_Dual_Locations
{
    public static function list($academic_year_id)
    {
        global $wpdb;
        $data = Olama_Transportation_Family_Locations::admin_list($academic_year_id, array('per_page' => 100));
        $rows = array();
        foreach (($data['items'] ?? array()) as $row) {
            if (($row['location_mode'] ?? 'default') !== 'two_locations') continue;
            $rows[] = $row;
        }
        $trips = array_merge(
            Olama_Transportation_Shared_Trips::list_for_context($academic_year_id, 'morning'),
            Olama_Transportation_Shared_Trips::list_for_context($academic_year_id, 'afternoon')
        );
        foreach ($rows as &$row) {
            $row['arrival_major_area_id'] = absint($row['arrival_major_area_id'] ?? 0);
            $row['departure_major_area_id'] = absint($row['departure_major_area_id'] ?? 0);
            $row['dual_assignments'] = self::assignments($row['family_uid'], $academic_year_id);
        }
        unset($row);
        return array('items' => $rows, 'trips' => $trips, 'metrics' => array('families' => count($rows)));
    }

    private static function assignments($family_uid, $academic_year_id)
    {
        global $wpdb;
        $members = Olama_Transportation_DB::table('shared_trip_students');
        $trips = Olama_Transportation_DB::table('shared_trips');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT t.direction,t.id,t.name,t.status FROM {$members} m INNER JOIN {$trips} t ON t.id=m.trip_id WHERE m.family_uid=%s AND t.academic_year_id=%d AND t.status IN ('draft','published')",
            $family_uid, absint($academic_year_id)
        ), ARRAY_A);
        $out = array('morning' => null, 'afternoon' => null);
        foreach ($rows as $row) $out[$row['direction']] = array('id' => absint($row['id']), 'name' => (string)$row['name'], 'status' => (string)$row['status']);
        return $out;
    }

    public static function assign($academic_year_id, $family_uid, $direction, $trip_id)
    {
        global $wpdb;
        $year = absint($academic_year_id); $family_uid = sanitize_text_field($family_uid); $direction = sanitize_key($direction); $trip_id = absint($trip_id);
        if (!$year || !in_array($direction, array('morning','afternoon'), true) || !$trip_id) return new WP_Error('invalid_dual_assignment', __('Select a valid direction and trip.', 'olama-transportation'), array('status'=>400));
        $trip = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".Olama_Transportation_DB::table('shared_trips')." WHERE id=%d AND academic_year_id=%d AND direction=%s AND status IN ('draft','published')", $trip_id, $year, $direction), ARRAY_A);
        if (!$trip) return new WP_Error('dual_trip_unavailable', __('The selected trip is not available in the selected direction.', 'olama-transportation'), array('status'=>409));
        $list = Olama_Transportation_Family_Locations::admin_list($year, array('per_page'=>100));
        $family = null; foreach (($list['items'] ?? array()) as $item) if ((string)$item['family_uid'] === $family_uid) {$family=$item; break;}
        if (!$family || ($family['location_mode'] ?? '') !== 'two_locations') return new WP_Error('dual_family_required', __('This family is not configured with two locations.', 'olama-transportation'), array('status'=>400));
        $area_id = $direction === 'morning' ? absint($family['arrival_major_area_id'] ?? 0) : absint($family['departure_major_area_id'] ?? 0);
        if (!$area_id) return new WP_Error('dual_area_required', __('Assign a planning area for this location first.', 'olama-transportation'), array('status'=>400));
        $students = $family['students'] ?? array();
        if (!$students) return new WP_Error('dual_students_missing', __('No students were found for this family.', 'olama-transportation'), array('status'=>400));
        $members = Olama_Transportation_DB::table('shared_trip_students'); $trip_table = Olama_Transportation_DB::table('shared_trips');
        $old_trip_ids = array_map('absint', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT m.trip_id FROM {$members} m INNER JOIN {$trip_table} t ON t.id=m.trip_id WHERE m.family_uid=%s AND t.academic_year_id=%d AND t.direction=%s AND t.status IN ('draft','published') AND m.trip_id<>%d",
            $family_uid, $year, $direction, $trip_id
        )));
        // A family is unique per direction. Selecting a different trip moves all
        // of its students out of the previous trip before inserting them here.
        $now = current_time('mysql', true); $wpdb->query('START TRANSACTION');
        foreach ($old_trip_ids as $old_trip_id) {
            if ($wpdb->query($wpdb->prepare("DELETE FROM {$members} WHERE trip_id=%d AND family_uid=%s", $old_trip_id, $family_uid)) === false) {
                $wpdb->query('ROLLBACK'); return new WP_Error('dual_assignment_failed', $wpdb->last_error ?: __('Could not move the family from its previous trip.', 'olama-transportation'), array('status'=>500));
            }
            $wpdb->delete(Olama_Transportation_DB::table('shared_trip_queue'), array('trip_id'=>$old_trip_id));
            $wpdb->update($trip_table, array('trip_limit_acknowledged'=>0,'bus_limit_acknowledged'=>0,'updated_by'=>get_current_user_id()?:null,'updated_at'=>$now), array('id'=>$old_trip_id));
        }
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.Olama_Transportation_DB::table('shared_trip_areas').' (trip_id,major_area_id,created_at) VALUES (%d,%d,%s)', $trip_id, $area_id, $now));
        foreach ($students as $student) {
            $wpdb->query($wpdb->prepare('DELETE FROM '.$members.' WHERE trip_id=%d AND student_uid=%s', $trip_id, $student['student_uid']));
            $ok = $wpdb->insert($members, array('trip_id'=>$trip_id,'student_id'=>absint($student['student_id'] ?? 0),'student_uid'=>$student['student_uid'],'oracle_student_id'=>$student['oracle_student_id'] ?? null,'student_name'=>$student['student_name'] ?? $student['first_name'],'family_uid'=>$family_uid,'oracle_family_id'=>$family['oracle_family_id'],'major_area_id'=>$area_id,'grade_name'=>$student['class_name'] ?? null,'section_name'=>$student['section_name'] ?? null,'created_at'=>$now));
            if (!$ok) {$wpdb->query('ROLLBACK'); return new WP_Error('dual_assignment_failed', $wpdb->last_error ?: __('Could not add the family to the trip.', 'olama-transportation'), array('status'=>500));}
        }
        $wpdb->delete(Olama_Transportation_DB::table('shared_trip_queue'), array('trip_id'=>$trip_id));
        $wpdb->update($trip_table, array('trip_limit_acknowledged'=>0,'bus_limit_acknowledged'=>0,'updated_by'=>get_current_user_id()?:null,'updated_at'=>$now), array('id'=>$trip_id));
        $wpdb->query('COMMIT');
        foreach ($old_trip_ids as $old_trip_id) {
            $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$members} WHERE trip_id=%d", $old_trip_id));
            if (!$remaining) continue;
            $old_queue = Olama_Transportation_Shared_Trips::build_queue($old_trip_id);
            if (is_wp_error($old_queue)) return $old_queue;
        }
        $queue = Olama_Transportation_Shared_Trips::build_queue($trip_id);
        if (is_wp_error($queue)) return $queue;
        Olama_Transportation_Audit::record('dual_family_assigned', 'shared_trip', $trip_id, null, array('family_uid'=>$family_uid,'direction'=>$direction,'area_id'=>$area_id));
        return array('trip_id'=>$trip_id,'family_uid'=>$family_uid,'direction'=>$direction);
    }
}
