(function () {
    'use strict';

    var root = document.getElementById('olama-area-planner');
    if (!root || typeof L === 'undefined' || typeof olamaPlanner === 'undefined') return;

    var school = [31.941402866181924, 36.00169383535448];
    var state = { data: null, mapData: null, markers: [], controller: null, activeAreaId: null, mapScope: 'transportation' };
    var map = L.map('olama-planning-map', { scrollWheelZoom: false, doubleClickZoom: false, touchZoom: false, boxZoom: false, keyboard: false }).setView(school, 12);
    var markerLayer = L.layerGroup().addTo(map);
    L.tileLayer(olamaPlanner.tileUrl, { attribution: olamaPlanner.tileAttribution, maxZoom: 19 }).addTo(map);

    function el(id) { return document.getElementById(id); }
    function node(value) { return document.createTextNode(value === null || value === undefined ? '' : String(value)); }
    function option(select, value, label) { var item = document.createElement('option'); item.value = value; item.appendChild(node(label)); select.appendChild(item); }
    function query(values) { var result = new URLSearchParams(); Object.keys(values).forEach(function (key) { if (values[key] !== '' && values[key] !== null && values[key] !== undefined) result.set(key, values[key]); }); return result.toString(); }
    function escape(value) { return String(value || '—').replace(/[&<>]/g, function (character) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[character]; }); }
    function color(area) { if (area && area.color && window.CSS && CSS.supports('color', area.color)) return area.color; var hash = 0, value = String(area && area.id || 'unassigned'); for (var i = 0; i < value.length; i++) hash = value.charCodeAt(i) + ((hash << 5) - hash); return 'hsl(' + Math.abs(hash % 360) + ',65%,42%)'; }
    function status(value, type) { var box = el('planner-demand-status'); box.textContent = value || ''; box.className = 'olama-planner-message' + (type ? ' is-' + type : ''); }
    function filters() { return { academic_year_id: parseInt(el('planner-year').value, 10), direction: el('planner-direction').value, major_area_id: el('planner-area').value }; }
    function get(path, signal) { return fetch(olamaPlanner.restUrl + path, { headers: { 'X-WP-Nonce': olamaPlanner.restNonce }, signal: signal }).then(function (response) { return response.json().then(function (body) { if (!response.ok) throw body; return body; }); }); }

    function load() {
        if (state.controller) state.controller.abort();
        state.controller = new AbortController();
        var currentFilters = filters(), signal = state.controller.signal;
        root.classList.add('is-loading');
        Promise.all([
            get('planning/area-allocations?' + query(Object.assign({}, currentFilters, { per_page: 500, page: 1, sort: 'name', order: 'asc' })), signal),
            get('planning/map-data?' + query(Object.assign({}, currentFilters, { mode: 'area', student_scope: 'all' })), signal)
        ]).then(function (response) { state.data = response[0]; state.mapData = response[1]; if (response[1].school && isFinite(Number(response[1].school.latitude)) && isFinite(Number(response[1].school.longitude))) school = [Number(response[1].school.latitude), Number(response[1].school.longitude)]; render(); }).catch(function (error) { if (error.name !== 'AbortError') status(error.message || olamaPlanner.i18n.failed, 'error'); }).finally(function () { root.classList.remove('is-loading'); });
    }

    function render() {
        var metrics = state.data.metrics || {}, metricIds = { registered_transportation_families: 'metric-registered', valid_family_locations: 'metric-valid', families_missing_coordinates: 'metric-missing-coordinates', families_with_planning_areas: 'metric-area-assigned', families_without_planning_areas: 'metric-area-missing' };
        Object.keys(metricIds).forEach(function (key) { el(metricIds[key]).textContent = metrics[key] || 0; });
        status(state.data.warning || '', state.data.warning ? 'warning' : ''); renderAreas(); ensureModeControls(); renderMap();
    }

    function renderAreas() {
        var areas = state.data.areas || [], select = el('planner-area'), selected = select.value, body = el('area-mapping-body');
        select.innerHTML = ''; option(select, '', olamaPlanner.i18n.allAreas); (state.data.area_options || []).forEach(function (area) { option(select, area.id, area.name); }); select.value = selected;
        body.innerHTML = ''; if (!areas.length) { var empty = body.insertRow().insertCell(); empty.colSpan = 6; empty.appendChild(node(olamaPlanner.i18n.noAreas)); return; }
        areas.forEach(function (area) { var row = body.insertRow(), name = row.insertCell(), label = document.createElement('span'), dot = document.createElement('span'); label.className = 'olama-area-name'; dot.className = 'olama-area-color-dot'; dot.style.setProperty('--area-color', color(area)); label.append(dot, node(area.name)); name.appendChild(label); row.insertCell().appendChild(node(String(area.family_count || 0) + ' (' + String(area.student_count || 0) + ' students)')); row.insertCell().appendChild(node(area.transportation_student_count || 0)); row.insertCell().appendChild(node(area.transport_kg_g1_count || 0)); row.insertCell().appendChild(node(area.non_transportation_student_count || 0)); var actions = row.insertCell(); [['all',olamaPlanner.i18n.mapAll||'Map all students'],['transportation',olamaPlanner.i18n.transportationMap||'Transportation map'],['walking',olamaPlanner.i18n.walkingMap||'Walking map']].forEach(function (item) { var button=document.createElement('button'); button.type='button'; button.className='button button-small'; button.appendChild(node(item[1])); button.addEventListener('click',function(event){event.stopPropagation();focusArea(area.id,item[0]);}); actions.appendChild(button); }); row.addEventListener('click', function (event) { if (!event.target.closest('button')) focusArea(area.id, 'all'); }); });
    }

    function renderMap() {
        markerLayer.clearLayers(); state.markers = []; var byArea = {}, legend = el('planner-area-legend'), used = {};
        (state.data.area_options || []).forEach(function (area) { byArea[area.id] = area; });
        (state.mapData.families || []).forEach(function (family) { var transportCount=Number(family.transportation_student_count||0), walkingCount=Number(family.non_transportation_student_count||0); if (state.activeAreaId !== null && parseInt(family.major_area_id || 0, 10) !== parseInt(state.activeAreaId, 10)) return; if (state.mapScope === 'transportation' && transportCount < 1) return; if (state.mapScope === 'walking' && walkingCount < 1) return; var area = byArea[family.major_area_id], count = state.mapScope === 'transportation' ? transportCount : (state.mapScope === 'walking' ? walkingCount : Number(family.student_count||0)), markerNode = document.createElement('span'); markerNode.className = 'olama-family-count-marker' + (family.major_area_id ? '' : ' is-unassigned'); markerNode.style.setProperty('--area-color', area ? color(area) : '#6b7280'); markerNode.style.setProperty('--marker-border-color', '#fff'); markerNode.textContent = String(count); var students=(family.students||[]).map(function(s){return '<li>'+escape(s.first_name||'')+' · '+escape(s.grade_name||'—')+' / '+escape(s.section_name||'—')+'</li>';}).join(''); var popup='<div><strong>Family #'+escape(family.oracle_family_id)+'</strong></div><div>Father: '+escape(family.father_name||'—')+'</div><div>Address: '+escape(family.oracle_address||'—')+'</div><div>'+count+' students</div>'+(students?'<ul>'+students+'</ul>':''); var marker = L.marker([family.latitude, family.longitude], { icon: L.divIcon({ className: 'olama-family-count-icon', html: markerNode.outerHTML, iconSize: [36, 36], iconAnchor: [18, 18], popupAnchor: [0, -18] }) }).bindPopup(popup); marker.addTo(markerLayer); state.markers.push({ marker: marker, areaId: family.major_area_id }); if (area) used[area.id] = area; });
        L.marker(school).addTo(markerLayer).bindPopup(olamaPlanner.i18n.school);
        legend.innerHTML = ''; Object.keys(used).map(function (id) { return used[id]; }).sort(function (a, b) { return a.name.localeCompare(b.name); }).forEach(function (area) { var item = document.createElement('span'), dot = document.createElement('span'); item.className = 'olama-area-color-key'; dot.className = 'olama-area-color-dot'; dot.style.setProperty('--area-color', color(area)); item.append(dot, node(area.name)); legend.appendChild(item); }); legend.hidden = !legend.children.length;
        renderInvalidLocations();
    }

    function ensureModeControls() {
        var mapNode=el('olama-planning-map'), controls=document.getElementById('planner-map-modes');
        if (!controls) { controls=document.createElement('div'); controls.id='planner-map-modes'; controls.className='olama-map-mode-actions'; mapNode.parentNode.insertBefore(controls,mapNode); }
        controls.innerHTML=''; [['transportation',olamaPlanner.i18n.transportationMode||'Transportation'],['walking',olamaPlanner.i18n.walkingMode||'Walking'],['all',olamaPlanner.i18n.allStudentsMode||'All students']].forEach(function(item){var button=document.createElement('button');button.type='button';button.className='button button-small'+(state.mapScope===item[0]?' is-active':'');button.appendChild(node(item[1]));button.addEventListener('click',function(){state.mapScope=item[0];ensureModeControls();renderMap();var points=state.markers.map(function(entry){return entry.marker.getLatLng();});points.push(L.latLng(school));if(points.length)map.fitBounds(L.latLngBounds(points),{padding:[24,24],maxZoom:14});});controls.appendChild(button);});
    }

    function renderInvalidLocations() { var strip=document.getElementById('planner-invalid-locations'); if(!strip) { strip=document.createElement('div'); strip.id='planner-invalid-locations'; strip.className='olama-invalid-location-strip'; el('olama-planning-map').parentNode.appendChild(strip); } var invalid=(state.mapData.invalid_families||[]).filter(function(f){return state.activeAreaId===null||parseInt(f.major_area_id||0,10)===parseInt(state.activeAreaId,10);}).filter(function(f){var t=Number(f.transportation_student_count||0),w=Number(f.non_transportation_student_count||0);return state.mapScope==='transportation'?t>0:state.mapScope==='walking'?w>0:true;}); strip.innerHTML=invalid.length?'<strong>'+(olamaPlanner.i18n.invalidLocations||'Invalid or missing locations')+':</strong> '+invalid.map(function(f){return '<span class="olama-invalid-family-chip" title="'+escape(f.father_name||'')+'">○ Family #'+escape(f.oracle_family_id)+'</span>';}).join(' '):''; strip.hidden=!invalid.length; }

    function focusArea(id, scope) { state.activeAreaId = parseInt(id, 10); state.mapScope = scope || 'all'; renderMap(); var points = state.markers.map(function (entry) { return entry.marker.getLatLng(); }); points.push(L.latLng(school)); if (points.length > 1) map.fitBounds(L.latLngBounds(points), { padding: [24, 24], maxZoom: 14 }); else status(olamaPlanner.i18n.noCoordinates, 'warning'); }
    el('planner-year').addEventListener('change', function () { state.activeAreaId = null; load(); });
    el('planner-direction').addEventListener('change', function () { state.activeAreaId = null; load(); });
    el('planner-area').addEventListener('change', function () { state.activeAreaId = this.value === '' ? null : parseInt(this.value, 10); load(); });
    el('planner-reset').addEventListener('click', function () { state.activeAreaId = null; el('planner-area').value = ''; load(); });
    el('planner-refresh-map').addEventListener('click', load);
    load();
}());
