<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Importer
{
    public function import($file)
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('invalid_upload', __('No valid import file was received.', 'olama-transportation'));
        }
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, array('csv', 'xlsx', 'xls'), true)) {
            return new WP_Error('invalid_type', __('Upload a CSV or Excel workbook.', 'olama-transportation'));
        }
        $rows = $this->read_rows($file['tmp_name'], $extension);
        if (is_wp_error($rows)) {
            return $rows;
        }
        if (!$rows) {
            return new WP_Error('empty_file', __('The import file has no data rows.', 'olama-transportation'));
        }
        return $this->reconcile($file['name'], hash_file('sha256', $file['tmp_name']), $rows);
    }

    private function read_rows($path, $extension)
    {
        if ($extension === 'csv') {
            $handle = fopen($path, 'rb');
            if (!$handle) {
                return new WP_Error('read_failed', __('Unable to read CSV file.', 'olama-transportation'));
            }
            $data = array();
            while (($row = fgetcsv($handle)) !== false) {
                $data[] = $row;
            }
            fclose($handle);
            return $this->associate($data);
        }

        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            return new WP_Error('excel_dependency', __('Excel support requires PhpSpreadsheet. Save as CSV or install the dependency.', 'olama-transportation'));
        }
        try {
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
            return $this->associate($sheet->toArray(null, true, true, false));
        } catch (Exception $exception) {
            return new WP_Error('excel_read_failed', __('Unable to read the Excel workbook.', 'olama-transportation'));
        }
    }

    private function associate($data)
    {
        if (count($data) < 2) {
            return array();
        }
        $aliases = array(
            'family_id' => array('family_id', 'family id', 'family number', 'رقم العائلة', 'رقم الاسرة'),
            'phone' => array('phone', 'mobile', 'whatsapp', 'رقم الهاتف', 'الهاتف'),
            'maps_url' => array('maps_url', 'google maps', 'location', 'رابط الموقع', 'الموقع'),
            'latitude' => array('latitude', 'lat', 'خط العرض'),
            'longitude' => array('longitude', 'lng', 'lon', 'خط الطول'),
            'address' => array('address', 'address_text', 'العنوان'),
            'area' => array('area', 'area_name', 'المنطقة'),
            'notes' => array('notes', 'ملاحظات'),
        );
        $headers = array_map(function ($value) {
            return strtolower(trim((string) $value));
        }, array_shift($data));
        $map = array();
        foreach ($aliases as $canonical => $names) {
            foreach ($names as $name) {
                $index = array_search(strtolower($name), $headers, true);
                if ($index !== false) {
                    $map[$canonical] = $index;
                    break;
                }
            }
        }
        $rows = array();
        foreach ($data as $number => $values) {
            if (!array_filter($values, function ($value) { return trim((string) $value) !== ''; })) {
                continue;
            }
            $item = array('_row' => $number + 2);
            foreach ($map as $key => $index) {
                $item[$key] = isset($values[$index]) ? trim((string) $values[$index]) : '';
            }
            $rows[] = $item;
        }
        return $rows;
    }

    private function reconcile($filename, $hash, $rows)
    {
        global $wpdb;
        $batches = Olama_Transportation_DB::table('import_batches');
        $import_rows = Olama_Transportation_DB::table('import_rows');
        $stops = Olama_Transportation_DB::table('family_stops');
        $now = current_time('mysql', true);
        $wpdb->insert($batches, array(
            'filename' => sanitize_file_name($filename),
            'file_hash' => $hash,
            'row_count' => count($rows),
            'status' => 'processing',
            'imported_by' => get_current_user_id() ?: null,
            'created_at' => $now,
        ));
        $batch_id = $wpdb->insert_id;
        $counts = array('matched' => 0, 'needs_review' => 0, 'invalid' => 0);

        foreach ($rows as $row) {
            $family_id = sanitize_text_field($row['family_id'] ?? '');
            list($lat, $lng) = $this->coordinates($row);
            $status = 'matched';
            $message = '';
            if ($family_id === '') {
                $status = 'needs_review';
                $message = 'Missing family ID';
            } elseif ($lat === null || $lng === null) {
                $status = 'invalid';
                $message = 'Invalid coordinates or Google Maps link';
            } elseif (!$this->family_exists($family_id)) {
                $status = 'needs_review';
                $message = 'Family ID was not found in Olama Core';
            } elseif (!$this->in_service_region($lat, $lng)) {
                $status = 'needs_review';
                $message = 'Coordinates are outside the configured service region';
            }

            $family_stop_id = null;
            if ($status === 'matched') {
                $core_family = function_exists('olama_core') ? olama_core()->families()->get_by_oracle_id($family_id) : null;
                $record = array(
                    'family_uid' => $core_family['family_uid'] ?? null,
                    'oracle_family_id' => $family_id,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'maps_url' => esc_url_raw($row['maps_url'] ?? ''),
                    'address_text' => sanitize_textarea_field($row['address'] ?? ''),
                    'area_text' => sanitize_text_field($row['area'] ?? ''),
                    'source' => 'whatsapp_excel',
                    'source_row_reference' => $batch_id . ':' . intval($row['_row']),
                    'verification_status' => 'needs_review',
                    'notes' => sanitize_textarea_field($row['notes'] ?? ''),
                    'updated_at' => $now,
                );
                $existing = $wpdb->get_row($wpdb->prepare("SELECT id,major_area_id FROM {$stops} WHERE oracle_family_id = %s", $family_id), ARRAY_A);
                if ($existing) {
                    // Preserve an existing reviewed/manual classification.
                    if (!empty($existing['major_area_id'])) {
                        $record['major_area_id'] = (int) $existing['major_area_id'];
                    } elseif ($core_family && class_exists('Olama_Transportation_Area_Sync')) {
                        $record['major_area_id'] = Olama_Transportation_Area_Sync::resolve_for_family($core_family, $record['area_text']) ?: null;
                    }
                    $wpdb->update($stops, $record, array('id' => $existing['id']));
                    $family_stop_id = intval($existing['id']);
                } else {
                    if ($core_family && class_exists('Olama_Transportation_Area_Sync')) {
                        $record['major_area_id'] = Olama_Transportation_Area_Sync::resolve_for_family($core_family, $record['area_text']) ?: null;
                    }
                    $record['created_at'] = $now;
                    $wpdb->insert($stops, $record);
                    $family_stop_id = $wpdb->insert_id;
                }
            }

            $count_key = $status === 'invalid' ? 'invalid' : $status;
            $counts[$count_key]++;
            $wpdb->insert($import_rows, array(
                'batch_id' => $batch_id,
                'source_row_number' => intval($row['_row']),
                'oracle_family_id' => $family_id ?: null,
                'phone' => sanitize_text_field($row['phone'] ?? ''),
                'latitude' => $lat,
                'longitude' => $lng,
                'status' => $status,
                'message' => $message,
                'raw_json' => wp_json_encode($row),
                'family_stop_id' => $family_stop_id,
                'created_at' => $now,
            ));
        }

        $wpdb->update($batches, array(
            'matched_count' => $counts['matched'],
            'review_count' => $counts['needs_review'],
            'invalid_count' => $counts['invalid'],
            'status' => 'completed',
            'completed_at' => current_time('mysql', true),
        ), array('id' => $batch_id));
        Olama_Transportation_Audit::record('import', 'family_stops', $batch_id, null, $counts);
        return array('batch_id' => $batch_id, 'row_count' => count($rows), 'counts' => $counts);
    }

    private function coordinates($row)
    {
        if (isset($row['latitude'], $row['longitude']) && is_numeric($row['latitude']) && is_numeric($row['longitude'])) {
            return array((float) $row['latitude'], (float) $row['longitude']);
        }
        $url = html_entity_decode($row['maps_url'] ?? '');
        if (preg_match('/(?:@|q=|query=)(-?\d{1,2}\.\d+)[,%2C\s]+(-?\d{1,3}\.\d+)/i', $url, $matches)) {
            return array((float) $matches[1], (float) $matches[2]);
        }
        return array(null, null);
    }

    private function family_exists($family_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_families';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return false;
        }
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE oracle_family_id = %s LIMIT 1",
            $family_id
        ));
    }

    private function in_service_region($lat, $lng)
    {
        $settings = get_option('olama_transportation_settings', array());
        $bounds = $settings['service_bounds'] ?? array('south' => 29, 'north' => 34, 'west' => 34, 'east' => 40);
        return $lat >= $bounds['south'] && $lat <= $bounds['north'] && $lng >= $bounds['west'] && $lng <= $bounds['east'];
    }
}
