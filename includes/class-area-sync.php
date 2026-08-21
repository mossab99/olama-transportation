<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Area_Sync
{
    /**
     * Return the Planning Area choices backed by the active Oracle region list.
     *
     * A Planning Area remains a local assignment, but family corrections must
     * choose from the same values supplied by Oracle. Legacy local-only areas
     * may remain in the database for historical reporting; they are not valid
     * choices in the family location workspace.
     */
    public static function selectable_areas()
    {
        global $wpdb;

        $areas = Olama_Transportation_DB::table('major_areas');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        return $wpdb->get_results(
            "SELECT a.id,
                    COALESCE(NULLIF(m.oracle_region_name, ''), a.name) AS name,
                    a.code, a.color, a.status,
                    m.oracle_region_id, m.oracle_region_name
             FROM {$mappings} m
             INNER JOIN {$areas} a ON a.id = m.major_area_id
             WHERE a.status = 'active'
             ORDER BY name ASC, m.oracle_region_id ASC",
            ARRAY_A
        );
    }

    public static function refresh_from_core()
    {
        global $wpdb;

        if (!function_exists('olama_core') || !method_exists(olama_core(), 'transport_master')) {
            return new WP_Error('core_unavailable', __('Olama Core transportation master service is unavailable.', 'olama-transportation'), array('status' => 503));
        }

        $areas = Olama_Transportation_DB::table('major_areas');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        // Read the Core mirror explicitly, then apply the active flag here so
        // the transportation mapping cannot depend on a stale filtered query.
        // This also keeps inactive Oracle regions out of every selector.
        $rows = array_values(array_filter(
            olama_core()->transport_master()->get_regions(false),
            array(__CLASS__, 'is_active_core_region')
        ));
        $now = current_time('mysql', true);
        $seen = array();
        $summary = array('core_regions' => count($rows), 'created' => 0, 'mappings_created' => 0, 'updated' => 0, 'deactivated' => 0, 'duplicates' => 0, 'backfilled' => 0);

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($rows as $row) {
                $oracle_id = sanitize_text_field((string) ($row['oracle_region_id'] ?? ''));
                if ($oracle_id === '') {
                    continue;
                }
                $seen[] = $oracle_id;
                $name = sanitize_text_field((string) ($row['region_name'] ?? $oracle_id));
                $mapping = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$mappings} WHERE oracle_region_id = %s", $oracle_id), ARRAY_A);
                if ($mapping) {
                    $area = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$areas} WHERE id = %d", $mapping['major_area_id']), ARRAY_A);
                    $wpdb->update($mappings, array('oracle_region_name' => $name, 'updated_at' => $now), array('id' => $mapping['id']));
                    if ($area) {
                        $area_update = array('status' => 'active', 'updated_at' => $now);
                        if (empty($area['notes']) && $area['name'] !== $name) {
                            $area_update['name'] = $name;
                        }
                        $wpdb->update($areas, $area_update, array('id' => $area['id']));
                        $summary['duplicates'] += self::merge_unmapped_duplicates((int) $area['id'], $name, $now);
                        $summary['updated']++;
                        continue;
                    }
                }

                // Reuse a legacy/manual area with the same normalized name. Older
                // versions always inserted a new row here, which produced duplicate
                // labels after the first Core refresh.
                $area = self::find_unmapped_area($name);
                if ($area) {
                    $area_id = (int) $area['id'];
                    $wpdb->update($areas, array('name' => $name, 'status' => 'active', 'updated_at' => $now), array('id' => $area_id));
                    $summary['updated']++;
                } else {
                    $code = self::unique_code($oracle_id, $areas);
                    $wpdb->insert($areas, array(
                        'name' => $name,
                        'code' => $code,
                        'color' => self::next_color($areas, $oracle_id),
                        'status' => 'active',
                        'created_by' => get_current_user_id() ?: null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ));
                    if (!$wpdb->insert_id || $wpdb->last_error) {
                        throw new RuntimeException($wpdb->last_error ?: 'Could not create the planning area.');
                    }
                    $area_id = (int) $wpdb->insert_id;
                    $summary['created']++;
                }

                $mapping_data = array('oracle_region_name' => $name, 'major_area_id' => $area_id, 'updated_at' => $now);
                if ($mapping) {
                    $wpdb->update($mappings, $mapping_data, array('id' => $mapping['id']));
                } else {
                    $mapping_data['oracle_region_id'] = $oracle_id;
                    $mapping_data['created_at'] = $now;
                    $wpdb->insert($mappings, $mapping_data);
                    $summary['mappings_created']++;
                }
                if ($wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error ?: 'Could not create the area mapping.');
                }
                $summary['duplicates'] += self::merge_unmapped_duplicates($area_id, $name, $now);
            }

            if ($seen) {
                $placeholders = implode(',', array_fill(0, count($seen), '%s'));
                $sql = $wpdb->prepare(
                    "UPDATE {$areas} a INNER JOIN {$mappings} m ON m.major_area_id = a.id
                     SET a.status = 'inactive', a.updated_at = %s
                     WHERE m.oracle_region_id NOT IN ({$placeholders}) AND a.status <> 'inactive'",
                    array_merge(array($now), $seen)
                );
                $summary['deactivated'] += max(0, (int) $wpdb->query($sql));
            } else {
                $summary['deactivated'] += max(0, (int) $wpdb->query($wpdb->prepare(
                    "UPDATE {$areas} a INNER JOIN {$mappings} m ON m.major_area_id = a.id
                     SET a.status = 'inactive', a.updated_at = %s
                     WHERE a.status <> 'inactive'",
                    $now
                )));
            }
            $summary['backfilled'] = self::backfill_family_stops(false);
            if ($wpdb->last_error) {
                throw new RuntimeException($wpdb->last_error);
            }
            $wpdb->query('COMMIT');
        } catch (Exception $exception) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('area_sync_failed', $exception->getMessage(), array('status' => 500));
        }

        Olama_Transportation_Audit::record('transport_areas_refreshed', 'major_areas', null, null, $summary);
        return $summary;
    }

    public static function resolve_for_family($family, $area_text = '')
    {
        global $wpdb;
        $mappings = Olama_Transportation_DB::table('area_mappings');
        $areas = Olama_Transportation_DB::table('major_areas');
        $region_id = sanitize_text_field((string) ($family['trans_region_id'] ?? ''));
        if ($region_id !== '') {
            $id = $wpdb->get_var($wpdb->prepare("SELECT major_area_id FROM {$mappings} WHERE oracle_region_id = %s", $region_id));
            if ($id) {
                return (int) $id;
            }
        }
        $text = sanitize_text_field((string) ($area_text ?: ($family['trans_region_name'] ?? '')));
        if ($text === '') {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT a.id FROM {$areas} a LEFT JOIN {$mappings} m ON m.major_area_id = a.id
             WHERE a.name = %s OR m.oracle_region_name = %s ORDER BY a.status = 'active' DESC LIMIT 1",
            $text,
            $text
        ));
    }

    public static function backfill_family_stops($audit = true)
    {
        global $wpdb;
        $stops = Olama_Transportation_DB::table('family_stops');
        $families = olama_core()->read_models()->table('families');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        $now = current_time('mysql', true);
        $updated = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$stops} fs
             INNER JOIN {$families} f ON f.family_uid = fs.family_uid OR (fs.family_uid IS NULL AND f.oracle_family_id = fs.oracle_family_id)
             INNER JOIN {$mappings} m ON m.oracle_region_id = f.trans_region_id
             SET fs.major_area_id = m.major_area_id, fs.area_assignment_source='core',
                 fs.area_assigned_by=NULL, fs.area_assigned_at=%s, fs.updated_at = %s
             WHERE fs.major_area_id IS NULL AND fs.area_assignment_source <> 'manual'",
            $now,
            $now
        ));
        if ($audit && $updated) {
            Olama_Transportation_Audit::record('family_stop_areas_backfilled', 'family_stops', null, null, array('updated' => $updated, 'rule' => 'only_unassigned'));
        }
        return max(0, $updated);
    }

    private static function unique_code($oracle_id, $areas)
    {
        global $wpdb;
        $base = 'CORE-REGION-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $oracle_id);
        $code = $base;
        $suffix = 2;
        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$areas} WHERE code = %s", $code))) {
            $code = $base . '-' . $suffix++;
        }
        return $code;
    }

    private static function find_unmapped_area($name, $exclude_id = 0)
    {
        global $wpdb;
        $areas = Olama_Transportation_DB::table('major_areas');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        $rows = $wpdb->get_results(
            "SELECT a.* FROM {$areas} a LEFT JOIN {$mappings} m ON m.major_area_id = a.id
             WHERE m.id IS NULL AND a.status = 'active' ORDER BY a.id ASC",
            ARRAY_A
        );
        $normalized_name = self::normalize_name($name);
        foreach ($rows as $row) {
            if ((int) $row['id'] !== $exclude_id && self::normalize_name($row['name']) === $normalized_name) {
                return $row;
            }
        }
        return null;
    }

    private static function merge_unmapped_duplicates($target_id, $name, $now)
    {
        global $wpdb;
        $areas = Olama_Transportation_DB::table('major_areas');
        $merged = 0;
        while ($duplicate = self::find_unmapped_area($name, $target_id)) {
            $duplicate_id = (int) $duplicate['id'];
            foreach (array('family_stops', 'stops', 'planning_groups') as $entity) {
                $table = Olama_Transportation_DB::table($entity);
                $wpdb->update($table, array('major_area_id' => $target_id), array('major_area_id' => $duplicate_id));
            }

            self::merge_area_bus_assignments($duplicate_id, $target_id);

            $target = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$areas} WHERE id = %d", $target_id), ARRAY_A);
            $target_update = array('updated_at' => $now);
            foreach (array('boundary_geojson', 'notes') as $field) {
                if ($target && empty($target[$field]) && !empty($duplicate[$field])) {
                    $target_update[$field] = $duplicate[$field];
                }
            }
            $wpdb->update($areas, $target_update, array('id' => $target_id));
            $wpdb->update($areas, array('status' => 'inactive', 'updated_at' => $now), array('id' => $duplicate_id));
            if ($wpdb->last_error) {
                throw new RuntimeException($wpdb->last_error);
            }
            $merged++;
        }
        return $merged;
    }

    private static function merge_area_bus_assignments($duplicate_id, $target_id)
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('area_bus_assignments');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE major_area_id = %d", $duplicate_id), ARRAY_A);
        foreach ($rows as $row) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE academic_year_id = %d AND direction = %s AND major_area_id = %d LIMIT 1",
                $row['academic_year_id'],
                $row['direction'],
                $target_id
            ));
            if ($existing) {
                $wpdb->update($table, array('status' => 'inactive'), array('id' => $row['id']));
            } else {
                $wpdb->update($table, array('major_area_id' => $target_id), array('id' => $row['id']));
            }
        }
    }

    private static function normalize_name($name)
    {
        $name = sanitize_text_field((string) $name);
        return preg_replace('/[\s\p{Z}]+/u', ' ', trim($name));
    }

    private static function is_active_core_region($row)
    {
        if (!is_array($row)) return false;
        foreach (array('is_active', 'active', 'region_is_active', 'region_active', 'is_active_name', 'active_name', 'status', 'status_name') as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === '' || $row[$key] === null) continue;
            $value = strtolower(trim((string) $row[$key]));
            return in_array($value, array('1', 'true', 'yes', 'y', 'active', 'enabled', 'فعال'), true);
        }
        // Older Core mirrors may not have a status column. In that case the
        // upstream active-only contract is the authority.
        return true;
    }

    private static function stable_color($value)
    {
        $palette = array('#2563eb', '#059669', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#dc2626');
        return $palette[abs(crc32((string) $value)) % count($palette)];
    }

    private static function next_color($areas, $seed)
    {
        global $wpdb;
        $used = $wpdb->get_col("SELECT color FROM {$areas} WHERE status='active'");
        $palette = array('#2563eb', '#059669', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#dc2626', '#0f766e', '#a16207');
        foreach ($palette as $color) if (!in_array($color, $used, true)) return $color;
        return self::stable_color($seed);
    }
}
