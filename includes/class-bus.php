<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Bus
{
    /**
     * Refresh the local planning projection from Olama Core.
     *
     * Core owns all Oracle-derived fields. This table stores only the stable
     * local bus ID used by route plans plus Olama-only planning overrides.
     */
    public static function refresh_from_core()
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

    public static function get_buses()
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_transport_buses";
        $employees = olama_core()->read_models()->table('employees');

        return $wpdb->get_results(
            "SELECT b.*,
                    COALESCE(d.full_name, NULLIF(b.driver_source_name, '')) AS driver_name,
                    COALESCE(c.full_name, NULLIF(b.companion_source_name, '')) AS companion_name
             FROM {$table} b
             LEFT JOIN {$employees} d ON b.driver_employee_id = d.employee_id
             LEFT JOIN {$employees} c ON b.companion_employee_id = c.employee_id
             ORDER BY b.bus_number ASC"
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
            'allow_multi_area'    => !empty($data['allow_multi_area']) ? 1 : 0,
            'main_area_id'        => !empty($data['main_area_id']) ? intval($data['main_area_id']) : null,
            'morning_trip_count'  => max(1, min(10, intval($data['morning_trip_count'] ?? $existing_bus->morning_trip_count ?? 2))),
            'afternoon_trip_count'=> max(1, min(10, intval($data['afternoon_trip_count'] ?? $existing_bus->afternoon_trip_count ?? 3))),
            'driver_user_id'      => !empty($data['driver_user_id']) ? intval($data['driver_user_id']) : null,
            'companion_user_id'   => !empty($data['companion_user_id']) ? intval($data['companion_user_id']) : null,
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
        if ($bus_data['main_area_id']) {
            $area = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Olama_Transportation_DB::table('major_areas') . " WHERE id=%d AND status='active'", $bus_data['main_area_id']));
            if (!$area) return new WP_Error('invalid_main_area', __('Select an active Oracle area for this bus.', 'olama-transportation'));
        }
        foreach (array('driver_user_id' => self::get_available_drivers(), 'companion_user_id' => self::get_available_companions()) as $field => $eligible) {
            if (!$bus_data[$field]) continue;
            $ids = array_map(function ($user) { return (int) $user->ID; }, $eligible);
            if (!in_array((int) $bus_data[$field], $ids, true)) {
                return new WP_Error('ineligible_staff_member', $field === 'driver_user_id'
                    ? __('Only active employees with the سائق title can be assigned as drivers.', 'olama-transportation')
                    : __('Only active teacher employees can be assigned as support administrators.', 'olama-transportation'));
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

    public static function get_bus_capacity_info($bus_id, $academic_year_id)
    {
        global $wpdb;

        $bus = self::get_bus($bus_id);
        if (!$bus) {
            return array('total' => 0, 'assigned' => 0, 'available' => 0, 'percentage' => 0);
        }

        $assigned_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}olama_student_bus_assignments
            WHERE bus_id = %d AND academic_year_id = %d
        ", $bus_id, $academic_year_id));

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
