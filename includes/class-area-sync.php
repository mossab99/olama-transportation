<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Area_Sync
{
    public static function refresh_from_core()
    {
        global $wpdb;

        if (!function_exists('olama_core') || !method_exists(olama_core(), 'transport_master')) {
            return new WP_Error('core_unavailable', __('Olama Core transportation master service is unavailable.', 'olama-transportation'), array('status' => 503));
        }

        $areas = Olama_Transportation_DB::table('major_areas');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        $rows = olama_core()->transport_master()->get_regions(false);
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
                $active = !empty($row['is_active']) ? 'active' : 'inactive';
                $mapping = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$mappings} WHERE oracle_region_id = %s", $oracle_id), ARRAY_A);
                if ($mapping) {
                    $area = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$areas} WHERE id = %d", $mapping['major_area_id']), ARRAY_A);
                    $wpdb->update($mappings, array('oracle_region_name' => $name, 'updated_at' => $now), array('id' => $mapping['id']));
                    if ($area) {
                        $area_update = array('status' => $active, 'updated_at' => $now);
                        if (empty($area['notes']) && $area['name'] !== $name) {
                            $area_update['name'] = $name;
                        }
                        $wpdb->update($areas, $area_update, array('id' => $area['id']));
                    }
                    $summary['updated']++;
                    continue;
                }

                $code = self::unique_code($oracle_id, $areas);
                $wpdb->insert($areas, array(
                    'name' => $name,
                    'code' => $code,
                    'color' => self::stable_color($oracle_id),
                    'status' => $active,
                    'created_by' => get_current_user_id() ?: null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ));
                if (!$wpdb->insert_id || $wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error ?: 'Could not create the planning area.');
                }
                $area_id = (int) $wpdb->insert_id;
                $summary['created']++;
                $wpdb->insert($mappings, array(
                    'oracle_region_id' => $oracle_id,
                    'oracle_region_name' => $name,
                    'major_area_id' => $area_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ));
                if (!$wpdb->insert_id || $wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error ?: 'Could not create the area mapping.');
                }
                $summary['mappings_created']++;
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
        $families = $wpdb->prefix . 'olama_core_families';
        $mappings = Olama_Transportation_DB::table('area_mappings');
        $now = current_time('mysql', true);
        $updated = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$stops} fs
             INNER JOIN {$families} f ON f.family_uid = fs.family_uid OR (fs.family_uid IS NULL AND f.oracle_family_id = fs.oracle_family_id)
             INNER JOIN {$mappings} m ON m.oracle_region_id = f.trans_region_id
             SET fs.major_area_id = m.major_area_id, fs.updated_at = %s
             WHERE fs.major_area_id IS NULL",
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

    private static function stable_color($value)
    {
        $palette = array('#2563eb', '#059669', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#dc2626');
        return $palette[abs(crc32((string) $value)) % count($palette)];
    }
}
