<?php

use DraziceSmart\Transport;

T::group('Transport host validation (SSRF)');

T::eq(Transport::validateHost('172.16.0.140')['ok'], true,  '172.16.0.140 private -> ok');
T::eq(Transport::validateHost('192.168.1.50')['ok'], true,  '192.168.1.50 private -> ok');
T::eq(Transport::validateHost('10.0.0.5')['ok'], true,      '10.0.0.5 private -> ok');
T::eq(Transport::validateHost('127.0.0.1')['ok'], false,    'loopback -> rejected');
T::eq(Transport::validateHost('169.254.169.254')['ok'], false, 'cloud metadata -> rejected');
T::eq(Transport::validateHost('8.8.8.8')['ok'], false,      'public IP -> rejected');
T::eq(Transport::validateHost('')['ok'], false,             'empty -> rejected');
T::eq(Transport::validateHost('boiler.local')['ok'], true,  'hostname -> ok');
T::eq(Transport::validateHost('http://evil/x')['ok'], false,'scheme/path -> rejected');
T::eq(Transport::validateHost('a@b')['ok'], false,          'userinfo char -> rejected');
T::eq(Transport::validateHost('10.0.0.5:80')['ok'], false,  'embedded port -> rejected');

T::group('Transport URL building (per-segment encoding)');
$t = new Transport(array('host' => '172.16.0.140', 'port' => 80));
$url = $t->buildUrl('sensor', 'Boiler Temp Upper');
T::eq($url, 'http://172.16.0.140:80/sensor/Boiler%20Temp%20Upper', 'spaces encoded as %20 per segment');
$url2 = $t->buildUrl('number', 'Boiler Requested Target', 'value=55');
T::eq($url2, 'http://172.16.0.140:80/number/Boiler%20Requested%20Target?value=55', 'query appended');

T::group('Transport value extraction');
T::eq(Transport::numericValue(array('value' => 55.0)), 55.0, 'numeric 55');
T::eq(Transport::numericValue(array('value' => '55')), 55.0, 'numeric string 55');
T::eq(Transport::numericValue(array('value' => 'NaN')), null, 'NaN string -> null');
T::eq(Transport::numericValue(array('state' => '55 C')), null, 'no value key -> null (do not parse state)');
T::eq(Transport::numericValue(array('value' => null)), null, 'null value -> null');
T::eq(Transport::numericValue(null), null, 'null json -> null');

T::eq(Transport::boolValue(array('value' => true)), true, 'bool true');
T::eq(Transport::boolValue(array('value' => 'on')), true, "'on' -> true");
T::eq(Transport::boolValue(array('value' => 0)), false, '0 -> false');
T::eq(Transport::boolValue(array('value' => 'unknownish')), null, 'unknown -> null');

T::eq(Transport::stringValue(array('state' => 'Connected')), 'Connected', 'state string');
T::eq(Transport::stringValue(array('value' => 'ANTIFREEZE')), 'ANTIFREEZE', 'value string fallback');
