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
            'assignments' => Olama_School_Helpers::translate('Student Assignments'),
            'planning'    => Olama_School_Helpers::translate('Area Planning'),
            'routes'      => Olama_School_Helpers::translate('Routes'),
            'import'      => Olama_School_Helpers::translate('Family Locations'),
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
        $selected_bus_id = 0;
        $active_year = Olama_School_Academic::get_active_year();
        $selected_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : ($active_year ? $active_year->id : 0);
        $years = Olama_School_Academic::get_years();
        $summary = array();
        $areas = array();
        $family_stops = array();
        $stops = array();
        $routes = array();
        $registered_families = array();
        $settings = get_option('olama_transportation_settings', array());

        if ($active_tab === 'buses') {
            $drivers = Olama_Transportation_Bus::get_available_drivers();
            $companions = Olama_Transportation_Bus::get_available_companions();
        } elseif ($active_tab === 'assignments') {
            $selected_bus_id = isset($_GET['bus_id']) ? intval($_GET['bus_id']) : 0;
        }
        if (in_array($active_tab, array('overview', 'planning'), true) && $selected_year_id) {
            $summary = Olama_Transportation_Planning::report_summary($selected_year_id);
            $areas = Olama_Transportation_Repository::list_items('areas', array('per_page' => 500));
            $family_stops = Olama_Transportation_Repository::list_items('family-stops', array('per_page' => 100));
        }
        if ($active_tab === 'routes') {
            $routes = Olama_Transportation_Routes::list_routes(array('academic_year_id' => $selected_year_id));
            $stops = Olama_Transportation_Repository::list_items('stops', array('per_page' => 500, 'status' => 'active'));
        } elseif ($active_tab === 'import' && $selected_year_id) {
            $registered_families = Olama_Transportation_Family_Locations::registered_families($selected_year_id);
        }

        include OLAMA_TRANSPORTATION_PATH . 'admin-views/transportation.php';
    }

    public function enqueue_assets($hook)
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'olama-transportation') {
            return;
        }

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
                'saved'                  => Olama_School_Helpers::translate('Saved successfully'),
                'failed'                 => Olama_School_Helpers::translate('Operation failed'),
            ),
        ));
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
