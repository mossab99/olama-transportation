<?php

class Olama_Transportation_Reports_Test extends WP_UnitTestCase
{
    private $year_id;
    private $area_id;
    private $now;

    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        Olama_Transportation_DB::install();
        $this->now = current_time('mysql', true);
        $wpdb->insert($wpdb->prefix . 'olama_academic_years', array(
            'code'=>'2026-2027', 'year_name'=>'2026-2027', 'start_date'=>'2026-08-01', 'end_date'=>'2027-07-31',
            'created_at'=>$this->now, 'updated_at'=>$this->now,
        ));
        $this->year_id = (int)$wpdb->insert_id;
        $wpdb->insert(Olama_Transportation_DB::table('major_areas'), array(
            'name'=>'Report Area', 'code'=>'REPORT-AREA-' . wp_generate_password(5, false), 'status'=>'active',
            'created_at'=>$this->now, 'updated_at'=>$this->now,
        ));
        $this->area_id = (int)$wpdb->insert_id;
    }

    public function test_reports_use_subscription_population_and_direction_specific_assignments()
    {
        $this->student('F1', 'S1', true);
        $this->transport('F1', 'S1', 1);
        $this->transport('F1', 'S1', 1); // Duplicate Oracle assignment row.
        $this->student('F2', 'S2', true);
        $this->transport('F2', 'S2', 1);
        $this->student('F3', 'S3', true);
        $this->transport('F3', 'S3', 0); // Registered but inactive: walking.
        $this->student('F5', 'S5', true); // Registered and never subscribed: walking.
        $this->transport('F4', 'S4', 1, false); // Active subscription without Core identity/year rows.

        $this->assign('F1', 'S1', 'morning');
        $this->assign('F3', 'S3', 'afternoon'); // Stale assignment for an inactive subscriber.

        $morning = Olama_Transportation_Reports::school_report($this->year_id, array('population'=>'transportation','direction'=>'morning'));
        $this->assertFalse(is_wp_error($morning));
        $this->assertSame(3, $morning['summary']['subscribed_students']);
        $this->assertSame(3, $morning['summary']['filtered_students']);
        $this->assertSame(1, $morning['summary']['assigned']);
        $this->assertSame(2, $morning['summary']['unassigned']);
        $this->assertSame(1, $morning['diagnostics']['duplicate_subscription_records']);
        $this->assertSame(1, $morning['diagnostics']['stale_assigned_students']);
        $this->assertSame(1, $morning['diagnostics']['missing_student_identity']);
        $this->assertSame(1, $morning['diagnostics']['missing_academic_registration']);

        $all = Olama_Transportation_Reports::school_report($this->year_id, array('population'=>'transportation','direction'=>'all'));
        $by_student = $this->by_student($all['rows']);
        $this->assertSame('partial', $by_student['ORA-STU-F1-S1']['assignment_status']);
        $this->assertSame('unassigned', $by_student['ORA-STU-F2-S2']['assignment_status']);
        $this->assertSame('unassigned', $by_student['ORA-STU-F4-S4']['assignment_status']);
        $this->assertSame(1, $all['summary']['partial']);
        $this->assertSame(2, $all['summary']['unassigned']);

        $walking = Olama_Transportation_Reports::school_report($this->year_id, array('population'=>'walking','direction'=>'all'));
        $walking_students = array_column($walking['rows'], 'student_uid');
        sort($walking_students);
        $this->assertSame(array('ORA-STU-F3-S3','ORA-STU-F5-S5'), $walking_students);
        $this->assertNotEmpty($this->by_student($walking['rows'])['ORA-STU-F3-S3']['departure']);
    }

    public function test_assignment_gap_scopes_are_reconcilable()
    {
        $this->student('F1', 'S1', true); $this->transport('F1', 'S1', 1); $this->assign('F1', 'S1', 'morning');
        $this->student('F2', 'S2', true); $this->transport('F2', 'S2', 1);
        $this->student('F3', 'S3', true); $this->transport('F3', 'S3', 1); $this->assign('F3', 'S3', 'morning'); $this->assign('F3', 'S3', 'afternoon');

        $none = Olama_Transportation_Reports::unassigned_report($this->year_id, 'none');
        $any = Olama_Transportation_Reports::unassigned_report($this->year_id, 'any_missing');
        $morning = Olama_Transportation_Reports::unassigned_report($this->year_id, 'morning');
        $afternoon = Olama_Transportation_Reports::unassigned_report($this->year_id, 'afternoon');

        $this->assertSame(array('ORA-STU-F2-S2'), array_column($none['rows'], 'student_uid'));
        $this->assertSame(array('ORA-STU-F1-S1','ORA-STU-F2-S2'), array_column($any['rows'], 'student_uid'));
        $this->assertSame(array('ORA-STU-F2-S2'), array_column($morning['rows'], 'student_uid'));
        $this->assertSame(array('ORA-STU-F1-S1','ORA-STU-F2-S2'), array_column($afternoon['rows'], 'student_uid'));
    }

    public function test_school_assignment_filter_never_adds_non_subscribed_trip_members()
    {
        $this->student('F1', 'S1', true); $this->transport('F1', 'S1', 1);
        $this->student('F2', 'S2', true); $this->assign('F2', 'S2', 'morning');
        $report = Olama_Transportation_Reports::school_report($this->year_id, array(
            'population'=>'transportation', 'direction'=>'morning', 'assignment_status'=>'assigned',
        ));
        $this->assertCount(0, $report['rows']);
        $this->assertSame(1, $report['diagnostics']['stale_assigned_students']);
    }

    public function test_family_search_shows_subscribed_and_walking_siblings_with_both_directions()
    {
        $this->student('F1', 'S1', true); $this->transport('F1', 'S1', 1); $this->assign('F1', 'S1', 'morning');
        $this->student('F1', 'S2', true);
        $report = Olama_Transportation_Reports::family_report($this->year_id, 'Family F1');
        $this->assertSame(1, $report['total']);
        $this->assertCount(2, $report['items'][0]['students']);
        $by_student = $this->by_student($report['items'][0]['students']);
        $this->assertTrue($by_student['ORA-STU-F1-S1']['subscribed']);
        $this->assertFalse($by_student['ORA-STU-F1-S2']['subscribed']);
        $this->assertNotEmpty($by_student['ORA-STU-F1-S1']['arrival']);
        $this->assertEmpty($by_student['ORA-STU-F1-S1']['departure']);
    }

    private function student($family, $student, $registered)
    {
        global $wpdb;
        $family_uid = 'ORA-FAM-' . $family;
        $student_uid = 'ORA-STU-' . $family . '-' . $student;
        if (!(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . olama_core()->read_models()->table('families') . ' WHERE family_uid=%s', $family_uid))) {
            $wpdb->insert(olama_core()->read_models()->table('families'), array(
                'family_uid'=>$family_uid, 'oracle_family_id'=>$family, 'sponsor_full_name'=>'Family ' . $family,
                'father_name'=>'Father ' . $family, 'created_at'=>$this->now, 'updated_at'=>$this->now,
            ));
        }
        $wpdb->insert(olama_core()->read_models()->table('students'), array(
            'family_uid'=>$family_uid, 'student_uid'=>$student_uid, 'oracle_family_id'=>$family,
            'oracle_student_id'=>$student, 'student_name'=>'Student ' . $student, 'created_at'=>$this->now, 'updated_at'=>$this->now,
        ));
        if ($registered) $wpdb->insert(olama_core()->read_models()->table('student_years'), array(
            'family_uid'=>$family_uid, 'student_uid'=>$student_uid, 'oracle_family_id'=>$family, 'oracle_student_id'=>$student,
            'study_year'=>'2026-2027', 'class_name'=>'Grade 1', 'section_name'=>'A', 'created_at'=>$this->now, 'updated_at'=>$this->now,
        ));
    }

    private function transport($family, $student, $active, $identity_exists = true)
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'olama_core_student_transportation', array(
            'family_uid'=>'ORA-FAM-' . $family, 'student_uid'=>'ORA-STU-' . $family . '-' . $student,
            'oracle_family_id'=>$family, 'oracle_student_id'=>$student, 'study_year'=>'2026-2027',
            'class_name'=>$identity_exists ? 'Grade 1' : 'KG1', 'section_name'=>'A', 'is_active'=>$active,
            'last_synced_at'=>$this->now, 'created_at'=>$this->now, 'updated_at'=>$this->now,
        ));
    }

    private function assign($family, $student, $direction)
    {
        global $wpdb;
        $wpdb->insert(Olama_Transportation_DB::table('shared_trips'), array(
            'academic_year_id'=>$this->year_id, 'direction'=>$direction, 'name'=>ucfirst($direction) . ' trip',
            'planning_limit'=>35, 'status'=>'draft', 'created_at'=>$this->now, 'updated_at'=>$this->now,
        ));
        $trip_id = (int)$wpdb->insert_id;
        $wpdb->insert(Olama_Transportation_DB::table('shared_trip_students'), array(
            'trip_id'=>$trip_id, 'student_id'=>1, 'student_uid'=>'ORA-STU-' . $family . '-' . $student,
            'oracle_student_id'=>$student, 'student_name'=>'Student ' . $student, 'family_uid'=>'ORA-FAM-' . $family,
            'oracle_family_id'=>$family, 'major_area_id'=>$this->area_id, 'created_at'=>$this->now,
        ));
    }

    private function by_student(array $rows)
    {
        $result = array();
        foreach ($rows as $row) $result[$row['student_uid']] = $row;
        return $result;
    }
}
