<?php

use DraziceSmart\Planner;

T::group('Planner::evalHdoControl (HDO solar override)');

function hdoSnap($over = array())
{
    $s = array(
        'now_utc' => 1000000,
        'temp_upper' => 50.0,
        'battery_soc' => 100.0,
        'is_daytime' => true,
        'surplus_latched' => false,
        'hdo_override_latched' => false,
    );
    return array_merge($s, $over);
}

$hcfg = array(
    'hdo_control_enabled' => true,
    'solar_surplus_enabled' => true,
    'surplus_soc_high' => 100, 'surplus_soc_low' => 98,
    'solar_target_c' => 65, 'absolute_max_c' => 70,
);

// Disabled: HDO stays ON (boiler obeys its own tariff window), nothing latched.
$r = Planner::evalHdoControl(hdoSnap(), array_merge($hcfg, array('hdo_control_enabled' => false)));
T::eq($r['enabled'], false, 'disabled -> enabled=false');
T::eq($r['desired'], 1, 'disabled -> desired ON');
T::eq($r['latched'], 0, 'disabled -> not latched');
T::eq($r['reason'], 'hdo_default_on', 'disabled -> hdo_default_on');

// Surplus active (SOC 100, daytime), water below target -> override: HDO OFF.
$r = Planner::evalHdoControl(hdoSnap(array('battery_soc' => 100.0, 'temp_upper' => 50.0)), $hcfg);
T::eq($r['enabled'], true, 'enabled');
T::eq($r['desired'], 0, 'surplus + below target -> HDO OFF');
T::eq($r['latched'], 1, 'override latched');
T::eq($r['reason'], 'hdo_solar_override', 'reason hdo_solar_override');

// No surplus (SOC below high, not latched) -> HDO stays ON.
$r = Planner::evalHdoControl(hdoSnap(array('battery_soc' => 80.0)), $hcfg);
T::eq($r['desired'], 1, 'SOC 80 -> HDO ON');
T::eq($r['latched'], 0, 'SOC 80 -> not latched');

// Surplus hysteresis (SOC latched between low and high): stays OFF at SOC 99.
$r = Planner::evalHdoControl(hdoSnap(array(
    'battery_soc' => 99.0, 'surplus_latched' => true, 'hdo_override_latched' => true)), $hcfg);
T::eq($r['desired'], 0, 'latched surplus at SOC 99 -> HDO still OFF');
T::eq($r['latched'], 1, 'still latched at SOC 99');

// SOC falls to the low threshold -> surplus ends -> HDO restored ON.
$r = Planner::evalHdoControl(hdoSnap(array(
    'battery_soc' => 98.0, 'surplus_latched' => true, 'hdo_override_latched' => true)), $hcfg);
T::eq($r['desired'], 1, 'SOC at low threshold -> HDO restored ON');
T::eq($r['latched'], 0, 'override released at low SOC');

// Target reached while surplus still active -> release (HDO back ON).
$r = Planner::evalHdoControl(hdoSnap(array(
    'battery_soc' => 100.0, 'temp_upper' => 65.0, 'hdo_override_latched' => true)), $hcfg);
T::eq($r['desired'], 1, 'target reached -> HDO ON');
T::eq($r['latched'], 0, 'target reached -> released');

// Night (not daytime): no surplus -> HDO ON even with SOC 100.
$r = Planner::evalHdoControl(hdoSnap(array('is_daytime' => false, 'hdo_override_latched' => true)), $hcfg);
T::eq($r['desired'], 1, 'night -> HDO ON');
T::eq($r['latched'], 0, 'night -> released');

// SOC unknown (null): surplus cannot be proven -> HDO ON.
$r = Planner::evalHdoControl(hdoSnap(array('battery_soc' => null)), $hcfg);
T::eq($r['desired'], 1, 'unknown SOC -> HDO ON (no override)');

// Unknown temperature: does not START an override...
$r = Planner::evalHdoControl(hdoSnap(array('temp_upper' => null)), $hcfg);
T::eq($r['desired'], 1, 'unknown temp, not latched -> HDO ON');
// ...and does not drop an override that is already latched while surplus lasts.
$r = Planner::evalHdoControl(hdoSnap(array('temp_upper' => null, 'hdo_override_latched' => true,
    'surplus_latched' => true)), $hcfg);
T::eq($r['desired'], 0, 'unknown temp, latched + surplus -> stays OFF');

// Solar target is capped by absolute_max: target 90 -> 70, so 69.9 still overrides, 70 releases.
$capCfg = array_merge($hcfg, array('solar_target_c' => 90));
$r = Planner::evalHdoControl(hdoSnap(array('temp_upper' => 69.9)), $capCfg);
T::eq($r['desired'], 0, 'capped target 70: 69.9 -> override');
$r = Planner::evalHdoControl(hdoSnap(array('temp_upper' => 70.0)), $capCfg);
T::eq($r['desired'], 1, 'capped target 70: 70.0 -> released');

// Solar surplus feature off: no override regardless of SOC.
$r = Planner::evalHdoControl(hdoSnap(), array_merge($hcfg, array('solar_surplus_enabled' => false)));
T::eq($r['desired'], 1, 'surplus feature off -> HDO ON');

T::group('Planner::evalHdoControl: vacation');

$vcfg = array_merge($hcfg, array(
    'vacation_enabled' => true,
    'vacation_start_utc' => 900000, 'vacation_end_utc' => 1100000,
));
// Vacation without surplus: no override, HDO stays ON, reason says so.
$r = Planner::evalHdoControl(hdoSnap(), array_merge($vcfg, array('vacation_allow_surplus' => false)));
T::eq($r['desired'], 1, 'vacation (no surplus) -> HDO ON');
T::eq($r['latched'], 0, 'vacation (no surplus) -> not latched');
T::eq($r['reason'], 'hdo_vacation_on', 'vacation reason hdo_vacation_on');

// Vacation WITH surplus allowed: override works as usual.
$r = Planner::evalHdoControl(hdoSnap(), array_merge($vcfg, array('vacation_allow_surplus' => true)));
T::eq($r['desired'], 0, 'vacation + surplus allowed -> override OFF');

// Vacation window already over: behaves as normal.
$r = Planner::evalHdoControl(hdoSnap(array('now_utc' => 2000000)), array_merge($vcfg, array('vacation_allow_surplus' => false)));
T::eq($r['desired'], 0, 'vacation ended -> normal override');
