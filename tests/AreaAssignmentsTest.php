<?php

class Olama_Transportation_Area_Assignments_Test extends WP_UnitTestCase
{
    private $year_id;
    private $area_one;
    private $area_two;
    private $bus_id;

    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        Olama_Transportation_DB::install();
        $now = current_time('mysql', true);
        $wpdb->insert($wpdb->prefix . 'olama_academic_years', array(
            'code' => '2026-2027', 'year_name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31', 'created_at' => $now, 'updated_at' => $now,
        ));
        $this->year_id = (int) $wpdb->insert_id;
        $this->area_one = $this->create_area('Area One');
        $this->area_two = $this->create_area('Area Two');
        $this->bus_id = $this->create_bus(20, 0, 2, 3);
    }

    private function create_area($name, $status = 'active', $mapped = true)
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(Olama_Transportation_DB::table('major_areas'), array(
            'name' => $name, 'code' => strtoupper(str_replace(' ', '-', $name)) . '-' . wp_generate_password(4, false), 'status' => $status, 'created_at' => $now, 'updated_at' => $now,
        ));
        $area_id = (int) $wpdb->insert_id;
        if ($mapped) {
            $wpdb->insert(Olama_Transportation_DB::table('area_mappings'), array(
                'oracle_region_id' => 'REGION-' . wp_generate_uuid4(), 'oracle_region_name' => $name,
                'major_area_id' => $area_id, 'created_at' => $now, 'updated_at' => $now,
            ));
        }
        return $area_id;
    }

    private function create_bus($capacity, $planning = 0, $morning = 2, $afternoon = 3, $status = 'active')
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(Olama_Transportation_DB::table('buses'), array(
            'core_bus_uid' => 'BUS-' . wp_generate_uuid4(), 'bus_number' => 'Bus ' . wp_generate_password(4, false),
            'passenger_capacity' => $capacity, 'planning_capacity' => $planning, 'morning_trip_count' => $morning,
            'afternoon_trip_count' => $afternoon, 'status' => $status, 'created_at' => $now, 'updated_at' => $now,
        ));
        return (int) $wpdb->insert_id;
    }

    private function create_stop($area_id = null, $source = 'core', $suffix = '')
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $uid = 'FAM-' . ($suffix ?: wp_generate_password(5, false));
        $oracle = 'ORA-' . $uid;
        $wpdb->insert(olama_core()->read_models()->table('families'), array(
            'family_uid' => $uid, 'oracle_family_id' => $oracle, 'sponsor_full_name' => $uid,
            'trans_region_id' => 'R-' . $uid, 'trans_region_name' => 'Oracle Region', 'created_at' => $now, 'updated_at' => $now,
        ));
        $wpdb->insert(Olama_Transportation_DB::table('family_stops'), array(
            'family_uid' => $uid, 'oracle_family_id' => $oracle, 'latitude' => 31.95, 'longitude' => 35.91,
            'major_area_id' => $area_id, 'area_assignment_source' => $source, 'verification_status' => 'approved', 'created_at' => $now, 'updated_at' => $now,
        ));
        return array('id' => (int) $wpdb->insert_id, 'family_uid' => $uid, 'oracle_family_id' => $oracle);
    }

    private function add_demand($stop, $count, $morning = 1, $afternoon = 1)
    {
        global $wpdb;
        $now = current_time('mysql', true);
        for ($i = 1; $i <= $count; $i++) {
            $wpdb->insert(Olama_Transportation_DB::table('enrollments'), array(
                'student_uid' => $stop['family_uid'] . '-S' . $i, 'family_uid' => $stop['family_uid'], 'oracle_family_id' => $stop['oracle_family_id'],
                'oracle_student_id' => 'OS-' . $stop['family_uid'] . '-' . $i, 'academic_year_id' => $this->year_id,
                'morning_enabled' => $morning, 'afternoon_enabled' => $afternoon, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ));
        }
    }

    private function assignment($area, $bus = null, $trip = 1, $direction = 'morning')
    {
        return array('academic_year_id' => $this->year_id, 'direction' => $direction, 'major_area_id' => $area, 'bus_id' => $bus ?: $this->bus_id, 'trip_number' => $trip);
    }

    private function create_shared_trip($direction = 'morning', $limit = 35)
    {
        $trip = Olama_Transportation_Shared_Trips::create(array(
            'academic_year_id' => $this->year_id, 'direction' => $direction, 'planning_limit' => $limit,
        ));
        $this->assertFalse(is_wp_error($trip));
        return $trip;
    }

    private function add_shared_member($trip_id, $area_id, $number, $family_uid = null)
    {
        global $wpdb;
        $family_uid = $family_uid ?: 'SHARED-FAM-' . $number;
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . Olama_Transportation_DB::table('shared_trip_areas') . ' (trip_id,major_area_id,created_at) VALUES (%d,%d,%s)',
            $trip_id, $area_id, current_time('mysql', true)
        ));
        $wpdb->insert(Olama_Transportation_DB::table('shared_trip_students'), array(
            'trip_id' => $trip_id, 'student_id' => $number, 'student_uid' => 'SHARED-STUDENT-' . $trip_id . '-' . $number,
            'oracle_student_id' => 'OS-' . $number, 'student_name' => 'Student ' . $number,
            'family_uid' => $family_uid, 'oracle_family_id' => 'OF-' . $family_uid,
            'major_area_id' => $area_id, 'grade_name' => 'Grade', 'section_name' => 'A', 'created_at' => current_time('mysql', true),
        ));
    }

    public function test_shared_trip_owns_companion_and_exposes_bus_driver_names()
    {
        global $wpdb;
        $driver = self::factory()->user->create(array('display_name' => 'Test Driver'));
        $companion = self::factory()->user->create(array('display_name' => 'Test Companion'));
        $wpdb->update(Olama_Transportation_DB::table('buses'), array('driver_user_id' => $driver), array('id' => $this->bus_id));
        $trip = $this->create_shared_trip();
        $wpdb->update(Olama_Transportation_DB::table('shared_trips'), array(
            'bus_id' => $this->bus_id, 'bus_trip_number' => 1, 'companion_user_id' => $companion,
        ), array('id' => $trip['id']));

        $saved = Olama_Transportation_Shared_Trips::get($trip['id']);
        $this->assertSame($companion, $saved['companion_user_id']);
        $this->assertSame('Test Driver', $saved['driver_name']);
        $this->assertSame('Test Companion', $saved['companion_name']);
        $this->assertContains('companion_user_id', $wpdb->get_col('SHOW COLUMNS FROM ' . Olama_Transportation_DB::table('shared_trips'), 0));
    }

    public function test_family_stop_can_be_manually_assigned_to_active_area()
    {
        $stop = $this->create_stop();
        $result = Olama_Transportation_Family_Area_Assignments::assign($stop['id'], $this->area_one);
        $this->assertFalse(is_wp_error($result));
        $row = Olama_Transportation_Repository::get_item('family-stops', $stop['id']);
        $this->assertSame($this->area_one, (int) $row['major_area_id']);
        $this->assertSame('manual', $row['area_assignment_source']);
        $this->assertSame('approved', $row['verification_status']);
        $this->assertNotEmpty($row['verified_at']);
    }

    public function test_shared_trip_limit_is_a_soft_warning()
    {
        $trip = $this->create_shared_trip('morning', 35);
        for ($index = 1; $index <= 37; $index++) {
            $this->add_shared_member($trip['id'], $index <= 10 ? $this->area_one : $this->area_two, $index);
        }
        $trip = Olama_Transportation_Shared_Trips::get($trip['id']);
        $this->assertSame(37, $trip['student_count']);
        $this->assertSame(2, $trip['trip_excess']);
        $this->assertSame(2, $trip['area_count']);
    }

    public function test_shared_trip_list_exposes_attached_areas()
    {
        $trip = Olama_Transportation_Shared_Trips::create(array(
            'academic_year_id' => $this->year_id,
            'direction' => 'morning',
        ));
        $trip = Olama_Transportation_Shared_Trips::save_areas($trip['id'], array($this->area_one, $this->area_two));
        $this->add_shared_member($trip['id'], $this->area_two, 1);
        $listed = array_values(array_filter(
            Olama_Transportation_Shared_Trips::list_for_context($this->year_id, 'morning'),
            function ($item) use ($trip) { return $item['id'] === $trip['id']; }
        ))[0];

        $this->assertSame(array($this->area_one, $this->area_two), $listed['area_ids']);
    }

    public function test_area_workspace_reports_assigned_and_unassigned_students()
    {
        $stop = $this->create_stop($this->area_one, 'manual', 'AREA-SUMMARY');
        $this->add_demand($stop, 3);
        $trip = $this->create_shared_trip('morning');
        Olama_Transportation_Shared_Trips::save_areas($trip['id'], array($this->area_one));
        $this->add_shared_member($trip['id'], $this->area_one, 1, $stop['family_uid']);

        $overview = Olama_Transportation_Areas_Workspace::overview($this->year_id, 'morning');
        $area = array_values(array_filter($overview['areas'], function ($candidate) {
            return (int) $candidate['id'] === $this->area_one;
        }))[0];

        $this->assertSame(3, (int) $area['student_count']);
        $this->assertSame(1, $area['assigned_student_count']);
        $this->assertSame(2, $area['unassigned_student_count']);
    }

    public function test_replacing_bus_preserves_trip_areas_and_students()
    {
        $trip = $this->create_shared_trip('morning');
        $this->add_shared_member($trip['id'], $this->area_one, 1);
        $first = Olama_Transportation_Shared_Trips::update($trip['id'], array('bus_id'=>$this->bus_id,'bus_trip_number'=>1));
        $replacement_bus = $this->create_bus(30, 0, 2, 3);
        $replaced = Olama_Transportation_Shared_Trips::update($trip['id'], array('bus_id'=>$replacement_bus,'bus_trip_number'=>1));

        $this->assertFalse(is_wp_error($first));
        $this->assertFalse(is_wp_error($replaced));
        $this->assertSame($replacement_bus, $replaced['bus_id']);
        $this->assertSame(array($this->area_one), $replaced['area_ids']);
        $this->assertSame(1, $replaced['student_count']);
    }

    public function test_shared_trip_queue_locks_school_to_direction_anchor()
    {
        $stop = $this->create_stop($this->area_one, 'core', 'QUEUE');
        $morning = $this->create_shared_trip('morning');
        $this->add_shared_member($morning['id'], $this->area_one, 1, $stop['family_uid']);
        $morning = Olama_Transportation_Shared_Trips::build_queue($morning['id']);
        $morning_queue = $morning['queue'];
        $this->assertSame('school', end($morning_queue)['node_type']);
        $morning_family = array_values(array_filter($morning_queue, function ($node) {
            return $node['node_type'] === 'family';
        }))[0];
        $this->assertSame($stop['oracle_family_id'], $morning_family['oracle_family_id']);
        $this->assertSame(array('Student 1'), $morning_family['student_names']);

        $afternoon = $this->create_shared_trip('afternoon');
        $this->add_shared_member($afternoon['id'], $this->area_one, 2, $stop['family_uid']);
        $afternoon = Olama_Transportation_Shared_Trips::build_queue($afternoon['id']);
        $afternoon_queue = $afternoon['queue'];
        $this->assertSame('school', reset($afternoon_queue)['node_type']);
    }

    public function test_multiple_families_move_atomically_between_compatible_trips()
    {
        global $wpdb;
        $source = $this->create_shared_trip('morning');
        $destination = $this->create_shared_trip('morning');
        $destination_bus = $this->create_bus(20);
        $wpdb->update(Olama_Transportation_DB::table('shared_trips'), array('bus_id'=>$this->bus_id,'bus_trip_number'=>1), array('id'=>$source['id']));
        $wpdb->update(Olama_Transportation_DB::table('shared_trips'), array('bus_id'=>$destination_bus,'bus_trip_number'=>1), array('id'=>$destination['id']));
        Olama_Transportation_Shared_Trips::save_areas($source['id'], array($this->area_one));
        Olama_Transportation_Shared_Trips::save_areas($destination['id'], array($this->area_one));
        $first = $this->create_stop($this->area_one, 'core', 'MOVE-A');
        $second = $this->create_stop($this->area_one, 'core', 'MOVE-B');
        $this->add_shared_member($source['id'], $this->area_one, 101, $first['family_uid']);
        $this->add_shared_member($source['id'], $this->area_one, 102, $first['family_uid']);
        $this->add_shared_member($source['id'], $this->area_one, 103, $second['family_uid']);

        $result = Olama_Transportation_Family_Move::move(array(
            'source_trip_id'=>$source['id'], 'destination_trip_id'=>$destination['id'],
            'family_uids'=>array($first['family_uid'], $second['family_uid']), 'reason'=>'Test move',
        ));

        $this->assertFalse(is_wp_error($result));
        $this->assertSame(2, $result['moved_family_count']);
        $this->assertSame(3, $result['moved_student_count']);
        $this->assertSame(0, Olama_Transportation_Shared_Trips::get($source['id'])['student_count']);
        $this->assertSame(3, Olama_Transportation_Shared_Trips::get($destination['id'])['student_count']);
        $this->assertCount(2, $result['destination_trip']['families']);
        $this->assertTrue($result['routes_need_recalculation']);
    }

    public function test_family_move_adds_a_different_planning_area_to_the_destination()
    {
        global $wpdb;
        $source = $this->create_shared_trip('morning');
        $destination = $this->create_shared_trip('morning');
        $wpdb->update(Olama_Transportation_DB::table('shared_trips'), array('bus_id'=>$this->bus_id,'bus_trip_number'=>1), array('id'=>$destination['id']));
        Olama_Transportation_Shared_Trips::save_areas($source['id'], array($this->area_one));
        Olama_Transportation_Shared_Trips::save_areas($destination['id'], array($this->area_two));
        $family = $this->create_stop($this->area_one, 'core', 'MOVE-AREA');
        $this->add_shared_member($source['id'], $this->area_one, 201, $family['family_uid']);

        $result = Olama_Transportation_Family_Move::move(array(
            'source_trip_id'=>$source['id'], 'destination_trip_id'=>$destination['id'], 'family_uids'=>array($family['family_uid']),
        ));

        $this->assertFalse(is_wp_error($result));
        $this->assertSame(array($this->area_one), $result['added_area_ids']);
        $this->assertSame(0, Olama_Transportation_Shared_Trips::get($source['id'])['student_count']);
        $this->assertSame(1, Olama_Transportation_Shared_Trips::get($destination['id'])['student_count']);
        $this->assertContains($this->area_one, Olama_Transportation_Shared_Trips::get($destination['id'])['area_ids']);
    }

    public function test_family_move_rechecks_destination_capacity_inside_transaction()
    {
        global $wpdb;
        $source = $this->create_shared_trip('morning');
        $destination = $this->create_shared_trip('morning', 2);
        $large_bus = $this->create_bus(20);
        $wpdb->update(Olama_Transportation_DB::table('shared_trips'), array('bus_id'=>$large_bus,'bus_trip_number'=>1), array('id'=>$destination['id']));
        Olama_Transportation_Shared_Trips::save_areas($source['id'], array($this->area_one));
        Olama_Transportation_Shared_Trips::save_areas($destination['id'], array($this->area_one));
        $moving = $this->create_stop($this->area_one, 'core', 'MOVE-CAPACITY');
        $existing = $this->create_stop($this->area_one, 'core', 'MOVE-EXISTING');
        $this->add_shared_member($source['id'], $this->area_one, 301, $moving['family_uid']);
        $this->add_shared_member($source['id'], $this->area_one, 302, $moving['family_uid']);
        $this->add_shared_member($destination['id'], $this->area_one, 303, $existing['family_uid']);

        $result = Olama_Transportation_Family_Move::move(array(
            'source_trip_id'=>$source['id'], 'destination_trip_id'=>$destination['id'], 'family_uids'=>array($moving['family_uid']),
        ));

        $this->assertWPError($result);
        $this->assertSame('family_move_trip_limit', $result->get_error_code());
        $this->assertSame(2, Olama_Transportation_Shared_Trips::get($source['id'])['student_count']);
        $this->assertSame(1, Olama_Transportation_Shared_Trips::get($destination['id'])['student_count']);
    }

    public function test_family_move_warns_when_destination_bus_capacity_would_be_exceeded()
    {
        global $wpdb;
        $source = $this->create_shared_trip('morning', 20);
        $destination = $this->create_shared_trip('morning', 20);
        $small_bus = $this->create_bus(2);
        $wpdb->update(Olama_Transportation_DB::table('shared_trips'), array('bus_id'=>$small_bus,'bus_trip_number'=>1), array('id'=>$destination['id']));
        Olama_Transportation_Shared_Trips::save_areas($source['id'], array($this->area_one));
        Olama_Transportation_Shared_Trips::save_areas($destination['id'], array($this->area_one));
        $moving = $this->create_stop($this->area_one, 'core', 'MOVE-BUS-CAPACITY');
        $existing = $this->create_stop($this->area_one, 'core', 'MOVE-BUS-EXISTING');
        $this->add_shared_member($source['id'], $this->area_one, 311, $moving['family_uid']);
        $this->add_shared_member($source['id'], $this->area_one, 312, $moving['family_uid']);
        $this->add_shared_member($destination['id'], $this->area_one, 313, $existing['family_uid']);

        $result = Olama_Transportation_Family_Move::move(array(
            'source_trip_id'=>$source['id'], 'destination_trip_id'=>$destination['id'], 'family_uids'=>array($moving['family_uid']),
        ));

        $this->assertWPError($result);
        $this->assertSame('family_move_bus_capacity', $result->get_error_code());
        $this->assertStringContainsString('1 student', $result->get_error_message());
        $this->assertSame(2, Olama_Transportation_Shared_Trips::get($source['id'])['student_count']);
        $this->assertSame(1, Olama_Transportation_Shared_Trips::get($destination['id'])['student_count']);
    }

    public function test_published_shared_trip_can_return_to_draft_for_editing()
    {
        global $wpdb;
        $trip = $this->create_shared_trip('morning');
        $now = current_time('mysql', true);
        $wpdb->update(Olama_Transportation_DB::table('shared_trips'), array(
            'status' => 'published', 'published_by' => 1, 'published_at' => $now,
        ), array('id' => $trip['id']));
        $assignments = $wpdb->prefix . 'olama_student_bus_assignments';
        $wpdb->insert($assignments, array(
            'student_id' => 9001, 'student_uid' => 'REOPEN-STUDENT-' . $trip['id'],
            'bus_id' => $this->bus_id, 'academic_year_id' => $this->year_id,
            'direction' => 'morning', 'trip_number' => 1, 'shared_trip_id' => $trip['id'],
            'assigned_at' => $now, 'assigned_by' => 1,
        ));

        $reopened = Olama_Transportation_Shared_Trips::return_to_draft($trip['id']);

        $this->assertFalse(is_wp_error($reopened));
        $this->assertSame('draft', $reopened['status']);
        $this->assertNull($reopened['published_by']);
        $this->assertNull($reopened['published_at']);
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$assignments} WHERE shared_trip_id=%d",
            $trip['id']
        )));
    }

    public function test_only_draft_shared_trips_can_be_deleted()
    {
        global $wpdb;
        $draft = $this->create_shared_trip('morning');
        $this->add_shared_member($draft['id'], $this->area_one, 1);

        $deleted = Olama_Transportation_Shared_Trips::delete_draft($draft['id']);

        $this->assertTrue($deleted['deleted']);
        $this->assertNull(Olama_Transportation_Shared_Trips::get($draft['id']));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Olama_Transportation_DB::table('shared_trip_students') . ' WHERE trip_id=%d',
            $draft['id']
        )));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Olama_Transportation_DB::table('shared_trip_areas') . ' WHERE trip_id=%d',
            $draft['id']
        )));

        $published = $this->create_shared_trip('morning');
        $wpdb->update(Olama_Transportation_DB::table('shared_trips'), array('status' => 'published'), array('id' => $published['id']));
        $result = Olama_Transportation_Shared_Trips::delete_draft($published['id']);
        $this->assertWPError($result);
        $this->assertSame('published_shared_trip_delete_forbidden', $result->get_error_code());
    }

    public function test_family_without_location_can_be_classified_by_uid()
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $uid = 'FAM-NO-LOCATION';
        $wpdb->insert(olama_core()->read_models()->table('families'), array(
            'family_uid' => $uid, 'oracle_family_id' => 'ORA-NO-LOCATION', 'sponsor_full_name' => 'No Location',
            'trans_region_name' => 'Oracle Region', 'created_at' => $now, 'updated_at' => $now,
        ));
        $result = Olama_Transportation_Family_Area_Assignments::assign_family($uid, $this->area_one, $this->year_id);
        $this->assertFalse(is_wp_error($result));
        $stop = $result['family_stop'];
        $this->assertNull($stop['latitude']);
        $this->assertNull($stop['longitude']);
        $this->assertSame($this->area_one, (int) $stop['major_area_id']);
        $this->assertSame('planning_placeholder', $stop['source']);
    }

    public function test_bulk_family_uid_assignment_is_atomic_when_one_family_is_missing()
    {
        $stop = $this->create_stop(null, 'core', 'UIDATOMIC');
        $result = Olama_Transportation_Family_Area_Assignments::bulk_assign_families(array($stop['family_uid'], 'DOES-NOT-EXIST'), $this->area_one, $this->year_id);
        $this->assertWPError($result);
        $this->assertNull(Olama_Transportation_Repository::get_item('family-stops', $stop['id'])['major_area_id']);
    }

    public function test_inactive_area_is_rejected()
    {
        $result = Olama_Transportation_Family_Area_Assignments::assign($this->create_stop()['id'], $this->create_area('Inactive', 'inactive'));
        $this->assertWPError($result);
        $this->assertSame('invalid_planning_area', $result->get_error_code());
    }

    public function test_missing_area_is_rejected()
    {
        $this->assertWPError(Olama_Transportation_Family_Area_Assignments::assign($this->create_stop()['id'], 999999));
    }

    public function test_local_only_area_is_rejected_for_family_assignment()
    {
        $result = Olama_Transportation_Family_Area_Assignments::assign(
            $this->create_stop()['id'],
            $this->create_area('Local Only', 'active', false)
        );

        $this->assertWPError($result);
        $this->assertSame('invalid_planning_area', $result->get_error_code());
    }

    public function test_clearing_family_area_preserves_coordinates()
    {
        $stop = $this->create_stop($this->area_one, 'manual');
        Olama_Transportation_Family_Area_Assignments::assign($stop['id'], 0);
        $row = Olama_Transportation_Repository::get_item('family-stops', $stop['id']);
        $this->assertNull($row['major_area_id']);
        $this->assertEquals(31.95, $row['latitude']);
    }

    public function test_bulk_assignment_is_atomic_when_one_stop_is_missing()
    {
        $stop = $this->create_stop();
        $result = Olama_Transportation_Family_Area_Assignments::bulk_assign(array($stop['id'], 999999), $this->area_one);
        $this->assertWPError($result);
        $this->assertNull(Olama_Transportation_Repository::get_item('family-stops', $stop['id'])['major_area_id']);
    }

    public function test_bulk_assignment_updates_every_valid_stop()
    {
        $one = $this->create_stop(null, 'core', 'B1'); $two = $this->create_stop(null, 'core', 'B2');
        $result = Olama_Transportation_Family_Area_Assignments::bulk_assign(array($one['id'], $two['id']), $this->area_two);
        $this->assertSame(2, $result['updated']);
    }

    public function test_core_backfill_only_updates_unassigned_stops()
    {
        global $wpdb;
        $manual = $this->create_stop($this->area_two, 'manual', 'M1');
        $empty = $this->create_stop(null, 'core', 'M2');
        $wpdb->insert(Olama_Transportation_DB::table('area_mappings'), array('oracle_region_id' => 'R-' . $empty['family_uid'], 'major_area_id' => $this->area_one, 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
        Olama_Transportation_Area_Sync::backfill_family_stops(false);
        $this->assertSame($this->area_two, (int) Olama_Transportation_Repository::get_item('family-stops', $manual['id'])['major_area_id']);
        $this->assertSame($this->area_one, (int) Olama_Transportation_Repository::get_item('family-stops', $empty['id'])['major_area_id']);
    }

    public function test_core_backfill_records_core_source()
    {
        global $wpdb;
        $stop = $this->create_stop(null, 'core', 'C1');
        $wpdb->insert(Olama_Transportation_DB::table('area_mappings'), array('oracle_region_id' => 'R-' . $stop['family_uid'], 'major_area_id' => $this->area_one, 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
        Olama_Transportation_Area_Sync::backfill_family_stops(false);
        $this->assertSame('core', Olama_Transportation_Repository::get_item('family-stops', $stop['id'])['area_assignment_source']);
    }

    public function test_coordinate_save_preserves_manual_area_metadata()
    {
        $stop = $this->create_stop($this->area_one, 'manual', 'COORD');
        $result = Olama_Transportation_Family_Locations::save($stop['family_uid'], '31.9600,35.9200');
        $this->assertFalse(is_wp_error($result));
        $row = Olama_Transportation_Repository::get_item('family-stops', $stop['id']);
        $this->assertSame($this->area_one, (int) $row['major_area_id']);
        $this->assertSame('manual', $row['area_assignment_source']);
    }

    public function test_area_can_have_separate_morning_and_afternoon_rows()
    {
        $this->assertFalse(is_wp_error(Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one))));
        $this->assertFalse(is_wp_error(Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one, null, 1, 'afternoon'))));
    }

    public function test_area_save_uses_upsert_for_same_year_and_direction()
    {
        global $wpdb;
        Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one));
        Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one, null, 2));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Olama_Transportation_DB::table('area_bus_assignments') . ' WHERE academic_year_id=%d AND direction=\'morning\' AND major_area_id=%d', $this->year_id, $this->area_one)));
    }

    public function test_multiple_areas_can_share_one_bus_trip()
    {
        $this->assertFalse(is_wp_error(Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one))));
        $this->assertFalse(is_wp_error(Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_two))));
    }

    public function test_student_assignment_screen_can_attach_another_area_to_a_defined_trip()
    {
        $this->assertFalse(is_wp_error(Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one))));
        $result = Olama_Transportation_Bus::attach_area_to_trip($this->bus_id, $this->year_id, 'morning', 1, $this->area_two);
        $this->assertFalse(is_wp_error($result));
        $trips = Olama_Transportation_Bus::get_assignment_trips($this->bus_id, $this->year_id);
        $this->assertCount(1, $trips);
        $this->assertSame(2, $trips[0]['area_count']);
    }

    public function test_student_assignment_screen_does_not_create_an_undefined_trip()
    {
        $result = Olama_Transportation_Bus::attach_area_to_trip($this->bus_id, $this->year_id, 'morning', 1, $this->area_two);
        $this->assertWPError($result);
        $this->assertSame('trip_not_defined', $result->get_error_code());
    }

    public function test_combined_area_demand_is_aggregated_per_trip()
    {
        $one = $this->create_stop($this->area_one, 'manual', 'D1'); $this->add_demand($one, 3);
        $two = $this->create_stop($this->area_two, 'manual', 'D2'); $this->add_demand($two, 4);
        Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one));
        $result = Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_two));
        $this->assertSame(7, $result['capacity']['resulting_used_seats']);
    }

    public function test_overcapacity_assignment_is_rejected()
    {
        $bus = $this->create_bus(5); $stop = $this->create_stop($this->area_one, 'manual', 'OVER'); $this->add_demand($stop, 6);
        $result = Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one, $bus));
        $this->assertWPError($result); $this->assertSame('capacity_exceeded', $result->get_error_code());
    }

    public function test_edit_excludes_previous_area_demand()
    {
        $stop = $this->create_stop($this->area_one, 'manual', 'EDIT'); $this->add_demand($stop, 12);
        Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one, $this->bus_id, 1));
        $result = Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one, $this->bus_id, 2));
        $this->assertSame(12, $result['capacity']['resulting_used_seats']);
    }

    public function test_capacity_preview_does_not_write_assignment()
    {
        global $wpdb;
        $before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Olama_Transportation_DB::table('area_bus_assignments'));
        $preview = Olama_Transportation_Area_Trip_Assignments::preview($this->assignment($this->area_one));
        $this->assertFalse(is_wp_error($preview));
        $this->assertNotEmpty($preview['preview_hash']);
        $this->assertSame($before, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Olama_Transportation_DB::table('area_bus_assignments')));
    }

    public function test_stale_preview_hash_is_rejected_before_write()
    {
        global $wpdb;
        $payload = $this->assignment($this->area_one);
        $payload['preview_hash'] = str_repeat('0', 64);
        $result = Olama_Transportation_Area_Trip_Assignments::save($payload);
        $this->assertWPError($result);
        $this->assertSame('capacity_changed', $result->get_error_code());
        $this->assertSame(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Olama_Transportation_DB::table('area_bus_assignments')));
    }

    public function test_allocation_list_hides_empty_areas_and_is_paginated()
    {
        $stop = $this->create_stop($this->area_one, 'manual', 'LIST');
        $this->add_demand($stop, 1);
        $result = Olama_Transportation_Area_Trip_Assignments::list_assignments($this->year_id, 'morning', array('per_page'=>20));
        $this->assertCount(1, $result['areas']);
        $this->assertSame($this->area_one, (int) $result['areas'][0]['id']);
        $this->assertSame(1, $result['pagination']['total']);
        $all = Olama_Transportation_Area_Trip_Assignments::list_assignments($this->year_id, 'morning', array('show_all'=>1,'per_page'=>20));
        $this->assertGreaterThanOrEqual(2, $all['pagination']['total']);
    }

    public function test_missing_coordinates_do_not_prevent_area_inheritance()
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $uid = 'FAM-MISSING-INHERIT';
        $oracle = 'ORA-MISSING-INHERIT';
        $wpdb->insert(olama_core()->read_models()->table('families'), array('family_uid'=>$uid,'oracle_family_id'=>$oracle,'sponsor_full_name'=>'Missing Inherit','created_at'=>$now,'updated_at'=>$now));
        Olama_Transportation_Family_Area_Assignments::assign_family($uid, $this->area_one, $this->year_id);
        $wpdb->insert(Olama_Transportation_DB::table('enrollments'), array('student_uid'=>$uid.'-S1','family_uid'=>$uid,'oracle_family_id'=>$oracle,'oracle_student_id'=>'OS-'.$uid,'academic_year_id'=>$this->year_id,'morning_enabled'=>1,'afternoon_enabled'=>0,'status'=>'active','created_at'=>$now,'updated_at'=>$now));
        Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one));
        $resolved = Olama_Transportation_Effective_Assignments::resolve($this->year_id, 'morning');
        $family = array_values(array_filter($resolved['families'], function($item) use ($uid) { return $item['family_uid'] === $uid; }))[0];
        $this->assertSame('missing_location', $family['location_status']);
        $this->assertSame('assigned', $family['assignment_status']);
        $this->assertSame($this->bus_id, $family['bus_id']);
        $map = Olama_Transportation_Map_Data::get($this->year_id, 'morning');
        $this->assertSame(1, $map['meta']['invalid_location_count']);
        $this->assertCount(0, array_filter($map['families'], function($item) use ($uid) { return $item['family_uid'] === $uid; }));
    }

    public function test_morning_trip_number_is_validated()
    {
        $result = Olama_Transportation_Area_Trip_Assignments::preview($this->assignment($this->area_one, $this->bus_id, 3));
        $this->assertWPError($result); $this->assertSame('invalid_trip_number', $result->get_error_code());
    }

    public function test_afternoon_trip_number_is_validated()
    {
        $result = Olama_Transportation_Area_Trip_Assignments::preview($this->assignment($this->area_one, $this->bus_id, 4, 'afternoon'));
        $this->assertWPError($result); $this->assertSame('invalid_trip_number', $result->get_error_code());
    }

    public function test_inactive_bus_is_rejected()
    {
        $result = Olama_Transportation_Area_Trip_Assignments::preview($this->assignment($this->area_one, $this->create_bus(10, 0, 2, 3, 'inactive')));
        $this->assertWPError($result); $this->assertSame('bus_unavailable', $result->get_error_code());
    }

    public function test_zero_capacity_bus_is_rejected()
    {
        $result = Olama_Transportation_Area_Trip_Assignments::preview($this->assignment($this->area_one, $this->create_bus(0)));
        $this->assertWPError($result); $this->assertSame('bus_has_no_capacity', $result->get_error_code());
    }

    public function test_planning_capacity_overrides_passenger_capacity()
    {
        $result = Olama_Transportation_Area_Trip_Assignments::preview($this->assignment($this->area_one, $this->create_bus(30, 12)));
        $this->assertSame(12, $result['effective_capacity']);
    }

    public function test_effective_assignment_uses_family_stop_area_not_legacy_group()
    {
        $stop = $this->create_stop($this->area_one, 'manual', 'MAP'); $this->add_demand($stop, 1);
        Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one));
        $family = Olama_Transportation_Effective_Assignments::family($this->year_id, 'morning', $stop['id']);
        $this->assertSame($this->bus_id, $family['bus_id']);
    }

    public function test_direction_specific_enrollment_counts_are_used()
    {
        $stop = $this->create_stop($this->area_one, 'manual', 'DIR'); $this->add_demand($stop, 2, 1, 0);
        $morning = Olama_Transportation_Effective_Assignments::family($this->year_id, 'morning', $stop['id']);
        $afternoon = Olama_Transportation_Effective_Assignments::family($this->year_id, 'afternoon', $stop['id']);
        $this->assertSame(2, $morning['student_count']); $this->assertNull($afternoon);
    }

    public function test_academic_registration_fallback_is_explicit()
    {
        $resolved = Olama_Transportation_Effective_Assignments::resolve($this->year_id, 'morning');
        $this->assertSame('academic_registration_fallback', $resolved['demand_mode']); $this->assertNotEmpty($resolved['warning']);
    }

    public function test_services_do_not_write_legacy_student_assignments()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_student_bus_assignments'; $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        Olama_Transportation_Family_Area_Assignments::assign($this->create_stop()['id'], $this->area_one);
        Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one));
        $this->assertSame($before, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"));
    }

    public function test_family_and_area_changes_create_audit_records()
    {
        global $wpdb;
        Olama_Transportation_Family_Area_Assignments::assign($this->create_stop()['id'], $this->area_one);
        Olama_Transportation_Area_Trip_Assignments::save($this->assignment($this->area_one));
        $actions = $wpdb->get_col("SELECT action FROM " . Olama_Transportation_DB::table('audit_log'));
        $this->assertContains('family_area_assigned', $actions); $this->assertContains('area_trip_assigned', $actions);
    }

    public function test_rest_permissions_reject_anonymous_user()
    {
        wp_set_current_user(0);
        $rest = new Olama_Transportation_REST();
        $this->assertFalse($rest->can_manage());
    }
}
