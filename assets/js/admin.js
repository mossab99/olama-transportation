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
            $('#bus-plate-number').val(bus.plate_number);
            $('#bus-capacity').val(bus.passenger_capacity);
            $('#bus-license-expiry').val(bus.license_expiry_date ? olamaFormatDate(bus.license_expiry_date) : '');
            $('#bus-driver-id').val(bus.driver_user_id);
            $('#bus-companion-id').val(bus.companion_user_id);
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
})(jQuery);
