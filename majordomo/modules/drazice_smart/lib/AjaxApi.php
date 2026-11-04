<?php
/**
 * AjaxApi: pure helpers for the admin-only AJAX JSON endpoints
 * (pattern of the sibling solarPlanner/onCharger modules: inside admin(),
 * `if ajax -> header json; echo json_encode; exit`).
 *
 * Authorization model:
 *   - admin() is only reached through the authenticated MajorDoMo admin panel;
 *   - READ ops need no CSRF token and allow GET or POST;
 *   - MUTATING ops (including the outbound LAN actions probe_host/scan_network)
 *     need POST + a valid CSRF token. Order: classify -> authorize BEFORE any
 *     other work, so a rejected op makes no ESP call and changes no config.
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class AjaxApi
{
    const READ_OPS = array('status', 'diag', 'pairing', 'ble_scan', 'log', 'policy');

    const MUTATING_OPS = array(
        'save_settings', 'send_pin', 'request_target', 'request_mode', 'boost', 'cancel_boost',
        'enable_automation', 'disable_automation', 'set_dry_run', 'release_ble', 'reconnect_ble',
        'lease_renew', 'ble_scan_clear', 'probe_host', 'scan_network', 'select_boiler',
        'provision_simple_devices', 'remove_simple_devices', 'save_vacation', 'clear_vacation', 'set_hdo', 'create_scene',
    );

    /** True iff the request carries ajax=1. */
    public static function isAjaxRequest($req)
    {
        return is_array($req) && isset($req['ajax']) && $req['ajax'] === '1';
    }

    /** 'read' | 'mutating' | null (unknown / empty / non-string). */
    public static function classify($op)
    {
        if (!is_string($op) || $op === '') {
            return null;
        }
        if (in_array($op, self::READ_OPS, true)) {
            return 'read';
        }
        if (in_array($op, self::MUTATING_OPS, true)) {
            return 'mutating';
        }
        return null;
    }

    /**
     * @return array ok(bool), status(int HTTP), error(string: '' | unknown_op | method | csrf)
     */
    public static function authorize($op, $httpMethod, $csrfOk)
    {
        $kind = self::classify($op);
        if ($kind === null) {
            return array('ok' => false, 'status' => 400, 'error' => 'unknown_op');
        }
        if ($kind === 'read') {
            return array('ok' => true, 'status' => 200, 'error' => '');
        }
        if (strtoupper((string) $httpMethod) !== 'POST') {
            return array('ok' => false, 'status' => 405, 'error' => 'method');
        }
        if (!$csrfOk) {
            return array('ok' => false, 'status' => 403, 'error' => 'csrf');
        }
        return array('ok' => true, 'status' => 200, 'error' => '');
    }

    /**
     * Short names of the LANG_DS_* constants the JS needs (JS reads
     * DS_I18N.<NAME>). Names missing from the language files become '' in
     * jsStrings() (the JS ships no hard-coded user-facing text).
     */
    public static function i18nKeys()
    {
        return array(
            'LOADING', 'DATA_UPDATED', 'DATA_AGO', 'DATA_STALE', 'DATA_NONE', 'DATA_LAST_FAIL',
            'REFRESH', 'FETCH_ERROR', 'AJAX_NETWORK_ERROR', 'SAVING',
            'SAVE_OK', 'SAVE_FAIL', 'SAVE_ERR_NUM', 'SAVE_ERR_JSON', 'SAVE_ENTERED', 'SAVE_EMPTY', 'INVALID_VALUE', 'BLE_MAC', 'BLE_RSSI', 'UNIT_DBM',
            'SETUP_PIN_ACCEPTED', 'SETUP_PIN_SENT', 'SETUP_PIN_ESP_DOWN', 'SETUP_PIN_BAD',
            'SETUP_PIN_HTTP_ERR', 'PAIR_STALE_WARN',
            'ST_CONN_OK', 'ST_CONN_PIN', 'ST_CONN_NO',
            'YES', 'NO', 'UNKNOWN', 'UNAVAILABLE',
            'BLE_IS_BOILER', 'BLE_CHOOSE', 'BLE_EMPTY', 'BLE_UNAVAILABLE', 'BLE_NAME', 'BLE_SELECTED',
            'SCAN_FOUND', 'SCAN_IS_BOILER', 'SCAN_ESPHOME', 'SCAN_NEED_BASE_IP',
            'PROBE_BOILER', 'PROBE_REACHABLE', 'PROBE_UNREACHABLE',
            'ERROR_CSRF', 'SD_PROVISIONED', 'SD_ALL_LINKED', 'SD_PROVISION_FAIL', 'SD_UNAVAILABLE',
            'SCENE_OK', 'SCENE_FAIL', 'VAC_BAD', 'SD_REMOVED',
            'STB_CONNECTED', 'STB_REQUIRE_PIN', 'STB_AUTH', 'STB_DISCONNECTED',
            'STG_BOOT_SAFE', 'STG_IDLE', 'STG_HEATING_ALLOWED', 'STG_PAUSE_REQUESTED',
            'STG_COOLDOWN', 'STG_LEASE_EXPIRED', 'STG_DISCONNECTED', 'STG_FAULT',
            'STR_BOOT_SAFE', 'STR_IDLE_AFTER_BOOT', 'STR_LEASE_EXPIRED', 'STR_LEASE_RENEWED',
            'STR_COOLDOWN_COMPLETE', 'STR_MAX_HEAT', 'STR_HEAT_NOT_STOPPED',
            'STR_COOLDOWN_RESTORED', 'STR_BLE_RECONNECT', 'ACT_HEAT', 'ACT_HOLD',
        );
    }

    /**
     * short name => translated string. $resolver(string $shortName) returns the
     * string or null when the constant is not defined (entry omitted).
     */
    public static function jsStrings($resolver)
    {
        $out = array();
        foreach (self::i18nKeys() as $k) {
            $v = call_user_func($resolver, $k);
            // Undefined constant -> '' (never a raw parser token, never a notice).
            $out[$k] = ($v === null || $v === false) ? '' : (string) $v;
        }
        return $out;
    }

    /** json_encode that is safe to print inside an inline <script> block. */
    public static function jsonForScript($value)
    {
        $json = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
            | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return is_string($json) ? $json : 'null';
    }
}
