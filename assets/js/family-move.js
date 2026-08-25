(function ($) {
    'use strict';

    var config = window.olamaFamilyMove || {};
    var context = {trips: []};
    var sides = {
        left: {tripId: 0, trip: null, selected: {}, activeUid: '', search: '', map: null, markers: {}, bounds: []},
        right: {tripId: 0, trip: null, selected: {}, activeUid: '', search: '', map: null, markers: {}, bounds: []}
    };
    var pending = null;
    var undoMove = null;

    function api(path, options) {
        options = options || {};
        options.headers = $.extend({'X-WP-Nonce': config.restNonce, 'Content-Type': 'application/json'}, options.headers || {});
        return fetch(config.restUrl + path, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok) {
                    var error = new Error(body.message || 'Request failed');
                    error.code = body.code || '';
                    throw error;
                }
                return body;
            });
        });
    }

    function esc(value) { return $('<div>').text(value == null ? '' : value).html(); }
    function pane(side) { return $('.olama-family-move-pane[data-side="' + side + '"]'); }
    function other(side) { return side === 'left' ? 'right' : 'left'; }
    function feedback(message, error) { $('#family-move-feedback').text(message || '').toggleClass('is-error', !!error).toggleClass('is-success', !error && !!message); }
    function tripById(id) { return (context.trips || []).find(function (trip) { return Number(trip.id) === Number(id); }); }
    function selectedFamilies(side) {
        var selected = sides[side].selected;
        return ((sides[side].trip && sides[side].trip.families) || []).filter(function (family) { return !!selected[family.family_uid]; });
    }
    function validCoordinates(item) { return item && item.location_status === 'valid' && Number.isFinite(Number(item.latitude)) && Number.isFinite(Number(item.longitude)); }
    function tripLabel(trip) {
        var bus = trip.bus_number ? 'Bus ' + trip.bus_number : 'Bus unassigned';
        return trip.name + ' · ' + bus + ' · ' + trip.student_count + ' students · ' + trip.status;
    }

    function destroyMap(side) {
        if (sides[side].map) sides[side].map.remove();
        sides[side].map = null;
        sides[side].markers = {};
        sides[side].bounds = [];
    }

    function chooseTrips() {
        var trips = context.trips || [];
        if (!tripById(sides.left.tripId)) sides.left.tripId = trips.length ? Number(trips[0].id) : 0;
        var leftSummary = tripById(sides.left.tripId);
        var rightValid = tripById(sides.right.tripId);
        if (!rightValid || Number(sides.right.tripId) === Number(sides.left.tripId) || (leftSummary && rightValid.status !== leftSummary.status)) {
            var match = trips.find(function (trip) { return Number(trip.id) !== Number(sides.left.tripId) && (!leftSummary || trip.status === leftSummary.status); });
            sides.right.tripId = match ? Number(match.id) : 0;
        }
    }

    function loadContext(message) {
        cancelPending();
        feedback('Loading family move workspace…');
        var query = '?academic_year_id=' + encodeURIComponent($('#family-move-year').val()) + '&direction=' + encodeURIComponent($('#family-move-direction').val());
        return api('family-move' + query).then(function (response) {
            context = response;
            chooseTrips();
            return Promise.all([loadTrip('left', sides.left.tripId), loadTrip('right', sides.right.tripId)]);
        }).then(function () {
            feedback(message || '');
        }).catch(function (error) {
            feedback(error.message, true);
            renderEmptyWorkspace();
        });
    }

    function loadTrip(side, id) {
        destroyMap(side);
        sides[side].tripId = Number(id) || 0;
        sides[side].trip = null;
        sides[side].selected = {};
        sides[side].activeUid = '';
        sides[side].search = '';
        if (!sides[side].tripId) {
            renderPane(side);
            return Promise.resolve();
        }
        pane(side).html('<div class="olama-family-move-pane-loading">Loading trip…</div>');
        return api('family-move/trips/' + sides[side].tripId).then(function (trip) {
            sides[side].trip = trip;
            renderPane(side);
        }).catch(function (error) {
            pane(side).html('<div class="olama-family-move-pane-loading">' + esc(error.message) + '</div>');
            throw error;
        });
    }

    function tripOptions(side) {
        var current = Number(sides[side].tripId);
        var opposite = Number(sides[other(side)].tripId);
        var options = '<option value="">Select a trip</option>';
        (context.trips || []).forEach(function (trip) {
            options += '<option value="' + trip.id + '" ' + (Number(trip.id) === current ? 'selected' : '') + ' ' + (Number(trip.id) === opposite ? 'disabled' : '') + '>' + esc(tripLabel(trip)) + '</option>';
        });
        return options;
    }

    function renderPane(side) {
        destroyMap(side);
        var state = sides[side], trip = state.trip;
        var heading = side === 'left' ? 'From / Left trip' : 'To / Right trip';
        var select = '<div class="olama-family-move-trip-select"><label>' + heading + '<select class="family-move-trip-select" data-side="' + side + '">' + tripOptions(side) + '</select></label></div>';
        if (!trip) {
            pane(side).html(select + '<div class="olama-family-move-pane-loading">' + ((context.trips || []).length ? 'Select a trip.' : 'Create at least two compatible trips first.') + '</div>');
            updateControls();
            return;
        }
        var used = Number(trip.student_count || 0), capacity = Number(trip.bus_capacity || 0), remaining = capacity ? capacity - used : 0;
        var busExcess = capacity ? Math.max(0, used - capacity) : 0;
        var summary = '<div class="olama-family-move-trip-summary">' +
            '<div><span>Families</span><strong>' + Number(trip.family_count || 0) + '</strong></div>' +
            '<div><span>Students</span><strong>' + used + '</strong></div>' +
            '<div><span>Bus capacity</span><strong>' + (capacity || '—') + '</strong></div>' +
            '<div class="' + (remaining <= 0 ? 'is-full' : '') + '"><span>Remaining</span><strong>' + (capacity ? remaining : '—') + '</strong></div></div>';
        var capacityWarning = busExcess ? '<div class="olama-family-move-capacity-warning" role="alert"><span class="dashicons dashicons-warning" aria-hidden="true"></span><strong>Bus capacity exceeded:</strong> this trip is ' + busExcess + ' student' + (busExcess === 1 ? '' : 's') + ' over its ' + capacity + '-seat limit.</div>' : '';
        var map = '<div class="olama-family-move-map-head"><div><strong>Trip map</strong><small> Academy and family stops</small></div><button type="button" class="button olama-family-move-fit" data-side="' + side + '">Fit route</button></div><div class="olama-family-move-map" id="family-move-map-' + side + '" aria-label="' + esc(trip.name) + ' map"></div>';
        var readonly = config.canManage ? '' : '<div class="olama-family-move-readonly">You can review this workspace, but you do not have permission to move families.</div>';
        var queue = '<div class="olama-family-move-queue-head"><strong>Family queue</strong><small>' + trip.family_count + ' families</small></div>' +
            '<div class="olama-family-move-queue-toolbar"><input type="search" class="olama-family-move-search" data-side="' + side + '" placeholder="Search family or student"><button type="button" class="button olama-family-move-clear" data-side="' + side + '">Clear selection</button></div>' +
            '<ul class="olama-family-move-family-list" data-side="' + side + '"></ul><aside class="olama-family-move-detail" data-side="' + side + '"><p>Select a family circle to view details.</p></aside>' +
            '<div class="olama-family-move-pane-actions"><button type="button" class="button button-primary family-move-direction-button" data-from="' + side + '" disabled>' + (side === 'left' ? 'Move selected →' : '← Move selected') + '</button><button type="button" class="button family-move-undo"' + (undoMove ? '' : ' hidden') + '>Undo last move</button></div>';
        pane(side).html(select + summary + capacityWarning + map + readonly + queue);
        renderFamilyList(side);
        setTimeout(function () { renderMap(side); }, 0);
        updateControls();
    }

    function familyMatches(family, search) {
        if (!search) return true;
        var text = [family.oracle_family_id, family.family_name].concat(family.area_names || []).concat((family.students || []).map(function (student) { return student.name + ' ' + student.grade + ' ' + student.section; })).join(' ').toLowerCase();
        return text.indexOf(search.toLowerCase()) !== -1;
    }

    function renderFamilyList(side) {
        var state = sides[side], list = pane(side).find('.olama-family-move-family-list');
        if (!list.length || !state.trip) return;
        var families = (state.trip.families || []).filter(function (family) { return familyMatches(family, state.search); });
        var html = families.map(function (family) {
            var selected = !!state.selected[family.family_uid];
            var cls = 'olama-family-move-family' + (selected ? ' is-selected' : '') + (!validCoordinates(family) ? ' is-missing' : '') + (state.activeUid === family.family_uid ? ' is-map-active' : '');
            return '<li class="' + cls + '" draggable="' + (config.canManage ? 'true' : 'false') + '" tabindex="0" role="checkbox" aria-checked="' + (selected ? 'true' : 'false') + '" data-side="' + side + '" data-family="' + esc(family.family_uid) + '" title="' + esc(family.family_name || family.oracle_family_id) + '"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><strong>' + esc(family.oracle_family_id || family.family_name) + '</strong><small>' + family.student_count + ' student' + (family.student_count === 1 ? '' : 's') + (!validCoordinates(family) ? '<br>Location missing' : '') + '</small></li>';
        }).join('');
        list.html(html || '<li class="olama-family-move-empty">No matching families.</li>');
    }

    function familyByUid(side, uid) { return ((sides[side].trip && sides[side].trip.families) || []).find(function (family) { return family.family_uid === uid; }); }
    function showDetails(side, uid) {
        var family = familyByUid(side, uid), detail = pane(side).find('.olama-family-move-detail');
        if (!family) return detail.html('<p>Select a family circle to view details.</p>');
        var students = (family.students || []).map(function (student) { return '<li><strong>' + esc(student.name) + '</strong> · ' + esc(student.grade || '—') + ' / ' + esc(student.section || '—') + '</li>'; }).join('');
        detail.html('<h4>Family #' + esc(family.oracle_family_id || '—') + ' · ' + esc(family.family_name || 'Family') + '</h4><div class="olama-family-move-detail-grid"><div><span>Planning area</span><strong>' + esc((family.area_names || []).join(', ') || '—') + '</strong></div><div><span>Location</span><strong>' + (validCoordinates(family) ? 'Available' : 'Missing or invalid') + '</strong></div><ul class="olama-family-move-students">' + students + '</ul></div>');
    }

    function markerIcon(label, school, selected) {
        return L.divIcon({className:'olama-family-move-marker-wrap', html:'<span class="olama-family-move-marker ' + (school ? 'is-school ' : '') + (selected ? 'is-selected' : '') + '">' + esc(label) + '</span>', iconSize:[34,34], iconAnchor:[17,17], popupAnchor:[0,-18]});
    }

    function renderMap(side) {
        var state = sides[side], trip = state.trip, element = document.getElementById('family-move-map-' + side);
        if (!trip || !element || typeof L === 'undefined') return;
        var map = L.map(element, {scrollWheelZoom:false, doubleClickZoom:false, boxZoom:false, touchZoom:false, keyboard:false, zoomControl:true}).setView([31.9539,35.9106], 12);
        L.tileLayer(config.tileUrl, {attribution:config.tileAttribution,maxZoom:19}).addTo(map);
        state.map = map; state.markers = {}; state.bounds = [];
        var line = [];
        (trip.queue || []).forEach(function (node) {
            if (!validCoordinates(node)) return;
            var school = node.node_type === 'school', family = school ? null : familyByUid(side, String(node.family_uid));
            var selected = family && !!state.selected[family.family_uid];
            var label = school ? 'S' : String(node.queue_position || '');
            var latlng = [Number(node.latitude), Number(node.longitude)];
            var popup = school ? '<strong>Academy</strong><br>' + (trip.direction === 'morning' ? 'Final stop' : 'Starting stop') : '<strong>Family #' + esc(node.oracle_family_id || '—') + '</strong><br>' + esc(node.family_name || '') + '<br><small>' + Number(node.student_count || 0) + ' students</small>';
            var marker = L.marker(latlng, {icon:markerIcon(label,school,selected)}).addTo(map).bindPopup(popup);
            if (family) marker.on('click', function () { state.activeUid = family.family_uid; showDetails(side, family.family_uid); renderFamilyList(side); });
            if (family) state.markers[family.family_uid] = {marker:marker,label:label};
            line.push(latlng); state.bounds.push(latlng);
        });
        if (line.length > 1) L.polyline(line, {color:side === 'left' ? '#d97706' : '#2563eb',weight:3,opacity:.78,dashArray:'7 8'}).addTo(map);
        fitMap(side);
        setTimeout(function () { if (state.map) state.map.invalidateSize(); }, 80);
    }

    function fitMap(side) {
        var state = sides[side];
        if (!state.map) return;
        if (state.bounds.length > 1) state.map.fitBounds(state.bounds, {padding:[28,28],maxZoom:15});
        else if (state.bounds.length === 1) state.map.setView(state.bounds[0], 14);
    }

    function updateMarkerSelection(side, uid) {
        var entry = sides[side].markers[uid];
        if (!entry) return;
        entry.marker.setIcon(markerIcon(entry.label, false, !!sides[side].selected[uid]));
    }

    function toggleFamily(side, uid, force) {
        if (!config.canManage) { sides[side].activeUid = uid; showDetails(side, uid); renderFamilyList(side); return; }
        var state = sides[side], selected = force === undefined ? !state.selected[uid] : !!force;
        if (selected) state.selected[uid] = true; else delete state.selected[uid];
        state.activeUid = uid;
        showDetails(side, uid);
        renderFamilyList(side);
        updateMarkerSelection(side, uid);
        cancelPending();
        updateControls();
    }

    function clearSelection(side) {
        sides[side].selected = {};
        sides[side].activeUid = '';
        renderFamilyList(side); showDetails(side, '');
        Object.keys(sides[side].markers).forEach(function (uid) { updateMarkerSelection(side, uid); });
        cancelPending(); updateControls();
    }

    function validation(fromSide) {
        var toSide = other(fromSide), source = sides[fromSide].trip, destination = sides[toSide].trip, families = selectedFamilies(fromSide);
        if (!source || !destination || !families.length) return {valid:false,message:'Select at least one family and two trips.'};
        if (source.status !== destination.status) return {valid:false,message:'Both trips must have the same draft or published status.'};
        if (!destination.bus_id || !Number(destination.bus_capacity)) return {valid:false,message:'The destination needs an assigned bus with usable capacity.'};
        var destinationAreas = {}; (destination.area_ids || []).forEach(function (id) { destinationAreas[Number(id)] = true; });
        var missingAreaNames = [];
        families.forEach(function (family) {
            (family.area_ids || []).forEach(function (id, index) {
                var name = (family.area_names || [])[index] || ('Area #' + id);
                if (!destinationAreas[Number(id)] && missingAreaNames.indexOf(name) === -1) missingAreaNames.push(name);
            });
        });
        var students = families.reduce(function (total, family) { return total + Number(family.student_count || 0); }, 0);
        var after = Number(destination.student_count || 0) + students;
        var warnings = [];
        if (after > Number(destination.bus_capacity || 0)) warnings.push('The move exceeds the destination bus capacity by ' + (after - Number(destination.bus_capacity)) + ' students.');
        if (after > Number(destination.planning_limit || 0)) warnings.push('The move exceeds the destination planning limit by ' + (after - Number(destination.planning_limit)) + ' students.');
        return {valid:true,message:warnings.length ? 'Ready to move with capacity warnings.' : 'Ready to move',warnings:warnings,families:families,students:students,after:after,remaining:Number(destination.bus_capacity)-after,missingAreaNames:missingAreaNames};
    }

    function stageMove(fromSide) {
        var check = validation(fromSide), toSide = other(fromSide);
        if (check.missingAreaNames && check.missingAreaNames.length) {
            window.alert('Planning area warning\n\nThe selected families use planning areas that are different from the destination trip:\n\u2022 ' + check.missingAreaNames.join('\n\u2022 ') + '\n\nThe move is allowed. These planning areas will be added to the destination trip when you apply the move.');
        }
        pending = {fromSide:fromSide,toSide:toSide,sourceTripId:sides[fromSide].tripId,destinationTripId:sides[toSide].tripId,familyUids:selectedFamilies(fromSide).map(function (family) { return family.family_uid; }),check:check};
        var names = selectedFamilies(fromSide).map(function (family) { return '#' + (family.oracle_family_id || family.family_name); }).join(', ');
        var resultClass = !check.valid ? 'is-invalid' : (check.warnings && check.warnings.length ? 'is-warning' : 'is-valid');
        var resultMessage = check.valid ? 'Destination after move: ' + check.after + '/' + sides[toSide].trip.bus_capacity + ' seats · ' + check.remaining + ' remaining. Routes will need recalculation.' : check.message;
        if (check.warnings && check.warnings.length) resultMessage += ' Warning: ' + check.warnings.join(' ');
        $('#family-move-preview').html('<p><strong>' + pending.familyUids.length + ' ' + (pending.familyUids.length === 1 ? 'family' : 'families') + ' · ' + Number(check.students || 0) + ' students</strong><br>' + esc(names) + '<br><span class="' + resultClass + '">' + esc(resultMessage) + '</span></p>');
        $('#family-move-cancel').prop('disabled', false);
        $('#family-move-apply').prop('disabled', !check.valid || !config.canManage);
        return check.valid;
    }

    function cancelPending() {
        pending = null;
        $('#family-move-preview').html('<p>Select families, then drag them to the other trip or use a move button.</p>');
        $('#family-move-cancel,#family-move-apply').prop('disabled', true);
        $('.olama-family-move-pane').removeClass('is-drop-target is-invalid-target');
    }

    function updateControls() {
        $('.family-move-direction-button[data-from="left"]').prop('disabled', !config.canManage || !selectedFamilies('left').length || !sides.right.trip);
        $('.family-move-direction-button[data-from="right"]').prop('disabled', !config.canManage || !selectedFamilies('right').length || !sides.left.trip);
    }

    function applyMove(payload, isUndo) {
        feedback(isUndo ? 'Undoing family move…' : 'Applying family move…');
        $('#family-move-apply,.family-move-undo').prop('disabled', true);
        return api('family-move', {method:'POST',body:JSON.stringify(payload)}).then(function (response) {
            undoMove = isUndo ? null : {source_trip_id:payload.destination_trip_id,destination_trip_id:payload.source_trip_id,family_uids:payload.family_uids,reason:'Undo family move'};
            cancelPending();
            $('#family-move-reason').val('');
            $('.family-move-undo').prop('hidden', !undoMove).prop('disabled', false);
            return loadContext((isUndo ? 'Family move undone. ' : response.moved_family_count + ' families and ' + response.moved_student_count + ' students moved. ') + 'Affected routes need recalculation.');
        }).catch(function (error) {
            feedback(error.message, true);
            $('#family-move-apply').prop('disabled', !(pending && pending.check.valid));
            $('.family-move-undo').prop('disabled', false);
        });
    }

    function applyPending() {
        if (!pending || !pending.check.valid) return;
        applyMove({source_trip_id:pending.sourceTripId,destination_trip_id:pending.destinationTripId,family_uids:pending.familyUids,reason:$('#family-move-reason').val()}, false);
    }

    function renderEmptyWorkspace() {
        destroyMap('left'); destroyMap('right');
        $('.olama-family-move-pane').html('<div class="olama-family-move-pane-loading">Unable to load trips.</div>');
    }

    $(function () {
        if (!$('#olama-family-move').length) return;
        loadContext();
        $('#family-move-year,#family-move-direction').on('change', function () { sides.left.tripId=0;sides.right.tripId=0;undoMove=null;$('.family-move-undo').prop('hidden',true);loadContext(); });
        $('#family-move-refresh').on('click', function () { loadContext('Trips refreshed.'); });
        $(document).on('change','.family-move-trip-select',function(){var side=$(this).data('side');cancelPending();loadTrip(side,this.value).then(function(){renderPane(other(side));}).catch(function(error){feedback(error.message,true);});});
        $(document).on('input','.olama-family-move-search',function(){var side=$(this).data('side');sides[side].search=this.value;renderFamilyList(side);});
        $(document).on('click','.olama-family-move-clear',function(){clearSelection($(this).data('side'));});
        $(document).on('click','.olama-family-move-family',function(){toggleFamily($(this).data('side'),String($(this).data('family')));});
        $(document).on('keydown','.olama-family-move-family',function(event){if(event.key==='Enter'||event.key===' '){event.preventDefault();$(this).trigger('click');}});
        $(document).on('click','.olama-family-move-fit',function(){fitMap($(this).data('side'));});
        $(document).on('click','.family-move-direction-button',function(){stageMove($(this).data('from'));});
        $(document).on('dragstart','.olama-family-move-family',function(event){var side=$(this).data('side'),uid=String($(this).data('family'));if(!sides[side].selected[uid]){clearSelection(side);toggleFamily(side,uid,true);}event.originalEvent.dataTransfer.effectAllowed='move';event.originalEvent.dataTransfer.setData('text/plain',side);});
        $(document).on('dragover','.olama-family-move-pane',function(event){if(!config.canManage)return;event.preventDefault();var target=$(this).data('side'),from=event.originalEvent.dataTransfer.getData('text/plain')||other(target);if(from===target)return;var valid=validation(from).valid;$(this).toggleClass('is-drop-target',valid).toggleClass('is-invalid-target',!valid);event.originalEvent.dataTransfer.dropEffect=valid?'move':'none';});
        $(document).on('dragleave','.olama-family-move-pane',function(){$(this).removeClass('is-drop-target is-invalid-target');});
        $(document).on('drop','.olama-family-move-pane',function(event){event.preventDefault();var target=$(this).data('side'),from=event.originalEvent.dataTransfer.getData('text/plain');$('.olama-family-move-pane').removeClass('is-drop-target is-invalid-target');if(from&&from!==target)stageMove(from);});
        $('#family-move-cancel').on('click',cancelPending);
        $('#family-move-apply').on('click',applyPending);
        $(document).on('click','.family-move-undo',function(){if(undoMove)applyMove(undoMove,true);});
    });
})(jQuery);
