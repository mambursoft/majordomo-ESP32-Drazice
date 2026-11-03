<?php
/**
 * Tariff: cheap-window calculation by the METER clock, timezone-aware,
 * DST-correct. Window is [cheap_start, cheap_end) crossing midnight.
 *
 *   meter_time = server_local_time + meter_offset_seconds
 *   cheap = meter_time >= start OR meter_time < end   (when start > end)
 *
 * start_guard / end_guard narrow the window for safety.
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class Tariff
{
    /**
     * @param int    $nowUtc            unix timestamp (UTC)
     * @param array  $cfg               keys: timezone, cheap_start_hour,
     *                                   cheap_start_min, cheap_end_hour,
     *                                   cheap_end_min, meter_offset_seconds,
     *                                   start_guard_seconds, end_guard_seconds
     * @return array  active(bool), reason(string), meter_local(string)
     */
    public static function evaluate($nowUtc, $cfg)
    {
        $tz = isset($cfg['timezone']) ? $cfg['timezone'] : 'Europe/Kyiv';
        $startHour = isset($cfg['cheap_start_hour']) ? (int) $cfg['cheap_start_hour'] : 23;
        $endHour = isset($cfg['cheap_end_hour']) ? (int) $cfg['cheap_end_hour'] : 7;
        // Minute components default to 0 when absent -> fully backward
        // compatible with hour-only callers/config.
        $startMin = isset($cfg['cheap_start_min']) ? (int) $cfg['cheap_start_min'] : 0;
        $endMin = isset($cfg['cheap_end_min']) ? (int) $cfg['cheap_end_min'] : 0;
        $offset = isset($cfg['meter_offset_seconds']) ? (int) $cfg['meter_offset_seconds'] : 0;
        $startGuard = isset($cfg['start_guard_seconds']) ? (int) $cfg['start_guard_seconds'] : 0;
        $endGuard = isset($cfg['end_guard_seconds']) ? (int) $cfg['end_guard_seconds'] : 0;

        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Exception $e) {
            return array('active' => false, 'reason' => 'bad_timezone', 'meter_local' => '');
        }

        // Meter time = real UTC + offset, then expressed in local tz.
        $meterUtc = $nowUtc + $offset;
        $dt = new \DateTime('@' . $meterUtc);
        $dt->setTimezone($zone);

        $secondsOfDay = ((int) $dt->format('H')) * 3600
            + ((int) $dt->format('i')) * 60
            + ((int) $dt->format('s'));

        // Window edges in seconds-of-day (hour*3600 + min*60), with guards.
        $startEdge = $startHour * 3600 + $startMin * 60;
        $endEdge = $endHour * 3600 + $endMin * 60;
        $startSec = $startEdge + $startGuard;               // start later by guard
        $endSec = $endEdge - $endGuard;                     // end earlier by guard

        $active = false;
        if ($startEdge > $endEdge) {
            // Crosses midnight, e.g. 23:05 -> 06:55. Compare by seconds-of-day.
            $active = ($secondsOfDay >= $startSec) || ($secondsOfDay < $endSec);
        } else {
            $active = ($secondsOfDay >= $startSec) && ($secondsOfDay < $endSec);
        }

        return array(
            'active' => $active,
            'reason' => $active ? 'cheap_window' : 'day_tariff',
            'meter_local' => $dt->format('Y-m-d H:i:s T'),
        );
    }
}
