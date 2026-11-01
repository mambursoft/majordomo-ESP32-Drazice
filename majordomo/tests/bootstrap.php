<?php
/**
 * Minimal, dependency-free test harness for PHP 7.3+ (no PHPUnit, no Composer).
 * Loads the DraziceSmart\ library only — NOT the MajorDoMo module class, so
 * tests run without a MajorDoMo runtime.
 */

require_once(dirname(__DIR__) . '/modules/drazice_smart/lib/autoload.php');

class T
{
    public static $passed = 0;
    public static $failed = 0;
    public static $failures = array();
    public static $current = '';

    public static function group($name)
    {
        self::$current = $name;
        echo "\n== $name ==\n";
    }

    public static function ok($cond, $msg)
    {
        if ($cond) {
            self::$passed++;
            echo "  [PASS] $msg\n";
        } else {
            self::$failed++;
            self::$failures[] = self::$current . ' :: ' . $msg;
            echo "  [FAIL] $msg\n";
        }
    }

    public static function eq($a, $b, $msg)
    {
        $cond = ($a === $b);
        self::ok($cond, $msg . ($cond ? '' : " (got " . var_export($a, true) . ", expected " . var_export($b, true) . ")"));
    }

    public static function approx($a, $b, $eps, $msg)
    {
        self::ok(abs($a - $b) <= $eps, $msg . " (got $a, expected ~$b +/- $eps)");
    }

    public static function summary()
    {
        echo "\n----------------------------------------\n";
        echo "PASSED: " . self::$passed . "   FAILED: " . self::$failed . "\n";
        if (self::$failed > 0) {
            echo "Failures:\n";
            foreach (self::$failures as $f) {
                echo "  - $f\n";
            }
            exit(1);
        }
        echo "ALL TESTS PASSED\n";
        exit(0);
    }
}
