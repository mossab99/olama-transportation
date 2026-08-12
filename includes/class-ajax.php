<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Transportation_Ajax
{
    public function __construct()
    {
        add_action('wp_ajax_olama_save_bus', array($this, 'save_bus'));
        add_action('wp_ajax_olama_get_buses', array($this, 'get_buses'));
        add_action('wp_ajax_olama_get_bus', array($this, 'get_bus'));
        add_action('wp_ajax_olama_delete_bus', array($this, 'delete_bus'));
        add_action('wp_ajax_olama_assign_students_to_bus', array($this, 'assign_students_to_bus'));
        add_action('wp_ajax_olama_unassign_student_from_bus', array($this, 'unassign_student_from_bus'));
        add_action('wp_ajax_olama_get_bus_students', array($this, 'get_bus_students'));
        add_action('wp_ajax_olama_get_unassigned_students', array($this, 'get_unassigned_students'));
        add_action('wp_ajax_olama_get_assignment_trips', array($this, 'get_assignment_trips'));
        add_action('wp_ajax_olama_get_trip_area_students', array($this, 'get_trip_area_students'));
        add_action('wp_ajax_olama_sync_trip_students', array($this, 'sync_trip_students'));
        add_action('wp_ajax_olama_attach_trip_area', array($this, 'attach_trip_area'));
    }

    public function save_bus()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_manage_transport_buses');

        $result = Olama_Transportation_Bus::save_bus($_POST);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'id'      => $result,
            'message' => __('Bus saved successfully', 'olama-transportation'),
        ));
    }

    public function get_buses()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_access_transport_mgmt');
        wp_send_json_success(Olama_Transportation_Bus::get_buses());
    }

    public function get_bus()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_access_transport_mgmt');

        $bus = Olama_Transportation_Bus::get_bus(isset($_POST['id']) ? intval($_POST['id']) : 0);
        if (!$bus) {
            wp_send_json_error(__('Bus not found', 'olama-transportation'));
        }

        wp_send_json_success($bus);
    }

    public function delete_bus()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_manage_transport_buses');

        $result = Olama_Transportation_Bus::delete_bus(isset($_POST['id']) ? intval($_POST['id']) : 0);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        if (!$result) {
            wp_send_json_error(__('Failed to delete bus', 'olama-transportation'));
        }

        wp_send_json_success(__('Bus deleted successfully', 'olama-transportation'));
    }

    public function assign_students_to_bus()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_manage_transport_buses');

        $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
        $student_ids = isset($_POST['student_ids']) ? array_map('intval', (array) $_POST['student_ids']) : array();
        $academic_year_id = isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0;

        if (!$bus_id || empty($student_ids) || !$academic_year_id) {
            wp_send_json_error(__('Missing required parameters', 'olama-transportation'));
        }

        $result = Olama_Transportation_Bus::assign_students_to_bus($bus_id, $student_ids, $academic_year_id);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => sprintf(__('%d student(s) assigned successfully', 'olama-transportation'), $result['success']),
            'result'  => $result,
        ));
    }

    public function unassign_student_from_bus()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_manage_transport_buses');

        $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
        $academic_year_id = isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0;

        if (!$student_id || !$academic_year_id) {
            wp_send_json_error(__('Missing required parameters', 'olama-transportation'));
        }

        if (!Olama_Transportation_Bus::unassign_student($student_id, $academic_year_id)) {
            wp_send_json_error(__('Failed to unassign student', 'olama-transportation'));
        }

        wp_send_json_success(__('Student unassigned successfully', 'olama-transportation'));
    }

    public function get_bus_students()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_access_transport_mgmt');

        $bus_id = isset($_GET['bus_id']) ? intval($_GET['bus_id']) : 0;
        $academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : 0;

        if (!$bus_id || !$academic_year_id) {
            wp_send_json_error(__('Missing required parameters', 'olama-transportation'));
        }

        wp_send_json_success(array(
            'students' => Olama_Transportation_Bus::get_bus_students($bus_id, $academic_year_id),
            'capacity' => Olama_Transportation_Bus::get_bus_capacity_info($bus_id, $academic_year_id),
        ));
    }

    public function get_unassigned_students()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_access_transport_mgmt');

        $academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : 0;
        if (!$academic_year_id) {
            wp_send_json_error(__('Missing academic year ID', 'olama-transportation'));
        }

        global $wpdb;
        $study_year = Olama_Transportation_Bus::study_year($academic_year_id);
        if ($study_year === '') {
            wp_send_json_error(__('The selected academic year could not be mapped to Olama Core.', 'olama-transportation'));
        }
        $core_students = olama_core()->read_models()->table('students');
        $core_student_years = olama_core()->read_models()->table('student_years');
        $students = $wpdb->get_results($wpdb->prepare("
            SELECT s.*, sy.section_name, sy.class_name AS grade_name
            FROM {$core_students} s
            JOIN {$core_student_years} sy ON s.student_uid = sy.student_uid
            LEFT JOIN {$wpdb->prefix}olama_student_bus_assignments a
                ON s.student_uid = a.student_uid AND a.academic_year_id = %d
            WHERE sy.study_year = %s AND a.id IS NULL
            ORDER BY sy.class_name, sy.section_name, s.student_name
        ", $academic_year_id, $study_year));

        $total_enrolled = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$core_student_years} WHERE study_year = %s",
            $study_year
        ));
        $total_assigned = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_student_bus_assignments WHERE academic_year_id = %d",
            $academic_year_id
        ));

        wp_send_json_success(array(
            'students' => $students,
            'debug'    => array(
                'total_enrolled'   => $total_enrolled,
                'total_assigned'   => $total_assigned,
                'unassigned_count' => count($students),
                'academic_year_id' => $academic_year_id,
            ),
        ));
    }

    public function get_assignment_trips()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_access_transport_mgmt');
        $bus_id = isset($_GET['bus_id']) ? absint($_GET['bus_id']) : 0;
        $year_id = isset($_GET['academic_year_id']) ? absint($_GET['academic_year_id']) : 0;
        if (!$bus_id || !$year_id) wp_send_json_error(__('Select an academic year and bus first.', 'olama-transportation'));
        wp_send_json_success(Olama_Transportation_Bus::get_assignment_trips($bus_id, $year_id));
    }

    public function get_trip_area_students()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_access_transport_mgmt');
        $context = $this->trip_context($_GET);
        if (is_wp_error($context)) wp_send_json_error($context->get_error_message());
        $result = Olama_Transportation_Bus::get_trip_area_students($context['bus_id'], $context['academic_year_id'], $context['direction'], $context['trip_number']);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
        wp_send_json_success($result);
    }

    public function sync_trip_students()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_manage_transport_buses');
        $context = $this->trip_context($_POST);
        if (is_wp_error($context)) wp_send_json_error($context->get_error_message());
        $student_ids = isset($_POST['student_ids']) ? array_map('absint', (array) $_POST['student_ids']) : array();
        $result = Olama_Transportation_Bus::sync_trip_students($context['bus_id'], $context['academic_year_id'], $context['direction'], $context['trip_number'], $student_ids);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
        wp_send_json_success(array('message' => __('Student selections saved.', 'olama-transportation'), 'result' => $result));
    }

    public function attach_trip_area()
    {
        check_ajax_referer('olama_admin_nonce', 'nonce');
        $this->require_capability('olama_manage_transport_buses');
        $context = $this->trip_context($_POST);
        if (is_wp_error($context)) wp_send_json_error($context->get_error_message());
        $context['major_area_id'] = isset($_POST['major_area_id']) ? absint($_POST['major_area_id']) : 0;
        $result = Olama_Transportation_Bus::attach_area_to_trip($context['bus_id'], $context['academic_year_id'], $context['direction'], $context['trip_number'], $context['major_area_id']);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
        wp_send_json_success(array('message' => __('Area attached to the bus trip.', 'olama-transportation')));
    }

    private function trip_context($input)
    {
        $context = array(
            'bus_id' => isset($input['bus_id']) ? absint($input['bus_id']) : 0,
            'academic_year_id' => isset($input['academic_year_id']) ? absint($input['academic_year_id']) : 0,
            'direction' => isset($input['direction']) ? sanitize_key($input['direction']) : '',
            'trip_number' => isset($input['trip_number']) ? absint($input['trip_number']) : 0,
        );
        if (!$context['bus_id'] || !$context['academic_year_id'] || !$context['trip_number'] || !in_array($context['direction'], array('morning', 'afternoon'), true)) {
            return new WP_Error('invalid_trip_context', __('Select a valid bus trip.', 'olama-transportation'));
        }
        return $context;
    }

    private function require_capability($capability)
    {
        if (!Olama_School_Permissions::can($capability)) {
            wp_send_json_error(__('Unauthorized', 'olama-transportation'));
        }
    }
}
