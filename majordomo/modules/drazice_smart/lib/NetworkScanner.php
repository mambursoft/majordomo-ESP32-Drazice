<?php
/**
 * NetworkScanner: helps a non-technical user FIND the ESP32 on the LAN when
 * they don't know its IP. Two strategies:
 *   1. Probe a user-given IP directly (validate + GET a known diagnostic entity).
 *   2. Sweep a /24 subnet derived from the server's own LAN IP, probing the
 *      ESPHome web_server root on each host with a short timeout.
 *
 * This is server-side only; the browser never talks to the ESP directly.
 * All hosts are validated through Transport::validateHost (SSRF guard: private
 * ranges only).
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class NetworkScanner
{
    /**
     * Probe a single host:port. Returns array(ok, is_boiler, detail).
     * is_boiler is true when a known guard entity is reachable.
     */
    public static function probe($host, $port, $user, $pass, $digest, $connectTimeout = 1, $maxTime = 2)
    {
        $v = Transport::validateHost($host);
        if (!$v['ok']) {
            return array('ok' => false, 'is_boiler' => false, 'detail' => $v['reason'], 'host' => $host);
        }
        $t = new Transport(array(
            'host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass,
            'digest' => $digest, 'connect_timeout' => $connectTimeout, 'max_time' => $maxTime,
        ));
        // A boiler responds on the guard-state text_sensor.
        $json = $t->getState('text_sensor', 'Boiler Guard State');
        if (is_array($json)) {
            return array('ok' => true, 'is_boiler' => true, 'detail' => 'guard_state_reachable',
                'host' => $host, 'state' => Transport::stringValue($json));
        }
        // Fall back: any 200 on the ESP uptime sensor => an ESPHome device.
        $json2 = $t->getState('sensor', 'ESP Uptime');
        if (is_array($json2)) {
            return array('ok' => true, 'is_boiler' => false, 'detail' => 'esphome_but_not_boiler', 'host' => $host);
        }
        return array('ok' => false, 'is_boiler' => false, 'detail' => $t->getLastError(), 'host' => $host);
    }

    /**
     * Derive candidate IPs for a /24 sweep from a base IP (e.g. server LAN IP).
     * Returns array of IP strings .1 .. .254 (excludes network & broadcast).
     */
    public static function subnetCandidates($baseIp)
    {
        $v = Transport::validateHost($baseIp);
        if (!$v['ok'] || !filter_var($baseIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return array();
        }
        $parts = explode('.', $baseIp);
        $prefix = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.';
        $out = array();
        for ($i = 1; $i <= 254; $i++) {
            $out[] = $prefix . $i;
        }
        return $out;
    }

    /**
     * Sweep a subnet. Returns a list of discovered devices (is_boiler flagged).
     * NOTE: sequential probing; the UI should warn this can take a while and
     * should run under the cycle worker, not a blocking web request. maxHosts
     * caps the work for responsiveness.
     */
    public static function sweep($baseIp, $port, $user, $pass, $digest, $maxHosts = 254)
    {
        $found = array();
        $candidates = self::subnetCandidates($baseIp);
        $n = 0;
        foreach ($candidates as $ip) {
            if ($n >= $maxHosts) {
                break;
            }
            $n++;
            $res = self::probe($ip, $port, $user, $pass, $digest, 1, 1);
            if (!empty($res['ok'])) {
                $found[] = $res;
            }
        }
        return $found;
    }
}
