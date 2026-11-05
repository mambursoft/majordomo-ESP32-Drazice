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
    /** @var string 'state' | 'legacy' | '' - where the last getAll() data came from */
    private $lastSource = '';

    /** Max time (s) for the batch GET /state (streamed body, bigger than a single entity). */
    const STATE_MAX_TIME = 8;

    /**
     * Write-only / sensitive entities that must never enter the cache or any
     * JSON sent to the browser: domain => list of display names.
     */
    const SENSITIVE = array('number' => array('Boiler Pairing PIN'));

    /** Domains carried by GET /state (order as emitted by the firmware). */
    const STATE_DOMAINS = array('sensor', 'binary_sensor', 'text_sensor', 'number', 'select');

    /**
     * Entities the module reads (buildSnapshot + readDiagnostics + BLE scan):
     * the per-entity GET fallback for firmware without GET /state. [domain, name].
     */
    const LEGACY_ENTITIES = array(
        array('sensor', 'Boiler Temp Upper'),
        array('sensor', 'Boiler Temp Lower'),
        array('binary_sensor', 'Boiler Heating'),
        array('binary_sensor', 'Boiler HDO'),
        array('sensor', 'Boiler Energy'),
        array('sensor', 'Boiler Cooldown Remaining'),
        array('text_sensor', 'Boiler Guard State'),
        array('sensor', 'ESP Uptime'),
        array('sensor', 'ESP Wi-Fi RSSI'),
        array('sensor', 'ESP Free Heap'),
        array('sensor', 'ESP Min Free Heap'),
        array('text_sensor', 'ESP Reset Reason'),
        array('text_sensor', 'ESPHome Version'),
        array('text_sensor', 'Boiler Guard Reason'),
        array('sensor', 'Boiler Continuous Heat Seconds'),
        array('sensor', 'Boiler Lease Remaining'),
        array('text_sensor', 'Boiler Version'),
        array('text_sensor', 'Boiler Name'),
        array('text_sensor', 'Boiler Time'),
        array('text_sensor', 'Boiler State'),
        array('number', 'Boiler Requested Target'),
        array('select', 'Boiler Requested Mode'),
        array('text_sensor', 'Boiler Pair Trace'),
        array('text_sensor', 'Boiler Last Disconnect'),
        array('sensor', 'Boiler Pair Attempts'),
        array('sensor', 'Boiler Connect Count'),
        array('text_sensor', 'BLE Scan Results'),
    );

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
        // The firmware rest_api applies writes ONLY on POST /{number|select}/{name}/set
        // (like the old ESPHome web_server); without /set it answers 404.
        return $this->request('POST', $this->buildSetUrl($domain, $name, $value));
    }

    /** URL of a guarded write: POST /{domain}/{name}/set?value=... */
    public function buildSetUrl($domain, $name, $value)
    {
        return $this->buildUrl($domain, $name) . '/set?value=' . rawurlencode((string) $value);
    }

    /** URL of a button press: POST /button/{name}/press */
    public function buildPressUrl($name)
    {
        return $this->buildUrl('button', $name, '') . '/press';
    }

    public function getLastSource()
    {
        return $this->lastSource;
    }

    /**
     * ONE request for every non-internal entity: GET /state (see
     * docs/api-contract.md). Returns map domain => name => array(value, state)
     * or null (lastError set). Falls back to per-entity GETs ONLY when the
     * firmware answers 404 (old build without /state); auth/transport/json
     * errors never trigger the fallback.
     */
    public function getAll()
    {
        $this->lastSource = '';
        $v = self::validateHost($this->host);
        if (!$v['ok']) {
            $this->lastError = array('stage' => 'host', 'reason' => $v['reason']);
            return null;
        }
        $this->lastError = array();
        $url = 'http://' . $this->host . ':' . $this->port . '/state';
        $raw = $this->rawRequest('GET', $url, self::STATE_MAX_TIME);
        if ($raw['errno'] !== 0) {
            $this->lastError = $raw['errno'] === -1
                ? array('stage' => 'curl', 'reason' => 'curl_extension_missing')
                : array('stage' => 'transport', 'reason' => 'curl_errno_' . $raw['errno'], 'detail' => $raw['error']);
            return null;
        }
        $c = self::classifyStateResponse($raw['http'], $raw['body']);
        if ($c['kind'] === 'map') {
            $this->lastSource = 'state';
            return $c['map'];
        }
        if ($c['kind'] === 'legacy') {
            return $this->getAllLegacy();
        }
        $this->lastError = $c['error'];
        return null;
    }

    /** Per-entity fallback for firmware without GET /state. Same map shape. */
    public function getAllLegacy()
    {
        $raw = array();
        $any = false;
        $lastErr = array();
        foreach (self::LEGACY_ENTITIES as $pair) {
            $j = $this->getState($pair[0], $pair[1]);
            if ($j === null) {
                $lastErr = $this->lastError;
                continue;
            }
            $any = true;
            $raw[$pair[0]][$pair[1]] = $j;
        }
        if (!$any) {
            $this->lastError = $lastErr;
            return null;
        }
        $this->lastError = array();
        $this->lastSource = 'legacy';
        return self::parseAll($raw);
    }

    /**
     * PURE: classify the answer to GET /state.
     * 200..299 + JSON object -> kind 'map'; 404 -> 'legacy'; everything else
     * -> 'error' with a lastError-shaped array. Invalid UTF-8 inside the body
     * (stray BLE device name) is substituted, never fatal.
     */
    public static function classifyStateResponse($httpCode, $body)
    {
        $httpCode = (int) $httpCode;
        if ($httpCode === 404) {
            return array('kind' => 'legacy');
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return array('kind' => 'error', 'error' => array('stage' => 'auth', 'reason' => 'http_' . $httpCode, 'http_code' => $httpCode));
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return array('kind' => 'error', 'error' => array('stage' => 'http', 'reason' => 'http_' . $httpCode, 'http_code' => $httpCode));
        }
        $decoded = json_decode((string) $body, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_array($decoded)) {
            return array('kind' => 'error', 'error' => array('stage' => 'json', 'reason' => 'bad_json'));
        }
        return array('kind' => 'map', 'map' => self::parseAll($decoded));
    }

    /**
     * PURE: normalise a decoded /state document into
     *   domain => name => array('value' => .., 'state' => ..)
     * Same semantics as numericValue/boolValue/stringValue: missing, NaN or
     * non-numeric -> null (NEVER 0). Keys starting with '_' are ignored; the
     * write-only SENSITIVE entities are dropped.
     */
    public static function parseAll($decoded)
    {
        $out = array();
        if (!is_array($decoded)) {
            return $out;
        }
        foreach (self::STATE_DOMAINS as $domain) {
            if (!isset($decoded[$domain]) || !is_array($decoded[$domain])) {
                continue;
            }
            $out[$domain] = array();
            foreach ($decoded[$domain] as $name => $e) {
                $name = (string) $name;
                if ($name === '' || $name[0] === '_' || !is_array($e)) {
                    continue;
                }
                if (isset(self::SENSITIVE[$domain]) && in_array($name, self::SENSITIVE[$domain], true)) {
                    continue;
                }
                $state = (array_key_exists('state', $e) && is_string($e['state'])) ? $e['state'] : null;
                if ($domain === 'sensor' || $domain === 'number') {
                    $value = self::numericValue($e);
                } elseif ($domain === 'binary_sensor') {
                    $value = self::boolValue($e);
                } else {
                    $state = self::stringValue($e);
                    $value = $state;
                }
                $out[$domain][$name] = array('value' => $value, 'state' => $state);
            }
        }
        return $out;
    }

    /** Null-safe lookup of one entity in a map from getAll()/parseAll(). */
    public static function entity($map, $domain, $name)
    {
        if (is_array($map) && isset($map[$domain]) && is_array($map[$domain])
            && isset($map[$domain][$name]) && is_array($map[$domain][$name])) {
            return $map[$domain][$name];
        }
        return null;
    }

    /** POST a button press (lease renew, release/reconnect BLE). */
    public function pressButton($name)
    {
        $v = self::validateHost($this->host);
        if (!$v['ok']) {
            $this->lastError = array('stage' => 'host', 'reason' => $v['reason']);
            return null;
        }
        return $this->request('POST', $this->buildPressUrl($name));
    }

    /**
     * One cURL round trip. Returns array(errno, error, http, body); errno -1 =
     * cURL extension missing. $maxTime null -> the configured per-call limit.
     */
    private function rawRequest($method, $url, $maxTime = null)
    {
        if (!function_exists('curl_init')) {
            return array('errno' => -1, 'error' => '', 'http' => 0, 'body' => '');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $maxTime === null ? $this->maxTime : (int) $maxTime);
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
        return array('errno' => $errno, 'error' => $err, 'http' => $httpCode, 'body' => $body);
    }

    private function request($method, $url)
    {
        $this->lastError = array();
        $raw = $this->rawRequest($method, $url);
        if ($raw['errno'] === -1) {
            $this->lastError = array('stage' => 'curl', 'reason' => 'curl_extension_missing');
            return null;
        }
        $body = $raw['body'];
        $errno = $raw['errno'];
        $httpCode = $raw['http'];
        $err = $raw['error'];

        if ($errno !== 0) {
            $this->lastError = array('stage' => 'transport', 'reason' => 'curl_errno_' . $errno, 'detail' => $err);
            return null;
        }
        if ($httpCode === 401 || $httpCode === 403) {
            $this->lastError = array('stage' => 'auth', 'reason' => 'http_' . $httpCode, 'http_code' => $httpCode);
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            // The ESP ANSWERED (e.g. 404/400): a request error, not "ESP unreachable".
            $this->lastError = array('stage' => 'http', 'reason' => 'http_' . $httpCode, 'http_code' => $httpCode);
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
