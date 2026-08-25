(function ($) {
    'use strict';
    var direction = 'all';

    function filterTrips() {
        var status = $('#olama-dashboard-status').val() || 'all';
        var search = ($('#olama-dashboard-trip-search').val() || '').toLowerCase().trim();
        var visible = 0;
        $('[data-dashboard-trip]').each(function () {
            var row = $(this);
            var matches = (direction === 'all' || row.data('direction') === direction)
                && (status === 'all' || row.data('status') === status)
                && (!search || String(row.data('search') || '').indexOf(search) !== -1);
            row.toggle(matches);
            if (matches) visible++;
        });
        $('#olama-dashboard-no-results').prop('hidden', visible > 0 || !$('[data-dashboard-trip]').length);
    }

    $(function () {
        if (!$('#olama-operations-dashboard').length) return;
        $('[data-dashboard-direction]').on('click', function () {
            direction = $(this).data('dashboard-direction');
            $('[data-dashboard-direction]').removeClass('is-active').attr('aria-pressed', 'false');
            $(this).addClass('is-active').attr('aria-pressed', 'true');
            filterTrips();
        });
        $('#olama-dashboard-status').on('change', filterTrips);
        $('#olama-dashboard-trip-search').on('input', filterTrips);
    });
})(jQuery);
