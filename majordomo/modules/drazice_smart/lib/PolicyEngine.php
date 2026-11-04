<?php
/**
 * PolicyEngine: interprets the power source signal and SOC into a safe
 * 'grid' | 'battery' | 'unknown' verdict, WITHOUT assuming polarity.
 *
 * The admin explicitly sets battery_active_value (what value of the bound
 * property means "running on battery"). Null / unparyseable / stale => unknown
 * (never silently 'grid').
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class PolicyEngine
{
    /**
     * @param mixed $rawBatteryActive  raw value of bound property (e.g. BatteryMode.active)
     * @param mixed $activeValue       configured value meaning "on battery"
     * @param int|null $sourceUpdatedUtc  when the producer last changed it (or null)
     * @param int   $nowUtc
     * @param int   $freshnessTtlS    0 = freshness not enforced (not provable)
     * @return array source, valid(bool), human, raw
     */
    public static function powerSource($rawBatteryActive, $activeValue, $sourceUpdatedUtc, $nowUtc, $freshnessTtlS)
    {
        $out = array(
            'source' => 'unknown',
            'valid'  => false,
            'raw'    => $rawBatteryActive,
            'human'  => '',
            // Language-neutral verdict code for the UI (Explain::power):
            // no_signal | stale | no_polarity | battery | grid
            'code'   => 'no_signal',
            'raw_norm'    => '',
            'active_norm' => '',
        );

        if ($rawBatteryActive === null || $rawBatteryActive === '') {
            $out['human'] = 'Сигнал джерела живлення відсутній — вважаємо «невідомо».';
            return $out;
        }

        // Freshness: only enforce if a TTL is configured AND we have a timestamp.
        if ($freshnessTtlS > 0 && $sourceUpdatedUtc !== null) {
            if (($nowUtc - (int) $sourceUpdatedUtc) > $freshnessTtlS) {
                $out['code'] = 'stale';
                $out['human'] = 'Сигнал джерела застарів (freshness перевищено) — «невідомо».';
                return $out;
            }
        }

        // Normalise both sides to string for a robust equality test.
        $rawNorm = self::norm($rawBatteryActive);
        $actNorm = self::norm($activeValue);
        $out['raw_norm'] = $rawNorm;
        $out['active_norm'] = $actNorm;

        if ($actNorm === '') {
            // Admin has not confirmed polarity yet.
            $out['code'] = 'no_polarity';
            $out['human'] = 'Полярність сигналу не підтверджено адміністратором — «невідомо».';
            return $out;
        }

        if ($rawNorm === $actNorm) {
            $out['source'] = 'battery';
            $out['valid'] = true;
            $out['code'] = 'battery';
            $out['human'] = 'Живлення від батарей (сире значення «' . $rawNorm . '» = активне).';
        } else {
            $out['source'] = 'grid';
            $out['valid'] = true;
            $out['code'] = 'grid';
            $out['human'] = 'Живлення від мережі (сире значення «' . $rawNorm . '» ≠ активного «' . $actNorm . '»).';
        }
        return $out;
    }

    private static function norm($v)
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        $s = trim((string) $v);
        $low = strtolower($s);
        if ($low === 'true' || $low === 'on' || $low === 'yes') {
            return '1';
        }
        if ($low === 'false' || $low === 'off' || $low === 'no') {
            return '0';
        }
        return $s;
    }

    /**
     * Validate a pairing PIN as a 1-4 digit string. Leading zeros are kept in
     * 'display' but the numeric 'value' drops them (e.g. '0692' -> 692). The
     * new firmware floor of 0 means '0' is a valid value.
     *
     * @param mixed $raw
     * @return array ok(bool), display(string)+value(int) when ok, else reason
     */
    public static function validatePin($raw)
    {
        $str = trim((string) $raw);
        if (preg_match('/^[0-9]{1,4}$/', $str)) {
            return array('ok' => true, 'display' => $str, 'value' => (int) $str);
        }
        return array('ok' => false, 'reason' => 'bad_pin');
    }

    /** Validate a SOC value (0..100). Returns float or null. */
    public static function validSoc($raw)
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        $f = (float) $raw;
        if (is_nan($f) || is_infinite($f)) {
            return null;
        }
        if ($f < 0.0 || $f > 100.0) {
            return null;
        }
        return $f;
    }
}
