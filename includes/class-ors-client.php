<?php

if (!defined('ABSPATH')) exit;

/** Small server-side OpenRouteService HTTP client. */
class Olama_Transportation_ORS_Client
{
    const BASE_URL = 'https://api.heigit.org';

    public function get_api_key()
    {
        if (defined('OLAMA_TRANSPORT_ORS_API_KEY') && OLAMA_TRANSPORT_ORS_API_KEY) return (string) OLAMA_TRANSPORT_ORS_API_KEY;
        $settings = get_option('olama_transportation_settings', array());
        return (string) ($settings['ors_api_key'] ?? '');
    }

    public function optimize(array $payload)
    {
        return $this->post('/vroom/v0', $payload);
    }

    public function directions(array $coordinates, array $options = array())
    {
        $profile = sanitize_key($options['profile'] ?? 'driving-car');
        return $this->post('/openrouteservice/v2/directions/' . rawurlencode($profile) . '/geojson', array(
            'coordinates' => array_values($coordinates),
            'instructions' => false,
            'geometry_simplify' => false,
        ));
    }

    public function test_connection(array $options = array())
    {
        $settings = get_option('olama_transportation_settings', array());
        $location = $options ?: ($settings['school_location'] ?? array());
        if (!$this->valid_point($location)) return new WP_Error('ors_missing_depot', __('Configure valid academy/depot coordinates first.', 'olama-transportation'));
        $response = $this->directions(array(
            array((float) $location['longitude'], (float) $location['latitude']),
            array((float) $location['longitude'] + 0.001, (float) $location['latitude']),
        ), array('profile' => $settings['ors_profile'] ?? 'driving-car'));
        return is_wp_error($response) ? $response : true;
    }

    private function post($path, array $payload)
    {
        if (!$this->get_api_key()) return new WP_Error('ors_missing_api_key', __('Configure the OpenRouteService API key first.', 'olama-transportation'));
        $response = wp_safe_remote_post(self::BASE_URL . $path, array(
            'timeout' => 90,
            'redirection' => 0,
            'headers' => array('Authorization' => $this->get_api_key(), 'Content-Type' => 'application/json', 'Accept' => 'application/json, application/geo+json'),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) return new WP_Error('ors_connection_error', __('Unable to contact OpenRouteService.', 'olama-transportation'));
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 429) return new WP_Error('ors_rate_limited', __('OpenRouteService rate limit reached. Try again later.', 'olama-transportation'));
        if ($code === 401 || $code === 403) return new WP_Error('ors_auth_failed', __('OpenRouteService rejected the configured API key.', 'olama-transportation'));
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $detail = is_array($body) ? ($body['error']['message'] ?? $body['message'] ?? '') : '';
            $message = $detail ? sprintf(__('OpenRouteService request failed: %s', 'olama-transportation'), sanitize_text_field($detail)) : __('OpenRouteService returned an invalid response.', 'olama-transportation');
            return new WP_Error('ors_request_failed', $message, array('status' => $code));
        }
        return $body;
    }

    private function valid_point($point)
    {
        return is_array($point) && isset($point['latitude'], $point['longitude']) && is_numeric($point['latitude']) && is_numeric($point['longitude'])
            && (float) $point['latitude'] >= -90 && (float) $point['latitude'] <= 90 && (float) $point['longitude'] >= -180 && (float) $point['longitude'] <= 180;
    }
}
