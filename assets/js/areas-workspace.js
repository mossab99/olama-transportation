(function ($) {
    'use strict';
    var app, data;
    function api(path, options) {
        options = options || {};
        options.headers = $.extend({'X-WP-Nonce': olamaTransportation.restNonce, 'Content-Type': 'application/json'}, options.headers || {});
        return fetch(olamaTransportation.restUrl + path, options).then(function (r) { return r.json().then(function (body) { if (!r.ok) throw new Error(body.message || 'Request failed'); return body; }); });
    }
    function esc(value) { return $('<div>').text(value == null ? '' : value).html(); }
    function feedback(message, error) { $('#areas-feedback').text(message || '').toggleClass('is-error', !!error).toggleClass('is-success', !error && !!message); }
    function buses(direction) {
        return data.buses.map(function (b) { var cap = Number(b.effective_capacity || 0); return '<option value="' + b.id + '">' + esc(b.bus_number) + ' (' + cap + ', ' + (direction === 'morning' ? b.morning_trip_count : b.afternoon_trip_count) + ' trips)</option>'; }).join('');
    }
    function tripHtml(trip) {
        var time = [trip.arrival_time && 'Arrival ' + trip.arrival_time.slice(0, 5), trip.departure_time && 'Departure ' + trip.departure_time.slice(0, 5)].filter(Boolean).join(' · ');
        return '<li><strong>Bus ' + esc(trip.bus_number) + ' — Trip ' + trip.trip_number + '</strong> <span>' + trip.student_count + '/' + trip.capacity + ' students, ' + trip.family_count + ' families</span>' + (time ? '<small>' + esc(time) + '</small>' : '') + '<button class="button button-small areas-show-queue" data-trip="' + trip.id + '">View queue</button> <button class="button button-small areas-generate-queue" data-trip="' + trip.id + '">Generate family queue</button></li>';
    }
    function render() {
        var direction = $('#areas-direction').val();
        var html = data.areas.map(function (area) {
            var tripList = area.trips.length ? '<ul class="olama-area-trip-list">' + area.trips.map(tripHtml).join('') + '</ul>' : '<em>No trips created.</em>';
            return '<tr data-area="' + area.id + '"><td><span class="olama-area-swatch" style="background:' + esc(area.color) + '"></span><strong>' + esc(area.name) + '</strong><small>' + esc(area.code) + '</small></td><td>' + area.student_count + '</td><td><label><input class="areas-type" type="checkbox" ' + (area.area_type === 'main' ? 'checked' : '') + '> Main area</label></td><td><input class="areas-color" type="color" value="' + esc(area.color || '#1a56db') + '"></td><td>' + tripList + '</td><td><form class="areas-add-trip"><input type="hidden" name="major_area_id" value="' + area.id + '"><select name="bus_id" required><option value="">Select bus</option>' + buses(direction) + '</select><input name="trip_number" type="number" min="1" required placeholder="Trip #"><input name="arrival_time" type="time" title="Arrival"><input name="departure_time" type="time" title="Departure"><button class="button button-primary">Add</button></form></td></tr>';
        }).join('');
        $('#areas-body').html(html || '<tr><td colspan="6">No active Oracle areas found.</td></tr>');
    }
    function load() {
        if (!app) return;
        feedback('Loading…');
        api('areas-workspace?academic_year_id=' + encodeURIComponent($('#areas-year').val()) + '&direction=' + encodeURIComponent($('#areas-direction').val())).then(function (response) { data = response; render(); feedback(response.warning || ''); }).catch(function (e) { feedback(e.message, true); });
    }
    $(function () {
        app = $('#olama-areas-workspace'); if (!app.length) return;
        load();
        $('#areas-year,#areas-direction').on('change', load);
        $('#areas-refresh-core').on('click', function () { api('core/refresh-areas', {method:'POST', body:'{}'}).then(function(){ feedback('Areas refreshed from Olama Core.'); load(); }).catch(function(e){feedback(e.message,true);}); });
        $(document).on('change', '.areas-type,.areas-color', function () { var row=$(this).closest('tr'), area=row.data('area'); api('areas-workspace/'+area,{method:'PUT',body:JSON.stringify({area_type:row.find('.areas-type').prop('checked')?'main':'secondary',color:row.find('.areas-color').val()})}).then(function(){feedback('Area settings saved.');load();}).catch(function(e){feedback(e.message,true);}); });
        $(document).on('submit', '.areas-add-trip', function (e) { e.preventDefault(); var form=$(this), payload={academic_year_id:$('#areas-year').val(),direction:$('#areas-direction').val(),major_area_id:form.find('[name=major_area_id]').val(),bus_id:form.find('[name=bus_id]').val(),trip_number:form.find('[name=trip_number]').val(),arrival_time:form.find('[name=arrival_time]').val(),departure_time:form.find('[name=departure_time]').val()}; api('areas-workspace/trips',{method:'POST',body:JSON.stringify(payload)}).then(function(){feedback('Trip created. Generate its family queue when ready.');load();}).catch(function(err){feedback(err.message,true);}); });
        $(document).on('click', '.areas-generate-queue', function () { var id=$(this).data('trip'); api('areas-workspace/trips/'+id+'/generate-queue',{method:'POST',body:'{}'}).then(function(result){feedback(result.families_added+' families added to the randomized queue. '+result.remaining_seats+' seats remain.');load();}).catch(function(e){feedback(e.message,true);}); });
        $(document).on('click', '.areas-show-queue', function () { var id=$(this).data('trip'); api('areas-workspace/trips/'+id+'/queue').then(function(result){ var circles=result.families.map(function(f){return '<li title="'+esc(f.family_name)+'"><span>'+esc(f.oracle_family_id || f.family_name)+'</span><small>'+f.student_count_snapshot+' students</small></li>';}).join(''); var dialog=$('<dialog class="olama-queue-dialog"><button type="button" class="button-link">Close</button><div class="olama-queue-view"><h3></h3><ol></ol></div></dialog>'); dialog.find('h3').text(result.label);dialog.find('ol').html(circles || '<li>No families allocated yet.</li>');dialog.on('click','button',function(){dialog.get(0).close();dialog.remove();});$('body').append(dialog);dialog.get(0).showModal(); }).catch(function(e){feedback(e.message,true);}); });
    });
})(jQuery);
