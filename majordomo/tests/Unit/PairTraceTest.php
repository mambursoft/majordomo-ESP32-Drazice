<?php

use DraziceSmart\PairTrace;
use DraziceSmart\SettingsSaver;

T::group('PairTrace::parse');

$raw = 'CONN#2@12000 | AUTH_TX@12150 | REQ_PIN@12400 | PIN_RX(3d)@15210 | PIN_Q@15211 | PIN_TX@15300 | RES=0@15900 | DISC(r=8,NP)@16400';
$ev = PairTrace::parse($raw);
T::eq(count($ev), 8, 'eight events parsed');
T::eq($ev[0]['tag'], 'CONN#2', 'first tag');
T::eq($ev[0]['ms'], 12000, 'first ms');
T::eq($ev[3]['tag'], 'PIN_RX(3d)', 'tag with parentheses kept');
T::eq($ev[3]['ms'], 15210, 'PIN_RX ms');
T::eq($ev[7]['tag'], 'DISC(r=8,NP)', 'disconnect tag with reason and state');
T::eq($ev[7]['ms'], 16400, 'disconnect ms');
T::eq(PairTrace::parse('-'), array(), 'placeholder "-" -> empty');
T::eq(PairTrace::parse(''), array(), 'empty -> empty');
T::eq(PairTrace::parse(null), array(), 'null -> empty');
$odd = PairTrace::parse('NOAT | X@abc');
T::eq($odd[0]['ms'], null, 'event without @ -> ms null');
T::eq($odd[1]['ms'], null, 'non-numeric ms -> null');
T::eq($odd[1]['tag'], 'X', 'tag before @ kept');

T::group('PairTrace::lastEvents');
$last = PairTrace::lastEvents($raw, 3);
T::eq($last, array('DISC(r=8,NP)@16400', 'RES=0@15900', 'PIN_TX@15300'), 'newest first, limited to 3');
T::eq(PairTrace::lastEvents('-', 3), array(), 'no events -> empty');
T::eq(count(PairTrace::lastEvents('A@1 | B@2', 5)), 2, 'fewer events than limit');

T::group('PairTrace::parseDisconnect');
$d = PairTrace::parseDisconnect('reason=8 state=NEED_PIN uptime_ms=16400 since_connect_ms=400 since_pin_tx_ms=100');
T::eq($d['reason'], '8', 'reason');
T::eq($d['state'], 'NEED_PIN', 'state');
T::eq($d['since_pin_tx_ms'], '100', 'since_pin_tx_ms');
$d2 = PairTrace::parseDisconnect('reason=19 state=CONNECTED uptime_ms=5 since_connect_ms=- since_pin_tx_ms=-');
T::eq($d2['since_pin_tx_ms'], '-', 'dash preserved when no PIN was sent');
T::eq(PairTrace::parseDisconnect('-'), array(), 'placeholder -> empty');
T::eq(PairTrace::parseDisconnect(null), array(), 'null -> empty');

T::group('PairTrace::isStale');
T::eq(PairTrace::isStale('3', 3.0), false, 'same connect count -> not stale');
T::eq(PairTrace::isStale('3', 4.0), true, 'connect count moved -> stale');
T::eq(PairTrace::isStale('0', 1), true, '0 -> 1 is stale');
T::eq(PairTrace::isStale('', 5), false, 'nothing rendered -> not stale');
T::eq(PairTrace::isStale('3', null), false, 'ESP count unknown -> not stale');
T::eq(PairTrace::isStale(null, null), false, 'both unknown -> not stale');
T::eq(PairTrace::isStale('abc', 2), false, 'garbage rendered value -> not stale');

T::group('PairTrace output is HTML-safe via esc');
$evil = '<script>alert(1)</script>@1 | "q"@2';
$tail = implode(' | ', PairTrace::lastEvents($evil, 3));
$escaped = SettingsSaver::esc($tail);
T::ok(strpos($escaped, '<script>') === false, 'script tag escaped');
T::ok(strpos($escaped, '&lt;script&gt;') !== false, 'escaped form present');
T::ok(strpos($escaped, '"q"') === false, 'raw double quotes escaped');
