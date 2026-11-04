<?php
/**
 * Planner: a PURE function. Input = immutable snapshot + config.
 * Output = a decision with an explicit, explainable reason code.
 * No cURL, no SQL, no time() inside — time is passed in the snapshot.
 *
 * Priority ladder (highest first), per specification §8.1:
 *   1. Hardware protections (not modelled here; we never fight them).
 *   2. Unusable data / unknown power / unverified control -> hold.
 *   3. Local max continuous heat + mandatory cooldown.
 *   4. Battery-source heating ban (policy).
 *      (an active solar surplus — battery at its SOC threshold — lifts it).
 *   5. Hygiene / comfort minimum (conflicts surfaced to a human).
 *   6. Manual boost with TTL (does not bypass 1–4).
 *   6b. Charge priority "battery first" (optional): automatic heating waits
 *       for SOC >= threshold, with hysteresis; boost is not gated.
 *   7. Comfort minimum before deadline.
 *   8. Forecast / solar surplus / tariff savings.
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class Planner
{
    const ALGO_VERSION = '1.1.0';

    /**
     * @param array $s   snapshot (see Repository::buildSnapshot keys)
     * @param array $c   config
     * @return array decision: action, target_c, mode, reason, reason_human,
     *               allowed(bool), priority_level(int)
     */
    public static function decide($s, $c)
    {
        $r = array(
            'algo_version'   => self::ALGO_VERSION,
            'action'         => 'hold',
            'target_c'       => null,
            'mode'           => null,
            'reason'         => 'init',
            'reason_human'   => '',
            'allowed'        => false,
            'priority_level' => 0,
            'now_utc'        => isset($s['now_utc']) ? (int) $s['now_utc'] : 0,
        );

        $comfortMin  = isset($c['comfort_min_c'])  ? (float) $c['comfort_min_c']  : 45.0;
        $nightTarget = isset($c['night_target_c']) ? (float) $c['night_target_c'] : 55.0;
        $solarTarget = isset($c['solar_target_c']) ? (float) $c['solar_target_c'] : 65.0;
        $absoluteMax = isset($c['absolute_max_c']) ? (float) $c['absolute_max_c'] : 70.0;

        // --- Level 2: data/connectivity/control sanity -----------------------
        // (checked before vacation so we never command a boiler we cannot read)
        if (empty($s['http_available'])) {
            return self::fill($r, 'hold', null, null,
                'esp_unreachable', 2, false,
                'ESP32 недоступний по HTTP — керування призупинено.');
        }
        if (!isset($s['heating']) || $s['heating'] === null) {
            return self::fill($r, 'hold', null, null,
                'heat_state_unknown', 2, false,
                'Невідомий стан нагріву (Heat) — не можна приймати рішення.');
        }
        if (!isset($s['temp_upper']) || $s['temp_upper'] === null) {
            return self::fill($r, 'hold', null, null,
                'temp_unknown', 2, false,
                'Немає валідної температури бойлера — очікуємо дані.');
        }

        // --- Level 3: cooldown / continuous heat -----------------------------
        if (!empty($s['in_cooldown']) || (isset($s['cooldown_remaining_s']) && (int) $s['cooldown_remaining_s'] > 0)) {
            return self::fill($r, 'hold', 40.0, null,
                'cooldown_active', 3, false,
                'Триває обов\'язкова пауза після тривалого нагріву. Нагрів не дозволяємо.');
        }

        // --- Level 3.5: vacation ---------------------------------------------
        // While a vacation window is active the boiler is kept at ANTIFREEZE
        // (freeze protection only). If the user opted to still use free solar
        // surplus during the vacation, the surplus rule below (Level 8a) may
        // override it; everything else (comfort, tariff, boost) is suppressed.
        $vac = self::evalVacation($s, $c);
        if ($vac['active']) {
            $surplusDuringVac = !empty($c['vacation_allow_surplus']) && self::evalSolarSurplus($s, $c)['active'];
            if (!$surplusDuringVac) {
                return self::fill($r, 'hold', null, 'ANTIFREEZE',
                    'vacation', 3, false, $vac['human']);
            }
            // else: fall through to let the surplus rule heat with free solar.
        }

        // --- Level 4: power source -------------------------------------------
        // Note: cooldown / continuous-heat (Level 3 above) ALWAYS win — the
        // battery allowance never bypasses the boiler's own safety pause.
        $power = isset($s['power_source']) ? $s['power_source'] : 'unknown';
        if ($power === 'unknown') {
            return self::fill($r, 'hold', null, null,
                'power_unknown', 4, false,
                'Джерело живлення невідоме — консервативно не запускаємо нагрів.');
        }

        // Solar surplus is evaluated BEFORE the battery-source ban: when the
        // battery has reached the surplus threshold the extra energy comes from
        // solar generation regardless of whether the inverter currently runs the
        // house from grid or battery. The surplus LOW threshold (hysteresis)
        // stops heating before the battery is meaningfully discharged.
        $surplus = self::evalSolarSurplus($s, $c);

        // On battery: blocked by default. Allowed only when the user explicitly
        // opts in — either a global "allow battery heat" flag, or a manual boost
        // that is marked battery-allowed — or when a solar surplus is active.
        // Boiler-safety (cooldown) still applies.
        $onBattery = ($power === 'battery');
        $allowBattery = !empty($c['allow_battery_heat']);
        $boostActive = !empty($s['boost_active']);
        $boostAllowsBattery = !empty($s['boost_allow_battery']);
        if ($onBattery && !$allowBattery && !($boostActive && $boostAllowsBattery) && !$surplus['active']) {
            return self::fill($r, 'hold', null, null,
                'battery_source_block', 4, false,
                'Живлення від батарей — нові пуски нагріву заборонено політикою. '
                . 'Увімкніть «Дозволити нагрів від батарей» або boost з дозволом на батарею, якщо справді потрібна тепла вода.');
        }

        // During a vacation with "use solar surplus" on, ONLY the surplus rule
        // may heat: skip boost / comfort / tariff so free solar is the only
        // thing that raises the temperature.
        $vacSurplusOnly = $vac['active'];

        // --- Level 6: manual boost (does not bypass cooldown/continuous-heat) -
        if (!$vacSurplusOnly && $boostActive) {
            $bt = isset($c['boost_target_c']) ? (float) $c['boost_target_c'] : $absoluteMax;
            if ($bt > $absoluteMax) {
                $bt = $absoluteMax;
            }
            if ($s['temp_upper'] < $bt) {
                $human = 'Активний ручний boost до ' . self::fmt($bt) . ' °C.';
                if ($onBattery) {
                    $human .= ' Живлення від батарей — свідомо дозволено користувачем.';
                }
                return self::fill($r, 'heat', $bt, 'MANUAL',
                    'manual_boost', 6, true, $human);
            }
        }

        // --- Level 6b: charge priority (battery first) ------------------------
        // Optional. Automatic heating (comfort minimum, cheap tariff) waits until
        // the battery SOC reaches a threshold, so the boiler does not start at
        // the same moment as a high-power battery charge (e.g. right after a
        // blackout). Manual boost above is NOT gated: it is an explicit decision.
        // The solar-surplus rule has its own SOC threshold and is not gated.
        $charge = self::evalChargePriority($s, $c);
        if ($charge['blocks'] && !$surplus['active']) {
            return self::fill($r, 'hold', null, null,
                $charge['reason'], 6, false, $charge['human']);
        }

        // --- Level 7: comfort minimum ----------------------------------------
        if (!$vacSurplusOnly && $s['temp_upper'] < $comfortMin) {
            return self::fill($r, 'heat', $comfortMin, 'MANUAL',
                'comfort_minimum', 7, true,
                'Температура нижча за комфортний мінімум ' . self::fmt($comfortMin) . ' °C — гріємо.');
        }

        // --- Level 8a: solar surplus by battery SOC --------------------------
        // Daytime + SOC reached high threshold => surplus, heat until low thr.
        if ($surplus['active']) {
            $st = $solarTarget;
            if ($st > $absoluteMax) {
                $st = $absoluteMax;
            }
            if ($s['temp_upper'] < $st) {
                return self::fill($r, 'heat', $st, 'MANUAL',
                    'solar_surplus', 8, true,
                    $surplus['human']);
            }
            return self::fill($r, 'hold', $st, null,
                'solar_surplus_satisfied', 8, false,
                'Сонячний надлишок є, але бойлер уже прогрітий до цілі.');
        }

        // --- Level 8b: cheap tariff window -----------------------------------
        if (!$vacSurplusOnly && !empty($s['tariff_active'])) {
            if ($s['temp_upper'] < $nightTarget) {
                return self::fill($r, 'heat', $nightTarget, 'MANUAL',
                    'cheap_tariff', 8, true,
                    'Дешевий тариф — гріємо до нічної цілі ' . self::fmt($nightTarget) . ' °C.');
            }
            return self::fill($r, 'hold', $nightTarget, null,
                'cheap_tariff_satisfied', 8, false,
                'Дешевий тариф, але нічну ціль уже досягнуто.');
        }

        // During a vacation with surplus allowed but no surplus right now:
        // stay at ANTIFREEZE, not generic idle.
        if ($vacSurplusOnly) {
            return self::fill($r, 'hold', null, 'ANTIFREEZE',
                'vacation', 3, false, $vac['human']);
        }

        // Nothing to do: comfort satisfied, day tariff, no surplus.
        return self::fill($r, 'hold', null, null,
            'idle_comfort_ok', 8, false,
            'Нема підстав гріти: комфорт забезпечено, тариф денний, надлишку нема.');
    }

    /**
     * HDO "solar override" decision. Base state is HDO on (the boiler obeys its
     * own cheap-tariff window). The module turns HDO OFF only to let the boiler
     * use free solar outside that window:
     *
     *   desired = ON   normally
     *   desired = OFF  when the solar surplus is active AND temp_upper is below
     *                  the surplus target (so the boiler may heat now on free
     *                  solar regardless of its tariff window).
     *
     * Hysteresis via the latch carried in snapshot['hdo_override_latched']:
     * once OFF, stay OFF until the surplus ends (surplus rule already has its
     * own SOC hysteresis) or the surplus target is reached.
     * Vacation (without surplus) forces HDO back ON — no override on vacation.
     *
     * @return array enabled, desired(1|0), reason, human, latched(1|0)
     */
    public static function evalHdoControl($s, $c)
    {
        $out = array('enabled' => false, 'desired' => 1, 'reason' => 'hdo_default_on',
            'human' => 'HDO увімкнено: бойлер гріє у власному тарифному вікні.', 'latched' => 0);
        if (empty($c['hdo_control_enabled'])) {
            return $out;
        }
        $out['enabled'] = true;

        // Vacation without surplus: no override, keep HDO as the boiler default.
        $vac = self::evalVacation($s, $c);
        if ($vac['active'] && empty($c['vacation_allow_surplus'])) {
            $out['reason'] = 'hdo_vacation_on';
            $out['human'] = 'Відпустка: HDO лишаємо як є, оверрайд вимкнено.';
            return $out;
        }

        $surplus = self::evalSolarSurplus($s, $c);
        $target = isset($c['solar_target_c']) ? (float) $c['solar_target_c'] : 65.0;
        $max = isset($c['absolute_max_c']) ? (float) $c['absolute_max_c'] : 70.0;
        if ($target > $max) {
            $target = $max;
        }
        $temp = isset($s['temp_upper']) ? $s['temp_upper'] : null;
        $latched = !empty($s['hdo_override_latched']);

        if ($surplus['active'] && $temp !== null && (float) $temp < $target) {
            $latched = true;
        }
        // Release the override when surplus ended or the target is reached.
        if (!$surplus['active'] || ($temp !== null && (float) $temp >= $target)) {
            $latched = false;
        }

        $out['latched'] = $latched ? 1 : 0;
        if ($latched) {
            $out['desired'] = 0;
            $out['reason'] = 'hdo_solar_override';
            $out['human'] = 'Сонячний надлишок: тимчасово вимикаємо HDO, щоб бойлер грів на безплатному сонці до '
                . self::fmt($target) . ' °C незалежно від тарифного вікна.';
        }
        return $out;
    }

    /**
     * Vacation window. Active when now_utc is within [start, end). The caller
     * supplies vacation_enabled, vacation_start_utc, vacation_end_utc in $c.
     *
     * @return array active(bool), human(string)
     */
    public static function evalVacation($s, $c)
    {
        $out = array('active' => false, 'human' => '');
        if (empty($c['vacation_enabled'])) {
            return $out;
        }
        $now = isset($s['now_utc']) ? (int) $s['now_utc'] : 0;
        $start = isset($c['vacation_start_utc']) ? (int) $c['vacation_start_utc'] : 0;
        $end = isset($c['vacation_end_utc']) ? (int) $c['vacation_end_utc'] : 0;
        if ($start <= 0 || $end <= 0 || $end <= $start) {
            return $out;
        }
        if ($now < $start) {
            $out['human'] = 'Відпустка заплановано, але ще не почалася.';
            return $out;
        }
        if ($now >= $end) {
            $out['human'] = 'Відпустка завершилася.';
            return $out;
        }
        $out['active'] = true;
        $daysLeft = (int) ceil(($end - $now) / 86400);
        $out['human'] = 'Режим відпустки: тримаємо захист від замерзання (ANTIFREEZE). '
            . 'Залишилось днів: ' . $daysLeft . '.';
        if (!empty($c['vacation_allow_surplus'])) {
            $out['human'] .= ' Сонячний надлишок дозволено (безплатна енергія).';
        }
        return $out;
    }

    /**
     * Solar surplus rule. Requires daytime and a valid SOC that reached the
     * high threshold. Hysteresis: once active, stays active until SOC drops to
     * the low threshold (state carried in snapshot as surplus_latched).
     */
    private static function evalSolarSurplus($s, $c)
    {
        $out = array('active' => false, 'human' => '');
        if (empty($c['solar_surplus_enabled'])) {
            return $out;
        }
        if (empty($s['is_daytime'])) {
            return $out;
        }
        if (!isset($s['battery_soc']) || $s['battery_soc'] === null) {
            return $out;
        }
        $soc = (float) $s['battery_soc'];
        $hi = isset($c['surplus_soc_high']) ? (float) $c['surplus_soc_high'] : 100.0;
        $lo = isset($c['surplus_soc_low'])  ? (float) $c['surplus_soc_low']  : 98.0;

        $latched = !empty($s['surplus_latched']);
        if (!$latched && $soc >= $hi) {
            $latched = true;
        }
        if ($latched && $soc <= $lo) {
            $latched = false;
        }
        if ($latched) {
            $out['active'] = true;
            $out['human'] = 'Надлишок генерації: SOC батареї ' . self::fmt($soc)
                . '% (поріг ' . self::fmt($hi) . '%). Витрачаємо надлишок на нагрів води, доки SOC не впаде до '
                . self::fmt($lo) . '%.';
        }
        return $out;
    }

    /**
     * Charge-priority ("battery first") rule. Pure: the latch state comes in
     * as snapshot['charge_released_latched'] and the new value goes out as
     * 'released' (the caller persists it).
     *
     *   disabled            -> blocks=false
     *   SOC unknown         -> blocks=true  (conservative: cannot tell whether
     *                          the battery is still charging)
     *   not released        -> released once SOC >= threshold
     *   released            -> stays released until SOC < threshold - hysteresis
     *
     * @return array enabled, blocks, released, reason, human
     */
    public static function evalChargePriority($s, $c)
    {
        $out = array('enabled' => false, 'blocks' => false, 'released' => false,
            'reason' => '', 'human' => '');
        if (empty($c['charge_priority_enabled'])) {
            return $out;
        }
        $out['enabled'] = true;
        $thr = isset($c['charge_priority_soc']) && is_numeric($c['charge_priority_soc'])
            ? (float) $c['charge_priority_soc'] : 100.0;
        $hyst = isset($c['charge_priority_hyst']) && is_numeric($c['charge_priority_hyst'])
            ? max(0.0, (float) $c['charge_priority_hyst']) : 5.0;
        $soc = isset($s['battery_soc']) ? $s['battery_soc'] : null;
        if ($soc === null) {
            $out['blocks'] = true;
            $out['reason'] = 'charge_priority_soc_unknown';
            $out['human'] = 'Увімкнено «Спершу заряд акумулятора», але SOC невідомий — '
                . 'консервативно не запускаємо автоматичний нагрів. Перевірте властивість SOC. '
                . 'Ручний boost працює.';
            return $out;
        }
        $soc = (float) $soc;
        $released = !empty($s['charge_released_latched']);
        if (!$released && $soc >= $thr) {
            $released = true;
        }
        if ($released && $soc < $thr - $hyst) {
            $released = false;
        }
        $out['released'] = $released;
        if (!$released) {
            $out['blocks'] = true;
            $out['reason'] = 'charge_priority_wait';
            $out['human'] = 'Спершу заряджаємо акумулятор: SOC ' . self::fmt($soc) . '% < порогу '
                . self::fmt($thr) . '%. Автоматичний нагрів почнеться після заряду '
                . '(щоб заряд акумулятора і бойлер не вмикались одночасно). Ручний boost працює.';
        }
        return $out;
    }

    private static function fill($r, $action, $target, $mode, $reason, $level, $allowed, $human)
    {
        $r['action'] = $action;
        $r['target_c'] = $target;
        $r['mode'] = $mode;
        $r['reason'] = $reason;
        $r['priority_level'] = $level;
        $r['allowed'] = $allowed;
        $r['reason_human'] = $human;
        return $r;
    }

    private static function fmt($n)
    {
        return rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.');
    }
}
