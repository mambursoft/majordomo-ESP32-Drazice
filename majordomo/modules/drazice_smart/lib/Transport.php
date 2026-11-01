<?php
/**
 * Transport: PHP cURL -> ESPHome web_server REST (no MQTT, no native API).
 *
 * Security:
 *   - validates host (IPv4 or hostname), rejects public/loopback/meta ranges
 *     unless explicitly allowed (SSRF protection);
 *   - encodes entity names per-segment (spaces -> %20);
 *   - never parses '55 °C' strings: reads value out of ESPHome JSON;
 *   - HTTP 200 is NOT success: callers must read back actual state.
 *
 * PHP 7.3 compatible (no typed properties / union types / arrow fns).
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class Transport
{
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $user;
    /** @var string */
    private $pass;
    /** @var int */
    private $connectTimeout;
    /** @var int */
    private $maxTime;
    /** @var bool */
    private $useDigest;
    /** @var array */
    private $lastError;

    public function __construct($config)
    {
        $this->host = isset($config['host']) ? trim($config['host']) : '';
        $this->port = isset($config['port']) ? (int) $config['port'] : 80;
        $this->user = isset($config['user']) ? $config['user'] : '';
        $this->pass = isset($config['pass']) ? $config['pass'] : '';
        $this->connectTimeout = isset($config['connect_timeout']) ? (int) $config['connect_timeout'] : 2;
        $this->maxTime = isset($config['max_time']) ? (int) $config['max_time'] : 5;
        $this->useDigest = isset($config['digest']) ? (bool) $config['digest'] : false;
        $this->lastError = array();
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Validate a host string. Returns array(ok=>bool, reason=>string).
     * Accepts an IPv4 address in a private/LAN range, or a hostname.
     * Rejects loopback, link-local, cloud metadata (169.254.169.254), and
     * obviously public addresses to reduce SSRF risk.
     */
    public static function validateHost($host)
    {
        $host = trim((string) $host);
        if ($host === '') {
            return array('ok' => false, 'reason' => 'empty_host');
        }
        // Reject URL schemes / paths / credentials embedded in host.
        // Disallow: backslash, slash, '@', whitespace, ':', '?', '#'.
        if (preg_match('~[\\\\/@\\s:?#]~', $host)) {
            // allow nothing but a bare host; port is configured separately
            return array('ok' => false, 'reason' => 'invalid_chars_in_host');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($host);
            if ($long === false) {
                return array('ok' => false, 'reason' => 'bad_ipv4');
            }
            // Block loopback 127/8
            if (($long & 0xFF000000) === (0x7F000000 & 0xFF000000) && ($long >> 24 & 0xFF) === 127) {
                return array('ok' => false, 'reason' => 'loopback_not_allowed');
            }
            // Block link-local 169.254/16 (includes cloud metadata 169.254.169.254)
            if (($long & 0xFFFF0000) === (ip2long('169.254.0.0') & 0xFFFF0000)) {
                return array('ok' => false, 'reason' => 'link_local_not_allowed');
            }
            // Must be a private LAN range: 10/8, 172.16/12, 192.168/16
            $isPrivate =
                (($long & 0xFF000000) === (ip2long('10.0.0.0') & 0xFF000000)) ||
                (($long & 0xFFF00000) === (ip2long('172.16.0.0') & 0xFFF00000)) ||
                (($long & 0xFFFF0000) === (ip2long('192.168.0.0') & 0xFFFF0000));
            if (!$isPrivate) {
                return array('ok' => false, 'reason' => 'public_ip_not_allowed');
            }
            return array('ok' => true, 'reason' => 'ipv4_private');
        }
        // Hostname: letters, digits, dot, hyphen; max 253 chars; <= 63 per label.
        if (strlen($host) > 253) {
            return array('ok' => false, 'reason' => 'hostname_too_long');
        }
        if (!preg_match('/^(?=.{1,253}$)([a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $host)) {
            return array('ok' => false, 'reason' => 'bad_hostname');
        }
        return array('ok' => true, 'reason' => 'hostname');
    }

    /**
     * Build a per-segment URL-encoded ESPHome REST path.
     * domain e.g. 'sensor', name e.g. 'Boiler Temp Upper' -> /sensor/Boiler%20Temp%20Upper
     */
    public function buildUrl($domain, $name, $query = '')
    {
        $encName = rawurlencode($name);
        $url = 'http://' . $this->host . ':' . $this->port . '/' . rawurlencode($domain) . '/' . $encName;
        if ($query !== '') {
            $url .= '?' . $query;
        }
        return $url;
    }

    /** GET an entity state, returns decoded JSON array or null. */
    public function getState($domain, $name)
    {
        $v = self::validateHost($this->host);
        if (!$v['ok']) {
            $this->lastError = array('stage' => 'host', 'reason' => $v['reason']);
            return null;
        }
        $url = $this->buildUrl($domain, $name);
        return $this->request('GET', $url);
    }

    /** POST a guarded write (e.g. number set). Returns decoded JSON or null. */
    public function postSet($domain, $name, $value)
    {
        $v = self::validateHost($this->host);
        if (!$v['ok']) {
            $this->lastError = array('stage' => 'host', 'reason' => $v['reason']);
            return null;
        }
        $query = 'value=' . rawurlencode((string) $value);
        $url = $this->buildUrl($domain, $name, $query);
        return $this->request('POST', $url);
    }

    /** POST a button press (lease renew, release/reconnect BLE). */
    public function pressButton($name)
    {
        $v = self::validateHost($this->host);
        if (!$v['ok']) {
            $this->lastError = array('stage' => 'host', 'reason' => $v['reason']);
            return null;
        }
        $url = $this->buildUrl('button', $name, '') . '/press';
        return $this->request('POST', $url);
    }

    private function request($method, $url)
    {
        $this->lastError = array();
        if (!function_exists('curl_init')) {
            $this->lastError = array('stage' => 'curl', 'reason' => 'curl_extension_missing');
            return null;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->maxTime);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // Never resolve to an unexpected redirect; LAN only.
        if ($this->user !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $this->user . ':' . $this->pass);
            curl_setopt($ch, CURLOPT_HTTPAUTH, $this->useDigest ? CURLAUTH_DIGEST : CURLAUTH_BASIC);
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->lastError = array('stage' => 'transport', 'reason' => 'curl_errno_' . $errno, 'detail' => $err);
            return null;
        }
        if ($httpCode === 401 || $httpCode === 403) {
            $this->lastError = array('stage' => 'auth', 'reason' => 'http_' . $httpCode);
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->lastError = array('stage' => 'http', 'reason' => 'http_' . $httpCode);
            return null;
        }
        // For POST set/press ESPHome may return empty body with 200; that is OK
        // but still NOT proof of effect. Caller must read back.
        if ($method === 'POST' && ($body === '' || $body === false)) {
            return array('_http' => $httpCode, '_empty' => true);
        }
        $decoded = json_decode((string) $body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->lastError = array('stage' => 'json', 'reason' => 'bad_json');
            return null;
        }
        if (is_array($decoded)) {
            $decoded['_http'] = $httpCode;
        }
        return $decoded;
    }

    /**
     * Extract a numeric value from an ESPHome JSON state.
     * ESPHome returns {"id":...,"value":<num>,"state":"55 °C"}. We use 'value'.
     * Returns null for NaN/missing/non-finite (never 0 as a fallback).
     */
    public static function numericValue($json)
    {
        if (!is_array($json) || !array_key_exists('value', $json)) {
            return null;
        }
        $v = $json['value'];
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        if (is_nan($f) || is_infinite($f)) {
            return null;
        }
        return $f;
    }

    /** Extract a boolean from an ESPHome binary_sensor JSON. Null if unknown. */
    public static function boolValue($json)
    {
        if (!is_array($json) || !array_key_exists('value', $json)) {
            return null;
        }
        $v = $json['value'];
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 1 || $v === '1' || $v === 'true' || $v === 'on') {
            return true;
        }
        if ($v === 0 || $v === '0' || $v === 'false' || $v === 'off') {
            return false;
        }
        return null;
    }

    /** Extract a string state. */
    public static function stringValue($json)
    {
        if (!is_array($json)) {
            return null;
        }
        if (array_key_exists('state', $json) && is_string($json['state'])) {
            return $json['state'];
        }
        if (array_key_exists('value', $json) && is_string($json['value'])) {
            return $json['value'];
        }
        return null;
    }
}
