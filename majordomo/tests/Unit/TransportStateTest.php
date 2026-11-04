<?php

use DraziceSmart\Transport;

$stateFixture = <<<'JSON'
{"sensor":{
  "Boiler Temp Upper":{"value":55.5,"state":"55.5"},
  "Boiler Temp Lower":{"value":null,"state":"NA"},
  "ESP Uptime":{"value":"NaN","state":"NaN"},
  "_http":{"value":1,"state":"1"}
 },
 "binary_sensor":{
  "Boiler Heating":{"value":true,"state":"ON"},
  "Boiler HDO":{"value":false,"state":"OFF"},
  "Boiler Unknown":{"value":null,"state":"NA"}
 },
 "text_sensor":{
  "Boiler Pair Trace":{"value":"TAG@12 | CONN@5 | \"q\" \\ x","state":"TAG@12 | CONN@5 | \"q\" \\ x"},
  "Boiler Name":{"value":"Бойлер «OKHE»","state":"Бойлер «OKHE»"},
  "Boiler Last Disconnect":{"value":null,"state":null}
 },
 "number":{
  "Boiler Requested Target":{"value":55,"state":"55"},
  "Boiler Pairing PIN":{"value":692,"state":"692"}
 },
 "select":{
  "Boiler Requested Mode":{"value":"SMART","state":"SMART"}
 },
 "garbage":{"X":{"value":1,"state":"1"}},
 "_http":200
}
JSON;

T::group('Transport::parseAll (GET /state document)');
$map = Transport::parseAll(json_decode($stateFixture, true));

T::eq($map['sensor']['Boiler Temp Upper']['value'], 55.5, 'numeric 55.5 kept');
T::eq($map['sensor']['Boiler Temp Upper']['state'], '55.5', 'numeric state string kept');
T::eq($map['sensor']['Boiler Temp Lower']['value'], null, 'value null / state NA -> null (NEVER 0)');
T::eq($map['sensor']['ESP Uptime']['value'], null, 'non-numeric "NaN" -> null (never 0)');
T::eq($map['binary_sensor']['Boiler Heating']['value'], true, 'binary true');
T::eq($map['binary_sensor']['Boiler HDO']['value'], false, 'binary false');
T::eq($map['binary_sensor']['Boiler Unknown']['value'], null, 'binary null stays null');
$tr = "TAG@12 | CONN@5 | \"q\" \\ x";
T::eq($map['text_sensor']['Boiler Pair Trace']['state'], $tr, 'text with quotes/backslash/pipe/at round-trips');
T::eq($map['text_sensor']['Boiler Pair Trace']['value'], $tr, 'text value mirrors state');
T::eq($map['text_sensor']['Boiler Name']['state'], 'Бойлер «OKHE»', 'unicode text kept');
T::eq($map['text_sensor']['Boiler Last Disconnect']['state'], null, 'text null stays null');
T::eq($map['number']['Boiler Requested Target']['value'], 55.0, 'number value float');
T::eq(isset($map['number']['Boiler Pairing PIN']), false, 'write-only Boiler Pairing PIN entity DROPPED');
T::eq($map['select']['Boiler Requested Mode']['value'], 'SMART', 'select value');
T::eq(isset($map['sensor']['_http']), false, '_http entity key ignored');
T::eq(isset($map['garbage']), false, 'unknown domain ignored');
T::eq(isset($map['_http']), false, 'top-level _http ignored');
T::eq(Transport::parseAll('x'), array(), 'non-array input -> empty map');
T::eq(Transport::parseAll(array('sensor' => 'oops')), array(), 'domain not an array ignored');
T::eq(Transport::parseAll(array('sensor' => array('A' => 'str'))), array('sensor' => array()), 'entity not an array skipped');

T::group('Transport::entity (null-safe lookup) + helpers on map entries');
T::eq(Transport::entity($map, 'sensor', 'Nope'), null, 'missing entity -> null');
T::eq(Transport::entity($map, 'nodomain', 'x'), null, 'missing domain -> null');
T::eq(Transport::entity(null, 'sensor', 'x'), null, 'null map -> null');
T::eq(Transport::numericValue(Transport::entity($map, 'sensor', 'Boiler Temp Upper')), 55.5, 'numericValue works on map entry');
T::eq(Transport::numericValue(Transport::entity($map, 'sensor', 'Boiler Temp Lower')), null, 'numericValue(null entry) -> null');
T::eq(Transport::boolValue(Transport::entity($map, 'binary_sensor', 'Boiler Heating')), true, 'boolValue works on map entry');
T::eq(Transport::stringValue(Transport::entity($map, 'text_sensor', 'Boiler Pair Trace')), $tr, 'stringValue works on map entry');

T::group('Transport::SENSITIVE / LEGACY_ENTITIES');
T::eq(Transport::SENSITIVE['number'][0], 'Boiler Pairing PIN', 'PIN entity declared sensitive');
T::eq(count(Transport::LEGACY_ENTITIES), 26, '26 unique legacy entities (buildSnapshot + readDiagnostics + BLE scan)');
$seen = array();
foreach (Transport::LEGACY_ENTITIES as $p) {
    $seen[$p[0] . '|' . $p[1]] = true;
}
T::eq(count($seen), 26, 'legacy entity list has no duplicates');
T::eq(isset($seen['number|Boiler Pairing PIN']), false, 'legacy list never reads the PIN entity');

T::group('Transport::classifyStateResponse (200 / 404 fallback / errors)');
$c = Transport::classifyStateResponse(200, $stateFixture);
T::eq($c['kind'], 'map', '200 + JSON -> map');
T::eq($c['map']['sensor']['Boiler Temp Upper']['value'], 55.5, '200 map content parsed');
T::eq(Transport::classifyStateResponse(404, 'Not Found')['kind'], 'legacy', '404 -> legacy per-entity fallback');
$e401 = Transport::classifyStateResponse(401, '');
T::eq($e401['kind'], 'error', '401 -> error, NO fallback');
T::eq($e401['error']['stage'], 'auth', '401 stage=auth');
T::eq(Transport::classifyStateResponse(403, '')['kind'], 'error', '403 -> error, NO fallback');
$e500 = Transport::classifyStateResponse(500, '');
T::eq($e500['kind'], 'error', '500 -> error, NO fallback');
T::eq($e500['error']['reason'], 'http_500', '500 reason http_500');
T::eq(Transport::classifyStateResponse(200, '{not json')['kind'], 'error', 'bad JSON -> error');
T::eq(Transport::classifyStateResponse(200, '{not json')['error']['reason'], 'bad_json', 'bad JSON reason');
T::eq(Transport::classifyStateResponse(200, '')['kind'], 'error', 'empty body -> error');
T::eq(Transport::classifyStateResponse(200, '"just a string"')['kind'], 'error', 'JSON scalar -> error');
$bad = '{"text_sensor":{"BLE Scan Results":{"value":"[{\"name\":\"' . "\xff\xfe" . 'X\"}]","state":"ok"}}}';
$cb = Transport::classifyStateResponse(200, $bad);
T::eq($cb['kind'], 'map', 'invalid UTF-8 byte in a BLE name does NOT break the parse');
T::ok(isset($cb['map']['text_sensor']['BLE Scan Results']), 'entity with invalid UTF-8 survives');

T::group('Transport write URLs (firmware rest_api needs /set and /press)');
$t = new Transport(array('host' => '172.16.0.150', 'port' => 80));
T::eq($t->buildSetUrl('number', 'Boiler Pairing PIN', 692),
    'http://172.16.0.150:80/number/Boiler%20Pairing%20PIN/set?value=692', 'number set URL has /set');
T::eq($t->buildSetUrl('select', 'Boiler Requested Mode', 'SMART'),
    'http://172.16.0.150:80/select/Boiler%20Requested%20Mode/set?value=SMART', 'select set URL has /set');
T::eq($t->buildSetUrl('number', 'Boiler Pairing PIN', '0692'),
    'http://172.16.0.150:80/number/Boiler%20Pairing%20PIN/set?value=0692', 'leading zero preserved');
T::eq($t->buildSetUrl('select', 'X', 'a b&c'),
    'http://172.16.0.150:80/select/X/set?value=a%20b%26c', 'value is URL-encoded');
T::eq($t->buildPressUrl('Boiler Lease Renew'),
    'http://172.16.0.150:80/button/Boiler%20Lease%20Renew/press', 'button press URL unchanged');
