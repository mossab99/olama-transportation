(function ($) {
    function t(key) {
        var configured = window.olamaTransportation && olamaTransportation.i18n && olamaTransportation.i18n[key];
        return configured || (typeof window.olamaTransportationTranslate === 'function' ? window.olamaTransportationTranslate(key) : key);
    }

    function nonce() {
        return window.olamaTransportation ? olamaTransportation.nonce : '';
    }

    $(function () {
        $('.olama-datepicker').datepicker({
            dateFormat: 'dd-mm-yy',
            changeMonth: true,
            changeYear: true
        });

        $('#olama-bus-form').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $submitBtn = $form.find('button[type="submit"]');

            $submitBtn.prop('disabled', true).text(t('saving'));

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: $form.serialize() + '&action=olama_save_bus&nonce=' + nonce(),
                success: function (response) {
                    if (response.success) {
                        window.location.reload();
                        return;
                    }

                    alert(response.data);
                    $submitBtn.prop('disabled', false).text(t('saveBus'));
                },
                error: function () {
                    alert(t('communicationError'));
                    $submitBtn.prop('disabled', false).text(t('saveBus'));
                }
            });
        });

        initAssignments();
    });

    window.olamaOpenBusModal = function (bus) {
        var $modal = $('#olama-bus-modal');
        var $form = $('#olama-bus-form');

        if (bus) {
            $('#bus-modal-title').text(t('editBus'));
            $('#bus-id').val(bus.id);
            $('#bus-number').val(bus.bus_number);
            $('#bus-government-number').val(bus.government_number);
            $('#bus-driver-license-number').val(bus.driver_license_number);
            $('#bus-capacity').val(bus.passenger_capacity);
            $('#bus-core-capacity-warning').toggle(Number(bus.passenger_capacity || 0) === 0);
            $('#bus-planning-capacity').val(bus.planning_capacity || bus.passenger_capacity);
            $('#bus-morning-trip-count').val(bus.morning_trip_count || 2);
            $('#bus-afternoon-trip-count').val(bus.afternoon_trip_count || 3);
            $('#bus-license-expiry').val(bus.license_expiry_date ? olamaFormatDate(bus.license_expiry_date) : '');
            $('#bus-driver-id').val(bus.driver_user_id);
            $('#bus-engine-capacity').val(bus.engine_capacity);
            $('#bus-fuel-type').val(bus.fuel_type);
            $('#bus-status').val(bus.status);
        } else {
            $('#bus-modal-title').text(t('addNewBus'));
            if ($form.length) {
                $form[0].reset();
            }
            $('#bus-id').val('');
        }

        $modal.show();
    };

    window.olamaCloseBusModal = function () {
        $('#olama-bus-modal').hide();
    };

    window.olamaDeleteBus = function (id, busNumber) {
        if (!confirm(t('deleteBusConfirm') + ' (' + busNumber + ')')) {
            return;
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'olama_delete_bus',
                id: id,
                nonce: nonce()
            },
            success: function (response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(response.data);
                }
            }
        });
    };

    window.olamaFormatDate = function (dateStr) {
        if (!dateStr) {
            return '';
        }

        var date = new Date(dateStr);
        var day = ('0' + date.getDate()).slice(-2);
        var month = ('0' + (date.getMonth() + 1)).slice(-2);
        return day + '-' + month + '-' + date.getFullYear();
    };

    function initAssignments() {
        if (!$('#assignment-bus-filter').length) {
            return;
        }

        var context = {};

        function selectedTrip() {
            var value = $('#assignment-trip-filter').val().split(':');
            return value.length === 2 ? {direction: value[0], trip_number: value[1]} : null;
        }

        function loadTrips() {
            var busId = $('#assignment-bus-filter').val(), yearId = $('#assignment-year-filter').val(), $trip = $('#assignment-trip-filter');
            $('#assignment-content').hide(); $('#no-bus-selected').show();
            $trip.empty().prop('disabled', true).append($('<option>').val('').text(t('selectTrip')));
            if (!busId || !yearId) return;
            $.get(ajaxurl, {action:'olama_get_assignment_trips',bus_id:busId,academic_year_id:yearId,nonce:nonce()}).done(function(response){
                if (!response.success) return alert(response.data);
                (response.data || []).forEach(function(item){
                    var direction = t(item.direction) || item.direction;
                    $('<option>').val(item.direction + ':' + item.trip_number).text(direction + ' - ' + t('trip') + ' ' + item.trip_number + ' - ' + item.area_names).appendTo($trip);
                });
                if (!response.data.length) $('<option>').val('').text(t('noDefinedTrips')).appendTo($trip);
                $trip.prop('disabled', !response.data.length);
            });
        }

        function loadBusAssignments() {
            var trip = selectedTrip();
            if (!trip) { $('#assignment-content').hide(); $('#no-bus-selected').show(); return; }
            context = {bus_id:$('#assignment-bus-filter').val(),academic_year_id:$('#assignment-year-filter').val(),direction:trip.direction,trip_number:trip.trip_number};
            $('#no-bus-selected').hide(); $('#assignment-content').show();
            $.get(ajaxurl, $.extend({action:'olama_get_trip_area_students',nonce:nonce()}, context)).done(function(response){
                if (!response.success) return alert(response.data);
                updateCapacityInfo(response.data.capacity);
                renderUnassignedStudents(response.data.students || []);
                $('#assignment-area-list').text((response.data.areas || []).map(function(area){return area.name;}).join(', ') || '-');
                var $areas=$('#assignment-attach-area').empty().append($('<option>').val('').text(t('attachAnotherArea')));
                (response.data.available_areas || []).forEach(function(area){$('<option>').val(area.id).text(area.name).appendTo($areas);});
                $('#assignment-attach-area-btn').prop('disabled', !response.data.available_areas.length);
            });
        }

        function updateCapacityInfo(capacity) {
            var percentage = capacity.percentage;
            $('#capacity-text').text(capacity.assigned + '/' + capacity.total);
            $('#capacity-bar').css('width', percentage + '%');

            if (percentage >= 100) {
                $('#capacity-bar').css('background', '#dc3232');
            } else if (percentage >= 80) {
                $('#capacity-bar').css('background', '#f56e28');
            } else {
                $('#capacity-bar').css('background', '#0073aa');
            }
        }

        function renderUnassignedStudents(students) {
            var $tbody = $('#unassigned-students-body').empty();
            if (!students.length) {
                $tbody.append('<tr><td colspan="6">' + t('noAreaStudents') + '</td></tr>');
                return;
            }

            students.forEach(function (student) {
                $('<tr>')
                    .append($('<td>').append($('<input type="checkbox" class="student-checkbox" />').val(student.id).prop('checked', !!student.selected)))
                    .append($('<td>').text(student.student_name || ''))
                    .append($('<td>').text(student.student_uid || ''))
                    .append($('<td>').text(student.area_name || ''))
                    .append($('<td>').text(student.grade_name || ''))
                    .append($('<td>').text(student.section_name || ''))
                    .appendTo($tbody);
            });
            $('#select-all-students').prop('checked', students.length > 0 && students.every(function(student){return !!student.selected;}));
            updateAssignButton();
        }

        function updateAssignButton() {
            $('#assign-selected-btn').prop('disabled', !selectedTrip());
        }

        $('#assignment-bus-filter, #assignment-year-filter').on('change', loadTrips);
        $('#assignment-trip-filter').on('change', loadBusAssignments);
        $('#select-all-students').on('change', function () {
            $('.student-checkbox').prop('checked', $(this).prop('checked'));
            updateAssignButton();
        });
        $(document).on('change', '.student-checkbox', updateAssignButton);

        $('#assign-selected-btn').on('click', function () {
            var studentIds = $('.student-checkbox:checked').map(function () {
                return $(this).val();
            }).get();

            if (!confirm(t('saveSelectionConfirm'))) return;

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'olama_sync_trip_students',
                    bus_id: context.bus_id,
                    student_ids: studentIds,
                    academic_year_id: context.academic_year_id,
                    direction: context.direction,
                    trip_number: context.trip_number,
                    nonce: nonce()
                },
                success: function (response) {
                    if (response.success) {
                        alert(response.data.message);
                        loadBusAssignments();
                    } else {
                        alert(response.data);
                    }
                }
            });
        });

        $('#assignment-attach-area-btn').on('click', function () {
            var areaId=$('#assignment-attach-area').val(); if(!areaId) return;
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: $.extend({action:'olama_attach_trip_area',major_area_id:areaId,nonce:nonce()},context),
                success: function (response) {
                    if (response.success) {
                        alert(response.data.message);
                        loadBusAssignments();
                    } else {
                        alert(response.data);
                    }
                }
            });
        });

        loadTrips();
    }

    function rest(path, options) {
        options = options || {};
        options.headers = options.headers || {};
        options.headers['X-WP-Nonce'] = olamaTransportation.restNonce;
        if (options.body && !(options.body instanceof FormData)) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }
        return window.fetch(olamaTransportation.restUrl + path, options).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) {
                    throw new Error(payload.message || t('failed'));
                }
                return payload;
            });
        });
    }

    function objectFromForm(form) {
        var output = {};
        new FormData(form).forEach(function (value, key) {
            if (key.slice(-2) === '[]') {
                key = key.slice(0, -2);
                output[key] = output[key] || [];
                output[key].push(value);
            } else {
                output[key] = value;
            }
        });
        return output;
    }

    $(document).on('change', '.olama-year-navigation', function () {
        var url = new URL(window.location.href);
        url.searchParams.set('academic_year_id', $(this).val());
        window.location.href = url.toString();
    });

    $('#area-form').on('submit', function (event) {
        event.preventDefault();
        rest('areas', {method: 'POST', body: objectFromForm(this)})
            .then(function () { window.location.reload(); })
            .catch(function (error) { alert(error.message); });
    });

    $('#route-form').on('submit', function (event) {
        event.preventDefault();
        rest('routes', {method: 'POST', body: objectFromForm(this)})
            .then(function () { window.location.reload(); })
            .catch(function (error) { alert(error.message); });
    });

    $(document).on('change', '#route-trip-select', function () {
        var option = $(this).find('option:selected');
        $('#route-bus-id').val(option.data('bus-id') || '');
        $('#route-direction').val(option.data('direction') || '');
    });

    $(document).on('click', '.olama-optimize-route', function () {
        var id = $(this).data('id');
        var button = this, editor = document.getElementById('olama-route-editor');
        $(button).prop('disabled', true).text('Optimizing...');
        rest('routes/' + id + '/optimize', {method: 'POST'})
            .then(function (route) { routeEditorData=route; if (editor) { editor.hidden=false; renderRouteEditor(); editor.scrollIntoView({behavior:'smooth',block:'start'}); } })
            .catch(function (error) { if (editor) $('#olama-route-editor-status').text('Route optimization failed. The existing route has not been changed. '+error.message); else alert(error.message); })
            .finally(function () { $(button).prop('disabled', false).text('Optimize'); });
    });

    $(document).on('click', '#olama-optimize-current-route', function () {
        var button = this, id = $(button).data('id');
        if (!id) return;
        if (routeEditorData && routeEditorData.needs_recalculation) { $('#olama-route-editor-status').text('Rebuild the route from the current trip before optimizing.'); return; }
        $(button).prop('disabled', true).text('Optimizing...');
        rest('routes/' + id + '/optimize', {method:'POST'}).then(function(route){ routeEditorData=route; renderRouteEditor(); $('#olama-route-editor-status').text('Route optimized successfully.'); }).catch(function(error){ $('#olama-route-editor-status').text('Route optimization failed. The existing route has not been changed. ' + error.message); }).finally(function(){ if (routeEditorData && !routeEditorData.needs_recalculation) $(button).prop('disabled', false).text('Optimize Route'); });
    });

    var routeEditorData = null, routeEditorMap = null, routeEditorLayer = null;
    function renderRouteEditor() {
        var route = routeEditorData || {}, stops = route.stops || [], $list = $('#olama-route-stop-list');
        if (!$('#olama-route-summary').length) $('#olama-route-editor-status').before('<div id="olama-route-summary" class="olama-route-summary"></div>');
        $('#olama-route-summary').text('Stops: '+stops.length+' · Distance: '+(route.total_distance_m ? (Number(route.total_distance_m)/1000).toFixed(1)+' km' : '—')+' · Driving time: '+(route.total_duration_seconds ? Math.round(Number(route.total_duration_seconds)/60)+' min' : '—')+' · Optimizer: '+(route.optimizer_provider || 'Manual')+' · Profile: '+(route.routing_profile || '—')+' · Status: '+(route.status || 'draft'));
        $('#olama-route-editor-title').text((route.name || 'Route') + ' · ' + (route.direction === 'morning' ? 'Arrival' : 'Departure'));
        var $actions = $('#olama-route-editor .olama-route-editor-actions');
        if (!$actions.find('#olama-optimize-current-route').length) $actions.prepend('<button type="button" class="button button-primary" id="olama-optimize-current-route">Optimize Route</button>');
        $('#olama-optimize-current-route').data('id', route.id).prop('disabled', route.status !== 'draft' || !!route.needs_recalculation);
        $('#olama-route-editor-status').text(route.needs_recalculation ? (route.stale_reason || 'Trip membership or family locations changed. Rebuild the route before optimization/publishing.') : (route.partial_route ? ('Optimization will continue with '+Number(route.located_family_count||0)+' located families. '+Number(route.missing_location_count||0)+' family location(s) are skipped.') : ''));
        var skipped = route.skipped_families || [];
        $('#olama-route-skipped-list').html(skipped.length ? '<strong>Skipped from optimization ('+skipped.length+')</strong><ul>'+skipped.map(function(f){return '<li>Family #'+escAdmin(f.family_number||'—')+(f.student_names?' · '+escAdmin(f.student_names):'')+'</li>';}).join('')+'</ul>' : '');
        $('#olama-save-route-order').prop('disabled', route.status !== 'draft');
        $('#olama-rebuild-route').prop('disabled', route.status !== 'draft');
        $list.html(stops.length ? stops.map(function (stop, index) {
            var familyLabel = stop.family_number ? 'Family #'+stop.family_number : (stop.name || ('Stop '+stop.stop_id));
            var father = stop.father_name ? ' · Father: '+stop.father_name : '';
            return '<li class="olama-route-stop" draggable="'+(route.status === 'draft' ? 'true' : 'false')+'" data-stop-id="'+Number(stop.stop_id)+'"><b>'+ (index + 1) +'</b><div><strong>'+escAdmin(familyLabel)+escAdmin(father)+'</strong><small>'+escAdmin(stop.access_notes || (Number(stop.latitude).toFixed(5)+', '+Number(stop.longitude).toFixed(5)))+'</small></div></li>';
        }).join('') : '<li>No valid family stops are available for this trip.</li>');
        if (window.L) {
            if (!routeEditorMap) {
                routeEditorMap = L.map('olama-route-map').setView([31.9539,35.9106], 11);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'&copy; OpenStreetMap contributors'}).addTo(routeEditorMap);
            }
            if (!routeEditorLayer) routeEditorLayer = L.layerGroup().addTo(routeEditorMap); else routeEditorLayer.clearLayers();
            var points = stops.map(function(s){return [Number(s.latitude),Number(s.longitude)];}).filter(function(p){return isFinite(p[0])&&isFinite(p[1]);});
            if (route.depot && isFinite(Number(route.depot.latitude)) && isFinite(Number(route.depot.longitude))) L.marker([Number(route.depot.latitude),Number(route.depot.longitude)]).bindPopup('Academy / depot').addTo(routeEditorLayer);
            points.forEach(function(point,index){L.marker(point).bindTooltip(String(index+1),{permanent:true,direction:'top'}).addTo(routeEditorLayer);});
            if (route.route_geometry_geojson) { try { L.geoJSON(typeof route.route_geometry_geojson === 'string' ? JSON.parse(route.route_geometry_geojson) : route.route_geometry_geojson,{style:{color:'#2457d6',weight:5}}).addTo(routeEditorLayer); } catch(e) {} }
            else if (points.length > 1) L.polyline(points,{color:'#8291b5',weight:4,dashArray:'6 6'}).bindTooltip('Preview').addTo(routeEditorLayer);
            if (points.length) routeEditorMap.fitBounds(points,{padding:[20,20]});
            setTimeout(function(){routeEditorMap.invalidateSize();},100);
        }
    }
    function escAdmin(value){return $('<div>').text(value == null ? '' : value).html();}
    function routeOptimizationData(route) {
        var group = 'Bus' + (route && route.bus_id || '');
        var rows = [];
        (route && route.stops || []).forEach(function (stop) {
            var latitude = Number(stop.latitude), longitude = Number(stop.longitude);
            var family = stop.family_number || String(stop.name || '').replace(/^Family #\s*/i, '');
            var name = family + (stop.father_name ? ' - ' + stop.father_name : '');
            rows.push([
                group,
                name,
                isFinite(latitude) ? latitude.toFixed(7) : '',
                isFinite(longitude) ? longitude.toFixed(7) : ''
            ].map(function (value) { return String(value).replace(/[\r\n,]+/g, ' ').trim(); }).join(', '));
        });
        return rows.join('\n');
    }
    function copyRouteOptimizationData(route) {
        var value = routeOptimizationData(route);
        if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(value);
        var textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try { document.execCommand('copy'); } finally { document.body.removeChild(textarea); }
        return Promise.resolve();
    }
    function copiedButtonFeedback(button) {
        var $button = $(button), original = $button.attr('title') || 'Copy optimization data';
        $button.attr('title', 'Copied').addClass('is-copied');
        window.setTimeout(function () { $button.attr('title', original).removeClass('is-copied'); }, 1400);
    }
    $(document).on('click', '.olama-copy-route-data', function () {
        var button = this, id = $(button).data('id');
        rest('routes/' + id).then(function (route) { return copyRouteOptimizationData(route); }).then(function () { copiedButtonFeedback(button); }).catch(function (error) { alert(error.message); });
    });
    $(document).on('click', '.olama-open-route', function () {
        var id = $(this).data('id');
        rest('routes/' + id).then(function(route){routeEditorData=route;var editor=document.getElementById('olama-route-editor');editor.hidden=false;renderRouteEditor();editor.scrollIntoView({behavior:'smooth',block:'start'});}).catch(function(error){alert(error.message);});
    });
    $(document).on('click', '#olama-close-route-editor', function () {
        var editor = document.getElementById('olama-route-editor');
        editor.hidden = true;
        routeEditorData = null;
        if (routeEditorMap) { routeEditorMap.remove(); routeEditorMap = null; routeEditorLayer = null; }
        document.querySelector('.olama-routes-page').scrollIntoView({behavior:'smooth', block:'start'});
    });
    $(document).on('click', '#olama-copy-route-data', function () {
        if (!routeEditorData) return;
        var button = this;
        copyRouteOptimizationData(routeEditorData).then(function () { copiedButtonFeedback(button); }).catch(function (error) { alert(error.message); });
    });
    $(document).on('dragstart', '.olama-route-stop[draggable="true"]', function(event){event.originalEvent.dataTransfer.setData('text/plain', $(this).data('stop-id'));$(this).addClass('is-dragging');});
    $(document).on('dragend', '.olama-route-stop', function(){$(this).removeClass('is-dragging');});
    $(document).on('dragover', '.olama-route-stop', function(event){event.preventDefault();});
    $(document).on('drop', '.olama-route-stop[draggable="true"]', function(event){event.preventDefault();var id=String(event.originalEvent.dataTransfer.getData('text/plain')),source=$('.olama-route-stop[data-stop-id="'+id+'"]')[0];if(source&&source!==this){var list=this.parentNode;list.insertBefore(source,this);}var ordered=$('#olama-route-stop-list .olama-route-stop').map(function(){return Number($(this).data('stop-id'));}).get();routeEditorData.stops.sort(function(a,b){return ordered.indexOf(Number(a.stop_id))-ordered.indexOf(Number(b.stop_id));});renderRouteEditor();});
    $(document).on('click', '#olama-save-route-order', function(){if(!routeEditorData||routeEditorData.status!=='draft')return;var ids=$('#olama-route-stop-list .olama-route-stop').map(function(){return Number($(this).data('stop-id'));}).get();rest('routes/'+routeEditorData.id,{method:'PUT',body:{stop_ids:ids}}).then(function(route){routeEditorData=route;renderRouteEditor();$('#olama-route-editor-status').text('Route order saved.');}).catch(function(error){$('#olama-route-editor-status').text(error.message);});});
    $(document).on('click', '#olama-rebuild-route', function(){if(!routeEditorData||routeEditorData.status!=='draft')return;if(!window.confirm(t('Rebuild stops from the current trip locations?')))return;rest('routes/'+routeEditorData.id,{method:'PUT',body:{rebuild_from_trip:true}}).then(function(route){routeEditorData=route;renderRouteEditor();$('#olama-route-editor-status').text(t('Stops rebuilt from the trip.'));}).catch(function(error){$('#olama-route-editor-status').text(error.message);});});

    $(document).on('click', '.olama-publish-route', function () {
        if (!window.confirm(t('Publish this immutable route version?'))) {
            return;
        }
        rest('routes/' + $(this).data('id') + '/publish', {method: 'POST'})
            .then(function () { window.location.reload(); })
            .catch(function (error) { alert(error.message); });
    });

    function filterFamilyLocations() {
        var query = ($('#family-location-search').val() || '').toLowerCase().trim();
        var missingOnly = $('#family-location-missing-only').is(':checked');
        var area = $('#family-location-area-filter').val() || 'all';
        var locationStatus = $('#family-location-status-filter').val() || 'all';
        $('[data-family-location-row]').each(function () {
            var $row = $(this);
            var matchesSearch = !query || $row.text().toLowerCase().indexOf(query) !== -1;
            var matchesMissing = !missingOnly || $row.attr('data-has-location') !== '1';
            var rowArea = $row.attr('data-area-id') || '0';
            var matchesArea = area === 'all' || (area === 'unassigned' ? rowArea === '0' : rowArea === area);
            var matchesStatus = locationStatus === 'all' || $row.attr('data-location-status') === locationStatus;
            $row.toggle(matchesSearch && matchesMissing && matchesArea && matchesStatus);
        });
    }

    $('#family-location-search').on('input', filterFamilyLocations);
    $('#family-location-missing-only').on('change', filterFamilyLocations);
    $('#family-location-area-filter, #family-location-status-filter').on('change', filterFamilyLocations);

    $('#family-location-select-all').on('change', function () {
        $('.family-location-select:enabled:visible').prop('checked', this.checked);
    });

    function saveFamilyArea($row, areaId) {
        var stopId = parseInt($row.find('.olama-save-family-area').data('stop-id'), 10);
        if (!stopId) return Promise.reject(new Error('Save the family location before assigning a planning area.'));
        return rest('family-locations/' + stopId + '/area', {method: 'POST', body: {major_area_id: areaId || 0}}).then(function () {
            $row.attr('data-area-id', areaId || '0');
            $row.find('.family-planning-area').val(areaId || '');
            filterFamilyLocations();
        });
    }

    $(document).on('click', '.olama-save-family-area', function () {
        var $button = $(this), $row = $button.closest('tr');
        $button.prop('disabled', true);
        saveFamilyArea($row, parseInt($row.find('.family-planning-area').val() || 0, 10))
            .then(function () { window.location.reload(); })
            .catch(function (error) { alert(error.message); $button.prop('disabled', false); });
    });

    $(document).on('click', '.olama-clear-family-area', function () {
        var $button = $(this), $row = $button.closest('tr');
        saveFamilyArea($row, 0).then(function () { window.location.reload(); }).catch(function (error) { alert(error.message); });
    });

    $('#family-location-bulk-save').on('click', function () {
        var ids = $('.family-location-select:checked').map(function () { return parseInt(this.value, 10); }).get();
        if (!ids.length) { alert(t('Select at least one family location.')); return; }
        var $button = $(this).prop('disabled', true);
        rest('family-locations/bulk-area', {method: 'POST', body: {family_stop_ids: ids, major_area_id: parseInt($('#family-location-bulk-area').val() || 0, 10)}})
            .then(function () { window.location.reload(); })
            .catch(function (error) { alert(error.message); $button.prop('disabled', false); });
    });

    $('#copy-family-location-template').on('click', function () {
        var text = $('#family-location-whatsapp-template').val();
        var $result = $('#copy-family-location-result');
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text)
                .then(function () { $result.text('Copied.'); })
                .catch(function () { $result.text('Could not copy automatically.'); });
            return;
        }
        var field = document.getElementById('family-location-whatsapp-template');
        field.focus();
        field.select();
        $result.text(document.execCommand('copy') ? 'Copied.' : 'Select and copy the message.');
    });

    $(document).on('keydown', '.family-location-input', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $(this).closest('tr').find('.olama-save-family-location').trigger('click');
        }
    });

    $(document).on('click', '.olama-save-family-location', function () {
        var $button = $(this);
        var $row = $button.closest('tr');
        var $input = $row.find('.family-location-input');
        var $result = $row.find('.family-location-result');
        var location = $input.val().trim();

        $result.removeClass('is-success is-error').text('');
        if (!location) {
            $result.addClass('is-error').text('Paste coordinates or a Google Maps link.');
            return;
        }

        $button.prop('disabled', true);
        $result.text(t('saving'));
        rest('family-locations/' + encodeURIComponent($button.data('family-uid')), {
            method: 'PUT',
            body: {location: location}
        }).then(function (response) {
            $input.val(response.normalized_location);
            $row.attr('data-has-location', '1');
            $row.find('.olama-status-pill')
                .attr('class', 'olama-status-pill olama-status-needs_review')
                .text('needs_review');
            $row.find('.olama-view-family-location')
                .attr('href', response.map_url)
                .removeClass('is-hidden');
            var stopId = response.family_stop && response.family_stop.id;
            if (stopId) {
                $row.find('.family-location-select').val(stopId).prop('disabled', false);
                $row.find('.olama-save-family-area, .olama-clear-family-area').data('stop-id', stopId).prop('disabled', false);
                $row.find('.family-planning-area').prop('disabled', false);
            }
            $result.addClass('is-success').text(response.message);
            $button.prop('disabled', false);
            filterFamilyLocations();
        }).catch(function (error) {
            $result.addClass('is-error').text(error.message);
            $button.prop('disabled', false);
        });
    });

    $('#family-stop-import-form').on('submit', function (event) {
        event.preventDefault();
        var form = this;
        $('#import-result').text(t('saving'));
        rest('imports/family-stops', {method: 'POST', body: new FormData(form)})
            .then(function (result) {
                $('#import-result').text(
                    'Rows: ' + result.row_count +
                    ', matched: ' + result.counts.matched +
                    ', review: ' + result.counts.needs_review +
                    ', invalid: ' + result.counts.invalid
                );
            })
            .catch(function (error) { $('#import-result').text(error.message); });
    });

    $('#transport-settings-form').on('submit', function (event) {
        event.preventDefault();
        var values = objectFromForm(this);
        values.traccar_enabled = values.traccar_enabled ? 1 : 0;
        values.school_location = {latitude: values['school_location[latitude]'] || '', longitude: values['school_location[longitude]'] || ''};
        delete values['school_location[latitude]']; delete values['school_location[longitude]'];
        rest('settings', {method: 'PUT', body: values})
            .then(function () {
                $('#settings-result').text(t('settingsSaved'));
                window.location.reload();
            })
            .catch(function (error) { $('#settings-result').text(error.message); });
    });
    function toggleOptimizerPanels() { var provider=$('#optimizer-provider').val(); $('[data-optimizer-panel]').each(function(){ $(this).toggle($(this).data('optimizer-panel') === provider); }); }
    $(document).on('change', '#optimizer-provider', toggleOptimizerPanels); toggleOptimizerPanels();
    $(document).on('click', '#test-ors-configuration', function(){ var button=this; $(button).prop('disabled',true).text(t('testing')); rest('settings/test-ors',{method:'POST'}).then(function(r){$('#settings-result').text(r.message||'OpenRouteService configuration is working.');}).catch(function(e){$('#settings-result').text(e.message);}).finally(function(){$(button).prop('disabled',false).text(t('testOrs'));}); });

    $('#refresh-core-buses').on('click', function () {
        var $button = $(this).prop('disabled', true);
        var $result = $('#bus-refresh-result').length ? $('#bus-refresh-result') : $('#settings-result');
        $result.text('Synchronizing buses from Oracle...');
        rest('core/refresh-buses', {method: 'POST'})
            .then(function (result) {
                $result.text('Received: ' + result.received + ', created: ' + result.created + ', updated: ' + result.updated + ', deactivated: ' + result.deactivated + '. Reloading...');
                window.setTimeout(function () { window.location.reload(); }, 750);
            })
            .catch(function (error) {
                $result.text(error.message);
                $button.prop('disabled', false);
            });
    });
})(jQuery);
