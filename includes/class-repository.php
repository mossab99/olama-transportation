<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Repository
{
    private static $entities = array(
        'areas' => array(
            'table' => 'major_areas',
            'fields' => array('name', 'code', 'color', 'area_type', 'boundary_geojson', 'notes', 'status'),
            'required' => array('name', 'code'),
        ),
        'stops' => array(
            'table' => 'stops',
            'fields' => array('name', 'code', 'latitude', 'longitude', 'major_area_id', 'stop_type', 'arrival_radius_m', 'service_duration_seconds', 'access_notes', 'status'),
            'required' => array('name', 'code', 'latitude', 'longitude'),
        ),
        'family-stops' => array(
            'table' => 'family_stops',
            'fields' => array('family_uid', 'oracle_family_id', 'latitude', 'longitude', 'location_mode', 'arrival_latitude', 'arrival_longitude', 'departure_latitude', 'departure_longitude', 'arrival_major_area_id', 'departure_major_area_id', 'maps_url', 'address_text', 'area_text', 'major_area_id', 'approved_stop_id', 'source', 'verification_status', 'verified_by', 'verified_at', 'notes'),
            'required' => array('oracle_family_id'),
        ),
        'area-mappings' => array(
            'table' => 'area_mappings',
            'fields' => array('oracle_region_id', 'oracle_region_name', 'major_area_id'),
            'required' => array('oracle_region_id', 'major_area_id'),
        ),
        'enrollments' => array(
            'table' => 'enrollments',
            'fields' => array('student_uid', 'family_uid', 'oracle_family_id', 'oracle_student_id', 'academic_year_id', 'morning_enabled', 'afternoon_enabled', 'pickup_family_stop_id', 'dropoff_family_stop_id', 'status', 'effective_from', 'effective_to', 'notes'),
            'required' => array('student_uid', 'oracle_family_id', 'oracle_student_id', 'academic_year_id'),
        ),
        'allocations' => array(
            'table' => 'area_bus_assignments',
            'fields' => array('academic_year_id', 'direction', 'major_area_id', 'bus_id', 'trip_number', 'notes', 'is_locked', 'status'),
            'required' => array('academic_year_id', 'direction', 'major_area_id', 'bus_id'),
        ),
        'devices' => array(
            'table' => 'tracking_devices',
            'fields' => array('bus_id', 'provider', 'external_device_id', 'external_unique_id', 'tracking_mode', 'status', 'last_seen_at'),
            'required' => array('bus_id', 'provider', 'external_device_id'),
        ),
    );

    public static function definition($entity)
    {
        return isset(self::$entities[$entity]) ? self::$entities[$entity] : null;
    }

    public static function list_items($entity, $args = array())
    {
        global $wpdb;
        $definition = self::definition($entity);
        if (!$definition) {
            return new WP_Error('invalid_entity', __('Unsupported transportation entity.', 'olama-transportation'));
        }

        $limit = min(500, max(1, intval($args['per_page'] ?? 100)));
        $offset = max(0, (intval($args['page'] ?? 1) - 1) * $limit);
        $table = Olama_Transportation_DB::table($definition['table']);
        $where = array('1=1');
        $params = array();

        foreach (array('status', 'verification_status', 'academic_year_id', 'major_area_id', 'direction', 'bus_id', 'oracle_family_id') as $filter) {
            if (isset($args[$filter]) && $args[$filter] !== '') {
                $where[] = "{$filter} = %s";
                $params[] = sanitize_text_field((string) $args[$filter]);
            }
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public static function get_item($entity, $id)
    {
        global $wpdb;
        $definition = self::definition($entity);
        if (!$definition) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Olama_Transportation_DB::table($definition['table']) . ' WHERE id = %d',
            intval($id)
        ), ARRAY_A);
    }

    public static function save_item($entity, $data, $id = 0)
    {
        global $wpdb;
        $definition = self::definition($entity);
        if (!$definition) {
            return new WP_Error('invalid_entity', __('Unsupported transportation entity.', 'olama-transportation'));
        }

        $clean = self::sanitize($entity, $data, $definition['fields']);
        $core_validation = self::validate_core_references($entity, $clean);
        if (is_wp_error($core_validation)) {
            return $core_validation;
        }
        foreach ($definition['required'] as $required) {
            if (!isset($clean[$required]) || $clean[$required] === '') {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'olama-transportation'), $required));
            }
        }

        if (isset($clean['direction']) && !in_array($clean['direction'], array('morning', 'afternoon'), true)) {
            return new WP_Error('invalid_direction', __('Direction must be morning or afternoon.', 'olama-transportation'));
        }
        if (isset($clean['status']) && !in_array($clean['status'], array('active', 'inactive'), true)) {
            return new WP_Error('invalid_status', __('Status must be active or inactive.', 'olama-transportation'));
        }
        if (isset($clean['verification_status']) && !in_array($clean['verification_status'], array('needs_review', 'approved', 'rejected'), true)) {
            return new WP_Error('invalid_verification_status', __('Invalid family stop verification status.', 'olama-transportation'));
        }
        foreach (array('latitude','arrival_latitude','departure_latitude') as $field) {
            if (isset($clean[$field]) && $clean[$field] !== null && (!is_numeric($clean[$field]) || $clean[$field] < -90 || $clean[$field] > 90)) return new WP_Error('invalid_latitude', __('Invalid latitude.', 'olama-transportation'));
        }
        foreach (array('longitude','arrival_longitude','departure_longitude') as $field) {
            if (isset($clean[$field]) && $clean[$field] !== null && (!is_numeric($clean[$field]) || $clean[$field] < -180 || $clean[$field] > 180)) return new WP_Error('invalid_longitude', __('Invalid longitude.', 'olama-transportation'));
        }

        $table = Olama_Transportation_DB::table($definition['table']);
        $now = current_time('mysql', true);
        $before = $id ? self::get_item($entity, $id) : null;
        $clean['updated_at'] = $now;

        if ($id) {
            $result = $wpdb->update($table, $clean, array('id' => intval($id)));
        } else {
            $clean['created_at'] = $now;
            if (in_array('created_by', self::columns($table), true)) {
                $clean['created_by'] = get_current_user_id() ?: null;
            }
            $result = $wpdb->insert($table, $clean);
            $id = $wpdb->insert_id;
        }

        if ($result === false) {
            return new WP_Error('db_error', $wpdb->last_error ?: __('Unable to save record.', 'olama-transportation'));
        }
        $after = self::get_item($entity, $id);
        Olama_Transportation_Audit::record($before ? 'update' : 'create', $entity, $id, $before, $after);
        return $after;
    }

    public static function delete_item($entity, $id)
    {
        global $wpdb;
        $definition = self::definition($entity);
        $before = self::get_item($entity, $id);
        if (!$definition || !$before) {
            return new WP_Error('not_found', __('Record not found.', 'olama-transportation'));
        }
        if (self::has_references($entity, intval($id))) {
            return new WP_Error('record_in_use', __('This record is in use. Mark it inactive instead of deleting it.', 'olama-transportation'));
        }
        $result = $wpdb->delete(Olama_Transportation_DB::table($definition['table']), array('id' => intval($id)));
        if (!$result) {
            return new WP_Error('db_error', __('Unable to delete record.', 'olama-transportation'));
        }
        Olama_Transportation_Audit::record('delete', $entity, $id, $before, null);
        return true;
    }

    private static function sanitize($entity, $data, $fields)
    {
        $clean = array();
        $integer_fields = array('major_area_id', 'approved_stop_id', 'verified_by', 'arrival_radius_m', 'service_duration_seconds', 'academic_year_id', 'morning_enabled', 'afternoon_enabled', 'pickup_family_stop_id', 'dropoff_family_stop_id', 'bus_id', 'trip_number', 'is_locked');
        $decimal_fields = array('latitude', 'longitude', 'arrival_latitude', 'arrival_longitude', 'departure_latitude', 'departure_longitude');
        $textarea_fields = array('notes', 'access_notes', 'boundary_geojson', 'address_text');
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($value === null || $value === '') {
                $clean[$field] = null;
            } elseif (in_array($field, $integer_fields, true)) {
                $clean[$field] = intval($value);
            } elseif (in_array($field, $decimal_fields, true)) {
                $clean[$field] = (float) $value;
            } elseif (in_array($field, $textarea_fields, true)) {
                $clean[$field] = sanitize_textarea_field($value);
            } elseif ($field === 'maps_url') {
                $clean[$field] = esc_url_raw($value);
            } else {
                $clean[$field] = sanitize_text_field($value);
            }
        }
        if ($entity === 'areas' && isset($clean['code'])) {
            $clean['code'] = strtoupper(sanitize_key($clean['code']));
        }
        return $clean;
    }

    private static function columns($table)
    {
        global $wpdb;
        return $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    }

    private static function validate_core_references($entity, &$clean)
    {
        if (!in_array($entity, array('family-stops', 'enrollments', 'area-mappings', 'allocations', 'devices'), true)) {
            return true;
        }
        if (!function_exists('olama_core')) {
            return new WP_Error('core_unavailable', __('Olama Core is required.', 'olama-transportation'));
        }

        if ($entity === 'family-stops') {
            $family = olama_core()->families()->get_by_oracle_id($clean['oracle_family_id'] ?? '');
            if (!$family) {
                return new WP_Error('core_family_not_found', __('Family does not exist in Olama Core. Synchronize it before creating a transportation stop.', 'olama-transportation'));
            }
            $clean['family_uid'] = $family['family_uid'];
        }

        if ($entity === 'enrollments') {
            $student = olama_core()->students()->get_by_uid($clean['student_uid'] ?? '');
            if (!$student) {
                return new WP_Error('core_student_not_found', __('Student does not exist in Olama Core.', 'olama-transportation'));
            }
            $study_year = Olama_Transportation_Bus::study_year($clean['academic_year_id'] ?? 0);
            if (!$study_year || !olama_core()->student_years()->get_current_year($student['student_uid'], $study_year)) {
                return new WP_Error('core_student_year_not_found', __('The student has no matching academic-year record in Olama Core.', 'olama-transportation'));
            }
            $clean['family_uid'] = $student['family_uid'];
            $clean['oracle_family_id'] = $student['oracle_family_id'];
            $clean['oracle_student_id'] = $student['oracle_student_id'];
        }

        if ($entity === 'area-mappings') {
            $matched_region = null;
            foreach (olama_core()->transport_master()->get_regions(true) as $region) {
                if ((string) $region['oracle_region_id'] === (string) ($clean['oracle_region_id'] ?? '')) {
                    $matched_region = $region;
                    break;
                }
            }
            if (!$matched_region) {
                return new WP_Error('core_region_not_found', __('Active transportation region does not exist in Olama Core.', 'olama-transportation'));
            }
            $clean['oracle_region_name'] = $matched_region['region_name'];
        }

        if ($entity === 'allocations' || $entity === 'devices') {
            $bus = Olama_Transportation_Bus::get_bus(intval($clean['bus_id'] ?? 0));
            if (!$bus || empty($bus->core_bus_uid)) {
                return new WP_Error('core_bus_not_found', __('Bus is not linked to an Olama Core master record.', 'olama-transportation'));
            }
        }

        return true;
    }

    private static function has_references($entity, $id)
    {
        global $wpdb;
        $checks = array(
            'areas' => array(
                array('family_stops', 'major_area_id'),
                array('stops', 'major_area_id'),
                array('area_mappings', 'major_area_id'),
                array('area_bus_assignments', 'major_area_id'),
            ),
            'family-stops' => array(
                array('enrollments', 'pickup_family_stop_id'),
                array('enrollments', 'dropoff_family_stop_id'),
            ),
            'stops' => array(array('route_stops', 'stop_id')),
        );
        foreach ($checks[$entity] ?? array() as $check) {
            $table = Olama_Transportation_DB::table($check[0]);
            if (intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$check[1]} = %d", $id)))) {
                return true;
            }
        }
        return false;
    }
}
