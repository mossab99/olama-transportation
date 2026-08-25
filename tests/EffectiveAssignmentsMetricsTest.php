<?php

class Olama_Transportation_Effective_Assignments_Metrics_Test extends WP_UnitTestCase
{
    public function test_kg_and_grade_one_labels_are_normalized()
    {
        foreach (array('KG1', 'kg1 بستان', 'kg2 تمهيدي', 'خاص KG', 'بستان', 'تمهيدي', 'أول أساسي', 'اول اساسي', 'الصف الأول', 'Grade 1', 'G1') as $grade) {
            $this->assertTrue(Olama_Transportation_Effective_Assignments::is_transport_kg_g1_grade($grade), $grade);
        }
        foreach (array('ثاني أساسي', 'Grade 2', 'عاشر أساسي') as $grade) {
            $this->assertFalse(Olama_Transportation_Effective_Assignments::is_transport_kg_g1_grade($grade), $grade);
        }
    }

    public function test_walking_is_an_identity_set_difference_not_total_subtraction()
    {
        $academic = array('student-1'=>true, 'student-2'=>true);
        $transportation = array('student-1'=>true, 'transport-only-student'=>true);

        $this->assertSame(array(
            'academic'=>2,
            'transportation'=>2,
            'walking'=>1,
        ), Olama_Transportation_Effective_Assignments::summarize_student_sets($academic, $transportation));
    }
}
