<?php

use DraziceSmart\BoilerObject;

T::group('BoilerObject: property change handlers (ONCHANGE) catalogue');

$cat = BoilerObject::catalogue();
$control = array();
$presence = array();
$user = array();
$methods = array();
foreach ($cat as $name => $def) {
    if (!empty($def['control'])) {
        $control[$name] = $def['method'];
    }
    if (!empty($def['onchange'])) {
        if (!empty($def['user'])) {
            $user[$name] = $def['method'];
        } else {
            $presence[$name] = $def['method'];
        }
    }
    if (!empty($def['method'])) {
        $methods[] = $def['method'];
    }
}

// Every control property the module can apply must have a handler.
$expectedControl = array('requestedTarget', 'requestedMode', 'boost', 'automationEnabled', 'allowBatteryHeat');
$got = array_keys($control);
sort($expectedControl);
sort($got);
T::eq($got, $expectedControl, 'control properties = the 5 that applyObjectControl() handles');

$expectedPresence = array('presentPeople', 'presenceSessionJson', 'presenceEvent', 'presenceEventJson');
$gotP = array_keys($presence);
sort($expectedPresence);
sort($gotP);
T::eq($gotP, $expectedPresence, 'presence properties = the 4 present* properties');

foreach ($control as $p => $m) {
    T::ok(is_string($m) && strpos($m, 'apply') === 0, "control '$p' handler '$m' is named apply*");
}
foreach ($presence as $p => $m) {
    T::ok(is_string($m) && strpos($m, 'on') === 0, "presence '$p' handler '$m' is named on*");
}
T::eq(count($methods), count(array_unique($methods)), 'every handler method name is unique');

T::group('BoilerObject::handlerCode (generated method bodies)');

foreach ($control as $p => $m) {
    $code = BoilerObject::handlerCode('control', $p, 'drazice_smart');
    T::ok(strpos($code, "applyObjectControl('" . $p . "'") !== false, "control code for '$p' calls applyObjectControl");
    T::ok(strpos($code, '$this->object_title') !== false, "control code for '$p' resolves the object via \$this (works as a class method)");
    T::ok(strpos($code, 'EspBoiler') === false, "control code for '$p' has no hard-coded object name");
    T::ok(strpos($code, "\$params['NEW_VALUE']") !== false, "control code for '$p' prefers the NEW_VALUE param");
    try {
        token_get_all('<?php ' . $code, TOKEN_PARSE);
        T::ok(true, "control code for '$p' is valid PHP");
    } catch (\ParseError $e) {
        T::ok(false, "control code for '$p' is valid PHP (" . $e->getMessage() . ')');
    }
}
foreach ($presence as $p => $m) {
    $code = BoilerObject::handlerCode('presence', $p, 'drazice_smart');
    T::ok(strpos($code, "onPresenceChange('" . $p . "'") !== false, "presence code for '$p' calls onPresenceChange");
    T::ok(strpos($code, "\$params['OLD_VALUE']") !== false, "presence code for '$p' forwards the previous value");
    T::ok(strpos($code, 'EspBoiler') === false, "presence code for '$p' has no hard-coded object name");
    try {
        token_get_all('<?php ' . $code, TOKEN_PARSE);
        T::ok(true, "presence code for '$p' is valid PHP");
    } catch (\ParseError $e) {
        T::ok(false, "presence code for '$p' is valid PHP (" . $e->getMessage() . ')');
    }
}

T::group('BoilerObject: owner-editable user hooks');

$wanted = array('tempUpper', 'tempLower', 'heating', 'hdo', 'reportedMode', 'energyTotalKwh', 'bleState',
    'plannedTarget', 'controlState', 'decisionReason', 'decisionHuman',
    'batterySocHist', 'connected', 'boilerClockSkewSeconds');
foreach ($wanted as $p) {
    T::ok(isset($user[$p]), "user hook exists for '$p'");
    T::eq(isset($user[$p]) ? $user[$p] : null, 'on' . ucfirst($p) . 'Change', "hook name for '$p' follows on<Name>Change");
}
foreach ($user as $p => $m) {
    $code = BoilerObject::handlerCode('user', $p, 'drazice_smart');
    T::ok(strpos($code, "'" . $p . "'") !== false, "user stub for '$p' mentions the property");
    T::ok(strpos($code, 'new drazice_smart') === false, "user stub for '$p' does not call the module (no cost, no loops)");
    try {
        token_get_all('<?php ' . $code, TOKEN_PARSE);
        T::ok(true, "user stub for '$p' is valid PHP");
    } catch (\ParseError $e) {
        T::ok(false, "user stub for '$p' is valid PHP (" . $e->getMessage() . ')');
    }
}

// A property name is embedded in single quotes: the catalogue must never
// contain characters that could break out of them.
foreach (array_keys($cat) as $name) {
    T::ok(preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) === 1, "catalogue key '$name' is a plain identifier");
}
