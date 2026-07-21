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
}
