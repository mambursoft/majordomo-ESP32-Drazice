<?php
/**
 * Module-level: the page render makes ZERO ESP calls, the cycle makes ONE,
 * AJAX ops are authorized before any work, the cache coalesces/refetches.
 */

use DraziceSmart\StateCache;

$TABS = array('status', 'setup', 'control', 'policy', 'log', 'diag', 'advanced');

function mt_render_admin($m, $tab)
{
    $_REQUEST = array('tab' => $tab);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $m->tab = $tab;
    $out = array();
    $m->admin($out);
    return $out;
}

function mt_ajax($m, $op, $extra = array(), $method = 'GET', $csrf = true)
{
    $req = array_merge(array('ajax' => '1', 'op' => $op), $extra);
    if ($csrf) {
        $req['csrf'] = 'tok';
    }
    return $m->ajaxDispatch($req, $method);
}

// ---------------------------------------------------------------------------
T::group('MODULE_VERSION matches modinfo.json; state-cache table registered');
$pkgRoot = dirname(__DIR__, 2); // .../majordomo
$info = json_decode(file_get_contents($pkgRoot . '/modinfo.json'), true);
T::eq(drazice_smart::MODULE_VERSION, $info['version'], 'drazice_smart::MODULE_VERSION == modinfo.json version');
$m = stub_fresh_module($ct);
T::ok(strpos($GLOBALS['__dbinstall_data'], 'drazice_state_cache: PAYLOAD') !== false, 'dbInstall() declares drazice_state_cache');
$dbi = file_get_contents($pkgRoot . '/modules/drazice_smart/db_install.php');
T::ok(strpos($dbi, 'drazice_state_cache: PAYLOAD') !== false, 'db_install.php declares drazice_state_cache');

// ---------------------------------------------------------------------------
T::group('admin(): ZERO ESP calls per tab (empty cache AND seeded cache)');
foreach ($TABS as $tab) {
    $m = stub_fresh_module($ct);
    $out = mt_render_admin($m, $tab);
    T::eq($ct->total(), 0, "admin($tab) empty cache: 0 transport calls");
    T::eq($out['DATA_HAS'], 0, "admin($tab) empty cache: DATA_HAS=0 (no data, not zeros)");
    T::eq($out['HTTP_AVAILABLE'], 0, "admin($tab) empty cache: ESP shown as unavailable");
    T::ok(strpos($out['TEMP_UPPER_F'], '55') === false, "admin($tab) empty cache: no invented temperature");

    $m = stub_fresh_module($ct);
    $m->refreshState(true);
    $ct->resetCounts();
    $out = mt_render_admin($m, $tab);
    T::eq($ct->total(), 0, "admin($tab) seeded cache: 0 transport calls");
    T::eq($out['DATA_HAS'], 1, "admin($tab) seeded cache: DATA_HAS=1");
    T::eq($out['TEMP_UPPER_F'], '55.5 °C', "admin($tab) seeded cache: temperature rendered from cache");
    T::eq($out['HTTP_AVAILABLE'], 1, "admin($tab) seeded cache: ESP shown as available");
    T::ok(isset($out['JS_I18N'], $out['JS_CONFIG'], $out['JS_VER']), "admin($tab): JS_I18N/JS_CONFIG/JS_VER present");
    T::ok(json_decode($out['JS_CONFIG'], true) !== null && strpos($out['JS_CONFIG'], '<') === false,
        "admin($tab): JS_CONFIG is valid JSON without raw '<'");
    $i18n = json_decode($out['JS_I18N'], true);
    T::ok(is_array($i18n) && strpos($out['JS_I18N'], '<') === false, "admin($tab): JS_I18N is valid JSON without raw '<'");
    T::ok(isset($out['DATA_AGE_S'], $out['DATA_STALE'], $out['DATA_FETCH_OK']), "admin($tab): DATA_* meta vars present");
    $cfgJs = json_decode($out['JS_CONFIG'], true);
    T::eq(isset($cfgJs['tab']) ? $cfgJs['tab'] : null, $tab, "admin($tab): JS_CONFIG.tab matches");
}

// ---------------------------------------------------------------------------
T::group('admin(): tab-specific work only on its tab');
$m = stub_fresh_module($ct);
$o = mt_render_admin($m, 'status');
T::eq(isset($o['LOG_DECISIONS']), false, 'status tab: log queries skipped');
T::eq(isset($o['OPTS_PROPS_DATALIST']), false, 'status tab: property list scan skipped');
T::eq(isset($o['FC_AVAILABLE']), false, 'status tab: forecast skipped');
$o = mt_render_admin($m, 'log');
T::eq(isset($o['LOG_DECISIONS']), true, 'log tab: LOG_DECISIONS built');
$o = mt_render_admin($m, 'policy');
T::eq(isset($o['OPTS_PROPS_DATALIST']) && isset($o['OPTS_TIMEZONE']), true, 'policy tab: shared property datalist + timezone list built');
T::eq(isset($o['OPTS_SOC_PROPERTY']), false, 'policy tab: no per-field duplicated option lists (one shared datalist)');
$tpl = file_get_contents(dirname(__DIR__, 2) . '/modules/drazice_smart/templates/action_admin.html');
T::eq(substr_count($tpl, '[#OPTS_PROPS_DATALIST#]'), 1, 'template renders the property list exactly once');
T::eq(substr_count($tpl, 'list="ds-props"'), 6, 'all 6 binding inputs use the shared datalist');
T::eq(isset($o['FC_AVAILABLE']), true, 'policy tab: forecast built');

// ---------------------------------------------------------------------------
T::group('usual(): never contacts the ESP and ignores ajax/op params');
$m = stub_fresh_module($ct);
$_REQUEST = array();
$out = array();
$m->usual($out);
T::eq($ct->total(), 0, 'usual() empty cache: 0 transport calls');
$m->refreshState(true);
$ct->resetCounts();
$_REQUEST = array('ajax' => '1', 'op' => 'send_pin', 'pin' => '0692', 'csrf' => 'tok');
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = array();
$m->usual($out);
T::eq($ct->total(), 0, 'usual() seeded + ajax=1&op=send_pin: still 0 transport calls (op ignored)');
T::eq(isset($out['PIN_CMD']), false, 'usual() did not run the PIN op');
T::eq($out['TEMP_UPPER_F'], '55.5 °C', 'usual() renders from the cache');
$_REQUEST = array();

// ---------------------------------------------------------------------------
T::group('cycleStep(): exactly ONE getAll, zero per-entity reads');
$m = stub_fresh_module($ct, array('AUTOMATION_ENABLED' => 1, 'PRESENCE_ENABLED' => 1, 'DRY_RUN' => 1));
$r = $m->cycleStep();
T::eq($ct->calls['getAll'], 1, 'cycleStep: 1 getAll');
T::eq($ct->calls['getState'], 0, 'cycleStep: 0 getState (diagnostics + BLE come from the same map)');
T::eq($ct->calls['pressButton'], 1, 'cycleStep: lease renew POST kept (automation on)');
T::eq($ct->calls['postSet'], 0, 'cycleStep: dry-run -> no writes');
T::eq($r['snapshot']['temp_upper'], 55.5, 'cycleStep snapshot built from the fetched map');
T::eq($r['snapshot']['http_available'], true, 'cycleStep: http_available');
T::eq($r['snapshot']['cooldown_known'], true, 'cycleStep: cooldown known');

$m = stub_fresh_module($ct);
$m->refreshState(true);
$ct->resetCounts();
$ct->down = true;
$r = $m->cycleStep();
T::eq($ct->calls['getAll'], 1, 'cycleStep ESP down: still exactly 1 getAll');
T::eq($r['snapshot']['http_available'], false, 'cycleStep ESP down: planner sees http_available=false');
T::eq($r['snapshot']['temp_upper'], null, 'cycleStep ESP down: temp_upper is null (planner NEVER decides on the stale cache)');
T::eq($r['snapshot']['cooldown_known'], false, 'cycleStep ESP down: cooldown unknown');
T::eq($ct->calls['pressButton'], 0, 'cycleStep ESP down: no lease renew');

// ---------------------------------------------------------------------------
T::group('ajaxDispatch: authorization happens BEFORE any work');
$m = stub_fresh_module($ct);
$cfgBefore = $GLOBALS['__cfg'];
foreach (DraziceSmart\AjaxApi::MUTATING_OPS as $op) {
    list($st, $pl) = mt_ajax($m, $op, array('value' => '60', 'pin' => '0692', 'host' => '172.16.0.150', 'mac' => 'AA:BB:CC:DD:EE:01'), 'POST', false);
    T::ok($st === 403 && $pl['ok'] === false && $pl['error'] === 'csrf', "$op: POST without csrf -> 403 csrf");
    list($st2, $pl2) = mt_ajax($m, $op, array(), 'GET', true);
    T::ok($st2 === 405 && $pl2['error'] === 'method', "$op: GET with csrf -> 405 method");
}
T::eq($ct->total(), 0, 'all rejected mutating ops made 0 transport calls');
T::eq($GLOBALS['__cfg'], $cfgBefore, 'all rejected mutating ops left the config unchanged');
T::eq(stub_table_rows('drazice_commands'), array(), 'rejected ops wrote no command rows');
list($st, $pl) = mt_ajax($m, 'bogus');
T::ok($st === 400 && $pl['error'] === 'unknown_op', 'unknown op -> 400 unknown_op');
list($st, $pl) = $m->ajaxDispatch(array('ajax' => '1'), 'GET');
T::ok($st === 400, 'missing op -> 400');
list($st, $pl) = $m->ajaxDispatch(array('ajax' => '1', 'op' => 'status', 'csrf' => 'wrong'), 'POST');
T::ok($st === 200 && $pl['ok'] === true, 'read op does not need a csrf token');
T::eq($ct->total() >= 0, true, 'sanity');

// ---------------------------------------------------------------------------
T::group('ajaxDispatch: mutating ops execute with POST + csrf');
$m = stub_fresh_module($ct);
T::eq(!empty($GLOBALS['__cfg']['AUTOMATION_ENABLED']), false, 'automation starts OFF (safe default)');
T::eq(!empty($GLOBALS['__cfg']['DRY_RUN']), true, 'dry-run starts ON (safe default)');
list($st, $pl) = mt_ajax($m, 'enable_automation', array(), 'POST');
T::ok($st === 200 && $pl['ok'] === true && $pl['result']['automation_enabled'] === true, 'enable_automation executed');
T::eq((int) $GLOBALS['__cfg']['AUTOMATION_ENABLED'], 1, 'config AUTOMATION_ENABLED=1 saved');
T::eq(!empty($GLOBALS['__cfg']['DRY_RUN']), true, 'enabling automation did not touch dry-run');
list($st, $pl) = mt_ajax($m, 'disable_automation', array(), 'POST');
T::eq((int) $GLOBALS['__cfg']['AUTOMATION_ENABLED'], 0, 'disable_automation executed');
list($st, $pl) = mt_ajax($m, 'set_dry_run', array('value' => '0'), 'POST');
T::eq($pl['result']['dry_run'], false, 'set_dry_run value=0 -> dry_run=false');
list($st, $pl) = mt_ajax($m, 'set_dry_run', array(), 'POST');
T::eq($pl['result']['dry_run'], true, 'set_dry_run default -> dry_run=true');
list($st, $pl) = mt_ajax($m, 'boost', array('ttl' => '600'), 'POST');
T::ok($pl['result']['boost_until_utc'] > time(), 'boost sets boost_until_utc in the future');
list($st, $pl) = mt_ajax($m, 'cancel_boost', array(), 'POST');
T::eq((int) $GLOBALS['__cfg']['BOOST_UNTIL_UTC'], 0, 'cancel_boost clears boost');

$ct->resetCounts();
list($st, $pl) = mt_ajax($m, 'request_target', array('value' => '60'), 'POST');
T::ok($st === 200 && $pl['result']['ok'] === true && $pl['result']['readback'] === 55.0, 'request_target: ok + readback');
T::eq($ct->calls['postSet'], 1, 'request_target: exactly 1 write');
list($st, $pl) = mt_ajax($m, 'request_target', array('value' => '99'), 'POST');
T::ok($st === 400 && $pl['error'] === 'bad_input' && $pl['reason'] === 'out_of_range', 'request_target 99 -> 400 bad_input out_of_range');
list($st, $pl) = mt_ajax($m, 'request_mode', array('value' => 'BOGUS'), 'POST');
T::ok($st === 400 && $pl['reason'] === 'bad_mode', 'request_mode bad -> 400 bad_mode');
list($st, $pl) = mt_ajax($m, 'request_mode', array('value' => 'SMART'), 'POST');
T::ok($st === 200 && $pl['result']['ok'] === true, 'request_mode SMART ok');
list($st, $pl) = mt_ajax($m, 'select_boiler', array('mac' => 'nope'), 'POST');
T::ok($st === 400 && $pl['reason'] === 'bad_mac', 'select_boiler bad mac -> 400');
list($st, $pl) = mt_ajax($m, 'select_boiler', array('mac' => 'aa:bb:cc:dd:ee:01'), 'POST');
T::ok($st === 200 && $pl['result']['mac'] === 'AA:BB:CC:DD:EE:01', 'select_boiler stores the upper-cased MAC');
$ct->resetCounts();
list($st, $pl) = mt_ajax($m, 'lease_renew', array(), 'POST');
T::ok($st === 200 && $pl['result']['ok'] === true && $ct->calls['pressButton'] === 1, 'lease_renew presses the button once');
list($st, $pl) = mt_ajax($m, 'scan_network', array('base_ip' => 'x'), 'POST');
T::ok($st === 400 && $pl['reason'] === 'need_base_ip', 'scan_network without a valid base IP -> 400 need_base_ip');
list($st, $pl) = mt_ajax($m, 'probe_host', array('host' => '8.8.8.8'), 'POST');
T::ok($st === 200 && $pl['result']['reachable'] === false && $pl['banner']['key'] === 'PROBE_UNREACHABLE', 'probe_host rejects a public IP (SSRF guard) as unreachable');

// ---------------------------------------------------------------------------
T::group('AJAX status: coalescing, force, DIRTY refetch');
$m = stub_fresh_module($ct);
list($st, $pl) = mt_ajax($m, 'status');
T::ok($st === 200 && $pl['ok'] === true && $pl['op'] === 'status', 'status envelope ok');
T::eq($ct->calls['getAll'], 1, 'first status: 1 getAll');
mt_ajax($m, 'status');
T::eq($ct->calls['getAll'], 1, 'second status within 5 s: served from cache (still 1 getAll)');
T::eq($ct->calls['getState'], 0, 'status never uses per-entity GETs');
mt_ajax($m, 'status', array('force' => '1'));
T::eq($ct->calls['getAll'], 2, 'status force=1 refetches');
mt_ajax($m, 'enable_automation', array(), 'POST');
mt_ajax($m, 'status');
T::eq($ct->calls['getAll'], 3, 'after a mutating op the next status refetches (DIRTY)');
mt_ajax($m, 'status');
T::eq($ct->calls['getAll'], 3, 'DIRTY cleared by the refetch');
foreach (array('pairing', 'diag') as $op) {
    $before = $ct->calls['getAll'];
    mt_ajax($m, $op);
    T::eq($ct->calls['getAll'], $before, "$op within 5 s of a fetch: served from cache");
}
$before = $ct->calls['getAll'];
mt_ajax($m, 'ble_scan');
T::eq($ct->calls['getAll'], $before + 1, 'ble_scan always forces ONE fresh fetch');
$ct->resetCounts();
mt_ajax($m, 'log');
mt_ajax($m, 'policy');
T::eq($ct->total(), 0, 'log + policy ops: DB only, 0 transport calls');

// ---------------------------------------------------------------------------
T::group('AJAX status: data contract');
$m = stub_fresh_module($ct);
list($st, $pl) = mt_ajax($m, 'status');
$d = $pl['data'];
foreach (array('temp_upper_f', 'temp_lower_f', 'heating_f', 'hdo_f', 'energy_f', 'cooldown_f', 'soc_f', 'power_source',
    'power_human', 'power_raw', 'tariff_active', 'guard_state', 'decision_action', 'decision_reason', 'decision_human',
    'decision_target_f', 'http_available', 'boiler_state', 'conn') as $k) {
    T::ok(array_key_exists($k, $d), "status.data has '$k'");
}
T::eq($d['temp_upper_f'], '55.5 °C', 'temp_upper_f formatted');
T::eq($d['http_available'], true, 'http_available true');
T::eq($d['conn'], 'ok', 'conn=ok when Connected');
foreach (array('has_data', 'fetched_utc', 'age_s', 'stale', 'fetch_ok', 'source') as $k) {
    T::ok(array_key_exists($k, $pl['meta']), "meta has '$k'");
}
T::eq($pl['meta']['fetch_ok'], true, 'meta.fetch_ok true');
T::eq($pl['meta']['stale'], false, 'meta.stale false after a fresh fetch');
T::eq($pl['meta']['source'], 'state', 'meta.source=state');

$m = stub_fresh_module($ct, array(), array('text_sensor|Boiler State' => array('Require PIN', 'Require PIN')));
list($st, $pl) = mt_ajax($m, 'status');
T::eq($pl['data']['conn'], 'pin', 'conn=pin when the boiler requires a PIN');

$m = stub_fresh_module($ct, array(), array('sensor|Boiler Cooldown Remaining' => null, 'sensor|Boiler Temp Upper' => null));
list($st, $pl) = mt_ajax($m, 'status');
T::eq($pl['data']['cooldown_f'], 'no data', 'missing cooldown shows "no data", never "0 с"');
T::eq($pl['data']['temp_upper_f'], 'no data', 'missing temperature shows "no data", never 0');

// ---------------------------------------------------------------------------
T::group('AJAX: ESP down keeps old data, marks stale + last fetch failed');
$m = stub_fresh_module($ct);
mt_ajax($m, 'status');
$ct->down = true;
list($st, $pl) = mt_ajax($m, 'status', array('force' => '1'));
T::ok($st === 200 && $pl['ok'] === true, 'ESP down is NOT an HTTP error: ok:true');
T::eq($pl['meta']['fetch_ok'], false, 'meta.fetch_ok=false');
T::eq($pl['meta']['stale'], true, 'meta.stale=true (last attempt failed)');
T::eq($pl['meta']['has_data'], true, 'old data kept');
T::eq($pl['data']['temp_upper_f'], '55.5 °C', 'old temperature still served');
T::eq($pl['data']['conn'], 'no', 'conn=no while stale/unreachable');
T::eq($pl['data']['http_available'], false, 'http_available=false while stale');
T::eq($pl['meta']['fetch_error']['stage'], 'transport', 'meta.fetch_error.stage=transport');
$ct->down = false;
list($st, $pl) = mt_ajax($m, 'status');
T::eq($pl['meta']['fetch_ok'], false, 'a failure inside the 5 s window is not retried instantly (no hammering a down ESP)');
list($st, $pl) = mt_ajax($m, 'status', array('force' => '1'));
T::eq($pl['meta']['fetch_ok'], true, 'force=1 recovers');
T::eq($pl['meta']['stale'], false, 'recovered: not stale');

$m = stub_fresh_module($ct);
$ct->down = true;
list($st, $pl) = mt_ajax($m, 'status');
T::ok($st === 200 && $pl['meta']['has_data'] === false && $pl['meta']['fetch_ok'] === false, 'cold cache + ESP down: ok:true, has_data=false');
T::eq($pl['data']['temp_upper_f'], 'no data', 'cold cache + ESP down: "no data" (never 0)');

// ---------------------------------------------------------------------------
T::group('send_pin: single POST, readback, HTTP-error vs unreachable, no PIN digits stored');
$m = stub_fresh_module($ct);
list($st, $pl) = mt_ajax($m, 'send_pin', array('pin' => '0692', 'pin_render_cc' => '5'), 'POST');
T::ok($st === 200 && $pl['result']['accepted'] === true && $pl['banner']['key'] === 'SETUP_PIN_ACCEPTED', 'PIN accepted -> banner SETUP_PIN_ACCEPTED');
T::eq($ct->calls['postSet'], 1, 'exactly ONE pairing POST (no retry inside the boiler PIN window)');
T::eq($pl['result']['stale'], false, 'same connect count -> not stale');
$rows = stub_table_rows('drazice_commands');
$last = end($rows);
T::eq($last['PAYLOAD'], '****', 'command log stores ****, not the PIN');
T::eq(strpos(json_encode($GLOBALS['__tbl']), '0692'), false, 'PIN digits appear nowhere in the stored rows');
T::eq(strpos(json_encode($pl), '0692'), false, 'PIN digits not echoed in the JSON');
list($st, $pl) = mt_ajax($m, 'send_pin', array('pin' => '0692', 'pin_render_cc' => '2'), 'POST');
T::eq($pl['result']['stale'], true, 'old rendered connect count -> stale warning flag');
list($st, $pl) = mt_ajax($m, 'send_pin', array('pin' => 'abc'), 'POST');
T::ok($st === 400 && $pl['error'] === 'bad_input' && $pl['reason'] === 'bad_pin' && $pl['banner']['key'] === 'SETUP_PIN_BAD', 'bad PIN -> 400 bad_input, SETUP_PIN_BAD');

$m = stub_fresh_module($ct);
$ct->postFailHttp = 404;
list($st, $pl) = mt_ajax($m, 'send_pin', array('pin' => '0692'), 'POST');
T::ok($st === 200 && $pl['result']['ok'] === false && $pl['result']['reason'] === 'http_error' && $pl['result']['http_code'] === 404,
    'ESP answered 404 -> reason http_error + http_code 404');
T::eq($pl['banner']['key'], 'SETUP_PIN_HTTP_ERR', 'ESP 404 gets its own banner (NOT "ESP unreachable")');
T::eq($ct->calls['postSet'], 1, 'failed PIN POST is not retried');

$m = stub_fresh_module($ct);
$ct->down = true;
list($st, $pl) = mt_ajax($m, 'send_pin', array('pin' => '0692'), 'POST');
T::ok($pl['result']['reason'] === 'transport' && $pl['banner']['key'] === 'SETUP_PIN_ESP_DOWN', 'unreachable ESP -> transport + SETUP_PIN_ESP_DOWN');

// legacy shell flags
$m = stub_fresh_module($ct);
$ct->postFailHttp = 404;
$_REQUEST = array('op' => 'send_pin', 'pin' => '0692', 'csrf' => 'tok', 'tab' => 'setup');
$m->tab = 'setup';
$out = array();
$m->admin($out);
T::ok($out['PIN_HTTP_ERR'] === 1 && $out['PIN_ESP_DOWN'] === 0 && $out['PIN_HTTP_CODE'] === 404, 'legacy shell: ESP 404 sets PIN_HTTP_ERR, not PIN_ESP_DOWN');
$_REQUEST = array();

// ---------------------------------------------------------------------------
T::group('pairing / diag / ble_scan / log / policy data contracts');
$m = stub_fresh_module($ct);
list($st, $pl) = mt_ajax($m, 'pairing');
$p = $pl['data'];
foreach (array('boiler_state', 'require_pin', 'connect_count', 'pair_attempts', 'trace_raw', 'trace_tail',
    'last_disconnect_raw', 'last_disconnect', 'has_trace') as $k) {
    T::ok(array_key_exists($k, $p), "pairing.data has '$k'");
}
T::eq($p['connect_count'], '5', 'connect_count digits-only string');
T::eq($p['require_pin'], false, 'require_pin false when Connected');
T::eq($p['has_trace'], true, 'has_trace true');
T::eq($p['trace_tail'], array('REQ_PIN@3', 'CONN@2', 'TAG@1'), 'trace_tail = last events, newest first');
T::eq($p['last_disconnect']['reason'], '19', 'last_disconnect parsed (reason=19)');
list($st, $pl) = mt_ajax($m, 'diag');
T::eq(count($pl['data']['dg']), 22, 'diag.data.dg has the 22 DG keys (incl. BOILER_SETPOINT, BOILER_MODE)');
T::ok(array_key_exists('BOILER_SETPOINT', $pl['data']['dg']) && array_key_exists('BOILER_MODE', $pl['data']['dg']), 'diag.dg exposes actual boiler setpoint + mode');
T::eq($pl['data']['dg']['BOILER_STATE'], 'Connected', 'diag.dg.BOILER_STATE');
T::eq($pl['data']['dg']['UPTIME'], 12345.0, 'diag.dg.UPTIME numeric');
T::ok(array_key_exists('pair', $pl['data']), 'diag.data.pair present');
list($st, $pl) = mt_ajax($m, 'ble_scan');
T::eq($pl['data']['available'], true, 'ble_scan.available');
T::eq($pl['data']['list'][0]['mac'], 'AA:BB:CC:DD:EE:01', 'ble_scan: boilers first');
T::eq($pl['data']['list'][0]['boiler'], true, 'ble_scan: boiler flag bool');
list($st, $pl) = mt_ajax($m, 'log');
T::ok(is_array($pl['data']['decisions']) && is_array($pl['data']['commands']), 'log op returns decisions + commands arrays');
list($st, $pl) = mt_ajax($m, 'policy');
T::ok(array_key_exists('forecast', $pl['data']), 'policy op returns forecast');

// ---------------------------------------------------------------------------
T::group('No secret in any AJAX payload');
$SECRET = 'S3cretPassw0rd-Z9';
$m = stub_fresh_module($ct, array('ESP_PASS' => $SECRET, 'ESP_USER' => 'boiler'));
$all = array();
foreach (DraziceSmart\AjaxApi::READ_OPS as $op) {
    list($st, $pl) = mt_ajax($m, $op);
    $all[$op] = json_encode($pl);
}
list($st, $pl) = mt_ajax($m, 'save_settings', array('ESP_PASS' => 'N3wPassw0rd-Q7', 'COMFORT_MIN_C' => 'abc', 'ESP_PORT' => '80'), 'POST');
$all['save_settings'] = json_encode($pl);
T::eq($pl['result']['errors'][0]['field'], 'COMFORT_MIN_C', 'save_settings reports the rejected numeric field');
T::eq($pl['result']['errors'][0]['reason'], 'num', 'save_settings reason=num');
T::eq($pl['result']['rejected']['COMFORT_MIN_C'], 'abc', 'save_settings returns the rejected raw input');
T::eq($pl['banner']['key'], 'SAVE_FAIL', 'save_settings with errors -> SAVE_FAIL banner');
list($st, $pl) = mt_ajax($m, 'save_settings', array('COMFORT_MIN_C' => '46'), 'POST');
T::eq($pl['banner']['key'], 'SAVE_OK', 'save_settings ok -> SAVE_OK banner');
T::eq($GLOBALS['__cfg']['COMFORT_MIN_C'], '46', 'save_settings persisted');
foreach (array('send_pin' => array('pin' => '0692'), 'probe_host' => array('host' => '172.16.0.150'),
    'lease_renew' => array(), 'request_target' => array('value' => '55')) as $op => $x) {
    list($st, $pl) = mt_ajax($m, $op, $x, 'POST');
    $all[$op] = json_encode($pl);
}
foreach ($all as $op => $json) {
    T::eq(strpos($json, $SECRET), false, "$op payload does not contain the stored ESP_PASS");
    T::eq(strpos($json, 'N3wPassw0rd-Q7'), false, "$op payload does not echo a submitted password");
    T::ok(json_decode($json, true) !== null, "$op payload is valid JSON");
}
$out = mt_render_admin($m, 'setup');
T::eq($out['CFG_ESP_PASS'], '********', 'shell masks ESP_PASS');
T::eq(strpos(json_encode($out), $SECRET), false, 'shell vars never contain ESP_PASS');
T::eq(strpos($out['JS_CONFIG'] . $out['JS_I18N'], $SECRET), false, 'JS_CONFIG/JS_I18N never contain ESP_PASS');

// ---------------------------------------------------------------------------
T::group('Hostile ESP text: escaped in the HTML shell, plain string in JSON');
$evil = '<script>alert(1)</script>"\'&';
$m = stub_fresh_module($ct, array(), array(
    'text_sensor|Boiler Guard State' => array($evil, $evil),
    'text_sensor|Boiler Guard Reason' => array($evil, $evil),
));
$m->refreshState(true);
$ct->resetCounts();
$out = mt_render_admin($m, 'diag');
T::eq($ct->total(), 0, 'hostile render: 0 transport calls');
T::eq(stripos($out['GUARD_STATE'], '<script'), false, 'shell GUARD_STATE has no raw <script');
T::eq(stripos($out['DG_GUARD_STATE'], '<script'), false, 'shell DG_GUARD_STATE has no raw <script');
T::eq(stripos($out['DG_GUARD_REASON'], '<script'), false, 'shell DG_GUARD_REASON has no raw <script');
T::ok(strpos($out['GUARD_STATE'], '&lt;script&gt;') !== false, 'shell GUARD_STATE is HTML-escaped');
list($st, $pl) = mt_ajax($m, 'status');
T::eq($pl['data']['guard_state'], $evil, 'JSON carries the exact string as DATA (client uses textContent)');
T::ok(json_decode(json_encode($pl), true)['data']['guard_state'] === $evil, 'JSON round-trips the hostile string');
list($st, $pl) = mt_ajax($m, 'diag');
T::eq($pl['data']['dg']['GUARD_STATE'], $evil, 'diag JSON carries the exact string');

// ---------------------------------------------------------------------------
T::group('StateCache never stores the PIN entity');
$m = stub_fresh_module($ct, array(), array('number|Boiler Pairing PIN' => array(692.0, '692')));
$m->refreshState(true);
$row = StateCache::load();
T::ok($row !== null && strpos($row['PAYLOAD'], 'Boiler Pairing PIN') === false, 'cached payload has no PIN entity');
T::ok(strpos($row['PAYLOAD'], 'Boiler Temp Upper') !== false, 'cached payload has the other entities');

// ---------------------------------------------------------------------------
T::group('Review pass 2 fixes: markDirty, anti-stampede, INVALID_VALUE banners');

$m = stub_fresh_module($ct);
$m->refreshState(true);
$before = StateCache::load();
StateCache::markDirty();
$after = StateCache::load();
T::eq((int) $after['DIRTY'], 1, 'markDirty sets DIRTY=1');
T::eq($after['PAYLOAD'], $before['PAYLOAD'], 'markDirty leaves PAYLOAD untouched');
T::eq($after['FETCHED_UTC'], $before['FETCHED_UTC'], 'markDirty leaves FETCHED_UTC untouched');
T::eq($after['ATTEMPT_UTC'], $before['ATTEMPT_UTC'], 'markDirty leaves ATTEMPT_UTC untouched');

// Anti-stampede: another request holds the fetch lock for > 3 s.
$m = stub_fresh_module($ct);
$m->refreshState(true);
$ct->resetCounts();
$lockFh = fopen(sys_get_temp_dir() . '/drazice_state.lock', 'c');
T::ok($lockFh && flock($lockFh, LOCK_EX | LOCK_NB), 'test holds the state fetch lock');
$r = $m->refreshState(true);
T::eq($ct->calls['getAll'], 0, 'forced read (pairing poll) while the lock is taken: NO getAll, cache served');
T::ok(isset($r['map']) && !empty($r['map']), 'forced read under lock still returns the cached map');
$r = $m->refreshState(true, true);
T::eq($ct->calls['getAll'], 1, 'cycle/planner fetch (mustFetch) still goes to the ESP under lock');
flock($lockFh, LOCK_UN);
fclose($lockFh);
$ct->resetCounts();
$m->refreshState(true);
T::eq($ct->calls['getAll'], 1, 'forced read with a free lock fetches once');

// Bad-input banners carry a real i18n key (never null -> FETCH_ERROR).
$m = stub_fresh_module($ct);
$cases = array(
    array('request_target', array('value' => '999')),
    array('request_mode', array('value' => 'bogus_mode')),
    array('select_boiler', array('mac' => 'not-a-mac')),
);
$keys = DraziceSmart\AjaxApi::i18nKeys();
foreach ($cases as $c) {
    list($st, $pl) = mt_ajax($m, $c[0], $c[1], 'POST', true);
    T::ok($st === 400 && $pl['error'] === 'bad_input', $c[0] . ': bad input -> 400 bad_input');
    T::eq(isset($pl['banner']['key']) ? $pl['banner']['key'] : null, 'INVALID_VALUE', $c[0] . ': banner key is INVALID_VALUE');
}
T::ok(in_array('INVALID_VALUE', $keys, true) && in_array('BLE_MAC', $keys, true)
    && in_array('BLE_RSSI', $keys, true) && in_array('UNIT_DBM', $keys, true), 'i18nKeys has INVALID_VALUE/BLE_MAC/BLE_RSSI/UNIT_DBM');
$js = file_get_contents(dirname(__DIR__, 2) . '/modules/drazice_smart/js/drazice_smart.js');
T::ok(strpos($js, "'MAC'") === false && strpos($js, "'RSSI'") === false && strpos($js, "' dBm'") === false, 'renderBle: no hard-coded MAC/RSSI/dBm in JS');
T::ok(strpos($js, 'pinBaseline') !== false && strpos($js, "'focusin'") !== false, 'JS freezes the PIN connect_count baseline on focus');
