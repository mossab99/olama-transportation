<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Admin
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menus'), 30);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'redirect_legacy_page'), 1);
    }

    public function register_menus()
    {
        add_menu_page(
            __('Olama Transportation', 'olama-transportation'),
            __('Olama Transportation', 'olama-transportation'),
            'olama_access_transport_mgmt',
            'olama-transportation',
            array($this, 'render_transportation_page'),
            'dashicons-car',
            29
        );

        add_submenu_page(
            'olama-transportation',
            __('Transportation', 'olama-transportation'),
            __('Transportation', 'olama-transportation'),
            'olama_access_transport_mgmt',
            'olama-transportation',
            array($this, 'render_transportation_page')
        );
    }

    public function redirect_legacy_page()
    {
        if (empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== 'olama-school-transport') {
            return;
        }

        $args = wp_unslash($_GET);
        $args['page'] = 'olama-transportation';
        unset($args['_wpnonce']);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function render_transportation_page()
    {
        if (!Olama_School_Permissions::can('olama_access_transport_mgmt')) {
            wp_die(esc_html__('Unauthorized access', 'olama-transportation'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        $allowed_tabs = array(
            'overview'    => Olama_School_Helpers::translate('Overview'),
            'buses'       => Olama_School_Helpers::translate('Buses'),
            'areas'       => Olama_School_Helpers::translate('Trips'),
            'planning'    => Olama_School_Helpers::translate('Area Coverage'),
            'routes'      => Olama_School_Helpers::translate('Routes'),
            'import'      => Olama_School_Helpers::translate('Family Locations'),
            'dual'        => Olama_School_Helpers::translate('Dual Locations'),
            'companions'  => Olama_School_Helpers::translate('Companion Locations'),
            'settings'    => Olama_School_Helpers::translate('Settings'),
        );

        if (!isset($allowed_tabs[$active_tab])) {
            $active_tab = 'buses';
        }

        $buses = Olama_Transportation_Bus::get_buses();
        $drivers = array();
        $companions = array();
        $years = array();
        $selected_year_id = 0;
        $active_year = Olama_School_Academic::get_active_year();
        $selected_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : ($active_year ? $active_year->id : 0);
        $years = Olama_School_Academic::get_years();
        $summary = array();
        $areas = array();
        $family_stops = array();
        $stops = array();
        $routes = array();
        $route_trips = array();
        $registered_families = array();
        $dual_locations = array('items'=>array(),'trips'=>array(),'metrics'=>array());
        $all_family_locations = array();
        $dual_family_lookup = array();
        $companion_locations = array('items'=>array());
        $settings = get_option('olama_transportation_settings', array());

        if ($active_tab === 'buses') {
            $drivers = Olama_Transportation_Bus::get_available_drivers();
            $areas = Olama_Transportation_Repository::list_items('areas', array('per_page' => 500, 'status' => 'active'));
        }
        if (in_array($active_tab, array('overview', 'areas', 'planning', 'import', 'dual', 'companions'), true) && $selected_year_id) {
            $summary = Olama_Transportation_Planning::report_summary($selected_year_id);
            $areas = Olama_Transportation_Repository::list_items('areas', array('per_page' => 500));
            $family_stops = Olama_Transportation_Repository::list_items('family-stops', array('per_page' => 100));
            if ($active_tab === 'import') {
                $areas = Olama_Transportation_Area_Sync::selectable_areas();
            }
            if ($active_tab === 'dual') {
                $dual_locations = Olama_Transportation_Dual_Locations::list($selected_year_id);
                $all_family_locations = Olama_Transportation_Family_Locations::admin_list($selected_year_id, array('per_page'=>100));
                $dual_family_lookup = Olama_Transportation_Family_Locations::registered_families($selected_year_id);
                $areas = Olama_Transportation_Area_Sync::selectable_areas();
            }
            if ($active_tab === 'companions') $companion_locations = Olama_Transportation_Companion_Locations::list($selected_year_id);
        }
        if ($active_tab === 'areas') {
            $companions = Olama_Transportation_Bus::get_available_companions();
        }
        if ($active_tab === 'routes') {
            $routes = Olama_Transportation_Routes::list_routes(array('academic_year_id' => $selected_year_id));
            $stops = Olama_Transportation_Repository::list_items('stops', array('per_page' => 500, 'status' => 'active'));
            $this->enqueue_style('olama-leaflet', 'assets/vendor/leaflet/leaflet.css');
            wp_enqueue_script('olama-leaflet', OLAMA_TRANSPORTATION_URL . 'assets/vendor/leaflet/leaflet.js', array(), $this->asset_version(OLAMA_TRANSPORTATION_PATH . 'assets/vendor/leaflet/leaflet.js'), true);
            $route_trips = array_merge(
                Olama_Transportation_Shared_Trips::list_for_context($selected_year_id, 'morning'),
                Olama_Transportation_Shared_Trips::list_for_context($selected_year_id, 'afternoon')
            );
        }

        include OLAMA_TRANSPORTATION_PATH . 'admin-views/transportation.php';
    }

    public function enqueue_assets($hook)
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'olama-transportation') {
            return;
        }

        // WordPress 7 enables automatic cross-document View Transitions in wp-admin.
        // Repeated async rerenders in this workspace can legitimately skip one and
        // Chromium reports that benign cancellation as an unhandled AbortError.
        // This screen has its own modal/UI state and does not need page transitions.
        wp_dequeue_style('wp-view-transitions-admin');

        $this->enqueue_style('olama-transportation-admin', 'assets/css/admin.css');
        wp_enqueue_style('jquery-ui-datepicker-css', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css', array(), '1.13.2');
        wp_enqueue_script('jquery-ui-datepicker');

        $script_path = OLAMA_TRANSPORTATION_PATH . 'assets/js/admin.js';
        wp_enqueue_script(
            'olama-transportation-admin',
            OLAMA_TRANSPORTATION_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-datepicker'),
            $this->asset_version($script_path),
            true
        );

        wp_localize_script('olama-transportation-admin', 'olamaTransportation', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('olama_admin_nonce'),
            'restUrl' => esc_url_raw(rest_url('olama-transportation/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n'    => array(
                'saving'                 => Olama_School_Helpers::translate('Saving...'),
                'saveBus'                => Olama_School_Helpers::translate('Save Bus'),
                'communicationError'     => Olama_School_Helpers::translate('Communication error'),
                'editBus'                => Olama_School_Helpers::translate('Edit Bus'),
                'addNewBus'              => Olama_School_Helpers::translate('Add New Bus'),
                'deleteBusConfirm'       => Olama_School_Helpers::translate('Are you sure you want to delete this bus?'),
                'noStudentsAssigned'     => Olama_School_Helpers::translate('No students assigned'),
                'allStudentsAssigned'    => Olama_School_Helpers::translate('All students are assigned'),
                'assignSelectedConfirm'  => Olama_School_Helpers::translate('Assign selected students to this bus?'),
                'unassignStudentConfirm' => Olama_School_Helpers::translate('Unassign this student from the bus?'),
                'unassign'               => Olama_School_Helpers::translate('Unassign'),
                'morning'                => Olama_School_Helpers::translate('Morning'),
                'afternoon'              => Olama_School_Helpers::translate('Afternoon'),
                'trip'                   => Olama_School_Helpers::translate('Trip'),
                'selectTrip'             => Olama_School_Helpers::translate('Select a trip'),
                'noDefinedTrips'         => Olama_School_Helpers::translate('No trips are defined for this bus.'),
                'noAreaStudents'         => Olama_School_Helpers::translate('No students belong to the attached areas for this direction.'),
                'attachAnotherArea'      => Olama_School_Helpers::translate('Attach another area'),
                'saveSelectionConfirm'   => Olama_School_Helpers::translate('Save these student selections for this bus trip?'),
                'saved'                  => Olama_School_Helpers::translate('Saved successfully'),
                'failed'                 => Olama_School_Helpers::translate('Operation failed'),
            ),
        ));

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        $dual_year_id = isset($_GET['academic_year_id']) ? absint($_GET['academic_year_id']) : 0;
        if (!$dual_year_id) {
            $dual_active_year = Olama_School_Academic::get_active_year();
            $dual_year_id = $dual_active_year ? absint($dual_active_year->id) : 0;
        }
        if ($tab === 'planning') {
            $this->enqueue_style('olama-leaflet', 'assets/vendor/leaflet/leaflet.css');
            $this->enqueue_style('olama-geographic-planner', 'assets/css/geographic-planner.css', array('olama-leaflet'));
            wp_enqueue_script('olama-leaflet', OLAMA_TRANSPORTATION_URL . 'assets/vendor/leaflet/leaflet.js', array(), $this->asset_version(OLAMA_TRANSPORTATION_PATH . 'assets/vendor/leaflet/leaflet.js'), true);
            wp_enqueue_script('olama-area-mapping', OLAMA_TRANSPORTATION_URL . 'assets/js/area-mapping.js', array('olama-leaflet'), $this->asset_version(OLAMA_TRANSPORTATION_PATH . 'assets/js/area-mapping.js'), true);
            wp_localize_script('olama-area-mapping', 'olamaPlanner', array(
                'restUrl' => esc_url_raw(rest_url('olama-transportation/v1/')),
                'restNonce' => wp_create_nonce('wp_rest'),
                'canManage' => Olama_School_Permissions::can('olama_manage_transport_buses'),
                'tileUrl' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                'tileAttribution' => '&copy; OpenStreetMap contributors',
                'i18n' => array(
                    'assigned' => Olama_School_Helpers::translate('Assigned'), 'edit' => Olama_School_Helpers::translate('Edit'),
                    'assignEdit' => Olama_School_Helpers::translate('Assign/Edit Bus Trip'),
                    'viewFamilies' => Olama_School_Helpers::translate('View Families'), 'remove' => Olama_School_Helpers::translate('Remove Assignment'),
                    'confirmRemove' => Olama_School_Helpers::translate('Remove this area bus-trip assignment?'),
                    'saved' => Olama_School_Helpers::translate('Area bus-trip assignment saved.'),
                    'noStudents' => Olama_School_Helpers::translate('No Students'),
                    'areaNotAllocated' => Olama_School_Helpers::translate('Area Not Allocated'),
                    'capacityProblem' => Olama_School_Helpers::translate('Over Capacity'),
                    'families' => Olama_School_Helpers::translate('Families'),
                    'students' => Olama_School_Helpers::translate('Students'),
                    'map' => Olama_School_Helpers::translate('Map'),
                    'move' => Olama_School_Helpers::translate('Move to Planning Area'),
                    'allAreas' => Olama_School_Helpers::translate('All areas'), 'allBuses' => Olama_School_Helpers::translate('All buses'),
                    'allTrips' => Olama_School_Helpers::translate('All trips'), 'selectBus' => Olama_School_Helpers::translate('Select Bus'),
                    'selectTrip' => Olama_School_Helpers::translate('Select Trip'), 'school' => Olama_School_Helpers::translate('School'),
                    'trip' => Olama_School_Helpers::translate('Trip'), 'areaStudents' => Olama_School_Helpers::translate('Area students'),
                    'currentTripStudents' => Olama_School_Helpers::translate('Current bus-trip students'), 'resultingSeats' => Olama_School_Helpers::translate('Resulting used seats'),
                    'capacity' => Olama_School_Helpers::translate('Capacity'), 'remaining' => Olama_School_Helpers::translate('Remaining'),
                    'familyMoved' => Olama_School_Helpers::translate('Family planning area updated.'),
                    'failed' => Olama_School_Helpers::translate('Operation failed.'),
                    'loading' => Olama_School_Helpers::translate('Loadingâ€¦'),
                    'removed' => Olama_School_Helpers::translate('Area assignment removed.'),
                    'areas' => Olama_School_Helpers::translate('areas'),
                    'previous' => Olama_School_Helpers::translate('Previous'), 'next' => Olama_School_Helpers::translate('Next'),
                    'selectArea' => Olama_School_Helpers::translate('Select Area'),
                    'noAreas' => Olama_School_Helpers::translate('No areas match the current filters.'),
                    'noCoordinates' => Olama_School_Helpers::translate('No mapped family coordinates are available for this area.'),
                    'noValidLocations' => Olama_School_Helpers::translate('No valid map locations match the current filters.'),
                    'mapAll' => Olama_School_Helpers::translate('Map all students'),
                    'transportationMap' => Olama_School_Helpers::translate('Transportation map'),
                    'walkingMap' => Olama_School_Helpers::translate('Walking map'),
                    'invalidLocations' => Olama_School_Helpers::translate('Invalid or missing locations'),
                    'transportationMode' => Olama_School_Helpers::translate('Transportation'),
                    'walkingMode' => Olama_School_Helpers::translate('Walking'),
                    'allStudentsMode' => Olama_School_Helpers::translate('All students'),
                    'previewIncomplete' => Olama_School_Helpers::translate('Preview incomplete. Complete the fields, then preview capacity.'),
                    'previewing' => Olama_School_Helpers::translate('Confirming capacity with the serverâ€¦'),
                    'completeFields' => Olama_School_Helpers::translate('Select an area, bus, and valid trip before previewing.'),
                    'effectiveCapacity' => Olama_School_Helpers::translate('Effective capacity'), 'override' => Olama_School_Helpers::translate('planning override'),
                    'noTrips' => Olama_School_Helpers::translate('This bus has no valid trips in the selected direction.'),
                    'areaFamilies' => Olama_School_Helpers::translate('Area families'), 'utilization' => Olama_School_Helpers::translate('Utilization'),
                    'currentAssignmentStudents' => Olama_School_Helpers::translate('Current assignment being replaced'), 'demandSource' => Olama_School_Helpers::translate('Demand source'),
                    'confirmSave' => Olama_School_Helpers::translate('Save this area allocation?'),
                    'removeEffect' => Olama_School_Helpers::translate('Families retain their Planning Area but become bus-trip unallocated.'),
                    'newAssignment' => Olama_School_Helpers::translate('Assign Area to Bus Trip'), 'editAssignment' => Olama_School_Helpers::translate('Edit Area Bus-Trip Assignment'),
                    'noFamilies' => Olama_School_Helpers::translate('No matching families in this area.'), 'selectFamilies' => Olama_School_Helpers::translate('Select families and a destination area first.'),
                    'statuses' => array(
                        'assigned'=>Olama_School_Helpers::translate('Assigned'),'area_not_allocated'=>Olama_School_Helpers::translate('Area Not Allocated'),
                        'missing_area'=>Olama_School_Helpers::translate('Missing Area'),'over_capacity'=>Olama_School_Helpers::translate('Over Capacity'),
                        'near_capacity'=>Olama_School_Helpers::translate('Near Capacity'),'at_capacity'=>Olama_School_Helpers::translate('At Capacity'),
                        'within_capacity'=>Olama_School_Helpers::translate('Within Capacity'),'no_student_demand'=>Olama_School_Helpers::translate('No Student Demand'),
                        'no_students'=>Olama_School_Helpers::translate('No Students'),'missing_locations'=>Olama_School_Helpers::translate('Missing Locations'),
                        'invalid_bus'=>Olama_School_Helpers::translate('Invalid Bus'),'invalid_bus_capacity'=>Olama_School_Helpers::translate('Invalid Bus Capacity'),
                        'invalid_trip'=>Olama_School_Helpers::translate('Invalid Trip'),'approved'=>Olama_School_Helpers::translate('Approved'),
                        'needs_review'=>Olama_School_Helpers::translate('Needs Review'),'missing_location'=>Olama_School_Helpers::translate('Missing Location'),
                        'invalid_location'=>Olama_School_Helpers::translate('Invalid Location'),
                        'transportation_enrollments'=>Olama_School_Helpers::translate('Transportation enrollments'),
                        'academic_registration_fallback'=>Olama_School_Helpers::translate('Academic registration fallback'),
                    ),
                ),
            ));
        } elseif ($tab === 'import') {
            $path = OLAMA_TRANSPORTATION_PATH . 'assets/js/family-locations.js';
            wp_enqueue_script('olama-family-locations', OLAMA_TRANSPORTATION_URL . 'assets/js/family-locations.js', array(), $this->asset_version($path), true);
            wp_localize_script('olama-family-locations', 'olamaFamilyLocations', array(
                'restUrl' => esc_url_raw(rest_url('olama-transportation/v1/')), 'restNonce' => wp_create_nonce('wp_rest'),
                'canManage' => Olama_School_Permissions::can('olama_manage_transport_buses'),
                'areas' => array_values(array_map(function ($area) { return array('id'=>(int)$area['id'],'name'=>$area['name']); }, Olama_Transportation_Area_Sync::selectable_areas())),
                'i18n' => array(
                    'loading'=>Olama_School_Helpers::translate('Loading…'),'saved'=>Olama_School_Helpers::translate('Saved.'),'saveFailed'=>Olama_School_Helpers::translate('Save failed.'),
                    'unsaved'=>Olama_School_Helpers::translate('Unsaved changes'),'saving'=>Olama_School_Helpers::translate('Saving…'),'saveArea'=>Olama_School_Helpers::translate('Save Area'),
                    'clear'=>Olama_School_Helpers::translate('Clear'),'details'=>Olama_School_Helpers::translate('Details'),'map'=>Olama_School_Helpers::translate('Map'),
                    'missingLocation'=>Olama_School_Helpers::translate('Missing Location'),'invalidLocation'=>Olama_School_Helpers::translate('Invalid Location'),
                    'needsReview'=>Olama_School_Helpers::translate('Needs review'),'approved'=>Olama_School_Helpers::translate('Approved'),
                    'morning'=>Olama_School_Helpers::translate('Morning'),'afternoon'=>Olama_School_Helpers::translate('Afternoon'),'arrival'=>Olama_School_Helpers::translate('Arrival'),'departure'=>Olama_School_Helpers::translate('Departure'),'trip'=>Olama_School_Helpers::translate('Trip'),
                    'assigned'=>Olama_School_Helpers::translate('Assigned'),'missingArea'=>Olama_School_Helpers::translate('Missing Area'),
                    'areaNotAllocated'=>Olama_School_Helpers::translate('Area Not Allocated'),'capacityProblem'=>Olama_School_Helpers::translate('Capacity Problem'),
                    'noMatches'=>Olama_School_Helpers::translate('No matching families. Adjust or reset the filters.'),'selected'=>Olama_School_Helpers::translate('selected'),
                    'bulkComplete'=>Olama_School_Helpers::translate('%d families updated successfully.'),'location'=>Olama_School_Helpers::translate('Default Location'),'defaultLocation'=>Olama_School_Helpers::translate('Default location'),'twoLocations'=>Olama_School_Helpers::translate('Two locations'),'locationType'=>Olama_School_Helpers::translate('Location setup'),'locationHelp'=>Olama_School_Helpers::translate('Use the default location for both trips, or enter separate arrival and departure locations.'),'arrivalLocation'=>Olama_School_Helpers::translate('Arrival location'),'departureLocation'=>Olama_School_Helpers::translate('Departure location'),
                    'saveLocation'=>Olama_School_Helpers::translate('Save Location'),'coordinates'=>Olama_School_Helpers::translate('Coordinates'),
                    'phone'=>Olama_School_Helpers::translate('Phone'),'source'=>Olama_School_Helpers::translate('Source'),'assignmentSource'=>Olama_School_Helpers::translate('Assignment Source'),
                    'assignedAt'=>Olama_School_Helpers::translate('Assigned At'),'notes'=>Olama_School_Helpers::translate('Notes'),'unassigned'=>Olama_School_Helpers::translate('Unassigned'),
                    'fatherMobile'=>Olama_School_Helpers::translate('Father Mobile'),'motherMobile'=>Olama_School_Helpers::translate('Mother Mobile'),
                    'students'=>Olama_School_Helpers::translate('students'),'families'=>Olama_School_Helpers::translate('families'),'transportSubscribed'=>'مشترك بالمواصلات',
                    'transportNotSubscribed'=>'غير مشترك بالمواصلات','transportStatus'=>Olama_School_Helpers::translate('Transportation Status'),
                    'oracleArea'=>Olama_School_Helpers::translate('Oracle Area'),'planningArea'=>Olama_School_Helpers::translate('Planning Area'),
                    'address'=>Olama_School_Helpers::translate('Address'),'noAddress'=>Olama_School_Helpers::translate('No address available'),
                    'manualOverride'=>Olama_School_Helpers::translate('Planning Area differs from Oracle Area'),
                    'networkError'=>Olama_School_Helpers::translate('The request failed. Check the connection and try again.'),
                ),
            ));
        } elseif ($tab === 'dual') {
            $path = OLAMA_TRANSPORTATION_PATH . 'assets/js/dual-locations.js';
            wp_enqueue_script('olama-dual-locations', OLAMA_TRANSPORTATION_URL . 'assets/js/dual-locations.js', array(), $this->asset_version($path), true);
            wp_localize_script('olama-dual-locations', 'olamaDualLocations', array(
                'restUrl'=>esc_url_raw(rest_url('olama-transportation/v1/')), 'restNonce'=>wp_create_nonce('wp_rest'), 'canManage'=>Olama_School_Permissions::can('olama_manage_transport_buses'), 'year'=>$dual_year_id,
                'areas'=>array_values(array_map(function($area){return array('id'=>(int)$area['id'],'name'=>$area['name']);}, Olama_Transportation_Area_Sync::selectable_areas())),
                'families'=>array_values(array_map(function($family){return array('uid'=>(string)$family['family_uid'],'oracle_id'=>(string)$family['oracle_family_id'],'name'=>(string)$family['family_name']);}, Olama_Transportation_Family_Locations::registered_families($dual_year_id))),
                'i18n'=>array('loading'=>Olama_School_Helpers::translate('Loading…'),'saved'=>Olama_School_Helpers::translate('Saved.'),'failed'=>Olama_School_Helpers::translate('Operation failed.'),'unassigned'=>Olama_School_Helpers::translate('Unassigned'),'selectTrip'=>Olama_School_Helpers::translate('Select an existing draft trip'),'arrival'=>Olama_School_Helpers::translate('Arrival / Morning'),'departure'=>Olama_School_Helpers::translate('Departure / Afternoon'),'assign'=>Olama_School_Helpers::translate('Assign to trip'),'assigned'=>Olama_School_Helpers::translate('Assigned'),'partial'=>Olama_School_Helpers::translate('Partially assigned'),'unassignedStatus'=>Olama_School_Helpers::translate('Not assigned'),'noFamilies'=>Olama_School_Helpers::translate('No dual-location families yet.')),
            ));
        } elseif ($tab === 'companions') {
            $path = OLAMA_TRANSPORTATION_PATH . 'assets/js/companion-locations.js';
            wp_enqueue_script('olama-companion-locations', OLAMA_TRANSPORTATION_URL . 'assets/js/companion-locations.js', array(), $this->asset_version($path), true);
            wp_localize_script('olama-companion-locations', 'olamaCompanionLocations', array('restUrl'=>esc_url_raw(rest_url('olama-transportation/v1/')),'restNonce'=>wp_create_nonce('wp_rest'),'year'=>$selected_year_id,'canManage'=>Olama_School_Permissions::can('olama_manage_transport_buses'),'i18n'=>array('saving'=>Olama_School_Helpers::translate('Saving…'),'saved'=>Olama_School_Helpers::translate('Saved.'),'failed'=>Olama_School_Helpers::translate('Save failed.'),'missing'=>Olama_School_Helpers::translate('Enter a valid location first.'),'noTrips'=>Olama_School_Helpers::translate('No attached trips.'))));
        }
        if ($tab === 'areas') {
            $trip_companions = Olama_Transportation_Bus::get_available_companions();
            $this->enqueue_style('olama-leaflet', 'assets/vendor/leaflet/leaflet.css');
            wp_enqueue_script('olama-leaflet', OLAMA_TRANSPORTATION_URL . 'assets/vendor/leaflet/leaflet.js', array(), $this->asset_version(OLAMA_TRANSPORTATION_PATH . 'assets/vendor/leaflet/leaflet.js'), true);
            $script_path = OLAMA_TRANSPORTATION_PATH . 'assets/js/areas-workspace.js';
            wp_enqueue_script('olama-qrcode', 'https://cdn.jsdelivr.net/npm/qrcode-generator@2.0.4/dist/qrcode.js', array(), '2.0.4', true);
            wp_enqueue_script('olama-areas-workspace', OLAMA_TRANSPORTATION_URL . 'assets/js/areas-workspace.js', array('jquery','olama-leaflet','olama-qrcode'), $this->asset_version($script_path), true);
            wp_localize_script('olama-areas-workspace', 'olamaTripStaff', array(
                'companions' => array_values(array_map(static function ($user) {
                    return array('id' => (int) $user->ID, 'name' => $user->display_name);
                }, $trip_companions)),
            ));
        }
    }

    private function enqueue_style($handle, $relative_path, $dependencies = array())
    {
        $path = OLAMA_TRANSPORTATION_PATH . $relative_path;
        wp_enqueue_style($handle, OLAMA_TRANSPORTATION_URL . $relative_path, $dependencies, $this->asset_version($path));
    }

    private function asset_version($path)
    {
        return OLAMA_TRANSPORTATION_VERSION . '-' . (file_exists($path) ? filemtime($path) : '0');
    }
}
