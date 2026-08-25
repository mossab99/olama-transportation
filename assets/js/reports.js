(function () {
    'use strict';

    var state = { filters: [] };
    var $ = function (selector) { return document.querySelector(selector); };
    var esc = function (value) { var node = document.createElement('div'); node.textContent = value == null ? '' : value; return node.innerHTML; };
    var tr = function (text) {
        if (typeof window.olamaTransportationTranslate === 'function') return window.olamaTransportationTranslate(text);
        return olamaReports.language === 'ar' && olamaReports.i18n && olamaReports.i18n[text] ? olamaReports.i18n[text] : text;
    };

    function api(path, params) {
        var query = params ? '?' + new URLSearchParams(params).toString() : '';
        return fetch(olamaReports.restUrl + path + query, {headers: {'X-WP-Nonce': olamaReports.restNonce}}).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) throw new Error(body.message || 'Report request failed.');
                return body;
            });
        });
    }

    function currentType() { return $('#school-report-type').value; }
    function population() { return currentType() === 'walking' ? 'walking' : 'transportation'; }
    function params(includeFilters) {
        var result = {academic_year_id:$('#school-report-year').value, population:population(), direction:population() === 'walking' ? 'all' : $('#school-report-direction').value};
        if (includeFilters) {
            result.grade = $('#school-report-grade').value; result.section = $('#school-report-section').value;
            result.area_id = $('#school-report-area').value; result.trip_id = population() === 'walking' ? '' : $('#school-report-trip').value;
            result.assignment_status = population() === 'walking' ? 'all' : $('#school-report-assignment').value;
            result.school_filter = $('#school-report-school') ? $('#school-report-school').value : 'all';
        }
        return result;
    }

    function setOptions(selector, options, placeholder, valueKey, labelKey) {
        var select = $(selector), previous = select.value;
        select.innerHTML = '<option value="">' + esc(placeholder) + '</option>';
        options.forEach(function (item) {
            var value = valueKey ? item[valueKey] : item, label = labelKey ? item[labelKey] : item;
            select.insertAdjacentHTML('beforeend', '<option value="' + esc(value) + '">' + esc(label) + '</option>');
        });
        if ([].some.call(select.options, function (option) { return option.value === previous; })) select.value = previous;
    }

    function ensureExtraFilters() {
        if (!$('#school-report-school')) {
            var grade = $('#school-report-grade'), label = document.createElement('label');
            label.innerHTML = 'School<select id="school-report-school"><option value="all">All school</option><option value="kgs">All KGs</option></select>';
            grade.parentElement.insertAdjacentElement('afterend', label);
            label.querySelector('select').addEventListener('change', renderSchool);
        }
        if (!$('#school-report-sort')) {
            var anchor = $('#school-report-school').parentElement, sort = document.createElement('label'), order = document.createElement('label');
            sort.innerHTML = 'Sort by<select id="school-report-sort"><option value="family">Family #</option><option value="student">Student</option><option value="grade">Grade / section</option><option value="area">Planning area</option><option value="status">Assignment status</option></select>';
            order.innerHTML = 'Order<select id="school-report-sort-order"><option value="asc">Ascending</option><option value="desc">Descending</option></select>';
            anchor.insertAdjacentElement('afterend', sort); sort.insertAdjacentElement('afterend', order);
            sort.querySelector('select').addEventListener('change', renderSchool); order.querySelector('select').addEventListener('change', renderSchool);
        }
    }

    function loadFilters() {
        ensureExtraFilters(); $('#school-report-feedback').textContent = 'Loading canonical report population…';
        api('reports/school-level', params(false)).then(function (data) {
            state.filters = data.filters || [];
            var grades = state.filters.map(function (item) { return item.grade_name; }).filter(function (value, index, list) { return value && list.indexOf(value) === index; });
            setOptions('#school-report-grade', grades, 'All grades'); updateSections(false);
            setOptions('#school-report-area', data.areas || [], 'All planning areas', 'id', 'name');
            setOptions('#school-report-trip', data.trips || [], 'All trips', 'id', 'name'); renderSchool();
        }).catch(showError);
    }

    function updateSections(renderAfter) {
        var grade = $('#school-report-grade').value;
        var sections = state.filters.filter(function (item) { return (!grade || item.grade_name === grade) && item.section_name; }).map(function (item) { return item.section_name; }).filter(function (value, index, list) { return list.indexOf(value) === index; });
        setOptions('#school-report-section', sections, 'All sections'); if (renderAfter !== false) renderSchool();
    }

    function statusLabel(status) { var label={fully_assigned:'Assigned both directions',partial:'One direction only',assigned:'Assigned',unassigned:'Not assigned'}[status]; return label ? tr(label) : status || '—'; }
    function tripCell(run) {
        if (!run || !run.trip_id) return '<span class="family-no-trip">—</span>';
        var warning = run.conflict_count ? '<small class="report-warning">'+esc(tr('Multiple assignments detected'))+'</small>' : '';
        return '<div><strong>' + esc(run.trip_name || '—') + '</strong><small>' + esc([run.driver_name, run.bus_number ? tr('Bus') + ' ' + run.bus_number : ''].filter(Boolean).join(' · ') || '—') + '</small>' + warning + '</div>';
    }

    function sortRows(rows) {
        var field = $('#school-report-sort') ? $('#school-report-sort').value : 'family', descending = $('#school-report-sort-order') && $('#school-report-sort-order').value === 'desc';
        function values(row) {
            if (field === 'student') return [row.student_name || '']; if (field === 'grade') return [row.grade_name || '',row.section_name || '',row.student_name || ''];
            if (field === 'area') return [row.planning_area || '',row.student_name || '']; if (field === 'status') return [row.assignment_status || '',row.student_name || ''];
            return [row.oracle_family_id || '',row.student_name || ''];
        }
        return rows.slice().sort(function (a,b) { var left=values(a),right=values(b),result=0; for(var i=0;i<left.length&&result===0;i++) result=String(left[i]).localeCompare(String(right[i]),'ar',{numeric:true,sensitivity:'base'}); return descending?-result:result; });
    }

    function summaryHtml(data) {
        var summary=data.summary||{}, diagnostics=data.diagnostics||{};
        var assignment=data.population==='walking'?'':'<span><b>'+Number(summary.fully_assigned||summary.assigned||0)+'</b> assigned</span><span><b>'+Number(summary.partial||0)+'</b> partial</span><span><b>'+Number(summary.unassigned||0)+'</b> unassigned</span>';
        var warnings=[];
        if(diagnostics.duplicate_subscription_records){var familyLabel=tr('Family #'),duplicateFamilies=(diagnostics.duplicate_subscription_families||[]).map(function(item){return item.family_number;}).filter(Boolean);var duplicateMessage=diagnostics.duplicate_subscription_records+' '+tr('duplicate subscription rows collapsed');if(duplicateFamilies.length)duplicateMessage+=' — '+duplicateFamilies.map(function(number){return familyLabel+(familyLabel.indexOf('#')===-1?' ':'')+number;}).join(', ');warnings.push(duplicateMessage);}
        if(diagnostics.missing_academic_registration)warnings.push(diagnostics.missing_academic_registration+' '+tr('subscribed students missing academic registration'));
        if(diagnostics.missing_student_identity)warnings.push(diagnostics.missing_student_identity+' '+tr('missing student identities'));
        if(diagnostics.stale_assigned_students)warnings.push(diagnostics.stale_assigned_students+' '+tr('assigned students are not actively subscribed'));
        if(diagnostics.assignment_conflicts)warnings.push(diagnostics.assignment_conflicts+' '+tr('duplicate direction assignments'));
        var sourceRows=data.population==='walking'?'':'<span><b>'+Number(summary.filtered_subscription_records||0)+'</b> synchronized rows</span>';
        return '<div class="school-report-summary"><span><b>'+Number(summary.filtered_students||0)+'</b> '+esc(tr('distinct students shown'))+'</span>'+sourceRows+'<span><b>'+Number(summary.subscribed_students||0)+'</b> '+esc(tr('school-wide subscribed'))+'</span><span><b>'+Number(summary.walking_students||0)+'</b> '+esc(tr('school-wide walking'))+'</span>'+assignment+'</div>'+(warnings.length?'<div class="report-diagnostics"><strong>'+esc(tr('Data checks:'))+'</strong> '+esc(warnings.join(' · '))+'</div>':'');
    }

    function renderSchool() {
        $('#school-report-feedback').textContent='Calculating report…';
        api('reports/school-level',params(true)).then(function(data){
            var rows=sortRows(data.rows||[]), body=rows.map(function(row,index){
                var map=row.maps_url?'<a target="_blank" rel="noopener" href="'+esc(row.maps_url)+'">Map</a>':'—', subscription=row.subscribed?'Subscribed':'Walking';
                return '<tr><td>'+(index+1)+'</td><td>'+esc(row.oracle_family_id||'—')+'</td><td>'+esc(row.student_name)+'</td><td>'+esc(row.grade_name||'—')+'</td><td>'+esc(row.section_name||'—')+'</td><td>'+esc(row.planning_area||'—')+'</td><td>'+tripCell(row.arrival)+'</td><td>'+tripCell(row.departure)+'</td><td>'+esc(subscription)+'</td><td>'+esc(row.subscribed?statusLabel(row.assignment_status):'Not subscribed')+'</td><td dir="ltr">'+esc(row.father_mobile||'—')+'</td><td dir="ltr">'+esc(row.mother_mobile||'—')+'</td><td>'+esc(row.oracle_address||'—')+'<br>'+map+'</td></tr>';
            }).join('');
            $('#school-report-results').innerHTML=summaryHtml(data)+'<div class="school-report-table-wrap"><table class="wp-list-table widefat striped school-report-table"><thead><tr><th>#</th><th>Family #</th><th>Student</th><th>Grade</th><th>Section</th><th>Planning area</th><th>Arrival trip</th><th>Departure trip</th><th>Subscription</th><th>Assignment</th><th>Father mobile</th><th>Mother mobile</th><th>Address / map</th></tr></thead><tbody>'+(body||'<tr><td colspan="13">No students match these filters.</td></tr>')+'</tbody></table></div>';
            $('#school-report-feedback').textContent='';
        }).catch(showError);
    }

    function familyReport() {
        var search=$('#family-report-search').value.trim(); if(!search){$('#family-report-results').innerHTML='<p>Enter a family ID, family name, student name, grade or section.</p>';return;}
        $('#school-report-feedback').textContent='Searching families…';
        api('reports/families',{academic_year_id:$('#family-report-year').value,search:search}).then(function(data){
            var html=(data.items||[]).map(function(family){
                var rows=(family.students||[]).map(function(row,index){return '<tr><td>'+(index+1)+'</td><td><strong>'+esc(row.student_name)+'</strong></td><td>'+esc(row.grade_name||'—')+'</td><td>'+esc(row.section_name||'—')+'</td><td>'+esc(row.subscribed?'Subscribed':'Walking')+'</td><td>'+tripCell(row.arrival)+'</td><td>'+tripCell(row.departure)+'</td></tr>';}).join('');
                return '<section class="family-report-card"><h3>'+esc(family.family_name)+' <small>'+esc(tr('Family #'))+' '+esc(family.oracle_family_id)+'</small></h3><p class="family-report-contact"><span>'+esc(tr('Father:'))+' '+esc(family.father_mobile||'—')+'</span><span>'+esc(tr('Mother:'))+' '+esc(family.mother_mobile||'—')+'</span><span>'+esc(tr('Area:'))+' '+esc(family.planning_area||'—')+'</span></p><table><thead><tr><th>#</th><th>Student</th><th>Grade</th><th>Section</th><th>Subscription</th><th>Arrival</th><th>Departure</th></tr></thead><tbody>'+rows+'</tbody></table></section>';
            }).join('');
            $('#family-report-results').innerHTML=html||'<p>No matching families found.</p>'; $('#school-report-feedback').textContent='';
        }).catch(showError);
    }

    function unassignedReport() {
        $('#school-report-feedback').textContent='Calculating assignment gaps…';
        api('reports/unassigned',{academic_year_id:$('#unassigned-report-year').value,scope:$('#unassigned-report-scope').value}).then(function(data){
            var rows=data.rows||[],body=rows.map(function(row,index){return '<tr><td>'+(index+1)+'</td><td>'+esc(row.oracle_family_id||'—')+'</td><td>'+esc(row.student_name)+'</td><td>'+esc(row.grade_name||'—')+'</td><td>'+esc(row.section_name||'—')+'</td><td>'+esc(row.planning_area||'—')+'</td><td>'+tripCell(row.arrival)+'</td><td>'+tripCell(row.departure)+'</td><td>'+esc(statusLabel(row.assignment_status))+'</td><td dir="ltr">'+esc(row.father_mobile||'—')+'</td><td dir="ltr">'+esc(row.mother_mobile||'—')+'</td></tr>';}).join('');
            $('#unassigned-report-results').innerHTML=summaryHtml({summary:data.summary,diagnostics:data.diagnostics,population:'transportation'})+'<div class="school-report-table-wrap"><table class="wp-list-table widefat striped school-report-table"><thead><tr><th>#</th><th>Family #</th><th>Student</th><th>Grade</th><th>Section</th><th>Planning area</th><th>Arrival</th><th>Departure</th><th>Status</th><th>Father mobile</th><th>Mother mobile</th></tr></thead><tbody>'+(body||'<tr><td colspan="11">No assignment gaps found.</td></tr>')+'</tbody></table></div>'; $('#school-report-feedback').textContent='';
        }).catch(showError);
    }

    function printHtml(title,content){var win=window.open('','_blank');if(!win)return;win.document.write('<!doctype html><html lang="'+esc(olamaReports.language||'en')+'" dir="'+esc(olamaReports.direction||'ltr')+'"><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>@page{size:A4 landscape;margin:8mm}body{font-family:Arial,Tahoma,sans-serif;color:#172033}table{width:100%;border-collapse:collapse;font-size:8px}th,td{border:1px solid #aeb8c7;padding:4px;text-align:start;vertical-align:top}th{background:#eaf0f7}.school-report-summary{display:flex;gap:12px;margin:8px 0}.school-report-summary span{border:1px solid #ccc;padding:5px}.report-diagnostics{color:#8a4b00;margin:6px 0}@media print{button{display:none}}</style></head><body><button onclick="window.print()">'+esc(tr('Print'))+'</button><h1>'+esc(title)+'</h1>'+content+'</body></html>');win.document.close();}

    function selectType(){var type=currentType(),school=type==='school'||type==='walking';$('#school-report-panel').hidden=!school;$('#family-report-panel').hidden=type!=='family';$('#unassigned-report-panel').hidden=type!=='unassigned';document.querySelectorAll('.report-transport-only').forEach(function(item){item.hidden=type==='walking';});if(school){$('#school-report-heading').textContent=type==='walking'?'Walking students':'Transportation subscription and assignment report';$('#school-report-description').textContent=type==='walking'?'Academically registered students without an active synchronized transportation subscription.':'Subscription comes from synchronized Core transportation records; assignments come from local trips.';loadFilters();}if(type==='family')familyReport();if(type==='unassigned')unassignedReport();}
    function showError(error){$('#school-report-feedback').textContent=error.message||String(error);}

    document.addEventListener('DOMContentLoaded',function(){
        if(!$('#olama-school-reports'))return;$('#school-report-type').parentElement.style.display='none';
        document.querySelectorAll('.reports-block').forEach(function(block){block.addEventListener('click',function(){$('#school-report-type').value=block.getAttribute('data-report-type');document.querySelectorAll('.reports-block').forEach(function(item){item.classList.toggle('is-active',item===block);});selectType();});});
        $('#school-report-year').addEventListener('change',loadFilters);$('#school-report-direction').addEventListener('change',loadFilters);$('#school-report-grade').addEventListener('change',function(){updateSections(true);});
        ['#school-report-section','#school-report-area','#school-report-trip','#school-report-assignment'].forEach(function(selector){$(selector).addEventListener('change',renderSchool);});
        $('#school-report-print').addEventListener('click',function(){printHtml(tr('Transportation report'),$('#school-report-results').innerHTML);});$('#family-report-search-button').addEventListener('click',familyReport);$('#family-report-search').addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();familyReport();}});$('#family-report-print').addEventListener('click',function(){printHtml(tr('Family transportation report'),$('#family-report-results').innerHTML);});
        $('#unassigned-report-year').addEventListener('change',unassignedReport);$('#unassigned-report-scope').addEventListener('change',unassignedReport);$('#unassigned-report-print').addEventListener('click',function(){printHtml(tr('Transportation assignment gaps'),$('#unassigned-report-results').innerHTML);});selectType();
    });
}());
