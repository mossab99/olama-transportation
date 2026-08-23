(function () {
    'use strict';
    var state = { data: { filters: [], areas: [], trips: [], rows: [] } };
    var $ = function (selector) { return document.querySelector(selector); };
    var esc = function (value) { var node = document.createElement('div'); node.textContent = value == null ? '' : value; return node.innerHTML; };
    function params(includeFilters) {
        var result = {academic_year_id: $('#school-report-year').value, direction: $('#school-report-direction').value};
        if (includeFilters) { result.grade = $('#school-report-grade').value; result.section = $('#school-report-section').value; result.area_id = $('#school-report-area').value; result.trip_id = $('#school-report-trip').value; result.transport_status = $('#school-report-transport').value; }
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
            state.data = data; var rows = data.rows || []; var body = rows.map(function (row) { return '<tr><td>' + esc(row.student_name) + '</td><td>' + esc(row.grade_name) + '</td><td>' + esc(row.section_name) + '</td><td>' + esc(row.planning_area || '—') + '</td><td>' + esc(row.trip_name || '—') + '</td><td>' + esc(row.driver_name || '—') + '</td><td>' + esc(row.bus_number || '—') + '</td><td>' + esc(row.transport_status === 'with' ? 'With transportation' : 'Without transportation') + '</td><td dir="ltr">' + esc(row.father_mobile || '—') + '</td><td dir="ltr">' + esc(row.mother_mobile || '—') + '</td><td>' + esc(row.oracle_address || '—') + '</td></tr>'; }).join('');
            $('#school-report-results').innerHTML = '<div class="school-report-summary"><strong>' + rows.length + ' students</strong></div><table class="wp-list-table widefat striped school-report-table"><thead><tr><th>Student Name</th><th>Grade</th><th>Section</th><th>Planning area</th><th>Trip</th><th>Driver</th><th>Bus #</th><th>Transportation</th><th>Father mobile</th><th>Mother mobile</th><th>Oracle address</th></tr></thead><tbody>' + (body || '<tr><td colspan="11">No students found.</td></tr>') + '</tbody></table>'; $('#school-report-feedback').textContent = '';
        }).catch(function (error) { $('#school-report-feedback').textContent = error.message; });
    }
    function printReport() { var table = $('#school-report-results').innerHTML; if (!table) return; var win = window.open('', '_blank'); if (!win) return; win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>School transportation report</title><style>@page{size:A4 landscape;margin:8mm}body{font-family:Arial,Tahoma,sans-serif;color:#172033}h1{font-size:20px}table{width:100%;border-collapse:collapse;font-size:9px}th,td{border:1px solid #aeb8c7;padding:4px;text-align:left;vertical-align:top}th{background:#eaf0f7;color:#173b63}@media print{button{display:none}}</style></head><body><button onclick="window.print()">Print report</button><h1>School transportation report</h1>' + table + '</body></html>'); win.document.close(); }
    document.addEventListener('DOMContentLoaded', function () {
        if (!$('#olama-school-reports')) return;
        $('#school-report-year').addEventListener('change', loadFilters); $('#school-report-direction').addEventListener('change', loadFilters); $('#school-report-grade').addEventListener('change', updateSections); ['#school-report-section','#school-report-area','#school-report-trip','#school-report-transport'].forEach(function (selector) { $(selector).addEventListener('change', render); }); $('#school-report-print').addEventListener('click', printReport); loadFilters();
    });
}());
