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
        if (!class_exists('Olama_School_DB') || !class_exists('Olama_School_Permissions')) {
            deactivate_plugins(plugin_basename(OLAMA_TRANSPORTATION_FILE));
            wp_die(
                esc_html__('Olama Transportation requires the Olama School plugin to be installed and active.', 'olama-transportation'),
                esc_html__('Plugin dependency missing', 'olama-transportation'),
                array('back_link' => true)
            );
        }

        Olama_Transportation_DB::install();
        Olama_School_Permissions::add_capabilities();
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

        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-bus.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-ajax.php';
        require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-admin.php';

        add_filter('olama_school_student_bus', array($this, 'get_student_bus'), 10, 3);

        if (is_admin()) {
            new Olama_Transportation_Admin();
            new Olama_Transportation_Ajax();
            add_action('admin_init', array($this, 'maybe_update_schema'), 5);
        }
    }

    private function dependencies_available()
    {
        return defined('OLAMA_SCHOOL_FILE')
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
        Olama_School_Permissions::add_capabilities();
        update_option('olama_transportation_db_version', OLAMA_TRANSPORTATION_VERSION);
    }

    public function dependency_notice()
    {
        if ($this->available || !current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            . esc_html__('Olama Transportation is inactive because Olama School is not active.', 'olama-transportation')
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
            'description' => __('Bus records, drivers, companions, capacity, and student bus assignments.', 'olama-transportation'),
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
            ),
        );

        return $cards;
    }
}
