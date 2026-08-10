(function ($) {
    function t(key) {
        return (window.olamaTransportation && olamaTransportation.i18n && olamaTransportation.i18n[key]) || key;
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
            $('#bus-companion-id').val(bus.companion_user_id);
            $('#bus-main-area-id').val(bus.main_area_id);
            $('#bus-allow-multi-area').prop('checked', Number(bus.allow_multi_area || 0) === 1);
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

        var currentBusId = 0;
        var currentYearId = 0;

        function loadBusAssignments() {
            var busId = $('#assignment-bus-filter').val();
            var yearId = $('#assignment-year-filter').val();

            if (!busId) {
                $('#assignment-content').hide();
                $('#no-bus-selected').show();
                return;
            }

            currentBusId = busId;
            currentYearId = yearId;
            $('#no-bus-selected').hide();
            $('#assignment-content').show();

            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'olama_get_bus_students',
                    bus_id: busId,
                    academic_year_id: yearId,
                    nonce: nonce()
                },
                success: function (response) {
                    if (response.success) {
                        updateCapacityInfo(response.data.capacity);
                        renderAssignedStudents(response.data.students);
                    } else {
                        alert(response.data);
                    }
                }
            });

            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'olama_get_unassigned_students',
                    academic_year_id: yearId,
                    nonce: nonce()
                },
                success: function (response) {
                    if (response.success) {
                        renderUnassignedStudents(response.data.students || response.data);
                    } else {
                        alert(response.data);
                    }
                }
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

        function renderAssignedStudents(students) {
            var $tbody = $('#assigned-students-body').empty();
            if (!students.length) {
                $tbody.append('<tr><td colspan="5">' + t('noStudentsAssigned') + '</td></tr>');
                return;
            }

            students.forEach(function (student) {
                $('<tr>')
                    .append($('<td>').text(student.student_name || ''))
                    .append($('<td>').text(student.student_uid || ''))
                    .append($('<td>').text(student.grade_name || ''))
                    .append($('<td>').text(student.section_name || ''))
                    .append($('<td>').append($('<button class="button button-small unassign-btn" type="button">').attr('data-student-id', student.id).text(t('unassign'))))
                    .appendTo($tbody);
            });
        }

        function renderUnassignedStudents(students) {
            var $tbody = $('#unassigned-students-body').empty();
            if (!students.length) {
                $tbody.append('<tr><td colspan="5">' + t('allStudentsAssigned') + '</td></tr>');
                return;
            }

            students.forEach(function (student) {
                $('<tr>')
                    .append($('<td>').append($('<input type="checkbox" class="student-checkbox" />').val(student.id)))
                    .append($('<td>').text(student.student_name || ''))
                    .append($('<td>').text(student.student_uid || ''))
                    .append($('<td>').text(student.grade_name || ''))
                    .append($('<td>').text(student.section_name || ''))
                    .appendTo($tbody);
            });
        }

        function updateAssignButton() {
            $('#assign-selected-btn').prop('disabled', $('.student-checkbox:checked').length === 0);
        }

        $('#assignment-bus-filter, #assignment-year-filter').on('change', loadBusAssignments);
        $('#select-all-students').on('change', function () {
            $('.student-checkbox').prop('checked', $(this).prop('checked'));
            updateAssignButton();
        });
        $(document).on('change', '.student-checkbox', updateAssignButton);

        $('#assign-selected-btn').on('click', function () {
            var studentIds = $('.student-checkbox:checked').map(function () {
                return $(this).val();
            }).get();

            if (!studentIds.length || !confirm(t('assignSelectedConfirm'))) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'olama_assign_students_to_bus',
                    bus_id: currentBusId,
                    student_ids: studentIds,
                    academic_year_id: currentYearId,
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

        $(document).on('click', '.unassign-btn', function () {
            if (!confirm(t('unassignStudentConfirm'))) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'olama_unassign_student_from_bus',
                    student_id: $(this).data('student-id'),
                    academic_year_id: currentYearId,
                    nonce: nonce()
                },
                success: function (response) {
                    if (response.success) {
                        alert(response.data);
                        loadBusAssignments();
                    } else {
                        alert(response.data);
                    }
                }
            });
        });

        loadBusAssignments();
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

    $(document).on('click', '.olama-optimize-route', function () {
        var id = $(this).data('id');
        $(this).prop('disabled', true);
        rest('routes/' + id + '/optimize', {method: 'POST'})
            .then(function () { window.location.reload(); })
            .catch(function (error) { alert(error.message); window.location.reload(); });
    });

    $(document).on('click', '.olama-publish-route', function () {
        if (!window.confirm('Publish this immutable route version?')) {
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
        if (!ids.length) { alert('Select at least one family location.'); return; }
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
        rest('settings', {method: 'PUT', body: values})
            .then(function () { $('#settings-result').text(t('saved')); })
            .catch(function (error) { $('#settings-result').text(error.message); });
    });

    $('#refresh-core-buses').on('click', function () {
        var $button = $(this).prop('disabled', true);
        rest('core/refresh-buses', {method: 'POST'})
            .then(function (result) {
                $('#settings-result').text('Created: ' + result.created + ', updated: ' + result.updated + ', deactivated: ' + result.deactivated);
                window.location.reload();
            })
            .catch(function (error) {
                $('#settings-result').text(error.message);
                $button.prop('disabled', false);
            });
    });
})(jQuery);
