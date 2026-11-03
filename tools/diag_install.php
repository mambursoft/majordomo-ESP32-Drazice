<?php
/**
 * Local diagnostic harness: emulate a MINIMAL MajorDoMo runtime (stub the
 * functions and the base `module` class) and run the module's install() + a
 * few lifecycle calls, to reproduce a 500 (PHP fatal) off-server.
 *
 * NOT part of the shipped package. Run: php tools/diag_install.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// ---- Minimal MajorDoMo function stubs --------------------------------------
$GLOBALS['__db'] = array('project_modules' => array(), 'plugins' => array());

if (!defined('ROOT')) define('ROOT', sys_get_temp_dir() . '/mdm_fake/');
if (!is_dir(ROOT)) @mkdir(ROOT, 0777, true);
if (!is_dir(ROOT . 'cms/modules_installed')) @mkdir(ROOT . 'cms/modules_installed', 0777, true);
if (!defined('DIR_MODULES')) define('DIR_MODULES', dirname(__DIR__) . '/majordomo/modules/');
if (!defined('DIR_TEMPLATES')) define('DIR_TEMPLATES', dirname(__DIR__) . '/majordomo/templates/');

function SQLSelectOne($q) {
    // Return array with no ID so "not installed" branches run, but a Field for SHOW COLUMNS.
    if (stripos($q, 'SHOW TABLES') !== false) return array();     // no plugins table
    if (stripos($q, 'SHOW COLUMNS') !== false) return array();
    return array();
}
function SQLSelect($q) { return array(); }
function SQLInsert($t, &$rec) { $rec['ID'] = 1; return 1; }
function SQLUpdate($t, $rec) { return 1; }
function SQLExec($q) { return 1; }
function SQLGetFields($t) { return array(); }
function SQLGetIndexes($t) { return array(); }
function isModuleInstalled($n) { return false; }
function getGlobal($p) { return ''; }
function setGlobal($p, $v) { return true; }
function getObject($o) { return false; }
function DBSafe($s) { return addslashes($s); }
function addClassObject($c, $t, $d) { return 1; }

// ---- Minimal base `module` class -------------------------------------------
class module
{
    public $name;
    public $title;
    public $config = array();
    public $data;
    public $result;
    public $action = '';
    public $owner;

    public function saveParams($data = 1) { return true; }
    public function getParams() {}
    public function checkInstalled() { if (!isModuleInstalled($this->name)) { $this->install(); } }
    public function getConfig() { $this->config = is_array($this->config) ? $this->config : array(); return $this->config; }
    public function saveConfig() { return true; }
    public function install($parent_name = "") { $this->dbInstall(""); }
    public function dbInstall($data) { return true; }
}

// stub parser (used by run())
class parser {
    public $result = '';
    public function __construct($tpl, $data, $owner) { $this->result = 'OK'; }
}

// ---- Load and exercise the module ------------------------------------------
echo "Loading class...\n";
require_once(dirname(__DIR__) . '/majordomo/modules/drazice_smart/drazice_smart.class.php');

echo "Constructing...\n";
$m = new drazice_smart();

echo "install()...\n";
$m->install();

echo "getConfig()...\n";
$m->getConfig();

echo "buildSnapshot() (no ESP; should degrade gracefully)...\n";
$snap = $m->buildSnapshot();

echo "OK: no fatal. snapshot http_available=" . var_export($snap['http_available'], true) . "\n";
