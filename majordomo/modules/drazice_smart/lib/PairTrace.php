<?php
/**
 * PairTrace: pure PHP helpers for the firmware pairing-diagnostics entities
 * (Boiler Pair Trace / Boiler Last Disconnect / connect-count comparison).
 *
 * The firmware NEVER puts PIN digits into these strings (see
 * firmware/components/smartboiler/UPSTREAM.md); this class only formats and
 * compares what the firmware already sends.
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class PairTrace
{
    /**
     * Split the compact "EVENT@ms | EVENT@ms | ..." ring string from
     * "Boiler Pair Trace" into a list of events, oldest first.
     * Returns array of array('raw' => 'REQ_PIN@12034', 'tag' => 'REQ_PIN', 'ms' => 12034).
     * Unparseable or empty input -> empty array (never throws).
     *
     * @param string|null $raw
     * @return array
     */
    public static function parse($raw)
    {
        $out = array();
        if (!is_string($raw) || $raw === '' || $raw === '-') {
            return $out;
        }
        $parts = explode('|', $raw);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $at = strrpos($p, '@');
            if ($at === false) {
                $out[] = array('raw' => $p, 'tag' => $p, 'ms' => null);
                continue;
            }
            $tag = substr($p, 0, $at);
            $msStr = substr($p, $at + 1);
            $ms = (preg_match('/^\d+$/', $msStr)) ? (int) $msStr : null;
            $out[] = array('raw' => $p, 'tag' => $tag, 'ms' => $ms);
        }
        return $out;
    }

    /**
     * Last $limit events (newest first) as plain display strings, for a
     * compact inline summary (e.g. after a PIN submit).
     *
     * @param string|null $raw
     * @param int $limit
     * @return string[]
     */
    public static function lastEvents($raw, $limit = 3)
    {
        $events = self::parse($raw);
        $events = array_reverse($events);
        $out = array();
        foreach ($events as $e) {
            $out[] = $e['raw'];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Parse "Boiler Last Disconnect" ("reason=8 state=NEED_PIN uptime_ms=16400
     * since_connect_ms=400 since_pin_tx_ms=100") into a key=>value map.
     * Missing/unparseable -> empty array.
     *
     * @param string|null $raw
     * @return array
     */
    public static function parseDisconnect($raw)
    {
        $out = array();
        if (!is_string($raw) || $raw === '' || $raw === '-') {
            return $out;
        }
        foreach (preg_split('/\s+/', trim($raw)) as $kv) {
            $eq = strpos($kv, '=');
            if ($eq === false) {
                continue;
            }
            $out[substr($kv, 0, $eq)] = substr($kv, $eq + 1);
        }
        return $out;
    }

    /**
     * Compare the connect-count rendered into the PIN form (hidden field,
     * captured when the page was built) against the connect-count read back
     * right now. If the ESP already rebuilt the BLE link (new PIN shown on
     * the boiler display), the PIN the user just typed is stale.
     *
     * Both null/non-numeric -> not stale (nothing to compare against).
     *
     * @param mixed $renderedCount
     * @param mixed $currentCount
     * @return bool
     */
    public static function isStale($renderedCount, $currentCount)
    {
        if (!is_numeric($renderedCount) || !is_numeric($currentCount)) {
            return false;
        }
        return ((int) $currentCount) !== ((int) $renderedCount);
    }
}
