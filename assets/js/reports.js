(function () {
    'use strict';
    var state = { data: { filters: [], areas: [], trips: [], rows: [] } };
    var $ = function (selector) { return document.querySelector(selector); };
    var esc = function (value) { var node = document.createElement('div'); node.textContent = value == null ? '' : value; return node.innerHTML; };
    function mapQr(url) {
        if (!url || typeof qrcode !== 'function') return '';
        var code = qrcode(0, 'M');
        code.addData(String(url)); code.make();
        return '<span class="school-report-map-qr">' + code.createImgTag(3, 2) + '<small>Scan map</small></span>';
    }
    function familyTripCell(run) {
        if (!run || (!run.trip_name && !run.bus_number)) return '<span class="family-no-trip">—</span>';
        return '<div><strong>' + esc(run.trip_name || '—') + '</strong><small>' + esc([run.driver_name, run.bus_number ? 'Bus ' + run.bus_number : ''].filter(Boolean).join(' · ') || '—') + '</small></div>';
    }
    function transportLabel(status) { return status === 'with' ? 'نقل' : status === 'without' ? 'مشي' : 'متعدد'; }
    function reportValue(rows, key, fallback) {
        var values = rows.map(function (row) { return row[key] || ''; }).filter(Boolean).filter(function (value, index, list) { return list.indexOf(value) === index; });
        return values.length === 1 ? values[0] : (values.length > 1 ? 'Multiple' : fallback);
    }
    function reportMeta(rows) {
        return '<div class="school-report-meta"><span><b>Planning area:</b> ' + esc(reportValue(rows, 'planning_area', '—')) + '</span><span><b>Trip:</b> ' + esc(reportValue(rows, 'trip_name', '—')) + '</span><span><b>Driver:</b> ' + esc(reportValue(rows, 'driver_name', '—')) + '</span><span><b>Bus #:</b> ' + esc(reportValue(rows, 'bus_number', '—')) + '</span><span><b>Transportation:</b> ' + esc(transportLabel(reportValue(rows, 'transport_status', 'all'))) + '</span></div>';
    }
    function params(includeFilters) {
        var type = $('#school-report-type').value, result = {academic_year_id: $('#school-report-year').value, direction: type === 'walking' ? 'all' : $('#school-report-direction').value};
        if (type === 'school') result.transport_status = 'with';
        if (includeFilters) { result.grade = $('#school-report-grade').value; result.section = $('#school-report-section').value; result.area_id = $('#school-report-area').value; result.trip_id = $('#school-report-trip').value; result.transport_status = $('#school-report-transport').value; }
        if (type === 'walking') { result.transport_status = 'without'; result.grade = ''; result.section = ''; result.area_id = ''; result.trip_id = ''; }
        return new URLSearchParams(result);
    }
    function fetchReport(includeFilters) {
        return fetch(olamaReports.restUrl + 'reports/school-level?' + params(includeFilters).toString(), {headers: {'X-WP-Nonce': olamaReports.restNonce}}).then(function (response) { return response.json().then(function (body) { if (!response.ok) throw new Error(body.message || 'Report request failed'); return body; }); });
    }
    function setOptions(selector, options, placeholder, valueKey, labelKey) {
        var select = $(selector); select.innerHTML = '<option value="">' + esc(placeholder) + '</option>';
        options.forEach(function (item) { var value = valueKey ? item[valueKey] : item, label = labelKey ? item[labelKey] : item; select.insertAdjacentHTML('beforeend', '<option value="' + esc(value) + '">' + esc(label) + '</option>'); });
    }
    function loadFilters() {
        if ($('#school-report-transport')) $('#school-report-transport').parentElement.hidden = true;
        $('#school-report-feedback').textContent = 'Loading grades, sections, areas and trips…';
        fetchReport(false).then(function (data) {
            state.data = data;
            var grades = (data.filters || []).map(function (item) { return item.grade_name; }).filter(function (value, index, list) { return value && list.indexOf(value) === index; });
            setOptions('#school-report-grade', grades, 'All grades'); setOptions('#school-report-section', [], 'All sections'); setOptions('#school-report-area', data.areas || [], 'All planning areas', 'id', 'name'); setOptions('#school-report-trip', data.trips || [], 'All trips', 'id', 'name');
            $('#school-report-results').innerHTML = ''; $('#school-report-feedback').textContent = 'Choose filters, then review or print the report.'; render();
        }).catch(function (error) { $('#school-report-feedback').textContent = error.message; });
    }
    function updateSections() {
        var grade = $('#school-report-grade').value, sections = (state.data.filters || []).filter(function (item) { return (!grade || item.grade_name === grade) && item.section_name; }).map(function (item) { return item.section_name; }).filter(function (value, index, list) { return list.indexOf(value) === index; });
        setOptions('#school-report-section', sections, 'All sections'); render();
    }
    function render() {
        fetchReport(true).then(function (data) {
            state.data = data; var rows = (data.rows || []).slice().sort(function (a, b) { return String(a.oracle_family_id || '').localeCompare(String(b.oracle_family_id || ''), undefined, {numeric:true}) || String(a.student_name || '').localeCompare(String(b.student_name || '')); }); var body = rows.map(function (row, index) { var map = row.maps_url ? '<a target="_blank" rel="noopener" href="' + esc(row.maps_url) + '">Map</a>' : '—'; return '<tr><td>' + (index + 1) + '</td><td>' + esc(row.oracle_family_id || '—') + '</td><td>' + esc(row.student_name) + '</td><td>' + esc(row.grade_name) + '</td><td>' + esc(row.section_name) + '</td><td>' + esc(row.planning_area || '—') + '</td><td>' + esc(row.trip_name || '—') + '</td><td>' + esc(row.driver_name || '—') + '</td><td>' + esc(row.bus_number || '—') + '</td><td>' + esc(transportLabel(row.transport_status)) + '</td><td dir="ltr">' + esc(row.father_mobile || '—') + '</td><td dir="ltr">' + esc(row.mother_mobile || '—') + '</td><td>' + esc(row.oracle_address || '—') + '<br>' + map + '</td><td class="school-report-qr-column">' + mapQr(row.maps_url) + '</td></tr>'; }).join('');
            $('#school-report-results').innerHTML = '<div class="school-report-summary"><strong>' + rows.length + ' students</strong></div>' + reportMeta(rows) + '<table class="wp-list-table widefat striped school-report-table"><thead><tr><th>#</th><th>Family #</th><th>Student Name</th><th>Grade</th><th>Section</th><th>Planning area</th><th>Trip</th><th>Driver</th><th>Bus #</th><th>Transportation</th><th>Father mobile</th><th>Mother mobile</th><th>Oracle address / map</th><th>QR</th></tr></thead><tbody>' + (body || '<tr><td colspan="14">No students found.</td></tr>') + '</tbody></table>'; $('#school-report-feedback').textContent = '';
        }).catch(function (error) { $('#school-report-feedback').textContent = error.message; });
    }
    function printHtml(title, content) { var win = window.open('', '_blank'); if (!win) return; win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + title + '</title><style>@page{size:A4 landscape;margin:8mm}body{font-family:Arial,Tahoma,sans-serif;color:#172033}h1{font-size:20px}.school-print-header{font-size:18px;text-align:center;border:1px solid #aeb8c7;background:#f4f7fb;padding:8px;margin:6px 0 10px}.school-report-meta{display:none}table{width:100%;border-collapse:collapse;font-size:9px}th,td{border:1px solid #aeb8c7;padding:4px;text-align:left;vertical-align:top}th{background:#eaf0f7;color:#173b63}.school-report-map-qr{display:inline-flex;flex-direction:column;align-items:center;vertical-align:middle}.school-report-map-qr img{width:54px;height:54px;display:block}.school-report-map-qr small{font-size:7px;margin-top:2px}.school-report-qr-column{text-align:center;vertical-align:middle}@media print{button{display:none}}</style></head><body><button onclick="window.print()">Print report</button><h1>' + title + '</h1>' + content + '</body></html>'); win.document.close(); }
    function selectedFilterLabel(selector, includeWhenEmpty) {
        var field = $(selector); if (!field) return '';
        if (!includeWhenEmpty && !field.value) return '';
        return field.options[field.selectedIndex] ? field.options[field.selectedIndex].text : '';
    }
    function schoolPrintHeader() {
        var filters = [
            selectedFilterLabel('#school-report-year', true),
            selectedFilterLabel('#school-report-direction', true),
            selectedFilterLabel('#school-report-grade'),
            selectedFilterLabel('#school-report-section'),
            selectedFilterLabel('#school-report-area'),
            selectedFilterLabel('#school-report-trip')
        ].filter(Boolean);
        return '<div class="school-print-header"><strong>' + esc(filters.join(' - ')) + '</strong></div>';
    }
    function printReport() {
        var source = $('#school-report-results'), clone = source ? source.cloneNode(true) : null;
        if (!clone) return;
        var meta = clone.querySelector('.school-report-meta'); if (meta) meta.remove();
        clone.insertAdjacentHTML('afterbegin', schoolPrintHeader());
        printHtml('School transportation report', clone.innerHTML);
    }
    function familyReport() {
        var search = $('#family-report-search').value.trim(); if (!search) { $('#family-report-results').innerHTML = '<p>Enter a family ID, family name, student name, grade or section.</p>'; return; } var query = new URLSearchParams({academic_year_id: $('#family-report-year').value, search: search}); $('#school-report-feedback').textContent = 'Searching families…';
        fetch(olamaReports.restUrl + 'reports/families?' + query.toString(), {headers: {'X-WP-Nonce': olamaReports.restNonce}}).then(function (response) { return response.json(); }).then(function (data) {
            var html = (data.items || []).map(function (family) { var rows = (family.transport_rows || []).map(function (row, index) { return '<tr><td>' + (index + 1) + '</td><td><strong>' + esc(row.student_name) + '</strong></td><td>' + esc(row.grade_name || '—') + '</td><td>' + esc(row.section_name || '—') + '</td><td>' + familyTripCell(row.arrival) + '</td><td>' + familyTripCell(row.departure) + '</td></tr>'; }).join(''); var map = family.maps_url ? '<a target="_blank" rel="noopener" href="' + esc(family.maps_url) + '">Map location</a>' : '—'; return '<section class="family-report-card"><h3>' + esc(family.family_name) + ' <small>Family #' + esc(family.oracle_family_id) + '</small></h3><p class="family-report-contact"><span>Father: ' + esc(family.father_mobile || '—') + '</span><span>Mother: ' + esc(family.mother_mobile || '—') + '</span><span>Address: ' + esc(family.oracle_address || '—') + '</span><span>' + map + '</span></p><table><thead><tr><th>#</th><th>Student</th><th>Grade</th><th>Section</th><th>Arrival / حضور</th><th>Departure / عودة</th></tr></thead><tbody>' + (rows || '<tr><td colspan="6">No trip assignment</td></tr>') + '</tbody></table></section>'; }).join(''); $('#family-report-results').innerHTML = html || '<p>No matching families found.</p>'; $('#school-report-feedback').textContent = ''; }).catch(function (error) { $('#school-report-feedback').textContent = error.message; });
    }
    function unassignedReport() {
        $('#school-report-feedback').textContent = 'Loading unassigned transportation students…'; fetch(olamaReports.restUrl + 'reports/unassigned?academic_year_id=' + encodeURIComponent($('#unassigned-report-year').value), {headers: {'X-WP-Nonce': olamaReports.restNonce}}).then(function (response) { return response.json(); }).then(function (data) { var rows = (data.rows || []).slice().sort(function (a, b) { return String(a.oracle_family_id || '').localeCompare(String(b.oracle_family_id || ''), undefined, {numeric:true}) || String(a.student_name || '').localeCompare(String(b.student_name || '')); }); var body = rows.map(function (row, index) { var map = row.maps_url ? '<a target="_blank" rel="noopener" href="' + esc(row.maps_url) + '">Map</a>' : '—'; return '<tr><td>' + (index + 1) + '</td><td>' + esc(row.oracle_family_id || '—') + '</td><td>' + esc(row.student_name) + '</td><td>' + esc(row.grade_name || '—') + '</td><td>' + esc(row.section_name || '—') + '</td><td>' + esc(row.planning_area || '—') + '</td><td dir="ltr">' + esc(row.father_mobile || '—') + '</td><td dir="ltr">' + esc(row.mother_mobile || '—') + '</td><td>' + esc(row.oracle_address || '—') + '<br>' + map + '</td></tr>'; }).join(''); $('#unassigned-report-results').innerHTML = '<strong>' + rows.length + ' students</strong><table class="wp-list-table widefat striped"><thead><tr><th>#</th><th>Family #</th><th>Student</th><th>Grade</th><th>Section</th><th>Planning area</th><th>Father mobile</th><th>Mother mobile</th><th>Address / map</th></tr></thead><tbody>' + (body || '<tr><td colspan="9">No unassigned students found.</td></tr>') + '</tbody></table>'; $('#school-report-feedback').textContent = ''; }).catch(function (error) { $('#school-report-feedback').textContent = error.message; });
    }
    function selectType() { var type = $('#school-report-type').value, school = type === 'school' || type === 'walking', filters = $('#school-report-filters'), transportFilter = $('#school-report-transport'); $('#school-report-panel').hidden = !school; if (filters) filters.hidden = type === 'walking'; if (transportFilter) transportFilter.parentElement.hidden = true; $('#family-report-panel').hidden = type !== 'family'; $('#unassigned-report-panel').hidden = type !== 'unassigned'; if (type === 'family') familyReport(); if (type === 'unassigned') unassignedReport(); if (school) { render(); } }
    document.addEventListener('DOMContentLoaded', function () {
        if (!$('#olama-school-reports')) return;
        $('#school-report-type').parentElement.style.display = 'none'; document.querySelectorAll('#olama-school-reports .olama-area-filters').forEach(function (bar) { if (bar.querySelector('#school-report-direction')) bar.id = 'school-report-filters'; }); document.querySelectorAll('.reports-block').forEach(function (block) { block.addEventListener('click', function () { $('#school-report-type').value = block.getAttribute('data-report-type'); document.querySelectorAll('.reports-block').forEach(function (item) { item.classList.toggle('is-active', item === block); }); selectType(); }); }); $('#school-report-type').addEventListener('change', selectType); $('#school-report-year').addEventListener('change', function () { loadFilters(); if ($('#school-report-type').value === 'family') familyReport(); if ($('#school-report-type').value === 'unassigned') unassignedReport(); }); $('#school-report-direction').addEventListener('change', loadFilters); $('#school-report-grade').addEventListener('change', updateSections); ['#school-report-section','#school-report-area','#school-report-trip','#school-report-transport'].forEach(function (selector) { $(selector).addEventListener('change', render); }); $('#school-report-print').addEventListener('click', printReport); $('#family-report-search-button').addEventListener('click', familyReport); $('#family-report-print').addEventListener('click', function () { printHtml('Family transportation report', $('#family-report-results').innerHTML); }); $('#unassigned-report-print').addEventListener('click', function () { printHtml('Transportation students not assigned to a trip', $('#unassigned-report-results').innerHTML); }); loadFilters();
    });
}());
