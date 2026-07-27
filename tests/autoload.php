<?php
// bootstrap file used only by tests

require __DIR__ . '/constants.php';

error_reporting((E_ALL | E_USER_ERROR | E_USER_WARNING | E_USER_NOTICE) & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$GLOBALS['wp_version'] = $GLOBALS['wp_version'] ?? '6.4.0';
$GLOBALS['locale'] = $GLOBALS['locale'] ?? 'en_US';

if (!function_exists('translate')) {
    function translate($text, $domain = null)
    {
        return $text;
    }
}


if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return (string) $url;
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($html)
    {
        // In tests, pass-through to simplify; production will use WP's kses.
        return (string) $html;
    }
}

/**
 * Mirror of wp_strip_all_tags() for the tests that need its real behaviour.
 *
 * Deliberately not named wp_strip_all_tags: anything declared in this bootstrap
 * is loaded before Patchwork and can no longer be redefined, and some tests do
 * stub that function. Those that want the real behaviour alias it to this with
 * Functions\when('wp_strip_all_tags')->alias('sucuriscan_test_strip_all_tags').
 *
 * @param mixed $text          Value to strip.
 * @param bool  $remove_breaks Collapse whitespace runs into a single space.
 * @return string Stripped value.
 */
function sucuriscan_test_strip_all_tags($text, $remove_breaks = false)
{
    if (!is_scalar($text)) {
        return '';
    }

    $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
    $text = strip_tags((string) $text);

    if ($remove_breaks) {
        $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
    }

    return trim($text);
}

if (file_exists(BASE_DIR . '/vendor/autoload.php')) {
    require BASE_DIR . '/vendor/autoload.php';
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($s)
    {
        return 'nonce';
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('network_admin_url')) {
    function network_admin_url($path = '')
    {
        return 'https://example.com/wp-admin/network/' . ltrim($path, '/');
    }
}


if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value)
    {
        return $value;
    }
}

if (!function_exists('current_user_can')) {
    /**
     * Test stub for current_user_can. Accepts optional cap for compatibility.
     *
     * Defaults to true so existing tests are unaffected; a test that needs to exercise a
     * denied path can set $GLOBALS['__test_current_user_can'] to false.
     *
     * @param mixed $cap Optional capability name (ignored in tests).
     * @return bool Result, overridable via $GLOBALS['__test_current_user_can'].
     */
    function current_user_can($cap = null)
    {
        return array_key_exists('__test_current_user_can', $GLOBALS)
            ? (bool) $GLOBALS['__test_current_user_can']
            : true;
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax()
    {
        return false;
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user()
    {
        return (object) ['user_login' => 'admin', 'user_email' => 'admin@example.com', 'display_name' => 'Admin'];
    }
}

require BASE_DIR . '/src/base.lib.php';
require BASE_DIR . '/src/request.lib.php';
require BASE_DIR . '/src/fileinfo.lib.php';
require BASE_DIR . '/src/cache.lib.php';
require BASE_DIR . '/src/option.lib.php';
require BASE_DIR . '/src/cron.lib.php';
require BASE_DIR . '/src/event.lib.php';
require BASE_DIR . '/src/hook.lib.php';
require BASE_DIR . '/src/api.lib.php';
require BASE_DIR . '/src/mail.lib.php';
require BASE_DIR . '/src/command.lib.php';
require BASE_DIR . '/src/template.lib.php';
require BASE_DIR . '/src/permissions.lib.php';
if (!function_exists('sucuriscanMainPages')) {
    function sucuriscanMainPages()
    {
        return array(
            'sucuriscan' => 'Dashboard',
            'sucuriscan_firewall' => 'Firewall',
            'sucuriscan_settings' => 'Settings',
        );
    }
}
require BASE_DIR . '/src/fsscanner.lib.php';
require BASE_DIR . '/src/hardening.lib.php';
require BASE_DIR . '/src/interface.lib.php';
require BASE_DIR . '/src/auditlogs.lib.php';
require BASE_DIR . '/src/sitecheck.lib.php';
require BASE_DIR . '/src/wordpress-recommendations.lib.php';
require BASE_DIR . '/src/integrity.lib.php';
require BASE_DIR . '/src/firewall.lib.php';
require BASE_DIR . '/src/installer-skin.lib.php';
require BASE_DIR . '/src/cachecontrol.lib.php';
require BASE_DIR . '/src/topt.lib.php';