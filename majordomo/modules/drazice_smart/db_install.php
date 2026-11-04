<?php
/**
 * db_install.php for the drazice_smart module.
 * Mirrors dbInstall() in drazice_smart.class.php. Re-safe: parent::dbInstall
 * only adds missing tables/columns and never drops history.
 *
 * @package drazice_smart
 * @license MIT
 */

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
