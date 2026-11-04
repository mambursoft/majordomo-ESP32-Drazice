<?php

use DraziceSmart\SimpleDevices;

T::group('SimpleDevices map (type keys, not class names)');

// The MajorDoMo devices dictionary is keyed by TYPE KEY (thermostat,
// sensor_temp, ...). Class names never matched, so nothing was ever created.
$validKeys = array('thermostat', 'sensor_temp', 'sensor_general', 'sensor_percentage',
    'sensor_power', 'sensor_state', 'counter', 'relay');
foreach (SimpleDevices::defaultMap() as $key => $d) {
    T::ok(in_array($d['type'], $validKeys, true), "default map '$key' uses a type key ({$d['type']})");
    T::ok(isset($d['object']) && $d['object'] !== '', "default map '$key' has an object");
}
T::eq(isset(SimpleDevices::defaultMap()['hdo']), false, 'HDO (a boiler config flag) is not a default device');
// Battery SOC mirrors the Deye object (deye_battery.soc); a dedicated card
// would only duplicate it, so it is not provisioned by default (1.13.1).
T::eq(isset(SimpleDevices::defaultMap()['battery_soc']), false, 'battery_soc (Deye mirror) is not a default device');
T::eq(count(SimpleDevices::defaultMap()), 3, 'default map has 3 devices (thermostat + 2 sensors)');

// Legacy maps saved by older builds (class names) are converted.
T::eq(SimpleDevices::normalizeType('SThermostats'), 'thermostat', 'legacy SThermostats -> thermostat');
T::eq(SimpleDevices::normalizeType('STempHumSensors'), 'sensor_temp', 'legacy STempHumSensors -> sensor_temp');
T::eq(SimpleDevices::normalizeType('SPowerSensors'), 'sensor_general', 'legacy SPowerSensors -> sensor_general');
T::eq(SimpleDevices::normalizeType('SSensors'), 'sensor_general', 'legacy SSensors -> sensor_general');
T::eq(SimpleDevices::normalizeType('thermostat'), 'thermostat', 'type key passes through');
T::eq(SimpleDevices::normalizeType('sensor_humidity'), 'sensor_humidity', 'unknown value passes through');
