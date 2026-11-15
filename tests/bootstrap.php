<?php
// Loads the SDK without Composer (composer's autoload works the same way).
foreach (['Exceptions', 'RunConvert', 'Jobs', 'Operations', 'Files', 'Webhooks'] as $f) {
    require_once __DIR__ . "/../src/$f.php";
}

/** A tiny test runner: test('name', fn () => ...); check($cond, 'message'); */
$GLOBALS['__tests'] = [];
function test(string $name, callable $fn): void { $GLOBALS['__tests'][$name] = $fn; }
function check(bool $cond, string $msg = 'assertion failed'): void { if (!$cond) { throw new \AssertionError($msg); } }
function same($a, $b, string $msg = ''): void { if ($a !== $b) { throw new \AssertionError(($msg ? "$msg: " : '') . var_export($a, true) . ' !== ' . var_export($b, true)); } }
function throws(string $class, callable $fn): \Throwable {
    try { $fn(); } catch (\Throwable $e) { if ($e instanceof $class) { return $e; } throw new \AssertionError("expected $class, got " . get_class($e) . ': ' . $e->getMessage()); }
    throw new \AssertionError("expected $class, nothing thrown");
}
function run_tests(): int {
    $fail = 0;
    foreach ($GLOBALS['__tests'] as $name => $fn) {
        try { $fn(); echo "✔ $name\n"; } catch (\Throwable $e) { $fail++; echo "✖ $name: " . $e->getMessage() . "\n"; }
    }
    $n = count($GLOBALS['__tests']);
    echo "\n" . ($n - $fail) . "/$n passed\n";
    return $fail ? 1 : 0;
}
