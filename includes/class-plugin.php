<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Olama_Transportation_Plugin
{
    private static $instance = null;
    private $available = false;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate()
    {
        if (!self::dependencies_available_static()) {
            deactivate_plugins(plugin_basename(OLAMA_TRANSPORTATION_FILE));
            wp_die(
                esc_html__('Olama Transportation requires Olama Core and Olama School to be installed and active.', 'olama-transportation'),
                esc_html__('Plugin dependency missing', 'olama-transportation'),
                array('back_link' => true)
            );
        }

        Olama_Transportation_DB::install();
        self::default_settings();
        self::remove_legacy_oracle_settings();
        update_option('olama_transportation_db_version', OLAMA_TRANSPORTATION_VERSION);
    }

    private function __construct()
    {
        load_plugin_textdomain(
            'olama-transportation',
            false,
            dirname(plugin_basename(OLAMA_TRANSPORTATION_FILE)) . '/languages'
        );

        add_action('admin_notices', array($this, 'dependency_notice'));
        add_filter('olama_dashboard_cards', array($this, 'register_hub_card'), 20);

        $this->available = $this->dependencies_available();
        if (!$this->available) {
            return;
        }
        self::remove_legacy_oracle_settings();

        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-bus.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-audit.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-repository.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-family-locations.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-importer.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-planning.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-area-sync.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-map-data.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-effective-assignments.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-family-area-assignments.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-area-trip-assignments.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-areas-workspace.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-shared-trips.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-routes.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-optimizer.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-rest.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-ajax.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-admin.php';

        add_action('olama_core_transport_master_updated', array('Olama_Transportation_Bus', 'refresh_from_core'), 10, 0);
        add_action('olama_core_transport_master_updated', array('Olama_Transportation_Area_Sync', 'refresh_from_core'), 11, 0);
        add_filter('olama_school_student_bus', array($this, 'get_student_bus'), 10, 3);
        $rest = new Olama_Transportation_REST();
        add_action('rest_api_init', array($rest, 'register'));

        if (is_admin()) {
            new Olama_Transportation_Admin();
            new Olama_Transportation_Ajax();
            add_action('admin_init', array($this, 'maybe_update_schema'), 5);
        }
    }

    private function dependencies_available()
    {
        return self::dependencies_available_static();
    }

    private static function dependencies_available_static()
    {
        return defined('OLAMA_CORE_VERSION')
            && function_exists('olama_core')
            && method_exists(olama_core(), 'transport_master')
            && defined('OLAMA_SCHOOL_FILE')
            && class_exists('Olama_School_DB')
            && class_exists('Olama_School_Permissions')
            && class_exists('Olama_School_Academic')
            && class_exists('Olama_School_Helpers');
    }

    public function maybe_update_schema()
    {
        if (get_option('olama_transportation_db_version') === OLAMA_TRANSPORTATION_VERSION) {
            return;
        }

        Olama_Transportation_DB::install();
        self::default_settings();
        self::remove_legacy_oracle_settings();
        update_option('olama_transportation_db_version', OLAMA_TRANSPORTATION_VERSION);
    }

    public function dependency_notice()
    {
        if ($this->available || !current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            . esc_html__('Olama Transportation is inactive because Olama Core or Olama School is not active.', 'olama-transportation')
            . '</p></div>';
    }

    public function get_student_bus($bus, $student_id, $academic_year_id)
    {
        if ($bus) {
            return $bus;
        }

        return Olama_Transportation_Bus::get_student_bus($student_id, $academic_year_id);
    }

    public function register_hub_card($cards)
    {
        if (!$this->available) {
            return $cards;
        }

        foreach ($cards as &$card) {
            if (($card['id'] ?? '') !== 'olama-school' || empty($card['submenus'])) {
                continue;
            }

            $card['submenus'] = array_values(array_filter($card['submenus'], function ($submenu) {
                return ($submenu['id'] ?? '') !== 'school.transport';
            }));
        }
        unset($card);

        $cards[] = array(
            'id'          => 'olama-transportation',
            'label'       => __('Transportation', 'olama-transportation'),
            'description' => __('Fleet, family stops, enrollment, area demand, route optimization, approvals, and tracking integrations.', 'olama-transportation'),
            'icon'        => 'dashicons-car',
            'accent'      => '#1a56db',
            'accent_rgb'  => '26,86,219',
            'active'      => true,
            'capability'  => 'olama_access_transport_mgmt',
            'primary_url' => admin_url('admin.php?page=olama-transportation'),
            'submenus'    => array(
                array(
                    'id'         => 'transportation.buses',
                    'label'      => __('Buses', 'olama-transportation'),
                    'icon'       => 'dashicons-car',
                    'url'        => admin_url('admin.php?page=olama-transportation&tab=buses'),
                    'capability' => 'olama_access_transport_mgmt',
                    'color'      => '#1a56db',
                ),
                array(
                    'id'         => 'transportation.assignments',
                    'label'      => __('Student Assignments', 'olama-transportation'),
                    'icon'       => 'dashicons-groups',
                    'url'        => admin_url('admin.php?page=olama-transportation&tab=assignments'),
                    'capability' => 'olama_access_transport_mgmt',
                    'color'      => '#1a56db',
                ),
                array(
                    'id'         => 'transportation.planning',
                    'label'      => __('Planning', 'olama-transportation'),
                    'icon'       => 'dashicons-location-alt',
                    'url'        => admin_url('admin.php?page=olama-transportation&tab=planning'),
                    'capability' => 'olama_access_transport_mgmt',
                    'color'      => '#1a56db',
                ),
                array(
                    'id'         => 'transportation.import',
                    'label'      => __('Family Locations', 'olama-transportation'),
                    'icon'       => 'dashicons-upload',
                    'url'        => admin_url('admin.php?page=olama-transportation&tab=import'),
                    'capability' => 'olama_manage_transport_buses',
                    'color'      => '#1a56db',
                ),
            ),
        );

        return $cards;
    }

    private static function default_settings()
    {
        if (get_option('olama_transportation_settings', null) !== null) {
            return;
        }
        add_option('olama_transportation_settings', array(
            'optimizer_provider' => 'manual',
            'traccar_enabled' => 0,
            'school_location' => array('latitude' => 31.9539, 'longitude' => 35.9106),
            'service_bounds' => array('south' => 29, 'north' => 34, 'west' => 34, 'east' => 40),
        ), '', false);
    }

    private static function remove_legacy_oracle_settings()
    {
        $settings = get_option('olama_transportation_settings', array());
        $changed = false;
        foreach (array('oracle_api_url', 'oracle_api_key') as $legacy_key) {
            if (array_key_exists($legacy_key, $settings)) {
                unset($settings[$legacy_key]);
                $changed = true;
            }
        }
        if ($changed) {
            update_option('olama_transportation_settings', $settings, false);
        }
    }
}
