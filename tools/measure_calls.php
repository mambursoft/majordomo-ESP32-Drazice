<?php
/**
 * Count ESP (transport) calls made by the drazice_smart module per page / op.
 * Stubbed MajorDoMo runtime + CountingTransport: NO network, NO hardware.
 * Numbers are CALL COUNTS; wall time is simulated arithmetic (calls x 50 ms),
 * not a measurement of real hardware.
 *
 * Run: php tools/measure_calls.php
 * Works against the pre-AJAX code (AJAX rows print "n/a") and the new code.
 */

require_once(dirname(__DIR__) . '/majordomo/tests/Module/stubs.php');
require_once(dirname(__DIR__) . '/majordomo/modules/drazice_smart/drazice_smart.class.php');

const MS_PER_CALL = 50;

function fresh_module(&$ct, $cfg = array())
{
    return stub_fresh_module($ct, $cfg);
}

$rows = array();
function row($label, $ct)
{
    global $rows;
    $get = $ct->calls['getState'] + $ct->calls['getAll'];
    $post = $ct->calls['postSet'] + $ct->calls['pressButton'];
    $rows[] = array($label, $get, $post, ($get + $post) * MS_PER_CALL, $ct->calls['getAll']);
}
function na($label)
{
    global $rows;
    $rows[] = array($label, 'n/a', 'n/a', 'n/a', 'n/a');
}

// ---- admin() per tab (empty cache = worst case for a cache-based design) ----
foreach (array('status', 'setup', 'control', 'policy', 'log', 'diag', 'advanced') as $tab) {
    $m = fresh_module($ct);
    $_REQUEST = array('tab' => $tab);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $m->tab = $tab;
    $out = array();
    $m->admin($out);
    row('admin() tab=' . $tab . ' (page load)', $ct);
}

// ---- usual() ----
$m = fresh_module($ct);
$_REQUEST = array();
$out = array();
$m->usual($out);
row('usual() (widget)', $ct);

// ---- cycleStep(): worst case = automation ON + presence ON (dry-run stays ON) ----
$m = fresh_module($ct, array('AUTOMATION_ENABLED' => 1, 'PRESENCE_ENABLED' => 1, 'DRY_RUN' => 1));
$m->cycleStep();
row('cycleStep() automation+presence ON', $ct);

$m = fresh_module($ct);
$m->cycleStep();
row('cycleStep() defaults (automation OFF)', $ct);

// ---- AJAX layer (only present after FEAT-002) ----
function ajax(&$m, $op, $extra = array(), $method = 'GET')
{
    $req = array_merge(array('ajax' => '1', 'op' => $op, 'csrf' => 'tok'), $extra);
    return $m->ajaxDispatch($req, $method);
}

if (method_exists('drazice_smart', 'ajaxDispatch')) {
    $m = fresh_module($ct);
    ajax($m, 'status');
    row('AJAX status (cold cache)', $ct);

    $ct->resetCounts();
    ajax($m, 'status');
    row('AJAX status again (< 5 s old cache)', $ct);

    $ct->resetCounts();
    ajax($m, 'status', array('force' => '1'));
    row('AJAX status force=1', $ct);

    foreach (array('pairing', 'diag') as $op) {
        $m = fresh_module($ct);
        ajax($m, $op);
        row('AJAX ' . $op . ' (cold cache)', $ct);
    }
    $m = fresh_module($ct);
    ajax($m, 'ble_scan');
    row('AJAX ble_scan (forced fetch)', $ct);
    foreach (array('policy', 'log') as $op) {
        $m = fresh_module($ct);
        ajax($m, $op);
        row('AJAX ' . $op, $ct);
    }
    $m = fresh_module($ct);
    ajax($m, 'send_pin', array('pin' => '0692'), 'POST');
    row('AJAX send_pin (POST, unchanged logic)', $ct);
} else {
    foreach (array('status', 'status again', 'pairing', 'diag', 'ble_scan', 'policy', 'log', 'send_pin') as $op) {
        na('AJAX ' . $op);
    }
}

// ---- print ----
printf("%-46s %5s %5s %9s %7s\n", 'scenario', 'GET', 'POST', 'sim ms', 'getAll');
echo str_repeat('-', 78) . "\n";
foreach ($rows as $r) {
    printf("%-46s %5s %5s %9s %7s\n", $r[0], $r[1], $r[2], $r[3], $r[4]);
}
echo "\nsim ms = (GET+POST) x " . MS_PER_CALL . " ms/call (arithmetic, NOT hardware timing)\n";
echo 'php ' . PHP_VERSION . ' ' . gmdate('Y-m-d') . "\n";
