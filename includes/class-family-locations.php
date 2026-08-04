<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Family_Locations
{
    public static function registered_families($academic_year_id)
    {
        global $wpdb;

        $study_year = Olama_Transportation_Bus::study_year($academic_year_id);
        if ($study_year === '') {
            return array();
        }
        $study_year = preg_replace('/\s*([\/-])\s*/', '$1', $study_year);
        $alternate_year = strpos($study_year, '/') !== false
            ? str_replace('/', '-', $study_year)
            : str_replace('-', '/', $study_year);

        $families = olama_core()->read_models()->table('families');
        $student_years = olama_core()->read_models()->table('student_years');
        $stops = Olama_Transportation_DB::table('family_stops');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.family_uid, f.oracle_family_id,
                    COALESCE(NULLIF(f.sponsor_full_name, ''), NULLIF(f.father_name, ''), f.oracle_family_id) AS family_name,
                    COALESCE(NULLIF(f.primary_mobile, ''), NULLIF(f.father_mobile, ''), f.mother_mobile) AS mobile,
                    COALESCE(NULLIF(f.family_address, ''), f.address) AS oracle_address,
                    COUNT(DISTINCT sy.student_uid) AS registered_students,
                    f.trans_region_id, f.trans_region_name,
                    fs.id AS family_stop_id, fs.latitude, fs.longitude, fs.major_area_id,
                    fs.area_assignment_source, fs.area_assigned_by, fs.area_assigned_at,
                    fs.source AS location_source, fs.notes, fs.maps_url, fs.verification_status, fs.updated_at AS location_updated_at,
                    a.name AS major_area_name
             FROM {$student_years} sy
             INNER JOIN {$families} f ON f.family_uid = sy.family_uid
             LEFT JOIN {$stops} fs
               ON fs.family_uid = f.family_uid
               OR (fs.family_uid IS NULL AND fs.oracle_family_id = f.oracle_family_id)
             LEFT JOIN " . Olama_Transportation_DB::table('major_areas') . " a ON a.id=fs.major_area_id
             WHERE sy.study_year IN (%s, %s)
             GROUP BY f.family_uid, f.oracle_family_id, f.sponsor_full_name, f.father_name,
                      f.primary_mobile, f.father_mobile, f.mother_mobile,
                      f.family_address, f.address, f.trans_region_id, f.trans_region_name,
                      fs.id, fs.latitude, fs.longitude, fs.major_area_id, fs.area_assignment_source,
                      fs.area_assigned_by, fs.area_assigned_at, fs.source, fs.notes,
                      fs.maps_url, fs.verification_status, fs.updated_at, a.name
             ORDER BY family_name, f.oracle_family_id",
            $study_year,
            $alternate_year
        ), ARRAY_A);
        $morning = Olama_Transportation_Effective_Assignments::resolve($academic_year_id, 'morning');
        $afternoon = Olama_Transportation_Effective_Assignments::resolve($academic_year_id, 'afternoon');
        $effective = array('morning' => array(), 'afternoon' => array());
        foreach (array('morning' => $morning, 'afternoon' => $afternoon) as $direction => $resolved) {
            if (is_wp_error($resolved)) {
                continue;
            }
            foreach ($resolved['families'] as $family) {
                $effective[$direction][$family['family_uid']] = $family;
            }
        }
        foreach ($rows as &$row) {
            $row['effective_morning'] = $effective['morning'][$row['family_uid']] ?? null;
            $row['effective_afternoon'] = $effective['afternoon'][$row['family_uid']] ?? null;
        }
        unset($row);
        return $rows;
    }

    public static function admin_list($academic_year_id, $args = array())
    {
        $rows = self::registered_families($academic_year_id);
        $search = trim(sanitize_text_field($args['search'] ?? ''));
        $area = sanitize_text_field((string) ($args['major_area_id'] ?? ''));
        $location = sanitize_key($args['location_status'] ?? 'all');
        $morning = sanitize_key($args['morning_status'] ?? 'all');
        $afternoon = sanitize_key($args['afternoon_status'] ?? 'all');
        $missing_only = !empty($args['missing_locations']);
        $filtered = array_values(array_filter($rows, function ($row) use ($search, $area, $location, $morning, $afternoon, $missing_only) {
            $has_location = $row['latitude'] !== null && $row['longitude'] !== null;
            $location_status = !$has_location ? 'missing_location' : sanitize_key($row['verification_status'] ?: 'invalid_location');
            if ($search !== '' && stripos($row['family_name'] . ' ' . $row['oracle_family_id'] . ' ' . ($row['mobile'] ?? ''), $search) === false) return false;
            if ($area === 'unassigned' && !empty($row['major_area_id'])) return false;
            if ($area !== '' && $area !== 'all' && $area !== 'unassigned' && (int) $row['major_area_id'] !== absint($area)) return false;
            if ($missing_only && $has_location) return false;
            if ($location !== 'all' && $location_status !== $location) return false;
            if ($morning !== 'all' && sanitize_key($row['effective_morning']['assignment_status'] ?? 'missing_area') !== $morning) return false;
            if ($afternoon !== 'all' && sanitize_key($row['effective_afternoon']['assignment_status'] ?? 'missing_area') !== $afternoon) return false;
            return true;
        }));
        $per_page = min(100, max(20, absint($args['per_page'] ?? 20)));
        $page = max(1, absint($args['page'] ?? 1));
        $total = count($filtered);
        return array(
            'items' => array_slice($filtered, ($page - 1) * $per_page, $per_page),
            'pagination' => array('page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page))),
            'metrics' => array(
                'registered_transportation_families' => count($rows),
                'families_with_valid_coordinates' => count(array_filter($rows, function ($row) { return $row['latitude'] !== null && $row['longitude'] !== null; })),
                'families_missing_coordinates' => count(array_filter($rows, function ($row) { return $row['latitude'] === null || $row['longitude'] === null; })),
                'families_with_planning_areas' => count(array_filter($rows, function ($row) { return !empty($row['major_area_id']); })),
                'families_without_planning_areas' => count(array_filter($rows, function ($row) { return empty($row['major_area_id']); })),
            ),
        );
    }

    public static function save($family_uid, $input, $notes = '')
    {
        global $wpdb;

        $family_uid = sanitize_text_field($family_uid);
        $family = function_exists('olama_core') ? olama_core()->families()->get_by_uid($family_uid) : null;
        if (!$family) {
            return new WP_Error(
                'core_family_not_found',
                __('Family does not exist in Olama Core.', 'olama-transportation'),
                array('status' => 404)
            );
        }

        $coordinates = self::parse_coordinates($input);
        if (is_wp_error($coordinates)) {
            return $coordinates;
        }
        if (!self::within_service_bounds($coordinates['latitude'], $coordinates['longitude'])) {
            return new WP_Error(
                'outside_service_area',
                __('The location is outside the configured transportation service area.', 'olama-transportation'),
                array('status' => 400)
            );
        }

        $table = Olama_Transportation_DB::table('family_stops');
        $existing_stop = $wpdb->get_row($wpdb->prepare(
            "SELECT id, major_area_id FROM {$table} WHERE family_uid = %s OR oracle_family_id = %s ORDER BY id LIMIT 1",
            $family_uid,
            $family['oracle_family_id']
        ), ARRAY_A);
        $existing_id = intval($existing_stop['id'] ?? 0);
        $resolved_area_id = class_exists('Olama_Transportation_Area_Sync')
            ? Olama_Transportation_Area_Sync::resolve_for_family($family, $family['trans_region_name'])
            : 0;
        // A current assignment may have been reviewed manually. Preserve it;
        // automatic Core/text resolution only fills an unassigned stop.
        $major_area_id = !empty($existing_stop['major_area_id'])
            ? (int) $existing_stop['major_area_id']
            : ($resolved_area_id ?: null);
        $maps_url = 'https://www.google.com/maps?q='
            . rawurlencode($coordinates['latitude'] . ',' . $coordinates['longitude']);
        $saved = Olama_Transportation_Repository::save_item('family-stops', array(
            'family_uid' => $family_uid,
            'oracle_family_id' => $family['oracle_family_id'],
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
            'maps_url' => $maps_url,
            'address_text' => $family['family_address'] ?: $family['address'],
            'area_text' => $family['trans_region_name'],
            'major_area_id' => $major_area_id,
            'source' => 'whatsapp_manual',
            'verification_status' => 'needs_review',
            'notes' => sanitize_textarea_field($notes),
        ), $existing_id);
        if (is_wp_error($saved)) {
            return $saved;
        }

        $latitude_key = number_format($coordinates['latitude'], 7, '.', '');
        $longitude_key = number_format($coordinates['longitude'], 7, '.', '');
        $duplicate_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE id <> %d AND latitude = %s AND longitude = %s",
            intval($saved['id']),
            $latitude_key,
            $longitude_key
        )));

        return array(
            'family_stop' => $saved,
            'normalized_location' => $coordinates['latitude'] . ', ' . $coordinates['longitude'],
            'map_url' => $maps_url,
            'duplicate_count' => $duplicate_count,
            'message' => $duplicate_count
                ? __('Location saved for review. Another family uses the same coordinates.', 'olama-transportation')
                : __('Location saved and marked for review.', 'olama-transportation'),
        );
    }

    public static function parse_coordinates($input)
    {
        $value = trim(html_entity_decode(wp_unslash((string) $input)));
        $value = strtr($value, array(
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '،' => ',',
        ));
        $decoded = rawurldecode($value);
        $patterns = array(
            '/(?:@|[?&](?:q|query)=)\s*(-?\d{1,2}(?:\.\d+)?)\s*[, ]\s*(-?\d{1,3}(?:\.\d+)?)/i',
            '/^\s*\(?\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*\)?\s*$/',
        );
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $decoded, $matches)) {
                continue;
            }
            $latitude = (float) $matches[1];
            $longitude = (float) $matches[2];
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                break;
            }
            return array(
                'latitude' => round($latitude, 7),
                'longitude' => round($longitude, 7),
            );
        }

        return new WP_Error(
            'invalid_location',
            __('Paste coordinates as latitude, longitude or paste a full Google Maps URL containing coordinates.', 'olama-transportation'),
            array('status' => 400)
        );
    }

    public static function within_service_bounds($latitude, $longitude)
    {
        $settings = get_option('olama_transportation_settings', array());
        $bounds = $settings['service_bounds'] ?? array('south' => 29, 'north' => 34, 'west' => 34, 'east' => 40);
        return $latitude >= (float) $bounds['south']
            && $latitude <= (float) $bounds['north']
            && $longitude >= (float) $bounds['west']
            && $longitude <= (float) $bounds['east'];
    }
}
