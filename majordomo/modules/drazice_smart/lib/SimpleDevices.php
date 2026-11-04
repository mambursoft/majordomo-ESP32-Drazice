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
        // "type" = simple-device TYPE KEY of the MajorDoMo devices dictionary
        // (modules/devices/devices_structure.inc.php), NOT a class name.
        return array(
            'thermostat' => array(
                'type'   => 'thermostat',
                'object' => 'DraziceBoiler',
                'title'  => 'Drazice Boiler',
            ),
            'temp_lower' => array(
                'type'   => 'sensor_temp',
                'object' => 'DraziceBoilerTempLower',
                'title'  => 'Drazice Boiler Temp Lower',
            ),
            'energy' => array(
                'type'   => 'sensor_general',
                'object' => 'DraziceBoilerEnergy',
                'title'  => 'Drazice Boiler Energy, kWh',
            ),
            'battery_soc' => array(
                'type'   => 'sensor_percentage',
                'object' => 'DraziceBoilerSoc',
                'title'  => 'Drazice Boiler Battery SOC',
            ),
        );
    }

    /**
     * Older builds stored CLASS names as "type" (that never matched the devices
     * dictionary, so nothing was ever created). Map them to type keys so a map
     * saved by an older version still works. Unknown values pass through.
     */
    public static function normalizeType($type)
    {
        $legacy = array(
            'SThermostats'      => 'thermostat',
            'STempHumSensors'   => 'sensor_temp',
            'STempSensors'      => 'sensor_temp',
            'SPowerSensors'     => 'sensor_general',
            'SSensors'          => 'sensor_general',
            'SPercentageSensors' => 'sensor_percentage',
            'SStateSensors'     => 'sensor_state',
        );
        $type = (string) $type;
        return isset($legacy[$type]) ? $legacy[$type] : $type;
    }

    /**
     * Which map entries already have a simple device LINKED to their object.
     * Returns key => array(object, title, type, linked(bool), device_id, device_title).
     * Pure DB read; empty linked flags when the DB is not available.
     */
    public static function linkStatus($map)
    {
        $out = array();
        foreach ($map as $key => $d) {
            $object = isset($d['object']) ? (string) $d['object'] : '';
            $row = array(
                'object'       => $object,
                'title'        => isset($d['title']) ? (string) $d['title'] : $object,
                'type'         => self::normalizeType(isset($d['type']) ? $d['type'] : ''),
                'linked'       => false,
                'device_id'    => 0,
                'device_title' => '',
            );
            if ($object !== '' && function_exists('SQLSelectOne')) {
                $dev = SQLSelectOne("SELECT ID, TITLE FROM devices WHERE LINKED_OBJECT='" . self::sql($object) . "'");
                if (is_array($dev) && !empty($dev['ID'])) {
                    $row['linked'] = true;
                    $row['device_id'] = (int) $dev['ID'];
                    $row['device_title'] = (string) $dev['TITLE'];
                }
            }
            $out[$key] = $row;
        }
        return $out;
    }

    private static function sql($s)
    {
        return function_exists('DBSafe') ? DBSafe($s) : addslashes($s);
    }

    /** Is the Devices runtime available? */
    public static function available()
    {
        return function_exists('getObject') && function_exists('setGlobal')
            && (class_exists('devices') || self::canLoadDevicesClass());
    }

    /**
     * The MajorDoMo `devices` module class is not always autoloaded. If the
     * module directory is present, require it so class_exists() can succeed.
     * Returns true if the class is available after the attempt.
     */
    public static function canLoadDevicesClass()
    {
        if (class_exists('devices')) {
            return true;
        }
        // ROOT is defined by MajorDoMo; fall back to this file's location.
        $roots = array();
        if (defined('ROOT')) {
            $roots[] = rtrim(ROOT, '/\\') . '/modules/devices/devices.class.php';
        }
        $roots[] = dirname(dirname(dirname(dirname(__DIR__)))) . '/modules/devices/devices.class.php';
        foreach ($roots as $p) {
            if (is_file($p)) {
                require_once($p);
                return class_exists('devices');
            }
        }
        return false;
    }

    /**
     * Human-diagnosable reason why the integration is or is not available.
     * Shown in the admin banner so a "not available" verdict is explainable
     * instead of silent. Returns array(ok(bool), missing(list), note(string)).
     */
    public static function diagnose()
    {
        $missing = array();
        foreach (array('getObject', 'setGlobal', 'getGlobal', 'addClassObject', 'SQLInsert', 'SQLSelectOne') as $fn) {
            if (!function_exists($fn)) {
                $missing[] = $fn . '()';
            }
        }
        $hasClass = class_exists('devices') || self::canLoadDevicesClass();
        if (!$hasClass) {
            $missing[] = "class devices";
        }
        $note = '';
        if ($hasClass) {
            $dv = new \devices();
            if (method_exists($dv, 'setDictionary')) {
                $dv->setDictionary();
            }
            $types = (isset($dv->device_types) && is_array($dv->device_types)) ? array_keys($dv->device_types) : array();
            $note = 'devices types: ' . (empty($types) ? '(none)' : implode(', ', array_slice($types, 0, 40)));
        }
        return array('ok' => empty($missing), 'missing' => $missing, 'note' => $note);
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
        if (method_exists($dv, 'setDictionary')) {
            $dv->setDictionary();
        }
        $types = (isset($dv->device_types) && is_array($dv->device_types)) ? $dv->device_types : array();
        $status = self::linkStatus($map);
        $created = 0;
        foreach ($map as $key => $d) {
            $object = isset($d['object']) ? (string) $d['object'] : '';
            $type = self::normalizeType(isset($d['type']) ? $d['type'] : '');
            $title = isset($d['title']) ? (string) $d['title'] : $object;
            if ($object === '' || $type === '') {
                $report[$key] = 'skipped_no_object_or_type';
                continue;
            }
            // Idempotent: a simple device already linked to this object.
            if (!empty($status[$key]['linked'])) {
                $report[$key] = 'exists';
                continue;
            }
            if (!isset($types[$type]['CLASS'])) {
                $report[$key] = 'unknown_type';
                continue;
            }
            // NOT devices::addDevice(): in MajorDoMo core it resets $rec right
            // after taking LINKED_OBJECT, so the device would be linked to a new
            // auto-named object instead of ours. Create the object of the type's
            // class (if missing) and the devices row with the right link.
            $class = $types[$type]['CLASS'];
            if (function_exists('getObject') && !getObject($object)) {
                if (!function_exists('addClassObject')) {
                    $report[$key] = 'add_failed';
                    continue;
                }
                addClassObject($class, $object);
            }
            $rec = array('TYPE' => $type, 'TITLE' => $title, 'LINKED_OBJECT' => $object);
            $id = function_exists('SQLInsert') ? SQLInsert('devices', $rec) : 0;
            if ($id) {
                $created++;
                $report[$key] = 'created';
            } else {
                $report[$key] = 'add_failed';
            }
        }
        return array('ok' => true, 'created' => $created, 'report' => $report);
    }

    /**
     * Remove the simple devices we created (the `devices` rows linked to our
     * mapped objects). Used to re-create them after the object's property set
     * changed. The underlying objects are NOT deleted here (ensureObject will
     * refresh them); only the device links are removed so provision() can make
     * fresh ones. Returns array(ok, removed(int), report).
     */
    public static function remove($map)
    {
        if (!self::available()) {
            return array('ok' => false, 'reason' => 'devices_runtime_unavailable');
        }
        $report = array();
        $removed = 0;
        foreach ($map as $key => $d) {
            $object = isset($d['object']) ? (string) $d['object'] : '';
            if ($object === '') {
                $report[$key] = 'skipped_no_object';
                continue;
            }
            $dev = function_exists('SQLSelectOne')
                ? SQLSelectOne("SELECT ID FROM devices WHERE LINKED_OBJECT='" . self::sql($object) . "'")
                : null;
            if (is_array($dev) && !empty($dev['ID'])) {
                if (function_exists('SQLExec')) {
                    SQLExec("DELETE FROM devices WHERE ID=" . (int) $dev['ID']);
                    $removed++;
                    $report[$key] = 'removed';
                } else {
                    $report[$key] = 'remove_failed';
                }
            } else {
                $report[$key] = 'absent';
            }
        }
        return array('ok' => true, 'removed' => $removed, 'report' => $report);
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
    public static function publish($map, $snapshot, $decision, $manual = false)
    {
        if (!self::available()) {
            return;
        }
        // Thermostat.
        if (!empty($map['thermostat']['object'])) {
            $o = $map['thermostat']['object'];
            // relay_readonly: in AUTO the core thermostat must only display (1);
            // in MANUAL the user drives relay_status / currentTargetValue (0).
            self::sg($o . '.relay_readonly', $manual ? 0 : 1);
            // value = current temperature: always published (it is telemetry).
            if ($snapshot['temp_upper'] !== null) {
                self::sgChanged($o . '.value', $snapshot['temp_upper']);
            }
            // In MANUAL do NOT overwrite relay_status / currentTargetValue:
            // those are the user's command inputs (we READ them instead).
            if (!$manual) {
                if ($snapshot['heating'] !== null) {
                    self::sgChanged($o . '.relay_status', $snapshot['heating'] ? 1 : 0);
                }
                $target = null;
                if (isset($decision['target_c']) && $decision['target_c'] !== null) {
                    $target = $decision['target_c'];
                }
                if ($target !== null) {
                    self::sgChanged($o . '.currentTargetValue', $target);
                }
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
     * Read the MANUAL command from the Simple Device thermostat: the user's
     * relay_status (1 = heat, 0 = off) and currentTargetValue (°C). Used only
     * while automation is OFF. Returns array(relay(0|1|null), target(float|null)).
     */
    public static function readManualCommand($map)
    {
        $out = array('relay' => null, 'target' => null);
        if (!self::available() || empty($map['thermostat']['object'])) {
            return $out;
        }
        $o = $map['thermostat']['object'];
        $rs = self::gg($o . '.relay_status');
        if ($rs !== null && $rs !== '') {
            $out['relay'] = self::truthyNum($rs) ? 1 : 0;
        }
        $tv = self::gg($o . '.currentTargetValue');
        if ($tv !== null && $tv !== '' && is_numeric($tv)) {
            $t = (float) $tv;
            if ($t < 40.0) { $t = 40.0; }
            if ($t > 80.0) { $t = 80.0; }
            $out['target'] = $t;
        }
        return $out;
    }

    private static function truthyNum($v)
    {
        $s = strtolower(trim((string) $v));
        return in_array($s, array('1', 'on', 'true', 'yes'), true);
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
