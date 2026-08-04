<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Map_Data
{
    public static function get($academic_year_id, $direction, $filters = array())
    {
        $resolved = Olama_Transportation_Effective_Assignments::resolve($academic_year_id, $direction);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $valid = array();
        $invalid_count = 0;
        foreach ($resolved['families'] as $family) {
            if ($family['latitude'] === null || $family['longitude'] === null) {
                $invalid_count++;
                continue;
            }
            $family['region_name'] = $family['oracle_region_name'];
            $family['assignment'] = $family['bus_id'] ? array(
                'bus_id' => $family['bus_id'], 'bus_number' => $family['bus_number'], 'trip_number' => $family['trip_number'],
                'status' => $family['assignment_status'],
            ) : null;
            $mode = sanitize_key($filters['mode'] ?? 'all');
            if ($mode === 'unassigned' && !in_array($family['assignment_status'], array('missing_area', 'area_not_allocated'), true)) continue;
            if ($mode === 'problems' && $family['assignment_status'] !== 'capacity_problem') continue;
            if (self::matches_filters($family, $filters)) {
                $valid[] = $family;
            }
        }
        $settings = get_option('olama_transportation_settings', array());
        $school = $settings['school_location'] ?? array('latitude' => 31.9539, 'longitude' => 35.9106);

        return array(
            'school' => array('latitude' => (float) $school['latitude'], 'longitude' => (float) $school['longitude']),
            'families' => $valid,
            'groups' => array(),
            'areas' => self::areas(),
            'buses' => self::buses(),
            'meta' => array(
                'family_count' => count($valid),
                'assigned_count' => count(array_filter($valid, function ($family) { return !empty($family['assignment']); })),
                'unassigned_count' => count(array_filter($valid, function ($family) { return empty($family['assignment']); })),
                'invalid_location_count' => $invalid_count,
                'demand_mode' => $resolved['demand_mode'],
                'warning' => $resolved['warning'],
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
                        fs.major_area_id, fs.verification_status, fs.area_assignment_source, COUNT(DISTINCT e.student_uid) student_count
                 FROM {$enrollments} e INNER JOIN {$families} f ON f.family_uid=e.family_uid OR (e.family_uid IS NULL AND f.oracle_family_id=e.oracle_family_id)
                 LEFT JOIN {$stops} fs ON fs.family_uid=f.family_uid OR (fs.family_uid IS NULL AND fs.oracle_family_id=f.oracle_family_id)
                 WHERE e.academic_year_id=%d AND e.status='active' AND e.{$column}=1
                 GROUP BY f.family_uid, f.oracle_family_id, f.sponsor_full_name, f.father_name, f.trans_region_id,
                          f.trans_region_name, fs.id, fs.latitude, fs.longitude, fs.major_area_id, fs.verification_status, fs.area_assignment_source",
                $academic_year_id
            ), ARRAY_A);
        }
        $years = olama_core()->read_models()->table('student_years');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT f.family_uid, f.oracle_family_id,
                    COALESCE(NULLIF(f.sponsor_full_name,''), NULLIF(f.father_name,''), f.oracle_family_id) family_name,
                    f.trans_region_id, f.trans_region_name, fs.id family_stop_id, fs.latitude, fs.longitude,
                    fs.major_area_id, fs.verification_status, fs.area_assignment_source, COUNT(DISTINCT sy.student_uid) student_count
             FROM {$years} sy INNER JOIN {$families} f ON f.family_uid=sy.family_uid
             LEFT JOIN {$stops} fs ON fs.family_uid=f.family_uid OR (fs.family_uid IS NULL AND fs.oracle_family_id=f.oracle_family_id)
             WHERE sy.study_year IN (%s,%s)
             GROUP BY f.family_uid, f.oracle_family_id, f.sponsor_full_name, f.father_name, f.trans_region_id,
                      f.trans_region_name, fs.id, fs.latitude, fs.longitude, fs.major_area_id, fs.verification_status, fs.area_assignment_source",
            $study_year,
            $alternate
        ), ARRAY_A);
    }

    private static function areas()
    {
        global $wpdb;
        $areas = Olama_Transportation_DB::table('major_areas');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        return $wpdb->get_results(
            "SELECT a.id,a.name,a.code,a.color,a.boundary_geojson,a.status,
                    (SELECT m.oracle_region_id FROM {$mappings} m WHERE m.major_area_id=a.id ORDER BY m.id LIMIT 1) oracle_region_id,
                    (SELECT m.oracle_region_name FROM {$mappings} m WHERE m.major_area_id=a.id ORDER BY m.id LIMIT 1) oracle_region_name
             FROM {$areas} a
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
