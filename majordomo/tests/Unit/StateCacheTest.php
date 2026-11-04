<?php

use DraziceSmart\StateCache;

T::group('StateCache::staleAfter');
T::eq(StateCache::staleAfter(15), 60, 'interval 15 -> max(60,45)=60');
T::eq(StateCache::staleAfter(20), 60, 'interval 20 -> 60 (boundary)');
T::eq(StateCache::staleAfter(30), 90, 'interval 30 -> 90');
T::eq(StateCache::staleAfter(0), 60, 'interval 0 -> 60');
T::eq(StateCache::staleAfter(-5), 60, 'negative interval -> 60');

T::group('StateCache::ageSeconds');
T::eq(StateCache::ageSeconds(1000, 1030), 30, 'age 30');
T::eq(StateCache::ageSeconds(0, 1030), null, 'never fetched (0) -> null');
T::eq(StateCache::ageSeconds(null, 1030), null, 'never fetched (null) -> null');
T::eq(StateCache::ageSeconds(2000, 1000), 0, 'future timestamp -> 0, never negative');

T::group('StateCache::isStale boundaries');
$now = 10000;
T::eq(StateCache::isStale($now - 59, $now, 60, 1), false, '59 s -> not stale');
T::eq(StateCache::isStale($now - 60, $now, 60, 1), false, '60 s -> not stale (stale only when > staleAfter)');
T::eq(StateCache::isStale($now - 61, $now, 60, 1), true, '61 s -> stale');
T::eq(StateCache::isStale($now - 5, $now, 60, 0), true, 'last attempt failed -> stale even if young');
T::eq(StateCache::isStale(0, $now, 60, 1), true, 'never fetched -> stale');
T::eq(StateCache::isStale(null, $now, 60, 1), true, 'null fetched -> stale');
T::eq(StateCache::isStale($now + 500, $now, 60, 1), false, 'future timestamp -> not stale');

T::group('StateCache::isFresh (coalescing window)');
T::eq(StateCache::isFresh($now - 4, $now, 5), true, '4 s fresh');
T::eq(StateCache::isFresh($now - 5, $now, 5), true, '5 s fresh (boundary)');
T::eq(StateCache::isFresh($now - 6, $now, 5), false, '6 s not fresh');
T::eq(StateCache::isFresh(0, $now, 5), false, 'never fetched not fresh');
T::eq(StateCache::isFresh($now + 100, $now, 5), true, 'future timestamp counts as age 0');

T::group('StateCache::meta');
$m0 = StateCache::meta(null, $now, 60);
T::eq($m0['has_data'], false, 'no row: has_data=false');
T::eq($m0['stale'], true, 'no row: stale');
T::eq($m0['fetch_ok'], null, 'no row: fetch_ok=null (never tried)');
$row = array('FETCHED_UTC' => $now - 10, 'ATTEMPT_UTC' => $now - 10, 'LAST_OK' => 1, 'SOURCE' => 'state', 'DIRTY' => 0, 'PAYLOAD' => '{"sensor":{}}');
$m1 = StateCache::meta($row, $now, 60);
T::eq($m1['has_data'], true, 'ok row: has_data');
T::eq($m1['age_s'], 10, 'ok row: age_s=10');
T::eq($m1['stale'], false, 'ok row: not stale');
T::eq($m1['fetch_ok'], true, 'ok row: fetch_ok');
T::eq($m1['source'], 'state', 'ok row: source');
$rowFail = $row;
$rowFail['ATTEMPT_UTC'] = $now - 1;
$rowFail['LAST_OK'] = 0;
$m2 = StateCache::meta($rowFail, $now, 60);
T::eq($m2['fetch_ok'], false, 'failed attempt: fetch_ok=false');
T::eq($m2['stale'], true, 'failed attempt: stale');
T::eq($m2['has_data'], true, 'failed attempt keeps old data (has_data)');
T::eq($m2['age_s'], 10, 'failed attempt keeps the OLD fetched time');
$mNever = StateCache::meta(array('FETCHED_UTC' => 0, 'ATTEMPT_UTC' => $now, 'LAST_OK' => 0, 'SOURCE' => '', 'PAYLOAD' => ''), $now, 60);
T::eq($mNever['has_data'], false, 'only failures so far: no data');
T::eq($mNever['fetch_ok'], false, 'only failures so far: fetch_ok=false');

T::group('StateCache encode/decode/redact');
$map = array('sensor' => array('A' => array('value' => 1.5, 'state' => '1.5')),
    'text_sensor' => array('T' => array('value' => "q\"\\|@Ж", 'state' => "q\"\\|@Ж")),
    'number' => array('Boiler Pairing PIN' => array('value' => 692, 'state' => '692'),
        'Boiler Requested Target' => array('value' => 55.0, 'state' => '55')));
$rt = StateCache::decode(StateCache::encode($map));
T::eq($rt['text_sensor']['T']['state'], "q\"\\|@Ж", 'round trip keeps text');
T::eq($rt['sensor']['A']['value'], 1.5, 'round trip keeps number');
T::eq(StateCache::decode('not json'), array(), 'garbage -> empty array');
T::eq(StateCache::decode(''), array(), 'empty -> empty array');
T::eq(StateCache::decode(null), array(), 'null -> empty array');
T::eq(StateCache::decode('"str"'), array(), 'scalar JSON -> empty array');
$red = StateCache::redact($map);
T::eq(isset($red['number']['Boiler Pairing PIN']), false, 'redact drops the PIN entity');
T::eq(isset($red['number']['Boiler Requested Target']), true, 'redact keeps other numbers');
T::eq(strpos(StateCache::encode($red), '692'), false, 'no PIN digits in the encoded redacted payload');
T::eq(StateCache::redact('x'), array(), 'redact(non-array) -> empty');
