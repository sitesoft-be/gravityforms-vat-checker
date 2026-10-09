<?php

/**
 * Minimal WordPress / Gravity Forms stand-ins so the plugin classes can be
 * tested without a WordPress install. Behaviour mirrors core where it matters
 * (transients with expiry, object cache add() semantics, JSON responses).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

final class WP_Test_State
{
    public static array $transients = [];
    public static array $object_cache = [];
    public static bool $ext_object_cache = false;
    public static array $filters = [];
    public static array $actions = [];
    public static bool $nonce_valid = true;
    public static array $log = [];
    public static string $environment = 'production';

    public static function reset(): void
    {
        self::$transients       = [];
        self::$object_cache     = [];
        self::$ext_object_cache = false;
        self::$filters          = [];
        self::$actions          = [];
        self::$nonce_valid      = true;
        self::$log              = [];
        self::$environment      = 'production';
        $_POST                  = [];
    }
}

final class WP_Test_Json_Response extends RuntimeException
{
    public function __construct(public array $payload)
    {
        parent::__construct('wp_send_json');
    }
}

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html__($text, $domain = 'default')
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_attr__($text, $domain = 'default')
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function absint($value)
{
    return abs((int) $value);
}

function selected($selected, $current = true, $display = true)
{
    return (string) $selected === (string) $current ? " selected='selected'" : '';
}

function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    WP_Test_State::$filters[ $hook ][] = $callback;
}

function apply_filters($hook, $value, ...$args)
{
    foreach (WP_Test_State::$filters[ $hook ] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }

    return $value;
}

function gf_apply_filters($hook, $value, ...$args)
{
    return $value;
}

function add_action($hook, $callback, $priority = 10, $args = 1)
{
}

function do_action($hook, ...$args)
{
    WP_Test_State::$actions[] = [ $hook, $args ];
}

function wp_salt($scheme = 'auth')
{
    return 'unit-test-salt-' . $scheme;
}

function wp_get_environment_type()
{
    return WP_Test_State::$environment;
}

function wp_using_ext_object_cache()
{
    return WP_Test_State::$ext_object_cache;
}

function get_transient($key)
{
    $entry = WP_Test_State::$transients[ $key ] ?? null;
    if (! $entry || $entry['expires'] <= time()) {
        unset(WP_Test_State::$transients[ $key ]);

        return false;
    }

    return $entry['value'];
}

function set_transient($key, $value, $ttl = 0)
{
    WP_Test_State::$transients[ $key ] = [
        'value'   => $value,
        'ttl'     => $ttl,
        'expires' => time() + $ttl,
    ];

    return true;
}

function delete_transient($key)
{
    unset(WP_Test_State::$transients[ $key ]);

    return true;
}

function wp_cache_get($key, $group = '', $force = false)
{
    $entry = WP_Test_State::$object_cache[ $group ][ $key ] ?? null;
    if (! $entry || ($entry['expires'] && $entry['expires'] <= time())) {
        return false;
    }

    return $entry['value'];
}

function wp_cache_set($key, $value, $group = '', $ttl = 0)
{
    WP_Test_State::$object_cache[ $group ][ $key ] = [
        'value'   => $value,
        'ttl'     => $ttl,
        'expires' => $ttl ? time() + $ttl : 0,
    ];

    return true;
}

function wp_cache_add($key, $value, $group = '', $ttl = 0)
{
    if (wp_cache_get($key, $group) !== false) {
        return false;
    }

    return wp_cache_set($key, $value, $group, $ttl);
}

function wp_cache_delete($key, $group = '')
{
    unset(WP_Test_State::$object_cache[ $group ][ $key ]);

    return true;
}

function check_ajax_referer($action = -1, $query_arg = false, $stop = true)
{
    return WP_Test_State::$nonce_valid ? 1 : false;
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function wp_send_json_success($data = null)
{
    throw new WP_Test_Json_Response([ 'success' => true, 'data' => $data ]);
}

function wp_send_json_error($data = null)
{
    throw new WP_Test_Json_Response([ 'success' => false, 'data' => $data ]);
}

function rgpost($name)
{
    return $_POST[ $name ] ?? '';
}

// Gravity Forms stand-ins.
class GF_Field_Text extends stdClass
{
    public $id = 1;
    public $failed_validation = false;
    public $validation_message = '';
    public $errorMessage = '';
    public $size = 'large';
    public $maxLength = '';
    public $isRequired = false;
    public $enableAutocomplete = false;
    public $euvatNameField = '';
    public $euvatStreetField = '';
    public $euvatZipField = '';
    public $euvatCityField = '';
    public $euvatCountryField = '';

    public function get_form_editor_field_settings()
    {
        return [];
    }

    public function is_entry_detail()
    {
        return false;
    }

    public function is_form_editor()
    {
        return false;
    }

    public function get_tabindex()
    {
        return '';
    }

    public function get_field_placeholder_attribute()
    {
        return '';
    }

    public function get_aria_describedby()
    {
        return '';
    }

    public function get_field_autocomplete_attribute()
    {
        return '';
    }
}

class GF_Fields
{
    public static function register($field)
    {
    }
}

$plugin_dir = dirname(__DIR__) . '/includes/';
require_once $plugin_dir . 'class-vat-result.php';
require_once $plugin_dir . 'class-vat-number.php';
require_once $plugin_dir . 'class-vat-cache.php';
require_once $plugin_dir . 'class-vat-logger.php';
require_once $plugin_dir . 'class-vies-client.php';
require_once $plugin_dir . 'class-vat-validation-service.php';
require_once $plugin_dir . 'class-eu-vat-api.php';
require_once $plugin_dir . 'class-ajax-handler.php';
require_once $plugin_dir . 'class-gf-field-euvat.php';

require_once __DIR__ . '/php/Support/FakeSoap.php';
require_once __DIR__ . '/php/Support/TestCase.php';
