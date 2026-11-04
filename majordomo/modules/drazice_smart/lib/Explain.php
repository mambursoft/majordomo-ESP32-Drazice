<?php
/**
 * Explain: turns a planner decision + snapshot + config into LOCALISED text
 * for the UI: a short reason label, a sentence, and the list of parameter
 * changes that would make heating start ("what must change").
 *
 * Pure: no MajorDoMo, no globals. Strings come from a resolver
 * callable($key) -> string|null (in the module: LANG_DS_<key> constants).
 * Placeholders in strings: {name}.
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class Explain
{
    /** Planner reason codes that have their own strings (DS_RSC_* / DS_RSN_*). */
    public static function reasonCodes()
    {
        return array(
            'init', 'esp_unreachable', 'heat_state_unknown', 'temp_unknown', 'cooldown_active',
            'power_unknown', 'battery_source_block', 'manual_boost', 'charge_priority_wait',
            'charge_priority_soc_unknown', 'comfort_minimum', 'solar_surplus',
            'solar_surplus_satisfied', 'cheap_tariff', 'cheap_tariff_satisfied', 'idle_comfort_ok',
        );
    }

    /** Every DS_* key this class may resolve (for the language-file test). */
    public static function keys()
    {
        $k = array();
        foreach (self::reasonCodes() as $code) {
            $k[] = 'RSC_' . strtoupper($code);
            $k[] = 'RSN_' . strtoupper($code);
        }
        return array_merge($k, array(
            'HT_HEADER_HOLD', 'HT_HEADER_HEAT', 'HT_OR', 'HT_NOT_SENT', 'HT_WHY_AUTO_OFF', 'HT_WHY_DRY',
            'HT_ESP', 'HT_BLE', 'HT_COOLDOWN', 'HT_PWR_NO_SIGNAL', 'HT_PWR_NO_POLARITY', 'HT_PWR_STALE',
            'HT_BAT_GRID', 'HT_BAT_ALLOW', 'HT_BAT_BOOST', 'HT_SURPLUS_SOC', 'HT_SURPLUS_NIGHT',
            'HT_SURPLUS_ENABLE', 'HT_CP_SOC', 'HT_CP_DISABLE', 'HT_CP_SOC_PROP', 'HT_BOOST',
            'HT_COMFORT', 'HT_TARIFF_WAIT', 'HT_TARIFF_RAISE', 'HT_TARIFF_ENABLE', 'HT_TARIFF_SAT',
            'HT_SURPLUS_SAT', 'HT_HEAT_TARGET', 'HT_BOOST_STOP',
            'PW_BATTERY', 'PW_GRID', 'PW_NO_SIGNAL', 'PW_STALE', 'PW_NO_POLARITY',
            'POWER_GRID', 'POWER_BATTERY', 'POWER_UNKNOWN',
        ));
    }

    /**
     * @param array    $s   snapshot (Repository/buildSnapshotFromState keys)
     * @param array    $d   Planner::decide() result
     * @param array    $c   MODULE config (upper-case keys)
     * @param callable $tr  key -> string|null
     * @return array label, text, howto (plain text, lines separated by "\n")
     */
    public static function decision($s, $d, $c, $tr)
    {
        $code = isset($d['reason']) ? (string) $d['reason'] : 'init';
        $up = strtoupper($code);
        $v = self::vars($s, $d, $c);

        $label = self::t($tr, 'RSC_' . $up, $v, $code);
        $text = self::t($tr, 'RSN_' . $up, $v, $code);

        $heat = (isset($d['action']) && $d['action'] === 'heat');
        $items = $heat ? self::heatItems($code, $v) : self::holdItems($code, $s, $c, $v);

        $lines = array();
        if (!empty($items)) {
            $lines[] = self::t($tr, $heat ? 'HT_HEADER_HEAT' : 'HT_HEADER_HOLD', $v, '');
            $or = self::t($tr, 'HT_OR', $v, 'OR');
            foreach ($items as $i => $key) {
                $line = self::t($tr, $key, $v, $key);
                $lines[] = '• ' . ($i > 0 && !$heat ? $or . ' ' : '') . $line;
            }
        }

        $why = array();
        if (empty($c['AUTOMATION_ENABLED'])) {
            $why[] = self::t($tr, 'HT_WHY_AUTO_OFF', $v, '');
        }
        if (!empty($c['DRY_RUN'])) {
            $why[] = self::t($tr, 'HT_WHY_DRY', $v, '');
        }
        if (!empty($why)) {
            $v['why'] = implode(', ', $why);
            $lines[] = self::t($tr, 'HT_NOT_SENT', $v, '');
        }

        return array('label' => $label, 'text' => $text, 'howto' => implode("\n", $lines));
    }

    /** Localised one-line interpretation of the power-source signal. */
    public static function power($s, $c, $tr)
    {
        $v = self::vars($s, array(), $c);
        $code = isset($s['power_code']) ? (string) $s['power_code'] : 'no_signal';
        $map = array('battery' => 'PW_BATTERY', 'grid' => 'PW_GRID', 'stale' => 'PW_STALE',
            'no_polarity' => 'PW_NO_POLARITY', 'no_signal' => 'PW_NO_SIGNAL');
        $key = isset($map[$code]) ? $map[$code] : 'PW_NO_SIGNAL';
        return self::t($tr, $key, $v, $code);
    }

    /** Localised power source word (grid / battery / unknown). */
    public static function powerWord($source, $tr)
    {
        $map = array('grid' => 'POWER_GRID', 'battery' => 'POWER_BATTERY');
        $key = isset($map[$source]) ? $map[$source] : 'POWER_UNKNOWN';
        return self::t($tr, $key, array(), (string) $source);
    }

    // ------------------------------------------------------------------ rules

    private static function heatItems($code, $v)
    {
        if ($code === 'manual_boost') {
            return array('HT_HEAT_TARGET', 'HT_BOOST_STOP');
        }
        return array('HT_HEAT_TARGET');
    }

    private static function holdItems($code, $s, $c, $v)
    {
        $surplusOn = !empty($c['SOLAR_SURPLUS_ENABLED']);
        $day = !empty($s['is_daytime']);
        $surplusItem = $surplusOn ? ($day ? 'HT_SURPLUS_SOC' : 'HT_SURPLUS_NIGHT') : 'HT_SURPLUS_ENABLE';
        $tariffOn = !isset($c['TARIFF_ENABLED']) || !empty($c['TARIFF_ENABLED']);

        switch ($code) {
            case 'esp_unreachable':
                return array('HT_ESP');
            case 'heat_state_unknown':
            case 'temp_unknown':
                return array('HT_BLE');
            case 'cooldown_active':
                return array('HT_COOLDOWN');
            case 'power_unknown':
                $pc = isset($s['power_code']) ? $s['power_code'] : 'no_signal';
                if ($pc === 'stale') {
                    return array('HT_PWR_STALE');
                }
                if ($pc === 'no_polarity') {
                    return array('HT_PWR_NO_POLARITY');
                }
                return array('HT_PWR_NO_SIGNAL');
            case 'battery_source_block':
                return array('HT_BAT_GRID', 'HT_BAT_ALLOW', 'HT_BAT_BOOST', $surplusItem);
            case 'charge_priority_wait':
                $out = array('HT_CP_SOC', 'HT_CP_DISABLE', 'HT_BOOST');
                if ($surplusOn && $day) {
                    $out[] = 'HT_SURPLUS_SOC';
                }
                return $out;
            case 'charge_priority_soc_unknown':
                return array('HT_CP_SOC_PROP', 'HT_CP_DISABLE', 'HT_BOOST');
            case 'cheap_tariff_satisfied':
                return array('HT_TARIFF_SAT', 'HT_BOOST');
            case 'solar_surplus_satisfied':
                return array('HT_SURPLUS_SAT', 'HT_BOOST');
            case 'idle_comfort_ok':
                $out = array('HT_COMFORT');
                if ($tariffOn) {
                    $out[] = 'HT_TARIFF_WAIT';
                    if ($v['t_num'] !== null && $v['night_num'] !== null && $v['t_num'] >= $v['night_num']) {
                        $out[] = 'HT_TARIFF_RAISE';
                    }
                } else {
                    $out[] = 'HT_TARIFF_ENABLE';
                }
                $out[] = $surplusItem;
                $out[] = 'HT_BOOST';
                return $out;
        }
        return array();
    }

    // ---------------------------------------------------------------- helpers

    private static function vars($s, $d, $c)
    {
        $g = function ($a, $k) {
            return (is_array($a) && isset($a[$k])) ? $a[$k] : null;
        };
        $t = $g($s, 'temp_upper');
        $soc = $g($s, 'battery_soc');
        $night = $g($c, 'NIGHT_TARGET_C');
        $max = $g($c, 'ABSOLUTE_MAX_C');
        $solar = $g($c, 'SOLAR_TARGET_C');
        $boost = $g($c, 'BOOST_TARGET_C');
        if ($boost !== null && $max !== null && (float) $boost > (float) $max) {
            $boost = $max;
        }
        $tariffCfg = array(
            (int) $g($c, 'CHEAP_START_HOUR'), (int) $g($c, 'CHEAP_START_MIN'),
            (int) $g($c, 'CHEAP_END_HOUR'), (int) $g($c, 'CHEAP_END_MIN'),
        );
        $until = (int) $g($c, 'BOOST_UNTIL_UTC');
        $now = (int) $g($s, 'now_utc');
        $raw = $g($s, 'power_raw_norm');
        if ($raw === null || $raw === '') {
            $raw = $g($s, 'power_raw');
        }
        return array(
            't'       => self::num($t, ' °C'),
            't_num'   => ($t === null ? null : (float) $t),
            'night_num' => ($night === null ? null : (float) $night),
            'soc'     => self::num($soc, '%'),
            'comfort' => self::num($g($c, 'COMFORT_MIN_C'), ''),
            'night'   => self::num($night, ''),
            'solar'   => self::num($solar, ''),
            'boost'   => self::num($boost, ''),
            'max'     => self::num($max, ''),
            'hi'      => self::num($g($c, 'SURPLUS_SOC_HIGH'), ''),
            'thr'     => self::num($g($c, 'CHARGE_PRIORITY_SOC'), ''),
            'start'   => sprintf('%02d:%02d', $tariffCfg[0], $tariffCfg[1]),
            'end'     => sprintf('%02d:%02d', $tariffCfg[2], $tariffCfg[3]),
            'meter'   => (string) ($g($s, 'tariff_meter_local') !== null ? $g($s, 'tariff_meter_local') : '—'),
            'left'    => (string) (int) $g($s, 'cooldown_remaining_s'),
            'host'    => (string) $g($c, 'ESP_HOST'),
            'prop'    => (string) $g($c, 'POWER_PROPERTY'),
            'socprop' => (string) $g($c, 'SOC_PROPERTY'),
            'active'  => (string) self::str($g($s, 'power_active_norm') !== null && $g($s, 'power_active_norm') !== '' ? $g($s, 'power_active_norm') : $g($c, 'BATTERY_ACTIVE_VALUE')),
            'raw'     => self::str($raw),
            'age'     => ($g($s, 'power_age_s') === null ? '—' : (string) (int) $g($s, 'power_age_s')),
            'ttl'     => (string) (int) $g($c, 'POWER_FRESHNESS_TTL_S'),
            'target'  => self::num($g($d, 'target_c'), ''),
            'boost_min' => ($until > $now ? (string) (int) ceil(($until - $now) / 60) : '0'),
        );
    }

    private static function num($v, $unit)
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '—';
        }
        $s = rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
        return $s . $unit;
    }

    private static function str($v)
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return is_scalar($v) ? (string) $v : '—';
    }

    private static function t($tr, $key, $vars, $fallback)
    {
        $s = is_callable($tr) ? call_user_func($tr, $key) : null;
        if (!is_string($s) || $s === '') {
            $s = $fallback;
        }
        // {@KEY} -> the UI label DS_KEY itself, so hints always quote the
        // exact label the user sees on the form (one source of truth).
        if (strpos($s, '{@') !== false) {
            $s = preg_replace_callback('/\{@([A-Z0-9_]+)\}/', function ($m) use ($tr) {
                $l = is_callable($tr) ? call_user_func($tr, $m[1]) : null;
                return (is_string($l) && $l !== '') ? rtrim($l, ': ') : $m[1];
            }, $s);
        }
        if (is_array($vars) && strpos($s, '{') !== false) {
            $s = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars) {
                return (isset($vars[$m[1]]) && is_scalar($vars[$m[1]])) ? (string) $vars[$m[1]] : $m[0];
            }, $s);
        }
        return $s;
    }
}
