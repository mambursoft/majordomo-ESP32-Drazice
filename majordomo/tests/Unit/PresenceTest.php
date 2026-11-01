<?php

use DraziceSmart\Presence;

T::group('Presence (passive Wi-Fi/BLE by MAC)');

$now = 1000000;
$roster = array('AA:BB:CC:DD:EE:FF' => 'Назар');
$cfg = array('presence_enabled' => true, 'absent_after_s' => 120,
    'notify_after_s' => 600, 'renotify_every_s' => 0);

// Disabled -> nothing.
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now)),
    $roster, $now, array('presence_enabled' => false), array());
T::eq(count($r['present']), 0, 'disabled -> no presence');

// Seen now, starting fresh -> present, no notify yet (duration 0 < 600).
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now)),
    $roster, $now, $cfg, array());
T::eq(count($r['present']), 1, 'seen -> present');
T::eq(count($r['events']), 0, 'duration 0 -> no notify');

// Present for 11 minutes -> notify fires once.
$state = array('Назар' => array('since_utc' => $now - 660, 'last_notify_utc' => 0));
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now)),
    $roster, $now, $cfg, $state);
T::eq(count($r['events']), 1, 'over 600s -> notify event');
T::eq($r['events'][0]['action'], 'announce_toilet', 'event action = announce_toilet');
T::ok($r['state']['Назар']['last_notify_utc'] === $now, 'last_notify recorded');

// Already notified, renotify disabled -> no duplicate notify.
$state2 = array('Назар' => array('since_utc' => $now - 900, 'last_notify_utc' => $now - 200));
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now)),
    $roster, $now, $cfg, $state2);
T::eq(count($r['events']), 0, 'renotify disabled -> no duplicate');

// Renotify every 300s -> fires again.
$cfgRe = $cfg; $cfgRe['renotify_every_s'] = 300;
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now)),
    $roster, $now, $cfgRe, array('Назар' => array('since_utc' => $now - 900, 'last_notify_utc' => $now - 400)));
T::eq(count($r['events']), 1, 'renotify after interval -> notify again');

// Absent (seen too long ago) -> not present.
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now - 300)),
    $roster, $now, $cfg, array());
T::eq(count($r['present']), 0, 'seen 300s ago (>120) -> absent');

// Unknown MAC ignored (data minimisation).
$r = Presence::evaluate(array(array('mac' => '11:22:33:44:55:66', 'seen_utc' => $now)),
    $roster, $now, $cfg, array());
T::eq(count($r['present']), 0, 'unknown MAC ignored');
