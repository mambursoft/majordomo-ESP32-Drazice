<?php
/**
 * FEAT-003: language keys, template tokens and the static JS file.
 * Language files are parsed as text (including them would define the LANG_*
 * constants globally and leak into the other tests).
 */

use DraziceSmart\AjaxApi;

$root = dirname(__DIR__, 2); // .../majordomo
$langs = array('default', 'ru', 'ua');

/** 'DS_KEY' => 'value' pairs of one language file (apostrophes unescaped). */
function lk_parse($file)
{
    $src = file_get_contents($file);
    $out = array();
    if (preg_match_all("/^\\s*'(DS_[A-Z0-9_]+)'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,/m", $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $out[$row[1]] = str_replace(array("\\'", '\\\\'), array("'", '\\'), $row[2]);
        }
    }
    return $out;
}

$newKeys = array(
    'DS_LOADING', 'DS_DATA_UPDATED', 'DS_DATA_AGO', 'DS_DATA_STALE', 'DS_DATA_NONE', 'DS_DATA_LAST_FAIL',
    'DS_REFRESH', 'DS_FETCH_ERROR', 'DS_AJAX_NETWORK_ERROR', 'DS_SAVING', 'DS_NOSCRIPT_NOTE',
);

$dict = array(); // [set][lang] => pairs
foreach ($langs as $l) {
    $dict['module'][$l] = lk_parse($root . '/modules/drazice_smart/languages/drazice_smart_' . $l . '.php');
    $dict['pkg'][$l] = lk_parse($root . '/languages/drazice_smart_' . $l . '.php');
}

T::group('Language files: new FEAT-003 keys in all six files');
foreach (array('module', 'pkg') as $set) {
    foreach ($langs as $l) {
        foreach ($newKeys as $k) {
            T::ok(isset($dict[$set][$l][$k]) && $dict[$set][$l][$k] !== '', "$set/$l has $k");
        }
    }
}
T::ok(strpos($dict['module']['ua']['DS_AJAX_NETWORK_ERROR'], 'НЕ підтверджено') !== false, 'ua network-error text warns the action is NOT confirmed');
T::ok(strpos($dict['module']['ru']['DS_AJAX_NETWORK_ERROR'], 'НЕ подтверждено') !== false, 'ru network-error text warns the action is NOT confirmed');
T::ok(strpos($dict['module']['default']['DS_AJAX_NETWORK_ERROR'], 'NOT confirmed') !== false, 'default network-error text warns the action is NOT confirmed');

T::group('Language files: the two sets are byte-identical per language');
foreach ($langs as $l) {
    $a = file_get_contents($root . '/languages/drazice_smart_' . $l . '.php');
    $b = file_get_contents($root . '/modules/drazice_smart/languages/drazice_smart_' . $l . '.php');
    T::ok($a !== false && $a === $b, "languages/drazice_smart_$l.php == modules/drazice_smart/languages/drazice_smart_$l.php");
}

T::group('Templates: every LANG_DS_* token is defined in all three languages');
foreach (array('action_admin.html', 'action_usual.html') as $tpl) {
    $html = file_get_contents($root . '/modules/drazice_smart/templates/' . $tpl);
    preg_match_all('/<#LANG_(DS_[A-Z0-9_]+)#>/', $html, $mm);
    $used = array_unique($mm[1]);
    T::ok(count($used) > 0, "$tpl uses LANG_DS tokens");
    foreach ($langs as $l) {
        $missing = array();
        foreach ($used as $k) {
            if (!isset($dict['module'][$l][$k]) || $dict['module'][$l][$k] === '') {
                $missing[] = $k;
            }
        }
        T::eq($missing, array(), "$tpl: all tokens defined in $l");
    }
}

T::group('AjaxApi::i18nKeys resolve to non-empty strings');
foreach ($langs as $l) {
    $missing = array();
    foreach (AjaxApi::i18nKeys() as $short) {
        if (!isset($dict['module'][$l]['DS_' . $short]) || $dict['module'][$l]['DS_' . $short] === '') {
            $missing[] = $short;
        }
    }
    T::eq($missing, array(), "i18nKeys all translated in $l");
}

T::group('Static JS file');
$jsPath = $root . '/modules/drazice_smart/js/drazice_smart.js';
T::ok(is_file($jsPath), 'drazice_smart.js exists');
$js = is_file($jsPath) ? file_get_contents($jsPath) : '';
T::eq(strpos($js, '<#'), false, 'JS has no parser tag open "<#"');
T::eq(strpos($js, '[#'), false, 'JS has no parser tag open "[#"');
T::eq(strpos($js, 'innerHTML'), false, 'JS never uses innerHTML');
T::eq(strpos($js, 'outerHTML'), false, 'JS never uses outerHTML');
T::eq(strpos($js, 'document.write'), false, 'JS never uses document.write');
T::eq(strpos($js, 'eval('), false, 'JS never uses eval(');
T::eq(preg_match('/\p{Cyrillic}/u', $js), 0, 'JS has no Cyrillic text (all strings come from DS_I18N)');
T::ok(strpos($js, 'textContent') !== false, 'JS renders with textContent');
T::ok(strpos($js, 'X-Requested-With') !== false, 'JS sends X-Requested-With');
T::ok(strpos($js, 'window.DraziceSmart') !== false, 'JS exposes only window.DraziceSmart');

T::group('Templates: JS include and data');
$admin = file_get_contents($root . '/modules/drazice_smart/templates/action_admin.html');
T::ok(strpos($admin, 'modules/drazice_smart/js/drazice_smart.js') !== false, 'action_admin.html includes the static JS');
T::ok(strpos($admin, '[#JS_I18N#]') !== false, 'action_admin.html injects JS_I18N');
T::ok(strpos($admin, '[#JS_CONFIG#]') !== false, 'action_admin.html injects JS_CONFIG');
T::ok(strpos($admin, '<#ROOTHTML#>modules/drazice_smart/js/drazice_smart.js') !== false, 'script src uses ROOTHTML');
T::eq(strpos($admin, 'innerHTML'), false, 'action_admin.html has no innerHTML');
T::ok(strpos($admin, 'id="ds-banner"') !== false && strpos($admin, 'id="ds-meta"') !== false && strpos($admin, 'id="ds-refresh"') !== false, 'banner, meta strip and refresh button present');
T::ok(strpos($admin, 'id="ds-ble-scan"') !== false, 'explicit BLE scan button present');
T::ok(strpos($admin, 'id="ds-log-decisions"') !== false && strpos($admin, 'id="ds-log-commands"') !== false, 'log tables have stable ids');
T::ok(strpos($admin, 'data-ds-pair-cc') !== false, 'pin_render_cc carries data-ds-pair-cc');
T::eq(strpos($admin, '[#DIAG.'), false, 'no non-working DIAG.* tags left');
T::eq(substr_count($admin, '<form method="post"') > 0, true, 'plain POST forms kept (no-JS fallback)');
// No literal parser tags inside HTML comments.
preg_match_all('/<!--(.*?)-->/s', $admin, $cm);
$bad = 0;
foreach ($cm[1] as $c) {
    if (strpos($c, '[#') !== false || strpos($c, '<#') !== false) {
        $bad++;
    }
}
T::eq($bad, 0, 'no parser tags inside HTML comments');
$usual = file_get_contents($root . '/modules/drazice_smart/templates/action_usual.html');
T::ok(strpos($usual, 'DATA_STALE') !== false && strpos($usual, '<script') === false, 'usual widget shows the stale badge and has no script');
