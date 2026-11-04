<?php
/**
 * SettingsSaver: pure logic behind the admin "save settings" forms.
 *
 * Takes the current config, a $_REQUEST-like array and a field spec and returns
 * the new config plus per-field errors. No MajorDoMo, no globals, no I/O, so it
 * is unit-testable.
 *
 * Rules:
 *  - checkbox: a browser does NOT submit an unchecked checkbox. Each form lists
 *    the checkboxes it contains in a hidden "_cb" field (comma separated); a
 *    listed checkbox that is absent from the request is stored as 0. A checkbox
 *    not listed (it lives in another form) is left alone.
 *  - text: stored verbatim (quotes, Cyrillic, everything). Missing -> unchanged.
 *  - secret: the mask "********" means "unchanged".
 *  - numeric: must be numeric, otherwise error and the previous value is kept.
 *  - json_object: empty, or a valid JSON object; otherwise error and the
 *    previous value is kept.
 *  - Rejected raw input is returned in 'rejected' so the form can show it back
 *    (user input is never discarded silently).
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class SettingsSaver
{
    const MASK = '********';

    /** Field spec for the module settings. */
    public static function spec()
    {
        return array(
            'checkbox' => array(
                'ESP_DIGEST', 'ALLOW_BATTERY_HEAT', 'SOLAR_SURPLUS_ENABLED',
                'PRESENCE_ENABLED', 'SIMPLE_DEVICES_ENABLED', 'CHARGE_PRIORITY_ENABLED',
                'TARIFF_ENABLED',
            ),
            'text' => array(
                'ESP_HOST', 'ESP_USER', 'TIMEZONE', 'POWER_PROPERTY', 'BATTERY_ACTIVE_VALUE',
                'SOC_PROPERTY', 'FORECAST_TODAY_PROP', 'FORECAST_TOMORROW_PROP',
                'FORECAST_UPDATED_PROP', 'FORECAST_PROVIDER_PROP', 'PRESENCE_HANDLER',
                'BOILER_OBJECT',
            ),
            'secret' => array('ESP_PASS'),
            'numeric' => array(
                'ESP_PORT', 'POLL_INTERVAL_S', 'COMFORT_MIN_C', 'NIGHT_TARGET_C',
                'SOLAR_TARGET_C', 'BOOST_TARGET_C', 'ABSOLUTE_MAX_C',
                'CHEAP_START_HOUR', 'CHEAP_START_MIN', 'CHEAP_END_HOUR', 'CHEAP_END_MIN',
                'METER_OFFSET_SECONDS', 'START_GUARD_SECONDS', 'END_GUARD_SECONDS',
                'MAX_CONTINUOUS_HEAT_S', 'MIN_PAUSE_S', 'LEASE_TTL_S', 'COMMAND_TIMEOUT_S',
                'POWER_FRESHNESS_TTL_S', 'SURPLUS_SOC_HIGH', 'SURPLUS_SOC_LOW',
                'CHARGE_PRIORITY_SOC', 'CHARGE_PRIORITY_HYST',
                'FORECAST_FRESHNESS_TTL_S', 'BOOST_TTL_S', 'PRESENCE_ABSENT_AFTER_S',
                'PRESENCE_NOTIFY_AFTER_S', 'PRESENCE_TOO_LONG_AFTER_S',
                'PRESENCE_RENOTIFY_EVERY_S',
                'PRESENCE_RETENTION_DAYS', 'TELEMETRY_RETENTION_DAYS', 'COMMANDS_RETENTION_DAYS',
            ),
            'json_object' => array('PRESENCE_ROSTER_JSON', 'SIMPLE_DEVICES_MAP_JSON'),
        );
    }

    /**
     * @param array      $config  current config
     * @param array      $request $_REQUEST-like
     * @param array|null $spec    see spec()
     * @return array config(new), errors(field=>code 'numeric'|'json'),
     *               rejected(field=>raw input), changed(list of fields)
     */
    public static function apply($config, $request, $spec = null)
    {
        if ($spec === null) {
            $spec = self::spec();
        }
        $new = is_array($config) ? $config : array();
        $errors = array();
        $rejected = array();
        $changed = array();

        $declared = array();
        if (isset($request['_cb']) && is_string($request['_cb'])) {
            foreach (explode(',', $request['_cb']) as $name) {
                $declared[trim($name)] = true;
            }
        }

        $set = function ($f, $v) use (&$new, &$changed) {
            $old = array_key_exists($f, $new) ? $new[$f] : null;
            if ($old === null || (string) $old !== (string) $v) {
                $changed[] = $f;
            }
            $new[$f] = $v;
        };

        foreach (self::group($spec, 'checkbox') as $f) {
            if (isset($request[$f])) {
                $set($f, self::truthy($request[$f]) ? 1 : 0);
            } elseif (isset($declared[$f])) {
                $set($f, 0);
            }
        }

        foreach (self::group($spec, 'text') as $f) {
            if (isset($request[$f]) && is_string($request[$f])) {
                $set($f, $request[$f]);
            }
        }

        foreach (self::group($spec, 'secret') as $f) {
            if (isset($request[$f]) && is_string($request[$f]) && $request[$f] !== self::MASK) {
                $set($f, $request[$f]);
            }
        }

        foreach (self::group($spec, 'numeric') as $f) {
            if (!isset($request[$f]) || !is_string($request[$f])) {
                continue;
            }
            $t = trim($request[$f]);
            if ($t !== '' && is_numeric($t)) {
                $set($f, $t);
            } else {
                $errors[$f] = 'numeric';
                $rejected[$f] = $request[$f];
            }
        }

        foreach (self::group($spec, 'json_object') as $f) {
            if (!isset($request[$f]) || !is_string($request[$f])) {
                continue;
            }
            $t = trim($request[$f]);
            if ($t === '' || self::isJsonObject($t)) {
                $set($f, $t);
            } else {
                $errors[$f] = 'json';
                $rejected[$f] = $request[$f];
            }
        }

        return array(
            'config'   => $new,
            'errors'   => $errors,
            'rejected' => $rejected,
            'changed'  => $changed,
        );
    }

    /** True if $s is a syntactically valid JSON object ("{...}"). */
    public static function isJsonObject($s)
    {
        if (!is_string($s) || $s === '' || $s[0] !== '{') {
            return false;
        }
        $d = json_decode($s, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($d);
    }

    /**
     * HTML-escape a value for an attribute or element body (UTF-8, quotes
     * included). Non-scalars are JSON-encoded first.
     */
    public static function esc($v)
    {
        if ($v === null) {
            return '';
        }
        if (!is_scalar($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function group($spec, $name)
    {
        return (isset($spec[$name]) && is_array($spec[$name])) ? $spec[$name] : array();
    }

    private static function truthy($v)
    {
        if (is_array($v)) {
            return false;
        }
        return in_array(strtolower(trim((string) $v)), array('1', 'on', 'true', 'yes'), true);
    }
}
