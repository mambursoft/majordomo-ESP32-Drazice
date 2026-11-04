<?php
/**
 * Cycle worker for the drazice_smart module.
 *
 * MajorDoMo's cycle.php auto-discovers scripts/cycle_*.php and runs each in its
 * own thread (one long-lived process). We therefore BOOTSTRAP the MajorDoMo
 * core here exactly like the sibling cycle_solarplanner.php / cycle_oncharger.php
 * do (chdir to the web root, include config.php + loader.php), then loop with a
 * sleep between iterations. A flock guard prevents overlapping runs and the
 * work itself self-throttles to POLL_INTERVAL_S.
 *
 * @package drazice_smart
 * @license MIT
 */

// Bootstrap the MajorDoMo runtime (same pattern as other cycle_*.php workers).
chdir(dirname(__FILE__) . '/../');
if (!defined('ROOT')) {
    include_once('./config.php');
    include_once('./lib/loader.php');
    @include_once('./lib/threads.php');
}

if (!function_exists('getObject') && !function_exists('SQLSelectOne')) {
    fwrite(STDERR, "drazice_smart cycle: MajorDoMo runtime not available\n");
    exit(1);
}

set_time_limit(0);

// Single-instance guard for the whole worker lifetime.
$lockDir = (defined('ROOT') ? ROOT : sys_get_temp_dir() . '/') . 'cms/';
if (!is_dir($lockDir)) {
    $lockDir = sys_get_temp_dir() . '/';
}
$lockFile = $lockDir . 'drazice_smart.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    $lockFile = sys_get_temp_dir() . '/drazice_smart.lock';
    $lock = @fopen($lockFile, 'c');
}
if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // another worker already running
}

require_once(dirname(__FILE__) . '/../modules/drazice_smart/drazice_smart.class.php');

// Long-lived loop: cycle.php starts this once; we iterate with a short sleep.
// cycle.php signals a graceful stop via a 'cycle_drazice_smartDisabled' / an
// empty 'cycle_drazice_smartControl' global; honour it so a restart is clean.
$LOOP_SLEEP_S = 10;
while (true) {
    if (function_exists('getGlobal') && getGlobal('cycle_drazice_smartDisabled')) {
        break;
    }
    try {
        $mod = new drazice_smart();
        $mod->getConfig();
        $interval = isset($mod->config['POLL_INTERVAL_S']) ? (int) $mod->config['POLL_INTERVAL_S'] : 15;
        if ($interval < 5) {
            $interval = 5;
        }
        $last = isset($mod->config['_LAST_CYCLE_UTC']) ? (int) $mod->config['_LAST_CYCLE_UTC'] : 0;
        if ((time() - $last) >= $interval) {
            $mod->cycleStep();
            $mod->config['_LAST_CYCLE_UTC'] = time();
            $mod->saveConfig();
        }
    } catch (\Throwable $e) {
        if (function_exists('DebMes')) {
            DebMes('drazice_smart cycle error: ' . $e->getMessage());
        }
    }
    sleep($LOOP_SLEEP_S);
}

if ($lock !== false) {
    flock($lock, LOCK_UN);
    fclose($lock);
}
