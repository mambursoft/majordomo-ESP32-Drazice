<?php

use DraziceSmart\Planner;
use DraziceSmart\Explain;

T::group('Planner: ESP32 guard FAULT (commands are silently dropped)');

function gfSnap($over = array())
{
    $s = array(
        'now_utc' => 1000000, 'http_available' => true, 'temp_upper' => 33.0, 'temp_lower' => 29.0,
        'heating' => false, 'in_cooldown' => false, 'cooldown_remaining_s' => 0,
        'power_source' => 'grid', 'battery_soc' => 50.0, 'is_daytime' => true, 'tariff_active' => false,
        'boost_active' => false, 'boost_allow_battery' => false, 'surplus_latched' => false,
        'guard_state' => 'IDLE',
    );
    return array_merge($s, $over);
}

$gcfg = array(
    'comfort_min_c' => 45, 'night_target_c' => 55, 'solar_target_c' => 65,
    'boost_target_c' => 65, 'absolute_max_c' => 70,
);

// Production case: boost active, grid power, cold water, cooldown_remaining=0 — but guard FAULT.
$d = Planner::decide(gfSnap(array('guard_state' => 'FAULT', 'boost_active' => true)), $gcfg);
T::eq($d['reason'], 'guard_fault', 'boost + guard FAULT -> guard_fault (not manual_boost)');
T::eq($d['action'], 'hold', 'guard FAULT -> hold');
T::eq($d['allowed'], false, 'guard FAULT -> not allowed');

// Same snapshot with a healthy guard: boost works as before.
$d = Planner::decide(gfSnap(array('guard_state' => 'IDLE', 'boost_active' => true)), $gcfg);
T::eq($d['reason'], 'manual_boost', 'healthy guard + boost -> manual_boost');
T::eq($d['allowed'], true, 'healthy guard + boost -> allowed');

// Cheap-tariff heating is blocked by FAULT too.
$d = Planner::decide(gfSnap(array('guard_state' => 'FAULT', 'tariff_active' => true, 'is_daytime' => false)), $gcfg);
T::eq($d['reason'], 'guard_fault', 'tariff window + guard FAULT -> guard_fault');

// Other guard states do not trigger the rule.
foreach (array('IDLE', 'HEATING_ALLOWED', 'LEASE_EXPIRED', 'BOOT_SAFE', '') as $gs) {
    $d = Planner::decide(gfSnap(array('guard_state' => $gs)), $gcfg);
    T::ok($d['reason'] !== 'guard_fault', "guard_state '$gs' is not guard_fault");
}

// Missing guard_state (old firmware / no data) does not trigger it.
$snap = gfSnap();
unset($snap['guard_state']);
$d = Planner::decide($snap, $gcfg);
T::ok($d['reason'] !== 'guard_fault', 'no guard_state key -> not guard_fault');

// ESP unreachable still wins (level 2, evaluated first).
$d = Planner::decide(gfSnap(array('guard_state' => 'FAULT', 'http_available' => false)), $gcfg);
T::eq($d['reason'], 'esp_unreachable', 'ESP down is reported before guard FAULT');

// Explain knows the new code and its how-to.
T::ok(in_array('guard_fault', Explain::reasonCodes(), true), 'Explain::reasonCodes has guard_fault');
T::ok(in_array('RSC_GUARD_FAULT', Explain::keys(), true), 'Explain::keys has RSC_GUARD_FAULT');
T::ok(in_array('HT_GUARD_FAULT', Explain::keys(), true), 'Explain::keys has HT_GUARD_FAULT');
