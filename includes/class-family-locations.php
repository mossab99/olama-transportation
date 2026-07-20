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

        $families = $wpdb->prefix . 'olama_core_families';
        $student_years = $wpdb->prefix . 'olama_core_student_years';
        $stops = Olama_Transportation_DB::table('family_stops');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT f.family_uid, f.oracle_family_id,
                    COALESCE(NULLIF(f.sponsor_full_name, ''), NULLIF(f.father_name, ''), f.oracle_family_id) AS family_name,
                    COALESCE(NULLIF(f.primary_mobile, ''), NULLIF(f.father_mobile, ''), f.mother_mobile) AS mobile,
                    COALESCE(NULLIF(f.family_address, ''), f.address) AS oracle_address,
                    COUNT(DISTINCT sy.student_uid) AS registered_students,
                    fs.id AS family_stop_id, fs.latitude, fs.longitude,
                    fs.maps_url, fs.verification_status, fs.updated_at AS location_updated_at
             FROM {$student_years} sy
             INNER JOIN {$families} f ON f.family_uid = sy.family_uid
             LEFT JOIN {$stops} fs
               ON fs.family_uid = f.family_uid
               OR (fs.family_uid IS NULL AND fs.oracle_family_id = f.oracle_family_id)
             WHERE sy.study_year IN (%s, %s)
             GROUP BY f.family_uid, f.oracle_family_id, f.sponsor_full_name, f.father_name,
                      f.primary_mobile, f.father_mobile, f.mother_mobile,
                      f.family_address, f.address, fs.id, fs.latitude, fs.longitude,
                      fs.maps_url, fs.verification_status, fs.updated_at
             ORDER BY family_name, f.oracle_family_id",
            $study_year,
            $alternate_year
        ), ARRAY_A);
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
        $existing_id = intval($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE family_uid = %s OR oracle_family_id = %s ORDER BY id LIMIT 1",
            $family_uid,
            $family['oracle_family_id']
        )));
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

    private static function within_service_bounds($latitude, $longitude)
    {
        $settings = get_option('olama_transportation_settings', array());
        $bounds = $settings['service_bounds'] ?? array('south' => 29, 'north' => 34, 'west' => 34, 'east' => 40);
        return $latitude >= (float) $bounds['south']
            && $latitude <= (float) $bounds['north']
            && $longitude >= (float) $bounds['west']
            && $longitude <= (float) $bounds['east'];
    }
}
