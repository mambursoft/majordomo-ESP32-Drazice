<?php

use DraziceSmart\Presence;

T::group('Presence (staged events: entered/long/too_long/left)');

$now = 1000000;
$roster = array('AA:BB:CC:DD:EE:FF' => 'Назар');
$cfg = array('presence_enabled' => true, 'absent_after_s' => 120,
    'long_after_s' => 600, 'too_long_after_s' => 1200, 'renotify_every_s' => 0);

function evt_types($r) {
    $t = array();
    foreach ($r['events'] as $e) { $t[] = $e['event']; }
    return $t;
}

// Disabled -> nothing.
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now)),
    $roster, $now, array('presence_enabled' => false), array());
T::eq(count($r['present']), 0, 'disabled -> no presence');

// Fresh sighting -> present + 'entered' event, stage=present.
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now, 'source' => 'ble')),
    $roster, $now, $cfg, array());
T::eq(count($r['present']), 1, 'seen -> present');
T::ok(in_array('entered', evt_types($r), true), 'new session -> entered event');
T::eq($r['present'][0]['source'], 'ble', 'source carried (ble)');
T::eq($r['state']['Назар']['stage'], 'present', 'stage=present after entered');

// Present 11 min (>600 long) with stage=present -> 'long'.
$state = array('Назар' => array('since_utc' => $now - 660, 'stage' => 'present', 'announced' => true, 'last_notify_utc' => 0));
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now)),
    $roster, $now, $cfg, $state);
T::ok(in_array('long', evt_types($r), true), 'over 600s -> long event');
T::eq($r['state']['Назар']['stage'], 'long', 'stage=long');
T::eq($r['events'][0]['action'], 'announce_toilet', 'event action = announce_toilet');

// Present 21 min (>1200 too_long) with stage=long -> 'too_long'.
$state = array('Назар' => array('since_utc' => $now - 1260, 'stage' => 'long', 'announced' => true, 'last_notify_utc' => $now - 600));
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now)),
    $roster, $now, $cfg, $state);
T::ok(in_array('too_long', evt_types($r), true), 'over 1200s -> too_long event');
T::eq($r['state']['Назар']['stage'], 'too_long', 'stage=too_long');

// Stage=long, renotify disabled, below next threshold -> no new event.
$state = array('Назар' => array('since_utc' => $now - 700, 'stage' => 'long', 'announced' => true, 'last_notify_utc' => $now - 100));
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now)),
    $roster, $now, $cfg, $state);
T::eq(count($r['events']), 0, 'long stage, no renotify -> no event');

// Renotify every 300s while long -> reminder fires.
$cfgRe = $cfg; $cfgRe['renotify_every_s'] = 300;
$state = array('Назар' => array('since_utc' => $now - 700, 'stage' => 'long', 'announced' => true, 'last_notify_utc' => $now - 400));
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now)),
    $roster, $now, $cfgRe, $state);
T::ok(in_array('long', evt_types($r), true), 'renotify interval -> reminder (long)');

// Was present, now gone -> 'left' event, no longer present.
$state = array('Назар' => array('since_utc' => $now - 800, 'stage' => 'long', 'announced' => true, 'last_notify_utc' => $now - 100));
$r = Presence::evaluate(array(), $roster, $now, $cfg, $state);
T::eq(count($r['present']), 0, 'gone -> not present');
T::ok(in_array('left', evt_types($r), true), 'gone -> left event');

// Wi-Fi source carried.
$r = Presence::evaluate(array(array('mac' => 'aa:bb:cc:dd:ee:ff', 'seen_utc' => $now, 'source' => 'wifi')),
    $roster, $now, $cfg, array());
T::eq($r['present'][0]['source'], 'wifi', 'source carried (wifi)');

// Unknown MAC ignored (data minimisation).
$r = Presence::evaluate(array(array('mac' => '11:22:33:44:55:66', 'seen_utc' => $now)),
    $roster, $now, $cfg, array());
T::eq(count($r['present']), 0, 'unknown MAC ignored');
