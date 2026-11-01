<?php
/**
 * Cycle worker for the drazice_smart module.
 *
 * Runs one bounded step per invocation: snapshot -> decide -> (optional) apply.
 * Designed to be scheduled by the MajorDoMo cron/cycle, NOT to loop forever.
 * A file lock prevents overlapping runs. No long sleeps, no infinite SSE.
 *
 * Register under the standard MajorDoMo scheduler at the module poll interval
 * (default 15s is set in config; the cron granularity is usually 1 min, so this
 * script self-throttles using a last-run timestamp).
 *
 * @package drazice_smart
 * @license MIT
 */

if (!defined('ROOT')) {
    // Resolve MajorDoMo root (two levels up from modules/<name>/scripts style is
    // not applicable here; this script lives in scripts/ at package root, which
    // is deployed under the web root). We rely on being included by cron.php
    // which defines ROOT; if run standalone, bail out safely.
    @include_once(dirname(__FILE__) . '/../../lib/loader.php');
}

if (!function_exists('getObject') && !function_exists('SQLSelectOne')) {
    fwrite(STDERR, "drazice_smart cycle: MajorDoMo runtime not available\n");
    exit(1);
}

$lockDir = (defined('ROOT') ? ROOT : sys_get_temp_dir() . '/') . 'cms/';
if (!is_dir($lockDir)) {
    $lockDir = sys_get_temp_dir() . '/';
}
$lockFile = $lockDir . 'drazice_smart.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    // Can't create a lock; fall back to temp dir.
    $lockFile = sys_get_temp_dir() . '/drazice_smart.lock';
    $lock = @fopen($lockFile, 'c');
}
if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
    // Another cycle is running; skip quietly.
    exit(0);
}

try {
    require_once(dirname(__FILE__) . '/../modules/drazice_smart/drazice_smart.class.php');
    $mod = new drazice_smart();

    // Self-throttle to the configured poll interval.
    $mod->getConfig();
    $interval = isset($mod->config['POLL_INTERVAL_S']) ? (int) $mod->config['POLL_INTERVAL_S'] : 15;
    if ($interval < 5) {
        $interval = 5;
    }
    $lastRun = isset($mod->config['_LAST_CYCLE_UTC']) ? (int) $mod->config['_LAST_CYCLE_UTC'] : 0;
    $now = time();
    if (($now - $lastRun) < $interval) {
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        exit(0);
    }

    $result = $mod->cycleStep();
    $mod->config['_LAST_CYCLE_UTC'] = time();
    $mod->saveConfig();

    if (php_sapi_name() === 'cli' && getenv('DRAZICE_VERBOSE')) {
        echo json_encode($result['decision']) . "\n";
    }
} catch (\Exception $e) {
    fwrite(STDERR, 'drazice_smart cycle error: ' . $e->getMessage() . "\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'drazice_smart cycle fatal: ' . $e->getMessage() . "\n");
}

if ($lock !== false) {
    flock($lock, LOCK_UN);
    fclose($lock);
}
