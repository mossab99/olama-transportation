<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Optimizer
{
    public function optimize($route_id)
    {
        global $wpdb;
        $route = Olama_Transportation_Routes::get($route_id);
        if (!$route || $route['status'] !== 'draft' || count($route['stops']) < 2) {
            return new WP_Error('invalid_route', __('A draft route with at least two stops is required.', 'olama-transportation'));
        }
        $settings = get_option('olama_transportation_settings', array());
        $provider = sanitize_key($settings['optimizer_provider'] ?? 'manual');
        if ($provider === 'manual') {
            return new WP_Error('manual_provider', __('Select and configure an external optimizer first.', 'olama-transportation'));
        }

        $request = $this->build_request($route, $settings);
        $hash = hash('sha256', wp_json_encode($request));
        $runs = Olama_Transportation_DB::table('optimization_runs');
        $wpdb->insert($runs, array(
            'route_version_id' => intval($route_id),
            'provider' => $provider,
            'request_hash' => $hash,
            'request_json' => wp_json_encode($request),
            'status' => 'pending',
            'requested_by' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql', true),
        ));
        $run_id = $wpdb->insert_id;

        $response = $provider === 'google'
            ? $this->google($request, $settings)
            : $this->webhook($request, $settings);
        if (is_wp_error($response)) {
            $wpdb->update($runs, array(
                'status' => 'failed',
                'error_message' => $response->get_error_message(),
                'completed_at' => current_time('mysql', true),
            ), array('id' => $run_id));
            return $response;
        }

        $normalized = $provider === 'google'
            ? $this->normalize_google($response, $route)
            : $this->normalize_webhook($response);
        if (is_wp_error($normalized)) {
            return $normalized;
        }
        $wpdb->update($runs, array(
            'status' => 'completed',
            'response_json' => wp_json_encode($response),
            'completed_at' => current_time('mysql', true),
        ), array('id' => $run_id));
        return Olama_Transportation_Routes::apply_optimization($route_id, $normalized['stop_ids'], array(
            'provider' => $provider,
            'request_hash' => $hash,
            'distance_m' => $normalized['distance_m'],
            'duration_seconds' => $normalized['duration_seconds'],
        ));
    }

    private function build_request($route, $settings)
    {
        $school = $settings['school_location'] ?? array('latitude' => 31.9539, 'longitude' => 35.9106);
        $shipments = array();
        foreach ($route['stops'] as $stop) {
            $shipment = array(
                'label' => 'stop:' . intval($stop['stop_id']),
                'pickups' => array(array(
                    'arrivalLocation' => array(
                        'latitude' => (float) $stop['latitude'],
                        'longitude' => (float) $stop['longitude'],
                    ),
                    'duration' => intval($stop['service_duration_seconds'] ?? 60) . 's',
                )),
            );
            $shipments[] = $shipment;
        }
        return array(
            'model' => array(
                'shipments' => $shipments,
                'vehicles' => array(array(
                    'label' => 'bus:' . intval($route['bus_id']),
                    'startLocation' => array('latitude' => (float) $school['latitude'], 'longitude' => (float) $school['longitude']),
                    'endLocation' => array('latitude' => (float) $school['latitude'], 'longitude' => (float) $school['longitude']),
                )),
            ),
            'searchMode' => 'CONSUME_ALL_AVAILABLE_TIME',
            'populatePolylines' => true,
        );
    }

    private function google($request, $settings)
    {
        $project = sanitize_text_field($settings['google_project_id'] ?? '');
        $credentials_path = defined('OLAMA_TRANSPORT_GOOGLE_CREDENTIALS') ? OLAMA_TRANSPORT_GOOGLE_CREDENTIALS : '';
        if (!$project || !$credentials_path || !is_readable($credentials_path) || !class_exists('\Google\Auth\Credentials\ServiceAccountCredentials')) {
            return new WP_Error('google_not_configured', __('Google optimizer requires a project ID and a readable service-account credentials file.', 'olama-transportation'));
        }
        $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials(
            array('https://www.googleapis.com/auth/cloud-platform'),
            $credentials_path
        );
        $token = $credentials->fetchAuthToken();
        if (empty($token['access_token'])) {
            return new WP_Error('google_auth_failed', __('Unable to authenticate with Google Route Optimization.', 'olama-transportation'));
        }
        return $this->post(
            'https://routeoptimization.googleapis.com/v1/projects/' . rawurlencode($project) . ':optimizeTours',
            $request,
            array('Authorization' => 'Bearer ' . $token['access_token'])
        );
    }

    private function webhook($request, $settings)
    {
        $url = esc_url_raw($settings['optimizer_webhook_url'] ?? '');
        if (!$url) {
            return new WP_Error('webhook_not_configured', __('External optimizer URL is not configured.', 'olama-transportation'));
        }
        $secret = (string) ($settings['optimizer_webhook_secret'] ?? '');
        return $this->post($url, $request, array(
            'X-Olama-Signature' => hash_hmac('sha256', wp_json_encode($request), $secret),
        ));
    }

    private function post($url, $payload, $headers)
    {
        $response = wp_safe_remote_post($url, array(
            'timeout' => 90,
            'redirection' => 0,
            'headers' => array_merge(array('Content-Type' => 'application/json', 'Accept' => 'application/json'), $headers),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return new WP_Error('optimizer_failed', __('The external optimizer request failed.', 'olama-transportation'), array('status' => $code));
        }
        return $body;
    }

    private function normalize_google($response, $route)
    {
        $stop_ids = array();
        $distance = 0;
        $duration = 0;
        foreach ($response['routes'][0]['visits'] ?? array() as $visit) {
            $index = isset($visit['shipmentIndex']) ? intval($visit['shipmentIndex']) : -1;
            if ($index >= 0 && isset($route['stops'][$index])) {
                $stop_ids[] = intval($route['stops'][$index]['stop_id']);
            }
        }
        foreach ($response['routes'][0]['transitions'] ?? array() as $transition) {
            $distance += intval($transition['travelDistanceMeters'] ?? 0);
            $duration += $this->seconds($transition['travelDuration'] ?? '0s');
        }
        return count($stop_ids) === count($route['stops'])
            ? array('stop_ids' => $stop_ids, 'distance_m' => $distance, 'duration_seconds' => $duration)
            : new WP_Error('invalid_optimizer_response', __('Optimizer response did not include every route stop.', 'olama-transportation'));
    }

    private function normalize_webhook($response)
    {
        $ids = array_values(array_unique(array_map('intval', $response['stop_ids'] ?? array())));
        if (!$ids) {
            return new WP_Error('invalid_optimizer_response', __('External optimizer returned no stop order.', 'olama-transportation'));
        }
        return array(
            'stop_ids' => $ids,
            'distance_m' => intval($response['distance_m'] ?? 0),
            'duration_seconds' => intval($response['duration_seconds'] ?? 0),
        );
    }

    private function seconds($duration)
    {
        return preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $matches) ? (int) round((float) $matches[1]) : 0;
    }
}
