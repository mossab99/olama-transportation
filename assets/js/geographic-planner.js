(function () {
    'use strict';

    var root = document.getElementById('olama-geographic-planner');
    if (!root || !window.L || !window.olamaPlanner) return;

    var state = {
        academicYearId: Number(root.dataset.yearId || 0), direction: 'morning', filters: {}, families: [], groups: [], buses: [], areas: [],
        selectedFamilyUids: new Set(), editingGroupId: 0, selectedBusId: 0, selectedTripNumber: 0,
        drawnPolygon: null, unsavedChanges: false, requestInProgress: false, readOnly: false, markers: new Map(), groupLayers: [], mapDataCache: {}
    };
    var el = function (id) { return document.getElementById(id); };
    var tr = function (key) { return olamaPlanner.i18n[key] || key; };
    var map = L.map('olama-planning-map', { preferCanvas: true }).setView([31.9539, 35.9106], 11);
    L.tileLayer(olamaPlanner.tileUrl, { attribution: olamaPlanner.tileAttribution, maxZoom: 19 }).addTo(map);
    var familyLayer = L.layerGroup().addTo(map);
    var overlayLayer = L.layerGroup().addTo(map);
    var drawnLayer = L.featureGroup().addTo(map);
    var schoolMarker = null;
    var activePolygonDrawer = null;
    var restoreMapDragging = false;

    function api(path, options) {
        options = options || {};
        options.headers = options.headers || {};
        options.headers['X-WP-Nonce'] = olamaPlanner.restNonce;
        if (options.body) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }
        return fetch(olamaPlanner.restUrl + path, options).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) {
                    var error = new Error(payload.message || 'Request failed');
                    error.payload = payload;
                    throw error;
                }
                return payload;
            });
        });
    }

    function load(force) {
        if (state.requestInProgress) return Promise.resolve();
        state.requestInProgress = true;
        setDisabled(true);
        var key = state.academicYearId + ':' + state.direction;
        var request = !force && state.mapDataCache[key]
            ? Promise.resolve(state.mapDataCache[key])
            : api('planning/map-data?academic_year_id=' + state.academicYearId + '&direction=' + encodeURIComponent(state.direction));
        return request.then(function (data) {
            state.mapDataCache[key] = data;
            state.families = data.families || [];
            state.groups = data.groups || [];
            state.buses = data.buses || [];
            state.areas = data.areas || [];
            populateFilters();
            renderAll(data, force);
            el('planner-demand-status').textContent = data.meta.warning || (data.meta.demand_mode === 'transport_enrollments' ? 'Transportation enrollment demand' : '');
            el('planner-demand-status').className = 'olama-planner-message ' + (data.meta.warning ? 'is-warning' : 'is-ok');
        }).catch(showError).finally(function () {
            state.requestInProgress = false;
            setDisabled(false);
        });
    }

    function populateFilters() {
        fillSelect(el('planner-area'), state.areas.filter(function (a) { return a.status === 'active'; }), 'id', 'name', 'All areas');
        fillSelect(el('group-area'), state.areas.filter(function (a) { return a.status === 'active'; }), 'id', 'name', 'No primary area');
        fillSelect(el('planner-filter-bus'), state.buses, 'id', 'bus_number', 'All buses');
        fillSelect(el('group-bus'), state.buses.filter(function (b) { return b.assignable; }), 'id', 'bus_number', 'Select Bus');
        el('group-bus').value = state.selectedBusId || '';
        updateFilterTrips();
    }

    function fillSelect(select, rows, valueKey, textKey, first) {
        var current = select.value;
        select.replaceChildren(new Option(first, ''));
        rows.forEach(function (row) {
            var label = row[textKey] + (row.core_capacity_missing ? ' — local capacity override' : '');
            select.appendChild(new Option(label, row[valueKey]));
        });
        if ([].some.call(select.options, function (o) { return o.value === current; })) select.value = current;
    }

    function renderAll(data, fit) {
        renderMarkers();
        renderGroupOverlays();
        renderGroupsTable();
        updateMetrics(data.meta);
        updatePanel();
        if (fit) fitMap(data.school);
        if (!schoolMarker) {
            schoolMarker = L.marker([data.school.latitude, data.school.longitude], { title: 'School', zIndexOffset: 1000 }).addTo(map).bindPopup('School');
        } else schoolMarker.setLatLng([data.school.latitude, data.school.longitude]);
    }

    function visibleFamilies() {
        var query = (el('planner-search').value || '').trim().toLowerCase();
        var area = Number(el('planner-area').value || 0);
        var assignment = el('planner-assignment').value;
        var location = el('planner-location-status').value;
        var bus = Number(el('planner-filter-bus').value || 0);
        var trip = Number(el('planner-filter-trip').value || 0);
        return state.families.filter(function (family) {
            if (area && Number(family.major_area_id) !== area) return false;
            if (assignment === 'assigned' && !family.assignment) return false;
            if (assignment === 'unassigned' && family.assignment) return false;
            if (location !== 'all' && family.location_status !== location) return false;
            if (bus && (!family.assignment || Number(family.assignment.bus_id) !== bus)) return false;
            if (trip && (!family.assignment || Number(family.assignment.trip_number) !== trip)) return false;
            return !query || (family.family_name + ' ' + family.oracle_family_id).toLowerCase().indexOf(query) !== -1;
        });
    }

    function renderMarkers() {
        var visible = new Set(visibleFamilies().map(function (f) { return f.family_uid; }));
        state.families.forEach(function (family) {
            var marker = state.markers.get(family.family_uid);
            if (!marker) {
                marker = L.marker([family.latitude, family.longitude], { icon: markerIcon(family) });
                marker.on('click', function () { markerClicked(family, marker); });
                state.markers.set(family.family_uid, marker);
            }
            marker.setIcon(markerIcon(family));
            if (visible.has(family.family_uid)) {
                if (!familyLayer.hasLayer(marker)) marker.addTo(familyLayer);
            } else if (familyLayer.hasLayer(marker)) familyLayer.removeLayer(marker);
        });
    }

    function markerIcon(family) {
        var selected = state.selectedFamilyUids.has(family.family_uid);
        var assigned = family.assignment;
        var color = assigned ? assigned.color : '#6b7280';
        var classes = 'olama-family-marker' + (selected ? ' is-selected' : '') + (family.location_status === 'needs_review' ? ' needs-review' : '');
        return L.divIcon({ className: '', html: '<span class="' + classes + '" style="--marker-color:' + safeColor(color) + '"><b>' + Number(family.student_count) + '</b><i aria-hidden="true">' + (family.location_status === 'needs_review' ? '!' : '') + '</i></span>', iconSize: [34, 34], iconAnchor: [17, 17] });
    }

    function markerClicked(family, marker) {
        if (activePolygonDrawer && activePolygonDrawer.enabled()) return;
        if (isSelectable(family)) toggleFamily(family.family_uid);
        marker.bindPopup(buildPopup(family), { maxWidth: 290 }).openPopup();
    }

    function buildPopup(family) {
        var box = document.createElement('div');
        var title = document.createElement('strong'); title.textContent = family.family_name; box.appendChild(title);
        [
            'Family: ' + family.oracle_family_id,
            'Area: ' + (family.region_name || '—'),
            'Students: ' + family.student_count,
            'Location: ' + family.location_status,
            family.assignment ? 'Group: ' + family.assignment.group_name : 'Group: Unassigned',
            family.assignment ? 'Bus / trip: ' + (family.assignment.bus_number || family.assignment.bus_id) + ' / ' + family.assignment.trip_number : ''
        ].filter(Boolean).forEach(function (text) { var p = document.createElement('div'); p.textContent = text; box.appendChild(p); });
        var action = document.createElement('button'); action.type = 'button'; action.className = 'button button-small';
        action.textContent = state.selectedFamilyUids.has(family.family_uid) ? 'Remove from Current Group' : 'Add to Current Group';
        action.disabled = !isSelectable(family);
        action.addEventListener('click', function () { toggleFamily(family.family_uid); marker.closePopup(); });
        box.appendChild(action);
        var maps = document.createElement('a'); maps.className = 'button button-small'; maps.target = '_blank'; maps.rel = 'noopener';
        maps.href = 'https://www.google.com/maps?q=' + encodeURIComponent(family.latitude + ',' + family.longitude); maps.textContent = 'Google Maps'; box.appendChild(maps);
        return box;
    }

    function isSelectable(family) {
        return !!state.editingGroupId || state.unsavedChanges
            ? (!family.assignment || Number(family.assignment.group_id) === Number(state.editingGroupId)) && Number(family.student_count) > 0
            : false;
    }

    function toggleFamily(uid) {
        var family = state.families.find(function (f) { return f.family_uid === uid; });
        if (!family || !isSelectable(family)) return;
        if (state.selectedFamilyUids.has(uid)) state.selectedFamilyUids.delete(uid); else state.selectedFamilyUids.add(uid);
        state.unsavedChanges = true;
        renderMarkers(); updatePanel();
    }

    function updatePanel() {
        var selected = state.families.filter(function (f) { return state.selectedFamilyUids.has(f.family_uid); });
        var students = selected.reduce(function (sum, f) { return sum + Number(f.student_count); }, 0);
        var bus = state.buses.find(function (b) { return Number(b.id) === Number(el('group-bus').value); });
        var capacity = bus ? Number(bus.effective_capacity) : 0;
        var remaining = capacity - students;
        var usage = capacity ? students / capacity * 100 : 0;
        el('metric-selected').textContent = selected.length;
        el('group-family-count').textContent = selected.length;
        el('group-student-count').textContent = students;
        el('group-capacity').textContent = capacity;
        el('group-remaining').textContent = remaining;
        el('group-usage').textContent = usage.toFixed(1) + '%';
        var status = el('group-capacity-status');
        status.className = 'olama-capacity-status ' + (students > capacity ? 'is-exceeded' : usage > 85 ? 'is-near' : 'is-normal');
        status.textContent = students > capacity ? tr('capacityExceeded') + ' ' + (students - capacity) : usage > 85 ? tr('nearCapacity') : tr('withinCapacity');
        var areas = Array.from(new Set(selected.map(function (f) { return f.region_name || 'Unclassified'; })));
        el('group-included-areas').textContent = 'Included areas: ' + (areas.join(', ') || '—') + (areas.length > 1 ? ' — multiple areas' : '');
        var list = el('group-family-list'); list.replaceChildren();
        selected.forEach(function (family) {
            var li = document.createElement('li'); li.append(document.createTextNode(family.family_name + ' (' + family.student_count + ') '));
            var remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.setAttribute('aria-label', 'Remove');
            remove.addEventListener('click', function () { toggleFamily(family.family_uid); }); li.appendChild(remove); list.appendChild(li);
        });
        var valid = !state.readOnly && selected.length > 0 && el('group-name').value.trim() && bus && bus.assignable && el('group-trip').value && students <= capacity && !state.requestInProgress;
        el('group-save').disabled = !valid || !olamaPlanner.canManage;
    }

    function startNew() {
        if (!discardOkay()) return;
        clearEditor();
        state.unsavedChanges = true;
        el('group-panel-title').textContent = 'New Geographic Group';
        updatePanel();
    }

    function clearEditor() {
        stopAreaDrawing();
        state.selectedFamilyUids.clear(); state.editingGroupId = 0; state.selectedBusId = 0; state.selectedTripNumber = 0; state.unsavedChanges = false; state.readOnly = false; state.drawnPolygon = null;
        drawnLayer.clearLayers(); el('group-name').value = ''; el('group-area').value = ''; el('group-bus').value = ''; el('group-trip').replaceChildren(new Option('Select Trip', '')); el('group-color').value = '#2563eb'; el('group-notes').value = ''; el('group-error').textContent = '';
        ['group-name','group-area','group-bus','group-trip','group-color','group-notes','planner-draw'].forEach(function (id) { el(id).disabled = false; });
        renderMarkers(); updatePanel();
    }

    function editGroup(id, readonly) {
        if (!discardOkay()) return;
        api('planning/groups/' + id).then(function (group) {
            clearEditor(); state.editingGroupId = group.id; state.selectedBusId = group.bus_id; state.selectedTripNumber = group.trip_number; state.readOnly = readonly;
            group.families.forEach(function (f) { state.selectedFamilyUids.add(f.family_uid); });
            el('group-name').value = group.group_name; el('group-area').value = group.major_area_id || ''; el('group-bus').value = group.bus_id; el('group-color').value = safeColor(group.color); el('group-notes').value = group.notes || '';
            el('group-panel-title').textContent = (readonly ? 'View: ' : 'Edit: ') + group.group_name;
            if (group.polygon_geojson) { state.drawnPolygon = group.polygon_geojson; L.geoJSON(group.polygon_geojson, { style: { color: group.color } }).eachLayer(function (layer) { drawnLayer.addLayer(layer); }); }
            ['group-name','group-area','group-bus','group-trip','group-color','group-notes','planner-draw'].forEach(function (id) { el(id).disabled = readonly; });
            loadTrips(group.trip_number).then(function () { state.unsavedChanges = !readonly; updatePanel(); renderMarkers(); });
        }).catch(showError);
    }

    function loadTrips(selected) {
        var busId = Number(el('group-bus').value || 0);
        state.selectedBusId = busId;
        var select = el('group-trip'); select.replaceChildren(new Option('Select Trip', ''));
        if (!busId) { updatePanel(); return Promise.resolve(); }
        return api('planning/trip-slots?academic_year_id=' + state.academicYearId + '&direction=' + state.direction + '&bus_id=' + busId).then(function (rows) {
            (rows[0] ? rows[0].slots : []).forEach(function (slot) {
                var own = slot.group && Number(slot.group.id) === Number(state.editingGroupId);
                var option = new Option((state.direction === 'morning' ? 'Morning' : 'Afternoon') + ' Trip ' + slot.trip_number + ' — ' + (slot.status === 'available' || own ? tr('available') : tr(slot.status)) + (slot.group && !own ? ': ' + slot.group.group_name : ''), slot.trip_number);
                option.disabled = slot.status !== 'available' && !own; select.appendChild(option);
            });
            select.value = selected || ''; state.selectedTripNumber = Number(select.value || 0); updatePanel();
        }).catch(showError);
    }

    function saveGroup() {
        if (state.requestInProgress || el('group-save').disabled) return;
        state.requestInProgress = true; setDisabled(true);
        var payload = {
            academic_year_id: state.academicYearId, direction: state.direction, trip_number: Number(el('group-trip').value),
            group_name: el('group-name').value.trim(), bus_id: Number(el('group-bus').value), major_area_id: Number(el('group-area').value || 0),
            color: el('group-color').value, notes: el('group-notes').value, polygon_geojson: state.drawnPolygon,
            family_uids: Array.from(state.selectedFamilyUids)
        };
        var path = 'planning/groups' + (state.editingGroupId ? '/' + state.editingGroupId : '');
        api(path, { method: state.editingGroupId ? 'PUT' : 'POST', body: payload }).then(function () {
            state.unsavedChanges = false; clearEditor(); state.mapDataCache = {}; return load(true);
        }).then(function () { el('group-error').textContent = tr('saved'); }).catch(showError).finally(function () { state.requestInProgress = false; setDisabled(false); updatePanel(); });
    }

    function lifecycle(id, action) {
        if (action === 'archive' && !window.confirm(tr('confirmArchive'))) return;
        api('planning/groups/' + id + '/' + action, { method: 'POST' }).then(function () { state.mapDataCache = {}; return load(true); }).catch(showError);
    }

    function renderGroupsTable() {
        var body = el('planner-groups-body'); body.replaceChildren();
        if (!state.groups.length) { var empty = document.createElement('tr'); var cell = document.createElement('td'); cell.colSpan = 12; cell.textContent = tr('noGroups'); empty.appendChild(cell); body.appendChild(empty); return; }
        state.groups.forEach(function (group) {
            var row = document.createElement('tr');
            [group.group_name, group.major_area_name || '—', group.direction, group.bus_number || group.bus_id, group.trip_number, group.family_count, group.student_count, group.capacity_snapshot, group.remaining_seats, group.status, group.updated_at].forEach(function (value) { var td = document.createElement('td'); td.textContent = value; row.appendChild(td); });
            var actions = document.createElement('td');
            addAction(actions, tr('view'), function () { editGroup(group.id, true); });
            if (group.status === 'draft' && olamaPlanner.canManage) addAction(actions, tr('edit'), function () { editGroup(group.id, false); });
            if (group.status === 'draft' && olamaPlanner.canApprove) addAction(actions, tr('approve'), function () { lifecycle(group.id, 'approve'); });
            if (group.status === 'approved' && olamaPlanner.canApprove) addAction(actions, tr('revert'), function () { lifecycle(group.id, 'revert'); });
            if (olamaPlanner.canApprove) addAction(actions, tr('archive'), function () { lifecycle(group.id, 'archive'); });
            row.appendChild(actions); body.appendChild(row);
        });
    }

    function addAction(parent, text, callback) { var button = document.createElement('button'); button.type = 'button'; button.className = 'button button-small'; button.textContent = text; button.addEventListener('click', callback); parent.appendChild(button); }

    function renderGroupOverlays() {
        overlayLayer.clearLayers();
        state.groups.forEach(function (group) {
            if (!group.polygon_geojson) return;
            L.geoJSON(group.polygon_geojson, { style: { color: safeColor(group.color), weight: 2, fillOpacity: .08 } }).bindTooltip(group.group_name).addTo(overlayLayer);
        });
    }

    function updateMetrics(meta) { el('metric-valid').textContent = meta.family_count; el('metric-assigned').textContent = meta.assigned_count; el('metric-unassigned').textContent = meta.unassigned_count; el('metric-invalid').textContent = meta.invalid_location_count; }
    function fitMap(school) { var points = visibleFamilies().map(function (f) { return [f.latitude, f.longitude]; }); points.push([school.latitude, school.longitude]); if (points.length > 1) map.fitBounds(points, { padding: [25, 25], maxZoom: 15 }); else map.setView(points[0], 12); }
    function updateFilterTrips() { var bus = state.buses.find(function (b) { return Number(b.id) === Number(el('planner-filter-bus').value); }); var select = el('planner-filter-trip'); select.replaceChildren(new Option('All trips', '')); if (!bus) return; var count = state.direction === 'morning' ? bus.morning_trip_count : bus.afternoon_trip_count; for (var i = 1; i <= count; i++) select.appendChild(new Option('Trip ' + i, i)); }
    function pointInPolygon(point, ring) { var x = point[1], y = point[0], inside = false; for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) { var xi = ring[i][0], yi = ring[i][1], xj = ring[j][0], yj = ring[j][1]; var intersect = ((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / ((yj - yi) || Number.EPSILON) + xi); if (intersect) inside = !inside; } return inside; }
    function safeColor(color) { return /^#[0-9a-f]{6}$/i.test(color || '') ? color : '#2563eb'; }
    function setDisabled(disabled) { root.classList.toggle('is-loading', disabled); }
    function showError(error) { el('group-error').textContent = error.message || String(error); el('group-error').className = 'olama-planner-message is-error'; }
    function discardOkay() { return !state.unsavedChanges || window.confirm(tr('discard')); }

    function startAreaDrawing() {
        if (activePolygonDrawer && activePolygonDrawer.enabled()) {
            activePolygonDrawer.disable();
            return;
        }
        if (!state.unsavedChanges && !state.editingGroupId) startNew();

        // A small pointer movement on the first click makes Leaflet treat it as
        // a pan. Area selection must own the pointer until drawing is finished.
        restoreMapDragging = map.dragging.enabled();
        if (restoreMapDragging) map.dragging.disable();
        map.getContainer().classList.add('is-area-drawing');
        el('planner-draw').classList.add('button-primary');
        el('planner-draw').setAttribute('aria-pressed', 'true');

        activePolygonDrawer = new L.Draw.Polygon(map, {
            allowIntersection: false,
            showArea: true,
            shapeOptions: { color: el('group-color').value }
        });
        activePolygonDrawer.enable();
    }

    function stopAreaDrawing() {
        if (activePolygonDrawer && activePolygonDrawer.enabled()) {
            activePolygonDrawer.disable();
            return; // draw:drawstop performs the shared cleanup synchronously.
        }
        activePolygonDrawer = null;
        map.getContainer().classList.remove('is-area-drawing');
        el('planner-draw').classList.remove('button-primary');
        el('planner-draw').setAttribute('aria-pressed', 'false');
        if (restoreMapDragging && !map.dragging.enabled()) map.dragging.enable();
        restoreMapDragging = false;
    }

    el('planner-new-group').addEventListener('click', startNew);
    el('group-cancel').addEventListener('click', function () { if (discardOkay()) clearEditor(); });
    el('group-save').addEventListener('click', saveGroup);
    el('group-bus').addEventListener('change', function () { state.unsavedChanges = true; loadTrips(0); });
    ['group-name','group-area','group-trip','group-color','group-notes'].forEach(function (id) { el(id).addEventListener('input', function () { state.unsavedChanges = true; updatePanel(); }); });
    ['planner-area','planner-assignment','planner-location-status','planner-filter-trip'].forEach(function (id) { el(id).addEventListener('change', function () { renderMarkers(); }); });
    el('planner-filter-bus').addEventListener('change', function () { updateFilterTrips(); renderMarkers(); });
    el('planner-search').addEventListener('input', function () { renderMarkers(); });
    el('planner-reset').addEventListener('click', function () { ['planner-area','planner-filter-bus','planner-filter-trip'].forEach(function (id) { el(id).value = ''; }); el('planner-assignment').value = 'all'; el('planner-location-status').value = 'all'; el('planner-search').value = ''; updateFilterTrips(); renderMarkers(); });
    el('planner-year').addEventListener('change', function () { if (!discardOkay()) { this.value = state.academicYearId; return; } state.academicYearId = Number(this.value); clearEditor(); load(false); });
    el('planner-direction').addEventListener('change', function () { if (!discardOkay()) { this.value = state.direction; return; } state.direction = this.value; clearEditor(); load(false); });
    el('planner-refresh-map').addEventListener('click', function () { state.mapDataCache = {}; load(true); });
    el('planner-refresh-areas').addEventListener('click', function () { api('core/refresh-areas', { method: 'POST' }).then(function () { state.mapDataCache = {}; return load(true); }).catch(showError); });
    el('planner-draw').setAttribute('aria-pressed', 'false');
    el('planner-draw').addEventListener('click', startAreaDrawing);
    map.on(L.Draw.Event.DRAWSTOP, stopAreaDrawing);
    map.on(L.Draw.Event.CREATED, function (event) {
        drawnLayer.clearLayers(); drawnLayer.addLayer(event.layer); state.drawnPolygon = event.layer.toGeoJSON().geometry; state.unsavedChanges = true;
        var ring = state.drawnPolygon.coordinates[0];
        visibleFamilies().forEach(function (family) { if (isSelectable(family) && pointInPolygon([family.latitude, family.longitude], ring)) state.selectedFamilyUids.add(family.family_uid); });
        renderMarkers(); updatePanel();
    });
    window.addEventListener('beforeunload', function (event) { if (state.unsavedChanges) { event.preventDefault(); event.returnValue = ''; } });
    load(true);
}());
