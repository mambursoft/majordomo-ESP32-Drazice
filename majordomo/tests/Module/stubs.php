<?php
/**
 * MajorDoMo runtime stubs for MODULE-level tests (tests/run_module.php) and
 * tools/measure_calls.php.
 *
 * Kept OUT of tests/Unit on purpose: the global SQL* function stubs must never
 * leak into the pure-library Unit run (tests/run.php).
 *
 * Provides:
 *   - SQLSelectOne/SQLSelect/SQLInsert/SQLUpdate/SQLExec with an IN-MEMORY
 *     table store ($GLOBALS['__tbl']) so rows written to drazice_state_cache
 *     can be read back; "SHOW TABLES LIKE 'drazice_*'" reports every module
 *     table as existing;
 *   - getGlobal/setGlobal/getObject/DBSafe and the base `module`/`parser` classes;
 *   - CountingTransport: a DraziceSmart\Transport that never touches the
 *     network, counts calls per method and serves a canned entity map.
 *
 * PHP 7.3 compatible.
 */

error_reporting(E_ALL);

if (!defined('ROOT')) define('ROOT', sys_get_temp_dir() . '/mdm_fake_mod/');
if (!is_dir(ROOT)) @mkdir(ROOT, 0777, true);
if (!is_dir(ROOT . 'cms/modules_installed')) @mkdir(ROOT . 'cms/modules_installed', 0777, true);
if (!defined('DIR_MODULES')) define('DIR_MODULES', dirname(dirname(__DIR__)) . '/modules/');
if (!defined('DIR_TEMPLATES')) define('DIR_TEMPLATES', dirname(dirname(__DIR__)) . '/templates/');

require_once(dirname(dirname(__DIR__)) . '/modules/drazice_smart/lib/autoload.php');

$GLOBALS['__tbl'] = array();       // table => (id => row)
$GLOBALS['__cfg'] = array();       // persisted module config
$GLOBALS['__dbinstall_data'] = '';
$GLOBALS['__props'] = array();     // MajorDoMo "Object.Property" values

function stub_reset_db()
{
    $GLOBALS['__tbl'] = array();
}

function stub_table_rows($table)
{
    return isset($GLOBALS['__tbl'][$table]) ? $GLOBALS['__tbl'][$table] : array();
}

function SQLSelectOne($q)
{
    if (preg_match("/SHOW TABLES LIKE '([A-Za-z0-9_]+)'/", $q, $m)) {
        return (strpos($m[1], 'drazice_') === 0) ? array('t' => $m[1]) : array();
    }
    if (preg_match('/FROM\s+drazice_state_cache/i', $q)) {
        $rows = stub_table_rows('drazice_state_cache');
        if (empty($rows)) {
            return array();
        }
        ksort($rows);
        return reset($rows);
    }
    return array();
}
function SQLSelect($q) { return array(); }
function SQLInsert($t, &$rec)
{
    if (!isset($GLOBALS['__tbl'][$t])) {
        $GLOBALS['__tbl'][$t] = array();
    }
    $id = count($GLOBALS['__tbl'][$t]) + 1;
    $rec['ID'] = $id;
    $GLOBALS['__tbl'][$t][$id] = $rec;
    return $id;
}
function SQLUpdate($t, $rec)
{
    if (!isset($rec['ID'])) {
        return 0;
    }
    // Like the real SQLUpdate: only the fields present in $rec are written.
    $old = isset($GLOBALS['__tbl'][$t][$rec['ID']]) ? $GLOBALS['__tbl'][$t][$rec['ID']] : array();
    $GLOBALS['__tbl'][$t][$rec['ID']] = array_merge($old, $rec);
    return 1;
}
function SQLExec($q) { return 1; }
function SQLGetFields($t) { return array(); }
function SQLGetIndexes($t) { return array(); }
function isModuleInstalled($n) { return false; }
function getGlobal($p) { return isset($GLOBALS['__props'][$p]) ? $GLOBALS['__props'][$p] : ''; }
function setGlobal($p, $v) { $GLOBALS['__props'][$p] = $v; return true; }
function getObject($o) { return false; }
function DBSafe($s) { return addslashes($s); }
function addClassObject($c, $t, $d) { return 1; }

class module
{
    public $name;
    public $title;
    public $config = array();
    public $data;
    public $result;
    public $action = '';
    public $owner;
    public $tab;

    public function saveParams($data = 1) { return true; }
    public function getParams() {}
    public function checkInstalled() { if (!isModuleInstalled($this->name)) { $this->install(); } }
    public function getConfig() { $this->config = $GLOBALS['__cfg']; return $this->config; }
    public function saveConfig() { $GLOBALS['__cfg'] = $this->config; return true; }
    public function install($parent_name = "") { $this->dbInstall(""); }
    public function dbInstall($data) { $GLOBALS['__dbinstall_data'] = $data; return true; }
}

class parser
{
    public $result = '';
    public function __construct($tpl, $data, $owner) { $this->result = 'OK'; }
}

/**
 * Fresh module instance on an empty DB with a CountingTransport injected.
 * CSRF token for ajaxDispatch/checkCsrf is the fixed string 'tok'.
 */
function stub_fresh_module(&$ct, $cfg = array(), $overrides = array())
{
    stub_reset_db();
    $GLOBALS['__cfg'] = array();
    $GLOBALS['__props'] = array();
    $GLOBALS['drazice_csrf_fallback'] = 'tok';
    $m = new drazice_smart();
    $m->install();
    $m->getConfig();
    foreach ($cfg as $k => $v) {
        $GLOBALS['__cfg'][$k] = $v;
    }
    $GLOBALS['__cfg']['ESP_HOST'] = '172.16.0.150';
    $m->getConfig();
    $ct = new CountingTransport($overrides);
    $m->setTransport($ct);
    return $m;
}

/**
 * Network-free Transport that counts calls. Canned entity map = the real
 * entity names used by the module (display names, see Transport::LEGACY_ENTITIES).
 */
class CountingTransport extends DraziceSmart\Transport
{
    public $calls = array('getState' => 0, 'getAll' => 0, 'postSet' => 0, 'pressButton' => 0);
    public $log = array();
    /** When true every read/write fails like an unreachable ESP (returns null). */
    public $down = false;
    /** When > 0 postSet() fails with this HTTP status (reads keep working). */
    public $postFailHttp = 0;
    /** @var array 'domain|name' => array(value, state) overrides/additions */
    public $entities;

    public function __construct($overrides = array())
    {
        parent::__construct(array('host' => '172.16.0.150', 'port' => 80));
        $this->entities = self::canned();
        foreach ($overrides as $k => $v) {
            $this->entities[$k] = $v;
        }
    }

    public static function canned()
    {
        $ble = json_encode(array(
            array('mac' => 'AA:BB:CC:DD:EE:01', 'name' => 'OKHE SMART', 'boiler' => true, 'rssi' => -60),
            array('mac' => 'AA:BB:CC:DD:EE:02', 'name' => 'Phone', 'boiler' => false, 'rssi' => -50),
        ));
        return array(
            'sensor|Boiler Temp Upper'             => array(55.5, '55.5'),
            'sensor|Boiler Temp Lower'             => array(41.0, '41'),
            'binary_sensor|Boiler Heating'         => array(true, 'ON'),
            'binary_sensor|Boiler HDO'             => array(false, 'OFF'),
            'sensor|Boiler Energy'                 => array(123.4, '123.4'),
            'sensor|Boiler Cooldown Remaining'     => array(0.0, '0'),
            'text_sensor|Boiler Guard State'       => array('ok', 'ok'),
            'sensor|ESP Uptime'                    => array(12345.0, '12345'),
            'sensor|ESP Wi-Fi RSSI'                => array(-61.0, '-61'),
            'sensor|ESP Free Heap'                 => array(21000.0, '21000'),
            'sensor|ESP Min Free Heap'             => array(15000.0, '15000'),
            'text_sensor|ESP Reset Reason'         => array('Power On', 'Power On'),
            'text_sensor|ESPHome Version'          => array('2025.9.3', '2025.9.3'),
            'text_sensor|Boiler Guard Reason'      => array('none', 'none'),
            'sensor|Boiler Continuous Heat Seconds' => array(100.0, '100'),
            'sensor|Boiler Lease Remaining'        => array(150.0, '150'),
            'text_sensor|Boiler Version'           => array('1.2.3', '1.2.3'),
            'text_sensor|Boiler Name'              => array('OKHE', 'OKHE'),
            'text_sensor|Boiler State'             => array('Connected', 'Connected'),
            'number|Boiler Requested Target'       => array(55.0, '55'),
            'select|Boiler Requested Mode'         => array('SMART', 'SMART'),
            'text_sensor|Boiler Pair Trace'        => array('TAG@1 | CONN@2 | REQ_PIN@3', 'TAG@1 | CONN@2 | REQ_PIN@3'),
            'text_sensor|Boiler Last Disconnect'   => array('reason=19 state=NEED_PIN since_pin_tx_ms=-', 'reason=19 state=NEED_PIN since_pin_tx_ms=-'),
            'sensor|Boiler Pair Attempts'          => array(3.0, '3'),
            'sensor|Boiler Connect Count'          => array(5.0, '5'),
            'text_sensor|BLE Scan Results'         => array($ble, $ble),
        );
    }

    private function entityJson($key)
    {
        if (!array_key_exists($key, $this->entities)) {
            return null;
        }
        $e = $this->entities[$key];
        if ($e === null) {
            return array('value' => null, 'state' => 'NA', '_http' => 200);
        }
        return array('value' => $e[0], 'state' => $e[1], '_http' => 200);
    }

    public function getState($domain, $name)
    {
        $this->calls['getState']++;
        $this->log[] = 'GET ' . $domain . '/' . $name;
        if ($this->down) {
            return null;
        }
        return $this->entityJson($domain . '|' . $name);
    }

    /** Same shape as Transport::parseAll output: domain => name => array(value,state). */
    public function getAll()
    {
        $this->calls['getAll']++;
        $this->log[] = 'GET /state';
        if ($this->down) {
            return null;
        }
        $map = array();
        foreach ($this->entities as $key => $e) {
            list($d, $n) = explode('|', $key, 2);
            $j = $this->entityJson($key);
            $map[$d][$n] = array('value' => $j['value'], 'state' => $j['state']);
        }
        return $map;
    }

    public function getLastSource()
    {
        return 'state';
    }

    public function postSet($domain, $name, $value)
    {
        $this->calls['postSet']++;
        $this->log[] = 'POST ' . $domain . '/' . $name . '/set';
        if ($this->postFailHttp) {
            return null; // the ESP ANSWERED with an HTTP error (e.g. 404)
        }
        return $this->down ? null : array('_http' => 200, '_empty' => true);
    }

    public function pressButton($name)
    {
        $this->calls['pressButton']++;
        $this->log[] = 'POST button/' . $name . '/press';
        return $this->down ? null : array('_http' => 200, '_empty' => true);
    }

    public function getLastError()
    {
        if ($this->down) {
            return array('stage' => 'transport', 'reason' => 'curl_errno_7');
        }
        if ($this->postFailHttp) {
            return array('stage' => 'http', 'reason' => 'http_' . $this->postFailHttp, 'http_code' => $this->postFailHttp);
        }
        return array();
    }

    public function total()
    {
        return $this->calls['getState'] + $this->calls['getAll'] + $this->calls['postSet'] + $this->calls['pressButton'];
    }

    public function resetCounts()
    {
        $this->calls = array('getState' => 0, 'getAll' => 0, 'postSet' => 0, 'pressButton' => 0);
        $this->log = array();
    }
}
