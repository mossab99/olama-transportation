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
        <?php if ($active_tab === 'overview'): ?>
            <div class="olama-transportation-toolbar">
                <h2><?php echo esc_html($translate('Semester Overview')); ?></h2>
                <label><?php echo esc_html($translate('Academic Year')); ?>
                    <select class="olama-year-navigation">
                        <?php foreach ($years as $year): ?>
                            <option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id, $year->id); ?>><?php echo esc_html($year->year_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="olama-metric-grid">
                <div class="olama-metric"><strong><?php echo intval($summary['enrolled_students'] ?? 0); ?></strong><span><?php echo esc_html($translate('Enrolled Students')); ?></span></div>
                <div class="olama-metric"><strong><?php echo intval($summary['verified_family_stops'] ?? 0); ?></strong><span><?php echo esc_html($translate('Approved Family Stops')); ?></span></div>
                <div class="olama-metric"><strong><?php echo intval($summary['stops_needing_review'] ?? 0); ?></strong><span><?php echo esc_html($translate('Stops Needing Review')); ?></span></div>
                <div class="olama-metric"><strong><?php echo intval($summary['published_routes'] ?? 0); ?></strong><span><?php echo esc_html($translate('Published Routes')); ?></span></div>
            </div>
            <div class="olama-card">
                <h2><?php echo esc_html($translate('Readiness Checklist')); ?></h2>
                <ol class="olama-readiness-list">
                    <li><?php echo esc_html($translate('Synchronize Oracle master data into Olama Core, then refresh the Transportation planning view.')); ?></li>
                    <li><?php echo esc_html($translate('Import and approve WhatsApp family locations.')); ?></li>
                    <li><?php echo esc_html($translate('Create student-level morning and afternoon enrollments.')); ?></li>
                    <li><?php echo esc_html($translate('Map family stops to major areas and allocate buses.')); ?></li>
                    <li><?php echo esc_html($translate('Create, optimize, review, and publish immutable route versions.')); ?></li>
                </ol>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'planning'): ?>
            <div id="olama-geographic-planner" class="olama-card" data-year-id="<?php echo intval($selected_year_id); ?>">
                <div class="olama-planner-header">
                    <div><h2><?php echo esc_html($translate('Geographic Planning')); ?></h2><div id="planner-demand-status" class="olama-planner-message" aria-live="polite"></div></div>
                    <div class="olama-planner-actions">
                        <button type="button" id="planner-refresh-areas" class="button"><?php echo esc_html($translate('Refresh Areas from Olama Core')); ?></button>
                        <button type="button" id="planner-refresh-map" class="button"><?php echo esc_html($translate('Refresh Map')); ?></button>
                        <button type="button" id="planner-new-group" class="button button-primary"><?php echo esc_html($translate('Create New Group')); ?></button>
                    </div>
                </div>
                <div class="olama-planner-metrics" aria-live="polite">
                    <div><strong id="metric-valid">0</strong><span><?php echo esc_html($translate('Valid locations')); ?></span></div>
                    <div><strong id="metric-assigned">0</strong><span><?php echo esc_html($translate('Assigned')); ?></span></div>
                    <div><strong id="metric-unassigned">0</strong><span><?php echo esc_html($translate('Unassigned')); ?></span></div>
                    <div><strong id="metric-selected">0</strong><span><?php echo esc_html($translate('Selected')); ?></span></div>
                    <div><strong id="metric-invalid">0</strong><span><?php echo esc_html($translate('Missing / invalid')); ?></span></div>
                </div>
                <div class="olama-planner-layout">
                    <aside class="olama-planner-filters">
                        <h3><?php echo esc_html($translate('Filters')); ?></h3>
                        <label><?php echo esc_html($translate('Academic Year')); ?><select id="planner-year"><?php foreach ($years as $year): ?><option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id, $year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></label>
                        <label><?php echo esc_html($translate('Direction')); ?><select id="planner-direction"><option value="morning"><?php echo esc_html($translate('Morning')); ?></option><option value="afternoon"><?php echo esc_html($translate('Afternoon')); ?></option></select></label>
                        <label><?php echo esc_html($translate('Oracle Major Area')); ?><select id="planner-area"><option value=""><?php echo esc_html($translate('All areas')); ?></option></select></label>
                        <label><?php echo esc_html($translate('Assignment')); ?><select id="planner-assignment"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="assigned"><?php echo esc_html($translate('Assigned')); ?></option><option value="unassigned"><?php echo esc_html($translate('Unassigned')); ?></option></select></label>
                        <label><?php echo esc_html($translate('Location Status')); ?><select id="planner-location-status"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="needs_review"><?php echo esc_html($translate('Needs review')); ?></option><option value="approved"><?php echo esc_html($translate('Approved')); ?></option></select></label>
                        <label><?php echo esc_html($translate('Bus')); ?><select id="planner-filter-bus"><option value=""><?php echo esc_html($translate('All buses')); ?></option></select></label>
                        <label><?php echo esc_html($translate('Trip')); ?><select id="planner-filter-trip"><option value=""><?php echo esc_html($translate('All trips')); ?></option></select></label>
                        <label><?php echo esc_html($translate('Family search')); ?><input id="planner-search" type="search" /></label>
                        <button type="button" id="planner-reset" class="button"><?php echo esc_html($translate('Reset')); ?></button>
                    </aside>
                    <main class="olama-planner-map-wrap">
                        <div id="olama-planning-map" role="application" aria-label="<?php echo esc_attr($translate('Family planning map')); ?>"></div>
                        <div class="olama-map-legend"><span class="marker-key marker-unassigned">○ <?php echo esc_html($translate('Unassigned')); ?></span><span class="marker-key marker-selected">◉ <?php echo esc_html($translate('Selected')); ?></span><span class="marker-key marker-review">△ <?php echo esc_html($translate('Needs review')); ?></span></div>
                    </main>
                    <aside class="olama-group-panel">
                        <h3 id="group-panel-title"><?php echo esc_html($translate('Current Group')); ?></h3>
                        <label><?php echo esc_html($translate('Group Name')); ?><input id="group-name" maxlength="180" /></label>
                        <label><?php echo esc_html($translate('Primary Area')); ?><select id="group-area"><option value=""><?php echo esc_html($translate('No primary area')); ?></option></select></label>
                        <label><?php echo esc_html($translate('Bus')); ?><select id="group-bus"><option value=""><?php echo esc_html($translate('Select Bus')); ?></option></select></label>
                        <label><?php echo esc_html($translate('Trip Number')); ?><select id="group-trip"><option value=""><?php echo esc_html($translate('Select Trip')); ?></option></select></label>
                        <label><?php echo esc_html($translate('Group Color')); ?><input id="group-color" type="color" value="#2563eb" /></label>
                        <button type="button" id="planner-draw" class="button"><?php echo esc_html($translate('Select by Area')); ?></button>
                        <dl class="olama-group-totals"><div><dt><?php echo esc_html($translate('Families')); ?></dt><dd id="group-family-count">0</dd></div><div><dt><?php echo esc_html($translate('Students')); ?></dt><dd id="group-student-count">0</dd></div><div><dt><?php echo esc_html($translate('Capacity')); ?></dt><dd id="group-capacity">0</dd></div><div><dt><?php echo esc_html($translate('Remaining')); ?></dt><dd id="group-remaining">0</dd></div><div><dt><?php echo esc_html($translate('Usage')); ?></dt><dd id="group-usage">0%</dd></div></dl>
                        <div id="group-capacity-status" class="olama-capacity-status"></div>
                        <div id="group-included-areas" class="description"></div>
                        <ul id="group-family-list" class="olama-selected-families"></ul>
                        <label><?php echo esc_html($translate('Notes')); ?><textarea id="group-notes" rows="3"></textarea></label>
                        <div id="group-error" class="olama-planner-message" aria-live="polite"></div>
                        <div class="olama-panel-buttons"><button type="button" id="group-cancel" class="button"><?php echo esc_html($translate('Cancel')); ?></button><button type="button" id="group-save" class="button button-primary" disabled><?php echo esc_html($translate('Save Draft')); ?></button></div>
                    </aside>
                </div>
            </div>
            <div class="olama-card olama-card-spaced">
                <h2><?php echo esc_html($translate('Saved Geographic Groups')); ?></h2>
                <table class="wp-list-table widefat striped"><thead><tr><th><?php echo esc_html($translate('Group')); ?></th><th><?php echo esc_html($translate('Primary Area')); ?></th><th><?php echo esc_html($translate('Direction')); ?></th><th><?php echo esc_html($translate('Bus')); ?></th><th><?php echo esc_html($translate('Trip')); ?></th><th><?php echo esc_html($translate('Families')); ?></th><th><?php echo esc_html($translate('Students')); ?></th><th><?php echo esc_html($translate('Capacity')); ?></th><th><?php echo esc_html($translate('Remaining')); ?></th><th><?php echo esc_html($translate('Status')); ?></th><th><?php echo esc_html($translate('Updated')); ?></th><th><?php echo esc_html($translate('Actions')); ?></th></tr></thead><tbody id="planner-groups-body"><tr><td colspan="12"><?php echo esc_html($translate('Loading...')); ?></td></tr></tbody></table>
            </div>
            <details class="olama-card olama-card-spaced"><summary><strong><?php echo esc_html($translate('Major Areas and Demand Summary')); ?></strong></summary>
                <table class="wp-list-table widefat striped"><thead><tr><th><?php echo esc_html($translate('Area')); ?></th><th><?php echo esc_html($translate('Morning Students')); ?></th><th><?php echo esc_html($translate('Morning Capacity')); ?></th><th><?php echo esc_html($translate('Afternoon Students')); ?></th><th><?php echo esc_html($translate('Afternoon Capacity')); ?></th></tr></thead><tbody><?php foreach (($summary['area_demand'] ?? array()) as $demand): ?><tr><td><?php echo esc_html($demand['major_area_name']); ?></td><td><?php echo intval($demand['morning_students']); ?></td><td><?php echo intval($demand['morning_capacity']); ?></td><td><?php echo intval($demand['afternoon_students']); ?></td><td><?php echo intval($demand['afternoon_capacity']); ?></td></tr><?php endforeach; ?><?php if (empty($summary['area_demand'])): ?><tr><td colspan="5"><?php echo esc_html($translate('Refresh areas from Olama Core to populate planning classifications.')); ?></td></tr><?php endif; ?></tbody></table>
            </details>
        <?php endif; ?>

        <?php if ($active_tab === 'routes'): ?>
            <div class="olama-card">
                <div class="olama-transportation-toolbar"><h2><?php echo esc_html($translate('Route Versions')); ?></h2></div>
                <p class="description"><?php echo esc_html($translate('Approved geographic planning groups identify the bus, direction, and trip, but remain separate from route versions until route generation is implemented.')); ?></p>
                <form id="route-form" class="olama-inline-form olama-route-form">
                    <input type="hidden" name="academic_year_id" value="<?php echo intval($selected_year_id); ?>" />
                    <input name="name" required placeholder="<?php echo esc_attr($translate('Route Name')); ?>" />
                    <select name="bus_id" required><option value=""><?php echo esc_html($translate('Select Bus')); ?></option><?php foreach ($buses as $bus): ?><option value="<?php echo intval($bus->id); ?>"><?php echo esc_html($bus->bus_number); ?></option><?php endforeach; ?></select>
                    <select name="direction" required><option value="morning"><?php echo esc_html($translate('Morning')); ?></option><option value="afternoon"><?php echo esc_html($translate('Afternoon')); ?></option></select>
                    <select name="stop_ids[]" multiple required class="olama-stops-select"><?php foreach ($stops as $stop): ?><option value="<?php echo intval($stop['id']); ?>"><?php echo esc_html($stop['name']); ?></option><?php endforeach; ?></select>
                    <button class="button button-primary" type="submit"><?php echo esc_html($translate('Create Draft')); ?></button>
                </form>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th><?php echo esc_html($translate('Name')); ?></th><th><?php echo esc_html($translate('Bus')); ?></th><th><?php echo esc_html($translate('Direction')); ?></th><th><?php echo esc_html($translate('Version')); ?></th><th><?php echo esc_html($translate('Status')); ?></th><th><?php echo esc_html($translate('Actions')); ?></th></tr></thead>
                    <tbody><?php foreach ($routes as $route): ?><tr><td><?php echo esc_html($route['name']); ?></td><td><?php echo intval($route['bus_id']); ?></td><td><?php echo esc_html($route['direction']); ?></td><td><?php echo intval($route['version_number']); ?></td><td><?php echo esc_html($route['status']); ?></td><td><?php if ($route['status'] === 'draft'): ?><button class="button olama-optimize-route" data-id="<?php echo intval($route['id']); ?>"><?php echo esc_html($translate('Optimize')); ?></button> <button class="button button-primary olama-publish-route" data-id="<?php echo intval($route['id']); ?>"><?php echo esc_html($translate('Publish')); ?></button><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$routes): ?><tr><td colspan="6"><?php echo esc_html($translate('No route versions.')); ?></td></tr><?php endif; ?></tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'import'): ?>
            <div class="olama-card">
                <div class="olama-transportation-toolbar">
                    <div>
                        <h2><?php echo esc_html($translate('Family Transportation Locations')); ?></h2>
                        <p><?php echo esc_html($translate('Paste the coordinates received from WhatsApp, for example: 32.110416, 35.752544. Full Google Maps links containing coordinates are also accepted.')); ?></p>
                    </div>
                    <label><?php echo esc_html($translate('Academic Year')); ?>
                        <select class="olama-year-navigation">
                            <?php foreach ($years as $year): ?>
                                <option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id, $year->id); ?>><?php echo esc_html($year->year_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <?php
                $families_with_locations = count(array_filter($registered_families, function ($family) {
                    return $family['latitude'] !== null && $family['longitude'] !== null;
                }));
                ?>
                <div class="olama-location-summary">
                    <div><strong><?php echo count($registered_families); ?></strong><span><?php echo esc_html($translate('Registered Families')); ?></span></div>
                    <div><strong><?php echo intval($families_with_locations); ?></strong><span><?php echo esc_html($translate('Locations Entered')); ?></span></div>
                    <div><strong><?php echo max(0, count($registered_families) - $families_with_locations); ?></strong><span><?php echo esc_html($translate('Locations Missing')); ?></span></div>
                </div>

                <details class="olama-whatsapp-location-guide">
                    <summary><?php echo esc_html($translate('Suggested WhatsApp message for families')); ?></summary>
                    <textarea id="family-location-whatsapp-template" readonly>يرجى إرسال موقع نقطة صعود الطالب: افتح مشبك المرفقات في واتساب، اختر "الموقع"، ثم اختر "إرسال موقعك الحالي". يرجى إرسال الموقع من نقطة الصعود الآمنة وليس من داخل المنزل.</textarea>
                    <button type="button" class="button" id="copy-family-location-template"><?php echo esc_html($translate('Copy Message')); ?></button>
                    <span id="copy-family-location-result" aria-live="polite"></span>
                </details>

                <div class="olama-family-location-tools">
                    <label>
                        <span class="screen-reader-text"><?php echo esc_html($translate('Search families')); ?></span>
                        <input type="search" id="family-location-search" placeholder="<?php echo esc_attr($translate('Search by family number, name, phone, or student')); ?>" />
                    </label>
                    <label><input type="checkbox" id="family-location-missing-only" /> <?php echo esc_html($translate('Show missing locations only')); ?></label>
                </div>

                <div class="olama-family-location-table-wrap">
                    <table class="wp-list-table widefat fixed striped olama-family-location-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html($translate('Family')); ?></th>
                                <th><?php echo esc_html($translate('Students')); ?></th>
                                <th><?php echo esc_html($translate('Phone')); ?></th>
                                <th><?php echo esc_html($translate('Oracle Address')); ?></th>
                                <th><?php echo esc_html($translate('WhatsApp Location')); ?></th>
                                <th><?php echo esc_html($translate('Status')); ?></th>
                                <th><?php echo esc_html($translate('Actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($registered_families as $family): ?>
                            <?php
                            $has_location = $family['latitude'] !== null && $family['longitude'] !== null;
                            $location_value = $has_location ? $family['latitude'] . ', ' . $family['longitude'] : '';
                            $map_url = $has_location
                                ? 'https://www.google.com/maps?q=' . rawurlencode($family['latitude'] . ',' . $family['longitude'])
                                : '';
                            ?>
                            <tr data-family-location-row data-has-location="<?php echo $has_location ? '1' : '0'; ?>">
                                <td><strong><?php echo esc_html($family['family_name']); ?></strong><small>#<?php echo esc_html($family['oracle_family_id']); ?></small></td>
                                <td><?php echo intval($family['registered_students']); ?></td>
                                <td dir="ltr"><?php echo $family['mobile'] ? esc_html($family['mobile']) : '-'; ?></td>
                                <td><?php echo $family['oracle_address'] ? esc_html($family['oracle_address']) : '-'; ?></td>
                                <td>
                                    <input
                                        type="text"
                                        class="widefat family-location-input"
                                        value="<?php echo esc_attr($location_value); ?>"
                                        placeholder="32.110416, 35.752544"
                                        inputmode="decimal"
                                        autocomplete="off"
                                    />
                                    <small class="family-location-result" aria-live="polite"></small>
                                </td>
                                <td>
                                    <span class="olama-status-pill olama-status-<?php echo esc_attr($family['verification_status'] ?: 'missing'); ?>">
                                        <?php echo esc_html($family['verification_status'] ?: $translate('Missing')); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="button button-primary olama-save-family-location" data-family-uid="<?php echo esc_attr($family['family_uid']); ?>"><?php echo esc_html($translate('Save')); ?></button>
                                    <a class="button olama-view-family-location <?php echo $has_location ? '' : 'is-hidden'; ?>" href="<?php echo $has_location ? esc_url($map_url) : '#'; ?>" target="_blank" rel="noopener"><?php echo esc_html($translate('Map')); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$registered_families): ?>
                            <tr><td colspan="7"><?php echo esc_html($translate('No registered families were found in Olama Core for the selected academic year.')); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <details class="olama-bulk-location-import">
                    <summary><?php echo esc_html($translate('Advanced: import many locations from Excel or CSV')); ?></summary>
                    <p><?php echo esc_html($translate('Use family_id, latitude/longitude or Google Maps URL, address, area, phone, and notes. Imported locations remain pending review.')); ?></p>
                    <form id="family-stop-import-form" enctype="multipart/form-data">
                        <input type="file" name="file" accept=".csv,.xlsx,.xls" required />
                        <button type="submit" class="button"><?php echo esc_html($translate('Import and Reconcile')); ?></button>
                    </form>
                    <div id="import-result" class="olama-operation-result" aria-live="polite"></div>
                </details>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'settings'): ?>
            <div class="olama-card">
                <h2><?php echo esc_html($translate('Transportation Settings')); ?></h2>
                <form id="transport-settings-form" class="olama-settings-form">
                    <h3><?php echo esc_html($translate('Olama Core Master Data')); ?></h3>
                    <p><?php echo esc_html($translate('Oracle credentials and synchronization are managed centrally by Olama Oracle Sync. Transportation never connects to Oracle directly.')); ?></p>
                    <button type="button" id="refresh-core-buses" class="button"><?php echo esc_html($translate('Refresh Buses from Olama Core')); ?></button>
                    <h3><?php echo esc_html($translate('Route Optimizer')); ?></h3>
                    <label><?php echo esc_html($translate('Provider')); ?><select name="optimizer_provider"><option value="manual" <?php selected($settings['optimizer_provider'] ?? 'manual', 'manual'); ?>><?php echo esc_html($translate('Manual only')); ?></option><option value="google" <?php selected($settings['optimizer_provider'] ?? '', 'google'); ?>>Google</option><option value="webhook" <?php selected($settings['optimizer_provider'] ?? '', 'webhook'); ?>><?php echo esc_html($translate('External Webhook')); ?></option></select></label>
                    <label>Google Project ID<input name="google_project_id" value="<?php echo esc_attr($settings['google_project_id'] ?? ''); ?>" /></label>
                    <label><?php echo esc_html($translate('External Webhook URL')); ?><input type="url" name="optimizer_webhook_url" value="<?php echo esc_attr($settings['optimizer_webhook_url'] ?? ''); ?>" /></label>
                    <label><?php echo esc_html($translate('Webhook Secret')); ?><input type="password" name="optimizer_webhook_secret" value="" autocomplete="new-password" /></label>
                    <h3>Traccar (<?php echo esc_html($translate('Future Optional Service')); ?>)</h3>
                    <label><input type="checkbox" name="traccar_enabled" value="1" <?php checked(!empty($settings['traccar_enabled'])); ?> /> <?php echo esc_html($translate('Enable Traccar integration')); ?></label>
                    <label>Traccar URL<input type="url" name="traccar_url" value="<?php echo esc_attr($settings['traccar_url'] ?? ''); ?>" /></label>
                    <p><button class="button button-primary" type="submit"><?php echo esc_html($translate('Save Settings')); ?></button></p>
                </form>
                <div id="settings-result" class="olama-operation-result" aria-live="polite"></div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'buses'): ?>
            <div class="olama-card">
                <div class="olama-transportation-toolbar">
                    <h2><?php echo esc_html($translate('Bus Management')); ?></h2>
                    <button type="button" id="refresh-core-buses" class="button button-primary"><?php echo esc_html($translate('Refresh from Olama Core')); ?></button>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html($translate('School Bus Code')); ?></th>
                            <th><?php echo esc_html($translate('Bus Number')); ?></th>
                            <th><?php echo esc_html($translate('Driver License Number')); ?></th>
                            <th><?php echo esc_html($translate('Passenger Capacity')); ?></th>
                            <th><?php echo esc_html($translate('Morning Trips')); ?></th>
                            <th><?php echo esc_html($translate('Afternoon Trips')); ?></th>
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
                                    <td><?php echo $bus->government_number ? esc_html($bus->government_number) : '-'; ?></td>
                                    <td><?php echo $bus->driver_license_number ? esc_html($bus->driver_license_number) : '-'; ?></td>
                                    <td><?php echo intval($bus->passenger_capacity); ?><?php if (intval($bus->passenger_capacity) === 0 && intval($bus->planning_capacity) > 0): ?><br><small><?php echo esc_html($translate('Planning override')); ?>: <?php echo intval($bus->planning_capacity); ?></small><?php endif; ?></td>
                                    <td><?php echo intval($bus->morning_trip_count); ?></td>
                                    <td><?php echo intval($bus->afternoon_trip_count); ?></td>
                                    <td>
                                        <?php
                                        echo $bus->driver_name ? esc_html($bus->driver_name) : '-';
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        echo $bus->companion_name ? esc_html($bus->companion_name) : '-';
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
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="11"><?php echo esc_html($translate('No data')); ?></td></tr>
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
                                <p><label><?php echo esc_html($translate('School Bus Code')); ?></label><input type="text" id="bus-number" readonly class="widefat" /></p>
                                <p><label><?php echo esc_html($translate('Bus Number')); ?></label><input type="text" id="bus-government-number" readonly class="widefat" /></p>
                                <p><label><?php echo esc_html($translate('Driver License Number')); ?></label><input type="text" id="bus-driver-license-number" readonly class="widefat" /></p>
                                <p><label><?php echo esc_html($translate('Registered Capacity')); ?></label><input type="number" id="bus-capacity" readonly class="widefat" /></p>
                                <p><label><?php echo esc_html($translate('Planning Capacity')); ?></label><input type="number" name="planning_capacity" id="bus-planning-capacity" required class="widefat" min="1" /></p>
                                <p><label><?php echo esc_html($translate('Morning Trips')); ?></label><input type="number" name="morning_trip_count" id="bus-morning-trip-count" required class="widefat" min="1" max="10" value="2" /></p>
                                <p><label><?php echo esc_html($translate('Afternoon Trips')); ?></label><input type="number" name="afternoon_trip_count" id="bus-afternoon-trip-count" required class="widefat" min="1" max="10" value="3" /></p>
                                <p id="bus-core-capacity-warning" class="description" style="display:none"><?php echo esc_html($translate('Core capacity is missing. The positive value entered here is a local planning override.')); ?></p>
                                <p><label><?php echo esc_html($translate('License Expiry')); ?></label><input type="text" id="bus-license-expiry" readonly class="widefat" /></p>
                                <p><label><?php echo esc_html($translate('Driver')); ?></label><select name="driver_user_id" id="bus-driver-id" class="widefat"><option value=""><?php echo esc_html($translate('Select Driver')); ?></option><?php foreach ($drivers as $driver): ?><option value="<?php echo intval($driver->ID); ?>"><?php echo esc_html($driver->display_name); ?></option><?php endforeach; ?></select></p>
                                <p><label><?php echo esc_html($translate('Companion')); ?></label><select name="companion_user_id" id="bus-companion-id" class="widefat"><option value=""><?php echo esc_html($translate('Select Companion')); ?></option><?php foreach ($companions as $companion): ?><option value="<?php echo intval($companion->ID); ?>"><?php echo esc_html($companion->display_name); ?></option><?php endforeach; ?></select></p>
                                <p><label><?php echo esc_html($translate('Engine Capacity')); ?></label><input type="text" id="bus-engine-capacity" readonly class="widefat" /></p>
                                <p><label><?php echo esc_html($translate('Fuel Type')); ?></label><input type="text" id="bus-fuel-type" readonly class="widefat" /></p>
                            </div>
                            <p><label><?php echo esc_html($translate('Core Status')); ?></label><select id="bus-status" disabled class="widefat"><option value="active"><?php echo esc_html($translate('Active')); ?></option><option value="inactive"><?php echo esc_html($translate('Inactive')); ?></option></select></p>
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
                <p class="notice notice-info inline"><?php echo esc_html($translate('This screen uses the legacy annual student-to-bus assignment model. Geographic groups are maintained separately by direction and trip.')); ?></p>
                <div class="olama-form-grid">
                    <p><label><?php echo esc_html($translate('Academic Year')); ?></label><select id="assignment-year-filter" class="widefat"><?php foreach ($years as $year): ?><option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id, $year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></p>
                    <p><label><?php echo esc_html($translate('Select Bus')); ?></label><select id="assignment-bus-filter" class="widefat"><option value=""><?php echo esc_html($translate('Select a bus')); ?></option><?php foreach ($buses as $bus): ?><option value="<?php echo intval($bus->id); ?>" <?php selected($selected_bus_id, $bus->id); ?>><?php echo esc_html($bus->bus_number . ' - ' . ($bus->government_number ?: $translate('No bus number'))); ?></option><?php endforeach; ?></select></p>
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
