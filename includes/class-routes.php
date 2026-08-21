<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Routes
{
    public static function create($data)
    {
        global $wpdb;
        $shared_trip_id = absint($data['shared_trip_id'] ?? 0);
        if ($shared_trip_id) {
            $trip = $wpdb->get_row($wpdb->prepare(
                'SELECT id,academic_year_id,direction,bus_id,name FROM ' . Olama_Transportation_DB::table('shared_trips') . " WHERE id=%d AND status IN ('draft','published')",
                $shared_trip_id
            ), ARRAY_A);
            if (!$trip || !$trip['bus_id']) return new WP_Error('invalid_trip', __('Select a valid trip with an assigned bus.', 'olama-transportation'));
            $data['academic_year_id'] = $trip['academic_year_id'];
            $data['direction'] = $trip['direction'];
            $data['bus_id'] = $trip['bus_id'];
            if (empty($data['name'])) $data['name'] = $trip['name'];
            if (empty($data['stop_ids'])) $data['stop_ids'] = self::trip_stop_ids($shared_trip_id);
        }
        foreach (array('academic_year_id', 'bus_id', 'direction', 'name') as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'olama-transportation'), $field));
            }
        }
        $direction = sanitize_key($data['direction']);
        if (!in_array($direction, array('morning', 'afternoon'), true)) {
            return new WP_Error('invalid_direction', __('Direction must be morning or afternoon.', 'olama-transportation'));
        }
        $table = Olama_Transportation_DB::table('route_versions');
        $version = 1 + intval($wpdb->get_var($wpdb->prepare(
            "SELECT MAX(version_number) FROM {$table} WHERE academic_year_id = %d AND bus_id = %d AND direction = %s",
            intval($data['academic_year_id']), intval($data['bus_id']), $direction
        )));
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($table, array(
            'shared_trip_id' => $shared_trip_id ?: null,
            'academic_year_id' => intval($data['academic_year_id']),
            'bus_id' => intval($data['bus_id']),
            'direction' => $direction,
            'version_number' => $version,
            'name' => sanitize_text_field($data['name']),
            'status' => 'draft',
            'created_by' => get_current_user_id() ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if (!$inserted) {
            return new WP_Error('db_error', $wpdb->last_error ?: __('Unable to create route version.', 'olama-transportation'));
        }
        $id = $wpdb->insert_id;
        $result = self::replace_stops($id, $data['stop_ids'] ?? array());
        if (is_wp_error($result)) {
            return $result;
        }
        Olama_Transportation_Audit::record('create', 'route_version', $id, null, self::get($id));
        return self::get($id);
    }

    public static function get($id)
    {
        global $wpdb;
        $routes = Olama_Transportation_DB::table('route_versions');
        $route_stops = Olama_Transportation_DB::table('route_stops');
        $stops = Olama_Transportation_DB::table('stops');
        $route = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$routes} WHERE id = %d", intval($id)), ARRAY_A);
        if (!$route) {
            return null;
        }
        if (!empty($route['shared_trip_id'])) {
            $route['trip'] = $wpdb->get_row($wpdb->prepare('SELECT id,name,status FROM ' . Olama_Transportation_DB::table('shared_trips') . ' WHERE id=%d', absint($route['shared_trip_id'])), ARRAY_A);
        }
        $route['stops'] = $wpdb->get_results($wpdb->prepare(
            "SELECT rs.*, s.name, s.code, s.latitude, s.longitude, s.arrival_radius_m, s.access_notes
             FROM {$route_stops} rs JOIN {$stops} s ON s.id = rs.stop_id
             WHERE rs.route_version_id = %d ORDER BY rs.sequence_number",
            intval($id)
        ), ARRAY_A);
        if (!empty($route['shared_trip_id'])) {
            $members = Olama_Transportation_DB::table('shared_trip_students');
            $family_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT family_uid) FROM {$members} WHERE trip_id=%d", absint($route['shared_trip_id'])));
            $route['needs_recalculation'] = $family_count !== count($route['stops']);
            $route['trip_family_count'] = $family_count;
        } else {
            $route['needs_recalculation'] = false;
        }
        return $route;
    }

    public static function list_routes($args = array())
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('route_versions');
        $where = array('1=1');
        $params = array();
        foreach (array('academic_year_id', 'bus_id', 'direction', 'status') as $field) {
            if (isset($args[$field]) && $args[$field] !== '') {
                $where[] = "{$field} = %s";
                $params[] = sanitize_text_field((string) $args[$field]);
            }
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY academic_year_id DESC, bus_id, direction, version_number DESC';
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $trips = Olama_Transportation_DB::table('shared_trips');
        $route_stops = Olama_Transportation_DB::table('route_stops');
        foreach ($rows as &$row) {
            $row['trip'] = !empty($row['shared_trip_id']) ? $wpdb->get_row($wpdb->prepare("SELECT id,name,status FROM {$trips} WHERE id=%d", absint($row['shared_trip_id'])), ARRAY_A) : null;
            $row['stop_count'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$route_stops} WHERE route_version_id=%d", absint($row['id'])));
        }
        unset($row);
        return $rows;
    }

    public static function update($id, $data)
    {
        global $wpdb;
        $before = self::get($id);
        if (!$before || $before['status'] !== 'draft') {
            return new WP_Error('immutable_route', __('Only draft route versions can be changed.', 'olama-transportation'));
        }
        $updates = array('updated_at' => current_time('mysql', true));
        if (isset($data['name'])) {
            $updates['name'] = sanitize_text_field($data['name']);
        }
        $wpdb->update(Olama_Transportation_DB::table('route_versions'), $updates, array('id' => intval($id)));
        if (!empty($data['rebuild_from_trip']) && !empty($before['shared_trip_id'])) {
            $data['stop_ids'] = self::trip_stop_ids($before['shared_trip_id']);
        }
        if (isset($data['stop_ids'])) {
            $result = self::replace_stops($id, $data['stop_ids']);
            if (is_wp_error($result)) {
                return $result;
            }
        }
        $after = self::get($id);
        Olama_Transportation_Audit::record('update', 'route_version', $id, $before, $after);
        return $after;
    }

    public static function delete($id)
    {
        global $wpdb;
        $before = self::get($id);
        if (!$before || $before['status'] !== 'draft') {
            return new WP_Error('immutable_route', __('Only draft route versions can be deleted.', 'olama-transportation'));
        }
        $wpdb->delete(Olama_Transportation_DB::table('route_stops'), array('route_version_id' => intval($id)));
        $wpdb->delete(Olama_Transportation_DB::table('route_versions'), array('id' => intval($id)));
        Olama_Transportation_Audit::record('delete', 'route_version', $id, $before, null);
        return true;
    }

    public static function publish($id)
    {
        global $wpdb;
        $route = self::get($id);
        if (!$route || $route['status'] !== 'draft' || empty($route['stops'])) {
            return new WP_Error('invalid_route', __('A non-empty draft route is required.', 'olama-transportation'));
        }
        if (!empty($route['needs_recalculation'])) {
            return new WP_Error('route_needs_recalculation', __('Trip membership or family locations changed. Rebuild and review the route before publishing.', 'olama-transportation'));
        }
        $table = Olama_Transportation_DB::table('route_versions');
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->update($table, array('status' => 'archived', 'updated_at' => current_time('mysql', true)), array(
                'academic_year_id' => $route['academic_year_id'],
                'bus_id' => $route['bus_id'],
                'direction' => $route['direction'],
                'status' => 'published',
            ));
            $result = $wpdb->update($table, array(
                'status' => 'published',
                'published_by' => get_current_user_id() ?: null,
                'published_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ), array('id' => intval($id), 'status' => 'draft'));
            if ($result === false) {
                throw new RuntimeException('publish failed');
            }
            $wpdb->query('COMMIT');
        } catch (Exception $exception) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('publish_failed', __('Unable to publish route.', 'olama-transportation'));
        }
        Olama_Transportation_Audit::record('publish', 'route_version', $id, $route, self::get($id));
        return self::get($id);
    }

    public static function apply_optimization($id, $ordered_stop_ids, $metrics = array())
    {
        global $wpdb;
        $route = self::get($id);
        if (!$route || $route['status'] !== 'draft') {
            return new WP_Error('immutable_route', __('Only draft routes can be optimized.', 'olama-transportation'));
        }
        $result = self::replace_stops($id, $ordered_stop_ids);
        if (is_wp_error($result)) {
            return $result;
        }
        $wpdb->update(Olama_Transportation_DB::table('route_versions'), array(
            'optimizer_provider' => sanitize_key($metrics['provider'] ?? 'external'),
            'optimizer_request_hash' => sanitize_text_field($metrics['request_hash'] ?? ''),
            'total_distance_m' => isset($metrics['distance_m']) ? intval($metrics['distance_m']) : null,
            'total_duration_seconds' => isset($metrics['duration_seconds']) ? intval($metrics['duration_seconds']) : null,
            'updated_at' => current_time('mysql', true),
        ), array('id' => intval($id)));
        return self::get($id);
    }

    private static function replace_stops($route_id, $stop_ids)
    {
        global $wpdb;
        $stop_ids = array_values(array_unique(array_filter(array_map('intval', (array) $stop_ids))));
        if (!$stop_ids) {
            return true;
        }
        $existing_count = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM " . Olama_Transportation_DB::table('stops') .
            ' WHERE id IN (' . implode(',', $stop_ids) . ") AND status = 'active'"
        ));
        if ($existing_count !== count($stop_ids)) {
            return new WP_Error('invalid_stops', __('One or more route stops are invalid.', 'olama-transportation'));
        }
        $table = Olama_Transportation_DB::table('route_stops');
        $wpdb->delete($table, array('route_version_id' => intval($route_id)));
        $now = current_time('mysql', true);
        foreach ($stop_ids as $index => $stop_id) {
            $wpdb->insert($table, array(
                'route_version_id' => intval($route_id),
                'stop_id' => $stop_id,
                'sequence_number' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ));
        }
        return true;
    }

    /** Build reusable route stops from the selected trip's approved family locations. */
    private static function trip_stop_ids($trip_id)
    {
        global $wpdb;
        $members = Olama_Transportation_DB::table('shared_trip_students');
        $family_stops = Olama_Transportation_DB::table('family_stops');
        $stops = Olama_Transportation_DB::table('stops');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT fs.family_uid,fs.oracle_family_id,fs.latitude,fs.longitude,fs.maps_url,fs.address_text
             FROM {$members} m INNER JOIN {$family_stops} fs ON fs.family_uid=m.family_uid
             WHERE m.trip_id=%d AND fs.latitude IS NOT NULL AND fs.longitude IS NOT NULL
               AND (fs.verification_status IN ('approved','needs_review') OR fs.verification_status IS NULL)
             ORDER BY m.family_uid", absint($trip_id)), ARRAY_A);
        $ids = array(); $now = current_time('mysql', true);
        foreach ($rows as $row) {
            $family_uid = sanitize_text_field((string) $row['family_uid']);
            if ($family_uid === '') continue;
            $code = 'family-' . substr(hash('sha256', $family_uid), 0, 40);
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$stops} WHERE code=%s LIMIT 1", $code));
            if ($existing) { $ids[] = (int) $existing; continue; }
            $name = 'Family #' . (string) ($row['oracle_family_id'] ?: $family_uid);
            $inserted = $wpdb->insert($stops, array(
                'name' => $name, 'code' => $code, 'latitude' => (float) $row['latitude'], 'longitude' => (float) $row['longitude'],
                'stop_type' => 'family', 'service_duration_seconds' => 60, 'access_notes' => (string) ($row['address_text'] ?? ''),
                'status' => 'active', 'created_by' => get_current_user_id() ?: null, 'created_at' => $now, 'updated_at' => $now,
            ));
            if ($inserted) $ids[] = (int) $wpdb->insert_id;
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
