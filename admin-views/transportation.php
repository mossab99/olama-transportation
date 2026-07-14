<?php

if (!defined('ABSPATH')) {
    exit;
}

$translate = array('Olama_School_Helpers', 'translate');
?>
<div class="wrap olama-school-wrap olama-transportation-wrap">
    <h1><?php echo esc_html($translate('Transportation')); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($allowed_tabs as $id => $label): ?>
            <a href="<?php echo esc_url(add_query_arg(array('page' => 'olama-transportation', 'tab' => $id), admin_url('admin.php'))); ?>"
                class="nav-tab <?php echo $active_tab === $id ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <div class="olama-tab-content">
        <?php if ($active_tab === 'buses'): ?>
            <div class="olama-card">
                <div class="olama-transportation-toolbar">
                    <h2><?php echo esc_html($translate('Bus Management')); ?></h2>
                    <button type="button" class="button button-primary" onclick="olamaOpenBusModal()">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <?php echo esc_html($translate('Add New Bus')); ?>
                    </button>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html($translate('Bus Number')); ?></th>
                            <th><?php echo esc_html($translate('Plate Number')); ?></th>
                            <th><?php echo esc_html($translate('Passenger Capacity')); ?></th>
                            <th><?php echo esc_html($translate('Driver')); ?></th>
                            <th><?php echo esc_html($translate('Companion')); ?></th>
                            <th><?php echo esc_html($translate('License Expiry')); ?></th>
                            <th><?php echo esc_html($translate('Status')); ?></th>
                            <th><?php echo esc_html($translate('Actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($buses): ?>
                            <?php foreach ($buses as $bus): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($bus->bus_number); ?></strong></td>
                                    <td><?php echo esc_html($bus->plate_number); ?></td>
                                    <td><?php echo intval($bus->passenger_capacity); ?></td>
                                    <td>
                                        <?php
                                        $driver = $bus->driver_user_id ? get_userdata($bus->driver_user_id) : null;
                                        echo $driver ? esc_html($driver->display_name) : '-';
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $companion = $bus->companion_user_id ? get_userdata($bus->companion_user_id) : null;
                                        echo $companion ? esc_html($companion->display_name) : '-';
                                        ?>
                                    </td>
                                    <td><?php echo $bus->license_expiry_date ? esc_html(Olama_School_Helpers::format_date($bus->license_expiry_date)) : '-'; ?></td>
                                    <td>
                                        <span class="olama-status-pill olama-status-<?php echo esc_attr($bus->status); ?>">
                                            <?php echo esc_html($bus->status === 'active' ? $translate('Active') : $translate('Inactive')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small" onclick='olamaOpenBusModal(<?php echo wp_json_encode($bus); ?>)'>
                                            <span class="dashicons dashicons-edit"></span>
                                        </button>
                                        <button type="button" class="button button-small olama-danger-button" onclick="olamaDeleteBus(<?php echo intval($bus->id); ?>, '<?php echo esc_js($bus->bus_number); ?>')">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8"><?php echo esc_html($translate('No data')); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="olama-bus-modal" class="olama-modal">
                <div class="olama-modal-content olama-bus-modal-content">
                    <div class="olama-modal-header">
                        <h2 id="bus-modal-title"><?php echo esc_html($translate('Add New Bus')); ?></h2>
                        <button type="button" class="olama-modal-close" onclick="olamaCloseBusModal()">&times;</button>
                    </div>
                    <form id="olama-bus-form">
                        <div class="olama-modal-body">
                            <input type="hidden" name="id" id="bus-id" />
                            <div class="olama-form-grid">
                                <p><label><?php echo esc_html($translate('Bus Number')); ?></label><input type="text" name="bus_number" id="bus-number" required class="widefat" /></p>
                                <p><label><?php echo esc_html($translate('Plate Number')); ?></label><input type="text" name="plate_number" id="bus-plate-number" required class="widefat" /></p>
                                <p><label><?php echo esc_html($translate('Passenger Capacity')); ?></label><input type="number" name="passenger_capacity" id="bus-capacity" required class="widefat" min="1" /></p>
                                <p><label><?php echo esc_html($translate('License Expiry')); ?></label><input type="text" name="license_expiry_date" id="bus-license-expiry" class="widefat olama-datepicker" autocomplete="off" /></p>
                                <p><label><?php echo esc_html($translate('Driver')); ?></label><select name="driver_user_id" id="bus-driver-id" class="widefat"><option value=""><?php echo esc_html($translate('Select Driver')); ?></option><?php foreach ($drivers as $driver): ?><option value="<?php echo intval($driver->ID); ?>"><?php echo esc_html($driver->display_name); ?></option><?php endforeach; ?></select></p>
                                <p><label><?php echo esc_html($translate('Companion')); ?></label><select name="companion_user_id" id="bus-companion-id" class="widefat"><option value=""><?php echo esc_html($translate('Select Companion')); ?></option><?php foreach ($companions as $companion): ?><option value="<?php echo intval($companion->ID); ?>"><?php echo esc_html($companion->display_name); ?></option><?php endforeach; ?></select></p>
                                <p><label><?php echo esc_html($translate('Engine Capacity')); ?></label><input type="text" name="engine_capacity" id="bus-engine-capacity" class="widefat" /></p>
                                <p><label><?php echo esc_html($translate('Fuel Type')); ?></label><input type="text" name="fuel_type" id="bus-fuel-type" class="widefat" /></p>
                            </div>
                            <p><label><?php echo esc_html($translate('Status')); ?></label><select name="status" id="bus-status" class="widefat"><option value="active"><?php echo esc_html($translate('Active')); ?></option><option value="inactive"><?php echo esc_html($translate('Inactive')); ?></option></select></p>
                        </div>
                        <div class="olama-modal-footer">
                            <button type="button" class="button" onclick="olamaCloseBusModal()"><?php echo esc_html($translate('Cancel')); ?></button>
                            <button type="submit" class="button button-primary"><?php echo esc_html($translate('Save Bus')); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'assignments'): ?>
            <div class="olama-card">
                <h2><?php echo esc_html($translate('Student Assignments')); ?></h2>
                <div class="olama-form-grid">
                    <p><label><?php echo esc_html($translate('Academic Year')); ?></label><select id="assignment-year-filter" class="widefat"><?php foreach ($years as $year): ?><option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id, $year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></p>
                    <p><label><?php echo esc_html($translate('Select Bus')); ?></label><select id="assignment-bus-filter" class="widefat"><option value=""><?php echo esc_html($translate('Select a bus')); ?></option><?php foreach ($buses as $bus): ?><option value="<?php echo intval($bus->id); ?>" <?php selected($selected_bus_id, $bus->id); ?>><?php echo esc_html($bus->bus_number . ' - ' . $bus->plate_number); ?></option><?php endforeach; ?></select></p>
                </div>

                <div id="assignment-content" class="olama-assignment-content">
                    <div id="capacity-info" class="olama-capacity-info">
                        <strong><?php echo esc_html($translate('Capacity')); ?>:</strong> <span id="capacity-text">0/0</span>
                        <div class="olama-capacity-track"><div id="capacity-bar"></div></div>
                    </div>

                    <h3><?php echo esc_html($translate('Assigned Students')); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th><?php echo esc_html($translate('Student Name')); ?></th><th><?php echo esc_html($translate('Student ID')); ?></th><th><?php echo esc_html($translate('Grade')); ?></th><th><?php echo esc_html($translate('Section')); ?></th><th><?php echo esc_html($translate('Actions')); ?></th></tr></thead>
                        <tbody id="assigned-students-body"><tr><td colspan="5"><?php echo esc_html($translate('Loading...')); ?></td></tr></tbody>
                    </table>

                    <div class="olama-transportation-toolbar">
                        <h3><?php echo esc_html($translate('Unassigned Students')); ?></h3>
                        <button type="button" class="button button-primary" id="assign-selected-btn" disabled><?php echo esc_html($translate('Assign Selected')); ?></button>
                    </div>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th><input type="checkbox" id="select-all-students" /></th><th><?php echo esc_html($translate('Student Name')); ?></th><th><?php echo esc_html($translate('Student ID')); ?></th><th><?php echo esc_html($translate('Grade')); ?></th><th><?php echo esc_html($translate('Section')); ?></th></tr></thead>
                        <tbody id="unassigned-students-body"><tr><td colspan="5"><?php echo esc_html($translate('Loading...')); ?></td></tr></tbody>
                    </table>
                </div>

                <div id="no-bus-selected" class="olama-empty-state">
                    <span class="dashicons dashicons-car"></span>
                    <p><?php echo esc_html($translate('Please select a bus to manage student assignments')); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
