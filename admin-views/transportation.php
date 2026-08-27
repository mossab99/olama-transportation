<?php

if (!defined('ABSPATH')) {
    exit;
}

$translate = array('Olama_Transportation_I18n', 'translate');
?>
<div class="wrap olama-school-wrap olama-transportation-wrap" dir="<?php echo esc_attr(Olama_Transportation_I18n::direction()); ?>" data-language="<?php echo esc_attr(Olama_Transportation_I18n::language()); ?>">
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
            <?php include OLAMA_TRANSPORTATION_PATH . 'admin-views/dashboard.php'; ?>
        <?php endif; ?>

        <?php if ($active_tab === 'areas'): ?>
            <div id="olama-areas-workspace" class="olama-card" data-year-id="<?php echo intval($selected_year_id); ?>">
                <div class="olama-transportation-toolbar">
                    <div><h2><?php echo esc_html($translate('Trip Planning Workspace')); ?></h2><p class="description"><?php echo esc_html($translate('Create an independent trip, assign or replace its bus, then attach areas and students.')); ?></p></div>
                    <div><button type="button" class="button" id="areas-refresh-core"><?php echo esc_html($translate('Refresh Areas from Olama Core')); ?></button> <button type="button" class="button button-primary" id="olama-create-trip-plan"><?php echo esc_html($translate('Create Trip')); ?></button></div>
                </div>
                <div class="olama-area-filters">
                    <label><?php echo esc_html($translate('Academic Year')); ?><select id="areas-year"><?php foreach ($years as $year): ?><option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id,$year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></label>
                    <label><?php echo esc_html($translate('Direction')); ?><select id="areas-direction"><option value="morning"><?php echo esc_html($translate('Arrival / حضور')); ?></option><option value="afternoon"><?php echo esc_html($translate('Departure / عودة')); ?></option></select></label>
                    <label class="olama-trip-name-search"><?php echo esc_html($translate('Search trip name')); ?><input type="search" id="olama-trip-name-search" placeholder="<?php echo esc_attr($translate('Type a trip name')); ?>" autocomplete="off"></label>
                </div>
                <div id="areas-feedback" class="olama-operation-result" aria-live="polite"></div>
                <section class="olama-trip-board" data-no-search-results="<?php echo esc_attr($translate('No trips match this name.')); ?>"><div class="olama-trip-board-head"><h3><?php echo esc_html($translate('Trips')); ?></h3><span id="olama-trip-board-summary"></span></div><div id="olama-trip-board-list" class="olama-trip-board-list"></div></section>
                <details class="olama-area-demand-panel" open><summary><?php echo esc_html($translate('Area demand and assignments')); ?></summary><div class="olama-area-table-wrap"><table class="wp-list-table widefat striped olama-area-table"><thead><tr><th><?php echo esc_html($translate('Oracle Area')); ?></th><th><?php echo esc_html($translate('Walking Students')); ?></th><th><?php echo esc_html($translate('Transportation Coverage')); ?></th><th><?php echo esc_html($translate('Assigned Trips')); ?></th><th><?php echo esc_html($translate('Display Settings')); ?></th></tr></thead><tbody id="areas-body"><tr><td colspan="5"><?php echo esc_html($translate('Loading...')); ?></td></tr></tbody></table></div></details>
                <dialog id="olama-area-family-dialog" class="olama-area-family-dialog"><form method="dialog"><button class="olama-trip-wizard-close" aria-label="<?php echo esc_attr($translate('Close')); ?>">×</button></form><h2 id="olama-area-family-title"><?php echo esc_html($translate('Families')); ?></h2><div id="olama-area-family-list"></div></dialog>
                <dialog id="olama-trip-wizard-dialog" class="olama-trip-wizard-dialog" aria-labelledby="olama-trip-wizard-title">
                    <div class="olama-trip-wizard" data-step="1">
                        <header class="olama-trip-wizard-head"><div><h2 id="olama-trip-wizard-title"></h2><p id="olama-trip-wizard-context"></p></div><button type="button" class="olama-trip-wizard-close" aria-label="<?php echo esc_attr($translate('Close')); ?>">×</button></header>
                        <ol class="olama-trip-steps"><li><span>1</span><?php echo esc_html($translate('Trip Details')); ?></li><li><span>2</span><?php echo esc_html($translate('Bus Assignment')); ?></li><li><span>3</span><?php echo esc_html($translate('Areas & Students')); ?></li><li><span>4</span><?php echo esc_html($translate('Family Queue')); ?></li></ol>
                        <div id="olama-trip-wizard-summary" class="olama-trip-wizard-summary"></div>
                        <div id="olama-trip-wizard-feedback" class="olama-operation-result" aria-live="polite"></div>
                        <section class="olama-trip-step-panel" data-step-panel="1"></section>
                        <section class="olama-trip-step-panel" data-step-panel="2"></section>
                        <section class="olama-trip-step-panel" data-step-panel="3"></section>
                        <section class="olama-trip-step-panel" data-step-panel="4"></section>
                        <footer class="olama-trip-wizard-actions"><button type="button" class="button" id="olama-trip-wizard-back"><?php echo esc_html($translate('Back')); ?></button><span id="olama-trip-wizard-mode-note"><?php echo esc_html($translate('Draft changes save as you continue.')); ?></span><button type="button" class="button button-primary" id="olama-trip-wizard-next"><?php echo esc_html($translate('Save & continue')); ?></button></footer>
                    </div>
                </dialog>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'family-move'): ?>
            <div id="olama-family-move" class="olama-card olama-family-move" data-year-id="<?php echo intval($selected_year_id); ?>">
                <header class="olama-family-move-hero">
                    <div><span class="olama-section-kicker"><?php echo esc_html($translate('Transportation Operations')); ?></span><h2><?php echo esc_html($translate('Family Move')); ?></h2><p><?php echo esc_html($translate('Transfer one or more complete families between compatible trips.')); ?></p></div>
                    <span class="dashicons dashicons-randomize" aria-hidden="true"></span>
                </header>
                <div class="olama-family-move-filters">
                    <label><?php echo esc_html($translate('Academic Year')); ?><select id="family-move-year"><?php foreach ($years as $year): ?><option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id,$year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></label>
                    <label><?php echo esc_html($translate('Direction')); ?><select id="family-move-direction"><option value="morning"><?php echo esc_html($translate('Arrival / حضور')); ?></option><option value="afternoon"><?php echo esc_html($translate('Departure / عودة')); ?></option></select></label>
                    <button type="button" class="button" id="family-move-refresh"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php echo esc_html($translate('Refresh')); ?></button>
                </div>
                <div id="family-move-feedback" class="olama-operation-result" aria-live="polite"></div>
                <div class="olama-family-move-columns">
                    <section class="olama-family-move-pane" data-side="left"><div class="olama-family-move-pane-loading"><?php echo esc_html($translate('Loading trips…')); ?></div></section>
                    <section class="olama-family-move-pane" data-side="right"><div class="olama-family-move-pane-loading"><?php echo esc_html($translate('Loading trips…')); ?></div></section>
                </div>
                <div class="olama-family-move-controls" aria-live="polite">
                    <div id="family-move-preview" class="olama-family-move-preview"><p><?php echo esc_html($translate('Select families, then drag them to the other trip or use a move button.')); ?></p></div>
                    <label class="olama-family-move-reason"><?php echo esc_html($translate('Reason (optional)')); ?><input type="text" id="family-move-reason" maxlength="250" placeholder="<?php echo esc_attr($translate('Administrative adjustment')); ?>"></label>
                    <div class="olama-family-move-apply"><button type="button" class="button" id="family-move-cancel" disabled><?php echo esc_html($translate('Cancel')); ?></button><button type="button" class="button button-primary" id="family-move-apply" disabled><?php echo esc_html($translate('Apply family move')); ?></button></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'reports'): ?>
            <div id="olama-school-reports" class="olama-card">
                <div class="reports-block-grid" role="group" aria-label="<?php echo esc_attr($translate('Report type')); ?>"><button type="button" class="button reports-block is-active" data-report-type="school" aria-pressed="true"><strong><?php echo esc_html($translate('Transportation students')); ?></strong><span><?php echo esc_html($translate('Subscribed students and their direction-specific trip status.')); ?></span></button><button type="button" class="button reports-block" data-report-type="walking" aria-pressed="false"><strong><?php echo esc_html($translate('Walking students')); ?></strong><span><?php echo esc_html($translate('Academically registered students without an active transportation subscription.')); ?></span></button><button type="button" class="button reports-block" data-report-type="family" aria-pressed="false"><strong><?php echo esc_html($translate('Family search')); ?></strong><span><?php echo esc_html($translate('Search every registered or subscribed student and review both directions.')); ?></span></button><button type="button" class="button reports-block" data-report-type="unassigned" aria-pressed="false"><strong><?php echo esc_html($translate('Assignment gaps')); ?></strong><span><?php echo esc_html($translate('Subscribed students missing arrival, departure, or both assignments.')); ?></span></button></div>
                <div class="olama-area-filters"><label><?php echo esc_html($translate('Report type')); ?><select id="school-report-type"><option value="school"><?php echo esc_html($translate('Transportation students')); ?></option><option value="walking"><?php echo esc_html($translate('Walking students')); ?></option><option value="family"><?php echo esc_html($translate('Family search')); ?></option><option value="unassigned"><?php echo esc_html($translate('Assignment gaps')); ?></option></select></label></div>
                <section id="school-report-panel">
                <div class="olama-transportation-toolbar"><div><h2 id="school-report-heading"><?php echo esc_html($translate('Transportation subscription and assignment report')); ?></h2><p id="school-report-description" class="description"><?php echo esc_html($translate('Subscription comes from synchronized Core transportation records; assignments come from local trips.')); ?></p></div></div>
                <div class="olama-area-filters" id="school-report-filters">
                    <label><?php echo esc_html($translate('Academic Year')); ?><select id="school-report-year"><?php foreach ($years as $year): ?><option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id,$year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></label>
                    <label class="report-transport-only"><?php echo esc_html($translate('Assignment direction')); ?><select id="school-report-direction"><option value="all"><?php echo esc_html($translate('Arrival and departure')); ?></option><option value="morning"><?php echo esc_html($translate('Arrival / حضور')); ?></option><option value="afternoon"><?php echo esc_html($translate('Departure / عودة')); ?></option></select></label>
                    <label><?php echo esc_html($translate('Grade')); ?><select id="school-report-grade"><option value=""><?php echo esc_html($translate('All grades')); ?></option></select></label>
                    <label><?php echo esc_html($translate('Section')); ?><select id="school-report-section"><option value=""><?php echo esc_html($translate('All sections')); ?></option></select></label>
                    <label><?php echo esc_html($translate('Planning area')); ?><select id="school-report-area"><option value=""><?php echo esc_html($translate('All planning areas')); ?></option></select></label>
                    <label class="report-transport-only"><?php echo esc_html($translate('Trip')); ?><select id="school-report-trip"><option value=""><?php echo esc_html($translate('All trips')); ?></option></select></label>
                    <label class="report-transport-only"><?php echo esc_html($translate('Assignment status')); ?><select id="school-report-assignment"><option value="all"><?php echo esc_html($translate('All subscribed students')); ?></option><option value="fully_assigned"><?php echo esc_html($translate('Assigned in both directions')); ?></option><option value="partial"><?php echo esc_html($translate('Assigned in one direction only')); ?></option><option value="unassigned"><?php echo esc_html($translate('Not assigned')); ?></option><option value="any_missing"><?php echo esc_html($translate('Missing any direction')); ?></option></select></label>
                    <button type="button" class="button" id="school-report-print"><?php echo esc_html($translate('Print report')); ?></button>
                </div>
                <div id="school-report-feedback" class="olama-operation-result" aria-live="polite"></div><div id="school-report-results"></div>
                </section>
                <section id="family-report-panel" hidden><div id="family-report-controls"><label><?php echo esc_html($translate('Academic Year')); ?> <select id="family-report-year"><?php foreach ($years as $year): ?><option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id,$year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></label><label><?php echo esc_html($translate('Family ID, family name, student name, grade or section')); ?> <input type="search" id="family-report-search"></label><button type="button" class="button" id="family-report-search-button"><?php echo esc_html($translate('Search')); ?></button><button type="button" class="button" id="family-report-print"><?php echo esc_html($translate('Print report')); ?></button></div><div id="family-report-results"></div></section>
                <section id="unassigned-report-panel" hidden><div class="olama-area-filters"><label><?php echo esc_html($translate('Academic Year')); ?> <select id="unassigned-report-year"><?php foreach ($years as $year): ?><option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id,$year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></label><label><?php echo esc_html($translate('Gap scope')); ?> <select id="unassigned-report-scope"><option value="any_missing"><?php echo esc_html($translate('Missing arrival or departure')); ?></option><option value="morning"><?php echo esc_html($translate('Missing arrival')); ?></option><option value="afternoon"><?php echo esc_html($translate('Missing departure')); ?></option><option value="none"><?php echo esc_html($translate('No trip in either direction')); ?></option></select></label><button type="button" class="button" id="unassigned-report-print"><?php echo esc_html($translate('Print report')); ?></button></div><div id="unassigned-report-results"></div></section>
            </div>
        <?php endif; ?>

        <?php if (false && $active_tab === 'planning'): // Deprecated polygon planner retained only in source for compatibility. ?>
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

        <?php if ($active_tab === 'planning'): ?>
            <div id="olama-area-planner" class="olama-card" data-year-id="<?php echo intval($selected_year_id); ?>">
                <div class="olama-planner-header">
                    <div><h2><?php echo esc_html($translate('Area Coverage')); ?></h2><p class="description"><?php echo esc_html($translate('View family locations and their planning areas. Assign students and buses from the Trips tab.')); ?></p></div>
                    <div><button type="button" id="planner-refresh-map" class="button"><?php echo esc_html($translate('Refresh map')); ?></button></div>
                </div>
                <div id="planner-demand-status" class="olama-planner-message" aria-live="polite"></div>
                <div class="olama-planner-filters olama-area-filters">
                    <label><?php echo esc_html($translate('Academic Year')); ?><select id="planner-year"><?php foreach ($years as $year): ?><option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id, $year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></label>
                    <label><?php echo esc_html($translate('Direction')); ?><select id="planner-direction"><option value="morning"><?php echo esc_html($translate('Morning')); ?></option><option value="afternoon"><?php echo esc_html($translate('Afternoon')); ?></option></select></label>
                    <label><?php echo esc_html($translate('Planning Area')); ?><select id="planner-area"><option value=""><?php echo esc_html($translate('All areas')); ?></option></select></label>
                    <button type="button" id="planner-reset" class="button"><?php echo esc_html($translate('Reset filters')); ?></button>
                </div>
                <div class="olama-planner-metrics" aria-live="polite">
                    <div title="<?php echo esc_attr($translate('Families registered for transportation in the selected academic year.')); ?>"><strong id="metric-registered">0</strong><span><?php echo esc_html($translate('Registered families')); ?></span></div>
                    <div><strong id="metric-valid">0</strong><span><?php echo esc_html($translate('Valid family locations')); ?></span></div>
                    <div title="<?php echo esc_attr($translate('Registered families that do not yet have usable coordinates.')); ?>"><strong id="metric-missing-coordinates">0</strong><span><?php echo esc_html($translate('Missing coordinates')); ?></span></div>
                    <div><strong id="metric-area-assigned">0</strong><span><?php echo esc_html($translate('Families with areas')); ?></span></div>
                    <div><strong id="metric-area-missing">0</strong><span><?php echo esc_html($translate('Families without areas')); ?></span></div>
                </div>
                <div class="olama-area-planner-grid">
                    <section class="olama-area-map-section" aria-label="<?php echo esc_attr($translate('Family allocation map')); ?>">
                        <div id="olama-planning-map" role="application" aria-label="<?php echo esc_attr($translate('Family allocation map')); ?>"></div>
                        <div id="planner-area-legend" class="olama-area-color-legend" aria-live="polite"></div>
                    </section>
                    <div class="olama-area-results">
                        <div class="olama-coverage-table-wrap">
                            <table class="wp-list-table widefat olama-area-allocation-table olama-coverage-table">
                                <thead><tr>
                                    <th><span class="olama-coverage-heading"><?php echo esc_html($translate('Planning Area')); ?></span><span class="olama-coverage-total"><strong id="coverage-total-areas">0</strong> <?php echo esc_html($translate('areas')); ?></span></th>
                                    <th><span class="olama-coverage-heading"><?php echo esc_html($translate('Families')); ?></span><span class="olama-coverage-total"><strong id="coverage-total-families">0</strong> <?php echo esc_html($translate('families')); ?> · <strong id="coverage-total-students">0</strong> <?php echo esc_html($translate('students')); ?></span></th>
                                    <th><span class="olama-coverage-heading"><?php echo esc_html($translate('Transportation Students')); ?></span><span class="olama-coverage-total"><strong id="coverage-total-transportation">0</strong> <?php echo esc_html($translate('students')); ?></span></th>
                                    <th><span class="olama-coverage-heading"><?php echo esc_html($translate('Transport KG + G1')); ?></span><span class="olama-coverage-total"><strong id="coverage-total-kg-g1">0</strong> <?php echo esc_html($translate('students')); ?></span></th>
                                    <th><span class="olama-coverage-heading"><?php echo esc_html($translate('Walking Students')); ?></span><span class="olama-coverage-total"><strong id="coverage-total-walking">0</strong> <?php echo esc_html($translate('students')); ?></span></th>
                                    <th><span class="olama-coverage-heading"><?php echo esc_html($translate('Map Actions')); ?></span><span class="olama-coverage-total"><?php echo esc_html($translate('View')); ?></span></th>
                                </tr></thead>
                                <tbody id="area-mapping-body"><tr><td colspan="6"><?php echo esc_html($translate('Loading...')); ?></td></tr></tbody>
                            </table>
                        </div>
                        <table class="wp-list-table widefat striped olama-area-allocation-table" hidden><thead><tr>
                            <th><button type="button" class="olama-sort-link" data-sort="name"><?php echo esc_html($translate('Planning Area')); ?></button></th><th><button type="button" class="olama-sort-link" data-sort="families"><?php echo esc_html($translate('Families')); ?></button> / <button type="button" class="olama-sort-link" data-sort="students"><?php echo esc_html($translate('Students')); ?></button></th><th><?php echo esc_html($translate('Bus / Trip')); ?></th><th><?php echo esc_html($translate('Capacity')); ?></th><th><button type="button" class="olama-sort-link" data-sort="remaining"><?php echo esc_html($translate('Used / Remaining')); ?></button></th><th><button type="button" class="olama-sort-link" data-sort="utilization"><?php echo esc_html($translate('Utilization')); ?></button></th><th><button type="button" class="olama-sort-link" data-sort="status"><?php echo esc_html($translate('Status')); ?></button></th><th><?php echo esc_html($translate('Actions')); ?> <button type="button" class="olama-sort-link" data-sort="updated" aria-label="<?php echo esc_attr($translate('Sort by updated date')); ?>">↕</button></th>
                        </tr></thead><tbody id="planner-allocations-body"><tr><td colspan="8"><?php echo esc_html($translate('Loading...')); ?></td></tr></tbody></table>
                        <div id="planner-pagination" class="olama-table-pagination"></div>
                    </div>
                </div>
                <div id="area-family-detail" class="olama-family-detail" hidden><div class="olama-planner-header"><h3 id="area-family-title"></h3><button type="button" id="area-family-close" class="button"><?php echo esc_html($translate('Close')); ?></button></div><div class="olama-area-filters"><label><?php echo esc_html($translate('Search families')); ?><input type="search" id="area-family-search"></label><label><?php echo esc_html($translate('Location')); ?><select id="area-family-location"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="valid"><?php echo esc_html($translate('Valid')); ?></option><option value="missing"><?php echo esc_html($translate('Missing')); ?></option></select></label><label><?php echo esc_html($translate('Allocation')); ?><select id="area-family-allocation"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="assigned"><?php echo esc_html($translate('Assigned')); ?></option><option value="problem"><?php echo esc_html($translate('Problem')); ?></option></select></label><label><?php echo esc_html($translate('Move selected to')); ?><select id="area-family-move-area"></select></label><button type="button" id="area-family-bulk-move" class="button button-primary"><?php echo esc_html($translate('Move selected')); ?></button></div><div id="area-family-list"></div></div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'routes'): ?>
            <div class="olama-card olama-routes-page">
                <div class="olama-routes-hero"><div><span class="olama-section-kicker"><?php echo esc_html($translate('Transportation')); ?></span><h2><?php echo esc_html($translate('Route Versions')); ?></h2><p><?php echo esc_html($translate('Build, review and publish the stop sequence for each configured trip.')); ?></p></div><div class="olama-route-hero-icon">↗</div></div>
                <p class="description"><?php echo esc_html($translate('Select a configured trip, then create and optimize a version of its route. The trip remains the source of students and families.')); ?></p>
                <form id="route-form" class="olama-inline-form olama-route-form">
                    <input type="hidden" name="academic_year_id" value="<?php echo intval($selected_year_id); ?>" />
                    <select name="shared_trip_id" id="route-trip-select" required><option value=""><?php echo esc_html($translate('Select Trip')); ?></option><?php foreach ($route_trips as $trip): ?><option value="<?php echo intval($trip['id']); ?>" data-bus-id="<?php echo intval($trip['bus_id']); ?>" data-direction="<?php echo esc_attr($trip['direction']); ?>"><?php echo esc_html($trip['name'].' · '.($trip['direction']==='morning' ? 'Arrival' : 'Departure').' · '.($trip['bus_number'] ?: 'Bus unassigned')); ?></option><?php endforeach; ?></select>
                    <input type="hidden" name="bus_id" id="route-bus-id" /><input type="hidden" name="direction" id="route-direction" />
                    <span class="description olama-route-stop-source"><?php echo esc_html($translate('Family stops will be loaded automatically from the selected trip.')); ?></span>
                    <button class="button button-primary" type="submit"><?php echo esc_html($translate('Create Draft')); ?></button>
                </form>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th><?php echo esc_html($translate('Trip')); ?></th><th><?php echo esc_html($translate('Bus')); ?></th><th><?php echo esc_html($translate('Direction')); ?></th><th><?php echo esc_html($translate('Stops')); ?></th><th><?php echo esc_html($translate('Distance')); ?></th><th><?php echo esc_html($translate('Version')); ?></th><th><?php echo esc_html($translate('Status')); ?></th><th><?php echo esc_html($translate('Actions')); ?></th></tr></thead>
                    <tbody><?php foreach ($routes as $route): ?><tr><td><strong><?php echo esc_html($route['trip']['name'] ?? $route['name']); ?></strong></td><td><span class="olama-route-bus-pill">Bus <?php echo intval($route['bus_id']); ?></span></td><td><span class="olama-route-direction <?php echo esc_attr($route['direction']); ?>"><?php echo esc_html($route['direction']==='morning' ? 'Arrival' : 'Departure'); ?></span></td><td><?php echo intval($route['stop_count'] ?? 0); ?></td><td><?php echo $route['total_distance_m'] ? esc_html(round(((int)$route['total_distance_m']) / 1000, 1).' km') : '—'; ?></td><td>v<?php echo intval($route['version_number']); ?></td><td><span class="olama-route-status <?php echo esc_attr($route['status']); ?>"><?php echo esc_html(ucfirst($route['status'])); ?></span></td><td class="olama-route-actions"><button type="button" class="button olama-route-icon-button olama-open-route" title="<?php echo esc_attr($translate('Open')); ?>" aria-label="<?php echo esc_attr($translate('Open')); ?>" data-id="<?php echo intval($route['id']); ?>"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button><button type="button" class="button olama-route-icon-button olama-copy-route-data" title="<?php echo esc_attr($translate('Copy optimization data')); ?>" aria-label="<?php echo esc_attr($translate('Copy optimization data')); ?>" data-id="<?php echo intval($route['id']); ?>"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span></button><?php if ($route['status'] === 'draft'): ?><button type="button" class="button olama-route-icon-button olama-optimize-route" title="<?php echo esc_attr($translate('Optimize')); ?>" aria-label="<?php echo esc_attr($translate('Optimize')); ?>" data-id="<?php echo intval($route['id']); ?>"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span></button><button type="button" class="button button-primary olama-route-icon-button olama-publish-route" title="<?php echo esc_attr($translate('Publish')); ?>" aria-label="<?php echo esc_attr($translate('Publish')); ?>" data-id="<?php echo intval($route['id']); ?>"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></button><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$routes): ?><tr><td colspan="8"><?php echo esc_html($translate('No route versions.')); ?></td></tr><?php endif; ?></tbody>
                </table>
            </div>
            <section id="olama-route-editor" class="olama-route-editor" hidden aria-live="polite"><div class="olama-route-editor-header"><div><span class="olama-section-kicker"><?php echo esc_html($translate('Route workspace')); ?></span><h2 id="olama-route-editor-title"></h2><p><?php echo esc_html($translate('Drag stops to adjust the order, review the map, then save or optimize the route.')); ?></p></div><button type="button" class="button" id="olama-close-route-editor"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php echo esc_html($translate('Back to routes')); ?></button></div><div id="olama-route-editor-status" class="olama-operation-result"></div><div id="olama-route-skipped-list" class="olama-route-skipped-list"></div><div class="olama-route-editor-grid"><div class="olama-route-stop-panel"><div class="olama-route-panel-heading"><strong><?php echo esc_html($translate('Stops')); ?></strong><span><?php echo esc_html($translate('Drag to reorder')); ?></span></div><ol id="olama-route-stop-list"></ol></div><div class="olama-route-map-panel"><div id="olama-route-map"></div></div></div><div class="olama-route-editor-actions"><button type="button" class="button" id="olama-rebuild-route"><?php echo esc_html($translate('Rebuild from Trip')); ?></button><button type="button" class="button" id="olama-copy-route-data"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><?php echo esc_html($translate('Copy optimization data')); ?></button><button type="button" class="button button-primary" id="olama-save-route-order"><?php echo esc_html($translate('Save Stop Order')); ?></button></div></section>
        <?php endif; ?>

        <?php if ($active_tab === 'import'): ?>
            <div id="olama-family-locations-app" class="olama-card" data-year-id="<?php echo intval($selected_year_id); ?>">
                <div class="olama-transportation-toolbar olama-family-location-header">
                    <div><h2><?php echo esc_html($translate('Family Transportation Locations')); ?></h2><p><?php echo esc_html($translate('Review contact details, match Oracle and Planning Areas, and approve GPS locations in one workspace.')); ?></p></div>
                    <label for="family-locations-year"><?php echo esc_html($translate('Academic Year')); ?><select id="family-locations-year"><?php foreach ($years as $year): ?><option value="<?php echo intval($year->id); ?>" <?php selected($selected_year_id, $year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></label>
                </div>
                <div id="family-location-feedback" class="olama-operation-result" aria-live="polite"></div>
                <div class="olama-location-summary" aria-live="polite">
                    <?php foreach (array('registered'=>'Registered transportation families','valid'=>'Families with valid coordinates','missing'=>'Families missing coordinates','with-area'=>'Families with Planning Areas','without-area'=>'Families without Planning Areas') as $metric_id=>$metric_label): ?>
                        <div title="<?php echo esc_attr($translate($metric_label)); ?>"><strong id="family-metric-<?php echo esc_attr($metric_id); ?>">0</strong><span><?php echo esc_html($translate($metric_label)); ?></span></div>
                    <?php endforeach; ?>
                </div>
                <div class="olama-family-location-tools" role="search">
                    <label for="family-location-search"><?php echo esc_html($translate('Search')); ?><input type="search" id="family-location-search" placeholder="<?php echo esc_attr($translate('Family name or Oracle family ID')); ?>" /></label>
                    <label for="family-location-oracle-area-filter"><?php echo esc_html($translate('Oracle Area')); ?><select id="family-location-oracle-area-filter"><option value="all"><?php echo esc_html($translate('All')); ?></option></select></label>
                    <label for="family-location-area-filter"><?php echo esc_html($translate('Planning Area')); ?><select id="family-location-area-filter"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="unassigned"><?php echo esc_html($translate('Unassigned')); ?></option><?php foreach ($areas as $area): if (($area['status'] ?? '') !== 'active') continue; ?><option value="<?php echo intval($area['id']); ?>"><?php echo esc_html($area['name']); ?></option><?php endforeach; ?></select></label>
                    <label for="family-location-transport-filter"><?php echo esc_html($translate('Transportation Subscription')); ?><select id="family-location-transport-filter"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="subscribed">مشترك بالمواصلات</option><option value="not_subscribed">غير مشترك بالمواصلات</option></select></label>
                    <label for="family-location-status-filter"><?php echo esc_html($translate('Location Status')); ?><select id="family-location-status-filter"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="missing_location"><?php echo esc_html($translate('Missing Location')); ?></option><option value="needs_review"><?php echo esc_html($translate('Needs review')); ?></option><option value="approved"><?php echo esc_html($translate('Approved')); ?></option><option value="invalid_location"><?php echo esc_html($translate('Invalid Location')); ?></option></select></label>
                    <label for="family-location-morning-filter"><?php echo esc_html($translate('Morning Status')); ?><select id="family-location-morning-filter"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="assigned"><?php echo esc_html($translate('Assigned')); ?></option><option value="missing_area"><?php echo esc_html($translate('Missing Area')); ?></option><option value="area_not_allocated"><?php echo esc_html($translate('Area Not Allocated')); ?></option><option value="capacity_problem"><?php echo esc_html($translate('Capacity Problem')); ?></option></select></label>
                    <label for="family-location-afternoon-filter"><?php echo esc_html($translate('Afternoon Status')); ?><select id="family-location-afternoon-filter"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="assigned"><?php echo esc_html($translate('Assigned')); ?></option><option value="missing_area"><?php echo esc_html($translate('Missing Area')); ?></option><option value="area_not_allocated"><?php echo esc_html($translate('Area Not Allocated')); ?></option><option value="capacity_problem"><?php echo esc_html($translate('Capacity Problem')); ?></option></select></label>
                    <label for="family-location-missing-filter"><?php echo esc_html($translate('Missing Locations')); ?><select id="family-location-missing-filter"><option value="all"><?php echo esc_html($translate('All locations')); ?></option><option value="missing_all"><?php echo esc_html($translate('All missing locations')); ?></option><option value="missing_subscribed"><?php echo esc_html($translate('Missing locations — subscribed to transportation')); ?></option><option value="missing_not_subscribed"><?php echo esc_html($translate('Missing locations — not subscribed to transportation')); ?></option></select></label>
                    <button type="button" id="family-location-reset" class="button"><?php echo esc_html($translate('Reset Filters')); ?></button>
                </div>
                <div class="olama-bulk-area-bar">
                    <strong id="family-filter-totals" class="olama-filter-totals" aria-live="polite">0 <?php echo esc_html($translate('families')); ?> · 0 <?php echo esc_html($translate('students')); ?></strong>
                    <button type="button" id="family-location-export" class="button"><?php echo esc_html($translate('Export CSV')); ?></button>
                    <strong id="family-selected-count">0 <?php echo esc_html($translate('selected')); ?></strong>
                    <label for="family-location-bulk-area"><?php echo esc_html($translate('Bulk Planning Area')); ?><select id="family-location-bulk-area"><option value=""><?php echo esc_html($translate('Clear area assignment')); ?></option><?php foreach ($areas as $area): if (($area['status'] ?? '') !== 'active') continue; ?><option value="<?php echo intval($area['id']); ?>"><?php echo esc_html($area['name']); ?></option><?php endforeach; ?></select></label>
                    <button type="button" id="family-location-bulk-save" class="button button-primary"><?php echo esc_html($translate('Apply to selected')); ?></button>
                    <span class="olama-override-legend"><span aria-hidden="true"></span><?php echo esc_html($translate('Red rows have a Planning Area different from their Oracle Area.')); ?></span>
                </div>
                <div class="olama-family-location-table-wrap">
                    <table class="wp-list-table widefat striped olama-family-location-table">
                        <thead><tr><th class="check-column"><input type="checkbox" id="family-location-select-all" aria-label="<?php echo esc_attr($translate('Select all visible families')); ?>" /></th><th><?php echo esc_html($translate('Family & Contacts')); ?></th><th><?php echo esc_html($translate('Students & Transportation')); ?></th><th><?php echo esc_html($translate('Areas & Address')); ?></th><th><?php echo esc_html($translate('Location Status')); ?></th><th><?php echo esc_html($translate('Effective Allocation')); ?></th><th><?php echo esc_html($translate('Actions')); ?></th></tr></thead>
                        <tbody id="family-locations-body"><tr><td colspan="7"><?php echo esc_html($translate('Loading family transportation records…')); ?></td></tr></tbody>
                    </table>
                </div>
                <div class="olama-pagination"><span id="family-results-count"></span><label for="family-page-size"><?php echo esc_html($translate('Rows')); ?><select id="family-page-size"><option>20</option><option>50</option><option>100</option></select></label><button type="button" id="family-page-prev" class="button"><?php echo esc_html($translate('Previous')); ?></button><span id="family-page-label"></span><button type="button" id="family-page-next" class="button"><?php echo esc_html($translate('Next')); ?></button></div>
                <dialog id="family-location-dialog" class="olama-family-dialog" aria-labelledby="family-location-dialog-title"><form method="dialog"><button class="olama-dialog-close" aria-label="<?php echo esc_attr($translate('Close')); ?>">×</button></form><h2 id="family-location-dialog-title"></h2><div id="family-location-dialog-content"></div><div id="family-location-dialog-feedback" aria-live="polite"></div></dialog>
            </div>
        <?php endif; ?>

        <?php if (false && $active_tab === 'import'): // Superseded by the compact AJAX workspace above. ?>
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
                    <label><?php echo esc_html($translate('Planning Area')); ?><select id="family-location-area-filter"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="unassigned"><?php echo esc_html($translate('Unassigned')); ?></option><?php foreach ($areas as $area): if (($area['status'] ?? '') !== 'active') continue; ?><option value="<?php echo intval($area['id']); ?>"><?php echo esc_html($area['name']); ?></option><?php endforeach; ?></select></label>
                    <label><?php echo esc_html($translate('Location Status')); ?><select id="family-location-status-filter"><option value="all"><?php echo esc_html($translate('All')); ?></option><option value="missing"><?php echo esc_html($translate('Missing')); ?></option><option value="needs_review"><?php echo esc_html($translate('Needs review')); ?></option><option value="approved"><?php echo esc_html($translate('Approved')); ?></option><option value="rejected"><?php echo esc_html($translate('Rejected')); ?></option></select></label>
                    <label><?php echo esc_html($translate('Bulk Assign Planning Area')); ?><select id="family-location-bulk-area"><option value=""><?php echo esc_html($translate('Clear area assignment')); ?></option><?php foreach ($areas as $area): if (($area['status'] ?? '') !== 'active') continue; ?><option value="<?php echo intval($area['id']); ?>"><?php echo esc_html($area['name']); ?></option><?php endforeach; ?></select></label>
                    <button type="button" id="family-location-bulk-save" class="button"><?php echo esc_html($translate('Apply to selected')); ?></button>
                </div>

                <div class="olama-family-location-table-wrap">
                    <table class="wp-list-table widefat fixed striped olama-family-location-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="family-location-select-all" aria-label="<?php echo esc_attr($translate('Select all')); ?>" /></th>
                                <th><?php echo esc_html($translate('Family')); ?></th>
                                <th><?php echo esc_html($translate('Students')); ?></th>
                                <th><?php echo esc_html($translate('Oracle Area')); ?></th>
                                <th><?php echo esc_html($translate('Planning Area')); ?></th>
                                <th><?php echo esc_html($translate('Effective Morning Assignment')); ?></th>
                                <th><?php echo esc_html($translate('Effective Afternoon Assignment')); ?></th>
                                <th><?php echo esc_html($translate('WhatsApp Location')); ?></th>
                                <th><?php echo esc_html($translate('Location Status')); ?></th>
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
                            <tr data-family-location-row data-has-location="<?php echo $has_location ? '1' : '0'; ?>" data-area-id="<?php echo intval($family['major_area_id']); ?>" data-location-status="<?php echo esc_attr($family['verification_status'] ?: 'missing'); ?>">
                                <td><input type="checkbox" class="family-location-select" value="<?php echo intval($family['family_stop_id']); ?>" <?php disabled(!$family['family_stop_id']); ?> /></td>
                                <td><strong><?php echo esc_html($family['family_name']); ?></strong><small>#<?php echo esc_html($family['oracle_family_id']); ?></small></td>
                                <td><?php echo intval($family['registered_students']); ?></td>
                                <td><?php echo $family['trans_region_name'] ? esc_html($family['trans_region_name']) : '-'; ?><small><?php echo esc_html($translate('Source: Olama Core')); ?></small></td>
                                <td><select class="family-planning-area" <?php disabled(!$family['family_stop_id']); ?>><option value=""><?php echo esc_html($translate('Unassigned')); ?></option><?php foreach ($areas as $area): if (($area['status'] ?? '') !== 'active') continue; ?><option value="<?php echo intval($area['id']); ?>" <?php selected($family['major_area_id'], $area['id']); ?>><?php echo esc_html($area['name']); ?></option><?php endforeach; ?></select><small><?php echo esc_html($translate('Local transportation classification')); ?></small></td>
                                <?php foreach (array('morning', 'afternoon') as $effective_direction): $effective = $family['effective_' . $effective_direction]; ?>
                                    <td><?php echo $effective && $effective['bus_id'] ? esc_html($effective['bus_number'] . ' / ' . $translate('Trip') . ' ' . intval($effective['trip_number'])) : esc_html($translate($effective ? ucwords(str_replace('_', ' ', $effective['assignment_status'])) : 'Not available')); ?></td>
                                <?php endforeach; ?>
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
                                    <button type="button" class="button olama-save-family-area" data-stop-id="<?php echo intval($family['family_stop_id']); ?>" <?php disabled(!$family['family_stop_id']); ?>><?php echo esc_html($translate('Save Area')); ?></button>
                                    <button type="button" class="button-link-delete olama-clear-family-area" data-stop-id="<?php echo intval($family['family_stop_id']); ?>" <?php disabled(!$family['family_stop_id']); ?>><?php echo esc_html($translate('Clear Area')); ?></button>
                                    <a class="button olama-view-family-location <?php echo $has_location ? '' : 'is-hidden'; ?>" href="<?php echo $has_location ? esc_url($map_url) : '#'; ?>" target="_blank" rel="noopener"><?php echo esc_html($translate('Map')); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$registered_families): ?>
                            <tr><td colspan="10"><?php echo esc_html($translate('No registered families were found in Olama Core for the selected academic year.')); ?></td></tr>
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

        <?php if ($active_tab === 'companions'): ?>
            <div id="olama-companion-locations-app" class="olama-card">
                <div class="olama-family-location-header"><h2><?php echo esc_html($translate('Companion Locations')); ?> <span class="description">(مرافقة الجولة)</span></h2><p><?php echo esc_html($translate('Assign a meeting location to each companion and review the trips attached to that employee.')); ?></p></div>
                <div class="olama-family-location-table-wrap"><table class="wp-list-table widefat striped olama-companion-location-table"><thead><tr><th><?php echo esc_html($translate('Companion')); ?></th><th><?php echo esc_html($translate('Location')); ?></th><th><?php echo esc_html($translate('Attached Trips')); ?></th><th><?php echo esc_html($translate('Actions')); ?></th></tr></thead><tbody>
                <?php foreach (($companion_locations['items'] ?? array()) as $companion): ?><tr data-user-id="<?php echo intval($companion['user_id']); ?>"><td><strong><?php echo esc_html($companion['name']); ?></strong><small><?php echo esc_html($companion['email']); ?></small></td><td><input class="widefat companion-location-input" value="<?php echo esc_attr($companion['latitude'] !== null ? $companion['latitude'].', '.$companion['longitude'] : ''); ?>" placeholder="31.9539, 35.9106" inputmode="decimal" /></td><td><?php if (!empty($companion['trips'])): ?><ul class="companion-trip-list"><?php foreach ($companion['trips'] as $trip): ?><li><strong><?php echo esc_html($trip['name']); ?></strong><small><?php echo esc_html(($trip['direction']==='morning' ? $translate('Arrival') : $translate('Departure')).' · '.$trip['status']); ?></small></li><?php endforeach; ?></ul><?php else: ?><span class="description"><?php echo esc_html($translate('No attached trips.')); ?></span><?php endif; ?></td><td><button type="button" class="button button-primary companion-save-location"><?php echo esc_html($translate('Save Location')); ?></button><small class="companion-location-result" aria-live="polite"></small></td></tr><?php endforeach; ?>
                <?php if (empty($companion_locations['items'])): ?><tr><td colspan="4"><?php echo esc_html($translate('No eligible companion employees were found.')); ?></td></tr><?php endif; ?></tbody></table></div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'dual'): ?>
            <div id="olama-dual-locations-app" class="olama-card">
                <div class="olama-family-location-header"><h2><?php echo esc_html($translate('Dual Locations')); ?></h2><p><?php echo esc_html($translate('Convert families with different arrival and departure points, then explicitly place them on existing draft trips.')); ?></p></div>
                <div class="olama-dual-convert-bar"><label><?php echo esc_html($translate('Family number')); ?><input type="text" id="dual-convert-family" inputmode="numeric" autocomplete="off" placeholder="<?php echo esc_attr($translate('Enter Oracle family number')); ?>" /></label><button type="button" class="button button-primary" id="dual-open-convert"><?php echo esc_html($translate('Configure two locations')); ?></button><span id="dual-lookup-feedback" class="dual-result" aria-live="polite"></span></div>
                <div class="olama-location-summary"><div><strong><?php echo intval($dual_locations['metrics']['families'] ?? 0); ?></strong><span><?php echo esc_html($translate('Dual-location families')); ?></span></div><div><strong><?php echo intval(count(array_filter($dual_locations['items'], function($f){return !empty($f['dual_assignments']['morning']);}))); ?></strong><span><?php echo esc_html($translate('Arrival assigned')); ?></span></div><div><strong><?php echo intval(count(array_filter($dual_locations['items'], function($f){return !empty($f['dual_assignments']['afternoon']);}))); ?></strong><span><?php echo esc_html($translate('Departure assigned')); ?></span></div></div>
                <div class="olama-family-location-table-wrap"><table class="wp-list-table widefat striped olama-dual-location-table"><thead><tr><th><?php echo esc_html($translate('Family')); ?></th><th><?php echo esc_html($translate('Arrival location / area')); ?></th><th><?php echo esc_html($translate('Departure location / area')); ?></th><th><?php echo esc_html($translate('Trip assignments')); ?></th><th><?php echo esc_html($translate('Actions')); ?></th></tr></thead><tbody>
                <?php foreach (($dual_locations['items'] ?? array()) as $family): ?>
                    <tr data-family-uid="<?php echo esc_attr($family['family_uid']); ?>" data-arrival="<?php echo esc_attr(($family['arrival_latitude'] ?? '').', '.($family['arrival_longitude'] ?? '')); ?>" data-departure="<?php echo esc_attr(($family['departure_latitude'] ?? '').', '.($family['departure_longitude'] ?? '')); ?>"><td><strong><?php echo esc_html($family['family_name']); ?></strong><small>#<?php echo esc_html($family['oracle_family_id']); ?></small><small><?php echo intval($family['registered_students']).' '.esc_html($translate('students')); ?></small></td>
                        <td><span><?php echo esc_html(($family['arrival_latitude'] ?? '—').', '.($family['arrival_longitude'] ?? '—')); ?></span><select class="dual-area" data-direction="morning"><option value=""><?php echo esc_html($translate('Select planning area')); ?></option><?php foreach ($areas as $area): ?><option value="<?php echo intval($area['id']); ?>" <?php selected($family['arrival_major_area_id'] ?? 0,$area['id']); ?>><?php echo esc_html($area['name']); ?></option><?php endforeach; ?></select></td>
                        <td><span><?php echo esc_html(($family['departure_latitude'] ?? '—').', '.($family['departure_longitude'] ?? '—')); ?></span><select class="dual-area" data-direction="afternoon"><option value=""><?php echo esc_html($translate('Select planning area')); ?></option><?php foreach ($areas as $area): ?><option value="<?php echo intval($area['id']); ?>" <?php selected($family['departure_major_area_id'] ?? 0,$area['id']); ?>><?php echo esc_html($area['name']); ?></option><?php endforeach; ?></select></td>
                        <td><div class="dual-trip-assignment"><label><?php echo esc_html($translate('Arrival / Morning')); ?><select class="dual-trip" data-direction="morning"><option value=""><?php echo esc_html($translate('Not assigned')); ?></option><?php foreach (($dual_locations['trips'] ?? array()) as $trip): if (($trip['direction'] ?? '') !== 'morning') continue; ?><option value="<?php echo intval($trip['id']); ?>" <?php selected($family['dual_assignments']['morning']['id'] ?? 0,$trip['id']); ?>><?php echo esc_html($trip['name'].' · '.($trip['status'] ?? '')); ?></option><?php endforeach; ?></select></label><label><?php echo esc_html($translate('Departure / Afternoon')); ?><select class="dual-trip" data-direction="afternoon"><option value=""><?php echo esc_html($translate('Not assigned')); ?></option><?php foreach (($dual_locations['trips'] ?? array()) as $trip): if (($trip['direction'] ?? '') !== 'afternoon') continue; ?><option value="<?php echo intval($trip['id']); ?>" <?php selected($family['dual_assignments']['afternoon']['id'] ?? 0,$trip['id']); ?>><?php echo esc_html($trip['name'].' · '.($trip['status'] ?? '')); ?></option><?php endforeach; ?></select></label></div></td>
                        <td><button type="button" class="button button-primary dual-save-family"><?php echo esc_html($translate('Save assignments')); ?></button><small class="dual-result" aria-live="polite"></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($dual_locations['items'])): ?><tr><td colspan="5"><?php echo esc_html($translate('No dual-location families yet. Convert a family from the Family Locations tab first.')); ?></td></tr><?php endif; ?>
                </tbody></table></div>
                <dialog id="dual-convert-dialog" class="olama-family-dialog"><form method="dialog"><button class="olama-dialog-close" aria-label="<?php echo esc_attr($translate('Close')); ?>">×</button></form><h2><?php echo esc_html($translate('Configure two locations')); ?></h2><div class="olama-family-dialog-content"><p><?php echo esc_html($translate('Enter both locations and select their planning areas.')); ?></p><label><?php echo esc_html($translate('Arrival location')); ?><input id="dual-arrival-location" class="widefat" placeholder="31.9539, 35.9106"></label><label><?php echo esc_html($translate('Arrival planning area')); ?><select id="dual-arrival-area" class="widefat"><option value=""><?php echo esc_html($translate('Select planning area')); ?></option><?php foreach ($areas as $area): ?><option value="<?php echo intval($area['id']); ?>"><?php echo esc_html($area['name']); ?></option><?php endforeach; ?></select></label><label><?php echo esc_html($translate('Departure location')); ?><input id="dual-departure-location" class="widefat" placeholder="31.9539, 35.9106"></label><label><?php echo esc_html($translate('Departure planning area')); ?><select id="dual-departure-area" class="widefat"><option value=""><?php echo esc_html($translate('Select planning area')); ?></option><?php foreach ($areas as $area): ?><option value="<?php echo intval($area['id']); ?>"><?php echo esc_html($area['name']); ?></option><?php endforeach; ?></select></label><div class="olama-family-dialog-footer"><button type="button" class="button button-primary" id="dual-save-convert"><?php echo esc_html($translate('Save two locations')); ?></button></div><div id="dual-convert-feedback" aria-live="polite"></div></div></dialog>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'settings'): ?>
            <div class="olama-card">
                <h2><?php echo esc_html($translate('Transportation Settings')); ?></h2>
                <form id="transport-settings-form" class="olama-settings-form">
                    <h3><?php echo esc_html($translate('Language')); ?></h3>
                    <label><?php echo esc_html($translate('System language')); ?>
                        <select name="language" id="transportation-language">
                            <option value="en" <?php selected($settings['language'] ?? 'en', 'en'); ?>><?php echo esc_html($translate('English')); ?></option>
                            <option value="ar" <?php selected($settings['language'] ?? 'en', 'ar'); ?>><?php echo esc_html($translate('Arabic')); ?></option>
                        </select>
                    </label>
                    <p class="description"><?php echo esc_html($translate('Choose the language used throughout the Transportation module.')); ?></p>
                    <h3><?php echo esc_html($translate('Olama Core Master Data')); ?></h3>
                    <p><?php echo esc_html($translate('Oracle credentials and synchronization are managed centrally by Olama Oracle Sync. Transportation never connects to Oracle directly.')); ?></p>
                    <button type="button" id="refresh-core-buses" class="button"><?php echo esc_html($translate('Sync Buses from Oracle')); ?></button>
                    <h3><?php echo esc_html($translate('Route Optimizer')); ?></h3>
                    <label><?php echo esc_html($translate('Provider')); ?><select name="optimizer_provider" id="optimizer-provider"><option value="manual" <?php selected($settings['optimizer_provider'] ?? 'manual', 'manual'); ?>><?php echo esc_html($translate('Manual only')); ?></option><option value="ors" <?php selected($settings['optimizer_provider'] ?? '', 'ors'); ?>>OpenRouteService</option><option value="google" <?php selected($settings['optimizer_provider'] ?? '', 'google'); ?>>Google</option><option value="webhook" <?php selected($settings['optimizer_provider'] ?? '', 'webhook'); ?>><?php echo esc_html($translate('External Webhook')); ?></option></select></label>
                    <div data-optimizer-panel="ors"><h4>OpenRouteService</h4><label><?php echo esc_html($translate('API Key')); ?><input type="password" name="ors_api_key" value="" autocomplete="new-password" placeholder="<?php echo !empty($settings['ors_api_key']) || (defined('OLAMA_TRANSPORT_ORS_API_KEY') && OLAMA_TRANSPORT_ORS_API_KEY) ? esc_attr__('Configured', 'olama-transportation') : ''; ?>" /></label><label><?php echo esc_html($translate('Driving Profile')); ?><select name="ors_profile"><option value="driving-car" <?php selected($settings['ors_profile'] ?? 'driving-car', 'driving-car'); ?>><?php echo esc_html($translate('Driving Car')); ?></option><option value="driving-hgv" <?php selected($settings['ors_profile'] ?? '', 'driving-hgv'); ?>><?php echo esc_html($translate('Driving HGV')); ?></option><option value="cycling-regular" <?php selected($settings['ors_profile'] ?? '', 'cycling-regular'); ?>><?php echo esc_html($translate('Cycling')); ?></option><option value="foot-walking" <?php selected($settings['ors_profile'] ?? '', 'foot-walking'); ?>><?php echo esc_html($translate('Walking')); ?></option></select></label><p class="description"><?php echo esc_html($translate('API Configuration Status')); ?>: <?php echo (!empty($settings['ors_api_key']) || (defined('OLAMA_TRANSPORT_ORS_API_KEY') && OLAMA_TRANSPORT_ORS_API_KEY)) ? esc_html($translate('Configured')) : esc_html($translate('Missing')); ?></p><h4><?php echo esc_html($translate('Academy / Depot')); ?></h4><label><?php echo esc_html($translate('Latitude')); ?><input type="number" step="any" name="school_location[latitude]" value="<?php echo esc_attr($settings['school_location']['latitude'] ?? ''); ?>" /></label><label><?php echo esc_html($translate('Longitude')); ?><input type="number" step="any" name="school_location[longitude]" value="<?php echo esc_attr($settings['school_location']['longitude'] ?? ''); ?>" /></label><button type="button" class="button" id="test-ors-configuration"><?php echo esc_html($translate('Test ORS Configuration')); ?></button></div>
                    <div data-optimizer-panel="google"><label><?php echo esc_html($translate('Google Project ID')); ?><input name="google_project_id" value="<?php echo esc_attr($settings['google_project_id'] ?? ''); ?>" /></label></div>
                    <div data-optimizer-panel="webhook"><label><?php echo esc_html($translate('External Webhook URL')); ?><input type="url" name="optimizer_webhook_url" value="<?php echo esc_attr($settings['optimizer_webhook_url'] ?? ''); ?>" /></label><label><?php echo esc_html($translate('Webhook Secret')); ?><input type="password" name="optimizer_webhook_secret" value="" autocomplete="new-password" /></label></div>
                    <h3>Traccar (<?php echo esc_html($translate('Future Optional Service')); ?>)</h3>
                    <label><input type="checkbox" name="traccar_enabled" value="1" <?php checked(!empty($settings['traccar_enabled'])); ?> /> <?php echo esc_html($translate('Enable Traccar integration')); ?></label>
                    <label><?php echo esc_html($translate('Traccar URL')); ?><input type="url" name="traccar_url" value="<?php echo esc_attr($settings['traccar_url'] ?? ''); ?>" /></label>
                    <p><button class="button button-primary" type="submit"><?php echo esc_html($translate('Save Settings')); ?></button></p>
                </form>
                <div id="settings-result" class="olama-operation-result" aria-live="polite"></div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'buses'): ?>
            <div class="olama-card">
                <div class="olama-transportation-toolbar">
                    <h2><?php echo esc_html($translate('Bus Management')); ?></h2>
                    <button type="button" id="refresh-core-buses" class="button button-primary"><?php echo esc_html($translate('Sync Buses from Oracle')); ?></button>
                </div>
                <div id="bus-refresh-result" class="olama-operation-result" aria-live="polite"></div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html($translate('School Bus Code')); ?></th>
                            <th><?php echo esc_html($translate('Description')); ?></th>
                            <th><?php echo esc_html($translate('Bus Number')); ?></th>
                            <th><?php echo esc_html($translate('Driver License Number')); ?></th>
                            <th><?php echo esc_html($translate('Passenger Capacity')); ?></th>
                            <th><?php echo esc_html($translate('Morning Trips')); ?></th>
                            <th><?php echo esc_html($translate('Afternoon Trips')); ?></th>
                            <th><?php echo esc_html($translate('Driver')); ?></th>
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
                                    <td><?php echo $bus->description ? esc_html($bus->description) : '-'; ?></td>
                                    <td><?php echo $bus->government_number ? esc_html($bus->government_number) : '-'; ?></td>
                                    <td><?php echo $bus->driver_license_number ? esc_html($bus->driver_license_number) : '-'; ?></td>
                                    <td><?php echo intval($bus->passenger_capacity); ?><?php if (intval($bus->passenger_capacity) === 0 && intval($bus->planning_capacity) > 0): ?><br><small><?php echo esc_html($translate('Planning override')); ?>: <?php echo intval($bus->planning_capacity); ?></small><?php endif; ?></td>
                                    <td><?php echo intval($bus->morning_trip_count); ?></td>
                                    <td><?php echo intval($bus->afternoon_trip_count); ?></td>
                                    <td>
                                        <?php
                                        if ($bus->driver_name) {
                                            echo esc_html($bus->driver_name);
                                        } elseif ($bus->driver_employee_id) {
                                            echo esc_html($translate('Oracle Employee ID') . ': ' . $bus->driver_employee_id);
                                        } else {
                                            echo '-';
                                        }
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
                                <p><label><?php echo esc_html($translate('Arrival Trips / حضور')); ?></label><input type="number" name="morning_trip_count" id="bus-morning-trip-count" required class="widefat" min="1" max="10" value="3" /></p>
                                <p><label><?php echo esc_html($translate('Departure Trips / عودة')); ?></label><input type="number" name="afternoon_trip_count" id="bus-afternoon-trip-count" required class="widefat" min="1" max="10" value="3" /></p>
                                <p id="bus-core-capacity-warning" class="description" style="display:none"><?php echo esc_html($translate('Core capacity is missing. The positive value entered here is a local planning override.')); ?></p>
                                <p><label><?php echo esc_html($translate('License Expiry')); ?></label><input type="text" id="bus-license-expiry" readonly class="widefat" /></p>
                                <p><label><?php echo esc_html($translate('Driver')); ?></label><select name="driver_user_id" id="bus-driver-id" class="widefat"><option value=""><?php echo esc_html($translate('Select Driver')); ?></option><?php foreach ($drivers as $driver): ?><option value="<?php echo intval($driver->ID); ?>"><?php echo esc_html($driver->display_name); ?></option><?php endforeach; ?></select></p>
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

    </div>
</div>
