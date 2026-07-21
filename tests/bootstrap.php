<?php

$tests_dir = getenv('WP_TESTS_DIR');
if (!$tests_dir) {
    $tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}
require_once $tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
    require dirname(__DIR__) . '/olama-transportation.php';
    require_once dirname(__DIR__) . '/includes/class-audit.php';
    require_once dirname(__DIR__) . '/includes/class-repository.php';
    require_once dirname(__DIR__) . '/includes/class-routes.php';
    require_once dirname(__DIR__) . '/includes/class-bus.php';
    require_once dirname(__DIR__) . '/includes/class-family-locations.php';
    require_once dirname(__DIR__) . '/includes/class-area-sync.php';
    require_once dirname(__DIR__) . '/includes/class-map-data.php';
    require_once dirname(__DIR__) . '/includes/class-geographic-planning.php';
});

require $tests_dir . '/includes/bootstrap.php';
