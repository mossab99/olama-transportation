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
        add_action('admin_post_olama_transport_export_family_locations', array($this, 'export_family_locations_csv'));
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

    public function export_family_locations_csv()
    {
        $can_manage = class_exists('Olama_School_Permissions')
            ? Olama_School_Permissions::can('olama_manage_transport_buses')
            : current_user_can('manage_options');
        if (!$can_manage) {
            wp_die(esc_html__('Unauthorized access', 'olama-transportation'), 403);
        }
        check_admin_referer('olama_transport_export_family_locations');

        $year = absint($_GET['academic_year_id'] ?? 0);
        if (!$year) {
            wp_die(esc_html__('Academic year is required.', 'olama-transportation'), 400);
        }
        $input = wp_unslash($_GET);
        $args = array(
            'export_all' => true,
            'search' => sanitize_text_field($input['search'] ?? ''),
            'major_area_id' => sanitize_text_field($input['major_area_id'] ?? 'all'),
            'oracle_area' => sanitize_text_field($input['oracle_area'] ?? 'all'),
            'transportation_status' => sanitize_key($input['transportation_status'] ?? 'all'),
            'location_status' => sanitize_key($input['location_status'] ?? 'all'),
            'morning_status' => sanitize_key($input['morning_status'] ?? 'all'),
            'afternoon_status' => sanitize_key($input['afternoon_status'] ?? 'all'),
            'missing_locations' => sanitize_key($input['missing_locations'] ?? 'all'),
        );
        $data = Olama_Transportation_Family_Locations::admin_list($year, $args);
        $now = current_time('mysql');
        $user = wp_get_current_user();
        $exported_by = $user ? $user->display_name : '';
        $study_year = Olama_Transportation_Bus::study_year($year);
        $filename = sprintf('family-locations-%s-%s.csv', sanitize_file_name($study_year), wp_date('Y-m-d-His'));

        Olama_Transportation_Audit::record('family_locations_exported', 'family_locations', $year, null, array(
            'academic_year_id' => $year,
            'study_year' => $study_year,
            'family_count' => (int) ($data['pagination']['total'] ?? 0),
            'student_count' => (int) ($data['pagination']['student_total'] ?? 0),
            'filters' => $args,
            'exported_at' => $now,
        ));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Arabic text in Excel.
        fputcsv($output, array('Family #', 'Father name', 'Father mobile', 'Kids (first name - grade - section)', 'GPS coordinates', 'Oracle area', 'Oracle address', 'Planning area', 'Location status', 'Exported at', 'Exported by'));
        foreach ($data['items'] as $family) {
            $kids = array();
            foreach (($family['students'] ?? array()) as $student) {
                $kids[] = trim(implode(' - ', array_filter(array($student['first_name'] ?? '', $student['class_name'] ?? '', $student['section_name'] ?? ''), 'strlen')));
            }
            $coordinates = $family['latitude'] !== null && $family['longitude'] !== null
                ? $family['latitude'] . ', ' . $family['longitude']
                : '';
            fputcsv($output, array(
                $family['oracle_family_id'] ?? '', $family['father_name'] ?: ($family['family_name'] ?? ''), $family['father_mobile'] ?? '', implode(' | ', $kids),
                $coordinates, $family['trans_region_name'] ?? '', $family['oracle_address'] ?? '', $family['major_area_name'] ?? '',
                $family['verification_status'] ?? '', $now, $exported_by,
            ));
        }
        fclose($output);
        exit;
    }

    public function render_transportation_page()
    {
        if (!Olama_School_Permissions::can('olama_access_transport_mgmt')) {
            wp_die(esc_html__('Unauthorized access', 'olama-transportation'));
        }

        $requested_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        $tab_labels = array(
            'overview'    => Olama_Transportation_I18n::translate('Overview'),
            'buses'       => Olama_Transportation_I18n::translate('Buses'),
            'areas'       => Olama_Transportation_I18n::translate('Trips'),
            'family-move' => Olama_Transportation_I18n::translate('Family Move'),
            'planning'    => Olama_Transportation_I18n::translate('Area Coverage'),
            'routes'      => Olama_Transportation_I18n::translate('Routes'),
            'import'      => Olama_Transportation_I18n::translate('Family Locations'),
            'dual'        => Olama_Transportation_I18n::translate('Dual Locations'),
            'companions'  => Olama_Transportation_I18n::translate('Companion Locations'),
            'reports'     => Olama_Transportation_I18n::translate('Reports'),
            'settings'    => Olama_Transportation_I18n::translate('Settings'),
        );

        $allowed_tabs = array();
        foreach (Olama_Transportation_Plugin::tab_capabilities() as $tab => $capability) {
            if (isset($tab_labels[$tab]) && Olama_School_Permissions::can($capability)) {
                $allowed_tabs[$tab] = $tab_labels[$tab];
            }
        }
        if (!$allowed_tabs) {
            wp_die(esc_html__('You do not have access to any Transportation section.', 'olama-transportation'), 403);
        }
        if ($requested_tab && !isset($allowed_tabs[$requested_tab])) {
            wp_die(esc_html__('You do not have access to this Transportation section.', 'olama-transportation'), 403);
        }
        $active_tab = $requested_tab ?: array_key_first($allowed_tabs);

        $buses = Olama_Transportation_Bus::get_buses();
        $drivers = array();
        $companions = array();
        $years = array();
        $selected_year_id = 0;
        $active_year = Olama_School_Academic::get_active_year();
        $selected_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : ($active_year ? $active_year->id : 0);
        $years = Olama_School_Academic::get_years();
        $summary = array();
        $dashboard = array();
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
        if ($active_tab === 'overview' && $selected_year_id) {
            $dashboard = Olama_Transportation_Dashboard::get($selected_year_id);
        }
        if (in_array($active_tab, array('areas', 'planning', 'import', 'dual', 'companions'), true) && $selected_year_id) {
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

        $i18n_path = OLAMA_TRANSPORTATION_PATH . 'assets/js/i18n.js';
        wp_enqueue_script(
            'olama-transportation-i18n',
            OLAMA_TRANSPORTATION_URL . 'assets/js/i18n.js',
            array(),
            $this->asset_version($i18n_path),
            true
        );
        wp_localize_script('olama-transportation-i18n', 'olamaTransportationI18n', array(
            'language' => Olama_Transportation_I18n::language(),
            'direction' => Olama_Transportation_I18n::direction(),
            'translations' => Olama_Transportation_I18n::translations(),
        ));

        $script_path = OLAMA_TRANSPORTATION_PATH . 'assets/js/admin.js';
        wp_enqueue_script(
            'olama-transportation-admin',
            OLAMA_TRANSPORTATION_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-datepicker', 'olama-transportation-i18n'),
            $this->asset_version($script_path),
            true
        );

        wp_localize_script('olama-transportation-admin', 'olamaTransportation', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('olama_admin_nonce'),
            'restUrl' => esc_url_raw(rest_url('olama-transportation/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n'    => array(
                'saving'                 => Olama_Transportation_I18n::translate('Saving...'),
                'saveBus'                => Olama_Transportation_I18n::translate('Save Bus'),
                'communicationError'     => Olama_Transportation_I18n::translate('Communication error'),
                'editBus'                => Olama_Transportation_I18n::translate('Edit Bus'),
                'addNewBus'              => Olama_Transportation_I18n::translate('Add New Bus'),
                'deleteBusConfirm'       => Olama_Transportation_I18n::translate('Are you sure you want to delete this bus?'),
                'noStudentsAssigned'     => Olama_Transportation_I18n::translate('No students assigned'),
                'allStudentsAssigned'    => Olama_Transportation_I18n::translate('All students are assigned'),
                'assignSelectedConfirm'  => Olama_Transportation_I18n::translate('Assign selected students to this bus?'),
                'unassignStudentConfirm' => Olama_Transportation_I18n::translate('Unassign this student from the bus?'),
                'unassign'               => Olama_Transportation_I18n::translate('Unassign'),
                'morning'                => Olama_Transportation_I18n::translate('Morning'),
                'afternoon'              => Olama_Transportation_I18n::translate('Afternoon'),
                'trip'                   => Olama_Transportation_I18n::translate('Trip'),
                'selectTrip'             => Olama_Transportation_I18n::translate('Select a trip'),
                'noDefinedTrips'         => Olama_Transportation_I18n::translate('No trips are defined for this bus.'),
                'noAreaStudents'         => Olama_Transportation_I18n::translate('No students belong to the attached areas for this direction.'),
                'attachAnotherArea'      => Olama_Transportation_I18n::translate('Attach another area'),
                'saveSelectionConfirm'   => Olama_Transportation_I18n::translate('Save these student selections for this bus trip?'),
                'saved'                  => Olama_Transportation_I18n::translate('Saved successfully'),
                'settingsSaved'          => Olama_Transportation_I18n::translate('Settings saved. Reloading…'),
                'testing'                => Olama_Transportation_I18n::translate('Testing...'),
                'testOrs'                => Olama_Transportation_I18n::translate('Test ORS Configuration'),
                'failed'                 => Olama_Transportation_I18n::translate('Operation failed'),
            ),
        ));

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if (!$tab) {
            foreach (Olama_Transportation_Plugin::tab_capabilities() as $candidate => $capability) {
                if (Olama_School_Permissions::can($capability)) {
                    $tab = $candidate;
                    break;
                }
            }
        }
        $dual_year_id = isset($_GET['academic_year_id']) ? absint($_GET['academic_year_id']) : 0;
        if (!$dual_year_id) {
            $dual_active_year = Olama_School_Academic::get_active_year();
            $dual_year_id = $dual_active_year ? absint($dual_active_year->id) : 0;
        }
        $selected_year_id = $dual_year_id;
        if ($tab === 'overview') {
            $path = OLAMA_TRANSPORTATION_PATH . 'assets/js/dashboard.js';
            wp_enqueue_script('olama-transportation-dashboard', OLAMA_TRANSPORTATION_URL . 'assets/js/dashboard.js', array('jquery'), $this->asset_version($path), true);
        } elseif ($tab === 'planning') {
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
                    'assigned' => Olama_Transportation_I18n::translate('Assigned'), 'edit' => Olama_Transportation_I18n::translate('Edit'),
                    'assignEdit' => Olama_Transportation_I18n::translate('Assign/Edit Bus Trip'),
                    'viewFamilies' => Olama_Transportation_I18n::translate('View Families'), 'remove' => Olama_Transportation_I18n::translate('Remove Assignment'),
                    'confirmRemove' => Olama_Transportation_I18n::translate('Remove this area bus-trip assignment?'),
                    'saved' => Olama_Transportation_I18n::translate('Area bus-trip assignment saved.'),
                    'noStudents' => Olama_Transportation_I18n::translate('No Students'),
                    'areaNotAllocated' => Olama_Transportation_I18n::translate('Area Not Allocated'),
                    'capacityProblem' => Olama_Transportation_I18n::translate('Over Capacity'),
                    'families' => Olama_Transportation_I18n::translate('Families'),
                    'students' => Olama_Transportation_I18n::translate('Students'),
                    'map' => Olama_Transportation_I18n::translate('Map'),
                    'move' => Olama_Transportation_I18n::translate('Move to Planning Area'),
                    'allAreas' => Olama_Transportation_I18n::translate('All areas'), 'allBuses' => Olama_Transportation_I18n::translate('All buses'),
                    'allTrips' => Olama_Transportation_I18n::translate('All trips'), 'selectBus' => Olama_Transportation_I18n::translate('Select Bus'),
                    'selectTrip' => Olama_Transportation_I18n::translate('Select Trip'), 'school' => Olama_Transportation_I18n::translate('School'),
                    'trip' => Olama_Transportation_I18n::translate('Trip'), 'areaStudents' => Olama_Transportation_I18n::translate('Area students'),
                    'currentTripStudents' => Olama_Transportation_I18n::translate('Current bus-trip students'), 'resultingSeats' => Olama_Transportation_I18n::translate('Resulting used seats'),
                    'capacity' => Olama_Transportation_I18n::translate('Capacity'), 'remaining' => Olama_Transportation_I18n::translate('Remaining'),
                    'familyMoved' => Olama_Transportation_I18n::translate('Family planning area updated.'),
                    'failed' => Olama_Transportation_I18n::translate('Operation failed.'),
                    'loading' => Olama_Transportation_I18n::translate('Loadingâ€¦'),
                    'removed' => Olama_Transportation_I18n::translate('Area assignment removed.'),
                    'areas' => Olama_Transportation_I18n::translate('areas'),
                    'previous' => Olama_Transportation_I18n::translate('Previous'), 'next' => Olama_Transportation_I18n::translate('Next'),
                    'selectArea' => Olama_Transportation_I18n::translate('Select Area'),
                    'noAreas' => Olama_Transportation_I18n::translate('No areas match the current filters.'),
                    'noCoordinates' => Olama_Transportation_I18n::translate('No mapped family coordinates are available for this area.'),
                    'noValidLocations' => Olama_Transportation_I18n::translate('No valid map locations match the current filters.'),
                    'mapAll' => Olama_Transportation_I18n::translate('Map all students'),
                    'transportationMap' => Olama_Transportation_I18n::translate('Transportation map'),
                    'walkingMap' => Olama_Transportation_I18n::translate('Walking map'),
                    'invalidLocations' => Olama_Transportation_I18n::translate('Invalid or missing locations'),
                    'transportationMode' => Olama_Transportation_I18n::translate('Transportation'),
                    'walkingMode' => Olama_Transportation_I18n::translate('Walking'),
                    'allStudentsMode' => Olama_Transportation_I18n::translate('All students'),
                    'previewIncomplete' => Olama_Transportation_I18n::translate('Preview incomplete. Complete the fields, then preview capacity.'),
                    'previewing' => Olama_Transportation_I18n::translate('Confirming capacity with the serverâ€¦'),
                    'completeFields' => Olama_Transportation_I18n::translate('Select an area, bus, and valid trip before previewing.'),
                    'effectiveCapacity' => Olama_Transportation_I18n::translate('Effective capacity'), 'override' => Olama_Transportation_I18n::translate('planning override'),
                    'noTrips' => Olama_Transportation_I18n::translate('This bus has no valid trips in the selected direction.'),
                    'areaFamilies' => Olama_Transportation_I18n::translate('Area families'), 'utilization' => Olama_Transportation_I18n::translate('Utilization'),
                    'currentAssignmentStudents' => Olama_Transportation_I18n::translate('Current assignment being replaced'), 'demandSource' => Olama_Transportation_I18n::translate('Demand source'),
                    'confirmSave' => Olama_Transportation_I18n::translate('Save this area allocation?'),
                    'removeEffect' => Olama_Transportation_I18n::translate('Families retain their Planning Area but become bus-trip unallocated.'),
                    'newAssignment' => Olama_Transportation_I18n::translate('Assign Area to Bus Trip'), 'editAssignment' => Olama_Transportation_I18n::translate('Edit Area Bus-Trip Assignment'),
                    'noFamilies' => Olama_Transportation_I18n::translate('No matching families in this area.'), 'selectFamilies' => Olama_Transportation_I18n::translate('Select families and a destination area first.'),
                    'statuses' => array(
                        'assigned'=>Olama_Transportation_I18n::translate('Assigned'),'area_not_allocated'=>Olama_Transportation_I18n::translate('Area Not Allocated'),
                        'missing_area'=>Olama_Transportation_I18n::translate('Missing Area'),'over_capacity'=>Olama_Transportation_I18n::translate('Over Capacity'),
                        'near_capacity'=>Olama_Transportation_I18n::translate('Near Capacity'),'at_capacity'=>Olama_Transportation_I18n::translate('At Capacity'),
                        'within_capacity'=>Olama_Transportation_I18n::translate('Within Capacity'),'no_student_demand'=>Olama_Transportation_I18n::translate('No Student Demand'),
                        'no_students'=>Olama_Transportation_I18n::translate('No Students'),'missing_locations'=>Olama_Transportation_I18n::translate('Missing Locations'),
                        'invalid_bus'=>Olama_Transportation_I18n::translate('Invalid Bus'),'invalid_bus_capacity'=>Olama_Transportation_I18n::translate('Invalid Bus Capacity'),
                        'invalid_trip'=>Olama_Transportation_I18n::translate('Invalid Trip'),'approved'=>Olama_Transportation_I18n::translate('Approved'),
                        'needs_review'=>Olama_Transportation_I18n::translate('Needs Review'),'missing_location'=>Olama_Transportation_I18n::translate('Missing Location'),
                        'invalid_location'=>Olama_Transportation_I18n::translate('Invalid Location'),
                        'transportation_enrollments'=>Olama_Transportation_I18n::translate('Transportation enrollments'),
                        'academic_registration_fallback'=>Olama_Transportation_I18n::translate('Academic registration fallback'),
                    ),
                ),
            ));
        } elseif ($tab === 'import') {
            $path = OLAMA_TRANSPORTATION_PATH . 'assets/js/family-locations.js';
            wp_enqueue_script('olama-family-locations', OLAMA_TRANSPORTATION_URL . 'assets/js/family-locations.js', array(), $this->asset_version($path), true);
            wp_localize_script('olama-family-locations', 'olamaFamilyLocations', array(
                'restUrl' => esc_url_raw(rest_url('olama-transportation/v1/')), 'restNonce' => wp_create_nonce('wp_rest'),
                'exportUrl' => admin_url('admin-post.php'), 'exportNonce' => wp_create_nonce('olama_transport_export_family_locations'),
                'canManage' => Olama_School_Permissions::can('olama_manage_transport_buses'),
                'areas' => array_values(array_map(function ($area) { return array('id'=>(int)$area['id'],'name'=>$area['name']); }, Olama_Transportation_Area_Sync::selectable_areas())),
                'i18n' => array(
                    'loading'=>Olama_Transportation_I18n::translate('Loading…'),'saved'=>Olama_Transportation_I18n::translate('Saved.'),'saveFailed'=>Olama_Transportation_I18n::translate('Save failed.'),
                    'unsaved'=>Olama_Transportation_I18n::translate('Unsaved changes'),'saving'=>Olama_Transportation_I18n::translate('Saving…'),'saveArea'=>Olama_Transportation_I18n::translate('Save Area'),
                    'clear'=>Olama_Transportation_I18n::translate('Clear'),'details'=>Olama_Transportation_I18n::translate('Details'),'map'=>Olama_Transportation_I18n::translate('Map'),
                    'missingLocation'=>Olama_Transportation_I18n::translate('Missing Location'),'invalidLocation'=>Olama_Transportation_I18n::translate('Invalid Location'),
                    'needsReview'=>Olama_Transportation_I18n::translate('Needs review'),'approved'=>Olama_Transportation_I18n::translate('Approved'),
                    'morning'=>Olama_Transportation_I18n::translate('Morning'),'afternoon'=>Olama_Transportation_I18n::translate('Afternoon'),'arrival'=>Olama_Transportation_I18n::translate('Arrival'),'departure'=>Olama_Transportation_I18n::translate('Departure'),'trip'=>Olama_Transportation_I18n::translate('Trip'),
                    'assigned'=>Olama_Transportation_I18n::translate('Assigned'),'missingArea'=>Olama_Transportation_I18n::translate('Missing Area'),
                    'areaNotAllocated'=>Olama_Transportation_I18n::translate('Area Not Allocated'),'capacityProblem'=>Olama_Transportation_I18n::translate('Capacity Problem'),
                    'noMatches'=>Olama_Transportation_I18n::translate('No matching families. Adjust or reset the filters.'),'selected'=>Olama_Transportation_I18n::translate('selected'),
                    'bulkComplete'=>Olama_Transportation_I18n::translate('%d families updated successfully.'),'location'=>Olama_Transportation_I18n::translate('Default Location'),'defaultLocation'=>Olama_Transportation_I18n::translate('Default location'),'twoLocations'=>Olama_Transportation_I18n::translate('Two locations'),'locationType'=>Olama_Transportation_I18n::translate('Location setup'),'locationHelp'=>Olama_Transportation_I18n::translate('Use the default location for both trips, or enter separate arrival and departure locations.'),'arrivalLocation'=>Olama_Transportation_I18n::translate('Arrival location'),'departureLocation'=>Olama_Transportation_I18n::translate('Departure location'),
                    'saveLocation'=>Olama_Transportation_I18n::translate('Save Location'),'coordinates'=>Olama_Transportation_I18n::translate('Coordinates'),
                    'phone'=>Olama_Transportation_I18n::translate('Phone'),'source'=>Olama_Transportation_I18n::translate('Source'),'assignmentSource'=>Olama_Transportation_I18n::translate('Assignment Source'),
                    'assignedAt'=>Olama_Transportation_I18n::translate('Assigned At'),'notes'=>Olama_Transportation_I18n::translate('Notes'),'unassigned'=>Olama_Transportation_I18n::translate('Unassigned'),
                    'fatherMobile'=>Olama_Transportation_I18n::translate('Father Mobile'),'motherMobile'=>Olama_Transportation_I18n::translate('Mother Mobile'),
                    'students'=>Olama_Transportation_I18n::translate('students'),'families'=>Olama_Transportation_I18n::translate('families'),'transportSubscribed'=>'مشترك بالمواصلات',
                    'transportNotSubscribed'=>'غير مشترك بالمواصلات','transportStatus'=>Olama_Transportation_I18n::translate('Transportation Status'),
                    'oracleArea'=>Olama_Transportation_I18n::translate('Oracle Area'),'planningArea'=>Olama_Transportation_I18n::translate('Planning Area'),
                    'address'=>Olama_Transportation_I18n::translate('Address'),'noAddress'=>Olama_Transportation_I18n::translate('No address available'),
                    'manualOverride'=>Olama_Transportation_I18n::translate('Planning Area differs from Oracle Area'),
                    'networkError'=>Olama_Transportation_I18n::translate('The request failed. Check the connection and try again.'),
                    'exportCsv'=>Olama_Transportation_I18n::translate('Export CSV'),
                ),
            ));
        } elseif ($tab === 'dual') {
            $path = OLAMA_TRANSPORTATION_PATH . 'assets/js/dual-locations.js';
            wp_enqueue_script('olama-dual-locations', OLAMA_TRANSPORTATION_URL . 'assets/js/dual-locations.js', array(), $this->asset_version($path), true);
            wp_localize_script('olama-dual-locations', 'olamaDualLocations', array(
                'restUrl'=>esc_url_raw(rest_url('olama-transportation/v1/')), 'restNonce'=>wp_create_nonce('wp_rest'), 'canManage'=>Olama_School_Permissions::can('olama_manage_transport_buses'), 'year'=>$dual_year_id,
                'areas'=>array_values(array_map(function($area){return array('id'=>(int)$area['id'],'name'=>$area['name']);}, Olama_Transportation_Area_Sync::selectable_areas())),
                'families'=>array_values(array_map(function($family){return array('uid'=>(string)$family['family_uid'],'oracle_id'=>(string)$family['oracle_family_id'],'name'=>(string)$family['family_name']);}, Olama_Transportation_Family_Locations::registered_families($dual_year_id))),
                'i18n'=>array('loading'=>Olama_Transportation_I18n::translate('Loading…'),'saved'=>Olama_Transportation_I18n::translate('Saved.'),'failed'=>Olama_Transportation_I18n::translate('Operation failed.'),'unassigned'=>Olama_Transportation_I18n::translate('Unassigned'),'selectTrip'=>Olama_Transportation_I18n::translate('Select an existing draft trip'),'arrival'=>Olama_Transportation_I18n::translate('Arrival / Morning'),'departure'=>Olama_Transportation_I18n::translate('Departure / Afternoon'),'assign'=>Olama_Transportation_I18n::translate('Assign to trip'),'assigned'=>Olama_Transportation_I18n::translate('Assigned'),'partial'=>Olama_Transportation_I18n::translate('Partially assigned'),'unassignedStatus'=>Olama_Transportation_I18n::translate('Not assigned'),'noFamilies'=>Olama_Transportation_I18n::translate('No dual-location families yet.')),
            ));
        } elseif ($tab === 'companions') {
            $path = OLAMA_TRANSPORTATION_PATH . 'assets/js/companion-locations.js';
            wp_enqueue_script('olama-companion-locations', OLAMA_TRANSPORTATION_URL . 'assets/js/companion-locations.js', array(), $this->asset_version($path), true);
            wp_localize_script('olama-companion-locations', 'olamaCompanionLocations', array('restUrl'=>esc_url_raw(rest_url('olama-transportation/v1/')),'restNonce'=>wp_create_nonce('wp_rest'),'year'=>$selected_year_id,'canManage'=>Olama_School_Permissions::can('olama_manage_transport_buses'),'i18n'=>array('saving'=>Olama_Transportation_I18n::translate('Saving…'),'saved'=>Olama_Transportation_I18n::translate('Saved.'),'failed'=>Olama_Transportation_I18n::translate('Save failed.'),'missing'=>Olama_Transportation_I18n::translate('Enter a valid location first.'),'noTrips'=>Olama_Transportation_I18n::translate('No attached trips.'))));
        }
        if ($tab === 'family-move') {
            $this->enqueue_style('olama-leaflet', 'assets/vendor/leaflet/leaflet.css');
            wp_enqueue_script('olama-leaflet', OLAMA_TRANSPORTATION_URL . 'assets/vendor/leaflet/leaflet.js', array(), $this->asset_version(OLAMA_TRANSPORTATION_PATH . 'assets/vendor/leaflet/leaflet.js'), true);
            $path = OLAMA_TRANSPORTATION_PATH . 'assets/js/family-move.js';
            wp_enqueue_script('olama-family-move', OLAMA_TRANSPORTATION_URL . 'assets/js/family-move.js', array('jquery','olama-leaflet'), $this->asset_version($path), true);
            wp_localize_script('olama-family-move', 'olamaFamilyMove', array(
                'restUrl'=>esc_url_raw(rest_url('olama-transportation/v1/')), 'restNonce'=>wp_create_nonce('wp_rest'),
                'canManage'=>Olama_School_Permissions::can('olama_manage_transport_buses'), 'year'=>(int)$selected_year_id,
                'tileUrl'=>'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', 'tileAttribution'=>'&copy; OpenStreetMap contributors',
            ));
        } elseif ($tab === 'areas') {
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
        } elseif ($tab === 'reports') {
            $path = OLAMA_TRANSPORTATION_PATH . 'assets/js/reports.js';
            wp_enqueue_script('olama-qrcode', 'https://cdn.jsdelivr.net/npm/qrcode-generator@2.0.4/dist/qrcode.js', array(), '2.0.4', true);
            wp_enqueue_script('olama-transportation-reports', OLAMA_TRANSPORTATION_URL . 'assets/js/reports.js', array('olama-qrcode', 'olama-transportation-i18n'), $this->asset_version($path), true);
            wp_localize_script('olama-transportation-reports', 'olamaReports', array(
                'restUrl'=>esc_url_raw(rest_url('olama-transportation/v1/')),
                'restNonce'=>wp_create_nonce('wp_rest'),
                'year'=>(int)$selected_year_id,
                'language'=>Olama_Transportation_I18n::language(),
                'direction'=>Olama_Transportation_I18n::direction(),
                'i18n'=>Olama_Transportation_I18n::translations(),
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
