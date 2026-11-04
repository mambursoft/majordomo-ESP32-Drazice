<?php
/**
 * AGENTS.md rule 3: the production target is PHP 7.3. The dev host runs a newer
 * PHP where `php -l` happily accepts 7.4/8.x syntax, so this test token-scans
 * the shipped PHP sources for constructs that would be a parse/runtime error on 7.3.
 */

T::group('PHP 7.3 compatibility token scan');

$root = dirname(__DIR__, 2); // .../majordomo
$files = array_merge(
    glob($root . '/modules/drazice_smart/lib/*.php'),
    array(
        $root . '/modules/drazice_smart/drazice_smart.class.php',
        $root . '/modules/drazice_smart/db_install.php',
        $root . '/scripts/cycle_drazice_smart.php',
    ),
    glob($root . '/languages/*.php'),
    glob($root . '/modules/drazice_smart/languages/*.php')
);

$forbiddenTokens = array();
foreach (array('T_FN', 'T_MATCH', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_COALESCE_EQUAL', 'T_READONLY', 'T_ENUM', 'T_ATTRIBUTE') as $name) {
    if (defined($name)) {
        $forbiddenTokens[constant($name)] = $name;
    }
}
$bannedCalls = array('str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'array_is_list', 'fdiv', 'mb_str_split');

$scanned = 0;
foreach ($files as $f) {
    if (!is_file($f)) {
        T::ok(false, 'file listed for the scan exists: ' . basename($f));
        continue;
    }
    $scanned++;
    $name = str_replace($root . '/', '', str_replace('\\', '/', $f));
    $src = file_get_contents($f);
    $tokens = token_get_all($src);
    $code = '';           // source without comments / string literals / inline html
    $bad = array();
    foreach ($tokens as $tok) {
        if (is_array($tok)) {
            $id = $tok[0];
            if (isset($forbiddenTokens[$id])) {
                $bad[] = $forbiddenTokens[$id] . ' at line ' . $tok[2];
            }
            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_INLINE_HTML
                || $id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                $code .= ' ';
                continue;
            }
            $code .= $tok[1];
        } else {
            $code .= $tok;
        }
    }
    foreach ($bannedCalls as $fn) {
        if (preg_match('/(?<![A-Za-z0-9_>:$\\\\])' . $fn . '\s*\(/', $code)) {
            $bad[] = $fn . '()';
        }
    }
    // Typed property declarations (7.4): visibility [static] [?]Type $name
    if (preg_match('/\b(public|protected|private)\s+(static\s+)?\??(?!static\b|function\b|const\b|abstract\b|final\b)[A-Za-z_\\\\]+\s+\$/', $code, $m)) {
        $bad[] = 'typed property: ' . trim($m[0]);
    }
    // Union types in parameters / returns (8.0): A|B $x   or   ): A|B
    if (preg_match('/[A-Za-z_\\\\?]\|[A-Za-z_\\\\]+\s+&?\.{0,3}\$/', $code, $m)) {
        $bad[] = 'union type in parameter: ' . trim($m[0]);
    }
    if (preg_match('/\)\s*:\s*\??[A-Za-z_\\\\]+\|[A-Za-z_\\\\|]+/', $code, $m)) {
        $bad[] = 'union return type: ' . trim($m[0]);
    }
    // Numeric literal separator (7.4)
    if (preg_match('/\b\d+_\d+\b/', $code, $m)) {
        $bad[] = 'numeric literal separator: ' . $m[0];
    }
    // Spread of string-keyed arrays / array spread (7.4): [...$a]
    if (preg_match('/\[\s*\.\.\./', $code)) {
        $bad[] = 'array spread [...';
    }
    // Constructor promotion (8.0)
    if (preg_match('/function\s+__construct\s*\([^)]*\b(public|protected|private)\s/', $code)) {
        $bad[] = 'constructor property promotion';
    }
    T::ok(empty($bad), 'PHP 7.3-compatible: ' . $name . (empty($bad) ? '' : ' -> ' . implode('; ', $bad)));
}
T::ok($scanned >= 20, 'scanned at least 20 PHP files (got ' . $scanned . ')');
