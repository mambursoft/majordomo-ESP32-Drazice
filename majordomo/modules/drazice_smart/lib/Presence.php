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
        $longAfter = isset($cfg['long_after_s']) ? (int) $cfg['long_after_s']
            : (isset($cfg['notify_after_s']) ? (int) $cfg['notify_after_s'] : 600);
        $tooLongAfter = isset($cfg['too_long_after_s']) ? (int) $cfg['too_long_after_s'] : 1200;
        $renotify = isset($cfg['renotify_every_s']) ? (int) $cfg['renotify_every_s'] : 0;

        $result = array('present' => array(), 'events' => array(), 'state' => array());
        if (!$enabled) {
            return $result;
        }

        // Normalise roster keys (MAC/beacon id -> person label).
        $normRoster = array();
        foreach ($roster as $mac => $label) {
            $normRoster[strtolower(trim($mac))] = $label;
        }

        // Most recent sighting per known person + the source (ble/wifi).
        $lastSeen = array();
        $lastSource = array();
        foreach ($observations as $obs) {
            if (!isset($obs['mac'])) {
                continue;
            }
            $mac = strtolower(trim($obs['mac']));
            if (!isset($normRoster[$mac])) {
                continue; // unknown id — ignore (data minimisation)
            }
            $person = $normRoster[$mac];
            $seen = isset($obs['seen_utc']) ? (int) $obs['seen_utc'] : $nowUtc;
            if (!isset($lastSeen[$person]) || $seen > $lastSeen[$person]) {
                $lastSeen[$person] = $seen;
                $lastSource[$person] = isset($obs['source']) ? $obs['source'] : 'lan';
            }
        }

        $prior = is_array($state) ? $state : array();

        // 1. People currently present: carry sessions, emit staged events.
        $seenPersons = array();
        foreach ($normRoster as $mac => $person) {
            if (isset($seenPersons[$person])) {
                continue; // one person may have several ids
            }
            $seen = isset($lastSeen[$person]) ? $lastSeen[$person] : null;
            $isPresent = ($seen !== null) && (($nowUtc - $seen) <= $absentAfter);
            if (!$isPresent) {
                continue;
            }
            $seenPersons[$person] = true;

            $since = isset($prior[$person]['since_utc']) ? (int) $prior[$person]['since_utc'] : $nowUtc;
            $lastNotify = isset($prior[$person]['last_notify_utc']) ? (int) $prior[$person]['last_notify_utc'] : 0;
            $stage = isset($prior[$person]['stage']) ? $prior[$person]['stage'] : 'none';
            $announced = isset($prior[$person]['announced']) ? (bool) $prior[$person]['announced'] : false;
            $duration = $nowUtc - $since;
            $source = isset($lastSource[$person]) ? $lastSource[$person] : 'lan';

            $result['present'][] = array(
                'person'     => $person,
                'since_utc'  => $since,
                'duration_s' => $duration,
                'source'     => $source,
            );

            // Stage transitions -> events.
            // none -> (new session) 'entered'
            if ($stage === 'none' && !$announced) {
                $result['events'][] = self::ev($person, $duration, 'entered', $source);
                $stage = 'present';
                $announced = true;
            }
            // present -> 'long' when duration crosses long threshold
            if ($stage === 'present' && $duration >= $longAfter) {
                $result['events'][] = self::ev($person, $duration, 'long', $source);
                $stage = 'long';
                $lastNotify = $nowUtc;
            }
            // long -> 'too_long' when duration crosses too-long threshold
            if ($stage === 'long' && $duration >= $tooLongAfter) {
                $result['events'][] = self::ev($person, $duration, 'too_long', $source);
                $stage = 'too_long';
                $lastNotify = $nowUtc;
            }
            // repeat reminder while long/too_long, if renotify configured
            if (($stage === 'long' || $stage === 'too_long') && $renotify > 0
                && ($nowUtc - $lastNotify) >= $renotify) {
                $result['events'][] = self::ev($person, $duration, $stage, $source);
                $lastNotify = $nowUtc;
            }

            $result['state'][$person] = array(
                'since_utc'       => $since,
                'last_notify_utc' => $lastNotify,
                'stage'           => $stage,
                'announced'       => $announced,
            );
        }

        // 2. People who WERE present last time but are gone now -> 'left'.
        foreach ($prior as $person => $ps) {
            if (isset($seenPersons[$person])) {
                continue;
            }
            $since = isset($ps['since_utc']) ? (int) $ps['since_utc'] : $nowUtc;
            $result['events'][] = self::ev($person, $nowUtc - $since, 'left', 'lan');
            // no state kept -> session closed
        }

        return $result;
    }

    private static function ev($person, $duration, $event, $source)
    {
        return array(
            'person'     => $person,
            'duration_s' => $duration,
            'event'      => $event,
            'source'     => $source,
            'action'     => 'announce_toilet',
        );
    }
}
