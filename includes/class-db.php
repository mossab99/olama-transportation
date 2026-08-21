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
            allow_multi_area tinyint(1) NOT NULL DEFAULT 0,
            main_area_id bigint(20) UNSIGNED DEFAULT NULL,
            morning_trip_count tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
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
            area_type varchar(20) NOT NULL DEFAULT 'secondary',
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
            latitude decimal(10,7) DEFAULT NULL,
            longitude decimal(10,7) DEFAULT NULL,
            maps_url text DEFAULT NULL,
            address_text text DEFAULT NULL,
            area_text varchar(150) DEFAULT NULL,
            major_area_id bigint(20) UNSIGNED DEFAULT NULL,
            area_assignment_source varchar(30) NOT NULL DEFAULT 'core',
            area_assigned_by bigint(20) UNSIGNED DEFAULT NULL,
            area_assigned_at datetime DEFAULT NULL,
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
            trip_number tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
            arrival_time time DEFAULT NULL,
            departure_time time DEFAULT NULL,
            notes text DEFAULT NULL,
            is_locked tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            updated_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY year_direction_area (academic_year_id, direction, major_area_id),
            KEY bus_trip (academic_year_id, direction, bus_id, trip_number),
            KEY bus_year (bus_id, academic_year_id),
            KEY area_year (major_area_id, academic_year_id),
            KEY status (status)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_area_trip_families (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            area_bus_assignment_id bigint(20) UNSIGNED NOT NULL,
            family_uid varchar(100) NOT NULL,
            student_count_snapshot int(10) UNSIGNED NOT NULL DEFAULT 0,
            queue_position int(10) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY trip_family (area_bus_assignment_id, family_uid),
            KEY trip_queue (area_bus_assignment_id, queue_position),
            KEY family_uid (family_uid)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_shared_trips (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            academic_year_id bigint(20) UNSIGNED NOT NULL,
            direction varchar(20) NOT NULL,
            name varchar(180) NOT NULL,
            planning_limit smallint(5) UNSIGNED NOT NULL DEFAULT 35,
            bus_id bigint(20) UNSIGNED DEFAULT NULL,
            bus_trip_number tinyint(3) UNSIGNED DEFAULT NULL,
            companion_user_id bigint(20) UNSIGNED DEFAULT NULL,
            arrival_time time DEFAULT NULL,
            departure_time time DEFAULT NULL,
            trip_limit_acknowledged tinyint(1) NOT NULL DEFAULT 0,
            bus_limit_acknowledged tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'draft',
            published_by bigint(20) UNSIGNED DEFAULT NULL,
            published_at datetime DEFAULT NULL,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            updated_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY year_direction (academic_year_id,direction,status),
            KEY bus_slot (academic_year_id,direction,bus_id,bus_trip_number,status),
            KEY companion_user_id (companion_user_id)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_shared_trip_areas (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trip_id bigint(20) UNSIGNED NOT NULL,
            major_area_id bigint(20) UNSIGNED NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY trip_area (trip_id,major_area_id),
            KEY area_trip (major_area_id,trip_id)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_shared_trip_students (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trip_id bigint(20) UNSIGNED NOT NULL,
            student_id bigint(20) UNSIGNED NOT NULL,
            student_uid varchar(100) NOT NULL,
            oracle_student_id varchar(100) DEFAULT NULL,
            student_name varchar(255) NOT NULL,
            family_uid varchar(100) NOT NULL,
            oracle_family_id varchar(100) DEFAULT NULL,
            major_area_id bigint(20) UNSIGNED NOT NULL,
            grade_name varchar(100) DEFAULT NULL,
            section_name varchar(100) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY trip_student (trip_id,student_uid),
            KEY student_lookup (student_uid),
            KEY trip_area (trip_id,major_area_id)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_shared_trip_queue (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trip_id bigint(20) UNSIGNED NOT NULL,
            node_key varchar(120) NOT NULL,
            node_type varchar(20) NOT NULL,
            family_uid varchar(100) DEFAULT NULL,
            family_name varchar(255) DEFAULT NULL,
            oracle_family_id varchar(100) DEFAULT NULL,
            student_count smallint(5) UNSIGNED NOT NULL DEFAULT 0,
            queue_position int(10) UNSIGNED NOT NULL DEFAULT 0,
            latitude decimal(10,7) DEFAULT NULL,
            longitude decimal(10,7) DEFAULT NULL,
            location_status varchar(30) NOT NULL DEFAULT 'missing_location',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY trip_node (trip_id,node_key),
            KEY trip_queue (trip_id,queue_position)
        ) $cc;");

        dbDelta("CREATE TABLE {$p}olama_transport_route_versions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            shared_trip_id bigint(20) UNSIGNED DEFAULT NULL,
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
            KEY shared_trip (shared_trip_id),
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
        self::allow_missing_family_coordinates();
        self::upgrade_area_assignment_schema();
        self::upgrade_areas_workspace_schema();
        self::upgrade_trip_staff_schema();
        self::ensure_legacy_assignment_links();
    }

    private static function allow_missing_family_coordinates()
    {
        global $wpdb;
        $table = self::table('family_stops');
        // dbDelta does not reliably relax NOT NULL columns on existing sites.
        $wpdb->query("ALTER TABLE {$table} MODIFY latitude decimal(10,7) NULL DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table} MODIFY longitude decimal(10,7) NULL DEFAULT NULL");
    }

    private static function upgrade_area_assignment_schema()
    {
        global $wpdb;
        $table = self::table('area_bus_assignments');
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $by_name = array();
        foreach ($indexes as $index) {
            $by_name[$index['Key_name']][] = $index;
        }
        if (isset($by_name['year_direction_area_bus'])) {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX year_direction_area_bus");
        }
        if (!isset($by_name['year_direction_area'])) {
            // Existing installations should not normally contain duplicates. If
            // they do, retain every row and let the service deactivate extras;
            // the migration remains non-destructive and can be rerun after review.
            $duplicates = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM (SELECT 1 FROM {$table} GROUP BY academic_year_id,direction,major_area_id HAVING COUNT(*) > 1) d"
            );
            if (!$duplicates) {
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY year_direction_area (academic_year_id,direction,major_area_id)");
            }
        }
    }

    private static function upgrade_areas_workspace_schema()
    {
        global $wpdb;
        $areas = self::table('major_areas');
        $buses = self::table('buses');
        $assignments = self::table('area_bus_assignments');
        // dbDelta does not remove the former one-area-per-direction constraint.
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$assignments}", ARRAY_A);
        foreach ($indexes as $index) {
            if ($index['Key_name'] === 'year_direction_area') {
                $wpdb->query("ALTER TABLE {$assignments} DROP INDEX year_direction_area");
                break;
            }
        }
        $columns = function ($table) use ($wpdb) { return $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0); };
        if (!in_array('area_type', $columns($areas), true)) $wpdb->query("ALTER TABLE {$areas} ADD COLUMN area_type varchar(20) NOT NULL DEFAULT 'secondary'");
        if (!in_array('allow_multi_area', $columns($buses), true)) $wpdb->query("ALTER TABLE {$buses} ADD COLUMN allow_multi_area tinyint(1) NOT NULL DEFAULT 0");
        if (!in_array('main_area_id', $columns($buses), true)) $wpdb->query("ALTER TABLE {$buses} ADD COLUMN main_area_id bigint(20) UNSIGNED DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$buses} ALTER morning_trip_count SET DEFAULT 3");
        $wpdb->query("ALTER TABLE {$buses} ALTER afternoon_trip_count SET DEFAULT 3");
        $wpdb->query("UPDATE {$buses} SET morning_trip_count=3 WHERE morning_trip_count<3");
        $wpdb->query("UPDATE {$buses} SET afternoon_trip_count=3 WHERE afternoon_trip_count<3");
        if (!in_array('arrival_time', $columns($assignments), true)) $wpdb->query("ALTER TABLE {$assignments} ADD COLUMN arrival_time time DEFAULT NULL");
        if (!in_array('departure_time', $columns($assignments), true)) $wpdb->query("ALTER TABLE {$assignments} ADD COLUMN departure_time time DEFAULT NULL");
        $indexes = $wpdb->get_col("SHOW INDEX FROM {$assignments}", 2);
        if (!in_array('area_year_direction', $indexes, true)) $wpdb->query("ALTER TABLE {$assignments} ADD KEY area_year_direction (major_area_id,academic_year_id,direction,status)");
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

    /** Move operational companion ownership from the vehicle to each trip. */
    private static function upgrade_trip_staff_schema()
    {
        global $wpdb;
        $trips = self::table('shared_trips');
        $buses = self::table('buses');
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$trips}", 0);
        if (!in_array('companion_user_id', $columns, true)) {
            $wpdb->query("ALTER TABLE {$trips} ADD COLUMN companion_user_id bigint(20) UNSIGNED DEFAULT NULL AFTER bus_trip_number");
            // A bus's former companion is the safest initial value for its existing trips.
            $wpdb->query("UPDATE {$trips} t INNER JOIN {$buses} b ON b.id=t.bus_id SET t.companion_user_id=b.companion_user_id WHERE t.companion_user_id IS NULL AND b.companion_user_id IS NOT NULL");
        }
        $indexes = $wpdb->get_col("SHOW INDEX FROM {$trips}", 2);
        if (!in_array('companion_user_id', $indexes, true)) {
            $wpdb->query("ALTER TABLE {$trips} ADD KEY companion_user_id (companion_user_id)");
        }
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
                direction varchar(20) NOT NULL DEFAULT 'morning',
                trip_number tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
                pickup_location varchar(255) DEFAULT NULL,
                dropoff_location varchar(255) DEFAULT NULL,
                notes text DEFAULT NULL,
                assigned_at datetime NOT NULL,
                assigned_by bigint(20) UNSIGNED NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY student_year_direction (student_uid, academic_year_id, direction),
                KEY bus_trip (bus_id, academic_year_id, direction, trip_number)
            ) {$wpdb->get_charset_collate()};");
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!in_array('direction', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD direction varchar(20) NOT NULL DEFAULT 'morning' AFTER academic_year_id");
        }
        if (!in_array('trip_number', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD trip_number tinyint(3) UNSIGNED NOT NULL DEFAULT 1 AFTER direction");
        }
        if (!in_array('shared_trip_id', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD shared_trip_id bigint(20) UNSIGNED DEFAULT NULL AFTER trip_number");
        }
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $index_names = array_unique(wp_list_pluck($indexes, 'Key_name'));
        if (in_array('student_uid_year', $index_names, true)) {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX student_uid_year");
        }
        if (!in_array('student_year_direction', $index_names, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY student_year_direction (student_uid, academic_year_id, direction)");
        }
        if (!in_array('bus_trip', $index_names, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD KEY bus_trip (bus_id, academic_year_id, direction, trip_number)");
        }
        if (!in_array('shared_trip_id', $index_names, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD KEY shared_trip_id (shared_trip_id)");
        }

        $students_table = olama_core()->read_models()->table('students');
        if (olama_core()->read_models()->available('students')) {
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
