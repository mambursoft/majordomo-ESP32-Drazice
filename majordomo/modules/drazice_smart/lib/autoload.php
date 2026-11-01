<?php
/**
 * Lightweight PSR-4 autoloader for the DraziceSmart\ namespace.
 *
 * No Composer dependency: installs cleanly on PHP 7.3 and on the RPi4 host.
 *
 * @package drazice_smart
 * @license MIT
 */

spl_autoload_register(function ($class) {
    $prefix = 'DraziceSmart\\';
    $len = strlen($prefix);
    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});
