<?php

use DraziceSmart\ForecastAdapter;

T::group('ForecastAdapter');

$now = 1000000;

// A fake property reader backed by an array.
function makeReader($data)
{
    return function ($prop) use ($data) {
        return isset($data[$prop]) ? $data[$prop] : null;
    };
}

// No bindings -> unavailable, not zero.
$fa = new ForecastAdapter(makeReader(array()), array());
$s = $fa->snapshot($now);
T::eq($s['available'], false, 'no bindings -> unavailable');
T::eq($s['today_remaining_kwh'], null, 'no bindings -> null (not 0)');

// Bound but value absent -> unavailable, null (not 0).
$fa = new ForecastAdapter(makeReader(array()), array('today_remaining_kwh' => 'SolarForecast.TodayRemainingKwh'));
$s = $fa->snapshot($now);
T::eq($s['available'], false, 'bound but empty -> unavailable');
T::eq($s['today_remaining_kwh'], null, 'bound but empty -> null (not 0)');

// Bound with a fresh value.
$data = array(
    'SolarForecast.TodayRemainingKwh' => '12.5',
    'SolarForecast.TomorrowKwh' => '30.0',
    'SolarForecast.Provider' => 'open-meteo',
    'SolarForecast.UpdatedAt' => $now - 100,
);
$fa = new ForecastAdapter(makeReader($data), array(
    'today_remaining_kwh' => 'SolarForecast.TodayRemainingKwh',
    'tomorrow_kwh' => 'SolarForecast.TomorrowKwh',
    'provider' => 'SolarForecast.Provider',
    'updated_at_utc' => 'SolarForecast.UpdatedAt',
));
$s = $fa->snapshot($now, 21600);
T::eq($s['available'], true, 'bound + value -> available');
T::approx($s['today_remaining_kwh'], 12.5, 0.001, 'today = 12.5');
T::approx($s['tomorrow_kwh'], 30.0, 0.001, 'tomorrow = 30.0');
T::eq($s['provider'], 'open-meteo', 'provider carried');
T::eq($s['quality'], 'ok', 'fresh -> ok');

// Stale forecast -> degraded quality.
$dataStale = $data; $dataStale['SolarForecast.UpdatedAt'] = $now - 100000;
$fa = new ForecastAdapter(makeReader($dataStale), array(
    'today_remaining_kwh' => 'SolarForecast.TodayRemainingKwh',
    'updated_at_utc' => 'SolarForecast.UpdatedAt',
));
$s = $fa->snapshot($now, 21600);
T::eq($s['quality'], 'stale', 'old updated_at -> stale');
