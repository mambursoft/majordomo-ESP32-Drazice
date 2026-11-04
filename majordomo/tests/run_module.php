<?php
/**
 * Module-level test runner (loads the MajorDoMo module class on top of an
 * in-memory stub runtime). Separate from tests/run.php so the global SQL*
 * stubs never leak into the pure-library Unit run.
 * Usage: php tests/run_module.php
 */

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/Module/stubs.php');
require_once(dirname(__DIR__) . '/modules/drazice_smart/drazice_smart.class.php');

foreach (glob(__DIR__ . '/Module/*Test.php') as $f) {
    require $f;
}

T::summary();
