<?php
/**
 * ClockCheck: compare the boiler's internal clock (reported by the ESP over
 * BLE as text "Day HH:MM:SS", e.g. "Mon 14:03:27") against the server clock.
 *
 * The boiler only exposes a day-of-week + time-of-day (no calendar date), so
 * the comparison is done on a weekly circle: both sides are reduced to
 * "seconds since Monday 00:00:00" in the configured timezone, and the skew is
 * the shortest signed distance on that 7-day (604800 s) circle. This correctly
 * handles midnight and week boundaries (e.g. Sun 23:59 vs Mon 00:01).
 *
 * Pure, timezone-aware, DST-correct, PHP 7.3 compatible. No side effects.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class ClockCheck
{
    const WEEK_SECONDS = 604800; // 7 * 24 * 3600

    /** Boiler day abbreviations, index 0 = Monday .. 6 = Sunday. */
    private static $days = array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun');

    /**
     * Parse "Day HH:MM:SS" into [day_index(0=Mon..6=Sun), seconds_of_day].
     * Tolerant: accepts 3-letter day in any case; also accepts a bare
     * "HH:MM:SS" (then day is null). Returns null when unparseable.
     *
     * @param string $raw
     * @return array|null array('dow'=>int|null, 'sod'=>int) or null
     */
    public static function parseBoilerTime($raw)
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $dow = null;
        // Optional leading day token.
        if (preg_match('/^([A-Za-z]{3})\s+(.*)$/', $raw, $m)) {
            $key = strtolower($m[1]);
            $idx = array_search($key, self::$days, true);
            if ($idx !== false) {
                $dow = (int) $idx;
            }
            $timePart = $m[2];
        } else {
            $timePart = $raw;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', trim($timePart), $t)) {
            return null;
        }
        $h = (int) $t[1];
        $mi = (int) $t[2];
        $s = (int) $t[3];
        if ($h > 23 || $mi > 59 || $s > 59) {
            return null;
        }
        return array('dow' => $dow, 'sod' => $h * 3600 + $mi * 60 + $s);
    }

    /**
     * Compute the boiler-vs-server clock skew.
     *
     * @param string $boilerRaw boiler time text ("Day HH:MM:SS" or "HH:MM:SS")
     * @param int    $nowUtc    server time, unix UTC
     * @param string $tzName    timezone for the server-local comparison
     * @param int    $thresholdS significant-drift threshold in seconds (>0)
     * @return array|null
     *   null when the boiler time is missing/unparseable; otherwise:
     *   array(
     *     'skew_s'    => int   signed seconds (boiler - server; +ve = boiler ahead),
     *     'abs_s'     => int   absolute skew,
     *     'ok'        => bool  abs_s <= threshold,
     *     'has_dow'   => bool  whether the boiler reported a day-of-week,
     *     'boiler'    => string normalized boiler time,
     *     'server'    => string server-local "Day HH:MM:SS" used for the compare
     *   )
     */
    public static function evaluate($boilerRaw, $nowUtc, $tzName, $thresholdS = 120)
    {
        $parsed = self::parseBoilerTime($boilerRaw);
        if ($parsed === null) {
            return null;
        }
        $threshold = (int) $thresholdS;
        if ($threshold < 0) {
            $threshold = 0;
        }

        $tzName = ($tzName !== '') ? $tzName : 'Europe/Kyiv';
        try {
            $tz = new \DateTimeZone($tzName);
            $dt = new \DateTime('@' . (int) $nowUtc);
            $dt->setTimezone($tz);
        } catch (\Exception $e) {
            return null;
        }

        // Server position on the weekly circle (0 = Monday 00:00:00 local).
        $srvDow = (int) $dt->format('N') - 1;           // 1..7 (Mon..Sun) -> 0..6
        $srvSod = (int) $dt->format('H') * 3600 + (int) $dt->format('i') * 60 + (int) $dt->format('s');
        $srvWeek = $srvDow * 86400 + $srvSod;

        if ($parsed['dow'] !== null) {
            // Full weekly comparison.
            $boWeek = $parsed['dow'] * 86400 + $parsed['sod'];
            $skew = self::circularDiff($boWeek, $srvWeek, self::WEEK_SECONDS);
            $hasDow = true;
        } else {
            // Time-of-day only: compare on a 24h circle.
            $skew = self::circularDiff($parsed['sod'], $srvSod, 86400);
            $hasDow = false;
        }

        $abs = $skew < 0 ? -$skew : $skew;

        // Build readable server reference in the same "Day HH:MM:SS" shape.
        $dayAbbr = array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun');
        $serverStr = $dayAbbr[$srvDow] . ' ' . $dt->format('H:i:s');
        $boilerStr = self::normalize($parsed);

        return array(
            'skew_s'  => (int) $skew,
            'abs_s'   => (int) $abs,
            'ok'      => ($abs <= $threshold),
            'has_dow' => $hasDow,
            'boiler'  => $boilerStr,
            'server'  => $serverStr,
        );
    }

    /**
     * Shortest signed distance from $b (reference) to $a on a circle of
     * $mod seconds, i.e. (a - b) wrapped into [-mod/2, +mod/2].
     * Positive => $a is ahead of $b.
     */
    private static function circularDiff($a, $b, $mod)
    {
        $d = ($a - $b) % $mod;
        if ($d < 0) {
            $d += $mod;
        }
        if ($d > $mod / 2) {
            $d -= $mod;
        }
        return (int) $d;
    }

    /** Human form of a parsed boiler time. */
    private static function normalize($parsed)
    {
        $sod = (int) $parsed['sod'];
        $h = intdiv($sod, 3600);
        $mi = intdiv($sod % 3600, 60);
        $s = $sod % 60;
        $time = sprintf('%02d:%02d:%02d', $h, $mi, $s);
        if ($parsed['dow'] !== null) {
            $dayAbbr = array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun');
            return $dayAbbr[$parsed['dow']] . ' ' . $time;
        }
        return $time;
    }
}
