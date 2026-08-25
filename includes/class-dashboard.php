<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Read-only operational control-center data for the Transportation home page. */
class Olama_Transportation_Dashboard
{
    public static function get($academic_year_id)
    {
        global $wpdb;
        $year = absint($academic_year_id);
        if (!$year) return self::empty_dashboard();

        $report = Olama_Transportation_Reports::school_report($year, array('population'=>'transportation','direction'=>'all'));
        $report_error = is_wp_error($report) ? $report->get_error_message() : '';
        if (is_wp_error($report)) $report = array('rows'=>array(),'summary'=>array(),'diagnostics'=>array());

        $students = (array)($report['rows'] ?? array());
        $student_count = count($students);
        $families = array();
        $arrival_assigned = 0;
        $departure_assigned = 0;
        $missing_area_families = array();
        $missing_location_families = array();
        foreach ($students as $student) {
            $family_key = (string)($student['family_uid'] ?: $student['oracle_family_id']);
            if ($family_key !== '') $families[$family_key] = true;
            if (!empty($student['arrival'])) $arrival_assigned++;
            if (!empty($student['departure'])) $departure_assigned++;
            if ($family_key !== '' && empty($student['planning_area_id'])) $missing_area_families[$family_key] = true;
            if ($family_key !== '' && (empty($student['latitude']) || empty($student['longitude']))) $missing_location_families[$family_key] = true;
        }

        $trips = array_merge(
            Olama_Transportation_Shared_Trips::list_for_context($year, 'morning'),
            Olama_Transportation_Shared_Trips::list_for_context($year, 'afternoon')
        );
        $routes = self::latest_routes($year);
        $active_bus_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . Olama_Transportation_DB::table('buses') . " WHERE status='active'");
        $used_bus_ids = array();
        $over_capacity = $long_trips = $missing_bus = $missing_staff = $stale_routes = 0;
        $published_trips = $published_routes = 0;
        $total_students_on_trips = $total_trip_capacity = 0;
        $long_trip_minutes = max(1, (int)apply_filters('olama_transportation_long_trip_minutes', 45));

        foreach ($trips as &$trip) {
            $trip_id = (int)$trip['id'];
            $route = $routes[$trip_id] ?? array();
            $trip['route_id'] = (int)($route['id'] ?? 0);
            $trip['route_status'] = (string)($route['status'] ?? 'missing');
            $trip['duration_seconds'] = (int)($route['total_duration_seconds'] ?? 0);
            $trip['distance_m'] = (int)($route['total_distance_m'] ?? 0);
            $trip['route_needs_recalculation'] = false;
            if ($route) {
                $current_hash = Olama_Transportation_Routes::source_hash($trip_id);
                $trip['route_needs_recalculation'] = empty($route['route_source_hash']) || !hash_equals((string)$route['route_source_hash'], $current_hash);
            }
            $trip['occupancy'] = $trip['bus_capacity'] > 0 ? round(($trip['student_count'] / $trip['bus_capacity']) * 100, 1) : 0;
            $trip['available_seats'] = $trip['bus_capacity'] > 0 ? $trip['bus_capacity'] - $trip['student_count'] : null;
            $trip['duration_minutes'] = $trip['duration_seconds'] ? (int)ceil($trip['duration_seconds'] / 60) : 0;
            $trip['is_over_capacity'] = $trip['bus_capacity'] > 0 && $trip['student_count'] > $trip['bus_capacity'];
            $trip['is_long'] = $trip['duration_minutes'] > $long_trip_minutes;
            $trip['missing_bus'] = empty($trip['bus_id']);
            $trip['missing_driver'] = empty($trip['driver_name']);
            $trip['missing_companion'] = empty($trip['companion_name']);
            $trip['operational_status'] = self::trip_status($trip);

            if ($trip['bus_id']) $used_bus_ids[(int)$trip['bus_id']] = true;
            if ($trip['is_over_capacity']) $over_capacity++;
            if ($trip['is_long']) $long_trips++;
            if ($trip['missing_bus']) $missing_bus++;
            if ($trip['missing_driver'] || $trip['missing_companion']) $missing_staff++;
            if ($trip['route_needs_recalculation']) $stale_routes++;
            if ($trip['status'] === 'published') $published_trips++;
            if ($trip['route_status'] === 'published') $published_routes++;
            $total_students_on_trips += (int)$trip['student_count'];
            $total_trip_capacity += (int)$trip['bus_capacity'];
        }
        unset($trip);
        usort($trips, array(__CLASS__, 'sort_trips'));

        $arrival_missing = max(0, $student_count - $arrival_assigned);
        $departure_missing = max(0, $student_count - $departure_assigned);
        $diagnostics = (array)($report['diagnostics'] ?? array());
        $exceptions = self::exceptions(array(
            'over_capacity'=>$over_capacity, 'long_trips'=>$long_trips, 'missing_bus'=>$missing_bus,
            'missing_staff'=>$missing_staff, 'stale_routes'=>$stale_routes,
            'arrival_missing'=>$arrival_missing, 'departure_missing'=>$departure_missing,
            'missing_locations'=>count($missing_location_families), 'missing_areas'=>count($missing_area_families),
            'data_problems'=>(int)($diagnostics['duplicate_subscription_records'] ?? 0)
                + (int)($diagnostics['missing_student_identity'] ?? 0)
                + (int)($diagnostics['assignment_conflicts'] ?? 0)
                + (int)($diagnostics['stale_assigned_students'] ?? 0),
        ));

        $arrival_rate = $student_count ? round(($arrival_assigned / $student_count) * 100, 1) : 0;
        $departure_rate = $student_count ? round(($departure_assigned / $student_count) * 100, 1) : 0;
        $family_count = count($families);
        $location_rate = $family_count ? (($family_count - count($missing_location_families)) / $family_count) * 100 : 0;
        $area_rate = $family_count ? (($family_count - count($missing_area_families)) / $family_count) * 100 : 0;
        $healthy_trip_rate = count($trips) ? ((count($trips) - $over_capacity - $missing_bus) / count($trips)) * 100 : 0;
        $readiness = $student_count ? (int)round(($arrival_rate * .25) + ($departure_rate * .25) + ($location_rate * .2) + ($area_rate * .2) + (max(0, $healthy_trip_rate) * .1)) : 0;

        return array(
            'year_id'=>$year,
            'generated_at'=>current_time('mysql'),
            'report_warning'=>$report_error,
            'readiness'=>max(0, min(100, $readiness)),
            'metrics'=>array(
                'students'=>$student_count, 'families'=>$family_count,
                'arrival_assigned'=>$arrival_assigned, 'departure_assigned'=>$departure_assigned,
                'arrival_rate'=>$arrival_rate, 'departure_rate'=>$departure_rate,
                'active_buses'=>$active_bus_count, 'used_buses'=>count($used_bus_ids),
                'trips'=>count($trips), 'published_trips'=>$published_trips, 'published_routes'=>$published_routes,
                'over_capacity'=>$over_capacity, 'long_trips'=>$long_trips,
                'unassigned_any'=>count(array_filter($students, static function($student){ return empty($student['arrival']) || empty($student['departure']); })),
                'missing_locations'=>count($missing_location_families), 'missing_areas'=>count($missing_area_families),
                'stale_routes'=>$stale_routes, 'total_trip_capacity'=>$total_trip_capacity,
                'total_students_on_trips'=>$total_students_on_trips,
                'fleet_utilization'=>$total_trip_capacity ? round(($total_students_on_trips / $total_trip_capacity) * 100, 1) : 0,
            ),
            'directions'=>array(
                'morning'=>array('assigned'=>$arrival_assigned,'missing'=>$arrival_missing,'rate'=>$arrival_rate,'trips'=>count(array_filter($trips, static function($trip){return $trip['direction']==='morning';}))),
                'afternoon'=>array('assigned'=>$departure_assigned,'missing'=>$departure_missing,'rate'=>$departure_rate,'trips'=>count(array_filter($trips, static function($trip){return $trip['direction']==='afternoon';}))),
            ),
            'trips'=>$trips,
            'exceptions'=>$exceptions,
            'changes'=>self::recent_changes(),
            'diagnostics'=>$diagnostics,
        );
    }

    private static function latest_routes($year)
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('route_versions');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.* FROM {$table} r INNER JOIN (SELECT shared_trip_id,MAX(version_number) version_number FROM {$table} WHERE academic_year_id=%d AND shared_trip_id IS NOT NULL GROUP BY shared_trip_id) latest ON latest.shared_trip_id=r.shared_trip_id AND latest.version_number=r.version_number",
            $year
        ), ARRAY_A);
        $result = array();
        foreach ((array)$rows as $row) $result[(int)$row['shared_trip_id']] = $row;
        return $result;
    }

    private static function trip_status($trip)
    {
        if ($trip['is_over_capacity']) return 'critical';
        if ($trip['missing_bus'] || $trip['missing_driver'] || $trip['missing_companion']) return 'incomplete';
        if ($trip['route_needs_recalculation']) return 'stale';
        if ($trip['is_long']) return 'long';
        if ($trip['occupancy'] >= 90) return 'crowded';
        return $trip['status'] === 'published' ? 'published' : 'ready';
    }

    private static function sort_trips($a, $b)
    {
        $priority = array('critical'=>0,'incomplete'=>1,'stale'=>2,'long'=>3,'crowded'=>4,'ready'=>5,'published'=>6);
        $comparison = ($priority[$a['operational_status']] ?? 9) <=> ($priority[$b['operational_status']] ?? 9);
        return $comparison ?: strnatcasecmp($a['name'], $b['name']);
    }

    private static function exceptions($counts)
    {
        $definitions = array(
            array('key'=>'over_capacity','severity'=>'critical','label'=>'Trips exceed bus capacity','tab'=>'areas'),
            array('key'=>'arrival_missing','severity'=>'critical','label'=>'Students missing arrival trips','tab'=>'reports'),
            array('key'=>'departure_missing','severity'=>'critical','label'=>'Students missing departure trips','tab'=>'reports'),
            array('key'=>'stale_routes','severity'=>'critical','label'=>'Routes need recalculation','tab'=>'routes'),
            array('key'=>'missing_locations','severity'=>'warning','label'=>'Families missing valid locations','tab'=>'import'),
            array('key'=>'missing_areas','severity'=>'warning','label'=>'Families missing planning areas','tab'=>'import'),
            array('key'=>'missing_bus','severity'=>'warning','label'=>'Trips missing buses','tab'=>'areas'),
            array('key'=>'missing_staff','severity'=>'warning','label'=>'Trips missing driver or companion','tab'=>'areas'),
            array('key'=>'long_trips','severity'=>'warning','label'=>'Trips exceed the duration target','tab'=>'routes'),
            array('key'=>'data_problems','severity'=>'info','label'=>'Data integrity warnings','tab'=>'reports'),
        );
        $items = array();
        foreach ($definitions as $definition) {
            $count = (int)($counts[$definition['key']] ?? 0);
            if (!$count) continue;
            $definition['count'] = $count;
            $definition['url'] = add_query_arg(array('page'=>'olama-transportation','tab'=>$definition['tab']), admin_url('admin.php'));
            $items[] = $definition;
        }
        return $items;
    }

    private static function recent_changes()
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('audit_log');
        $rows = $wpdb->get_results(
            "SELECT a.*,u.display_name actor_name FROM {$table} a LEFT JOIN {$wpdb->users} u ON u.ID=a.actor_user_id ORDER BY a.id DESC LIMIT 12",
            ARRAY_A
        );
        $items = array();
        foreach ((array)$rows as $row) {
            $after = json_decode((string)$row['after_json'], true);
            $items[] = array(
                'action'=>(string)$row['action'], 'object_type'=>(string)$row['object_type'],
                'object_id'=>(string)$row['object_id'], 'actor'=>(string)($row['actor_name'] ?: __('System', 'olama-transportation')),
                'created_at'=>(string)$row['created_at'], 'detail'=>self::change_detail((string)$row['action'], is_array($after)?$after:array()),
            );
        }
        return $items;
    }

    private static function change_detail($action, $after)
    {
        switch ($action) {
            case 'families_moved': return sprintf(__('%1$d families and %2$d students moved between trips.', 'olama-transportation'), count((array)($after['family_uids'] ?? array())), (int)($after['student_count'] ?? 0));
            case 'shared_trip_students_changed': return sprintf(__('%d students saved to a trip.', 'olama-transportation'), (int)($after['student_count'] ?? 0));
            case 'shared_trip_published': return __('Trip published.', 'olama-transportation');
            case 'shared_trip_returned_to_draft': return __('Trip returned to draft.', 'olama-transportation');
            case 'publish': return __('Route published.', 'olama-transportation');
            case 'family_area_bulk_assigned': return sprintf(__('%d family planning areas updated.', 'olama-transportation'), (int)($after['count'] ?? 0));
            case 'import': return __('Family locations import completed.', 'olama-transportation');
            case 'transport_areas_refreshed': return __('Planning areas refreshed from Olama Core.', 'olama-transportation');
            case 'core_refresh': return __('Bus data refreshed from Olama Core.', 'olama-transportation');
            default: return ucwords(str_replace('_', ' ', $action));
        }
    }

    private static function empty_dashboard()
    {
        return array('readiness'=>0,'metrics'=>array(),'directions'=>array(),'trips'=>array(),'exceptions'=>array(),'changes'=>array(),'diagnostics'=>array(),'report_warning'=>'');
    }
}
