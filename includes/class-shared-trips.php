<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Draft-first, student-level trips that may contain demand from many areas. */
class Olama_Transportation_Shared_Trips
{
    const DEFAULT_PLANNING_LIMIT = 35;

    public static function list_for_context($academic_year_id, $direction)
    {
        global $wpdb;
        $year = absint($academic_year_id);
        $direction = sanitize_key($direction);
        if (!$year || !in_array($direction, array('morning', 'afternoon'), true)) {
            return array();
        }
        $trips = Olama_Transportation_DB::table('shared_trips');
        $members = Olama_Transportation_DB::table('shared_trip_students');
        $trip_areas = Olama_Transportation_DB::table('shared_trip_areas');
        $buses = Olama_Transportation_DB::table('buses');
        $areas = Olama_Transportation_DB::table('major_areas');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*,b.bus_number,
                    COALESCE(NULLIF(b.planning_capacity,0),b.passenger_capacity,0) bus_capacity,
                    COALESCE(NULLIF(driver.display_name,''),NULLIF(b.driver_source_name,''),'') driver_name,
                    COALESCE(NULLIF(companion.display_name,''),'') companion_name,
                    COUNT(DISTINCT s.student_uid) student_count,COUNT(DISTINCT s.family_uid) family_count,
                    GROUP_CONCAT(DISTINCT ta.major_area_id ORDER BY ta.major_area_id) area_ids,
                    GROUP_CONCAT(DISTINCT a.name ORDER BY a.name SEPARATOR ', ') area_names
             FROM {$trips} t
             LEFT JOIN {$members} s ON s.trip_id=t.id
             LEFT JOIN {$trip_areas} ta ON ta.trip_id=t.id
             LEFT JOIN {$buses} b ON b.id=t.bus_id
             LEFT JOIN {$wpdb->users} driver ON driver.ID=b.driver_user_id
             LEFT JOIN {$wpdb->users} companion ON companion.ID=t.companion_user_id
             LEFT JOIN {$areas} a ON a.id=ta.major_area_id
             WHERE t.academic_year_id=%d AND t.direction=%s AND t.status IN ('draft','published')
             GROUP BY t.id ORDER BY t.status='draft' DESC,t.id",
            $year,
            $direction
        ), ARRAY_A);
        foreach ($rows as &$row) {
            $row = self::normalize_trip($row);
        }
        unset($row);
        return $rows;
    }

    public static function area_contributions($academic_year_id, $direction)
    {
        global $wpdb;
        $trips = Olama_Transportation_DB::table('shared_trips');
        $members = Olama_Transportation_DB::table('shared_trip_students');
        $trip_areas = Olama_Transportation_DB::table('shared_trip_areas');
        $buses = Olama_Transportation_DB::table('buses');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ta.major_area_id,t.id,t.name,t.status,t.planning_limit,t.bus_trip_number,b.bus_number,
                    COALESCE(NULLIF(b.planning_capacity,0),b.passenger_capacity,0) bus_capacity,
                    COUNT(DISTINCT s.student_uid) area_student_count,
                    (SELECT COUNT(DISTINCT all_s.student_uid) FROM {$members} all_s WHERE all_s.trip_id=t.id) student_count
             FROM {$trip_areas} ta INNER JOIN {$trips} t ON t.id=ta.trip_id
             LEFT JOIN {$members} s ON s.trip_id=t.id AND s.major_area_id=ta.major_area_id
             LEFT JOIN {$buses} b ON b.id=t.bus_id
             WHERE t.academic_year_id=%d AND t.direction=%s AND t.status IN ('draft','published')
             GROUP BY ta.major_area_id,t.id ORDER BY t.id",
            absint($academic_year_id),
            sanitize_key($direction)
        ), ARRAY_A);
        $by_area = array();
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['planning_limit'] = (int) $row['planning_limit'];
            $row['bus_capacity'] = (int) $row['bus_capacity'];
            $row['area_student_count'] = (int) $row['area_student_count'];
            $row['student_count'] = (int) $row['student_count'];
            $row['trip_excess'] = max(0, $row['student_count'] - $row['planning_limit']);
            $row['bus_excess'] = $row['bus_capacity'] ? max(0, $row['student_count'] - $row['bus_capacity']) : 0;
            $by_area[(int) $row['major_area_id']][] = $row;
        }
        return $by_area;
    }

    public static function create($data)
    {
        global $wpdb;
        $year = absint($data['academic_year_id'] ?? 0);
        $direction = sanitize_key($data['direction'] ?? '');
        if (!$year || !in_array($direction, array('morning', 'afternoon'), true)) {
            return new WP_Error('invalid_shared_trip', __('Academic year and direction are required.', 'olama-transportation'), array('status' => 400));
        }
        $table = Olama_Transportation_DB::table('shared_trips');
        $sequence = 1 + (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE academic_year_id=%d AND direction=%s",
            $year,
            $direction
        ));
        $now = current_time('mysql', true);
        $record = array(
            'academic_year_id' => $year,
            'direction' => $direction,
            'name' => sanitize_text_field($data['name'] ?? sprintf(__('Trip %d', 'olama-transportation'), $sequence)),
            'planning_limit' => max(1, absint($data['planning_limit'] ?? self::DEFAULT_PLANNING_LIMIT)),
            'status' => 'draft',
            'created_by' => get_current_user_id() ?: null,
            'updated_by' => get_current_user_id() ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        );
        if (!$wpdb->insert($table, $record)) {
            return new WP_Error('shared_trip_create_failed', $wpdb->last_error ?: __('Could not create the trip draft.', 'olama-transportation'), array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;
        Olama_Transportation_Audit::record('shared_trip_created', 'shared_trip', $id, null, $record);
        return self::get($id);
    }

    public static function get($id)
    {
        global $wpdb;
        $trips = Olama_Transportation_DB::table('shared_trips');
        $buses = Olama_Transportation_DB::table('buses');
        $trip = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*,b.bus_number,b.driver_user_id,
                    COALESCE(NULLIF(b.planning_capacity,0),b.passenger_capacity,0) bus_capacity,
                    COALESCE(NULLIF(driver.display_name,''),NULLIF(b.driver_source_name,''),'') driver_name,
                    COALESCE(NULLIF(companion.display_name,''),'') companion_name
             FROM {$trips} t LEFT JOIN {$buses} b ON b.id=t.bus_id
             LEFT JOIN {$wpdb->users} driver ON driver.ID=b.driver_user_id
             LEFT JOIN {$wpdb->users} companion ON companion.ID=t.companion_user_id
             WHERE t.id=%d",
            absint($id)
        ), ARRAY_A);
        if (!$trip) {
            return null;
        }
        $trip = self::normalize_trip($trip);
        $trip['areas'] = $wpdb->get_results($wpdb->prepare(
            'SELECT a.id,a.name,a.code,a.color FROM ' . Olama_Transportation_DB::table('shared_trip_areas') . ' ta INNER JOIN ' . Olama_Transportation_DB::table('major_areas') . ' a ON a.id=ta.major_area_id WHERE ta.trip_id=%d ORDER BY a.name',
            $trip['id']
        ), ARRAY_A);
        $trip['area_ids'] = array_map('intval', array_column($trip['areas'], 'id'));
        $trip['students'] = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Olama_Transportation_DB::table('shared_trip_students') . ' WHERE trip_id=%d ORDER BY major_area_id,grade_name,section_name,student_name',
            $trip['id']
        ), ARRAY_A);
        $trip['queue'] = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Olama_Transportation_DB::table('shared_trip_queue') . ' WHERE trip_id=%d ORDER BY queue_position,id',
            $trip['id']
        ), ARRAY_A);
        $dual_by_family = array();
        if ($trip['queue']) {
            $dual_rows = $wpdb->get_results('SELECT family_uid,location_mode FROM ' . Olama_Transportation_DB::table('family_stops') . " WHERE location_mode='two_locations' AND family_uid IS NOT NULL", ARRAY_A);
            foreach ($dual_rows as $dual_row) $dual_by_family[(string)$dual_row['family_uid']] = true;
        }
        $student_names_by_family = array();
        foreach ($trip['students'] as $student) {
            $family_uid = (string) $student['family_uid'];
            $student_names_by_family[$family_uid][] = (string) $student['student_name'];
        }
        foreach ($trip['queue'] as &$node) {
            $node['dual_location'] = $node['node_type'] === 'family' && !empty($dual_by_family[(string)$node['family_uid']]);
            $node['student_names'] = $node['node_type'] === 'family'
                ? array_values(array_unique($student_names_by_family[(string) $node['family_uid']] ?? array()))
                : array();
        }
        unset($node);
        $trip['student_count'] = count($trip['students']);
        $trip['family_count'] = count(array_unique(array_column($trip['students'], 'family_uid')));
        $trip['area_count'] = count($trip['areas']);
        $trip['trip_excess'] = max(0, $trip['student_count'] - $trip['planning_limit']);
        $trip['bus_excess'] = $trip['bus_capacity'] ? max(0, $trip['student_count'] - $trip['bus_capacity']) : 0;
        $trip['missing_location_count'] = count(array_filter($trip['queue'], static function ($node) {
            return $node['node_type'] === 'family' && $node['location_status'] !== 'valid';
        }));
        return $trip;
    }

    public static function candidates($id, $area_id)
    {
        global $wpdb;
        $trip = self::get($id);
        if (!$trip) {
            return new WP_Error('shared_trip_not_found', __('Trip draft was not found.', 'olama-transportation'), array('status' => 404));
        }
        $area_id = absint($area_id);
        $study_year = preg_replace('/\s*([\/-])\s*/', '$1', Olama_Transportation_Bus::study_year($trip['academic_year_id']));
        $alternate_year = strpos($study_year, '/') !== false ? str_replace('/', '-', $study_year) : str_replace('-', '/', $study_year);
        $students = olama_core()->read_models()->table('students');
        $student_years = olama_core()->read_models()->table('student_years');
        $families = olama_core()->read_models()->table('families');
        $stops = Olama_Transportation_DB::table('family_stops');
        $members = Olama_Transportation_DB::table('shared_trip_students');
        $trips = Olama_Transportation_DB::table('shared_trips');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.id student_id,s.student_uid,s.oracle_student_id,s.student_name,sy.class_name grade_name,sy.section_name,
                    f.family_uid,f.oracle_family_id,COALESCE(NULLIF(f.sponsor_full_name,''),NULLIF(f.father_name,''),f.oracle_family_id) family_name,
                    IF(current_member.id IS NULL,0,1) selected,
                    (SELECT other_trip.id FROM {$members} other_member
                     INNER JOIN {$trips} other_trip ON other_trip.id=other_member.trip_id
                     WHERE other_member.student_uid=s.student_uid AND other_trip.id<>%d
                       AND other_trip.academic_year_id=%d AND other_trip.direction=%s
                       AND other_trip.status IN ('draft','published') ORDER BY other_trip.id LIMIT 1) assigned_trip_id,
                    (SELECT other_trip.name FROM {$members} other_member
                     INNER JOIN {$trips} other_trip ON other_trip.id=other_member.trip_id
                     WHERE other_member.student_uid=s.student_uid AND other_trip.id<>%d
                       AND other_trip.academic_year_id=%d AND other_trip.direction=%s
                       AND other_trip.status IN ('draft','published') ORDER BY other_trip.id LIMIT 1) assigned_trip_name
             FROM {$student_years} sy INNER JOIN {$students} s ON s.student_uid=sy.student_uid
             INNER JOIN {$families} f ON f.family_uid=sy.family_uid
             INNER JOIN {$stops} fs ON fs.family_uid=f.family_uid OR (fs.family_uid IS NULL AND fs.oracle_family_id=f.oracle_family_id)
             LEFT JOIN {$members} current_member ON current_member.trip_id=%d AND current_member.student_uid=s.student_uid
             WHERE sy.study_year IN (%s,%s) AND fs.major_area_id=%d
             ORDER BY f.oracle_family_id, s.student_name, sy.class_name, sy.section_name",
            $trip['id'],
            $trip['academic_year_id'],
            $trip['direction'],
            $trip['id'],
            $trip['academic_year_id'],
            $trip['direction'],
            $trip['id'],
            $study_year,
            $alternate_year,
            $area_id
        ), ARRAY_A);
        $transport_students = self::transportation_students_for_candidates($study_year, $rows);
        foreach ($rows as &$row) {
            $row['student_id'] = (int) $row['student_id'];
            $row['selected'] = (bool) $row['selected'];
            $row['subscribed'] = isset($transport_students[(string) $row['student_uid']]);
            // Existing data may contain a student who is no longer subscribed.
            // Do not present that stale membership as selected.
            if (!$row['subscribed']) $row['selected'] = false;
            $row['assigned_elsewhere'] = !empty($row['assigned_trip_id']);
        }
        unset($row);
        return array('trip' => $trip, 'area_id' => $area_id, 'students' => $rows);
    }

    /** Return active transportation records keyed by student UID, not family UID. */
    private static function transportation_students_for_candidates($study_year, array $rows)
    {
        global $wpdb;
        $result = array();
        $family_uids = array_values(array_unique(array_filter(array_column($rows, 'family_uid'))));
        if (!$family_uids) return $result;

        $transportation = $wpdb->prefix . 'olama_core_student_transportation';
        $student_years = $wpdb->prefix . 'olama_core_student_years';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $transportation)) === $transportation;
        if ($table_exists) {
            $alternate = strpos($study_year, '/') !== false ? str_replace('/', '-', $study_year) : str_replace('-', '/', $study_year);
            $placeholders = implode(',', array_fill(0, count($family_uids), '%s'));
            $records = $wpdb->get_results($wpdb->prepare(
                "SELECT tr.family_uid,tr.student_uid,tr.oracle_student_id FROM {$transportation} tr
                 WHERE tr.study_year IN (%s,%s) AND (tr.is_active IS NULL OR tr.is_active=1)
                   AND tr.family_uid IN ({$placeholders})",
                array_merge(array($study_year, $alternate), $family_uids)
            ), ARRAY_A);
            foreach ((array) $records as $record) {
                $uid = (string) ($record['student_uid'] ?: $record['oracle_student_id']);
                if ($uid !== '') $result[$uid] = true;
            }
            return $result;
        }

        // Compatibility fallback for older Core installations without the mirror table.
        if (function_exists('olama_core')) foreach ($rows as $row) {
            foreach ((array) olama_core()->transportation()->get_family((string) $row['oracle_family_id'], $study_year) as $record) {
                if (isset($record['is_active']) && $record['is_active'] !== null && (int) $record['is_active'] !== 1) continue;
                $uid = (string) ($record['student_uid'] ?? ($record['oracle_student_id'] ?? ''));
                if ($uid !== '') $result[$uid] = true;
            }
        }
        return $result;
    }

    public static function save_area_students($id, $area_id, $student_uids)
    {
        global $wpdb;
        $trip = self::editable($id);
        if (is_wp_error($trip)) {
            return $trip;
        }
        $candidate_data = self::candidates($id, $area_id);
        if (is_wp_error($candidate_data)) {
            return $candidate_data;
        }
        $eligible = array();
        foreach ($candidate_data['students'] as $student) {
            if (!$student['assigned_elsewhere'] && $student['subscribed']) {
                $eligible[$student['student_uid']] = $student;
            }
        }
        $student_uids = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $student_uids))));
        foreach ($student_uids as $uid) {
            if (!isset($eligible[$uid])) {
                return new WP_Error('invalid_shared_trip_student', __('A selected student is unavailable or already belongs to another trip.', 'olama-transportation'), array('status' => 409));
            }
        }
        // A family must travel together: selecting one sibling requires every
        // transportation-subscribed sibling in this area to join this trip too.
        $selected_lookup = array_fill_keys($student_uids, true);
        $selected_families = array();
        foreach ($student_uids as $uid) $selected_families[(string) $eligible[$uid]['family_uid']] = true;
        foreach ($candidate_data['students'] as $student) {
            if (!$student['subscribed']) continue;
            $family = (string) $student['family_uid'];
            if (isset($selected_families[$family]) && (!isset($selected_lookup[$student['student_uid']]) || $student['assigned_elsewhere'])) {
                return new WP_Error('shared_trip_family_incomplete', __('All transportation students in the same family must be assigned to the same trip.', 'olama-transportation'), array('status' => 409));
            }
        }
        $table = Olama_Transportation_DB::table('shared_trip_students');
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . Olama_Transportation_DB::table('shared_trip_areas') . ' (trip_id,major_area_id,created_at) VALUES (%d,%d,%s)',
            $trip['id'], absint($area_id), $now
        ));
        if ($wpdb->delete($table, array('trip_id' => $trip['id'], 'major_area_id' => absint($area_id))) === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('shared_trip_students_failed', $wpdb->last_error, array('status' => 500));
        }
        foreach ($student_uids as $uid) {
            $student = $eligible[$uid];
            $saved = $wpdb->insert($table, array(
                'trip_id' => $trip['id'], 'student_id' => $student['student_id'], 'student_uid' => $uid,
                'oracle_student_id' => $student['oracle_student_id'], 'student_name' => $student['student_name'],
                'family_uid' => $student['family_uid'], 'oracle_family_id' => $student['oracle_family_id'],
                'major_area_id' => absint($area_id), 'grade_name' => $student['grade_name'],
                'section_name' => $student['section_name'], 'created_at' => $now,
            ));
            if (!$saved) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('shared_trip_students_failed', $wpdb->last_error ?: __('Could not save the selected students.', 'olama-transportation'), array('status' => 500));
            }
        }
        $wpdb->delete(Olama_Transportation_DB::table('shared_trip_queue'), array('trip_id' => $trip['id']));
        $trip_update = array(
            'trip_limit_acknowledged' => 0, 'bus_limit_acknowledged' => 0,
            'updated_by' => get_current_user_id() ?: null, 'updated_at' => $now,
        );
        $wpdb->update(Olama_Transportation_DB::table('shared_trips'), $trip_update, array('id' => $trip['id']));
        $wpdb->query('COMMIT');
        Olama_Transportation_Audit::record('shared_trip_students_changed', 'shared_trip', $trip['id'], null, array('area_id' => absint($area_id), 'student_count' => count($student_uids)));
        return self::get($trip['id']);
    }

    public static function save_areas($id, $area_ids)
    {
        global $wpdb;
        $trip = self::editable($id);
        if (is_wp_error($trip)) return $trip;
        $area_ids = array_values(array_unique(array_filter(array_map('absint', (array) $area_ids))));
        if ($area_ids) {
            $placeholders = implode(',', array_fill(0, count($area_ids), '%d'));
            $valid = $wpdb->get_col($wpdb->prepare(
                'SELECT id FROM ' . Olama_Transportation_DB::table('major_areas') . " WHERE status='active' AND id IN ({$placeholders})",
                $area_ids
            ));
            if (count($valid) !== count($area_ids)) {
                return new WP_Error('invalid_shared_trip_area', __('One or more selected areas are unavailable.', 'olama-transportation'), array('status' => 400));
            }
        }
        $links = Olama_Transportation_DB::table('shared_trip_areas');
        $students = Olama_Transportation_DB::table('shared_trip_students');
        $queue = Olama_Transportation_DB::table('shared_trip_queue');
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        if ($area_ids) {
            $placeholders = implode(',', array_fill(0, count($area_ids), '%d'));
            $params = array_merge(array($trip['id']), $area_ids);
            $wpdb->query($wpdb->prepare("DELETE FROM {$students} WHERE trip_id=%d AND major_area_id NOT IN ({$placeholders})", $params));
            $wpdb->query($wpdb->prepare("DELETE FROM {$links} WHERE trip_id=%d AND major_area_id NOT IN ({$placeholders})", $params));
        } else {
            $wpdb->delete($students, array('trip_id' => $trip['id']));
            $wpdb->delete($links, array('trip_id' => $trip['id']));
        }
        foreach ($area_ids as $area_id) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$links} (trip_id,major_area_id,created_at) VALUES (%d,%d,%s)", $trip['id'], $area_id, $now));
        }
        $wpdb->delete($queue, array('trip_id' => $trip['id']));
        $wpdb->update(Olama_Transportation_DB::table('shared_trips'), array('trip_limit_acknowledged'=>0,'bus_limit_acknowledged'=>0,'updated_by'=>get_current_user_id()?:null,'updated_at'=>$now), array('id'=>$trip['id']));
        $wpdb->query('COMMIT');
        Olama_Transportation_Audit::record('shared_trip_areas_changed', 'shared_trip', $trip['id'], null, array('area_ids'=>$area_ids));
        return self::get($trip['id']);
    }

    public static function update($id, $data)
    {
        global $wpdb;
        $trip = self::editable($id);
        if (is_wp_error($trip)) {
            return $trip;
        }
        $record = array('updated_by' => get_current_user_id() ?: null, 'updated_at' => current_time('mysql', true));
        if (array_key_exists('name', $data)) $record['name'] = sanitize_text_field($data['name']);
        if (array_key_exists('planning_limit', $data)) {
            $record['planning_limit'] = max(1, absint($data['planning_limit']));
            $record['trip_limit_acknowledged'] = 0;
        }
        if (array_key_exists('arrival_time', $data)) $record['arrival_time'] = self::time($data['arrival_time']);
        if (array_key_exists('departure_time', $data)) $record['departure_time'] = self::time($data['departure_time']);
        if (array_key_exists('companion_user_id', $data)) {
            $companion_id = absint($data['companion_user_id']);
            if ($companion_id) {
                $eligible = array_map(static function ($user) { return (int) $user->ID; }, Olama_Transportation_Bus::get_available_companions());
                if (!in_array($companion_id, $eligible, true)) {
                    return new WP_Error('ineligible_companion', __('Select an active eligible companion.', 'olama-transportation'), array('status' => 400));
                }
            }
            $record['companion_user_id'] = $companion_id ?: null;
        }
        if (array_key_exists('bus_id', $data) || array_key_exists('bus_trip_number', $data)) {
            $bus_id = absint($data['bus_id'] ?? $trip['bus_id']);
            $slot = absint($data['bus_trip_number'] ?? $trip['bus_trip_number']);
            if ($bus_id) {
                $valid = self::validate_bus_slot($trip, $bus_id, $slot);
                if (is_wp_error($valid)) return $valid;
                $record['bus_id'] = $bus_id;
                $record['bus_trip_number'] = $slot;
            } else {
                $record['bus_id'] = null;
                $record['bus_trip_number'] = null;
            }
            $record['bus_limit_acknowledged'] = 0;
        }
        // Explicit acknowledgements are applied after fields that invalidate them.
        if (array_key_exists('trip_limit_acknowledged', $data)) $record['trip_limit_acknowledged'] = empty($data['trip_limit_acknowledged']) ? 0 : 1;
        if (array_key_exists('bus_limit_acknowledged', $data)) $record['bus_limit_acknowledged'] = empty($data['bus_limit_acknowledged']) ? 0 : 1;
        if ($wpdb->update(Olama_Transportation_DB::table('shared_trips'), $record, array('id' => $trip['id'])) === false) {
            return new WP_Error('shared_trip_update_failed', $wpdb->last_error ?: __('Could not update the trip draft.', 'olama-transportation'), array('status' => 500));
        }
        return self::get($trip['id']);
    }

    public static function build_queue($id)
    {
        global $wpdb;
        $trip = self::get($id);
        if (!$trip) return new WP_Error('shared_trip_not_found', __('Trip was not found.', 'olama-transportation'), array('status' => 404));
        $members = Olama_Transportation_DB::table('shared_trip_students');
        $families = olama_core()->read_models()->table('families');
        $stops = Olama_Transportation_DB::table('family_stops');
        $families_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.family_uid,MAX(s.oracle_family_id) oracle_family_id,COUNT(DISTINCT s.student_uid) student_count,
                    COALESCE(NULLIF(f.sponsor_full_name,''),NULLIF(f.father_name,''),MAX(s.oracle_family_id)) family_name,
                    ".($trip['direction'] === 'morning' ? 'COALESCE(fs.arrival_latitude,fs.latitude)' : 'COALESCE(fs.departure_latitude,fs.latitude)')." latitude,
                    ".($trip['direction'] === 'morning' ? 'COALESCE(fs.arrival_longitude,fs.longitude)' : 'COALESCE(fs.departure_longitude,fs.longitude)')." longitude,
                    fs.verification_status
             FROM {$members} s INNER JOIN {$families} f ON f.family_uid=s.family_uid
             LEFT JOIN {$stops} fs ON fs.family_uid=f.family_uid OR (fs.family_uid IS NULL AND fs.oracle_family_id=f.oracle_family_id)
             WHERE s.trip_id=%d GROUP BY s.family_uid,fs.id,fs.latitude,fs.longitude,fs.arrival_latitude,fs.arrival_longitude,fs.departure_latitude,fs.departure_longitude ORDER BY MIN(s.major_area_id),family_name",
            $trip['id']
        ), ARRAY_A);
        if (!$families_rows) return new WP_Error('empty_shared_trip', __('Select students before building the family queue.', 'olama-transportation'), array('status' => 400));
        $table = Olama_Transportation_DB::table('shared_trip_queue');
        $existing = $wpdb->get_results($wpdb->prepare("SELECT node_key,queue_position FROM {$table} WHERE trip_id=%d", $trip['id']), OBJECT_K);
        usort($families_rows, static function ($a, $b) use ($existing) {
            $a_position = isset($existing['family:' . $a['family_uid']]) ? (int) $existing['family:' . $a['family_uid']]->queue_position : PHP_INT_MAX;
            $b_position = isset($existing['family:' . $b['family_uid']]) ? (int) $existing['family:' . $b['family_uid']]->queue_position : PHP_INT_MAX;
            return $a_position === $b_position ? strnatcasecmp($a['family_name'], $b['family_name']) : $a_position - $b_position;
        });
        $settings = get_option('olama_transportation_settings', array());
        $school = $settings['school_location'] ?? array('latitude' => 31.9539, 'longitude' => 35.9106);
        $nodes = array();
        $school_node = array('node_key' => 'school', 'node_type' => 'school', 'family_uid' => null, 'family_name' => __('School', 'olama-transportation'), 'oracle_family_id' => null, 'student_count' => 0, 'latitude' => (float) $school['latitude'], 'longitude' => (float) $school['longitude'], 'location_status' => 'valid');
        if ($trip['direction'] === 'afternoon') $nodes[] = $school_node;
        foreach ($families_rows as $family) {
            $valid = $family['latitude'] !== null && $family['longitude'] !== null
                && in_array($family['verification_status'], array('approved', 'needs_review'), true)
                && Olama_Transportation_Family_Locations::within_service_bounds((float) $family['latitude'], (float) $family['longitude']);
            $nodes[] = array(
                'node_key' => 'family:' . $family['family_uid'], 'node_type' => 'family', 'family_uid' => $family['family_uid'],
                'family_name' => $family['family_name'], 'oracle_family_id' => $family['oracle_family_id'],
                'student_count' => (int) $family['student_count'], 'latitude' => $valid ? (float) $family['latitude'] : null,
                'longitude' => $valid ? (float) $family['longitude'] : null, 'location_status' => $valid ? 'valid' : 'missing_location',
            );
        }
        if ($trip['direction'] === 'morning') $nodes[] = $school_node;
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        $wpdb->delete($table, array('trip_id' => $trip['id']));
        foreach ($nodes as $index => $node) {
            $node['trip_id'] = $trip['id']; $node['queue_position'] = $index + 1; $node['created_at'] = $now; $node['updated_at'] = $now;
            if (!$wpdb->insert($table, $node)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('shared_trip_queue_failed', $wpdb->last_error ?: __('Could not build the family queue.', 'olama-transportation'), array('status' => 500));
            }
        }
        $wpdb->query('COMMIT');
        Olama_Transportation_Audit::record('shared_trip_queue_built', 'shared_trip', $trip['id'], null, array('node_count' => count($nodes)));
        return self::get($trip['id']);
    }

    public static function publish($id)
    {
        global $wpdb;
        $trip = self::editable($id);
        if (is_wp_error($trip)) return $trip;
        $trip = self::get($trip['id']);
        if (!$trip['student_count']) return new WP_Error('empty_shared_trip', __('Select at least one student.', 'olama-transportation'), array('status' => 400));
        if (!$trip['companion_user_id']) return new WP_Error('missing_trip_companion', __('Select a companion before publishing the trip.', 'olama-transportation'), array('status' => 400));
        $slot = self::validate_bus_slot($trip, $trip['bus_id'], $trip['bus_trip_number']);
        if (is_wp_error($slot)) return $slot;
        if ($trip['trip_excess'] && !$trip['trip_limit_acknowledged']) return new WP_Error('trip_limit_acknowledgement_required', __('Acknowledge the trip planning-limit warning before publishing.', 'olama-transportation'), array('status' => 409));
        if ($trip['bus_excess'] && !$trip['bus_limit_acknowledged']) return new WP_Error('bus_limit_acknowledgement_required', __('Acknowledge the bus-capacity warning before publishing.', 'olama-transportation'), array('status' => 409));
        $queue = self::build_queue($trip['id']);
        if (is_wp_error($queue)) return $queue;
        $assignments = $wpdb->prefix . 'olama_student_bus_assignments';
        $selected_uids = array_column($trip['students'], 'student_uid');
        $placeholders = implode(',', array_fill(0, count($selected_uids), '%s'));
        $params = array_merge(array($trip['academic_year_id'], $trip['direction'], $trip['id']), $selected_uids);
        $conflict = $wpdb->get_var($wpdb->prepare(
            "SELECT student_uid FROM {$assignments} WHERE academic_year_id=%d AND direction=%s
             AND (shared_trip_id IS NULL OR shared_trip_id<>%d) AND student_uid IN ({$placeholders}) LIMIT 1",
            $params
        ));
        if ($conflict) return new WP_Error('published_student_conflict', __('A selected student already has another live bus assignment.', 'olama-transportation'), array('status' => 409, 'student_uid' => $conflict));
        $now = current_time('mysql');
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare("DELETE FROM {$assignments} WHERE shared_trip_id=%d", $trip['id']));
        foreach ($trip['students'] as $student) {
            $saved = $wpdb->insert($assignments, array(
                'student_id' => (int) $student['student_id'], 'student_uid' => $student['student_uid'],
                'bus_id' => $trip['bus_id'], 'academic_year_id' => $trip['academic_year_id'],
                'direction' => $trip['direction'], 'trip_number' => $trip['bus_trip_number'],
                'shared_trip_id' => $trip['id'], 'assigned_at' => $now, 'assigned_by' => get_current_user_id(),
            ));
            if (!$saved) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('shared_trip_publish_failed', $wpdb->last_error ?: __('Could not publish student assignments.', 'olama-transportation'), array('status' => 500));
            }
        }
        $published = array('status' => 'published', 'published_by' => get_current_user_id() ?: null, 'published_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true));
        if ($wpdb->update(Olama_Transportation_DB::table('shared_trips'), $published, array('id' => $trip['id'])) === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('shared_trip_publish_failed', $wpdb->last_error, array('status' => 500));
        }
        $wpdb->query('COMMIT');
        Olama_Transportation_Audit::record('shared_trip_published', 'shared_trip', $trip['id'], $trip, $published);
        return self::get($trip['id']);
    }

    /** Reopen a published trip for editing and withdraw its live assignments. */
    public static function return_to_draft($id)
    {
        global $wpdb;
        $trip = self::get($id);
        if (!$trip) {
            return new WP_Error('shared_trip_not_found', __('Trip was not found.', 'olama-transportation'), array('status' => 404));
        }
        if ($trip['status'] === 'draft') {
            return $trip;
        }
        if ($trip['status'] !== 'published') {
            return new WP_Error('shared_trip_not_reopenable', __('Only a published trip can be returned to draft.', 'olama-transportation'), array('status' => 409));
        }

        $assignments = $wpdb->prefix . 'olama_student_bus_assignments';
        $trips = Olama_Transportation_DB::table('shared_trips');
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        if ($wpdb->delete($assignments, array('shared_trip_id' => $trip['id'])) === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('shared_trip_reopen_failed', $wpdb->last_error ?: __('Could not withdraw the published assignments.', 'olama-transportation'), array('status' => 500));
        }
        $draft = array(
            'status' => 'draft',
            'published_by' => null,
            'published_at' => null,
            'trip_limit_acknowledged' => 0,
            'bus_limit_acknowledged' => 0,
            'updated_by' => get_current_user_id() ?: null,
            'updated_at' => $now,
        );
        if ($wpdb->update($trips, $draft, array('id' => $trip['id'])) === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('shared_trip_reopen_failed', $wpdb->last_error ?: __('Could not return the trip to draft.', 'olama-transportation'), array('status' => 500));
        }
        $wpdb->query('COMMIT');
        Olama_Transportation_Audit::record('shared_trip_returned_to_draft', 'shared_trip', $trip['id'], $trip, $draft);
        return self::get($trip['id']);
    }

    /** Permanently remove an unpublished draft and its dependent planning data. */
    public static function delete_draft($id)
    {
        global $wpdb;
        $trip = self::get($id);
        if (!$trip) {
            return new WP_Error('shared_trip_not_found', __('Trip was not found.', 'olama-transportation'), array('status' => 404));
        }
        if ($trip['status'] !== 'draft') {
            return new WP_Error('published_shared_trip_delete_forbidden', __('Published trips must be returned to draft before they can be deleted.', 'olama-transportation'), array('status' => 409));
        }

        $trip_id = (int) $trip['id'];
        $tables = array(
            Olama_Transportation_DB::table('shared_trip_queue'),
            Olama_Transportation_DB::table('shared_trip_students'),
            Olama_Transportation_DB::table('shared_trip_areas'),
            $wpdb->prefix . 'olama_student_bus_assignments',
        );
        $wpdb->query('START TRANSACTION');
        foreach ($tables as $table) {
            $column = $table === $wpdb->prefix . 'olama_student_bus_assignments' ? 'shared_trip_id' : 'trip_id';
            if ($wpdb->delete($table, array($column => $trip_id)) === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('shared_trip_delete_failed', $wpdb->last_error ?: __('Could not remove the trip draft.', 'olama-transportation'), array('status' => 500));
            }
        }
        if ($wpdb->delete(Olama_Transportation_DB::table('shared_trips'), array('id' => $trip_id)) === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('shared_trip_delete_failed', $wpdb->last_error ?: __('Could not remove the trip draft.', 'olama-transportation'), array('status' => 500));
        }
        $wpdb->query('COMMIT');
        Olama_Transportation_Audit::record('shared_trip_draft_deleted', 'shared_trip', $trip_id, $trip, null);
        return array('deleted' => true, 'id' => $trip_id);
    }

    /** Build the private, print-only CR80 badge projection for a trip. */
    public static function badges($id)
    {
        global $wpdb;
        $trip = self::get($id);
        if (!$trip) return new WP_Error('shared_trip_not_found', __('Trip was not found.', 'olama-transportation'), array('status' => 404));

        $families = olama_core()->read_models()->table('families');
        $student_years = olama_core()->read_models()->table('student_years');
        $students = olama_core()->read_models()->table('students');
        $stops = Olama_Transportation_DB::table('family_stops');
        $areas = Olama_Transportation_DB::table('major_areas');
        $members = Olama_Transportation_DB::table('shared_trip_students');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*,f.father_name,f.mother_name,f.father_mobile,f.mother_mobile,
                    COALESCE(NULLIF(fs.address_text,''),NULLIF(f.family_address,''),f.address,'') full_address,
                    fs.latitude,fs.longitude,a.name area_name
             FROM {$members} m INNER JOIN {$families} f ON f.family_uid=m.family_uid
             LEFT JOIN {$stops} fs ON fs.id=(SELECT preferred_stop.id FROM {$stops} preferred_stop
                 WHERE preferred_stop.family_uid=f.family_uid OR (preferred_stop.family_uid IS NULL AND preferred_stop.oracle_family_id=f.oracle_family_id)
                 ORDER BY preferred_stop.family_uid IS NULL,preferred_stop.id LIMIT 1)
             LEFT JOIN {$areas} a ON a.id=m.major_area_id WHERE m.trip_id=%d
             ORDER BY m.grade_name,m.section_name,m.student_name",
            $trip['id']
        ), ARRAY_A);
        $study_year = preg_replace('/\s*([\/-])\s*/', '$1', Olama_Transportation_Bus::study_year($trip['academic_year_id']));
        $alternate_year = strpos($study_year, '/') !== false ? str_replace('/', '-', $study_year) : str_replace('-', '/', $study_year);
        $sibling_cache = array();
        foreach ($rows as &$row) {
            $family_uid = (string) $row['family_uid'];
            if (!isset($sibling_cache[$family_uid])) {
                $sibling_cache[$family_uid] = $wpdb->get_results($wpdb->prepare(
                    "SELECT s.student_uid,s.student_name,sy.class_name grade_name,sy.section_name
                     FROM {$student_years} sy INNER JOIN {$students} s ON s.student_uid=sy.student_uid
                     WHERE sy.family_uid=%s AND sy.study_year IN (%s,%s) ORDER BY sy.class_name,sy.section_name,s.student_name",
                    $family_uid, $study_year, $alternate_year
                ), ARRAY_A);
            }
            $row['siblings'] = array_values(array_filter($sibling_cache[$family_uid], static function ($sibling) use ($row) {
                return (string) $sibling['student_uid'] !== (string) $row['student_uid'];
            }));
            $row['maps_url'] = ($row['latitude'] !== null && $row['longitude'] !== null)
                ? 'https://www.google.com/maps?q=' . rawurlencode($row['latitude'] . ',' . $row['longitude']) : '';
        }
        unset($row);
        return array(
            'trip' => array(
                'id'=>$trip['id'],'name'=>$trip['name'],'direction'=>$trip['direction'],'bus_number'=>$trip['bus_number'],
                'bus_trip_number'=>$trip['bus_trip_number'],'driver_name'=>$trip['driver_name'],
                'companion_name'=>$trip['companion_name'],'companion_phone'=>self::staff_phone($trip['companion_user_id']),
            ),
            'students' => $rows,
        );
    }

    /** Return the school-level transportation report, optionally filtered by grade and section. */
    public static function school_report($academic_year_id, $direction, $grade = '', $section = '', $area_id = '', $trip_id = '', $transport_status = 'all')
    {
        global $wpdb;
        $year = absint($academic_year_id);
        $direction = sanitize_key($direction);
        if (!$year || !in_array($direction, array('all', 'morning', 'afternoon'), true)) return array('filters'=>array(), 'areas'=>array(), 'trips'=>array(), 'rows'=>array());

        $study_year = preg_replace('/\s*([\/\-])\s*/', '$1', Olama_Transportation_Bus::study_year($year));
        $alternate_year = strpos($study_year, '/') !== false ? str_replace('/', '-', $study_year) : str_replace('-', '/', $study_year);
        $members = Olama_Transportation_DB::table('shared_trip_students');
        $trips = Olama_Transportation_DB::table('shared_trips');
        $buses = Olama_Transportation_DB::table('buses');
        $families = olama_core()->read_models()->table('families');
        $student_years = olama_core()->read_models()->table('student_years');
        $students = olama_core()->read_models()->table('students');
        $stops = Olama_Transportation_DB::table('family_stops');
        $trip_scope = "current_trip.academic_year_id=%d AND current_trip.status IN ('draft','published')";
        $trip_join = "t.academic_year_id=%d AND t.status IN ('draft','published')";
        $trip_params = array($year);
        if ($direction !== 'all') { $trip_scope .= ' AND current_trip.direction=%s'; $trip_join .= ' AND t.direction=%s'; $trip_params[] = $direction; }
        $where = 'sy.study_year IN (%s,%s)';
        $params = array($study_year, $alternate_year);
        if ($grade !== '') { $where .= ' AND sy.class_name=%s'; $params[] = sanitize_text_field($grade); }
        if ($section !== '') { $where .= ' AND sy.section_name=%s'; $params[] = sanitize_text_field($section); }
        if ($area_id !== '') { $where .= ' AND m.major_area_id=%d'; $params[] = absint($area_id); }
        if ($trip_id !== '') { $where .= ' AND t.id=%d'; $params[] = absint($trip_id); }
        if ($transport_status === 'with') { $where .= ' AND t.id IS NOT NULL'; }
        if ($transport_status === 'without') { $where .= ' AND t.id IS NULL'; }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT sy.student_uid,COALESCE(NULLIF(s.student_name,''),sy.student_uid) student_name,
                    f.oracle_family_id,
                    sy.class_name grade_name,sy.section_name,f.father_mobile,f.mother_mobile,
                    COALESCE(NULLIF(f.family_address,''),NULLIF(f.address,''),'') oracle_address,
                    COALESCE(NULLIF(trip_area.name,''),family_area.name) planning_area,t.id trip_id,t.name trip_name,COALESCE(NULLIF(driver.display_name,''),NULLIF(b.driver_source_name,''),'') driver_name,
                    b.bus_number,t.bus_trip_number,sy.family_uid,
                    CASE WHEN fs.maps_url IS NOT NULL AND fs.maps_url<>'' THEN fs.maps_url WHEN fs.latitude IS NOT NULL AND fs.longitude IS NOT NULL THEN CONCAT('https://www.google.com/maps?q=',fs.latitude,',',fs.longitude) ELSE '' END maps_url,
                    CASE WHEN t.id IS NULL THEN 'without' ELSE 'with' END transport_status
             FROM {$student_years} sy
             LEFT JOIN {$members} m ON m.student_uid=sy.student_uid AND m.family_uid=sy.family_uid
                 AND m.trip_id IN (SELECT current_trip.id FROM {$trips} current_trip WHERE {$trip_scope})
             LEFT JOIN {$trips} t ON t.id=m.trip_id AND {$trip_join}
             LEFT JOIN {$buses} b ON b.id=t.bus_id
             LEFT JOIN {$wpdb->users} driver ON driver.ID=b.driver_user_id
             LEFT JOIN {$students} s ON s.student_uid=sy.student_uid
             LEFT JOIN {$families} f ON f.family_uid=sy.family_uid
             LEFT JOIN {$stops} fs ON fs.family_uid=sy.family_uid
             LEFT JOIN {$wpdb->prefix}olama_transport_major_areas trip_area ON trip_area.id=m.major_area_id
             LEFT JOIN {$wpdb->prefix}olama_transport_major_areas family_area ON family_area.id=fs.major_area_id
             WHERE {$where}
             ORDER BY f.oracle_family_id,student_name,sy.class_name,sy.section_name",
            ...array_merge($trip_params, $trip_params, $params)
        ), ARRAY_A);
        $filter_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT sy.class_name grade_name,sy.section_name
             FROM {$student_years} sy
             WHERE sy.study_year IN (%s,%s)
             ORDER BY sy.class_name,sy.section_name",
            $study_year, $alternate_year
        ), ARRAY_A);
        $area_rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT a.id,a.name FROM {$members} m INNER JOIN {$trips} t ON t.id=m.trip_id LEFT JOIN {$wpdb->prefix}olama_transport_major_areas a ON a.id=m.major_area_id WHERE t.academic_year_id=%d AND t.direction=%s AND t.status IN ('draft','published') AND a.id IS NOT NULL ORDER BY a.name", $year, $direction), ARRAY_A);
        $trip_rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT t.id,t.name FROM {$trips} t WHERE t.academic_year_id=%d AND t.direction=%s AND t.status IN ('draft','published') ORDER BY t.name,t.id", $year, $direction), ARRAY_A);
        return array('filters'=>$filter_rows, 'areas'=>$area_rows, 'trips'=>$trip_rows, 'rows'=>$rows);
    }

    public static function family_report($academic_year_id, $search)
    {
        global $wpdb;
        $search = trim(sanitize_text_field($search));
        if ($search === '') return array('items'=>array(), 'total'=>0);
        $study_year = preg_replace('/\s*([\/\-])\s*/', '$1', Olama_Transportation_Bus::study_year(absint($academic_year_id)));
        $alternate_year = strpos($study_year, '/') !== false ? str_replace('/', '-', $study_year) : str_replace('-', '/', $study_year);
        $families = Olama_Transportation_Family_Locations::admin_list(absint($academic_year_id), array('export_all'=>true));
        $families['items'] = array_values(array_filter($families['items'], static function ($family) use ($search) {
            $haystack = (string)($family['oracle_family_id'] ?? '') . ' ' . (string)($family['family_name'] ?? '') . ' ' . (string)($family['father_mobile'] ?? '') . ' ' . (string)($family['mother_mobile'] ?? '');
            foreach (($family['students'] ?? array()) as $student) $haystack .= ' ' . ($student['student_name'] ?? '') . ' ' . ($student['class_name'] ?? '') . ' ' . ($student['section_name'] ?? '');
            return stripos($haystack, $search) !== false;
        }));
        $members = Olama_Transportation_DB::table('shared_trip_students'); $trips = Olama_Transportation_DB::table('shared_trips'); $buses = Olama_Transportation_DB::table('buses'); $students = olama_core()->read_models()->table('students'); $student_years = olama_core()->read_models()->table('student_years');
        foreach ($families['items'] as &$family) {
            $transport_rows = $wpdb->get_results($wpdb->prepare("SELECT m.student_uid,m.student_name,sy.class_name grade_name,sy.section_name,t.direction,t.name trip_name,b.bus_number,COALESCE(NULLIF(driver.display_name,''),NULLIF(b.driver_source_name,''),'') driver_name FROM {$members} m LEFT JOIN {$trips} t ON t.id=m.trip_id AND t.academic_year_id=%d AND t.status IN ('draft','published') LEFT JOIN {$buses} b ON b.id=t.bus_id LEFT JOIN {$wpdb->users} driver ON driver.ID=b.driver_user_id LEFT JOIN {$students} s ON s.student_uid=m.student_uid LEFT JOIN {$student_years} sy ON sy.student_uid=m.student_uid AND sy.family_uid=m.family_uid AND sy.study_year IN (%s,%s) WHERE m.family_uid=%s AND t.id IS NOT NULL ORDER BY sy.class_name,sy.section_name,m.student_name", absint($academic_year_id), $study_year, $alternate_year, $family['family_uid']), ARRAY_A);
            $grouped = array();
            foreach ($transport_rows as $row) {
                $key = (string) $row['student_uid'];
                if (!isset($grouped[$key])) {
                    $grouped[$key] = array('student_uid'=>$row['student_uid'],'student_name'=>$row['student_name'],'grade_name'=>$row['grade_name'],'section_name'=>$row['section_name'],'arrival'=>array(),'departure'=>array(),'legacy_directions'=>array(),'legacy_trips'=>array(),'legacy_drivers'=>array(),'legacy_buses'=>array());
                }
                $slot = $row['direction'] === 'morning' ? 'arrival' : 'departure';
                $grouped[$key][$slot] = array('trip_name'=>$row['trip_name'],'driver_name'=>$row['driver_name'],'bus_number'=>$row['bus_number']);
                $grouped[$key]['legacy_directions'][$slot] = $row['direction'] === 'morning' ? 'حضور' : 'عودة';
                if (!empty($row['trip_name'])) $grouped[$key]['legacy_trips'][$slot] = $row['trip_name'];
                if (!empty($row['driver_name'])) $grouped[$key]['legacy_drivers'][$slot] = $row['driver_name'];
                if (!empty($row['bus_number'])) $grouped[$key]['legacy_buses'][$slot] = $row['bus_number'];
            }
            foreach ($grouped as &$student) {
                // Keep cached copies of the previous report script useful while clients
                // receive the new one-row-per-student structure.
                $student['direction'] = implode(' / ', $student['legacy_directions']);
                $student['trip_name'] = implode(' / ', $student['legacy_trips']);
                $student['driver_name'] = implode(' / ', $student['legacy_drivers']);
                $student['bus_number'] = implode(' / ', $student['legacy_buses']);
                unset($student['legacy_directions'], $student['legacy_trips'], $student['legacy_drivers'], $student['legacy_buses']);
            }
            unset($student);
            $family['transport_rows'] = array_values($grouped);
        }
        unset($family); return array('items'=>$families['items'], 'total'=>(int)($families['pagination']['total'] ?? count($families['items'])));
    }

    public static function unassigned_report($academic_year_id)
    {
        global $wpdb;
        $year = absint($academic_year_id); $study = preg_replace('/\s*([\/\-])\s*/', '$1', Olama_Transportation_Bus::study_year($year)); $alternate = strpos($study, '/') !== false ? str_replace('/', '-', $study) : str_replace('-', '/', $study);
        $tr = $wpdb->prefix . 'olama_core_student_transportation'; $sy = olama_core()->read_models()->table('student_years'); $s = olama_core()->read_models()->table('students'); $f = olama_core()->read_models()->table('families'); $members = Olama_Transportation_DB::table('shared_trip_students'); $trips = Olama_Transportation_DB::table('shared_trips'); $stops = Olama_Transportation_DB::table('family_stops');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tr)) !== $tr) return array('rows'=>array());
        $areas = Olama_Transportation_DB::table('major_areas');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT tr.student_uid,COALESCE(NULLIF(s.student_name,''),tr.student_uid) student_name,sy.class_name grade_name,sy.section_name,f.oracle_family_id,f.father_name,f.father_mobile,f.mother_mobile,COALESCE(NULLIF(f.family_address,''),NULLIF(f.address,''),'') oracle_address,a.name planning_area,fs.latitude,fs.longitude,fs.maps_url FROM {$tr} tr LEFT JOIN {$sy} sy ON sy.student_uid=tr.student_uid AND sy.family_uid=tr.family_uid AND sy.study_year IN (%s,%s) LEFT JOIN {$s} s ON s.student_uid=tr.student_uid LEFT JOIN {$f} f ON f.family_uid=tr.family_uid LEFT JOIN {$stops} fs ON fs.family_uid=tr.family_uid LEFT JOIN {$areas} a ON a.id=fs.major_area_id WHERE tr.study_year IN (%s,%s) AND (tr.is_active IS NULL OR tr.is_active=1) AND NOT EXISTS (SELECT 1 FROM {$members} m INNER JOIN {$trips} t ON t.id=m.trip_id AND t.status IN ('draft','published') WHERE m.student_uid=tr.student_uid AND m.family_uid=tr.family_uid AND t.academic_year_id=%d) ORDER BY f.oracle_family_id,student_name,sy.class_name,sy.section_name", $study,$alternate,$study,$alternate,$year), ARRAY_A);
        foreach ($rows as &$row) { $row['maps_url'] = $row['maps_url'] ?: (($row['latitude'] !== null && $row['longitude'] !== null) ? 'https://www.google.com/maps?q=' . rawurlencode($row['latitude'] . ',' . $row['longitude']) : ''); } unset($row); return array('rows'=>$rows);
    }

    private static function staff_phone($user_id)
    {
        $user_id = absint($user_id);
        if (!$user_id) return '';
        if (function_exists('olama_core') && method_exists(olama_core(), 'staff')) {
            $profile = olama_core()->staff()->get($user_id);
            if ($profile && !empty($profile['phone_number'])) return sanitize_text_field((string) $profile['phone_number']);
        }
        foreach (array('phone_number','phone','mobile','billing_phone') as $key) {
            $value = sanitize_text_field((string) get_user_meta($user_id, $key, true));
            if ($value !== '') return $value;
        }
        return '';
    }

    private static function editable($id)
    {
        $trip = self::get($id);
        if (!$trip) return new WP_Error('shared_trip_not_found', __('Trip draft was not found.', 'olama-transportation'), array('status' => 404));
        if ($trip['status'] !== 'draft') return new WP_Error('shared_trip_locked', __('Published trips are locked.', 'olama-transportation'), array('status' => 409));
        return $trip;
    }

    private static function validate_bus_slot($trip, $bus_id, $slot)
    {
        global $wpdb;
        $trip_label = $trip['direction'] === 'morning'
            ? __('arrival trip (حضور)', 'olama-transportation')
            : __('departure trip (عودة)', 'olama-transportation');
        if (!$bus_id || !$slot) return new WP_Error('missing_bus_slot', sprintf(__('Select a bus and %s.', 'olama-transportation'), $trip_label), array('status' => 400));
        $bus = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Olama_Transportation_DB::table('buses') . ' WHERE id=%d AND status=%s', $bus_id, 'active'), ARRAY_A);
        $limit_field = $trip['direction'] === 'morning' ? 'morning_trip_count' : 'afternoon_trip_count';
        if (!$bus || $slot > (int) $bus[$limit_field]) return new WP_Error('invalid_bus_slot', sprintf(__('The selected bus does not provide that %s number.', 'olama-transportation'), $trip_label), array('status' => 400));
        $table = Olama_Transportation_DB::table('shared_trips');
        $other = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE academic_year_id=%d AND direction=%s AND bus_id=%d AND bus_trip_number=%d AND id<>%d AND status IN ('draft','published') LIMIT 1",
            $trip['academic_year_id'], $trip['direction'], $bus_id, $slot, $trip['id']
        ));
        return $other ? new WP_Error('bus_slot_occupied', sprintf(__('This bus %s is already assigned to another trip.', 'olama-transportation'), $trip_label), array('status' => 409)) : true;
    }

    private static function normalize_trip($row)
    {
        foreach (array('id','academic_year_id','planning_limit','bus_id','bus_trip_number','companion_user_id','driver_user_id','bus_capacity','student_count','family_count') as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        $row['area_ids'] = empty($row['area_ids']) ? array() : array_values(array_map('intval', explode(',', $row['area_ids'])));
        $row['trip_limit_acknowledged'] = !empty($row['trip_limit_acknowledged']);
        $row['bus_limit_acknowledged'] = !empty($row['bus_limit_acknowledged']);
        $row['trip_excess'] = max(0, $row['student_count'] - $row['planning_limit']);
        $row['bus_excess'] = $row['bus_capacity'] ? max(0, $row['student_count'] - $row['bus_capacity']) : 0;
        return $row;
    }

    private static function time($value)
    {
        $value = sanitize_text_field((string) $value);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d(?:\:[0-5]\d)?$/', $value) ? substr($value, 0, 5) . ':00' : null;
    }
}
