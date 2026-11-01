<?php
/**
 * ForecastAdapter: binds the boiler module to a PV forecast source WITHOUT
 * assuming the solarPlanner module is installed. If bindings are missing the
 * result is 'unavailable' — never a silent 0 kWh.
 *
 * Default bindings target the sibling solarPlanner module's mirror object
 * (SolarPlanner.*), but any namespace/property is configurable by the admin.
 *
 * This class takes an injected property reader so it stays pure-ish and
 * testable (no direct getGlobal() dependency inside unit tests).
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class ForecastAdapter
{
    /** @var callable  function(string $property): mixed  (null if absent) */
    private $reader;
    /** @var array */
    private $bindings;

    /**
     * @param callable $reader   reads a MajorDoMo property by "Object.Property"
     * @param array    $bindings keys: today_remaining_kwh, tomorrow_kwh,
     *                           updated_at_utc, provider, available
     */
    public function __construct($reader, $bindings)
    {
        $this->reader = $reader;
        $this->bindings = is_array($bindings) ? $bindings : array();
    }

    /**
     * Returns a normalised forecast snapshot. Missing = null + available=false.
     */
    public function snapshot($nowUtc, $freshnessTtlS = 21600)
    {
        $out = array(
            'available'           => false,
            'today_remaining_kwh' => null,
            'tomorrow_kwh'        => null,
            'provider'            => null,
            'updated_at_utc'      => null,
            'quality'             => 'unavailable',
            'human'               => 'Прогноз СЕС не прив\'язано або недоступний.',
        );

        if (empty($this->bindings) || empty($this->bindings['today_remaining_kwh'])) {
            return $out;
        }

        $today = $this->readNumeric($this->bindings['today_remaining_kwh']);
        $tomorrow = isset($this->bindings['tomorrow_kwh'])
            ? $this->readNumeric($this->bindings['tomorrow_kwh']) : null;
        $provider = isset($this->bindings['provider'])
            ? $this->readRaw($this->bindings['provider']) : null;
        $updated = isset($this->bindings['updated_at_utc'])
            ? $this->readNumeric($this->bindings['updated_at_utc']) : null;

        if ($today === null) {
            $out['human'] = 'Властивість прогнозу прив\'язана, але значення відсутнє (не підмінюємо нулем).';
            return $out;
        }

        // Freshness: do not trust a stale forecast; mark degraded.
        $quality = 'ok';
        if ($updated !== null && $freshnessTtlS > 0 && ($nowUtc - (int) $updated) > $freshnessTtlS) {
            $quality = 'stale';
        }

        $out['available'] = true;
        $out['today_remaining_kwh'] = $today;
        $out['tomorrow_kwh'] = $tomorrow;
        $out['provider'] = $provider !== null ? (string) $provider : null;
        $out['updated_at_utc'] = $updated !== null ? (int) $updated : null;
        $out['quality'] = $quality;
        $out['human'] = $quality === 'stale'
            ? 'Прогноз СЕС застарів — використовуємо обережно.'
            : 'Прогноз СЕС доступний.';
        return $out;
    }

    private function readNumeric($property)
    {
        $v = $this->readRaw($property);
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        if (is_nan($f) || is_infinite($f)) {
            return null;
        }
        return $f;
    }

    private function readRaw($property)
    {
        $fn = $this->reader;
        if (!is_callable($fn)) {
            return null;
        }
        return call_user_func($fn, $property);
    }
}
