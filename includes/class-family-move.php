<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Atomic, family-level transfers between compatible shared trips. */
class Olama_Transportation_Family_Move
{
    public static function context($academic_year_id, $direction)
    {
        $year = absint($academic_year_id);
        $direction = sanitize_key($direction);
        if (!$year || !in_array($direction, array('morning', 'afternoon'), true)) {
            return new WP_Error('invalid_family_move_context', __('Academic year and direction are required.', 'olama-transportation'), array('status' => 400));
        }
        return array(
            'academic_year_id' => $year,
            'direction' => $direction,
            'trips' => Olama_Transportation_Shared_Trips::list_for_context($year, $direction),
        );
    }

    public static function trip($id)
    {
        $trip = Olama_Transportation_Shared_Trips::get($id);
        if (!$trip || !in_array($trip['status'], array('draft', 'published'), true)) {
            return new WP_Error('family_move_trip_not_found', __('Trip was not found.', 'olama-transportation'), array('status' => 404));
        }
        if ($trip['student_count'] && empty($trip['queue'])) {
            $rebuilt = Olama_Transportation_Shared_Trips::build_queue($trip['id']);
            if (is_wp_error($rebuilt)) return $rebuilt;
            $trip = $rebuilt;
        }
        $area_names = array();
        foreach ($trip['areas'] as $area) $area_names[(int) $area['id']] = (string) $area['name'];
        $families = array();
        foreach ($trip['students'] as $student) {
            $uid = (string) $student['family_uid'];
            if (!isset($families[$uid])) {
                $families[$uid] = array(
                    'family_uid' => $uid,
                    'oracle_family_id' => (string) $student['oracle_family_id'],
                    'family_name' => '',
                    'student_count' => 0,
                    'students' => array(),
                    'area_ids' => array(),
                    'area_names' => array(),
                    'latitude' => null,
                    'longitude' => null,
                    'location_status' => 'missing_location',
                    'queue_position' => 0,
                    'dual_location' => false,
                );
            }
            $area_id = (int) $student['major_area_id'];
            $families[$uid]['students'][] = array(
                'student_uid' => (string) $student['student_uid'],
                'name' => (string) $student['student_name'],
                'grade' => (string) $student['grade_name'],
                'section' => (string) $student['section_name'],
            );
            $families[$uid]['student_count']++;
            if (!in_array($area_id, $families[$uid]['area_ids'], true)) {
                $families[$uid]['area_ids'][] = $area_id;
                $families[$uid]['area_names'][] = $area_names[$area_id] ?? sprintf(__('Area #%d', 'olama-transportation'), $area_id);
            }
        }
        foreach ($trip['queue'] as $node) {
            if ($node['node_type'] !== 'family' || !isset($families[(string) $node['family_uid']])) continue;
            $family =& $families[(string) $node['family_uid']];
            $family['family_name'] = (string) $node['family_name'];
            $family['latitude'] = $node['latitude'] === null ? null : (float) $node['latitude'];
            $family['longitude'] = $node['longitude'] === null ? null : (float) $node['longitude'];
            $family['location_status'] = (string) $node['location_status'];
            $family['queue_position'] = (int) $node['queue_position'];
            $family['dual_location'] = !empty($node['dual_location']);
            unset($family);
        }
        $trip['families'] = array_values($families);
        usort($trip['families'], static function ($a, $b) {
            return $a['queue_position'] === $b['queue_position']
                ? strnatcasecmp($a['oracle_family_id'], $b['oracle_family_id'])
                : $a['queue_position'] - $b['queue_position'];
        });
        unset($trip['students']);
        return $trip;
    }

    public static function move($data)
    {
        global $wpdb;
        $source_id = absint($data['source_trip_id'] ?? 0);
        $destination_id = absint($data['destination_trip_id'] ?? 0);
        $family_uids = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) ($data['family_uids'] ?? array())))));
        $reason = sanitize_textarea_field($data['reason'] ?? '');
        if (!$source_id || !$destination_id || $source_id === $destination_id || !$family_uids) {
            return new WP_Error('invalid_family_move', __('Choose two different trips and at least one family.', 'olama-transportation'), array('status' => 400));
        }
        if (count($family_uids) > 100) {
            return new WP_Error('family_move_too_large', __('Move no more than 100 families at a time.', 'olama-transportation'), array('status' => 400));
        }

        $trips_table = Olama_Transportation_DB::table('shared_trips');
        $members_table = Olama_Transportation_DB::table('shared_trip_students');
        $areas_table = Olama_Transportation_DB::table('shared_trip_areas');
        $assignments_table = $wpdb->prefix . 'olama_student_bus_assignments';
        $trip_ids = array($source_id, $destination_id);
        sort($trip_ids, SORT_NUMERIC);
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$trips_table} WHERE id IN (%d,%d) ORDER BY id FOR UPDATE",
            $trip_ids[0], $trip_ids[1]
        ), ARRAY_A);
        if (count($locked) !== 2) return self::rollback_error('family_move_trip_not_found', __('One of the selected trips was not found.', 'olama-transportation'), 404);

        $source = Olama_Transportation_Shared_Trips::get($source_id);
        $destination = Olama_Transportation_Shared_Trips::get($destination_id);
        if (!$source || !$destination || !in_array($source['status'], array('draft', 'published'), true) || !in_array($destination['status'], array('draft', 'published'), true)) {
            return self::rollback_error('family_move_trip_unavailable', __('Both trips must be active drafts or published trips.', 'olama-transportation'), 409);
        }
        if ((int) $source['academic_year_id'] !== (int) $destination['academic_year_id'] || $source['direction'] !== $destination['direction']) {
            return self::rollback_error('family_move_context_mismatch', __('Families can only move between trips in the same academic year and direction.', 'olama-transportation'), 409);
        }
        if ($source['status'] !== $destination['status']) {
            return self::rollback_error('family_move_status_mismatch', __('Both trips must have the same draft or published status.', 'olama-transportation'), 409);
        }
        $bus_ids = array_values(array_unique(array_filter(array_map('absint', array($source['bus_id'], $destination['bus_id'])))));
        sort($bus_ids, SORT_NUMERIC);
        if ($bus_ids) {
            $bus_placeholders = implode(',', array_fill(0, count($bus_ids), '%d'));
            $wpdb->get_col($wpdb->prepare(
                'SELECT id FROM ' . Olama_Transportation_DB::table('buses') . " WHERE id IN ({$bus_placeholders}) ORDER BY id FOR UPDATE",
                $bus_ids
            ));
            // Capacity may have changed before the bus lock was acquired.
            $source = Olama_Transportation_Shared_Trips::get($source_id);
            $destination = Olama_Transportation_Shared_Trips::get($destination_id);
        }
        if (!$destination['bus_id'] || !(int) $destination['bus_capacity']) {
            return self::rollback_error('family_move_destination_bus_required', __('Assign a bus with usable capacity to the destination trip first.', 'olama-transportation'), 409);
        }

        $placeholders = implode(',', array_fill(0, count($family_uids), '%s'));
        $source_params = array_merge(array($source_id), $family_uids);
        $moving_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE trip_id=%d AND family_uid IN ({$placeholders}) ORDER BY id FOR UPDATE",
            $source_params
        ), ARRAY_A);
        $found_families = array_values(array_unique(array_column($moving_rows, 'family_uid')));
        sort($found_families, SORT_STRING);
        $expected_families = $family_uids;
        sort($expected_families, SORT_STRING);
        if (!$moving_rows || $found_families !== $expected_families) {
            return self::rollback_error('family_move_membership_changed', __('One or more selected families no longer belong to the source trip. Refresh and try again.', 'olama-transportation'), 409);
        }

        $context_params = array_merge(array($source['academic_year_id'], $source['direction'], $source_id), $family_uids);
        $elsewhere = $wpdb->get_var($wpdb->prepare(
            "SELECT m.family_uid FROM {$members_table} m INNER JOIN {$trips_table} t ON t.id=m.trip_id
             WHERE t.academic_year_id=%d AND t.direction=%s AND t.status IN ('draft','published')
               AND m.trip_id<>%d AND m.family_uid IN ({$placeholders}) LIMIT 1 FOR UPDATE",
            $context_params
        ));
        if ($elsewhere) {
            return self::rollback_error('family_move_family_conflict', __('A selected family already has students in another trip.', 'olama-transportation'), 409);
        }

        $moving_area_ids = array_values(array_unique(array_map('intval', array_column($moving_rows, 'major_area_id'))));
        $destination_area_ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT major_area_id FROM {$areas_table} WHERE trip_id=%d FOR UPDATE", $destination_id)));
        $added_area_ids = array_values(array_filter(array_diff($moving_area_ids, $destination_area_ids)));

        $destination_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT student_uid) FROM {$members_table} WHERE trip_id=%d", $destination_id));
        $moving_count = count(array_unique(array_column($moving_rows, 'student_uid')));
        $after_count = $destination_count + $moving_count;
        if ($after_count > (int) $destination['bus_capacity']) {
            return self::rollback_error('family_move_bus_capacity', sprintf(__('The move would exceed the destination bus capacity by %d students.', 'olama-transportation'), $after_count - (int) $destination['bus_capacity']), 409);
        }
        if ($after_count > (int) $destination['planning_limit']) {
            return self::rollback_error('family_move_trip_limit', sprintf(__('The move would exceed the destination planning limit by %d students.', 'olama-transportation'), $after_count - (int) $destination['planning_limit']), 409);
        }

        $now = current_time('mysql', true);
        foreach ($added_area_ids as $area_id) {
            if (!$wpdb->insert($areas_table, array('trip_id'=>$destination_id, 'major_area_id'=>$area_id, 'created_at'=>$now))) {
                return self::rollback_error('family_move_save_failed', $wpdb->last_error ?: __('Could not add the family planning area to the destination trip.', 'olama-transportation'), 500);
            }
        }
        foreach ($moving_rows as $row) {
            unset($row['id']);
            $row['trip_id'] = $destination_id;
            $row['created_at'] = $now;
            if (!$wpdb->insert($members_table, $row)) {
                return self::rollback_error('family_move_save_failed', $wpdb->last_error ?: __('Could not add the family to the destination trip.', 'olama-transportation'), 500);
            }
        }
        $delete_params = array_merge(array($source_id), $family_uids);
        if ($wpdb->query($wpdb->prepare("DELETE FROM {$members_table} WHERE trip_id=%d AND family_uid IN ({$placeholders})", $delete_params)) === false) {
            return self::rollback_error('family_move_save_failed', $wpdb->last_error ?: __('Could not remove the family from the source trip.', 'olama-transportation'), 500);
        }

        $student_uids = array_values(array_unique(array_column($moving_rows, 'student_uid')));
        if ($source['status'] === 'published') {
            $student_placeholders = implode(',', array_fill(0, count($student_uids), '%s'));
            $delete_assignment_params = array_merge(array($source['academic_year_id'], $source['direction']), $student_uids);
            if ($wpdb->query($wpdb->prepare(
                "DELETE FROM {$assignments_table} WHERE academic_year_id=%d AND direction=%s AND student_uid IN ({$student_placeholders})",
                $delete_assignment_params
            )) === false) {
                return self::rollback_error('family_move_assignment_failed', $wpdb->last_error ?: __('Could not update the live bus assignments.', 'olama-transportation'), 500);
            }
            foreach ($moving_rows as $row) {
                if (!$wpdb->insert($assignments_table, array(
                    'student_id' => (int) $row['student_id'], 'student_uid' => $row['student_uid'],
                    'bus_id' => (int) $destination['bus_id'], 'academic_year_id' => (int) $destination['academic_year_id'],
                    'direction' => $destination['direction'], 'trip_number' => (int) $destination['bus_trip_number'],
                    'shared_trip_id' => $destination_id, 'assigned_at' => current_time('mysql'), 'assigned_by' => get_current_user_id(),
                ))) {
                    return self::rollback_error('family_move_assignment_failed', $wpdb->last_error ?: __('Could not update the live bus assignments.', 'olama-transportation'), 500);
                }
            }
        }

        $trip_update = array('trip_limit_acknowledged'=>0, 'bus_limit_acknowledged'=>0, 'updated_by'=>get_current_user_id()?:null, 'updated_at'=>$now);
        if ($wpdb->update($trips_table, $trip_update, array('id'=>$source_id)) === false || $wpdb->update($trips_table, $trip_update, array('id'=>$destination_id)) === false) {
            return self::rollback_error('family_move_save_failed', $wpdb->last_error ?: __('Could not update the affected trips.', 'olama-transportation'), 500);
        }
        $source_queue = Olama_Transportation_Shared_Trips::build_queue($source_id, false);
        if (is_wp_error($source_queue) && $source_queue->get_error_code() !== 'empty_shared_trip') {
            $wpdb->query('ROLLBACK');
            return $source_queue;
        }
        if (is_wp_error($source_queue)) $wpdb->delete(Olama_Transportation_DB::table('shared_trip_queue'), array('trip_id'=>$source_id));
        $destination_queue = Olama_Transportation_Shared_Trips::build_queue($destination_id, false);
        if (is_wp_error($destination_queue)) {
            $wpdb->query('ROLLBACK');
            return $destination_queue;
        }
        Olama_Transportation_Audit::record('families_moved', 'shared_trip', $destination_id, array(
            'source_trip_id'=>$source_id, 'destination_trip_id'=>$destination_id,
            'family_uids'=>$family_uids, 'student_count'=>$moving_count, 'added_area_ids'=>array(),
        ), array(
            'source_trip_id'=>$source_id, 'destination_trip_id'=>$destination_id,
            'family_uids'=>$family_uids, 'student_count'=>$moving_count, 'added_area_ids'=>$added_area_ids, 'reason'=>$reason,
        ));
        $wpdb->query('COMMIT');

        return array(
            'moved_family_uids' => $family_uids,
            'moved_family_count' => count($family_uids),
            'moved_student_count' => $moving_count,
            'added_area_ids' => $added_area_ids,
            'source_trip' => self::trip($source_id),
            'destination_trip' => self::trip($destination_id),
            'routes_need_recalculation' => true,
        );
    }

    private static function rollback_error($code, $message, $status, $extra = array())
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        return new WP_Error($code, $message, array_merge(array('status' => $status), $extra));
    }
}
