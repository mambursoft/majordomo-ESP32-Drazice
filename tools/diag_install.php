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
    public $tab;

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

// ---- Headless admin-tab render check ---------------------------------------
// The real MajorDoMo jTemplate engine is not vendored in this repo, so this is
// an approximation: it loads the LANG_DS_* constants (every language file of
// both language sets) and checks that admin() produces output for all 7 tabs
// without a fatal, then scans the raw template source for <#LANG_DS_...#>
// tokens that have no matching defined constant in ANY language file. It does
// NOT execute the [#if#]/[#begin#] control flow, so it cannot prove there are
// no leaked [#...#] tags at runtime; that still needs a real MajorDoMo page
// load. Labelled honestly as "approximate" in the output.
echo "\n-- headless admin-tab checks (approximate; real jTemplate not available offline) --\n";

$langSets = array(
    'modules/drazice_smart/languages' => dirname(__DIR__) . '/majordomo/modules/drazice_smart/languages',
    'languages'                       => dirname(__DIR__) . '/majordomo/languages',
);
$definedByFile = array();
foreach ($langSets as $label => $dir) {
    foreach (glob($dir . '/drazice_smart_*.php') as $f) {
        $before = get_defined_constants();
        require $f;
        $after = get_defined_constants();
        $new = array_diff_key($after, $before);
        $definedByFile[$label . '/' . basename($f)] = array_keys($new);
    }
}
$anyDefined = array();
foreach ($definedByFile as $file => $consts) {
    foreach ($consts as $c) {
        $anyDefined[$c] = true;
    }
}

$tplPath = dirname(__DIR__) . '/majordomo/modules/drazice_smart/templates/action_admin.html';
$tpl = file_get_contents($tplPath);
preg_match_all('/<#(LANG_DS_[A-Z0-9_]+)#>/', $tpl, $langMatches);
$usedLangKeys = array_unique($langMatches[1]);
$missing = array();
foreach ($usedLangKeys as $k) {
    if (!isset($anyDefined[$k])) {
        $missing[] = $k;
    }
}
echo "Template LANG_DS_* tokens used: " . count($usedLangKeys) . "\n";
if (empty($missing)) {
    echo "OK: every <#LANG_DS_...#> token used by action_admin.html is defined in at least one language file.\n";
} else {
    echo "MISSING LANG_DS_* constants (used in template, not defined anywhere):\n";
    foreach ($missing as $k) {
        echo "  - $k\n";
    }
}

// Render admin() for every tab to confirm no PHP fatal while building $out.
foreach (array('status', 'setup', 'control', 'policy', 'log', 'diag', 'advanced') as $tab) {
    $_REQUEST = array('tab' => $tab);
    $out = array();
    $m->tab = $tab;
    $m->admin($out);
    echo "admin() tab=$tab: OK (" . count($out) . " template vars)\n";
}
echo "OK: no fatal (admin tabs)\n";
