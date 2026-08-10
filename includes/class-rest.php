<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_REST
{
    const NS = 'olama-transportation/v1';

    public function register()
    {
        foreach (array('areas', 'area-mappings', 'family-stops', 'stops', 'enrollments', 'allocations', 'devices') as $entity) {
            register_rest_route(self::NS, '/' . $entity, array(
                array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'list_entity'), 'permission_callback' => array($this, 'can_view')),
                array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'create_entity'), 'permission_callback' => array($this, 'can_manage')),
                'args' => array('entity' => array('default' => $entity)),
            ));
            register_rest_route(self::NS, '/' . $entity . '/(?P<id>\d+)', array(
                array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_entity'), 'permission_callback' => array($this, 'can_view')),
                array('methods' => WP_REST_Server::EDITABLE, 'callback' => array($this, 'update_entity'), 'permission_callback' => array($this, 'can_manage')),
                array('methods' => WP_REST_Server::DELETABLE, 'callback' => array($this, 'delete_entity'), 'permission_callback' => array($this, 'can_manage')),
                'args' => array('entity' => array('default' => $entity)),
            ));
        }

        register_rest_route(self::NS, '/routes', array(
            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'list_routes'), 'permission_callback' => array($this, 'can_view')),
            array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'create_route'), 'permission_callback' => array($this, 'can_manage')),
        ));
        register_rest_route(self::NS, '/routes/(?P<id>\d+)', array(
            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_route'), 'permission_callback' => array($this, 'can_view')),
            array('methods' => WP_REST_Server::EDITABLE, 'callback' => array($this, 'update_route'), 'permission_callback' => array($this, 'can_manage')),
            array('methods' => WP_REST_Server::DELETABLE, 'callback' => array($this, 'delete_route'), 'permission_callback' => array($this, 'can_manage')),
        ));
        register_rest_route(self::NS, '/routes/(?P<id>\d+)/publish', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'publish_route'), 'permission_callback' => array($this, 'can_approve'),
        ));
        register_rest_route(self::NS, '/routes/(?P<id>\d+)/optimize', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'optimize_route'), 'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route(self::NS, '/reports/summary', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'summary'), 'permission_callback' => array($this, 'can_view'),
        ));
        register_rest_route(self::NS, '/core/refresh-buses', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'refresh_buses'), 'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route(self::NS, '/core/refresh-areas', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'refresh_areas'), 'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route(self::NS, '/planning/map-data', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'planning_map_data'), 'permission_callback' => array($this, 'can_view'),
        ));
        register_rest_route(self::NS, '/areas-workspace', array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'areas_workspace'),'permission_callback'=>array($this,'can_view')),
        ));
        register_rest_route(self::NS, '/areas-workspace/(?P<id>\d+)', array(
            array('methods'=>WP_REST_Server::EDITABLE,'callback'=>array($this,'update_workspace_area'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/areas-workspace/trips', array(
            array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'create_workspace_trip'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/areas-workspace/trips/(?P<id>\d+)/generate-queue', array(
            array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'generate_workspace_queue'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/areas-workspace/trips/(?P<id>\d+)/queue', array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'workspace_queue'),'permission_callback'=>array($this,'can_view')),
        ));
        register_rest_route(self::NS, '/planning/area-allocations', array(
            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'area_allocations'), 'permission_callback' => array($this, 'can_view')),
            array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'save_area_allocation'), 'permission_callback' => array($this, 'can_manage')),
        ));
        register_rest_route(self::NS, '/planning/area-allocations/preview', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'preview_area_allocation'), 'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route(self::NS, '/planning/area-allocations/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::DELETABLE, 'callback' => array($this, 'delete_area_allocation'), 'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route(self::NS, '/planning/areas/(?P<id>\d+)/families', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'area_families'), 'permission_callback' => array($this, 'can_view'),
        ));
        register_rest_route(self::NS, '/planning/trip-slots', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'planning_trip_slots'), 'permission_callback' => array($this, 'can_view'),
        ));
        register_rest_route(self::NS, '/planning/groups', array(
            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'planning_groups'), 'permission_callback' => array($this, 'can_view')),
            array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'create_planning_group'), 'permission_callback' => array($this, 'can_manage')),
        ));
        register_rest_route(self::NS, '/planning/groups/(?P<id>\d+)', array(
            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_planning_group'), 'permission_callback' => array($this, 'can_view')),
            array('methods' => WP_REST_Server::EDITABLE, 'callback' => array($this, 'update_planning_group'), 'permission_callback' => array($this, 'can_manage')),
        ));
        foreach (array('approve', 'revert', 'archive') as $action) {
            register_rest_route(self::NS, '/planning/groups/(?P<id>\d+)/' . $action, array(
                'methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, $action . '_planning_group'), 'permission_callback' => array($this, 'can_approve'),
            ));
        }
        register_rest_route(self::NS, '/imports/family-stops', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'import_family_stops'), 'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route(self::NS, '/family-locations/(?P<id>\d+)/area', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'save_family_area'),
            'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route(self::NS, '/family-locations', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'family_location_list'), 'permission_callback' => array($this, 'can_view'),
        ));
        register_rest_route(self::NS, '/family-locations/by-family/(?P<family_uid>[A-Za-z0-9:_-]+)/area', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'save_family_area_by_uid'), 'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route(self::NS, '/family-locations/bulk-area', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'bulk_save_family_area'),
            'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route(self::NS, '/family-locations/(?P<family_uid>[A-Za-z0-9:_-]+)', array(
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => array($this, 'save_family_location'),
            'permission_callback' => array($this, 'can_manage'),
        ));
        register_rest_route(self::NS, '/settings', array(
            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_settings'), 'permission_callback' => array($this, 'can_manage')),
            array('methods' => WP_REST_Server::EDITABLE, 'callback' => array($this, 'save_settings'), 'permission_callback' => array($this, 'can_manage')),
        ));
    }

    public function can_view()
    {
        return $this->can('olama_access_transport_mgmt');
    }

    public function can_manage()
    {
        return $this->can('olama_manage_transport_buses');
    }

    public function can_approve()
    {
        return $this->can('olama_approve_transport_routes') || $this->can_manage();
    }

    private function can($capability)
    {
        return class_exists('Olama_School_Permissions')
            ? Olama_School_Permissions::can($capability)
            : current_user_can('manage_options');
    }

    public function list_entity(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Repository::list_items($request['entity'], $request->get_params()));
    }

    public function get_entity(WP_REST_Request $request)
    {
        $item = Olama_Transportation_Repository::get_item($request['entity'], $request['id']);
        return $item ? rest_ensure_response($item) : new WP_Error('not_found', __('Record not found.', 'olama-transportation'), array('status' => 404));
    }

    public function create_entity(WP_REST_Request $request)
    {
        if ($request['entity'] === 'allocations') {
            return $this->respond(Olama_Transportation_Area_Trip_Assignments::save($request->get_json_params() ?: $request->get_params()));
        }
        return $this->respond(Olama_Transportation_Repository::save_item($request['entity'], $request->get_json_params() ?: $request->get_params()));
    }

    public function update_entity(WP_REST_Request $request)
    {
        if ($request['entity'] === 'allocations') {
            return $this->respond(Olama_Transportation_Area_Trip_Assignments::save($request->get_json_params() ?: $request->get_params()));
        }
        return $this->respond(Olama_Transportation_Repository::save_item($request['entity'], $request->get_json_params() ?: $request->get_params(), $request['id']));
    }

    public function delete_entity(WP_REST_Request $request)
    {
        if ($request['entity'] === 'allocations') {
            return $this->respond(Olama_Transportation_Area_Trip_Assignments::unassign($request['id']));
        }
        return $this->respond(Olama_Transportation_Repository::delete_item($request['entity'], $request['id']));
    }

    public function list_routes(WP_REST_Request $request)
    {
        return rest_ensure_response(Olama_Transportation_Routes::list_routes($request->get_params()));
    }

    public function get_route(WP_REST_Request $request)
    {
        $route = Olama_Transportation_Routes::get($request['id']);
        return $route ? rest_ensure_response($route) : new WP_Error('not_found', __('Route not found.', 'olama-transportation'), array('status' => 404));
    }

    public function create_route(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Routes::create($request->get_json_params() ?: $request->get_params()));
    }

    public function update_route(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Routes::update($request['id'], $request->get_json_params() ?: $request->get_params()));
    }

    public function delete_route(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Routes::delete($request['id']));
    }

    public function publish_route(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Routes::publish($request['id']));
    }

    public function optimize_route(WP_REST_Request $request)
    {
        return $this->respond((new Olama_Transportation_Optimizer())->optimize($request['id']));
    }

    public function summary(WP_REST_Request $request)
    {
        $year = intval($request->get_param('academic_year_id'));
        if (!$year) {
            return new WP_Error('missing_year', __('Academic year is required.', 'olama-transportation'), array('status' => 400));
        }
        return rest_ensure_response(Olama_Transportation_Planning::report_summary($year));
    }

    public function refresh_buses()
    {
        return $this->respond(Olama_Transportation_Bus::refresh_from_core());
    }

    public function refresh_areas()
    {
        return $this->respond(Olama_Transportation_Area_Sync::refresh_from_core());
    }

    public function planning_map_data(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Map_Data::get(
            $request->get_param('academic_year_id'),
            $request->get_param('direction') ?: 'morning',
            $request->get_params()
        ));
    }

    public function areas_workspace(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Areas_Workspace::overview($request->get_param('academic_year_id'), $request->get_param('direction') ?: 'morning')); }
    public function update_workspace_area(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Areas_Workspace::update_area($request['id'], $request->get_json_params() ?: $request->get_params())); }
    public function create_workspace_trip(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Areas_Workspace::create_trip($request->get_json_params() ?: $request->get_params())); }
    public function generate_workspace_queue(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Areas_Workspace::generate_queue($request['id'])); }
    public function workspace_queue(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Areas_Workspace::queue($request['id'])); }

    public function area_allocations(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Area_Trip_Assignments::list_assignments(
            absint($request->get_param('academic_year_id')),
            sanitize_key($request->get_param('direction') ?: 'morning'),
            $request->get_params()
        ));
    }

    public function save_area_allocation(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Area_Trip_Assignments::save($request->get_json_params() ?: $request->get_params()));
    }

    public function preview_area_allocation(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Area_Trip_Assignments::preview($request->get_json_params() ?: $request->get_params()));
    }

    public function delete_area_allocation(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Area_Trip_Assignments::unassign($request['id']));
    }

    public function area_families(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Area_Trip_Assignments::area_families(
            absint($request->get_param('academic_year_id')), sanitize_key($request->get_param('direction') ?: 'morning'),
            $request['id'], $request->get_params()
        ));
    }

    public function family_location_list(WP_REST_Request $request)
    {
        $year = absint($request->get_param('academic_year_id'));
        if (!$year) return new WP_Error('missing_year', __('Academic year is required.', 'olama-transportation'), array('status'=>400));
        return rest_ensure_response(Olama_Transportation_Family_Locations::admin_list($year, $request->get_params()));
    }

    public function save_family_area(WP_REST_Request $request)
    {
        $input = $request->get_json_params() ?: $request->get_params();
        return $this->respond(Olama_Transportation_Family_Area_Assignments::assign($request['id'], $input['major_area_id'] ?? 0));
    }

    public function save_family_area_by_uid(WP_REST_Request $request)
    {
        $input = $request->get_json_params() ?: $request->get_params();
        return $this->respond(Olama_Transportation_Family_Area_Assignments::assign_family(
            $request['family_uid'], $input['major_area_id'] ?? 0, $input['academic_year_id'] ?? 0
        ));
    }

    public function bulk_save_family_area(WP_REST_Request $request)
    {
        $input = $request->get_json_params() ?: $request->get_params();
        if (!empty($input['family_uids'])) {
            return $this->respond(Olama_Transportation_Family_Area_Assignments::bulk_assign_families(
                $input['family_uids'], $input['major_area_id'] ?? 0, $input['academic_year_id'] ?? 0
            ));
        }
        return $this->respond(Olama_Transportation_Family_Area_Assignments::bulk_assign($input['family_stop_ids'] ?? array(), $input['major_area_id'] ?? 0));
    }

    public function planning_trip_slots(WP_REST_Request $request)
    {
        $year = absint($request->get_param('academic_year_id'));
        $direction = sanitize_key($request->get_param('direction') ?: 'morning');
        if (!$year || !in_array($direction, array('morning', 'afternoon'), true)) {
            return new WP_Error('invalid_trip_slot_request', __('A valid academic year and direction are required.', 'olama-transportation'), array('status' => 400));
        }
        return rest_ensure_response(Olama_Transportation_Geographic_Planning::trip_slots($year, $direction, absint($request->get_param('bus_id'))));
    }

    public function planning_groups(WP_REST_Request $request)
    {
        return rest_ensure_response(Olama_Transportation_Geographic_Planning::list_groups($request->get_params()));
    }

    public function get_planning_group(WP_REST_Request $request)
    {
        $group = Olama_Transportation_Geographic_Planning::get($request['id']);
        return $group ? rest_ensure_response($group) : new WP_Error('planning_group_not_found', __('Planning group was not found.', 'olama-transportation'), array('status' => 404));
    }

    public function create_planning_group(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Geographic_Planning::save($request->get_json_params() ?: $request->get_params()));
    }

    public function update_planning_group(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Geographic_Planning::save($request->get_json_params() ?: $request->get_params(), $request['id']));
    }

    public function approve_planning_group(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Geographic_Planning::approve($request['id']));
    }

    public function revert_planning_group(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Geographic_Planning::revert($request['id']));
    }

    public function archive_planning_group(WP_REST_Request $request)
    {
        return $this->respond(Olama_Transportation_Geographic_Planning::archive($request['id']));
    }

    public function import_family_stops(WP_REST_Request $request)
    {
        $files = $request->get_file_params();
        return $this->respond((new Olama_Transportation_Importer())->import($files['file'] ?? array()));
    }

    public function save_family_location(WP_REST_Request $request)
    {
        $input = $request->get_json_params() ?: $request->get_params();
        if (empty($input['location'])) {
            return new WP_Error(
                'missing_location',
                __('Paste the family location first.', 'olama-transportation'),
                array('status' => 400)
            );
        }
        return $this->respond(Olama_Transportation_Family_Locations::save(
            $request['family_uid'],
            $input['location'],
            $input['notes'] ?? ''
        ));
    }

    public function get_settings()
    {
        $settings = get_option('olama_transportation_settings', array());
        unset($settings['oracle_api_url'], $settings['oracle_api_key'], $settings['optimizer_webhook_secret']);
        $settings['optimizer_webhook_secret_configured'] = !empty(get_option('olama_transportation_settings', array())['optimizer_webhook_secret']);
        $settings['core_transport_master_sync'] = function_exists('olama_core') && method_exists(olama_core(), 'transport_master')
            ? olama_core()->transport_master()->last_synced_at()
            : array('buses' => null, 'regions' => null);
        return rest_ensure_response($settings);
    }

    public function save_settings(WP_REST_Request $request)
    {
        $current = get_option('olama_transportation_settings', array());
        $input = $request->get_json_params() ?: $request->get_params();
        $settings = array(
            'optimizer_provider' => in_array(($input['optimizer_provider'] ?? 'manual'), array('manual', 'google', 'webhook'), true) ? $input['optimizer_provider'] : 'manual',
            'google_project_id' => sanitize_text_field($input['google_project_id'] ?? ($current['google_project_id'] ?? '')),
            'optimizer_webhook_url' => esc_url_raw($input['optimizer_webhook_url'] ?? ($current['optimizer_webhook_url'] ?? '')),
            'traccar_enabled' => !empty($input['traccar_enabled']) ? 1 : 0,
            'traccar_url' => esc_url_raw($input['traccar_url'] ?? ($current['traccar_url'] ?? '')),
            'school_location' => array(
                'latitude' => (float) ($input['school_location']['latitude'] ?? ($current['school_location']['latitude'] ?? 31.9539)),
                'longitude' => (float) ($input['school_location']['longitude'] ?? ($current['school_location']['longitude'] ?? 35.9106)),
            ),
            'service_bounds' => array(
                'south' => (float) ($input['service_bounds']['south'] ?? 29),
                'north' => (float) ($input['service_bounds']['north'] ?? 34),
                'west' => (float) ($input['service_bounds']['west'] ?? 34),
                'east' => (float) ($input['service_bounds']['east'] ?? 40),
            ),
        );
        foreach (array('optimizer_webhook_secret') as $secret) {
            $settings[$secret] = !empty($input[$secret]) ? sanitize_text_field($input[$secret]) : ($current[$secret] ?? '');
        }
        update_option('olama_transportation_settings', $settings, false);
        Olama_Transportation_Audit::record('update', 'settings', null, null, array('changed' => array_keys($settings)));
        return $this->get_settings();
    }

    private function respond($result)
    {
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }
}
