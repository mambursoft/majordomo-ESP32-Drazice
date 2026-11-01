<?php
/**
 * Presence: optional passive Wi-Fi/BLE presence by phone MAC (variant "a").
 * DISABLED by default. Tracks "who is in the toilet and for how long" by
 * matching an observed MAC against a configured roster, then — once a
 * configured duration is exceeded — asks the caller to invoke a MajorDoMo
 * handler (e.g. announce on the toilet speaker).
 *
 * HONEST LIMITATIONS (documented in docs/presence.*.md):
 *   - Modern POCO / HyperOS / MIUI phones use MAC randomization. Passive MAC
 *     matching is UNRELIABLE and may never see a stable MAC. Nothing is
 *     installed on the kids' phones by request.
 *   - RSSI does not prove the person is in a specific room; this is presence
 *     by MAC association, not a room occupancy sensor.
 *
 * This class is pure logic over an injected "observations" list; the caller
 * supplies MACs seen on the LAN (e.g. from the router / arp / a scanner).
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class Presence
{
    /**
     * @param array $observations  list of array('mac'=>..., 'seen_utc'=>int)
     * @param array $roster        map mac(lower) => person label
     * @param int   $nowUtc
     * @param array $cfg           keys: presence_enabled, absent_after_s,
     *                             notify_after_s, renotify_every_s
     * @param array $state         prior per-person state (persisted by caller):
     *                             person => array(since_utc, last_notify_utc)
     * @return array present(list), events(list of notify actions), state(new)
     */
    public static function evaluate($observations, $roster, $nowUtc, $cfg, $state)
    {
        $enabled = !empty($cfg['presence_enabled']);
        $absentAfter = isset($cfg['absent_after_s']) ? (int) $cfg['absent_after_s'] : 120;
        $notifyAfter = isset($cfg['notify_after_s']) ? (int) $cfg['notify_after_s'] : 600;
        $renotify = isset($cfg['renotify_every_s']) ? (int) $cfg['renotify_every_s'] : 0;

        $result = array('present' => array(), 'events' => array(), 'state' => array());
        if (!$enabled) {
            return $result;
        }

        // Normalise roster keys.
        $normRoster = array();
        foreach ($roster as $mac => $label) {
            $normRoster[strtolower(trim($mac))] = $label;
        }

        // Most recent sighting per known person.
        $lastSeen = array();
        foreach ($observations as $obs) {
            if (!isset($obs['mac'])) {
                continue;
            }
            $mac = strtolower(trim($obs['mac']));
            if (!isset($normRoster[$mac])) {
                continue; // unknown MAC — ignore (data minimisation)
            }
            $person = $normRoster[$mac];
            $seen = isset($obs['seen_utc']) ? (int) $obs['seen_utc'] : $nowUtc;
            if (!isset($lastSeen[$person]) || $seen > $lastSeen[$person]) {
                $lastSeen[$person] = $seen;
            }
        }

        $prior = is_array($state) ? $state : array();

        foreach ($normRoster as $mac => $person) {
            $seen = isset($lastSeen[$person]) ? $lastSeen[$person] : null;
            $isPresent = ($seen !== null) && (($nowUtc - $seen) <= $absentAfter);

            if (!$isPresent) {
                // person absent -> clear state
                continue;
            }

            // carry or start the "since" timestamp
            $since = isset($prior[$person]['since_utc']) ? (int) $prior[$person]['since_utc'] : $nowUtc;
            $lastNotify = isset($prior[$person]['last_notify_utc']) ? (int) $prior[$person]['last_notify_utc'] : 0;
            $duration = $nowUtc - $since;

            $result['present'][] = array(
                'person'      => $person,
                'since_utc'   => $since,
                'duration_s'  => $duration,
            );

            $shouldNotify = false;
            if ($duration >= $notifyAfter) {
                if ($lastNotify === 0) {
                    $shouldNotify = true;
                } elseif ($renotify > 0 && ($nowUtc - $lastNotify) >= $renotify) {
                    $shouldNotify = true;
                }
            }
            if ($shouldNotify) {
                $result['events'][] = array(
                    'person'     => $person,
                    'duration_s' => $duration,
                    'action'     => 'announce_toilet',
                );
                $lastNotify = $nowUtc;
            }

            $result['state'][$person] = array(
                'since_utc'       => $since,
                'last_notify_utc' => $lastNotify,
            );
        }

        return $result;
    }
}
