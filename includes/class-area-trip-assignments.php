<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Area_Trip_Assignments
{
    public static function list_assignments($academic_year_id, $direction, $args = array())
    {
        $resolved = Olama_Transportation_Effective_Assignments::resolve($academic_year_id, $direction, array('include_all_students' => true));
        if (is_wp_error($resolved)) return $resolved;
        $resolved['area_options'] = array_map(function ($area) { return array_intersect_key($area, array_flip(array('id', 'name', 'color'))); }, $resolved['areas']);
        $show_all = !empty($args['show_all']);
        $area_id = absint($args['major_area_id'] ?? 0);
        $bus_id = absint($args['bus_id'] ?? 0);
        $trip = absint($args['trip_number'] ?? 0);
        $status = sanitize_key($args['assignment_status'] ?? 'all');
        $readiness = sanitize_key($args['location_readiness'] ?? 'all');
        $areas = array_values(array_filter($resolved['areas'], function ($area) use ($show_all, $area_id, $bus_id, $trip, $status, $readiness) {
            $assignment = $area['assignment'];
            $operational = $area['family_count'] > 0 || $area['student_count'] > 0 || $assignment || in_array($area['assignment_status'], array('invalid_bus','invalid_bus_capacity','invalid_trip','over_capacity'), true);
            if (!$show_all && !$operational) return false;
            if ($area_id && (int) $area['id'] !== $area_id) return false;
            if ($bus_id && (!$assignment || (int) $assignment['bus_id'] !== $bus_id)) return false;
            if ($trip && (!$assignment || (int) $assignment['trip_number'] !== $trip)) return false;
            $capacity_problems = array('over_capacity','invalid_bus','invalid_bus_capacity','invalid_trip');
            $assigned_statuses = array('assigned','near_capacity','at_capacity','missing_locations','no_students');
            if ($status === 'assigned' && !in_array($area['assignment_status'], $assigned_statuses, true)) return false;
            if ($status === 'capacity_problem' && !in_array($area['assignment_status'], $capacity_problems, true)) return false;
            if ($status !== 'all' && $status !== 'capacity_problem' && $status !== 'assigned' && $area['assignment_status'] !== $status) return false;
            if (in_array($readiness, array('missing_locations','needs_attention'), true) && !$area['missing_location_family_count']) return false;
            if ($readiness === 'ready' && $area['missing_location_family_count']) return false;
            return true;
        }));
        $sort = sanitize_key($args['sort'] ?? 'priority');
        $order = strtolower((string) ($args['order'] ?? 'asc')) === 'desc' ? -1 : 1;
        usort($areas, function ($a, $b) use ($sort, $order) {
            $priority = array('over_capacity'=>1,'invalid_bus'=>1,'invalid_bus_capacity'=>1,'invalid_trip'=>1,'area_not_allocated'=>2,'missing_locations'=>3,'near_capacity'=>4,'at_capacity'=>4,'assigned'=>5,'no_students'=>6);
            $values = array(
                'name' => array($a['name'], $b['name']), 'families' => array($a['family_count'], $b['family_count']),
                'students' => array($a['student_count'], $b['student_count']), 'utilization' => array($a['utilization'] ?? -1, $b['utilization'] ?? -1),
                'remaining' => array($a['bus_trip_remaining_seats'] ?? PHP_INT_MAX, $b['bus_trip_remaining_seats'] ?? PHP_INT_MAX),
                'status' => array($a['assignment_status'], $b['assignment_status']),
                'updated' => array($a['assignment']['updated_at'] ?? '', $b['assignment']['updated_at'] ?? ''),
                'priority' => array($priority[$a['assignment_status']] ?? 7, $priority[$b['assignment_status']] ?? 7),
            );
            $pair = $values[$sort] ?? $values['priority'];
            if (is_string($pair[0])) return $order * strnatcasecmp($pair[0], $pair[1]);
            return $order * ($pair[0] <=> $pair[1]);
        });
        $per_page = min(100, max(20, absint($args['per_page'] ?? 20)));
        $page = max(1, absint($args['page'] ?? 1));
        $total = count($areas);
        $resolved['areas'] = array_slice($areas, ($page - 1) * $per_page, $per_page);
        $resolved['pagination'] = array('page'=>$page,'per_page'=>$per_page,'total'=>$total,'total_pages'=>max(1,(int) ceil($total/$per_page)));
        unset($resolved['families'], $resolved['trip_usage']);
        return $resolved;
    }

    public static function area_families($academic_year_id, $direction, $major_area_id, $args = array())
    {
        $resolved = Olama_Transportation_Effective_Assignments::resolve($academic_year_id, $direction);
        if (is_wp_error($resolved)) return $resolved;
        $area_id = absint($major_area_id);
        $search = trim(sanitize_text_field($args['search'] ?? ''));
        $location = sanitize_key($args['location_status'] ?? 'all');
        $allocation = sanitize_key($args['allocation_status'] ?? 'all');
        $families = array_values(array_filter($resolved['families'], function ($family) use ($area_id, $search, $location, $allocation) {
            if ((int) $family['major_area_id'] !== $area_id) return false;
            if ($search !== '' && stripos($family['family_name'] . ' ' . $family['oracle_family_id'], $search) === false) return false;
            if ($location === 'valid' && !in_array($family['location_status'], array('approved','needs_review'), true)) return false;
            if ($location === 'missing' && !in_array($family['location_status'], array('missing_location','invalid_location'), true)) return false;
            if (!in_array($location, array('all','valid','missing'), true) && $family['location_status'] !== $location) return false;
            if ($allocation === 'assigned' && $family['assignment_status'] !== 'assigned') return false;
            if (in_array($allocation, array('unallocated','problem'), true) && $family['assignment_status'] === 'assigned') return false;
            return true;
        }));
        $items = array_map(function ($family) {
            return array_intersect_key($family, array_flip(array(
                'family_uid','oracle_family_id','family_name','family_stop_id','major_area_id','major_area_name','student_count',
                'location_status','latitude','longitude','bus_id','bus_number','trip_number','assignment_status','area_assignment_source','demand_mode'
            )));
        }, $families);
        return array('items'=>$items,'total'=>count($items),'demand_mode'=>$resolved['demand_mode'],'warning'=>$resolved['warning']);
    }

    public static function get($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Olama_Transportation_DB::table('area_bus_assignments') . ' WHERE id=%d',
            absint($id)
        ), ARRAY_A);
    }

    public static function preview($data)
    {
        global $wpdb;
        $existing_id = absint($data['current_assignment_id'] ?? 0);
        if (!$existing_id) $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Olama_Transportation_DB::table('area_bus_assignments') . ' WHERE academic_year_id=%d AND direction=%s AND major_area_id=%d ORDER BY status=\'active\' DESC,id LIMIT 1',
            absint($data['academic_year_id'] ?? 0),
            sanitize_key($data['direction'] ?? ''),
            absint($data['major_area_id'] ?? 0)
        ));
        return self::calculate($data, $existing_id, false);
    }

    public static function save($data)
    {
        global $wpdb;
        $year = absint($data['academic_year_id'] ?? 0);
        $direction = sanitize_key($data['direction'] ?? '');
        $area_id = absint($data['major_area_id'] ?? 0);
        $bus_id = absint($data['bus_id'] ?? 0);
        $trip = absint($data['trip_number'] ?? 0);
        if (!$year || !$area_id || !$bus_id || !$trip || !in_array($direction, array('morning', 'afternoon'), true)) {
            return new WP_Error('invalid_area_trip_assignment', __('Academic year, direction, planning area, bus, and trip are required.', 'olama-transportation'), array('status' => 400));
        }

        $table = Olama_Transportation_DB::table('area_bus_assignments');
        $areas = Olama_Transportation_DB::table('major_areas');
        $buses = Olama_Transportation_DB::table('buses');
        $wpdb->query('START TRANSACTION');
        try {
            $area = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$areas} WHERE id=%d FOR UPDATE", $area_id), ARRAY_A);
            if (!$area || $area['status'] !== 'active') {
                return self::rollback_error('invalid_planning_area', __('The planning area does not exist or is inactive.', 'olama-transportation'), 400);
            }
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE academic_year_id=%d AND direction=%s AND major_area_id=%d ORDER BY status='active' DESC,id ASC LIMIT 1 FOR UPDATE",
                $year,
                $direction,
                $area_id
            ), ARRAY_A);
            $bus = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$buses} WHERE id=%d FOR UPDATE", $bus_id), ARRAY_A);
            if (!$bus) {
                return self::rollback_error('bus_not_found', __('The selected bus was not found.', 'olama-transportation'), 400);
            }
            $calculation = self::calculate(array(
                'academic_year_id' => $year, 'direction' => $direction, 'major_area_id' => $area_id,
                'bus_id' => $bus_id, 'trip_number' => $trip,
            ), $existing ? (int) $existing['id'] : 0, true, $bus);
            if (is_wp_error($calculation)) {
                $wpdb->query('ROLLBACK');
                if ($calculation->get_error_code() === 'capacity_exceeded') {
                    Olama_Transportation_Audit::record('area_assignment_capacity_rejected', 'area_bus_assignment', $existing['id'] ?? null, null, array(
                        'academic_year_id' => $year, 'direction' => $direction, 'major_area_id' => $area_id,
                        'bus_id' => $bus_id, 'trip_number' => $trip, 'calculation' => $calculation->get_error_data(),
                    ));
                }
                return $calculation;
            }
            $preview_hash = sanitize_text_field((string) ($data['preview_hash'] ?? ''));
            if ($preview_hash !== '' && !hash_equals($calculation['preview_hash'], $preview_hash)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('capacity_changed', __('The bus-trip capacity changed after the preview. Review the updated capacity and try again.', 'olama-transportation'), array('status' => 409, 'capacity' => $calculation));
            }
            $now = current_time('mysql', true);
            $record = array(
                'academic_year_id' => $year,
                'direction' => $direction,
                'major_area_id' => $area_id,
                'bus_id' => $bus_id,
                'trip_number' => $trip,
                'notes' => sanitize_textarea_field($data['notes'] ?? ''),
                'status' => 'active',
                'updated_by' => get_current_user_id() ?: null,
                'updated_at' => $now,
            );
            if ($existing) {
                $result = $wpdb->update($table, $record, array('id' => (int) $existing['id']));
                $id = (int) $existing['id'];
            } else {
                $record['created_by'] = get_current_user_id() ?: null;
                $record['created_at'] = $now;
                $result = $wpdb->insert($table, $record);
                $id = (int) $wpdb->insert_id;
            }
            if ($result === false) {
                throw new RuntimeException($wpdb->last_error ?: __('Could not save the area bus-trip assignment.', 'olama-transportation'));
            }
            // A pre-2.4 installation with historical duplicates may not yet have
            // the unique index. Keep history but ensure only this row is active.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='inactive',updated_at=%s WHERE academic_year_id=%d AND direction=%s AND major_area_id=%d AND id<>%d AND status='active'",
                $now,
                $year,
                $direction,
                $area_id,
                $id
            ));
            $wpdb->query('COMMIT');
        } catch (Exception $exception) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('area_trip_persistence_failed', $exception->getMessage(), array('status' => 500));
        }

        $after = self::get($id);
        Olama_Transportation_Audit::record($existing ? 'area_trip_updated' : 'area_trip_assigned', 'area_bus_assignment', $id, $existing, array_merge($after, array('calculation' => $calculation)));
        $resolved = self::list_assignments($year, $direction);
        return array('assignment' => $after, 'capacity' => $calculation, 'allocations' => $resolved);
    }

    public static function unassign($id)
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('area_bus_assignments');
        $wpdb->query('START TRANSACTION');
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE", absint($id)), ARRAY_A);
        if (!$before) {
            return self::rollback_error('area_trip_not_found', __('The area bus-trip assignment was not found.', 'olama-transportation'), 404);
        }
        $updated = $wpdb->update($table, array(
            'status' => 'inactive', 'updated_by' => get_current_user_id() ?: null, 'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $before['id']));
        if ($updated === false) {
            return self::rollback_error('area_trip_persistence_failed', $wpdb->last_error ?: __('Could not remove the assignment.', 'olama-transportation'), 500);
        }
        $wpdb->query('COMMIT');
        Olama_Transportation_Audit::record('area_trip_unassigned', 'area_bus_assignment', $before['id'], $before, array('status' => 'inactive'));
        return array('id' => (int) $before['id'], 'status' => 'inactive');
    }

    private static function calculate($data, $exclude_id = 0, $for_update = false, $locked_bus = null)
    {
        global $wpdb;
        $year = absint($data['academic_year_id'] ?? 0);
        $direction = sanitize_key($data['direction'] ?? '');
        $area_id = absint($data['major_area_id'] ?? 0);
        $bus_id = absint($data['bus_id'] ?? 0);
        $trip = absint($data['trip_number'] ?? 0);
        if (!$year || !$area_id || !$bus_id || !$trip || !in_array($direction, array('morning', 'afternoon'), true)) {
            return new WP_Error('invalid_capacity_preview', __('Complete the assignment fields to calculate capacity.', 'olama-transportation'), array('status' => 400));
        }
        $bus = $locked_bus ?: $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Olama_Transportation_DB::table('buses') . ' WHERE id=%d', $bus_id), ARRAY_A);
        if (!$bus || $bus['status'] !== 'active') {
            return new WP_Error('bus_unavailable', __('Only an active bus can be assigned.', 'olama-transportation'), array('status' => 400));
        }
        $capacity = (int) ($bus['planning_capacity'] > 0 ? $bus['planning_capacity'] : $bus['passenger_capacity']);
        if ($capacity < 1) {
            return new WP_Error('bus_has_no_capacity', __('The selected bus has no effective planning capacity.', 'olama-transportation'), array('status' => 400));
        }
        $trip_count = (int) $bus[$direction . '_trip_count'];
        if ($trip < 1 || $trip > $trip_count) {
            return new WP_Error('invalid_trip_number', sprintf(__('Trip number must be between 1 and %d for this bus and direction.', 'olama-transportation'), $trip_count), array('status' => 400));
        }

        $resolved = Olama_Transportation_Effective_Assignments::resolve($year, $direction);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $demand_by_area = array();
        foreach ($resolved['areas'] as $area) {
            $demand_by_area[(int) $area['id']] = (int) $area['student_count'];
        }
        if (!isset($demand_by_area[$area_id])) {
            return new WP_Error('invalid_planning_area', __('The planning area does not exist or is inactive.', 'olama-transportation'), array('status' => 400));
        }
        $assignment_table = Olama_Transportation_DB::table('area_bus_assignments');
        $sql = "SELECT id,major_area_id FROM {$assignment_table} WHERE academic_year_id=%d AND direction=%s AND bus_id=%d AND trip_number=%d AND status='active'";
        if ($exclude_id) {
            $sql .= ' AND id<>%d';
            $params = array($year, $direction, $bus_id, $trip, $exclude_id);
        } else {
            $params = array($year, $direction, $bus_id, $trip);
        }
        if ($for_update) {
            $sql .= ' FOR UPDATE';
        }
        $other_assignments = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $current = 0;
        foreach ($other_assignments as $assignment) {
            $current += (int) ($demand_by_area[(int) $assignment['major_area_id']] ?? 0);
        }
        $area_demand = $demand_by_area[$area_id];
        $resulting = $current + $area_demand;
        $summary = array(
            'area_family_count' => 0,
            'area_students' => $area_demand,
            'current_bus_trip_students' => $current,
            'current_assignment_students' => $exclude_id ? $area_demand : 0,
            'resulting_used_seats' => $resulting,
            'effective_capacity' => $capacity,
            'remaining_seats' => $capacity - $resulting,
            'utilization' => round(($resulting / $capacity) * 100, 1),
            'demand_mode' => $resolved['demand_mode'],
            'warning' => $resolved['warning'],
        );
        foreach ($resolved['areas'] as $area) {
            if ((int) $area['id'] === $area_id) { $summary['area_family_count'] = (int) $area['family_count']; break; }
        }
        $summary['capacity_status'] = $area_demand < 1 ? 'no_student_demand'
            : ($resulting > $capacity ? 'over_capacity' : ($resulting === $capacity ? 'at_capacity' : (($resulting / $capacity) >= .85 ? 'near_capacity' : 'within_capacity')));
        $summary['valid'] = $resulting <= $capacity;
        $summary['preview_hash'] = hash('sha256', implode('|', array($year,$direction,$area_id,$bus_id,$trip,$area_demand,$current,$resulting,$capacity)));
        if ($resulting > $capacity) {
            return new WP_Error('capacity_exceeded', __('This assignment exceeds the bus-trip capacity. Create or use a smaller planning area and manually move the relevant families before assigning it.', 'olama-transportation'), array_merge(array('status' => 409), $summary));
        }
        return $summary;
    }

    private static function rollback_error($code, $message, $status)
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        return new WP_Error($code, $message, array('status' => $status));
    }
}
