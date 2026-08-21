<?php

class ORSIntegrationTest extends WP_UnitTestCase
{
    public function test_ors_payload_uses_longitude_latitude_and_stop_id()
    {
        $payload = Olama_Transportation_Optimizer::build_ors_payload(array('bus_id'=>5), array(
            'profile'=>'driving-car', 'depot'=>array(35.9,31.9),
            'stops'=>array(array('stop_id'=>88,'latitude'=>31.899234,'longitude'=>35.961736,'service'=>60)),
        ));
        $this->assertSame(array(35.961736,31.899234), $payload['jobs'][0]['location']);
        $this->assertSame(88, $payload['jobs'][0]['id']);
        $this->assertSame(array(35.9,31.9), $payload['vehicles'][0]['start']);
        $this->assertArrayNotHasKey('family_name', $payload['jobs'][0]);
    }

    public function test_ors_response_rejects_missing_unknown_and_duplicate_jobs()
    {
        $this->assertWPError(Olama_Transportation_Optimizer::parse_ors_order(array('routes'=>array(array('steps'=>array(array('type'=>'job','id'=>88))))), array(88,99)));
        $this->assertWPError(Olama_Transportation_Optimizer::parse_ors_order(array('routes'=>array(array('steps'=>array(array('type'=>'job','id'=>88),array('type'=>'job','id'=>77))))), array(88,99)));
        $this->assertWPError(Olama_Transportation_Optimizer::parse_ors_order(array('routes'=>array(array('steps'=>array(array('type'=>'job','id'=>88),array('type'=>'job','id'=>88))))), array(88,89)));
        $this->assertWPError(Olama_Transportation_Optimizer::parse_ors_order(array('unassigned'=>array(99)), array(88)));
    }

    public function test_directions_metrics_are_normalized()
    {
        $result = Olama_Transportation_Optimizer::parse_directions(array('features'=>array(array('geometry'=>array('type'=>'LineString','coordinates'=>array(array(35.9,31.9))),'properties'=>array('summary'=>array('distance'=>17400,'duration'=>2340),'segments'=>array(array('distance'=>1000,'duration'=>60)))))));
        $this->assertSame(17400, $result['distance_m']);
        $this->assertSame(2340, $result['duration_seconds']);
        $this->assertSame(1000, $result['legs'][0]['distance_m']);
    }
}
