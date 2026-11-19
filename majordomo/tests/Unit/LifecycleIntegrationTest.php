<?php

use DraziceSmart\SettingsSaver;

T::group('Lifecycle Integration: scripts/cycle_drazice_smart.php static analysis');

$workerFile = dirname(dirname(__DIR__)) . '/scripts/cycle_drazice_smart.php';
T::ok(file_exists($workerFile), 'scripts/cycle_drazice_smart.php exists');
$code = file_get_contents($workerFile);

// Heartbeat contracts
T::ok(strpos($code, "setGlobal(\$cycleVarName, time(), 1)") !== false || strpos($code, "setGlobal('cycle_drazice_smartRun', time(), 1)") !== false,
    'worker registers initial heartbeat before entering loop');
T::ok(strpos($code, "setGlobal(\$cycleVarName, \$now, 1)") !== false || strpos($code, "setGlobal('cycle_drazice_smartRun', \$now, 1)") !== false,
    'worker updates cycle_drazice_smartRun inside loop');
T::ok(strpos($code, "cycle_drazice_smartLastCycle") !== false,
    'worker tracks cycle_drazice_smartLastCycle separately on cycleStep success');

// Exit / signal handling contracts
T::ok(strpos($code, 'isRebootRequired') !== false, 'worker checks isRebootRequired()');
T::ok(strpos($code, "file_exists(ROOT . 'reboot')") !== false, 'worker checks ROOT reboot file');
T::ok(strpos($code, 'cycle_drazice_smartDisabled') !== false, 'worker checks cycle_drazice_smartDisabled');
T::ok(strpos($code, 'empty \'cycle_drazice_smartControl\'') === false,
    'worker does NOT treat empty Control as a stop signal');

// Flock safety
T::ok(strpos($code, 'flock($lock, LOCK_EX | LOCK_NB)') !== false, 'worker guards with non-blocking flock');
T::ok(strpos($code, 'flock($lock, LOCK_UN)') !== false, 'worker releases flock on clean exit');
T::ok(strpos($code, 'unlink') === false, 'worker never unlinks the lock file during process lifetime');

// Logging & DebMes
T::ok(strpos($code, "'drazice_smart'") !== false, "worker uses 'drazice_smart' category for DebMes");
T::ok(strpos($code, 'cycle_drazice_smartLastError') !== false, 'worker integrates cycle_drazice_smartLastError in cache');
T::ok(strpos($code, '$LOG_SUPPRESS_INTERVAL') !== false, 'worker throttles repeated error logs');

// Avoid config overwrite from worker loop
T::ok(strpos($code, '$mod->saveConfig()') === false,
    'worker loop does NOT call saveConfig() on every iteration (protects user settings)');

T::group('Lifecycle Integration: safe saveConfig contract');

$classFile = dirname(dirname(__DIR__)) . '/modules/drazice_smart/drazice_smart.class.php';
$classCode = file_get_contents($classFile);

T::ok(strpos($classCode, 'public function saveConfig($isUserAction = false)') !== false,
    'drazice_smart overrides saveConfig with $isUserAction parameter');
T::ok(strpos($classCode, 'strpos($k, \'_\') !== 0') !== false,
    'saveConfig protects non-internal user settings when saving background runtime state');

T::group('Lifecycle Integration: lastSampleUtc separation on connection loss');

T::ok(strpos($classCode, 'if (!empty($s[\'http_available\']))') !== false,
    'publishProperties only advances lastSampleUtc when http_available is true');
