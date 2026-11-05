<?php

use DraziceSmart\ClockCheck;

T::group('ClockCheck: parse boiler time');

$p = ClockCheck::parseBoilerTime('Mon 14:03:27');
T::eq(is_array($p), true, 'parse "Mon 14:03:27" -> array');
T::eq($p['dow'], 0, 'Mon -> dow 0');
T::eq($p['sod'], 14 * 3600 + 3 * 60 + 27, 'sod computed');

$p = ClockCheck::parseBoilerTime('sun 00:00:00');
T::eq($p['dow'], 6, 'sun (lowercase) -> dow 6');
T::eq($p['sod'], 0, 'midnight -> sod 0');

$p = ClockCheck::parseBoilerTime('09:30:00'); // no day
T::eq($p['dow'], null, 'bare time -> dow null');
T::eq($p['sod'], 9 * 3600 + 30 * 60, 'bare time sod');

T::eq(ClockCheck::parseBoilerTime(''), null, 'empty -> null');
T::eq(ClockCheck::parseBoilerTime('garbage'), null, 'garbage -> null');
T::eq(ClockCheck::parseBoilerTime('Mon 99:00:00'), null, 'out-of-range hour -> null');
T::eq(ClockCheck::parseBoilerTime(null), null, 'null input -> null');

T::group('ClockCheck: skew on the weekly circle (UTC, DST-free)');

// 2026-11-02 is a Monday. 14:03:27 UTC.
$monUtc = gmmktime(14, 3, 27, 11, 2, 2026);
T::eq((int) gmdate('N', $monUtc), 1, 'sanity: 2026-11-02 is Monday');

// Boiler exactly matches the server.
$r = ClockCheck::evaluate('Mon 14:03:27', $monUtc, 'UTC', 120);
T::eq($r['skew_s'], 0, 'identical -> 0 skew');
T::eq($r['ok'], true, 'identical -> ok');
T::eq($r['has_dow'], true, 'day reported -> has_dow');

// Boiler 90 s ahead -> within 120 threshold.
$r = ClockCheck::evaluate('Mon 14:04:57', $monUtc, 'UTC', 120);
T::eq($r['skew_s'], 90, 'boiler +90s -> +90');
T::eq($r['ok'], true, '90s within 120 -> ok');

// Boiler 200 s behind -> exceeds 120.
$r = ClockCheck::evaluate('Mon 14:00:07', $monUtc, 'UTC', 120);
T::eq($r['skew_s'], -200, 'boiler -200s -> -200');
T::eq($r['ok'], false, '200s exceeds 120 -> not ok');
T::eq($r['abs_s'], 200, 'abs skew 200');

// Midnight/week wrap: server Mon 00:00:30, boiler Sun 23:59:30 -> boiler 60s behind.
$monMidnight = gmmktime(0, 0, 30, 11, 2, 2026);
$r = ClockCheck::evaluate('Sun 23:59:30', $monMidnight, 'UTC', 120);
T::eq($r['skew_s'], -60, 'week wrap: Sun 23:59:30 vs Mon 00:00:30 -> -60');
T::eq($r['ok'], true, 'week wrap 60s -> ok');

// Wrong day entirely (server Mon, boiler Wed ~same time) -> 2 days, huge skew.
$r = ClockCheck::evaluate('Wed 14:03:27', $monUtc, 'UTC', 120);
T::eq($r['skew_s'], 2 * 86400, 'Wed vs Mon same tod -> +2 days');
T::eq($r['ok'], false, 'two-day gap -> not ok');

// Threshold boundary: exactly at threshold is ok.
$r = ClockCheck::evaluate('Mon 14:05:27', $monUtc, 'UTC', 120);
T::eq($r['abs_s'], 120, 'exactly 120 skew');
T::eq($r['ok'], true, 'skew == threshold -> ok (<=)');

// Unparseable boiler time -> null (caller leaves state untouched).
T::eq(ClockCheck::evaluate('', $monUtc, 'UTC', 120), null, 'empty boiler time -> null');
T::eq(ClockCheck::evaluate('nope', $monUtc, 'UTC', 120), null, 'bad boiler time -> null');

// Time-only comparison uses a 24h circle.
$r = ClockCheck::evaluate('14:04:00', $monUtc, 'UTC', 120);
T::eq($r['has_dow'], false, 'bare time -> has_dow false');
T::eq($r['skew_s'], 33, 'bare time skew 14:04:00 vs 14:03:27 = +33');
