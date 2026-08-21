<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Central resolver for the family stop -> planning area -> bus trip model. */
class Olama_Transportation_Effective_Assignments
{
    public static function resolve($academic_year_id, $direction, $options = array())
    {
        global $wpdb;
        $year = absint($academic_year_id);
        $direction = sanitize_key($direction);
        if (!$year || !in_array($direction, array('morning', 'afternoon'), true)) {
            return new WP_Error('invalid_assignment_context', __('A valid academic year and direction are required.', 'olama-transportation'), array('status' => 400));
        }
        $study_year = Olama_Transportation_Bus::study_year($year);
        if ($study_year === '') {
            return new WP_Error('invalid_academic_year', __('Academic year was not found.', 'olama-transportation'), array('status' => 400));
        }

        $enrollments = Olama_Transportation_DB::table('enrollments');
        $has_enrollments = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$enrollments} WHERE academic_year_id=%d AND status='active'",
            $year
        )) > 0;
        $mode = $has_enrollments ? 'transport_enrollments' : 'academic_registration_fallback';
        $include_all_students = !empty($options['include_all_students']);
        $demand_rows = Olama_Transportation_Map_Data::demand_rows($year, $study_year, $direction, $include_all_students ? 'academic_registration_fallback' : $mode);
        // Keep the academic demand available for planning, but separately track
        // the students who actually have an active Core transportation record.
        $transport_counts = self::transportation_counts($study_year, $demand_rows, $include_all_students ? 'academic_registration_fallback' : $mode);
        $transport_kg_g1_counts = self::transportation_kg_g1_counts($study_year, $direction, $demand_rows, $include_all_students ? 'academic_registration_fallback' : $mode);

        $areas_table = Olama_Transportation_DB::table('major_areas');
        $allocation_table = Olama_Transportation_DB::table('area_bus_assignments');
        $buses_table = Olama_Transportation_DB::table('buses');
        $areas = $wpdb->get_results(
            "SELECT id,name,code,color,area_type,boundary_geojson,status FROM {$areas_table} WHERE status='active' ORDER BY name",
            ARRAY_A
        );
        $area_index = array();
        foreach ($areas as &$area) {
            $area['id'] = (int) $area['id'];
            $area['family_count'] = 0;
            $area['student_count'] = 0;
            $area['transportation_student_count'] = 0;
            $area['transport_kg_g1_count'] = 0;
            $area['non_transportation_student_count'] = 0;
            $area['missing_location_family_count'] = 0;
            $area_index[$area['id']] = &$area;
        }
        unset($area);

        $allocations = $wpdb->get_results($wpdb->prepare(
            "SELECT x.*,b.bus_number,b.passenger_capacity,b.planning_capacity,b.morning_trip_count,b.afternoon_trip_count,b.status bus_status
             FROM {$allocation_table} x INNER JOIN {$buses_table} b ON b.id=x.bus_id
             WHERE x.academic_year_id=%d AND x.direction=%s AND x.status='active'",
            $year,
            $direction
        ), ARRAY_A);
        $allocation_by_area = array();
        foreach ($allocations as &$allocation) {
            $allocation['id'] = (int) $allocation['id'];
            $allocation['major_area_id'] = (int) $allocation['major_area_id'];
            $allocation['bus_id'] = (int) $allocation['bus_id'];
            $allocation['trip_number'] = (int) $allocation['trip_number'];
            $allocation['effective_capacity'] = (int) ($allocation['planning_capacity'] > 0 ? $allocation['planning_capacity'] : $allocation['passenger_capacity']);
            $allocation['valid_bus_trip'] = $allocation['bus_status'] === 'active' && $allocation['effective_capacity'] > 0
                && $allocation['trip_number'] >= 1 && $allocation['trip_number'] <= (int) $allocation[$direction . '_trip_count'];
            $allocation_by_area[$allocation['major_area_id']] = $allocation;
        }
        unset($allocation);

        $families = array();
        $trip_usage = array();
        foreach ($demand_rows as $row) {
            $count = (int) $row['student_count'];
            if ($count < 1) {
                continue;
            }
            $area_id = absint($row['major_area_id'] ?? 0);
            $stop_id = absint($row['family_stop_id'] ?? 0);
            $has_coordinates = $stop_id && array_key_exists('latitude', $row) && $row['latitude'] !== null
                && array_key_exists('longitude', $row) && $row['longitude'] !== null;
            $lat = $has_coordinates ? (float) $row['latitude'] : null;
            $lng = $has_coordinates ? (float) $row['longitude'] : null;
            $stored_location_status = sanitize_key((string) ($row['verification_status'] ?? ''));
            $valid_location = $has_coordinates && in_array($stored_location_status, array('needs_review', 'approved'), true)
                && Olama_Transportation_Family_Locations::within_service_bounds($lat, $lng);
            $location_status = !$has_coordinates ? 'missing_location' : ($valid_location ? $stored_location_status : 'invalid_location');
            $valid_area = $area_id && isset($area_index[$area_id]);
            $assignment = $valid_area && isset($allocation_by_area[$area_id]) ? $allocation_by_area[$area_id] : null;
            if ($area_id && isset($area_index[$area_id])) {
                $area_index[$area_id]['family_count']++;
                $area_index[$area_id]['student_count'] += $count;
                $transport_count = (int) ($transport_counts[(string) $row['family_uid']] ?? ($mode === 'transport_enrollments' ? $count : 0));
                $area_index[$area_id]['transportation_student_count'] += min($count, max(0, $transport_count));
                $area_index[$area_id]['transport_kg_g1_count'] += min($count, max(0, (int) ($transport_kg_g1_counts[(string) $row['family_uid']] ?? 0)));
                if (!$valid_location) {
                    $area_index[$area_id]['missing_location_family_count']++;
                }
            }
            if ($assignment) {
                $key = $assignment['bus_id'] . ':' . $assignment['trip_number'];
                $trip_usage[$key] = ($trip_usage[$key] ?? 0) + $count;
            }
            $status = !$valid_area ? 'missing_area'
                : (!$assignment ? 'area_not_allocated' : (!$assignment['valid_bus_trip'] ? 'capacity_problem' : 'assigned'));
            $families[] = array(
                'academic_year_id' => $year,
                'direction' => $direction,
                'family_uid' => (string) $row['family_uid'],
                'oracle_family_id' => (string) $row['oracle_family_id'],
                'family_name' => (string) $row['family_name'],
                'family_stop_id' => $stop_id ?: null,
                'major_area_id' => $area_id ?: null,
                'major_area_name' => $area_id && isset($area_index[$area_id]) ? $area_index[$area_id]['name'] : '',
                'oracle_region_id' => (string) ($row['trans_region_id'] ?? ''),
                'oracle_region_name' => (string) ($row['trans_region_name'] ?? ''),
                'latitude' => $valid_location ? $lat : null,
                'longitude' => $valid_location ? $lng : null,
                'location_status' => $location_status,
                'area_assignment_source' => sanitize_key((string) ($row['area_assignment_source'] ?? 'core')),
                'bus_id' => $assignment ? $assignment['bus_id'] : null,
                'bus_number' => $assignment ? (string) $assignment['bus_number'] : '',
                'trip_number' => $assignment ? $assignment['trip_number'] : null,
                'student_count' => $count,
                'transportation_student_count' => min($count, max(0, (int) ($transport_counts[(string) $row['family_uid']] ?? ($mode === 'transport_enrollments' ? $count : 0)))),
                'effective_capacity' => $assignment ? $assignment['effective_capacity'] : 0,
                'demand_mode' => $mode,
                'assignment_status' => $status,
            );
        }

        $allocated_students = 0;
        $problem_count = 0;
        foreach ($areas as &$area) {
            $allocation = $allocation_by_area[$area['id']] ?? null;
            $used = 0;
            $capacity = 0;
            if ($allocation) {
                $used = (int) ($trip_usage[$allocation['bus_id'] . ':' . $allocation['trip_number']] ?? 0);
                $capacity = $allocation['effective_capacity'];
                $allocated_students += (int) $area['student_count'];
            }
            $remaining = $allocation ? $capacity - $used : null;
            $utilization = $allocation && $capacity > 0 ? round(($used / $capacity) * 100, 1) : null;
            if (!$allocation) {
                $status = $area['student_count'] ? 'area_not_allocated' : 'no_students';
            } elseif ($allocation['bus_status'] !== 'active') {
                $status = 'invalid_bus';
            } elseif ($capacity < 1) {
                $status = 'invalid_bus_capacity';
            } elseif ($allocation['trip_number'] < 1 || $allocation['trip_number'] > (int) $allocation[$direction . '_trip_count']) {
                $status = 'invalid_trip';
            } elseif ($used > $capacity) {
                $status = 'over_capacity';
            } elseif ($area['missing_location_family_count'] > 0) {
                $status = 'missing_locations';
            } elseif (!$area['student_count']) {
                $status = 'no_students';
            } elseif ($used === $capacity) {
                $status = 'at_capacity';
            } elseif ($utilization >= 85) {
                $status = 'near_capacity';
            } else {
                $status = 'assigned';
            }
            if (in_array($status, array('invalid_bus', 'invalid_bus_capacity', 'invalid_trip', 'over_capacity'), true)) {
                $problem_count++;
            }
            $area['assignment'] = $allocation;
            $area['effective_capacity'] = $allocation ? $capacity : null;
            $area['bus_trip_used_seats'] = $allocation ? $used : null;
            $area['bus_trip_remaining_seats'] = $remaining;
            $area['utilization'] = $utilization;
            $area['assignment_status'] = $status;
        }
        unset($area);
        foreach ($areas as &$area) {
            $area['non_transportation_student_count'] = max(0, (int) $area['student_count'] - (int) $area['transportation_student_count']);
        }
        unset($area);
        foreach ($families as &$family) {
            if ($family['bus_id']) {
                $key = $family['bus_id'] . ':' . $family['trip_number'];
                $family['bus_trip_used_seats'] = (int) ($trip_usage[$key] ?? 0);
                $family['bus_trip_remaining_seats'] = $family['effective_capacity'] - $family['bus_trip_used_seats'];
                $family['utilization'] = $family['effective_capacity'] > 0 ? round(($family['bus_trip_used_seats'] / $family['effective_capacity']) * 100, 1) : 0;
                if ($family['bus_trip_used_seats'] > $family['effective_capacity'] || !$allocation_by_area[$family['major_area_id']]['valid_bus_trip']) {
                    $family['assignment_status'] = 'capacity_problem';
                }
            } else {
                $family['bus_trip_used_seats'] = 0;
                $family['bus_trip_remaining_seats'] = 0;
                $family['utilization'] = 0;
            }
        }
        unset($family);

        $valid_locations = count(array_filter($families, function ($family) { return $family['latitude'] !== null; }));
        $families_with_areas = count(array_filter($families, function ($family) { return !empty($family['major_area_id']); }));
        $students = array_sum(array_column($families, 'student_count'));
        return array(
            'academic_year_id' => $year,
            'direction' => $direction,
            'demand_mode' => $mode,
            'warning' => $mode === 'academic_registration_fallback' ? __('Planning demand uses academic registration; transportation coverage counts only active transportation registrations.', 'olama-transportation') : '',
            'families' => $families,
            'areas' => $areas,
            'trip_usage' => $trip_usage,
            'metrics' => array(
                'valid_family_locations' => $valid_locations,
                'registered_transportation_families' => count($families),
                'families_missing_coordinates' => count($families) - $valid_locations,
                'families_with_planning_areas' => $families_with_areas,
                'families_without_planning_areas' => count($families) - $families_with_areas,
                'direction_students' => $students,
                'students_allocated' => $allocated_students,
                'students_not_allocated' => max(0, $students - $allocated_students),
                'active_buses' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$buses_table} WHERE status='active' AND (CASE WHEN planning_capacity>0 THEN planning_capacity ELSE passenger_capacity END)>0"),
                'used_bus_trip_slots' => count($trip_usage),
                'capacity_problem_count' => $problem_count,
            ),
        );
    }

    public static function family($academic_year_id, $direction, $family_stop_id)
    {
        $resolved = self::resolve($academic_year_id, $direction);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        foreach ($resolved['families'] as $family) {
            if ((int) $family['family_stop_id'] === absint($family_stop_id)) {
                return $family;
            }
        }
        return null;
    }

    private static function transportation_counts($study_year, array $demand_rows, $mode)
    {
        $counts = array();
        foreach ($demand_rows as $row) {
            $family_uid = (string) ($row['family_uid'] ?? '');
            if ($family_uid !== '') {
                $counts[$family_uid] = $mode === 'transport_enrollments' ? (int) ($row['student_count'] ?? 0) : 0;
            }
        }
        if ($mode === 'transport_enrollments' || !function_exists('olama_core')) {
            return $counts;
        }
        foreach (self::core_transport_students($study_year, $demand_rows) as $family_uid => $students) $counts[$family_uid] = count($students);
        return $counts;
    }

    private static function transportation_kg_g1_counts($study_year, $direction, array $demand_rows, $mode)
    {
        $counts = array();
        foreach ($demand_rows as $row) {
            $family_uid = (string) ($row['family_uid'] ?? '');
            if ($family_uid !== '') {
                $counts[$family_uid] = $mode === 'transport_enrollments' ? (int) ($row['transport_kg_g1_count'] ?? 0) : 0;
            }
        }
        if ($mode === 'transport_enrollments' || !function_exists('olama_core')) {
            return $counts;
        }

        foreach (self::core_transport_students($study_year, $demand_rows) as $family_uid => $students) {
            foreach ($students as $student) if (self::is_transport_kg_g1_grade($student['class_name'] ?? '')) $counts[$family_uid]++;
        }
        return $counts;
    }

    private static function core_transport_students($study_year, array $demand_rows)
    {
        static $cache = array();
        $family_uids = array_values(array_unique(array_filter(array_column($demand_rows, 'family_uid'))));
        if (!$family_uids || !function_exists('olama_core')) return array();
        sort($family_uids, SORT_STRING);
        $cache_key = $study_year . ':' . md5(implode('|', $family_uids));
        if (isset($cache[$cache_key])) return $cache[$cache_key];

        global $wpdb;
        // These are synchronized Core tables. Use their established table names here so
        // Transportation remains compatible with Core versions that do not expose the
        // transportation mirror through the read-model allowlist yet.
        $transportation = $wpdb->prefix . 'olama_core_student_transportation';
        $student_years = $wpdb->prefix . 'olama_core_student_years';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $transportation)) !== $transportation) {
            return self::legacy_core_transport_students($study_year, $demand_rows);
        }
        $alternate = strpos($study_year, '/') !== false ? str_replace('/', '-', $study_year) : str_replace('-', '/', $study_year);
        $placeholders = implode(',', array_fill(0, count($family_uids), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.family_uid,tr.student_uid,tr.oracle_student_id,
                    COALESCE(NULLIF(tr.class_name,''), sy.class_name) class_name
             FROM {$transportation} tr
             LEFT JOIN {$student_years} sy ON sy.student_uid=tr.student_uid AND sy.study_year=tr.study_year
             WHERE tr.study_year IN (%s,%s) AND (tr.is_active IS NULL OR tr.is_active=1)
               AND tr.family_uid IN ({$placeholders})",
            array_merge(array($study_year, $alternate), $family_uids)
        ), ARRAY_A);
        if (!is_array($rows)) {
            return self::legacy_core_transport_students($study_year, $demand_rows);
        }
        $result = array();
        foreach ($rows as $row) {
            $family_uid = (string) $row['family_uid'];
            $student_uid = (string) ($row['student_uid'] ?: $row['oracle_student_id']);
            if ($family_uid === '' || $student_uid === '') continue;
            $result[$family_uid][$student_uid] = array('class_name' => (string) ($row['class_name'] ?? ''));
        }
        return $cache[$cache_key] = $result;
    }

    /** Compatibility path for installations whose Core transportation mirror is unavailable. */
    private static function legacy_core_transport_students($study_year, array $demand_rows)
    {
        $result = array();
        $transportation = olama_core()->transportation();
        foreach ($demand_rows as $row) {
            $family_uid = (string) ($row['family_uid'] ?? '');
            $family_id = (string) ($row['oracle_family_id'] ?? '');
            if ($family_uid === '' || $family_id === '') continue;
            foreach ($transportation->get_family($family_id, $study_year) as $record) {
                if (isset($record['is_active']) && $record['is_active'] !== null && (int) $record['is_active'] !== 1) continue;
                $student_uid = (string) ($record['student_uid'] ?? ($record['oracle_student_id'] ?? ''));
                if ($student_uid === '') continue;
                $result[$family_uid][$student_uid] = array('class_name' => (string) ($record['class_name'] ?? ''));
            }
        }
        return $result;
    }

    private static function is_transport_kg_g1_grade($grade)
    {
        $grade = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $grade), 'UTF-8') : strtolower(trim((string) $grade));
        $grade = preg_replace('/\s+/u', ' ', $grade);
        return in_array($grade, array('kg1','kg 1','kg-1','kg2','kg 2','kg-2','تمهيدي','بستان','الصف الأول','الصف الاول','صف أول','صف اول','الأول','الاول','grade 1','first grade','1'), true);
    }
}
