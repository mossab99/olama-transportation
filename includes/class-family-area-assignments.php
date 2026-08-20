<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Family_Area_Assignments
{
    public static function assign_family($family_uid, $major_area_id, $academic_year_id = 0, $include_effective = true)
    {
        $result = self::bulk_assign_families(array($family_uid), $major_area_id, $academic_year_id, $include_effective);
        if (is_wp_error($result)) {
            return $result;
        }
        return array_merge($result, array('family_stop' => $result['family_stops'][0] ?? null));
    }

    /** Assign Core families, creating location-less transportation placeholders atomically. */
    public static function bulk_assign_families($family_uids, $major_area_id, $academic_year_id = 0, $include_effective = true)
    {
        global $wpdb;
        $uids = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $family_uids))));
        $area_id = absint($major_area_id);
        if (!$uids) {
            return new WP_Error('missing_families', __('Select at least one family.', 'olama-transportation'), array('status' => 400));
        }
        $areas = Olama_Transportation_DB::table('major_areas');
        $stops = Olama_Transportation_DB::table('family_stops');
        $core_families = olama_core()->read_models()->table('families');
        $uid_placeholders = implode(',', array_fill(0, count($uids), '%s'));
        $families = $wpdb->get_results($wpdb->prepare(
            "SELECT family_uid,oracle_family_id,trans_region_name FROM {$core_families} WHERE family_uid IN ({$uid_placeholders})",
            $uids
        ), ARRAY_A);
        if (count($families) !== count($uids)) {
            return new WP_Error('core_family_not_found', __('One or more selected families no longer exist in Olama Core. No changes were saved.', 'olama-transportation'), array('status' => 404));
        }
        if ($area_id) {
            $area = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$areas} WHERE id=%d", $area_id), ARRAY_A);
            if (!$area || $area['status'] !== 'active') {
                return new WP_Error('invalid_planning_area', __('The planning area does not exist or is inactive.', 'olama-transportation'), array('status' => 400));
            }
        }

        $now = current_time('mysql', true);
        $user_id = get_current_user_id() ?: null;
        $wpdb->query('START TRANSACTION');
        $before = array();
        $stop_ids = array();
        try {
            if ($area_id) {
                $locked_area = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$areas} WHERE id=%d FOR UPDATE", $area_id), ARRAY_A);
                if (!$locked_area || $locked_area['status'] !== 'active') {
                    throw new RuntimeException(__('The planning area became unavailable. No changes were saved.', 'olama-transportation'));
                }
            }
            $existing = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$stops} WHERE family_uid IN ({$uid_placeholders}) FOR UPDATE",
                $uids
            ), ARRAY_A);
            $by_uid = array();
            foreach ($existing as $row) {
                $by_uid[$row['family_uid']] = $row;
            }
            foreach ($families as $family) {
                $row = $by_uid[$family['family_uid']] ?? null;
                if (!$row) {
                    $inserted = $wpdb->insert($stops, array(
                        'family_uid' => $family['family_uid'], 'oracle_family_id' => $family['oracle_family_id'],
                        'latitude' => null, 'longitude' => null, 'area_text' => $family['trans_region_name'],
                        'major_area_id' => $area_id ?: null, 'area_assignment_source' => 'manual',
                        'area_assigned_by' => $user_id, 'area_assigned_at' => $now,
                        'source' => 'planning_placeholder', 'verification_status' => 'missing',
                        'created_at' => $now, 'updated_at' => $now,
                    ));
                    if (!$inserted) {
                        throw new RuntimeException($wpdb->last_error ?: __('Could not create the family transportation record.', 'olama-transportation'));
                    }
                    $stop_ids[] = (int) $wpdb->insert_id;
                    $before[$family['family_uid']] = null;
                } else {
                    $before[$family['family_uid']] = array('id' => (int) $row['id'], 'major_area_id' => $row['major_area_id'] ? (int) $row['major_area_id'] : null);
                    $updated = $wpdb->update($stops, array(
                        'major_area_id' => $area_id ?: null, 'area_assignment_source' => 'manual',
                        'area_assigned_by' => $user_id, 'area_assigned_at' => $now, 'updated_at' => $now,
                    ), array('id' => (int) $row['id']));
                    if ($updated === false) {
                        throw new RuntimeException($wpdb->last_error ?: __('Could not update a family planning area.', 'olama-transportation'));
                    }
                    $stop_ids[] = (int) $row['id'];
                }
            }
            $wpdb->query('COMMIT');
        } catch (Exception $exception) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('family_area_assignment_failed', $exception->getMessage(), array('status' => 500));
        }

        foreach ($families as $family) {
            Olama_Transportation_Audit::record($area_id ? 'family_area_assigned' : 'family_area_cleared', 'family_stop', null, $before[$family['family_uid']] ?? null, array(
                'family_uid' => $family['family_uid'], 'oracle_family_id' => $family['oracle_family_id'],
                'major_area_id' => $area_id ?: null, 'assignment_source' => 'manual',
            ));
        }
        if (count($families) > 1) {
            Olama_Transportation_Audit::record('family_area_bulk_assigned', 'family_stops', null, null, array(
                'family_uids' => $uids, 'major_area_id' => $area_id ?: null, 'count' => count($uids),
            ));
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $stops . ' WHERE id IN (' . implode(',', array_fill(0, count($stop_ids), '%d')) . ') ORDER BY id',
            $stop_ids
        ), ARRAY_A);
        return array(
            'updated' => count($families), 'failed' => 0, 'family_stop_ids' => $stop_ids,
            'major_area_id' => $area_id ?: null, 'family_stops' => $rows,
            'effective_assignments' => $include_effective
                ? self::effective_for_stops($stop_ids, absint($academic_year_id))
                : array(),
        );
    }

    public static function assign($family_stop_id, $major_area_id)
    {
        return self::bulk_assign(array($family_stop_id), $major_area_id, false);
    }

    public static function bulk_assign($family_stop_ids, $major_area_id, $bulk_event = true)
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $family_stop_ids))));
        $area_id = absint($major_area_id);
        if (!$ids) {
            return new WP_Error('missing_family_stops', __('Select at least one family location.', 'olama-transportation'), array('status' => 400));
        }
        if ($area_id) {
            $area = $wpdb->get_row($wpdb->prepare(
                'SELECT id,name,status FROM ' . Olama_Transportation_DB::table('major_areas') . ' WHERE id=%d',
                $area_id
            ), ARRAY_A);
            if (!$area || $area['status'] !== 'active') {
                return new WP_Error('invalid_planning_area', __('The planning area does not exist or is inactive.', 'olama-transportation'), array('status' => 400));
            }
        }
        $table = Olama_Transportation_DB::table('family_stops');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,family_uid,oracle_family_id,major_area_id FROM {$table} WHERE id IN ({$placeholders})", $ids), ARRAY_A);
        if (count($rows) !== count($ids)) {
            return new WP_Error('family_stop_not_found', __('One or more family locations were not found. No changes were saved.', 'olama-transportation'), array('status' => 404));
        }

        $now = current_time('mysql', true);
        $user_id = get_current_user_id() ?: null;
        $wpdb->query('START TRANSACTION');
        try {
            if ($area_id) {
                $locked_area = $wpdb->get_row($wpdb->prepare(
                    'SELECT id,status FROM ' . Olama_Transportation_DB::table('major_areas') . ' WHERE id=%d FOR UPDATE',
                    $area_id
                ), ARRAY_A);
                if (!$locked_area || $locked_area['status'] !== 'active') {
                    throw new RuntimeException(__('The planning area became unavailable. No changes were saved.', 'olama-transportation'));
                }
            }
            $locked = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE id IN ({$placeholders}) FOR UPDATE", $ids), ARRAY_A);
            if (count($locked) !== count($ids)) {
                throw new RuntimeException(__('A family location changed while it was being assigned.', 'olama-transportation'));
            }
            foreach ($rows as $row) {
                $updated = $wpdb->update($table, array(
                    'major_area_id' => $area_id ?: null,
                    'area_assignment_source' => 'manual',
                    'area_assigned_by' => $user_id,
                    'area_assigned_at' => $now,
                    'updated_at' => $now,
                ), array('id' => (int) $row['id']));
                if ($updated === false) {
                    throw new RuntimeException($wpdb->last_error ?: __('Could not update a family planning area.', 'olama-transportation'));
                }
            }
            $wpdb->query('COMMIT');
        } catch (Exception $exception) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('family_area_assignment_failed', $exception->getMessage(), array('status' => 500));
        }

        foreach ($rows as $row) {
            Olama_Transportation_Audit::record($area_id ? 'family_area_assigned' : 'family_area_cleared', 'family_stop', $row['id'], array(
                'family_stop_id' => (int) $row['id'], 'family_uid' => $row['family_uid'], 'oracle_family_id' => $row['oracle_family_id'], 'major_area_id' => $row['major_area_id'] ? (int) $row['major_area_id'] : null,
            ), array('major_area_id' => $area_id ?: null, 'assignment_source' => 'manual'));
        }
        if ($bulk_event && count($rows) > 1) {
            Olama_Transportation_Audit::record('family_area_bulk_assigned', 'family_stops', null, null, array('family_stop_ids' => $ids, 'major_area_id' => $area_id ?: null, 'count' => count($ids)));
        }
        $result = array('updated' => count($rows), 'family_stop_ids' => $ids, 'major_area_id' => $area_id ?: null, 'effective_assignments' => array());
        if (count($ids) === 1 && class_exists('Olama_School_Academic')) {
            $active_year = Olama_School_Academic::get_active_year();
            if ($active_year) {
                foreach (array('morning', 'afternoon') as $direction) {
                    $effective = Olama_Transportation_Effective_Assignments::family((int) $active_year->id, $direction, $ids[0]);
                    $result['effective_assignments'][$direction] = is_wp_error($effective) ? null : $effective;
                }
            }
        }
        return $result;
    }

    private static function effective_for_stops($stop_ids, $academic_year_id)
    {
        if (!$academic_year_id) {
            return array();
        }
        $wanted = array_flip(array_map('absint', $stop_ids));
        $result = array();
        foreach (array('morning', 'afternoon') as $direction) {
            $resolved = Olama_Transportation_Effective_Assignments::resolve($academic_year_id, $direction);
            if (is_wp_error($resolved)) {
                continue;
            }
            foreach ($resolved['families'] as $family) {
                if ($family['family_stop_id'] && isset($wanted[(int) $family['family_stop_id']])) {
                    $result[$family['family_uid']][$direction] = $family;
                }
            }
        }
        return $result;
    }
}
