<?php

use DraziceSmart\Planner;

T::group('Planner priorities');

function baseSnap($over = array())
{
    $s = array(
        'now_utc' => 1000000,
        'http_available' => true,
        'temp_upper' => 50.0,
        'temp_lower' => 48.0,
        'heating' => false,
        'in_cooldown' => false,
        'cooldown_remaining_s' => 0,
        'power_source' => 'grid',
        'battery_soc' => 50.0,
        'is_daytime' => true,
        'tariff_active' => false,
        'boost_active' => false,
        'surplus_latched' => false,
        'solar_surplus_enabled' => false,
    );
    return array_merge($s, $over);
}

$cfg = array(
    'comfort_min_c' => 45, 'night_target_c' => 55, 'solar_target_c' => 65,
    'boost_target_c' => 65, 'absolute_max_c' => 70,
    'solar_surplus_enabled' => true, 'surplus_soc_high' => 100, 'surplus_soc_low' => 98,
);

// Level 2: ESP unreachable -> hold, not allowed.
$d = Planner::decide(baseSnap(array('http_available' => false)), $cfg);
T::eq($d['reason'], 'esp_unreachable', 'unreachable -> esp_unreachable');
T::eq($d['allowed'], false, 'unreachable -> not allowed');

// Level 2: unknown heat.
$d = Planner::decide(baseSnap(array('heating' => null)), $cfg);
T::eq($d['reason'], 'heat_state_unknown', 'null heat -> heat_state_unknown');

// Level 3: cooldown blocks heating.
$d = Planner::decide(baseSnap(array('in_cooldown' => true, 'cooldown_remaining_s' => 120, 'temp_upper' => 30.0)), $cfg);
T::eq($d['reason'], 'cooldown_active', 'cooldown -> cooldown_active');
T::eq($d['allowed'], false, 'cooldown -> not allowed even if cold');

// Level 4: unknown power blocks.
$d = Planner::decide(baseSnap(array('power_source' => 'unknown', 'temp_upper' => 30.0)), $cfg);
T::eq($d['reason'], 'power_unknown', 'unknown power -> power_unknown');

// Level 4: battery blocks new heating.
$d = Planner::decide(baseSnap(array('power_source' => 'battery', 'temp_upper' => 30.0)), $cfg);
T::eq($d['reason'], 'battery_source_block', 'battery -> battery_source_block');
T::eq($d['allowed'], false, 'battery -> not allowed');

// Level 7: below comfort minimum -> heat.
$d = Planner::decide(baseSnap(array('temp_upper' => 40.0)), $cfg);
T::eq($d['reason'], 'comfort_minimum', 'below comfort -> comfort_minimum');
T::eq($d['allowed'], true, 'below comfort -> allowed');
T::eq($d['target_c'], 45.0, 'comfort target = 45');

// Level 8a: solar surplus by SOC (daytime, SOC>=100).
$d = Planner::decide(baseSnap(array('battery_soc' => 100.0, 'temp_upper' => 55.0)), $cfg);
T::eq($d['reason'], 'solar_surplus', 'SOC 100 daytime -> solar_surplus');
T::eq($d['target_c'], 65.0, 'solar target = 65');
T::eq($d['allowed'], true, 'solar surplus -> allowed');

// Surplus hysteresis: latched true, SOC 99 (between low 98 and high 100) stays active.
$d = Planner::decide(baseSnap(array('battery_soc' => 99.0, 'temp_upper' => 55.0, 'surplus_latched' => true)), $cfg);
T::eq($d['reason'], 'solar_surplus', 'latched + SOC 99 -> still surplus');

// Surplus releases at low threshold.
$d = Planner::decide(baseSnap(array('battery_soc' => 98.0, 'temp_upper' => 55.0, 'surplus_latched' => true)), $cfg);
T::ok($d['reason'] !== 'solar_surplus', 'SOC drops to low 98 -> surplus released');

// Surplus NOT active at night (not daytime).
$d = Planner::decide(baseSnap(array('battery_soc' => 100.0, 'is_daytime' => false, 'temp_upper' => 55.0, 'tariff_active' => true)), $cfg);
T::ok($d['reason'] !== 'solar_surplus', 'night + SOC100 -> not solar_surplus');

// Level 8b: cheap tariff night heating.
$d = Planner::decide(baseSnap(array('tariff_active' => true, 'is_daytime' => false, 'temp_upper' => 50.0, 'battery_soc' => 50.0)), $cfg);
T::eq($d['reason'], 'cheap_tariff', 'cheap tariff -> cheap_tariff');
T::eq($d['target_c'], 55.0, 'night target = 55');

// Boost does not bypass battery block.
$d = Planner::decide(baseSnap(array('boost_active' => true, 'power_source' => 'battery', 'temp_upper' => 30.0)), $cfg);
T::eq($d['reason'], 'battery_source_block', 'boost does NOT bypass battery');

// Boost does not bypass cooldown.
$d = Planner::decide(baseSnap(array('boost_active' => true, 'in_cooldown' => true, 'cooldown_remaining_s' => 60, 'temp_upper' => 30.0)), $cfg);
T::eq($d['reason'], 'cooldown_active', 'boost does NOT bypass cooldown');

// Absolute max caps the target.
$cfgCap = $cfg; $cfgCap['solar_target_c'] = 90; $cfgCap['absolute_max_c'] = 70;
$d = Planner::decide(baseSnap(array('battery_soc' => 100.0, 'temp_upper' => 60.0)), $cfgCap);
T::eq($d['target_c'], 70.0, 'target capped to absolute_max 70');

// Idle when comfort satisfied, day, no surplus.
$cfgNoSurplus = $cfg; $cfgNoSurplus['solar_surplus_enabled'] = false;
$d = Planner::decide(baseSnap(array('temp_upper' => 60.0, 'solar_surplus_enabled' => false)), $cfgNoSurplus);
T::eq($d['reason'], 'idle_comfort_ok', 'comfort ok day -> idle');
