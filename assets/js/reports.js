(function () {
    'use strict';
    var state = { data: { filters: [], rows: [] } };
    var $ = function (selector) { return document.querySelector(selector); };
    var esc = function (value) { var node = document.createElement('div'); node.textContent = value == null ? '' : value; return node.innerHTML; };
    function request() {
        var params = new URLSearchParams({academic_year_id: $('#school-report-year').value, direction: $('#school-report-direction').value});
        return fetch(olamaReports.restUrl + 'reports/school-level?' + params.toString(), {headers: {'X-WP-Nonce': olamaReports.restNonce}}).then(function (response) {
            return response.json().then(function (body) { if (!response.ok) throw new Error(body.message || 'Report request failed'); return body; });
        });
    }
    function setOptions(selector, values, placeholder) {
        var select = $(selector); select.innerHTML = '<option value="">' + esc(placeholder) + '</option>';
        values.forEach(function (value) { select.insertAdjacentHTML('beforeend', '<option value="' + esc(value) + '">' + esc(value) + '</option>'); });
    }
    function loadFilters() {
        $('#school-report-feedback').textContent = 'Loading grades and sections…';
        return request().then(function (data) {
            state.data = data; var grades = [], sections = [];
            (data.filters || []).forEach(function (item) { if (item.grade_name && grades.indexOf(item.grade_name) < 0) grades.push(item.grade_name); if (item.section_name && sections.indexOf(item.section_name) < 0) sections.push(item.section_name); });
            setOptions('#school-report-grade', grades, 'Select grade'); setOptions('#school-report-section', sections, 'Select section'); $('#school-report-results').innerHTML = ''; $('#school-report-feedback').textContent = 'Select a grade and section.';
        }).catch(function (error) { $('#school-report-feedback').textContent = error.message; });
    }
    function render() {
        var grade = $('#school-report-grade').value, section = $('#school-report-section').value;
        if (!grade || !section) { $('#school-report-results').innerHTML = ''; return; }
        var params = new URLSearchParams({academic_year_id: $('#school-report-year').value, direction: $('#school-report-direction').value, grade: grade, section: section});
        fetch(olamaReports.restUrl + 'reports/school-level?' + params.toString(), {headers: {'X-WP-Nonce': olamaReports.restNonce}}).then(function (response) { return response.json(); }).then(function (data) {
            state.data = data; var rows = data.rows || []; var body = rows.map(function (row) { return '<tr><td>' + esc(row.student_name) + '</td><td>' + esc(row.grade_name) + '</td><td>' + esc(row.section_name) + '</td><td>' + esc(row.trip_name) + '</td><td>' + esc(row.driver_name || '—') + '</td><td>' + esc(row.bus_number || '—') + '</td><td dir="ltr">' + esc(row.father_mobile || '—') + '</td><td dir="ltr">' + esc(row.mother_mobile || '—') + '</td><td>' + esc(row.oracle_address || '—') + '</td></tr>'; }).join('');
            $('#school-report-results').innerHTML = '<div class="school-report-summary"><strong>' + rows.length + ' students</strong> · ' + esc(grade) + ' · ' + esc(section) + '</div><table class="wp-list-table widefat striped school-report-table"><thead><tr><th>Student Name</th><th>Grade</th><th>Section</th><th>Trip</th><th>Driver</th><th>Bus #</th><th>Father mobile</th><th>Mother mobile</th><th>Oracle address</th></tr></thead><tbody>' + (body || '<tr><td colspan="9">No transportation students found.</td></tr>') + '</tbody></table>';
            $('#school-report-feedback').textContent = '';
        }).catch(function (error) { $('#school-report-feedback').textContent = error.message; });
    }
    function printReport() {
        var table = $('#school-report-results').innerHTML; if (!table) return;
        var win = window.open('', '_blank'); if (!win) return;
        win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>School transportation report</title><style>@page{size:A4 landscape;margin:10mm}body{font-family:Arial,Tahoma,sans-serif;color:#172033}h1{font-size:20px}table{width:100%;border-collapse:collapse;font-size:10px}th,td{border:1px solid #aeb8c7;padding:5px;text-align:left;vertical-align:top}th{background:#eaf0f7;color:#173b63}@media print{button{display:none}}</style></head><body><button onclick="window.print()">Print report</button><h1>School transportation report</h1>' + table + '</body></html>'); win.document.close();
    }
    document.addEventListener('DOMContentLoaded', function () {
        if (!$('#olama-school-reports')) return;
        $('#school-report-year').addEventListener('change', loadFilters); $('#school-report-direction').addEventListener('change', loadFilters); $('#school-report-grade').addEventListener('change', render); $('#school-report-section').addEventListener('change', render); $('#school-report-print').addEventListener('click', printReport); loadFilters();
    });
}());
