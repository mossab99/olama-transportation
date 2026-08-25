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
        register_rest_route(self::NS, '/areas-workspace/shared-trips', array(
            array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'create_shared_trip'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/areas-workspace/shared-trips/(?P<id>\d+)', array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'get_shared_trip'),'permission_callback'=>array($this,'can_view')),
            array('methods'=>WP_REST_Server::EDITABLE,'callback'=>array($this,'update_shared_trip'),'permission_callback'=>array($this,'can_manage')),
            array('methods'=>WP_REST_Server::DELETABLE,'callback'=>array($this,'delete_shared_trip_draft'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/areas-workspace/shared-trips/(?P<id>\d+)/candidates', array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'shared_trip_candidates'),'permission_callback'=>array($this,'can_view')),
        ));
        register_rest_route(self::NS, '/areas-workspace/shared-trips/(?P<id>\d+)/students', array(
            array('methods'=>WP_REST_Server::EDITABLE,'callback'=>array($this,'save_shared_trip_students'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/areas-workspace/shared-trips/(?P<id>\d+)/areas', array(
            array('methods'=>WP_REST_Server::EDITABLE,'callback'=>array($this,'save_shared_trip_areas'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/areas-workspace/shared-trips/(?P<id>\d+)/queue', array(
            array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'build_shared_trip_queue'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/areas-workspace/shared-trips/(?P<id>\d+)/badges', array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'shared_trip_badges'),'permission_callback'=>array($this,'can_view')),
        ));
        register_rest_route(self::NS, '/family-move', array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'family_move_context'),'permission_callback'=>array($this,'can_view')),
            array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'move_families'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/family-move/trips/(?P<id>\d+)', array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'family_move_trip'),'permission_callback'=>array($this,'can_view')),
        ));
        register_rest_route(self::NS, '/reports/school-level', array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'school_level_report'),'permission_callback'=>array($this,'can_view_reports')),
        ));
        register_rest_route(self::NS, '/reports/families', array(array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'family_report'),'permission_callback'=>array($this,'can_view_reports'))));
        register_rest_route(self::NS, '/reports/unassigned', array(array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'unassigned_report'),'permission_callback'=>array($this,'can_view_reports'))));
        register_rest_route(self::NS, '/areas-workspace/shared-trips/(?P<id>\d+)/publish', array(
            array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'publish_shared_trip'),'permission_callback'=>array($this,'can_approve')),
        ));
        register_rest_route(self::NS, '/areas-workspace/shared-trips/(?P<id>\d+)/return-to-draft', array(
            array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'return_shared_trip_to_draft'),'permission_callback'=>array($this,'can_approve')),
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
        register_rest_route(self::NS, '/dual-locations', array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'dual_location_list'),'permission_callback'=>array($this,'can_view')),
        ));
        register_rest_route(self::NS, '/dual-locations/assign', array(
            array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'dual_location_assign'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/companion-locations', array(
            array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'companion_location_list'),'permission_callback'=>array($this,'can_view')),
        ));
        register_rest_route(self::NS, '/companion-locations/(?P<user_id>\d+)', array(
            array('methods'=>WP_REST_Server::EDITABLE,'callback'=>array($this,'save_companion_location'),'permission_callback'=>array($this,'can_manage')),
        ));
        register_rest_route(self::NS, '/settings', array(
            array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_settings'), 'permission_callback' => array($this, 'can_manage_settings')),
            array('methods' => WP_REST_Server::EDITABLE, 'callback' => array($this, 'save_settings'), 'permission_callback' => array($this, 'can_manage_settings')),
        ));
        register_rest_route(self::NS, '/settings/test-ors', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'test_ors'), 'permission_callback' => array($this, 'can_manage_settings')));
    }

    public function can_view()
    {
        return $this->can('olama_access_transport_mgmt');
    }

    public function can_manage()
    {
        return $this->can('olama_manage_transport_buses');
    }

    public function can_view_reports()
    {
        return $this->can('olama_view_transport_reports');
    }

    public function can_manage_settings()
    {
        return $this->can('olama_manage_transport_settings');
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
        return $this->respond(Olama_Transportation_Bus::sync_from_source());
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
    public function create_shared_trip(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Shared_Trips::create($request->get_json_params() ?: $request->get_params())); }
    public function get_shared_trip(WP_REST_Request $request) { $trip=Olama_Transportation_Shared_Trips::get($request['id']); if ($trip && $trip['status'] === 'published' && $trip['student_count'] > 0 && empty($trip['queue'])) { $rebuilt=Olama_Transportation_Shared_Trips::build_queue($trip['id']); if (!is_wp_error($rebuilt)) $trip=$rebuilt; } return $trip ? rest_ensure_response($trip) : new WP_Error('shared_trip_not_found', __('Trip was not found.', 'olama-transportation'), array('status'=>404)); }
    public function update_shared_trip(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Shared_Trips::update($request['id'], $request->get_json_params() ?: $request->get_params())); }
    public function delete_shared_trip_draft(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Shared_Trips::delete_draft($request['id'])); }
    public function shared_trip_candidates(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Shared_Trips::candidates($request['id'], $request->get_param('major_area_id'))); }
    public function shared_trip_badges(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Shared_Trips::badges($request['id'])); }
    public function family_move_context(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Family_Move::context($request->get_param('academic_year_id'), $request->get_param('direction') ?: 'morning')); }
    public function family_move_trip(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Family_Move::trip($request['id'])); }
    public function move_families(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Family_Move::move($request->get_json_params() ?: $request->get_params())); }
    public function school_level_report(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Reports::school_report($request->get_param('academic_year_id'), array(
        'population'=>$request->get_param('population') ?: 'transportation', 'direction'=>$request->get_param('direction') ?: 'morning',
        'grade'=>$request->get_param('grade') ?: '', 'section'=>$request->get_param('section') ?: '', 'area_id'=>$request->get_param('area_id') ?: '',
        'trip_id'=>$request->get_param('trip_id') ?: '', 'assignment_status'=>$request->get_param('assignment_status') ?: ($request->get_param('transport_status') ?: 'all'),
        'school_filter'=>$request->get_param('school_filter') ?: 'all',
    ))); }
    public function family_report(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Reports::family_report($request->get_param('academic_year_id'), $request->get_param('search') ?: '')); }
    public function unassigned_report(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Reports::unassigned_report($request->get_param('academic_year_id'), $request->get_param('scope') ?: 'none')); }
    public function save_shared_trip_students(WP_REST_Request $request) { $input=$request->get_json_params() ?: $request->get_params(); return $this->respond(Olama_Transportation_Shared_Trips::save_area_students($request['id'], $input['major_area_id'] ?? 0, $input['student_uids'] ?? array())); }
    public function save_shared_trip_areas(WP_REST_Request $request) { $input=$request->get_json_params() ?: $request->get_params(); return $this->respond(Olama_Transportation_Shared_Trips::save_areas($request['id'], $input['area_ids'] ?? array())); }
    public function build_shared_trip_queue(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Shared_Trips::build_queue($request['id'])); }
    public function publish_shared_trip(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Shared_Trips::publish($request['id'])); }
    public function return_shared_trip_to_draft(WP_REST_Request $request) { return $this->respond(Olama_Transportation_Shared_Trips::return_to_draft($request['id'])); }

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

    public function dual_location_list(WP_REST_Request $request)
    {
        $year = absint($request->get_param('academic_year_id'));
        if (!$year) return new WP_Error('missing_year', __('Academic year is required.', 'olama-transportation'), array('status'=>400));
        return rest_ensure_response(Olama_Transportation_Dual_Locations::list($year));
    }

    public function dual_location_assign(WP_REST_Request $request)
    {
        $input = $request->get_json_params() ?: $request->get_params();
        return $this->respond(Olama_Transportation_Dual_Locations::assign($input['academic_year_id'] ?? 0, $input['family_uid'] ?? '', $input['direction'] ?? '', $input['trip_id'] ?? 0));
    }

    public function companion_location_list(WP_REST_Request $request)
    {
        return rest_ensure_response(Olama_Transportation_Companion_Locations::list(absint($request->get_param('academic_year_id'))));
    }

    public function save_companion_location(WP_REST_Request $request)
    {
        $input=$request->get_json_params() ?: $request->get_params();
        return $this->respond(Olama_Transportation_Companion_Locations::save($request['user_id'], $input['location'] ?? ''));
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
            $request['family_uid'], $input['major_area_id'] ?? 0, $input['academic_year_id'] ?? 0, false
        ));
    }

    public function bulk_save_family_area(WP_REST_Request $request)
    {
        $input = $request->get_json_params() ?: $request->get_params();
        if (!empty($input['family_uids'])) {
            return $this->respond(Olama_Transportation_Family_Area_Assignments::bulk_assign_families(
                $input['family_uids'], $input['major_area_id'] ?? 0, $input['academic_year_id'] ?? 0, false
            ));
        }
        return $this->respond(Olama_Transportation_Family_Area_Assignments::bulk_assign($input['family_stop_ids'] ?? array(), $input['major_area_id'] ?? 0));
    }

    public function import_family_stops(WP_REST_Request $request)
    {
        $files = $request->get_file_params();
        return $this->respond((new Olama_Transportation_Importer())->import($files['file'] ?? array()));
    }

    public function save_family_location(WP_REST_Request $request)
    {
        $input = $request->get_json_params() ?: $request->get_params();
        if (empty($input['location']) && empty($input['locations'])) {
            return new WP_Error(
                'missing_location',
                __('Paste the family location first.', 'olama-transportation'),
                array('status' => 400)
            );
        }
        return $this->respond(Olama_Transportation_Family_Locations::save(
            $request['family_uid'],
            !empty($input['locations']) ? $input : ($input['location'] ?? ''),
            $input['notes'] ?? ''
        ));
    }

    public function get_settings()
    {
        $settings = get_option('olama_transportation_settings', array());
        unset($settings['oracle_api_url'], $settings['oracle_api_key'], $settings['optimizer_webhook_secret']);
        unset($settings['ors_api_key']);
        $settings['ors_api_key_configured'] = (bool) (defined('OLAMA_TRANSPORT_ORS_API_KEY') && OLAMA_TRANSPORT_ORS_API_KEY) || !empty(get_option('olama_transportation_settings', array())['ors_api_key']);
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
        $lat = $input['school_location']['latitude'] ?? ($current['school_location']['latitude'] ?? null);
        $lng = $input['school_location']['longitude'] ?? ($current['school_location']['longitude'] ?? null);
        if (($lat !== null && $lat !== '' && (!is_numeric($lat) || (float)$lat < -90 || (float)$lat > 90)) || ($lng !== null && $lng !== '' && (!is_numeric($lng) || (float)$lng < -180 || (float)$lng > 180))) return new WP_Error('invalid_school_location', __('Academy coordinates must be valid latitude/longitude values.', 'olama-transportation'), array('status'=>400));
        $settings = array(
            'language' => in_array(($input['language'] ?? ($current['language'] ?? 'en')), array('en', 'ar'), true) ? ($input['language'] ?? ($current['language'] ?? 'en')) : 'en',
            'optimizer_provider' => in_array(($input['optimizer_provider'] ?? 'manual'), array('manual', 'ors', 'google', 'webhook'), true) ? $input['optimizer_provider'] : 'manual',
            'ors_profile' => in_array(($input['ors_profile'] ?? ($current['ors_profile'] ?? 'driving-car')), array('driving-car','driving-hgv','cycling-regular','foot-walking'), true) ? $input['ors_profile'] : 'driving-car',
            'ors_service_duration_seconds' => max(0, absint($input['ors_service_duration_seconds'] ?? ($current['ors_service_duration_seconds'] ?? 60))),
            'google_project_id' => sanitize_text_field($input['google_project_id'] ?? ($current['google_project_id'] ?? '')),
            'optimizer_webhook_url' => esc_url_raw($input['optimizer_webhook_url'] ?? ($current['optimizer_webhook_url'] ?? '')),
            'traccar_enabled' => !empty($input['traccar_enabled']) ? 1 : 0,
            'traccar_url' => esc_url_raw($input['traccar_url'] ?? ($current['traccar_url'] ?? '')),
            'school_location' => array(
                'latitude' => ($input['school_location']['latitude'] ?? ($current['school_location']['latitude'] ?? null)) === '' ? null : (float) ($input['school_location']['latitude'] ?? ($current['school_location']['latitude'] ?? null)),
                'longitude' => ($input['school_location']['longitude'] ?? ($current['school_location']['longitude'] ?? null)) === '' ? null : (float) ($input['school_location']['longitude'] ?? ($current['school_location']['longitude'] ?? null)),
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
        if (!defined('OLAMA_TRANSPORT_ORS_API_KEY') && !empty($input['ors_api_key'])) $settings['ors_api_key'] = sanitize_text_field($input['ors_api_key']);
        elseif (!defined('OLAMA_TRANSPORT_ORS_API_KEY')) $settings['ors_api_key'] = (string) ($current['ors_api_key'] ?? '');
        update_option('olama_transportation_settings', $settings, false);
        Olama_Transportation_Audit::record('update', 'settings', null, null, array('changed' => array_keys($settings)));
        return $this->get_settings();
    }

    public function test_ors()
    {
        $client = new Olama_Transportation_ORS_Client();
        $result = $client->test_connection();
        return is_wp_error($result) ? $result : rest_ensure_response(array('success'=>true,'message'=>__('OpenRouteService configuration is working.', 'olama-transportation')));
    }

    private function respond($result)
    {
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }
}
