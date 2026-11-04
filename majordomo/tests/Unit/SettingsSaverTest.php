<?php
use DraziceSmart\SettingsSaver;

T::group('SettingsSaver: checkboxes');
{
    $cfg = array('PRESENCE_ENABLED' => 1, 'SIMPLE_DEVICES_ENABLED' => 1, 'ESP_HOST' => '10.0.0.5');

    // Browser omits an unchecked checkbox; the form declares it in _cb.
    $r = SettingsSaver::apply($cfg, array('_cb' => 'PRESENCE_ENABLED', 'PRESENCE_ABSENT_AFTER_S' => '120'));
    T::eq($r['config']['PRESENCE_ENABLED'], 0, 'unchecked (declared, absent) checkbox -> 0');
    T::eq($r['config']['SIMPLE_DEVICES_ENABLED'], 1, 'checkbox of another form is untouched');
    T::eq($r['errors'], array(), 'no errors');

    $r = SettingsSaver::apply(array('PRESENCE_ENABLED' => 0), array('_cb' => 'PRESENCE_ENABLED', 'PRESENCE_ENABLED' => '1'));
    T::eq($r['config']['PRESENCE_ENABLED'], 1, 'checked checkbox -> 1');

    $r = SettingsSaver::apply(array('PRESENCE_ENABLED' => 1), array('PRESENCE_ENABLED' => '0'));
    T::eq($r['config']['PRESENCE_ENABLED'], 0, 'explicit 0 -> 0');

    $r = SettingsSaver::apply(array('PRESENCE_ENABLED' => 1), array('ESP_HOST' => 'x'));
    T::eq($r['config']['PRESENCE_ENABLED'], 1, 'checkbox not declared and absent -> unchanged');

    $r = SettingsSaver::apply(array('ESP_HOST' => 'a'), array('_cb' => 'ESP_HOST,NOT_A_FIELD'));
    T::eq($r['config']['ESP_HOST'], 'a', '_cb cannot turn non-checkbox fields into 0');
    T::ok(!array_key_exists('NOT_A_FIELD', $r['config']), '_cb cannot create unknown keys');
}

T::group('SettingsSaver: text and secret');
{
    $roster = '{"aa:bb:cc":"Назар \"x\" <b>"}';
    $r = SettingsSaver::apply(array('PRESENCE_HANDLER' => 'old'), array('PRESENCE_HANDLER' => $roster));
    T::eq($r['config']['PRESENCE_HANDLER'], $roster, 'text saved verbatim incl. quotes, Cyrillic, <>');

    $r = SettingsSaver::apply(array('ESP_HOST' => 'keep'), array('ESP_USER' => 'u'));
    T::eq($r['config']['ESP_HOST'], 'keep', 'missing text field keeps old value');
    T::eq($r['config']['ESP_USER'], 'u', 'present text field is stored');

    $r = SettingsSaver::apply(array('ESP_PASS' => 'secret'), array('ESP_PASS' => '********'));
    T::eq($r['config']['ESP_PASS'], 'secret', 'masked ESP_PASS keeps old password');
    $r = SettingsSaver::apply(array('ESP_PASS' => 'secret'), array('ESP_PASS' => 'new'));
    T::eq($r['config']['ESP_PASS'], 'new', 'real ESP_PASS replaces old password');

    $r = SettingsSaver::apply(array('ESP_HOST' => 'a'), array('ESP_HOST' => 'b'));
    T::eq($r['changed'], array('ESP_HOST'), 'changed lists modified fields');
    $r = SettingsSaver::apply(array('ESP_HOST' => 'a'), array('ESP_HOST' => 'a'));
    T::eq($r['changed'], array(), 'unchanged value is not listed as changed');
}

T::group('SettingsSaver: numeric');
{
    $r = SettingsSaver::apply(array('ESP_PORT' => 80), array('ESP_PORT' => ' 8080 ', 'COMFORT_MIN_C' => '42.5'));
    T::eq($r['config']['ESP_PORT'], '8080', 'numeric is trimmed and stored');
    T::eq($r['config']['COMFORT_MIN_C'], '42.5', 'decimal numeric accepted');

    $r = SettingsSaver::apply(array('ESP_PORT' => 80, 'ESP_HOST' => 'a'), array('ESP_PORT' => 'abc', 'ESP_HOST' => 'b'));
    T::eq($r['errors'], array('ESP_PORT' => 'numeric'), 'non-numeric -> error');
    T::eq($r['config']['ESP_PORT'], 80, 'non-numeric keeps previous value');
    T::eq($r['rejected']['ESP_PORT'], 'abc', 'rejected raw input is returned');
    T::eq($r['config']['ESP_HOST'], 'b', 'other valid fields are still saved');

    $r = SettingsSaver::apply(array('BOOST_TTL_S' => 1800), array('BOOST_TTL_S' => ''));
    T::eq($r['errors'], array('BOOST_TTL_S' => 'numeric'), 'empty numeric -> error');
    T::eq($r['config']['BOOST_TTL_S'], 1800, 'empty numeric keeps previous value');
}

T::group('SettingsSaver: JSON objects');
{
    $good = '{"aa:bb:cc:dd:ee:ff":"Назар"}';
    $r = SettingsSaver::apply(array('PRESENCE_ROSTER_JSON' => '{}'), array('PRESENCE_ROSTER_JSON' => $good));
    T::eq($r['config']['PRESENCE_ROSTER_JSON'], $good, 'valid JSON roster is stored');
    T::eq($r['errors'], array(), 'valid JSON -> no error');

    $r = SettingsSaver::apply(array('PRESENCE_ROSTER_JSON' => $good), array('PRESENCE_ROSTER_JSON' => '{"aa":'));
    T::eq($r['errors'], array('PRESENCE_ROSTER_JSON' => 'json'), 'broken JSON -> error');
    T::eq($r['config']['PRESENCE_ROSTER_JSON'], $good, 'broken JSON keeps previous value');
    T::eq($r['rejected']['PRESENCE_ROSTER_JSON'], '{"aa":', 'broken JSON raw input is returned');

    $r = SettingsSaver::apply(array('SIMPLE_DEVICES_MAP_JSON' => ''), array('SIMPLE_DEVICES_MAP_JSON' => '[1,2]'));
    T::eq($r['errors'], array('SIMPLE_DEVICES_MAP_JSON' => 'json'), 'JSON array (not object) -> error');

    $r = SettingsSaver::apply(array('SIMPLE_DEVICES_MAP_JSON' => '{"a":1}'), array('SIMPLE_DEVICES_MAP_JSON' => '  '));
    T::eq($r['config']['SIMPLE_DEVICES_MAP_JSON'], '', 'empty JSON field clears the value');
    T::eq($r['errors'], array(), 'empty JSON field is not an error');

    T::ok(SettingsSaver::isJsonObject('{}'), 'isJsonObject: {} is an object');
    T::ok(!SettingsSaver::isJsonObject('"x"'), 'isJsonObject: string is not an object');
    T::ok(!SettingsSaver::isJsonObject('{"a"'), 'isJsonObject: truncated is not an object');
}

T::group('SettingsSaver: round trip through HTML attribute');
{
    $roster = '{"aa:bb:cc":"Назар"}';
    $html = '<input value="' . SettingsSaver::esc($roster) . '">';
    // A browser decodes the attribute; emulate with html_entity_decode.
    preg_match('/value="([^"]*)"/', $html, $m);
    T::eq(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $roster, 'roster with quotes survives an attribute round trip');
    T::ok(strpos($m[1], '"') === false, 'no raw double quote inside the attribute');
}

T::group('SettingsSaver::esc');
{
    T::eq(SettingsSaver::esc('a"b'), 'a&quot;b', 'double quote escaped');
    T::eq(SettingsSaver::esc("a'b"), 'a&#039;b', 'single quote escaped');
    T::eq(SettingsSaver::esc('a<b>c'), 'a&lt;b&gt;c', '< and > escaped');
    T::eq(SettingsSaver::esc('a&b'), 'a&amp;b', '& escaped');
    T::eq(SettingsSaver::esc('x</textarea><script>'), 'x&lt;/textarea&gt;&lt;script&gt;', '</textarea> cannot close the element');
    T::eq(SettingsSaver::esc('Назар'), 'Назар', 'Cyrillic kept as-is');
    T::eq(SettingsSaver::esc(null), '', 'null -> empty string');
    T::eq(SettingsSaver::esc(5), '5', 'int -> string');
    T::eq(SettingsSaver::esc(array('a' => '"')), '{&quot;a&quot;:&quot;\&quot;&quot;}', 'array is JSON-encoded then escaped');
    T::eq(SettingsSaver::esc("bad\xff"), "bad\xEF\xBF\xBD", 'invalid UTF-8 is substituted, not dropped');
}
