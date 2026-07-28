<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Map_Data
{
    public static function get($academic_year_id, $direction, $filters = array())
    {
        global $wpdb;

        $academic_year_id = absint($academic_year_id);
        $direction = sanitize_key($direction);
        if (!$academic_year_id || !in_array($direction, array('morning', 'afternoon'), true)) {
            return new WP_Error('invalid_map_request', __('A valid academic year and direction are required.', 'olama-transportation'), array('status' => 400));
        }
        $study_year = Olama_Transportation_Bus::study_year($academic_year_id);
        if ($study_year === '') {
            return new WP_Error('invalid_academic_year', __('Academic year was not found.', 'olama-transportation'), array('status' => 400));
        }

        $enrollments = Olama_Transportation_DB::table('enrollments');
        $enrollment_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$enrollments} WHERE academic_year_id = %d AND status = 'active'",
            $academic_year_id
        ));
        $mode = $enrollment_count > 0 ? 'transport_enrollments' : 'academic_registration_fallback';
        $demand = self::demand_rows($academic_year_id, $study_year, $direction, $mode);
        $families_by_uid = array();
        foreach ($demand as $row) {
            $families_by_uid[$row['family_uid']] = $row;
        }

        $assignments = self::active_assignments($academic_year_id, $direction);
        $valid = array();
        $invalid_count = 0;
        foreach ($families_by_uid as $uid => $row) {
            $student_count = (int) $row['student_count'];
            if ($student_count < 1) {
                continue;
            }
            $lat = isset($row['latitude']) ? (float) $row['latitude'] : 0;
            $lng = isset($row['longitude']) ? (float) $row['longitude'] : 0;
            $status = sanitize_key((string) ($row['verification_status'] ?? ''));
            if (!$row['family_stop_id'] || !in_array($status, array('needs_review', 'approved'), true)
                || !Olama_Transportation_Family_Locations::within_service_bounds($lat, $lng)) {
                $invalid_count++;
                continue;
            }
            $assignment = $assignments[$uid] ?? null;
            $item = array(
                'family_uid' => $uid,
                'oracle_family_id' => (string) $row['oracle_family_id'],
                'family_name' => (string) $row['family_name'],
                'oracle_region_id' => (string) ($row['trans_region_id'] ?? ''),
                'oracle_region_name' => (string) ($row['trans_region_name'] ?? ''),
                'region_name' => (string) ($row['trans_region_name'] ?? ''),
                'major_area_id' => $row['major_area_id'] ? (int) $row['major_area_id'] : null,
                'latitude' => $lat,
                'longitude' => $lng,
                'morning_student_count' => $direction === 'morning' ? $student_count : (int) ($row['morning_student_count'] ?? $student_count),
                'afternoon_student_count' => $direction === 'afternoon' ? $student_count : (int) ($row['afternoon_student_count'] ?? $student_count),
                'student_count' => $student_count,
                'location_status' => $status,
                'assignment' => $assignment,
            );
            if (!self::matches_filters($item, $filters)) {
                continue;
            }
            $valid[] = $item;
        }

        $assigned = 0;
        foreach ($valid as $family) {
            $assigned += $family['assignment'] ? 1 : 0;
        }
        $settings = get_option('olama_transportation_settings', array());
        $school = $settings['school_location'] ?? array('latitude' => 31.9539, 'longitude' => 35.9106);

        return array(
            'school' => array('latitude' => (float) $school['latitude'], 'longitude' => (float) $school['longitude']),
            'families' => $valid,
            'groups' => Olama_Transportation_Geographic_Planning::list_groups(array('academic_year_id' => $academic_year_id, 'direction' => $direction, 'include_archived' => false)),
            'areas' => self::areas(),
            'buses' => self::buses(),
            'meta' => array(
                'family_count' => count($valid),
                'assigned_count' => $assigned,
                'unassigned_count' => count($valid) - $assigned,
                'invalid_location_count' => $invalid_count,
                'demand_mode' => $mode,
                'warning' => $mode === 'academic_registration_fallback'
                    ? __('Transportation enrollment data is unavailable. Student counts are currently based on academic registration.', 'olama-transportation')
                    : '',
            ),
        );
    }

    public static function demand_rows($academic_year_id, $study_year, $direction, $mode)
    {
        global $wpdb;
        $families = olama_core()->read_models()->table('families');
        $stops = Olama_Transportation_DB::table('family_stops');
        $alternate = strpos($study_year, '/') !== false ? str_replace('/', '-', $study_year) : str_replace('-', '/', $study_year);
        if ($mode === 'transport_enrollments') {
            $enrollments = Olama_Transportation_DB::table('enrollments');
            $column = $direction === 'morning' ? 'morning_enabled' : 'afternoon_enabled';
            return $wpdb->get_results($wpdb->prepare(
                "SELECT f.family_uid, f.oracle_family_id,
                        COALESCE(NULLIF(f.sponsor_full_name,''), NULLIF(f.father_name,''), f.oracle_family_id) family_name,
                        f.trans_region_id, f.trans_region_name, fs.id family_stop_id, fs.latitude, fs.longitude,
                        fs.major_area_id, fs.verification_status, COUNT(DISTINCT e.student_uid) student_count
                 FROM {$enrollments} e INNER JOIN {$families} f ON f.family_uid=e.family_uid OR (e.family_uid IS NULL AND f.oracle_family_id=e.oracle_family_id)
                 LEFT JOIN {$stops} fs ON fs.family_uid=f.family_uid OR (fs.family_uid IS NULL AND fs.oracle_family_id=f.oracle_family_id)
                 WHERE e.academic_year_id=%d AND e.status='active' AND e.{$column}=1
                 GROUP BY f.family_uid, f.oracle_family_id, f.sponsor_full_name, f.father_name, f.trans_region_id,
                          f.trans_region_name, fs.id, fs.latitude, fs.longitude, fs.major_area_id, fs.verification_status",
                $academic_year_id
            ), ARRAY_A);
        }
        $years = olama_core()->read_models()->table('student_years');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT f.family_uid, f.oracle_family_id,
                    COALESCE(NULLIF(f.sponsor_full_name,''), NULLIF(f.father_name,''), f.oracle_family_id) family_name,
                    f.trans_region_id, f.trans_region_name, fs.id family_stop_id, fs.latitude, fs.longitude,
                    fs.major_area_id, fs.verification_status, COUNT(DISTINCT sy.student_uid) student_count
             FROM {$years} sy INNER JOIN {$families} f ON f.family_uid=sy.family_uid
             LEFT JOIN {$stops} fs ON fs.family_uid=f.family_uid OR (fs.family_uid IS NULL AND fs.oracle_family_id=f.oracle_family_id)
             WHERE sy.study_year IN (%s,%s)
             GROUP BY f.family_uid, f.oracle_family_id, f.sponsor_full_name, f.father_name, f.trans_region_id,
                      f.trans_region_name, fs.id, fs.latitude, fs.longitude, fs.major_area_id, fs.verification_status",
            $study_year,
            $alternate
        ), ARRAY_A);
    }

    private static function active_assignments($year, $direction)
    {
        global $wpdb;
        $groups = Olama_Transportation_DB::table('planning_groups');
        $members = Olama_Transportation_DB::table('planning_group_families');
        $buses = Olama_Transportation_DB::table('buses');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.family_uid,g.id group_id,g.group_name,g.color,g.status,g.bus_id,g.trip_number,b.bus_number
             FROM {$members} m INNER JOIN {$groups} g ON g.id=m.group_id LEFT JOIN {$buses} b ON b.id=g.bus_id
             WHERE g.academic_year_id=%d AND g.direction=%s AND g.status IN ('draft','approved')",
            $year,
            $direction
        ), ARRAY_A);
        $result = array();
        foreach ($rows as $row) {
            $result[$row['family_uid']] = array(
                'group_id' => (int) $row['group_id'], 'group_name' => $row['group_name'], 'color' => $row['color'],
                'status' => $row['status'], 'bus_id' => (int) $row['bus_id'], 'bus_number' => $row['bus_number'], 'trip_number' => (int) $row['trip_number'],
            );
        }
        return $result;
    }

    private static function areas()
    {
        global $wpdb;
        $areas = Olama_Transportation_DB::table('major_areas');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        return $wpdb->get_results(
            "SELECT a.id,a.name,a.code,a.color,a.boundary_geojson,a.status,m.oracle_region_id,m.oracle_region_name
             FROM {$areas} a LEFT JOIN {$mappings} m ON m.major_area_id=a.id
             WHERE a.status='active' ORDER BY a.name",
            ARRAY_A
        );
    }

    private static function buses()
    {
        $rows = Olama_Transportation_Bus::get_buses();
        $result = array();
        foreach ($rows as $bus) {
            $effective = (int) ($bus->planning_capacity > 0 ? $bus->planning_capacity : $bus->passenger_capacity);
            $result[] = array(
                'id' => (int) $bus->id, 'bus_number' => $bus->bus_number, 'government_number' => $bus->government_number,
                'passenger_capacity' => (int) $bus->passenger_capacity, 'planning_capacity' => (int) $bus->planning_capacity,
                'effective_capacity' => $effective, 'morning_trip_count' => (int) $bus->morning_trip_count,
                'afternoon_trip_count' => (int) $bus->afternoon_trip_count, 'status' => $bus->status,
                'capacity_source' => $bus->planning_capacity > 0 ? 'planning_override' : 'core',
                'core_capacity_missing' => (int) $bus->passenger_capacity < 1,
                'assignable' => $bus->status === 'active' && $effective > 0,
            );
        }
        return $result;
    }

    private static function matches_filters($family, $filters)
    {
        $area = absint($filters['major_area_id'] ?? 0);
        if ($area && (int) $family['major_area_id'] !== $area) {
            return false;
        }
        $assignment = sanitize_key($filters['assignment_status'] ?? 'all');
        if ($assignment === 'assigned' && !$family['assignment']) {
            return false;
        }
        if ($assignment === 'unassigned' && $family['assignment']) {
            return false;
        }
        $status = sanitize_key($filters['location_status'] ?? 'all');
        if ($status !== 'all' && $family['location_status'] !== $status) {
            return false;
        }
        $search = trim(sanitize_text_field($filters['search'] ?? ''));
        return $search === '' || stripos($family['family_name'] . ' ' . $family['oracle_family_id'], $search) !== false;
    }
}
