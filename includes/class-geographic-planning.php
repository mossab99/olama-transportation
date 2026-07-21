<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Geographic_Planning
{
    public static function list_groups($args = array())
    {
        global $wpdb;
        $groups = Olama_Transportation_DB::table('planning_groups');
        $areas = Olama_Transportation_DB::table('major_areas');
        $buses = Olama_Transportation_DB::table('buses');
        $where = array('1=1');
        $values = array();
        if (!empty($args['academic_year_id'])) {
            $where[] = 'g.academic_year_id=%d';
            $values[] = absint($args['academic_year_id']);
        }
        if (!empty($args['direction'])) {
            $where[] = 'g.direction=%s';
            $values[] = sanitize_key($args['direction']);
        }
        if (empty($args['include_archived'])) {
            $where[] = "g.status<>'archived'";
        }
        $sql = "SELECT g.*,a.name major_area_name,b.bus_number,b.government_number
                FROM {$groups} g LEFT JOIN {$areas} a ON a.id=g.major_area_id LEFT JOIN {$buses} b ON b.id=g.bus_id
                WHERE " . implode(' AND ', $where) . ' ORDER BY g.updated_at DESC,g.id DESC';
        $rows = $values ? $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows as &$row) {
            $row = self::format_group($row, false);
        }
        unset($row);
        return $rows;
    }

    public static function get($id)
    {
        global $wpdb;
        $groups = Olama_Transportation_DB::table('planning_groups');
        $areas = Olama_Transportation_DB::table('major_areas');
        $buses = Olama_Transportation_DB::table('buses');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT g.*,a.name major_area_name,b.bus_number,b.government_number
             FROM {$groups} g LEFT JOIN {$areas} a ON a.id=g.major_area_id LEFT JOIN {$buses} b ON b.id=g.bus_id WHERE g.id=%d",
            absint($id)
        ), ARRAY_A);
        return $row ? self::format_group($row, true) : null;
    }

    public static function save($data, $id = 0)
    {
        global $wpdb;
        $groups = Olama_Transportation_DB::table('planning_groups');
        $members = Olama_Transportation_DB::table('planning_group_families');
        $id = absint($id);
        $wpdb->query('START TRANSACTION');
        try {
            $before = null;
            if ($id) {
                $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$groups} WHERE id=%d FOR UPDATE", $id), ARRAY_A);
                if (!$before) {
                    return self::rollback_error('planning_group_not_found', __('Planning group was not found.', 'olama-transportation'), 404);
                }
                if ($before['status'] !== 'draft') {
                    return self::rollback_error('approved_group_read_only', __('Only draft groups can be edited. Revert the approved group first.', 'olama-transportation'), 409);
                }
            }
            $validated = self::validate_payload($data, $id);
            if (is_wp_error($validated)) {
                $wpdb->query('ROLLBACK');
                return $validated;
            }
            $now = current_time('mysql', true);
            $group_data = array(
                'academic_year_id' => $validated['academic_year_id'], 'direction' => $validated['direction'],
                'trip_number' => $validated['trip_number'], 'group_name' => $validated['group_name'], 'bus_id' => $validated['bus_id'],
                'major_area_id' => $validated['major_area_id'] ?: null, 'color' => $validated['color'],
                'polygon_geojson' => $validated['polygon_geojson'], 'notes' => $validated['notes'], 'status' => 'draft',
                'family_count' => count($validated['families']), 'student_count' => $validated['student_count'],
                'capacity_snapshot' => $validated['capacity'], 'updated_by' => get_current_user_id() ?: null, 'updated_at' => $now,
            );
            if ($id) {
                if ($wpdb->update($groups, $group_data, array('id' => $id)) === false) {
                    throw new RuntimeException($wpdb->last_error ?: 'Could not update planning group.');
                }
                if ($wpdb->delete($members, array('group_id' => $id)) === false) {
                    throw new RuntimeException($wpdb->last_error ?: 'Could not replace group membership.');
                }
            } else {
                $group_data['created_by'] = get_current_user_id() ?: null;
                $group_data['created_at'] = $now;
                if (!$wpdb->insert($groups, $group_data)) {
                    throw new RuntimeException($wpdb->last_error ?: 'Could not create planning group.');
                }
                $id = (int) $wpdb->insert_id;
            }
            foreach ($validated['families'] as $family) {
                $ok = $wpdb->insert($members, array(
                    'group_id' => $id, 'family_uid' => $family['family_uid'], 'oracle_family_id' => $family['oracle_family_id'],
                    'family_stop_id' => $family['family_stop_id'], 'student_count_snapshot' => $family['student_count'],
                    'latitude_snapshot' => $family['latitude'], 'longitude_snapshot' => $family['longitude'],
                    'major_area_id_snapshot' => $family['major_area_id'] ?: null, 'region_name_snapshot' => $family['trans_region_name'],
                    'created_at' => $now,
                ));
                if (!$ok) {
                    throw new RuntimeException($wpdb->last_error ?: 'Could not save planning group family.');
                }
            }
            $wpdb->query('COMMIT');
        } catch (Exception $exception) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('planning_group_persistence_failed', $exception->getMessage(), array('status' => 500));
        }
        $after = self::get($id);
        Olama_Transportation_Audit::record($before ? 'planning_group_updated' : 'planning_group_created', 'planning_group', $id, $before, self::audit_snapshot($after));
        Olama_Transportation_Audit::record('planning_group_membership_changed', 'planning_group', $id, null, array('family_count' => $after['family_count'], 'student_count' => $after['student_count']));
        return $after;
    }

    public static function approve($id)
    {
        global $wpdb;
        $groups = Olama_Transportation_DB::table('planning_groups');
        $wpdb->query('START TRANSACTION');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$groups} WHERE id=%d FOR UPDATE", absint($id)), ARRAY_A);
        if (!$row) {
            return self::rollback_error('planning_group_not_found', __('Planning group was not found.', 'olama-transportation'), 404);
        }
        if ($row['status'] !== 'draft') {
            return self::rollback_error('invalid_group_status', __('Only draft groups can be approved.', 'olama-transportation'), 409);
        }
        $payload = $row;
        $payload['family_uids'] = self::family_uids($id);
        $polygon = json_decode((string) $row['polygon_geojson'], true);
        $payload['polygon_geojson'] = $polygon ?: null;
        $validated = self::validate_payload($payload, absint($id));
        if (is_wp_error($validated)) {
            $wpdb->query('ROLLBACK');
            return $validated;
        }
        $now = current_time('mysql', true);
        $updated = $wpdb->update($groups, array(
            'status' => 'approved', 'family_count' => count($validated['families']), 'student_count' => $validated['student_count'],
            'capacity_snapshot' => $validated['capacity'], 'approved_by' => get_current_user_id() ?: null,
            'approved_at' => $now, 'updated_by' => get_current_user_id() ?: null, 'updated_at' => $now,
        ), array('id' => absint($id)));
        if ($updated === false) {
            return self::rollback_error('planning_group_persistence_failed', $wpdb->last_error ?: __('Could not approve planning group.', 'olama-transportation'), 500);
        }
        $wpdb->query('COMMIT');
        $result = self::get($id);
        Olama_Transportation_Audit::record('planning_group_approved', 'planning_group', $id, self::audit_snapshot($row), self::audit_snapshot($result));
        return $result;
    }

    public static function revert($id)
    {
        return self::change_status($id, 'approved', 'draft', 'planning_group_reverted');
    }

    public static function archive($id)
    {
        global $wpdb;
        $groups = Olama_Transportation_DB::table('planning_groups');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$groups} WHERE id=%d", absint($id)), ARRAY_A);
        if (!$row) {
            return new WP_Error('planning_group_not_found', __('Planning group was not found.', 'olama-transportation'), array('status' => 404));
        }
        if ($row['status'] === 'archived') {
            return self::get($id);
        }
        return self::change_status($id, array('draft', 'approved'), 'archived', 'planning_group_archived');
    }

    public static function trip_slots($academic_year_id, $direction, $bus_id = 0)
    {
        global $wpdb;
        $groups = Olama_Transportation_DB::table('planning_groups');
        $buses = Olama_Transportation_DB::table('buses');
        $where = "status='active'";
        $values = array();
        if ($bus_id) {
            $where .= ' AND id=%d';
            $values[] = absint($bus_id);
        }
        $sql = "SELECT * FROM {$buses} WHERE {$where} ORDER BY bus_number";
        $bus_rows = $values ? $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $result = array();
        foreach ($bus_rows as $bus) {
            $capacity = (int) ($bus['planning_capacity'] > 0 ? $bus['planning_capacity'] : $bus['passenger_capacity']);
            $count = $direction === 'afternoon' ? (int) $bus['afternoon_trip_count'] : (int) $bus['morning_trip_count'];
            $slots = array();
            for ($trip = 1; $trip <= $count; $trip++) {
                $occupied = $wpdb->get_row($wpdb->prepare(
                    "SELECT id,group_name,status FROM {$groups} WHERE academic_year_id=%d AND direction=%s AND bus_id=%d AND trip_number=%d AND status IN ('draft','approved') LIMIT 1",
                    absint($academic_year_id), $direction, $bus['id'], $trip
                ), ARRAY_A);
                $slots[] = array('trip_number' => $trip, 'status' => $occupied ? $occupied['status'] : 'available', 'group' => $occupied ?: null);
            }
            $result[] = array('bus_id' => (int) $bus['id'], 'bus_number' => $bus['bus_number'], 'effective_capacity' => $capacity, 'assignable' => $capacity > 0, 'slots' => $slots);
        }
        return $result;
    }

    private static function validate_payload($data, $editing_id)
    {
        global $wpdb;
        $year = absint($data['academic_year_id'] ?? 0);
        $direction = sanitize_key($data['direction'] ?? '');
        $bus_id = absint($data['bus_id'] ?? 0);
        $trip = absint($data['trip_number'] ?? 0);
        $name = sanitize_text_field($data['group_name'] ?? '');
        $uids = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) ($data['family_uids'] ?? array())))));
        if (!$year || !in_array($direction, array('morning', 'afternoon'), true) || !$bus_id || !$trip || $name === '' || !$uids) {
            return new WP_Error('invalid_planning_group', __('Academic year, direction, group name, bus, trip, and at least one family are required.', 'olama-transportation'), array('status' => 400));
        }
        $bus = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Olama_Transportation_DB::table('buses') . ' WHERE id=%d FOR UPDATE', $bus_id), ARRAY_A);
        $capacity = $bus ? (int) ($bus['planning_capacity'] > 0 ? $bus['planning_capacity'] : $bus['passenger_capacity']) : 0;
        $max_trips = $bus && $direction === 'morning' ? (int) $bus['morning_trip_count'] : ($bus ? (int) $bus['afternoon_trip_count'] : 0);
        if (!$bus || $bus['status'] !== 'active' || $capacity < 1) {
            return new WP_Error('invalid_planning_bus', __('The selected bus is inactive or has no effective capacity.', 'olama-transportation'), array('status' => 400));
        }
        if ($trip < 1 || $trip > $max_trips) {
            return new WP_Error('invalid_trip_number', __('The selected trip number is outside this bus direction’s configured range.', 'olama-transportation'), array('status' => 400));
        }
        $groups = Olama_Transportation_DB::table('planning_groups');
        $conflict = $wpdb->get_row($wpdb->prepare(
            "SELECT id,group_name,status FROM {$groups} WHERE academic_year_id=%d AND direction=%s AND bus_id=%d AND trip_number=%d
             AND status IN ('draft','approved') AND id<>%d FOR UPDATE",
            $year, $direction, $bus_id, $trip, $editing_id
        ), ARRAY_A);
        if ($conflict) {
            return new WP_Error('bus_trip_conflict', __('This bus trip is already occupied by another active planning group.', 'olama-transportation'), array('status' => 409, 'conflicting_group' => $conflict));
        }
        $members = Olama_Transportation_DB::table('planning_group_families');
        $placeholders = implode(',', array_fill(0, count($uids), '%s'));
        $conflict_sql = $wpdb->prepare(
            "SELECT m.family_uid,g.id group_id,g.group_name FROM {$members} m INNER JOIN {$groups} g ON g.id=m.group_id
             WHERE m.family_uid IN ({$placeholders}) AND g.academic_year_id=%d AND g.direction=%s
             AND g.status IN ('draft','approved') AND g.id<>%d FOR UPDATE",
            array_merge($uids, array($year, $direction, $editing_id))
        );
        $family_conflicts = $wpdb->get_results($conflict_sql, ARRAY_A);
        if ($family_conflicts) {
            return new WP_Error('family_assignment_conflict', __('One or more families already belong to another active group for this direction.', 'olama-transportation'), array('status' => 409, 'conflicting_families' => $family_conflicts));
        }
        $study_year = Olama_Transportation_Bus::study_year($year);
        $enrollments = Olama_Transportation_DB::table('enrollments');
        $mode = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$enrollments} WHERE academic_year_id=%d AND status='active'", $year))
            ? 'transport_enrollments' : 'academic_registration_fallback';
        $rows = Olama_Transportation_Map_Data::demand_rows($year, $study_year, $direction, $mode);
        $available = array();
        foreach ($rows as $row) {
            if ((int) $row['student_count'] > 0 && $row['family_stop_id'] && in_array($row['verification_status'], array('needs_review', 'approved'), true)
                && Olama_Transportation_Family_Locations::within_service_bounds((float) $row['latitude'], (float) $row['longitude'])) {
                $available[$row['family_uid']] = $row;
            }
        }
        $selected = array();
        foreach ($uids as $uid) {
            if (!isset($available[$uid])) {
                return new WP_Error('invalid_planning_family', __('A selected family has no valid selectable location or direction-specific demand.', 'olama-transportation'), array('status' => 400, 'family_uid' => $uid));
            }
            $selected[] = $available[$uid];
        }
        $student_count = array_sum(array_map(function ($family) { return (int) $family['student_count']; }, $selected));
        if ($student_count > $capacity) {
            return new WP_Error('capacity_exceeded', sprintf(__('Capacity exceeded by %d students.', 'olama-transportation'), $student_count - $capacity), array('status' => 400, 'student_count' => $student_count, 'capacity' => $capacity));
        }
        $polygon = self::validate_polygon($data['polygon_geojson'] ?? null);
        if (is_wp_error($polygon)) {
            return $polygon;
        }
        return array(
            'academic_year_id' => $year, 'direction' => $direction, 'bus_id' => $bus_id, 'trip_number' => $trip,
            'group_name' => $name, 'major_area_id' => absint($data['major_area_id'] ?? 0),
            'color' => self::sanitize_color($data['color'] ?? '#2563eb'), 'polygon_geojson' => $polygon,
            'notes' => sanitize_textarea_field($data['notes'] ?? ''), 'families' => $selected,
            'student_count' => $student_count, 'capacity' => $capacity,
        );
    }

    private static function validate_polygon($value)
    {
        if ($value === null || $value === '' || $value === array()) {
            return null;
        }
        $geometry = is_string($value) ? json_decode(wp_unslash($value), true) : $value;
        if (isset($geometry['type']) && $geometry['type'] === 'Feature') {
            $geometry = $geometry['geometry'] ?? null;
        }
        if (!is_array($geometry) || ($geometry['type'] ?? '') !== 'Polygon' || empty($geometry['coordinates']) || !is_array($geometry['coordinates'])) {
            return new WP_Error('invalid_polygon_geojson', __('Polygon GeoJSON must contain a Polygon geometry.', 'olama-transportation'), array('status' => 400));
        }
        foreach ($geometry['coordinates'] as $ring) {
            if (!is_array($ring) || count($ring) < 4) {
                return new WP_Error('invalid_polygon_geojson', __('Every polygon ring must contain at least four positions.', 'olama-transportation'), array('status' => 400));
            }
            $first = $ring[0];
            $last = $ring[count($ring) - 1];
            if (!is_array($first) || !is_array($last) || (float) ($first[0] ?? 999) !== (float) ($last[0] ?? -999) || (float) ($first[1] ?? 999) !== (float) ($last[1] ?? -999)) {
                return new WP_Error('invalid_polygon_geojson', __('Every polygon ring must be closed.', 'olama-transportation'), array('status' => 400));
            }
            foreach ($ring as $position) {
                if (!is_array($position) || count($position) < 2 || !is_numeric($position[0]) || !is_numeric($position[1])
                    || $position[0] < -180 || $position[0] > 180 || $position[1] < -90 || $position[1] > 90) {
                    return new WP_Error('invalid_polygon_geojson', __('Polygon GeoJSON contains an invalid coordinate.', 'olama-transportation'), array('status' => 400));
                }
            }
        }
        return wp_json_encode(array('type' => 'Polygon', 'coordinates' => $geometry['coordinates']));
    }

    private static function format_group($row, $with_families)
    {
        $row['id'] = (int) $row['id'];
        foreach (array('academic_year_id','trip_number','bus_id','major_area_id','family_count','student_count','capacity_snapshot') as $key) {
            $row[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
        }
        $row['remaining_seats'] = max(0, $row['capacity_snapshot'] - $row['student_count']);
        $row['polygon_geojson'] = $row['polygon_geojson'] ? json_decode($row['polygon_geojson'], true) : null;
        if ($with_families) {
            global $wpdb;
            $members = Olama_Transportation_DB::table('planning_group_families');
            $families = $wpdb->prefix . 'olama_core_families';
            $row['families'] = $wpdb->get_results($wpdb->prepare(
                "SELECT m.*,COALESCE(NULLIF(f.sponsor_full_name,''),NULLIF(f.father_name,''),m.oracle_family_id) family_name
                 FROM {$members} m LEFT JOIN {$families} f ON f.family_uid=m.family_uid WHERE m.group_id=%d ORDER BY family_name",
                $row['id']
            ), ARRAY_A);
        }
        return $row;
    }

    private static function family_uids($id)
    {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare('SELECT family_uid FROM ' . Olama_Transportation_DB::table('planning_group_families') . ' WHERE group_id=%d', absint($id)));
    }

    private static function change_status($id, $from, $to, $event)
    {
        global $wpdb;
        $groups = Olama_Transportation_DB::table('planning_groups');
        $wpdb->query('START TRANSACTION');
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$groups} WHERE id=%d FOR UPDATE", absint($id)), ARRAY_A);
        if (!$before) {
            return self::rollback_error('planning_group_not_found', __('Planning group was not found.', 'olama-transportation'), 404);
        }
        if (!in_array($before['status'], (array) $from, true)) {
            return self::rollback_error('invalid_group_status', __('The planning group is not in a valid status for this action.', 'olama-transportation'), 409);
        }
        $data = array('status' => $to, 'updated_by' => get_current_user_id() ?: null, 'updated_at' => current_time('mysql', true));
        if ($to === 'draft') {
            $data['approved_by'] = null;
            $data['approved_at'] = null;
        }
        if ($wpdb->update($groups, $data, array('id' => absint($id))) === false) {
            return self::rollback_error('planning_group_persistence_failed', $wpdb->last_error ?: __('Could not update planning group.', 'olama-transportation'), 500);
        }
        $wpdb->query('COMMIT');
        $after = self::get($id);
        Olama_Transportation_Audit::record($event, 'planning_group', $id, self::audit_snapshot($before), self::audit_snapshot($after));
        return $after;
    }

    private static function rollback_error($code, $message, $status)
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        return new WP_Error($code, $message, array('status' => $status));
    }

    private static function sanitize_color($color)
    {
        $color = sanitize_hex_color($color);
        return $color ?: '#2563eb';
    }

    private static function audit_snapshot($group)
    {
        if (!$group) {
            return null;
        }
        return array_intersect_key((array) $group, array_flip(array('id','academic_year_id','direction','trip_number','group_name','bus_id','major_area_id','status','family_count','student_count','capacity_snapshot')));
    }
}
