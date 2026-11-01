<?php

use DraziceSmart\Tariff;

T::group('Tariff');

// Base: cheap window 23:00..07:00 Europe/Kyiv, no offset, no guards.
$cfg = array('timezone' => 'Europe/Kyiv', 'cheap_start_hour' => 23, 'cheap_end_hour' => 7,
    'meter_offset_seconds' => 0, 'start_guard_seconds' => 0, 'end_guard_seconds' => 0);

// Helper: make a UTC timestamp for a given Kyiv local time on a fixed date.
function kyivUtc($localStr)
{
    $dt = new DateTime($localStr, new DateTimeZone('Europe/Kyiv'));
    return $dt->getTimestamp();
}

// Winter date (standard time, UTC+2): 2026-01-15
T::eq(Tariff::evaluate(kyivUtc('2026-01-15 22:59:59'), $cfg)['active'], false, '22:59:59 winter -> day');
T::eq(Tariff::evaluate(kyivUtc('2026-01-15 23:00:00'), $cfg)['active'], true,  '23:00:00 winter -> cheap');
T::eq(Tariff::evaluate(kyivUtc('2026-01-15 06:59:59'), $cfg)['active'], true,  '06:59:59 winter -> cheap');
T::eq(Tariff::evaluate(kyivUtc('2026-01-15 07:00:00'), $cfg)['active'], false, '07:00:00 winter -> day');
T::eq(Tariff::evaluate(kyivUtc('2026-01-15 03:00:00'), $cfg)['active'], true,  '03:00 after midnight -> cheap');

// Summer date (DST, UTC+3): 2026-07-15 — must still map correctly, NOT a fixed 8h.
T::eq(Tariff::evaluate(kyivUtc('2026-07-15 23:00:00'), $cfg)['active'], true,  '23:00 summer -> cheap (DST aware)');
T::eq(Tariff::evaluate(kyivUtc('2026-07-15 06:59:59'), $cfg)['active'], true,  '06:59 summer -> cheap (DST aware)');
T::eq(Tariff::evaluate(kyivUtc('2026-07-15 07:00:00'), $cfg)['active'], false, '07:00 summer -> day (DST aware)');

// Meter offset +120s (meter is fast): cheap time starts 2 min earlier by server.
$cfgOff = $cfg; $cfgOff['meter_offset_seconds'] = 120;
T::eq(Tariff::evaluate(kyivUtc('2026-01-15 22:58:00'), $cfgOff)['active'], true,  'offset +120: 22:58 server -> cheap');
T::eq(Tariff::evaluate(kyivUtc('2026-01-15 22:57:59'), $cfgOff)['active'], false, 'offset +120: 22:57:59 server -> day');

// Guards: start_guard 120s delays cheap start by 2 min of meter time.
$cfgGuard = $cfg; $cfgGuard['start_guard_seconds'] = 120; $cfgGuard['end_guard_seconds'] = 120;
T::eq(Tariff::evaluate(kyivUtc('2026-01-15 23:00:30'), $cfgGuard)['active'], false, 'start_guard: 23:00:30 still blocked');
T::eq(Tariff::evaluate(kyivUtc('2026-01-15 23:02:30'), $cfgGuard)['active'], true,  'start_guard: 23:02:30 allowed');
T::eq(Tariff::evaluate(kyivUtc('2026-01-15 06:58:30'), $cfgGuard)['active'], false, 'end_guard: 06:58:30 already blocked');

// Bad timezone -> not active, explicit reason.
$bad = Tariff::evaluate(time(), array('timezone' => 'Not/AZone'));
T::eq($bad['active'], false, 'bad timezone -> inactive');
T::eq($bad['reason'], 'bad_timezone', 'bad timezone -> reason');
