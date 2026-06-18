<?php

$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
$_plugin_dir = dirname(__DIR__, 2);

$autoload = $_plugin_dir . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_plugin_dir . '/vendor/yoast/phpunit-polyfills');
}

require_once $_tests_dir . '/includes/functions.php';

function waa_tests_load_plugin(): void {
    require dirname(__DIR__, 2) . '/wp-admin-agent.php';
}

tests_add_filter('muplugins_loaded', 'waa_tests_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';
