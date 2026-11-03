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
        if (isset($tab))       $this->tab       = $tab;
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
                    'CURRENT_VERSION' => '1.2.0',
                    'LATEST_VERSION'  => '1.2.0',
                    'IS_INSTALLED'    => 1,
                    'LATEST_UPDATE'   => date('Y-m-d H:i:s'),
                );
                SQLInsert('plugins', $plRec);
            } else {
                $pRec['TITLE']           = $this->title;
                $pRec['AUTHOR']          = 'mambursoft';
                $pRec['DESCRIPTION_RU']  = $descRu;
                $pRec['DESCRIPTION_EN']  = $descEn;
                $pRec['CURRENT_VERSION'] = '1.2.0';
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
            'CHEAP_END_HOUR'       => 7,
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
            // presence (variant a: passive Wi-Fi/BLE by MAC) — OFF by default
            'PRESENCE_ENABLED'     => 0,
            'PRESENCE_ROSTER_JSON' => '{}', // {"aa:bb:..":"Назар"}
            'PRESENCE_ABSENT_AFTER_S' => 120,
            'PRESENCE_NOTIFY_AFTER_S' => 600,
            'PRESENCE_RENOTIFY_EVERY_S' => 0,
            'PRESENCE_HANDLER'     => '',   // MajorDoMo method to call (announce)
            // surplus latch state (planner hysteresis)
            'SURPLUS_LATCHED'      => 0,
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
            'cheap_end_hour'      => $this->config['CHEAP_END_HOUR'],
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

    private function publishProperties($s, $d)
    {
        if (!function_exists('setGlobal')) {
            return;
        }
        // Dirty-check to avoid setGlobal recursion loops: only write on change.
        $map = array(
            'EspBoiler.tempUpper'    => $s['temp_upper'],
            'EspBoiler.tempLower'    => $s['temp_lower'],
            'EspBoiler.heating'      => ($s['heating'] === null ? '' : ($s['heating'] ? 1 : 0)),
            'EspBoiler.powerSource'  => $s['power_source'],
            'EspBoiler.batterySoc'   => ($s['battery_soc'] === null ? '' : $s['battery_soc']),
            'EspBoiler.tariffActive' => $s['tariff_active'] ? 1 : 0,
            'EspBoiler.decisionReason' => $d['reason'],
            'EspBoiler.plannedTarget' => ($d['target_c'] === null ? '' : $d['target_c']),
            'EspBoiler.controlState' => $d['action'],
        );
        foreach ($map as $prop => $val) {
            if ($val === null) {
                continue;
            }
            $cur = getGlobal($prop);
            if ((string) $cur !== (string) $val) {
                setGlobal($prop, $val);
            }
        }
    }

    private function evaluatePresence($now)
    {
        // Observations come from the LAN (router/arp). We read a property that a
        // user cron/rule can populate: EspBoiler.presenceObservationsJson (list
        // of {mac,seen_utc}). If absent, nothing to do.
        $obsRaw = Repository::readProperty('EspBoiler.presenceObservationsJson');
        $obs = array();
        if ($obsRaw) {
            $decoded = json_decode($obsRaw, true);
            if (is_array($decoded)) {
                $obs = $decoded;
            }
        }
        $roster = json_decode($this->config['PRESENCE_ROSTER_JSON'], true);
        if (!is_array($roster)) {
            $roster = array();
        }
        $stateRaw = Repository::readProperty('EspBoiler.presenceStateJson');
        $state = $stateRaw ? json_decode($stateRaw, true) : array();
        if (!is_array($state)) {
            $state = array();
        }

        $res = Presence::evaluate($obs, $roster, $now, array(
            'presence_enabled'  => true,
            'absent_after_s'    => (int) $this->config['PRESENCE_ABSENT_AFTER_S'],
            'notify_after_s'    => (int) $this->config['PRESENCE_NOTIFY_AFTER_S'],
            'renotify_every_s'  => (int) $this->config['PRESENCE_RENOTIFY_EVERY_S'],
        ), $state);

        if (function_exists('setGlobal')) {
            setGlobal('EspBoiler.presenceStateJson', json_encode($res['state']));
            $labels = array();
            foreach ($res['present'] as $pp) {
                $labels[] = $pp['person'] . ' (' . intval($pp['duration_s'] / 60) . ' хв)';
            }
            setGlobal('EspBoiler.presentPeople', implode(', ', $labels));
        }

        foreach ($res['events'] as $ev) {
            Repository::saveCommand(array(
                'ts_utc' => $now, 'command_id' => 'presence', 'kind' => 'presence',
                'payload' => $ev['person'] . ':' . $ev['duration_s'], 'status' => 'notify',
                'detail' => $ev['action'],
            ));
            // Call the configured MajorDoMo handler (e.g. announce on speaker).
            $handler = isset($this->config['PRESENCE_HANDLER']) ? $this->config['PRESENCE_HANDLER'] : '';
            if ($handler && function_exists('callMethod')) {
                callMethod($handler, array(
                    'person' => $ev['person'],
                    'duration_s' => $ev['duration_s'],
                ));
            }
        }
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
            $out['CFG_' . $k] = is_scalar($v) ? $v : json_encode($v);
        }
    }

    function admin(&$out)
    {
        global $this_computer;
        $this->getConfig();

        $op = isset($_REQUEST['op']) ? $_REQUEST['op'] : '';

        // CSRF: all mutating ops require a valid session token.
        if ($op !== '' && $op !== 'tab') {
            if (!$this->checkCsrf()) {
                $out['ERROR'] = 'csrf';
                $op = '';
            }
        }

        switch ($op) {
            case 'save_settings':
                $this->opSaveSettings();
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
                $out['SCAN_RESULT'] = $this->opScanNetwork();
                break;
            case 'probe_host':
                $out['PROBE_RESULT'] = $this->opProbeHost();
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
                $out['CMD_RESULT'] = $this->opSendPin();
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
        $out['BLE_DEVICES']      = $ble['list'];
        $out['SELECTED_BLE_MAC'] = isset($this->config['BOILER_BLE_MAC']) ? $this->config['BOILER_BLE_MAC'] : '';

        // Full diagnostics: flatten every firmware entity into DG_<KEY> keys.
        $diag = $this->readDiagnostics();
        foreach ($diag as $k => $v) {
            $out['DG_' . $k] = ($v === null || $v === '') ? '—' : $v;
        }
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
        if (!function_exists('SQLSelect')) {
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
        if (!function_exists('SQLSelect')) {
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

    private function opSaveSettings()
    {
        $fields = array(
            'ESP_HOST','ESP_PORT','ESP_USER','ESP_PASS','ESP_DIGEST','POLL_INTERVAL_S',
            'COMFORT_MIN_C','NIGHT_TARGET_C','SOLAR_TARGET_C','BOOST_TARGET_C','ABSOLUTE_MAX_C',
            'TIMEZONE','CHEAP_START_HOUR','CHEAP_END_HOUR','METER_OFFSET_SECONDS',
            'START_GUARD_SECONDS','END_GUARD_SECONDS','MAX_CONTINUOUS_HEAT_S','MIN_PAUSE_S',
            'LEASE_TTL_S','COMMAND_TIMEOUT_S','POWER_PROPERTY','BATTERY_ACTIVE_VALUE',
            'POWER_FRESHNESS_TTL_S','SOLAR_SURPLUS_ENABLED','SOC_PROPERTY','SURPLUS_SOC_HIGH',
            'SURPLUS_SOC_LOW','FORECAST_TODAY_PROP','FORECAST_TOMORROW_PROP','FORECAST_UPDATED_PROP',
            'FORECAST_PROVIDER_PROP','FORECAST_FRESHNESS_TTL_S','BOOST_TTL_S','PRESENCE_ENABLED',
            'PRESENCE_ROSTER_JSON','PRESENCE_ABSENT_AFTER_S','PRESENCE_NOTIFY_AFTER_S',
            'PRESENCE_RENOTIFY_EVERY_S','PRESENCE_HANDLER',
            'SIMPLE_DEVICES_ENABLED','SIMPLE_DEVICES_MAP_JSON',
        );
        foreach ($fields as $f) {
            if (isset($_REQUEST[$f])) {
                // Keep masked password unchanged if left as asterisks.
                if ($f === 'ESP_PASS' && $_REQUEST[$f] === '********') {
                    continue;
                }
                $this->config[$f] = $_REQUEST[$f];
            }
        }
        // Validate host before saving if provided.
        $this->saveConfig();
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
        $pin = isset($_REQUEST['pin']) ? (int) $_REQUEST['pin'] : 0;
        if ($pin < 1111 || $pin > 9999) {
            return array('ok' => false, 'reason' => 'bad_pin');
        }
        $t = $this->makeTransport();
        // PIN is sensitive: never store it; send once and forget.
        $res = $t->postSet('number', 'Boiler Pairing PIN', $pin);
        return array('ok' => ($res !== null), 'error' => $t->getLastError());
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

    // ---- CSRF ---------------------------------------------------------------

    private function csrfToken()
    {
        global $session;
        if (is_object($session) && method_exists($session, 'get')) {
            $tok = $session->get('drazice_csrf');
            if (!$tok) {
                $tok = md5(uniqid((string) mt_rand(), true));
                $session->set('drazice_csrf', $tok);
            }
            return $tok;
        }
        // Fallback: per-process token (still better than nothing).
        if (!isset($GLOBALS['drazice_csrf_fallback'])) {
            $GLOBALS['drazice_csrf_fallback'] = md5(uniqid((string) mt_rand(), true));
        }
        return $GLOBALS['drazice_csrf_fallback'];
    }

    private function checkCsrf()
    {
        $sent = isset($_REQUEST['csrf']) ? $_REQUEST['csrf'] : '';
        return ($sent !== '' && hash_equals($this->csrfToken(), $sent));
    }
}
