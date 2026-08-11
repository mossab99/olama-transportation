(function () {
    'use strict';

    var root = document.getElementById('olama-area-planner');
    if (!root || typeof L === 'undefined' || typeof olamaPlanner === 'undefined') return;

    var school = [31.941402866181924, 36.00169383535448];
    var state = { data: null, mapData: null, markers: [], controller: null };
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
            get('planning/map-data?' + query(Object.assign({}, currentFilters, { mode: 'area' })), signal)
        ]).then(function (response) { state.data = response[0]; state.mapData = response[1]; render(); }).catch(function (error) { if (error.name !== 'AbortError') status(error.message || olamaPlanner.i18n.failed, 'error'); }).finally(function () { root.classList.remove('is-loading'); });
    }

    function render() {
        var metrics = state.data.metrics || {}, metricIds = { registered_transportation_families: 'metric-registered', valid_family_locations: 'metric-valid', families_missing_coordinates: 'metric-missing-coordinates', families_with_planning_areas: 'metric-area-assigned', families_without_planning_areas: 'metric-area-missing' };
        Object.keys(metricIds).forEach(function (key) { el(metricIds[key]).textContent = metrics[key] || 0; });
        status(state.data.warning || '', state.data.warning ? 'warning' : ''); renderAreas(); renderMap();
    }

    function renderAreas() {
        var areas = state.data.areas || [], select = el('planner-area'), selected = select.value, body = el('area-mapping-body');
        select.innerHTML = ''; option(select, '', olamaPlanner.i18n.allAreas); (state.data.area_options || []).forEach(function (area) { option(select, area.id, area.name); }); select.value = selected;
        body.innerHTML = ''; if (!areas.length) { var empty = body.insertRow().insertCell(); empty.colSpan = 4; empty.appendChild(node(olamaPlanner.i18n.noAreas)); return; }
        areas.forEach(function (area) { var row = body.insertRow(), name = row.insertCell(), label = document.createElement('span'), dot = document.createElement('span'), button = document.createElement('button'); label.className = 'olama-area-name'; dot.className = 'olama-area-color-dot'; dot.style.setProperty('--area-color', color(area)); label.append(dot, node(area.name)); name.appendChild(label); row.insertCell().appendChild(node(area.family_count)); row.insertCell().appendChild(node(area.student_count)); button.type = 'button'; button.className = 'button button-small'; button.appendChild(node(olamaPlanner.i18n.map)); button.addEventListener('click', function () { focusArea(area.id); }); row.insertCell().appendChild(button); row.addEventListener('click', function (event) { if (!event.target.closest('button')) focusArea(area.id); }); });
    }

    function renderMap() {
        markerLayer.clearLayers(); state.markers = []; var byArea = {}, legend = el('planner-area-legend'), used = {};
        (state.data.area_options || []).forEach(function (area) { byArea[area.id] = area; });
        (state.mapData.families || []).forEach(function (family) { var area = byArea[family.major_area_id], count = Math.max(0, parseInt(family.student_count || 0, 10)), markerNode = document.createElement('span'); markerNode.className = 'olama-family-count-marker' + (family.major_area_id ? '' : ' is-unassigned'); markerNode.style.setProperty('--area-color', area ? color(area) : '#6b7280'); markerNode.style.setProperty('--marker-border-color', '#fff'); markerNode.textContent = String(count); var marker = L.marker([family.latitude, family.longitude], { icon: L.divIcon({ className: 'olama-family-count-icon', html: markerNode.outerHTML, iconSize: [36, 36], iconAnchor: [18, 18], popupAnchor: [0, -18] }) }).bindPopup('<div>' + escape(family.family_name) + '</div><div>#' + escape(family.oracle_family_id) + '</div><div>' + escape(family.major_area_name) + '</div><div>' + count + ' ' + escape(olamaPlanner.i18n.students) + '</div>'); marker.addTo(markerLayer); state.markers.push({ marker: marker, areaId: family.major_area_id }); if (area) used[area.id] = area; });
        L.marker(school).addTo(markerLayer).bindPopup(olamaPlanner.i18n.school);
        legend.innerHTML = ''; Object.keys(used).map(function (id) { return used[id]; }).sort(function (a, b) { return a.name.localeCompare(b.name); }).forEach(function (area) { var item = document.createElement('span'), dot = document.createElement('span'); item.className = 'olama-area-color-key'; dot.className = 'olama-area-color-dot'; dot.style.setProperty('--area-color', color(area)); item.append(dot, node(area.name)); legend.appendChild(item); }); legend.hidden = !legend.children.length;
    }

    function focusArea(id) { var points = state.markers.filter(function (entry) { return parseInt(entry.areaId || 0, 10) === parseInt(id, 10); }).map(function (entry) { return entry.marker.getLatLng(); }); if (points.length) map.panTo(L.latLngBounds(points).getCenter()); else status(olamaPlanner.i18n.noCoordinates, 'warning'); }
    ['planner-year', 'planner-direction', 'planner-area'].forEach(function (id) { el(id).addEventListener('change', load); });
    el('planner-reset').addEventListener('click', function () { el('planner-area').value = ''; load(); });
    el('planner-refresh-map').addEventListener('click', load);
    load();
}());
