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
        $cc = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}olama_transport_buses (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            core_bus_uid varchar(100) DEFAULT NULL,
            oracle_bus_id varchar(50) DEFAULT NULL,
            bus_number varchar(50) NOT NULL,
            description varchar(255) DEFAULT NULL,
            model varchar(100) DEFAULT NULL,
            plate_number varchar(50) DEFAULT NULL,
            government_number varchar(100) DEFAULT NULL,
            driver_license_number varchar(100) DEFAULT NULL,
            chassis_number varchar(100) DEFAULT NULL,
            passenger_capacity smallint(5) UNSIGNED NOT NULL,
            planning_capacity smallint(5) UNSIGNED DEFAULT NULL,
            morning_trip_count tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
            afternoon_trip_count tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
            driver_employee_id varchar(50) DEFAULT NULL,
            driver_source_name varchar(255) DEFAULT NULL,
            companion_employee_id varchar(50) DEFAULT NULL,
            companion_source_name varchar(255) DEFAULT NULL,
            driver_user_id bigint(20) UNSIGNED DEFAULT NULL,
            companion_user_id bigint(20) UNSIGNED DEFAULT NULL,
            last_license_renewal date DEFAULT NULL,
            license_expiry_date date DEFAULT NULL,
            engine_capacity varchar(50) DEFAULT NULL,
            fuel_type varchar(50) DEFAULT NULL,
            accessibility tinyint(1) NOT NULL DEFAULT 0,
            tracking_provider varchar(30) DEFAULT NULL,
            tracking_device_id varchar(100) DEFAULT NULL,
            source_system varchar(30) NOT NULL DEFAULT 'olama',
            source_hash varchar(64) DEFAULT NULL,
            last_synced_at datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY plate_number (plate_number),
            KEY government_number (government_number),
            KEY driver_license_number (driver_license_number),
            UNIQUE KEY core_bus_uid (core_bus_uid),
            UNIQUE KEY oracle_bus_id (oracle_bus_id),
            KEY status (status)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_major_areas (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            code varchar(50) NOT NULL,
            color varchar(20) DEFAULT '#1a56db',
            boundary_geojson longtext DEFAULT NULL,
            notes text DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY status (status)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_area_mappings (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            oracle_region_id varchar(50) NOT NULL,
            oracle_region_name varchar(150) DEFAULT NULL,
            major_area_id bigint(20) UNSIGNED NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY oracle_region (oracle_region_id),
            KEY major_area_id (major_area_id)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_family_stops (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            family_uid varchar(100) DEFAULT NULL,
            oracle_family_id varchar(100) NOT NULL,
            latitude decimal(10,7) NOT NULL,
            longitude decimal(10,7) NOT NULL,
            maps_url text DEFAULT NULL,
            address_text text DEFAULT NULL,
            area_text varchar(150) DEFAULT NULL,
            major_area_id bigint(20) UNSIGNED DEFAULT NULL,
            approved_stop_id bigint(20) UNSIGNED DEFAULT NULL,
            source varchar(30) NOT NULL DEFAULT 'whatsapp_excel',
            source_row_reference varchar(100) DEFAULT NULL,
            verification_status varchar(30) NOT NULL DEFAULT 'needs_review',
            verified_by bigint(20) UNSIGNED DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY oracle_family (oracle_family_id),
            KEY family_uid (family_uid),
            KEY major_area_id (major_area_id),
            KEY approved_stop_id (approved_stop_id),
            KEY verification_status (verification_status)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_stops (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(180) NOT NULL,
            code varchar(50) NOT NULL,
            latitude decimal(10,7) NOT NULL,
            longitude decimal(10,7) NOT NULL,
            major_area_id bigint(20) UNSIGNED DEFAULT NULL,
            stop_type varchar(30) NOT NULL DEFAULT 'family',
            arrival_radius_m smallint(5) UNSIGNED NOT NULL DEFAULT 150,
            service_duration_seconds smallint(5) UNSIGNED NOT NULL DEFAULT 60,
            access_notes text DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY major_area_id (major_area_id),
            KEY status (status)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_enrollments (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            student_uid varchar(100) NOT NULL,
            family_uid varchar(100) DEFAULT NULL,
            oracle_family_id varchar(100) NOT NULL,
            oracle_student_id varchar(100) NOT NULL,
            academic_year_id bigint(20) UNSIGNED NOT NULL,
            morning_enabled tinyint(1) NOT NULL DEFAULT 1,
            afternoon_enabled tinyint(1) NOT NULL DEFAULT 1,
            pickup_family_stop_id bigint(20) UNSIGNED DEFAULT NULL,
            dropoff_family_stop_id bigint(20) UNSIGNED DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            effective_from date DEFAULT NULL,
            effective_to date DEFAULT NULL,
            notes text DEFAULT NULL,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY student_year (student_uid, academic_year_id),
            KEY family_year (oracle_family_id, academic_year_id),
            KEY status (status)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_area_bus_assignments (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            academic_year_id bigint(20) UNSIGNED NOT NULL,
            direction varchar(20) NOT NULL,
            major_area_id bigint(20) UNSIGNED NOT NULL,
            bus_id bigint(20) UNSIGNED NOT NULL,
            is_locked tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY year_direction_area_bus (academic_year_id, direction, major_area_id, bus_id),
            KEY bus_year (bus_id, academic_year_id),
            KEY area_year (major_area_id, academic_year_id)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_route_versions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            academic_year_id bigint(20) UNSIGNED NOT NULL,
            bus_id bigint(20) UNSIGNED NOT NULL,
            direction varchar(20) NOT NULL,
            version_number int(10) UNSIGNED NOT NULL,
            name varchar(180) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            optimizer_provider varchar(30) DEFAULT NULL,
            optimizer_request_hash varchar(64) DEFAULT NULL,
            total_distance_m int(10) UNSIGNED DEFAULT NULL,
            total_duration_seconds int(10) UNSIGNED DEFAULT NULL,
            published_by bigint(20) UNSIGNED DEFAULT NULL,
            published_at datetime DEFAULT NULL,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY route_version (academic_year_id, bus_id, direction, version_number),
            KEY published_route (academic_year_id, bus_id, direction, status)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_route_stops (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            route_version_id bigint(20) UNSIGNED NOT NULL,
            stop_id bigint(20) UNSIGNED NOT NULL,
            sequence_number int(10) UNSIGNED NOT NULL,
            expected_time time DEFAULT NULL,
            distance_from_previous_m int(10) UNSIGNED DEFAULT NULL,
            duration_from_previous_seconds int(10) UNSIGNED DEFAULT NULL,
            is_locked tinyint(1) NOT NULL DEFAULT 0,
            notes text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY route_sequence (route_version_id, sequence_number),
            UNIQUE KEY route_stop (route_version_id, stop_id)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_optimization_runs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            route_version_id bigint(20) UNSIGNED DEFAULT NULL,
            provider varchar(30) NOT NULL,
            request_hash varchar(64) NOT NULL,
            request_json longtext NOT NULL,
            response_json longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            error_message text DEFAULT NULL,
            requested_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY request_hash (request_hash),
            KEY status (status)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_import_batches (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            filename varchar(255) NOT NULL,
            file_hash varchar(64) NOT NULL,
            row_count int(10) UNSIGNED NOT NULL DEFAULT 0,
            matched_count int(10) UNSIGNED NOT NULL DEFAULT 0,
            review_count int(10) UNSIGNED NOT NULL DEFAULT 0,
            invalid_count int(10) UNSIGNED NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'processing',
            imported_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY file_hash (file_hash),
            KEY status (status)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_import_rows (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id bigint(20) UNSIGNED NOT NULL,
            source_row_number int(10) UNSIGNED NOT NULL,
            oracle_family_id varchar(100) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            latitude decimal(10,7) DEFAULT NULL,
            longitude decimal(10,7) DEFAULT NULL,
            status varchar(30) NOT NULL,
            message text DEFAULT NULL,
            raw_json longtext NOT NULL,
            family_stop_id bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_row (batch_id, source_row_number),
            KEY status (status)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_tracking_devices (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            bus_id bigint(20) UNSIGNED NOT NULL,
            provider varchar(30) NOT NULL DEFAULT 'traccar',
            external_device_id varchar(100) NOT NULL,
            external_unique_id varchar(150) DEFAULT NULL,
            tracking_mode varchar(20) NOT NULL DEFAULT 'phone',
            status varchar(20) NOT NULL DEFAULT 'inactive',
            last_seen_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_device (provider, external_device_id),
            KEY bus_id (bus_id)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_audit_log (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id bigint(20) UNSIGNED DEFAULT NULL,
            action varchar(80) NOT NULL,
            object_type varchar(80) NOT NULL,
            object_id varchar(100) DEFAULT NULL,
            before_json longtext DEFAULT NULL,
            after_json longtext DEFAULT NULL,
            ip_hash varchar(64) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY object_lookup (object_type, object_id),
            KEY actor_user_id (actor_user_id),
            KEY created_at (created_at)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_planning_groups (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            academic_year_id bigint(20) UNSIGNED NOT NULL,
            direction varchar(20) NOT NULL,
            trip_number tinyint(3) UNSIGNED NOT NULL,
            group_name varchar(180) NOT NULL,
            bus_id bigint(20) UNSIGNED NOT NULL,
            major_area_id bigint(20) UNSIGNED DEFAULT NULL,
            color varchar(20) NOT NULL,
            polygon_geojson longtext DEFAULT NULL,
            notes text DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            family_count int(10) UNSIGNED NOT NULL DEFAULT 0,
            student_count int(10) UNSIGNED NOT NULL DEFAULT 0,
            capacity_snapshot int(10) UNSIGNED NOT NULL DEFAULT 0,
            approved_by bigint(20) UNSIGNED DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            updated_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY year_direction_status (academic_year_id, direction, status),
            KEY bus_trip_lookup (academic_year_id, direction, bus_id, trip_number, status),
            KEY major_area_lookup (major_area_id, academic_year_id)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_planning_group_families (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id bigint(20) UNSIGNED NOT NULL,
            family_uid varchar(100) NOT NULL,
            oracle_family_id varchar(100) NOT NULL,
            family_stop_id bigint(20) UNSIGNED NOT NULL,
            student_count_snapshot int(10) UNSIGNED NOT NULL DEFAULT 0,
            latitude_snapshot decimal(10,7) NOT NULL,
            longitude_snapshot decimal(10,7) NOT NULL,
            major_area_id_snapshot bigint(20) UNSIGNED DEFAULT NULL,
            region_name_snapshot varchar(150) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY group_family (group_id, family_uid),
            KEY family_lookup (family_uid),
            KEY group_lookup (group_id)
        ) $cc;");

        self::normalize_core_bus_projection();
        self::ensure_legacy_assignment_links();
    }

    private static function normalize_core_bus_projection()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'olama_transport_buses';
        // dbDelta does not reliably relax an existing NOT NULL column.
        $wpdb->query("ALTER TABLE {$table} MODIFY plate_number varchar(50) NULL DEFAULT NULL");
        $wpdb->query(
            "UPDATE {$table} SET plate_number = NULL
             WHERE plate_number = '' OR plate_number LIKE 'CORE-ORA-BUS-%'"
        );
        $wpdb->query(
            "UPDATE {$table}
             SET driver_license_number = plate_number, plate_number = NULL
             WHERE driver_license_number IS NULL AND plate_number IS NOT NULL"
        );
        $wpdb->query(
            "UPDATE {$table} SET last_license_renewal = NULL
             WHERE last_license_renewal = '0000-00-00'"
        );
        $wpdb->query(
            "UPDATE {$table} SET license_expiry_date = NULL
             WHERE license_expiry_date = '0000-00-00'"
        );
    }

    private static function ensure_legacy_assignment_links()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_student_bus_assignments';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            dbDelta("CREATE TABLE {$table} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                student_id bigint(20) UNSIGNED NOT NULL,
                student_uid varchar(100) DEFAULT NULL,
                bus_id bigint(20) UNSIGNED NOT NULL,
                academic_year_id bigint(20) UNSIGNED NOT NULL,
                pickup_location varchar(255) DEFAULT NULL,
                dropoff_location varchar(255) DEFAULT NULL,
                notes text DEFAULT NULL,
                assigned_at datetime NOT NULL,
                assigned_by bigint(20) UNSIGNED NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY student_uid_year (student_uid, academic_year_id),
                KEY bus_id (bus_id)
            ) {$wpdb->get_charset_collate()};");
            return;
        }

        $students_table = $wpdb->prefix . 'olama_core_students';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $students_table)) === $students_table) {
            $wpdb->query(
                "UPDATE {$table} a INNER JOIN {$students_table} s ON a.student_id = s.id
                 SET a.student_uid = s.student_uid WHERE a.student_uid IS NULL"
            );
        }
    }

    public static function table($suffix)
    {
        global $wpdb;
        return $wpdb->prefix . 'olama_transport_' . $suffix;
    }
}
