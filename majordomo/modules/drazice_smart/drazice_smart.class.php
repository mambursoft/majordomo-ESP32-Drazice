<?php
/**
 * drazice_smart module for MajorDoMo.
 *
 * Monitoring and safe control of Drazice SMART water heaters via ESP32/BLE over
 * Wi-Fi. No MQTT, no mandatory Home Assistant. Deterministic, explainable
 * planner. Safe by default: starts in monitor-only (dry-run), automation off.
 *
 * @package    drazice_smart
 * @author     mambursoft
 * @copyright  2026 mambursoft
 * @license    MIT
 * @version    1.0.0
 */

require_once(dirname(__FILE__) . '/lib/autoload.php');

use DraziceSmart\Transport;
use DraziceSmart\Planner;
use DraziceSmart\PolicyEngine;
use DraziceSmart\Tariff;
use DraziceSmart\ForecastAdapter;
use DraziceSmart\Repository;
use DraziceSmart\NetworkScanner;
use DraziceSmart\Presence;
use DraziceSmart\SimpleDevices;
use DraziceSmart\BoilerObject;
use DraziceSmart\SettingsSaver;
use DraziceSmart\PairTrace;
use DraziceSmart\StateCache;
use DraziceSmart\AjaxApi;
use DraziceSmart\Explain;
use DraziceSmart\SceneBuilder;
use DraziceSmart\ClockCheck;

class drazice_smart extends module
{
    /** Must equal majordomo/modinfo.json "version" (guarded by tests/Module). */
    const MODULE_VERSION = '1.16.0';

    // Declared to avoid PHP 8.2 "dynamic property" deprecations (base class
    // declares name/title/config/action/mode/view_mode/edit_mode/tab already).
    public $module_category;
    public $id;
    public $title;

    function drazice_smart()
    {
        $this->name            = 'drazice_smart';
        $this->title           = 'Drazice SMART Boiler';
        $this->module_category = '<#LANG_SECTION_DEVICES#>';
        $this->checkInstalled();
        // Ensure DB tables exist even if the module was marked "installed" by an
        // earlier (broken) build that failed before dbInstall ran. CREATE TABLE
        // IF NOT EXISTS is cheap and idempotent, so this is safe on every load.
        $this->ensureTables();
    }

    /** True if a DB table exists. Defensive against missing-table SQL errors. */
    private function tableExists($table)
    {
        if (!function_exists('SQLSelectOne')) {
            return false;
        }
        $rec = SQLSelectOne("SHOW TABLES LIKE '" . $table . "'");
        return is_array($rec) && !empty($rec);
    }

    /** Create module tables if any is missing (idempotent). */
    private function ensureTables()
    {
        if (!function_exists('SQLSelectOne')) {
            return;
        }
        if (!$this->tableExists('drazice_decisions')
            || !$this->tableExists('drazice_telemetry')
            || !$this->tableExists('drazice_commands')
            || !$this->tableExists('drazice_presence')
            || !$this->tableExists('drazice_people')
            || !$this->tableExists('drazice_state_cache')) {
            $this->dbInstall();
        }
    }

    function __construct()
    {
        $this->drazice_smart();
    }

    /**
     * Timer cycle entry point. MajorDoMo's cycle.php calls processCycle() on
     * every registered module roughly once a minute. We self-throttle to
     * POLL_INTERVAL_S and guard against overlapping runs with a non-blocking
     * lock, then run one bounded cycleStep(). This is what makes lease renewal,
     * active hold, history, presence and the HDO override work in the
     * background instead of only when the admin page is open.
     */
    function processCycle()
    {
        $this->getConfig();

        // Self-throttle: cycle.php fires ~every minute; honour POLL_INTERVAL_S.
        $interval = isset($this->config['POLL_INTERVAL_S']) ? (int) $this->config['POLL_INTERVAL_S'] : 15;
        if ($interval < 5) {
            $interval = 5;
        }
        $last = isset($this->config['_LAST_CYCLE_UTC']) ? (int) $this->config['_LAST_CYCLE_UTC'] : 0;
        $now = time();
        if (($now - $last) < $interval) {
            return;
        }

        // Non-blocking lock so a slow cycle never overlaps the next tick.
        $lockFile = (defined('ROOT') ? ROOT : sys_get_temp_dir() . '/') . 'cms/drazice_smart.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            $lockFile = sys_get_temp_dir() . '/drazice_smart.lock';
            $lock = @fopen($lockFile, 'c');
        }
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            return; // another run in progress
        }

        try {
            $this->cycleStep();
            $this->config['_LAST_CYCLE_UTC'] = time();
            $this->saveConfig();
        } catch (\Throwable $e) {
            if (function_exists('DebMes')) {
                DebMes('drazice_smart cycle error: ' . $e->getMessage());
            }
        }

        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id))        $p['id']        = $this->id;
        if (isset($this->view_mode)) $p['view_mode'] = $this->view_mode;
        if (isset($this->edit_mode)) $p['edit_mode'] = $this->edit_mode;
        if (isset($this->tab))       $p['tab']       = $this->tab;
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $id, $mode, $view_mode, $edit_mode, $tab;
        if (isset($id))        $this->id        = $id;
        if (isset($mode))      $this->mode      = $mode;
        if (isset($view_mode)) $this->view_mode = $view_mode;
        if (isset($edit_mode)) $this->edit_mode = $edit_mode;
        // Only honour the tab from the CURRENT request (?tab=...). Do NOT restore
        // the persisted/global tab: a fresh open with no ?tab= must land on the
        // default Status tab (see run(): falls back to 'status' when unset).
        if (isset($_REQUEST['tab'])) $this->tab = $_REQUEST['tab'];
    }

    function run()
    {
        $out = array();

        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }

        $out['VIEW_MODE'] = isset($this->view_mode) ? $this->view_mode : '';
        $out['EDIT_MODE'] = isset($this->edit_mode) ? $this->edit_mode : '';
        $out['MODE']      = isset($this->mode) ? $this->mode : '';
        $out['ACTION']    = $this->action;
        $out['TAB']       = isset($this->tab) ? $this->tab : 'status';

        $this->data = $out;

        $templateFile = DIR_TEMPLATES . $this->name . '/' . $this->name . '.html';
        if (!file_exists($templateFile)) {
            $templateFile = DIR_MODULES . $this->name . '/templates/' . $this->name . '.html';
        }
        if (!file_exists($templateFile) && defined('ROOT')) {
            $templateFile = ROOT . 'modules/' . $this->name . '/templates/' . $this->name . '.html';
        }
        $p = new parser($templateFile, $this->data, $this);
        $this->result = $p->result;
    }

    function checkInstalled()
    {
        // NOTE: do NOT call parent::checkInstalled() here. The base method calls
        // $this->install() when the module is not yet installed, and install()
        // calls checkInstalled() again -> infinite recursion -> memory-exhaust
        // fatal (HTTP 500) during package installation. We perform the full
        // registration ourselves below, and install() is invoked directly by
        // the package installer.
        $rec = SQLSelectOne("SELECT ID, CATEGORY FROM project_modules WHERE NAME='" . $this->name . "'");
        if (!is_array($rec) || empty($rec['ID'])) {
            // NB: SQLInsert takes $record BY REFERENCE — must pass a variable,
            // not an array literal (a literal is a fatal "cannot be passed by
            // reference" error on PHP 7+ => HTTP 500).
            $newRec = array(
                'NAME'     => $this->name,
                'TITLE'    => $this->title,
                'CATEGORY' => $this->module_category,
                'DATA'     => '',
            );
            SQLInsert('project_modules', $newRec);
        } elseif (isset($rec['CATEGORY']) && $rec['CATEGORY'] != $this->module_category) {
            $rec['CATEGORY'] = $this->module_category;
            SQLUpdate('project_modules', $rec);
        }

        if (defined('ROOT') && is_dir(ROOT . 'cms/modules_installed')) {
            $flagFile = ROOT . 'cms/modules_installed/' . $this->name . '.installed';
            if (!file_exists($flagFile)) {
                @file_put_contents($flagFile, date("H:i d.m.Y"));
            }
        }

        // Provision the module icon into /img/modules/ for the UI header.
        if (defined('ROOT')) {
            $dstDir = ROOT . 'img/modules/';
            if (!is_dir($dstDir)) {
                @mkdir($dstDir, 0775, true);
            }
            $srcIconAlt = ROOT . 'modules/' . $this->name . '/img/' . $this->name . '.png';
            $dstIcon = $dstDir . $this->name . '.png';
            if (!file_exists($dstIcon) && file_exists($srcIconAlt)) {
                @copy($srcIconAlt, $dstIcon);
            }
        }

        // Register in plugins table for Marketplace & Installed extensions.
        $hasPlugins = SQLSelectOne("SHOW TABLES LIKE 'plugins'");
        if ($hasPlugins) {
            $col = SQLSelectOne("SHOW COLUMNS FROM plugins LIKE 'MODULE_NAME'");
            $keyField = ($col && isset($col['Field'])) ? 'MODULE_NAME' : 'NAME';
            $pRec = SQLSelectOne("SELECT * FROM plugins WHERE " . $keyField . "='" . $this->name . "'");
            if (!is_array($pRec)) {
                $pRec = array();
            }
            $descRu = 'Мониторинг и безопасное управление бойлерами Drazice SMART через ESP32/BLE. Без MQTT и без обязательного Home Assistant. Безопасно по умолчанию.';
            $descEn = 'Monitoring and safe control of Drazice SMART boilers via ESP32/BLE. No MQTT, no mandatory Home Assistant. Safe by default.';
            if (empty($pRec['ID'])) {
                // SQLInsert takes $record by reference — pass a variable.
                $plRec = array(
                    $keyField         => $this->name,
                    'TITLE'           => $this->title,
                    'AUTHOR'          => 'mambursoft',
                    'SUPPORT_URL'     => 'https://github.com/mambursoft/majordomo-ESP32-Drazice',
                    'REPOSITORY_URL'  => 'https://github.com/mambursoft/majordomo-ESP32-Drazice',
                    'DESCRIPTION_RU'  => $descRu,
                    'DESCRIPTION_EN'  => $descEn,
                    'CURRENT_VERSION' => '1.3.1',
                    'LATEST_VERSION'  => '1.3.1',
                    'IS_INSTALLED'    => 1,
                    'LATEST_UPDATE'   => date('Y-m-d H:i:s'),
                );
                SQLInsert('plugins', $plRec);
            } else {
                $pRec['TITLE']           = $this->title;
                $pRec['AUTHOR']          = 'mambursoft';
                $pRec['DESCRIPTION_RU']  = $descRu;
                $pRec['DESCRIPTION_EN']  = $descEn;
                $pRec['CURRENT_VERSION'] = '1.3.1';
                $pRec['IS_INSTALLED']    = 1;
                SQLUpdate('plugins', $pRec);
            }
        }
    }

    function install($data = '')
    {
        $this->dbInstall();
        parent::install();
        $this->getConfig();

        // Safe, explainable defaults. Monitor-only, automation OFF, dry-run ON.
        // Temperatures are STARTING POINTS to review within the exact-model
        // manual — NOT certified safe values. Setpoint hardware range is 40..80.
        $defaults = array(
            // transport / device
            'ESP_HOST'             => '',
            'ESP_PORT'             => 80,
            'ESP_USER'             => 'boiler',
            'ESP_PASS'             => '',
            'ESP_DIGEST'           => 0,
            'POLL_INTERVAL_S'      => 15,
            'BOILER_BLE_MAC'       => '',   // обраний у сканері BLE MAC бойлера
            // safety master switches
            'AUTOMATION_ENABLED'   => 0,     // never auto-control until confirmed
            'DRY_RUN'              => 1,     // compute decisions, do not write
            // comfort / targets (review against the manual!)
            'COMFORT_MIN_C'        => 45,
            'NIGHT_TARGET_C'       => 55,
            'SOLAR_TARGET_C'       => 65,
            'BOOST_TARGET_C'       => 65,
            'ABSOLUTE_MAX_C'       => 70,
            // tariff
            'TARIFF_ENABLED'       => 1,     // 0 = ignore the cheap window entirely
            'TIMEZONE'             => 'Europe/Kyiv',
            'CHEAP_START_HOUR'     => 23,
            'CHEAP_START_MIN'      => 5,
            'CHEAP_END_HOUR'       => 6,
            'CHEAP_END_MIN'        => 55,
            'METER_OFFSET_SECONDS' => 0,
            'START_GUARD_SECONDS'  => 120,
            'END_GUARD_SECONDS'    => 120,
            // continuous-heat guard mirror (authoritative copy lives on ESP32)
            'MAX_CONTINUOUS_HEAT_S' => 3600,
            'MIN_PAUSE_S'          => 600,
            'LEASE_TTL_S'          => 180,
            'COMMAND_TIMEOUT_S'    => 20,
            // power source binding
            'POWER_PROPERTY'       => 'BatteryMode.active',
            'BATTERY_ACTIVE_VALUE' => '',    // admin must confirm polarity
            'POWER_FRESHNESS_TTL_S' => 0,    // 0 = freshness not enforced
            // solar surplus by SOC
            'SOLAR_SURPLUS_ENABLED' => 0,
            'SOC_PROPERTY'         => 'deye_battery.soc',
            'SURPLUS_SOC_HIGH'     => 100,
            'SURPLUS_SOC_LOW'      => 98,
            // forecast bindings (point at sibling solarPlanner mirror by default)
            'FORECAST_TODAY_PROP'  => '',    // e.g. SolarForecast.TodayRemainingKwh
            'FORECAST_TOMORROW_PROP' => '',
            'FORECAST_UPDATED_PROP' => '',
            'FORECAST_PROVIDER_PROP' => '',
            'FORECAST_FRESHNESS_TTL_S' => 21600,
            // boost
            'BOOST_TTL_S'          => 1800,
            'BOOST_UNTIL_UTC'      => 0,
            'BOOST_ALLOW_BATTERY'  => 0,   // this boost may run on battery power
            // allow heating while the house runs on batteries (user opt-in).
            // Boiler safety (cooldown / continuous-heat limit) still applies.
            'ALLOW_BATTERY_HEAT'   => 0,
            // presence (variant a: passive Wi-Fi/BLE by MAC) — OFF by default
            'PRESENCE_ENABLED'     => 0,
            'PRESENCE_ROSTER_JSON' => '{}', // {"aa:bb:..":"Назар"}
            'PRESENCE_ABSENT_AFTER_S' => 120,
            'PRESENCE_NOTIFY_AFTER_S' => 600,    // "long" threshold
            'PRESENCE_TOO_LONG_AFTER_S' => 1200, // "too_long" threshold
            'PRESENCE_RENOTIFY_EVERY_S' => 0,
            'PRESENCE_HANDLER'     => '',   // MajorDoMo method to call (announce)
            // Data retention (RPi/SD disk is small): prune the module's own
            // history tables so they never grow without bound. 0 = keep forever.
            // Pruning runs at most once per day (see _LAST_PRUNE_UTC).
            'PRESENCE_RETENTION_DAYS'  => 90,   // drazice_presence
            'TELEMETRY_RETENTION_DAYS' => 30,   // drazice_telemetry
            'COMMANDS_RETENTION_DAYS'  => 30,   // drazice_commands
            '_LAST_PRUNE_UTC'          => 0,    // internal: last time tables were pruned
            // Boiler clock drift check: the ESP reports the boiler's internal
            // clock (HOME_TIME) over BLE; the module compares it with the server
            // clock and flags a significant drift. Checked rarely: on a fresh
            // read, after the link recovers, and at least once a day.
            'CLOCK_SKEW_THRESHOLD_S' => 120,    // UI-configurable significant-drift threshold
            '_OBJECT_SCHEMA_VERSION' => '',     // internal: module version the boiler object was last (re)applied for
            '_LAST_CLOCK_CHECK_UTC'  => 0,      // internal: last time the clock was compared
            '_LAST_CLOCK_SKEW_S'     => '',     // internal: last computed skew (seconds, '' = unknown)
            '_LAST_BOILER_TIME'      => '',     // internal: last boiler time text we checked
            '_CLOCK_WAS_OFFLINE'     => 0,      // internal: boiler was unavailable on the previous cycle
            // Event handlers: a script/method called when a value CHANGES.
            // Format: "script:Title" or "Object.method"; empty = no handler.
            // The handler receives $params: event, value, prev (globals too).
            'EVENT_HANDLER'        => '',   // single handler for all change events
            'EVENT_ON_HEATING'     => 1,    // fire on heating 0<->1
            'EVENT_ON_STATUS'      => 1,    // fire on controlState heat<->hold
            'EVENT_ON_MODE'        => 1,    // fire on reported mode change
            'EVENT_ON_TARIFF'      => 1,    // fire on tariff_active change
            'EVENT_ON_POWER'       => 1,    // fire on power source change
            'EVENT_ON_TEMP_STEP'   => 0,    // fire on temp change >= EVENT_TEMP_STEP_C
            'EVENT_TEMP_STEP_C'    => 5,    // temperature change threshold for an event
            // surplus latch state (planner hysteresis)
            'SURPLUS_LATCHED'      => 0,
            // charge priority ("battery first"): automatic heating waits until
            // battery SOC >= CHARGE_PRIORITY_SOC; once released it stays released
            // until SOC < SOC - HYST. OFF by default. Manual boost not gated.
            'CHARGE_PRIORITY_ENABLED' => 0,
            'CHARGE_PRIORITY_SOC'  => 100,
            'CHARGE_PRIORITY_HYST' => 5,
            'CHARGE_RELEASED_LATCHED' => 0,
            // Vacation: hold ANTIFREEZE for a window. Times are unix UTC; 0 = unset.
            'VACATION_ENABLED'     => 0,
            'VACATION_START_UTC'   => 0,
            'VACATION_END_UTC'     => 0,
            'VACATION_ALLOW_SURPLUS' => 0, // 0 = pure ANTIFREEZE, 1 = still use free solar surplus
            // HDO control ("solar override"): base state HDO on (boiler keeps its
            // own cheap-tariff window). When daytime + SOC high + not yet at the
            // surplus target, the module turns HDO OFF so the boiler may heat
            // outside its window on free solar; restored on target/SOC-low.
            // OFF by default; DRY-RUN by default (never writes HDO until armed).
            'HDO_CONTROL_ENABLED'  => 0,
            'HDO_CONTROL_DRY_RUN'  => 1,
            'HDO_DESIRED'          => 1, // last desired HDO state the module computed
            'HDO_OVERRIDE_LATCHED' => 0, // hysteresis latch for the solar override
            // Low-traffic policy: read the ESP rarely in the background, write to
            // the boiler ONLY on a real change of intent (with a cooldown), and
            // renew the lease far less often than once per cycle.
            'CYCLE_READ_INTERVAL_S' => 60,  // background GET /state cadence (UI poll uses POLL_INTERVAL_S)
            'WRITE_COOLDOWN_S'      => 300, // min seconds between identical writes of the same intent
            'LEASE_RENEW_EVERY_S'   => 90,  // renew lease at ~half of LEASE_TTL_S, not every cycle
            '_LAST_APPLIED_MODE'    => '',  // last mode we actually sent
            '_LAST_APPLIED_TARGET'  => '',  // last target we actually sent
            '_LAST_APPLIED_UTC'     => 0,   // when we last sent a boiler write
            '_LAST_LEASE_UTC'       => 0,   // when we last renewed the lease
            '_LAST_READ_UTC'        => 0,   // when the cycle last fetched /state
            // Single boiler+ESP object (all properties, presence sessions/events).
            'BOILER_OBJECT'        => 'EspBoiler',
            // Simple Devices ("Простые устройства") integration — OFF by default.
            // When enabled, the boiler is mirrored into native simple devices
            // (thermostat + sensors) so it is visible/controllable in the
            // Devices UI. Map is editable in the UI; types come from runtime.
            'SIMPLE_DEVICES_ENABLED' => 0,
            'SIMPLE_DEVICES_MAP_JSON' => '',   // empty => SimpleDevices::defaultMap()
            '_LAST_PUBLISHED_TARGET' => '',    // internal: last target we pushed
        );
        foreach ($defaults as $k => $v) {
            if (!isset($this->config[$k])) {
                $this->config[$k] = $v;
            }
        }
        $this->saveConfig();
        $this->checkInstalled();

        // Create the single boiler+ESP object with all described properties and
        // linked control methods. Idempotent; safe if objects runtime present.
        if (BoilerObject::available()) {
            BoilerObject::ensureObject($this->config['BOILER_OBJECT'], $this->name);
        }
    }

    function dbInstall($data = '')
    {
        $data = <<<EOD
drazice_telemetry: ID int(10) unsigned NOT NULL auto_increment
drazice_telemetry: SAMPLE_UTC int(11) NOT NULL DEFAULT '0'
drazice_telemetry: TEMP_LOWER float
drazice_telemetry: TEMP_UPPER float
drazice_telemetry: HEATING tinyint(1)
drazice_telemetry: HDO tinyint(1)
drazice_telemetry: MODE_REPORTED varchar(16) NOT NULL DEFAULT ''
drazice_telemetry: ENERGY_TOTAL_KWH float
drazice_telemetry: POWER_SOURCE varchar(12) NOT NULL DEFAULT 'unknown'
drazice_telemetry: BATTERY_SOC float
drazice_telemetry: HTTP_OK tinyint(1)
drazice_telemetry: BLE_STATE varchar(24) NOT NULL DEFAULT ''

drazice_decisions: ID int(10) unsigned NOT NULL auto_increment
drazice_decisions: DECISION_UTC int(11) NOT NULL DEFAULT '0'
drazice_decisions: ACTION varchar(16) NOT NULL DEFAULT ''
drazice_decisions: TARGET_C float
drazice_decisions: MODE varchar(16) NOT NULL DEFAULT ''
drazice_decisions: REASON varchar(48) NOT NULL DEFAULT ''
drazice_decisions: REASON_HUMAN varchar(255) NOT NULL DEFAULT ''
drazice_decisions: PRIORITY_LEVEL int(4) NOT NULL DEFAULT '0'
drazice_decisions: ALLOWED tinyint(1) NOT NULL DEFAULT '0'
drazice_decisions: ALGO_VERSION varchar(16) NOT NULL DEFAULT ''
drazice_decisions: DRY_RUN tinyint(1) NOT NULL DEFAULT '1'

drazice_commands: ID int(10) unsigned NOT NULL auto_increment
drazice_commands: TS_UTC int(11) NOT NULL DEFAULT '0'
drazice_commands: COMMAND_ID varchar(48) NOT NULL DEFAULT ''
drazice_commands: KIND varchar(24) NOT NULL DEFAULT ''
drazice_commands: PAYLOAD varchar(255) NOT NULL DEFAULT ''
drazice_commands: STATUS varchar(16) NOT NULL DEFAULT ''
drazice_commands: DETAIL varchar(255) NOT NULL DEFAULT ''

drazice_presence: ID int(10) unsigned NOT NULL auto_increment
drazice_presence: TS_UTC int(11) NOT NULL DEFAULT '0'
drazice_presence: PERSON varchar(48) NOT NULL DEFAULT ''
drazice_presence: DURATION_S int(11) NOT NULL DEFAULT '0'
drazice_presence: EVENT varchar(24) NOT NULL DEFAULT ''

drazice_people: ID int(10) unsigned NOT NULL auto_increment
drazice_people: PERSON varchar(48) NOT NULL DEFAULT ''
drazice_people: BLE_MAC varchar(32) NOT NULL DEFAULT ''
drazice_people: WIFI_MAC varchar(32) NOT NULL DEFAULT ''
drazice_people: BEACON_UUID varchar(48) NOT NULL DEFAULT ''
drazice_people: CREATED datetime

drazice_state_cache: ID int(10) unsigned NOT NULL auto_increment
drazice_state_cache: FETCHED_UTC int(11) NOT NULL DEFAULT '0'
drazice_state_cache: ATTEMPT_UTC int(11) NOT NULL DEFAULT '0'
drazice_state_cache: LAST_OK tinyint(1) NOT NULL DEFAULT '0'
drazice_state_cache: SOURCE varchar(8) NOT NULL DEFAULT ''
drazice_state_cache: DIRTY tinyint(1) NOT NULL DEFAULT '0'
drazice_state_cache: PAYLOAD text
EOD;
        parent::dbInstall($data);
    }

    // ---- transport helper ---------------------------------------------------

    /** @var Transport|null test seam: replaces the real cURL transport */
    private $transportOverride = null;

    /** @var array|null per-request memo of allProperties() */
    private $propsMemo = null;

    /** Inject a transport (tests / measure_calls.php). null restores the default. */
    public function setTransport($t)
    {
        $this->transportOverride = $t;
    }

    private function makeTransport()
    {
        if ($this->transportOverride !== null) {
            return $this->transportOverride;
        }
        return new Transport(array(
            'host'   => isset($this->config['ESP_HOST']) ? $this->config['ESP_HOST'] : '',
            'port'   => isset($this->config['ESP_PORT']) ? (int) $this->config['ESP_PORT'] : 80,
            'user'   => isset($this->config['ESP_USER']) ? $this->config['ESP_USER'] : '',
            'pass'   => isset($this->config['ESP_PASS']) ? $this->config['ESP_PASS'] : '',
            'digest' => !empty($this->config['ESP_DIGEST']),
        ));
    }

    // ---- ESP state cache (ONE batch GET /state, DB snapshot) ----------------

    /**
     * Path of the state-fetch lock. Keyed by effective uid so a lock left by
     * another OS user (e.g. a one-off CLI run as root) does not block the web
     * user (www-data) with "Permission denied". Public so tests can target the
     * exact same file the code uses.
     */
    public static function stateLockPath()
    {
        $uid = function_exists('posix_geteuid') ? (string) posix_geteuid()
            : (function_exists('getmyuid') ? (string) getmyuid() : '0');
        return sys_get_temp_dir() . '/drazice_state_' . $uid . '.lock';
    }

    /** Cache staleness threshold derived from POLL_INTERVAL_S. */
    private function staleAfterS()
    {
        return StateCache::staleAfter(isset($this->config['POLL_INTERVAL_S']) ? (int) $this->config['POLL_INTERVAL_S'] : 15);
    }

    /** Package a cache row (+ optional just-fetched map) as array(map, meta, error). */
    private function stateResult($row, $now, $freshMap = null, $error = array())
    {
        $map = ($freshMap !== null) ? StateCache::redact($freshMap)
            : ($row !== null ? StateCache::decode(isset($row['PAYLOAD']) ? $row['PAYLOAD'] : '') : array());
        return array(
            'map'   => $map,
            'meta'  => StateCache::meta($row, $now, $this->staleAfterS()),
            'error' => $error,
        );
    }

    /** Cached state ONLY. Never contacts the ESP (admin()/usual() render path). */
    public function stateFromCache()
    {
        return $this->stateResult(StateCache::load(), time());
    }

    /**
     * Cached state, refetched with ONE GET /state when needed.
     *  - !$force and the last attempt is < FRESH_S old and nothing is dirty:
     *    served from the cache, the ESP is NOT contacted (coalescing);
     *  - otherwise a non-blocking-with-3s-wait flock (the ESP serves one client
     *    at a time) guards the fetch; the row is re-read after taking the lock
     *    (double-check) so concurrent requests produce a single GET.
     * A failed fetch keeps the old payload (meta.fetch_ok=false, stale=true).
     *
     * @return array map, meta, error
     */
    public function refreshState($force = false, $mustFetch = false)
    {
        $now = time();
        $row = StateCache::load();
        if (!$force && $this->cacheServesRead($row, $now)) {
            return $this->stateResult($row, $now);
        }

        $fh = @fopen(self::stateLockPath(), 'c');
        $locked = false;
        if ($fh) {
            $deadline = microtime(true) + 3.0;
            do {
                $locked = flock($fh, LOCK_EX | LOCK_NB);
                if ($locked) {
                    break;
                }
                usleep(100000);
            } while (microtime(true) < $deadline);
        }
        try {
            if ($locked && !$force) {
                // Double-check: another request may have fetched while we waited.
                $row = StateCache::load();
                if ($this->cacheServesRead($row, time())) {
                    return $this->stateResult($row, time());
                }
            } elseif (!$locked && !$mustFetch && $fh) {
                // Someone else has been fetching for > 3 s: do not pile on, even for
                // forced reads (pairing polls). Only the cycle/planner ($mustFetch) goes on.
                return $this->stateResult(StateCache::load(), time());
            }
            $t = $this->makeTransport();
            $map = $t->getAll();
            $now = time();
            if (is_array($map)) {
                $source = $t->getLastSource();
                StateCache::saveSuccess($map, $source, $now);
                $saved = StateCache::load();
                if ($saved === null) {
                    // No DB layer (headless run): synthesize the row so the caller still sees a fresh success.
                    $saved = array('FETCHED_UTC' => $now, 'ATTEMPT_UTC' => $now, 'LAST_OK' => 1,
                        'SOURCE' => $source, 'DIRTY' => 0, 'PAYLOAD' => '{}');
                }
                return $this->stateResult($saved, $now, $map);
            }
            $err = $t->getLastError();
            StateCache::saveFailure($now);
            $res = $this->stateResult(StateCache::load(), $now);
            $res['error'] = is_array($err) ? $err : array();
            return $res;
        } finally {
            if ($fh) {
                if ($locked) {
                    flock($fh, LOCK_UN);
                }
                fclose($fh);
            }
        }
    }

    /** True when the cache row may answer a non-forced read without an ESP call. */
    private function cacheServesRead($row, $now)
    {
        return $row !== null && empty($row['DIRTY'])
            && StateCache::isFresh(isset($row['ATTEMPT_UTC']) ? $row['ATTEMPT_UTC'] : 0, $now, StateCache::FRESH_S);
    }

    /**
     * Map for a decision/telemetry: the result of a FRESH fetch, or an EMPTY
     * map when the fetch failed (never the stale cache).
     */
    private function freshMapOrEmpty($r)
    {
        return (isset($r['meta']['fetch_ok']) && $r['meta']['fetch_ok'] === true) ? $r['map'] : array();
    }

    /**
     * Build an immutable snapshot for the pure planner from a FRESH fetch.
     * The PLANNER ALWAYS DECIDES ON A FRESH FETCH, never on the stale cache:
     * refreshState(true) bypasses the cache and, if the ESP does not answer,
     * the snapshot is built from an empty map (http_available=false), exactly
     * like the old per-entity code did.
     */
    public function buildSnapshot()
    {
        $r = $this->refreshState(true, true);
        return $this->buildSnapshotFromState($this->freshMapOrEmpty($r), time());
    }

    /** Snapshot for the pure planner from an entity map (no ESP access). */
    public function buildSnapshotFromState($map, $now)
    {
        $tempUpperJson = Transport::entity($map, 'sensor', 'Boiler Temp Upper');
        $tempLowerJson = Transport::entity($map, 'sensor', 'Boiler Temp Lower');
        $heatJson      = Transport::entity($map, 'binary_sensor', 'Boiler Heating');
        $hdoJson       = Transport::entity($map, 'binary_sensor', 'Boiler HDO');
        $energyJson    = Transport::entity($map, 'sensor', 'Boiler Energy');
        $cooldownJson  = Transport::entity($map, 'sensor', 'Boiler Cooldown Remaining');
        $guardStateJson = Transport::entity($map, 'text_sensor', 'Boiler Guard State');
        // Actual mode/setpoint the boiler reports (read-only mirrors). Used to
        // make writes idempotent WITHOUT an extra GET: we already have them here.
        $modeActualJson = Transport::entity($map, 'text_sensor', 'Boiler Mode');
        $setpointActualJson = Transport::entity($map, 'sensor', 'Boiler Setpoint');

        $httpAvailable = ($tempUpperJson !== null) || ($guardStateJson !== null);

        // Power source.
        $powerProp = isset($this->config['POWER_PROPERTY']) ? $this->config['POWER_PROPERTY'] : '';
        $rawPower = Repository::readProperty($powerProp);
        $srcUpdated = null;
        if (strpos($powerProp, '.') !== false) {
            list($o, $p) = explode('.', $powerProp, 2);
            $srcUpdated = Repository::propertyUpdatedUtc($o, $p);
        }
        $power = PolicyEngine::powerSource(
            $rawPower,
            isset($this->config['BATTERY_ACTIVE_VALUE']) ? $this->config['BATTERY_ACTIVE_VALUE'] : '',
            $srcUpdated, $now,
            isset($this->config['POWER_FRESHNESS_TTL_S']) ? (int) $this->config['POWER_FRESHNESS_TTL_S'] : 0
        );

        // Battery SOC.
        $socRaw = Repository::readProperty(isset($this->config['SOC_PROPERTY']) ? $this->config['SOC_PROPERTY'] : '');
        $soc = PolicyEngine::validSoc($socRaw);

        // Tariff (optional: TARIFF_ENABLED=0 ignores the cheap window).
        $tariffOn = !isset($this->config['TARIFF_ENABLED']) || !empty($this->config['TARIFF_ENABLED']);
        $tariff = Tariff::evaluate($now, self::tariffConfig($this->config, $tariffOn));

        // Daytime = NOT night window (with the tariff off: fixed 23:00-07:00).
        $isDaytime = empty($tariff['active']);
        if (!$tariffOn) {
            $tariff = array('active' => false, 'reason' => 'tariff_disabled', 'meter_local' => $tariff['meter_local']);
        }

        // Boost.
        $boostActive = (isset($this->config['BOOST_UNTIL_UTC']) && (int) $this->config['BOOST_UNTIL_UTC'] > $now);

        return array(
            'now_utc'        => $now,
            'http_available' => $httpAvailable,
            'temp_upper'     => Transport::numericValue($tempUpperJson),
            'temp_lower'     => Transport::numericValue($tempLowerJson),
            'heating'        => Transport::boolValue($heatJson),
            'hdo'            => Transport::boolValue($hdoJson),
            'energy_total_kwh' => Transport::numericValue($energyJson),
            'cooldown_remaining_s' => (int) (Transport::numericValue($cooldownJson) !== null ? Transport::numericValue($cooldownJson) : 0),
            'in_cooldown'    => (Transport::numericValue($cooldownJson) !== null && Transport::numericValue($cooldownJson) > 0),
            // Display only: cooldown_remaining_s above is 0 when unknown (planner
            // contract, unchanged); the UI must show "no data", not "0 с".
            'cooldown_known' => (Transport::numericValue($cooldownJson) !== null),
            'guard_state'    => Transport::stringValue($guardStateJson),
            'ble_state'      => Transport::stringValue($guardStateJson),
            'power_source'   => $power['source'],
            'power_valid'    => $power['valid'],
            'power_human'    => $power['human'],
            'power_raw'      => $power['raw'],
            'power_code'     => $power['code'],
            'power_raw_norm' => $power['raw_norm'],
            'power_active_norm' => $power['active_norm'],
            // Seconds since the power-source property was last WRITTEN in MajorDoMo
            // (pvalues.UPDATED); null when unknown. Shown next to the freshness TTL
            // so a "stale -> unknown" verdict is explainable in the UI.
            'power_age_s'    => ($srcUpdated !== null ? max(0, (int) $now - (int) $srcUpdated) : null),
            'battery_soc'    => $soc,
            'tariff_active'  => !empty($tariff['active']),
            'tariff_meter_local' => $tariff['meter_local'],
            'is_daytime'     => $isDaytime,
            'boost_active'   => $boostActive,
            'boost_allow_battery' => !empty($this->config['BOOST_ALLOW_BATTERY']),
            'surplus_latched' => !empty($this->config['SURPLUS_LATCHED']),
            'charge_released_latched' => !empty($this->config['CHARGE_RELEASED_LATCHED']),
            'solar_surplus_enabled' => !empty($this->config['SOLAR_SURPLUS_ENABLED']),
            'hdo_override_latched' => !empty($this->config['HDO_OVERRIDE_LATCHED']),
            // Actual boiler-reported mode/setpoint (for idempotent writes).
            'reported_mode'   => Transport::stringValue($modeActualJson),
            'reported_target' => Transport::numericValue($setpointActualJson),
            // Boiler internal clock text ("Day HH:MM:SS"); null when unknown.
            'boiler_time'     => Transport::stringValue(Transport::entity($map, 'text_sensor', 'Boiler Time')),
        );
    }

    /** Planner config extracted from module config. */
    public function plannerConfig()
    {
        return array(
            'comfort_min_c'  => $this->config['COMFORT_MIN_C'],
            'night_target_c' => $this->config['NIGHT_TARGET_C'],
            'solar_target_c' => $this->config['SOLAR_TARGET_C'],
            'boost_target_c' => $this->config['BOOST_TARGET_C'],
            'absolute_max_c' => $this->config['ABSOLUTE_MAX_C'],
            'solar_surplus_enabled' => !empty($this->config['SOLAR_SURPLUS_ENABLED']),
            'surplus_soc_high' => $this->config['SURPLUS_SOC_HIGH'],
            'surplus_soc_low'  => $this->config['SURPLUS_SOC_LOW'],
            'allow_battery_heat' => !empty($this->config['ALLOW_BATTERY_HEAT']),
            'charge_priority_enabled' => !empty($this->config['CHARGE_PRIORITY_ENABLED']),
            'charge_priority_soc' => isset($this->config['CHARGE_PRIORITY_SOC']) ? $this->config['CHARGE_PRIORITY_SOC'] : 100,
            'charge_priority_hyst' => isset($this->config['CHARGE_PRIORITY_HYST']) ? $this->config['CHARGE_PRIORITY_HYST'] : 5,
            'vacation_enabled' => !empty($this->config['VACATION_ENABLED']),
            'vacation_start_utc' => isset($this->config['VACATION_START_UTC']) ? (int) $this->config['VACATION_START_UTC'] : 0,
            'vacation_end_utc' => isset($this->config['VACATION_END_UTC']) ? (int) $this->config['VACATION_END_UTC'] : 0,
            'vacation_allow_surplus' => !empty($this->config['VACATION_ALLOW_SURPLUS']),
            'hdo_control_enabled' => !empty($this->config['HDO_CONTROL_ENABLED']),
        );
    }

    /**
     * One cycle step: snapshot -> decide -> (optionally) apply. Called by the
     * cycle worker. Respects AUTOMATION_ENABLED and DRY_RUN.
     */
    public function cycleStep()
    {
        $this->getConfig();
        $now = time();

        // Keep the boiler object (properties, handler methods, ONCHANGE links,
        // history retention) in step with the deployed code: re-applied once
        // per module version, so a deploy never needs a reinstall.
        $this->ensureObjectSchema();

        // LOW-TRAFFIC READ: fetch /state from the ESP only every
        // CYCLE_READ_INTERVAL_S (default 60s), with exponential backoff when the
        // ESP is unreachable. Between fetches we reuse the DB cache, so the
        // planner still runs every cycle but the ESP is not polled every ~15s.
        $readInterval = isset($this->config['CYCLE_READ_INTERVAL_S']) ? (int) $this->config['CYCLE_READ_INTERVAL_S'] : 60;
        if ($readInterval < 15) {
            $readInterval = 15;
        }
        $lastRead = isset($this->config['_LAST_READ_UTC']) ? (int) $this->config['_LAST_READ_UTC'] : 0;
        $backoff = isset($this->config['_READ_BACKOFF_S']) ? (int) $this->config['_READ_BACKOFF_S'] : 0;
        $due = ($now - $lastRead) >= max($readInterval, $backoff);

        if ($due) {
            $state = $this->refreshState(true, true); // forced GET /state
            $this->config['_LAST_READ_UTC'] = time();
            // Backoff on failure: 2x up to 4x the interval; reset on success.
            $ok = is_array($state) && !empty($state['map']);
            $this->config['_READ_BACKOFF_S'] = $ok ? 0 : min($readInterval * 4, max($readInterval * 2, $backoff * 2));
            $this->saveConfig();
        } else {
            $state = $this->refreshState(false); // serve from cache, no ESP hit
        }
        $map = $this->freshMapOrEmpty($state);
        $snapshot = $this->buildSnapshotFromState($map, time());
        $decision = Planner::decide($snapshot, $this->plannerConfig());
        $decision['dry_run'] = !empty($this->config['DRY_RUN']) || empty($this->config['AUTOMATION_ENABLED']);

        // Maintain surplus hysteresis latch across cycles.
        if (!empty($this->config['SOLAR_SURPLUS_ENABLED']) && $snapshot['battery_soc'] !== null) {
            $soc = (float) $snapshot['battery_soc'];
            $hi = (float) $this->config['SURPLUS_SOC_HIGH'];
            $lo = (float) $this->config['SURPLUS_SOC_LOW'];
            $latched = !empty($this->config['SURPLUS_LATCHED']);
            if (!$latched && $soc >= $hi) $latched = true;
            if ($latched && $soc <= $lo) $latched = false;
            $this->config['SURPLUS_LATCHED'] = $latched ? 1 : 0;
            $this->saveConfig();
        }

        // Maintain the charge-priority latch (same pure rule the planner used).
        // SOC unknown => keep the previous latch value (no information).
        if (!empty($this->config['CHARGE_PRIORITY_ENABLED']) && $snapshot['battery_soc'] !== null) {
            $cp = Planner::evalChargePriority($snapshot, $this->plannerConfig());
            $newLatch = $cp['released'] ? 1 : 0;
            if ((int) (isset($this->config['CHARGE_RELEASED_LATCHED']) ? $this->config['CHARGE_RELEASED_LATCHED'] : 0) !== $newLatch) {
                $this->config['CHARGE_RELEASED_LATCHED'] = $newLatch;
                $this->saveConfig();
            }
        }

        // Persist telemetry + decision (history).
        Repository::saveTelemetry($snapshot);
        Repository::saveDecision($decision);

        // Mirror key values into MajorDoMo properties for dashboards/linked props.
        $this->publishProperties($snapshot, $decision, $this->diagFromState($map));

        // Renew the lease at most every LEASE_RENEW_EVERY_S (not every cycle).
        if (!empty($this->config['AUTOMATION_ENABLED']) && !empty($snapshot['http_available'])) {
            $leaseEvery = isset($this->config['LEASE_RENEW_EVERY_S']) ? (int) $this->config['LEASE_RENEW_EVERY_S'] : 90;
            if ($leaseEvery < 15) {
                $leaseEvery = 15;
            }
            $lastLease = isset($this->config['_LAST_LEASE_UTC']) ? (int) $this->config['_LAST_LEASE_UTC'] : 0;
            if ((time() - $lastLease) >= $leaseEvery) {
                $this->makeTransport()->pressButton('Boiler Lease Renew');
                $this->config['_LAST_LEASE_UTC'] = time();
                $this->saveConfig();
            }
        }

        // Apply the decision when automation is ON and not dry-run. Both heat
        // and hold go through applyBoilerState(), which writes ONLY when the
        // boiler's actual mode/target differs from the desired one AND the same
        // intent was not just written (WRITE_COOLDOWN_S). In the steady state
        // this sends NOTHING to the boiler.
        if (!empty($this->config['AUTOMATION_ENABLED']) && empty($this->config['DRY_RUN'])) {
            if (!empty($decision['allowed']) && $decision['action'] === 'heat') {
                $mode = ($decision['mode'] !== null) ? $decision['mode'] : 'MANUAL';
                $this->applyBoilerState($snapshot, $mode, (int) round($decision['target_c']), $decision['reason']);
            } else {
                // Active hold: desired = ANTIFREEZE + 40 (STOP unavailable).
                $mode = (isset($decision['mode']) && $decision['mode'] !== null) ? $decision['mode'] : 'ANTIFREEZE';
                $this->applyBoilerState($snapshot, $mode, 40, $decision['reason']);
            }
        } elseif (empty($this->config['AUTOMATION_ENABLED']) && empty($this->config['DRY_RUN'])
            && !empty($this->config['SIMPLE_DEVICES_ENABLED'])) {
            // MANUAL OVERRIDE: automation is off, so the user drives the boiler
            // through the Simple Device thermostat (relay_status 1/0 +
            // currentTargetValue), exactly like any other MajorDoMo thermostat.
            $this->applyManualOverride($snapshot);
        }

        // HDO solar-override control (independent of the heat decision).
        $this->applyHdoControl($snapshot);

        // Presence evaluation (optional).
        if (!empty($this->config['PRESENCE_ENABLED'])) {
            $this->evaluatePresence($snapshot['now_utc'], $this->bleFromState($map));
        }

        // Simple Devices ("Простые устройства") sync (optional).
        if (!empty($this->config['SIMPLE_DEVICES_ENABLED'])) {
            $this->syncSimpleDevices($snapshot, $decision);
        }

        // Boiler clock drift check (rare): on a fresh read, after the link
        // recovers from an outage, or at least once a day.
        $this->checkBoilerClock($snapshot, isset($due) ? (bool) $due : false);

        // Prune our own history tables at most once per day so they never grow
        // without bound (RPi/SD disk is small). Cheap no-op between runs.
        $this->pruneHistory(time());

        return array('snapshot' => $snapshot, 'decision' => $decision);
    }

    /**
     * Create the missing simple devices and, when every device of the map is
     * linked afterwards, switch the sync on (otherwise the created devices
     * would stay empty). Returns array(report, banner_kind, banner_key).
     */
    private function provisionSimpleDevices()
    {
        if (!SimpleDevices::available()) {
            return array('report' => array(), 'kind' => 'danger', 'key' => 'SD_UNAVAILABLE');
        }
        $map = $this->simpleDevicesMap();
        $res = SimpleDevices::provision($map);
        $report = isset($res['report']) && is_array($res['report']) ? $res['report'] : array();
        $failed = 0;
        foreach ($report as $v) {
            if ($v !== 'created' && $v !== 'exists') {
                $failed++;
            }
        }
        $created = isset($res['created']) ? (int) $res['created'] : 0;
        if ($failed === 0 && empty($this->config['SIMPLE_DEVICES_ENABLED'])) {
            $this->config['SIMPLE_DEVICES_ENABLED'] = 1;
            $this->saveConfig();
        }
        if ($failed > 0) {
            return array('report' => $report, 'kind' => 'danger', 'key' => 'SD_PROVISION_FAIL');
        }
        return array('report' => $report, 'kind' => ($created > 0 ? 'success' : 'info'),
            'key' => ($created > 0 ? 'SD_PROVISIONED' : 'SD_ALL_LINKED'));
    }

    /**
     * Remove the created simple devices (for re-creation after the object's
     * property set changed). Switches the sync OFF so nothing writes to the
     * now-absent devices. Returns array(report, kind, key).
     */
    private function removeSimpleDevices()
    {
        if (!SimpleDevices::available()) {
            return array('report' => array(), 'kind' => 'danger', 'key' => 'SD_UNAVAILABLE');
        }
        $res = SimpleDevices::remove($this->simpleDevicesMap());
        $report = isset($res['report']) && is_array($res['report']) ? $res['report'] : array();
        // Turn the integration off: the devices are gone, so don't keep syncing.
        $this->config['SIMPLE_DEVICES_ENABLED'] = 0;
        $this->saveConfig();
        return array('report' => $report, 'kind' => 'success', 'key' => 'SD_REMOVED');
    }

    /** Resolve the Simple Devices map (admin JSON override or default). */
    private function simpleDevicesMap()
    {
        $raw = isset($this->config['SIMPLE_DEVICES_MAP_JSON']) ? $this->config['SIMPLE_DEVICES_MAP_JSON'] : '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }
        return SimpleDevices::defaultMap();
    }

    /**
     * Mirror telemetry into simple devices and pick up a target command issued
     * from the native Devices UI, routing it through the guarded REST path.
     */
    private function syncSimpleDevices($snapshot, $decision)
    {
        if (!SimpleDevices::available()) {
            return;
        }
        $map = $this->simpleDevicesMap();
        // In MANUAL mode (automation off) the thermostat's relay_status /
        // currentTargetValue are the user's command inputs, read by
        // applyManualOverride(); publish() must NOT overwrite them. In AUTO the
        // module mirrors state into them.
        $manual = empty($this->config['AUTOMATION_ENABLED']);
        SimpleDevices::publish($map, $snapshot, $decision, $manual);
    }

    /**
     * MANUAL override via the Simple Device thermostat (automation OFF).
     * The user sets relay_status (1 heat / 0 off) and currentTargetValue; we
     * translate that into a boiler state through the SAME guarded, low-traffic
     * path (applyBoilerState): respects cooldown, writes only on change.
     *   relay 1 -> MANUAL + currentTargetValue (default = comfort minimum)
     *   relay 0 -> ANTIFREEZE + 40
     */
    private function applyManualOverride($snapshot)
    {
        if (empty($snapshot['http_available']) || !SimpleDevices::available()) {
            return;
        }
        $cmd = SimpleDevices::readManualCommand($this->simpleDevicesMap());
        if ($cmd['relay'] === null) {
            return; // no command yet (device never toggled)
        }
        if ($cmd['relay'] === 1) {
            $target = ($cmd['target'] !== null) ? (int) round($cmd['target'])
                : (int) round(isset($this->config['COMFORT_MIN_C']) ? $this->config['COMFORT_MIN_C'] : 45);
            $this->applyBoilerState($snapshot, 'MANUAL', $target, 'manual_on');
        } else {
            $this->applyBoilerState($snapshot, 'ANTIFREEZE', 40, 'manual_off');
        }
    }

    /**
     * Compute and (unless dry-run) apply the HDO desired state. The latch is
     * persisted so hysteresis survives across cycles. In dry-run the desired
     * value is only recorded (HDO_DESIRED) and published, never written to the
     * boiler — so you can watch the decision before arming it.
     */
    private function applyHdoControl($snapshot)
    {
        if (empty($this->config['HDO_CONTROL_ENABLED'])) {
            return;
        }
        $h = Planner::evalHdoControl($snapshot, $this->plannerConfig());
        $this->config['HDO_DESIRED'] = $h['desired'];
        if ((int) (isset($this->config['HDO_OVERRIDE_LATCHED']) ? $this->config['HDO_OVERRIDE_LATCHED'] : 0) !== (int) $h['latched']) {
            $this->config['HDO_OVERRIDE_LATCHED'] = (int) $h['latched'];
        }
        $this->saveConfig();

        $dryRun = !empty($this->config['HDO_CONTROL_DRY_RUN'])
            || empty($this->config['AUTOMATION_ENABLED']) || !empty($this->config['DRY_RUN']);
        if ($dryRun || empty($snapshot['http_available'])) {
            return;
        }
        // Only write when the boiler's current HDO differs from desired.
        $current = isset($snapshot['hdo']) ? $snapshot['hdo'] : null;
        if ($current !== null && (int) (bool) $current === (int) $h['desired']) {
            return;
        }
        $t = $this->makeTransport();
        $res = $t->pressButton($h['desired'] ? 'Boiler HDO On' : 'Boiler HDO Off');
        Repository::saveCommand(array(
            'ts_utc' => time(), 'command_id' => uniqid('hdo_', true), 'kind' => 'hdo',
            'payload' => $h['desired'] ? '1' : '0',
            'status' => ($res !== null ? 'sent' : 'failed'),
            'detail' => ($res === null ? json_encode($t->getLastError()) : $h['reason']),
        ));
        StateCache::markDirty();
    }

    /**
     * Apply a desired boiler state (mode + target) with MINIMAL traffic.
     * Writes to the boiler ONLY when ALL of these hold:
     *   - the ESP is reachable;
     *   - the boiler's ACTUAL reported mode/target (from the snapshot we already
     *     fetched — no extra GET) differs from the desired one;
     *   - we did not just send the SAME intent within WRITE_COOLDOWN_S.
     * Otherwise it sends nothing. This stops the "flash 40 every cycle" problem
     * and keeps the boiler quiet in the steady state. STOP is unavailable, so a
     * "do not heat" decision arrives here as ANTIFREEZE + 40.
     *
     * @param array  $snapshot current snapshot (has reported_mode/reported_target)
     * @param string $mode     desired mode (ANTIFREEZE/SMART/PROG/MANUAL)
     * @param int    $target   desired setpoint °C (clamped 40..80)
     * @param string $reason   planner reason (for the command log)
     */
    private function applyBoilerState($snapshot, $mode, $target, $reason)
    {
        if (empty($snapshot['http_available'])) {
            return;
        }
        // The ESP32 guard in FAULT silently drops every mode/target request, so
        // a "sent" would be a lie (and would repeat every WRITE_COOLDOWN_S). The
        // planner already reports 'guard_fault'; just do not write.
        if (isset($snapshot['guard_state']) && $snapshot['guard_state'] === 'FAULT') {
            return;
        }
        $target = (int) $target;
        if ($target < 40) { $target = 40; }
        if ($target > 80) { $target = 80; }

        $curMode = isset($snapshot['reported_mode']) ? (string) $snapshot['reported_mode'] : '';
        $curTargetRaw = isset($snapshot['reported_target']) ? $snapshot['reported_target'] : null;
        $curTarget = ($curTargetRaw === null) ? null : (int) round($curTargetRaw);

        // Desired already in effect? Nothing to do. (Unknown current target is
        // treated as "matches" to avoid a write on missing telemetry.)
        $modeMatches = ($curMode === '' ) ? false : ($curMode === $mode);
        $targetMatches = ($curTarget === null) ? true : ($curTarget === $target);
        if ($modeMatches && $targetMatches) {
            return;
        }

        // Write cooldown: do not resend the SAME intent within WRITE_COOLDOWN_S,
        // even if the boiler has not reflected it yet (avoids dribbling writes
        // while the boiler catches up or briefly disagrees).
        $cooldown = isset($this->config['WRITE_COOLDOWN_S']) ? (int) $this->config['WRITE_COOLDOWN_S'] : 300;
        $lastMode = isset($this->config['_LAST_APPLIED_MODE']) ? (string) $this->config['_LAST_APPLIED_MODE'] : '';
        $lastTarget = (isset($this->config['_LAST_APPLIED_TARGET']) && $this->config['_LAST_APPLIED_TARGET'] !== '')
            ? (int) $this->config['_LAST_APPLIED_TARGET'] : null;
        $lastUtc = isset($this->config['_LAST_APPLIED_UTC']) ? (int) $this->config['_LAST_APPLIED_UTC'] : 0;
        $sameIntent = ($lastMode === $mode) && ($lastTarget === $target);
        if ($sameIntent && (time() - $lastUtc) < $cooldown) {
            return; // throttled: identical intent sent recently
        }

        $t = $this->makeTransport();
        $sent = null;
        if (!$modeMatches) {
            $sent = $t->postSet('select', 'Boiler Requested Mode', $mode);
        }
        if (!$targetMatches) {
            $sent = $t->postSet('number', 'Boiler Requested Target', $target);
        }

        $this->config['_LAST_APPLIED_MODE'] = $mode;
        $this->config['_LAST_APPLIED_TARGET'] = (string) $target;
        $this->config['_LAST_APPLIED_UTC'] = time();
        $this->saveConfig();

        Repository::saveCommand(array(
            'ts_utc' => time(), 'command_id' => uniqid('set_', true), 'kind' => 'apply',
            'payload' => $mode . ' ' . $target,
            'status' => ($sent !== null ? 'sent' : 'failed'),
            'detail' => ($sent === null ? json_encode($t->getLastError())
                : ($reason . ' (was ' . ($curMode === '' ? '?' : $curMode) . '/' . ($curTarget === null ? '?' : $curTarget) . ')')),
        ));
        // Schedule ONE read-back soon (not every cycle): clear last-read so the
        // next cycle fetches to confirm the write took effect.
        $this->config['_LAST_READ_UTC'] = 0;
        $this->saveConfig();
        StateCache::markDirty();
    }

    /**
     * Publish EVERYTHING the ESP exposes into the single boiler+ESP object
     * (BOILER_OBJECT). Read-only telemetry/diagnostics + planner reflections.
     * Control properties are NOT written here (they are user-driven inputs).
     */
    private function publishProperties($s, $d, $diag)
    {
        if (!BoilerObject::available()) {
            return;
        }
        $o = isset($this->config['BOILER_OBJECT']) ? $this->config['BOILER_OBJECT'] : 'EspBoiler';
        $b = function ($v) { return $v === null ? null : ($v ? 1 : 0); };

        // telemetry
        BoilerObject::set($o, 'tempUpper',      $s['temp_upper']);
        BoilerObject::set($o, 'tempLower',      $s['temp_lower']);
        BoilerObject::set($o, 'heating',        $b($s['heating']));
        BoilerObject::set($o, 'hdo',            $b($s['hdo']));
        BoilerObject::set($o, 'energyTotalKwh', $s['energy_total_kwh']);
        BoilerObject::set($o, 'bleState',       $s['ble_state']);
        BoilerObject::set($o, 'powerSource',    $s['power_source']);
        BoilerObject::set($o, 'batterySoc',     $s['battery_soc']);
        BoilerObject::set($o, 'tariffActive',   $s['tariff_active'] ? 1 : 0);
        BoilerObject::set($o, 'connected',      $s['http_available'] ? 1 : 0);
        BoilerObject::set($o, 'lastSampleUtc',  $s['now_utc']);
        // planner reflections
        BoilerObject::set($o, 'plannedTarget',  $d['target_c']);
        BoilerObject::set($o, 'controlState',   $d['action']);
        BoilerObject::set($o, 'decisionReason', $d['reason']);
        BoilerObject::set($o, 'decisionHuman',  $d['reason_human']);
        BoilerObject::set($o, 'decisionAllowed', !empty($d['allowed']) ? 1 : 0);
        BoilerObject::set($o, 'decisionLevel',  isset($d['priority_level']) ? (int) $d['priority_level'] : 0);
        // One compact line meant to be logged to history (enable "store history"
        // on this property in MajorDoMo to chart decisions over days):
        //   "<action> <reason> target=<t> allowed=<0|1>"
        BoilerObject::set($o, 'decisionLog',
            $d['action'] . ' ' . $d['reason']
            . ' target=' . ($d['target_c'] === null ? '-' : $d['target_c'])
            . ' allowed=' . (!empty($d['allowed']) ? 1 : 0));
        // HDO control reflection.
        BoilerObject::set($o, 'hdoDesired', isset($this->config['HDO_DESIRED']) ? (int) $this->config['HDO_DESIRED'] : 1);
        BoilerObject::set($o, 'hdoControlEnabled', !empty($this->config['HDO_CONTROL_ENABLED']) ? 1 : 0);
        BoilerObject::set($o, 'vacationActive', (!empty($this->config['VACATION_ENABLED'])
            && (int) $this->config['VACATION_START_UTC'] <= $s['now_utc']
            && $s['now_utc'] < (int) $this->config['VACATION_END_UTC']) ? 1 : 0);

        // ESP diagnostics + guard + reported mode/target (from the same GET /state).
        BoilerObject::set($o, 'espUptime',      $diag['UPTIME']);
        BoilerObject::set($o, 'espRssi',        $diag['RSSI']);
        BoilerObject::set($o, 'espFreeHeap',    $diag['FREE_HEAP']);
        BoilerObject::set($o, 'espResetReason', $diag['RESET_REASON']);
        BoilerObject::set($o, 'guardState',     $diag['GUARD_STATE']);
        BoilerObject::set($o, 'guardReason',    $diag['GUARD_REASON']);
        BoilerObject::set($o, 'continuousHeatSeconds', $diag['CONT_HEAT_S']);
        BoilerObject::set($o, 'cooldownRemaining',     $diag['COOLDOWN_S']);
        BoilerObject::set($o, 'leaseRemaining',        $diag['LEASE_S']);
        BoilerObject::set($o, 'reportedMode',   $diag['REQ_MODE']);
        BoilerObject::set($o, 'reportedTarget', $diag['REQ_TARGET']);
        BoilerObject::set($o, 'boilerVersion',  $diag['BOILER_VER']);
        BoilerObject::set($o, 'boilerName',     $diag['BOILER_NAME']);

        // Fire change-event handlers (heating/status/mode/tariff/power/temp).
        $this->fireEvents($s, $d, $diag);
    }

    /**
     * Call the configured EVENT_HANDLER when a watched value CHANGES. Each
     * event is compared against the previously-seen value (persisted in
     * _EVT_* config keys) so the handler fires once per transition, not every
     * cycle. The handler ("script:Title" or "Object.method") receives
     * $params = array('event'=>..., 'value'=>..., 'prev'=>...). No handler set
     * or an unchanged value => nothing happens.
     */
    private function fireEvents($s, $d, $diag)
    {
        $handler = isset($this->config['EVENT_HANDLER']) ? trim((string) $this->config['EVENT_HANDLER']) : '';
        if ($handler === '') {
            return;
        }
        $events = array();
        $watch = function ($enabled, $event, $cur, $key) use (&$events) {
            if (empty($enabled)) {
                return;
            }
            $prev = isset($this->config[$key]) ? $this->config[$key] : null;
            $curN = ($cur === null) ? '' : (string) $cur;
            if ((string) $prev !== $curN) {
                $events[] = array('event' => $event, 'value' => $curN, 'prev' => (string) $prev);
                $this->config[$key] = $curN;
            }
        };

        $watch($this->config['EVENT_ON_HEATING'], 'heating',
            ($s['heating'] === null ? null : ($s['heating'] ? 1 : 0)), '_EVT_HEATING');
        $watch($this->config['EVENT_ON_STATUS'], 'status', $d['action'], '_EVT_STATUS');
        $watch($this->config['EVENT_ON_MODE'], 'mode', $diag['REQ_MODE'], '_EVT_MODE');
        $watch($this->config['EVENT_ON_TARIFF'], 'tariff',
            (!empty($s['tariff_active']) ? 1 : 0), '_EVT_TARIFF');
        $watch($this->config['EVENT_ON_POWER'], 'power', $s['power_source'], '_EVT_POWER');

        // Temperature: fire only when it moved by >= EVENT_TEMP_STEP_C.
        if (!empty($this->config['EVENT_ON_TEMP_STEP']) && $s['temp_upper'] !== null) {
            $step = isset($this->config['EVENT_TEMP_STEP_C']) ? (float) $this->config['EVENT_TEMP_STEP_C'] : 5.0;
            $prevT = isset($this->config['_EVT_TEMP']) && $this->config['_EVT_TEMP'] !== '' ? (float) $this->config['_EVT_TEMP'] : null;
            if ($prevT === null || abs((float) $s['temp_upper'] - $prevT) >= $step) {
                $events[] = array('event' => 'temperature', 'value' => (string) $s['temp_upper'],
                    'prev' => ($prevT === null ? '' : (string) $prevT));
                $this->config['_EVT_TEMP'] = (string) $s['temp_upper'];
            }
        }

        if (empty($events)) {
            return;
        }
        $this->saveConfig();
        foreach ($events as $ev) {
            $this->runHandler($handler, $ev);
        }
    }

    /**
     * Invoke a handler string: "script:Title" runs that user script, otherwise
     * "Object.method" calls that object method. $params carries event/value/prev.
     * Defensive: silently skipped if the MajorDoMo runtime is not present.
     */
    private function runHandler($handler, array $params)
    {
        if (strpos($handler, 'script:') === 0) {
            $title = substr($handler, 7);
            if (function_exists('runScript')) {
                runScript($title, $params);
            }
        } else {
            if (function_exists('callMethod')) {
                callMethod($handler, $params);
            } elseif (function_exists('callMethodSafe')) {
                callMethodSafe($handler, $params);
            }
        }
    }

    /**
     * Apply a change of a CONTROL property of the boiler object to the boiler.
     * Called by the property's linked method (change -> guarded REST write).
     * Public so the auto-generated method code can reach it.
     */
    public function applyObjectControl($property, $value)
    {
        $this->getConfig();
        $t = $this->makeTransport();
        switch ($property) {
            case 'requestedTarget':
                $v = (int) round((float) $value);
                if ($v < 40 || $v > 80) {
                    return;
                }
                $t->postSet('number', 'Boiler Requested Target', $v);
                break;
            case 'requestedMode':
                $m = strtoupper(trim((string) $value));
                if (in_array($m, array('ANTIFREEZE', 'SMART', 'PROG', 'MANUAL'), true)) {
                    $t->postSet('select', 'Boiler Requested Mode', $m);
                }
                break;
            case 'boost':
                if ($value) {
                    $this->config['BOOST_UNTIL_UTC'] = time() + (int) $this->config['BOOST_TTL_S'];
                } else {
                    $this->config['BOOST_UNTIL_UTC'] = 0;
                }
                $this->saveConfig();
                break;
            case 'automationEnabled':
                $this->config['AUTOMATION_ENABLED'] = $value ? 1 : 0;
                $this->saveConfig();
                break;
            case 'allowBatteryHeat':
                $this->config['ALLOW_BATTERY_HEAT'] = $value ? 1 : 0;
                $this->saveConfig();
                break;
        }
        StateCache::markDirty();
        Repository::saveCommand(array(
            'ts_utc' => time(), 'command_id' => 'objprop', 'kind' => $property,
            'payload' => (string) $value, 'status' => 'applied',
            'detail' => 'from boiler object property change',
        ));
    }

    /**
     * Variant (b) entry point: called by the per-property linked method on the
     * boiler object whenever a presence property changes (presentPeople,
     * presenceSessionJson, presenceEvent, presenceEventJson). One place handles
     * every presence change; extend the switch below for custom reactions.
     *
     * Default behaviour: on a presenceEvent change, invoke the configured
     * PRESENCE_HANDLER (script:Title or Object.method) with parsed params, so
     * announcements keep working even outside the cycle's own event loop. All
     * other properties are accepted as a no-op seam for the user to fill in.
     *
     * @param string $property changed property name (no object prefix)
     * @param mixed  $value    new value
     * @param mixed  $prev     previous value ('' when unknown)
     */
    public function onPresenceChange($property, $value, $prev = '')
    {
        $this->getConfig();

        switch ($property) {
            case 'presenceEvent':
            case 'presenceEventJson':
                // Parse "event:person" or the JSON payload into handler params.
                $person = '';
                $event = '';
                $duration = 0;
                $decoded = is_string($value) ? json_decode($value, true) : null;
                if (is_array($decoded)) {
                    $person = isset($decoded['person']) ? (string) $decoded['person'] : '';
                    $event = isset($decoded['event']) ? (string) $decoded['event'] : '';
                    $duration = isset($decoded['duration_s']) ? (int) $decoded['duration_s'] : 0;
                } elseif (is_string($value) && strpos($value, ':') !== false) {
                    $parts = explode(':', $value, 2);
                    $event = $parts[0];
                    $person = isset($parts[1]) ? $parts[1] : '';
                }
                $this->runPresenceHandler($person, $duration, $event);
                break;

            case 'presentPeople':
            case 'presenceSessionJson':
            default:
                // Seam: a change was observed and acknowledged. Add custom
                // reactions here (e.g. lights, logging). Intentionally a no-op
                // by default to avoid surprising side effects.
                break;
        }
    }

    /** Call the configured PRESENCE_HANDLER (script:Title or Object.method). */
    private function runPresenceHandler($person, $duration, $event)
    {
        $handler = isset($this->config['PRESENCE_HANDLER']) ? (string) $this->config['PRESENCE_HANDLER'] : '';
        if ($handler === '') {
            return;
        }
        $params = array('person' => $person, 'duration_s' => $duration, 'event' => $event);
        if (strpos($handler, 'script:') === 0 && function_exists('runScript')) {
            runScript(substr($handler, 7), $params);
        } elseif (strpos($handler, 'script:') !== 0 && function_exists('callMethod')) {
            callMethod($handler, $params);
        }
    }

    private function evaluatePresence($now, $ble)
    {
        $o = isset($this->config['BOILER_OBJECT']) ? $this->config['BOILER_OBJECT'] : 'EspBoiler';

        // Observations (BLE from the ESP scan + Wi-Fi from router/arp) are
        // collected into <object>.presenceObservationsJson by the presence
        // collector (see collectPresenceObservations). Each entry:
        //   {"mac":"aa:..","seen_utc":..,"source":"ble|wifi"}
        $obs = $this->collectPresenceObservations($o, $now, $ble);

        $roster = json_decode($this->config['PRESENCE_ROSTER_JSON'], true);
        if (!is_array($roster)) {
            $roster = array();
        }
        $stateRaw = Repository::readProperty($o . '.presenceStateJson');
        $state = $stateRaw ? json_decode($stateRaw, true) : array();
        if (!is_array($state)) {
            $state = array();
        }

        $res = Presence::evaluate($obs, $roster, $now, array(
            'presence_enabled'  => true,
            'absent_after_s'    => (int) $this->config['PRESENCE_ABSENT_AFTER_S'],
            'notify_after_s'    => (int) $this->config['PRESENCE_NOTIFY_AFTER_S'],
            'renotify_every_s'  => (int) $this->config['PRESENCE_RENOTIFY_EVERY_S'],
            'long_after_s'      => (int) $this->config['PRESENCE_NOTIFY_AFTER_S'],
            'too_long_after_s'  => (int) $this->config['PRESENCE_TOO_LONG_AFTER_S'],
        ), $state);

        // Write presence sessions + events into the single boiler object.
        if (function_exists('setGlobal')) {
            setGlobal($o . '.presenceStateJson', json_encode($res['state']));
            BoilerObject::set($o, 'presenceSessionJson', json_encode($res['present']));
            $labels = array();
            foreach ($res['present'] as $pp) {
                $labels[] = $pp['person'] . ' (' . intval($pp['duration_s'] / 60) . ' хв'
                    . (isset($pp['source']) ? ', ' . $pp['source'] : '') . ')';
            }
            BoilerObject::set($o, 'presentPeople', ($labels ? implode(', ', $labels) : '—'));
        }

        foreach ($res['events'] as $ev) {
            // Persist the session event (history) + surface as object property.
            if (function_exists('SQLInsert') && $this->tableExists('drazice_presence')) {
                $prRec = array(
                    'TS_UTC'     => $now,
                    'PERSON'     => isset($ev['person']) ? (string) $ev['person'] : '',
                    'DURATION_S' => isset($ev['duration_s']) ? (int) $ev['duration_s'] : 0,
                    'EVENT'      => isset($ev['event']) ? (string) $ev['event'] : 'long',
                );
                SQLInsert('drazice_presence', $prRec);
            }
            if (function_exists('setGlobal')) {
                BoilerObject::set($o, 'presenceEvent',
                    (isset($ev['event']) ? $ev['event'] : 'long') . ':' . (isset($ev['person']) ? $ev['person'] : ''));
                BoilerObject::set($o, 'presenceEventJson', json_encode($ev));
            }
            // Call the configured MajorDoMo handler (e.g. announce on speaker).
            $handler = isset($this->config['PRESENCE_HANDLER']) ? $this->config['PRESENCE_HANDLER'] : '';
            if ($handler && strpos($handler, 'script:') === 0 && function_exists('runScript')) {
                // "script:Title" runs a MajorDoMo script with the same params.
                runScript(substr($handler, 7), array(
                    'person'     => isset($ev['person']) ? $ev['person'] : '',
                    'duration_s' => isset($ev['duration_s']) ? $ev['duration_s'] : 0,
                    'event'      => isset($ev['event']) ? $ev['event'] : 'long',
                ));
            } elseif ($handler && strpos($handler, 'script:') !== 0 && function_exists('callMethod')) {
                callMethod($handler, array(
                    'person'     => isset($ev['person']) ? $ev['person'] : '',
                    'duration_s' => isset($ev['duration_s']) ? $ev['duration_s'] : 0,
                    'event'      => isset($ev['event']) ? $ev['event'] : 'long',
                ));
            }
        }
    }

    /**
     * Re-apply the boiler object definition (BoilerObject::ensureObject) when
     * the module version changed since it was last applied. ensureObject() is
     * idempotent and cheap; running it once per version means property
     * descriptions, history retention, class-level handler methods and the
     * ONCHANGE links always match the code that is actually deployed.
     * Marked done only on success, so a failure is retried on the next cycle.
     */
    private function ensureObjectSchema()
    {
        $applied = isset($this->config['_OBJECT_SCHEMA_VERSION']) ? (string) $this->config['_OBJECT_SCHEMA_VERSION'] : '';
        if ($applied === self::MODULE_VERSION) {
            return;
        }
        if (!BoilerObject::available()) {
            return;
        }
        $title = isset($this->config['BOILER_OBJECT']) ? $this->config['BOILER_OBJECT'] : 'EspBoiler';
        $res = BoilerObject::ensureObject($title, $this->name);
        if (!empty($res['ok'])) {
            $this->config['_OBJECT_SCHEMA_VERSION'] = self::MODULE_VERSION;
            $this->saveConfig();
            if (function_exists('DebMes')) {
                DebMes('drazice_smart: object schema applied for v' . self::MODULE_VERSION
                    . ' (' . json_encode($res) . ')');
            }
        }
    }

    /**
     * Compare the boiler's internal clock (reported over BLE) with the server
     * clock and flag a significant drift. Called rarely — only when one of:
     *   - this cycle did a fresh ESP read ($freshRead), OR
     *   - the boiler just recovered from an outage (was offline, now online), OR
     *   - at least CLOCK_CHECK_INTERVAL (1 day) passed since the last check.
     * Writes boilerClock / boilerClockSkewSeconds / boilerClockOk on the object,
     * and fires the configured event handler on a transition into "not ok".
     *
     * @param array $snapshot current snapshot (uses boiler_time, http_available)
     * @param bool  $freshRead whether this cycle forced a GET /state
     */
    private function checkBoilerClock($snapshot, $freshRead)
    {
        $o = isset($this->config['BOILER_OBJECT']) ? $this->config['BOILER_OBJECT'] : 'EspBoiler';
        $now = time();
        $online = !empty($snapshot['http_available']);
        $wasOffline = !empty($this->config['_CLOCK_WAS_OFFLINE']);

        // Track online/offline edge for "check after recovery".
        if ((int) $this->config['_CLOCK_WAS_OFFLINE'] !== ($online ? 0 : 1)) {
            $this->config['_CLOCK_WAS_OFFLINE'] = $online ? 0 : 1;
            $this->saveConfig();
        }

        if (!$online) {
            return; // nothing to compare while the boiler is unreachable
        }

        $boilerRaw = isset($snapshot['boiler_time']) ? $snapshot['boiler_time'] : null;
        if ($boilerRaw === null || $boilerRaw === '') {
            return; // ESP has not reported the boiler clock yet
        }

        $lastCheck = (int) (isset($this->config['_LAST_CLOCK_CHECK_UTC']) ? $this->config['_LAST_CLOCK_CHECK_UTC'] : 0);
        $dueDaily = ($now - $lastCheck) >= 86400;
        $recovered = $wasOffline; // offline on the previous cycle, online now
        if (!$freshRead && !$recovered && !$dueDaily) {
            return; // not time yet
        }

        $tz = isset($this->config['TIMEZONE']) ? $this->config['TIMEZONE'] : 'Europe/Kyiv';
        $threshold = isset($this->config['CLOCK_SKEW_THRESHOLD_S']) ? (int) $this->config['CLOCK_SKEW_THRESHOLD_S'] : 120;
        $res = ClockCheck::evaluate($boilerRaw, $now, $tz, $threshold);
        if ($res === null) {
            return; // unparseable boiler time; leave previous state untouched
        }

        // Persist state (internal + object properties for UI/history).
        $prevOk = !isset($this->config['_LAST_CLOCK_SKEW_S']) || $this->config['_LAST_CLOCK_SKEW_S'] === ''
            ? null
            : (abs((int) $this->config['_LAST_CLOCK_SKEW_S']) <= $threshold);
        $this->config['_LAST_CLOCK_CHECK_UTC'] = $now;
        $this->config['_LAST_CLOCK_SKEW_S'] = (int) $res['skew_s'];
        $this->config['_LAST_BOILER_TIME'] = (string) $res['boiler'];
        $this->saveConfig();

        if (function_exists('setGlobal')) {
            BoilerObject::set($o, 'boilerClock', $res['boiler']);
            BoilerObject::set($o, 'boilerClockSkewSeconds', (int) $res['skew_s']);
            BoilerObject::set($o, 'boilerClockOk', $res['ok'] ? 1 : 0);
        }

        if (function_exists('DebMes')) {
            DebMes('drazice_smart clock check: boiler=' . $res['boiler'] . ' server=' . $res['server']
                . ' skew=' . $res['skew_s'] . 's ok=' . ($res['ok'] ? '1' : '0'));
        }

        // Fire the event handler on a transition into "not ok" (fresh problem).
        if (!$res['ok'] && $prevOk !== false) {
            $handler = isset($this->config['EVENT_HANDLER']) ? (string) $this->config['EVENT_HANDLER'] : '';
            if ($handler !== '') {
                $params = array(
                    'event' => 'clock_drift',
                    'value' => (int) $res['skew_s'],
                    'boiler' => $res['boiler'],
                    'server' => $res['server'],
                );
                if (strpos($handler, 'script:') === 0 && function_exists('runScript')) {
                    runScript(substr($handler, 7), $params);
                } elseif (strpos($handler, 'script:') !== 0 && function_exists('callMethod')) {
                    callMethod($handler, $params);
                }
            }
        }
    }

    /**
     * Prune the module's own history tables so they never grow without bound.
     * The RPi stores MySQL on a small SD/flash disk, so every row costs real
     * space. Each table has its own retention (days); 0 keeps it forever.
     * Runs at most once per day (guarded by _LAST_PRUNE_UTC) regardless of how
     * often the cycle fires, and is a cheap no-op between daily runs.
     *
     * @param int $now unix UTC (injectable for tests)
     * @return array map table => rows deleted (only when it actually pruned)
     */
    private function pruneHistory($now)
    {
        $last = isset($this->config['_LAST_PRUNE_UTC']) ? (int) $this->config['_LAST_PRUNE_UTC'] : 0;
        if (($now - $last) < 86400) {
            return array(); // already pruned within the last 24h
        }

        $plan = array(
            // table => array(time column, retention-days config key)
            'drazice_presence'  => array('TS_UTC',      'PRESENCE_RETENTION_DAYS'),
            'drazice_telemetry' => array('SAMPLE_UTC',   'TELEMETRY_RETENTION_DAYS'),
            'drazice_commands'  => array('TS_UTC',       'COMMANDS_RETENTION_DAYS'),
        );

        $deleted = array();
        foreach ($plan as $table => $def) {
            list($tsCol, $cfgKey) = $def;
            $days = isset($this->config[$cfgKey]) ? (int) $this->config[$cfgKey] : 0;
            if ($days <= 0) {
                continue; // 0 (or invalid) => keep forever for this table
            }
            if (!$this->tableExists($table)) {
                continue;
            }
            $cutoff = $now - ($days * 86400);
            if (function_exists('SQLExec')) {
                // Parameterless, integer-only WHERE: no user input in the SQL.
                SQLExec('DELETE FROM ' . $table . ' WHERE ' . $tsCol . ' < ' . (int) $cutoff);
                $deleted[$table] = $days;
            }
        }

        $this->config['_LAST_PRUNE_UTC'] = $now;
        $this->saveConfig();
        return $deleted;
    }

    /**
     * Collect presence observations from BLE (ESP scan results) and Wi-Fi.
     * BLE: reads "BLE Scan Results" from the ESP. Wi-Fi: reads a property that a
     * user cron/rule fills (router/arp table) at <object>.wifiObservationsJson.
     * Returns a list of array(mac, seen_utc, source).
     */
    private function collectPresenceObservations($o, $now, $ble)
    {
        $obs = array();

        // BLE from the ESP scanner (if enabled/flashed): $ble comes from the
        // same GET /state map as the rest of the cycle (no extra ESP call).
        if (!empty($ble['available']) && !empty($ble['list'])) {
            foreach ($ble['list'] as $d) {
                if (!empty($d['MAC'])) {
                    $obs[] = array('mac' => strtolower($d['MAC']), 'seen_utc' => $now, 'source' => 'ble');
                }
            }
        }

        // Wi-Fi observations from the LAN (router/arp), filled externally.
        $wifiRaw = Repository::readProperty($o . '.wifiObservationsJson');
        if ($wifiRaw) {
            $decoded = json_decode($wifiRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $d) {
                    if (!empty($d['mac'])) {
                        $obs[] = array(
                            'mac' => strtolower($d['mac']),
                            'seen_utc' => isset($d['seen_utc']) ? (int) $d['seen_utc'] : $now,
                            'source' => 'wifi',
                        );
                    }
                }
            }
        }

        // Legacy/combined feed, if the user fills it directly.
        $legacy = Repository::readProperty($o . '.presenceObservationsJson');
        if ($legacy) {
            $decoded = json_decode($legacy, true);
            if (is_array($decoded)) {
                foreach ($decoded as $d) {
                    if (!empty($d['mac'])) {
                        $obs[] = array(
                            'mac' => strtolower($d['mac']),
                            'seen_utc' => isset($d['seen_utc']) ? (int) $d['seen_utc'] : $now,
                            'source' => isset($d['source']) ? $d['source'] : 'lan',
                        );
                    }
                }
            }
        }
        return $obs;
    }

    // ---- UI -----------------------------------------------------------------

    function usual(&$out)
    {
        // The widget NEVER contacts the ESP: it renders the DB snapshot cache
        // (kept warm by the cycle worker) and ignores ajax/op request params.
        $this->getConfig();
        $cached = $this->stateFromCache();
        $snapshot = $this->displaySnapshot($cached, time());
        $decision = Planner::decide($snapshot, $this->plannerConfig());
        $this->flattenForTemplate($out, $snapshot, $decision, null);
        $this->addDataMeta($out, $cached['meta']);
        $out['IS_ADMIN'] = 0;
    }

    /**
     * Snapshot for DISPLAY from the cached map. Stale/never-fetched cache must
     * not be shown as "ESP connected": http_available is forced to false then.
     */
    private function displaySnapshot($cached, $now)
    {
        $s = $this->buildSnapshotFromState($cached['map'], $now);
        if (!empty($cached['meta']['stale'])) {
            $s['http_available'] = false;
        }
        return $s;
    }

    /** DATA_* template vars describing the cache (age, stale badge, last fetch). */
    private function addDataMeta(&$out, $meta)
    {
        $out['DATA_HAS']      = !empty($meta['has_data']) ? 1 : 0;
        $out['DATA_AGE_S']    = ($meta['age_s'] === null) ? '' : (string) (int) $meta['age_s'];
        $out['DATA_STALE']    = !empty($meta['stale']) ? 1 : 0;
        $out['DATA_FETCH_OK'] = ($meta['fetch_ok'] === null) ? '' : ($meta['fetch_ok'] ? 1 : 0);
    }

    /** "55.5 °C"-style formatter; missing -> the "no data" text (never 0). */
    private function fmtVal($v, $suffix = '')
    {
        if ($v === null || $v === '') {
            return defined('LANG_DS_UNAVAILABLE') ? LANG_DS_UNAVAILABLE : 'no data';
        }
        return (is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.') : $v) . $suffix;
    }

    private function ynqVal($v)
    {
        if ($v === null) {
            return defined('LANG_DS_UNKNOWN') ? LANG_DS_UNKNOWN : 'unknown';
        }
        return $v ? (defined('LANG_DS_YES') ? LANG_DS_YES : 'yes')
                  : (defined('LANG_DS_NO') ? LANG_DS_NO : 'no');
    }

    /** ok | pin | no  (same rule as the Status tab badge). */
    private function connState($httpAvailable, $boilerState)
    {
        if (!$httpAvailable) {
            return 'no';
        }
        return ($boilerState === 'Require PIN') ? 'pin' : 'ok';
    }

    /**
     * PLAIN-TEXT status strings shared by the HTML shell (escaped there) and
     * the AJAX `status` op (NOT escaped: the client uses textContent).
     */
    private function statusStrings($s, $d)
    {
        $cdKnown = array_key_exists('cooldown_known', $s) ? !empty($s['cooldown_known']) : true;
        // Localised explanation (the planner's own reason_human is Ukrainian-only
        // and stays for the decision log / object properties).
        $tr = function ($k) {
            $name = 'LANG_DS_' . $k;
            return defined($name) ? constant($name) : null;
        };
        $ex = Explain::decision($s, $d, $this->config, $tr);
        return array(
            'http_available'    => !empty($s['http_available']),
            'temp_upper_f'      => $this->fmtVal($s['temp_upper'], ' °C'),
            'temp_lower_f'      => $this->fmtVal($s['temp_lower'], ' °C'),
            'heating_f'         => $this->ynqVal($s['heating']),
            'hdo_f'             => $this->ynqVal($s['hdo']),
            'energy_f'          => $this->fmtVal($s['energy_total_kwh'], ' кВт·год'),
            'cooldown_f'        => $this->fmtVal($cdKnown ? $s['cooldown_remaining_s'] : null, ' с'),
            'soc_f'             => $this->fmtVal($s['battery_soc'], ' %'),
            'power_source'      => (string) $s['power_source'],
            'power_source_f'    => Explain::powerWord((string) $s['power_source'], $tr),
            'power_human'       => Explain::power($s, $this->config, $tr),
            'power_raw'         => ($s['power_raw'] === null ? '—' : (string) $s['power_raw']),
            'tariff_active'     => !empty($s['tariff_active']),
            'guard_state'       => ($s['guard_state'] === null ? '—' : (string) $s['guard_state']),
            'decision_action'   => (string) $d['action'],
            'decision_reason'   => (string) $d['reason'],
            'decision_reason_f' => $ex['label'],
            'decision_human'    => $ex['text'],
            'decision_howto'    => $ex['howto'],
            'decision_target_f' => $this->fmtVal($d['target_c'], ' °C'),
        ) + $this->clockStrings($s);
    }

    /**
     * Boiler-clock drift strings for the UI (display only; the authoritative
     * check with history/events runs rarely in checkBoilerClock()). Computed
     * live from the snapshot so the card reflects the latest read.
     */
    private function clockStrings($s)
    {
        $raw = isset($s['boiler_time']) ? $s['boiler_time'] : null;
        $tz = isset($this->config['TIMEZONE']) ? $this->config['TIMEZONE'] : 'Europe/Kyiv';
        $threshold = isset($this->config['CLOCK_SKEW_THRESHOLD_S']) ? (int) $this->config['CLOCK_SKEW_THRESHOLD_S'] : 120;
        $res = ($raw !== null && $raw !== '') ? ClockCheck::evaluate($raw, time(), $tz, $threshold) : null;
        if ($res === null) {
            return array(
                'boiler_clock_f'     => '—',
                'boiler_clock_skew_f'=> '—',
                'boiler_clock_ok'    => true,     // unknown -> do not alarm the UI
                'boiler_clock_known' => false,
                'clock_threshold_s'  => $threshold,
            );
        }
        $sign = $res['skew_s'] > 0 ? '+' : '';
        return array(
            'boiler_clock_f'     => (string) $res['boiler'],
            'boiler_clock_skew_f'=> $sign . (int) $res['skew_s'] . ' с',
            'boiler_clock_ok'    => (bool) $res['ok'],
            'boiler_clock_known' => true,
            'clock_threshold_s'  => $threshold,
        );
    }

    /** Flatten nested snapshot/decision/config into flat [#VAR#] keys. */
    private function flattenForTemplate(&$out, $s, $d, $forecast)
    {
        $st = $this->statusStrings($s, $d);

        $out['HTTP_AVAILABLE'] = $st['http_available'] ? 1 : 0;
        $out['TEMP_UPPER_F']   = SettingsSaver::esc($st['temp_upper_f']);
        $out['TEMP_LOWER_F']   = SettingsSaver::esc($st['temp_lower_f']);
        $out['HEATING_F']      = SettingsSaver::esc($st['heating_f']);
        $out['HDO_F']          = SettingsSaver::esc($st['hdo_f']);
        $out['ENERGY_F']       = SettingsSaver::esc($st['energy_f']);
        // Externally-sourced strings (ESP text, MajorDoMo property values): escaped for the HTML shell.
        $out['POWER_SOURCE']   = SettingsSaver::esc($st['power_source']);
        $out['POWER_HUMAN']    = SettingsSaver::esc($st['power_human']);
        $out['POWER_RAW']      = SettingsSaver::esc($st['power_raw']);
        $out['POWER_AGE_F']    = (isset($s['power_age_s']) && $s['power_age_s'] !== null) ? (string) (int) $s['power_age_s'] : '—';
        $out['POWER_TTL']      = (int) (isset($this->config['POWER_FRESHNESS_TTL_S']) ? $this->config['POWER_FRESHNESS_TTL_S'] : 0);
        $out['SOC_F']          = SettingsSaver::esc($st['soc_f']);
        $out['TARIFF_ACTIVE']  = $st['tariff_active'] ? 1 : 0;
        $out['TARIFF_METER_LOCAL'] = $s['tariff_meter_local'];
        $out['GUARD_STATE']    = SettingsSaver::esc($st['guard_state']);
        $out['COOLDOWN_F']     = SettingsSaver::esc($st['cooldown_f']);
        $out['DECISION_ACTION'] = SettingsSaver::esc($st['decision_action']);
        $out['DECISION_REASON'] = SettingsSaver::esc($st['decision_reason']);
        $out['DECISION_HUMAN']  = SettingsSaver::esc($st['decision_human']);
        $out['DECISION_REASON_F'] = SettingsSaver::esc($st['decision_reason_f']);
        $out['DECISION_HOWTO']  = SettingsSaver::esc($st['decision_howto']);
        $out['POWER_SOURCE_F']  = SettingsSaver::esc($st['power_source_f']);
        $out['DECISION_TARGET_F'] = SettingsSaver::esc($st['decision_target_f']);
        $out['DECISION_ALLOWED'] = !empty($d['allowed']) ? 1 : 0;
        // Boiler clock drift (display + highlight when out of threshold).
        $out['BOILER_CLOCK_F']      = SettingsSaver::esc($st['boiler_clock_f']);
        $out['BOILER_CLOCK_SKEW_F'] = SettingsSaver::esc($st['boiler_clock_skew_f']);
        $out['BOILER_CLOCK_OK']     = !empty($st['boiler_clock_ok']) ? 1 : 0;
        $out['BOILER_CLOCK_KNOWN']  = !empty($st['boiler_clock_known']) ? 1 : 0;
        $out['CLOCK_THRESHOLD_S']   = (int) $st['clock_threshold_s'];
        $out['CFG_CLOCK_SKEW_THRESHOLD_S'] = (int) (isset($this->config['CLOCK_SKEW_THRESHOLD_S']) ? $this->config['CLOCK_SKEW_THRESHOLD_S'] : 120);

        if (is_array($forecast)) {
            $out['FC_AVAILABLE']  = !empty($forecast['available']) ? 1 : 0;
            $out['FC_TODAY_F']    = $this->fmtVal($forecast['today_remaining_kwh'], ' кВт·год');
            $out['FC_TOMORROW_F'] = $this->fmtVal($forecast['tomorrow_kwh'], ' кВт·год');
            $out['FC_PROVIDER']   = ($forecast['provider'] === null ? '—' : SettingsSaver::esc($forecast['provider']));
            $out['FC_QUALITY']    = SettingsSaver::esc($forecast['quality']);
            $out['FC_HUMAN']      = SettingsSaver::esc($forecast['human']);
        }

        // Flatten whole config as CFG_<KEY> for form fields.
        foreach ($this->config as $k => $v) {
            if (strpos($k, '_') === 0) {
                continue; // skip internal keys
            }
            if ($k === 'ESP_PASS') {
                $out['CFG_' . $k] = ($v === '' ? '' : '********');
                continue;
            }
            // HTML-escaped: these go into value="..." attributes and <textarea>
            // bodies; raw quotes (e.g. a JSON roster) would truncate the field.
            $out['CFG_' . $k] = SettingsSaver::esc($v);
        }
    }

    function admin(&$out)
    {
        global $this_computer;
        $this->getConfig();

        // AJAX JSON endpoints (pattern of solarPlanner/onCharger): admin() is
        // only reached through the authenticated admin panel; usual() never
        // dispatches ajax. Terminates the request.
        if (AjaxApi::isAjaxRequest($_REQUEST)) {
            $this->handleAjax();
        }

        // Settings introduced after the first install get their defaults here
        // (install() only seeds keys at install time). Not persisted until the
        // user saves; the planner reads the same defaults via isset().
        foreach (array('CHARGE_PRIORITY_ENABLED' => 0, 'CHARGE_PRIORITY_SOC' => 100,
            'CHARGE_PRIORITY_HYST' => 5, 'CHARGE_RELEASED_LATCHED' => 0, 'ALLOW_BATTERY_HEAT' => 0,
            'TARIFF_ENABLED' => 1,
            'VACATION_ENABLED' => 0, 'VACATION_START_UTC' => 0, 'VACATION_END_UTC' => 0, 'VACATION_ALLOW_SURPLUS' => 0,
            'HDO_CONTROL_ENABLED' => 0, 'HDO_CONTROL_DRY_RUN' => 1, 'HDO_DESIRED' => 1, 'HDO_OVERRIDE_LATCHED' => 0) as $dk => $dv) {
            if (!isset($this->config[$dk])) {
                $this->config[$dk] = $dv;
            }
        }

        $op = isset($_REQUEST['op']) ? $_REQUEST['op'] : '';
        $rejectedInput = array();

        // CSRF: all mutating ops require a valid session token.
        if ($op !== '' && $op !== 'tab') {
            if (!$this->checkCsrf()) {
                $out['ERROR'] = 'csrf';
                $op = '';
            }
        }

        // Legacy (no-JS) POST ops: kept as a fallback. They call the SAME op*
        // methods as the AJAX endpoints; only the ops themselves may touch the ESP.
        switch ($op) {
            case 'save_settings':
                $saved = $this->opSaveSettings($_REQUEST);
                // Visible result for the user (flat keys for the parser).
                $out['SAVE_RESULT'] = empty($saved['errors']) ? 'ok' : 'error';
                $rows = array();
                foreach ($saved['errors'] as $field => $code) {
                    $rows[] = array(
                        'FIELD'  => SettingsSaver::esc($field),
                        'REASON' => SettingsSaver::esc($code === 'json'
                            ? (defined('LANG_DS_SAVE_ERR_JSON') ? LANG_DS_SAVE_ERR_JSON : 'must be a valid JSON object')
                            : (defined('LANG_DS_SAVE_ERR_NUM') ? LANG_DS_SAVE_ERR_NUM : 'must be a number')),
                    );
                }
                $out['SAVE_ERRORS'] = $rows;
                $rejectedInput = $saved['rejected'];
                break;
            case 'boost':
                $this->opBoost($_REQUEST);
                break;
            case 'cancel_boost':
                $this->opCancelBoost();
                break;
            case 'enable_automation':
                $this->opSetAutomation(true);
                break;
            case 'disable_automation':
                $this->opSetAutomation(false);
                break;
            case 'set_dry_run':
                $this->opSetDryRun($_REQUEST);
                break;
            case 'scan_network':
                $scan = $this->opScanNetwork($_REQUEST);
                // Flatten for the parser: a list of devices OR a need_base_ip error.
                if (isset($scan['error'])) {
                    $out['SCAN_NEED_BASE_IP'] = ($scan['error'] === 'need_base_ip') ? 1 : 0;
                } else {
                    $out['SCAN_RESULT'] = $scan;
                }
                break;
            case 'probe_host':
                $probe = $this->opProbeHost($_REQUEST);
                // Flat keys (the parser cannot branch on nested PROBE_RESULT.*).
                $out['PROBE_CMD'] = 1;
                $out['PROBE_REACHABLE'] = !empty($probe['ok']) ? 1 : 0;
                $out['PROBE_IS_BOILER'] = !empty($probe['is_boiler']) ? 1 : 0;
                $out['PROBE_HOST'] = SettingsSaver::esc(isset($probe['host']) ? $probe['host'] : '');
                break;
            case 'discover_properties':
                $out['PROPERTY_CANDIDATES'] = $this->discoverProperties();
                break;
            case 'request_target':
                $out['CMD_RESULT'] = $this->opRequestTarget($_REQUEST);
                break;
            case 'request_mode':
                $out['CMD_RESULT'] = $this->opRequestMode($_REQUEST);
                break;
            case 'release_ble':
                $out['CMD_RESULT'] = $this->opButton('Boiler Release BLE');
                break;
            case 'reconnect_ble':
                $out['CMD_RESULT'] = $this->opButton('Boiler Reconnect BLE');
                break;
            case 'send_pin':
                $pinRes = $this->opSendPin($_REQUEST);
                $out['CMD_RESULT'] = $pinRes;
                // Flat keys for the parser (it cannot read nested CMD_RESULT.*).
                $out['PIN_CMD'] = 1;
                $reason = isset($pinRes['reason']) ? $pinRes['reason'] : '';
                $out['PIN_ACCEPTED'] = !empty($pinRes['accepted']) ? 1 : 0;
                // "sent": transport POST succeeded but the boiler is not yet Connected.
                $out['PIN_SENT'] = (!empty($pinRes['ok']) && empty($pinRes['accepted'])) ? 1 : 0;
                // bad_pin -> validator rejected the input.
                $out['PIN_BAD'] = ($reason === 'bad_pin') ? 1 : 0;
                // The ESP ANSWERED with an HTTP error (404/400...): a request problem, not "unreachable".
                $out['PIN_HTTP_ERR'] = ($reason === 'http_error') ? 1 : 0;
                $out['PIN_HTTP_CODE'] = isset($pinRes['http_code']) ? (int) $pinRes['http_code'] : 0;
                // ESP unreachable / transport / auth failure.
                $out['PIN_ESP_DOWN'] = (empty($pinRes['ok']) && $reason !== 'bad_pin' && $reason !== 'http_error') ? 1 : 0;
                // Stale-PIN warning + post-submit trace snapshot (never PIN digits).
                $out['PIN_STALE'] = !empty($pinRes['stale']) ? 1 : 0;
                $traceTail = isset($pinRes['trace_tail']) && is_array($pinRes['trace_tail']) ? $pinRes['trace_tail'] : array();
                $out['PIN_TRACE_TAIL'] = SettingsSaver::esc(implode(' | ', $traceTail));
                $out['PIN_LAST_DISCONNECT'] = SettingsSaver::esc(isset($pinRes['last_disconnect']) ? $pinRes['last_disconnect'] : '');
                break;
            case 'ble_scan_clear':
                $out['CMD_RESULT'] = $this->opButton('BLE Scan Clear');
                break;
            case 'select_boiler':
                $out['CMD_RESULT'] = $this->opSelectBoiler($_REQUEST);
                break;
            case 'lease_renew':
                $out['CMD_RESULT'] = $this->opButton('Boiler Lease Renew');
                break;
            case 'provision_simple_devices':
                $pr = $this->provisionSimpleDevices();
                $out['PROVISION_RESULT'] = 1;
                $out['PROVISION_KIND'] = $pr['kind'];
                $out['PROVISION_KEY'] = $pr['key'];
                break;
        }
        if (AjaxApi::classify($op) === 'mutating') {
            // The ESP state may have changed: the next AJAX read must refetch.
            StateCache::markDirty();
        }

        // ---- render: ZERO ESP calls (DB snapshot cache + DB/property reads only) ----
        $now = time();
        $tab = (isset($this->tab) && $this->tab !== '') ? (string) $this->tab : 'status';
        $cached = $this->stateFromCache();
        $map = $cached['map'];

        $snapshot = $this->displaySnapshot($cached, $now);
        $decision = Planner::decide($snapshot, $this->plannerConfig());
        $forecast = ($tab === 'policy') ? $this->forecastSnapshot($now) : null;

        $this->flattenForTemplate($out, $snapshot, $decision, $forecast);
        $this->addDataMeta($out, $cached['meta']);
        // Show rejected input back to the user instead of discarding it.
        foreach ($rejectedInput as $f => $raw) {
            $out['CFG_' . $f] = SettingsSaver::esc($raw);
        }
        $out['IS_ADMIN'] = 1;
        $out['CSRF']     = $this->csrfToken();
        $out['AUTOMATION_ENABLED'] = !empty($this->config['AUTOMATION_ENABLED']) ? 1 : 0;
        $out['DRY_RUN']  = !empty($this->config['DRY_RUN']) ? 1 : 0;
        $out['PRESENCE_ENABLED'] = !empty($this->config['PRESENCE_ENABLED']) ? 1 : 0;
        $out['SOLAR_SURPLUS_ENABLED'] = !empty($this->config['SOLAR_SURPLUS_ENABLED']) ? 1 : 0;
        $out['SIMPLE_DEVICES_ENABLED'] = !empty($this->config['SIMPLE_DEVICES_ENABLED']) ? 1 : 0;
        $out['SIMPLE_DEVICES_AVAILABLE'] = SimpleDevices::available() ? 1 : 0;
        if ($tab === 'advanced') {
            $sdDiag = SimpleDevices::diagnose();
            $out['SD_DIAG_MISSING'] = SettingsSaver::esc(empty($sdDiag['missing']) ? '' : implode(', ', $sdDiag['missing']));
            $out['SD_DIAG_NOTE'] = SettingsSaver::esc($sdDiag['note']);
            // Link status of every simple device of the effective map, so the
            // "create" button is offered only when something is not linked yet.
            $sdRows = array();
            $sdMissing = 0;
            foreach (SimpleDevices::linkStatus($this->simpleDevicesMap()) as $key => $row) {
                if (!$row['linked']) {
                    $sdMissing++;
                }
                $sdRows[] = array(
                    'KEY'          => SettingsSaver::esc($key),
                    'OBJECT'       => SettingsSaver::esc($row['object']),
                    'TITLE'        => SettingsSaver::esc($row['title']),
                    'TYPE'         => SettingsSaver::esc($row['type']),
                    'LINKED'       => $row['linked'] ? 1 : 0,
                    'DEVICE_ID'    => (int) $row['device_id'],
                    'DEVICE_TITLE' => SettingsSaver::esc($row['device_title']),
                );
            }
            $out['SD_ROWS'] = $sdRows;
            $out['SD_MISSING'] = $sdMissing;
            $out['SD_HAS_MISSING'] = $sdMissing > 0 ? 1 : 0;
            $out['SD_DEFAULT_MAP'] = SettingsSaver::esc(json_encode(SimpleDevices::defaultMap(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $out['WIFI_OBS_JSON'] = SettingsSaver::esc((string) Repository::readProperty(
                (isset($this->config['BOILER_OBJECT']) ? $this->config['BOILER_OBJECT'] : 'EspBoiler') . '.wifiObservationsJson'));
            $out['OPTS_HANDLERS_DATALIST'] = $this->handlerDatalistHtml();
        }
        // Vacation: expose UTC window back as local datetime-local strings.
        $vs = (int) (isset($this->config['VACATION_START_UTC']) ? $this->config['VACATION_START_UTC'] : 0);
        $ve = (int) (isset($this->config['VACATION_END_UTC']) ? $this->config['VACATION_END_UTC'] : 0);
        $out['CFG_VACATION_START_LOCAL'] = $this->utcToLocalDatetime($vs);
        $out['CFG_VACATION_END_LOCAL'] = $this->utcToLocalDatetime($ve);
        $out['VACATION_DAYS'] = ($vs > 0 && $ve > $vs) ? rtrim(rtrim(number_format(($ve - $vs) / 86400, 1, '.', ''), '0'), '.') : '';
        $out['CFG_VACATION_ENABLED'] = !empty($this->config['VACATION_ENABLED']) ? 1 : 0;
        $out['CFG_VACATION_ALLOW_SURPLUS'] = !empty($this->config['VACATION_ALLOW_SURPLUS']) ? 1 : 0;
        $out['CFG_HDO_CONTROL_ENABLED'] = !empty($this->config['HDO_CONTROL_ENABLED']) ? 1 : 0;
        $out['CFG_HDO_CONTROL_DRY_RUN'] = !empty($this->config['HDO_CONTROL_DRY_RUN']) ? 1 : 0;
        $out['HDO_DESIRED_F'] = (isset($this->config['HDO_DESIRED']) && (int) $this->config['HDO_DESIRED'] === 0) ? 0 : 1;

        $hv = Transport::validateHost(isset($this->config['ESP_HOST']) ? $this->config['ESP_HOST'] : '');
        $out['HOST_OK'] = !empty($hv['ok']) ? 1 : 0;
        $out['HOST_REASON'] = $hv['reason'];

        // Recent decisions + commands: DB only, only on the log tab.
        if ($tab === 'log') {
            $out['LOG_DECISIONS'] = $this->recentDecisions();
            $out['LOG_COMMANDS'] = $this->recentCommands();
        }
        $out['PRESENT_PEOPLE'] = Repository::readProperty('EspBoiler.presentPeople');

        // BLE scan results (list of discovered boilers to choose from), from the cache.
        // Use flat keys for the MajorDoMo parser (no dotted nesting in [#begin#]).
        $ble = $this->bleFromState($map);
        $out['BLE_AVAILABLE']    = !empty($ble['available']) ? 1 : 0;
        $out['BLE_HAS_DEVICES']  = (!empty($ble['list'])) ? 1 : 0;
        // MAC/NAME come over the air: escape (MAC lands in a hidden value="...").
        $bleRows = array();
        foreach ($ble['list'] as $row) {
            $row['MAC']  = SettingsSaver::esc($row['MAC']);
            $row['NAME'] = SettingsSaver::esc($row['NAME']);
            $bleRows[] = $row;
        }
        $out['BLE_DEVICES']      = $bleRows;
        $out['SELECTED_BLE_MAC'] = SettingsSaver::esc(isset($this->config['BOILER_BLE_MAC']) ? $this->config['BOILER_BLE_MAC'] : '');

        // Full diagnostics: flatten every firmware entity into DG_<KEY> keys.
        $diag = $this->diagFromState($map);
        foreach ($diag as $k => $v) {
            $out['DG_' . $k] = ($v === null || $v === '') ? '—' : SettingsSaver::esc($v);
        }
        // Pairing diagnostics for the Setup/Diagnostics tabs. CONNECT_COUNT is
        // rendered into a hidden form field (digits only, empty when unknown)
        // so a later submit can detect that the boiler issued a new PIN.
        $ccRaw = isset($diag['CONNECT_COUNT']) ? $diag['CONNECT_COUNT'] : null;
        $out['PAIR_RENDER_CC'] = is_numeric($ccRaw) ? (string) (int) $ccRaw : '';
        $out['PAIR_HAS_TRACE'] = (isset($diag['PAIR_TRACE']) && $diag['PAIR_TRACE'] !== null && $diag['PAIR_TRACE'] !== '' && $diag['PAIR_TRACE'] !== '-') ? 1 : 0;
        $dc = PairTrace::parseDisconnect(isset($diag['LAST_DISCONNECT']) ? $diag['LAST_DISCONNECT'] : null);
        $out['PAIR_HAS_DISC'] = empty($dc) ? 0 : 1;

        // Property-binding dropdowns + timezone list: policy tab only (the
        // property scan is the heaviest DB query of the page).
        if ($tab === 'policy') {
            // ONE shared <datalist> rendered once + an <input list="ds-props"> per
            // binding field. Previously six full <select>s each repeated every
            // MajorDoMo property (6 x N options), which made the Policy tab very
            // slow on real installs. The datalist also lets the user search by
            // typing; an empty input means "not bound"; a stored value that is no
            // longer in the list is kept because it is the input's own value.
            $props = $this->allProperties();
            $out['HAS_PROPERTIES'] = (!empty($props)) ? 1 : 0;
            $out['OPTS_PROPS_DATALIST'] = $this->propertyDatalistHtml($props);
            $out['OPTS_TIMEZONE'] = $this->timezoneOptionsHtml(
                isset($this->config['TIMEZONE']) ? (string) $this->config['TIMEZONE'] : ''
            );
        }

        // Client side (FEAT-003 JS): translated strings + config, safe inside <script>.
        $out['JS_I18N'] = AjaxApi::jsonForScript(AjaxApi::jsStrings(function ($k) {
            $c = 'LANG_DS_' . $k;
            return defined($c) ? constant($c) : null;
        }));
        $out['JS_CONFIG'] = AjaxApi::jsonForScript(array(
            'csrf'      => $this->csrfToken(),
            'tab'       => $tab,
            'ops'       => array('read' => AjaxApi::READ_OPS, 'mutating' => AjaxApi::MUTATING_OPS),
            'intervals' => array('statusMs' => 10000, 'pairingMs' => 2000, 'idleMs' => 10000),
            'version'   => self::MODULE_VERSION,
        ));
        $out['JS_VER'] = self::MODULE_VERSION;
    }

    // ---- ESP entity map -> UI structures (no ESP access) --------------------

    /**
     * Parse the BLE scan JSON from the "BLE Scan Results" text sensor in the
     * entity map (present when ble_scan.yaml / boiler-scan.yaml is flashed).
     * Returns array(available, list of array(MAC, NAME, BOILER, RSSI)) sorted
     * boilers-first, then by RSSI (strongest first).
     */
    public function bleFromState($map)
    {
        $json = Transport::entity($map, 'text_sensor', 'BLE Scan Results');
        $raw = Transport::stringValue($json);
        $out = array('available' => ($json !== null), 'list' => array());
        if ($raw === null || $raw === '') {
            return $out;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $out;
        }
        // Boilers first, then by RSSI (strongest first).
        usort($decoded, function ($a, $b) {
            $ab = !empty($a['boiler']) ? 1 : 0;
            $bb = !empty($b['boiler']) ? 1 : 0;
            if ($ab !== $bb) {
                return $bb - $ab;
            }
            $ar = isset($a['rssi']) ? (int) $a['rssi'] : -999;
            $br = isset($b['rssi']) ? (int) $b['rssi'] : -999;
            return $br - $ar;
        });
        foreach ($decoded as $d) {
            if (!is_array($d) || !isset($d['mac'])) {
                continue;
            }
            $out['list'][] = array(
                'MAC'     => $d['mac'],
                'NAME'    => isset($d['name']) ? $d['name'] : '',
                'BOILER'  => !empty($d['boiler']) ? 1 : 0,
                'RSSI'    => isset($d['rssi']) ? (int) $d['rssi'] : '',
            );
        }
        return $out;
    }

    /** BLE scan devices (public API kept): cached unless stale. */
    public function bleScanDevices()
    {
        $r = $this->refreshState(false);
        return $this->bleFromState($r['map']);
    }

    /** All diagnostic / full-feature entities for the Diagnostics tab, from the map. */
    public function diagFromState($map)
    {
        $read = function ($domain, $name) use ($map) {
            $j = Transport::entity($map, $domain, $name);
            if ($domain === 'sensor' || $domain === 'number') {
                return Transport::numericValue($j);
            }
            return Transport::stringValue($j);
        };
        return array(
            'UPTIME'        => $read('sensor', 'ESP Uptime'),
            'RSSI'          => $read('sensor', 'ESP Wi-Fi RSSI'),
            'FREE_HEAP'     => $read('sensor', 'ESP Free Heap'),
            'MIN_FREE_HEAP' => $read('sensor', 'ESP Min Free Heap'),
            'RESET_REASON'  => $read('text_sensor', 'ESP Reset Reason'),
            'ESPHOME_VER'   => $read('text_sensor', 'ESPHome Version'),
            'GUARD_STATE'   => $read('text_sensor', 'Boiler Guard State'),
            'GUARD_REASON'  => $read('text_sensor', 'Boiler Guard Reason'),
            'CONT_HEAT_S'   => $read('sensor', 'Boiler Continuous Heat Seconds'),
            'COOLDOWN_S'    => $read('sensor', 'Boiler Cooldown Remaining'),
            'LEASE_S'       => $read('sensor', 'Boiler Lease Remaining'),
            'BOILER_VER'    => $read('text_sensor', 'Boiler Version'),
            'BOILER_NAME'   => $read('text_sensor', 'Boiler Name'),
            'BOILER_STATE'  => $read('text_sensor', 'Boiler State'),
            'REQ_TARGET'    => $read('number', 'Boiler Requested Target'),
            'REQ_MODE'      => $read('select', 'Boiler Requested Mode'),
            // Actual values reported BY the boiler (read-only, firmware >= 1.9.0);
            // REQ_* above are only what the module asked through the guard.
            'BOILER_SETPOINT' => $read('sensor', 'Boiler Setpoint'),
            'BOILER_MODE'     => $read('text_sensor', 'Boiler Mode'),
            // Pairing diagnostics (local firmware modification, see UPSTREAM.md).
            'PAIR_TRACE'       => $read('text_sensor', 'Boiler Pair Trace'),
            'LAST_DISCONNECT'  => $read('text_sensor', 'Boiler Last Disconnect'),
            'PAIR_ATTEMPTS'    => $read('sensor', 'Boiler Pair Attempts'),
            'CONNECT_COUNT'    => $read('sensor', 'Boiler Connect Count'),
        );
    }

    /** Diagnostics (public API kept): cached unless stale. */
    public function readDiagnostics()
    {
        $r = $this->refreshState(false);
        return $this->diagFromState($r['map']);
    }

    // ---- AJAX JSON endpoints ------------------------------------------------

    /** Print the JSON answer and terminate (solarPlanner/onCharger pattern). */
    private function handleAjax()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        list($status, $payload) = $this->ajaxDispatch($_REQUEST, $method);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /**
     * Testable AJAX core: NO output, NO exit.
     * @return array(int httpStatus, array payload)
     */
    public function ajaxDispatch(array $req, $method)
    {
        $this->getConfig();
        $op = (isset($req['op']) && is_string($req['op'])) ? $req['op'] : '';
        // Authorization BEFORE any other work: a rejected op makes no ESP
        // call and changes no config.
        $auth = AjaxApi::authorize($op, $method, $this->checkCsrf($req));
        if (!$auth['ok']) {
            return array($auth['status'], array('ok' => false, 'op' => $op, 'error' => $auth['error']));
        }
        try {
            if (AjaxApi::classify($op) === 'read') {
                return $this->ajaxRead($op, $req);
            }
            return $this->ajaxMutate($op, $req);
        } catch (\Throwable $e) {
            return array(500, array('ok' => false, 'op' => $op, 'error' => 'server'));
        }
    }

    /** Whitelist of error fields safe to send to the browser. */
    private function safeErr($err)
    {
        $o = array();
        if (is_array($err)) {
            foreach (array('stage', 'reason', 'http_code') as $k) {
                if (isset($err[$k])) {
                    $o[$k] = $err[$k];
                }
            }
        }
        return $o;
    }

    private function ajaxRead($op, array $req)
    {
        $force = (isset($req['force']) && $req['force'] === '1');
        switch ($op) {
            case 'status':
            case 'diag':
            case 'pairing':
                $r = $this->refreshState($force);
                break;
            case 'ble_scan':
                $r = $this->refreshState(true); // button press: always a fresh scan list
                break;
            default: // log, policy: DB only, no ESP
                $r = $this->stateFromCache();
        }
        $meta = $r['meta'];
        if ($meta['fetch_ok'] === false) {
            $meta['fetch_error'] = $this->safeErr(isset($r['error']) ? $r['error'] : array());
        }
        $map = $r['map'];
        switch ($op) {
            case 'status':
                $data = $this->statusData($map, $meta);
                break;
            case 'diag':
                $data = array('dg' => $this->diagFromState($map), 'pair' => $this->pairData($map));
                break;
            case 'pairing':
                $data = $this->pairData($map);
                break;
            case 'ble_scan':
                $ble = $this->bleFromState($map);
                $list = array();
                foreach ($ble['list'] as $row) {
                    $list[] = array('mac' => (string) $row['MAC'], 'name' => (string) $row['NAME'],
                        'boiler' => !empty($row['BOILER']), 'rssi' => ($row['RSSI'] === '' ? null : (int) $row['RSSI']));
                }
                $data = array('available' => !empty($ble['available']), 'list' => $list,
                    'selected_mac' => isset($this->config['BOILER_BLE_MAC']) ? (string) $this->config['BOILER_BLE_MAC'] : '');
                break;
            case 'log':
                $data = array('decisions' => array(), 'commands' => array());
                foreach ($this->recentDecisions() as $x) {
                    $data['decisions'][] = array('time' => $x['TIME'], 'action' => $x['ACTION'],
                        'reason' => $x['REASON'], 'allowed' => !empty($x['ALLOWED']));
                }
                foreach ($this->recentCommands() as $x) {
                    $data['commands'][] = array('time' => $x['TIME'], 'kind' => $x['KIND'],
                        'payload' => $x['PAYLOAD'], 'status' => $x['STATUS']);
                }
                break;
            default: // policy
                $fc = $this->forecastSnapshot(time());
                $data = array('forecast' => $fc,
                    'today_f' => $this->fmtVal(isset($fc['today_remaining_kwh']) ? $fc['today_remaining_kwh'] : null, ' кВт·год'),
                    'tomorrow_f' => $this->fmtVal(isset($fc['tomorrow_kwh']) ? $fc['tomorrow_kwh'] : null, ' кВт·год'));
        }
        return array(200, array('ok' => true, 'op' => $op, 'meta' => $meta, 'data' => $data));
    }

    private function statusData($map, $meta)
    {
        $s = $this->buildSnapshotFromState($map, time());
        if (!empty($meta['stale'])) {
            $s['http_available'] = false;
        }
        $d = Planner::decide($s, $this->plannerConfig());
        $boilerState = Transport::stringValue(Transport::entity($map, 'text_sensor', 'Boiler State'));
        $data = $this->statusStrings($s, $d);
        $data['boiler_state'] = $boilerState;
        $data['conn'] = $this->connState(!empty($s['http_available']), $boilerState);
        return $data;
    }

    private function pairData($map)
    {
        $d = $this->diagFromState($map);
        $cc = $d['CONNECT_COUNT'];
        $trace = $d['PAIR_TRACE'];
        $disc = PairTrace::parseDisconnect($d['LAST_DISCONNECT']);
        return array(
            'boiler_state'       => $d['BOILER_STATE'],
            'require_pin'        => ($d['BOILER_STATE'] === 'Require PIN'),
            'connect_count'      => is_numeric($cc) ? (string) (int) $cc : '',
            'pair_attempts'      => $d['PAIR_ATTEMPTS'],
            'trace_raw'          => $trace,
            'trace_tail'         => PairTrace::lastEvents($trace, 3),
            'last_disconnect_raw' => $d['LAST_DISCONNECT'],
            'last_disconnect'    => empty($disc) ? new \stdClass() : $disc,
            'has_trace'          => ($trace !== null && $trace !== '' && $trace !== '-'),
        );
    }

    /**
     * Tariff::evaluate config. With the tariff on: the configured cheap window.
     * With it off: a fixed 23:00-07:00 night (no offset/guards) used only to
     * tell day from night; the caller then forces tariff_active=false.
     */
    public static function tariffConfig(array $c, $tariffOn)
    {
        $tz = isset($c['TIMEZONE']) ? $c['TIMEZONE'] : 'Europe/Kyiv';
        if (!$tariffOn) {
            return array(
                'timezone' => $tz, 'cheap_start_hour' => 23, 'cheap_start_min' => 0,
                'cheap_end_hour' => 7, 'cheap_end_min' => 0, 'meter_offset_seconds' => 0,
                'start_guard_seconds' => 0, 'end_guard_seconds' => 0,
            );
        }
        $g = function ($k, $d) use ($c) {
            return (isset($c[$k]) && is_numeric($c[$k])) ? $c[$k] : $d;
        };
        return array(
            'timezone'             => $tz,
            'cheap_start_hour'     => $g('CHEAP_START_HOUR', 23),
            'cheap_start_min'      => $g('CHEAP_START_MIN', 0),
            'cheap_end_hour'       => $g('CHEAP_END_HOUR', 7),
            'cheap_end_min'        => $g('CHEAP_END_MIN', 0),
            'meter_offset_seconds' => $g('METER_OFFSET_SECONDS', 0),
            'start_guard_seconds'  => $g('START_GUARD_SECONDS', 0),
            'end_guard_seconds'    => $g('END_GUARD_SECONDS', 0),
        );
    }

    private function banner($kind, $key)
    {
        return array('kind' => $kind, 'key' => $key);
    }

    /** Failure banner for an ESP write that returned null. */
    private function espFailBanner($err, $httpKey = 'FETCH_ERROR', $downKey = 'FETCH_ERROR')
    {
        $stage = (is_array($err) && isset($err['stage'])) ? $err['stage'] : '';
        return $this->banner('danger', $stage === 'http' ? $httpKey : $downKey);
    }

    private function ajaxMutate($op, array $req)
    {
        $result = array();
        $banner = null;
        switch ($op) {
            case 'save_settings':
                $saved = $this->opSaveSettings($req);
                $errs = array();
                foreach ($saved['errors'] as $field => $code) {
                    $errs[] = array('field' => (string) $field, 'reason' => ($code === 'json' ? 'json' : 'num'));
                }
                // NEVER echo the config (ESP_PASS): only field errors + the rejected raw input.
                $result = array('errors' => $errs, 'rejected' => $saved['rejected']);
                $banner = empty($errs) ? $this->banner('success', 'SAVE_OK') : $this->banner('danger', 'SAVE_FAIL');
                break;
            case 'send_pin':
                $p = $this->opSendPin($req);
                if (isset($p['reason']) && $p['reason'] === 'bad_pin') {
                    return $this->badInput($op, 'bad_pin', $this->banner('warning', 'SETUP_PIN_BAD'));
                }
                $result = array(
                    'ok'              => !empty($p['ok']),
                    'accepted'        => !empty($p['accepted']),
                    'state'           => $p['state'],
                    'reason'          => $p['reason'],
                    'stale'           => !empty($p['stale']),
                    'trace_tail'      => $p['trace_tail'],
                    'last_disconnect' => $p['last_disconnect'],
                );
                if (isset($p['http_code'])) {
                    $result['http_code'] = $p['http_code'];
                }
                if (!empty($p['accepted'])) {
                    $banner = $this->banner('success', 'SETUP_PIN_ACCEPTED');
                } elseif (!empty($p['ok'])) {
                    $banner = $this->banner('warning', 'SETUP_PIN_SENT');
                } elseif ($p['reason'] === 'http_error') {
                    $banner = $this->banner('danger', 'SETUP_PIN_HTTP_ERR');
                } else {
                    $banner = $this->banner('danger', 'SETUP_PIN_ESP_DOWN');
                }
                break;
            case 'request_target':
                $r = $this->opRequestTarget($req);
                if (isset($r['reason'])) {
                    return $this->badInput($op, $r['reason'], $this->banner('danger', 'INVALID_VALUE'));
                }
                $result = array('ok' => !empty($r['ok']), 'readback' => $r['readback'], 'error' => $this->safeErr($r['error']));
                $banner = !empty($r['ok']) ? $this->banner('success', 'SAVE_OK') : $this->espFailBanner($r['error']);
                break;
            case 'request_mode':
                $r = $this->opRequestMode($req);
                if (isset($r['reason'])) {
                    return $this->badInput($op, $r['reason'], $this->banner('danger', 'INVALID_VALUE'));
                }
                $result = array('ok' => !empty($r['ok']), 'readback' => $r['readback'], 'error' => $this->safeErr($r['error']));
                $banner = !empty($r['ok']) ? $this->banner('success', 'SAVE_OK') : $this->espFailBanner($r['error']);
                break;
            case 'boost':
                $result = $this->opBoost($req);
                $banner = $this->banner('success', 'SAVE_OK');
                break;
            case 'cancel_boost':
                $this->opCancelBoost();
                $result = array('boost_until_utc' => 0);
                $banner = $this->banner('success', 'SAVE_OK');
                break;
            case 'enable_automation':
            case 'disable_automation':
                $this->opSetAutomation($op === 'enable_automation');
                $result = array('automation_enabled' => !empty($this->config['AUTOMATION_ENABLED']));
                $banner = $this->banner('success', 'SAVE_OK');
                break;
            case 'set_dry_run':
                $this->opSetDryRun($req);
                $result = array('dry_run' => !empty($this->config['DRY_RUN']));
                $banner = $this->banner('success', 'SAVE_OK');
                break;
            case 'release_ble':
            case 'reconnect_ble':
            case 'lease_renew':
            case 'ble_scan_clear':
                $names = array('release_ble' => 'Boiler Release BLE', 'reconnect_ble' => 'Boiler Reconnect BLE',
                    'lease_renew' => 'Boiler Lease Renew', 'ble_scan_clear' => 'BLE Scan Clear');
                $r = $this->opButton($names[$op]);
                $result = array('ok' => !empty($r['ok']), 'error' => $this->safeErr($r['error']));
                $banner = !empty($r['ok']) ? $this->banner('success', 'SAVE_OK') : $this->espFailBanner($r['error']);
                break;
            case 'select_boiler':
                $r = $this->opSelectBoiler($req);
                if (empty($r['ok'])) {
                    return $this->badInput($op, isset($r['reason']) ? $r['reason'] : 'bad_mac', $this->banner('danger', 'INVALID_VALUE'));
                }
                $result = array('ok' => true, 'mac' => $r['mac']);
                $banner = $this->banner('success', 'SAVE_OK');
                break;
            case 'probe_host':
                $r = $this->opProbeHost($req);
                $result = array(
                    'reachable' => !empty($r['ok']),
                    'is_boiler' => !empty($r['is_boiler']),
                    'host'      => isset($r['host']) ? (string) $r['host'] : '',
                    'detail'    => $this->detailString(isset($r['detail']) ? $r['detail'] : ''),
                );
                if ($result['is_boiler']) {
                    $banner = $this->banner('success', 'PROBE_BOILER');
                } elseif ($result['reachable']) {
                    $banner = $this->banner('info', 'PROBE_REACHABLE');
                } else {
                    $banner = $this->banner('warning', 'PROBE_UNREACHABLE');
                }
                break;
            case 'scan_network':
                $r = $this->opScanNetwork($req);
                if (isset($r['error'])) {
                    return $this->badInput($op, (string) $r['error'], $this->banner('warning', 'SCAN_NEED_BASE_IP'));
                }
                $devices = array();
                foreach ($r as $x) {
                    $devices[] = array(
                        'host'      => isset($x['host']) ? (string) $x['host'] : '',
                        'is_boiler' => !empty($x['is_boiler']),
                        'detail'    => $this->detailString(isset($x['detail']) ? $x['detail'] : ''),
                    );
                }
                $result = array('devices' => $devices);
                $banner = $this->banner('info', 'SCAN_FOUND');
                break;
            case 'provision_simple_devices':
                $pr = $this->provisionSimpleDevices();
                $result = array('report' => $pr['report'], 'reload' => true);
                $banner = $this->banner($pr['kind'], $pr['key']);
                break;
            case 'remove_simple_devices':
                $pr = $this->removeSimpleDevices();
                $result = array('report' => $pr['report'], 'reload' => true);
                $banner = $this->banner($pr['kind'], $pr['key']);
                break;
            case 'save_vacation':
                $r = $this->opSaveVacation($req);
                if (isset($r['reason'])) {
                    return $this->badInput($op, $r['reason'], $this->banner('danger', 'VAC_BAD'));
                }
                $result = array('reload' => true);
                $banner = $this->banner('success', 'SAVE_OK');
                break;
            case 'clear_vacation':
                $this->config['VACATION_ENABLED'] = 0;
                $this->saveConfig();
                $result = array('reload' => true);
                $banner = $this->banner('success', 'SAVE_OK');
                break;
            case 'set_hdo':
                $r = $this->opSetHdo($req);
                $result = array('ok' => !empty($r['ok']), 'desired' => $r['desired'], 'error' => $this->safeErr(isset($r['error']) ? $r['error'] : null));
                $banner = !empty($r['ok']) ? $this->banner('success', 'SAVE_OK') : $this->espFailBanner(isset($r['error']) ? $r['error'] : null);
                break;
            case 'create_scene':
                $r = $this->opCreateScene();
                $result = array('ok' => !empty($r['ok']), 'detail' => isset($r['detail']) ? (string) $r['detail'] : '');
                $banner = !empty($r['ok']) ? $this->banner('success', 'SCENE_OK') : $this->banner('danger', 'SCENE_FAIL');
                break;
        }
        // Whatever the op did, the ESP state may have changed: next read refetches.
        StateCache::markDirty();
        $c = $this->stateFromCache();
        return array(200, array('ok' => true, 'op' => $op, 'meta' => $c['meta'], 'result' => $result, 'banner' => $banner));
    }

    private function badInput($op, $reason, $banner)
    {
        return array(400, array('ok' => false, 'op' => $op, 'error' => 'bad_input', 'reason' => $reason, 'banner' => $banner));
    }

    private function detailString($d)
    {
        if (is_array($d)) {
            return (isset($d['reason']) ? (string) $d['reason'] : '');
        }
        return (string) $d;
    }

    /** Choose a discovered BLE MAC as the boiler: store it in config. */
    private function opSelectBoiler(array $req)
    {
        $mac = isset($req['mac']) && is_string($req['mac']) ? trim($req['mac']) : '';
        if (!preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac)) {
            return array('ok' => false, 'reason' => 'bad_mac');
        }
        $this->config['BOILER_BLE_MAC'] = strtoupper($mac);
        $this->saveConfig();
        return array('ok' => true, 'mac' => strtoupper($mac));
    }

    private function recentDecisions()
    {
        if (!function_exists('SQLSelect') || !$this->tableExists('drazice_decisions')) {
            return array();
        }
        $rows = SQLSelect("SELECT DECISION_UTC, ACTION, REASON, REASON_HUMAN, ALLOWED, DRY_RUN
            FROM drazice_decisions ORDER BY ID DESC LIMIT 25");
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = array(
                    'TIME'   => date('Y-m-d H:i:s', (int) $r['DECISION_UTC']),
                    'ACTION' => $r['ACTION'],
                    'REASON' => $r['REASON_HUMAN'] ? $r['REASON_HUMAN'] : $r['REASON'],
                    'ALLOWED' => $r['ALLOWED'] ? 1 : 0,
                );
            }
        }
        return $out;
    }

    private function recentCommands()
    {
        if (!function_exists('SQLSelect') || !$this->tableExists('drazice_commands')) {
            return array();
        }
        $rows = SQLSelect("SELECT TS_UTC, KIND, PAYLOAD, STATUS, DETAIL
            FROM drazice_commands ORDER BY ID DESC LIMIT 25");
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = array(
                    'TIME'   => date('Y-m-d H:i:s', (int) $r['TS_UTC']),
                    'KIND'   => $r['KIND'],
                    'PAYLOAD' => $r['PAYLOAD'],
                    'STATUS' => $r['STATUS'],
                );
            }
        }
        return $out;
    }

    private function forecastSnapshot($now)
    {
        $bindings = array(
            'today_remaining_kwh' => $this->config['FORECAST_TODAY_PROP'],
            'tomorrow_kwh'        => $this->config['FORECAST_TOMORROW_PROP'],
            'updated_at_utc'      => $this->config['FORECAST_UPDATED_PROP'],
            'provider'            => $this->config['FORECAST_PROVIDER_PROP'],
        );
        $adapter = new ForecastAdapter(array('DraziceSmart\\Repository', 'readProperty'), $bindings);
        return $adapter->snapshot($now, (int) $this->config['FORECAST_FRESHNESS_TTL_S']);
    }

    // ---- admin operations ---------------------------------------------------
    // Shared by the legacy POST switch in admin() and the AJAX endpoints. Every
    // op takes its input as an explicit $req array (never reads $_REQUEST).

    /**
     * Save a settings form. The logic lives in SettingsSaver (pure, unit-tested).
     * Returns array(errors => field=>code, rejected => field=>raw input) so the
     * page can show a visible result and keep the user's rejected input.
     */
    private function opSaveSettings(array $req)
    {
        $res = SettingsSaver::apply($this->config, $req);
        $this->config = $res['config'];
        $this->saveConfig();
        return array('errors' => $res['errors'], 'rejected' => $res['rejected']);
    }

    private function opBoost(array $req)
    {
        $ttl = isset($req['ttl']) ? (int) $req['ttl'] : (int) $this->config['BOOST_TTL_S'];
        if ($ttl < 60) {
            $ttl = 60;
        }
        if ($ttl > 7200) {
            $ttl = 7200;
        }
        $this->config['BOOST_UNTIL_UTC'] = time() + $ttl;
        // This boost may run on battery power if the user ticked the box.
        $this->config['BOOST_ALLOW_BATTERY'] = (isset($req['allow_battery']) && $req['allow_battery']) ? 1 : 0;
        $this->saveConfig();
        return array('boost_until_utc' => (int) $this->config['BOOST_UNTIL_UTC'],
            'allow_battery' => !empty($this->config['BOOST_ALLOW_BATTERY']));
    }

    private function opCancelBoost()
    {
        $this->config['BOOST_UNTIL_UTC'] = 0;
        $this->saveConfig();
    }

    /**
     * Save the vacation window. The form sends datetime-local strings in the
     * configured timezone; store them as unix UTC. Accepts either an explicit
     * end, or a start + duration in days (the client keeps the two in sync; we
     * recompute the end from the duration when end is missing/empty).
     */
    private function opSaveVacation(array $req)
    {
        $enabled = (isset($req['VACATION_ENABLED']) && $req['VACATION_ENABLED']) ? 1 : 0;
        $allowSurplus = (isset($req['VACATION_ALLOW_SURPLUS']) && $req['VACATION_ALLOW_SURPLUS']) ? 1 : 0;
        $startRaw = isset($req['vacation_start']) ? trim((string) $req['vacation_start']) : '';
        $endRaw = isset($req['vacation_end']) ? trim((string) $req['vacation_end']) : '';
        $days = isset($req['vacation_days']) && is_numeric($req['vacation_days']) ? (float) $req['vacation_days'] : 0.0;

        if (!$enabled) {
            $this->config['VACATION_ENABLED'] = 0;
            $this->config['VACATION_ALLOW_SURPLUS'] = $allowSurplus;
            $this->saveConfig();
            return array('ok' => true);
        }

        $start = $this->localDatetimeToUtc($startRaw);
        if ($start === null) {
            return array('reason' => 'bad_start');
        }
        $end = $this->localDatetimeToUtc($endRaw);
        if ($end === null && $days > 0) {
            $end = $start + (int) round($days * 86400);
        }
        if ($end === null || $end <= $start) {
            return array('reason' => 'bad_end');
        }
        $this->config['VACATION_ENABLED'] = 1;
        $this->config['VACATION_START_UTC'] = $start;
        $this->config['VACATION_END_UTC'] = $end;
        $this->config['VACATION_ALLOW_SURPLUS'] = $allowSurplus;
        $this->saveConfig();
        return array('ok' => true);
    }

    /** unix UTC -> "YYYY-MM-DDTHH:MM" in the configured timezone ('' if 0). */
    private function utcToLocalDatetime($utc)
    {
        $utc = (int) $utc;
        if ($utc <= 0) {
            return '';
        }
        $tzName = isset($this->config['TIMEZONE']) ? $this->config['TIMEZONE'] : 'Europe/Kyiv';
        try {
            $dt = new \DateTime('@' . $utc);
            $dt->setTimezone(new \DateTimeZone($tzName));
            return $dt->format('Y-m-d\TH:i');
        } catch (\Exception $e) {
            return '';
        }
    }

    /** "YYYY-MM-DDTHH:MM" in the configured timezone -> unix UTC, or null. */
    private function localDatetimeToUtc($s)
    {
        $s = trim((string) $s);
        if ($s === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $s)) {
            return null;
        }
        $tzName = isset($this->config['TIMEZONE']) ? $this->config['TIMEZONE'] : 'Europe/Kyiv';
        try {
            $tz = new \DateTimeZone($tzName);
            $dt = new \DateTime(str_replace('T', ' ', $s), $tz);
            return $dt->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Diagnostic HDO on/off switch (Advanced tab). Writes the boiler's HDO
     * decoder enable flag through the guarded REST entity. Also stores the
     * desired value so the cycle does not immediately revert it.
     */
    private function opSetHdo(array $req)
    {
        $on = (isset($req['value']) && (string) $req['value'] === '1') ? 1 : 0;
        $t = $this->makeTransport();
        $res = $t->pressButton($on ? 'Boiler HDO On' : 'Boiler HDO Off');
        $this->config['HDO_DESIRED'] = $on;
        $this->saveConfig();
        return array('ok' => ($res !== null), 'desired' => $on, 'error' => $t->getLastError());
    }

    /** Build the MajorDoMo scene from the boiler object properties. */
    private function opCreateScene()
    {
        $object = isset($this->config['BOILER_OBJECT']) ? $this->config['BOILER_OBJECT'] : 'EspBoiler';
        return SceneBuilder::create($object);
    }

    private function opSetAutomation($on)
    {
        $this->config['AUTOMATION_ENABLED'] = $on ? 1 : 0;
        $this->saveConfig();
    }

    private function opSetDryRun(array $req)
    {
        $this->config['DRY_RUN'] = isset($req['value']) && $req['value'] == '0' ? 0 : 1;
        $this->saveConfig();
    }

    private function opScanNetwork(array $req)
    {
        $base = isset($req['base_ip']) && is_string($req['base_ip']) ? trim($req['base_ip']) : '';
        if ($base === '') {
            // Try to derive from server's own LAN IP.
            $base = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
        }
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return array('error' => 'need_base_ip');
        }
        $max = isset($req['max_hosts']) ? (int) $req['max_hosts'] : 64;
        return NetworkScanner::sweep(
            $base, (int) $this->config['ESP_PORT'],
            $this->config['ESP_USER'], $this->config['ESP_PASS'],
            !empty($this->config['ESP_DIGEST']), $max
        );
    }

    private function opProbeHost(array $req)
    {
        $host = isset($req['host']) && is_string($req['host']) ? trim($req['host']) : '';
        return NetworkScanner::probe(
            $host, (int) $this->config['ESP_PORT'],
            $this->config['ESP_USER'], $this->config['ESP_PASS'],
            !empty($this->config['ESP_DIGEST'])
        );
    }

    private function opRequestTarget(array $req)
    {
        $v = isset($req['value']) ? (int) $req['value'] : 0;
        if ($v < 40 || $v > 80) {
            return array('ok' => false, 'reason' => 'out_of_range');
        }
        $t = $this->makeTransport();
        $res = $t->postSet('number', 'Boiler Requested Target', $v);
        $err = $t->getLastError();
        // Readback.
        $rb = $t->getState('number', 'Boiler Requested Target');
        return array('ok' => ($res !== null), 'readback' => Transport::numericValue($rb),
            'error' => $err);
    }

    private function opRequestMode(array $req)
    {
        $m = isset($req['value']) && is_string($req['value']) ? trim($req['value']) : '';
        $allowed = array('ANTIFREEZE', 'SMART', 'PROG', 'MANUAL');
        if (!in_array($m, $allowed, true)) {
            return array('ok' => false, 'reason' => 'bad_mode');
        }
        $t = $this->makeTransport();
        $res = $t->postSet('select', 'Boiler Requested Mode', $m);
        $err = $t->getLastError();
        $rb = $t->getState('select', 'Boiler Requested Mode');
        return array('ok' => ($res !== null), 'readback' => Transport::stringValue($rb),
            'error' => $err);
    }

    private function opSendPin(array $req)
    {
        $v = PolicyEngine::validatePin(isset($req['pin']) ? $req['pin'] : '');
        if (empty($v['ok'])) {
            return array('ok' => false, 'reason' => 'bad_pin');
        }
        $t = $this->makeTransport();

        // Stale-PIN check: the hidden field carries the connect count that was
        // rendered when the form was shown. If the ESP's connect count already
        // moved on, the boiler rebuilt the BLE link and issued a NEW pairing
        // PIN on its display — the PIN just typed is for the previous session.
        // We still send it (the user may be looking at the new PIN already).
        $renderedCc = isset($req['pin_render_cc']) ? $req['pin_render_cc'] : null;
        $currentCcRaw = Transport::numericValue($t->getState('sensor', 'Boiler Connect Count'));
        $stale = PairTrace::isStale($renderedCc, $currentCcRaw);

        // PIN is sensitive: send the numeric value ONCE (single fast POST, no
        // retry: the boiler's PIN window is only ~29 s); NEVER store the digits.
        $res = $t->postSet('number', 'Boiler Pairing PIN', $v['value']);
        $postErr = $t->getLastError();

        // Bounded non-blocking readback of Boiler State: the boiler flips to
        // "Connected" once it accepts the PIN. Poll a couple of times with a
        // short total wait so the admin page stays responsive.
        $state = null;
        if ($res !== null) {
            for ($i = 0; $i < 3; $i++) {
                $state = Transport::stringValue($t->getState('text_sensor', 'Boiler State'));
                if ($state === 'Connected') {
                    break;
                }
                if ($i < 2) {
                    usleep(700000); // ~0.7s, total <= ~2.1s
                }
            }
        }
        $accepted = ($state === 'Connected');

        // Post-submit trace snapshot: a short non-sensitive summary for the
        // inline result and the command log. Never includes PIN digits (the
        // firmware trace itself never records them).
        $traceRaw = Transport::stringValue($t->getState('text_sensor', 'Boiler Pair Trace'));
        $lastDisconnectRaw = Transport::stringValue($t->getState('text_sensor', 'Boiler Last Disconnect'));
        $traceTail = PairTrace::lastEvents($traceRaw, 3);

        // Why did the POST fail? The ESP ANSWERED with an HTTP error (e.g. 404/400)
        // = a request problem, which is different from "ESP unreachable".
        $reason = 'sent';
        $httpCode = null;
        if ($res === null) {
            $stage = isset($postErr['stage']) ? $postErr['stage'] : '';
            if ($stage === 'http') {
                $reason = 'http_error';
            } elseif ($stage === 'auth') {
                $reason = 'auth';
            } else {
                $reason = 'transport';
            }
            $httpCode = isset($postErr['http_code']) ? (int) $postErr['http_code'] : null;
        }

        Repository::saveCommand(array(
            'ts_utc'     => time(),
            'command_id' => 'pair',
            'kind'       => 'pair',
            'payload'    => '****',
            'status'     => $accepted ? 'accepted' : ($res !== null ? 'sent' : 'failed'),
            'detail'     => ($state === null ? '' : $state) . ($stale ? ' [stale_pin]' : '')
                . ($res === null ? (' ' . $reason . ($httpCode !== null ? ('_' . $httpCode) : '')) : '')
                . (empty($traceTail) ? '' : (' trace:' . implode(',', $traceTail)))
                . ($lastDisconnectRaw ? (' disc:' . $lastDisconnectRaw) : ''),
        ));

        $out = array(
            'ok'               => ($res !== null),
            'accepted'         => $accepted,
            'state'            => $state,
            'reason'           => $reason,
            'error'            => $postErr,
            'stale'            => $stale,
            'trace_tail'       => $traceTail,
            'last_disconnect'  => $lastDisconnectRaw,
        );
        if ($httpCode !== null) {
            $out['http_code'] = $httpCode;
        }
        return $out;
    }

    private function opButton($name)
    {
        $t = $this->makeTransport();
        $res = $t->pressButton($name);
        return array('ok' => ($res !== null), 'error' => $t->getLastError());
    }

    /**
     * Read-only discovery of candidate MajorDoMo properties for power/forecast
     * bindings by keyword. Returns candidates with current value and object.
     */
    public function discoverProperties()
    {
        $keywords = array('solar', 'forecast', 'pv', 'solcast', 'meteo', 'generation',
            'battery', 'soc', 'deye', 'прогноз', 'сонц', 'батар');
        $out = array();
        if (!function_exists('SQLSelect')) {
            return $out;
        }
        $rows = SQLSelect("SELECT o.TITLE AS OBJ, p.TITLE AS PROP, pv.VALUE AS VAL, pv.UPDATED AS UPD
            FROM pvalues pv
            LEFT JOIN objects o ON o.ID = pv.OBJECT_ID
            LEFT JOIN properties p ON p.ID = pv.PROPERTY_ID
            WHERE o.TITLE IS NOT NULL AND p.TITLE IS NOT NULL
            ORDER BY o.TITLE, p.TITLE");
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $r) {
            $hay = strtolower($r['OBJ'] . '.' . $r['PROP']);
            foreach ($keywords as $kw) {
                if (strpos($hay, $kw) !== false) {
                    $out[] = array(
                        'property' => $r['OBJ'] . '.' . $r['PROP'],
                        'value'    => $r['VAL'],
                        'updated'  => $r['UPD'],
                    );
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Read-only list of ALL MajorDoMo object properties as a flat list of
     * "ObjectTitle.PropertyTitle" strings, sorted alphabetically and unique.
     * Modelled on discoverProperties() but WITHOUT the keyword filter.
     * Returns an empty array when the DB/objects are not available (headless
     * / tests) so the admin form can fall back to free-text inputs.
     */
    public function allProperties()
    {
        if ($this->propsMemo !== null) {
            return $this->propsMemo;
        }
        // Short-lived file cache (5 min) so repeated Policy-tab opens do not
        // rescan pvalues on large installs. A property created meanwhile shows
        // up after the TTL; it can always be typed into the input directly.
        $cacheFile = (defined('ROOT') && is_dir(ROOT . 'cms/cached'))
            ? ROOT . 'cms/cached/drazice_smart_props.json' : '';
        if ($cacheFile !== '' && is_file($cacheFile)
            && (time() - (int) @filemtime($cacheFile)) < 300) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                $this->propsMemo = $cached;
                return $cached;
            }
        }
        $out = array();
        if (!function_exists('SQLSelect')) {
            return $out;
        }
        // INNER JOIN + DISTINCT, no ORDER BY: avoids a filesort on joined text
        // columns; the list is sorted once below in PHP.
        $rows = SQLSelect("SELECT DISTINCT o.TITLE AS OBJ, p.TITLE AS PROP
            FROM pvalues pv
            INNER JOIN objects o ON o.ID = pv.OBJECT_ID
            INNER JOIN properties p ON p.ID = pv.PROPERTY_ID
            WHERE o.TITLE IS NOT NULL AND p.TITLE IS NOT NULL");
        if (!is_array($rows)) {
            return $out;
        }
        $seen = array();
        foreach ($rows as $r) {
            $name = $r['OBJ'] . '.' . $r['PROP'];
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $out[] = $name;
            }
        }
        sort($out, SORT_STRING | SORT_FLAG_CASE);
        $this->propsMemo = $out;
        if ($cacheFile !== '' && !empty($out)) {
            @file_put_contents($cacheFile, json_encode($out), LOCK_EX);
        }
        return $out;
    }

    /**
     * Inner HTML of the single shared <datalist id="ds-props">: one value-only
     * <option> per "Object.Property" (no label text, so the HTML stays small).
     *
     * @param array $props flat list of "Object.Property" strings
     * @return string
     */
    /**
     * <datalist id="ds-handlers"> options for the presence handler: every
     * object-level method as "Object.method" and every MajorDoMo script as
     * "script:Title". Class methods inherited by objects are not listed (the
     * list would explode); they can still be typed as "Object.method".
     */
    private function handlerDatalistHtml()
    {
        $items = array();
        if (function_exists('SQLSelect')) {
            $rows = SQLSelect("SELECT o.TITLE AS OBJ, m.TITLE AS METH FROM methods m
                INNER JOIN objects o ON o.ID = m.OBJECT_ID
                WHERE m.OBJECT_ID > 0 AND o.TITLE IS NOT NULL AND m.TITLE IS NOT NULL");
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $items[] = $r['OBJ'] . '.' . $r['METH'];
                }
            }
            $scripts = SQLSelect("SELECT TITLE FROM scripts WHERE TITLE IS NOT NULL");
            if (is_array($scripts)) {
                foreach ($scripts as $r) {
                    $items[] = 'script:' . $r['TITLE'];
                }
            }
        }
        $items = array_values(array_unique($items));
        sort($items, SORT_STRING | SORT_FLAG_CASE);
        return $this->propertyDatalistHtml($items);
    }

    private function propertyDatalistHtml($props)
    {
        $html = '';
        foreach ($props as $name) {
            $html .= '<option value="' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '">';
        }
        return $html;
    }

    /**
     * Best-effort read of the MajorDoMo system timezone. MajorDoMo stores it in
     * settings; try getGlobal('TimeZone')/SETTINGS, then PHP's default. Returns
     * '' when nothing reliable is found.
     */
    private function systemTimezone()
    {
        $candidates = array();
        if (function_exists('getGlobal')) {
            $g = getGlobal('TimeZone');
            if (is_string($g) && $g !== '') {
                $candidates[] = $g;
            }
        }
        if (function_exists('SQLSelectOne')) {
            $rec = SQLSelectOne("SELECT VALUE FROM settings WHERE NAME='TimeZone'");
            if (is_array($rec) && isset($rec['VALUE']) && $rec['VALUE'] !== '') {
                $candidates[] = $rec['VALUE'];
            }
        }
        $d = @date_default_timezone_get();
        if (is_string($d) && $d !== '') {
            $candidates[] = $d;
        }
        foreach ($candidates as $tz) {
            try {
                new \DateTimeZone($tz);
                return $tz;
            } catch (\Exception $e) {
                // try next candidate
            }
        }
        return '';
    }

    /**
     * Build the inner HTML of the timezone <select>: the current configured
     * value (or MajorDoMo's system timezone, else Europe/Kyiv) is preselected,
     * populated from PHP's timezone identifier list.
     *
     * @param string $current currently configured TIMEZONE value
     * @return string
     */
    private function timezoneOptionsHtml($current)
    {
        $current = (string) $current;
        if ($current === '') {
            $sys = $this->systemTimezone();
            $current = ($sys !== '') ? $sys : 'Europe/Kyiv';
        }
        $zones = \DateTimeZone::listIdentifiers();
        if (!is_array($zones) || empty($zones)) {
            $zones = array('Europe/Kyiv', 'UTC');
        }
        $html = '';
        $found = false;
        foreach ($zones as $tz) {
            $sel = ($tz === $current) ? ' selected' : '';
            if ($sel !== '') {
                $found = true;
            }
            $html .= '<option value="' . htmlspecialchars($tz, ENT_QUOTES) . '"' . $sel . '>'
                . htmlspecialchars($tz, ENT_QUOTES) . '</option>';
        }
        if (!$found && $current !== '') {
            $html = '<option value="' . htmlspecialchars($current, ENT_QUOTES) . '" selected>'
                . htmlspecialchars($current, ENT_QUOTES) . '</option>' . $html;
        }
        return $html;
    }

    // ---- CSRF ---------------------------------------------------------------

    private function csrfToken()
    {
        global $session;
        // MajorDoMo session stores data in $session->data[...] and persists it
        // to $_SESSION via $session->save(). Use that so the token is stable
        // between rendering the form and submitting it.
        if (is_object($session) && isset($session->data) && is_array($session->data)) {
            if (empty($session->data['drazice_csrf'])) {
                $session->data['drazice_csrf'] = md5(uniqid((string) mt_rand(), true));
                if (method_exists($session, 'save')) {
                    $session->save();
                }
            }
            return $session->data['drazice_csrf'];
        }
        // Fallback: native PHP session, then a per-process token.
        if (function_exists('session_id') && session_id() !== '') {
            if (empty($_SESSION['drazice_csrf'])) {
                $_SESSION['drazice_csrf'] = md5(uniqid((string) mt_rand(), true));
            }
            return $_SESSION['drazice_csrf'];
        }
        if (!isset($GLOBALS['drazice_csrf_fallback'])) {
            $GLOBALS['drazice_csrf_fallback'] = md5(uniqid((string) mt_rand(), true));
        }
        return $GLOBALS['drazice_csrf_fallback'];
    }

    private function checkCsrf($req = null)
    {
        if ($req === null) {
            $req = $_REQUEST;
        }
        $sent = (isset($req['csrf']) && is_string($req['csrf'])) ? $req['csrf'] : '';
        $token = $this->csrfToken();
        if ($sent === '' || $token === '') {
            return false;
        }
        return hash_equals($token, $sent);
    }
}
