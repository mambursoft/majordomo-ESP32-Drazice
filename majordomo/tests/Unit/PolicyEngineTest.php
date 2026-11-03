<?php

use DraziceSmart\PolicyEngine;

T::group('PolicyEngine power source');

$now = 1000000;

// activeValue '1' => raw '1' means battery, raw '0' means grid.
$r = PolicyEngine::powerSource('1', '1', null, $now, 0);
T::eq($r['source'], 'battery', "raw '1' == active '1' -> battery");
$r = PolicyEngine::powerSource('0', '1', null, $now, 0);
T::eq($r['source'], 'grid', "raw '0' != active '1' -> grid");

// Reversed polarity: activeValue '0'.
$r = PolicyEngine::powerSource(0, '0', null, $now, 0);
T::eq($r['source'], 'battery', "raw 0 == active '0' -> battery (reversed polarity)");
$r = PolicyEngine::powerSource(1, '0', null, $now, 0);
T::eq($r['source'], 'grid', "raw 1 != active '0' -> grid");

// Boolean + textual normalisation.
$r = PolicyEngine::powerSource(true, '1', null, $now, 0);
T::eq($r['source'], 'battery', 'bool true normalises to 1');
$r = PolicyEngine::powerSource('on', '1', null, $now, 0);
T::eq($r['source'], 'battery', "'on' normalises to 1");
$r = PolicyEngine::powerSource('false', '0', null, $now, 0);
T::eq($r['source'], 'battery', "'false' normalises to 0 == active '0'");

// Null / empty -> unknown (never grid).
T::eq(PolicyEngine::powerSource(null, '1', null, $now, 0)['source'], 'unknown', 'null -> unknown');
T::eq(PolicyEngine::powerSource('', '1', null, $now, 0)['source'], 'unknown', 'empty -> unknown');

// Polarity not confirmed (activeValue empty) -> unknown.
T::eq(PolicyEngine::powerSource('1', '', null, $now, 0)['source'], 'unknown', 'no polarity -> unknown');

// Freshness: stale producer -> unknown (only when TTL>0 and timestamp present).
$r = PolicyEngine::powerSource('1', '1', $now - 1000, $now, 300);
T::eq($r['source'], 'unknown', 'stale beyond TTL -> unknown');
$r = PolicyEngine::powerSource('1', '1', $now - 100, $now, 300);
T::eq($r['source'], 'battery', 'fresh within TTL -> battery');
// TTL 0 means not enforced even if old timestamp.
$r = PolicyEngine::powerSource('1', '1', $now - 100000, $now, 0);
T::eq($r['source'], 'battery', 'TTL 0 -> freshness not enforced');

T::group('PolicyEngine SOC validation');
T::eq(PolicyEngine::validSoc('85'), 85.0, "'85' -> 85.0");
T::eq(PolicyEngine::validSoc(100), 100.0, '100 -> 100.0');
T::eq(PolicyEngine::validSoc(null), null, 'null -> null');
T::eq(PolicyEngine::validSoc(''), null, 'empty -> null');
T::eq(PolicyEngine::validSoc('abc'), null, 'non-numeric -> null');
T::eq(PolicyEngine::validSoc(-5), null, 'negative -> null');
T::eq(PolicyEngine::validSoc(150), null, 'over 100 -> null');

T::group('PolicyEngine PIN validation');

$p = PolicyEngine::validatePin('0692');
T::ok($p['ok'], "'0692' -> ok");
T::eq($p['display'], '0692', "'0692' display preserves leading zero");
T::eq($p['value'], 692, "'0692' value drops leading zero -> 692");

$p = PolicyEngine::validatePin('692');
T::ok($p['ok'], "'692' -> ok");
T::eq($p['value'], 692, "'692' -> value 692");

$p = PolicyEngine::validatePin('1111');
T::ok($p['ok'], "'1111' -> ok");
T::eq($p['value'], 1111, "'1111' -> value 1111");

$p = PolicyEngine::validatePin('9999');
T::ok($p['ok'], "'9999' -> ok");
T::eq($p['value'], 9999, "'9999' -> value 9999");

$p = PolicyEngine::validatePin('0');
T::ok($p['ok'], "'0' -> ok");
T::eq($p['value'], 0, "'0' -> value 0 (allowed by new floor)");

T::eq(PolicyEngine::validatePin('')['ok'], false, "'' -> bad_pin");
T::eq(PolicyEngine::validatePin('')['reason'], 'bad_pin', "'' -> reason bad_pin");
T::eq(PolicyEngine::validatePin('abc')['ok'], false, "'abc' -> bad_pin");
T::eq(PolicyEngine::validatePin('12345')['ok'], false, "5 digits -> bad_pin");

$p = PolicyEngine::validatePin(' 42 ');
T::ok($p['ok'], "' 42 ' -> ok (trimmed)");
T::eq($p['value'], 42, "' 42 ' -> value 42");
