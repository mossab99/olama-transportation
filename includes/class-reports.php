<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical transportation reporting read model.
 *
 * A transportation subscription and a local trip assignment are different facts:
 * - Core transportation rows determine whether a student is subscribed.
 * - Shared-trip membership determines assignment independently for each direction.
 * - Academic registration supplies the walking population and identity metadata.
 */
class Olama_Transportation_Reports
{
    public static function school_report($academic_year_id, array $args = array())
    {
        $context = self::context($academic_year_id);
        if (is_wp_error($context)) return $context;

        $population = sanitize_key($args['population'] ?? 'transportation');
        if (!in_array($population, array('transportation', 'walking'), true)) $population = 'transportation';
        $direction = sanitize_key($args['direction'] ?? 'all');
        if (!in_array($direction, array('all', 'morning', 'afternoon'), true)) $direction = 'all';

        $rows = $population === 'walking' ? $context['walking'] : $context['subscribed'];
        $rows = self::enrich_rows($rows, $context['assignments'], $direction);
        $rows = self::filter_rows($rows, $args, $direction, $population);

        return array(
            'population' => $population,
            'direction' => $direction,
            'filters' => self::grade_section_filters($population === 'walking' ? $context['walking'] : $context['subscribed']),
            'areas' => self::area_filters($population === 'walking' ? $context['walking'] : $context['subscribed']),
            'trips' => self::trip_filters($context['assignments'], $direction, $population === 'walking' ? array() : $context['subscribed']),
            'summary' => self::summary($rows, $context, $population, $direction),
            'diagnostics' => $context['diagnostics'],
            'rows' => array_values($rows),
        );
    }

    public static function family_report($academic_year_id, $search)
    {
        $context = self::context($academic_year_id);
        if (is_wp_error($context)) return $context;
        $search = trim(sanitize_text_field($search));
        if ($search === '') return array('items'=>array(), 'total'=>0, 'diagnostics'=>$context['diagnostics']);

        $all = array();
        foreach (array_merge($context['registered'], $context['subscribed']) as $row) {
            $key = self::student_key($row);
            if (!isset($all[$key])) $all[$key] = $row;
            elseif (!empty($row['subscribed'])) $all[$key] = array_merge($all[$key], $row);
        }
        $rows = self::enrich_rows(array_values($all), $context['assignments'], 'all');
        $matching_families = array();
        foreach ($rows as $row) {
            $haystack = implode(' ', array(
                $row['oracle_family_id'] ?? '', $row['family_name'] ?? '', $row['father_name'] ?? '',
                $row['father_mobile'] ?? '', $row['mother_mobile'] ?? '', $row['student_name'] ?? '',
                $row['grade_name'] ?? '', $row['section_name'] ?? '',
            ));
            $key = (string) ($row['family_uid'] ?: $row['oracle_family_id']);
            if (stripos($haystack, $search) !== false) $matching_families[$key] = true;
        }
        $families = array();
        foreach ($rows as $row) {
            $key = (string) ($row['family_uid'] ?: $row['oracle_family_id']);
            if (!isset($matching_families[$key])) continue;
            if (!isset($families[$key])) {
                $families[$key] = array(
                    'family_uid'=>$row['family_uid'], 'oracle_family_id'=>$row['oracle_family_id'],
                    'family_name'=>$row['family_name'], 'father_name'=>$row['father_name'],
                    'father_mobile'=>$row['father_mobile'], 'mother_mobile'=>$row['mother_mobile'],
                    'oracle_address'=>$row['oracle_address'], 'maps_url'=>$row['maps_url'],
                    'planning_area'=>$row['planning_area'], 'students'=>array(),
                );
            }
            $families[$key]['students'][] = $row;
        }
        foreach ($families as &$family) {
            usort($family['students'], array(__CLASS__, 'compare_students'));
            // Compatibility for cached clients of the former endpoint.
            $family['transport_rows'] = $family['students'];
        }
        unset($family);
        return array('items'=>array_values($families), 'total'=>count($families), 'diagnostics'=>$context['diagnostics']);
    }

    public static function unassigned_report($academic_year_id, $scope = 'none')
    {
        $context = self::context($academic_year_id);
        if (is_wp_error($context)) return $context;
        $scope = sanitize_key($scope);
        if (!in_array($scope, array('none', 'any_missing', 'morning', 'afternoon'), true)) $scope = 'none';
        $rows = self::enrich_rows($context['subscribed'], $context['assignments'], 'all');
        $rows = array_values(array_filter($rows, static function ($row) use ($scope) {
            $morning = !empty($row['arrival']);
            $afternoon = !empty($row['departure']);
            if ($scope === 'morning') return !$morning;
            if ($scope === 'afternoon') return !$afternoon;
            if ($scope === 'any_missing') return !$morning || !$afternoon;
            return !$morning && !$afternoon;
        }));
        return array(
            'scope'=>$scope,
            'summary'=>self::summary($rows, $context, 'transportation', 'all'),
            'diagnostics'=>$context['diagnostics'],
            'rows'=>$rows,
        );
    }

    private static function context($academic_year_id)
    {
        global $wpdb;
        $year = absint($academic_year_id);
        if (!$year) return new WP_Error('invalid_report_year', __('Academic year is required.', 'olama-transportation'), array('status'=>400));
        $study_year = preg_replace('/\s*([\/-])\s*/', '$1', Olama_Transportation_Bus::study_year($year));
        if ($study_year === '') return new WP_Error('invalid_report_year', __('The academic year has no study-year code.', 'olama-transportation'), array('status'=>400));
        $transportation = $wpdb->prefix . 'olama_core_student_transportation';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $transportation)) !== $transportation) {
            return new WP_Error('transportation_source_unavailable', __('The synchronized Core transportation table is unavailable. Reports cannot safely infer subscriptions from trip assignments.', 'olama-transportation'), array('status'=>503));
        }
        $subscribed = self::subscribed_rows($study_year);
        if ($wpdb->last_error) return self::query_error($wpdb->last_error);
        $registered = self::registered_rows($study_year);
        if ($wpdb->last_error) return self::query_error($wpdb->last_error);
        $subscribed_keys = array_fill_keys(array_map(array(__CLASS__, 'student_key'), $subscribed), true);
        $walking = array_values(array_filter($registered, static function ($row) use ($subscribed_keys) {
            return !isset($subscribed_keys[self::student_key($row)]);
        }));
        $assignments = self::assignments($year);
        if ($wpdb->last_error) return self::query_error($wpdb->last_error);
        $diagnostics = self::diagnostics($study_year, $subscribed, $registered, $assignments);
        return compact('year', 'study_year', 'subscribed', 'registered', 'walking', 'assignments', 'diagnostics');
    }

    private static function subscribed_rows($study_year)
    {
        global $wpdb;
        $alternate = self::alternate_year($study_year);
        $tr = $wpdb->prefix . 'olama_core_student_transportation';
        $sy = olama_core()->read_models()->table('student_years');
        $students = olama_core()->read_models()->table('students');
        $families = olama_core()->read_models()->table('families');
        $stops = Olama_Transportation_DB::table('family_stops');
        $areas = Olama_Transportation_DB::table('major_areas');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.family_uid,tr.student_uid,
                    MAX(tr.oracle_family_id) oracle_family_id,MAX(tr.oracle_student_id) oracle_student_id,
                    COALESCE(MAX(NULLIF(s.student_name,'')),MAX(NULLIF(tr.student_uid,''))) student_name,
                    COALESCE(MAX(NULLIF(sy.class_name,'')),MAX(NULLIF(tr.class_name,''))) grade_name,
                    COALESCE(MAX(NULLIF(sy.section_name,'')),MAX(NULLIF(tr.section_name,''))) section_name,
                    MAX(NULLIF(f.sponsor_full_name,'')) family_name,MAX(NULLIF(f.father_name,'')) father_name,
                    MAX(f.father_mobile) father_mobile,MAX(f.mother_mobile) mother_mobile,
                    MAX(COALESCE(NULLIF(f.family_address,''),NULLIF(f.address,''),'')) oracle_address,
                    MAX(fs.major_area_id) planning_area_id,MAX(a.name) planning_area,
                    MAX(fs.latitude) latitude,MAX(fs.longitude) longitude,MAX(fs.maps_url) maps_url,
                    MAX(tr.source_record_count) source_record_count,
                    MAX(CASE WHEN s.student_uid IS NULL THEN 1 ELSE 0 END) missing_student_identity,
                    MAX(CASE WHEN sy.student_uid IS NULL THEN 1 ELSE 0 END) missing_academic_registration,
                    MAX(CASE WHEN f.family_uid IS NULL THEN 1 ELSE 0 END) missing_family_identity
             FROM (
                 SELECT family_uid,student_uid,MAX(oracle_family_id) oracle_family_id,MAX(oracle_student_id) oracle_student_id,
                        MAX(class_name) class_name,MAX(section_name) section_name,COUNT(*) source_record_count
                 FROM {$tr}
                 WHERE study_year IN (%s,%s) AND (is_active IS NULL OR is_active=1)
                 GROUP BY family_uid,student_uid
             ) tr
             LEFT JOIN {$students} s ON s.student_uid=tr.student_uid
             LEFT JOIN {$sy} sy ON sy.student_uid=tr.student_uid AND sy.family_uid=tr.family_uid AND sy.study_year IN (%s,%s)
             LEFT JOIN {$families} f ON f.family_uid=tr.family_uid
             LEFT JOIN {$stops} fs ON fs.id=(SELECT MIN(fs2.id) FROM {$stops} fs2 WHERE fs2.family_uid=tr.family_uid OR (fs2.family_uid IS NULL AND fs2.oracle_family_id=tr.oracle_family_id))
             LEFT JOIN {$areas} a ON a.id=fs.major_area_id
             GROUP BY tr.family_uid,tr.student_uid
             ORDER BY oracle_family_id,student_name,grade_name,section_name",
            $study_year, $alternate, $study_year, $alternate
        ), ARRAY_A);
        $rows = (array)$rows;
        foreach ($rows as &$row) {
            $row = self::normalize_identity_row($row);
            $row['subscribed'] = true;
            $row['source_record_count'] = (int) $row['source_record_count'];
            foreach (array('missing_student_identity','missing_academic_registration','missing_family_identity') as $key) $row[$key] = !empty($row[$key]);
        }
        unset($row);
        return $rows;
    }

    private static function registered_rows($study_year)
    {
        global $wpdb;
        $alternate = self::alternate_year($study_year);
        $sy = olama_core()->read_models()->table('student_years');
        $students = olama_core()->read_models()->table('students');
        $families = olama_core()->read_models()->table('families');
        $stops = Olama_Transportation_DB::table('family_stops');
        $areas = Olama_Transportation_DB::table('major_areas');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sy.family_uid,sy.student_uid,MAX(sy.oracle_family_id) oracle_family_id,MAX(sy.oracle_student_id) oracle_student_id,
                    COALESCE(MAX(NULLIF(s.student_name,'')),MAX(NULLIF(sy.student_uid,''))) student_name,
                    MAX(sy.class_name) grade_name,MAX(sy.section_name) section_name,
                    MAX(NULLIF(f.sponsor_full_name,'')) family_name,MAX(NULLIF(f.father_name,'')) father_name,
                    MAX(f.father_mobile) father_mobile,MAX(f.mother_mobile) mother_mobile,
                    MAX(COALESCE(NULLIF(f.family_address,''),NULLIF(f.address,''),'')) oracle_address,
                    MAX(fs.major_area_id) planning_area_id,MAX(a.name) planning_area,
                    MAX(fs.latitude) latitude,MAX(fs.longitude) longitude,MAX(fs.maps_url) maps_url
             FROM {$sy} sy
             LEFT JOIN {$students} s ON s.student_uid=sy.student_uid
             LEFT JOIN {$families} f ON f.family_uid=sy.family_uid
             LEFT JOIN {$stops} fs ON fs.id=(SELECT MIN(fs2.id) FROM {$stops} fs2 WHERE fs2.family_uid=sy.family_uid OR (fs2.family_uid IS NULL AND fs2.oracle_family_id=sy.oracle_family_id))
             LEFT JOIN {$areas} a ON a.id=fs.major_area_id
             WHERE sy.study_year IN (%s,%s)
             GROUP BY sy.family_uid,sy.student_uid
             ORDER BY oracle_family_id,student_name,grade_name,section_name",
            $study_year, $alternate
        ), ARRAY_A);
        $rows = (array)$rows;
        foreach ($rows as &$row) {
            $row = self::normalize_identity_row($row);
            $row['subscribed'] = false;
            $row['source_record_count'] = 0;
            $row['missing_student_identity'] = false;
            $row['missing_academic_registration'] = false;
            $row['missing_family_identity'] = empty($row['family_name']) && empty($row['father_name']);
        }
        unset($row);
        return $rows;
    }

    private static function assignments($academic_year_id)
    {
        global $wpdb;
        $members = Olama_Transportation_DB::table('shared_trip_students');
        $trips = Olama_Transportation_DB::table('shared_trips');
        $buses = Olama_Transportation_DB::table('buses');
        $areas = Olama_Transportation_DB::table('major_areas');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.family_uid,m.student_uid,t.direction,t.id trip_id,t.name trip_name,t.bus_trip_number,
                    b.id bus_id,b.bus_number,COALESCE(NULLIF(driver.display_name,''),NULLIF(b.driver_source_name,''),'') driver_name,
                    m.major_area_id,a.name assignment_area
             FROM {$members} m INNER JOIN {$trips} t ON t.id=m.trip_id
             LEFT JOIN {$buses} b ON b.id=t.bus_id LEFT JOIN {$wpdb->users} driver ON driver.ID=b.driver_user_id
             LEFT JOIN {$areas} a ON a.id=m.major_area_id
             WHERE t.academic_year_id=%d AND t.status IN ('draft','published')
             ORDER BY m.family_uid,m.student_uid,t.direction,t.id",
            absint($academic_year_id)
        ), ARRAY_A);
        $result = array();
        foreach ((array)$rows as $row) {
            $key = self::student_key($row);
            $direction = $row['direction'];
            if (!isset($result[$key][$direction])) {
                foreach (array('trip_id','bus_trip_number','bus_id','major_area_id') as $field) $row[$field] = (int) $row[$field];
                $row['conflict_count'] = 0;
                $result[$key][$direction] = $row;
            } else {
                $result[$key][$direction]['conflict_count']++;
            }
        }
        return $result;
    }

    private static function enrich_rows(array $rows, array $assignments, $direction)
    {
        foreach ($rows as &$row) {
            $student_assignments = $assignments[self::student_key($row)] ?? array();
            $row['arrival'] = $student_assignments['morning'] ?? array();
            $row['departure'] = $student_assignments['afternoon'] ?? array();
            $morning = !empty($row['arrival']);
            $afternoon = !empty($row['departure']);
            if ($direction === 'morning') $row['assignment_status'] = $morning ? 'assigned' : 'unassigned';
            elseif ($direction === 'afternoon') $row['assignment_status'] = $afternoon ? 'assigned' : 'unassigned';
            elseif ($morning && $afternoon) $row['assignment_status'] = 'fully_assigned';
            elseif ($morning || $afternoon) $row['assignment_status'] = 'partial';
            else $row['assignment_status'] = 'unassigned';
            $selected = $direction === 'afternoon' ? $row['departure'] : ($direction === 'morning' ? $row['arrival'] : array());
            $row['trip_id'] = (int) ($selected['trip_id'] ?? 0);
            $row['trip_name'] = (string) ($selected['trip_name'] ?? '');
            $row['driver_name'] = (string) ($selected['driver_name'] ?? '');
            $row['bus_number'] = (string) ($selected['bus_number'] ?? '');
            $row['subscription_status'] = !empty($row['subscribed']) ? 'subscribed' : 'walking';
            $row['transport_status'] = !empty($row['subscribed']) ? 'with' : 'without';
            $row['assignment_conflict'] = !empty($row['arrival']['conflict_count']) || !empty($row['departure']['conflict_count']);
        }
        unset($row);
        return $rows;
    }

    private static function filter_rows(array $rows, array $args, $direction, $population)
    {
        $grade = sanitize_text_field($args['grade'] ?? '');
        $section = sanitize_text_field($args['section'] ?? '');
        $area_id = absint($args['area_id'] ?? 0);
        $trip_id = absint($args['trip_id'] ?? 0);
        $school_filter = sanitize_key($args['school_filter'] ?? 'all');
        $status = sanitize_key($args['assignment_status'] ?? 'all');
        if ($status === 'with') $status = 'assigned';
        if ($status === 'without') $status = 'unassigned';
        return array_values(array_filter($rows, static function ($row) use ($grade,$section,$area_id,$trip_id,$school_filter,$status,$direction,$population) {
            if ($grade !== '' && (string)$row['grade_name'] !== $grade) return false;
            if ($section !== '' && (string)$row['section_name'] !== $section) return false;
            if ($school_filter === 'kgs' && !self::is_kg_grade($row['grade_name'])) return false;
            if ($area_id && (int)$row['planning_area_id'] !== $area_id) return false;
            if ($trip_id) {
                $matches = $direction === 'morning' ? (int)($row['arrival']['trip_id'] ?? 0) === $trip_id
                    : ($direction === 'afternoon' ? (int)($row['departure']['trip_id'] ?? 0) === $trip_id
                    : (int)($row['arrival']['trip_id'] ?? 0) === $trip_id || (int)($row['departure']['trip_id'] ?? 0) === $trip_id);
                if (!$matches) return false;
            }
            if ($population === 'transportation' && $status !== '' && $status !== 'all') {
                if ($status === 'any_missing') return $row['assignment_status'] !== 'fully_assigned';
                if ($status === 'assigned' && $direction === 'all') return $row['assignment_status'] !== 'unassigned';
                return $row['assignment_status'] === $status;
            }
            return true;
        }));
    }

    private static function summary(array $rows, array $context, $population, $direction)
    {
        $counts = array('filtered_students'=>count($rows),'assigned'=>0,'unassigned'=>0,'partial'=>0,'fully_assigned'=>0);
        foreach ($rows as $row) {
            $status = $row['assignment_status'] ?? 'unassigned';
            if (isset($counts[$status])) $counts[$status]++;
        }
        $counts['subscribed_students'] = count($context['subscribed']);
        $counts['registered_students'] = count($context['registered']);
        $counts['walking_students'] = count($context['walking']);
        $counts['population'] = $population;
        $counts['direction'] = $direction;
        return $counts;
    }

    private static function diagnostics($study_year, array $subscribed, array $registered, array $assignments)
    {
        $diagnostics = array(
            'study_year'=>$study_year, 'active_subscription_students'=>count($subscribed),
            'active_subscription_records'=>0, 'registered_students'=>count($registered),
            'duplicate_subscription_records'=>0, 'missing_student_identity'=>0,
            'missing_academic_registration'=>0, 'missing_family_identity'=>0,
            'assignment_conflicts'=>0, 'stale_assigned_students'=>0,
        );
        $subscribed_keys = array();
        foreach ($subscribed as $row) {
            $subscribed_keys[self::student_key($row)] = true;
            $diagnostics['active_subscription_records'] += (int)$row['source_record_count'];
            $diagnostics['duplicate_subscription_records'] += max(0, (int)$row['source_record_count'] - 1);
            foreach (array('missing_student_identity','missing_academic_registration','missing_family_identity') as $key) if (!empty($row[$key])) $diagnostics[$key]++;
        }
        foreach ($assignments as $key => $directions) {
            if (!isset($subscribed_keys[$key])) $diagnostics['stale_assigned_students']++;
            foreach ($directions as $assignment) $diagnostics['assignment_conflicts'] += (int)($assignment['conflict_count'] ?? 0);
        }
        return $diagnostics;
    }

    private static function normalize_identity_row(array $row)
    {
        foreach (array('planning_area_id') as $key) $row[$key] = (int)($row[$key] ?? 0);
        $row['family_uid'] = (string)($row['family_uid'] ?? '');
        $row['student_uid'] = (string)($row['student_uid'] ?? '');
        $row['oracle_family_id'] = (string)($row['oracle_family_id'] ?? '');
        $row['oracle_student_id'] = (string)($row['oracle_student_id'] ?? '');
        $row['student_name'] = (string)($row['student_name'] ?: $row['student_uid'] ?: $row['oracle_student_id']);
        $row['family_name'] = (string)($row['family_name'] ?: $row['father_name'] ?: $row['oracle_family_id']);
        $row['maps_url'] = (string)($row['maps_url'] ?? '');
        if ($row['maps_url'] === '' && $row['latitude'] !== null && $row['longitude'] !== null) {
            $row['maps_url'] = 'https://www.google.com/maps?q=' . rawurlencode($row['latitude'] . ',' . $row['longitude']);
        }
        return $row;
    }

    private static function grade_section_filters(array $rows)
    {
        $filters = array();
        foreach ($rows as $row) {
            $key = (string)$row['grade_name'] . "\0" . (string)$row['section_name'];
            $filters[$key] = array('grade_name'=>$row['grade_name'], 'section_name'=>$row['section_name']);
        }
        $filters = array_values($filters);
        usort($filters, static function ($a,$b) { return strnatcasecmp($a['grade_name'].' '.$a['section_name'], $b['grade_name'].' '.$b['section_name']); });
        return $filters;
    }

    private static function area_filters(array $rows)
    {
        $areas = array();
        foreach ($rows as $row) if (!empty($row['planning_area_id'])) $areas[(int)$row['planning_area_id']] = array('id'=>(int)$row['planning_area_id'],'name'=>$row['planning_area']);
        uasort($areas, static function ($a,$b) { return strnatcasecmp($a['name'], $b['name']); });
        return array_values($areas);
    }

    private static function trip_filters(array $assignments, $direction, array $population_rows)
    {
        $trips = array();
        $population_keys = array_fill_keys(array_map(array(__CLASS__, 'student_key'), $population_rows), true);
        foreach ($assignments as $key => $student) foreach ($student as $assignment) {
            if (!isset($population_keys[$key])) continue;
            if ($direction !== 'all' && $assignment['direction'] !== $direction) continue;
            $trips[(int)$assignment['trip_id']] = array('id'=>(int)$assignment['trip_id'],'name'=>$assignment['trip_name'],'direction'=>$assignment['direction']);
        }
        uasort($trips, static function ($a,$b) { return strnatcasecmp($a['name'], $b['name']); });
        return array_values($trips);
    }

    private static function student_key($row)
    {
        return (string)($row['family_uid'] ?? '') . '|' . (string)($row['student_uid'] ?? '');
    }

    private static function alternate_year($study_year)
    {
        return strpos($study_year, '/') !== false ? str_replace('/', '-', $study_year) : str_replace('-', '/', $study_year);
    }

    private static function query_error($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) error_log('Olama Transportation report query failed: ' . (string)$message);
        return new WP_Error('transportation_report_query_failed', __('The transportation report could not be calculated.', 'olama-transportation'), array('status'=>500));
    }

    private static function is_kg_grade($grade)
    {
        $grade = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$grade), 'UTF-8') : strtolower(trim((string)$grade));
        return in_array(preg_replace('/\s+/u', ' ', $grade), array('kg1','kg 1','kg-1','kg2','kg 2','kg-2','بستان','تمهيدي'), true);
    }

    private static function compare_students($a, $b)
    {
        return strnatcasecmp(($a['grade_name'] ?? '').' '.($a['section_name'] ?? '').' '.($a['student_name'] ?? ''), ($b['grade_name'] ?? '').' '.($b['section_name'] ?? '').' '.($b['student_name'] ?? ''));
    }
}
