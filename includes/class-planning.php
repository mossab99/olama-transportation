<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Planning
{
    public static function demand($academic_year_id)
    {
        global $wpdb;
        $enrollments = Olama_Transportation_DB::table('enrollments');
        $family_stops = Olama_Transportation_DB::table('family_stops');
        $areas = Olama_Transportation_DB::table('major_areas');
        $allocations = Olama_Transportation_DB::table('area_bus_assignments');
        $buses = Olama_Transportation_DB::table('buses');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id AS major_area_id, a.name AS major_area_name,
                    COUNT(DISTINCT CASE WHEN e.morning_enabled = 1 THEN e.student_uid END) AS morning_students,
                    COUNT(DISTINCT CASE WHEN e.afternoon_enabled = 1 THEN e.student_uid END) AS afternoon_students,
                    COUNT(DISTINCT fs.id) AS family_stops
             FROM {$areas} a
             LEFT JOIN {$family_stops} fs ON fs.major_area_id = a.id
             LEFT JOIN {$enrollments} e
               ON (e.pickup_family_stop_id = fs.id OR e.dropoff_family_stop_id = fs.id)
              AND e.academic_year_id = %d AND e.status = 'active'
             WHERE a.status = 'active'
             GROUP BY a.id, a.name ORDER BY a.name",
            intval($academic_year_id)
        ), ARRAY_A);

        foreach ($rows as &$row) {
            foreach (array('morning', 'afternoon') as $direction) {
                $capacity = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(COALESCE(b.planning_capacity, b.passenger_capacity)), 0)
                     FROM {$allocations} x JOIN {$buses} b ON b.id = x.bus_id
                     WHERE x.academic_year_id = %d AND x.major_area_id = %d
                       AND x.direction = %s AND x.status = 'active'",
                    intval($academic_year_id), intval($row['major_area_id']), $direction
                ));
                $students = intval($row[$direction . '_students']);
                $row[$direction . '_capacity'] = $capacity;
                $row[$direction . '_shortage'] = max(0, $students - $capacity);
                $row[$direction . '_utilization'] = $capacity ? round(($students / $capacity) * 100, 1) : ($students ? 100 : 0);
            }
        }
        unset($row);
        return $rows;
    }

    public static function report_summary($academic_year_id)
    {
        global $wpdb;
        $enrollments = Olama_Transportation_DB::table('enrollments');
        $family_stops = Olama_Transportation_DB::table('family_stops');
        $routes = Olama_Transportation_DB::table('route_versions');
        return array(
            'enrolled_students' => intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$enrollments} WHERE academic_year_id = %d AND status = 'active'",
                $academic_year_id
            ))),
            'verified_family_stops' => intval($wpdb->get_var(
                "SELECT COUNT(*) FROM {$family_stops} WHERE verification_status = 'approved'"
            )),
            'stops_needing_review' => intval($wpdb->get_var(
                "SELECT COUNT(*) FROM {$family_stops} WHERE verification_status = 'needs_review'"
            )),
            'published_routes' => intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$routes} WHERE academic_year_id = %d AND status = 'published'",
                $academic_year_id
            ))),
            'area_demand' => self::demand($academic_year_id),
        );
    }
}
