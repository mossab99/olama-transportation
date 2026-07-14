<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_DB
{
    public static function install()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$wpdb->prefix}olama_transport_buses (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            bus_number varchar(50) NOT NULL,
            plate_number varchar(50) NOT NULL,
            passenger_capacity tinyint(4) NOT NULL,
            driver_user_id bigint(20) UNSIGNED DEFAULT NULL,
            companion_user_id bigint(20) UNSIGNED DEFAULT NULL,
            license_expiry_date date DEFAULT NULL,
            engine_capacity varchar(50) DEFAULT NULL,
            fuel_type varchar(50) DEFAULT NULL,
            status varchar(20) DEFAULT 'active' NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY plate_number (plate_number)
        ) $charset_collate;");

        dbDelta("CREATE TABLE {$wpdb->prefix}olama_student_bus_assignments (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            student_id mediumint(9) NOT NULL,
            student_uid varchar(50) DEFAULT NULL,
            bus_id mediumint(9) NOT NULL,
            academic_year_id mediumint(9) NOT NULL,
            pickup_location varchar(255) DEFAULT NULL,
            dropoff_location varchar(255) DEFAULT NULL,
            notes text DEFAULT NULL,
            assigned_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            assigned_by bigint(20) UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY student_uid_year (student_uid, academic_year_id),
            KEY student_uid (student_uid),
            KEY bus_id (bus_id),
            KEY academic_year_id (academic_year_id)
        ) $charset_collate;");

        self::ensure_student_uid_links();
    }

    private static function ensure_student_uid_links()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'olama_student_bus_assignments';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
            return;
        }

        $uid_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'student_uid'");
        if (empty($uid_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN student_uid varchar(50) DEFAULT NULL AFTER student_id");
            $wpdb->query("ALTER TABLE $table_name ADD KEY student_uid (student_uid)");
        }

        $wpdb->query(
            "UPDATE $table_name t
            INNER JOIN {$wpdb->prefix}olama_students s ON t.student_id = s.id
            SET t.student_uid = s.student_uid
            WHERE t.student_uid IS NULL"
        );

        $old_key_exists = $wpdb->get_row($wpdb->prepare(
            'SELECT 1 FROM information_schema.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = %s LIMIT 1',
            DB_NAME,
            $table_name,
            'student_year'
        ));

        if ($old_key_exists) {
            $wpdb->query("ALTER TABLE $table_name DROP INDEX student_year");
            $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY student_uid_year (student_uid, academic_year_id)");
        }
    }
}
