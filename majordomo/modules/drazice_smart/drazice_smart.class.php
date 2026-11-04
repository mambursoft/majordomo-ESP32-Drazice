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

class drazice_smart extends module
{
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
            || !$this->tableExists('drazice_people')) {
            $this->dbInstall();
        }
    }

    function __construct()
    {
        $this->drazice_smart();
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
            // surplus latch state (planner hysteresis)
            'SURPLUS_LATCHED'      => 0,
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
EOD;
        parent::dbInstall($data);
    }

    // ---- transport helper ---------------------------------------------------

    private function makeTransport()
    {
        return new Transport(array(
            'host'   => isset($this->config['ESP_HOST']) ? $this->config['ESP_HOST'] : '',
            'port'   => isset($this->config['ESP_PORT']) ? (int) $this->config['ESP_PORT'] : 80,
            'user'   => isset($this->config['ESP_USER']) ? $this->config['ESP_USER'] : '',
            'pass'   => isset($this->config['ESP_PASS']) ? $this->config['ESP_PASS'] : '',
            'digest' => !empty($this->config['ESP_DIGEST']),
        ));
    }

    /** Build an immutable snapshot for the pure planner. Reads ESP + properties. */
    public function buildSnapshot()
    {
        $t = $this->makeTransport();
        $now = time();

        $tempUpperJson = $t->getState('sensor', 'Boiler Temp Upper');
        $tempLowerJson = $t->getState('sensor', 'Boiler Temp Lower');
        $heatJson      = $t->getState('binary_sensor', 'Boiler Heating');
        $hdoJson       = $t->getState('binary_sensor', 'Boiler HDO');
        $energyJson    = $t->getState('sensor', 'Boiler Energy');
        $cooldownJson  = $t->getState('sensor', 'Boiler Cooldown Remaining');
        $guardStateJson = $t->getState('text_sensor', 'Boiler Guard State');

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

        // Tariff.
        $tariff = Tariff::evaluate($now, array(
            'timezone'            => $this->config['TIMEZONE'],
            'cheap_start_hour'    => $this->config['CHEAP_START_HOUR'],
            'cheap_start_min'     => isset($this->config['CHEAP_START_MIN']) ? $this->config['CHEAP_START_MIN'] : 0,
            'cheap_end_hour'      => $this->config['CHEAP_END_HOUR'],
            'cheap_end_min'       => isset($this->config['CHEAP_END_MIN']) ? $this->config['CHEAP_END_MIN'] : 0,
            'meter_offset_seconds' => $this->config['METER_OFFSET_SECONDS'],
            'start_guard_seconds' => $this->config['START_GUARD_SECONDS'],
            'end_guard_seconds'   => $this->config['END_GUARD_SECONDS'],
        ));

        // Daytime = NOT cheap window (simplified: cheap window is night).
        $isDaytime = empty($tariff['active']);

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
            'guard_state'    => Transport::stringValue($guardStateJson),
            'ble_state'      => Transport::stringValue($guardStateJson),
            'power_source'   => $power['source'],
            'power_valid'    => $power['valid'],
            'power_human'    => $power['human'],
            'power_raw'      => $power['raw'],
            'battery_soc'    => $soc,
            'tariff_active'  => !empty($tariff['active']),
            'tariff_meter_local' => $tariff['meter_local'],
            'is_daytime'     => $isDaytime,
            'boost_active'   => $boostActive,
            'boost_allow_battery' => !empty($this->config['BOOST_ALLOW_BATTERY']),
            'surplus_latched' => !empty($this->config['SURPLUS_LATCHED']),
            'solar_surplus_enabled' => !empty($this->config['SOLAR_SURPLUS_ENABLED']),
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
        );
    }

    /**
     * One cycle step: snapshot -> decide -> (optionally) apply. Called by the
     * cycle worker. Respects AUTOMATION_ENABLED and DRY_RUN.
     */
    public function cycleStep()
    {
        $this->getConfig();
        $snapshot = $this->buildSnapshot();
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

        // Persist telemetry + decision (history).
        Repository::saveTelemetry($snapshot);
        Repository::saveDecision($decision);

        // Mirror key values into MajorDoMo properties for dashboards/linked props.
        $this->publishProperties($snapshot, $decision);

        // Renew lease if automation is enabled and ESP reachable.
        if (!empty($this->config['AUTOMATION_ENABLED']) && !empty($snapshot['http_available'])) {
            $t = $this->makeTransport();
            $t->pressButton('Boiler Lease Renew');
        }

        // Apply only when automation is ON and not dry-run and decision allows.
        if (!empty($this->config['AUTOMATION_ENABLED']) && empty($this->config['DRY_RUN'])
            && !empty($decision['allowed']) && $decision['action'] === 'heat') {
            $this->applyDecision($decision);
        }

        // Presence evaluation (optional).
        if (!empty($this->config['PRESENCE_ENABLED'])) {
            $this->evaluatePresence($snapshot['now_utc']);
        }

        // Simple Devices ("Простые устройства") sync (optional).
        if (!empty($this->config['SIMPLE_DEVICES_ENABLED'])) {
            $this->syncSimpleDevices($snapshot, $decision);
        }

        return array('snapshot' => $snapshot, 'decision' => $decision);
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

        // 1. A target change made in the Devices thermostat UI -> guarded write.
        $last = ($this->config['_LAST_PUBLISHED_TARGET'] === '' ? null : (float) $this->config['_LAST_PUBLISHED_TARGET']);
        $cmd = SimpleDevices::readTargetCommand($map, $last);
        if ($cmd !== null) {
            $t = $this->makeTransport();
            $res = $t->postSet('number', 'Boiler Requested Target', (int) round($cmd));
            Repository::saveCommand(array(
                'ts_utc' => time(), 'command_id' => 'simpledev', 'kind' => 'target',
                'payload' => (string) $cmd,
                'status' => ($res !== null ? 'sent' : 'failed'),
                'detail' => ($res === null ? json_encode($t->getLastError()) : 'from Simple Devices UI'),
            ));
        }

        // 2. Publish current state into the simple-device objects.
        SimpleDevices::publish($map, $snapshot, $decision);

        // Remember the target we published so a later UI change is detected.
        if (isset($decision['target_c']) && $decision['target_c'] !== null) {
            $this->config['_LAST_PUBLISHED_TARGET'] = (string) $decision['target_c'];
            $this->saveConfig();
        }
    }

    private function applyDecision($decision)
    {
        $t = $this->makeTransport();
        $commandId = uniqid('cmd_', true);
        if ($decision['mode'] !== null) {
            $t->postSet('select', 'Boiler Requested Mode', $decision['mode']);
        }
        if ($decision['target_c'] !== null) {
            $res = $t->postSet('number', 'Boiler Requested Target', (int) round($decision['target_c']));
            Repository::saveCommand(array(
                'ts_utc' => time(), 'command_id' => $commandId, 'kind' => 'target',
                'payload' => (string) $decision['target_c'],
                'status' => ($res !== null ? 'sent' : 'failed'),
                'detail' => ($res === null ? json_encode($t->getLastError()) : ''),
            ));
        }
    }

    /**
     * Publish EVERYTHING the ESP exposes into the single boiler+ESP object
     * (BOILER_OBJECT). Read-only telemetry/diagnostics + planner reflections.
     * Control properties are NOT written here (they are user-driven inputs).
     */
    private function publishProperties($s, $d)
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

        // ESP diagnostics + guard + reported mode/target (one HTTP batch).
        $diag = $this->readDiagnostics();
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
        Repository::saveCommand(array(
            'ts_utc' => time(), 'command_id' => 'objprop', 'kind' => $property,
            'payload' => (string) $value, 'status' => 'applied',
            'detail' => 'from boiler object property change',
        ));
    }

    private function evaluatePresence($now)
    {
        $o = isset($this->config['BOILER_OBJECT']) ? $this->config['BOILER_OBJECT'] : 'EspBoiler';

        // Observations (BLE from the ESP scan + Wi-Fi from router/arp) are
        // collected into <object>.presenceObservationsJson by the presence
        // collector (see collectPresenceObservations). Each entry:
        //   {"mac":"aa:..","seen_utc":..,"source":"ble|wifi"}
        $obs = $this->collectPresenceObservations($o, $now);

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
            if ($handler && function_exists('callMethod')) {
                callMethod($handler, array(
                    'person'     => isset($ev['person']) ? $ev['person'] : '',
                    'duration_s' => isset($ev['duration_s']) ? $ev['duration_s'] : 0,
                    'event'      => isset($ev['event']) ? $ev['event'] : 'long',
                ));
            }
        }
    }

    /**
     * Collect presence observations from BLE (ESP scan results) and Wi-Fi.
     * BLE: reads "BLE Scan Results" from the ESP. Wi-Fi: reads a property that a
     * user cron/rule fills (router/arp table) at <object>.wifiObservationsJson.
     * Returns a list of array(mac, seen_utc, source).
     */
    private function collectPresenceObservations($o, $now)
    {
        $obs = array();

        // BLE from the ESP scanner (if enabled/flashed).
        $ble = $this->bleScanDevices();
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
        $this->getConfig();
        $snapshot = $this->buildSnapshot();
        $decision = Planner::decide($snapshot, $this->plannerConfig());
        $this->flattenForTemplate($out, $snapshot, $decision, null);
        $out['IS_ADMIN'] = 0;
    }

    /** Flatten nested snapshot/decision/config into flat [#VAR#] keys. */
    private function flattenForTemplate(&$out, $s, $d, $forecast)
    {
        $fmt = function ($v, $suffix = '') {
            if ($v === null || $v === '') {
                return defined('LANG_DS_UNAVAILABLE') ? LANG_DS_UNAVAILABLE : 'no data';
            }
            return (is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.') : $v) . $suffix;
        };
        $ynq = function ($v) {
            if ($v === null) return defined('LANG_DS_UNKNOWN') ? LANG_DS_UNKNOWN : 'unknown';
            return $v ? (defined('LANG_DS_YES') ? LANG_DS_YES : 'yes')
                      : (defined('LANG_DS_NO') ? LANG_DS_NO : 'no');
        };

        $out['HTTP_AVAILABLE'] = !empty($s['http_available']) ? 1 : 0;
        $out['TEMP_UPPER_F']   = $fmt($s['temp_upper'], ' °C');
        $out['TEMP_LOWER_F']   = $fmt($s['temp_lower'], ' °C');
        $out['HEATING_F']      = $ynq($s['heating']);
        $out['HDO_F']          = $ynq($s['hdo']);
        $out['ENERGY_F']       = $fmt($s['energy_total_kwh'], ' кВт·год');
        $out['POWER_SOURCE']   = $s['power_source'];
        $out['POWER_HUMAN']    = $s['power_human'];
        $out['POWER_RAW']      = ($s['power_raw'] === null ? '—' : (string) $s['power_raw']);
        $out['SOC_F']          = $fmt($s['battery_soc'], ' %');
        $out['TARIFF_ACTIVE']  = !empty($s['tariff_active']) ? 1 : 0;
        $out['TARIFF_METER_LOCAL'] = $s['tariff_meter_local'];
        $out['GUARD_STATE']    = ($s['guard_state'] === null ? '—' : $s['guard_state']);
        $out['COOLDOWN_F']     = $fmt($s['cooldown_remaining_s'], ' с');
        $out['DECISION_ACTION'] = $d['action'];
        $out['DECISION_REASON'] = $d['reason'];
        $out['DECISION_HUMAN']  = $d['reason_human'];
        $out['DECISION_TARGET_F'] = $fmt($d['target_c'], ' °C');
        $out['DECISION_ALLOWED'] = !empty($d['allowed']) ? 1 : 0;

        if (is_array($forecast)) {
            $out['FC_AVAILABLE']  = !empty($forecast['available']) ? 1 : 0;
            $out['FC_TODAY_F']    = $fmt($forecast['today_remaining_kwh'], ' кВт·год');
            $out['FC_TOMORROW_F'] = $fmt($forecast['tomorrow_kwh'], ' кВт·год');
            $out['FC_PROVIDER']   = ($forecast['provider'] === null ? '—' : $forecast['provider']);
            $out['FC_QUALITY']    = $forecast['quality'];
            $out['FC_HUMAN']      = $forecast['human'];
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

        $op = isset($_REQUEST['op']) ? $_REQUEST['op'] : '';
        $rejectedInput = array();

        // CSRF: all mutating ops require a valid session token.
        if ($op !== '' && $op !== 'tab') {
            if (!$this->checkCsrf()) {
                $out['ERROR'] = 'csrf';
                $op = '';
            }
        }

        switch ($op) {
            case 'save_settings':
                $saved = $this->opSaveSettings();
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
                $this->opBoost();
                break;
            case 'cancel_boost':
                $this->config['BOOST_UNTIL_UTC'] = 0;
                $this->saveConfig();
                break;
            case 'enable_automation':
                $this->config['AUTOMATION_ENABLED'] = 1;
                $this->saveConfig();
                break;
            case 'disable_automation':
                $this->config['AUTOMATION_ENABLED'] = 0;
                $this->saveConfig();
                break;
            case 'set_dry_run':
                $this->config['DRY_RUN'] = isset($_REQUEST['value']) && $_REQUEST['value'] == '0' ? 0 : 1;
                $this->saveConfig();
                break;
            case 'scan_network':
                $scan = $this->opScanNetwork();
                // Flatten for the parser: a list of devices OR a need_base_ip error.
                if (isset($scan['error'])) {
                    $out['SCAN_NEED_BASE_IP'] = ($scan['error'] === 'need_base_ip') ? 1 : 0;
                } else {
                    $out['SCAN_RESULT'] = $scan;
                }
                break;
            case 'probe_host':
                $probe = $this->opProbeHost();
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
                $out['CMD_RESULT'] = $this->opRequestTarget();
                break;
            case 'request_mode':
                $out['CMD_RESULT'] = $this->opRequestMode();
                break;
            case 'release_ble':
                $out['CMD_RESULT'] = $this->opButton('Boiler Release BLE');
                break;
            case 'reconnect_ble':
                $out['CMD_RESULT'] = $this->opButton('Boiler Reconnect BLE');
                break;
            case 'send_pin':
                $pinRes = $this->opSendPin();
                $out['CMD_RESULT'] = $pinRes;
                // Flat keys for the parser (it cannot read nested CMD_RESULT.*).
                $out['PIN_CMD'] = 1;
                $reason = isset($pinRes['reason']) ? $pinRes['reason'] : '';
                $out['PIN_ACCEPTED'] = !empty($pinRes['accepted']) ? 1 : 0;
                // "sent": transport POST succeeded but the boiler is not yet Connected.
                $out['PIN_SENT'] = (!empty($pinRes['ok']) && empty($pinRes['accepted'])) ? 1 : 0;
                // bad_pin -> validator rejected the input.
                $out['PIN_BAD'] = ($reason === 'bad_pin') ? 1 : 0;
                // ESP unreachable / transport failure.
                $out['PIN_ESP_DOWN'] = (empty($pinRes['ok']) && $reason !== 'bad_pin') ? 1 : 0;
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
                $out['CMD_RESULT'] = $this->opSelectBoiler();
                break;
            case 'lease_renew':
                $out['CMD_RESULT'] = $this->opButton('Boiler Lease Renew');
                break;
            case 'provision_simple_devices':
                $out['PROVISION_RESULT'] = SimpleDevices::provision($this->simpleDevicesMap());
                break;
        }

        $snapshot = $this->buildSnapshot();
        $decision = Planner::decide($snapshot, $this->plannerConfig());
        $forecast = $this->forecastSnapshot($snapshot['now_utc']);

        $this->flattenForTemplate($out, $snapshot, $decision, $forecast);
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
        $hv = Transport::validateHost(isset($this->config['ESP_HOST']) ? $this->config['ESP_HOST'] : '');
        $out['HOST_OK'] = !empty($hv['ok']) ? 1 : 0;
        $out['HOST_REASON'] = $hv['reason'];

        // Recent decisions + commands for the log tab.
        $out['LOG_DECISIONS'] = $this->recentDecisions();
        $out['LOG_COMMANDS'] = $this->recentCommands();
        $out['PRESENT_PEOPLE'] = Repository::readProperty('EspBoiler.presentPeople');

        // BLE scan results (list of discovered boilers to choose from).
        // Use flat keys for the MajorDoMo parser (no dotted nesting in [#begin#]).
        $ble = $this->bleScanDevices();
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
        $diag = $this->readDiagnostics();
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

        // Property-binding dropdowns: build <select> option lists in PHP (the
        // simple template parser can't do "selected if equals current" inside a
        // [#begin#] loop). When the property list is empty (no DB/objects), the
        // template falls back to a free-text input (HAS_PROPERTIES=0).
        $props = $this->allProperties();
        $out['HAS_PROPERTIES'] = (!empty($props)) ? 1 : 0;
        $bindFields = array(
            'SOC_PROPERTY', 'POWER_PROPERTY', 'FORECAST_TODAY_PROP',
            'FORECAST_TOMORROW_PROP', 'FORECAST_UPDATED_PROP', 'FORECAST_PROVIDER_PROP',
        );
        foreach ($bindFields as $f) {
            $cur = isset($this->config[$f]) ? (string) $this->config[$f] : '';
            $out['OPTS_' . $f] = $this->propertyOptionsHtml($props, $cur);
        }

        // Timezone dropdown (always available from PHP's identifier list).
        $out['OPTS_TIMEZONE'] = $this->timezoneOptionsHtml(
            isset($this->config['TIMEZONE']) ? (string) $this->config['TIMEZONE'] : ''
        );
    }

    /**
     * Read the BLE scan results JSON from the ESP ("BLE Scan Results" text
     * sensor, present when ble_scan.yaml / boiler-scan.yaml is flashed).
     * Returns a list of array(mac, name, boiler(bool), rssi) sorted boilers-first.
     */
    public function bleScanDevices()
    {
        $t = $this->makeTransport();
        $json = $t->getState('text_sensor', 'BLE Scan Results');
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
            if (!isset($d['mac'])) {
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

    /** Read all diagnostic / full-feature entities for the Diagnostics tab. */
    public function readDiagnostics()
    {
        $t = $this->makeTransport();
        $read = function ($domain, $name) use ($t) {
            $j = $t->getState($domain, $name);
            if ($domain === 'sensor' || $domain === 'number') {
                $v = Transport::numericValue($j);
                return $v === null ? null : $v;
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
            // Pairing diagnostics (local firmware modification, see UPSTREAM.md).
            'PAIR_TRACE'       => $read('text_sensor', 'Boiler Pair Trace'),
            'LAST_DISCONNECT'  => $read('text_sensor', 'Boiler Last Disconnect'),
            'PAIR_ATTEMPTS'    => $read('sensor', 'Boiler Pair Attempts'),
            'CONNECT_COUNT'    => $read('sensor', 'Boiler Connect Count'),
        );
    }

    /** Choose a discovered BLE MAC as the boiler: store it in config. */
    private function opSelectBoiler()
    {
        $mac = isset($_REQUEST['mac']) ? trim($_REQUEST['mac']) : '';
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

    /**
     * Save a settings form. The logic lives in SettingsSaver (pure, unit-tested).
     * Returns array(errors => field=>code, rejected => field=>raw input) so the
     * page can show a visible result and keep the user's rejected input.
     */
    private function opSaveSettings()
    {
        $res = SettingsSaver::apply($this->config, $_REQUEST);
        $this->config = $res['config'];
        $this->saveConfig();
        return array('errors' => $res['errors'], 'rejected' => $res['rejected']);
    }

    private function opBoost()
    {
        $ttl = isset($_REQUEST['ttl']) ? (int) $_REQUEST['ttl'] : (int) $this->config['BOOST_TTL_S'];
        if ($ttl < 60) {
            $ttl = 60;
        }
        if ($ttl > 7200) {
            $ttl = 7200;
        }
        $this->config['BOOST_UNTIL_UTC'] = time() + $ttl;
        // This boost may run on battery power if the user ticked the box.
        $this->config['BOOST_ALLOW_BATTERY'] = (isset($_REQUEST['allow_battery']) && $_REQUEST['allow_battery']) ? 1 : 0;
        $this->saveConfig();
    }

    private function opScanNetwork()
    {
        global $this_computer;
        $base = isset($_REQUEST['base_ip']) ? trim($_REQUEST['base_ip']) : '';
        if ($base === '') {
            // Try to derive from server's own LAN IP.
            $base = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
        }
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return array('error' => 'need_base_ip');
        }
        $max = isset($_REQUEST['max_hosts']) ? (int) $_REQUEST['max_hosts'] : 64;
        return NetworkScanner::sweep(
            $base, (int) $this->config['ESP_PORT'],
            $this->config['ESP_USER'], $this->config['ESP_PASS'],
            !empty($this->config['ESP_DIGEST']), $max
        );
    }

    private function opProbeHost()
    {
        $host = isset($_REQUEST['host']) ? trim($_REQUEST['host']) : '';
        return NetworkScanner::probe(
            $host, (int) $this->config['ESP_PORT'],
            $this->config['ESP_USER'], $this->config['ESP_PASS'],
            !empty($this->config['ESP_DIGEST'])
        );
    }

    private function opRequestTarget()
    {
        $v = isset($_REQUEST['value']) ? (int) $_REQUEST['value'] : 0;
        if ($v < 40 || $v > 80) {
            return array('ok' => false, 'reason' => 'out_of_range');
        }
        $t = $this->makeTransport();
        $res = $t->postSet('number', 'Boiler Requested Target', $v);
        // Readback.
        $rb = $t->getState('number', 'Boiler Requested Target');
        return array('ok' => ($res !== null), 'readback' => Transport::numericValue($rb),
            'error' => $t->getLastError());
    }

    private function opRequestMode()
    {
        $m = isset($_REQUEST['value']) ? trim($_REQUEST['value']) : '';
        $allowed = array('ANTIFREEZE', 'SMART', 'PROG', 'MANUAL');
        if (!in_array($m, $allowed, true)) {
            return array('ok' => false, 'reason' => 'bad_mode');
        }
        $t = $this->makeTransport();
        $res = $t->postSet('select', 'Boiler Requested Mode', $m);
        $rb = $t->getState('select', 'Boiler Requested Mode');
        return array('ok' => ($res !== null), 'readback' => Transport::stringValue($rb),
            'error' => $t->getLastError());
    }

    private function opSendPin()
    {
        $v = PolicyEngine::validatePin(isset($_REQUEST['pin']) ? $_REQUEST['pin'] : '');
        if (empty($v['ok'])) {
            return array('ok' => false, 'reason' => 'bad_pin');
        }
        $t = $this->makeTransport();

        // Stale-PIN check: the hidden field carries the connect count that was
        // rendered when the form was shown. If the ESP's connect count already
        // moved on, the boiler rebuilt the BLE link and issued a NEW pairing
        // PIN on its display — the PIN just typed is for the previous session.
        // We still send it (the user may be looking at the new PIN already).
        $renderedCc = isset($_REQUEST['pin_render_cc']) ? $_REQUEST['pin_render_cc'] : null;
        $currentCcRaw = Transport::numericValue($t->getState('sensor', 'Boiler Connect Count'));
        $stale = PairTrace::isStale($renderedCc, $currentCcRaw);

        // PIN is sensitive: send the numeric value once; NEVER store the digits.
        $res = $t->postSet('number', 'Boiler Pairing PIN', $v['value']);

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

        Repository::saveCommand(array(
            'ts_utc'     => time(),
            'command_id' => 'pair',
            'kind'       => 'pair',
            'payload'    => '****',
            'status'     => $accepted ? 'accepted' : ($res !== null ? 'sent' : 'failed'),
            'detail'     => ($state === null ? '' : $state) . ($stale ? ' [stale_pin]' : '')
                . (empty($traceTail) ? '' : (' trace:' . implode(',', $traceTail)))
                . ($lastDisconnectRaw ? (' disc:' . $lastDisconnectRaw) : ''),
        ));

        return array(
            'ok'               => ($res !== null),
            'accepted'         => $accepted,
            'state'            => $state,
            'reason'           => ($res !== null ? 'sent' : 'transport'),
            'error'            => $t->getLastError(),
            'stale'            => $stale,
            'trace_tail'       => $traceTail,
            'last_disconnect'  => $lastDisconnectRaw,
        );
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
        $out = array();
        if (!function_exists('SQLSelect')) {
            return $out;
        }
        $rows = SQLSelect("SELECT o.TITLE AS OBJ, p.TITLE AS PROP
            FROM pvalues pv
            LEFT JOIN objects o ON o.ID = pv.OBJECT_ID
            LEFT JOIN properties p ON p.ID = pv.PROPERTY_ID
            WHERE o.TITLE IS NOT NULL AND p.TITLE IS NOT NULL
            ORDER BY o.TITLE, p.TITLE");
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
        return $out;
    }

    /**
     * Build the inner HTML of a <select> for a property-binding field: an empty
     * first option (so "not bound" is possible) plus one <option> per property,
     * with the currently-configured value marked selected. If $current is not
     * present in $props it is still added (and selected) so a stored value is
     * never lost.
     *
     * @param array  $props   flat list of "Object.Property" strings
     * @param string $current currently configured value
     * @return string
     */
    private function propertyOptionsHtml($props, $current)
    {
        $current = (string) $current;
        $html = '<option value="">—</option>';
        $found = false;
        foreach ($props as $name) {
            $sel = ($name === $current) ? ' selected' : '';
            if ($sel !== '') {
                $found = true;
            }
            $html .= '<option value="' . htmlspecialchars($name, ENT_QUOTES) . '"' . $sel . '>'
                . htmlspecialchars($name, ENT_QUOTES) . '</option>';
        }
        if (!$found && $current !== '') {
            $html .= '<option value="' . htmlspecialchars($current, ENT_QUOTES) . '" selected>'
                . htmlspecialchars($current, ENT_QUOTES) . '</option>';
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

    private function checkCsrf()
    {
        $sent = isset($_REQUEST['csrf']) ? (string) $_REQUEST['csrf'] : '';
        $token = $this->csrfToken();
        if ($sent === '' || $token === '') {
            return false;
        }
        return hash_equals($token, $sent);
    }
}
