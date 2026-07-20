<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Audit
{
    public static function record($action, $object_type, $object_id = null, $before = null, $after = null)
    {
        global $wpdb;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'olama-transportation';

        $wpdb->insert(Olama_Transportation_DB::table('audit_log'), array(
            'actor_user_id' => get_current_user_id() ?: null,
            'action'        => sanitize_key($action),
            'object_type'   => sanitize_key($object_type),
            'object_id'     => $object_id === null ? null : (string) $object_id,
            'before_json'   => $before === null ? null : wp_json_encode($before),
            'after_json'    => $after === null ? null : wp_json_encode($after),
            'ip_hash'       => $ip ? hash_hmac('sha256', $ip, $salt) : null,
            'created_at'    => current_time('mysql', true),
        ));
    }
}
