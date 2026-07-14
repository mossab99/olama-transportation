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
        $students = $wpdb->get_results($wpdb->prepare("
            SELECT s.*, sec.section_name, g.grade_name
            FROM {$wpdb->prefix}olama_students s
            JOIN {$wpdb->prefix}olama_student_enrollment e ON s.student_uid = e.student_uid
            JOIN {$wpdb->prefix}olama_sections sec ON e.section_id = sec.id
            JOIN {$wpdb->prefix}olama_grades g ON sec.grade_id = g.id
            LEFT JOIN {$wpdb->prefix}olama_student_bus_assignments a
                ON s.student_uid = a.student_uid AND a.academic_year_id = %d
            WHERE e.academic_year_id = %d AND a.id IS NULL
            ORDER BY g.id, sec.id, s.student_name
        ", $academic_year_id, $academic_year_id));

        $total_enrolled = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_student_enrollment WHERE academic_year_id = %d",
            $academic_year_id
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

    private function require_capability($capability)
    {
        if (!Olama_School_Permissions::can($capability)) {
            wp_send_json_error(__('Unauthorized', 'olama-transportation'));
        }
    }
}
