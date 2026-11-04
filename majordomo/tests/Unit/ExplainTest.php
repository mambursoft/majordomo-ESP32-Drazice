<?php

use DraziceSmart\Explain;
use DraziceSmart\Planner;

T::group('Explain: localised reasons and "what must change"');

// Resolver over one real language file (ru): every key Explain may ask for
// must exist in all three languages, otherwise the UI shows raw keys.
$langDir = dirname(__DIR__, 2) . '/languages'; // .../majordomo/languages
foreach (array('default', 'ru', 'ua') as $lg) {
    $path = $langDir . '/drazice_smart_' . $lg . '.php';
    $src = is_file($path) ? file_get_contents($path) : '';
    T::ok($src !== '', "language file readable: $lg");
    $missing = array();
    foreach (Explain::keys() as $k) {
        if (strpos($src, "'DS_" . $k . "'") === false) {
            $missing[] = $k;
        }
    }
    T::eq(implode(',', $missing), '', "all Explain keys present in $lg");
}

// Minimal resolver: returns "<KEY>{placeholders}" so we can assert which keys
// were chosen and that placeholders were substituted.
$tr = function ($k) {
    $map = array(
        'RSC_BATTERY_SOURCE_BLOCK' => 'on battery',
        'RSN_BATTERY_SOURCE_BLOCK' => 'House on battery.',
        'HT_HEADER_HOLD' => 'Must change:',
        'HT_OR' => 'OR',
        'HT_BAT_GRID' => 'grid: {prop} != {active} (now {raw})',
        'HT_BAT_ALLOW' => 'tick "{@ALLOW_BATTERY_HEAT}"',
        'HT_BAT_BOOST' => 'boost',
        'HT_SURPLUS_ENABLE' => 'enable surplus at {hi}% (now {soc})',
        'HT_SURPLUS_SOC' => 'SOC to {hi}% (now {soc})',
        'ALLOW_BATTERY_HEAT' => 'Allow battery heat',
        'HT_NOT_SENT' => 'not sent: {why}',
        'HT_WHY_AUTO_OFF' => 'automation off',
        'HT_WHY_DRY' => 'dry run',
        'PW_BATTERY' => 'battery: {prop}={raw}',
        'POWER_BATTERY' => 'akku',
    );
    return isset($map[$k]) ? $map[$k] : null;
};

$cfg = array(
    'POWER_PROPERTY' => 'BatteryMode.active', 'BATTERY_ACTIVE_VALUE' => '1',
    'SOLAR_SURPLUS_ENABLED' => 0, 'SURPLUS_SOC_HIGH' => 100, 'AUTOMATION_ENABLED' => 0, 'DRY_RUN' => 1,
    'COMFORT_MIN_C' => 45, 'NIGHT_TARGET_C' => 55, 'BOOST_TARGET_C' => 65, 'ABSOLUTE_MAX_C' => 70,
);
$s = array(
    'http_available' => true, 'heating' => false, 'temp_upper' => 50.0, 'power_source' => 'battery',
    'power_code' => 'battery', 'power_raw' => '1', 'power_raw_norm' => '1', 'power_active_norm' => '1',
    'battery_soc' => 87.0, 'is_daytime' => true, 'tariff_active' => false,
);
$d = Planner::decide($s, array('comfort_min_c' => 45, 'night_target_c' => 55));
T::eq($d['reason'], 'battery_source_block', 'fixture is battery_source_block');

$ex = Explain::decision($s, $d, $cfg, $tr);
T::eq($ex['label'], 'on battery', 'label localised (no raw code)');
T::eq($ex['text'], 'House on battery.', 'sentence localised');
$lines = explode("\n", $ex['howto']);
T::eq($lines[0], 'Must change:', 'howto header');
T::eq($lines[1], '• grid: BatteryMode.active != 1 (now 1)', 'first option without OR, placeholders filled');
T::eq($lines[2], '• OR tick "Allow battery heat"', 'second option prefixed with OR, {@LABEL} resolved');
T::eq($lines[3], '• OR boost', 'boost option');
T::eq($lines[4], '• OR enable surplus at 100% (now 87%)', 'surplus disabled -> suggest enabling it with values');
T::eq($lines[5], 'not sent: automation off, dry run', 'automation/dry-run note');

$cfg['SOLAR_SURPLUS_ENABLED'] = 1;
$cfg['AUTOMATION_ENABLED'] = 1;
$cfg['DRY_RUN'] = 0;
$ex = Explain::decision($s, $d, $cfg, $tr);
$lines = explode("\n", $ex['howto']);
T::eq($lines[4], '• OR SOC to 100% (now 87%)', 'surplus enabled + day -> SOC target');
T::eq(count($lines), 5, 'no not-sent note when automation is live');

T::eq(Explain::power($s, $cfg, $tr), 'battery: BatteryMode.active=1', 'power line localised');
T::eq(Explain::powerWord('battery', $tr), 'akku', 'power word localised');

// Unknown key -> falls back to the code, never empty.
$ex = Explain::decision($s, array('reason' => 'something_new', 'action' => 'hold'), $cfg, function ($k) { return null; });
T::eq($ex['label'], 'something_new', 'unknown reason falls back to the raw code');
T::eq($ex['howto'], '', 'unknown reason -> no howto list');
