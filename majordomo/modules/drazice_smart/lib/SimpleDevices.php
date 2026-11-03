<?php
/**
 * SimpleDevices: integration with MajorDoMo "Простые устройства" (Simple
 * Devices, the `devices` module).
 *
 * The Drazice boiler is surfaced as standard simple devices so it appears and
 * is controllable in the native Devices UI (not only in this module's panel):
 *
 *   - a THERMOSTAT device (class SThermostats) — current/target temperature,
 *     mode, and relay status (= Heat). It is created with relay_readonly so the
 *     core thermostat logic only DISPLAYS; it never drives the element itself.
 *     Target changes made in the Devices UI are picked up here and sent through
 *     the guarded REST path (cooldown / lease preserved).
 *   - sensor devices (temperature lower, energy, battery SOC, HDO) so every
 *     telemetry value is visible as a normal object/property.
 *
 * This adapter is intentionally defensive: it only acts when the MajorDoMo
 * `devices` runtime (addDevice / getObject / setGlobal / getGlobal) is present,
 * and it is idempotent (addDevice dedups by LINKED_OBJECT). Nothing here runs
 * in unit tests (the class is not loaded there).
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class SimpleDevices
{
    /**
     * Device map: our logical key => array(type, object, class, title).
     * The "type" is the simple-device TYPE as registered in the devices
     * dictionary; the admin confirms/overrides these in the UI because exact
     * type keys come from the running MajorDoMo, not from a guess.
     */
    public static function defaultMap()
    {
        return array(
            'thermostat' => array(
                'type'   => 'SThermostats',
                'object' => 'DraziceBoiler',
                'title'  => 'Drazice Boiler',
            ),
            'temp_lower' => array(
                'type'   => 'STempHumSensors',
                'object' => 'DraziceBoilerTempLower',
                'title'  => 'Drazice Boiler Temp Lower',
            ),
            'energy' => array(
                'type'   => 'SPowerSensors',
                'object' => 'DraziceBoilerEnergy',
                'title'  => 'Drazice Boiler Energy',
            ),
            'battery_soc' => array(
                'type'   => 'SSensors',
                'object' => 'DraziceBoilerSoc',
                'title'  => 'Drazice Boiler SOC',
            ),
            'hdo' => array(
                'type'   => 'SControllers',
                'object' => 'DraziceBoilerHdo',
                'title'  => 'Drazice Boiler HDO',
            ),
        );
    }

    /** Is the Devices runtime available? */
    public static function available()
    {
        return function_exists('getObject') && function_exists('setGlobal')
            && class_exists('devices');
    }

    /**
     * Create the simple devices if missing. Returns a report array.
     * $map is the (possibly admin-edited) device map.
     */
    public static function provision($map)
    {
        $report = array();
        if (!self::available()) {
            return array('ok' => false, 'reason' => 'devices_runtime_unavailable');
        }
        $dv = new \devices();
        foreach ($map as $key => $d) {
            $object = isset($d['object']) ? $d['object'] : '';
            $type = isset($d['type']) ? $d['type'] : '';
            $title = isset($d['title']) ? $d['title'] : $object;
            if ($object === '' || $type === '') {
                $report[$key] = 'skipped_no_object_or_type';
                continue;
            }
            // Idempotent: if an object already exists, do not recreate.
            if (function_exists('getObject') && getObject($object)) {
                $report[$key] = 'exists';
                continue;
            }
            $res = $dv->addDevice($type, array(
                'TITLE'         => $title,
                'LINKED_OBJECT' => $object,
            ));
            $report[$key] = $res ? 'created' : 'add_failed';
        }
        return array('ok' => true, 'report' => $report);
    }

    /**
     * Push the current boiler snapshot into the simple-device objects so they
     * show up in the native Devices UI. Uses dirty-checking (only writes on
     * change) to avoid setGlobal feedback loops.
     *
     * For the thermostat:
     *   value               <- temp_upper (current temperature)
     *   currentTargetValue  <- requested/planned target (display)
     *   relay_status        <- heating (read-only mirror of Heat)
     *   relay_readonly      <- 1 (core logic must not drive the element)
     *   mode                <- reported/requested mode (string)
     */
    public static function publish($map, $snapshot, $decision)
    {
        if (!self::available()) {
            return;
        }
        // Thermostat.
        if (!empty($map['thermostat']['object'])) {
            $o = $map['thermostat']['object'];
            self::sg($o . '.relay_readonly', 1);
            if ($snapshot['temp_upper'] !== null) {
                self::sgChanged($o . '.value', $snapshot['temp_upper']);
            }
            if ($snapshot['heating'] !== null) {
                self::sgChanged($o . '.relay_status', $snapshot['heating'] ? 1 : 0);
            }
            // Target shown = planned target if any, else requested target.
            $target = null;
            if (isset($decision['target_c']) && $decision['target_c'] !== null) {
                $target = $decision['target_c'];
            }
            if ($target !== null) {
                self::sgChanged($o . '.currentTargetValue', $target);
            }
        }
        // Temperature lower sensor.
        if (!empty($map['temp_lower']['object']) && $snapshot['temp_lower'] !== null) {
            self::sgChanged($map['temp_lower']['object'] . '.value', $snapshot['temp_lower']);
        }
        // Energy (power sensor carries a cumulative value).
        if (!empty($map['energy']['object']) && $snapshot['energy_total_kwh'] !== null) {
            self::sgChanged($map['energy']['object'] . '.value', $snapshot['energy_total_kwh']);
        }
        // Battery SOC.
        if (!empty($map['battery_soc']['object']) && $snapshot['battery_soc'] !== null) {
            self::sgChanged($map['battery_soc']['object'] . '.value', $snapshot['battery_soc']);
        }
        // HDO as a controller status.
        if (!empty($map['hdo']['object']) && $snapshot['hdo'] !== null) {
            self::sgChanged($map['hdo']['object'] . '.status', $snapshot['hdo'] ? 1 : 0);
        }
    }

    /**
     * Read a target-temperature command issued from the native Devices UI.
     * The Devices thermostat writes currentTargetValue; we compare it to the
     * last value we published and, if the user changed it, return the new
     * target so the caller can send it through the guarded REST path.
     *
     * Returns null if no new command, or a float target (clamped 40..80).
     */
    public static function readTargetCommand($map, $lastPublishedTarget)
    {
        if (!self::available() || empty($map['thermostat']['object'])) {
            return null;
        }
        $o = $map['thermostat']['object'];
        $cur = self::gg($o . '.currentTargetValue');
        if ($cur === null || $cur === '' || !is_numeric($cur)) {
            return null;
        }
        $cur = (float) $cur;
        if ($lastPublishedTarget !== null && abs($cur - (float) $lastPublishedTarget) < 0.01) {
            return null; // unchanged -> not a user command
        }
        if ($cur < 40.0) {
            $cur = 40.0;
        }
        if ($cur > 80.0) {
            $cur = 80.0;
        }
        return $cur;
    }

    // --- helpers -------------------------------------------------------------

    private static function sg($prop, $val)
    {
        if (function_exists('setGlobal')) {
            setGlobal($prop, $val);
        }
    }

    private static function sgChanged($prop, $val)
    {
        if (!function_exists('setGlobal') || !function_exists('getGlobal')) {
            return;
        }
        $cur = getGlobal($prop);
        if ((string) $cur !== (string) $val) {
            setGlobal($prop, $val);
        }
    }

    private static function gg($prop)
    {
        if (!function_exists('getGlobal')) {
            return null;
        }
        $v = getGlobal($prop);
        return ($v === '' || $v === false) ? null : $v;
    }
}
