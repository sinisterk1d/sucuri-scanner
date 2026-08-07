<?php

// Define the constant with an empty value to stop
// the `undefined constant WP_CONTENT_DIR` errors when running tests.
define('WP_CONTENT_DIR', '');
define('SUCURISCAN_INIT', true);
define('SUCURISCAN', 'sucuriscan');
define('SUCURISCAN_VERSION', 'test');
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define('BASE_DIR', __DIR__ . '/..');
define('SUCURISCAN_URL', 'https://example.com/wp-content/plugins/sucuri-scanner');
define('SUCURISCAN_PLUGIN_PATH', BASE_DIR);

// Mirrors the audit log values defined by sucuri.php at bootstrap.
define('SUCURISCAN_AUDITLOGS_LIFETIME', 600);
define('SUCURISCAN_AUDITLOGS_PER_PAGE', 25);
define('SUCURISCAN_MAX_PAGINATION_BUTTONS', 16);

// TODO: Copy fixtures to a temporary location in the future,
// so they can be mutated when needed.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define('SUCURI_DATA_STORAGE', BASE_DIR . '/tests/fixtures');
define('ABSPATH', __DIR__ . '/../');
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}
