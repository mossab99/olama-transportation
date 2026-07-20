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
}
