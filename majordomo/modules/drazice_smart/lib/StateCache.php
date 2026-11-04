<?php
/**
 * StateCache: one-row DB snapshot of the ESP entity states (table
 * drazice_state_cache), so admin()/usual() render WITHOUT touching the ESP and
 * the browser polls the cache through AJAX (the ESP serves one client at a time).
 *
 * Pure static helpers (staleAfter/isStale/ageSeconds/isFresh/meta/encode/decode/
 * redact) are unit-tested; the DB layer is guarded with function_exists like
 * Repository.php and is exercised by the module-level tests with an in-memory
 * SQL stub.
 *
 * The payload holds ONLY ESP entity states (no credentials; the write-only
 * pairing-PIN entity is redacted). DB-only, never in git.
 *
 * Failure keeps the OLD payload and FETCHED_UTC: the UI shows old data with a
 * "stale" badge and "last fetch failed".
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class StateCache
{
    const TABLE = 'drazice_state_cache';
    /** Reads younger than this are served from the cache (request coalescing). */
    const FRESH_S = 5;
    const MIN_STALE_S = 60;

    // ---- pure helpers -------------------------------------------------------

    /** Age after which cached data is "stale": max(60 s, 3 x poll interval). */
    public static function staleAfter($pollIntervalS)
    {
        $p = (int) $pollIntervalS;
        return max(self::MIN_STALE_S, 3 * max(0, $p));
    }

    /** Seconds since the last successful fetch; null if never fetched; never negative. */
    public static function ageSeconds($fetchedUtc, $now)
    {
        $f = (int) $fetchedUtc;
        if ($f <= 0) {
            return null;
        }
        return max(0, (int) $now - $f);
    }

    /** Stale: never fetched, older than $staleAfter, or the last attempt failed. */
    public static function isStale($fetchedUtc, $now, $staleAfter, $lastOk)
    {
        $age = self::ageSeconds($fetchedUtc, $now);
        if ($age === null) {
            return true;
        }
        if (!$lastOk) {
            return true;
        }
        return $age > (int) $staleAfter;
    }

    /** Fresh enough to skip contacting the ESP (coalescing window). */
    public static function isFresh($fetchedUtc, $now, $window)
    {
        $age = self::ageSeconds($fetchedUtc, $now);
        return $age !== null && $age <= (int) $window;
    }

    /**
     * Cache metadata for the UI/JSON.
     * @param array|null $row DB row (or null)
     * @return array has_data, fetched_utc, age_s, stale, fetch_ok (null = never tried), source
     */
    public static function meta($row, $now, $staleAfter)
    {
        if (!is_array($row) || empty($row)) {
            return array('has_data' => false, 'fetched_utc' => null, 'age_s' => null,
                'stale' => true, 'fetch_ok' => null, 'source' => null);
        }
        $fetched = isset($row['FETCHED_UTC']) ? (int) $row['FETCHED_UTC'] : 0;
        $lastOk = !empty($row['LAST_OK']);
        $hasPayload = isset($row['PAYLOAD']) && $row['PAYLOAD'] !== '' && $fetched > 0;
        $attempted = isset($row['ATTEMPT_UTC']) && (int) $row['ATTEMPT_UTC'] > 0;
        return array(
            'has_data'    => $hasPayload,
            'fetched_utc' => $fetched > 0 ? $fetched : null,
            'age_s'       => self::ageSeconds($fetched, $now),
            'stale'       => self::isStale($fetched, $now, $staleAfter, $lastOk),
            'fetch_ok'    => $attempted ? $lastOk : null,
            'source'      => (isset($row['SOURCE']) && $row['SOURCE'] !== '') ? (string) $row['SOURCE'] : null,
        );
    }

    public static function encode($map)
    {
        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) ? $json : '{}';
    }

    /** Decode a stored payload; garbage -> empty array. */
    public static function decode($payload)
    {
        if (!is_string($payload) || $payload === '') {
            return array();
        }
        $d = json_decode($payload, true);
        return is_array($d) ? $d : array();
    }

    /** Remove write-only/sensitive entities (Transport::SENSITIVE) from a map. */
    public static function redact($map)
    {
        if (!is_array($map)) {
            return array();
        }
        foreach (Transport::SENSITIVE as $domain => $names) {
            if (!isset($map[$domain]) || !is_array($map[$domain])) {
                continue;
            }
            foreach ($names as $n) {
                unset($map[$domain][$n]);
            }
        }
        return $map;
    }

    // ---- DB layer (single row) ---------------------------------------------

    /** The cache row or null. */
    public static function load()
    {
        if (!function_exists('SQLSelectOne')) {
            return null;
        }
        $row = SQLSelectOne('SELECT * FROM ' . self::TABLE . ' ORDER BY ID ASC LIMIT 1');
        return (is_array($row) && !empty($row)) ? $row : null;
    }

    public static function saveSuccess($map, $source, $now)
    {
        $row = self::load();
        $isNew = ($row === null);
        if ($isNew) {
            $row = array();
        }
        $row['FETCHED_UTC'] = (int) $now;
        $row['ATTEMPT_UTC'] = (int) $now;
        $row['LAST_OK'] = 1;
        $row['SOURCE'] = substr((string) $source, 0, 8);
        $row['DIRTY'] = 0;
        $row['PAYLOAD'] = self::encode(self::redact($map));
        self::write($row, $isNew);
    }

    /** Failed attempt: keep the OLD payload and FETCHED_UTC. */
    public static function saveFailure($now)
    {
        $row = self::load();
        $isNew = ($row === null);
        if ($isNew) {
            $row = array('FETCHED_UTC' => 0, 'SOURCE' => '', 'DIRTY' => 0, 'PAYLOAD' => '');
        }
        $row['ATTEMPT_UTC'] = (int) $now;
        $row['LAST_OK'] = 0;
        self::write($row, $isNew);
    }

    /** A write op happened: the next read must refetch regardless of freshness. */
    public static function markDirty()
    {
        // Touch ONLY the DIRTY column: a load-modify-write of the whole row could
        // overwrite a concurrent saveSuccess() payload with an older one.
        $row = self::load();
        if ($row === null || !isset($row['ID'])) {
            return;
        }
        // MajorDoMo SQLUpdate() writes only the fields present in the record.
        if (function_exists('SQLUpdate')) {
            SQLUpdate(self::TABLE, array('ID' => (int) $row['ID'], 'DIRTY' => 1));
        }
    }

    private static function write($row, $isNew)
    {
        if ($isNew) {
            if (function_exists('SQLInsert')) {
                SQLInsert(self::TABLE, $row);
            }
        } elseif (function_exists('SQLUpdate')) {
            SQLUpdate(self::TABLE, $row);
        }
    }
}
