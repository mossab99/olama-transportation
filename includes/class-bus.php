<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Bus
{
    /**
     * Ask the configured Olama Oracle Sync adapter for the current fleet,
     * update the canonical Core mirror, and then refresh this plugin's local
     * planning projection.
     *
     * Transportation does not own Oracle credentials or make raw Oracle
     * connections. The installed synchronization adapter remains responsible
     * for the authenticated Bridge request and Core remains the canonical
     * master-data store.
     */
    public static function sync_from_source()
    {
        if (!current_user_can('olama_access_oracle_sync')) {
            return new WP_Error(
                'oracle_sync_forbidden',
                __('You do not have permission to synchronize Oracle transportation data.', 'olama-transportation'),
                array('status' => 403)
            );
        }

        if (!class_exists('Olama_Oracle_Api_Client') || !function_exists('olama_core') || !method_exists(olama_core(), 'transport_master')) {
            return new WP_Error(
                'oracle_sync_unavailable',
                __('Olama Oracle Sync is unavailable. Activate it before refreshing buses from the source.', 'olama-transportation'),
                array('status' => 503)
            );
        }

        $response = (new Olama_Oracle_Api_Client())->get_transportation_buses();
        if (empty($response['success'])) {
            return new WP_Error(
                'oracle_bus_refresh_failed',
                sprintf(
                    /* translators: %s is the Oracle Sync error message. */
                    __('Oracle bus synchronization failed: %s', 'olama-transportation'),
                    sanitize_text_field((string) ($response['message'] ?? __('Unknown error', 'olama-transportation')))
                ),
                array('status' => 502)
            );
        }

        $payload = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();
        $rows = isset($payload['buses']) && is_array($payload['buses']) ? $payload['buses'] : array();
        if (!$rows) {
            // An empty fleet is much more likely to be a Bridge/API regression
            // than a legitimate master-data deletion. Do not deactivate every
            // existing bus on an ambiguous response.
            return new WP_Error(
                'oracle_bus_refresh_empty',
                __('Oracle Sync returned no buses. The existing Core fleet was left unchanged.', 'olama-transportation'),
                array('status' => 502)
            );
        }

        $legacy_numbers = array();
        foreach ($rows as $row) {
            $operational_id = sanitize_text_field((string) ($row['bus_school_id'] ?? $row['BUS_SCHOOL_ID'] ?? ''));
            $legacy_number = sanitize_text_field((string) ($row['bus_number'] ?? $row['bus_school_num'] ?? ''));
            if ($operational_id !== '' && $legacy_number !== '' && $operational_id !== $legacy_number) {
                $legacy_numbers[$operational_id] = $legacy_number;
            }
        }

        try {
            $core_summary = olama_core()->transport_master()->replace_buses_from_source($rows);
        } catch (Throwable $exception) {
            return new WP_Error(
                'core_bus_refresh_failed',
                sprintf(
                    /* translators: %s is the Core update error message. */
                    __('Olama Core could not store the synchronized buses: %s', 'olama-transportation'),
                    sanitize_text_field($exception->getMessage())
                ),
                array('status' => 500)
            );
        }

        $projection_summary = self::refresh_from_core($legacy_numbers);
        if (is_wp_error($projection_summary)) {
            return $projection_summary;
        }

        $projection_summary['received'] = count($rows);
        $projection_summary['core'] = $core_summary;
        return $projection_summary;
    }

    /**
     * Refresh the local planning projection from Olama Core.
     *
     * Core owns all Oracle-derived fields. This table stores only the stable
     * local bus ID used by route plans plus Olama-only planning overrides.
     */
    public static function refresh_from_core($legacy_numbers = array())
    {
        global $wpdb;

        if (!function_exists('olama_core') || !method_exists(olama_core(), 'transport_master')) {
            return new WP_Error('core_unavailable', __('Olama Core transportation master service is unavailable.', 'olama-transportation'));
        }

        $table = "{$wpdb->prefix}olama_transport_buses";
        $rows = olama_core()->transport_master()->get_buses(false);
        $seen = array();
        $summary = array('created' => 0, 'updated' => 0, 'deactivated' => 0);

        foreach ($rows as $row) {
            $core_uid = sanitize_text_field((string) ($row['bus_uid'] ?? ''));
            if ($core_uid === '') {
                continue;
            }
            $seen[] = $core_uid;
            $oracle_id = sanitize_text_field((string) ($row['oracle_bus_id'] ?? ''));
            $government_number = sanitize_text_field((string) ($row['government_number'] ?? ''));
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, planning_capacity FROM {$table} WHERE core_bus_uid = %s",
                $core_uid
            ), ARRAY_A);
            if (!$existing && $oracle_id !== '') {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, planning_capacity FROM {$table} WHERE oracle_bus_id = %s",
                    $oracle_id
                ), ARRAY_A);
            }
            if (!$existing && $government_number !== '') {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, planning_capacity FROM {$table} WHERE government_number = %s",
                    $government_number
                ), ARRAY_A);
            }
            if (!$existing && $oracle_id !== '' && isset($legacy_numbers[$oracle_id])) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, planning_capacity FROM {$table} WHERE bus_number = %s ORDER BY id ASC LIMIT 1",
                    sanitize_text_field((string) $legacy_numbers[$oracle_id])
                ), ARRAY_A);
            }
            if (!$existing) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, planning_capacity FROM {$table} WHERE bus_number = %s ORDER BY id ASC LIMIT 1",
                    sanitize_text_field((string) ($row['bus_number'] ?? ''))
                ), ARRAY_A);
            }
            $registered_capacity = max(0, intval($row['registered_capacity'] ?? 0));
            $master = array(
                'core_bus_uid' => $core_uid,
                'oracle_bus_id' => $oracle_id,
                'bus_number' => sanitize_text_field((string) ($row['bus_number'] ?? '')),
                'description' => sanitize_text_field((string) ($row['description'] ?? '')),
                'model' => sanitize_text_field((string) ($row['model'] ?? '')),
                'plate_number' => null,
                'government_number' => $government_number ?: null,
                'driver_license_number' => sanitize_text_field((string) ($row['driver_license_number'] ?? $row['plate_number'] ?? '')) ?: null,
                'chassis_number' => sanitize_text_field((string) ($row['chassis_number'] ?? '')),
                'passenger_capacity' => $registered_capacity,
                // Olama Core owns the bus capacity. Keep the local planning projection in sync
                // so prior local overrides cannot make trip capacity disagree with Core.
                'planning_capacity' => $registered_capacity,
                'driver_employee_id' => sanitize_text_field((string) ($row['driver_employee_id'] ?? '')),
                'driver_source_name' => sanitize_text_field((string) ($row['driver_employee_name'] ?? '')),
                'companion_employee_id' => sanitize_text_field((string) ($row['companion_employee_id'] ?? '')),
                'companion_source_name' => sanitize_text_field((string) ($row['companion_employee_name'] ?? '')),
                'last_license_renewal' => self::sanitize_date($row['last_license_renewal'] ?? ''),
                'license_expiry_date' => self::sanitize_date($row['next_license_renewal'] ?? ''),
                'engine_capacity' => sanitize_text_field((string) ($row['engine_capacity'] ?? '')),
                'fuel_type' => sanitize_text_field((string) ($row['fuel_type'] ?? '')),
                'source_system' => 'olama_core',
                'source_hash' => sanitize_text_field((string) ($row['source_hash'] ?? '')),
                'last_synced_at' => self::sanitize_datetime($row['last_synced_at'] ?? ''),
                'status' => !empty($row['is_active']) ? 'active' : 'inactive',
                'updated_at' => current_time('mysql', true),
            );

            if ($existing) {
                $wpdb->update($table, $master, array('id' => intval($existing['id'])));
                $summary['updated']++;
            } else {
                $master['created_at'] = current_time('mysql', true);
                $wpdb->insert($table, $master);
                $summary['created']++;
            }
            if ($wpdb->last_error) {
                return new WP_Error('core_projection_failed', $wpdb->last_error);
            }
        }

        if ($seen) {
            $placeholders = implode(',', array_fill(0, count($seen), '%s'));
            $summary['deactivated'] = intval($wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'inactive', updated_at = %s
                 WHERE source_system = 'olama_core' AND core_bus_uid NOT IN ({$placeholders}) AND status <> 'inactive'",
                array_merge(array(current_time('mysql', true)), $seen)
            )));
        }

        if (class_exists('Olama_Transportation_Audit')) {
            Olama_Transportation_Audit::record('core_refresh', 'buses', null, null, $summary);
        }
        return $summary;
    }

    public static function get_buses($include_inactive = false)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_transport_buses";
        $employees = olama_core()->read_models()->table('employees');
        $where = $include_inactive ? '' : "WHERE b.status = 'active'";

        return $wpdb->get_results(
            "SELECT b.*,
                    COALESCE(d.full_name, NULLIF(b.driver_source_name, '')) AS driver_name,
                    COALESCE(c.full_name, NULLIF(b.companion_source_name, '')) AS companion_name
             FROM {$table} b
             LEFT JOIN {$employees} d ON b.driver_employee_id = d.employee_id
             LEFT JOIN {$employees} c ON b.companion_employee_id = c.employee_id
             {$where}
             ORDER BY CASE WHEN b.bus_number REGEXP '^[0-9]+$' THEN 0 ELSE 1 END,
                      CAST(b.bus_number AS UNSIGNED), b.bus_number ASC"
        );
    }

    public static function get_bus($id)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_transport_buses";

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }

    public static function save_bus($data)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_transport_buses";

        $id = isset($data['id']) ? intval($data['id']) : 0;
        if (!$id) {
            return new WP_Error('core_master_required', __('Create buses in Oracle and synchronize them into Olama Core first.', 'olama-transportation'));
        }
        $existing_bus = self::get_bus($id);
        if (!$existing_bus || empty($existing_bus->core_bus_uid)) {
            return new WP_Error('invalid_core_bus', __('Only buses sourced from Olama Core can be managed here.', 'olama-transportation'));
        }
        $bus_data = array(
            'planning_capacity'   => intval($data['planning_capacity'] ?? $existing_bus->passenger_capacity),
            'morning_trip_count'  => max(1, min(10, intval($data['morning_trip_count'] ?? $existing_bus->morning_trip_count ?? 3))),
            'afternoon_trip_count'=> max(1, min(10, intval($data['afternoon_trip_count'] ?? $existing_bus->afternoon_trip_count ?? 3))),
            'driver_user_id'      => !empty($data['driver_user_id']) ? intval($data['driver_user_id']) : null,
            'accessibility'       => !empty($data['accessibility']) ? 1 : 0,
            'tracking_provider'   => sanitize_key($data['tracking_provider'] ?? $existing_bus->tracking_provider),
            'tracking_device_id'  => sanitize_text_field($data['tracking_device_id'] ?? $existing_bus->tracking_device_id),
            'updated_at'          => current_time('mysql', true),
        );

        $registered_capacity = intval($existing_bus->passenger_capacity);
        if ($bus_data['planning_capacity'] <= 0 || ($registered_capacity > 0 && $bus_data['planning_capacity'] > $registered_capacity)) {
            return new WP_Error('invalid_capacity', $registered_capacity > 0
                ? __('Planning capacity must be greater than zero and cannot exceed the registered Core capacity.', 'olama-transportation')
                : __('Enter a positive local planning capacity because Core capacity is missing.', 'olama-transportation'));
        }
        foreach (array('driver_user_id' => self::get_available_drivers()) as $field => $eligible) {
            if (!$bus_data[$field]) continue;
            $ids = array_map(function ($user) { return (int) $user->ID; }, $eligible);
            if (!in_array((int) $bus_data[$field], $ids, true)) {
                return new WP_Error('ineligible_staff_member', __('Only active employees with the سائق title can be assigned as drivers.', 'olama-transportation'));
            }
        }

        $before = self::get_bus($id);
        $updated = $wpdb->update($table, $bus_data, array('id' => $id));
        if ($updated !== false && class_exists('Olama_Transportation_Audit')) {
            Olama_Transportation_Audit::record('update_planning_profile', 'bus', $id, $before, self::get_bus($id));
            if ((int) $before->planning_capacity !== $bus_data['planning_capacity']) {
                Olama_Transportation_Audit::record('bus_planning_capacity_updated', 'bus', $id, array('planning_capacity' => (int) $before->planning_capacity), array('planning_capacity' => $bus_data['planning_capacity']));
            }
            if ((int) $before->morning_trip_count !== $bus_data['morning_trip_count'] || (int) $before->afternoon_trip_count !== $bus_data['afternoon_trip_count']) {
                Olama_Transportation_Audit::record('bus_trip_counts_updated', 'bus', $id, array('morning' => (int) $before->morning_trip_count, 'afternoon' => (int) $before->afternoon_trip_count), array('morning' => $bus_data['morning_trip_count'], 'afternoon' => $bus_data['afternoon_trip_count']));
            }
        }
        return $updated !== false ? $id : new WP_Error('db_error', __('Failed to update bus planning settings.', 'olama-transportation'));
    }

    public static function delete_bus($id)
    {
        return new WP_Error('core_master_required', __('Buses are mastered in Oracle and Olama Core; they cannot be deleted from Transportation.', 'olama-transportation'));
    }

    public static function get_available_drivers()
    {
        return self::eligible_employee_users('سائق');
    }

    public static function get_available_companions()
    {
        return self::eligible_employee_users('معلم');
    }

    private static function eligible_employee_users($title)
    {
        if (!function_exists('olama_core') || !method_exists(olama_core(), 'employees')) return array();
        $users = array();
        foreach ((array) olama_core()->employees()->active(array('limit' => 1000)) as $employee) {
            $row = is_array($employee) ? $employee : (array) $employee;
            $job_title = (string) ($row['job_title'] ?? '');
            if (mb_stripos($job_title, $title, 0, 'UTF-8') === false) continue;
            $profile = olama_core()->employees()->get_360((string) ($row['employee_id'] ?? ''));
            foreach ((array) ($profile['accounts'] ?? array()) as $account) {
                $user = get_user_by('id', (int) $account['user_id']);
                if ($user) $users[$user->ID] = $user;
            }
        }
        return array_values($users);
    }

    public static function assign_students_to_bus($bus_id, $student_ids, $academic_year_id)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_student_bus_assignments";

        $bus = self::get_bus($bus_id);
        if (!$bus) {
            return new WP_Error('invalid_bus', __('Bus not found.', 'olama-transportation'));
        }

        $capacity_info = self::get_bus_capacity_info($bus_id, $academic_year_id);
        if (count($student_ids) > $capacity_info['available']) {
            return new WP_Error('capacity_exceeded', sprintf(
                __('Cannot assign %d students. Only %d seats available.', 'olama-transportation'),
                count($student_ids),
                $capacity_info['available']
            ));
        }

        $success_count = 0;
        $error_count = 0;
        $errors = array();
        $current_user_id = get_current_user_id();

        foreach ($student_ids as $student_id) {
            $student_id = intval($student_id);
            $student = olama_core()->students()->get_by_id($student_id);
            $student_uid = $student['student_uid'] ?? '';

            if (!$student_uid) {
                $error_count++;
                $errors[] = $student_id;
                continue;
            }

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE student_uid = %s AND academic_year_id = %d",
                $student_uid,
                $academic_year_id
            ));

            if ($existing) {
                $result = $wpdb->update(
                    $table,
                    array(
                        'bus_id'      => $bus_id,
                        'student_uid' => $student_uid,
                        'assigned_at' => current_time('mysql'),
                        'assigned_by' => $current_user_id,
                    ),
                    array('id' => $existing)
                );
            } else {
                $result = $wpdb->insert(
                    $table,
                    array(
                        'student_id'        => $student_id,
                        'student_uid'       => $student_uid,
                        'bus_id'            => $bus_id,
                        'academic_year_id'  => $academic_year_id,
                        'assigned_at'       => current_time('mysql'),
                        'assigned_by'       => $current_user_id,
                    )
                );
            }

            if ($result !== false) {
                $success_count++;
            } else {
                $error_count++;
                $errors[] = $student_id;
            }
        }

        return array(
            'success'   => $success_count,
            'errors'    => $error_count,
            'error_ids' => $errors,
        );
    }

    /** Trips must be planned before students can be selected for them. */
    public static function get_assignment_trips($bus_id, $academic_year_id)
    {
        global $wpdb;
        $allocations = Olama_Transportation_DB::table('area_bus_assignments');
        $areas = Olama_Transportation_DB::table('major_areas');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.direction,a.trip_number,COUNT(DISTINCT a.major_area_id) area_count,
                    GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ', ') area_names
             FROM {$allocations} a JOIN {$areas} m ON m.id=a.major_area_id
             WHERE a.academic_year_id=%d AND a.bus_id=%d AND a.status='active'
             GROUP BY a.direction,a.trip_number ORDER BY FIELD(a.direction,'morning','afternoon'),a.trip_number",
            absint($academic_year_id), absint($bus_id)
        ), ARRAY_A);
        foreach ($rows as &$row) {
            $row['trip_number'] = (int) $row['trip_number'];
            $row['area_count'] = (int) $row['area_count'];
        }
        return $rows;
    }

    public static function get_trip_area_students($bus_id, $academic_year_id, $direction, $trip_number)
    {
        global $wpdb;
        $context = self::assignment_trip_context($bus_id, $academic_year_id, $direction, $trip_number);
        if (is_wp_error($context)) return $context;

        $area_ids = $context['area_ids'];
        $placeholders = implode(',', array_fill(0, count($area_ids), '%d'));
        $students = olama_core()->read_models()->table('students');
        $student_years = olama_core()->read_models()->table('student_years');
        $stops = Olama_Transportation_DB::table('family_stops');
        $areas = Olama_Transportation_DB::table('major_areas');
        $assignments = $wpdb->prefix . 'olama_student_bus_assignments';
        $enrollments = Olama_Transportation_DB::table('enrollments');
        $has_enrollments = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$enrollments} WHERE academic_year_id=%d AND status='active'", $academic_year_id)) > 0;
        $direction_column = $direction === 'morning' ? 'morning_enabled' : 'afternoon_enabled';

        if ($has_enrollments) {
            $sql = "SELECT DISTINCT s.id,s.student_uid,s.student_name,sy.class_name grade_name,sy.section_name,
                           fs.major_area_id,m.name area_name,
                           IF(a.bus_id=%d AND a.trip_number=%d,1,0) selected
                    FROM {$enrollments} e
                    JOIN {$students} s ON s.student_uid=e.student_uid
                    LEFT JOIN {$student_years} sy ON sy.student_uid=s.student_uid AND sy.study_year IN (%s,%s)
                    JOIN {$stops} fs ON fs.family_uid=e.family_uid OR (e.family_uid IS NULL AND fs.oracle_family_id=e.oracle_family_id)
                    JOIN {$areas} m ON m.id=fs.major_area_id
                    LEFT JOIN {$assignments} a ON a.student_uid=s.student_uid AND a.academic_year_id=%d AND a.direction=%s
                    WHERE e.academic_year_id=%d AND e.status='active' AND e.{$direction_column}=1
                      AND fs.major_area_id IN ({$placeholders})
                    ORDER BY m.name,sy.class_name,sy.section_name,s.student_name";
            $params = array_merge(array($bus_id, $trip_number, $context['study_year'], $context['alternate_year'], $academic_year_id, $direction, $academic_year_id), $area_ids);
        } else {
            $sql = "SELECT DISTINCT s.id,s.student_uid,s.student_name,sy.class_name grade_name,sy.section_name,
                           fs.major_area_id,m.name area_name,
                           IF(a.bus_id=%d AND a.trip_number=%d,1,0) selected
                    FROM {$student_years} sy JOIN {$students} s ON s.student_uid=sy.student_uid
                    JOIN {$stops} fs ON fs.family_uid=sy.family_uid
                    JOIN {$areas} m ON m.id=fs.major_area_id
                    LEFT JOIN {$assignments} a ON a.student_uid=s.student_uid AND a.academic_year_id=%d AND a.direction=%s
                    WHERE sy.study_year IN (%s,%s) AND fs.major_area_id IN ({$placeholders})
                    ORDER BY m.name,sy.class_name,sy.section_name,s.student_name";
            $params = array_merge(array($bus_id, $trip_number, $academic_year_id, $direction, $context['study_year'], $context['alternate_year']), $area_ids);
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['major_area_id'] = (int) $row['major_area_id'];
            $row['selected'] = (bool) $row['selected'];
        }

        return array(
            'students' => $rows,
            'areas' => $context['areas'],
            'available_areas' => self::available_attachment_areas($area_ids),
            'capacity' => self::get_bus_capacity_info($bus_id, $academic_year_id, $direction, $trip_number),
            'demand_mode' => $has_enrollments ? 'transport_enrollments' : 'academic_registration_fallback',
        );
    }

    public static function sync_trip_students($bus_id, $academic_year_id, $direction, $trip_number, $student_ids)
    {
        global $wpdb;
        $data = self::get_trip_area_students($bus_id, $academic_year_id, $direction, $trip_number);
        if (is_wp_error($data)) return $data;
        $eligible = array();
        foreach ($data['students'] as $student) $eligible[(int) $student['id']] = $student;
        $student_ids = array_values(array_unique(array_filter(array_map('absint', $student_ids))));
        foreach ($student_ids as $student_id) {
            if (!isset($eligible[$student_id])) return new WP_Error('invalid_trip_student', __('A selected student does not belong to an area attached to this trip.', 'olama-transportation'));
        }
        if (count($student_ids) > (int) $data['capacity']['total']) {
            return new WP_Error('capacity_exceeded', sprintf(__('Cannot select %1$d students. This trip has %2$d seats.', 'olama-transportation'), count($student_ids), $data['capacity']['total']));
        }

        $table = $wpdb->prefix . 'olama_student_bus_assignments';
        $selected_uids = array();
        foreach ($student_ids as $id) $selected_uids[] = (string) $eligible[$id]['student_uid'];
        $wpdb->query('START TRANSACTION');
        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT id,student_uid FROM {$table} WHERE bus_id=%d AND academic_year_id=%d AND direction=%s AND trip_number=%d FOR UPDATE",
            $bus_id, $academic_year_id, $direction, $trip_number
        ), ARRAY_A);
        foreach ($existing as $row) {
            if (!in_array((string) $row['student_uid'], $selected_uids, true) && $wpdb->delete($table, array('id' => (int) $row['id'])) === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('assignment_save_failed', __('Could not deselect a student.', 'olama-transportation'));
            }
        }
        foreach ($student_ids as $student_id) {
            $student = $eligible[$student_id];
            $record = array('student_id'=>$student_id,'student_uid'=>$student['student_uid'],'bus_id'=>$bus_id,'academic_year_id'=>$academic_year_id,'direction'=>$direction,'trip_number'=>$trip_number,'assigned_at'=>current_time('mysql'),'assigned_by'=>get_current_user_id());
            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE student_uid=%s AND academic_year_id=%d AND direction=%s", $student['student_uid'], $academic_year_id, $direction));
            $saved = $existing_id ? $wpdb->update($table, $record, array('id'=>(int)$existing_id)) : $wpdb->insert($table, $record);
            if ($saved === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('assignment_save_failed', $wpdb->last_error ?: __('Could not save student selections.', 'olama-transportation'));
            }
        }
        $wpdb->query('COMMIT');
        return array('selected' => count($student_ids));
    }

    public static function attach_area_to_trip($bus_id, $academic_year_id, $direction, $trip_number, $major_area_id)
    {
        global $wpdb;
        $bus = self::get_bus($bus_id);
        $direction = sanitize_key($direction);
        $limit_field = $direction === 'morning' ? 'morning_trip_count' : 'afternoon_trip_count';
        if (!$bus || !in_array($direction, array('morning','afternoon'), true) || $trip_number < 1 || $trip_number > (int) $bus->{$limit_field}) {
            return new WP_Error('invalid_trip', __('Select a valid trip for this bus.', 'olama-transportation'));
        }
        $areas = Olama_Transportation_DB::table('major_areas');
        $area = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$areas} WHERE id=%d AND status='active'", $major_area_id), ARRAY_A);
        if (!$area) return new WP_Error('invalid_area', __('Select an active planning area.', 'olama-transportation'));
        $table = Olama_Transportation_DB::table('area_bus_assignments');
        $defined = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE academic_year_id=%d AND bus_id=%d AND direction=%s AND trip_number=%d AND status='active' LIMIT 1", $academic_year_id, $bus_id, $direction, $trip_number));
        if (!$defined) return new WP_Error('trip_not_defined', __('Define this bus trip before attaching another area.', 'olama-transportation'));
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE academic_year_id=%d AND bus_id=%d AND direction=%s AND trip_number=%d AND major_area_id=%d AND status='active'", $academic_year_id, $bus_id, $direction, $trip_number, $major_area_id));
        if ($exists) return (int) $exists;
        $now = current_time('mysql', true);
        $saved = $wpdb->insert($table, array('academic_year_id'=>absint($academic_year_id),'direction'=>$direction,'major_area_id'=>absint($major_area_id),'bus_id'=>absint($bus_id),'trip_number'=>absint($trip_number),'status'=>'active','created_by'=>get_current_user_id()?:null,'updated_by'=>get_current_user_id()?:null,'created_at'=>$now,'updated_at'=>$now));
        if (!$saved) return new WP_Error('area_attach_failed', $wpdb->last_error ?: __('Could not attach the area.', 'olama-transportation'));
        $id = (int) $wpdb->insert_id;
        if (class_exists('Olama_Transportation_Audit')) Olama_Transportation_Audit::record('trip_area_attached', 'area_bus_assignment', $id, null, array('bus_id'=>$bus_id,'direction'=>$direction,'trip_number'=>$trip_number,'major_area_id'=>$major_area_id));
        return $id;
    }

    private static function assignment_trip_context($bus_id, $academic_year_id, $direction, $trip_number)
    {
        global $wpdb;
        $direction = sanitize_key($direction);
        if (!self::get_bus($bus_id) || !in_array($direction, array('morning','afternoon'), true) || !$trip_number) return new WP_Error('invalid_trip', __('Select a valid bus trip.', 'olama-transportation'));
        $allocations = Olama_Transportation_DB::table('area_bus_assignments');
        $areas_table = Olama_Transportation_DB::table('major_areas');
        $areas = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT m.id,m.name FROM {$allocations} a JOIN {$areas_table} m ON m.id=a.major_area_id WHERE a.academic_year_id=%d AND a.bus_id=%d AND a.direction=%s AND a.trip_number=%d AND a.status='active' ORDER BY m.name", $academic_year_id, $bus_id, $direction, $trip_number), ARRAY_A);
        if (!$areas) return new WP_Error('trip_not_defined', __('This bus trip has not been defined yet.', 'olama-transportation'));
        $study_year = self::study_year($academic_year_id);
        if (!$study_year) return new WP_Error('invalid_academic_year', __('The academic year could not be mapped to Olama Core.', 'olama-transportation'));
        return array('areas'=>$areas,'area_ids'=>array_map('intval',wp_list_pluck($areas,'id')),'study_year'=>$study_year,'alternate_year'=>strpos($study_year,'/')!==false?str_replace('/','-',$study_year):str_replace('-','/',$study_year));
    }

    private static function available_attachment_areas($excluded_ids)
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('major_areas');
        $sql = "SELECT id,name FROM {$table} WHERE status='active'";
        if ($excluded_ids) $sql .= ' AND id NOT IN (' . implode(',', array_map('absint', $excluded_ids)) . ')';
        return $wpdb->get_results($sql . ' ORDER BY name', ARRAY_A);
    }

    public static function unassign_student($student_id, $academic_year_id)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_student_bus_assignments";

        $student = olama_core()->students()->get_by_id($student_id);
        $student_uid = $student['student_uid'] ?? '';

        if ($student_uid) {
            $result = $wpdb->delete($table, array(
                'student_uid'      => $student_uid,
                'academic_year_id' => $academic_year_id,
            ));
        } else {
            $result = $wpdb->delete($table, array(
                'student_id'       => $student_id,
                'academic_year_id' => $academic_year_id,
            ));
        }

        return $result !== false;
    }

    public static function get_bus_students($bus_id, $academic_year_id)
    {
        global $wpdb;
        $study_year = self::study_year($academic_year_id);
        if (!$study_year) {
            return array();
        }

        $core_students = olama_core()->read_models()->table('students');
        $core_student_years = olama_core()->read_models()->table('student_years');
        return $wpdb->get_results($wpdb->prepare("
            SELECT s.*, a.id AS assignment_id, a.pickup_location, a.dropoff_location,
                   a.notes, a.assigned_at, sy.section_name, sy.class_name AS grade_name
            FROM {$wpdb->prefix}olama_student_bus_assignments a
            JOIN {$core_students} s ON a.student_uid = s.student_uid
            LEFT JOIN {$core_student_years} sy
                ON s.student_uid = sy.student_uid AND sy.study_year = %s
            WHERE a.bus_id = %d AND a.academic_year_id = %d
            ORDER BY s.student_name ASC
        ", $study_year, $bus_id, $academic_year_id));
    }

    public static function get_student_bus($student_id, $academic_year_id)
    {
        global $wpdb;

        $core_students = olama_core()->read_models()->table('students');
        return $wpdb->get_row($wpdb->prepare("
            SELECT a.*, b.bus_number, b.government_number,
                   b.driver_license_number, b.passenger_capacity
            FROM {$wpdb->prefix}olama_student_bus_assignments a
            JOIN {$wpdb->prefix}olama_transport_buses b ON a.bus_id = b.id
            JOIN {$core_students} s ON a.student_uid = s.student_uid
            WHERE s.id = %d AND a.academic_year_id = %d
        ", $student_id, $academic_year_id));
    }

    public static function get_bus_capacity_info($bus_id, $academic_year_id, $direction = null, $trip_number = null)
    {
        global $wpdb;

        $bus = self::get_bus($bus_id);
        if (!$bus) {
            return array('total' => 0, 'assigned' => 0, 'available' => 0, 'percentage' => 0);
        }

        $scope = '';
        $params = array($bus_id, $academic_year_id);
        if ($direction && $trip_number) {
            $scope = ' AND direction = %s AND trip_number = %d';
            $params[] = sanitize_key($direction);
            $params[] = absint($trip_number);
        }
        $assigned_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}olama_student_bus_assignments
            WHERE bus_id = %d AND academic_year_id = %d{$scope}
        ", $params));

        $total = intval($bus->planning_capacity ?: $bus->passenger_capacity);
        $assigned = intval($assigned_count);

        return array(
            'total'      => $total,
            'assigned'   => $assigned,
            'available'  => max(0, $total - $assigned),
            'percentage' => $total > 0 ? round(($assigned / $total) * 100) : 0,
        );
    }

    private static function sanitize_date($date)
    {
        if (empty($date) || $date === '0000-00-00') {
            return null;
        }
        if (class_exists('Olama_School_Helpers') && method_exists('Olama_School_Helpers', 'sanitize_date')) {
            $sanitized = Olama_School_Helpers::sanitize_date($date);
            return $sanitized ?: null;
        }

        $timestamp = strtotime((string) $date);
        return $timestamp ? gmdate('Y-m-d', $timestamp) : null;
    }

    private static function sanitize_datetime($date)
    {
        $timestamp = strtotime((string) $date);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : current_time('mysql', true);
    }

    public static function study_year($academic_year_id)
    {
        if (!class_exists('Olama_School_Academic')) {
            return '';
        }
        $year = Olama_School_Academic::get_year(intval($academic_year_id));
        return $year ? sanitize_text_field((string) $year->year_name) : '';
    }
}
