<?php
/**
 * Repository: thin persistence + MajorDoMo integration layer.
 *
 * Isolates all SQL and getGlobal/setProperty calls so the Planner/Policy stay
 * pure and unit-testable. Uses MajorDoMo helpers (SQLInsert, SQLSelectOne,
 * getGlobal) which exist at runtime; in tests this class is NOT loaded.
 *
 * PHP 7.3 compatible. Timestamps stored as UTC unix ints.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class Repository
{
    /** Read a MajorDoMo property "Object.Property" safely. Null if absent. */
    public static function readProperty($property)
    {
        if ($property === null || $property === '') {
            return null;
        }
        if (!function_exists('getGlobal')) {
            return null;
        }
        $v = getGlobal($property);
        if ($v === '' || $v === false) {
            return null;
        }
        return $v;
    }

    /** When was a property last updated (UTC unix) or null if unknown. */
    public static function propertyUpdatedUtc($object, $property)
    {
        if (!function_exists('SQLSelectOne')) {
            return null;
        }
        $object = addslashes($object);
        $property = addslashes($property);
        $rec = SQLSelectOne("SELECT pv.UPDATED FROM pvalues pv
            LEFT JOIN objects o ON o.ID = pv.OBJECT_ID
            LEFT JOIN properties p ON p.ID = pv.PROPERTY_ID
            WHERE o.TITLE = '" . $object . "' AND p.TITLE = '" . $property . "'
            LIMIT 1");
        if (!$rec || empty($rec['UPDATED'])) {
            return null;
        }
        $ts = strtotime($rec['UPDATED']);
        return $ts ? $ts : null;
    }

    /** Append a telemetry sample. */
    public static function saveTelemetry($row)
    {
        if (!function_exists('SQLInsert')) {
            return;
        }
        $rec = array(
            'SAMPLE_UTC'       => isset($row['sample_utc']) ? (int) $row['sample_utc'] : time(),
            'TEMP_LOWER'       => self::nn($row, 'temp_lower'),
            'TEMP_UPPER'       => self::nn($row, 'temp_upper'),
            'HEATING'          => self::nb($row, 'heating'),
            'HDO'              => self::nb($row, 'hdo'),
            'MODE_REPORTED'    => isset($row['mode_reported']) ? (string) $row['mode_reported'] : '',
            'ENERGY_TOTAL_KWH' => self::nn($row, 'energy_total_kwh'),
            'POWER_SOURCE'     => isset($row['power_source']) ? (string) $row['power_source'] : 'unknown',
            'BATTERY_SOC'      => self::nn($row, 'battery_soc'),
            'HTTP_OK'          => self::nb($row, 'http_available'),
            'BLE_STATE'        => isset($row['ble_state']) ? (string) $row['ble_state'] : '',
        );
        SQLInsert('drazice_telemetry', $rec);
    }

    /** Append a planner decision. */
    public static function saveDecision($decision)
    {
        if (!function_exists('SQLInsert')) {
            return;
        }
        $rec = array(
            'DECISION_UTC'    => isset($decision['now_utc']) ? (int) $decision['now_utc'] : time(),
            'ACTION'          => isset($decision['action']) ? (string) $decision['action'] : '',
            'TARGET_C'        => self::nn($decision, 'target_c'),
            'MODE'            => isset($decision['mode']) ? (string) $decision['mode'] : '',
            'REASON'          => isset($decision['reason']) ? (string) $decision['reason'] : '',
            'REASON_HUMAN'    => isset($decision['reason_human']) ? (string) $decision['reason_human'] : '',
            'PRIORITY_LEVEL'  => isset($decision['priority_level']) ? (int) $decision['priority_level'] : 0,
            'ALLOWED'         => !empty($decision['allowed']) ? 1 : 0,
            'ALGO_VERSION'    => isset($decision['algo_version']) ? (string) $decision['algo_version'] : '',
            'DRY_RUN'         => !empty($decision['dry_run']) ? 1 : 0,
        );
        SQLInsert('drazice_decisions', $rec);
    }

    /** Append a command audit row. */
    public static function saveCommand($row)
    {
        if (!function_exists('SQLInsert')) {
            return;
        }
        $rec = array(
            'TS_UTC'      => isset($row['ts_utc']) ? (int) $row['ts_utc'] : time(),
            'COMMAND_ID'  => isset($row['command_id']) ? (string) $row['command_id'] : '',
            'KIND'        => isset($row['kind']) ? (string) $row['kind'] : '',
            'PAYLOAD'     => isset($row['payload']) ? (string) $row['payload'] : '',
            'STATUS'      => isset($row['status']) ? (string) $row['status'] : '',
            'DETAIL'      => isset($row['detail']) ? (string) $row['detail'] : '',
        );
        SQLInsert('drazice_commands', $rec);
    }

    private static function nn($row, $key)
    {
        return (isset($row[$key]) && $row[$key] !== null && is_numeric($row[$key])) ? (float) $row[$key] : null;
    }

    private static function nb($row, $key)
    {
        if (!isset($row[$key]) || $row[$key] === null) {
            return null;
        }
        return !empty($row[$key]) ? 1 : 0;
    }
}
