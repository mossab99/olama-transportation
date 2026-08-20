<?php

class Olama_Transportation_Repository_Test extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Olama_Transportation_DB::install();
    }

    public function test_rejects_invalid_coordinates()
    {
        $result = Olama_Transportation_Repository::save_item('stops', array(
            'name' => 'Invalid stop',
            'code' => 'INVALID',
            'latitude' => 190,
            'longitude' => 35.9,
        ));
        $this->assertWPError($result);
        $this->assertSame('invalid_latitude', $result->get_error_code());
    }

    public function test_route_versions_are_immutable_after_publish()
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(Olama_Transportation_DB::table('route_versions'), array(
            'academic_year_id' => 1,
            'bus_id' => 1,
            'direction' => 'morning',
            'version_number' => 1,
            'name' => 'Published route',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $result = Olama_Transportation_Routes::update($wpdb->insert_id, array('name' => 'Changed'));
        $this->assertWPError($result);
        $this->assertSame('immutable_route', $result->get_error_code());
    }

    public function test_geographic_planning_schema_is_idempotent()
    {
        global $wpdb;
        Olama_Transportation_DB::install();
        Olama_Transportation_DB::install();
        $this->assertSame(
            Olama_Transportation_DB::table('planning_groups'),
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', Olama_Transportation_DB::table('planning_groups')))
        );
        $this->assertSame(
            Olama_Transportation_DB::table('planning_group_families'),
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', Olama_Transportation_DB::table('planning_group_families')))
        );
    }

    public function test_bus_trip_defaults_are_two_and_three()
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('buses');
        $now = current_time('mysql', true);
        $wpdb->insert($table, array(
            'core_bus_uid' => 'TEST-BUS-DEFAULTS', 'bus_number' => 'TEST-DEFAULTS',
            'passenger_capacity' => 20, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ));
        $bus = Olama_Transportation_Bus::get_bus($wpdb->insert_id);
        $this->assertSame(2, (int) $bus->morning_trip_count);
        $this->assertSame(3, (int) $bus->afternoon_trip_count);
    }

    public function test_local_planning_capacity_is_allowed_when_core_capacity_is_zero()
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('buses');
        $now = current_time('mysql', true);
        $wpdb->insert($table, array(
            'core_bus_uid' => 'TEST-BUS-ZERO', 'bus_number' => 'TEST-ZERO',
            'passenger_capacity' => 0, 'planning_capacity' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ));
        $id = $wpdb->insert_id;
        $result = Olama_Transportation_Bus::save_bus(array('id' => $id, 'planning_capacity' => 19));
        $this->assertSame($id, $result);
        $this->assertSame(19, (int) Olama_Transportation_Bus::get_bus($id)->planning_capacity);
    }

    public function test_planning_capacity_cannot_exceed_positive_core_capacity()
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('buses');
        $now = current_time('mysql', true);
        $wpdb->insert($table, array(
            'core_bus_uid' => 'TEST-BUS-CAPPED', 'bus_number' => 'TEST-CAPPED',
            'passenger_capacity' => 20, 'planning_capacity' => 20, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ));
        $result = Olama_Transportation_Bus::save_bus(array('id' => $wpdb->insert_id, 'planning_capacity' => 21));
        $this->assertWPError($result);
        $this->assertSame('invalid_capacity', $result->get_error_code());
    }

    public function test_zero_capacity_bus_has_no_assignable_trip_capacity()
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('buses');
        $now = current_time('mysql', true);
        $wpdb->insert($table, array(
            'core_bus_uid' => 'TEST-BUS-NONE', 'bus_number' => 'TEST-NONE',
            'passenger_capacity' => 0, 'planning_capacity' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ));
        $slots = Olama_Transportation_Geographic_Planning::trip_slots(1, 'morning', $wpdb->insert_id);
        $this->assertFalse($slots[0]['assignable']);
        $this->assertCount(2, $slots[0]['slots']);
    }

    public function test_area_sync_merges_same_name_unmapped_area_without_losing_stop_links()
    {
        global $wpdb;
        $areas = Olama_Transportation_DB::table('major_areas');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        $stops = Olama_Transportation_DB::table('stops');
        $now = current_time('mysql', true);

        $wpdb->insert($areas, array('name' => 'نادي السباق', 'code' => 'CORE-96', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now));
        $target_id = (int) $wpdb->insert_id;
        $wpdb->insert($mappings, array('oracle_region_id' => '96', 'oracle_region_name' => 'نادي السباق', 'major_area_id' => $target_id, 'created_at' => $now, 'updated_at' => $now));
        $wpdb->insert($areas, array('name' => "نادي\u{00A0}السباق", 'code' => 'LEGACY-96', 'notes' => 'Keep this note', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now));
        $duplicate_id = (int) $wpdb->insert_id;
        $wpdb->insert($stops, array('name' => 'Legacy stop', 'code' => 'LEGACY-STOP', 'latitude' => 31.95, 'longitude' => 35.91, 'major_area_id' => $duplicate_id, 'created_at' => $now, 'updated_at' => $now));

        $method = new ReflectionMethod(Olama_Transportation_Area_Sync::class, 'merge_unmapped_duplicates');
        $method->setAccessible(true);
        $this->assertSame(1, $method->invoke(null, $target_id, 'نادي السباق', $now));
        $this->assertSame($target_id, (int) $wpdb->get_var($wpdb->prepare("SELECT major_area_id FROM {$stops} WHERE code = %s", 'LEGACY-STOP')));
        $this->assertSame('inactive', $wpdb->get_var($wpdb->prepare("SELECT status FROM {$areas} WHERE id = %d", $duplicate_id)));
        $this->assertSame('Keep this note', $wpdb->get_var($wpdb->prepare("SELECT notes FROM {$areas} WHERE id = %d", $target_id)));
    }

    public function test_area_sync_does_not_merge_two_oracle_mapped_areas_with_same_name()
    {
        global $wpdb;
        $areas = Olama_Transportation_DB::table('major_areas');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        $now = current_time('mysql', true);
        $area_ids = array();
        foreach (array('96', '97') as $oracle_id) {
            $wpdb->insert($areas, array('name' => 'Shared Oracle label', 'code' => 'CORE-' . $oracle_id, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now));
            $area_ids[] = (int) $wpdb->insert_id;
            $wpdb->insert($mappings, array('oracle_region_id' => $oracle_id, 'oracle_region_name' => 'Shared Oracle label', 'major_area_id' => $wpdb->insert_id, 'created_at' => $now, 'updated_at' => $now));
        }

        $method = new ReflectionMethod(Olama_Transportation_Area_Sync::class, 'merge_unmapped_duplicates');
        $method->setAccessible(true);
        $this->assertSame(0, $method->invoke(null, $area_ids[0], 'Shared Oracle label', $now));
        $this->assertSame(2, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$areas} WHERE name = 'Shared Oracle label' AND status = 'active'"));
    }

    public function test_selectable_planning_areas_match_active_oracle_mappings()
    {
        global $wpdb;
        $areas = Olama_Transportation_DB::table('major_areas');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        $now = current_time('mysql', true);

        $wpdb->insert($areas, array('name' => 'Legacy local label', 'code' => 'ORACLE-CHOICE', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now));
        $mapped_id = (int) $wpdb->insert_id;
        $wpdb->insert($mappings, array('oracle_region_id' => 'ORA-CHOICE', 'oracle_region_name' => 'Oracle label', 'major_area_id' => $mapped_id, 'created_at' => $now, 'updated_at' => $now));

        $wpdb->insert($areas, array('name' => 'Local only', 'code' => 'LOCAL-ONLY', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now));
        $local_id = (int) $wpdb->insert_id;

        $choices = Olama_Transportation_Area_Sync::selectable_areas();
        $by_id = array();
        foreach ($choices as $choice) {
            $by_id[(int) $choice['id']] = $choice;
        }

        $this->assertArrayHasKey($mapped_id, $by_id);
        $this->assertSame('Oracle label', $by_id[$mapped_id]['name']);
        $this->assertArrayNotHasKey($local_id, $by_id);
    }

    public function test_map_data_lists_an_area_once_when_it_has_multiple_oracle_mappings()
    {
        global $wpdb;
        $areas = Olama_Transportation_DB::table('major_areas');
        $mappings = Olama_Transportation_DB::table('area_mappings');
        $now = current_time('mysql', true);
        $wpdb->insert($areas, array('name' => 'One planning area', 'code' => 'ONE-AREA', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now));
        $area_id = (int) $wpdb->insert_id;
        foreach (array('SOURCE-A', 'SOURCE-B') as $oracle_id) {
            $wpdb->insert($mappings, array('oracle_region_id' => $oracle_id, 'oracle_region_name' => 'One planning area', 'major_area_id' => $area_id, 'created_at' => $now, 'updated_at' => $now));
        }

        $method = new ReflectionMethod(Olama_Transportation_Map_Data::class, 'areas');
        $method->setAccessible(true);
        $rows = array_values(array_filter($method->invoke(null), function ($area) use ($area_id) {
            return (int) $area['id'] === $area_id;
        }));
        $this->assertCount(1, $rows);
    }
}
