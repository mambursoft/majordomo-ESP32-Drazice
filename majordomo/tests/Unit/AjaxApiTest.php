<?php

use DraziceSmart\AjaxApi;

T::group('AjaxApi::isAjaxRequest');
T::eq(AjaxApi::isAjaxRequest(array('ajax' => '1')), true, "ajax='1' -> true");
T::eq(AjaxApi::isAjaxRequest(array('ajax' => '0')), false, "ajax='0' -> false");
T::eq(AjaxApi::isAjaxRequest(array('ajax' => 1)), false, 'int 1 (not a request string) -> false');
T::eq(AjaxApi::isAjaxRequest(array()), false, 'no param -> false');
T::eq(AjaxApi::isAjaxRequest(null), false, 'null -> false');

T::group('AjaxApi::classify');
foreach (AjaxApi::READ_OPS as $op) {
    T::eq(AjaxApi::classify($op), 'read', "$op is a read op");
}
foreach (AjaxApi::MUTATING_OPS as $op) {
    T::eq(AjaxApi::classify($op), 'mutating', "$op is a mutating op");
}
T::eq(AjaxApi::classify(''), null, 'empty op -> null');
T::eq(AjaxApi::classify('nope'), null, 'unknown op -> null');
T::eq(AjaxApi::classify(null), null, 'null op -> null');
T::eq(AjaxApi::classify(array('status')), null, 'array op -> null');
T::eq(count(array_intersect(AjaxApi::READ_OPS, AjaxApi::MUTATING_OPS)), 0, 'read and mutating sets are disjoint');
T::eq(in_array('probe_host', AjaxApi::MUTATING_OPS, true) && in_array('scan_network', AjaxApi::MUTATING_OPS, true), true,
    'outbound LAN actions probe_host/scan_network are classed mutating (POST + CSRF)');

T::group('AjaxApi::authorize - read ops');
foreach (AjaxApi::READ_OPS as $op) {
    $g = AjaxApi::authorize($op, 'GET', false);
    T::eq($g['ok'], true, "$op: GET without csrf allowed");
    $p = AjaxApi::authorize($op, 'POST', false);
    T::eq($p['ok'], true, "$op: POST without csrf allowed");
}

T::group('AjaxApi::authorize - EVERY mutating op');
foreach (AjaxApi::MUTATING_OPS as $op) {
    $a = AjaxApi::authorize($op, 'POST', false);
    T::ok($a['ok'] === false && $a['status'] === 403 && $a['error'] === 'csrf', "$op: POST without csrf -> 403 csrf");
    $b = AjaxApi::authorize($op, 'GET', true);
    T::ok($b['ok'] === false && $b['status'] === 405 && $b['error'] === 'method', "$op: GET even with csrf -> 405 method");
    $b2 = AjaxApi::authorize($op, 'GET', false);
    T::ok($b2['ok'] === false && $b2['status'] === 405, "$op: GET without csrf -> 405");
    $c = AjaxApi::authorize($op, 'POST', true);
    T::ok($c['ok'] === true && $c['status'] === 200, "$op: POST + csrf -> ok");
    $d = AjaxApi::authorize($op, 'post', true);
    T::eq($d['ok'], true, "$op: method compare is case-insensitive");
}

T::group('AjaxApi::authorize - unknown ops');
$u = AjaxApi::authorize('', 'POST', true);
T::ok($u['ok'] === false && $u['status'] === 400 && $u['error'] === 'unknown_op', 'empty op -> 400 unknown_op');
$u2 = AjaxApi::authorize('rm_rf', 'POST', true);
T::ok($u2['ok'] === false && $u2['status'] === 400 && $u2['error'] === 'unknown_op', 'unknown op -> 400 unknown_op');

T::group('AjaxApi::jsonForScript (safe inside <script>)');
$evil = array(
    "з'єднано <b>",
    '</script><script>alert(1)</script>',
    'a & b "q" \'s\'',
    "line\u{2028}sep",
);
$js = AjaxApi::jsonForScript($evil);
T::eq(strpos($js, '<'), false, 'no raw "<"');
T::eq(strpos($js, '>'), false, 'no raw ">"');
T::eq(strpos($js, '&'), false, 'no raw "&"');
T::eq(strpos($js, "'"), false, "no raw apostrophe");
T::eq(stripos($js, '</script'), false, 'no </script> possible');
T::eq(strpos($js, "\xE2\x80\xA8"), false, 'U+2028 escaped (breaks JS string literals otherwise)');
T::eq(json_decode($js, true), $evil, 'still decodes to the exact original strings');
T::ok(strpos($js, 'з') !== false, 'cyrillic stays readable (UNESCAPED_UNICODE)');
T::ok(is_string(AjaxApi::jsonForScript(array('x' => NAN))), 'unencodable value never makes it return false/crash');

T::group('AjaxApi::i18nKeys / jsStrings');
$keys = AjaxApi::i18nKeys();
T::ok(count($keys) === count(array_unique($keys)), 'i18n key list has no duplicates');
foreach (array('SAVE_OK', 'SETUP_PIN_ACCEPTED', 'SETUP_PIN_HTTP_ERR', 'DATA_STALE', 'AJAX_NETWORK_ERROR', 'PAIR_STALE_WARN') as $k) {
    T::ok(in_array($k, $keys, true), "i18n key $k listed");
}
$fake = function ($k) {
    if ($k === 'SAVE_OK') return 'Збережено.';
    if ($k === 'YES') return "it's";
    return null;
};
$s = AjaxApi::jsStrings($fake);
T::eq(isset($s['YES']) ? $s['YES'] : null, "it's", 'resolver value with apostrophe kept as-is');
T::eq(isset($s['SAVE_OK']) ? $s['SAVE_OK'] : null, 'Збережено.', 'resolver value used');
T::eq(isset($s['LOADING']) ? $s['LOADING'] : 'MISSING', '', 'keys the resolver cannot translate (null) become empty string');
T::eq(count($s), count($keys), 'every listed key is present in the output');
