<?php
if (!defined('ABSPATH')) exit;

class Olama_Transportation_Companion_Locations
{
    public static function list($academic_year_id)
    {
        global $wpdb;
        $table = Olama_Transportation_DB::table('companion_locations');
        $trips = Olama_Transportation_DB::table('shared_trips');
        $locations = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
        $by_user = array(); foreach ($locations as $location) $by_user[(int)$location['user_id']] = $location;
        $attached = $wpdb->get_results($wpdb->prepare("SELECT companion_user_id,id,name,direction,status FROM {$trips} WHERE academic_year_id=%d AND companion_user_id IS NOT NULL AND status IN ('draft','published') ORDER BY direction,id", absint($academic_year_id)), ARRAY_A);
        $trips_by_user = array(); foreach ($attached as $trip) $trips_by_user[(int)$trip['companion_user_id']][] = array('id'=>(int)$trip['id'],'name'=>(string)$trip['name'],'direction'=>(string)$trip['direction'],'status'=>(string)$trip['status']);
        $rows = array();
        foreach (Olama_Transportation_Bus::get_available_companions() as $user) {
            $id = (int)$user->ID; $location = $by_user[$id] ?? array();
            $rows[] = array('user_id'=>$id,'name'=>$user->display_name,'email'=>$user->user_email,'latitude'=>$location['latitude'] ?? null,'longitude'=>$location['longitude'] ?? null,'trips'=>$trips_by_user[$id] ?? array());
        }
        usort($rows, static function($a,$b){return strnatcasecmp($a['name'],$b['name']);});
        return array('items'=>$rows);
    }

    public static function save($user_id, $location)
    {
        global $wpdb;
        $user_id = absint($user_id); $user = get_user_by('id', $user_id);
        $eligible = array_filter(Olama_Transportation_Bus::get_available_companions(), static function($item) use ($user_id){return (int)$item->ID === $user_id;});
        if (!$user || !$eligible) return new WP_Error('invalid_companion', __('Select an active companion employee.', 'olama-transportation'), array('status'=>400));
        $coordinates = Olama_Transportation_Family_Locations::parse_coordinates($location);
        if (is_wp_error($coordinates)) return $coordinates;
        if (!Olama_Transportation_Family_Locations::within_service_bounds($coordinates['latitude'],$coordinates['longitude'])) return new WP_Error('outside_service_area', __('The location is outside the configured transportation service area.', 'olama-transportation'), array('status'=>400));
        $table = Olama_Transportation_DB::table('companion_locations'); $now=current_time('mysql',true); $maps='https://www.google.com/maps?q='.rawurlencode($coordinates['latitude'].','.$coordinates['longitude']);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d",$user_id));
        $data=array('user_id'=>$user_id,'latitude'=>$coordinates['latitude'],'longitude'=>$coordinates['longitude'],'maps_url'=>$maps,'verification_status'=>'approved','updated_at'=>$now);
        if ($existing) $ok=$wpdb->update($table,$data,array('id'=>absint($existing))); else {$data['created_at']=$now;$ok=$wpdb->insert($table,$data);}
        if ($ok===false) return new WP_Error('companion_location_save_failed',$wpdb->last_error ?: __('Could not save companion location.', 'olama-transportation'),array('status'=>500));
        return array('user_id'=>$user_id,'latitude'=>$coordinates['latitude'],'longitude'=>$coordinates['longitude'],'maps_url'=>$maps);
    }
}
