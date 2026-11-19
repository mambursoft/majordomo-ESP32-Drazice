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
$lockDir = (defined('ROOT') ? ROOT : sys_get_temp_dir() . DIRECTORY_SEPARATOR) . 'cms/';
if (!is_dir($lockDir)) {
    $lockDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
}
$lockFile = $lockDir . 'drazice_smart.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'drazice_smart.lock';
    $lock = @fopen($lockFile, 'c');
}
if ($lock === false) {
    if (function_exists('DebMes')) {
        DebMes('drazice_smart cycle: cannot open lock file ' . $lockFile, 'drazice_smart');
    }
    if (function_exists('saveCycleToCache')) {
        saveCycleToCache('cycle_drazice_smartLastError', 'Cannot open lock file');
    }
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    exit(0); // another worker already running
}

require_once(dirname(__FILE__) . '/../modules/drazice_smart/drazice_smart.class.php');

echo date('H:i:s') . " running " . basename(__FILE__) . PHP_EOL;

// Register initial heartbeat so MajorDoMo sees the worker immediately on start
$cycleVarName = 'cycle_drazice_smartRun';
setGlobal($cycleVarName, time(), 1);

// Long-lived loop: cycle.php starts this once; we iterate with a short sleep.
// Graceful termination is requested via isRebootRequired() or cycle_drazice_smartDisabled.
$LOOP_SLEEP_S = 2;
$LOG_SUPPRESS_INTERVAL = 300; // 5 minutes
$latestHeartbeat = 0;
$lastCycle = 0;
$lastLoggedError = '';
$lastLoggedTime = 0;

while (true) {
    $now = time();

    // Periodic worker heartbeat for MajorDoMo cycle monitor (every iteration, throttled)
    if (($now - $latestHeartbeat) >= 2) {
        $latestHeartbeat = $now;
        setGlobal($cycleVarName, $now, 1);
    }

    // Check termination signals: system reboot flag or module disabled
    if (function_exists('isRebootRequired') && isRebootRequired()) {
        break;
    }
    if (defined('ROOT') && file_exists(ROOT . 'reboot')) {
        break;
    }
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

        if (($now - $lastCycle) >= $interval) {
            $mod->cycleStep();
            $lastCycle = time();
            setGlobal('cycle_drazice_smartLastCycle', $lastCycle, 1);

            if ($lastLoggedError !== '') {
                $lastLoggedError = '';
                if (function_exists('DebMes')) {
                    DebMes('drazice_smart cycle recovered, cycleStep succeeded', 'drazice_smart');
                }
                if (function_exists('saveCycleToCache')) {
                    saveCycleToCache('cycle_drazice_smartLastError', '');
                }
            }
        }
    } catch (\Throwable $e) {
        $errClass = get_class($e);
        $errMsg = $e->getMessage();
        $errFile = basename($e->getFile());
        $errLine = $e->getLine();

        $cleanMsg = preg_replace('/(password|pass|secret|pin)=([^& ]+)/i', '$1=***', $errMsg);
        $formattedError = "[$errClass] $cleanMsg in $errFile:$errLine";

        if ($formattedError !== $lastLoggedError || ($now - $lastLoggedTime) >= $LOG_SUPPRESS_INTERVAL) {
            $lastLoggedError = $formattedError;
            $lastLoggedTime = $now;
            if (function_exists('DebMes')) {
                DebMes('drazice_smart cycle error: ' . $formattedError, 'drazice_smart');
            }
        }
        if (function_exists('saveCycleToCache')) {
            saveCycleToCache('cycle_drazice_smartLastError', substr($formattedError, 0, 240));
        }
    }

    if (!empty($_GET['onetime'])) {
        break;
    }

    sleep($LOOP_SLEEP_S);
}

if (is_resource($lock)) {
    flock($lock, LOCK_UN);
    fclose($lock);
}
