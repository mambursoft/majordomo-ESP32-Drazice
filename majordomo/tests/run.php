<?php
/**
 * Test runner: includes every tests/Unit/*.php then prints a summary.
 * Exit code is non-zero if anything failed. Usage: php tests/run.php
 */

require_once(__DIR__ . '/bootstrap.php');

foreach (glob(__DIR__ . '/Unit/*.php') as $f) {
    require $f;
}

T::summary();
