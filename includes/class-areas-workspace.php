<?php

if (!defined('ABSPATH')) exit;

/** Operational area/trip workspace. Oracle areas stay read-only; planning fields live here. */
class Olama_Transportation_Areas_Workspace
{
    private static $colors = array('#1a56db','#dc2626','#16a34a','#9333ea','#ea580c','#0891b2','#be123c','#4f46e5','#65a30d','#a16207');

    public static function overview($year, $direction)
    {
        global $wpdb;
        $resolved = Olama_Transportation_Effective_Assignments::resolve(absint($year), sanitize_key($direction));
        if (is_wp_error($resolved)) return $resolved;
        $table = Olama_Transportation_DB::table('area_bus_assignments');
        $families = Olama_Transportation_DB::table('area_trip_families');
        $buses = Olama_Transportation_DB::table('buses');
        $trips = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*,b.bus_number,b.planning_capacity,b.passenger_capacity,COUNT(f.id) family_count,COALESCE(SUM(f.student_count_snapshot),0) student_count
             FROM {$table} a JOIN {$buses} b ON b.id=a.bus_id LEFT JOIN {$families} f ON f.area_bus_assignment_id=a.id
             WHERE a.academic_year_id=%d AND a.direction=%s AND a.status='active'
             GROUP BY a.id ORDER BY a.major_area_id,a.trip_number,a.id", absint($year), sanitize_key($direction)), ARRAY_A);
        $by_area = array();
        foreach ($trips as $trip) {
            $trip['id'] = (int)$trip['id']; $trip['student_count'] = (int)$trip['student_count']; $trip['family_count'] = (int)$trip['family_count'];
            $trip['capacity'] = (int)($trip['planning_capacity'] ?: $trip['passenger_capacity']);
            $by_area[(int)$trip['major_area_id']][] = $trip;
        }
        foreach ($resolved['areas'] as &$area) {
            $area['area_type'] = in_array($area['area_type'] ?? '', array('main','secondary'), true) ? $area['area_type'] : 'secondary';
            $area['suggested_buses'] = (int)ceil((int)$area['student_count'] / 35);
            $area['trips'] = $by_area[(int)$area['id']] ?? array();
        }
        return array('areas'=>$resolved['areas'], 'buses'=>self::available_buses(), 'demand_mode'=>$resolved['demand_mode'], 'warning'=>$resolved['warning']);
    }

    public static function update_area($id, $data)
    {
        global $wpdb;
        $type = sanitize_key($data['area_type'] ?? 'secondary');
        $color = sanitize_hex_color($data['color'] ?? '');
        if (!in_array($type, array('main','secondary'), true) || !$color) return new WP_Error('invalid_area_settings', __('Provide a valid area type and color.', 'olama-transportation'), array('status'=>400));
        $table = Olama_Transportation_DB::table('major_areas');
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status='active'", absint($id)), ARRAY_A);
        if (!$before) return new WP_Error('area_not_found', __('Active Oracle area was not found.', 'olama-transportation'), array('status'=>404));
        $duplicate = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE color=%s AND id<>%d AND status='active' LIMIT 1", $color, absint($id)));
        if ($duplicate) return new WP_Error('duplicate_area_color', __('Each active area must use a unique color. Choose another basic color.', 'olama-transportation'), array('status'=>409));
        $wpdb->update($table, array('area_type'=>$type,'color'=>$color,'updated_at'=>current_time('mysql',true)), array('id'=>absint($id)));
        $after = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", absint($id)), ARRAY_A);
        Olama_Transportation_Audit::record('area_workspace_updated', 'area', $id, $before, $after);
        return $after;
    }

    public static function create_trip($data)
    {
        global $wpdb;
        $year=absint($data['academic_year_id']??0); $area=absint($data['major_area_id']??0); $bus=absint($data['bus_id']??0);
        $direction=sanitize_key($data['direction']??''); $number=absint($data['trip_number']??0);
        if (!$year || !$area || !$bus || !$number || !in_array($direction,array('morning','afternoon'),true)) return new WP_Error('invalid_trip', __('Academic year, area, direction, bus and trip number are required.', 'olama-transportation'), array('status'=>400));
        $bus_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Olama_Transportation_DB::table('buses').' WHERE id=%d AND status=%s',$bus,'active'), ARRAY_A);
        if (!$bus_row || $number > (int)$bus_row[$direction.'_trip_count']) return new WP_Error('invalid_bus_trip', __('The selected active bus does not provide this trip number.', 'olama-transportation'), array('status'=>400));
        $assignments=Olama_Transportation_DB::table('area_bus_assignments');
        if (empty($bus_row['allow_multi_area'])) {
            $other=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$assignments} WHERE academic_year_id=%d AND direction=%s AND bus_id=%d AND major_area_id<>%d AND status='active'",$year,$direction,$bus,$area));
            if ($other) return new WP_Error('bus_area_restricted', __('This bus is already assigned to another area. Enable “Can serve multiple areas” in Bus details to override.', 'olama-transportation'), array('status'=>409));
        }
        $now=current_time('mysql',true);
        $record=array('academic_year_id'=>$year,'major_area_id'=>$area,'direction'=>$direction,'bus_id'=>$bus,'trip_number'=>$number,'arrival_time'=>self::time($data['arrival_time']??''),'departure_time'=>self::time($data['departure_time']??''),'notes'=>sanitize_textarea_field($data['notes']??''),'status'=>'active','created_by'=>get_current_user_id()?:null,'updated_by'=>get_current_user_id()?:null,'created_at'=>$now,'updated_at'=>$now);
        if (!$wpdb->insert($assignments,$record)) return new WP_Error('trip_save_failed',$wpdb->last_error ?: __('Could not create the trip.', 'olama-transportation'),array('status'=>500));
        $id=(int)$wpdb->insert_id; Olama_Transportation_Audit::record('area_trip_created','area_bus_assignment',$id,null,$record);
        return array('id'=>$id);
    }

    public static function generate_queue($id)
    {
        global $wpdb;
        $assignment=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Olama_Transportation_DB::table('area_bus_assignments').' WHERE id=%d AND status=%s',absint($id),'active'),ARRAY_A);
        if (!$assignment) return new WP_Error('trip_not_found',__('Trip was not found.','olama-transportation'),array('status'=>404));
        $resolved=Olama_Transportation_Effective_Assignments::resolve($assignment['academic_year_id'],$assignment['direction']); if(is_wp_error($resolved)) return $resolved;
        $bus=$wpdb->get_row($wpdb->prepare('SELECT planning_capacity,passenger_capacity FROM '.Olama_Transportation_DB::table('buses').' WHERE id=%d',$assignment['bus_id']),ARRAY_A);
        $remaining=(int)($bus['planning_capacity']?:$bus['passenger_capacity']);
        $links=Olama_Transportation_DB::table('area_trip_families'); $assignment_table=Olama_Transportation_DB::table('area_bus_assignments');
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(student_count_snapshot),0) FROM {$links} WHERE area_bus_assignment_id=%d",$assignment['id'])); $remaining-=$used;
        $already=$wpdb->get_col($wpdb->prepare("SELECT f.family_uid FROM {$links} f JOIN {$assignment_table} a ON a.id=f.area_bus_assignment_id WHERE a.academic_year_id=%d AND a.direction=%s AND a.major_area_id=%d AND a.status='active'",$assignment['academic_year_id'],$assignment['direction'],$assignment['major_area_id']));
        $candidates=array_values(array_filter($resolved['families'],function($f)use($assignment,$already){return (int)$f['major_area_id']===(int)$assignment['major_area_id'] && !in_array($f['family_uid'],$already,true); }));
        shuffle($candidates); $added=0; $position=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(queue_position),0) FROM {$links} WHERE area_bus_assignment_id=%d",$assignment['id']));
        foreach($candidates as $family){ if((int)$family['student_count']>$remaining) continue; $position++; $wpdb->insert($links,array('area_bus_assignment_id'=>$assignment['id'],'family_uid'=>$family['family_uid'],'student_count_snapshot'=>(int)$family['student_count'],'queue_position'=>$position,'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)));$remaining-=(int)$family['student_count'];$added++; }
        Olama_Transportation_Audit::record('area_trip_queue_generated','area_bus_assignment',$assignment['id'],null,array('families_added'=>$added));
        return array('families_added'=>$added,'remaining_seats'=>$remaining);
    }

    public static function queue($id)
    {
        global $wpdb;
        $assignment = $wpdb->get_row($wpdb->prepare('SELECT a.*,m.name area_name,b.bus_number FROM '.Olama_Transportation_DB::table('area_bus_assignments').' a JOIN '.Olama_Transportation_DB::table('major_areas').' m ON m.id=a.major_area_id JOIN '.Olama_Transportation_DB::table('buses').' b ON b.id=a.bus_id WHERE a.id=%d', absint($id)), ARRAY_A);
        if (!$assignment) return new WP_Error('trip_not_found', __('Trip was not found.', 'olama-transportation'), array('status'=>404));
        $resolved = Olama_Transportation_Effective_Assignments::resolve($assignment['academic_year_id'], $assignment['direction']); if (is_wp_error($resolved)) return $resolved;
        $family_index = array(); foreach ($resolved['families'] as $family) $family_index[$family['family_uid']] = $family;
        $links = $wpdb->get_results($wpdb->prepare('SELECT family_uid,student_count_snapshot,queue_position FROM '.Olama_Transportation_DB::table('area_trip_families').' WHERE area_bus_assignment_id=%d ORDER BY queue_position,id', absint($id)), ARRAY_A);
        foreach ($links as &$link) { $family=$family_index[$link['family_uid']] ?? array(); $link['family_name']=$family['family_name'] ?? $link['family_uid']; $link['oracle_family_id']=$family['oracle_family_id'] ?? ''; }
        return array('label'=>$assignment['area_name'].' - '.($assignment['direction']==='morning' ? __('Arrival','olama-transportation') : __('Departure','olama-transportation')).' - '.__('Trip','olama-transportation').' '.$assignment['trip_number'], 'families'=>$links);
    }

    private static function available_buses(){ global $wpdb; return $wpdb->get_results('SELECT id,bus_number,passenger_capacity,planning_capacity,allow_multi_area,main_area_id,morning_trip_count,afternoon_trip_count FROM '.Olama_Transportation_DB::table('buses')." WHERE status='active' ORDER BY bus_number",ARRAY_A); }
    private static function time($value){ $value=sanitize_text_field($value); return preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/',$value)?$value.':00':null; }
}
