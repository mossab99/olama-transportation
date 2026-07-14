<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Bus
{
    public static function get_buses()
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_transport_buses";

        return $wpdb->get_results("SELECT * FROM $table ORDER BY bus_number ASC");
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
        $bus_data = array(
            'bus_number'          => sanitize_text_field($data['bus_number'] ?? ''),
            'plate_number'        => sanitize_text_field($data['plate_number'] ?? ''),
            'passenger_capacity'  => intval($data['passenger_capacity'] ?? 0),
            'driver_user_id'      => !empty($data['driver_user_id']) ? intval($data['driver_user_id']) : null,
            'companion_user_id'   => !empty($data['companion_user_id']) ? intval($data['companion_user_id']) : null,
            'license_expiry_date' => !empty($data['license_expiry_date']) ? self::sanitize_date($data['license_expiry_date']) : null,
            'engine_capacity'     => sanitize_text_field($data['engine_capacity'] ?? ''),
            'fuel_type'           => sanitize_text_field($data['fuel_type'] ?? ''),
            'status'              => sanitize_text_field($data['status'] ?? 'active'),
        );

        if (empty($bus_data['bus_number']) || empty($bus_data['plate_number'])) {
            return new WP_Error('missing_data', __('Bus number and Plate number are required.', 'olama-transportation'));
        }

        if ($bus_data['passenger_capacity'] <= 0) {
            return new WP_Error('invalid_capacity', __('Passenger capacity must be greater than zero.', 'olama-transportation'));
        }

        if ($id > 0) {
            $updated = $wpdb->update($table, $bus_data, array('id' => $id));
            return $updated !== false ? $id : new WP_Error('db_error', __('Failed to update bus.', 'olama-transportation'));
        }

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE plate_number = %s", $bus_data['plate_number']));
        if ($exists) {
            return new WP_Error('duplicate_plate', __('Plate number already exists.', 'olama-transportation'));
        }

        $inserted = $wpdb->insert($table, $bus_data);
        return $inserted ? $wpdb->insert_id : new WP_Error('db_error', __('Failed to save bus.', 'olama-transportation'));
    }

    public static function delete_bus($id)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_transport_buses";

        return $wpdb->delete($table, array('id' => intval($id)));
    }

    public static function get_available_drivers()
    {
        return get_users(array('role__in' => array('administrator', 'editor', 'author', 'teacher', 'assistant')));
    }

    public static function get_available_companions()
    {
        return get_users(array('role__in' => array('administrator', 'editor', 'author', 'teacher', 'assistant')));
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
            $student_uid = $wpdb->get_var($wpdb->prepare(
                "SELECT student_uid FROM {$wpdb->prefix}olama_students WHERE id = %d",
                $student_id
            ));

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

        $student_uid = $wpdb->get_var($wpdb->prepare(
            "SELECT student_uid FROM {$wpdb->prefix}olama_students WHERE id = %d",
            $student_id
        ));

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

        return $wpdb->get_results($wpdb->prepare("
            SELECT s.*, a.id as assignment_id, a.pickup_location, a.dropoff_location,
                   a.notes, a.assigned_at, sec.section_name, g.grade_name
            FROM {$wpdb->prefix}olama_student_bus_assignments a
            JOIN {$wpdb->prefix}olama_students s ON a.student_uid = s.student_uid
            LEFT JOIN {$wpdb->prefix}olama_student_enrollment e ON s.student_uid = e.student_uid AND e.academic_year_id = %d
            LEFT JOIN {$wpdb->prefix}olama_sections sec ON e.section_id = sec.id
            LEFT JOIN {$wpdb->prefix}olama_grades g ON sec.grade_id = g.id
            WHERE a.bus_id = %d AND a.academic_year_id = %d
            ORDER BY s.student_name ASC
        ", $academic_year_id, $bus_id, $academic_year_id));
    }

    public static function get_student_bus($student_id, $academic_year_id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare("
            SELECT a.*, b.bus_number, b.plate_number, b.passenger_capacity
            FROM {$wpdb->prefix}olama_student_bus_assignments a
            JOIN {$wpdb->prefix}olama_transport_buses b ON a.bus_id = b.id
            JOIN {$wpdb->prefix}olama_students s ON a.student_uid = s.student_uid
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

        $total = intval($bus->passenger_capacity);
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
        if (class_exists('Olama_School_Helpers') && method_exists('Olama_School_Helpers', 'sanitize_date')) {
            return Olama_School_Helpers::sanitize_date($date);
        }

        $timestamp = strtotime((string) $date);
        return $timestamp ? gmdate('Y-m-d', $timestamp) : null;
    }
}
