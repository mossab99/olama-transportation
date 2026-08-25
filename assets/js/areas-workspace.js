(function ($) {
    'use strict';
    var app, data;
    var wizard = {trip:null, step:1, areaId:0, candidates:[], showAll:false};
    var queueMap = null, queueMarkers = {};

    function api(path, options) {
        options = options || {};
        options.headers = $.extend({'X-WP-Nonce':olamaTransportation.restNonce,'Content-Type':'application/json'}, options.headers || {});
        return fetch(olamaTransportation.restUrl + path, options).then(function (response) {
            return response.json().catch(function(){ return {}; }).then(function (body) {
                if (!response.ok) throw new Error(body.message || 'Request failed');
                return body;
            });
        });
    }
    function esc(value) { return $('<div>').text(value == null ? '' : value).html(); }
    function feedback(message, error) { $('#areas-feedback').text(message || '').toggleClass('is-error',!!error).toggleClass('is-success',!error&&!!message); }
    function wizardFeedback(message, error) { $('#olama-trip-wizard-feedback').text(message || '').toggleClass('is-error',!!error).toggleClass('is-success',!error&&!!message); }
    function panel(step) { return $('.olama-trip-step-panel[data-step-panel="' + step + '"]'); }
    function published() { return wizard.trip && wizard.trip.status === 'published'; }
    function warning(label, count) { return count > 0 ? '<span class="olama-capacity-warning">⚠ ' + esc(label) + ': +' + count + '</span>' : ''; }
    function tripRunLabel(direction, number) {
        return direction === 'morning' ? 'Arrival trip ' + number + ' (حضور ' + number + ')' : 'Departure trip ' + number + ' (عودة ' + number + ')';
    }
    function tripRunPrompt(direction) { return direction === 'morning' ? 'arrival trip / حضور' : 'departure trip / عودة'; }

    function tripCard(trip) {
        var hasBus = Number(trip.bus_id || 0) > 0, capacity = Number(trip.bus_capacity || 0), students = Number(trip.student_count || 0);
        var capacityClass = trip.bus_excess ? ' is-over-capacity' : (hasBus ? ' is-within-capacity' : '');
        var capacityNote = trip.bus_excess ? '+' + trip.bus_excess + ' over capacity' : (hasBus ? Math.max(0, capacity - students) + ' seats available' : 'Assign a bus to set capacity');
        var areas = trip.area_names ? esc(trip.area_names) : '<em>No areas attached</em>';
        return '<article class="olama-trip-card ' + (trip.status === 'published' ? 'is-published' : 'is-draft') + '">' +
            '<div class="olama-trip-card-head"><div><strong>' + esc(trip.name) + '</strong><span class="olama-trip-status">' + esc(trip.status) + '</span></div><div><button type="button" class="button olama-print-badges" data-trip="' + trip.id + '">Print badges</button> <button type="button" class="button olama-print-trip-report" data-trip="' + trip.id + '">HTML report</button> <button type="button" class="button olama-export-trip-csv" data-trip="' + trip.id + '">CSV report</button> <button type="button" class="button olama-open-trip" data-trip="' + trip.id + '">' + (trip.status === 'published' ? 'Review trip' : 'Open trip') + '</button></div></div>' +
            '<div class="olama-trip-card-facts">' +
                '<div><span>Bus number</span><strong>' + (hasBus ? esc(trip.bus_number || '#' + trip.bus_id) : 'Unassigned') + '</strong><small>' + (hasBus ? tripRunLabel(trip.direction, trip.bus_trip_number) : 'No bus selected') + '</small></div>' +
                '<div><span>Students in trip</span><strong>' + students + '</strong><small>' + Number(trip.family_count || 0) + ' families</small></div>' +
                '<div class="' + capacityClass + '"><span>Bus capacity</span><strong>' + (hasBus ? capacity : '—') + '</strong><small>' + capacityNote + '</small></div>' +
            '</div><div class="olama-trip-staff"><span><b>Driver:</b> '+esc(trip.driver_name||'Not assigned')+'</span><span><b>Companion:</b> '+esc(trip.companion_name||'Not assigned')+'</span></div>' +
            '<div class="olama-trip-covered-areas"><span>Areas covered <b>' + (trip.area_ids || []).length + '</b></span><div>' + areas + '</div></div>' +
            '<div class="olama-trip-card-warnings">' + warning('trip limit',trip.trip_excess) + warning('bus capacity',trip.bus_excess) + '</div></article>';
    }
    function render() {
        var trips = data.shared_trips || [];
        $('#olama-trip-board-list').html(trips.length ? trips.map(tripCard).join('') : '<div class="olama-empty-trip-state"><strong>No trips created yet.</strong><span>Create a trip first, then assign its bus, areas, and students.</span></div>');
        $('#olama-trip-board-summary').text(trips.length + ' trip' + (trips.length === 1 ? '' : 's'));
        var rows = (data.areas || []).map(function (area) {
            var assigned = area.shared_trips && area.shared_trips.length ? '<ul class="olama-area-trip-list">' + area.shared_trips.map(function(trip){return '<li><button type="button" class="button-link olama-open-trip" data-trip="'+trip.id+'">'+esc(trip.name)+'</button><small>'+trip.area_student_count+' students · '+esc(trip.bus_number ? 'Bus '+trip.bus_number+' · '+tripRunLabel($('#areas-direction').val(),trip.bus_trip_number) : 'Bus unassigned')+'</small></li>';}).join('') + '</ul>' : '<em>Not attached to a trip.</em>';
            return '<tr data-area="'+area.id+'"><td class="olama-area-identity"><span class="olama-area-swatch" style="background:'+esc(area.color)+'"></span><strong>'+esc(area.name)+'</strong><small>'+esc(area.code)+'</small></td>'+
                '<td><div class="olama-area-non-transport olama-area-clickable" data-box="walking"><b>'+Number(area.non_transportation_student_count||0)+'</b><small>Walking Students</small></div></td>'+
                '<td><div class="olama-area-student-summary"><span class="olama-area-clickable" data-box="transport"><b>'+Number(area.transportation_student_count||0)+'</b>Transportation students</span><span class="is-assigned olama-area-clickable" data-box="assigned"><b>'+Number(area.assigned_student_count||0)+'</b>Assigned</span><span class="'+(Number(area.unassigned_student_count||0)>0?'is-unassigned':'is-complete')+' olama-area-clickable" data-box="unassigned"><b>'+Number(area.unassigned_student_count||0)+'</b>Not assigned</span></div></td>'+
                '<td>'+assigned+'</td><td><div class="olama-area-display-settings"><label><span>Color</span><input class="areas-color" type="color" value="'+esc(area.color||'#1a56db')+'"></label></div></td></tr>';
        }).join('');
        $('#areas-body').html(rows || '<tr><td colspan="5">No active Oracle areas found.</td></tr>');
    }
    function load(message) {
        feedback('Loading…');
        return api('areas-workspace?academic_year_id='+encodeURIComponent($('#areas-year').val())+'&direction='+encodeURIComponent($('#areas-direction').val())).then(function(response){data=response;render();feedback(message||response.warning||'');}).catch(function(error){feedback(error.message,true);});
    }

    function createTrip() {
        feedback('Creating trip…');
        api('areas-workspace/shared-trips',{method:'POST',body:JSON.stringify({academic_year_id:$('#areas-year').val(),direction:$('#areas-direction').val(),planning_limit:35})}).then(openTrip).catch(function(error){feedback(error.message,true);});
    }
    function openTrip(tripOrId) {
        var promise = typeof tripOrId === 'object' ? Promise.resolve(tripOrId) : api('areas-workspace/shared-trips/'+tripOrId);
        promise.then(function(trip){
            wizard={trip:trip,step:1,areaId:0,candidates:[],showAll:false};
            $('#olama-trip-wizard-title').text(trip.name);
            $('#olama-trip-wizard-context').text((trip.direction==='morning'?'Arrival / حضور':'Departure / عودة')+' · Independent trip plan');
            wizardFeedback('');setStep(1);renderDetails();updateSummary();
            document.getElementById('olama-trip-wizard-dialog').showModal();
        }).catch(function(error){feedback(error.message,true);});
    }
    function updateSummary() {
        if (!wizard.trip) return $('#olama-trip-wizard-summary').empty();
        var trip=wizard.trip,count=Number(trip.student_count||0),limit=Number(trip.planning_limit||35),capacity=Number(trip.bus_capacity||0);
        var excess=Math.max(0,count-limit),busExcess=capacity?Math.max(0,count-capacity):0;
        $('#olama-trip-wizard-summary').html('<div><strong>'+count+'</strong><span>Students</span></div><div><strong>'+Number(trip.family_count||0)+'</strong><span>Families</span></div><div><strong>'+Number(trip.area_count||0)+'</strong><span>Areas</span></div><div class="'+(excess?'has-warning':'')+'"><strong>'+(excess?'+'+excess:count+'/'+limit)+'</strong><span>Trip limit</span></div><div class="'+(busExcess?'has-warning':'')+'"><strong>'+(busExcess?'+'+busExcess:(capacity?count+'/'+capacity:'—'))+'</strong><span>Bus capacity</span></div>');
    }
    function updateModeNote(){ $('#olama-trip-wizard-mode-note').text(published()?'Published trip — return it to draft before editing.':'Draft changes save as you continue.'); }
    function setStep(step) {
        if(step!==4)destroyQueueMap();
        wizard.step=step;$('.olama-trip-wizard').attr('data-step',step);$('.olama-trip-step-panel').hide().filter('[data-step-panel="'+step+'"]').show();
        $('.olama-trip-steps li').each(function(index){$(this).toggleClass('is-current',index+1===step).toggleClass('is-done',index+1<step);});
        $('#olama-trip-wizard-back').prop('disabled',step===1);$('#olama-trip-wizard-next').text(step===4?(published()?'Close':'Publish trip'):(published()?'Continue review':'Save & continue')).prop('disabled',false);updateModeNote();updateSummary();
    }

    function renderDetails() {
        var disabled=published()?' disabled':'';
        var companionOptions=(window.olamaTripStaff&&olamaTripStaff.companions||[]).map(function(person){return '<option value="'+person.id+'" '+(Number(wizard.trip.companion_user_id)===Number(person.id)?'selected':'')+'>'+esc(person.name)+'</option>';}).join('');
        var lifecycle=published()?'<div class="notice notice-info inline"><p>This trip is published. <button type="button" class="button" id="olama-return-trip-to-draft">Return to draft and edit</button></p></div>':'<button type="button" class="button-link-delete" id="olama-delete-trip-draft">Delete draft</button>';
        panel(1).html('<div class="olama-trip-form"><h3>Trip details</h3><p>The trip is permanent; its bus can be assigned or replaced independently.</p>'+lifecycle+'<label>Trip name<input type="text" id="olama-trip-name" value="'+esc(wizard.trip.name)+'"'+disabled+'></label><label>Companion<select id="olama-trip-companion"'+disabled+'><option value="">Select companion</option>'+companionOptions+'</select></label><label>Planning target<input type="number" min="1" id="olama-trip-limit" value="'+wizard.trip.planning_limit+'"'+disabled+'></label><div class="olama-trip-time-grid"><label>Arrival time<input type="time" id="olama-trip-arrival" value="'+esc((wizard.trip.arrival_time||'').slice(0,5))+'"'+disabled+'></label><label>Departure time<input type="time" id="olama-trip-departure" value="'+esc((wizard.trip.departure_time||'').slice(0,5))+'"'+disabled+'></label></div><div class="olama-school-anchor-note"><strong>School anchor</strong><span>'+(wizard.trip.direction==='morning'?'Morning queue ends at school.':'Afternoon queue starts from school.')+'</span></div></div>');
    }
    function saveDetails(){return api('areas-workspace/shared-trips/'+wizard.trip.id,{method:'PUT',body:JSON.stringify({name:$('#olama-trip-name').val(),companion_user_id:$('#olama-trip-companion').val(),planning_limit:$('#olama-trip-limit').val(),arrival_time:$('#olama-trip-arrival').val(),departure_time:$('#olama-trip-departure').val()})}).then(function(trip){wizard.trip=trip;$('#olama-trip-wizard-title').text(trip.name);});}

    function renderBus() {
        var disabled=published()?' disabled':'';
        var runPrompt=tripRunPrompt(wizard.trip.direction);
        var options=(data.buses||[]).map(function(bus){return '<option value="'+bus.id+'" data-capacity="'+bus.effective_capacity+'" data-slots="'+(wizard.trip.direction==='morning'?bus.morning_trip_count:bus.afternoon_trip_count)+'" '+(Number(wizard.trip.bus_id)===Number(bus.id)?'selected':'')+'>'+esc(bus.bus_number)+' · '+bus.effective_capacity+' seats</option>';}).join('');
        panel(2).html('<div class="olama-trip-form"><h3>Bus assignment</h3><p>Changing the bus keeps the trip, areas, students, and family queue intact.</p><label>Assigned bus<select id="olama-trip-bus"'+disabled+'><option value="">Unassigned</option>'+options+'</select></label><label>Select '+runPrompt+'<select id="olama-trip-bus-slot"'+disabled+'><option value="">Select '+runPrompt+'</option></select></label><div id="olama-bus-capacity-preview"></div></div>');populateSlots();updateBusPreview();
    }
    function populateSlots(){var selected=$('#olama-trip-bus option:selected'),slots=Number(selected.data('slots')||0),html='<option value="">Select '+tripRunPrompt(wizard.trip.direction)+'</option>';for(var i=1;i<=slots;i++)html+='<option value="'+i+'" '+(Number(wizard.trip.bus_trip_number)===i?'selected':'')+'>'+tripRunLabel(wizard.trip.direction,i)+'</option>';$('#olama-trip-bus-slot').html(html);}
    function updateBusPreview(){var capacity=Number($('#olama-trip-bus option:selected').data('capacity')||0),count=Number(wizard.trip.student_count||0),excess=capacity?Math.max(0,count-capacity):0;$('#olama-bus-capacity-preview').html(capacity?'<strong>'+count+'/'+capacity+' seats</strong>'+(excess?warning('bus capacity',excess):'<span class="olama-capacity-ok">Within bus capacity</span>'):'The trip may remain without a bus while it is a draft.');}
    function saveBus(){var bus=$('#olama-trip-bus').val(),slot=$('#olama-trip-bus-slot').val();if(bus&&!slot)return Promise.reject(new Error('Select the bus '+tripRunPrompt(wizard.trip.direction)+'.'));return api('areas-workspace/shared-trips/'+wizard.trip.id,{method:'PUT',body:JSON.stringify({bus_id:bus||0,bus_trip_number:slot||0})}).then(function(trip){wizard.trip=trip;});}

    function renderAreas() {
        var attached=(wizard.trip.area_ids||[]).map(Number),disabled=published()?' disabled':'';
        var checks=(data.areas||[]).map(function(area){var count=(wizard.trip.students||[]).filter(function(student){return Number(student.major_area_id)===Number(area.id);}).length;return '<label class="olama-trip-area-choice"><input type="checkbox" class="olama-trip-area-check" value="'+area.id+'" '+(attached.indexOf(Number(area.id))!==-1?'checked':'')+disabled+'><span class="olama-area-swatch" style="background:'+esc(area.color)+'"></span><strong>'+esc(area.name)+'</strong><small>'+count+' selected / '+area.student_count+' demand</small></label>';}).join('');
        var editorOptions=(wizard.trip.areas||[]).map(function(area){return '<option value="'+area.id+'" '+(Number(wizard.areaId)===Number(area.id)?'selected':'')+'>'+esc(area.name)+'</option>';}).join('');
        var apply=published()?'':'<button type="button" class="button" id="olama-save-trip-areas">Apply area selection</button>';
        panel(3).html('<div class="olama-trip-areas"><div class="olama-trip-panel-head"><div><h3>Areas and students</h3><p>Attach one or more areas, then choose the students contributed by each area.</p></div>'+apply+'</div><div class="olama-trip-area-grid">'+checks+'</div><div class="olama-area-student-editor"><label>Edit students for area<select id="olama-trip-area-editor"><option value="">Select an attached area</option>'+editorOptions+'</select></label><div id="olama-trip-area-students"><p>Select an attached area to review its students.</p></div></div></div>');
        if(wizard.areaId&&attached.indexOf(Number(wizard.areaId))!==-1)loadAreaCandidates(wizard.areaId);
    }
    function selectedAreaIds(){return $('.olama-trip-area-check:checked').map(function(){return Number(this.value);}).get();}
    function saveAreas(){wizardFeedback('Saving trip areas…');return api('areas-workspace/shared-trips/'+wizard.trip.id+'/areas',{method:'PUT',body:JSON.stringify({area_ids:selectedAreaIds()})}).then(function(trip){wizard.trip=trip;if(trip.area_ids.indexOf(Number(wizard.areaId))===-1){wizard.areaId=0;wizard.candidates=[];}renderAreas();updateSummary();wizardFeedback('Areas saved.');});}
    function loadAreaCandidates(areaId){wizard.areaId=Number(areaId)||0;if(!wizard.areaId){wizard.candidates=[];return $('#olama-trip-area-students').html('<p>Select an attached area to review its students.</p>');}wizardFeedback('Loading area students…');api('areas-workspace/shared-trips/'+wizard.trip.id+'/candidates?major_area_id='+wizard.areaId).then(function(response){wizard.trip=response.trip;wizard.candidates=response.students;renderAreaStudents();updateSummary();wizardFeedback('');}).catch(function(error){wizardFeedback(error.message,true);});}
    function renderAreaStudents(){var isPublished=published();var rows=wizard.candidates.map(function(student,index){var hidden=!wizard.showAll&&!student.subscribed&&!student.selected&&!student.assigned_elsewhere?' is-filtered-out':'';var assignedClass=student.assigned_elsewhere?' is-assigned-elsewhere':'';var disabled=student.assigned_elsewhere||!student.subscribed||isPublished;return '<tr class="'+hidden+assignedClass+'"><td>'+(index+1)+'</td><td><input type="checkbox" class="olama-trip-student" value="'+esc(student.student_uid)+'" '+(student.selected?'checked':'')+(disabled?' disabled':'')+'></td><td><strong>'+esc(student.student_name)+'</strong><small>'+esc([student.grade_name,student.section_name].filter(Boolean).join(' · '))+'</small></td><td>'+esc(student.family_name)+'</td><td><span class="olama-transport-chip '+(student.subscribed?'is-yes':'is-no')+'">'+(student.subscribed?'مشترك بالمواصلات':'غير مشترك بالمواصلات')+'</span>'+(student.assigned_elsewhere?'<small class="olama-student-conflict"><strong>Protected</strong> · Assigned to '+esc(student.assigned_trip_name)+'</small>':'')+'</td></tr>';}).join('');var bulk=isPublished?'':'<button type="button" class="button" id="olama-select-all-students">Select all available students</button><button type="button" class="button" id="olama-clear-student-selection">Clear selection</button>';$('#olama-trip-area-students').html('<div class="olama-trip-student-actions"><label><input type="checkbox" id="olama-show-nonsubscribers" '+(wizard.showAll?'checked':'')+'> Show non-subscribers</label>'+bulk+'<span id="olama-student-selection-count"></span>'+(isPublished?'':'<button type="button" class="button button-primary" id="olama-save-area-students">Save this area’s students</button>')+'</div><div class="olama-trip-student-scroll"><table class="widefat striped"><thead><tr><th>#</th><th></th><th>Student</th><th>Family</th><th>Transportation</th></tr></thead><tbody>'+(rows||'<tr><td colspan="5">No students available for this area.</td></tr>')+'</tbody></table></div>');updateStudentSelectionCount();}
    function updateStudentSelectionCount(){var available=$('.olama-trip-student:not(:disabled)').length,selected=$('.olama-trip-student:checked:not(:disabled)').length;$('#olama-student-selection-count').text(selected+' selected · '+available+' available');}
    function rememberStudentSelection(){var selected=new Set($('.olama-trip-student:checked').map(function(){return this.value;}).get());wizard.candidates.forEach(function(student){if(!student.assigned_elsewhere)student.selected=selected.has(student.student_uid);});}
    function selectFamilySiblings(uid, checked){var student=wizard.candidates.find(function(item){return item.student_uid===uid;});if(!student)return;wizard.candidates.forEach(function(sibling){if(sibling.family_uid===student.family_uid&&sibling.subscribed&&!sibling.assigned_elsewhere){$('.olama-trip-student[value="'+CSS.escape(sibling.student_uid)+'"]').prop('checked',checked);sibling.selected=checked;}});updateStudentSelectionCount();}
    function selectAllStudents(){var boxes=$('.olama-trip-student:not(:disabled)');boxes.prop('checked',true).closest('tr').removeClass('is-filtered-out');rememberStudentSelection();updateStudentSelectionCount();wizardFeedback(boxes.length+' available students selected.');}
    function clearStudentSelection(){$('.olama-trip-student:not(:disabled)').prop('checked',false);rememberStudentSelection();updateStudentSelectionCount();wizardFeedback('Student selection cleared.');}
    function saveCurrentStudents(){if(!wizard.areaId)return Promise.resolve(wizard.trip);var uids=$('.olama-trip-student:checked').map(function(){return this.value;}).get();wizardFeedback('Saving area students…');return api('areas-workspace/shared-trips/'+wizard.trip.id+'/students',{method:'PUT',body:JSON.stringify({major_area_id:wizard.areaId,student_uids:uids})}).then(function(trip){wizard.trip=trip;renderAreas();updateSummary();wizardFeedback('Students saved.');return trip;});}

    function qrSvg(value){if(!value||typeof qrcode==='undefined')return '<span class="no-location">لا يوجد موقع</span>';var qr=qrcode(0,'M');qr.addData(value);qr.make();return qr.createSvgTag(2,0);}
    function nameWords(value){return String(value||'').trim().split(/\s+/).filter(Boolean);}
    function firstName(value){var words=nameWords(value);return words[0]||'—';}
    function firstLastName(value){var words=nameWords(value);return words.length>1?words[0]+' '+words[words.length-1]:(words[0]||'—');}
    function shortGrade(value){var words=nameWords(value);if(!words.length)return '—';var secondary=words.find(function(word){return word.indexOf('ثانوي')!==-1;});return secondary?words[0]+' '+secondary:words[0];}
    function gradeSection(grade,section){return [shortGrade(grade),String(section||'').trim()].filter(function(value){return value&&value!=='—';}).join(' / ')||'—';}
    function badgeCard(student,trip){
        var siblings=(student.siblings||[]).map(function(s){return '<span><b>'+esc(firstName(s.student_name))+'</b><em>'+esc(gradeSection(s.grade_name,s.section_name))+'</em></span>';}).join('')||'<span><b>لا يوجد</b></span>';
        var grade=esc(gradeSection(student.grade_name,student.section_name));
        var density=((student.full_address||'').length+((student.siblings||[]).length*28))>145?' is-dense':'';
        return '<section class="badge'+density+'" dir="rtl"><div class="lanyard" aria-hidden="true"></div><header><strong>أكاديمية علماء المستقبل</strong><span>بطاقة المواصلات المدرسية</span></header><h1>'+esc(student.student_name)+'</h1><div class="summary"><div><small>الصف والشعبة</small><b>'+grade+'</b></div><div><small>الباص / الجولة</small><b>'+esc(trip.bus_number||'—')+' / '+esc(trip.bus_trip_number||'—')+'</b></div><div><small>المنطقة</small><b>'+esc(student.area_name||'—')+'</b></div></div><div class="transport"><div class="qr">'+qrSvg(student.maps_url)+'<small>امسح للوصول إلى الموقع</small></div><div class="staff-list"><div class="staff-card"><small>السائق</small><b>'+esc(firstLastName(trip.driver_name))+'</b></div><div class="staff-card"><small>المرافقة</small><b>'+esc(firstLastName(trip.companion_name))+'</b></div></div></div><div class="family"><div><small>الأب</small><p><b>'+esc(firstName(student.father_name))+'</b><span>'+esc(student.father_mobile||'—')+'</span></p></div><div><small>الأم</small><p><b>'+esc(firstName(student.mother_name))+'</b><span>'+esc(student.mother_mobile||'—')+'</span></p></div></div><div class="detail siblings"><small>الإخوة والأخوات</small><p>'+siblings+'</p></div><div class="detail address"><small>العنوان الكامل</small><p>'+esc(student.full_address||'—')+'</p></div><footer>أكاديمية علماء المستقبل - المرقب - 0777496676</footer></section>';
    }
    function reportRows(payload){
        return (payload.students||[]).map(function(student){return {
            student_name:student.student_name||'', grade:student.grade_name||'', section:student.section_name||'',
            father_mobile:student.father_mobile||'', mother_mobile:student.mother_mobile||'', address:student.full_address||'',
            trip:payload.trip.name||'', driver:payload.trip.driver_name||'', bus:payload.trip.bus_number||''
        };});
    }
    function openTripReport(tripId){
        var win=window.open('','_blank');if(!win){feedback('Allow pop-ups to preview the report.',true);return;}
        win.document.write('<p dir="rtl" style="font-family:Tahoma,Arial;padding:24px">جارٍ تجهيز التقرير…</p>');
        api('areas-workspace/shared-trips/'+tripId+'/badges').then(function(payload){
            var rows=reportRows(payload), cells=function(row){return '<td>'+esc(row.student_name)+'</td><td>'+esc(row.grade)+'</td><td>'+esc(row.section)+'</td><td dir="ltr">'+esc(row.father_mobile)+'</td><td dir="ltr">'+esc(row.mother_mobile)+'</td><td>'+esc(row.address)+'</td>';};
            var body=rows.map(function(row){return '<tr>'+cells(row)+'</tr>';}).join('');
            var html='<!doctype html><html lang="en" dir="ltr"><head><meta charset="utf-8"><title>Trip student report</title><style>@page{size:A4 landscape;margin:12mm}body{font-family:Arial,Tahoma,sans-serif;color:#172033}h1{font-size:22px;margin:0 0 5px}p{margin:0 0 15px;color:#586579}table{width:100%;border-collapse:collapse;font-size:12px}th,td{border:1px solid #b9c3d0;padding:7px;text-align:left;vertical-align:top}th{background:#eaf0f7;color:#173b63}@media print{.toolbar{display:none}}.toolbar{margin-bottom:16px;padding:10px;background:#f4f6f9}button{padding:7px 14px}</style></head><body><div class="toolbar"><button onclick="window.print()">Print report</button></div><h1>'+esc(payload.trip.name||'Trip student report')+'</h1><p>Driver: '+esc(payload.trip.driver_name||'—')+' &nbsp; | &nbsp; Bus #: '+esc(payload.trip.bus_number||'—')+' &nbsp; | &nbsp; Students: '+rows.length+'</p><table><thead><tr><th>Student Name</th><th>Grade</th><th>Section</th><th>Father mobile</th><th>Mother mobile</th><th>Oracle address</th></tr></thead><tbody>'+body+'</tbody></table></body></html>';
            win.document.open();win.document.write(html);win.document.close();
        }).catch(function(error){win.close();feedback(error.message,true);});
    }
    function exportTripCsv(tripId){
        feedback('Preparing CSV report…');
        api('areas-workspace/shared-trips/'+tripId+'/badges').then(function(payload){
            var headers=['Trip name','Student Name','Grade','Section','Driver','Bus #','Father mobile','Mother mobile','Oracle address'];
            var rows=reportRows(payload).map(function(row){return [row.trip,row.student_name,row.grade,row.section,row.driver,row.bus,row.father_mobile,row.mother_mobile,row.address];});
            var csv='\ufeff'+[headers].concat(rows).map(function(row){return row.map(function(value){return '"'+String(value==null?'':value).replace(/"/g,'""')+'"';}).join(',');}).join('\r\n');
            var link=document.createElement('a');link.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'}));link.download='trip-students-'+tripId+'.csv';document.body.appendChild(link);link.click();link.remove();setTimeout(function(){URL.revokeObjectURL(link.href);},1000);feedback('CSV report downloaded.');
        }).catch(function(error){feedback(error.message,true);});
    }
    function printBadges(tripId){
        var win=window.open('','_blank');if(!win){feedback('Allow pop-ups to preview the badges.',true);return;}win.document.write('<p dir="rtl" style="font-family:Tahoma,Arial;padding:24px">جارٍ تجهيز البطاقات…</p>');
        feedback('Preparing badges…');api('areas-workspace/shared-trips/'+tripId+'/badges').then(function(payload){
            if(!payload.students.length)throw new Error('This trip has no students to print.');
            var cards=payload.students.map(function(student){return '<div class="student-badge"><div class="screen-name"><b>'+esc(student.student_name)+'</b><button onclick="printOne(this)">طباعة هذه البطاقة</button></div>'+badgeCard(student,payload.trip)+'</div>';}).join('');
            var style='@page{size:A4 portrait;margin:10mm}*{box-sizing:border-box}body{margin:0;background:#edf0f4;font-family:Tahoma,Arial,sans-serif;color:#172033}.toolbar{position:sticky;top:0;z-index:2;padding:12px;text-align:center;background:#fff;border-bottom:1px solid #d6dbe3}.toolbar button,.screen-name button{padding:7px 13px;border:1px solid #1f4b7a;border-radius:5px;background:#fff;color:#173b63;cursor:pointer}.student-badge{display:flex;justify-content:center;flex-wrap:wrap;margin:18px}.screen-name{display:flex;flex-basis:100%;justify-content:center;align-items:center;gap:12px;margin-bottom:9px;direction:rtl}.badge{position:relative;width:54mm;height:85.6mm;overflow:hidden;padding:6.5mm 3mm 2.4mm;direction:rtl;background:#fff;border:1px solid #aeb8c7;border-radius:2.5mm;box-shadow:0 2px 8px rgba(22,38,57,.08)}.lanyard{position:absolute;top:1.4mm;left:50%;width:13mm;height:2.6mm;transform:translateX(-50%);border:1px solid #aeb8c7;border-radius:3mm;background:#fff}.badge header{text-align:center;border-bottom:.45mm solid #234f7e;padding-bottom:.7mm}.badge header strong{display:block;color:#173b63;font-size:3.6mm}.badge header span{display:block;margin-top:.35mm;color:#5b6573;font-size:2.25mm}.badge h1{margin:1mm 0 .8mm;text-align:center;color:#111827;font-size:4.05mm;line-height:1.15}.summary{display:grid;grid-template-columns:1.12fr .9fr 1fr;border:1px solid #cbd3de;border-radius:1.5mm;overflow:hidden}.summary div{min-width:0;padding:.8mm .7mm;text-align:center;border-left:1px solid #d8dee7}.summary div:last-child{border-left:0}.summary small,.detail small,.family small{display:block;color:#5c6878;font-size:1.85mm;font-weight:700}.summary b{display:block;margin-top:.25mm;font-size:2.45mm;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.transport{display:grid;grid-template-columns:18.5mm 1fr;gap:2mm;margin-top:1mm;padding-bottom:.8mm;border-bottom:1px solid #d8dee7}.qr{text-align:center}.qr svg{display:block;width:17mm;height:17mm;margin:auto}.qr>small,.no-location{display:block;margin-top:.2mm;color:#5c6878;font-size:1.55mm}.staff-list{display:grid;align-content:center;gap:1mm}.staff-card{padding:1mm 1.2mm;border:1px solid #cbd3de;border-right:.8mm solid #234f7e;border-radius:1mm;background:#f8fafc}.staff-card small,.staff-card b,.staff-card span{display:block}.staff-card small{color:#5c6878;font-size:1.7mm;font-weight:700}.staff-card b{margin-top:.25mm;font-size:2.35mm;line-height:1.15}.staff-card span{margin-top:.3mm;color:#173b63;font-size:2.15mm;font-weight:700;direction:ltr;text-align:right}.family{display:grid;grid-template-columns:1fr;gap:.4mm;margin-top:.8mm}.family>div{display:grid;grid-template-columns:7mm 1fr;align-items:center;min-width:0;padding:.4mm .8mm;border:1px solid #d8dee7;border-radius:1mm}.family p{display:flex;align-items:center;gap:1mm;margin:0;white-space:nowrap}.family b,.family span{min-width:0;overflow:hidden;text-overflow:ellipsis;font-size:2.05mm;font-weight:700}.family span{color:#173b63;direction:ltr;text-align:right}.detail{margin-top:.7mm;padding:0 .5mm}.detail p{margin:.25mm 0 0;font-size:1.95mm;line-height:1.18;overflow-wrap:anywhere}.siblings p{display:grid;gap:.25mm}.siblings p span{display:flex;align-items:center;gap:1mm}.siblings p b{min-width:8mm}.siblings p em{font-style:normal;color:#5c6878}.address{padding-top:.5mm;border-top:1px dotted #c5ccd6}.badge footer{position:absolute;right:3mm;bottom:1.3mm;left:3mm;border-top:1px solid #d8dee7;padding-top:.55mm;text-align:center;color:#173b63;font-size:1.75mm;font-weight:700}.badge.is-dense .detail p{font-size:1.65mm;line-height:1.12}.badge.is-dense .family b,.badge.is-dense .family span{font-size:1.85mm}@media print{html,body{background:#fff}.toolbar,.screen-name{display:none}.badge-sheet{display:grid;grid-template-columns:repeat(3,54mm);grid-auto-rows:85.6mm;gap:3mm 4mm;justify-content:center;align-content:start;direction:ltr}.student-badge{display:block;width:54mm;height:85.6mm;margin:0;break-inside:avoid;page-break-inside:avoid}.badge{border:.2mm dashed #7c8796;border-radius:0;box-shadow:none;-webkit-print-color-adjust:exact;print-color-adjust:exact}.student-badge.is-hidden{display:none}}';
            win.document.write('<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>بطاقات المواصلات المدرسية</title><style>'+style+'</style></head><body><div class="toolbar"><button onclick="window.print()">طباعة جميع البطاقات على A4</button> &nbsp; صفحة A4 عمودية · 9 بطاقات في الصفحة · خطوط قص واضحة</div><main class="badge-sheet">'+cards+'</main><script>function printOne(b){document.querySelectorAll(".student-badge").forEach(function(x){x.classList.toggle("is-hidden",x!==b.closest(".student-badge"));});window.print();document.querySelectorAll(".student-badge").forEach(function(x){x.classList.remove("is-hidden");});}<\/script></body></html>');win.document.close();feedback('');
        }).catch(function(error){win.close();feedback(error.message,true);});
    }

    function validCoordinates(node){return node.location_status==='valid'&&Number.isFinite(Number(node.latitude))&&Number.isFinite(Number(node.longitude));}
    function destroyQueueMap(){if(queueMap){queueMap.remove();queueMap=null;}queueMarkers={};}
    function familyDetails(node){
        if(!node||node.node_type!=='family')return '';
        var children=(node.student_names||[]).map(function(name){return '<li>'+esc(name)+'</li>';}).join('');
        return '<div class="olama-family-queue-card"><div><span>Family number</span><strong>'+esc(node.oracle_family_id||'—')+'</strong></div><div><span>Family name</span><strong>'+esc(node.family_name||'—')+'</strong></div><div class="olama-family-queue-children"><span>Children in this trip</span><ul>'+(children||'<li>No child names available</li>')+'</ul></div></div>';
    }
    function showFamilyDetails(index,onMap){var node=(wizard.trip.queue||[])[index];if(!node||node.node_type!=='family')return;$('#olama-family-queue-details').html(familyDetails(node)).prop('hidden',false);if(onMap)$('#olama-map-family-details').html(familyDetails(node)+'<button type="button" class="olama-map-family-details-close" aria-label="Close">×</button>').prop('hidden',false);}
    function familyMapPopup(node,index){return '<div class="olama-map-family-popup"><strong>Family #'+esc(node.oracle_family_id||'—')+'</strong><span>'+esc(node.family_name||'—')+'</span><small>'+index+'. '+node.student_count+' students</small>'+familyDetails(node)+'</div>';}
    function renderQueueMap(){
        destroyQueueMap();
        var container=document.getElementById('olama-trip-queue-map');
        if(!container||typeof L==='undefined')return;
        var nodes=wizard.trip.queue||[],valid=nodes.filter(validCoordinates);
        queueMap=L.map(container,{scrollWheelZoom:false}).setView([31.9539,35.9106],12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'&copy; OpenStreetMap contributors',maxZoom:19}).addTo(queueMap);
        var bounds=[];
        valid.forEach(function(node){
            var index=nodes.indexOf(node)+1,isSchool=node.node_type==='school';
            var icon=L.divIcon({className:'olama-queue-map-icon-wrap',html:'<span class="olama-queue-map-marker '+(isSchool?'is-school':(node.dual_location?'is-dual':'is-family'))+'">'+(isSchool?'S':index)+'</span>',iconSize:[38,38],iconAnchor:[19,19],popupAnchor:[0,-20]});
            var latlng=[Number(node.latitude),Number(node.longitude)];
            var popup=isSchool?'<strong>School</strong><br>'+(wizard.trip.direction==='morning'?'Final stop':'Starting stop'):familyMapPopup(node,index);
            var marker=L.marker(latlng,{icon:icon}).addTo(queueMap).bindPopup(popup,{maxWidth:340});
            marker.on('click',function(){highlightQueueNode(index-1);if(!isSchool)showFamilyDetails(index-1,false);});
            queueMarkers[index-1]=marker;bounds.push(latlng);
        });
        if(bounds.length>1){L.polyline(bounds,{color:'#3f4de1',weight:3,opacity:.75,dashArray:'8 9'}).addTo(queueMap);queueMap.fitBounds(bounds,{paddingTopLeft:[35,95],paddingBottomRight:[35,35],maxZoom:15});}
        else if(bounds.length===1)queueMap.setView(bounds[0],14);
        setTimeout(function(){if(queueMap)queueMap.invalidateSize();},80);
    }
    function highlightQueueNode(index){$('.olama-shared-queue li').removeClass('is-map-active').filter('[data-node-index="'+index+'"]').addClass('is-map-active');$('.olama-missing-map-node').removeClass('is-active').filter('[data-node-index="'+index+'"]').addClass('is-active');}
    function renderQueue(){
        var queue=wizard.trip.queue||[],missing=queue.filter(function(node){return node.node_type==='family'&&!validCoordinates(node);});
        var nodes=queue.map(function(node,index){var cls=node.node_type==='school'?'is-school':(node.dual_location?'is-dual':(validCoordinates(node)?'is-family':'is-missing'));var label=node.node_type==='school'?'School':'View details for family '+(node.oracle_family_id||node.family_name);return '<li class="'+cls+'" data-node-index="'+index+'" tabindex="0" role="button" aria-label="'+esc(label)+'"><span>'+esc(node.node_type==='school'?'School':(node.oracle_family_id||node.family_name))+'</span><small>'+(node.node_type==='school'?(wizard.trip.direction==='morning'?'Final stop':'Starting stop'):(node.student_count+' students'+(!validCoordinates(node)?' · Location missing':'')))+'</small></li>';}).join('');
        var missingNodes=missing.map(function(node){var index=queue.indexOf(node);return '<button type="button" class="olama-missing-map-node" data-node-index="'+index+'" aria-label="View details for family '+esc(node.oracle_family_id||node.family_name)+'"><strong>'+esc(node.oracle_family_id||node.family_name)+'</strong><small>'+node.student_count+' students</small></button>';}).join('');
        var missingTray=missing.length?'<div class="olama-queue-map-missing"><strong>Missing locations: '+missing.length+'</strong><div>'+missingNodes+'</div></div>':'';
        var ack='';if(!published()&&wizard.trip.trip_excess)ack+='<label><input type="checkbox" id="olama-ack-trip"> I acknowledge +'+wizard.trip.trip_excess+' above the planning target.</label>';if(!published()&&wizard.trip.bus_excess)ack+='<label><input type="checkbox" id="olama-ack-bus"> I acknowledge +'+wizard.trip.bus_excess+' above bus capacity.</label>';
        panel(4).html('<div class="olama-queue-review"><div><h3>Family queue</h3><p>'+(published()?'Published queue shown for review. Select a family circle for details.':'Select a family circle to see its number, name, and children in this trip.')+'</p></div><ol class="olama-shared-queue">'+nodes+'</ol><aside id="olama-family-queue-details" class="olama-family-queue-details" aria-live="polite" hidden></aside><section class="olama-queue-map-section"><header><div><h3>Queue map preview</h3><p>Select any map marker to see the family number, name, and children.</p></div><div class="olama-queue-map-legend"><span class="is-school">School</span><span class="is-family">Valid location</span><span class="is-missing">Missing location</span></div></header><div class="olama-queue-map-wrap"><div id="olama-trip-queue-map" aria-label="Family queue map"></div>'+missingTray+'<aside id="olama-map-family-details" class="olama-map-family-details" hidden></aside></div></section><div class="olama-trip-acknowledgements">'+ack+'</div></div>');
        setTimeout(renderQueueMap,0);
    }
    function buildQueue(){var checked=selectedAreaIds().sort(function(a,b){return a-b;}),saved=(wizard.trip.area_ids||[]).map(Number).sort(function(a,b){return a-b;});if(JSON.stringify(checked)!==JSON.stringify(saved))return Promise.reject(new Error('Apply the area selection before continuing.'));return saveCurrentStudents().then(function(){return api('areas-workspace/shared-trips/'+wizard.trip.id+'/queue',{method:'POST',body:'{}'});}).then(function(trip){wizard.trip=trip;});}
    function publishTrip(){var tripAck=!wizard.trip.trip_excess||$('#olama-ack-trip').is(':checked'),busAck=!wizard.trip.bus_excess||$('#olama-ack-bus').is(':checked');if(!tripAck||!busAck)return Promise.reject(new Error('Acknowledge the capacity warnings before publishing.'));return api('areas-workspace/shared-trips/'+wizard.trip.id,{method:'PUT',body:JSON.stringify({trip_limit_acknowledged:tripAck,bus_limit_acknowledged:busAck})}).then(function(trip){return api('areas-workspace/shared-trips/'+trip.id+'/publish',{method:'POST',body:'{}'});}).then(function(){document.getElementById('olama-trip-wizard-dialog').close();return load('Trip published successfully.');});}
    function next(){if(published()){if(wizard.step===4){document.getElementById('olama-trip-wizard-dialog').close();return;}setStep(wizard.step+1);renderStep();return;}var action=wizard.step===1?saveDetails():(wizard.step===2?saveBus():(wizard.step===3?buildQueue():publishTrip()));$('#olama-trip-wizard-next').prop('disabled',true);Promise.resolve(action).then(function(){if(wizard.step<4){setStep(wizard.step+1);renderStep();}wizardFeedback('');updateSummary();}).catch(function(error){wizardFeedback(error.message,true);}).finally(function(){$('#olama-trip-wizard-next').prop('disabled',false);});}
    function renderStep(){if(wizard.step===1)renderDetails();if(wizard.step===2)renderBus();if(wizard.step===3)renderAreas();if(wizard.step===4)renderQueue();}
    function returnToDraft(){if(!window.confirm('Return this trip to draft? Live assignments will be withdrawn until it is published again.'))return;api('areas-workspace/shared-trips/'+wizard.trip.id+'/return-to-draft',{method:'POST',body:'{}'}).then(function(trip){wizard.trip=trip;setStep(1);renderDetails();wizardFeedback('Trip returned to draft.');}).catch(function(error){wizardFeedback(error.message,true);});}
    function deleteDraft(){if(!window.confirm('Delete this draft permanently? Its areas, students, and queue will be removed.'))return;api('areas-workspace/shared-trips/'+wizard.trip.id,{method:'DELETE'}).then(function(){document.getElementById('olama-trip-wizard-dialog').close();return load('Draft deleted.');}).catch(function(error){wizardFeedback(error.message,true);});}

    $(function(){
        app=$('#olama-areas-workspace');if(!app.length)return;
        var params=new URLSearchParams(window.location.search),linkedTrip=Number(params.get('trip_id')||0),linkedDirection=params.get('direction');
        if(linkedDirection==='morning'||linkedDirection==='afternoon')$('#areas-direction').val(linkedDirection);
        load().then(function(){if(linkedTrip)openTrip(linkedTrip);});
        $('#areas-year,#areas-direction').on('change',load);
        $('#areas-refresh-core').on('click',function(){api('core/refresh-areas',{method:'POST',body:'{}'}).then(function(){return load('Areas refreshed from Olama Core.');}).catch(function(error){feedback(error.message,true);});});
        $('#olama-create-trip-plan').on('click',createTrip);
        $(document).on('click','.olama-open-trip',function(event){event.stopPropagation();openTrip(Number($(this).data('trip')));});
        $(document).on('click','.olama-print-badges',function(event){event.stopPropagation();printBadges(Number($(this).data('trip')));});
        $(document).on('click','.olama-print-trip-report',function(event){event.stopPropagation();openTripReport(Number($(this).data('trip')));});
        $(document).on('click','.olama-export-trip-csv',function(event){event.stopPropagation();exportTripCsv(Number($(this).data('trip')));});
        $(document).on('click','.olama-area-clickable',function(){var area=(data.areas||[]).find(function(item){return String(item.id)===String($(this).closest('tr').data('area'));},this);if(!area)return;var box=$(this).data('box'),families=area.family_details||[];if(box==='walking')families=families.filter(function(f){return Number(f.transportation_student_count||0)===0;});if(box==='unassigned')families=families.filter(function(f){return Number(f.transportation_student_count||0)>0;});$('#olama-area-family-title').text(area.name+' · '+(box==='walking'?'Walking Students':box==='assigned'?'Assigned':'Families'));$('#olama-area-family-list').html(families.length?families.map(function(f){return '<article class="olama-area-family-item"><strong>Family #'+esc(f.family_number)+'</strong><span>Father: '+esc(f.father_name||'—')+'</span><div>'+(f.kids||[]).map(function(k){return '<small>'+esc((k.name||'').split(/\s+/)[0])+' · '+esc((k.grade||'—')+' - '+(k.section||'—'))+'</small>';}).join('')+'</div>'+(f.maps_url?'<a target="_blank" rel="noopener" href="'+esc(f.maps_url)+'">Map location</a>':'')+'</article>';}).join(''):'<p>No families in this category.</p>');var dialog=document.getElementById('olama-area-family-dialog');if(dialog.showModal)dialog.showModal();});
        $(document).on('change','.areas-color',function(){var row=$(this).closest('tr');api('areas-workspace/'+row.data('area'),{method:'PUT',body:JSON.stringify({color:row.find('.areas-color').val()})}).then(function(){return load('Area settings saved.');}).catch(function(error){feedback(error.message,true);});});
        $(document).on('change','#olama-trip-bus',function(){populateSlots();updateBusPreview();});
        $(document).on('click','#olama-save-trip-areas',saveAreas);
        $(document).on('change','#olama-trip-area-editor',function(){loadAreaCandidates(this.value);});
        $(document).on('click','#olama-save-area-students',saveCurrentStudents);
        $(document).on('click','#olama-select-all-students',selectAllStudents);
        $(document).on('click','#olama-clear-student-selection',clearStudentSelection);
        $(document).on('change','.olama-trip-student',function(){selectFamilySiblings(this.value,this.checked);rememberStudentSelection();updateStudentSelectionCount();});
        $(document).on('change','#olama-show-nonsubscribers',function(){rememberStudentSelection();wizard.showAll=this.checked;renderAreaStudents();});
        $(document).on('click','.olama-shared-queue li',function(){var index=Number($(this).data('node-index'));highlightQueueNode(index);showFamilyDetails(index,false);if(queueMap&&queueMarkers[index]){queueMap.panTo(queueMarkers[index].getLatLng());queueMarkers[index].openPopup();}});
        $(document).on('keydown','.olama-shared-queue li',function(event){if(event.key==='Enter'||event.key===' '){event.preventDefault();$(this).trigger('click');}});
        $(document).on('click','.olama-missing-map-node',function(){var index=Number($(this).data('node-index'));highlightQueueNode(index);showFamilyDetails(index,true);});
        $(document).on('click','.olama-map-family-details-close',function(){$('#olama-map-family-details').prop('hidden',true).empty();});
        $(document).on('click','#olama-return-trip-to-draft',returnToDraft);
        $(document).on('click','#olama-delete-trip-draft',deleteDraft);
        $('#olama-trip-wizard-next').on('click',next);
        $('#olama-trip-wizard-back').on('click',function(){if(wizard.step>1){setStep(wizard.step-1);renderStep();}});
        $('.olama-trip-wizard-close').on('click',function(){destroyQueueMap();document.getElementById('olama-trip-wizard-dialog').close();load();});
    });
})(jQuery);
